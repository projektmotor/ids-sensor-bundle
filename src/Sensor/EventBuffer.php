<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Der Puffer: eine Liste erfasster Events im Arbeitsspeicher des laufenden
 * Prozesses. Kein Cache, keine Datei, keine Queue.
 *
 * Existiert, damit im Request nichts serialisiert oder versendet werden muss —
 * beides passiert erst nach dem Absenden der Antwort (Konzept 2.1 Sensorik —
 * Latenzbudget verbietet Datenbankabfragen im Request-Pfad, und ein
 * Netzwerk-Roundtrip zum Broker würde das 5-ms-Budget allein aufbrauchen).
 *
 * Zwei Obergrenzen:
 *  - pro Request/Durchlauf: verhindert, dass eine Schleife mit vielen
 *    Autorisierungsprüfungen den Speicher füllt
 *  - pro Prozess: greift in langlebigen Prozessen (Messenger-Worker,
 *    Import-Commands), in denen kein kernel.terminate den Puffer leert
 *
 * @internal
 */
final class EventBuffer implements ResetInterface
{
    /** @var list<CapturedEvent> */
    private array $events = [];

    private int $droppedOverflow = 0;

    private int $droppedReset = 0;

    public function __construct(
        private readonly int $maxEvents = 64,
    ) {
    }

    /**
     * Nimmt ein Event auf. Ist der Puffer voll, wird verworfen und gezählt —
     * niemals stillschweigend.
     */
    public function append(CapturedEvent $event): void
    {
        if (\count($this->events) >= $this->maxEvents) {
            ++$this->droppedOverflow;

            return;
        }

        $this->events[] = $event;
    }

    /**
     * @return list<CapturedEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    public function count(): int
    {
        return \count($this->events);
    }

    public function isEmpty(): bool
    {
        return [] === $this->events;
    }

    public function isFull(): bool
    {
        return \count($this->events) >= $this->maxEvents;
    }

    /**
     * Entnimmt alle Events und leert den Puffer.
     *
     * Wird vom Flusher benutzt, damit ein zweiter Flush-Durchlauf (etwa aus der
     * Shutdown-Funktion nach einem regulären terminate) nicht dieselben Events
     * erneut versendet.
     *
     * @return list<CapturedEvent>
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * Wird von Symfonys services_resetter zwischen zwei Requests aufgerufen
     * (relevant in Worker-Laufzeiten wie FrankenPHP oder RoadRunner).
     *
     * Ein noch gefüllter Puffer bedeutet hier: es gab keinen Flush, die Events
     * gehen verloren. Statt sie still zu löschen, werden sie gezählt — ein
     * sichtbarer Zähler ist einem unsichtbaren Datenverlust vorzuziehen
     * (Konzept 4. IdsBackendBundle — Restrisiko).
     */
    public function reset(): void
    {
        $pending = \count($this->events);
        if ($pending > 0) {
            $this->droppedReset += $pending;
            $this->events = [];
        }
    }

    public function droppedOverflow(): int
    {
        return $this->droppedOverflow;
    }

    public function droppedReset(): int
    {
        return $this->droppedReset;
    }
}
