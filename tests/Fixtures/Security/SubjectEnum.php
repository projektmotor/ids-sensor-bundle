<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures\Security;

enum SubjectEnum: string
{
    case Draft = 'draft';
    case Published = 'published';
}
