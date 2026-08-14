<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Breaker;

/**
 * Verhindert, dass ein Broker-Ausfall die überwachte Anwendung erdrosselt.
 *
 * DIESER BAUSTEIN IST NICHT OPTIONAL, und der Grund ist nicht Eleganz.
 *
 * Ohne Breaker sieht ein Broker-Ausfall so aus: jeder Request läuft in den
 * Verbindungs- oder Lese-Timeout. Bei 20 ms Connect- und 30 ms Read-Timeout sind das
 * bis zu 50 ms zusätzliche Belegung — pro Request, für die Dauer des Ausfalls. Ein
 * FPM-Pool mit 32 Kindprozessen bei 200 Requests pro Sekunde ist damit erschöpft, und
 * die Anwendung ist nicht mehr erreichbar.
 *
 * Das Ergebnis wäre paradox: die Grundsatzentscheidung fail-open aus Konzept 4. soll
 * garantieren, dass eine IDS-Störung die Anwendung nicht beeinträchtigt — ohne Breaker
 * würde sie unter Last genau ins Gegenteil kippen und *closed* failen.
 *
 * Ist der Breaker offen, findet gar kein Verbindungsversuch statt: null Netzwerk-I/O,
 * der Frame geht direkt in den Spool.
 *
 * @internal
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly BreakerStateStoreInterface $store,
        private readonly int $failureThreshold = 3,
        private readonly int $openForSeconds = 30,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Ob der Broker als tot gilt und übersprungen werden soll.
     */
    public function isOpen(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->store->read()->isOpenAt(microtime(true));
    }

    /**
     * Ein erfolgreicher Versand schließt den Breaker vollständig.
     *
     * Damit ist der Halb-offen-Zustand implizit umgesetzt: sobald die Offen-Zeit
     * abgelaufen ist, meldet isOpen() wieder false, der nächste Request ist die
     * Probe. Gelingt sie, wird hier zurückgesetzt; scheitert sie, öffnet
     * recordFailure() sofort erneut, weil der Fehlerzähler noch über der Schwelle
     * steht.
     */
    public function recordSuccess(): void
    {
        if (!$this->enabled) {
            return;
        }

        $state = $this->store->read();

        // Nur schreiben, wenn sich etwas ändert — im Normalbetrieb ist das der
        // häufigste Pfad und soll nichts kosten.
        if (0 !== $state->failures || 0.0 !== $state->openUntil) {
            $this->store->write(new BreakerState(0, 0.0, $state->openCount));
        }
    }

    public function recordFailure(): void
    {
        if (!$this->enabled) {
            return;
        }

        $state = $this->store->read();
        $failures = $state->failures + 1;

        if ($failures >= $this->failureThreshold) {
            $this->store->write(new BreakerState(
                $failures,
                microtime(true) + $this->openForSeconds,
                $state->openCount + 1,
            ));

            return;
        }

        $this->store->write(new BreakerState($failures, 0.0, $state->openCount));
    }

    /**
     * Für Heartbeat und ids:sensor:setup-check — der Betreiber soll sehen, ob und wie oft
     * der Breaker gegriffen hat.
     *
     * @return array{state: string, failures: int, open_count: int, open_for_ms: int}
     */
    public function snapshot(): array
    {
        $state = $this->store->read();
        $now = microtime(true);
        $open = $state->isOpenAt($now);

        return [
            'state' => $open ? 'open' : (0 === $state->failures ? 'closed' : 'half_open'),
            'failures' => $state->failures,
            'open_count' => $state->openCount,
            'open_for_ms' => $open ? (int) round(($state->openUntil - $now) * 1000) : 0,
        ];
    }
}
