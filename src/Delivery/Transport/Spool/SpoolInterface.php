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

    public function spooledFrames(): int;
}
