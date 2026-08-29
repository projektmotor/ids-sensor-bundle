<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Processing\Normalization\UuidGeneratorInterface;

/**
 * Vorhersagbare Kennungen für Tests und Golden Files — event_id wie frame_id.
 */
final class SequentialUuidGenerator implements UuidGeneratorInterface
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
