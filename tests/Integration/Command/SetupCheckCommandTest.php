<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die Betriebsprüfung (Plan: Phase 12).
 *
 * Der Command existiert, weil das Bundle im Request-Pfad fail-open ist und eine
 * Fehlkonfiguration deshalb im Betrieb NICHT auffällt. Diese Tests halten fest, welche
 * Fehler er wirklich aufdeckt — eine Prüfung, die immer grün ist, wäre schlimmer als keine,
 * weil sie Vertrauen erzeugt, das sie nicht deckt.
 */
final class SetupCheckCommandTest extends TestCase
{
    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir().'/ids-setup-check-'.bin2hex(random_bytes(6));
        @mkdir($this->spoolDir, 0o770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
    }

    public function testAViableConfigurationIsGreen(): void
    {
        $tester = $this->setupCheck('gruen');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('einsatzfähig', $tester->getDisplay());
    }

    /**
     * DER teuerste Fehler: ein nicht abbildbares environment führt collectorseitig zu
     * env_type NOT NULL (Konzept 4.2.1) — stiller Totalverlust dieser Instanz, von einem
     * toten Sensor nicht unterscheidbar. Deshalb Rückgabewert 1.
     */
    public function testAnUnmappableEnvironmentAborts(): void
    {
        $tester = $this->setupCheck('env', ['environment' => 'prod_eu_west_2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nicht auf prod|staging|dev abbildbar', $tester->getDisplay());
    }

    /**
     * Der wahrscheinlichste Erstinstallationsfehler: auto_setup sendet XGROUP CREATE, was
     * die XADD-only-Rechte ablehnen. In der Entwicklung mit weiten Rechten fällt das nicht
     * auf — beim ersten Versand in Produktion schon.
     */
    public function testAutoSetupTrueIsAFinding(): void
    {
        $tester = $this->setupCheck('autosetup', [
            'transport' => [
                'dsn' => 'redis://127.0.0.1:6379/ids:events/group/consumer',
                'options' => ['auto_setup' => true],
            ],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('auto_setup', $tester->getDisplay());
    }

    public function testAMissingTransportDsnIsAFinding(): void
    {
        $tester = $this->setupCheck('kein-transport', ['transport' => ['dsn' => null]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('enden im Nichts', $tester->getDisplay());
    }

    /**
     * Ein altes Element im Spool heißt: niemand holt es ab. Unter Spool-First ist das der
     * Weg in den Totalverlust, deshalb ein Befund und kein Hinweis.
     */
    public function testAnOldSpoolEntryIsAFinding(): void
    {
        $datei = $this->spoolDir.'/frames-4711.jsonl';
        file_put_contents($datei, "{}\n");
        // 30 s Intervall × 3 = 90 s Grenze; 600 s liegt sicher darüber.
        touch($datei, time() - 600);

        $tester = $this->setupCheck('spool-alt');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('spool:flush', $tester->getDisplay());
    }

    public function testAFreshSpoolEntryIsFine(): void
    {
        file_put_contents($this->spoolDir.'/frames-4712.jsonl', "{}\n");

        $tester = $this->setupCheck('spool-frisch');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testDisabledHeartbeatIsAFinding(): void
    {
        $tester = $this->setupCheck('hb-aus', ['heartbeat' => ['enabled' => false]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ids.sensor_silent', $tester->getDisplay());
    }

    public function testADisabledKernelLayerIsAFinding(): void
    {
        $tester = $this->setupCheck('kernel-aus', ['layers' => ['kernel' => ['enabled' => false]]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Kernel-Ebene ist abgeschaltet', $tester->getDisplay());
    }

    /**
     * Konzept 2. verlangt, die Asymmetrie der drei Ebenen nicht zu verschleiern. Dass die
     * Business-Ebene ohne Anwendungscode wirkungslos ist, MUSS also gesagt werden — darf
     * aber kein Befund sein, denn es ist kein Fehler.
     */
    public function testTheBusinessLayerAsymmetryIsNamedButNotAsAnError(): void
    {
        $tester = $this->setupCheck('asymmetrie');
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString('ERFOLGREICHE Angriffe', $display);
    }

    /**
     * --strict macht aus Hinweisen Befunde. Weil der Business-Hinweis immer erscheint, ist
     * --strict praktisch immer rot — das ist Absicht: es ist der Schalter für Deployments,
     * die jede Einschränkung ausdrücklich abnicken wollen.
     */
    public function testStrictTurnsHintsIntoFindings(): void
    {
        $tester = $this->setupCheck('strict', [], ['--strict' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $input
     */
    private function setupCheck(string $variant, array $overrides = [], array $input = []): CommandTester
    {
        $kernel = new TestKernel(array_replace_recursive([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => IntegrationTestCase::SESSION_KEY],
            'transport' => ['dsn' => 'in-memory://'],
            'spool' => ['dir' => $this->spoolDir, 'drain_interval_s' => 30],
        ], $overrides), 'setup-check-'.$variant);
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('ids:sensor:setup-check'));
        $tester->execute($input);

        return $tester;
    }
}
