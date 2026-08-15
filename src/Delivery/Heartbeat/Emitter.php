<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Heartbeat;

use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use Psr\Log\LoggerInterface;

/**
 * Versendet den Heartbeat.
 *
 * WARUM HEARTBEATS NICHT GESPOOLT WERDEN
 *
 * Das ist die eine Stelle, an der der Sensor bewusst NICHT auf den Spool zurückfällt, und
 * die Begründung ist der Zweck des Heartbeats selbst: er ist eine Aussage über das JETZT.
 * Ein gespoolter Heartbeat käme Minuten oder Stunden später an und behauptete Leben zu
 * einem Zeitpunkt, an dem der Sensor den Broker gerade nicht erreichte. Der Collector
 * würde `ids.sensor_silent` unterdrücken — für einen Sensor, der tatsächlich nichts
 * liefern konnte. Der Alarm wäre nachträglich weggeräumt, obwohl er berechtigt war.
 *
 * Scheitert der Versand, ist Schweigen die richtige Auskunft: der Sensor erreicht den
 * Broker nicht, und exakt das soll der Collector erkennen. Gezählt wird es trotzdem.
 *
 * DER BREAKER WIRD GELESEN, ABER NUR EINSEITIG BESCHRIEBEN
 *
 * Gelesen: im request-getriebenen Modus läuft der Versand in `kernel.terminate`, und bei
 * einem Broker-Ausfall würde jeder Versuch ein Timeout kosten und einen Worker belegen.
 * Genau davor schützt der Breaker beim Frame-Versand; der Heartbeat darf diese Absicherung
 * nicht umgehen.
 *
 * Ein Fehlschlag wird aber NICHT in den Breaker gezählt. Der Grund ist gemessen und nicht
 * theoretisch: Frame-Versand und Heartbeat laufen im selben kernel.terminate. Zählten
 * beide, ergäbe EIN Broker-Ausfall ZWEI Fehlschläge, und ein konfiguriertes
 * `failure_threshold: 2` wäre faktisch 1. Der Wert bedeutete etwas anderes, als er sagt —
 * und niemand könnte den Unterschied an der Konfiguration ablesen. Die Schwelle gehört dem
 * Frame-Pfad, also dem Pfad, der Antwortzeit kostet. Eigene Fehlschläge zählt der Heartbeat
 * in `heartbeat_failed`.
 *
 * Ein ERFOLG wird hingegen vermerkt: ein durchgekommener Heartbeat ist ein
 * angenommenes XADD und damit genau die Half-Open-Probe, auf die der Breaker wartet. Die
 * Asymmetrie ist Absicht — sie kann die Erholung nur beschleunigen, niemals das Verstummen.
 *
 * @internal
 */
final class Emitter implements EmitterInterface
{
    public function __construct(
        private readonly PayloadFactory $payloadFactory,
        private readonly Scheduler $scheduler,
        private readonly ShipperInterface $shipper,
        private readonly Counters $counters,
        private readonly Mode $mode,
        private readonly RuntimeProfile $runtime,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CircuitBreaker $breaker = null,
    ) {
    }

    /**
     * Sendet, wenn fällig. Der reguläre Weg für beide Modi.
     *
     * @return bool ob gesendet wurde
     */
    public function emitIfDue(Mode $triggeredBy): bool
    {
        if (!$this->isResponsibleFor($triggeredBy)) {
            return false;
        }

        // Unter einer Laufzeit ohne abkoppelbare Antwort (mod_php) darf Phase B kein
        // Netzwerk anfassen — auch nicht für einen Heartbeat. Er ist zwar klein, aber ein
        // TLS-Handschlag zu Redis kostet 1–5 ms, und bei einer chunked übertragenen Antwort
        // wäre das echte Antwortzeit.
        //
        // FOLGE FÜR DEN BETRIEB, die in die README gehört: unter mod_php ist
        // `ids:sensor:heartbeat` per cron der EINZIGE Weg, wie ein Lebenszeichen entsteht —
        // genau wie beim Spool-Drain. Fehlt der cron, meldet der Collector dauerhaft
        // ids.sensor_silent, obwohl der Sensor arbeitet.
        if (Mode::Request === $triggeredBy && !$this->runtime->shipsDirectly()) {
            return false;
        }

        if (!$this->scheduler->isDue()) {
            return false;
        }

        return $this->emit($triggeredBy);
    }

    /**
     * Sendet unabhängig von der Drosselung.
     *
     * Für `ids:sensor:heartbeat --force` und `ids:sensor:setup-check`: beim Deployment will man
     * einen Heartbeat SEHEN, nicht auf das nächste Intervall warten.
     */
    public function emit(Mode $triggeredBy): bool
    {
        if (null !== $this->breaker && $this->breaker->isOpen()) {
            $this->counters->increment(Counters::HEARTBEAT_FAILED);

            return false;
        }

        try {
            $payload = $this->payloadFactory->create($triggeredBy);
            $this->shipper->shipHeartbeat($payload);
        } catch (\Throwable $e) {
            // fail-open: ein fehlgeschlagener Heartbeat darf die Anwendung nicht berühren.
            $this->counters->increment(Counters::HEARTBEAT_FAILED);
            $this->logger?->warning('ids_sensor: Heartbeat fehlgeschlagen: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }

        // Erst NACH erfolgreichem Versand stempeln — sonst verschwiege ein Fehlschlag das
        // ganze nächste Intervall, und der Collector sähe eine Lücke, die der Sensor
        // selbst nicht bemerkt hat.
        $this->scheduler->markSent();
        $this->counters->increment(Counters::HEARTBEAT_SENT);
        $this->breaker?->recordSuccess();

        return true;
    }

    /**
     * Ist dieser Auslöser im konfigurierten Modus zuständig?
     *
     * Verhindert, dass eine Installation mit `mode: command` bei jedem Request drosselnd
     * mitmischt — und umgekehrt, dass ein versehentlich eingerichteter cron in einer
     * `mode: request`-Installation die Drosselung der Requests verstellt.
     */
    private function isResponsibleFor(Mode $triggeredBy): bool
    {
        return Mode::Both === $this->mode || $this->mode === $triggeredBy;
    }
}
