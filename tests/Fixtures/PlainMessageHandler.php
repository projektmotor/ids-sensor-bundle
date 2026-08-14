<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Zählt, ob der Standard-Bus der Anwendung Nachrichten noch behandelt. */
#[AsMessageHandler]
final class PlainMessageHandler
{
    public int $handled = 0;

    public function __invoke(PlainMessage $message): void
    {
        ++$this->handled;
    }
}
