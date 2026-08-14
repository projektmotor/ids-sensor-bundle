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
        // application_id, nicht die instance_id: der Broker ist für alle Instanzen
        // derselbe, also ist auch sein Ausfall gemeinsam.
        $this->apcuKey = self::APCU_KEY_PREFIX.$scopeKey;
        $this->file = rtrim($directory, '/').'/breaker.state';
        $this->useApcu = \function_exists('apcu_fetch') && \function_exists('apcu_store') && filter_var(
            \ini_get('apc.enabled'),
            \FILTER_VALIDATE_BOOLEAN,
        );
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
