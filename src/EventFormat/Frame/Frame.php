<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Frame;

use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;

/**
 * Der Transport-Umschlag: alle normalisierten Events eines Requests plus
 * Sensor-Kennung und Zählerstände.
 *
 * Ein Request erzeugt typischerweise 3–5 Events, bei vielen isGranted()-Aufrufen
 * deutlich mehr. Einzeln verschickt wären das N Netzwerk-Roundtrips; der Frame
 * bündelt sie zu einem einzigen XADD.
 *
 * Wichtig für das Verständnis: Der Frame ist KEIN Event und ändert das Event-Schema
 * aus Konzept Abschnitt 3 nicht — er umhüllt es. Deshalb liegen dispatch_path,
 * spool_delay_ms und die Zählerstände hier und nicht im Event: sie sind
 * Eigenschaften der Sendung, nicht einer einzelnen Beobachtung. Ein einzelnes Event
 * weiß nicht, ob es verzögert verschickt wurde; die Sendung weiß es.
 *
 * Derselbe Frame ist auch das Format im Spool — eine Zeile pro Frame. Beim Drain
 * wird er unverändert weitergeschickt, also nicht erneut normalisiert oder
 * redigiert: ein zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es
 * falsch zu machen.
 *
 * Öffentliche API: Der Frame ist das Format auf der Leitung UND im Spool. Der Consumer
 * liest genau diese Struktur.
 */
final class Frame
{
    /**
     * Version des Frame-Formats — unabhängig von der schema_version der Events
     * darin. Der Umschlag kann sich weiterentwickeln, ohne den Event-Vertrag zu
     * berühren.
     */
    public const FRAME_VERSION = 1;

    /**
     * @param list<NormalizedEvent> $events
     * @param array<string, int>    $counters
     */
    public function __construct(
        public readonly SensorIdentity $identity,
        public readonly array $events,
        public readonly \DateTimeImmutable $flushedAt,
        public readonly DispatchPath $dispatchPath = DispatchPath::Direct,
        public readonly int $spoolDelayMs = 0,
        public readonly array $counters = [],
        public readonly string $processEpoch = '',
        public readonly int $pid = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->events;
    }

    public function count(): int
    {
        return \count($this->events);
    }

    /**
     * Markiert den Frame als aus dem Spool nachgesendet.
     *
     * Wird beim Drain aufgerufen. Die Events selbst bleiben unverändert — nur der
     * Umschlag lernt, auf welchem Weg er gereist ist und wie lange.
     */
    public function asDeferred(DispatchPath $path, int $spoolDelayMs): self
    {
        return new self(
            $this->identity,
            $this->events,
            $this->flushedAt,
            $path,
            $spoolDelayMs,
            $this->counters,
            $this->processEpoch,
            $this->pid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::FRAME_VERSION,
            'sensor' => [
                EventSchema::FIELD_APPLICATION_ID => $this->identity->applicationId,
                EventSchema::FIELD_INSTANCE_ID => $this->identity->instanceId,
                EventSchema::FIELD_ENVIRONMENT => $this->identity->environment->value,
                'process_epoch' => $this->processEpoch,
                'pid' => $this->pid,
            ],
            'flushed_at' => $this->flushedAt->format(EventSchema::TIMESTAMP_FORMAT),
            'dispatch_path' => $this->dispatchPath->value,
            'spool_delay_ms' => $this->spoolDelayMs,
            'counters' => $this->counters,
            'events' => array_map(
                static fn (NormalizedEvent $event): array => $event->toArray(),
                $this->events,
            ),
        ];
    }
}
