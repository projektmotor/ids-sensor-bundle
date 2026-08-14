<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Shipper;

/**
 * Übergibt einen Frame an den Transport.
 *
 * Nimmt das Frame-ARRAY und nicht das Frame-Objekt. Der Grund ist Symmetrie mit dem
 * Spool: dort liegt genau dieses Array als eine JSON-Zeile, und beim Nachsenden wird
 * es unverändert weitergeschickt — nicht erneut normalisiert oder redigiert. Ein
 * zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es falsch zu machen.
 *
 * Implementierungen DÜRFEN werfen — der EventFlusher fängt jedes Throwable und
 * entscheidet über Spool und Circuit Breaker. Ein Shipper, der Fehler selbst
 * verschluckt, nähme dem Flusher genau diese Entscheidung und machte den Verlust
 * unsichtbar.
 *
 * @internal
 */
interface ShipperInterface
{
    /**
     * @param array<string, mixed> $frame Ergebnis von {@see \ProjektMotor\IdsSensor\EventFormat\Frame\Frame::toArray()}
     *
     * @throws \Throwable wenn der Frame nicht übergeben werden konnte
     */
    public function ship(array $frame): void;

    /**
     * Übergibt einen Heartbeat.
     *
     * Eigene Methode und nicht derselbe Weg wie ein Frame, weil es ein eigener
     * Nachrichtentyp ist (siehe {@see \ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat}):
     * `layer`, `event_severity` und `correlation_id` sind laut Konzept 4.2.1 NOT NULL, und
     * ein Heartbeat hat keines davon. Ihn als Frame zu verpacken würde Ersatzwerte
     * erfordern und jede Aggregation nach diesen Feldern verfälschen.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Throwable wenn der Heartbeat nicht übergeben werden konnte
     */
    public function shipHeartbeat(array $payload): void;
}
