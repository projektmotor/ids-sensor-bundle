<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsEventData\Frame\Frame;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolInterface;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use Psr\Log\LoggerInterface;

/**
 * Entscheidet, auf welchem Weg eine Sendung hinausgeht: Broker oder Spool.
 *
 * WOZU EIN EIGENER DIENST
 *
 * Diese Entscheidung stand über drei Methoden des EventFlushers verteilt, und die
 * dafür nötigen Abhängigkeiten — Shipper, Breaker, Spool, RuntimeProfile — wurden
 * ausschließlich dort benutzt. Der Frame gehört mit hierher: er ist laut eigenem
 * Docblock „eine Eigenschaft der Sendung, nicht einer Beobachtung", und ohne ihn
 * bräuchte der Flusher das RuntimeProfile weiterhin, um `dispatch_path` vor dem
 * Frame-Bau zu setzen.
 *
 * Was übrig bleibt, ist die Pipeline: puffern, normalisieren, sampeln, übergeben.
 *
 * KEIN ShipperInterface
 *
 * Diese Klasse implementiert bewusst NICHT ShipperInterface, obwohl die Signatur
 * beinahe passt. Wäre sie gegen `ids_sensor.shipper` austauschbar, entstünden Rekursion
 * und doppeltes Spooling.
 *
 * @internal
 */
final class FrameDispatcher
{
    public function __construct(
        private readonly ShipperInterface $shipper,
        private readonly Counters $counters,
        // Nicht nullable, und das ist eine Sicherheitsaussage: `shipsDirectly()` ist die
        // einzige Schranke vor dem Netzwerk unter mod_php. Ein fehlendes Argument
        // bedeutete „sende direkt" — also die gefährliche Richtung.
        private readonly RuntimeProfile $runtime,
        private readonly ?SpoolInterface $spool = null,
        private readonly ?CircuitBreaker $breaker = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Baut den Frame um die Events und schickt ihn los.
     *
     * @param list<NormalizedEvent> $events
     *
     * @return int Anzahl direkt versendeter Events (0 bei Spool oder Verlust)
     */
    public function dispatch(SensorIdentity $identity, array $events, DispatchPath $path): int
    {
        // Unter einer Laufzeit ohne abkoppelbare Antwort (mod_php) ist der planmäßige
        // Weg der Spool, nicht der Broker. Der Frame trägt das als `deferred` — nicht als
        // `recovered`, weil die Verzögerung hier begrenzt ist und der Collector die
        // Echtzeit-Regeln weiter anwenden darf.
        if (DispatchPath::Direct === $path) {
            $path = $this->runtime->dispatchPath();
        }

        // Die Zählerstände werden VOR dem Versandversuch genommen. Andernfalls trüge ein
        // Frame sein eigenes `sent` beziehungsweise `ship_failed`.
        $frame = new Frame(
            $identity,
            $events,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $path,
            0,
            $this->counters->all(),
            $this->counters->processEpoch(),
            $this->counters->pid(),
        );

        return $this->ship($frame);
    }

    private function ship(Frame $frame): int
    {
        $payload = $frame->toArray();

        // Die Verzweigung, auf der die mod_php-Unterstützung beruht: hier findet
        // NACHWEISLICH kein Verbindungsversuch statt. Nicht „mit kurzem Timeout", nicht
        // „nur wenn Budget übrig" — gar keiner. Bei einer chunked übertragenen Antwort
        // wartet der Client noch, und jede Millisekunde hier wäre echte Antwortzeit.
        if (!$this->runtime->shipsDirectly()) {
            return $this->spool($payload, $frame->count(), 'Laufzeit ohne abkoppelbare Antwort ('.$this->runtime->sapi().')');
        }

        // Ist der Breaker offen, findet KEIN Verbindungsversuch statt. Das ist der
        // Unterschied zwischen fail-open in der Theorie und in der Praxis: ohne diese
        // Abkürzung kostet ein Broker-Ausfall jeden Request ein Timeout und erschöpft
        // den Worker-Pool.
        if (null !== $this->breaker && $this->breaker->isOpen()) {
            return $this->spool($payload, $frame->count(), 'Circuit Breaker offen');
        }

        try {
            $this->shipper->ship($payload);
            $this->counters->increment(Counters::SENT, $frame->count());
            $this->breaker?->recordSuccess();

            return $frame->count();
        } catch (\Throwable $e) {
            $this->counters->increment(Counters::SHIP_FAILED, $frame->count());
            $this->breaker?->recordFailure();
            $this->logger?->error('ids_sensor: Versand fehlgeschlagen: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->spool($payload, $frame->count(), $e->getMessage());

            return 0;
        }
    }

    /**
     * Letzte Zuflucht: auf die Platte, damit ein zweiter Prozess es später nachsendet.
     *
     * Ist auch das nicht möglich (Spool voll oder nicht beschreibbar), ist der Verlust
     * endgültig — aber gezählt. Konzept 4. verlangt genau das: „Jeder verworfene oder
     * verlorene Event wird gezählt", weil ein stiller Ausfall gefährlicher ist als ein
     * sichtbarer.
     *
     * @param array<string, mixed> $payload
     */
    private function spool(array $payload, int $eventCount, string $reason): int
    {
        if (null === $this->spool) {
            return 0;
        }

        if ($this->spool->append($payload)) {
            $this->counters->increment(Counters::SPOOLED, $eventCount);
            $this->logger?->info(
                'ids_sensor: {count} Events in den Spool geschrieben ({reason}).',
                ['count' => $eventCount, 'reason' => $reason],
            );

            return 0;
        }

        $this->counters->increment(Counters::DROPPED_SPOOL_FULL, $eventCount);

        return 0;
    }
}
