<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Telemetry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;

/**
 * Die Buchführung, auf der die Zusage aus Konzept 4. steht: „Jeder verworfene oder
 * verlorene Event wird gezählt.".
 *
 * Konzept 3.4 verlangt zusätzlich, dass die Zähler ABSOLUT sind und nicht als
 * Zuwachs — bei at-least-once-Zustellung würden Deltas bei einer erneuten Zustellung
 * doppelt zählen. Die Monotonie ist damit keine Feinheit, sondern die Eigenschaft,
 * die die Zahlen collectorseitig überhaupt verwertbar macht.
 */
#[CoversClass(Counters::class)]
final class CountersTest extends TestCase
{
    /**
     * Jeder Zähler reist mit, auch mit dem Wert 0 (Konzept 3.4).
     *
     * Hier stand `assertSame([], $counters->all())` — der Testname sagte schon damals das
     * Gegenteil dessen, was er prüfte. Ein fehlender Schlüssel ist für den Collector
     * zweideutig: „nichts verloren" oder „diese Sensorfassung kennt den Zähler nicht".
     * Genau diese Unterscheidung braucht er, wenn Sensoren verschiedener Fassungen
     * gleichzeitig laufen, und ohne sie ist `ids.event_loss` nicht bildbar.
     */
    public function testAnUntouchedCounterIsZeroAndNotAbsent(): void
    {
        $counters = new Counters();

        self::assertSame(0, $counters->get(Counters::DROPPED_BUFFER_FULL));
        self::assertSame(
            Counters::ALL,
            array_keys($counters->all()),
            'Ein nie berührter Zähler steht mit 0 im Frame, nicht gar nicht',
        );
        self::assertSame([0], array_values(array_unique($counters->all())));
    }

    /**
     * Ein Schlüssel außerhalb der geschlossenen Liste geht nicht verloren.
     *
     * Die Nullfüllung ist eine Ergänzung, kein Filter. Einen Zählerstand stillschweigend
     * zu verschlucken wäre der schlechtere Ausgang — Konzept 4. verlangt, dass JEDER
     * Verlust gezählt wird, und ein unbekannter Name ist immer noch eine Zahl.
     */
    public function testACounterOutsideTheClosedListSurvives(): void
    {
        $counters = new Counters();

        $counters->increment('dropped_something_new', 3);

        self::assertSame(3, $counters->all()['dropped_something_new']);
    }

    public function testIncrementAccumulates(): void
    {
        $counters = new Counters();

        $counters->increment(Counters::SENT);
        $counters->increment(Counters::SENT, 4);

        self::assertSame(5, $counters->get(Counters::SENT));
    }

    /**
     * `raiseTo()` übernimmt Stände aus Quellen, die anderswo geführt werden — und zwar
     * NIE abwärts.
     *
     * Die Quellen (EventBuffer, CaptureBudget) führen prozessweit monotone Stände. Ein
     * zweiter Flush im selben Prozess meldet denselben oder einen höheren Wert; würde
     * er abwärts übernommen, sänke ein absoluter Zähler zwischen zwei Frames — für den
     * Collector ein unmöglicher Zustand.
     */
    public function testRaiseToNeverLowersACounter(): void
    {
        $counters = new Counters();

        $counters->raiseTo(Counters::DROPPED_BUFFER_FULL, 7);
        $counters->raiseTo(Counters::DROPPED_BUFFER_FULL, 3);

        self::assertSame(7, $counters->get(Counters::DROPPED_BUFFER_FULL));
    }

    public function testRaiseToLiftsACounter(): void
    {
        $counters = new Counters();

        $counters->increment(Counters::DROPPED_BUFFER_FULL, 2);
        $counters->raiseTo(Counters::DROPPED_BUFFER_FULL, 9);

        self::assertSame(9, $counters->get(Counters::DROPPED_BUFFER_FULL));
    }

    /**
     * Die Prozesskennung unterscheidet zwei Zählerstände desselben Hosts.
     *
     * Ohne sie ließen sich die absoluten Stände zweier FPM-Kinder nicht auseinander
     * halten — sie sähen aus wie ein einziger Zähler, der springt.
     */
    public function testEachInstanceHasItsOwnProcessEpoch(): void
    {
        self::assertNotSame((new Counters())->processEpoch(), (new Counters())->processEpoch());
    }

    public function testTheProcessEpochAndPidCanBeSuppliedForReproducibility(): void
    {
        $counters = new Counters('feste-epoche', 4711);

        self::assertSame('feste-epoche', $counters->processEpoch());
        self::assertSame(4711, $counters->pid());
    }
}
