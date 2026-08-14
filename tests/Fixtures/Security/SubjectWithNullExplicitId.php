<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

use ProjektMotor\IdsSensor\Contract\IdsResourceIdentifier;

/** Gibt null zurück — dann greift die normale Ableitung. */
final class SubjectWithNullExplicitId implements IdsResourceIdentifier
{
    public function getIdsResourceId(): ?string
    {
        return null;
    }

    public function getId(): int
    {
        return 5;
    }
}
