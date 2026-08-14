<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;

/**
 * Sammelt Frames im Speicher, statt sie zu versenden.
 *
 * Hält die Frame-ARRAYS — also genau das, was auf dem Draht landet. Damit prüfen die
 * Tests die Sendung und nicht ein Zwischenobjekt.
 */
final class CollectingShipper implements ShipperInterface
{
    /** @var list<array<string, mixed>> */
    private array $frames = [];

    /** @var list<array<string, mixed>> */
    private array $heartbeats = [];

    public function __construct(
        private readonly ?\Throwable $failWith = null,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function ship(array $frame): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }

        $this->frames[] = $frame;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shipHeartbeat(array $payload): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }

        $this->heartbeats[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function heartbeats(): array
    {
        return $this->heartbeats;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastHeartbeat(): ?array
    {
        return $this->heartbeats[\count($this->heartbeats) - 1] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastFrame(): ?array
    {
        return $this->frames[\count($this->frames) - 1] ?? null;
    }

    public function frameCount(): int
    {
        return \count($this->frames);
    }

    /**
     * Anzahl der Events im letzten Frame.
     */
    public function lastEventCount(): int
    {
        $events = $this->lastFrame()['events'] ?? [];

        return \is_array($events) ? \count($events) : 0;
    }
}
