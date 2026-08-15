<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Processing\Normalization\EventNormalizerInterface;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\DeferredCounters;
use ProjektMotor\IdsSensor\Support\Telemetry\LatencyRecorder;
use Psr\Log\LoggerInterface;

/**
 * Phase B: leert den Puffer, normalisiert, sampelt und übergibt dem
 * {@see FrameDispatcher}, der über Broker oder Spool entscheidet.
 *
 * Läuft nach dem Absenden der Antwort. Das ist der Kern der Zweiteilung aus Konzept
 * 2.1: im Request wird nur gesammelt, hier wird gearbeitet. Ein Netzwerk-Roundtrip
 * zum Broker kostet allein schon mehr als das gesamte Erfassungsbudget von 5 ms —
 * er darf also nicht stattfinden, während der Client wartet.
 *
 * Wirft unter keinen Umständen nach außen (Konzept 4. IdsBackendBundle —
 * Grundsatzentscheidung: fail-open). Jeder Fehlerpfad zählt stattdessen einen
 * Zähler hoch, damit der Verlust collectorseitig sichtbar wird und nicht lautlos
 * bleibt.
 *
 * @internal
 */
final class EventFlusher
{
    /**
     * @param iterable<EventNormalizerInterface> $normalizers
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly SensorIdentityProvider $identityProvider,
        private readonly iterable $normalizers,
        private readonly FrameDispatcher $frameDispatcher,
        private readonly Counters $counters,
        private readonly DeferredCounters $deferredCounters,
        private readonly LatencyRecorder $latencyRecorder,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CoherentInfoSampler $sampler = null,
    ) {
    }

    /**
     * Leert den Puffer und versendet.
     *
     * @return int Anzahl der versendeten Events (0 wenn nichts zu tun war oder alles fehlschlug)
     */
    public function flush(DispatchPath $path = DispatchPath::Direct): int
    {
        $started = hrtime(true);

        try {
            return $this->doFlush($path);
        } catch (\Throwable $e) {
            // Letzte Auffanglinie. Kommt es hierher, ist etwas außerhalb der
            // einzeln abgesicherten Schritte schiefgegangen — die Anwendung darf
            // das trotzdem nicht merken.
            $this->logger?->error('ids_sensor: Flush fehlgeschlagen: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return 0;
        } finally {
            $this->latencyRecorder->recordDispatch(hrtime(true) - $started);
        }
    }

    private function doFlush(DispatchPath $path): int
    {
        // drain() statt all(): ein zweiter Durchlauf — etwa aus der
        // Shutdown-Funktion nach einem regulären terminate — darf dieselben Events
        // nicht erneut versenden.
        $captured = $this->buffer->drain();

        $this->deferredCounters->collect();

        if ([] === $captured) {
            return 0;
        }

        $identity = $this->identityProvider->get();
        $normalized = $this->normalizeAll($captured, $identity);

        if ([] === $normalized) {
            return 0;
        }

        // Sampling NACH dem Normalisieren: erst dort steht die endgültige severity fest,
        // und nur mit ihr ist entscheidbar, ob dieser Request relevant ist. Vor dem
        // Normalisieren wäre die Kohärenzregel nicht anwendbar.
        //
        // Bei der Vorgabe info_rate = 1.0 entfällt der Schritt vollständig — isActive()
        // ist dann false und es wird nichts kopiert.
        if (null !== $this->sampler && $this->sampler->isActive()) {
            $sampled = $this->sampler->sample($normalized);
            $dropped = $this->sampler->droppedCount($normalized, $sampled);

            if ($dropped > 0) {
                $this->counters->increment(Counters::DROPPED_SAMPLING, $dropped);
            }

            $normalized = $sampled;

            if ([] === $normalized) {
                return 0;
            }
        }

        return $this->frameDispatcher->dispatch($identity, $normalized, $path);
    }

    /**
     * @param list<CapturedEvent> $captured
     *
     * @return list<NormalizedEvent>
     */
    private function normalizeAll(array $captured, SensorIdentity $identity): array
    {
        $normalized = [];

        foreach ($captured as $event) {
            $normalizer = $this->normalizerFor($event);

            if (null === $normalizer) {
                // Kann auftreten, wenn eine Ebene abgeschaltet ist, ihre Sensoren
                // aber noch Events liefern. Zählen statt schweigen.
                $this->counters->increment(Counters::DROPPED_NO_NORMALIZER);
                continue;
            }

            try {
                $normalized[] = $normalizer->normalize($event, $identity);
            } catch (\Throwable $e) {
                // Ein einzelnes unnormalisierbares Event darf nicht die übrigen
                // Events desselben Requests mitnehmen.
                $this->counters->increment(Counters::DROPPED_NORMALIZE_ERROR);
                $this->logger?->error(
                    'ids_sensor: Normalisierung von "{event_type}" fehlgeschlagen: {message}',
                    ['event_type' => $event->eventType, 'message' => $e->getMessage(), 'exception' => $e],
                );
            }
        }

        $this->counters->increment(Counters::CAPTURED, \count($normalized));

        return $normalized;
    }

    private function normalizerFor(CapturedEvent $event): ?EventNormalizerInterface
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->supports($event)) {
                return $normalizer;
            }
        }

        return null;
    }
}
