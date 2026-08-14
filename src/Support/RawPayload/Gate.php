<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\RawPayload;

use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;

/**
 * Entscheidet, ob ein Event sein raw-Feld überhaupt behalten darf.
 *
 * Konzept Abschnitt 3 legt „nur warning und critical" fest; `ids_sensor.raw.severities`
 * erlaubt, das weiter einzuschränken (etwa auf `critical` allein, wenn das Volumenbudget
 * aus 4.2.3 gerissen wird).
 *
 * Die Entscheidung fällt in der {@see \ProjektMotor\IdsSensor\Processing\Normalization\EventFactory} und
 * damit an der Stelle, an der Severity und raw-Closure zum ersten Mal beide vorliegen.
 * Wird raw hier verworfen, wird die Closure NIE aufgerufen — die Kosten für
 * Header-Kopie, Redaktion und Trace-Aufbau entstehen also gar nicht.
 *
 * @internal
 */
final class Gate
{
    /** @var array<string, true> */
    private array $allowed = [];

    /**
     * @param list<string> $severities
     */
    public function __construct(
        private readonly bool $enabled = true,
        array $severities = ['warning', 'critical'],
    ) {
        foreach ($severities as $severity) {
            $this->allowed[$severity] = true;
        }
    }

    public function allows(Severity $severity): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Die eingebaute Grenze aus Konzept Abschnitt 3 bleibt bestehen, auch wenn die
        // Konfiguration info zuließe: raw für jedes info-Event würde das Volumenbudget
        // aus 4.2.3 um Größenordnungen reißen. Die Konfiguration kann die Menge
        // verkleinern, nicht vergrößern.
        if (!$severity->carriesRaw()) {
            return false;
        }

        return isset($this->allowed[$severity->value]);
    }
}
