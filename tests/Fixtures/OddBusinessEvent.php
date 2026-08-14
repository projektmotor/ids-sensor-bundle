<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Ein Event, das sich nicht an die Empfehlungen hält: abweichender Name, unbrauchbarer
 * Severity-Hint, verschachtelte Nutzlast mit Objekten und reservierten Schlüsseln.
 */
final class OddBusinessEvent implements SecurityRelevantBusinessEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $name = 'Order Amount Überschrieben!',
        private readonly string $hint = 'sehr kritisch',
        private readonly array $payload = [],
    ) {
    }

    public function getEventName(): string
    {
        return $this->name;
    }

    public function getSeverityHint(): string
    {
        return $this->hint;
    }

    public function getActorId(): ?string
    {
        return null;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
