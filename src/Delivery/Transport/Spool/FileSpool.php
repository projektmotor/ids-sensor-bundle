<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

use Psr\Log\LoggerInterface;

/**
 * Legt Frames als JSON Lines auf die Platte, eine Zeile je Frame.
 *
 * Zwei Verwendungen:
 *  - Broker nicht erreichbar oder Circuit Breaker offen (Ausnahmefall)
 *  - Laufzeiten ohne abkoppelbare Antwort, also mod_php (Regelfall dort)
 *
 * EINE DATEI PRO PROZESS, nicht eine gemeinsame. Damit ist kein Verlass auf die
 * Atomizität von O_APPEND nötig — die gilt für kleine Schreibvorgänge, ein Frame kann
 * aber mehrere Kilobyte haben — und es entsteht keine Sperrkonkurrenz im Schreibpfad.
 *
 * AKTIV ODER VERSIEGELT
 *
 * Jede Datei ist in genau einem von zwei Zuständen, und der Zustand steht im Namen:
 *
 *  - `frames-<pid>-<kennung>.active` — hier schreibt dieser Prozess gerade hinein.
 *  - `frames-<pid>-<kennung>-<nr>.jsonl` — versiegelt, niemand schreibt mehr, der
 *    Drainer darf sie abholen.
 *
 * Die Trennung löst das Rennen zwischen Schreiber und Drainer auf. Vorher bestand der
 * Name nur aus PID und Thread-Zusatz — er war für jeden anderen Prozess rekonstruierbar,
 * und der Drainer benannte genau die Datei um, in die gerade geschrieben wurde. Drei
 * Verlustwege folgten daraus, der größte: nach dem Beanspruchen legte der Schreiber
 * unter demselben Namen eine neue Datei an, und das `rename()` des Drainers überschrieb
 * sie am Ende wortlos — mitsamt allem, was während des ganzen Laufs erfasst worden war.
 *
 * Die Kennung wird je Instanz einmal gezogen. Sie ersetzt zugleich den früheren
 * Thread-Zusatz und macht eine wiederverwendete PID unschädlich.
 *
 * Versiegelt wird bei Größe ODER Alter — siehe {@see currentFile()}. Das Alter ist der
 * Grund, warum unter mod_php überhaupt regelmäßig etwas abfließt.
 *
 * KEIN fsync. Der Spool ist eine Zwischenablage, keine Buchhaltung; ein Stromausfall
 * darf Events kosten. Ein fsync pro Request wäre der teuerste Teil des ganzen Sensors.
 *
 * Die Größe wird mitgeführt statt bei jedem Schreibvorgang per glob() und filesize()
 * neu ermittelt — letzteres wären zwei Dateisystemzugriffe pro Request, nur um eine
 * Obergrenze zu prüfen.
 *
 * @internal
 */
final class FileSpool implements SpoolInterface
{
    public const FILE_PREFIX = 'frames-';

    public const FILE_SUFFIX = '.jsonl';

    public const DRAINING_SUFFIX = '.draining';

    /**
     * Die Datei, in die dieser Prozess gerade schreibt.
     *
     * Sie trägt bewusst NICHT {@see FILE_SUFFIX} und ist damit für {@see pendingFiles()}
     * unsichtbar — der Drainer kann sie nicht beanspruchen. Das ist die Naht, an der das
     * Rennen zwischen Schreiber und Drainer aufgelöst wird: Abgeholt wird ausschließlich,
     * was der Schreiber vorher selbst versiegelt hat.
     */
    public const ACTIVE_SUFFIX = '.active';

    /** Nach so vielen Schreibvorgängen wird die mitgeführte Größe nachgerechnet. */
    private const RECOUNT_INTERVAL = 256;

    private ?int $trackedBytes = null;

    private int $writesSinceRecount = 0;

    private int $discardedFull = 0;

    private int $discardedUnwritable = 0;

    private int $discardedUnencodable = 0;

    private int $spooled = 0;

    /**
     * Wann die aktive Datei ihre erste Zeile bekommen hat.
     *
     * Im Instanzspeicher und nicht über `filemtime()`: die Änderungszeit ist die des
     * LETZTEN Schreibvorgangs, und die ist bei einer vielbeschriebenen Datei immer
     * gerade eben. Zum Versiegeln braucht es aber das Alter des Inhalts.
     */
    private ?int $activeSince = null;

    private int $sealSequence = 0;

