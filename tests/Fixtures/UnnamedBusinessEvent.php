<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Ein Event, dessen Getter FUNKTIONIEREN, aber einen leeren Namen liefern.
 *
 * Das Gegenstück zu {@see BrokenBusinessEvent}: Beide landen als `business.unnamed`
 * im Frame, und genau deshalb muss unterscheidbar bleiben, welcher von beiden es war.
 */
final class UnnamedBusinessEvent implements SecurityRelevantBusinessEvent
{
    public function __construct(
        private readonly ?string $actorId = 'bob',
    ) {
    }

    public function getEventName(): string
    {
        return '';
    }

    public function getSeverityHint(): string
    {
        return 'warning';
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return ['grund' => 'ohne Namen'];
    }
}
