<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Shipper;

use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
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
 * Fängt bewusst KEINE Fehler. Der {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\EventFlusher} fängt
 * jedes Throwable und entscheidet über Spool und Circuit Breaker. Würde dieser Shipper
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
     */
    public function ship(array $frame): void
    {
        if ([] === ($frame['events'] ?? [])) {
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
