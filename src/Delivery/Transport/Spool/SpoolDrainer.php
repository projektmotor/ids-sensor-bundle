<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use Psr\Log\LoggerInterface;

/**
 * Leert den Spool in Richtung Collector.
 *
 * Läuft auf DEMSELBEN System wie die überwachte Anwendung — nur dort gibt es Zugriff
 * auf die Spool-Dateien — und verwendet dieselben Zugangsdaten und dieselben drei
 * POST-Adressen wie der Sensor im Request. Für den Collector ändert sich nichts, und die asymmetrische
 * Rechteverteilung aus Konzept 2. bleibt vollständig intakt.
 *
 * Als CLI-Prozess ohne Latenzbudget darf hier blockiert werden: es wartet kein Browser.
 *
 * Der Frame wird UNVERÄNDERT weitergeschickt — nicht erneut normalisiert oder
 * redigiert. Ein zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es falsch
 * zu machen. Gesetzt wird nur der dispatch_path, damit der Collector die Verzögerung
 * einordnen kann.
 *
 * WARUM FileSpool UND NICHT SpoolInterface
 *
 * Weil der Drainer keine Nutzung des Spools ist, sondern seine andere Hälfte.
 * {@see SpoolInterface} beschreibt die Schreibseite und verbirgt gerade, WOHIN die
 * Frames gehen; der Drainer öffnet Dateien, benennt sie um und braucht dafür
 * `pendingFiles()` und {@see FileSpool::DRAINING_SUFFIX} — beides ausdrücklich nicht
 * Teil der Schreibseite. Ein zweites Interface dafür hätte einen Implementierer und
 * einen Aufrufer und wäre damit ein Name statt einer Naht (CLAUDE.md §1.9).
 *
 * @internal
 */
final class SpoolDrainer
{
    public function __construct(
        private readonly FileSpool $spool,
        private readonly ShipperInterface $shipper,
        private readonly ?LoggerInterface $logger = null,
        // Ab wann eine BEANSPRUCHTE Datei als liegengeblieben gilt. Entspricht
        // `ids_sensor.spool.stale_after_s`.
        private readonly int $staleAfterSeconds = 300,
        // Ab wann eine AKTIVE Datei stellvertretend versiegelt wird. Entspricht
        // `ids_sensor.spool.drain_interval_s` — siehe {@see sealIdleFiles()} für den
        // Grund, warum das nicht dieselbe Frist sein darf.
        private readonly int $sealIdleAfterSeconds = 30,
        // Der Drainer hatte keinen Zaehler: Eine verworfene Zeile hinterliess nur einen
        // Logeintrag, und $logger ist optional. Konzept 4. verlangt aber, dass JEDER
        // verlorene Event gezaehlt wird — sonst ist der Verlust von "nichts war da"
        // nicht zu unterscheiden.
        private readonly ?Counters $counters = null,
    ) {
    }

    /**
     * Holt Dateien zurück, deren Bearbeiter nicht mehr lebt.
     *
     * {@see claim()} benennt vor dem Senden auf `.draining` um. Stirbt der Prozess
     * danach, war die Datei bisher für immer verloren: `pendingFiles()` findet sie
     * nicht, ihre Bytes zählen aber weiter gegen `spool.max_bytes`. Zusammen ergab das
     * den Zustand, den Konzept 4. ausschließt — der Spool läuft voll und verwirft, und
     * jede Auskunft des Sensors über sich selbst sagt „leer".
     *
     * Zurückbenannt statt an Ort und Stelle gelesen, damit der reguläre Weg genau einer
     * bleibt.
     */
    private function reclaimStalled(): void
    {
        foreach ($this->spool->stalledFiles($this->staleAfterSeconds) as $file) {
            // NICHT auf den ursprünglichen Namen zurück: Unter ihm könnte längst ein
            // anderer Prozess schreiben, und rename() überschreibt wortlos. Ein frischer
            // Name gehört garantiert niemandem — der Drainer trägt eine eigene Kennung.
            if (@rename($file, $this->spool->sealedFileName())) {
                $this->logger?->warning(
                    'ids_sensor: liegengebliebene Spool-Datei {file} zurückgeholt — ein früherer '
                    .'Drain-Lauf hat sie beansprucht und nicht beendet.',
                    ['file' => $file],
                );
            }
        }
    }

