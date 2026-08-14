<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Processing\Normalization\EventIdGeneratorInterface;

/**
 * Vorhersagbare Event-IDs für Tests und Golden Files.
 */
final class SequentialEventIdGenerator implements EventIdGeneratorInterface
{
    private int $next = 1;

    public function __construct(
        private readonly string $prefix = '00000000-0000-7000-8000-',
    ) {
    }

    public function generate(): string
    {
        return $this->prefix.str_pad((string) $this->next++, 12, '0', \STR_PAD_LEFT);
    }
}
