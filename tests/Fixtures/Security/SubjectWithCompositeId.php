<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

/** Zusammengesetzter Schlüssel. */
final class SubjectWithCompositeId
{
    /**
     * @return array<string, int|string>
     */
    public function getId(): array
    {
        return ['tenant' => 7, 'order' => 'A-42'];
    }
}