    /**
     * Prozessweit einmalige Kennung im Dateinamen.
     *
     * Der Name bestand vorher nur aus PID und einem Thread-Zusatz und war damit für
     * jeden anderen Prozess jederzeit rekonstruierbar — genau darauf beruhten die
     * Überschreib-Rennen zwischen Drainer und Schreiber. Mit der Kennung kann kein
     * fremder Prozess den Namen einer aktiven Datei bilden, und eine
     * wiederverwendete PID nach einem Neustart trifft nie die Datei ihres Vorgängers.
     *
     * Ersetzt zugleich den früheren Thread-Zusatz, der `zend_thread_id()` aufrief — eine
     * Funktion, die es im PHP-Kern nicht gibt — und im Rückfall das Objekt statt des
     * Threads identifizierte.
     */
    private readonly string $nonce;

    /**
     * @param int $sealAfterSeconds Nach dieser Zeit wird die aktive Datei versiegelt,
     *                              auch wenn sie klein ist. Verdrahtet wird das
     *                              Drain-Intervall: Damit wartet ein Frame höchstens
     *                              einen Drain-Lauf, was Konzept 3.3.1 für `deferred`
     *                              genau so zusagt. 0 schaltet die Zeitschranke ab.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = 16777216,
        private readonly int $maxFileBytes = 4194304,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $sealAfterSeconds = 30,
    ) {
        $this->nonce = bin2hex(random_bytes(4));
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function append(array $frame): bool
    {
        $line = json_encode(
            $frame,
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if (false === $line) {
            // EIGENER Zähler, nicht discardedFull. SpoolInterface definiert den als
            // „wegen Überschreitung der Maximalgröße verworfen", und der Wert geht unter
            // diesem Namen in den Heartbeat. Ein Kodierfehler schickte den Betreiber
            // damit auf die falsche Fährte: Er vergrößerte die Platte, während die
            // Ursache ein Payload ist, den json_encode nicht abbilden kann — bei
            // gesetztem JSON_PARTIAL_OUTPUT_ON_ERROR praktisch nur eine
            // Tiefenüberschreitung.
            ++$this->discardedUnencodable;

            return false;
        }

        $line .= "\n";
        $length = \strlen($line);

        $this->refreshByteCount();

        if (!$this->hasRoomFor($length)) {
            // Bevor verworfen wird, EINMAL hart nachrechnen. Der mitgeführte Stand kann
            // beliebig veraltet sein — ein Drain-Lauf hat das Verzeichnis vielleicht
            // längst geleert.
            //
            // Ohne diese Zeilen war das Verwerfen endgültig: writesSinceRecount wird nur
            // im Erfolgsfall erhöht, also fror der Zähler beim ersten vollen Spool ein und
            // refreshByteCount() rechnete NIE wieder nach. Der Prozess verwarf jeden
            // weiteren Frame bis zu seinem Ende — bei einem FPM-Kind mit
            // pm.max_requests = 0 sind das Stunden, und unter mod_php, wo der Spool der
            // Regelweg ist, war es der vollständige Ausfall der Erfassung dieses
            // Kindprozesses. Sichtbar nur als wachsendes dropped_spool_full, während
            // sizeInBytes() dem Heartbeat weiterhin den eingefrorenen Stand meldete.
            //
            // Die Kosten fallen nur auf diesem Pfad an, und wer hier ankommt, hat ein
            // größeres Problem als einen glob().
            $this->trackedBytes = $this->recount();
            $this->writesSinceRecount = 0;
        }

        if (!$this->hasRoomFor($length)) {
            // Konzept 4. IdsBackendBundle: „Ist der Puffer voll, werden weitere Events
            // VERWORFEN statt gepuffert — unbegrenztes Puffern würde den Plattenplatz
            // der Anwendung erschöpfen und aus einer IDS-Störung einen
            // Anwendungsausfall machen."
            ++$this->discardedFull;
            $this->logger?->warning(
                'ids_sensor: Spool ist voll ({bytes} von {max} Byte), Frame verworfen.',
                ['bytes' => $this->trackedBytes ?? 0, 'max' => $this->maxBytes],
            );

            return false;
        }

        if (!$this->ensureDirectory()) {
            ++$this->discardedUnwritable;

            return false;
        }

        $written = @file_put_contents($this->currentFile(), $line, \FILE_APPEND);

        if (false === $written) {
            ++$this->discardedUnwritable;
            $this->logger?->error(
                'ids_sensor: Spool-Verzeichnis {dir} ist nicht beschreibbar, Frame verworfen.',
                ['dir' => $this->directory],
            );

            return false;
        }

        $this->trackedBytes = ($this->trackedBytes ?? 0) + $written;
        $this->activeSince ??= time();
        ++$this->spooled;
        ++$this->writesSinceRecount;

        return true;
    }

    /**
     * Die VERSIEGELTEN Dateien, älteste zuerst.
     *
     * Die aktive Datei des schreibenden Prozesses ist hier bewusst nicht dabei — sie
     * trägt {@see ACTIVE_SUFFIX} und passt nicht auf das Muster. Genau das macht den
     * Drainer und den Schreiber verträglich: Beansprucht werden kann nur, woran niemand
     * mehr schreibt.
     *
     * @return list<string> vollständige Pfade
     */
    public function pendingFiles(): array
    {
        $files = glob($this->directory.'/'.self::FILE_PREFIX.'*'.self::FILE_SUFFIX);

        if (false === $files) {
            return [];
        }

        // Änderungszeiten einmal einsammeln statt im Vergleich: usort ruft den
        // Vergleicher O(n log n) mal auf, und jeder Aufruf wären zwei stat(). Zudem kann
        // ein paralleler Drainer zwischen glob() und Vergleich umbenennen — unmaskiert
        // warnte filemtime() dann und lieferte false, was die Sortierung verdreht.
        $byAge = [];

        foreach ($files as $file) {
            $modified = @filemtime($file);
            $byAge[$file] = false === $modified ? \PHP_INT_MAX : $modified;
        }

        // Älteste zuerst: bei einer Obergrenze pro Lauf sollen die ältesten Events
        // zuerst abfließen, sonst verhungern sie. Inzwischen verschwundene Dateien
        // sortieren nach hinten — sie kosten dann höchstens einen Fehlversuch.
        uasort($byAge, static fn (int $a, int $b): int => $a <=> $b);

        return array_keys($byAge);
    }

