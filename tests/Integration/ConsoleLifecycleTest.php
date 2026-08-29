<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Der Durchstich durch einen echten Konsolenlauf — Konzept 3.1.1, offener Punkt E1.
 *
 * Der Unterschied zum Unit-Test ist die Verdrahtung: Hier entscheidet sich, ob die
 * Ereignisse überhaupt ankommen, ob der ConsoleCorrelationListener rechtzeitig vor dem
 * Sensor läuft und ob die Vorgabe der Ausschlussliste in einem gebauten Container
 * wirklich greift.
 *
 * Die beiden Testbefehle werden der Application zur Laufzeit übergeben statt im Kernel
 * registriert. Wären sie Dienste, stünden sie in allen fünfzehn Container-Abdrücken —
 * Testwerkzeug in einer Datei, die die AUSLIEFERUNG festhalten soll.
 *
 * GEPRÜFT WIRD AM VERSANDTEN FRAME, NICHT AM PUFFER
 *
 * Anders als beim Request endet ein Konsolenlauf IMMER mit `console.terminate`, und dort
 * versendet der FlushListener — der Puffer ist danach leer. Das ist kein Hindernis,
 * sondern die ehrlichere Prüfung: Sie umfasst auch die Normalisierung, also Payloadfelder
 * und Einstufung, statt nur die Erfassung.
 */
final class ConsoleLifecycleTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        'collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim'],
    ];

    public function testASuccessfulCommandProducesOneEvent(): void
    {
        $events = $this->runCommand('konsole-ok', 'app:test:ok');

        self::assertSame([KernelPayload::EVENT_CONSOLE_COMMAND], self::typesOf($events));
        self::assertSame('app:test:ok', $events[0]['payload'][KernelPayload::FIELD_COMMAND]);
    }

    /**
     * Die Ebene bleibt `kernel` — sonst verlangte der collectorseitige ENUM `layer_type`
     * eine Migration, und der Frame trüge eine neue Fassung.
     */
    public function testTheEventsStayOnTheKernelLayerWithoutAVersionBump(): void
    {
        $frames = $this->runFrames('konsole-ebene', 'app:test:boom');

        self::assertSame(EventSchema::SCHEMA_VERSION, $frames[0][EventSchema::FIELD_SCHEMA_VERSION]);

        foreach ($frames[0]['events'] as $event) {
            self::assertSame(Layer::Kernel->value, $event[EventSchema::FIELD_LAYER]);
        }
    }

    /**
     * `console.command` ist info, `console.error` warning — und nicht critical. Auf der
     * Konsole gibt es kein Gegenstück zur Aufteilung 5xx/4xx: Ein vertippter Befehl und
     * ein abgestürzter Worker enden beide mit einer Ausnahme. Konzept 2.2.1 behält
     * `critical` Serverfehlern vor.
     */
    public function testTheSeverities(): void
    {
        $events = $this->runCommand('konsole-stufen', 'app:test:boom');

        self::assertSame(Severity::Info->value, $events[0][EventSchema::FIELD_EVENT_SEVERITY]);
        self::assertSame(Severity::Warning->value, $events[1][EventSchema::FIELD_EVENT_SEVERITY]);
    }

    /**
     * `warning` trägt `raw` — der Stacktrace des gescheiterten Befehls reist mit, sonst
     * wäre das Ereignis die Feststellung, dass etwas schiefging, ohne die Auskunft, wo.
     */
    public function testTheErrorCarriesItsTrace(): void
    {
        $events = $this->runCommand('konsole-raw', 'app:test:boom');

        self::assertArrayHasKey(EventSchema::FIELD_RAW, $events[1]);
        self::assertArrayHasKey('trace', $events[1][EventSchema::FIELD_RAW]);
    }

    /**
     * Ein Fehlschlag erzeugt BEIDE Ereignisse: den Start und den Fehler. Ohne den Start
     * wäre nicht ablesbar, dass der Befehl überhaupt begonnen hat — und bei einem
     * Prozess, der vor `console.error` stirbt, ist er das Einzige, was bleibt.
     */
    public function testAFailingCommandProducesStartAndError(): void
    {
        $events = $this->runCommand('konsole-fehler', 'app:test:boom');

        self::assertSame(
            [KernelPayload::EVENT_CONSOLE_COMMAND, KernelPayload::EVENT_CONSOLE_ERROR],
            self::typesOf($events),
        );
        self::assertSame(\RuntimeException::class, $events[1]['payload'][KernelPayload::FIELD_EXCEPTION_CLASS]);
    }

    /**
     * Die Verkettung, auf der jeder Self-Join des Collectors beruht — auf der Konsole
     * genauso wie im Request.
     */
    public function testAllEventsOfARunShareTheCorrelationId(): void
    {
        $events = $this->runCommand('konsole-korrelation', 'app:test:boom');

        $ids = array_unique(array_column($events, EventSchema::FIELD_CORRELATION_ID));

        self::assertCount(1, $ids);
        self::assertNotSame([''], array_values($ids), 'Der Leerstring hieße: kein zuordenbarer Durchlauf.');
    }

    /**
     * Die eigenen Befehle des Bundles sind ohne Zutun ausgeschlossen. Sonst erzeugte
     * der minütliche `ids:sensor:spool:flush` ein Ereignis, das der nächste Lauf
     * versendet, um dabei das nächste zu erzeugen.
     */
    public function testTheBundlesOwnCommandsProduceNothing(): void
    {
        $events = $this->runCommand('konsole-eigene', 'ids:sensor:setup-check');

        self::assertSame([], $events);
    }

    public function testTheSensorCanBeSwitchedOff(): void
    {
        $events = $this->runCommand('konsole-aus', 'app:test:ok', [
            'layers' => ['kernel' => ['console' => ['enabled' => false]]],
        ]);

        self::assertSame([], $events);
    }

    /**
     * Die Ereignistypen in Reihenfolge.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<mixed>
     */
    private static function typesOf(array $events): array
    {
        return array_column($events, EventSchema::FIELD_EVENT_TYPE);
    }

    /**
     * Die normalisierten Ereignisse aller versandten Frames.
     *
     * @param array<string, mixed> $overrides
     *
     * @return list<array<string, mixed>>
     */
    private function runCommand(string $variant, string $command, array $overrides = []): array
    {
        $events = [];

        foreach ($this->runFrames($variant, $command, $overrides) as $frame) {
            foreach ($frame['events'] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return list<array<string, mixed>>
     */
    private function runFrames(string $variant, string $command, array $overrides = []): array
    {
        // debug: false — sonst hängt der TraceableEventDispatcher an jedem Lauf und
        // schreibt seine „Notified event"-Zeilen in die Testausgabe. Geprüft wird hier
        // die Verdrahtung, nicht das Entwicklerwerkzeug.
        $kernel = new TestKernel(array_merge(self::CONFIG, $overrides), $variant, debug: false);
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->add(new class('app:test:ok') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::SUCCESS;
            }
        });
        $application->add(new class('app:test:boom') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('Der Befehl ist gescheitert');
            }
        });

        $application->run(new ArrayInput(['command' => $command]), new NullOutput());

        return $this->frames($this->services($kernel));
    }
}
