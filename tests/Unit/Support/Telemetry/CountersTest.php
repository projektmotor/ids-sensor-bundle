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
    public function testAnUntouchedCounterIsZeroAndNotAbsent(): void
    {
        $counters = new Counters();

        self::assertSame(0, $counters->get(Counters::DROPPED_BUFFER_FULL));
        self::assertSame([], $counters->all(), 'Ein nie berührter Zähler steht nicht im Frame');
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
