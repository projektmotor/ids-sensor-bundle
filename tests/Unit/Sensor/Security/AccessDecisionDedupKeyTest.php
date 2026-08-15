<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Security;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Payload\SecurityPayload;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor;
use ProjektMotor\IdsSensor\Sensor\Security\ResourceIdentifierResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Der Dedup-Schlüssel darf keine zwei verschiedenen Entscheidungen zusammenfallen lassen.
 *
 * Er wurde aus Attribut, Ressource und Ergebnis zusammengesetzt, und „keine Ressource"
 * stand darin als `'-'`. Ein Bindestrich ist aber eine gültige Ressourcenkennung — dann
 * verschwand die zweite Entscheidung dedupliziert, ohne dass irgendetwas sie zählte.
 * Beide Wege zusätzlich gegen `max_decisions_per_request`.
 */
final class AccessDecisionDedupKeyTest extends TestCase
{
    public function testARoleCheckDoesNotSwallowADecisionOnADashResource(): void
    {
        $buffer = new EventBuffer(100);
        $sensor = $this->sensor($buffer);

        $sensor->decide($this->token(), ['VIEW']);
        $sensor->decide($this->token(), ['VIEW'], '-');

        self::assertCount(2, $buffer->all(), 'Rollenprüfung und Ressource "-" sind zwei Entscheidungen');
    }

    /**
     * Eine Ressource, die zur leeren Zeichenkette wird, ist keine Ressource.
     *
     * `is_scalar('')` und `(string) false` lieferten `''`. Für den Collector ist das ein
     * dritter Zustand neben Kennung und „nicht vorhanden", nach dem er gruppiert.
     */
    public function testAnEmptyResourceBecomesNull(): void
    {
        $buffer = new EventBuffer(100);
        $sensor = $this->sensor($buffer);

        $sensor->decide($this->token(), ['VIEW'], '');

        $events = $buffer->all();
        self::assertCount(1, $events);
        self::assertNull($events[0]->get(SecurityPayload::FIELD_RESOURCE));
    }

    private function sensor(EventBuffer $buffer): AccessDecisionSensor
    {
        return new AccessDecisionSensor(
            $this->grantingManager(),
            $buffer,
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(
                    new SessionIdHasher(null, null, false),
                    new ClientFingerprinter(enabled: false),
                ),
            ),
            new ResourceIdentifierResolver(),
            new CaptureBudget(0),
            new RequestStack(),
        );
    }

    private function grantingManager(): AccessDecisionManagerInterface
    {
        return new class implements AccessDecisionManagerInterface {
            /**
             * @param array<array-key, mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
            {
                return true;
            }
        };
    }

    private function token(): TokenInterface
    {
        return new class implements TokenInterface {
            public function getUserIdentifier(): string
            {
                return 'anna';
            }

            public function getUser(): ?\Symfony\Component\Security\Core\User\UserInterface
            {
                return null;
            }

            public function setUser(\Symfony\Component\Security\Core\User\UserInterface $user): void
            {
            }

            /**
             * @return list<string>
             */
            public function getRoleNames(): array
            {
                return ['ROLE_USER'];
            }

            /**
             * @return array<string, mixed>
             */
            public function getAttributes(): array
            {
                return [];
            }

            /**
             * @param array<string, mixed> $attributes
             */
            public function setAttributes(array $attributes): void
            {
            }

            public function hasAttribute(string $name): bool
            {
                return false;
            }

            public function getAttribute(string $name): mixed
            {
                return null;
            }

            public function setAttribute(string $name, mixed $value): void
            {
            }

            public function eraseCredentials(): void
            {
            }

            public function __toString(): string
            {
                return 'anna';
            }

            /**
             * @return array<mixed>
             */
            public function __serialize(): array
            {
                return [];
            }

            /**
             * @param array<mixed> $data
             */
            public function __unserialize(array $data): void
            {
            }
        };
    }
}
