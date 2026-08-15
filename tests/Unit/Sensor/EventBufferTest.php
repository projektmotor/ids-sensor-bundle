<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
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

    /**
     * Pflicht-Events müssen auch dann noch hineinpassen, wenn die Obergrenze erreicht ist.
     *
     * Die Zusage stammt aus {@see \ProjektMotor\IdsSensor\Sensor\CaptureBudget::guardMandatory()}:
     * „mit kernel.response ginge der Statuscode verloren — das wichtigste Einzelfeld
     * überhaupt". Der Puffer kannte diesen Unterschied vorher nicht, und mit den
     * Vorgabewerten genügte eine Seite mit 64 Rechteprüfungen, um genau dieses Event zu
     * verlieren: der ResponseSensor läuft bei Priorität −2048 zuletzt.
     */
    public function testMandatoryEventsStillFitWhenTheLimitIsReached(): void
    {
        $collector = new EventBuffer(maxEvents: 2);

        $collector->append($this->event('security.access_decision'));
        $collector->append($this->event('security.access_decision'));
        $collector->append($this->event('security.access_decision'));

        self::assertTrue($collector->isFull());
        self::assertSame(1, $collector->droppedOverflow());

        $collector->appendMandatory($this->event('kernel.response'));

        self::assertSame(3, $collector->count());
        self::assertSame(
            'kernel.response',
            $collector->all()[2]->eventType,
            'Das Pflicht-Event muss im Puffer stehen',
        );
        self::assertSame(1, $collector->droppedOverflow(), 'Und es darf nichts zusätzlich verwerfen');
    }

    /**
     * Die Reserve ist begrenzt — auch Pflicht-Events werden irgendwann verworfen, aber
     * gezählt. Unbegrenztes Puffern verböte Konzept 4.
     */
    public function testTheReserveIsBoundedAndOverflowIsCounted(): void
    {
        $collector = new EventBuffer(maxEvents: 1);

        $collector->append($this->event('security.access_decision'));

        for ($i = 0; $i <= EventBuffer::MANDATORY_RESERVE; ++$i) {
            $collector->appendMandatory($this->event('kernel.response'));
        }

        self::assertSame(1 + EventBuffer::MANDATORY_RESERVE, $collector->count());
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
