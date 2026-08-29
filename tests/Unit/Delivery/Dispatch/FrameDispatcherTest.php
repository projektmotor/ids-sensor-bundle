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
use ProjektMotor\IdsSensor\Exception\ThrottledException;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\FailSafeLogger;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingShipper;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingSpool;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialUuidGenerator;
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
     * Jede Sendung bekommt ihre eigene Kennung. Ohne sie könnte der Collector zwei
     * Sendungen nicht auseinanderhalten — und mit einer wiederholten Zustellung
     * derselben Sendung erst recht nicht (Konzept 4.2.2).
     */
    public function testEveryDispatchCarriesItsOwnFrameId(): void
    {
        $shipper = new CollectingShipper();
        $dispatcher = $this->dispatcher($shipper, new Counters());

        $dispatcher->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);
        $dispatcher->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        $kennungen = array_column($shipper->frames(), 'frame_id');

        self::assertCount(2, $kennungen);
        self::assertNotEmpty($kennungen[0]);
        self::assertNotSame($kennungen[0], $kennungen[1], 'Zwei Sendungen, zwei Kennungen');
    }

    /**
     * Die Kennung entsteht VOR der Verzweigung Collector/Spool. Ginge sie erst im
     * Direktzweig aus, trüge ein gespoolter Frame gar keine — und der Collector
     * bekäme ausgerechnet für den Nachlauf keinen Zeilenschlüssel.
     */
    public function testASpooledFrameCarriesAFrameIdAsWell(): void
    {
        $spool = new CollectingSpool();

        $this->dispatcher(new CollectingShipper(), new Counters(), $spool, RuntimeProfile::POLICY_SPOOL)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertCount(1, $spool->frames());
        self::assertNotEmpty($spool->frames()[0]['frame_id']);
    }

    /**
     * Auch der Shutdown-Pfad nach einem Fatal Error. Er umgeht den Collector, nicht
     * aber das Format: Was dort in den Spool geht, wird später nachgesendet und
     * braucht denselben Zeilenschlüssel.
     */
    public function testTheShutdownPathAlsoCarriesAFrameId(): void
    {
        $spool = new CollectingSpool();

        $this->dispatcher(new CollectingShipper(), new Counters(), $spool)
            ->dispatchToSpool($this->identity(), [$this->event()]);

        self::assertCount(1, $spool->frames());
        self::assertNotEmpty($spool->frames()[0]['frame_id']);
    }

    /**
     * Unter mod_php findet KEIN Verbindungsversuch statt — nicht „mit kurzem Timeout",
     * sondern gar keiner.
     *
     * Bei einer chunked übertragenen Antwort wartet der Client noch, und jede
     * Millisekunde hier wäre echte Antwortzeit.
     */
    public function testARuntimeWithoutDetachableResponseNeverTouchesTheCollector(): void
    {
        $shipper = new CollectingShipper(new \RuntimeException('darf nie aufgerufen werden'));
        $spool = new CollectingSpool();
        $counters = new Counters();

        $this->dispatcher($shipper, $counters, $spool, RuntimeProfile::POLICY_SPOOL)
            ->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames());
        self::assertSame(1, $counters->get(Counters::SPOOLED_EVENTS));
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
     * Ohne diese Abkürzung kostet ein Collector-Ausfall jeden Request ein Timeout und
     * erschöpft den Worker-Pool — fail-open kippte unter Last ins Gegenteil.
     */
    public function testAnOpenBreakerSkipsTheCollectorEntirely(): void
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
            new CollectingShipper(new \RuntimeException('Collector weg')),
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
     * Der Drainer schickte denselben Frame später an denselben Collector und liefe in
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
     * Der Shutdown-Pfad geht ohne Collector-Versuch in den Spool.
     *
     * Der Prozess stirbt gerade, der Zustand ist unzuverlässig, und ein
     * Verbindungsversuch mit 20 ms Timeout überschritte das Shutdown-Budget schon für
     * sich genommen.
     */
    public function testTheShutdownPathSpoolsWithoutTouchingTheCollector(): void
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
            new CollectingShipper(new \RuntimeException('Collector weg')),
            $counters,
            new CollectingSpool(acceptsNothing: true),
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $counters->get(Counters::DROPPED_SPOOL_FULL));
        self::assertSame(0, $counters->get(Counters::SPOOLED_EVENTS));
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
            new CollectingShipper(new \RuntimeException('Collector weg')),
            $counters,
            new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
            $spool,
            new SequentialUuidGenerator('frame-'),
            262144,
            null,
            new FailSafeLogger(new ThrowingLogger()),
        );

        $dispatcher->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames(), 'Die Rettung in den Spool muss den Logfehler überleben');
        self::assertSame(1, $counters->get(Counters::SPOOLED_EVENTS));
    }

    /**
     * Eine dauerhafte Ablehnung wird VERWORFEN, nicht gespoolt.
     *
     * Konzept 3.6 ist an dieser Stelle normativ: 400, 403, 413 und 422 heißen „geht
     * nie". Hier stand nur der allgemeine \Throwable-Zweig, der beides gleich behandelte
     * — und damit den Frame in den Spool legte, wo der Drainer ihn später an denselben
     * Collector schickte und in denselben Fehler lief. Genau das Head-of-Line-Blocking,
     * gegen das es {@see UnshippableFrameException} laut eigenem Docblock gibt.
     */
    public function testAPermanentRejectionIsDiscardedInsteadOfSpooled(): void
    {
        $spool = new CollectingSpool();
        $counters = new Counters();

        $gesendet = $this->dispatcher(
            new CollectingShipper(new UnshippableFrameException('Der Collector hat die Sendung mit 422 abgelehnt.')),
            $counters,
            $spool,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(0, $gesendet);
        self::assertSame(0, $spool->spooledFrames(), 'Was nie durchkommt, gehört nicht in den Spool');
        self::assertSame(1, $counters->get(Counters::DROPPED_REJECTED));
    }

    /**
     * Und sie zählt NICHT als `ship_failed`.
     *
     * Die beiden Zähler führen zu entgegengesetzten Maßnahmen (Konzept 3.6):
     * `ship_failed` heißt „den Collector prüfen", `dropped_rejected` heißt „den Payload
     * prüfen". Eine gemeinsame Zahl ließe nicht erkennen, welche greift.
     */
    public function testAPermanentRejectionIsNotCountedAsAShippingFailure(): void
    {
        $counters = new Counters();

        $this->dispatcher(
            new CollectingShipper(new UnshippableFrameException('mit 400 abgelehnt')),
            $counters,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(0, $counters->get(Counters::SHIP_FAILED));
    }

    /**
     * Der Breaker bleibt bei einer dauerhaften Ablehnung unberührt.
     *
     * Sonst öffnete er nach drei abgewiesenen Sendungen gegen einen völlig gesunden
     * Collector — und der Sensor spoolte alles Weitere, obwohl nichts ausgefallen ist.
     * Konzept 3.6 sagt für diese Zeilen ausdrücklich „Breaker unberührt".
     */
    public function testAPermanentRejectionLeavesTheBreakerUntouched(): void
    {
        $store = $this->breakerStore();
        $breaker = new CircuitBreaker($store, failureThreshold: 1);

        $this->dispatcher(
            new CollectingShipper(new UnshippableFrameException('mit 403 abgelehnt')),
            new Counters(),
            null,
            RuntimeProfile::POLICY_DIRECT,
            262144,
            $breaker,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(0, $store->read()->failures, 'Eine Ablehnung ist kein Ausfall des Collectors');
        self::assertFalse($breaker->isOpen());
    }

    /**
     * `429` ist das Gegenteil: spoolen, nicht verwerfen.
     *
     * „Später erneut" und „geht nie" sehen als Antwortcode ähnlich aus und sind
     * entgegengesetzt. Verwürfe der Sensor hier, wären die Events wegen einer
     * vorübergehenden Ratengrenze endgültig verloren.
     */
    public function testARateLimitIsSpooledAndNotDiscarded(): void
    {
        $spool = new CollectingSpool();
        $counters = new Counters();

        $this->dispatcher(
            new CollectingShipper(new ThrottledException('429', 120.0)),
            $counters,
            $spool,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertSame(1, $spool->spooledFrames());
        self::assertSame(1, $counters->get(Counters::SHIP_FAILED));
        self::assertSame(0, $counters->get(Counters::DROPPED_REJECTED));
    }

    /**
     * `Retry-After` öffnet den Breaker sofort und für die verlangte Dauer.
     *
     * Konzept 3.6 verlangt beides in einer Zeile: spoolen UND `Retry-After` beachten.
     * Ohne das sofortige Öffnen widerspräche sich das — unterhalb der Fehlerschwelle
     * ginge der nächste Frame unmittelbar wieder hinaus, und die Wartezeit wäre
     * entgegengenommen und im selben Atemzug ignoriert.
     */
    public function testRetryAfterOpensTheBreakerForTheRequestedDuration(): void
    {
        $store = $this->breakerStore();
        // Schwelle 3: Ein einzelner Fehler öffnet den Breaker sonst NICHT.
        $breaker = new CircuitBreaker($store, failureThreshold: 3, openForSeconds: 30);

        $this->dispatcher(
            new CollectingShipper(new ThrottledException('429', 300.0)),
            new Counters(),
            null,
            RuntimeProfile::POLICY_DIRECT,
            262144,
            $breaker,
        )->dispatch($this->identity(), [$this->event()], DispatchPath::Direct);

        self::assertTrue($breaker->isOpen(), 'Eine verlangte Wartezeit gilt ab dem ersten 429');
        self::assertGreaterThan(
            microtime(true) + 250,
            $store->read()->openUntil,
            'Die 300 s des Collectors dürfen nicht auf die konfigurierten 30 s zusammenfallen',
        );
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
            new SequentialUuidGenerator('frame-'),
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
