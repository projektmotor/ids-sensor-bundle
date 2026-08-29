<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Shipper;

/**
 * Verwirft Frames.
 *
 * Die Vorgabe, solange keine `collector.base_uri` konfiguriert ist. Damit ist das Bundle
 * installierbar, bevor Infrastruktur bereitsteht — und das Erfassungsbudget aus
 * Konzept 2.1 lässt sich beweisen, ohne dass Broker-Latenz und Sensor-Kosten
 * vermischt werden.
 *
 * NICHT der Kill-Schalter. Hier stand `ids_sensor.enabled: false` als zweite
 * Verwendung; bei dem Wert kehrt `IdsSensorBundle::loadExtension()` zurück, BEVOR
 * `services.yaml` importiert wird — es gibt dann überhaupt keinen Shipper, keinen
 * Sensor und keinen Listener. Genau das ist dort die Absicht: „registriert bewusst
 * gar keine Listener, statt sie zur Laufzeit abzufragen".
 *
 * @internal
 */
final class NullShipper implements ShipperInterface
{
    private int $shipped = 0;

    private int $heartbeats = 0;

    /**
     * @param array<string, mixed> $frame
     */
    public function ship(array $frame): void
    {
        $events = $frame['events'] ?? [];
        $this->shipped += \is_array($events) ? \count($events) : 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shipHeartbeat(array $payload): void
    {
        ++$this->heartbeats;
    }

    public function shippedEvents(): int
    {
        return $this->shipped;
    }

    public function shippedHeartbeats(): int
    {
        return $this->heartbeats;
    }
}
