<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
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

    public function testAMissingTransportDsnIsAFinding(): void
    {
        $tester = $this->setupCheck('kein-transport', ['collector' => ['base_uri' => null]]);

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
     * Die abgeschaltete Ebene dagegen IST ein Befund — wie bei Kernel und Security.
     *
     * Der Unterschied zum Test darüber ist der Unterschied zwischen „niemand hat
     * instrumentiert" (Asymmetrie, kein Fehler) und „jemand hat abgeschaltet". Ohne diesen
     * Befund schwieg der Deploy-Check ausgerechnet bei der Ebene, deren Ausfall doc/02
     * selbst als die wichtigste Aussage der Dokumentation bezeichnet.
     */
    public function testADisabledBusinessLayerIsAFinding(): void
    {
        $tester = $this->setupCheck('business-aus', ['layers' => ['business' => ['enabled' => false]]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Business-Ebene ist abgeschaltet', $tester->getDisplay());
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
            'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            'collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim'],
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
     * Die Prüfung, die den entfallenen HMAC-Schlüssel ersetzt.
     *
     * `actor.session_id_hash` ist seit Fassung 2 ein blanker SHA-256 (Konzept 2.2.4).
     * Damit trägt allein die Entropie der Session-ID, dass sich der Hash nicht
     * durchprobieren lässt — und diese Voraussetzung wird nirgends sonst geprüft. Wer
     * `session.sid_length` nach unten dreht, bekommt deshalb einen Befund und keinen
     * Hinweis: Dieselbe Einstellung schwächt die Sitzungssicherheit der Anwendung mit.
     */
    public function testAWeakSessionIdEntropyIsAFinding(): void
    {
        $tester = $this->setupCheckMitSessionEntropie('schwache-id', laenge: 22, bitsJeZeichen: 4);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('88 Bit Entropie', $tester->getDisplay());
    }

    /**
     * Die PHP-Vorgaben müssen grün bleiben — sonst beschwert sich der Deploy-Check über
     * eine Einstellung, die niemand vorgenommen hat.
     */
    public function testTheDefaultSessionIdEntropyIsGreen(): void
    {
        $tester = $this->setupCheckMitSessionEntropie('starke-id', laenge: 32, bitsJeZeichen: 5);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('Bit Entropie', $tester->getDisplay());
    }

    /**
     * `spool.max_bytes: 0` heißt: der Spool nimmt NICHTS auf.
     *
     * `FileSpool::hasRoomFor()` ist dann immer falsch, jeder Frame wird verworfen und als
     * `dropped_spool_full` gezählt. Unter mod_php, wo der Spool laut Konzept 3.3.1 der
     * einzige Transportweg ist, ist das der vollständige Erfassungsausfall — sichtbar nur
     * als wachsender Zähler. Der Konfigurationsbaum kann die 0 nicht ablehnen (sie ist der
     * Typ-Platzhalter für `int`) und weist die Prüfung dem verbrauchenden Dienst zu; für
     * den Circuit Breaker war sie eingelöst, für den Spool nicht.
     */
    public function testASpoolLimitOfZeroIsAFinding(): void
    {
        $tester = $this->setupCheck('spool-null', ['spool' => ['max_bytes' => 0]]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('spool.max_bytes ist 0', $tester->getDisplay());
    }

    /**
     * Eine Körpergrenze über dem raw-Budget kann niemand gewollt haben.
     *
     * `max_request_body_bytes` lässt den JSON-Körper herein, `max_bytes` wirft ihn danach
     * wieder hinaus — er steht als erstes in der Abbaureihenfolge von `capped()`. Ist die
     * erste Grenze größer, ist jeder Körper, der sie ausschöpft, garantiert verloren:
     * gelesen, redigiert, nie angekommen.
     */
    public function testABodyLimitAboveTheRawBudgetIsAHint(): void
    {
        $tester = $this->setupCheck('raw-grenzen', [
            'raw' => ['max_bytes' => 8192, 'max_request_body_bytes' => 65536],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'Kaputt ist nichts — nur wirkungslos');
        self::assertStringContainsString('raw.max_request_body_bytes', $tester->getDisplay());
    }

    /**
     * Die mitgelieferten Vorgaben sind beide 32768 — und dürfen NICHT warnen.
     *
     * Ein Deploy-Check, der sich über die Standardkonfiguration beschwert, wird beim
     * ersten Mal gelesen und danach nie wieder. Bei gleichen Grenzen überleben Körper bis
     * etwa 28 KiB; das ist die dokumentierte Folge der Vorgabe, keine Fehlkonfiguration.
     */
    public function testEqualRawLimitsAreNotReported(): void
    {
        $tester = $this->setupCheck('raw-gleich');

        self::assertStringNotContainsString('raw.max_request_body_bytes', $tester->getDisplay());
    }

    /**
     * Setzt die beiden ini-Werte, aus denen der Command die Entropie rechnet, und stellt
     * sie danach wieder her — sonst trüge der nächste Test die Einstellung mit.
     */
    private function setupCheckMitSessionEntropie(string $variant, int $laenge, int $bitsJeZeichen): CommandTester
    {
        $vorherLaenge = \ini_get('session.sid_length');
        $vorherBits = \ini_get('session.sid_bits_per_character');

        ini_set('session.sid_length', (string) $laenge);
        ini_set('session.sid_bits_per_character', (string) $bitsJeZeichen);

        try {
            return $this->setupCheck($variant);
        } finally {
            ini_set('session.sid_length', false === $vorherLaenge ? '32' : $vorherLaenge);
            ini_set('session.sid_bits_per_character', false === $vorherBits ? '4' : $vorherBits);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $input
     */
    private function setupCheck(string $variant, array $overrides = [], array $input = []): CommandTester
    {
        $kernel = new TestKernel(array_replace_recursive([
            'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
            'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
            'collector' => ['base_uri' => 'https://collector.test', 'username' => 'sensor', 'password' => 'geheim'],
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
