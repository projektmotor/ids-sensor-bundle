<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelation;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor;
use ProjektMotor\IdsSensor\Sensor\Security\ResourceIdentifierResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Der Signaturvertrag des AccessDecisionManager-Decorators.
 *
 * Das Interface ist über die Versionen stabil, die KONKRETE Klasse nicht: Symfony 6.4
 * hat einen Zusatzparameter (`bool $allowMultipleAttributes`), 7.x hat zwei
 * (`bool|AccessDecision|null $accessDecision`, `bool $allowMultipleAttributes`) — und
 * echte Aufrufer übergeben sie POSITIONAL. AccessListener etwa übergibt `true` als
 * vierten Parameter.
 *
 * Ein Decorator mit fester Parameterliste würde diese Argumente je nach Symfony-Version
 * verschlucken und damit das Autorisierungsverhalten der überwachten Anwendung ändern —
 * der schlimmste denkbare Fehler in einem Bundle, das nur beobachten soll. Diese Tests
 * halten die variadische Weiterleitung fest.
 */
final class AccessDecisionSensorSignatureTest extends TestCase
{
    public function testTheDecoratorAcceptsArbitraryExtraArguments(): void
    {
        $decide = new \ReflectionMethod(AccessDecisionSensor::class, 'decide');

        self::assertTrue($decide->isVariadic(), 'Ohne variadischen Rest bricht der Decorator bei jedem Versionswechsel');

        $parameters = $decide->getParameters();
        self::assertCount(4, $parameters, 'token, attributes, object und der variadische Rest');
        self::assertTrue($parameters[3]->isVariadic());
    }

    /**
     * Der eigentliche Nachweis: alles, was die konkrete Klasse dieser Symfony-Version
     * jenseits von $object annimmt, deckt unser variadischer Rest ab.
     */
    public function testEveryExtraParameterOfTheConcreteClassIsCovered(): void
    {
        $konkret = new \ReflectionMethod(AccessDecisionManager::class, 'decide');
        $zusatz = \array_slice($konkret->getParameters(), 3);

        self::assertNotSame([], $zusatz, 'Erwartet mindestens einen Zusatzparameter — sonst ist dieser Test wertlos');

        $unsere = new \ReflectionMethod(AccessDecisionSensor::class, 'decide');
        self::assertTrue(
            $unsere->isVariadic(),
            \sprintf(
                'Die konkrete Klasse nimmt %d Zusatzparameter (%s); ohne variadischen Rest gehen sie verloren',
                \count($zusatz),
                implode(', ', array_map(static fn (\ReflectionParameter $p): string => '$'.$p->getName(), $zusatz)),
            ),
        );
    }

    public function testExtraArgumentsArePassedThroughUnchanged(): void
    {
        $inner = new class implements AccessDecisionManagerInterface {
            /** @var list<mixed> */
            public array $rest = [];

            /**
             * @param array<array-key, mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
            {
                $this->rest = array_values($rest);

                return true;
            }
        };

        $sensor = $this->sensor($inner);
        $sensor->decide(new NullToken(), ['ROLE_USER'], null, true, false);

        self::assertSame([true, false], $inner->rest);
    }

    #[DataProvider('decisions')]
    public function testTheDecisionIsReturnedUnchanged(bool $granted): void
    {
        $sensor = $this->sensor($this->fixedInner($granted));

        self::assertSame($granted, $sensor->decide(new NullToken(), ['ROLE_USER']));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function decisions(): iterable
    {
        yield 'granted' => [true];
        yield 'denied' => [false];
    }

    /**
     * Die fail-open-Zusage an der empfindlichsten Stelle: ein Fehler in der Erfassung
     * darf die Autorisierungsentscheidung nicht beeinflussen. Andernfalls könnte ein Bug
     * im Sensor eine Anwendung unbenutzbar machen — oder, schlimmer, Zugriff gewähren.
     */
    public function testAnErrorDuringCaptureDoesNotChangeTheDecision(): void
    {
        // Der Fehler wird über den Token-Speicher eingespeist: ActorFactory::currentUser()
        // ruft ihn im Erfassungspfad auf und fängt nichts ab. Damit ist der Fehler dort,
        // wo er in der Praxis am wahrscheinlichsten auftritt — in fremdem Code, den der
        // Sensor nur benutzt.
        $kaputterSpeicher = new class implements TokenStorageInterface {
            public function getToken(): ?TokenInterface
            {
                throw new \RuntimeException('Token-Speicher kaputt');
            }

            public function setToken(?TokenInterface $token): void
            {
            }
        };

        $sensor = $this->sensor($this->fixedInner(false), $kaputterSpeicher);

        self::assertFalse($sensor->decide(new NullToken(), ['VIEW'], new \stdClass()));
    }

    private function fixedInner(bool $granted): AccessDecisionManagerInterface
    {
        return new class($granted) implements AccessDecisionManagerInterface {
            public function __construct(private readonly bool $granted)
            {
            }

            /**
             * @param array<array-key, mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
            {
                return $this->granted;
            }
        };
    }

    private function sensor(
        AccessDecisionManagerInterface $inner,
        ?TokenStorageInterface $tokenStorage = null,
    ): AccessDecisionSensor {
        return new AccessDecisionSensor(
            $inner,
            new EventBuffer(100),
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(
                    new SessionIdHasher(null, false),
                    new ClientFingerprinter(enabled: false),
                    $tokenStorage,
                ),
                new ConsoleCorrelation(new CorrelationIdFactory()),
            ),
            new ResourceIdentifierResolver(),
            new CaptureBudget(0),
            new RequestStack(),
        );
    }
}
