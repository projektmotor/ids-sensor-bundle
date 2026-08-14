<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\AppWithOwnBusKernel;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\PlainMessage;
use ProjektMotor\IdsSensor\Tests\Fixtures\PlainMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Das Bundle darf die Messenger-Einrichtung der überwachten Anwendung nicht verändern.
 *
 * WARUM ES DIESEN TEST BRAUCHT
 *
 * Der reguläre {@see \ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel} konfiguriert Messenger
 * nicht. Dort war der Bus des Sensors zwangsläufig der einzige — und damit unauffällig auch
 * der Standard-Bus der Anwendung. Genau dadurch blieb die Wechselwirkung mit einer
 * Anwendung, die Messenger selbst benutzt, jahrelang ungeprüft, obwohl 390 Tests grün waren.
 *
 * Der Befund, den diese Tests festhalten: das Bundle hatte über
 * `framework.messenger.buses` den Standard-Bus der Anwendung ERSETZT. Weil damit ein Wert
 * für `buses` vorlag, griff Symfonys Vorgabe `messenger.bus.default` nicht mehr, und der
 * einzige verbleibende Bus — der des Sensors, mit `default_middleware: false` — wurde zum
 * Standard. Jedes `$bus->dispatch()` der Anwendung lief danach ins Leere, ohne Fehler und
 * ohne Warnung. Nannte die Anwendung einen eigenen Bus, brach die Kompilierung ab.
 *
 * Das verletzte den ersten Grundsatz des Bundles (Konzept 4.): eine Störung des IDS darf die
 * überwachte Anwendung unter keinen Umständen beeinträchtigen.
 */
#[Group('messenger')]
final class MessengerInteroperabilityTest extends TestCase
{
    /** @var array<string, mixed> */
    private const SENSOR = [
        'application_id' => 'shop-api',
        'environment' => 'prod',
        'session_hash' => ['key' => IntegrationTestCase::SESSION_KEY],
        'transport' => ['dsn' => 'in-memory://'],
    ];

    /**
     * DER Test. Die Anwendung konfiguriert Messenger wie üblich — Transports, keine
     * ausdrücklichen Buses — und ihr Handler muss weiterhin laufen.
     */
    public function testTheApplicationsDefaultBusStillHandlesMessages(): void
    {
        $kernel = $this->boot('nur-transports', ['transports' => ['app_async' => 'in-memory://app']]);
        $container = $kernel->getContainer();

        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.default_bus');
        /** @var PlainMessageHandler $handler */
        $handler = $container->get(PlainMessageHandler::class);

        $bus->dispatch(new PlainMessage());

        self::assertSame(
            1,
            $handler->handled,
            'Der Standard-Bus der Anwendung behandelt keine Nachrichten mehr. Das Bundle hat ihn '
            .'vermutlich durch einen eigenen, sendenden Bus ersetzt.',
        );
    }

    /**
     * Nennt die Anwendung ihren Bus ausdrücklich, muss der Container weiterhin kompilieren.
     *
     * Vorher scheiterte das mit „You must specify the default_bus if you define more than one
     * bus" — das Bundle hatte einen zweiten Bus hinzugefügt und die Anwendung damit
     * mehrdeutig gemacht.
     */
    public function testAnApplicationWithItsOwnBusStillCompiles(): void
    {
        $kernel = $this->boot('eigener-bus', ['buses' => ['app.command_bus' => []]]);

        self::assertTrue($kernel->getContainer()->has('messenger.default_bus'));
    }

    /**
     * Und mit zwei eigenen Buses samt ausdrücklichem Standard bleibt dieser der Standard.
     */
    public function testTheApplicationsExplicitDefaultBusIsPreserved(): void
    {
        $kernel = $this->boot('zwei-busse', [
            'buses' => ['app.command_bus' => [], 'app.event_bus' => []],
            'default_bus' => 'app.command_bus',
        ]);
        $container = $kernel->getContainer();

        /** @var MessageBusInterface $bus */
        $bus = $container->get('messenger.default_bus');
        /** @var PlainMessageHandler $handler */
        $handler = $container->get(PlainMessageHandler::class);

        $bus->dispatch(new PlainMessage());

        self::assertSame(1, $handler->handled);
    }

    /**
     * @param array<string, mixed> $messengerConfig
     */
    private function boot(string $variant, array $messengerConfig): AppWithOwnBusKernel
    {
        $kernel = new AppWithOwnBusKernel(self::SENSOR, $messengerConfig, $variant);
        $kernel->boot();

        return $kernel;
    }
}
