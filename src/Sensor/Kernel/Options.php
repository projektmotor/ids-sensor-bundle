<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

/**
 * Die Schalter der Kernel-Ebene, gebündelt statt als sechs Konstruktorargumente je
 * Sensor.
 *
 * @internal
 */
final class Options
{
    /**
     * @param list<string> $ignoredPaths reguläre Ausdrücke gegen den Pfad
     */
    public function __construct(
        public readonly bool $captureRequest = true,
        public readonly bool $captureResponse = true,
        public readonly bool $captureException = true,
        public readonly SubRequestMode $subRequests = SubRequestMode::ExceptionsOnly,
        public readonly array $ignoredPaths = [],
        public readonly bool $exposeCorrelationAttribute = true,
    ) {
    }

    /**
     * Die Vorgabe für ignoredPaths ist leer, und das ist eine Entscheidung, keine
     * Nachlässigkeit: Regel R2b lebt davon, Zugriffe auf /_profiler zu SEHEN
     * (Konzept 2.2.1 nennt das ausdrücklich als Beispiel). Ein gut gemeinter Default,
     * der Framework-Pfade ausschließt, würde genau das Signal löschen, das Szenario
     * S1 erkennbar macht.
     */
    public function isIgnored(string $path): bool
    {
        foreach ($this->ignoredPaths as $pattern) {
            if (1 === @preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
