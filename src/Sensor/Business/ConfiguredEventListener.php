<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Business;

use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Hört auf ausdrücklich benannte Event-Klassen (capture_mode: configured).
 *
 * Für Deployments, die eine Dekoration von `event_dispatcher` ablehnen, aber auch nicht
 * jede Meldestelle im Fachcode anfassen wollen. Die Listener werden zur Compile-Zeit
 * für die in `layers.business.event_classes` genannten FQCN registriert — das ist der
 * Weg, den Symfonys Dispatcher tatsächlich unterstützt: exakter Event-Name.
 *
 * Preis: die Liste muss gepflegt werden. Eine neue Event-Klasse, die niemand einträgt,
 * bleibt unsichtbar.
 *
 * @internal
 */
final class ConfiguredEventListener
{
    public function __construct(
        private readonly EventSensor $sensor,
    ) {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof SecurityRelevantBusinessEvent) {
            return;
        }

        try {
            $this->sensor->capture($event);
        } catch (\Throwable) {
            // fail-open.
        }
    }
}
