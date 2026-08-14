<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

/** getId() braucht ein Argument — kein aufrufbarer Getter. */
final class SubjectWithParametrizedId
{
    public function getId(string $locale): string
    {
        return $locale;
    }
}
