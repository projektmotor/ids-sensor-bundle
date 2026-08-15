<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Heartbeat;

/**
 * Sendet ein Lebenszeichen, wenn eines fällig ist.
 *
 * WOZU EIN INTERFACE FÜR EINEN EINZIGEN IMPLEMENTIERER
 *
 * Nicht wegen der Austauschbarkeit — es wird auf absehbare Zeit genau einen
 * {@see Emitter} geben. Sondern wegen der Naht: {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener}
 * entscheidet anhand des Versandbudgets aus Konzept 4., ob das Lebenszeichen noch
 * hinausgeht. Diese Entscheidung ist eine Zusage, also gehört sie geprüft — und mit dem
 * finalen Emitter im Konstruktor ging das nicht: Ihn zu bauen verlangt eine
 * {@see PayloadFactory} mit zehn Abhängigkeiten, für einen Test, der nur wissen will, ob
 * überhaupt gesendet wurde.
 *
 * Ein untestbarer Schutzmechanismus ist kein Schutzmechanismus. Das ist der Grund.
 *
 * @internal
 */
interface EmitterInterface
{
    /**
     * @return bool ob tatsächlich gesendet wurde
     */
    public function emitIfDue(Mode $trigger): bool;
}
