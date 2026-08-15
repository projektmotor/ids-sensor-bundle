<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Ein Logger, der bei jedem Schreibversuch wirft.
 *
 * Bildet den Fall nach, der die fail-open-Kette an ihrer empfindlichsten Stelle trifft:
 * ein Monolog-StreamHandler auf voller oder schreibgeschützter Platte wirft
 * `UnexpectedValueException`. Passiert das im catch-Zweig eines Sensors, ist der
 * Fehlerpfad selbst der Fehler — und ohne äußere Absicherung steht die Exception in der
 * überwachten Anwendung.
 *
 * @internal
 */
final class ThrowingLogger extends AbstractLogger
{
    /**
     * Untypisierte Parameter, damit die Klasse auch unter `psr/log: ^1.1` lädt — siehe
     * {@see \ProjektMotor\IdsSensor\Support\Telemetry\FailSafeLogger::log()}.
     *
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        throw new \UnexpectedValueException('Der Log-Datenstrom konnte nicht geöffnet werden.');
    }
}
