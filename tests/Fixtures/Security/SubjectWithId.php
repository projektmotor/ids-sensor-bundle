<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

/** Der Normalfall: eine Entity mit skalarer Kennung. */
final class SubjectWithId
{
    public function __construct(private readonly int|string $id)
    {
    }

    public function getId(): int|string
    {
        return $this->id;
    }
}
