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
use ProjektMotor\IdsSensor\Exception\ThrottledException;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use Psr\Log\LoggerInterface;

/**
 * Entscheidet, auf welchem Weg eine Sendung hinausgeht: Collector oder Spool.
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
 * Was übrig bleibt, ist die Pipeline: puffern, normalisieren, redigieren, übergeben.
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
        // Ebenfalls nicht nullable, und aus demselben Grund wie $runtime: der Spool ist
        // die letzte Stelle, an der ein Verlust noch gezählt werden kann. Ein fehlendes
        // Argument hätte bedeutet, dass Events lautlos verschwinden — genau das, was der
        // Docblock von spool() ausschließt.
        private readonly SpoolInterface $spool,
        // Obergrenze je Sendung. 0 hebt sie auf.
        private readonly int $maxFrameBytes = 262144,
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
        // Weg der Spool, nicht der Collector. Der Frame trägt das als `deferred` — nicht als
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

    /**
     * Baut den Frame und legt ihn OHNE Collector-Versuch in den Spool.
     *
     * Für den Shutdown-Pfad nach einem Fatal Error. Dort ist ein Netzwerkzugriff die
     * falsche Wahl: Der Prozess stirbt gerade, der Zustand ist unzuverlässig, und ein
     * Verbindungsversuch mit 20 ms Timeout überschreitet das Shutdown-Budget von
     * `budget.fatal_dispatch_ms` (15 ms) schon für sich genommen.
     *
     * Der Frame trägt `deferred` und nicht `recovered`: Der Weg über den Spool ist hier
     * planmäßig, und die Verzögerung ist auf ein Drain-Intervall begrenzt — genau die
     * Unterscheidung, die Konzept 3.3.1 verlangt.
     *
     * @param list<NormalizedEvent> $events
     *
     * @return int Anzahl der gespoolten Events
     */
    public function dispatchToSpool(SensorIdentity $identity, array $events): int
    {
        $frame = new Frame(
            $identity,
            $events,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            DispatchPath::Deferred,
            0,
            $this->counters->all(),
            $this->counters->processEpoch(),
            $this->counters->pid(),
        );

        // Das Ergebnis wird AUSGEWERTET, nicht verworfen. Hier stand `return
        // $frame->count()` ohne Rücksicht darauf, ob der Spool die Zeile überhaupt
        // angenommen hat — der FatalErrorFlushListener protokollierte daraufhin „n Events
        // wurden gerettet", während derselbe Vorgang sie als dropped_spool_full zählte.
        // Der Zähler stimmte, das Protokoll widersprach ihm.
        return $this->spool($frame->toArray(), $frame->count(), 'Shutdown nach Fatal Error')
            ? $frame->count()
            : 0;
    }

    private function ship(Frame $frame): int
    {
        $payload = $frame->toArray();

        if ($this->tooLarge($payload)) {
            // NICHT spoolen: Der Drainer schickte denselben Frame später an denselben
            // Collector und liefe in denselben Fehler — die Zeile blockierte den Spool, bis
            // er voll ist. Genau das Head-of-Line-Blocking, gegen das es
            // UnshippableFrameException gibt.
            //
            // Also verwerfen und zählen. Konzept 4.: „Jeder verworfene oder verlorene
            // Event wird gezählt", weil ein stiller Ausfall gefährlicher ist als ein
            // sichtbarer.
            $this->counters->increment(Counters::DROPPED_FRAME_TOO_LARGE, $frame->count());
            $this->logger?->error(
                'ids_sensor: Frame mit {count} Events überschreitet {max} Byte und wurde verworfen. '
                .'Ursache ist fast immer ein einzelnes übergroßes raw-Feld — siehe raw.max_bytes.',
                ['count' => $frame->count(), 'max' => $this->maxFrameBytes],
            );

            return 0;
        }

        // Die Verzweigung, auf der die mod_php-Unterstützung beruht: hier findet
        // NACHWEISLICH kein Verbindungsversuch statt. Nicht „mit kurzem Timeout", nicht
        // „nur wenn Budget übrig" — gar keiner. Bei einer chunked übertragenen Antwort
        // wartet der Client noch, und jede Millisekunde hier wäre echte Antwortzeit.
        if (!$this->runtime->shipsDirectly()) {
            $this->spool($payload, $frame->count(), 'Laufzeit ohne abkoppelbare Antwort ('.$this->runtime->sapi().')');

            // 0 in beiden Fällen: Der Rückgabewert zählt DIREKT versendete Events, und
            // gespoolt ist das Gegenteil davon.
            return 0;
        }

        // Ist der Breaker offen, findet KEIN Verbindungsversuch statt. Das ist der
        // Unterschied zwischen fail-open in der Theorie und in der Praxis: ohne diese
        // Abkürzung kostet ein Collector-Ausfall jeden Request ein Timeout und erschöpft
        // den Worker-Pool.
        if (null !== $this->breaker && $this->breaker->isOpen()) {
            $this->spool($payload, $frame->count(), 'Circuit Breaker offen');

            return 0;
        }

        try {
            $this->shipper->ship($payload);
            $this->counters->increment(Counters::SENT, $frame->count());
            $this->breaker?->recordSuccess();

            return $frame->count();
        } catch (UnshippableFrameException $e) {
            // Konzept 3.6 trennt „geht nie" von „später erneut", und die Tabelle dort ist
            // normativ: 400, 403, 413 und 422 werden VERWORFEN, nicht gespoolt, und der
            // Breaker bleibt unberührt.
            //
            // Hier stand nur der \Throwable-Zweig darunter, der beides gleich behandelte.
            // Das kostete dreierlei auf einmal: Der Frame ging in den Spool, wo der
            // Drainer ihn später an denselben Collector schickte und in denselben Fehler
            // lief — genau das Head-of-Line-Blocking, gegen das es diese Ausnahme gibt.
            // Der Breaker zählte einen Fehler und öffnete nach drei abgewiesenen Sendungen
            // gegen einen völlig gesunden Collector. Und gezählt wurde ship_failed, was
            // den Betreiber den Collector prüfen ließ, während die Ursache im Payload lag.
            $this->counters->increment(Counters::DROPPED_REJECTED, $frame->count());
            $this->logger?->error(
                'ids_sensor: Der Collector hat {count} Events dauerhaft abgewiesen und sie wurden '
                .'verworfen: {message}. Ein erneuter Versuch ändert daran nichts — zu prüfen ist '
                .'der Payload, nicht der Collector.',
                ['count' => $frame->count(), 'message' => $e->getMessage(), 'exception' => $e],
            );

            return 0;
        } catch (\Throwable $e) {
            $this->counters->increment(Counters::SHIP_FAILED, $frame->count());

            // Bei 429 trägt die Ausnahme die vom Collector verlangte Wartezeit; der
            // Breaker hält sie ein, statt nach der konfigurierten Offen-Zeit wieder
            // anzuklopfen (Konzept 3.6). In jedem anderen Fall ist sie null und es bleibt
            // beim gewöhnlichen Fehler.
            $this->breaker?->recordFailure(
                $e instanceof ThrottledException ? $e->retryAfterSeconds : null,
            );
            $this->logger?->error('ids_sensor: Versand fehlgeschlagen: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->spool($payload, $frame->count(), $e->getMessage());

            return 0;
        }
    }

    /**
     * Ob die Sendung die Größengrenze überschreitet.
     *
     * Ein Transportschutz, keine Konzeptzusage: Der Collector weist eine zu große Sendung
     * mit `413` ab (Konzept 3.6), und {@see UnshippableFrameException}
     * behandelt das als „geht nie". Der Frame käme also aus sich heraus nie durch. Ohne
     * diese Prüfung landete er trotzdem erst im Spool und blockierte ihn dort bei jedem
     * Lauf erneut.
     *
     * Gemessen wird der kodierte Umfang, nicht geschätzt. Das kostet ein zweites
     * `json_encode` — aber erst NACH dem Absenden der Antwort, und nur wenn eine Grenze
     * gesetzt ist. Schlägt das Kodieren fehl, gilt der Frame als zu groß: Was sich nicht
     * kodieren lässt, lässt sich auch nicht versenden.
     *
     * @param array<string, mixed> $payload
     */
    private function tooLarge(array $payload): bool
    {
        if ($this->maxFrameBytes <= 0) {
            return false;
        }

        $encoded = json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return false === $encoded || \strlen($encoded) > $this->maxFrameBytes;
    }

    /**
     * Letzte Zuflucht: auf die Platte, damit ein zweiter Prozess es später nachsendet.
     *
     * Ist auch das nicht möglich (Spool voll oder nicht beschreibbar), ist der Verlust
     * endgültig — aber gezählt. Konzept 4. verlangt genau das: „Jeder verworfene oder
     * verlorene Event wird gezählt", weil ein stiller Ausfall gefährlicher ist als ein
     * sichtbarer.
     *
     * Gibt `bool` und nicht `int` zurück: Die Zahl war für beide Ausgänge 0 und damit als
     * Auskunft wertlos — {@see dispatchToSpool()} konnte Erfolg und Verlust nicht
     * unterscheiden und meldete beides als gerettet. Die Rückgabe von {@see ship()} bleibt
     * davon unberührt: Dort ist 0 richtig, denn gespoolt heißt „nicht direkt versendet".
     *
     * @param array<string, mixed> $payload
     *
     * @return bool ob der Spool die Sendung angenommen hat
     */
    private function spool(array $payload, int $eventCount, string $reason): bool
    {
        $vorher = $this->discardsByReason();

        if ($this->spool->append($payload)) {
            $this->counters->increment(Counters::SPOOLED_EVENTS, $eventCount);
            $this->logger?->info(
                'ids_sensor: {count} Events in den Spool geschrieben ({reason}).',
                ['count' => $eventCount, 'reason' => $reason],
            );

            return true;
        }

        $this->counters->increment($this->reasonThatMoved($vorher), $eventCount);

        return false;
    }

    /**
     * Die drei Verwerfungsstände des Spools, unter dem Namen ihres Zählers.
     *
     * @return array<string, int>
     */
    private function discardsByReason(): array
    {
        return [
            Counters::DROPPED_SPOOL_FULL => $this->spool->discardedFull(),
            Counters::DROPPED_SPOOL_UNWRITABLE => $this->spool->discardedUnwritable(),
            Counters::DROPPED_SPOOL_UNENCODABLE => $this->spool->discardedUnencodable(),
        ];
    }

    /**
     * Welcher der drei Gründe die abgelehnte Zeile verworfen hat.
     *
     * Hier stand ein pauschales `DROPPED_SPOOL_FULL` für jedes gescheiterte `append()`.
     * Für zwei der drei Gründe war das falsch: Ein nicht beschreibbares Verzeichnis und
     * ein nicht kodierbarer Payload wurden als „Platte voll" gemeldet und schickten den
     * Betreiber zu einer Maßnahme, die nichts bewirkt. Der Spool unterschied sie längst —
     * die Zahlen standen nur im `spool`-Block des Heartbeats unter den Namen
     * `discarded_*`, die Konzept 3.4 ausdrücklich abgeschafft hat.
     *
     * Verglichen wird der Stand VOR und NACH dem Versuch, statt die Zahlen des Spools zu
     * übernehmen. Der Grund ist die Einheit: `ids.event_loss` summiert Events, der Spool
     * zählt Zeilen. Nur so bleibt der Zähler in derselben Einheit wie seine Nachbarn.
     *
     * @param array<string, int> $vorher
     */
    private function reasonThatMoved(array $vorher): string
    {
        foreach ($this->discardsByReason() as $counter => $nachher) {
            if ($nachher > $vorher[$counter]) {
                return $counter;
            }
        }

        // Eine Ablehnung ohne bewegten Grund: möglich bei einer fremden
        // SpoolInterface-Umsetzung. Der Rückfall ist der bisherige Zähler — eine
        // Verwerfung ungezählt zu lassen wäre der schlechtere Ausgang (Konzept 4.).
        return Counters::DROPPED_SPOOL_FULL;
    }
}
