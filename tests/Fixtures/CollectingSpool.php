<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolInterface;

/**
 * Sammelt Frames im Speicher, statt sie auf die Platte zu schreiben.
 *
 * Nötig, seit {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher} den Spool
 * zwingend verlangt. Ein {@see \ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool}
 * im Unit-Test bräuchte ein Verzeichnis und verstieße damit gegen die F.I.R.S.T.-Regel
 * „Fast" aus CLAUDE.md §1.10.
 *
 * Mit `$acceptsNothing` bildet er den vollen Spool nach — den Fall, in dem der Verlust
 * endgültig ist und nur noch gezählt wird.
 */
final class CollectingSpool implements SpoolInterface
{
    /** @var list<array<string, mixed>> */
    private array $frames = [];

    private int $discardedFull = 0;

    public function __construct(
        private readonly bool $acceptsNothing = false,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function append(array $frame): bool
    {
        if ($this->acceptsNothing) {
            ++$this->discardedFull;

            return false;
        }

        $this->frames[] = $frame;

        return true;
    }

    public function sizeInBytes(): int
    {
        return 0;
    }

    public function discardedFull(): int
    {
        return $this->discardedFull;
    }

    public function discardedUnwritable(): int
    {
        return 0;
    }

    public function discardedUnencodable(): int
    {
        return 0;
    }

    public function spooledFrames(): int
    {
        return \count($this->frames);
    }

    /**
     * Der Speicher-Spool hat keine Dateien — und meldet das, statt es zu behaupten.
     *
     * @return list<string>
     */
    public function waitingFiles(): array
    {
        return [];
    }

    public function oldestWaitingAgeSeconds(): ?int
    {
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        return $this->frames;
    }
}
