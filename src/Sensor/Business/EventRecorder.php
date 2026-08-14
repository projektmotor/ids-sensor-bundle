<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Business;

use ProjektMotor\IdsSensor\Contract\BusinessEventRecorderInterface;
use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Der explizite Übergabeweg (capture_mode: recorder).
 *
 * Die Anwendung injiziert {@see BusinessEventRecorderInterface} und ruft record() auf.
 * Gegenüber dem Dispatcher-Decorator:
 *
 *  + kein Eingriff in einen zentralen Symfony-Service, damit ein kleinerer
 *    Schadensradius
 *  + im Code-Review sichtbar und greppbar
 *  + funktioniert auch dort, wo kein EventDispatcher zur Hand ist
 *  − die Fachlogik nimmt eine sichtbare Abhängigkeit auf das IDS
 *  − das Melden kann vergessen werden
 *
 * Wird IMMER registriert, unabhängig vom konfigurierten Modus: eine Anwendung soll
 * einzelne Vorgänge explizit melden können, auch wenn sie sonst auf den Decorator
 * setzt.
 *
 * @internal
 */
final class EventRecorder implements BusinessEventRecorderInterface
{
    public function __construct(
        private readonly EventSensor $sensor,
    ) {
    }

    public function record(SecurityRelevantBusinessEvent $event): void
    {
        // Der Sensor kapselt Budget und fail-open bereits; dieses try ist die
        // Zusicherung des Interfaces, dass hier unter keinen Umständen etwas nach
        // außen dringt.
        try {
            $this->sensor->capture($event);
        } catch (\Throwable) {
            // Siehe Interface-Vertrag: niemals in die aufrufende Anwendung werfen.
        }
    }
}
