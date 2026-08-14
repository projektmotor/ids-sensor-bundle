<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;

final class EventBufferTest extends TestCase
{
    public function testCollectsEventsInOrder(): void
    {
        $collector = new EventBuffer();

        $collector->append($this->event('kernel.request'));
        $collector->append($this->event('kernel.response'));

        self::assertSame(2, $collector->count());
        self::assertSame(
            ['kernel.request', 'kernel.response'],
            array_map(static fn (CapturedEvent $e): string => $e->eventType, $collector->all()),
        );
    }

    /**
     * Die Obergrenze verhindert, dass eine Schleife mit vielen
     * Autorisierungsprüfungen den Speicher der überwachten Anwendung füllt.
     */
    public function testDiscardsAboveTheUpperLimitAndCountsThat(): void
    {
        $collector = new EventBuffer(maxEvents: 2);

        $collector->append($this->event('a'));
        $collector->append($this->event('b'));
        $collector->append($this->event('c'));

        self::assertSame(2, $collector->count());
        self::assertTrue($collector->isFull());
        self::assertSame(1, $collector->droppedOverflow());
    }

    public function testDrainEmptiesTheBuffer(): void
    {
        $collector = new EventBuffer();
        $collector->append($this->event('kernel.request'));

        $drained = $collector->drain();

        self::assertCount(1, $drained);
        self::assertTrue($collector->isEmpty());
    }

    /**
     * drain() muss den Puffer leeren, damit ein zweiter Flush-Durchlauf — etwa aus
     * der Shutdown-Funktion nach einem regulären terminate — dieselben Events nicht
     * erneut versendet.
     */
    public function testASecondDrainYieldsNothing(): void
    {
        $collector = new EventBuffer();
        $collector->append($this->event('kernel.request'));

        $collector->drain();

        self::assertSame([], $collector->drain());
    }

    /**
     * In Worker-Laufzeiten ruft Symfonys services_resetter reset() zwischen zwei
     * Requests auf. Ein noch gefüllter Puffer bedeutet dort Datenverlust — der muss
     * gezählt werden, statt still zu passieren.
     */
    public function testResetCountsDiscardedEventsInsteadOfDeletingThemSilently(): void
    {
        $collector = new EventBuffer();
        $collector->append($this->event('kernel.request'));
        $collector->append($this->event('kernel.response'));

        $collector->reset();

        self::assertTrue($collector->isEmpty());
        self::assertSame(2, $collector->droppedReset());
    }

    public function testResetOnAnEmptyBufferCountsNothing(): void
    {
        $collector = new EventBuffer();

        $collector->reset();

        self::assertSame(0, $collector->droppedReset());
    }

    private function event(string $eventType): CapturedEvent
    {
        return CapturedEvent::now(Layer::Kernel, $eventType);
    }
}
