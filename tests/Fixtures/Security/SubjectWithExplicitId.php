<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

use ProjektMotor\IdsSensor\Contract\IdsResourceIdentifier;

/** Die ausdrückliche Angabe der Anwendung hat Vorrang vor jeder Ableitung. */
final class SubjectWithExplicitId implements IdsResourceIdentifier
{
    public function getIdsResourceId(): string
    {
        return 'Invoice#2026-0815';
    }

    public function getId(): int
    {
        return 99;
    }
}
