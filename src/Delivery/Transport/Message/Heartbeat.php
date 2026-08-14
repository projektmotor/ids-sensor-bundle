<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Message;

/**
 * Das Lebenszeichen des Sensors — ein EIGENER Nachrichtentyp, kein Event.
 *
 * ERGÄNZUNG ZUM KONZEPT, MIT BEGRÜNDUNG
 *
 * Konzept 2. verlangt einen Heartbeat mit `application_id` und `instance_id`, legt aber
 * kein Format fest. Ihn als Event nach Abschnitt 3 zu übertragen ist NICHT möglich, und
 * das ist keine Auslegungsfrage:
 *
 *  - `layer` ist laut 2.2.1 ein Enum aus `kernel|security|business`. Ein Heartbeat gehört
 *    zu keiner dieser Ebenen — er ist eine Aussage ÜBER den Sensor, nicht über die
 *    Anwendung.
 *  - `layer`, `event_severity` und `correlation_id` sind laut 4.2.1 Tabellenschema
 *    NOT NULL. Ein Heartbeat hat keines davon: er beobachtet nichts, hat keinen Schweregrad
 *    und keinen Request, zu dem er gehört.
 *
 * Würde man Ersatzwerte erfinden, um das Schema zu erfüllen, stünden in der
 * Ereignistabelle Zeilen, die keine Ereignisse sind — und jede Aggregation nach `layer`
 * oder `event_severity` wäre um sie verfälscht. Deshalb eine eigene Nachricht mit eigenem
 * `type`-Header, die der Collector getrennt behandelt.
 *
 * Trägt wie {@see EventBatch} nur ein Array, keine Objekte: der Collector muss keine
 * Klasse dieses Pakets kennen.
 *
 * @internal
 */
final class Heartbeat
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {
    }
}
