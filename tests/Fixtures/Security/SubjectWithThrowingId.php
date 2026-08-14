<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

/**
 * getId() wirft — so verhält sich ein uninitialisierter Doctrine-Proxy, dessen
 * Lazy-Load fehlschlägt. Darf nie nach außen wirken.
 */
final class SubjectWithThrowingId
{
    public function getId(): int
    {
        throw new \RuntimeException('Lazy-Load fehlgeschlagen');
    }
}
