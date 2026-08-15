<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Dispatch;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Delivery\Dispatch\EventFlusher;
use ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener;
use ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\EmitterInterface;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
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
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingSpool;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialEventIdGenerator;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\ThrowingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Die letzte Auffanglinie vor der überwachten Anwendung.
 *
 * Dieser Listener hängt in `kernel.terminate` und ist damit die einzige Stelle, an der
 * ein Fehler des Sensors noch in fremden Code entweichen kann. Konzept 4. lässt das
 * ausdrücklich nicht zu: „Eine Störung des IDS darf die überwachte Anwendung unter
 * keinen Umständen beeinträchtigen."
 *
 * @internal
 */
final class FlushListenerTest extends TestCase
{
    /**
     * Ein Fehler beim Protokollieren des Fehlers darf nicht nach draußen.
     *
     * Der Weg ist echt und war vor dieser Absicherung offen: Der rawBuilder wird erst
     * beim Serialisieren des Frames ausgewertet, also außerhalb des try/catch im
     * FrameDispatcher. Sein Wurf landet in der äußeren Auffanglinie des Flushers — und
     * die ruft den Logger. Wirft der ebenfalls (ein Monolog-StreamHandler auf voller
     * Platte genügt), verlässt die Exception `flush()`.
     *
     * Vorher hätte sie damit unmittelbar in kernel.terminate der Anwendung gestanden.
     */
    public function testAThrowingLoggerInTheFlusherDoesNotReachTheApplication(): void
    {
        $listener = new FlushListener($this->flusherWithBrokenRawAndLogger());

        $listener->onKernelTerminate($this->terminateEvent());

        $this->addToAssertionCount(1);
    }

    /**
     * Dieselbe Zusage für den Worker-Pfad: dort feuert kein kernel.terminate, und ein
     * Wurf beendet sonst den Worker mitten in der Nachrichtenverarbeitung.
     */
    public function testTheSamePromiseHoldsForConsoleAndWorker(): void
    {
        $listener = new FlushListener($this->flusherWithBrokenRawAndLogger());

        $listener->onConsoleTerminate();
        $listener->onWorkerMessageHandled();
        $listener->onWorkerMessageFailed();

        $this->addToAssertionCount(1);
    }

    /**
     * Ist das Versandbudget beim Frame aufgebraucht, entfällt das Lebenszeichen.
     *
     * Konzept 4.: „Hartes Timeout von 50 ms; danach Abbruch des Versands, der Request
     * läuft normal weiter." Durchsetzbar ist das nur ZWISCHEN Broker-Operationen — PHP
     * kann einen laufenden Syscall nicht abbrechen. Im Request-Pfad gibt es genau eine
     * solche Naht: zwischen Frame und Heartbeat.
     *
     * Der Heartbeat ist dabei der richtige Verzicht: Er wiederholt sich im nächsten
     * Intervall von selbst, die Events dieses Requests sind einmalig.
     */
    public function testTheHeartbeatIsSkippedWhenTheDispatchBudgetIsSpent(): void
    {
        $emitter = new CountingEmitter();

        $listener = new FlushListener($this->slowFlusher(), $emitter, dispatchBudgetMs: 1);
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame(0, $emitter->aufrufe, 'Über dem Budget wird nicht mehr gesendet');
    }

    /**
     * Und ohne Budget läuft beides — sonst prüfte der Test oben nur, dass der Emitter
     * schweigt.
     */
    public function testWithoutABudgetTheHeartbeatStillRuns(): void
    {
        $emitter = new CountingEmitter();

        $listener = new FlushListener($this->slowFlusher(), $emitter, dispatchBudgetMs: 0);
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame(1, $emitter->aufrufe);
    }

    /**
     * Stirbt der Prozess an einem Fatal Error, muss der Puffer in den Spool.
     *
     * Ohne diesen Weg endet PHP sofort: kein kernel.terminate, kein Flush, und der
     * Puffer stirbt mitsamt allen Events des Requests — ungezählt und
     * unprotokolliert, von einem stillen Sensor nicht zu unterscheiden. Konzept 4.
     * schließt das aus, und die betroffenen Requests sind selten die uninteressanten:
     * Ein OOM ist ein möglicher Ausgang eines Speicherangriffs.
     *
     * NUR in den Spool: Ein Broker-Versuch mit 20 ms Timeout überschritte das
     * Shutdown-Budget schon für sich genommen, und der Zustand des sterbenden Prozesses
     * ist ohnehin unzuverlässig.
     */
    public function testTheBufferSurvivesAFatalErrorThroughTheSpool(): void
    {
        $buffer = new EventBuffer();
        $buffer->appendMandatory($this->kernelResponseEvent());

        $shipper = new CollectingShipper();
        $spool = new CollectingSpool();

        $this->flusherWith($buffer, $shipper, $spool)->flushToSpool();

        self::assertSame(0, $shipper->frameCount(), 'Der Broker wird im Shutdown nicht angefasst');
        self::assertCount(1, $spool->frames(), 'Der Frame muss auf der Platte liegen');
        self::assertSame(
            'deferred',
            $spool->frames()[0]['dispatch_path'],
            'Planmäßig über den Spool, nicht Nachlauf nach einer Störung',
        );
        self::assertTrue($buffer->isEmpty(), 'Und der Puffer ist geleert');
    }

