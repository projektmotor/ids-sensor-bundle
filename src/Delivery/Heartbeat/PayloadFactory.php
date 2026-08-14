<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Heartbeat;

use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolInterface;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\LatencyRecorder;

/**
 * Baut den Heartbeat-Payload.
 *
 * Der Heartbeat ist mehr als ein „ich lebe". Er ist der einzige Kanal, über den
 * Betriebszustände des Sensors OHNE Verkehr nach draußen kommen — und damit die Antwort auf
 * eine Reihe von Konzeptanforderungen, die sonst unerfüllbar bleiben:
 *
 *  - `ids.event_loss` (Konzept 4., Restrisiko) braucht die Verlustzähler. Reisen sie nur im
 *    Frame mit, sind sie genau dann unsichtbar, wenn kein Verkehr da ist — also im Fall
 *    „Sensor läuft, aber nichts kommt an".
 *  - Die 5-ms-Zusage aus 2.1 ist nur überprüfbar, wenn die gemessene Latenz im BETRIEB
 *    berichtet wird und nicht nur im Benchmark.
 *  - Der Spool-Füllstand entscheidet unter mod_php über Datenverlust: schreibt der Sensor
 *    und holt niemand ab, läuft der Spool voll und verwirft. Von außen ist das
 *    ausschließlich hier sichtbar.
 *
 * ZÄHLER SIND MONOTON, NICHT DELTAS
 *
 * Konzept 4. sichert at-least-once-Zustellung zu. Bei einer erneuten Zustellung würden
 * Deltas doppelt gezählt. Deshalb übertragen wir Absolutwerte, zusammen mit
 * `process_epoch` und `pid`: der Collector kann daraus selbst Zuwächse bilden und erkennt
 * am Wechsel der Epoche, dass ein neuer Prozess bei null angefangen hat — ohne ihn wäre ein
 * Neustart von einem Zählerrücksprung nicht zu unterscheiden.
 *
 * @internal
 */
final class PayloadFactory
{
    public const TYPE = 'ids.heartbeat';

    public function __construct(
        private readonly SensorIdentityProvider $identityProvider,
        private readonly Counters $counters,
        private readonly LatencyRecorder $latencyRecorder,
        private readonly RuntimeProfile $runtime,
        private readonly Scheduler $scheduler,
        private readonly Mode $mode,
        private readonly int $intervalSeconds,
        private readonly int $cleanupVersion,
        private readonly ?SpoolInterface $spool = null,
        private readonly ?CircuitBreaker $breaker = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(Mode $triggeredBy): array
    {
        $identity = $this->identityProvider->get();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $payload = [
            'type' => self::TYPE,
            'schema_version' => EventSchema::SCHEMA_VERSION,
            'sent_at' => $now->format(EventSchema::TIMESTAMP_FORMAT),

            // Die drei Felder, an denen der Collector die Instanz erkennt (Konzept 2.).
            'application_id' => $identity->applicationId,
            'instance_id' => $identity->instanceId,
            'environment' => $identity->environment->value,

            'process_epoch' => $this->counters->processEpoch(),
            'pid' => $this->counters->pid(),

            // Der konfigurierte Modus UND der Weg, über den dieser Heartbeat kam. Beide,
            // weil sie auseinanderfallen können: bei mode=both und totem cron kommen nur
            // noch request-getriebene Heartbeats, und genau daran ist der tote cron
            // erkennbar, bevor der Spool volläuft.
            'heartbeat_mode' => $this->mode->value,
            'triggered_by' => $triggeredBy->value,
            'interval_s' => $this->intervalSeconds,
            'seconds_since_last' => $this->scheduler->secondsSinceLastSend(),

            // Damit collectorseitig bekannt ist, welche Verzögerung für diese Instanz
            // NORMAL ist — nur so fällt eine auf, die es nicht ist.
            'runtime' => $this->runtime->describe(),

            'counters' => $this->counters->all(),
            'latency' => $this->latencyRecorder->snapshot(),

            // Nach welcher Fassung der Denylist diese Instanz redigiert (Konzept 4.5.1).
            'cleanup_version' => $this->cleanupVersion,
        ];

        if (null !== $this->spool) {
            $payload['spool'] = $this->spoolState();
        }

        if (null !== $this->breaker) {
            $payload['circuit_breaker'] = $this->breaker->snapshot();
        }

        return $payload;
    }

    /**
     * Der Spool-Zustand — für mod_php-Installationen die wichtigste Einzelangabe.
     *
     * `oldest_pending_age_s` ist der Wert, an dem ein nicht laufender Drain-Prozess
     * auffällt: er wächst dann unbegrenzt, während er im Normalbetrieb unter dem
     * Drain-Intervall bleibt. Ohne diese Zahl bemerkt niemand den fehlenden cron, bis der
     * Spool voll ist und verwirft — also bis der Datenverlust bereits eingetreten ist.
     *
     * @return array<string, mixed>
     */
    private function spoolState(): array
    {
        $spool = $this->spool;

        if (null === $spool) {
            return [];
        }

        $state = [
            'bytes' => $spool->sizeInBytes(),
            'spooled_frames' => $spool->spooledFrames(),
            'discarded_full' => $spool->discardedFull(),
            'discarded_unwritable' => $spool->discardedUnwritable(),
        ];

        if (!method_exists($spool, 'pendingFiles')) {
            return $state;
        }

        /** @var list<string> $files */
        $files = $spool->pendingFiles();
        $state['pending_files'] = \count($files);

        $oldest = null;

        foreach ($files as $file) {
            $modified = @filemtime($file);

            if (false === $modified) {
                continue;
            }

            $oldest = null === $oldest ? $modified : min($oldest, $modified);
        }

        $state['oldest_pending_age_s'] = null === $oldest ? null : max(0, time() - $oldest);

        return $state;
    }
}
