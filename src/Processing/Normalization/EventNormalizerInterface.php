<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

/**
 * Übersetzt ein erfasstes Event in das verbindliche Format aus Konzept Abschnitt 3.
 *
 * Je Beobachtungsebene eine Umsetzung — entsprechend der Architekturentscheidung aus
 * Konzept 2.1: Sensor und Normalisierer sind fest gekoppelt (ein Baustein), nicht
 * zwei beliebig kombinierbare Komponenten.
 *
 * Läuft in Phase B, nach dem Absenden der Antwort. Darf deshalb rechnen, aber
 * weiterhin keine Datenbank und kein Netzwerk anfassen.
 *
 * @internal
 */
interface EventNormalizerInterface
{
    public function supports(CapturedEvent $captured): bool;

    public function normalize(CapturedEvent $captured, SensorIdentity $identity): NormalizedEvent;
}
