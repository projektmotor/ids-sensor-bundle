<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

/**
 * Was mit einer Spool-Zeile geschehen ist.
 *
 * Drei Ausgänge, nicht zwei — und der Unterschied zwischen den beiden Misserfolgen ist
 * der Grund für diesen Typ: nach dem ersten `Retryable` bricht der Drainer ab und hebt
 * den gesamten Rest der Datei auf. Zählte ein dauerhaft unversendbarer Frame als
 * `Retryable`, hielte er die Datei für immer fest (Head-of-Line-Blocking).
 *
 * @internal
 */
enum DrainOutcome
{
    /** Beim Collector angekommen. */
    case Sent;

    /** Geht nie: unlesbare Zeile oder dauerhaft unkodierbarer Frame. Weg damit. */
    case Discarded;

    /** Der Collector war nicht erreichbar. Zeile aufheben, später erneut versuchen. */
    case Retryable;
}
