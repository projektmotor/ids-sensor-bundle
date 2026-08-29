<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport\Breaker;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerState;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\SharedStateStore;

/**
 * Der prozessübergreifende Zustandsspeicher des Breakers.
 *
 * Er hatte bis hierher KEINEN Test — weder Unit noch Integration.
 * {@see CircuitBreakerTest}
 * benutzt durchgehend eine In-Memory-Attrappe, und `ResilienceTest` prüft nur den Effekt
 * innerhalb eines einzelnen Prozesses. Genau deshalb ist unbemerkt geblieben, dass die
 * APCu-Erkennung in der CLI systematisch das Falsche antwortete.
 *
 * @internal
 */
final class SharedStateStoreTest extends TestCase
{
    private string $directory;

    /**
     * Je Test ein eigener Bereichsschlüssel.
     *
     * Das APCu-Segment ist prozessweit — genau die Eigenschaft, die den Speicher in
     * Produktion nützlich macht. Ohne Trennung sähe jeder Test den Zustand seiner
     * Vorgänger, und die Reihenfolge entschiede über das Ergebnis (F.I.R.S.T.:
     * Independent). Dieselbe Vorkehrung wie in `ResilienceTest`.
     */
    private string $scopeKey;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir().'/ids-breaker-'.$suffix;
        $this->scopeKey = 'shop-api-'.$suffix;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * Ohne vorherigen Schreibvorgang ist der Breaker geschlossen — nicht offen.
     *
     * Die Richtung ist eine Sicherheitsaussage: „unbekannt" muss „sende" heißen. Ein
     * Speicher, der im Zweifel offen meldet, schaltet die Erfassung ab.
     */
    public function testAnUnknownStateIsClosed(): void
    {
        self::assertFalse($this->store()->read()->isOpenAt(microtime(true)));
    }

    /**
     * Der geschriebene Zustand muss von einer ZWEITEN Instanz gelesen werden können.
     *
     * Das ist der ganze Zweck der Klasse: Bei PHP-FPM zählt jedes Kind für sich, und
     * ohne geteilten Zustand erreichte die Schwelle nie jemand. Zwei Instanzen im selben
     * Prozess bilden das so nah nach, wie es ohne zweiten Prozess geht — sie teilen
     * keinen Objektzustand, nur die Ablage.
     */
    public function testAWrittenStateIsVisibleToASecondInstance(): void
    {
        $geschrieben = new BreakerState(3, microtime(true) + 30, 1);

        $this->store()->write($geschrieben);
        $gelesen = $this->store()->read();

        self::assertSame(3, $gelesen->failures);
        self::assertTrue($gelesen->isOpenAt(microtime(true)), 'Der offene Zustand muss überdauern');
    }

    /**
     * DER Regressionstest: Bei `apc.enable_cli=0` muss der Dateirückfall greifen.
     *
     * Hier lag der Fehler. Die Erkennung fragte `ini_get('apc.enabled')`, und das meldet
     * auch in der CLI 1, wenn nur `apc.enable_cli` auf 0 steht. APCu galt damit als
     * verwendbar, während `apcu_store()` folgenlos blieb und `apcu_fetch()` immer
     * `$success = false` lieferte — der Rückfall wurde NIE erreicht, und der Breaker war
     * in jedem CLI-Prozess still wirkungslos. Betroffen war unter anderem der
     * cron-getriebene `ids:sensor:spool:flush` gegen einen ausgefallenen Collector: kein
     * Öffnen, also bei jedem Lauf das volle Timeout.
     *
     * Im UNTERPROZESS mit `-d apc.enable_cli=0`, weil die Testumgebung APCu in der CLI
     * ausdrücklich aktiviert (siehe .github/workflows/ci.yml). Ein Test, der sich hier
     * überspringt, prüfte genau in der Konstellation nichts, in der der Fehler steckte.
     */
    public function testWithApcuDisabledInCliTheStateGoesToDisk(): void
    {
        if (!\function_exists('apcu_enabled')) {
            self::markTestSkipped('Ohne APCu-Erweiterung gibt es die fragliche Unterscheidung nicht.');
        }

        $stateFile = $this->directory.'/breaker.state';

        $skript = \sprintf(
            'require %s; (new %s(%s, "shop-api"))->write(new %s(2, microtime(true) + 30, 1));',
            var_export(__DIR__.'/../../../../../vendor/autoload.php', true),
            SharedStateStore::class,
            var_export($this->directory, true),
            BreakerState::class,
        );
        $skript = str_replace('"shop-api"', var_export($this->scopeKey, true), $skript);

        exec(\sprintf(
            '%s -d apc.enabled=1 -d apc.enable_cli=0 -r %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($skript),
        ), $ausgabe, $rueckgabe);

        self::assertSame(0, $rueckgabe, 'Der Unterprozess ist gescheitert: '.implode("\n", $ausgabe));
        self::assertFileExists(
            $stateFile,
            'Ohne speicherndes APCu MUSS der Zustand auf die Platte — sonst ist der Breaker in der CLI wirkungslos',
        );
        // Bewusst die DATEI und nicht der Store: Dieser Prozess hat APCu, läse also
        // von dort und nicht von der Platte.
        $inhalt = (string) file_get_contents($stateFile);
        self::assertStringContainsString('"failures":2', $inhalt);
    }

