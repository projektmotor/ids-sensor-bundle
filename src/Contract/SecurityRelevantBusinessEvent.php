<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Contract;

/**
 * Der Vertrag für die Business-Ebene, wörtlich übernommen aus Konzept 2.1.3
 * Business-/Domain-Events.
 *
 * Dies ist das einzige Artefakt, das überwachte Anwendungen selbst implementieren.
 * Die Signatur ist deshalb ÖFFENTLICHE API und bewusst unverändert gegenüber dem
 * Konzept — insbesondere bleibt getSeverityHint() ein string und wird nicht auf
 * das Enum Severity verengt:
 *
 *  - Eine Verengung auf das Enum wäre für jeden bestehenden Implementierer ein
 *    harter Bruch, eine Aufweitung ist unmöglich. string hält die Option offen.
 *  - Anwendungen können das Interface an vorhandene DTOs hängen, ohne unser Enum
 *    in ihre Domänenschicht zu importieren.
 *
 * Für Komfort steht {@see Severity} bereit: `return Severity::Warning->value;`
 *
 * Ungültige Hints werden NICHT mit einer Exception bestraft — das verstieße gegen
 * fail-open (Konzept 4. IdsBackendBundle). Der Normalisierer stuft stattdessen auf
 * "warning" ein und hinterlegt den Originalwert im raw-Feld.
 *
 * Wie die Events den Sensor erreichen, hängt vom konfigurierten capture_mode ab
 * (dispatcher | recorder | configured) — siehe README. Der im Konzept genannte
 * Weg „generisch alle Events, die dieses Interface implementieren, abonnieren"
 * ist mit Symfonys EventDispatcher nicht umsetzbar: dieser löst Listener über den
 * exakten Event-Namen auf, nie über Interfaces oder Elternklassen.
 */
interface SecurityRelevantBusinessEvent
{
    /**
     * Der Event-Name, der als event_type übertragen wird.
     *
     * Konvention: punktgetrennte snake_case-Segmente, z. B.
     * "order.payment_amount_overridden". Abweichende Namen werden nicht
     * abgelehnt, sondern bereinigt übertragen.
     */
    public function getEventName(): string;

    /**
     * Selbsteinschätzung der Kritikalität: "info", "warning" oder "critical".
     *
     * Wird laut Konzept 2.2.1 direkt als event_severity übernommen — die
     * Business-Ebene bewertet sich selbst, es gibt keine eigene Ableitung.
     */
    public function getSeverityHint(): string;

    /**
     * Die handelnde Benutzerkennung, oder null wenn nicht bestimmbar
     * (z. B. Systemvorgänge, CLI, Worker).
     */
    public function getActorId(): ?string;

    /**
     * Die projektspezifischen Kernfelder des Vorgangs.
     *
     * Empfehlung aus Konzept 3.1.3: flache Struktur, nur primitive Typen,
     * snake_case-Feldnamen. Der Sensor reicht den Inhalt unverändert durch,
     * redigiert aber sensible Schlüsselnamen und begrenzt Tiefe und Größe.
     *
     * Der Schlüsselpräfix "_ids_" ist für das Bundle reserviert und wird aus
     * eingehenden Payloads entfernt.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;
}
