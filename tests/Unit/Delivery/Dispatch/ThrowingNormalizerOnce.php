<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Dispatch;

use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\EventNormalizerInterface;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

/**
 * Scheitert beim ersten Aufruf und gelingt danach.
 *
 * Prüft, dass ein einzelnes unnormalisierbares Event die übrigen Events desselben
 * Requests nicht mitnimmt.
 */
final class ThrowingNormalizerOnce implements EventNormalizerInterface
{
    private int $calls = 0;

    public function supports(CapturedEvent $captured): bool
    {
        return true;
    }

    public function normalize(CapturedEvent $captured, SensorIdentity $identity): NormalizedEvent
    {
        if (0 === $this->calls++) {
            throw new \RuntimeException('Normalisierung kaputt');
        }

        return new NormalizedEvent(
            'id-'.$this->calls,
            new \DateTimeImmutable('@0'),
            $captured->layer,
            $captured->eventType,
            $captured->correlationId() ?? '',
            Severity::Info,
            $identity,
            $captured->actor(),
        );
    }
}