    /**
     * DER Test für die Unteilbarkeit: Gleichzeitiges Hochzählen darf nichts verschlucken.
     *
     * Vorher zählte der Breaker mit `read()` + `write()`, und das ist ein Lost Update.
     * Fällt der Collector aus, laufen n FPM-Kinder gleichzeitig durch diesen Pfad, lesen alle
     * `failures = 0` und schreiben alle `1` — der Zähler stieg nicht mit der Zahl der
     * Fehlschläge, sondern wurde ständig zurückgesetzt. Die Schwelle wurde im ungünstigen
     * Fall NIE erreicht: ausgerechnet unter Last, also in genau dem Szenario, für das
     * `CircuitBreaker` laut seinem eigenen Docblock existiert.
     *
     * Echte Unterprozesse, weil es in EINEM Prozess nichts zu verschränken gibt.
     */
    public function testConcurrentIncrementsDoNotGetLost(): void
    {
        $prozesse = 4;
        $proProzess = 25;

        $skript = \sprintf(
            'require %s; $s = new %s(%s, %s);'
            .'for ($i = 0; $i < %d; ++$i) { $s->mutate(static fn (%s $z): %s => new %s($z->failures + 1, 0.0, 0)); }',
            var_export(__DIR__.'/../../../../../vendor/autoload.php', true),
            SharedStateStore::class,
            var_export($this->directory, true),
            var_export($this->scopeKey, true),
            $proProzess,
            BreakerState::class,
            BreakerState::class,
            BreakerState::class,
        );

        $laufende = [];

        for ($i = 0; $i < $prozesse; ++$i) {
            $laufende[] = proc_open(
                \sprintf('%s -d apc.enable_cli=0 -r %s', escapeshellarg(\PHP_BINARY), escapeshellarg($skript)),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $rohre,
            );
        }

        foreach ($laufende as $prozess) {
            if (\is_resource($prozess)) {
                proc_close($prozess);
            }
        }

        // Ohne APCu liegt der Zustand in der Datei — von dort lesen, nicht über den
        // Store dieses Prozesses, der APCu benutzen könnte.
        $raw = (string) file_get_contents($this->directory.'/breaker.state');
        /** @var array{failures?: int} $decoded */
        $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(
            $prozesse * $proProzess,
            $decoded['failures'] ?? 0,
            'Jeder Fehlschlag muss zählen — sonst erreicht der Breaker die Schwelle unter Last nie',
        );
    }

