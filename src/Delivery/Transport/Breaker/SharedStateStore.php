<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Breaker;

/**
 * Speichert den Breaker-Zustand prozessübergreifend: APCu, sonst Datei.
 *
 * APCu ist der Normalfall — das Segment ist bei PHP-FPM über alle Kindprozesse
 * hinweg geteilt, genau die Sichtbarkeit, die der Breaker braucht.
 *
 * Der Dateirückfall ist keine Zierde: APCu ist häufig nicht installiert, und ohne
 * Rückfall wäre der Breaker dort still wirkungslos — er würde in jedem Kindprozess
 * bei Null anfangen und nie öffnen. Also lieber ein stat() und ein kleiner
 * Dateizugriff im Flush-Pfad (der ohnehin nach dem Absenden der Antwort läuft) als
 * ein Schutz, der nur scheinbar existiert.
 *
 * WICHTIG: Die Zustandsdatei muss node-lokal liegen, wie der Spool. Auf einem
 * geteilten Volume würde der Breaker eines Hosts die anderen mit abschalten.
 *
 * @internal
 */
final class SharedStateStore implements BreakerStateStoreInterface
{
    private const APCU_KEY_PREFIX = 'ids_sensor.breaker.';

    /**
     * Lebensdauer des APCu-Eintrags in Sekunden.
     *
     * Deutlich über der längsten sinnvollen Offen-Zeit (Vorgabe: 30 s), damit ein
     * vergessener Eintrag von selbst verfällt, statt den Breaker dauerhaft offen zu
     * halten — aber nicht so knapp, dass ein regulär offener Breaker mitten in seiner
     * Wartezeit den Zustand verliert und die Fehlversuche von vorn zählt.
     */
    private const APCU_TTL_SECONDS = 3600;

    private readonly bool $useApcu;

    private readonly string $apcuKey;

    private readonly string $file;

    public function __construct(string $directory, string $scopeKey)
    {
        // Der Schlüssel grenzt den Zustand ab, damit in einem geteilten APCu-Segment
        // nicht zwei Anwendungen denselben Breaker benutzen. Verdrahtet wird die
        // application_id, nicht die sensor_id: der Collector ist für alle Sensoren
        // derselbe, also ist auch sein Ausfall gemeinsam.
        $this->apcuKey = self::APCU_KEY_PREFIX.$scopeKey;
        $this->file = rtrim($directory, '/').'/breaker.state';
        $this->useApcu = self::apcuUsable();
    }

    /**
     * Ob APCu hier und jetzt tatsächlich SPEICHERT.
     *
     * Hier stand `ini_get('apc.enabled')`, und das war in der CLI systematisch falsch:
     * Maßgeblich ist dort `apc.enable_cli`, per Vorgabe 0. `apc.enabled` meldete
     * trotzdem 1, also galt APCu als verwendbar, während `apcu_store()` folgenlos blieb
     * und `apcu_fetch()` immer `$success = false` lieferte. Folge: In jedem
     * CLI-Prozess las {@see read()} dauerhaft `closed()`, und der DATEIRÜCKFALL wurde
     * nie erreicht — der Breaker war dort still wirkungslos. Betroffen war unter anderem
     * `ids:sensor:spool:flush` per cron gegen einen ausgefallenen Broker: kein Öffnen,
     * also bei jedem Lauf das volle Timeout. Genau das Szenario, gegen das der Docblock
     * dieser Klasse argumentiert.
     *
     * `apcu_enabled()` berücksichtigt `apc.enable_cli`. Es ist dieselbe Prüfung, die
     * {@see \ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler} und
     * {@see \ProjektMotor\IdsSensor\Command\SetupCheckCommand} schon immer benutzt
     * haben — es gab drei Antworten auf dieselbe Frage.
     */
    private static function apcuUsable(): bool
    {
        return \function_exists('apcu_fetch')
            && \function_exists('apcu_store')
            && \function_exists('apcu_enabled')
            && @apcu_enabled();
    }

