<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Security;

use PHPUnit\Framework\TestCase;
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
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * actor.user meint den HANDELNDEN Akteur, nicht den geprüften Nutzer.
 *
 * Symfony 7.3 hat AuthorizationChecker::isGrantedForUser() eingeführt: damit kann Code
 * fragen „dürfte NUTZER X das?" und übergibt dafür einen künstlichen Token für einen
 * ANDEREN Nutzer an decide(). Würde der Sensor blind den übergebenen Token nehmen,
 * stünde in actor.user der geprüfte statt der handelnde Nutzer — und jede Auswertung,
 * die Ablehnungen einem Angreifer zuordnet, zeigte auf das falsche Konto.
 */
final class AccessDecisionActorTest extends TestCase
{
    public function testTheAmbientTokenTakesPrecedenceOverThePassedOne(): void
    {
        $collector = new EventBuffer(10);
        $sensor = $this->sensor($collector, $this->storageFor('alice'));

        // Die Prüfung erfolgt FÜR bob — gehandelt hat aber alice.
        $sensor->decide($this->token('bob'), ['VIEW'], null);

        self::assertSame('alice', $collector->all()[0]->actor()->user);
    }

    /**
     * Ohne Token im Speicher — etwa im Messenger-Worker — ist der übergebene Token die
     * beste verfügbare Auskunft und wird als Rückfall benutzt.
     */
    public function testWithoutAnAmbientTokenThePassedOneApplies(): void
    {
        $collector = new EventBuffer(10);
        $sensor = $this->sensor($collector, new TokenStorage());

        $sensor->decide($this->token('bob'), ['VIEW'], null);

        self::assertSame('bob', $collector->all()[0]->actor()->user);
    }

    /**
     * Ein NullToken steht für „nicht angemeldet". Seine Kennung ist die leere
     * Zeichenkette; die als Benutzernamen zu melden wäre eine Falschauskunft.
     */
    public function testANullTokenYieldsNoUser(): void
    {
        $collector = new EventBuffer(10);
        $sensor = $this->sensor($collector, new TokenStorage());

        $sensor->decide(new NullToken(), ['ROLE_USER'], null);

        self::assertNull($collector->all()[0]->actor()->user);
    }

    private function token(string $user): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($user, null), 'main');
    }

    private function storageFor(string $user): TokenStorage
    {
        $storage = new TokenStorage();
        $storage->setToken($this->token($user));

        return $storage;
    }

    private function sensor(EventBuffer $collector, TokenStorage $storage): AccessDecisionSensor
    {
        $inner = new class implements AccessDecisionManagerInterface {
            /**
             * @param array<array-key, mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
            {
                return false;
            }
        };

        return new AccessDecisionSensor(
            $inner,
            $collector,
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(new SessionIdHasher(null, null, false), new ClientFingerprinter(enabled: false), $storage),
            ),
            new ResourceIdentifierResolver(),
            new CaptureBudget(0),
            new RequestStack(),
        );
    }
}