    /**
     * Eine beschädigte Zustandsdatei darf den Sensor nicht mitreißen — und nicht dazu
     * führen, dass der Breaker offen zu sein behauptet.
     *
     * IM UNTERPROZESS, NICHT ÜBERSPRUNGEN
     *
     * Hier stand ein `markTestSkipped('Mit aktivem APCu wird die Datei gar nicht
     * gelesen.')`. Das war dieselbe Lücke, vor der
     * {@see testWithApcuDisabledInCliTheStateGoesToDisk()} zwei Methoden weiter oben
     * ausdrücklich warnt: „Ein Test, der sich hier überspringt, prüfte genau in der
     * Konstellation nichts, in der der Fehler steckte." Die Testumgebung aktiviert APCu in
     * der CLI (siehe `.github/workflows/ci.yml`), also übersprang sich der Test in JEDEM
     * Lauf — der Dateirückfall wurde nie gegen eine beschädigte Datei geprüft.
     *
     * Das ist nicht theoretisch: Der Rückfall ist der Pfad, den eine Installation ohne
     * APCu dauerhaft benutzt, und eine halb geschriebene `breaker.state` ist nach einem
     * abgebrochenen Deploy oder einer vollen Platte der Normalfall. Läse sie als „offen",
     * spoolte der Sensor durchgehend, obwohl der Collector läuft.
     *
     * Die Gegenprobe mit einer GÜLTIGEN Datei steht davor: Ohne sie wäre „closed" auch
     * dann grün, wenn der Unterprozess die Datei gar nicht anfasst.
     */
    public function testACorruptStateFileReadsAsClosed(): void
    {
        if (!\function_exists('apcu_enabled')) {
            self::markTestSkipped('Ohne APCu-Erweiterung gibt es die fragliche Unterscheidung nicht.');
        }

        @mkdir($this->directory, 0o775, true);
        $stateFile = $this->directory.'/breaker.state';

        file_put_contents($stateFile, (string) json_encode(
            (new BreakerState(3, microtime(true) + 30, 1))->toArray(),
            \JSON_THROW_ON_ERROR,
        ));

        self::assertSame(
            'open',
            $this->readWithoutApcu(),
            'Vorbedingung: der Dateirückfall muss überhaupt gelesen werden, sonst beweist der '
            .'zweite Teil nichts',
        );

        file_put_contents($stateFile, '{kein gueltiges json');

        self::assertSame(
            'closed',
            $this->readWithoutApcu(),
            'Eine unlesbare Zustandsdatei muss als geschlossen gelten — „offen" hieße: dauerhaft '
            .'spoolen, obwohl der Collector läuft',
        );
    }

    /**
     * Liest den Zustand in einem Unterprozess OHNE speicherndes APCu.
     *
     * Dieser Prozess hat APCu und läse von dort statt von der Platte — der Rückfall wäre
     * unerreichbar. Dieselbe Vorkehrung wie in
     * {@see testWithApcuDisabledInCliTheStateGoesToDisk()}.
     */
    private function readWithoutApcu(): string
    {
        $skript = \sprintf(
            'require %s; $s = new %s(%s, %s); echo $s->read()->isOpenAt(microtime(true)) ? "open" : "closed";',
            var_export(__DIR__.'/../../../../../vendor/autoload.php', true),
            SharedStateStore::class,
            var_export($this->directory, true),
            var_export($this->scopeKey, true),
        );

        exec(\sprintf(
            '%s -d apc.enabled=1 -d apc.enable_cli=0 -r %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($skript),
        ), $ausgabe, $rueckgabe);

        self::assertSame(0, $rueckgabe, 'Der Unterprozess ist gescheitert: '.implode("\n", $ausgabe));

        return implode('', $ausgabe);
    }

    /**
     * Je application_id ein eigener Zustand: zwei Anwendungen in einem geteilten
     * APCu-Segment dürfen sich nicht gegenseitig abschalten.
     */
    public function testTheScopeKeySeparatesApplications(): void
    {
        $this->store()->write(new BreakerState(5, microtime(true) + 30, 1));

        $andere = new SharedStateStore($this->directory, $this->scopeKey.'-andere');

        // Der Dateirückfall teilt sich eine Datei je Verzeichnis; getrennt wird dort über
        // das Verzeichnis. Geprüft wird deshalb der APCu-Weg, und nur dort.
        if (!\function_exists('apcu_enabled') || !@apcu_enabled()) {
            self::markTestSkipped('Ohne APCu trennt das Verzeichnis, nicht der Schlüssel.');
        }

        self::assertSame(0, $andere->read()->failures);
    }

    private function store(): SharedStateStore
    {
        return new SharedStateStore($this->directory, $this->scopeKey);
    }
}