    /**
     * Beanspruchte Dateien, deren Bearbeiter offensichtlich nicht mehr lebt.
     *
     * {@see SpoolDrainer::claim()} benennt eine Datei vor dem Senden auf
     * {@see DRAINING_SUFFIX} um. Stirbt der Prozess danach — SIGKILL, OOM, Deploy,
     * cron-Timeout —, bleibt sie liegen, und ohne diese Methode für immer:
     * {@see pendingFiles()} findet sie nicht mehr. Sichtbar war das nirgends, denn
     * Heartbeat und `ids:sensor:setup-check` fragen genau dort. {@see recount()} zählt
     * ihre Bytes aber weiter gegen die Obergrenze — der Spool lief also voll und meldete
     * dabei „leer".
     *
     * Konzept 3.4 nennt `oldest_pending_age_s` ausdrücklich als die EINZIGE Stelle, an
     * der ein nicht laufender Drain von außen sichtbar wird. Eine unsichtbare Datei
     * unterläuft genau diese Zusage.
     *
     * Die Frist trennt „abgestürzt" von „läuft gerade": Ein Drain-Lauf, der noch
     * arbeitet, darf nicht bestohlen werden.
     *
     * @return list<string>
     */
    public function stalledFiles(int $olderThanSeconds): array
    {
        $files = glob($this->directory.'/'.self::FILE_PREFIX.'*'.self::DRAINING_SUFFIX);

        if (false === $files) {
            return [];
        }

        $limit = time() - max(1, $olderThanSeconds);

        return array_values(array_filter($files, static function (string $file) use ($limit): bool {
            $modified = @filemtime($file);

            return false !== $modified && $modified < $limit;
        }));
    }

    /**
     * Die Datei, in die der nächste Frame geschrieben wird — nach einer fälligen
     * Versiegelung.
     *
     * ZWEI GRÜNDE ZU VERSIEGELN, UND DER ZWEITE IST DER WICHTIGERE
     *
     *  - GRÖSSE: eine zu große Einzeldatei erschwert das Abholen und macht einen
     *    Teilfehler teuer. Das war schon immer der Grund.
     *  - ALTER: Nur eine versiegelte Datei kann der Drainer abholen. Ohne Zeitschranke
     *    bliebe unter mod_php — wo der Spool der REGELWEG ist — alles bis zum Erreichen
     *    von `max_file_bytes` liegen, also unter Umständen stundenlang. Die Schranke ist
     *    das Drain-Intervall; damit wartet ein Frame höchstens einen Drain-Lauf, und das
     *    ist genau die Zusage, die Konzept 3.3.1 an `deferred` knüpft.
     *
     * Gemessen wird das Alter am Zeitpunkt der ERSTEN Zeile, nicht an `filemtime()`: die
     * Änderungszeit einer vielbeschriebenen Datei ist immer gerade eben.
     */
    public function currentFile(): string
    {
        $active = $this->activeFile();

        if ($this->isSealDue($active)) {
            $this->sealFile($active);
        }

        return $active;
    }

