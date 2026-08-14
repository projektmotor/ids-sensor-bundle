<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport;

use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serialisiert Frames als JSON — und zwar ausschließlich.
 *
 * DAS IST EINE SICHERHEITSENTSCHEIDUNG, keine Geschmacksfrage.
 *
 * Messengers Vorgabe ist der PhpSerializer, der `serialize($envelope)` auf den
 * Stream legt. Zusammen mit der Vertrauensgrenze aus Konzept 2. („Warum das
 * IdsSensorBundle keinen Datenbankzugriff erhalten darf") ergäbe das einen
 * Deserialisierungs-Pfad aus der überwachten Anwendung in den Beweisspeicher:
 *
 *  - Der Sensor braucht zwingend Schreibrecht auf den Stream (XADD) — das ist laut
 *    Konzept unvermeidbar und ausdrücklich als Restrisiko benannt.
 *  - Ein Angreifer mit Codeausführung in der Anwendung — also genau das Szenario aus
 *    S4 und S5 — könnte damit einen präparierten PHP-serialisierten Payload
 *    einstellen.
 *  - Der Collector würde ihn unserialisieren. Eine Gadget Chain dort wäre
 *    Codeausführung in genau der Komponente, die die Kompromittierung der Anwendung
 *    überleben soll.
 *
 * JSON hat keine solche Klasse von Angriffen. Zusätzlich entkoppelt es den Collector
 * vollständig von den PHP-Klassen des Sensors.
 *
 * @internal
 */
final class MessageSerializer implements SerializerInterface
{
    public const TYPE_EVENT_BATCH = 'ids.event_batch';

    /**
     * Der Heartbeat ist ein EIGENER Typ, kein Event.
     *
     * Der `type`-Header ist die Stelle, an der der Collector beides trennt, ohne den Body
     * zu parsen — er kann also entscheiden, bevor er liest. Begründung für die Trennung
     * siehe {@see Heartbeat}.
     */
    public const TYPE_HEARTBEAT = 'ids.heartbeat';

    public const HEADER_TYPE = 'type';

    public const HEADER_SCHEMA_VERSION = 'schema_version';

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE ist NICHT optional.
     *
     * path, user_agent und die Query-Parameter sind angreiferkontrolliert und
     * enthalten regelmäßig ungültiges UTF-8 — das ist genau, was ein Scanner sendet.
     * Ohne dieses Flag liefert json_encode() `false`, und der komplette Frame wäre
     * verloren. Ein Angreifer könnte damit gezielt seine eigenen Events unterdrücken:
     * ein auslösbarer blinder Fleck.
     *
     * JSON_PARTIAL_OUTPUT_ON_ERROR ist der Gürtel zum Hosenträger — lieber ein
     * unvollständiges Event als keines.
     */
    private const ENCODE_FLAGS = \JSON_INVALID_UTF8_SUBSTITUTE
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * @return array{body: string, headers: array<string, string|int>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if ($message instanceof Heartbeat) {
            return $this->encodePayload($message->payload, self::TYPE_HEARTBEAT);
        }

        if (!$message instanceof EventBatch) {
            throw new \LogicException(\sprintf('Der IDS-Transport befördert ausschließlich %s und %s, nicht %s.', EventBatch::class, Heartbeat::class, get_debug_type($message)));
        }

        return $this->encodePayload($message->frame, self::TYPE_EVENT_BATCH);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{body: string, headers: array<string, string|int>}
     */
    private function encodePayload(array $data, string $type): array
    {
        $body = json_encode($data, self::ENCODE_FLAGS);

        if (false === $body) {
            // Sollte durch JSON_PARTIAL_OUTPUT_ON_ERROR oben nicht mehr auftreten. Falls
            // doch, ist der Frame dauerhaft unversendbar — ein zweiter Versuch aus dem
            // Spool würde an derselben Stelle scheitern. Der eigene Typ sagt genau das
            // und bewahrt den Drainer davor, die Datei daran festzuhalten.
            throw new UnshippableFrameException(\sprintf('Nachricht "%s" konnte nicht als JSON kodiert werden: %s', $type, json_last_error_msg()));
        }

        return [
            'body' => $body,
            'headers' => [
                self::HEADER_TYPE => $type,
                self::HEADER_SCHEMA_VERSION => EventSchema::SCHEMA_VERSION,
            ],
        ];
    }

    /**
     * Der Sensor liest nie vom Broker.
     *
     * Das ist keine fehlende Umsetzung, sondern die Manipulationsgrenze aus Konzept
     * 2.: die Anwendung hat `write` auf den Exchange beziehungsweise nur XADD auf den
     * Stream — kein read, kein configure. Damit kann ein Angreifer in der Anwendung
     * weder abgesendete Events löschen noch die noch nicht konsumierten Events
     * anderer Requests mitlesen. Ein funktionierendes decode() hier wäre ein
     * Widerspruch zu dieser Zusage.
     *
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        throw new \LogicException('Der Sensor liest grundsätzlich nicht vom Broker. Die Manipulationsgrenze verläuft dort (Konzept 2.): die überwachte Anwendung darf ausschließlich schreiben. Das Lesen ist Aufgabe des IdsBackendBundle.');
    }
}
