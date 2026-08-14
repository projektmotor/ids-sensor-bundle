<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Lehnt VIEW auf TestOrder immer ab — der IDOR-Fall aus dem Konzeptszenario S7.
 *
 * @extends Voter<string, TestOrder>
 */
final class TestOrderVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'VIEW' === $attribute && $subject instanceof TestOrder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return false;
    }
}
