<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;

/**
 * Ein Business-Event nach Vorgangsklasse V4 aus Konzept 2.1.3: wertverändernder
 * Vorgang oberhalb einer Schwelle.
 */
final class OrderAmountOverridden implements SecurityRelevantBusinessEvent
{
    public function __construct(
        private readonly int $orderId = 42,
        private readonly float $original = 19.99,
        private readonly float $overridden = 0.01,
        private readonly ?string $actorId = 'alice',
    ) {
    }

    public function getEventName(): string
    {
        return 'order.amount_overridden';
    }

    public function getSeverityHint(): string
    {
        return Severity::Critical->value;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getPayload(): array
    {
        return [
            'order_id' => $this->orderId,
            'original_amount' => $this->original,
            'overridden_amount' => $this->overridden,
        ];
    }
}
