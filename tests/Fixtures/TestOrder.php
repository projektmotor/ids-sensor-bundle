<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

/**
 * Ein Voter-Subjekt mit getId() — prüft, dass der ResourceIdentifierResolver daraus
 * `TestOrder#43` bildet.
 */
final class TestOrder
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}
