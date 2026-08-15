<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Shipper;

use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Übergibt den Frame an den Messenger-TRANSPORT — einen Frame, einen Versand.
 *
 * Ein Aufruf pro Request, nicht pro Event. Messengers Redis-Transport bündelt nicht selbst;
 * ohne den Frame wären es N Netzwerk-Roundtrips pro Request.
 *
 * WARUM DIREKT AN DEN TRANSPORT UND NICHT ÜBER EINEN BUS
 *
 * Ursprünglich lief der Versand über einen eigenen Message-Bus, den das Bundle über
 * `framework.messenger.buses` registrierte. Das hatte eine Nebenwirkung auf die überwachte
 * Anwendung, die alle Tests überlebt hat und erst durch einen Container-Abdruck auffiel:
 *
 * Sobald das Bundle einen Wert für `buses` beisteuert, greift Symfonys Vorgabe
 * `messenger.bus.default` nicht mehr. In einer Anwendung, die Messenger benutzt, ohne ihre
 * Buses ausdrücklich zu benennen — der Normalfall —, blieb damit genau ein Bus übrig: der
 * des Sensors, mit `default_middleware: false` und nur `send_message`. Er wurde zum
 * Standard-Bus der Anwendung, und jedes `$bus->dispatch()` der Anwendung lief ins Leere.
 * Ohne Fehler, ohne Warnung. Nannte die Anwendung einen eigenen Bus, brach die
 * Kompilierung stattdessen mit „You must specify the default_bus" ab.
 *
 * Für einen reinen SENDEPFAD trägt ein Bus ohnehin nichts bei: er hätte Middleware, Routing
 * und Handler-Auflösung zu bieten, und keines davon wird hier gebraucht. Der Transport allein
 * genügt — er kennt die DSN, die Verbindung und den Serializer. Damit entfällt jede Berührung
 * mit der Messenger-Konfiguration der Anwendung, nicht nur dieser eine Fall.
 *
 * Fängt bewusst KEINE Fehler. Der {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher}
 * fängt jedes Throwable und entscheidet über Spool und Circuit Breaker. Würde dieser Shipper
 * Fehler selbst verschlucken, nähme er ihm genau diese Entscheidung — und der Verlust bliebe
 * unsichtbar, statt gezählt zu werden.
 *
 * @internal
 */
final class MessengerShipper implements ShipperInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @throws UnshippableFrameException wenn der Frame keine Events trägt
     */
    public function ship(array $frame): void
    {
        $events = $frame['events'] ?? null;

        if (!\is_array($events)) {
            // Hier stand ein stilles `return`. Das war die schlechteste der drei
            // möglichen Antworten: Im Direktpfad zählte FrameDispatcher den Frame
            // anschließend als `sent`, obwohl nichts gesendet wurde, und im Drain-Pfad
            // wertete SpoolDrainer den Rücksprung als Erfolg und LÖSCHTE die Zeile.
            // Beides verletzt Konzept 4. („Jeder verworfene oder verlorene Event wird
            // gezählt") an genau der Stelle, an der man es am wenigsten bemerkt.
            //
            // Ein Wurf ist richtig und billig: Der Drainer unterscheidet
            // UnshippableFrameException schon immer vom Broker-Ausfall und verwirft die
            // Zeile, statt sie ewig zu wiederholen; der FrameDispatcher fängt sie und
            // zählt `ship_failed`. Ein Frame ohne `events` ist genau das, was die
            // Exception meint: ein zweiter Versuch heilt ihn nicht.
            throw new UnshippableFrameException('Der Frame trägt kein "events"-Feld — vermutlich beim Schreiben in den Spool abgeschnitten.');
        }

        if ([] === $events) {
            // Ein leerer Frame ist kein Fehler: Der Flusher erzeugt ihn nicht, aber ein
            // vollständig weggesampelter Durchlauf könnte es. Nichts zu senden ist dann
            // die richtige Antwort — und kein Verlust, denn es gibt nichts zu verlieren.
            return;
        }

        $this->transport->send(new Envelope(new EventBatch($frame)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shipHeartbeat(array $payload): void
    {
        $this->transport->send(new Envelope(new Heartbeat($payload)));
    }
}