    /**
     * ALLES, was auf der Platte liegt und noch nicht beim Broker ist — für die Meldung.
     *
     * Der Unterschied zu {@see pendingFiles()} ist die Frage, die gestellt wird.
     * `pendingFiles()` beantwortet „was darf der Drainer abholen" und lässt Aktives
     * bewusst aus. Der Betreiber will aber wissen „liegt etwas herum", und dafür zählt
     * jede Datei: die aktive, die versiegelte und die gerade beanspruchte.
     *
     * Ohne diese Unterscheidung hätte das Versiegeln eine Zusage gebrochen: Konzept 3.4
     * nennt `oldest_pending_age_s` als die EINZIGE Stelle, an der ein nicht laufender
     * Drain von außen sichtbar wird. Meldete der Heartbeat nur Versiegeltes, sähe ein
     * Betreiber bei geringer Last dauerhaft „Spool leer", obwohl Frames auf der Platte
     * liegen — derselbe blinde Fleck, den die verwaisten `.draining`-Dateien hatten.
     *
     * @return list<string>
     */
    public function waitingFiles(): array
    {
        $files = glob($this->directory.'/'.self::FILE_PREFIX.'*');

        return false === $files ? [] : $files;
    }

    /**
     * Alter der ältesten wartenden Datei in Sekunden — `null`, wenn nichts wartet.
     *
     * Konzept 3.4 nennt `oldest_pending_age_s` als die EINZIGE Stelle, an der ein nicht
     * laufender Drain von außen sichtbar wird. Genau diese Zahl brauchen zwei Aufrufer:
     * der Heartbeat für den Collector und `ids:sensor:setup-check` für den Betreiber.
     * Beide hatten sie eigenhändig ausgerechnet — dieselbe Schleife über `filemtime()`,
     * zweimal geschrieben, mit zwei Gelegenheiten, sie unterschiedlich zu machen.
     *
     * Sie gehört hierher, weil dieser Klasse die Dateien gehören.
     */
    public function oldestWaitingAgeSeconds(): ?int
    {
        $oldest = null;

        foreach ($this->waitingFiles() as $file) {
            $modified = @filemtime($file);

            if (false === $modified) {
                continue;
            }

            $oldest = null === $oldest ? $modified : min($oldest, $modified);
        }

        return null === $oldest ? null : max(0, time() - $oldest);
    }

    /**
     * Aktive Dateien, an die erkennbar niemand mehr schreibt.
     *
     * Ohne sie hätte das Versiegeln ein Loch: {@see currentFile()} versiegelt erst beim
     * NÄCHSTEN Schreibvorgang. Ein Prozess, der einen Frame erfasst und dann keinen
     * Verkehr mehr bekommt — bei geringer Last der Normalfall —, ließe ihn für immer in
     * seiner aktiven Datei liegen, und der Drainer sähe ihn nie. Ein Prozess, der stirbt,
     * ebenso.
     *
     * Der {@see SpoolDrainer} versiegelt diese Dateien deshalb stellvertretend. Das ist
     * gefahrlos, obwohl der Schreiber vielleicht noch lebt: Sein nächster Anhang legt die
     * Datei unter demselben Namen einfach neu an — der Name enthält SEINE Kennung, kein
     * anderer Prozess vergibt ihn —, und was während des Lesens noch hineinlief, hebt
     * {@see SpoolDrainer} über den Längenvergleich auf.
     *
     * @return list<string>
     */
    public function idleActiveFiles(int $idleForSeconds): array
    {
        $files = glob($this->directory.'/'.self::FILE_PREFIX.'*'.self::ACTIVE_SUFFIX);

        if (false === $files) {
            return [];
        }

        $limit = time() - max(1, $idleForSeconds);

        return array_values(array_filter($files, static function (string $file) use ($limit): bool {
            $modified = @filemtime($file);

            return false !== $modified && $modified <= $limit;
        }));
    }

