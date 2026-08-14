<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Dispatch;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Dispatch\EventFlusher;
use ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\EventFormat\Frame\DispatchPath;
use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Processing\Normalization\EventFactory;
use ProjektMotor\IdsSensor\Processing\Normalization\KernelEventNormalizer;
use ProjektMotor\IdsSensor\Processing\Normalization\QueryNormalizer;
use ProjektMotor\IdsSensor\Processing\Normalization\SeverityResolver;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\DeferredCounters;
use ProjektMotor\IdsSensor\Support\Telemetry\LatencyRecorder;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingShipper;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialEventIdGenerator;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

final class EventFlusherTest extends TestCase
{
    private EventBuffer $collector;

    private Counters $counters;

    private CaptureBudget $budget;

    protected function setUp(): void
    {
        $this->collector = new EventBuffer();
        $this->counters = new Counters('epoch-1', 4711);
        $this->budget = new CaptureBudget(1500);
    }

    public function testAnEmptyBufferProducesNoFrame(): void
    {
        $shipper = new CollectingShipper();

        $sent = $this->flusher($shipper)->flush();

        self::assertSame(0, $sent);
        self::assertSame(0, $shipper->frameCount());
    }

    /**
     * Ein Request bündelt alle seine Events in EINEN Frame — nicht einen Frame pro
     * Event. Sonst wären es N Netzwerk-Roundtrips pro Request.
     */
    public function testAllEventsOfOneRunLandInOneFrame(): void
    {
        $this->collector->append($this->kernelRequest());
        $this->collector->append($this->kernelResponse(200));
        $shipper = new CollectingShipper();

        $sent = $this->flusher($shipper)->flush();

        self::assertSame(2, $sent);
        self::assertSame(1, $shipper->frameCount());
        self::assertSame(2, $shipper->lastEventCount());
    }

    public function testTheFrameCarriesIdentityAndProcessKey(): void
    {
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper();

        $this->flusher($shipper)->flush();
        $frame = $shipper->lastFrame();

        self::assertNotNull($frame);
        self::assertSame('shop-api', $frame['sensor']['application_id']);
        self::assertSame('epoch-1', $frame['sensor']['process_epoch']);
        self::assertSame(4711, $frame['sensor']['pid']);
        self::assertSame(DispatchPath::Direct->value, $frame['dispatch_path']);
    }

