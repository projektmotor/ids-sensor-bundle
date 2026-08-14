<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Breaker;

/**
 * Der Zustand des Circuit Breakers.
 *
 * @internal
 */
final class BreakerState
{
    public function __construct(
        public readonly int $failures = 0,
        public readonly float $openUntil = 0.0,
        public readonly int $openCount = 0,
    ) {
    }

    public static function closed(): self
    {
        return new self();
    }

    public function isOpenAt(float $now): bool
    {
        return $this->openUntil > $now;
    }

    /**
     * @return array{failures: int, open_until: float, open_count: int}
     */
    public function toArray(): array
    {
        return [
            'failures' => $this->failures,
            'open_until' => $this->openUntil,
            'open_count' => $this->openCount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_numeric($data['failures'] ?? null) ? (int) $data['failures'] : 0,
            is_numeric($data['open_until'] ?? null) ? (float) $data['open_until'] : 0.0,
            is_numeric($data['open_count'] ?? null) ? (int) $data['open_count'] : 0,
        );
    }
}