    /**
     * Der dispatch_path wird NICHT von außen vorgegeben, sondern aus jedem Frame
     * einzeln abgeleitet — siehe {@see markPath()}. Ein Lauf kann Frames beider Wege
     * enthalten, und ein einzelner Wert für alle wäre für mindestens einen von beiden
     * falsch.
     *
     * @return array{files: int, frames: int, failed: int, skipped: int, discarded: int}
     */
    public function drain(int $maxFiles = 2): array
    {
        $result = ['files' => 0, 'frames' => 0, 'failed' => 0, 'skipped' => 0, 'discarded' => 0];

        $this->reclaimStalled();
        $this->sealIdleFiles();

        foreach ($this->spool->pendingFiles() as $file) {
            if ($result['files'] >= $maxFiles) {
                ++$result['skipped'];
                continue;
            }

            $claimed = $this->claim($file);

            if (null === $claimed) {
                // Ein anderer Prozess war schneller. Kein Fehler.
                ++$result['skipped'];
                continue;
            }

            ++$result['files'];
            $outcome = $this->drainFile($claimed);
            $result['frames'] += $outcome['sent'];
            $result['failed'] += $outcome['failed'];
            $result['discarded'] += $outcome['discarded'];

            if ($outcome['failed'] > 0) {
                // Beim ersten Fehlschlag abbrechen: ist der Collector weg, bringt es
                // nichts, die restlichen Dateien durchzuprobieren. Der Rest bleibt
                // liegen und wird beim nächsten Lauf erneut versucht.
                break;
            }
        }

        return $result;
    }

    /**
     * Versiegelt stellvertretend, woran erkennbar niemand mehr schreibt.
     *
     * Der Schreiber versiegelt seine Datei erst beim NÄCHSTEN Anhang. Wer einen Frame
     * erfasst und danach keinen Verkehr mehr bekommt — bei geringer Last der Normalfall —
     * ließe ihn sonst für immer liegen, und ein abgestürzter Prozess ohnehin.
     *
     * WARUM NICHT DIESELBE FRIST WIE FÜRS BEANSPRUCHEN
     *
     * Hier stand `stale_after_s` (Vorgabe 300 s), und das brach die Zusage, die derselbe
     * Umbau eine Zeile später gab: Konzept 3.3.1 sagt für `deferred` „höchstens ein
     * Drain-Intervall" zu und empfiehlt dem Collector als Toleranzschwelle das Zweifache
     * des gemeldeten `drain_interval_s`, also 60 s. Ein Frame, der 300 s auf seine
     * Versiegelung wartet, kommt mit `spool_delay_ms ≈ 300 000` an und wird
     * collectorseitig wie `recovered` behandelt: KEINE Echtzeit-Regeln.
     *
     * Unter mod_php, wo der Spool der Regelweg ist, traf das jede Installation mit
     * geringer Last — also genau den Ausfall, gegen den es die drei Zustände aus 3.3.1
     * überhaupt gibt. `stale_after_s` bleibt, wofür es gedacht ist: das Zurückholen
     * beanspruchter `.draining`-Dateien in {@see reclaimStalled()}.
     *
     * Gefahrlos ist die kürzere Frist, weil der Schreiber noch leben darf: Der Name seiner
     * aktiven Datei enthält SEINE Kennung, sein nächster Anhang legt sie unter demselben
     * Namen einfach neu an, und was zwischen unserem Dateiende und dem Abschluss noch
     * hereinlief, hebt {@see discardOrKeepTail()} über den Längenvergleich auf.
     */
    private function sealIdleFiles(): void
    {
        foreach ($this->spool->idleActiveFiles($this->sealIdleAfterSeconds) as $file) {
            @rename($file, $this->spool->sealedFileName());
        }
    }

    /**
     * Beansprucht eine Datei durch Umbenennen. rename() ist innerhalb eines
     * Dateisystems atomar, damit kann nicht zweimal dasselbe gesendet werden.
     */
    private function claim(string $file): ?string
    {
        $claimed = $file.FileSpool::DRAINING_SUFFIX;

        return @rename($file, $claimed) ? $claimed : null;
    }

