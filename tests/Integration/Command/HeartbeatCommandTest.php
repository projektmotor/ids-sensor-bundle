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
 * Der cron-Command und sein Rückgabewert.
 *
 * Der Rückgabewert ist hier die eigentliche Schnittstelle: Er läuft minütlich, und ein
 * Fehlerkanal, der ununterbrochen meldet, meldet nichts mehr. Der Hilfetext sagt zu,
 * dass nur ein FEHLGESCHLAGENER Versand 1 ergibt — geprüft hat das niemand.
 */
final class HeartbeatCommandTest extends TestCase
{
    /** @var list<string> */
    private array $stempeldateien = [];

    protected function tearDown(): void
    {
        foreach ($this->stempeldateien as $datei) {
            @unlink($datei);
        }

        $this->stempeldateien = [];
    }

    public function testAForcedRunSends(): void
    {
        $tester = $this->heartbeat('force', input: ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Heartbeat gesendet', $tester->getDisplay());
    }

    /**
     * Ein zweiter Lauf innerhalb des Intervalls ist gedrosselt — und das ist kein Fehler.
     */
    public function testAThrottledRunIsNotAnError(): void
    {
        $tester = $this->heartbeat('drossel', input: ['--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $zweiter = $this->heartbeat('drossel');

        self::assertSame(Command::SUCCESS, $zweiter->getStatusCode());
        self::assertStringContainsString('Noch nicht fällig', $zweiter->getDisplay());
    }

    /**
     * `mode: request` macht den Command wirkungslos — aber nicht zum Fehlerfall.
     *
     * Er gab hier FAILURE zurück, und damit feuerte der cron-Fehlerbericht bei JEDEM
     * Lauf, dauerhaft — genau das, was der Hilfetext ausschließt („nicht bei jedem
     * gedrosselten Lauf"). Die Lage ist zudem keine Störung: Der Request-Pfad sendet
     * weiter, es fehlt kein Lebenszeichen. Sichtbar bleibt sie als Warnung und als
     * Befund im Deploy-Check, der nicht minütlich läuft.
     */
    public function testTheRequestModeWarnsButDoesNotFailTheCronJob(): void
    {
        $tester = $this->heartbeat('nur-request', ['heartbeat' => ['mode' => 'request']]);

        self::assertSame(
            Command::SUCCESS,
            $tester->getStatusCode(),
            'Ein dauerhafter Exit 1 macht den cron-Fehlerkanal wertlos',
        );
        self::assertStringContainsString('wirkungslos', $tester->getDisplay());
    }

    /**
     * `interval_s: 0` stellt das automatische Senden ein — `--force` übergeht es.
     *
     * Die Bedeutung stand bis zuletzt NUR im Docblock eines Unit-Tests. In `doc/08`
     * hieß es „Drosselungsintervall", und ein Betreiber erfuhr nirgends, dass die 0
     * ein Abschaltweg ist und wie er sich von `enabled: false` unterscheidet. Beides
     * steht jetzt dort, und dieser Test hält es fest.
     */
    public function testAnIntervalOfZeroStopsAutomaticSendingButNotForce(): void
    {
        $ohneForce = $this->heartbeat('interval-null', ['heartbeat' => ['interval_s' => 0]]);

        self::assertSame(Command::SUCCESS, $ohneForce->getStatusCode(), 'Abgeschaltet ist kein Fehler');
        self::assertStringContainsString('Noch nicht fällig', $ohneForce->getDisplay());

        $mitForce = $this->heartbeat('interval-null-force', ['heartbeat' => ['interval_s' => 0]], ['--force' => true]);

        self::assertStringContainsString(
            'Heartbeat gesendet',
            $mitForce->getDisplay(),
            '--force sagt ausdrücklich, dass es das Intervall übergeht — wer es tippt, will senden',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $input
     */
    private function heartbeat(string $variant, array $overrides = [], array $input = []): CommandTester
    {
        $kernel = new TestKernel(array_replace_recursive([
            // Je Variante eine eigene Kennung: Der Scheduler drosselt über APCu, und
            // dessen Schlüssel besteht aus application_id und instance_id. Ohne das
            // drosselte der erste Test in diesem Prozess alle folgenden.
            'application_id' => 'shop-'.$variant,
            'environment' => 'prod',
            'session_hash' => ['key' => IntegrationTestCase::SESSION_KEY],
            'transport' => ['dsn' => 'in-memory://'],
            'heartbeat' => ['stamp_file' => $this->stampFile($variant)],
        ], $overrides), 'heartbeat-cmd-'.$variant);
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('ids:sensor:heartbeat'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * Je Variante eine eigene Stempeldatei — sonst drosselt der erste Test den zweiten.
     */
    private function stampFile(string $variant): string
    {
        $pfad = sys_get_temp_dir().'/ids-heartbeat-'.$variant.'-'.getmypid().'.stamp';
        $this->stempeldateien[] = $pfad;

        return $pfad;
    }
}
