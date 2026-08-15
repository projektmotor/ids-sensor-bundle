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
 * Die Obergrenze verhindert, dass eine Schleife mit vielen Autorisierungsprüfungen
 * den Speicher füllt.
 *
 * EINE Obergrenze, nicht zwei
 *
 * Hier stand „Zwei Obergrenzen: pro Request und pro Prozess", und dazu gab es die
 * Konfigurationsoption `budget.max_events_per_process`. Beides ist entfallen, weil die
 * zweite Grenze keine kohärente Bedeutung hatte:
 *
 *  - Als Grenze für den AKTUELLEN Inhalt wäre sie wirkungslos — {@see drain()} leert den
 *    Puffer, sein Inhalt liegt also ohnehin immer unter `maxEvents`, und die Vorgabe 200
 *    lag über den 64 des Requests.
 *  - Als kumulative Grenze über die Prozesslebenszeit wäre sie schädlich: Ein
 *    Messenger-Worker läuft Stunden und hätte nach 200 Events dauerhaft aufgehört zu
 *    erfassen — ein blinder Sensor, der weiterläuft.
 *
 * Der Fall, den die Begründung nannte („langlebige Prozesse, in denen kein
 * kernel.terminate den Puffer leert"), tritt nicht ein:
 * {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener} hängt zusätzlich an
 * `console.terminate` und an den Worker-Ereignissen, leert also nach jeder Nachricht.
 *
 * ZWEI AUFNAHMEWEGE, PASSEND ZU DEN ZWEI BUDGETWEGEN
 *
 * {@see append()} und {@see appendMandatory()} entsprechen genau
 * {@see CaptureBudget::guard()} und {@see CaptureBudget::guardMandatory()}. Der Grund
 * ist derselbe, und er stand vorher nur auf der Budgetseite: Die Zahl der
 * Autorisierungsentscheidungen ist nach oben offen, die der Kernel- und
 * Anmeldeereignisse konstruktionsbedingt nicht.
 *
 * Ohne diese Unterscheidung hob das Budget ein Versprechen auf, das der Puffer
 * anschließend brach. `guardMandatory()` begründet sich wörtlich damit, dass „mit
 * kernel.response der Statuscode verloren ginge — das wichtigste Einzelfeld
 * überhaupt"; der Puffer kannte den Unterschied aber nicht. Mit den Vorgaben
 * `budget.max_events_per_request: 64` und `layers.security.max_decisions_per_request: 200`
 * genügte eine Übersichtsseite mit 64 Rechteprüfungen — der ausdrücklich als
 * unverzichtbar bezeichnete `kernel.response` fiel dann als letzter heraus, weil der
 * ResponseSensor bei Priorität −2048 zuletzt läuft.
 *
 * @internal
 */
final class EventBuffer implements ResetInterface
{
    /**
     * Zusätzliche Plätze, die ausschließlich Pflicht-Events bekommen.
     *
     * Klein und fest: Ein Haupt-Request erzeugt höchstens drei Kernel-Events und ein
     * Anmeldeereignis; der Rest deckt einige Sub-Requests ab. Eine Reserve, die mit der
     * Obergrenze wüchse, würde den Zweck der Obergrenze aushöhlen — und auch
     * Pflicht-Events darüber hinaus werden verworfen und gezählt, nicht unbegrenzt
     * gepuffert (Konzept 4.).
     */
    public const MANDATORY_RESERVE = 8;

    /** @var list<CapturedEvent> */
    private array $events = [];

    private int $droppedOverflow = 0;

    private int $droppedReset = 0;

    public function __construct(
        private readonly int $maxEvents = 64,
    ) {
    }

    /**
     * Nimmt ein Event auf, dessen Anzahl pro Durchlauf nach oben offen ist.
     *
     * Ist der Puffer voll, wird verworfen und gezählt — niemals stillschweigend.
     */
    public function append(CapturedEvent $event): void
    {
        $this->appendUpTo($event, $this->maxEvents);
    }

    /**
     * Nimmt ein Event auf, das nicht entfallen darf.
     *
     * Für die konstruktionsbedingt begrenzten Ereignisse: kernel.request,
     * kernel.response, kernel.exception und die Anmeldeereignisse. Sie bekommen
     * {@see MANDATORY_RESERVE} Plätze oberhalb der Obergrenze, damit eine Seite mit
     * vielen Rechteprüfungen ihnen nicht den Platz wegnimmt.
     */
    public function appendMandatory(CapturedEvent $event): void
    {
        $this->appendUpTo($event, $this->maxEvents + self::MANDATORY_RESERVE);
    }

    private function appendUpTo(CapturedEvent $event, int $limit): void
    {
        if (\count($this->events) >= $limit) {
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

    /**
     * Ob die reguläre Obergrenze erreicht ist — Pflicht-Events passen dann noch.
     */
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
