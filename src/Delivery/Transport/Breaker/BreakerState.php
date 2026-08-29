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
    /**
     * @param float $openForSeconds Wie lange DIESE Öffnung gelten sollte, als sie
     *                              geschrieben wurde. 0.0 heißt „nach der konfigurierten
     *                              Offen-Zeit" und ist der Normalfall; ein `Retry-After`
     *                              des Collectors trägt hier seine eigene, längere Dauer
     *                              ein (Konzept 3.6).
     */
    public function __construct(
        public readonly int $failures = 0,
        public readonly float $openUntil = 0.0,
        public readonly int $openCount = 0,
        public readonly float $openForSeconds = 0.0,
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
        // spoolte durchgehend, obwohl der Collector längst wieder läuft, und im Heartbeat
        // stünde `state: open` ohne einen einzigen frischen Fehlschlag.
        //
        // Länger als die vorgesehene Offen-Zeit kann der Zustand nie berechtigt sein.
        // Maßgeblich ist dabei die Dauer, die der Zustand SELBST mitbringt, sonst
        // verwürfe diese Prüfung genau die Fälle, für die es sie nicht gibt: eine
        // `Retry-After`-Sperre über der konfigurierten Offen-Zeit, und einen Zustand, der
        // unter einer anderen Konfiguration geschrieben wurde.
        $ceiling = max($maxOpenSeconds, $this->openForSeconds);

        if ($ceiling > 0.0 && ($this->openUntil - $now) > $ceiling) {
            return false;
        }

        return true;
    }

    /**
     * @return array{failures: int, open_until: float, open_count: int, open_for: float}
     */
    public function toArray(): array
    {
        return [
            'failures' => $this->failures,
            'open_until' => $this->openUntil,
            'open_count' => $this->openCount,
            'open_for' => $this->openForSeconds,
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
            // Fehlt in Zuständen, die eine ältere Fassung geschrieben hat. 0.0 bedeutet
            // dort dasselbe wie zuvor: es gilt die konfigurierte Offen-Zeit.
            is_numeric($data['open_for'] ?? null) ? (float) $data['open_for'] : 0.0,
        );
    }
}