    /**
     * Der Listener hängt mit hoher Priorität in kernel.terminate — der Versand soll
     * laufen, bevor ein terminate-Listener der Anwendung den Prozess beendet.
     */
    public function testItSubscribesToTerminateWithHighPriority(): void
    {
        $events = FlushListener::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
        self::assertSame(['onKernelTerminate', 1024], $events[KernelEvents::TERMINATE]);
    }

    private function terminateEvent(): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new TerminateEvent($kernel, Request::create('/'), new Response());
    }

    /**
     * Ein Flusher, dessen Serialisierung wirft UND dessen Logger den Wurf nicht
     * überlebt.
     */
    private function flusherWithBrokenRawAndLogger(): EventFlusher
    {
        $buffer = new EventBuffer();
        $buffer->append($this->eventWithThrowingRawBuilder());

        $counters = new Counters('epoch-1', 4711);

        return new EventFlusher(
            $buffer,
            new SensorIdentityProvider(
                'shop-api',
                new InstanceIdProvider('web-03'),
                new EnvironmentResolver('prod'),
            ),
            [new KernelEventNormalizer(
                new EventFactory(new SequentialEventIdGenerator()),
                new SeverityResolver(),
                new QueryNormalizer(TestCleaner::default()),
                TestCleaner::default(),
            )],
            new FrameDispatcher(
                new CollectingShipper(),
                $counters,
                new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
                new CollectingSpool(),
            ),
            $counters,
            new DeferredCounters($counters, $buffer, new CaptureBudget(1500)),
            new LatencyRecorder(),
            new ThrowingLogger(),
        );
    }

    /**
     * Ein Flusher, dessen Arbeit garantiert länger als eine Millisekunde dauert.
     *
     * Über einen echten Frame mit rawBuilder: Der wird beim Serialisieren ausgewertet,
     * und der Test braucht keine künstliche Verzögerung, sondern nur einen Lauf, der
     * messbar Zeit kostet.
     */
    private function slowFlusher(): EventFlusher
    {
        $buffer = new EventBuffer();

        for ($i = 0; $i < 200; ++$i) {
            $buffer->append($this->eventWithSlowRawBuilder());
        }

        $counters = new Counters('epoch-1', 4711);

        return new EventFlusher(
            $buffer,
            new SensorIdentityProvider(
                'shop-api',
                new InstanceIdProvider('web-03'),
                new EnvironmentResolver('prod'),
            ),
            [new KernelEventNormalizer(
                new EventFactory(new SequentialEventIdGenerator()),
                new SeverityResolver(),
                new QueryNormalizer(TestCleaner::default()),
                TestCleaner::default(),
            )],
            new FrameDispatcher(
                new CollectingShipper(),
                $counters,
                new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
                new CollectingSpool(),
            ),
            $counters,
            new DeferredCounters($counters, $buffer, new CaptureBudget(0)),
            new LatencyRecorder(),
        );
    }

    private function flusherWith(EventBuffer $buffer, CollectingShipper $shipper, CollectingSpool $spool): EventFlusher
    {
        $counters = new Counters('epoch-1', 4711);

        return new EventFlusher(
            $buffer,
            new SensorIdentityProvider(
                'shop-api',
                new InstanceIdProvider('web-03'),
                new EnvironmentResolver('prod'),
            ),
            [new KernelEventNormalizer(
                new EventFactory(new SequentialEventIdGenerator()),
                new SeverityResolver(),
                new QueryNormalizer(TestCleaner::default()),
                TestCleaner::default(),
            )],
            new FrameDispatcher(
                $shipper,
                $counters,
                new RuntimeProfile(RuntimeProfile::POLICY_DIRECT),
                $spool,
            ),
            $counters,
            new DeferredCounters($counters, $buffer, new CaptureBudget(0)),
            new LatencyRecorder(),
        );
    }

    private function kernelResponseEvent(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_RESPONSE, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/ok',
            KernelPayload::FIELD_HTTP_STATUS => 200,
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    private function eventWithSlowRawBuilder(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_RESPONSE, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/langsam',
            KernelPayload::FIELD_HTTP_STATUS => 403,
        ]);
        $event->setCorrelationId('req-7f2a1c');
        $event->setRawBuilder(static function (): array {
            // Genug Arbeit, um die Millisekunde sicher zu überschreiten.
            return ['fuellung' => str_repeat('x', 8192), 'hash' => hash('sha256', random_bytes(64))];
        });

        return $event;
    }

    private function eventWithThrowingRawBuilder(): CapturedEvent
    {
        // warning, damit der rawBuilder beim Serialisieren überhaupt aufgerufen wird —
        // bei info bleibt er laut Konzept Abschnitt 3 ungelesen.
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_RESPONSE, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
            KernelPayload::FIELD_HTTP_STATUS => 403,
        ]);
        $event->setCorrelationId('req-7f2a1c');
        $event->setRawBuilder(static function (): array {
            throw new \RuntimeException('raw ist kaputt');
        });

        return $event;
    }
}

/**
 * Zählt nur, ob und wie oft ein Lebenszeichen verlangt wurde.
 *
 * Möglich geworden durch {@see EmitterInterface}: Der finale {@see \ProjektMotor\IdsSensor\Delivery\Heartbeat\Emitter}
 * verlangt eine PayloadFactory mit zehn Abhängigkeiten — für einen Test, der nur wissen
 * will, ob überhaupt gesendet wurde.
 *
 * @internal
 */
final class CountingEmitter implements EmitterInterface
{
    public int $aufrufe = 0;

    public function emitIfDue(Mode $trigger): bool
    {
        ++$this->aufrufe;

        return true;
    }
}