    public function testTheFrameIsJsonCapableAndContainsTheEvents(): void
    {
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper();

        $this->flusher($shipper)->flush();
        $data = $shipper->lastFrame() ?? [];

        self::assertSame('direct', $data['dispatch_path']);
        self::assertCount(1, $data['events']);
        self::assertSame('kernel.request', $data['events'][0][EventSchema::FIELD_EVENT_TYPE]);
        self::assertJson(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Der Puffer muss geleert sein, damit ein zweiter Durchlauf — etwa aus der
     * Shutdown-Funktion nach einem regulären terminate — dieselben Events nicht
     * erneut versendet.
     */
    public function testASecondFlushShipsNothingAgain(): void
    {
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper();
        $flusher = $this->flusher($shipper);

        $flusher->flush();
        $second = $flusher->flush();

        self::assertSame(0, $second);
        self::assertSame(1, $shipper->frameCount());
    }

    /**
     * Die zentrale fail-open-Zusage: ein nicht erreichbarer Broker darf die
     * überwachte Anwendung nicht behelligen.
     */
    public function testADispatchErrorIsNotCarriedOutwards(): void
    {
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper(new \RuntimeException('Redis weg'));

        $sent = $this->flusher($shipper)->flush();

        self::assertSame(0, $sent);
        self::assertSame(1, $this->counters->get(Counters::SHIP_FAILED));
    }

    public function testAnEventWithoutAResponsibleNormalizerIsCounted(): void
    {
        // Business-Ebene: dafür ist in dieser Ausbaustufe kein Normalisierer registriert.
        $this->collector->append(CapturedEvent::now(Layer::Business, 'order.amount_overridden'));
        $shipper = new CollectingShipper();

        $sent = $this->flusher($shipper)->flush();

        self::assertSame(0, $sent);
        self::assertSame(1, $this->counters->get(Counters::DROPPED_NO_NORMALIZER));
        self::assertSame(0, $shipper->frameCount(), 'Ohne normalisierte Events entsteht kein Frame');
    }

    /**
     * Ein einzelnes unnormalisierbares Event darf die übrigen Events desselben
     * Requests nicht mitnehmen.
     */
    public function testAnErrorOnOneEventDoesNotLoseTheOthers(): void
    {
        $this->collector->append($this->kernelRequest());
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper();

        $flusher = new EventFlusher(
            $this->collector,
            $this->identityProvider(),
            [new ThrowingNormalizerOnce()],
            $this->dispatcher($shipper),
            $this->counters,
            $this->deferredCounters($this->collector),
            new LatencyRecorder(),
        );
        $sent = $flusher->flush();

        self::assertSame(1, $sent, 'Das zweite Event kommt trotzdem durch');
        self::assertSame(1, $this->counters->get(Counters::DROPPED_NORMALIZE_ERROR));
    }

    /**
     * Die Zähler von Puffer und Budget werden beim Flush eingesammelt und reisen im
     * Frame mit — nur so wird der Verlust collectorseitig sichtbar
     * (Konzept 4. IdsBackendBundle — Restrisiko: ids.event_loss).
     */
    public function testLossCountersTravelAlongInTheFrame(): void
    {
        $collector = new EventBuffer(maxEvents: 1);
        $collector->append($this->kernelRequest());
        $collector->append($this->kernelRequest()); // verworfen
        $shipper = new CollectingShipper();

        $flusher = new EventFlusher(
            $collector,
            $this->identityProvider(),
            [$this->kernelNormalizer()],
            $this->dispatcher($shipper),
            $this->counters,
            $this->deferredCounters($collector),
            new LatencyRecorder(),
        );
        $flusher->flush();

        $frame = $shipper->lastFrame();
        self::assertNotNull($frame);
        $counters = $frame['counters'];
        self::assertSame(1, $counters[Counters::DROPPED_BUFFER_FULL] ?? 0);
        self::assertSame(1, $counters[Counters::CAPTURED] ?? 0);
    }

    /**
     * Auch der Versand wird gemessen — getrennt vom Erfassungsbudget, weil beide
     * Zahlen Verschiedenes bedeuten: die eine ist Antwortzeit, die andere
     * Worker-Belegung.
     */
    public function testDispatchTimeIsMeasured(): void
    {
        $this->collector->append($this->kernelRequest());
        $recorder = new LatencyRecorder();

        $flusher = new EventFlusher(
            $this->collector,
            $this->identityProvider(),
            [$this->kernelNormalizer()],
            $this->dispatcher(new CollectingShipper()),
            $this->counters,
            $this->deferredCounters($this->collector),
            $recorder,
        );
        $flusher->flush();

        self::assertSame(1, $recorder->dispatchMs()->count());
        self::assertSame(0, $recorder->inRequestOverheadUs()->count(), 'Der Flush zählt nicht als Erfassungskost');
    }

    public function testDispatchPathIsAdopted(): void
    {
        $this->collector->append($this->kernelRequest());
        $shipper = new CollectingShipper();

        $this->flusher($shipper)->flush(DispatchPath::Deferred);

        self::assertSame(DispatchPath::Deferred->value, $shipper->lastFrame()['dispatch_path'] ?? null);
    }

    private function flusher(CollectingShipper $shipper): EventFlusher
    {
        return new EventFlusher(
            $this->collector,
            $this->identityProvider(),
            [$this->kernelNormalizer()],
            $this->dispatcher($shipper),
            $this->counters,
            $this->deferredCounters($this->collector),
            new LatencyRecorder(),
        );
    }

    /**
     * Ohne AccessDecisionSensor — die Entscheidungserfassung ist abschaltbar, und der
     * Flusher darf davon nichts merken. Ihr eigener Zählerpfad steht in
     * {@see \ProjektMotor\IdsSensor\Tests\Unit\Support\Telemetry\DeferredCountersTest}.
     */
    private function deferredCounters(EventBuffer $buffer): DeferredCounters
    {
        return new DeferredCounters($this->counters, $buffer, $this->budget);
    }

    /**
     * Bis zur Entflechtung nahm der Flusher den Shipper direkt. POLICY_DIRECT liefert
     * dasselbe Verhalten wie das frühere `$runtime = null`: kein Spool, kein Breaker.
     */
    private function dispatcher(ShipperInterface $shipper): FrameDispatcher
    {
        return new FrameDispatcher(
            $shipper,
            $this->counters,
            new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
        );
    }

    private function kernelNormalizer(): KernelEventNormalizer
    {
        return new KernelEventNormalizer(
            new EventFactory(new SequentialEventIdGenerator()),
            new SeverityResolver(),
            new QueryNormalizer(TestCleaner::default()),
        );
    }

    private function identityProvider(): SensorIdentityProvider
    {
        return new SensorIdentityProvider(
            'shop-api',
            new InstanceIdProvider('web-03'),
            new EnvironmentResolver('prod'),
        );
    }

    private function kernelRequest(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, 'kernel.request', [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/api/orders/42',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    private function kernelResponse(int $status): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, 'kernel.response', [
            KernelPayload::FIELD_HTTP_STATUS => $status,
            KernelPayload::FIELD_PATH => '/api/orders/42',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }
}
