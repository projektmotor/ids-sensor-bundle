<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Console;

/**
 * Die Schalter der Konsolen-Erfassung.
 *
 * @internal
 */
final class Options
{
    /**
     * @param list<string> $ignoredCommands reguläre Ausdrücke gegen den Befehlsnamen
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly array $ignoredCommands = [],
    ) {
    }

    /**
     * Anders als bei {@see \ProjektMotor\IdsSensor\Sensor\Kernel\Options::isIgnored()}
     * ist die VORGABE hier nicht leer — sie schließt die eigenen Befehle des Bundles
     * aus. Das ist die eine begründete Ausnahme von der Regel, die dort steht:
     *
     * `ids:sensor:spool:flush` läuft laut Konzept 3.6 je Minute per cron. Ohne den
     * Ausschluss erzeugte er ein `console.command` je Lauf, das der nächste Lauf
     * versendet, um dabei das nächste zu erzeugen — eine Ereignisspur, die nur die
     * eigene Maschinerie beschreibt und mit der cron-Frequenz wächst. Dass der Sensor
     * lebt, meldet der Heartbeat (Konzept 3.4), und zwar billiger.
     *
     * Der Unterschied zu `ignored_paths`: `/_profiler` ist ein Angriffsziel IN der
     * überwachten Anwendung, `ids:sensor:*` ist der Sensor selbst. Ausgeschlossen wird
     * hier Selbstbeobachtung, kein Signal über die Anwendung.
     */
    public function isIgnored(string $command): bool
    {
        foreach ($this->ignoredCommands as $pattern) {
            if (1 === @preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }
}
