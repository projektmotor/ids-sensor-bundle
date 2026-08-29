<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Telemetry;

use Symfony\Component\Uid\Uuid;

/**
 * Monotone Zählerstände des Sensors.
 *
 * Warum monoton und nicht als Delta pro Frame: Die Zustellgarantie ist at-least-once
 * (Konzept 4. IdsBackendBundle — Zustellgarantie), Duplikate sind also normal.
 * Delta-Zähler würden bei einer erneuten Zustellung doppelt gezählt. Monotone Werte
 * mit einem Prozess-Schlüssel sind idempotent: der Collector nimmt je Schlüssel das
 * Maximum und summiert über die Schlüssel.
 *
 * Der Schlüssel ist (sensor_id, process_epoch, pid). process_epoch wird einmal pro
 * Prozess erzeugt, damit ein Neustart nicht als Rückwärtssprung erscheint.
 *
 * Diese Umsetzung hält die Werte nur im Prozessspeicher, und dabei bleibt es vorerst.
 * Eine dateibasierte Materialisierung stand hier als „folgt mit dem Spool" — der Spool
 * ist längst da, sie nicht. Sie ist auch keine offene Baustelle, sondern eine
 * Entscheidung: Der Prozess-Schlüssel (sensor_id, process_epoch, pid) macht die
 * Stände collectorseitig zusammensetzbar, ohne dass Prozesse einen gemeinsamen Zustand
 * brauchen. Was ein CLI-Prozess zählt, reist in SEINEN Frames mit; was ein FPM-Kind
 * zählt, in seinen. Erst wenn ein Zähler einen Prozesstod überleben müsste — bisher
 * muss das keiner —, wird die Materialisierung nötig.
 *
 * @internal
 */
final class Counters
{
    /** Erfasste Events, bevor irgendetwas verworfen wurde. */
    public const CAPTURED = 'captured';

    /** Erfolgreich an den Broker übergeben. */
    public const SENT = 'sent';

    /** In den Spool geschrieben statt direkt gesendet. */
    public const SPOOLED = 'spooled';

    /** Verworfen, weil der Puffer die Obergrenze erreicht hatte. */
    public const DROPPED_BUFFER_FULL = 'dropped_buffer_full';

    /** Verworfen, weil das Erfassungsbudget im Request erschöpft war. */
    public const DROPPED_CAPTURE_BUDGET = 'dropped_capture_budget';

    /**
     * Verworfen, weil die Erfassung selbst einen Fehler geworfen hat.
     *
     * Eigener Zähler und nicht DROPPED_CAPTURE_BUDGET: Der eine sagt „die Zeit war alle",
     * der andere „der Sensor ist defekt". Die erste Auskunft führt zu einer
     * Latenzuntersuchung, die zweite zu einem Fehlerbericht.
     */
    public const DROPPED_CAPTURE_ERROR = 'dropped_capture_error';

    /** Verworfen, weil der Puffer beim Service-Reset noch gefüllt war. */
    public const DROPPED_RESET = 'dropped_reset';

    /**
     * Verworfen, weil ein Request mehr Autorisierungsentscheidungen erzeugt hat, als
     * {@see \ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor} pro Request
     * aufnimmt.
     *
     * Eigener Zähler und nicht DROPPED_CAPTURE_BUDGET: der eine sagt „die Zeit war
     * alle", der andere „diese eine Seite prüft mehr Rechte als vorgesehen". Die erste
     * Auskunft führt zu einer Latenzuntersuchung, die zweite zu einer höheren
     * max_decisions_per_request — dieselbe Zahl für beides ließe nicht erkennen, welche
     * der beiden Maßnahmen greift.
     */
    public const DROPPED_DECISION_CAP = 'dropped_decision_cap';

    /** Verworfen, weil für die Ebene kein Normalisierer registriert ist. */
    public const DROPPED_NO_NORMALIZER = 'dropped_no_normalizer';

