<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Event;

use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;

/**
 * Ein fertig normalisiertes Event im verbindlichen Format aus Konzept Abschnitt 3.
 *
 * Unveränderlich. Entsteht im Sensor-Bundle erst in Phase B (nach dem Absenden der
 * Antwort) aus einem erfassten Event; im Request selbst wird nichts normalisiert —
 * das wäre Arbeit, für die der Client wartet. Wer das Event erzeugt, ist von hier aus
 * bewusst nicht benannt: dieses Verzeichnis soll als eigenes Paket auslösbar bleiben,
 * und ein Verweis auf `Sensor\CapturedEvent` zeigte danach ins Leere.
 *
 * Das raw-Feld wird als Closure gehalten und erst in {@see toArray()} ausgewertet,
 * und zwar nur bei event_severity in (warning, critical) — so zahlt der info-Pfad,
 * also die Masse aller Events, nichts für Header-Kopien und Redaktion.
 *
 * Öffentliche API: das ist das Event aus Konzept Abschnitt 3, so wie der Collector es
 * empfängt.
 */
final class NormalizedEvent
{
    /**
     * @param array<string, mixed>                    $payload      ebenenspezifische Nutzlast, Struktur nach Konzept 3.1
     * @param (\Closure(): array<string, mixed>)|null $rawBuilder   wird nur bei warning/critical aufgerufen
     * @param float|null                              $samplingRate null bedeutet „nicht gesampelt" und wird weggelassen
     */
    public function __construct(
        public readonly string $eventId,
        public readonly \DateTimeImmutable $timestamp,
        public readonly Layer $layer,
        public readonly string $eventType,
        public readonly string $correlationId,
        public readonly Severity $severity,
        public readonly SensorIdentity $identity,
        public readonly Actor $actor,
        public readonly array $payload = [],
        private readonly ?\Closure $rawBuilder = null,
        public readonly ?float $samplingRate = null,
    ) {
    }

    /**
     * Setzt die Sampling-Rate, unter der dieses Event überlebt hat.
     *
     * Ergänzung zum Konzept: Abschnitt 4.2.3 verlangt, dass die Rate im Event
     * mitreist, damit Aggregate hochgerechnet werden können — die Pflichtfeldliste
     * in Abschnitt 3 kennt sie aber nicht. Deshalb optionales Feld, Default 1.0.
     */
    public function withSamplingRate(?float $rate): self
    {
        return new self(
            $this->eventId,
            $this->timestamp,
            $this->layer,
            $this->eventType,
            $this->correlationId,
            $this->severity,
            $this->identity,
            $this->actor,
            $this->payload,
            $this->rawBuilder,
            $rate,
        );
    }

    /**
     * Serialisiert in die Struktur aus Konzept Abschnitt 3.
     *
     * Die Reihenfolge entspricht dem Beispiel im Konzept. Sie ist fachlich
     * irrelevant, erleichtert aber den Vergleich mit den Golden Files.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            EventSchema::FIELD_SCHEMA_VERSION => EventSchema::SCHEMA_VERSION,
            EventSchema::FIELD_EVENT_ID => $this->eventId,
            EventSchema::FIELD_TIMESTAMP => $this->timestamp->format(EventSchema::TIMESTAMP_FORMAT),
            EventSchema::FIELD_LAYER => $this->layer->value,
            EventSchema::FIELD_EVENT_TYPE => $this->eventType,
            EventSchema::FIELD_CORRELATION_ID => $this->correlationId,
            EventSchema::FIELD_EVENT_SEVERITY => $this->severity->value,
            EventSchema::FIELD_APPLICATION_ID => $this->identity->applicationId,
            EventSchema::FIELD_INSTANCE_ID => $this->identity->instanceId,
            EventSchema::FIELD_ENVIRONMENT => $this->identity->environment->value,
            EventSchema::FIELD_ACTOR => $this->actor->toArray(),
            EventSchema::FIELD_PAYLOAD => $this->payload,
        ];

        // Nur mitschicken, wenn tatsächlich gesampelt wurde. 1.0 ist der
        // dokumentierte Default und würde jedes Event unnötig verbreitern.
        if (null !== $this->samplingRate && 1.0 !== $this->samplingRate) {
            $data[EventSchema::FIELD_SAMPLING_RATE] = $this->samplingRate;
        }

        // Konzept Abschnitt 3: raw ist kein Pflichtfeld und wird nur für
        // warning/critical übertragen.
        if (null !== $this->rawBuilder && $this->severity->carriesRaw()) {
            $raw = ($this->rawBuilder)();
            if ([] !== $raw) {
                $data[EventSchema::FIELD_RAW] = $raw;
            }
        }

        return $data;
    }
}
