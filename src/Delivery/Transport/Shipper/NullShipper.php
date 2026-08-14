<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Shipper;

/**
 * Verwirft Frames.
 *
 * Zwei Verwendungen:
 *  - `ids_sensor.enabled: false` — der Kill-Schalter, ohne das Bundle zu entfernen
 *  - Messung des Erfassungsbudgets ohne jede Broker-Beteiligung
 *
 * Der zweite Punkt ist der wichtigere: das Budget aus Konzept 2.1 Sensorik lässt
 * sich damit beweisen, bevor überhaupt ein Transport existiert. Broker-Latenz und
 * Sensor-Kosten sind so nicht vermischt.
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
