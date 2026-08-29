<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft das Verhalten bei nicht erreichbarem Collector durch den echten Container.
 *
 * Der Kern der Grundsatzentscheidung fail-open aus Konzept 4.: eine Störung des IDS
 * darf die überwachte Anwendung unter keinen Umständen beeinträchtigen — und der
 * Verlust muss gezählt werden, weil ein stiller Ausfall gefährlicher ist als ein
 * sichtbarer.
 */
final class ResilienceTest extends IntegrationTestCase
{
    private string $spoolDir;

    /**
     * Je Test eine eigene application_id.
     *
     * Nötig, weil der Breaker-Zustand genau die Eigenschaft hat, die ihn in Produktion
     * nützlich macht: er liegt in APCu, ist prozessübergreifend sichtbar und je
     * application_id global. Ohne Trennung würde ein Fehlschlag aus dem
     * vorangegangenen Test den Zähler dieses Tests vorbelasten — und der Breaker wäre
     * eine Anfrage zu früh offen.
     *
     * Ein neues Spool-Verzeichnis allein genügt nicht: der Dateirückfall greift nur,
     * wenn APCu fehlt.
     */
    private string $applicationId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->spoolDir = sys_get_temp_dir().'/ids-resilience-'.$suffix;
        $this->applicationId = 'shop-api-'.$suffix;
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->spoolDir)) {
            return;
        }

        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
    }

    /**
     * Ein nicht erreichbarer Collector darf keinen Fehler in die Anwendung tragen — und
     * die Events müssen im Spool landen statt verloren zu gehen.
     */
    public function testAnUnreachableCollectorLandsInTheSpoolWithoutError(): void
    {
        $kernel = $this->boot('resilience-down');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());

        // Kein Fehler nach außen.
        $kernel->terminate(Request::create('/'), new Response());

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(1, $counters->get(Counters::SHIP_FAILED));
        self::assertSame(1, $counters->get(Counters::SPOOLED));
        self::assertSame(0, $counters->get(Counters::SENT));

        /** @var FileSpool $spool */
        $spool = $services->get('ids_sensor.spool');
        self::assertCount(1, $spool->waitingFiles(), 'Der Frame muss im Spool liegen');
    }

    /**
     * Der Breaker öffnet nach der Schwelle und schneidet danach den Collector-Zugriff ab.
     *
     * Das ist der Unterschied zwischen fail-open in der Theorie und in der Praxis:
     * ohne diese Abkürzung kostet jeder weitere Request ein Timeout, und der
     * Worker-Pool ist erschöpft.
     */
    public function testBreakerOpensAfterTheThresholdAndSavesTheConnectionAttempt(): void
    {
        $kernel = $this->boot('resilience-breaker', ['failure_threshold' => 2, 'open_for_s' => 30]);
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        /** @var CircuitBreaker $breaker */
        $breaker = $services->get('ids_sensor.circuit_breaker');
        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');

        self::assertFalse($breaker->isOpen());

        // Zwei Fehlschläge erreichen die Schwelle.
        for ($i = 0; $i < 2; ++$i) {
            $collector->append($this->kernelRequest());
            $kernel->terminate(Request::create('/'), new Response());
        }

        self::assertTrue($breaker->isOpen(), 'Nach zwei Fehlschlägen muss der Breaker offen sein');
        self::assertSame(2, $counters->get(Counters::SHIP_FAILED));

        // Der dritte Versuch darf den Collector nicht mehr anfassen: ship_failed bleibt
        // stehen, gespoolt wird trotzdem.
        $collector->append($this->kernelRequest());
        $kernel->terminate(Request::create('/'), new Response());

        self::assertSame(
            2,
            $counters->get(Counters::SHIP_FAILED),
            'Bei offenem Breaker darf kein Verbindungsversuch mehr stattfinden',
        );
        self::assertSame(3, $counters->get(Counters::SPOOLED), 'Gespoolt wird weiterhin');
    }

    /**
     * Ist auch der Spool nicht aufnahmefähig, ist der Verlust endgültig — aber
     * gezählt. Konzept 4. verlangt genau das: „Jeder verworfene oder verlorene Event
     * wird gezählt.".
     */
    public function testAFullSpoolDiscardsAndCounts(): void
    {
        $kernel = $this->boot('resilience-full', spoolMaxBytes: 10);
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());
        $kernel->terminate(Request::create('/'), new Response());

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(1, $counters->get(Counters::DROPPED_SPOOL_FULL));
        self::assertSame(0, $counters->get(Counters::SPOOLED));
    }

    /**
     * Eine unbrauchbare Collector-Adresse darf die Anwendung nicht treffen.
     *
     * Abzugrenzen vom nicht erreichbaren Collector weiter oben: Dort steht die Adresse
     * richtig da und der Host antwortet nicht. Hier ist sie selbst kaputt — ein
     * Konfigurationsfehler, der beim ersten Versand als InvalidArgumentException aus dem
     * HTTP-Client kommt statt als Netzfehler.
     *
     * Beide Wege müssen im abgesicherten Pfad landen: gezählt als ship_failed, gespoolt
     * statt verloren, und kein Wurf nach außen. Der Fall ist die Umsetzung von
     * Konzept 4 — eine Störung des IDS beeinträchtigt die Anwendung nicht, auch wenn
     * der Betreiber sie selbst verursacht hat.
     */
    public function testAnUnusableCollectorUriDoesNotReachTheApplication(): void
    {
        $kernel = new TestKernel([
            'application_id' => $this->applicationId,
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            // Keine gültige Adresse: weder Schema noch Host.
            'collector' => ['base_uri' => 'weder-schema-noch-host', 'username' => 'sensor', 'password' => 'geheim'],
            'spool' => ['dir' => $this->spoolDir],
        ], 'resilience-uri-kaputt');
        $kernel->boot();

        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());

        // Die eigentliche Zusage: kein Wurf nach außen.
        $kernel->terminate(Request::create('/'), new Response());

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(1, $counters->get(Counters::SHIP_FAILED), 'Der Fehlschlag muss gezählt sein');
        self::assertSame(1, $counters->get(Counters::SPOOLED), 'Und die Events müssen im Spool liegen');

        /** @var FileSpool $spool */
        $spool = $services->get('ids_sensor.spool');
        self::assertCount(1, $spool->waitingFiles());
    }

    public function testTheSpoolFlushCommandIsRegistered(): void
    {
        $services = $this->services($this->boot('resilience-down'));

        self::assertTrue($services->has('ids_sensor.command.spool_flush'));
    }

    private function kernelRequest(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_REQUEST, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    /**
     * @param array<string, mixed> $breaker
     */
    private function boot(string $variant, array $breaker = [], int $spoolMaxBytes = 16777216): TestKernel
    {
        $kernel = new TestKernel([
            'application_id' => $this->applicationId,
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            // Ein Host, den es nicht gibt: der Verbindungsversuch scheitert.
            'collector' => [
                'base_uri' => 'https://nicht-erreichbar.invalid',
                'username' => 'sensor',
                'password' => 'geheim',
            ],
            'spool' => ['dir' => $this->spoolDir, 'max_bytes' => $spoolMaxBytes],
            'circuit_breaker' => $breaker,
        ], $variant);
        $kernel->boot();

        return $kernel;
    }
}
