<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Ein Event, dessen Getter werfen — etwa weil getActorId() auf eine nicht geladene
 * Beziehung zugreift. Realistischer Fall, und er darf den Request nicht kosten.
 */
final class BrokenBusinessEvent implements SecurityRelevantBusinessEvent
{
    public function __construct(
        private readonly bool $breakName = false,
        private readonly bool $breakActor = true,
        private readonly bool $breakPayload = false,
        private readonly ?string $actorId = 'bob',
    ) {
    }

    public function getEventName(): string
    {
        if ($this->breakName) {
            throw new \RuntimeException('getEventName kaputt');
        }

        return 'user.roles_changed';
    }

    public function getSeverityHint(): string
    {
        return 'critical';
    }

    public function getActorId(): ?string
    {
        if ($this->breakActor) {
            throw new \RuntimeException('Beziehung nicht geladen');
        }

        return $this->actorId;
    }

    public function getPayload(): array
    {
        if ($this->breakPayload) {
            throw new \RuntimeException('getPayload kaputt');
        }

        return ['roles' => ['ROLE_ADMIN']];
    }
}
