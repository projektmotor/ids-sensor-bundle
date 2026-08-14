<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Breaker;

/**
 * Hält den Zustand des Circuit Breakers über Requests hinweg.
 *
 * Der Zustand MUSS prozessübergreifend sichtbar sein, sonst ist der Breaker
 * wirkungslos: unter PHP-FPM bearbeitet jedes Kindprozess-Exemplar eigene Requests,
 * und ein nur im Prozessspeicher gehaltener Zähler würde bei einem Broker-Ausfall in
 * jedem Kindprozess von Null anfangen. Genau dann soll der Breaker aber greifen.
 *
 * @internal
 */
interface BreakerStateStoreInterface
{
    public function read(): BreakerState;

    public function write(BreakerState $state): void;
}
