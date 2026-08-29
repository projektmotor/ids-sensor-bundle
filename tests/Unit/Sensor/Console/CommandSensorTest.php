<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Console\CommandSensor;
use ProjektMotor\IdsSensor\Sensor\Console\Options;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelation;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Die Erfassung auf der Konsole — Konzept 3.1.1, offener Punkt E1.
 *
 * Geprüft wird, was die Ereignisse tragen und was sie ausdrücklich NICHT tragen. Der
 * zweite Teil ist der wichtigere: Eine Befehlszeile führt regelmäßig Zugangsdaten mit,
 * und ein Feld, das sie aufnähme, wäre ein Weg an der Redaktion vorbei.
 */
#[CoversClass(CommandSensor::class)]
final class CommandSensorTest extends TestCase
{
    public function testACommandStartProducesAnEvent(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer)->onConsoleCommand($this->commandEvent('app:import-users'));

        $captured = $this->single($buffer);

        self::assertSame(Layer::Kernel, $captured->layer);
        self::assertSame(KernelPayload::EVENT_CONSOLE_COMMAND, $captured->eventType);
        self::assertSame('app:import-users', $captured->get(KernelPayload::FIELD_COMMAND));
    }

    /**
     * Die Ebene bleibt `kernel`. Ein vierter Wert in `Layer` wäre ein neuer Fall in
     * einem geschlossenen Vokabular — also eine neue Fassung des Ereignisformats und
     * eine Datenbankmigration beim Collector (Konzept 3.7).
     */
    public function testTheEventStaysOnTheKernelLayer(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer)->onConsoleError($this->errorEvent('app:import-users', new \RuntimeException('kaputt')));

        self::assertSame(Layer::Kernel, $this->single($buffer)->layer);
    }

    public function testAnErrorCarriesClassMessageAndExitCode(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer)->onConsoleError(
            $this->errorEvent('app:import-users', new \RuntimeException('Verbindung abgebrochen'), 3),
        );

        $captured = $this->single($buffer);

        self::assertSame(KernelPayload::EVENT_CONSOLE_ERROR, $captured->eventType);
        self::assertSame(\RuntimeException::class, $captured->get(KernelPayload::FIELD_EXCEPTION_CLASS));
        self::assertSame('Verbindung abgebrochen', $captured->get(KernelPayload::FIELD_EXCEPTION_MESSAGE));
        self::assertSame(3, $captured->get(KernelPayload::FIELD_EXIT_CODE));
    }

    /**
     * Ein Fehlschlag trägt den Stacktrace — sonst wäre das Ereignis die Feststellung,
     * dass etwas schiefging, ohne die Auskunft, wo.
     */
    public function testAnErrorCarriesTheTrace(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer)->onConsoleError($this->errorEvent('app:import-users', new \RuntimeException('kaputt')));

        $rawBuilder = $this->single($buffer)->rawBuilder();

        self::assertNotNull($rawBuilder);
        self::assertArrayHasKey('trace', $rawBuilder());
    }

    /**
     * KEIN Feld für die Aufrufargumente — weder beim Start noch beim Fehlschlag.
     *
     * `bin/console app:import --password=geheim` schriebe sie sonst wortwörtlich in
     * den Beweisspeicher, an der Redaktion aus Konzept 4.5.1 vorbei.
     */
    public function testNoEventCarriesTheInvocationArguments(): void
    {
        $buffer = new EventBuffer(100);
        $sensor = $this->sensor($buffer);

        $sensor->onConsoleCommand($this->commandEvent('app:import-users', ['--password' => 'geheim']));
        $sensor->onConsoleError($this->errorEvent('app:import-users', new \RuntimeException('kaputt')));

        foreach ($buffer->all() as $captured) {
            self::assertStringNotContainsString(
                'geheim',
                json_encode($captured->all(), \JSON_THROW_ON_ERROR),
                'Kein Payload der Konsolen-Ebene darf die Aufrufargumente tragen.',
            );
        }
    }

    public function testAnIgnoredCommandProducesNothing(): void
    {
        $buffer = new EventBuffer(100);
        $sensor = $this->sensor($buffer, new Options(ignoredCommands: ['#^ids:sensor:#']));

        $sensor->onConsoleCommand($this->commandEvent('ids:sensor:spool:flush'));
        $sensor->onConsoleError($this->errorEvent('ids:sensor:spool:flush', new \RuntimeException('kaputt')));

        self::assertTrue($buffer->isEmpty());
    }

    public function testADisabledSensorProducesNothing(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer, new Options(enabled: false))->onConsoleCommand($this->commandEvent('app:import-users'));

        self::assertTrue($buffer->isEmpty());
    }

    /**
     * Ein unbekannter Befehlsname erreicht die Application, bevor ein Command-Objekt
     * existiert. Das Ereignis fällt trotzdem an: Es sagt, dass jemand etwas versucht
     * hat, das es nicht gibt — und genau das ist auf der Konsole ein Signal.
     */
    public function testARunWithoutACommandObjectStillProducesAnEvent(): void
    {
        $buffer = new EventBuffer(100);

        $this->sensor($buffer)->onConsoleError($this->errorEvent(null, new \RuntimeException('kaputt')));

        self::assertSame(CommandSensor::UNKNOWN_COMMAND, $this->single($buffer)->get(KernelPayload::FIELD_COMMAND));
    }

    /**
     * Die Kennung des Laufs steht am Ereignis, obwohl es keinen Request gibt. Ohne sie
     * trüge es den Leerstring und fiele aus dem Self-Join aus Konzept 3.2 heraus.
     */
    public function testTheEventCarriesTheConsoleRunCorrelationId(): void
    {
        $buffer = new EventBuffer(100);
        $correlation = new ConsoleCorrelation(new CorrelationIdFactory());
        $correlation->begin();

        $this->sensor($buffer, correlation: $correlation)->onConsoleCommand($this->commandEvent('app:import-users'));

        self::assertSame($correlation->correlationId(), $this->single($buffer)->correlationId());
    }

    /**
     * Der Sensor abonniert beide Konsolen-Ereignisse und bleibt dabei unter dem
     * ConsoleCorrelationListener (1024) — sonst stünde die Kennung noch nicht.
     */
    public function testItSubscribesBelowTheCorrelationListener(): void
    {
        $events = CommandSensor::getSubscribedEvents();

        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        self::assertArrayHasKey(ConsoleEvents::ERROR, $events);
        self::assertLessThan(1024, $events[ConsoleEvents::COMMAND][1]);
        self::assertLessThan(1024, $events[ConsoleEvents::ERROR][1]);
    }

    private function single(EventBuffer $buffer): CapturedEvent
    {
        $events = $buffer->all();

        self::assertCount(1, $events);

        return $events[0];
    }

    private function sensor(
        EventBuffer $buffer,
        ?Options $options = null,
        ?ConsoleCorrelation $correlation = null,
    ): CommandSensor {
        return new CommandSensor(
            $buffer,
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(
                    new SessionIdHasher(null, false),
                    new ClientFingerprinter(enabled: false),
                ),
                $correlation ?? new ConsoleCorrelation(new CorrelationIdFactory()),
            ),
            new CaptureBudget(0),
            $options ?? new Options(),
            new Builder(TestCleaner::default()),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function commandEvent(?string $name, array $input = []): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(
            null === $name ? null : new Command($name),
            new ArrayInput($input),
            new NullOutput(),
        );
    }

    private function errorEvent(?string $name, \Throwable $error, int $exitCode = 1): ConsoleErrorEvent
    {
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), $error, null === $name ? null : new Command($name));
        $event->setExitCode($exitCode);

        return $event;
    }
}