    /**
     * Ein frischer, noch nicht vergebener Name für eine versiegelte Datei.
     *
     * Öffentlich, weil der {@see SpoolDrainer} ihn braucht: Er darf den Rest einer nur
     * teilweise gesendeten Datei NICHT unter ihrem alten Namen ablegen. Dort könnte
     * inzwischen ein anderer Prozess schreiben, und `rename()` überschreibt wortlos —
     * das war der größte der drei Verlustpfade. Da der Drainer eine eigene Kennung
     * trägt, kann der Name, den er hier bekommt, keinem lebenden Schreiber gehören.
     */
    public function sealedFileName(): string
    {
        do {
            $candidate = \sprintf(
                '%s/%s%d-%s-%d%s',
                $this->directory,
                self::FILE_PREFIX,
                getmypid() ?: 0,
                $this->nonce,
                ++$this->sealSequence,
                self::FILE_SUFFIX,
            );
        } while (file_exists($candidate));

        return $candidate;
    }

    /**
     * Die Datei dieses Prozesses, an die angehängt wird.
     *
     * Trägt {@see ACTIVE_SUFFIX} und ist damit für {@see pendingFiles()} unsichtbar.
     */
    private function activeFile(): string
    {
        return \sprintf(
            '%s/%s%d-%s%s',
            $this->directory,
            self::FILE_PREFIX,
            getmypid() ?: 0,
            $this->nonce,
            self::ACTIVE_SUFFIX,
        );
    }

    private function isSealDue(string $active): bool
    {
        if (!is_file($active)) {
            return false;
        }

        if ((int) @filesize($active) >= $this->maxFileBytes) {
            return true;
        }

        if ($this->sealAfterSeconds <= 0 || null === $this->activeSince) {
            return false;
        }

        return (time() - $this->activeSince) >= $this->sealAfterSeconds;
    }

    /**
     * Versiegelt die aktive Datei sofort, unabhängig von Größe und Alter.
     *
     * Wer weiß, dass sein Stapel fertig ist, sagt es damit — statt zu warten, bis der
     * Drainer die Datei nach {@see idleActiveFiles()} adoptiert. Ohne aktive Datei
     * passiert nichts.
     */
    public function seal(): void
    {
        $active = $this->activeFile();

        if (is_file($active)) {
            $this->sealFile($active);
        }
    }

    /**
     * Scheitert das Umbenennen, bleibt die Datei aktiv und der nächste Schreibvorgang
     * versucht es erneut — verloren geht dabei nichts.
     */
    private function sealFile(string $active): void
    {
        if (!@rename($active, $this->sealedFileName())) {
            return;
        }

        $this->activeSince = null;
    }

    public function sizeInBytes(): int
    {
        return $this->trackedBytes ??= $this->recount();
    }

    public function discardedFull(): int
    {
        return $this->discardedFull;
    }

    public function discardedUnwritable(): int
    {
        return $this->discardedUnwritable;
    }

    /** Wegen eines Kodierfehlers verworfene Frames. */
    public function discardedUnencodable(): int
    {
        return $this->discardedUnencodable;
    }

    public function spooledFrames(): int
    {
        return $this->spooled;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Rechnet den Belegungsstand nach, wenn er fehlt oder veraltet ist.
     *
     * Getrennt von {@see hasRoomFor()}, weil eine Methode entweder Zustand ändert oder
     * eine Auskunft gibt — eine Frage mit Seiteneffekt ist die Sorte Überraschung, die
     * beim nächsten Leser Zeit kostet.
     */
    private function refreshByteCount(): void
    {
        // Kaltstart oder fällige Nachrechnung: die mitgeführte Zahl kann von anderen
        // Prozessen unterlaufen sein.
        if (null === $this->trackedBytes || $this->writesSinceRecount >= self::RECOUNT_INTERVAL) {
            $this->trackedBytes = $this->recount();
            $this->writesSinceRecount = 0;
        }
    }

    private function hasRoomFor(int $length): bool
    {
        return ($this->trackedBytes ?? 0) + $length <= $this->maxBytes;
    }

    private function recount(): int
    {
        $pattern = $this->directory.'/'.self::FILE_PREFIX.'*';
        $files = glob($pattern);

        if (false === $files) {
            return 0;
        }

        $total = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            $total += false === $size ? 0 : $size;
        }

        return $total;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        return @mkdir($this->directory, 0o775, true) || is_dir($this->directory);
    }
}
