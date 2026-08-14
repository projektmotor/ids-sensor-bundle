<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Telemetry;

use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor;

/**
 * Holt beim Flush die Zähler ein, die anderswo geführt werden.
 *
 * WARUM DIESE ZÄHLER NICHT GLEICH IN {@see Counters} LANDEN
 *
 * Ihre Quellen liegen alle in Phase A und laufen damit unter dem Erfassungsbudget aus
 * Konzept 2.1. Dort soll kein zusätzlicher Dienst im Weg stehen — jede von ihnen zählt
 * ihren Verlust deshalb in einem eigenen `int` und wird erst nach dem Absenden der
 * Antwort abgefragt.
 *
 * WARUM EIN EIGENER DIENST UND KEINE METHODE IM {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\EventFlusher}
 *
 * Der Flusher brauchte für diese eine Methode drei Abhängigkeiten, die er sonst nirgends
 * benutzte. Nach CLAUDE.md §1.8 ist das der Befund „eigene Klasse": eine Feldgruppe, die
 * nur in einer Methode auftritt. Und jede weitere Verlustquelle in Phase A ist damit ein
 * Konstruktorargument HIER statt eines weiteren im Flusher.
 *
 * ÜBERNOMMEN WIRD AUFWÄRTS
 *
 * Konzept 3.4: „Die Zähler sind absolut, nicht als Zuwachs." Bei at-least-once-Zustellung
 * würden Deltas bei einer erneuten Zustellung doppelt zählen. Die Quellen führen deshalb
 * prozessweit monotone Stände, und {@see Counters::raiseTo()} übernimmt sie nie abwärts.
 *
 * @internal
 */
final class DeferredCounters
{
    public function __construct(
        private readonly Counters $counters,
        private readonly EventBuffer $buffer,
        private readonly CaptureBudget $captureBudget,
        private readonly ?AccessDecisionSensor $accessDecisionSensor = null,
    ) {
    }

    public function collect(): void
    {
        $this->counters->raiseTo(Counters::DROPPED_BUFFER_FULL, $this->buffer->droppedOverflow());
        $this->counters->raiseTo(Counters::DROPPED_RESET, $this->buffer->droppedReset());
        $this->counters->raiseTo(Counters::DROPPED_CAPTURE_BUDGET, $this->captureBudget->skipped());

        // Fehlt, wenn die Security-Ebene oder die Entscheidungserfassung abgeschaltet ist.
        // Dann gibt es die Verlustquelle nicht und der Zähler bleibt bei 0 — richtig, denn
        // ein 0-Stand heißt hier „nichts verloren" und nicht „nicht gemessen".
        if (null !== $this->accessDecisionSensor) {
            $this->counters->raiseTo(
                Counters::DROPPED_DECISION_CAP,
                $this->accessDecisionSensor->overflowCount(),
            );
        }
    }
}
