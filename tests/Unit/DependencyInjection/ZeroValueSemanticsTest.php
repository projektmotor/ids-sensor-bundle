<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerState;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerStateStoreInterface;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;

/**
 * Was `0` bedeutet — je Option etwas anderes, und nirgends geprüft.
 *
 * `ConfigurationTree` lässt bei jeder Zahl die 0 zu (der Typ-Platzhalter für `int` ist
 * 0, `->min(1)` würde ihn zurückweisen) und weist die fachliche Untergrenze
 * ausdrücklich dem verbrauchenden Dienst zu. Damit ist jede dieser Nullen ein
 * eigenständiges Verhalten — und die Bedeutungen widersprechen einander:
 * `capture_us: 0` heißt „unbegrenzt", `max_events_per_request: 0` heißt „nichts".
 *
 * Diese Tests halten fest, was gilt. Nicht, weil jede Null eine gute Idee wäre,
 * sondern weil eine Fehlkonfiguration ein vorhersagbares Ergebnis haben muss.
 */
final class ZeroValueSemanticsTest extends TestCase
{
    /**
     * `capture_us: 0` heißt UNBEGRENZT — der dokumentierte Weg, das Budget abzuschalten.
     */
    public function testACaptureBudgetOfZeroMeansUnlimited(): void
    {
        $budget = new CaptureBudget(0);
        $gelaufen = false;

        $budget->guard(static function () use (&$gelaufen): void {
            $gelaufen = true;
        });

        self::assertTrue($gelaufen);
        self::assertSame(0, $budget->skipped());
    }

    /**
     * `max_events_per_request: 0` heißt NICHTS PUFFERN — die entgegengesetzte
     * Auslegung derselben Zahl, und die gefährlichere: Der Sensor läuft, kostet
     * Erfassungszeit und liefert nie ein Event.
     */
    public function testABufferOfZeroKeepsNothingButCounts(): void
    {
        $buffer = new EventBuffer(0);

        $buffer->append($this->irgendeinEvent());

        self::assertTrue($buffer->isEmpty());
        self::assertSame(1, $buffer->droppedOverflow(), 'Verloren ist nicht dasselbe wie nie erfasst');
    }

    /**
     * `failure_threshold: 0` öffnet beim ERSTEN Fehlschlag.
     *
     * Nicht „nie" und nicht „sofort dauerhaft": Der Vergleich lautet
     * `$failures < $threshold`, und ein erster Fehlschlag ergibt 1.
     */
    public function testAFailureThresholdOfZeroOpensOnTheFirstFailure(): void
    {
        $breaker = $this->breaker(threshold: 0, openFor: 30);

        $breaker->recordFailure();

        self::assertTrue($breaker->isOpen());
    }

    /**
     * `open_for_s: 0` heißt: der Breaker öffnet NIE wirksam.
     *
     * `openUntil` liegt dann in derselben Mikrosekunde wie „jetzt", und `isOpenAt()`
     * meldet geschlossen. Der Zustand steht im Snapshot als `half_open` — der Breaker
     * hat Fehlschläge gezählt, sperrt aber nichts. Das ist die stillste denkbare
     * Fehlkonfiguration: Der Betreiber glaubt, einen Schutz zu haben.
     */
    public function testAnOpenPeriodOfZeroNeverBlocks(): void
    {
        $breaker = $this->breaker(threshold: 1, openFor: 0);

        $breaker->recordFailure();

        self::assertFalse($breaker->isOpen());
        self::assertSame('half_open', $breaker->snapshot()['state']);
        self::assertSame(1, $breaker->snapshot()['failures'], 'Gezählt wird trotzdem — sonst wäre es unsichtbar');
    }

    private function breaker(int $threshold, int $openFor): CircuitBreaker
    {
        return new CircuitBreaker($this->store(), $threshold, $openFor);
    }

    private function store(): BreakerStateStoreInterface
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

    private function irgendeinEvent(): \ProjektMotor\IdsSensor\Sensor\CapturedEvent
    {
        return \ProjektMotor\IdsSensor\Sensor\CapturedEvent::now(
            \ProjektMotor\IdsEventData\Vocabulary\Layer::Kernel,
            'kernel.request',
        );
    }
}
