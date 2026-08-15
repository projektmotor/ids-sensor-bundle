<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Message;

use ProjektMotor\IdsEventData\Frame\Frame;

/**
 * Die Messenger-Nachricht: ein Frame auf dem Weg zum Broker.
 *
 * Trägt bewusst das bereits serialisierte Array und nicht das Frame-Objekt. Damit
 * hängt am Transport kein einziger PHP-Typ des Sensors — der Collector muss keine
 * Klassen dieses Pakets kennen, um die Nachricht zu lesen. Die Paketgrenze bleibt
 * das JSON-Format aus Konzept Abschnitt 3.
 *
 * @internal
 */
final class EventBatch
{
    /**
     * @param array<string, mixed> $frame Ergebnis von {@see Frame::toArray()}
     */
    public function __construct(
        public readonly array $frame,
    ) {
    }

    public static function fromFrame(Frame $frame): self
    {
        return new self($frame->toArray());
    }

    public function eventCount(): int
    {
        $events = $this->frame['events'] ?? [];

        return \is_array($events) ? \count($events) : 0;
    }
}
