<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Heartbeat;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Emitter;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

        $payload = $this->heartbeats($services)[0];

        // Konzept 1 verlangt ausdrücklich alle drei Kennungen.
        self::assertSame($this->applicationId, $payload['application_id']);
        self::assertSame('3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522', $payload['environment_id']);
        self::assertSame('c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4', $payload['sensor_id']);
        self::assertSame(2, $payload['schema_version']);

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

        self::assertSame(TestCleaner::rules()->version, $payload['cleanup_version']);
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

        $payload = $this->heartbeats($services)[0];

        self::assertIsString($payload['process_epoch']);
        self::assertNotSame('', $payload['process_epoch']);
        self::assertIsInt($payload['pid']);
    }

    /**
     * Der Heartbeat geht an eine EIGENE Route — der Collector kann also entscheiden,
     * bevor er den Körper liest (Konzept 3.6).
     */
    public function testTheHeartbeatGoesToItsOwnRoute(): void
    {
        $kernel = $this->boot('typ');
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        $adressen = array_column($this->client($services)->requests, 'url');
        $heartbeatRouten = array_values(array_filter(
            $adressen,
            static fn (string $url): bool => str_contains($url, '/api/v1/sensor-heartbeat/'),
        ));

        self::assertNotSame([], $heartbeatRouten, 'Der Heartbeat muss auf seiner eigenen Route landen');
        self::assertStringNotContainsString('/sensor-data/', $heartbeatRouten[0]);

        $decoded = $this->heartbeats($services)[0];

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

        self::assertSame([], $this->client($services)->requests, 'Kein Versand im Request');

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertGreaterThan(0, $counters->get(Counters::SPOOLED));
        self::assertSame(0, $counters->get(Counters::SENT));
        // Wichtig: NICHT als Fehlschlag gezählt — der Spool ist hier der planmäßige Weg.
        self::assertSame(0, $counters->get(Counters::SHIP_FAILED));

        // Nicht nur *.jsonl: Frisch Geschriebenes liegt in der AKTIVEN Datei des
        // Prozesses und trägt FileSpool::ACTIVE_SUFFIX. Versiegelt wird erst nach Größe
        // oder Alter, bzw. stellvertretend durch den Drainer — genau diese Trennung löst
        // das Rennen zwischen Schreiber und Drainer auf.
        $files = glob($this->spoolDir.'/'.FileSpool::FILE_PREFIX.'*') ?: [];
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
            'collector' => ['base_uri' => 'https://nicht-erreichbar.invalid'],
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
     * @return list<array<string, mixed>>
     */
    private function heartbeats(ContainerInterface $services): array
    {
        return $this->client($services)->heartbeats();
    }

    /**
     * Bei offenem Breaker findet KEIN Verbindungsversuch statt — auch nicht für ein
     * Lebenszeichen.
     *
     * Der Zweig ist der einzige des Emitters, der bislang nur indirekt geprüft war. Er
     * ist heikler, als er aussieht: Der Heartbeat ist die Stelle, an der ein Sensor
     * meldet, dass es ihn gibt. Ihn bei offenem Breaker zu unterdrücken, heißt, dass der
     * Collector während eines Broker-Ausfalls `ids.sensor_silent` meldet — richtig, denn
     * er hört tatsächlich nichts mehr. Falsch wäre, es NICHT zu zählen: Dann sähe der
     * Ausfall aus wie ein toter Sensor, und niemand könnte die beiden unterscheiden.
     */
    public function testAnOpenBreakerSuppressesTheHeartbeatButCountsIt(): void
    {
        $kernel = $this->boot('breaker-offen', ['circuit_breaker' => ['failure_threshold' => 1, 'open_for_s' => 30]]);
        $services = $this->services($kernel);

        /** @var CircuitBreaker $breaker */
        $breaker = $services->get('ids_sensor.circuit_breaker');
        $breaker->recordFailure();

        self::assertTrue($breaker->isOpen(), 'Vorbedingung: der Breaker ist offen');

        /** @var Emitter $emitter */
        $emitter = $services->get('ids_sensor.heartbeat.emitter');
        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        /** @var Scheduler $scheduler */
        $scheduler = $services->get('ids_sensor.heartbeat.scheduler');

        self::assertFalse($emitter->emit(Mode::Command));
        self::assertSame([], $this->heartbeats($services), 'Kein Versuch heißt: nichts auf der Leitung');
        self::assertSame(1, $counters->get(Counters::HEARTBEAT_FAILED), 'Aber gezählt — sonst ist der Ausfall unsichtbar');
        self::assertNull($scheduler->lastSentAt(), 'Und kein Stempel, damit der nächste Lauf es erneut versucht');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function boot(string $variant, array $overrides = []): TestKernel
    {
        $kernel = new TestKernel(array_replace_recursive([
            'application_id' => $this->applicationId,
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            'collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim'],
            'spool' => ['dir' => $this->spoolDir],
            'budget' => ['capture_us' => 0],
        ], $overrides), 'heartbeat-'.$variant);
        $kernel->boot();

        return $kernel;
    }
}
