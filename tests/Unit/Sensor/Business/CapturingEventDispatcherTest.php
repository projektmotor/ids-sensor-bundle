<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Business;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Business\CapturingEventDispatcher;
use ProjektMotor\IdsSensor\Sensor\Business\EventSensor;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelation;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Der Dekorator um den Event-Dispatcher der Anwendung.
 *
 * Die riskanteste Klasse des Bundles: Sie liegt im Weg JEDES dispatchten Events,
 * einschließlich der `kernel.*`-Events des Frameworks. Ein Fehler hier ist kein
 * blinder Fleck, sondern ein Anwendungsausfall.
 *
 * Geprüft war davon nichts — insbesondere nicht die Aufweitung der Signaturen auf
 * `callable|array`, für die es sogar eine eigene PHPStan-Ausnahme gibt.
 */
#[CoversClass(CapturingEventDispatcher::class)]
final class CapturingEventDispatcherTest extends TestCase
{
    /**
     * Ein Lazy Listener kommt als `[$serviceId, 'method']` — und das ist noch KEIN
     * gültiges Callable, die Auflösung passiert erst beim Aufruf.
     *
     * Mit dem engeren `callable` aus dem Interface scheitert die Weitergabe zur Laufzeit
     * an einem TypeError, sobald `TraceableEventDispatcher` einen solchen Listener
     * durchreicht. Genau dafür weitet die Klasse auf, und genau das prüft niemand — der
     * Fehler zeigte sich erst im Dev-Profiler der Anwendung.
     */
    public function testAnArrayListenerIsPassedThroughUnchanged(): void
    {
        $inner = new EventDispatcher();
        $dispatcher = $this->dispatcher($inner);

        $listener = ['app.mein_listener', 'onEvent'];

        $dispatcher->addListener('mein.event', $listener, 42);

        self::assertSame(42, $dispatcher->getListenerPriority('mein.event', $listener));
        self::assertTrue($dispatcher->hasListeners('mein.event'));

        $dispatcher->removeListener('mein.event', $listener);

        self::assertFalse($dispatcher->hasListeners('mein.event'));
    }

    public function testAClosureListenerWorksJustTheSame(): void
    {
        $inner = new EventDispatcher();
        $dispatcher = $this->dispatcher($inner);

        $listener = static function (): void {};

        $dispatcher->addListener('mein.event', $listener, 7);

        self::assertSame(7, $dispatcher->getListenerPriority('mein.event', $listener));
        self::assertCount(1, $dispatcher->getListeners('mein.event'));
    }

    /**
     * Ein fremdes Event geht unangetastet durch — der Dekorator liegt im Weg von allem.
     */
    public function testAnUnrelatedEventIsDispatchedUnchanged(): void
    {
        $inner = new EventDispatcher();
        $dispatcher = $this->dispatcher($inner);
        $event = new \stdClass();

        self::assertSame($event, $dispatcher->dispatch($event, 'mein.event'));
    }

    /**
     * Der Aufruf des inneren Dispatchers liegt NIEMALS im try-Block.
     *
     * Andernfalls würde aus einer IDS-Störung ein Anwendungsausfall. Hier umgekehrt
     * geprüft: Wirft die Anwendung in ihrem eigenen Listener, muss der Fehler
     * durchkommen — ein Dekorator, der ihn schluckte, wäre genauso falsch.
     */
    public function testAnExceptionFromTheApplicationIsNotSwallowed(): void
    {
        $inner = new EventDispatcher();
        $inner->addListener('mein.event', static function (): never {
            throw new \RuntimeException('Fehler der Anwendung');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fehler der Anwendung');

        $this->dispatcher($inner)->dispatch(new \stdClass(), 'mein.event');
    }

    private function dispatcher(EventDispatcher $inner): CapturingEventDispatcher
    {
        return new CapturingEventDispatcher($inner, $this->sensor());
    }

    private function sensor(): EventSensor
    {
        return new EventSensor(
            new EventBuffer(100),
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(
                    new SessionIdHasher(null, null, false),
                    new ClientFingerprinter(enabled: false),
                ),
                new ConsoleCorrelation(new CorrelationIdFactory()),
            ),
            new CaptureBudget(0),
            new RequestStack(),
        );
    }
}
