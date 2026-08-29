<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Exception;

/**
 * Der Collector hat mit `429` abgewiesen und eine Wartezeit genannt.
 *
 * WARUM ES DAFÜR EINEN EIGENEN TYP GIBT
 *
 * Aus demselben Grund wie bei {@see UnshippableFrameException}: weil eine Entscheidung
 * daran hängt. Das Bundle ist fail-open und wirft nach außen grundsätzlich nicht, also
 * gibt es eine eigene Klasse nur dort, wo jemand sie FANGEN muss, um sich anders zu
 * verhalten.
 *
 * Hier ist es der Circuit Breaker. Konzept 3.6 legt für `429` normativ fest: spoolen,
 * `Retry-After` beachten, ein Breaker-Fehler. Die ersten beiden Teile widersprächen sich
 * ohne diesen Typ — ein gewöhnliches Throwable führt zu genau einem Fehler im Breaker,
 * und unterhalb der Fehlerschwelle ginge der nächste Frame unmittelbar wieder hinaus.
 * Die Wartezeit wäre entgegengenommen und im selben Atemzug ignoriert.
 *
 * Die Sendung selbst wird behandelt wie jede andere Störung: gespoolt, nicht verworfen.
 * `429` heißt „später erneut", nicht „geht nie" — das ist der Unterschied zu
 * {@see UnshippableFrameException}.
 *
 * @internal
 */
final class ThrottledException extends \RuntimeException
{
    /**
     * @param float|null $retryAfterSeconds Was der Collector im `Retry-After` genannt hat,
     *                                      oder `null`, wenn der Header fehlte oder
     *                                      unbrauchbar war. `null` ist kein Fehler: `429`
     *                                      ohne `Retry-After` ist zulässig, und dann gilt
     *                                      die konfigurierte Offen-Zeit.
     */
    public function __construct(
        string $message,
        public readonly ?float $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
