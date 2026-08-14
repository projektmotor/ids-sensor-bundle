<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

/**
 * __toString() darf NICHT benutzt werden: es könnte nachladen und schreibt im Zweifel
 * personenbezogene Daten aus.
 */
final class SubjectWithToString
{
    public function __toString(): string
    {
        return 'geheimer.kunde@example.com';
    }
}
