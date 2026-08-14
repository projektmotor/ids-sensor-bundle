<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

/**
 * Erzeugt die event_id.
 *
 * Als Interface, damit Tests deterministische IDs einsetzen können — sonst wären
 * die Golden Files (der Formatvertrag gegenüber dem Collector) nicht vergleichbar.
 *
 * @internal
 */
interface EventIdGeneratorInterface
{
    public function generate(): string;
}
