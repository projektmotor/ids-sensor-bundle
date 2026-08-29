<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\DependencyInjection\ConfigurationTree;
use ProjektMotor\IdsSensor\Sensor\Console\Options;

/**
 * Die Schalter der Konsolen-Erfassung — vor allem die Vorgabe der Ausschlussliste.
 *
 * Sie ist die einzige nicht-leere Ausschlussvorgabe des ganzen Bundles. Fiele sie weg,
 * erzeugte der minütliche `ids:sensor:spool:flush` ein Ereignis, das der nächste Lauf
 * versendet, um dabei das nächste zu erzeugen.
 */
#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    /**
     * Die Vorgabe des Konfigurationsbaums schließt die eigenen Befehle aus — geprüft
     * an der Konstante selbst, damit Baum und Verhalten nicht auseinanderlaufen können.
     */
    public function testTheBundlesOwnCommandsAreIgnoredByDefault(): void
    {
        $options = new Options(ignoredCommands: ConfigurationTree::DEFAULT_IGNORED_COMMANDS);

        self::assertTrue($options->isIgnored('ids:sensor:spool:flush'));
        self::assertTrue($options->isIgnored('ids:sensor:heartbeat'));
        self::assertTrue($options->isIgnored('ids:sensor:setup-check'));
    }

    /**
     * Der Ausschluss ist am Anfang verankert. Ohne den Anker verschwände auch ein
     * fremder Befehl, der die Zeichenkette bloß enthält — und mit ihm ein Signal über
     * die überwachte Anwendung.
     */
    public function testTheDefaultDoesNotReachBeyondTheBundle(): void
    {
        $options = new Options(ignoredCommands: ConfigurationTree::DEFAULT_IGNORED_COMMANDS);

        self::assertFalse($options->isIgnored('app:import-users'));
        self::assertFalse($options->isIgnored('messenger:consume'));
        self::assertFalse($options->isIgnored('app:ids:sensor:eigenes'));
    }

    /**
     * Die Klasse selbst gibt nichts vor: Die Vorgabe gehört in den
     * Konfigurationsbaum, wo `debug:config` sie zeigt. Ein zweiter Vorgabewert hier
     * wäre eine zweite Wahrheit.
     */
    public function testTheClassItselfIgnoresNothing(): void
    {
        $options = new Options();

        self::assertFalse($options->isIgnored('ids:sensor:spool:flush'));
        self::assertTrue($options->enabled);
    }

    public function testAMatchingPatternIgnoresTheCommand(): void
    {
        $options = new Options(ignoredCommands: ['#^app:cron:#']);

        self::assertTrue($options->isIgnored('app:cron:nightly'));
        self::assertFalse($options->isIgnored('app:import-users'));
    }
}