    /** Verworfen, weil die Normalisierung selbst fehlgeschlagen ist. */
    public const DROPPED_NORMALIZE_ERROR = 'dropped_normalize_error';

    /**
     * Verworfen, weil der Frame die Größengrenze überschreitet.
     *
     * Eigener Zähler und nicht DROPPED_SPOOL_FULL: Der eine sagt „die Platte ist voll",
     * der andere „diese eine Sendung ist zu groß". Die erste Auskunft führt zu mehr
     * Plattenplatz, die zweite zu einer Untersuchung des Payloads — dieselbe Zahl für
     * beides ließe nicht erkennen, welche der beiden Maßnahmen greift.
     */
    public const DROPPED_FRAME_TOO_LARGE = 'dropped_frame_too_large';

    /**
     * Beim Nachsenden verworfen: unlesbare Zeile oder dauerhaft unversendbarer Frame.
     *
     * Beides bedeutet dasselbe — ein zweiter Versuch scheitert an derselben Stelle. Der
     * Zaehler unterscheidet sich von DROPPED_SPOOL_FULL darin, dass hier nicht der Platz
     * fehlte, sondern der Inhalt unbrauchbar war.
     */
    public const DROPPED_SPOOL_UNREADABLE = 'dropped_spool_unreadable';

    /** Verworfen, weil auch der Spool nichts mehr aufnehmen konnte. */
    public const DROPPED_SPOOL_FULL = 'dropped_spool_full';

    /**
     * Absichtlich weggesampelte Events (Konzept 4.2.3).
     *
     * Auch ein gewollter Verlust wird gezählt. Ohne diesen Zähler wäre eine zu niedrig
     * gesetzte info_rate von einem Sensordefekt nicht zu unterscheiden — man sähe nur, dass
     * weniger ankommt als erwartet.
     */
    public const DROPPED_SAMPLING = 'dropped_sampling';

    /** Versand fehlgeschlagen (Broker nicht erreichbar, ACL, Timeout). */
    public const SHIP_FAILED = 'ship_failed';

    /**
     * Heartbeats werden mitgezählt, obwohl sie keine Events sind.
     *
     * Grund: aus Sicht des Collectors ist ein ausbleibender Heartbeat nicht von einem
     * gescheiterten zu unterscheiden. Kommt später wieder einer durch, zeigt
     * `heartbeat_failed`, dass der Sensor die Lücke SELBST bemerkt hat — und trennt damit
     * „Broker war weg" von „Sensor war stillgelegt". Ohne den Zähler bliebe nur eine
     * Lücke ohne Erklärung.
     */
    public const HEARTBEAT_SENT = 'heartbeat_sent';

    public const HEARTBEAT_FAILED = 'heartbeat_failed';

    /** @var array<string, int> */
    private array $values = [];

    private readonly string $processEpoch;

    private readonly int $pid;

    public function __construct(?string $processEpoch = null, ?int $pid = null)
    {
        $this->processEpoch = $processEpoch ?? Uuid::v7()->toRfc4122();
        $this->pid = $pid ?? getmypid() ?: 0;
    }

    public function increment(string $name, int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }

        $this->values[$name] = ($this->values[$name] ?? 0) + $by;
    }

    public function get(string $name): int
    {
        return $this->values[$name] ?? 0;
    }

    /**
     * Setzt einen Zähler auf einen absoluten Wert, aber nur aufwärts.
     *
     * Gebraucht für Zähler, die anderswo geführt werden (Puffer-Überlauf im
     * EventBuffer, Budget-Überschreitungen im CaptureBudget) und beim Flush
     * eingesammelt werden. Nie abwärts, damit die Monotonie erhalten bleibt.
     */
    public function raiseTo(string $name, int $value): void
    {
        if ($value > ($this->values[$name] ?? 0)) {
            $this->values[$name] = $value;
        }
    }

    public function processEpoch(): string
    {
        return $this->processEpoch;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->values;
    }
}
