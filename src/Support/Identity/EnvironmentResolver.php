<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Identity;

use ProjektMotor\IdsEventData\Vocabulary\Environment;
use Psr\Log\LoggerInterface;

/**
 * Übersetzt den konfigurierten Umgebungsnamen in das collectorseitige Enum.
 *
 * Warum diese Klasse überhaupt existiert: Konzept 2.2.1 erlaubt für environment
 * genau drei Werte, und Konzept 4.2.1 Tabellenschema führt sie als
 * `env_type NOT NULL`. `%kernel.environment%` ist dagegen ein beliebiger String —
 * "test", "prod_eu", "staging2" sind alle gültige Symfony-Umgebungen.
 *
 * Was ohne Übersetzung passiert: Der Insert auf der Collector-Seite scheitert am
 * Enum, und zwar für JEDES Event dieser Instanz. Das Ergebnis ist ein stiller
 * Totalverlust, der von einem toten Sensor nicht zu unterscheiden ist — genau die
 * Klasse von lautlosem Versagen, die das Konzept beim Stilllegen des Sensors als
 * besonders gefährlich beschreibt.
 *
 * @internal
 */
final class EnvironmentResolver
{
    /**
     * Deckt die gängige Benennung ab. Anwendungskonfiguration wird über diese
     * Vorgaben gemischt, nicht ersetzt.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MAP = [
        'prod' => 'prod',
        'production' => 'prod',
        'live' => 'prod',
        'staging' => 'staging',
        'stage' => 'staging',
        'preprod' => 'staging',
        'dev' => 'dev',
        'develop' => 'dev',
        'development' => 'dev',
        'local' => 'dev',
        'test' => 'dev',
    ];

    private ?Environment $resolved = null;

    private bool $warned = false;

    /**
     * @param array<string, string> $map
     */
    public function __construct(
        private readonly string $configuredEnvironment,
        private readonly array $map = self::DEFAULT_MAP,
        private readonly Environment $fallback = Environment::Prod,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Löst einmalig auf und merkt sich das Ergebnis — die Umgebung ändert sich
     * während der Prozesslaufzeit nicht.
     */
    public function resolve(): Environment
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $raw = strtolower(trim($this->configuredEnvironment));

        $mapped = $this->map[$raw] ?? null;
        $environment = null !== $mapped ? Environment::tryFrom($mapped) : Environment::tryFrom($raw);

        if (null === $environment) {
            $environment = $this->fallback;
            $this->warnOnce($raw);
        }

        return $this->resolved = $environment;
    }

    /**
     * Ob der konfigurierte Wert überhaupt auflösbar ist.
     *
     * Wird von ids:sensor:setup-check benutzt, um im Deploy mit Exit-Code != 0
     * abzubrechen — dort ist ein harter Abbruch richtig, im Request-Pfad nicht.
     */
    public function isResolvable(): bool
    {
        $raw = strtolower(trim($this->configuredEnvironment));

        if (isset($this->map[$raw])) {
            return null !== Environment::tryFrom($this->map[$raw]);
        }

        return null !== Environment::tryFrom($raw);
    }

    public function configuredValue(): string
    {
        return $this->configuredEnvironment;
    }

    /**
     * Warum der Fallback prod ist und nicht dev: Fälschlich als prod markierter
     * Verkehr wird weiterhin erkannt — nur seine Baseline ist leicht verunreinigt.
     * Fälschlich als dev markierter Produktionsverkehr fällt dagegen aus JEDER
     * Aggregation der Produktionsregeln heraus (Konzept 2.2.1 — verbindliche
     * Aggregationsregel) und erzeugt einen vollständigen blinden Fleck. Beide
     * Fehlerfälle sind unerwünscht; der, der die Erkennung am Leben hält, gewinnt.
     */
    private function warnOnce(string $raw): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        // Der Logger im eigenen try: `resolve()` läuft im Request-Pfad, und fail-open
        // gilt ohne Ausnahme (Konzept 4.). Ein Monolog-Handler, der auf ein volles
        // Dateisystem schreibt, ist der realistische Fall — er darf die Umgebung nicht
        // kosten, deren Auflösung ohnehin schon einen Rückfall benutzt. Derselbe Fehler
        // steckte im CaptureBudget und im EventFlusher.
        try {
            $this->logger?->critical(
                'ids_sensor: environment "{configured}" ist nicht auf prod|staging|dev abbildbar, '
                .'es wird "{fallback}" verwendet. Bitte ids_sensor.environment_map ergänzen — '
                .'sonst sind alle Events dieser Instanz falsch zugeordnet.',
                ['configured' => $raw, 'fallback' => $this->fallback->value],
            );
        } catch (\Throwable) {
            // Nicht einmal ein zweiter Logversuch: Wer hier scheitert, scheitert auch dort.
        }
    }
}
