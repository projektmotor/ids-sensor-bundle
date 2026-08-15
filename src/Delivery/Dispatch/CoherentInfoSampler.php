<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Vocabulary\Layer;

/**
 * Dünnt info-Events der Kernel-Ebene aus — kohärent pro Request.
 *
 * WOZU
 *
 * Konzept 4.2.3 (Volumenbudget und gestufte Retention) nennt Sampling als das Stellrad
 * für Instanzen, deren Ereignisvolumen das Budget übersteigt. Betroffen ist ausschließlich
 * die Masse: `kernel.request` und erfolgreiche `kernel.response` sind pro Request garantiert
 * vorhanden und in aller Regel `info`.
 *
 * WAS NIE GESAMPELT WIRD
 *
 *  - `warning` und `critical` (Konzept 4.2.3 ausdrücklich) — sie tragen die Erkennung.
 *  - Security- und Business-Events, auch wenn sie `info` sind. Ein erfolgreicher Login ist
 *    `info`, ist aber die Voraussetzung für Regel B5 (Erfolg nach Fehlversuchsserie); ein
 *    Business-Event ist laut Konzept 2.1.3 die EINZIGE Signalklasse für erfolgreiche
 *    Angriffe. Beide sind ohnehin selten — sie zu sampeln spart kein Volumen und kostet
 *    Erkennung.
 *
 * WARUM DIE ENTSCHEIDUNG PRO REQUEST FÄLLT UND NICHT PRO EVENT
 *
 * Das ist der Kern und der Grund für „kohärent" im Namen. Fiele die Entscheidung je Event,
 * käme bei einer Rate von 0,1 regelmäßig ein `kernel.response` ohne den zugehörigen
 * `kernel.request` an — und umgekehrt. Für den Collector wäre das nicht von einem
 * Verbindungsabbruch zu unterscheiden, und jeder Self-Join über die `correlation_id`
 * (Konzept 3.2) liefe ins Leere. Man hätte 90 % des Volumens gespart und dabei 100 % der
 * Verknüpfbarkeit verloren.
 *
 * Deshalb: eine Ziehung pro Request, gültig für alle sampelbaren Events dieses Requests.
 *
 * WARUM EIN RELEVANTER REQUEST SEINE INFO-EVENTS BEHÄLT
 *
 * Enthält ein Request irgendein `warning`/`critical`, werden seine `info`-Events
 * mitbehalten. Sonst käme bei einem 500er gerade der `kernel.request` nicht an — also der
 * Pfad, die Methode, die Query und der User-Agent. Die Exception allein sagt, DASS etwas
 * kaputtging, nicht WORAUF. Das ist keine Volumenfrage: relevante Requests sind selten,
 * und ihr Kontext ist der teuerste Teil eines Ausfalls.
 *
 * WARUM DIE ZIEHUNG ZUFÄLLIG IST UND NICHT AUS DER correlation_id ABGELEITET
 *
 * Eine Ableitung aus der `correlation_id` wäre reproduzierbar und billiger. Sie wäre aber
 * STEUERBAR: ist `correlation.require_trusted_proxy` gelockert, setzt der Client die ID
 * selbst — und ein Angreifer könnte solange IDs probieren, bis er eine findet, die
 * garantiert weggesampelt wird. Er hätte damit einen selbst gewählten blinden Fleck. Bei
 * `random_int()` ist das ausgeschlossen. Die Kosten sind vertretbar: EIN Aufruf pro
 * Request, nicht pro Event.
 *
 * @internal
 */
final class CoherentInfoSampler
{
    /** Der Nenner der Ziehung. Sechs Stellen genügen für Raten bis 0,000001. */
    private const PRECISION = 1_000_000;

    /**
     * @param \Closure(int, int): int|null $randomInt nur für Tests; Standard ist random_int()
     */
    public function __construct(
        private readonly float $infoRate = 1.0,
        private readonly bool $keepIfRequestRelevant = true,
        private readonly ?\Closure $randomInt = null,
    ) {
    }

    /**
     * Ist Sampling überhaupt eingeschaltet?
     *
     * Bei Rate 1.0 — der Vorgabe — entfällt der ganze Schritt. Es wird nicht gezogen, nichts
     * kopiert und kein `sampling_rate` gesetzt. Sampling ist ein Stellrad für den Notfall,
     * kein Regelbetrieb.
     */
    public function isActive(): bool
    {
        return $this->infoRate < 1.0;
    }

    /**
     * @param list<NormalizedEvent> $events alle normalisierten Events EINES Requests
     *
     * @return list<NormalizedEvent>
     */
    public function sample(array $events): array
    {
        if (!$this->isActive() || [] === $events) {
            return $events;
        }

        $sampleable = [];

        foreach ($events as $index => $event) {
            if ($this->isSampleable($event)) {
                $sampleable[$index] = true;
            }
        }

        if ([] === $sampleable) {
            return $events;
        }

        if ($this->keepIfRequestRelevant && $this->containsRelevantEvent($events)) {
            return $events;
        }

        // EINE Ziehung für den ganzen Request.
        if ($this->survives()) {
            // Überlebt: die Rate reist mit, damit der Collector Aggregate hochrechnen
            // kann (Konzept 4.2.3). Ohne sie wäre jede Zählung um den Faktor 1/rate zu
            // klein — und niemand könnte das im Nachhinein korrigieren.
            $kept = [];

            foreach ($events as $index => $event) {
                $kept[] = isset($sampleable[$index]) ? $event->withSamplingRate($this->infoRate) : $event;
            }

            return $kept;
        }

        // Verworfen: nur die sampelbaren Events fallen weg. Security- und
        // Business-Events desselben Requests bleiben — sie sind nie sampelbar.
        $kept = [];

        foreach ($events as $index => $event) {
            if (!isset($sampleable[$index])) {
                $kept[] = $event;
            }
        }

        return $kept;
    }

    /**
     * Wie viele Events diese Runde weggesampelt wurden — für den Verlustzähler.
     *
     * Konzept 4. verlangt, dass jeder verworfene Event gezählt wird. Sampling ist ein
     * ABSICHTLICHER Verlust, aber deshalb kein unsichtbarer: ohne Zähler wäre eine zu
     * niedrig gesetzte Rate von einem Sensordefekt nicht zu unterscheiden.
     *
     * @param list<NormalizedEvent> $before
     * @param list<NormalizedEvent> $after
     */
    public function droppedCount(array $before, array $after): int
    {
        return max(0, \count($before) - \count($after));
    }

    private function isSampleable(NormalizedEvent $event): bool
    {
        return Layer::Kernel === $event->layer && $event->severity->isSampleable();
    }

    /**
     * @param list<NormalizedEvent> $events
     */
    private function containsRelevantEvent(array $events): bool
    {
        foreach ($events as $event) {
            if (!$event->severity->isSampleable()) {
                return true;
            }
        }

        return false;
    }

    private function survives(): bool
    {
        $threshold = (int) round($this->infoRate * self::PRECISION);

        if ($threshold <= 0) {
            return false;
        }

        $draw = null !== $this->randomInt
            ? ($this->randomInt)(0, self::PRECISION - 1)
            : random_int(0, self::PRECISION - 1);

        return $draw < $threshold;
    }
}
