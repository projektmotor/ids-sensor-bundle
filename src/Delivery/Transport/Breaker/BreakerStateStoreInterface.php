<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Breaker;

/**
 * Hält den Zustand des Circuit Breakers über Requests hinweg.
 *
 * Der Zustand MUSS prozessübergreifend sichtbar sein, sonst ist der Breaker
 * wirkungslos: unter PHP-FPM bearbeitet jedes Kindprozess-Exemplar eigene Requests,
 * und ein nur im Prozessspeicher gehaltener Zähler würde bei einem Collector-Ausfall in
 * jedem Kindprozess von Null anfangen. Genau dann soll der Breaker aber greifen.
 *
 * @internal
 */
interface BreakerStateStoreInterface
{
    public function read(): BreakerState;

    public function write(BreakerState $state): void;

    /**
     * Liest, wendet an, schreibt — für konkurrierende Prozesse unteilbar.
     *
     * WOZU ES DAS BRAUCHT
     *
     * Der Breaker zählte mit `read()` + `write()`, und das ist ein Lost Update. Fällt der
     * Collector aus, laufen n FPM-Kinder gleichzeitig durch diesen Pfad, lesen alle
     * `failures = 0` und schreiben alle `1`. Der Zähler stieg damit nicht mit der Zahl der
     * Fehlschläge, sondern wurde ständig zurückgesetzt — die Schwelle wurde im
     * ungünstigen Fall NIE erreicht, und `openCount` verzählte sich mit.
     *
     * Ausgerechnet unter Last, also in genau dem Szenario, für das
     * {@see CircuitBreaker} laut seinem eigenen Docblock existiert: „Ein FPM-Pool mit 32
     * Kindprozessen bei 200 Requests pro Sekunde ist damit erschöpft."
     *
     * Die Entscheidung, WAS aus dem Zustand wird, bleibt beim Breaker — der Mutator ist
     * eine reine Funktion. Der Speicher liefert nur die Unteilbarkeit.
     *
     * FAIL-OPEN GILT AUCH HIER: Lässt sich die Sperre nicht in vertretbarer Zeit
     * bekommen, führt die Umsetzung den unsicheren Weg aus, statt zu blockieren. Ein
     * Breaker, der einen Request anhält, ist schlimmer als einer, der gelegentlich
     * verzählt.
     *
     * @param \Closure(BreakerState): BreakerState $mutator
     *
     * @return BreakerState der geschriebene Zustand
     */
    public function mutate(\Closure $mutator): BreakerState;
}