    public function read(): BreakerState
    {
        if ($this->useApcu) {
            $stored = apcu_fetch($this->apcuKey, $success);

            if (true === $success && \is_array($stored)) {
                return BreakerState::fromArray($stored);
            }

            return BreakerState::closed();
        }

        if (!is_file($this->file)) {
            return BreakerState::closed();
        }

        $raw = @file_get_contents($this->file);

        if (false === $raw || '' === $raw) {
            return BreakerState::closed();
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return BreakerState::closed();
        }

        return \is_array($decoded) ? BreakerState::fromArray($decoded) : BreakerState::closed();
    }

    /**
     * @param \Closure(BreakerState): BreakerState $mutator
     */
    public function mutate(\Closure $mutator): BreakerState
    {
        $handle = $this->lock();

        try {
            $state = $mutator($this->read());
            $this->write($state);

            return $state;
        } finally {
            if (null !== $handle) {
                @flock($handle, \LOCK_UN);
                @fclose($handle);
            }
        }
    }

    /**
     * Eine exklusive Sperre — oder null, wenn sie nicht zu bekommen war.
     *
     * `flock` auf einer eigenen Datei und nicht auf der Zustandsdatei: Diese wird über
     * `rename()` ersetzt, und eine Sperre auf dem alten Inode schützt danach nichts mehr.
     * Die Sperrdatei bleibt bestehen und ist damit der stabile Bezugspunkt.
     *
     * Auch im APCu-Fall wird die Datei benutzt. APCu hat kein Vergleiche-und-Tausche für
     * Arrays, und ein Spinlock über `apcu_add()` wäre eine zweite, schwächere Umsetzung
     * derselben Sache. Der Pfad läuft nach dem Absenden der Antwort — ein lokaler
     * `flock` ist dort bezahlbar.
     *
     * Lässt sich die Sperrdatei gar nicht öffnen — kein Verzeichnis, keine Rechte —, gibt
     * es eben keine Sperre: Der Aufrufer rechnet dann ungeschützt, statt aufzugeben. Ein
     * ungenauer Breaker ist besser als keiner, und fail-open (Konzept 4.) gilt ohne
     * Ausnahme.
     *
     * @return resource|null
     */
    private function lock()
    {
        $directory = \dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return null;
        }

        $handle = @fopen($this->file.'.lock', 'c');

        if (false === $handle) {
            return null;
        }

        // BLOCKIEREND, und das ist die richtige Wahl — auch unter fail-open.
        //
        // Hier stand zuerst ein nicht blockierender Versuch mit kurzer Wiederholung. Der
        // gab unter Last auf und rechnete dann ungeschützt weiter, also genau in der
        // Konstellation, für die es die Sperre gibt: Ein Messlauf mit vier Prozessen
        // verlor damit reproduzierbar Fehlschläge.
        //
        // Blockieren ist hier vertretbar, weil der kritische Abschnitt keine
        // Netzwerk-Ein-/Ausgabe enthält — ein Lesen und ein Schreiben auf einer kleinen,
        // node-lokalen Datei. Und der wichtigste Punkt: Stirbt ein Prozess, während er
        // die Sperre hält, gibt das Betriebssystem sie frei. Ein Verklemmen über
        // Prozessgrenzen hinweg ist damit ausgeschlossen.
        if (@flock($handle, \LOCK_EX)) {
            return $handle;
        }

        @fclose($handle);

        return null;
    }

    public function write(BreakerState $state): void
    {
        if ($this->useApcu) {
            apcu_store($this->apcuKey, $state->toArray(), self::APCU_TTL_SECONDS);

            return;
        }

        $directory = \dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $encoded = json_encode($state->toArray());

        if (false === $encoded) {
            return;
        }

        // Über temporäre Datei und rename(): ein gleichzeitiger Leser sieht entweder
        // den alten oder den neuen Zustand, nie einen halben.
        $temporary = $this->file.'.'.getmypid().'.tmp';

        if (false !== @file_put_contents($temporary, $encoded)) {
            @rename($temporary, $this->file);
        }
    }
}
