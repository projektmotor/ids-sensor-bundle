<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

/**
 * Nimmt Frames auf, die nicht direkt versendet werden konnten oder dürfen.
 *
 * Der Spool ist KEIN Übertragungsweg, sondern eine lokale Zwischenablage. Das
 * IdsBackendBundle liest ihn nie — es liest ausschließlich vom Broker. Den Versand
 * übernimmt ein zweiter Prozess auf demselben System
 * ({@see \ProjektMotor\IdsSensor\Command\SpoolFlushCommand}), der dieselben
 * XADD-only-Rechte verwendet. Die Paketgrenze bleibt damit das Format aus Konzept
 * Abschnitt 3, und niemand braucht Dateizugriff auf den fremden Host.
 *
 * @internal
 */
interface SpoolInterface
{
    /**
     * @param array<string, mixed> $frame
     *
     * @return bool false, wenn der Frame verworfen wurde (Spool voll oder nicht beschreibbar)
     */
    public function append(array $frame): bool;

    public function sizeInBytes(): int;

    /** Wegen Überschreitung der Maximalgröße verworfene Frames. */
    public function discardedFull(): int;

    /** Wegen fehlender Schreibrechte verworfene Frames. */
    public function discardedUnwritable(): int;

    /** Wegen eines Kodierfehlers verworfene Frames — nicht zu verwechseln mit „voll". */
    public function discardedUnencodable(): int;

    public function spooledFrames(): int;

    /**
     * Wartende Dateien — aktive, versiegelte und gerade beanspruchte.
     *
     * Beantwortet „liegt etwas herum", nicht „was darf der Drainer abholen". Gehört ins
     * Interface, weil {@see \ProjektMotor\IdsSensor\Delivery\Heartbeat\PayloadFactory}
     * die Zahl braucht und sie sich bis hierher mit `method_exists()` besorgt hat —
     * also mit einer Prüfung, die kein Vertrag ist und jede Fehlbenennung verschweigt.
     *
     * @return list<string>
     */
    public function waitingFiles(): array;

    /**
     * Alter der ältesten wartenden Datei in Sekunden, `null` wenn nichts wartet.
     *
     * Konzept 3.4: die einzige Außenansicht eines nicht laufenden Drains.
     */
    public function oldestWaitingAgeSeconds(): ?int;
}