    /**
     * @return array{sent: int, failed: int, discarded: int}
     */
    private function drainFile(string $file): array
    {
        $handle = @fopen($file, 'rb');

        if (false === $handle) {
            $this->logger?->error('ids_sensor: Spool-Datei {file} nicht lesbar.', ['file' => $file]);

            return ['sent' => 0, 'failed' => 0, 'discarded' => 0];
        }

        $sent = 0;
        $failed = 0;
        $discarded = 0;
        $readUpTo = 0;
        /** @var list<string> $remaining */
        $remaining = [];

        try {
            while (false !== ($line = fgets($handle))) {
                $line = trim($line);

                if ('' === $line) {
                    continue;
                }

                if ($failed > 0) {
                    // Nach dem ersten Fehlschlag nichts mehr versuchen, sondern den
                    // Rest aufheben.
                    $remaining[] = $line;
                    continue;
                }

                $outcome = $this->sendLine($line);

                if (DrainOutcome::Sent === $outcome) {
                    ++$sent;
                    continue;
                }

                // Discarded fällt hier bewusst durch: die Zeile ist weg und wird nicht
                // aufgehoben. Nur ein erreichbarkeitsbedingter Fehlschlag hält die Datei.
                if (DrainOutcome::Retryable === $outcome) {
                    ++$failed;
                    $remaining[] = $line;

                    continue;
                }

                // Discarded und Rejected: die Zeile ist weg und wird nicht aufgehoben —
                // aber gezaehlt, und zwar auf getrennten Zaehlern. Konzept 3.6 trennt
                // beide, weil das eine zur Spool-Datei fuehrt und das andere zum Payload;
                // hier lief bis dahin auch die Ablehnung des Collectors auf
                // dropped_spool_unreadable und schickte den Betreiber zur falschen Datei.
                ++$discarded;
                $this->counters?->increment(
                    DrainOutcome::Rejected === $outcome
                        ? Counters::DROPPED_REJECTED
                        : Counters::DROPPED_SPOOL_UNREADABLE,
                );
            }
            // Wie weit wir gekommen sind. Alles darüber hinaus ist NACH dem Lesen
            // angehängt worden — siehe finish().
            $readUpTo = (int) ftell($handle);
        } finally {
            fclose($handle);
        }

        $this->finish($file, $remaining, $readUpTo);

        return ['sent' => $sent, 'failed' => $failed, 'discarded' => $discarded];
    }

