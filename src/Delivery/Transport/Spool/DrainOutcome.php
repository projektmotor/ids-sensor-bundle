<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

/**
 * Was mit einer Spool-Zeile geschehen ist.
 *
 * Vier Ausgänge, nicht zwei — und der Unterschied zwischen den Misserfolgen ist der Grund
 * für diesen Typ: nach dem ersten `Retryable` bricht der Drainer ab und hebt den gesamten
 * Rest der Datei auf. Zählte ein dauerhaft unversendbarer Frame als `Retryable`, hielte er
 * die Datei für immer fest (Head-of-Line-Blocking).
 *
 * `Discarded` und `Rejected` verhalten sich für die Datei gleich — die Zeile ist weg. Sie
 * sind trotzdem getrennt, weil sie zu ENTGEGENGESETZTEN Maßnahmen führen und deshalb auf
 * verschiedene Zähler laufen (Konzept 3.6): `dropped_spool_unreadable` heißt „die
 * Spool-Datei prüfen", `dropped_rejected` heißt „den Payload prüfen".
 *
 * @internal
 */
enum DrainOutcome
{
    /** Beim Collector angekommen. */
    case Sent;

    /** Unlesbare Zeile — der Spool selbst ist beschädigt. Weg damit. */
    case Discarded;

    /** Der Collector hat dauerhaft abgewiesen (400, 403, 413, 422). Weg damit. */
    case Rejected;

    /** Der Collector war nicht erreichbar. Zeile aufheben, später erneut versuchen. */
    case Retryable;
}
