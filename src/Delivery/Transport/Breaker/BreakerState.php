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

    /**
     * @param float $maxOpenSeconds Die konfigurierte Offen-Zeit. Ein Zielzeitpunkt, der
     *                              weiter als das in der Zukunft liegt, kann nicht von
     *                              diesem Sensor stammen und wird ignoriert.
     */
    public function isOpenAt(float $now, float $maxOpenSeconds = 0.0): bool
    {
        if ($this->openUntil <= $now) {
            return false;
        }

        // `openUntil` ist absolute Wanduhrzeit und überlebt im Dateirückfall Prozess und
        // Neustart — dort gibt es keine TTL, die einen Uhr-Rücksprung kappt. Springt die
        // Uhr um eine Stunde zurück, bliebe der Breaker eine Stunde offen: Der Sensor
        // spoolte durchgehend, obwohl der Broker längst wieder läuft, und im Heartbeat
        // stünde `state: open` ohne einen einzigen frischen Fehlschlag.
        //
        // Länger als die konfigurierte Offen-Zeit kann der Zustand nie berechtigt sein.
        if ($maxOpenSeconds > 0.0 && ($this->openUntil - $now) > $maxOpenSeconds) {
            return false;
        }

        return true;
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
