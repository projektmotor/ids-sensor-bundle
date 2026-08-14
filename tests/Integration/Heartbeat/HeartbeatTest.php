<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Heartbeat;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Emitter;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
use ProjektMotor\IdsSensor\Delivery\Transport\MessageSerializer;
use ProjektMotor\IdsSensor\IdsSensorBundle;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Der Heartbeat durch den echten Container (Konzept 2.).
 *
 * Je Test eine eigene application_id: die Drosselung ist prozessübergreifend (APCu) und
 * je application_id/instance_id global. Ohne Trennung würde der Stempel des einen Tests
 * den nächsten unterdrücken — und der wäre grün oder rot je nach Ausführungsreihenfolge.
 */
final class HeartbeatTest extends IntegrationTestCase
{
    private string $applicationId;

    private string $spoolDir;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->applicationId = 'shop-api-'.$suffix;
        $this->spoolDir = sys_get_temp_dir().'/ids-heartbeat-'.$suffix;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
    }

    /**
     * Ein Request löst das Lebenszeichen aus — ohne jede Ops-Einrichtung. Das ist die
     * Zusage, die nach `composer require` gilt.
     */
    public function testARequestTriggersTheHeartbeat(): void
    {
        $kernel = $this->boot('request');
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate($request, $response);

        $heartbeats = $this->heartbeats($services);

        self::assertCount(1, $heartbeats, 'Der erste Request muss ein Lebenszeichen senden');
    }

    /**
     * Die Drosselung: ein zweiter Request innerhalb des Intervalls sendet nicht erneut.
     * Ohne sie erzeugte jede Anfrage einen Heartbeat — bei 1000 req/s wären das 1000
     * Nachrichten pro Sekunde für eine Information, die sich pro Minute einmal ändert.
     */
    public function testASecondRequestDoesNotSendAgainWithinTheInterval(): void
    {
        $kernel = $this->boot('drosselung');
        $services = $this->services($kernel);

        for ($i = 0; $i < 3; ++$i) {
            $request = Request::create('/ok');
            $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));
        }

        // Geprüft wird über den Zähler und NICHT über den Transport: Symfony setzt
        // registrierte Dienste zwischen zwei handle()-Aufrufen zurück, und der
        // InMemoryTransport gehört dazu — getSent() enthält danach nur noch die
        // Nachrichten des letzten Requests. Die Zähler sind bewusst monoton über die
        // Prozesslebensdauer (siehe Counters) und damit die richtige Quelle.
        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');

        self::assertSame(1, $counters->get(Counters::HEARTBEAT_SENT), 'Drei Requests, ein Lebenszeichen');
        // SENT zählt EVENTS, nicht Frames: drei Requests à kernel.request + kernel.response.
        self::assertSame(6, $counters->get(Counters::SENT), 'Die Events gehen dagegen bei jedem Request raus');
    }

    /**
     * Der Payload muss alles tragen, was der Collector braucht, um aus einem Schweigen
     * einen Befund zu machen.
     */
    public function testThePayloadCarriesTheOperationalState(): void
    {
        $kernel = $this->boot('payload');
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        $payload = $this->heartbeats($services)[0]->payload;

        // Konzept 2. verlangt ausdrücklich application_id und instance_id.
        self::assertSame($this->applicationId, $payload['application_id']);
        self::assertIsString($payload['instance_id']);
        self::assertNotSame('', $payload['instance_id']);
        self::assertSame('prod', $payload['environment']);

        self::assertSame('ids.heartbeat', $payload['type']);
        self::assertSame(1, $payload['schema_version']);

        // Der Modus entscheidet, was ein Schweigen bedeutet — deshalb reist er mit.
        self::assertSame('both', $payload['heartbeat_mode']);
        self::assertSame('request', $payload['triggered_by']);
        self::assertSame(60, $payload['interval_s']);

        // Damit collectorseitig bekannt ist, welche Verzögerung normal ist.
        self::assertSame('direct', $payload['runtime']['dispatch_path']);

        // Ohne die Zähler wäre ids.event_loss (Konzept 4., Restrisiko) genau dann blind,
        // wenn kein Verkehr da ist.
        self::assertArrayHasKey('captured', $payload['counters']);
        self::assertArrayHasKey('in_request_overhead_us', $payload['latency']);

        // Für mod_php die wichtigste Einzelangabe: ein nicht laufender Drain lässt
        // oldest_pending_age_s unbegrenzt wachsen.
        self::assertArrayHasKey('spool', $payload);
        self::assertArrayHasKey('oldest_pending_age_s', $payload['spool']);

        self::assertSame(1, $payload['cleanup_version']);
    }

    /**
     * Zähler sind ABSOLUT, nicht als Zuwachs. Bei at-least-once-Zustellung (Konzept 4.)
     * würde eine erneute Zustellung Deltas doppelt zählen. process_epoch trennt einen
     * Neustart von einem Zählerrücksprung.
     */
    public function testCountersAreMonotonicAndCarryTheProcessEpoch(): void
    {
        $kernel = $this->boot('monoton');
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        $payload = $this->heartbeats($services)[0]->payload;

        self::assertIsString($payload['process_epoch']);
        self::assertNotSame('', $payload['process_epoch']);
        self::assertIsInt($payload['pid']);
    }

    /**
     * Der Heartbeat ist ein EIGENER Nachrichtentyp und trägt einen eigenen type-Header —
     * der Collector kann also entscheiden, bevor er den Body liest.
     */
    public function testTheHeartbeatCarriesItsOwnTypeHeader(): void
    {
        $kernel = $this->boot('typ');
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);
        $encoded = $serializer->encode(new Envelope($this->heartbeats($services)[0]));

        self::assertSame(MessageSerializer::TYPE_HEARTBEAT, $encoded['headers'][MessageSerializer::HEADER_TYPE]);
        self::assertJson($encoded['body']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded['body'], true, 512, \JSON_THROW_ON_ERROR);

        // Ein Heartbeat hat keine dieser drei Angaben — sie sind laut Konzept 4.2.1
        // NOT NULL. Genau deshalb ist er kein Event.
        self::assertArrayNotHasKey('layer', $decoded);
        self::assertArrayNotHasKey('event_severity', $decoded);
        self::assertArrayNotHasKey('correlation_id', $decoded);
    }

    /**
     * DER Test für den mod_php-Fall.
     *
     * Ist die Antwort nicht abkoppelbar, darf Phase B kein Netzwerk anfassen — auch nicht
     * für einen Heartbeat. Folge für den Betrieb: dort ist `ids:sensor:heartbeat` per cron
     * der einzige Weg, wie ein Lebenszeichen entsteht.
     */
    public function testWithoutADetachableResponseTheRequestPathSendsNoHeartbeat(): void
    {
        $kernel = $this->boot('modphp', ['flush' => ['policy' => 'spool']]);
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        self::assertSame([], $this->heartbeats($services), 'Im Spool-First-Betrieb kein Netzwerk im Request');

        // Der Command dagegen muss liefern — er läuft außerhalb jedes Requests.
        /** @var Emitter $emitter */
        $emitter = $services->get('ids_sensor.heartbeat.emitter');

        self::assertTrue($emitter->emit(Mode::Command));
        self::assertCount(1, $this->heartbeats($services));
    }

    /**
     * Und im Spool-First-Betrieb landen die Events tatsächlich im Spool statt auf dem
     * Netz — der Kern der mod_php-Zusage.
     */
    public function testInSpoolFirstOperationFramesGoToTheSpoolAndCarryDeferred(): void
    {
        $kernel = $this->boot('modphp-frames', ['flush' => ['policy' => 'spool']]);
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        /** @var InMemoryTransport $transport */
        $transport = $services->get('messenger.transport.ids_events');
        self::assertSame([], $transport->getSent(), 'Kein Versand im Request');

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertGreaterThan(0, $counters->get(Counters::SPOOLED));
        self::assertSame(0, $counters->get(Counters::SENT));
        // Wichtig: NICHT als Fehlschlag gezählt — der Spool ist hier der planmäßige Weg.
        self::assertSame(0, $counters->get(Counters::SHIP_FAILED));

        $files = glob($this->spoolDir.'/*.jsonl') ?: [];
        self::assertNotSame([], $files);

        /** @var array<string, mixed> $frame */
        $frame = json_decode(trim((string) file_get_contents($files[0])), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            'deferred',
            $frame['dispatch_path'],
            'deferred und nicht recovered — sonst wäre mod_php dauerhaft von der Echtzeit-Erkennung ausgeschlossen',
        );
    }

    /**
     * Ein fehlgeschlagener Heartbeat darf den Stempel NICHT setzen. Andernfalls
     * verschwiege er das ganze nächste Intervall, und der Collector sähe eine Lücke, die
     * der Sensor selbst nicht bemerkt hat.
     */
    public function testAFailedHeartbeatDoesNotSetTheStamp(): void
    {
        $kernel = $this->boot('fehlschlag', [
            'transport' => ['dsn' => 'redis://127.0.0.1:6392/ids:hb/group/consumer', 'options' => ['timeout' => 0.05, 'read_timeout' => 0.05]],
        ]);
        $services = $this->services($kernel);

        /** @var Emitter $emitter */
        $emitter = $services->get('ids_sensor.heartbeat.emitter');
        /** @var Scheduler $scheduler */
        $scheduler = $services->get('ids_sensor.heartbeat.scheduler');
        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');

        self::assertFalse($emitter->emit(Mode::Command));
        self::assertNull($scheduler->lastSentAt(), 'Kein Stempel nach einem Fehlschlag');
        self::assertSame(1, $counters->get(Counters::HEARTBEAT_FAILED));
        self::assertTrue($scheduler->isDue(), 'Und damit sofort wieder fällig');
    }

    /**
     * Abschalten entfernt die Dienste vollständig — und dass das den Dauerfalschalarm
     * ids.sensor_silent bedeutet, gehört in die README, nicht in eine stille Vorgabe.
     */
    public function testDisablingRemovesTheServicesEntirely(): void
    {
        $kernel = $this->boot('aus', ['heartbeat' => ['enabled' => false]]);

        self::assertFalse($kernel->getContainer()->has('ids_sensor.heartbeat.emitter'));
        self::assertFalse($kernel->getContainer()->has('ids_sensor.command.heartbeat'));
    }

    /**
     * @return list<Heartbeat>
     */
    private function heartbeats(ContainerInterface $services): array
    {
        /** @var InMemoryTransport $transport */
        $transport = $services->get('messenger.transport.ids_events');

        $heartbeats = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof Heartbeat) {
                $heartbeats[] = $message;
            }
        }

        return $heartbeats;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function boot(string $variant, array $overrides = []): TestKernel
    {
        $kernel = new TestKernel(array_replace_recursive([
            'application_id' => $this->applicationId,
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
            'transport' => ['dsn' => 'in-memory://'],
            'spool' => ['dir' => $this->spoolDir],
            'budget' => ['capture_us' => 0],
        ], $overrides), 'heartbeat-'.$variant);
        $kernel->boot();

        return $kernel;
    }
}