    /**
     * Dekodiert eine Zeile und versendet sie.
     *
     * Die beiden Verwerfen-Fälle bedeuten für die DATEI dasselbe — ein zweiter Versuch
     * scheiterte an derselben Stelle —, für den Betreiber aber nicht: Eine unlesbare
     * Zeile ist ein beschädigter Spool, eine Ablehnung ein untauglicher Payload. Sie
     * tragen deshalb verschiedene Ausgänge und laufen auf verschiedene Zähler.
     */
    private function sendLine(string $line): DrainOutcome
    {
        $frame = $this->decode($line);

        if (null === $frame) {
            $this->logger?->warning('ids_sensor: unlesbare Spool-Zeile verworfen.');

            return DrainOutcome::Discarded;
        }

        try {
            $this->shipper->ship($this->markPath($frame));

            return DrainOutcome::Sent;
        } catch (UnshippableFrameException $e) {
            $this->logger?->warning(
                'ids_sensor: dauerhaft unversendbarer Spool-Frame verworfen: {message}',
                ['message' => $e->getMessage()],
            );

            return DrainOutcome::Rejected;
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'ids_sensor: Nachsenden aus dem Spool fehlgeschlagen: {message}',
                ['message' => $e->getMessage()],
            );

            return DrainOutcome::Retryable;
        }
    }

    /**
     * Setzt dispatch_path und die gemessene Verzögerung.
     *
     * Die Events selbst bleiben unangetastet. Nur der Umschlag lernt, wie lange er
     * unterwegs war — der Collector braucht das, um zu entscheiden, ob die Events noch
     * für die Echtzeit-Regeln taugen.
     *
     * HERABSTUFEN, NICHT ÜBERSCHREIBEN
     *
     * Hier stand `$frame['dispatch_path'] = $path->value` mit `Recovered` als Vorgabe,
     * und das war ein stiller Totalausfall für mod_php. Dort schreibt der Sensor jeden
     * Frame planmäßig als `deferred` in den Spool; der dokumentierte cron-Eintrag lautet
     * `ids:sensor:spool:flush --quiet`, also ohne weitere Angabe. Jeder Frame kam damit
     * als `recovered` an — und Konzept 3.3.1 nimmt genau diesen Wert von der
     * Echtzeit-Auswertung aus. Die Regeln R1–R7 hätten auf einer mod_php-Installation
     * nie gefeuert.
     *
     * Es ist derselbe Fehler, den Konzept 3.3 dem verworfenen „late"-Flag vorwirft: ein
     * Wert, der planmäßigen Transportweg und Störung nicht unterscheiden kann. Der
     * Drainer weiß aber, welcher von beiden vorliegt — es steht im Frame:
     *
     *  - `deferred` hat der Sensor bewusst gesetzt, weil die Laufzeit die Antwort nicht
     *    abkoppeln kann. Das bleibt so; wie tolerant der Collector damit umgeht,
     *    entscheidet er anhand von `spool_delay_ms`.
     *  - `direct` bedeutet, dass der Versand versucht wurde und fehlschlug. Erst das
     *    ist Nachlauf nach einer Störung, also `recovered`.
     *
     * Damit ist der Wert wieder das, was Konzept 3.3.1 verlangt: „kein Schalter, sondern
     * ein vom Sensor abgeleiteter Tatsachenwert".
     *
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function markPath(array $frame): array
    {
        $frame['dispatch_path'] = self::pathOf($frame)->value;

        $flushedAt = $frame['flushed_at'] ?? null;

        if (\is_string($flushedAt)) {
            $timestamp = strtotime($flushedAt);

            if (false !== $timestamp) {
                $frame['spool_delay_ms'] = max(0, (int) round((microtime(true) - $timestamp) * 1000));
            }
        }

        return $frame;
    }

    /**
     * Löscht die abgearbeitete Datei — es sei denn, es ist noch etwas dazugekommen.
     *
     * Das schließt das letzte verbliebene Verlustfenster. Der Drainer beansprucht auch
     * Dateien, an die ein lebender Prozess in derselben Sekunde noch anhängt; landet
     * seine Zeile zwischen unserem EOF und dem `unlink()`, wäre sie sonst weg. Der
     * Vergleich der Dateilänge mit der gelesenen Position sagt genau das.
     *
     * Gerettet wird nur der SCHWANZ, nicht die ganze Datei: Die bereits gesendeten
     * Zeilen erneut zu schicken wäre zwar durch die at-least-once-Zusage aus Konzept 4.
     * gedeckt, aber unnötig.
     */
    private function discardOrKeepTail(string $file, int $readUpTo): void
    {
        $size = @filesize($file);

        if (false === $size || $size <= $readUpTo) {
            @unlink($file);

            return;
        }

        $tail = @file_get_contents($file, false, null, $readUpTo);

        if (false === $tail || '' === trim($tail)) {
            @unlink($file);

            return;
        }

        $target = $this->spool->sealedFileName();

        if (false !== @file_put_contents($target, $tail)) {
            @unlink($file);

            return;
        }

        $this->logger?->error(
            'ids_sensor: Nachzügler in {file} konnte nicht gesichert werden — die Datei bleibt liegen.',
            ['file' => $file],
        );
    }

    /**
     * Der Weg, den dieser Frame tatsächlich genommen hat.
     *
     * Ein planmäßig gespoolter Frame bleibt `deferred`, alles andere wird `recovered`.
     * Ein fehlender oder unbekannter Wert gilt als Nachlauf — die vorsichtigere der
     * beiden Auskünfte, weil sie den Collector höchstens zu wenig auswerten lässt.
     *
     * @param array<string, mixed> $frame
     */
    private static function pathOf(array $frame): DispatchPath
    {
        if (DispatchPath::Deferred->value === ($frame['dispatch_path'] ?? null)) {
            return DispatchPath::Deferred;
        }

        return DispatchPath::Recovered;
    }

    /**
     * @param list<string> $remaining Zeilen, die erneut versucht werden müssen
     * @param int          $readUpTo  Dateiposition, bis zu der gelesen wurde
     */
    private function finish(string $file, array $remaining, int $readUpTo): void
    {
        if ([] === $remaining) {
            $this->discardOrKeepTail($file, $readUpTo);

            return;
        }

        // Rest zurückschreiben: über temporäre Datei und rename(), damit bei einem
        // Abbruch keine halbe Datei entsteht. Der Name verliert den
        // .draining-Zusatz, damit der nächste Lauf ihn wieder findet.
        // Ein FRISCHER Name, nicht der ursprüngliche. Der alte gehörte einem
        // schreibenden Prozess; ihn zurückzuschreiben überschrieb alles, was der
        // während des ganzen Drain-Laufs erfasst hatte — der größte der drei
        // Verlustwege, und rename() meldet ihn nicht einmal.
        $target = $this->spool->sealedFileName();
        $temporary = $file.'.tmp';

        if (false !== @file_put_contents($temporary, implode("\n", $remaining)."\n")) {
            @rename($temporary, $target);
            @unlink($file);

            return;
        }

        // Schweigen wäre hier das Gefährlichste: Der Rest der Datei bleibt unter
        // `.draining` liegen, also unsichtbar für Heartbeat und setup-check. Erst
        // reclaimStalled() holt sie beim nächsten Lauf zurück — dass es dazu kam, gehört
        // ins Protokoll. Häufigste Ursache ist die volle Platte, und die ist genau dann
        // wahrscheinlich, wenn ein Spool nachgesendet wird.
        $this->logger?->error(
            'ids_sensor: Rest der Spool-Datei {file} konnte nicht zurückgeschrieben werden. Sie '
            .'bleibt beansprucht liegen und wird nach {stale}s automatisch zurückgeholt.',
            ['file' => $file, 'stale' => $this->staleAfterSeconds],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
