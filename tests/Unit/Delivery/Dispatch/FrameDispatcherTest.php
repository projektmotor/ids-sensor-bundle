<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerState;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerStateStoreInterface;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\FailSafeLogger;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingShipper;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingSpool;
use ProjektMotor\IdsSensor\Tests\Fixtures\ThrowingLogger;

/**
 * Die Spitze der Pipeline: Was hier passiert, entscheidet über Verlust oder Zustellung.
 *
 * Geprüft war bisher nur der direkte Weg — `EventFlusherTest` läuft mit
 * `POLICY_DIRECT`, also ohne Spool- und ohne Breaker-Zweig. Genau diese beiden tragen
 * aber die fail-open-Zusage aus Konzept 4.: Unter mod_php findet hier NACHWEISLICH kein
 * Verbindungsversuch statt, und bei offenem Breaker ebensowenig.
 */
#[CoversClass(FrameDispatcher::class)]
final class FrameDispatcherTest extends TestCase
{
    public function testADirectDispatchShipsAndCounts(): void
    {
        $shipper = new CollectingShipper();
        $counters = new Counters();

        $gesendet = $this->dispatcher($shipper, $counters)->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $gesendet);
        self::assertSame(1, $shipper->frameCount());
        self::assertSame(1, $counters->get(Counters::SENT));
    }

    /**
     * Unter mod_php findet KEIN Verbindungsversuch statt — nicht „mit kurzem Timeout",
     * sondern gar keiner.
     *
     * Bei einer chunked übertragenen Antwort wartet der Client noch, und jede
     * Millisekunde hier wäre echte Antwortzeit.
     */
    public function testARuntimeWithoutDetachableResponseNeverTouchesTheBroker(): void
    {
        $shipper = new CollectingShipper(new \RuntimeException('darf nie aufgerufen werden'));
        $spool = new CollectingSpool();
        $counters = new Counters();

        $this->dispatcher($shipper, $counters, $spool, RuntimeProfile::POLICY_SPOOL)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames());
        self::assertSame(1, $counters->get(Counters::SPOOLED));
        self::assertSame(0, $counters->get(Counters::SHIP_FAILED), 'Kein Versuch heißt auch kein Fehlschlag');
    }

    /**
     * Der planmäßig gespoolte Frame ist `deferred`, nicht `recovered`.
     *
     * Konzept 3.3.1: Die Verzögerung ist hier begrenzt, und der Collector darf die
     * Echtzeit-Regeln weiter anwenden. `recovered` hieße das Gegenteil.
     */
    public function testASpooledFrameUnderModPhpIsMarkedDeferred(): void
    {
        $spool = new CollectingSpool();

        $this->dispatcher(new CollectingShipper(), new Counters(), $spool, RuntimeProfile::POLICY_SPOOL)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(DispatchPath::Deferred->value, $spool->frames()[0]['dispatch_path']);
    }

    /**
     * Ist der Breaker offen, findet ebenfalls kein Verbindungsversuch statt.
     *
     * Ohne diese Abkürzung kostet ein Broker-Ausfall jeden Request ein Timeout und
     * erschöpft den Worker-Pool — fail-open kippte unter Last ins Gegenteil.
     */
    public function testAnOpenBreakerSkipsTheBrokerEntirely(): void
    {
        $shipper = new CollectingShipper(new \RuntimeException('darf nie aufgerufen werden'));
        $spool = new CollectingSpool();
        $breaker = new CircuitBreaker($this->breakerStore(), 1, 30);
        $breaker->recordFailure();

        self::assertTrue($breaker->isOpen(), 'Vorbedingung');

        $counters = new Counters();
        $this->dispatcher($shipper, $counters, $spool, breaker: $breaker)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames());
        self::assertSame(0, $counters->get(Counters::SHIP_FAILED));
    }

    /**
     * Ein gescheiterter Versand landet im Spool, wird gezählt und öffnet den Breaker.
     */
    public function testAFailedShipIsSpooledCountedAndReportedToTheBreaker(): void
    {
        $spool = new CollectingSpool();
        $counters = new Counters();
        $breaker = new CircuitBreaker($this->breakerStore(), 1, 30);

        $gesendet = $this->dispatcher(
            new CollectingShipper(new \RuntimeException('Broker weg')),
            $counters,
            $spool,
            breaker: $breaker,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(0, $gesendet);
        self::assertSame(1, $counters->get(Counters::SHIP_FAILED));
        self::assertSame(1, $spool->spooledFrames());
        self::assertTrue($breaker->isOpen());
    }

    /**
     * Ein übergroßer Frame wird verworfen und NICHT gespoolt.
     *
     * Der Drainer schickte denselben Frame später an denselben Broker und liefe in
     * denselben Fehler — die Zeile blockierte den Spool, bis er voll ist. Verworfen wird
     * er deshalb, gezählt aber in jedem Fall (Konzept 4.).
     */
    public function testAnOversizedFrameIsDiscardedAndNotSpooled(): void
    {
        $spool = new CollectingSpool();
        $counters = new Counters();

        $gesendet = $this->dispatcher(new CollectingShipper(), $counters, $spool, maxFrameBytes: 256)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(0, $gesendet);
        self::assertSame(0, $spool->spooledFrames(), 'Sonst blockiert er den Spool bei jedem Drain-Lauf erneut');
        self::assertSame(1, $counters->get(Counters::DROPPED_FRAME_TOO_LARGE));
    }

    public function testAZeroLimitLiftsTheSizeCap(): void
    {
        $shipper = new CollectingShipper();

        $gesendet = $this->dispatcher($shipper, new Counters(), maxFrameBytes: 0)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $gesendet);
    }

    /**
     * Der Shutdown-Pfad geht ohne Broker-Versuch in den Spool.
     *
     * Der Prozess stirbt gerade, der Zustand ist unzuverlässig, und ein
     * Verbindungsversuch mit 20 ms Timeout überschritte das Shutdown-Budget schon für
     * sich genommen.
     */
    public function testTheShutdownPathSpoolsWithoutTouchingTheBroker(): void
    {
        $spool = new CollectingSpool();

        $gespoolt = $this->dispatcher(new CollectingShipper(new \RuntimeException('darf nie')), new Counters(), $spool)
            ->dispatchToSpool($this->identity(), [$this->event()]);

        self::assertSame(1, $gespoolt);
        self::assertSame(DispatchPath::Deferred->value, $spool->frames()[0]['dispatch_path']);
    }

    /**
     * Der Shutdown-Pfad meldet NICHT gerettet, wenn der Spool nichts angenommen hat.
     *
     * `dispatchToSpool()` gab `$frame->count()` zurück, ohne das Ergebnis des
     * Spool-Versuchs anzusehen — und `spool()` lieferte für beide Ausgänge 0, war als
     * Auskunft also wertlos. Der `FatalErrorFlushListener` protokollierte daraufhin „n
     * Events wurden gerettet", während derselbe Vorgang sie als `dropped_spool_full`
     * zählte. Der Zähler stimmte, das Protokoll widersprach ihm — und wer im Protokoll
     * nachsieht, sieht zuerst das Protokoll.
     */
    public function testTheShutdownPathReportsNothingRescuedWhenTheSpoolRefuses(): void
    {
        $counters = new Counters();

        $gerettet = $this->dispatcher(
            new CollectingShipper(new \RuntimeException('darf nie')),
            $counters,
            new CollectingSpool(acceptsNothing: true),
        )->dispatchToSpool($this->identity(), [$this->event()]);

        self::assertSame(0, $gerettet, 'Verworfen ist nicht gerettet');
        self::assertSame(1, $counters->get(Counters::DROPPED_SPOOL_FULL));
    }

    /**
     * Ein voller Spool ist der endgültige Verlust — und wird als solcher gezählt.
     */
    public function testAFullSpoolCountsTheLoss(): void
    {
        $counters = new Counters();

        $this->dispatcher(
            new CollectingShipper(new \RuntimeException('Broker weg')),
            $counters,
            new CollectingSpool(acceptsNothing: true),
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $counters->get(Counters::DROPPED_SPOOL_FULL));
        self::assertSame(0, $counters->get(Counters::SPOOLED));
    }

    /**
     * Ein werfender Logger darf den Frame nicht kosten.
     *
     * Der Logaufruf steht im catch-Zweig VOR dem Spool-Rettungsversuch: Wirft er, wird
     * der Frame nicht mehr gespoolt und ist verloren. Ausgerechnet der Versuch, einen
     * Verlust zu MELDEN, machte ihn damit größer. Ein Monolog-Handler auf einem vollen
     * Dateisystem ist der realistische Fall — und Konzept 4. lässt dafür keinen
     * Spielraum.
     *
     * Gelöst wird das am Container: Alle Dienste bekommen den Logger über
     * {@see FailSafeLogger}. Hier wird
     * geprüft, dass die Zusage auch dann gilt, wenn jemand einen rohen Logger übergibt.
     */
    public function testAThrowingLoggerDoesNotCostTheFrame(): void
    {
        $spool = new CollectingSpool();
        $counters = new Counters();

        $dispatcher = new FrameDispatcher(
            new CollectingShipper(new \RuntimeException('Broker weg')),
            $counters,
            new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
            $spool,
            262144,
            null,
            new FailSafeLogger(new ThrowingLogger()),
        );

        $dispatcher->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames(), 'Die Rettung in den Spool muss den Logfehler überleben');
        self::assertSame(1, $counters->get(Counters::SPOOLED));
    }

    private function dispatcher(
        CollectingShipper $shipper,
        Counters $counters,
        ?CollectingSpool $spool = null,
        string $policy = RuntimeProfile::POLICY_DIRECT,
        int $maxFrameBytes = 262144,
        ?CircuitBreaker $breaker = null,
    ): FrameDispatcher {
        return new FrameDispatcher(
            $shipper,
            $counters,
            new RuntimeProfile($policy),
            $spool ?? new CollectingSpool(),
            $maxFrameBytes,
            $breaker,
        );
    }

    private function breakerStore(): BreakerStateStoreInterface
    {
        return new class implements BreakerStateStoreInterface {
            private BreakerState $state;

            public function __construct()
            {
                $this->state = BreakerState::closed();
            }

            public function read(): BreakerState
            {
                return $this->state;
            }

            public function write(BreakerState $state): void
            {
                $this->state = $state;
            }

            public function mutate(\Closure $mutator): BreakerState
            {
                return $this->state = $mutator($this->state);
            }
        };
    }

    private function identity(): SensorIdentity
    {
        return new SensorIdentity('9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31', '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522', '7d2e9a44-1b30-4c67-b8e1-05af3c69d271');
    }

    private function event(): NormalizedEvent
    {
        return new NormalizedEvent(
            '01a00000-0000-7000-8000-000000000001',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            Layer::Kernel,
            'kernel.request',
            'korrelation-1',
            Severity::Info,
            $this->identity(),
            \ProjektMotor\IdsEventData\Event\Actor::anonymous(),
            ['method' => 'GET', 'path' => '/'],
        );
    }
}
