<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;
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

    /**
     * `open_for_s: 0` ist die stillste denkbare Fehlkonfiguration.
     *
     * Der Breaker zählt dann Fehlschläge, meldet `half_open` — und sperrt nie. Jeder
     * Request zahlt bei einem Broker-Ausfall weiterhin die vollen Timeouts, also genau
     * das, wogegen es den Breaker gibt. Der Konfigurationsbaum kann die 0 nicht
     * ablehnen (sie ist der Typ-Platzhalter für `int`) und weist die Prüfung
     * ausdrücklich dem verbrauchenden Dienst zu; für den Breaker tat sie niemand.
     */
    public function testAnOpenPeriodOfZeroIsAFinding(): void
    {
        $tester = $this->setupCheck('breaker-null', ['circuit_breaker' => ['open_for_s' => 0]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('open_for_s ist 0', $tester->getDisplay());
    }

    /**
     * Der Deploy-Check zeigt den WIRKSAMEN Modus, nicht den konfigurierten.
     *
     * `auto` wird zur Compile-Zeit auf `both` aufgelöst. Hier stand der konfigurierte
     * Wert — wer die Ausgabe mit dem `mode` im Heartbeat verglich, sah einen Widerspruch,
     * den es nicht gibt.
     */
    public function testTheEffectiveHeartbeatModeIsShownNotTheConfiguredOne(): void
    {
        $tester = $this->setupCheck('hb-auto', ['heartbeat' => ['mode' => 'auto']]);

        self::assertStringContainsString('mode=both (aus auto)', $tester->getDisplay());
    }

    /**
     * Der Hostname-Hinweis erscheint nicht für die bereinigte Form des eigenen Namens.
     *
     * Die Regel selbst — dass ein gekürzter oder umgeschriebener Hostname derselbe Host
     * bleibt — steht in `InstanceIdProviderTest`; sie lässt sich nur dort scharf prüfen,
     * weil `gethostname()` in diesem Prozess nicht austauschbar ist. Hier wird belegt,
     * dass der Command diese Regel benutzt.
     */
    public function testTheOwnHostnameIsNotReportedAsMismatch(): void
    {
        $bereinigt = InstanceIdProvider::sanitize((string) gethostname());

        $tester = $this->setupCheck('hostname', ['instance_id' => $bereinigt], ['--strict' => true]);

        self::assertStringNotContainsString('entspricht nicht dem Hostnamen', $tester->getDisplay());
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
     * Der Command muss auf der dokumentierten Mindestkonfiguration durchlaufen.
     *
     * `spool.dir` ist per Vorgabe null; erst das Bundle setzt daraus
     * `%kernel.project_dir%/var/ids-spool`. Der Command las aber den ROHEN
     * Konfigurationswert, und weil die Datei `declare(strict_types=1)` trägt, warf
     * `is_dir(null)` einen TypeError. Genau der Command, der laut doc/07-betrieb.md im
     * Deploy Pflicht ist und ausdrücklich nicht mit `|| true` entschärft werden soll,
     * brach damit bei der Standardinstallation ab.
     *
     * Jede andere Variante dieses Testfiles setzt `spool.dir` — deshalb ist es nie
     * aufgefallen.
     */
    public function testItRunsOnTheDocumentedMinimalConfiguration(): void
    {
        $kernel = new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => IntegrationTestCase::SESSION_KEY],
            'transport' => ['dsn' => 'in-memory://'],
        ], 'setup-check-ohne-spool-dir');
        $kernel->boot();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('ids:sensor:setup-check'));
        $tester->execute([]);

        self::assertSame(
            Command::SUCCESS,
            $tester->getStatusCode(),
            'Ohne spool.dir muss der Deploy-Check durchlaufen:'."\n".$tester->getDisplay(),
        );
        self::assertStringContainsString('Spool', $tester->getDisplay());
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
