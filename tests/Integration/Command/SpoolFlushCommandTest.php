<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Der Nachsende-Command — unter mod_php der EINZIGE Transportweg.
 *
 * Damit ist er auch die Stelle mit dem größten Schadenspotenzial: Ohne die Schranke
 * gegen den fehlenden Collector leerte ein Lauf den Spool, meldete Erfolg, und kein
 * einziger Frame kam an. Der `NullShipper` wirft nie, also galt jede Zeile als
 * versendet und `finish()` löschte die Datei.
 */
final class SpoolFlushCommandTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir().'/ids-flush-cmd-'.bin2hex(random_bytes(6));
        @mkdir($this->spoolDir, 0o770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir.'/*') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->spoolDir);
    }

    public function testAnEmptySpoolIsReportedAndSucceeds(): void
    {
        $tester = $this->flush('leer');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nichts nachzusenden', $tester->getDisplay());
    }

    /**
     * Ohne collector.base_uri gibt es keine Gegenstelle — und ein Lauf wäre der lautlose
     * Totalverlust.
     *
     * Der Command hält deshalb an, statt zu laufen: Er würde die Dateien löschen,
     * nachdem der `NullShipper` jede Zeile widerspruchslos „versendet" hat. Unter mod_php
     * mit vergessener DSN war das der Weg, auf dem alle Events verschwanden, ohne dass
     * irgendetwas es meldete.
     */
    public function testWithoutACollectorTheCommandRefusesToRun(): void
    {
        $tester = $this->flush('ohne-dsn', ohneDsn: true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('kein Collector konfiguriert', $tester->getDisplay());

        // Die entscheidende Zusicherung: Der Spool ist unangetastet.
        self::assertFileExists($this->spoolDatei());
    }

    private function spoolDatei(): string
    {
        $dateien = glob($this->spoolDir.'/frames-*') ?: [];

        self::assertNotSame([], $dateien, 'Vorbedingung: es liegt etwas im Spool');

        return $dateien[0];
    }

    private function flush(string $variant, bool $ohneDsn = false): CommandTester
    {
        if ($ohneDsn) {
            file_put_contents(
                $this->spoolDir.'/frames-'.getmypid().'-test-1.jsonl',
                json_encode(['v' => 1, 'events' => [['event_type' => 'kernel.request']]], \JSON_THROW_ON_ERROR)."\n",
            );
        }

        $config = [
            'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            'spool' => ['dir' => $this->spoolDir],
        ];

        if (!$ohneDsn) {
            $config['collector'] = ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim'];
        }

        $kernel = new TestKernel($config, 'spool-flush-'.$variant);
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('ids:sensor:spool:flush'));
        $tester->execute([]);

        return $tester;
    }
}
