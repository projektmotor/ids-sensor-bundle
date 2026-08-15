<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Erzwingt das Erfassungsbudget im Request-Pfad.
 *
 * Konzept 2.1 Sensorik — Latenzbudget nennt eine verbindliche Obergrenze von 5 ms
 * im 99. Perzentil für alle drei Sensoren zusammen. Ohne Messung wäre das eine
 * Absichtserklärung; diese Klasse macht daraus eine durchgesetzte Grenze: ist das
 * Budget aufgebraucht, wird für den Rest des Durchlaufs nicht mehr erfasst.
 *
 * Der Standardwert liegt bei 1500 µs, also deutlich unter den 5 ms — die 5 ms sind
 * die Obergrenze, nicht das Ziel.
 *
 * {@see guard()} ist der vorgesehene Aufrufweg für Sensoren: es kapselt Messung,
 * Budgetprüfung und die fail-open-Zusage aus Konzept 4. IdsBackendBundle
 * („Fehler werden nie an die Anwendung propagiert") an einer einzigen Stelle,
 * statt in jedem Listener ein eigenes try/catch zu wiederholen.
 *
 * @internal
 */
final class CaptureBudget implements ResetInterface
{
    private int $spentNs = 0;

    private int $limitNs;

    private int $skipped = 0;

    private int $failed = 0;

    public function __construct(
        int $limitMicroseconds = 1500,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->limitNs = max(0, $limitMicroseconds) * 1000;
    }

    /**
     * Führt eine Erfassung aus, die bei erschöpftem Budget entfallen darf.
     *
     * Für Erfassungen, deren Anzahl pro Request nach oben offen ist — vor allem
     * Autorisierungsentscheidungen: eine Übersichtsseite mit einem Voter pro Zeile
     * erzeugt beliebig viele. Genau davor schützt das Budget.
     *
     * Wirft unter keinen Umständen. Ein Fehler im Sensor darf die überwachte
     * Anwendung nicht beeinträchtigen.
     *
     * Gibt bewusst nichts zurück (CLAUDE.md §1.2, Command Query Separation): die
     * Methode ändert einen Zustand — sie führt aus und schreibt Verbrauch und
     * Überspringungen fort. Ob übersprungen wurde, beantwortet {@see skipped()}, und
     * genau dieser Zähler reist auch als dropped_capture_budget nach draußen; ein
     * zweiter Weg zur selben Auskunft wäre eine Gelegenheit, sie unterschiedlich zu
     * beantworten.
     *
     * @param callable():void $capture
     */
    public function guard(callable $capture): void
    {
        if ($this->isExhausted()) {
            ++$this->skipped;

            return;
        }

        $this->run($capture);
    }

    /**
     * Führt eine Erfassung aus, die NICHT entfallen darf — gemessen, aber nicht
     * budgetiert.
     *
     * Gedacht für die konstruktionsbedingt begrenzten Events: pro Request gibt es
     * genau ein kernel.request, ein kernel.response und höchstens ein
     * kernel.exception. Diese drei können das Budget gar nicht sprengen, und sie
     * fallenzulassen wäre ein schlechter Tausch: mit kernel.response ginge der
     * Statuscode verloren — das wichtigste Einzelfeld überhaupt, denn daran hängen
     * die Severity-Ableitung (Konzept 2.2.1) und die Scanning-Erkennung über gehäufte
     * 403/404-Antworten.
     *
     * Der Grund, warum es diese Unterscheidung braucht: die erste Erfassung eines
     * Prozesses zahlt die Einmalkosten für das Laden aller beteiligten Klassen —
     * gemessen rund 2,4 ms gegenüber unter 200 µs im eingeschwungenen Zustand. Ein
     * pauschales Budget hätte damit im ersten Request jedes neu gestarteten
     * FPM-Kindprozesses den Response-Sensor stillgelegt. Bei pm.max_requests = 500
     * wäre das systematisch jeder 500. Request ohne Statuscode, ohne jede Meldung.
     *
     * @param callable():void $capture
     */
    public function guardMandatory(callable $capture): void
    {
        $this->run($capture);
    }

    /**
     * @param callable():void $capture
     */
    private function run(callable $capture): void
    {
        $started = hrtime(true);

        try {
            $capture();
        } catch (\Throwable $e) {
            // GEZÄHLT, nicht bloß geschluckt.
            //
            // Hier stand ein optionaler `$onError`-Rückruf, und KEINE der acht
            // Aufrufstellen übergab ihn — der Zweig war toter Produktionscode. Ein im
            // Sensor geworfener Fehler verschwand damit spurlos: kein Zähler, kein
            // Logeintrag, von „nichts passiert" nicht zu unterscheiden. Das widersprach
            // der Zusage aus Konzept 4. („Jeder verworfene oder verlorene Event wird
            // gezählt") und wörtlich dem Docblock von CapturingEventDispatcher: „Der
            // Sensor selbst protokolliert seine Fehler."
            //
            // Als Rückruf war die Zusage opt-in: Sie galt nur, wenn jede Aufrufstelle
            // daran dachte. Eine Zusage, die jede Aufrufstelle einzeln einhalten muss,
            // ist keine — deshalb zählt und protokolliert das Budget selbst.
            ++$this->failed;

            try {
                $this->logger?->error('ids_sensor: Erfassung fehlgeschlagen: {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            } catch (\Throwable) {
                // Der Fehlerpfad ist die empfindlichste Stelle: Wirft der Logger hier —
                // ein Monolog-Handler auf voller Platte genügt —, entwiche die Exception
                // ausgerechnet beim Behandeln eines Fehlers, und zwar mitten im
                // Request-Pfad. Der Zähler steht dann trotzdem.
            }
        } finally {
            // Auch verpflichtende Erfassungen werden angerechnet: die Messung soll
            // die Wahrheit über die eigenen Kosten sagen, unabhängig davon, ob sie
            // zum Überspringen führt.
            $this->spentNs += hrtime(true) - $started;
        }
    }

    /**
     * Ein Limit von 0 bedeutet „unbegrenzt" — sinnvoll in CLI- und
     * Worker-Kontexten, wo es keine Antwortzeit gibt, auf die zu achten wäre.
     */
    public function isExhausted(): bool
    {
        return 0 !== $this->limitNs && $this->spentNs >= $this->limitNs;
    }

    /**
     * Die gemessene Erfassungszeit dieses Durchlaufs in Nanosekunden.
     *
     * Wird beim Flush an den {@see \ProjektMotor\IdsSensor\Support\Telemetry\LatencyRecorder}
     * gereicht. Ohne diesen Weg maß das Budget zwar mit, behielt die Zahl aber für sich:
     * `heartbeat.latency.in_request_overhead_us` war dauerhaft leer, obwohl Konzept 3.4
     * genau daran die 5-ms-Zusage aus 2.1 überprüfbar macht.
     */
    public function spentNanoseconds(): int
    {
        return $this->spentNs;
    }

    public function spentMicroseconds(): float
    {
        return $this->spentNs / 1000;
    }

    /**
     * Wie viele Erfassungen wegen erschöpften Budgets übersprungen wurden.
     *
     * Wandert als dropped_capture_budget in die Zählerstände des Frames und in
     * den Heartbeat, damit der Verlust sichtbar ist.
     */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * Wie viele Erfassungen mit einem Fehler endeten.
     *
     * Wandert als `dropped_capture_error` in die Zählerstände. Ohne ihn war ein Defekt im
     * Sensor von einem ruhigen Request nicht zu unterscheiden.
     */
    public function failed(): int
    {
        return $this->failed;
    }

    /**
     * Setzt das Budget für den nächsten Durchlauf zurück (Worker-Laufzeiten).
     * Der skipped-Zähler bleibt bestehen — er ist eine Prozess-Statistik.
     */
    public function reset(): void
    {
        $this->spentNs = 0;
    }
}
