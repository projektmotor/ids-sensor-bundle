<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport\Spool;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolDrainer;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Tests\Fixtures\CollectingShipper;

final class FileSpoolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ids-spool-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testWritesOneLinePerFrame(): void
    {
        $spool = $this->spool();

        self::assertTrue($spool->append($this->frame('a')));
        self::assertTrue($spool->append($this->frame('b')));
        $spool->seal();

        $files = $spool->pendingFiles();
        self::assertCount(1, $files, 'Eine Datei pro Prozess');
        self::assertCount(2, file($files[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function testEveryLineIsSelfContainedJson(): void
    {
        $spool = $this->filled($this->spool(), 'a', 'b');

        $lines = file($spool->pendingFiles()[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 64, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
        }
    }

    /**
     * Konzept 4. IdsBackendBundle: „Ist der Puffer voll, werden weitere Events
     * VERWORFEN statt gepuffert — unbegrenztes Puffern würde den Plattenplatz der
     * Anwendung erschöpfen und aus einer IDS-Störung einen Anwendungsausfall machen.".
     */
    public function testDiscardsAtTheUpperLimitAndCountsThat(): void
    {
        // Grenze aus der tatsächlichen Frame-Größe ableiten, damit der Test bei
        // Änderungen an der Frame-Struktur weiter aussagekräftig bleibt.
        $frameSize = \strlen((string) json_encode($this->frame('mustermessung'))) + 1;
        $limit = $frameSize * 3;

        $spool = new FileSpool($this->directory, maxBytes: $limit);

        $angenommen = 0;
        for ($i = 0; $i < 50; ++$i) {
            if ($spool->append($this->frame('event-'.$i))) {
                ++$angenommen;
            }
        }

        self::assertGreaterThanOrEqual(2, $angenommen, 'Am Anfang muss etwas angenommen werden');
        self::assertLessThan(50, $angenommen, 'Die Obergrenze muss greifen');
        self::assertGreaterThan(0, $spool->discardedFull());
        self::assertLessThanOrEqual(
            $limit,
            $spool->sizeInBytes(),
            'Die Obergrenze darf nicht überschritten werden — sonst könnte der Spool den Plattenplatz der Anwendung erschöpfen',
        );
    }

    public function testAnUnwritableDirectoryIsCountedInsteadOfThrowing(): void
    {
        // Eine Datei dort, wo das Verzeichnis sein müsste: mkdir scheitert.
        $blocker = sys_get_temp_dir().'/ids-spool-blocker-'.bin2hex(random_bytes(4));
        file_put_contents($blocker, 'x');

        try {
            $spool = new FileSpool($blocker.'/unterverzeichnis');

            self::assertFalse($spool->append($this->frame('a')));
            self::assertSame(1, $spool->discardedUnwritable());
        } finally {
            @unlink($blocker);
        }
    }

    /**
     * Der zweite Weg in dieselbe Zahl: das Verzeichnis existiert, ist aber nur lesbar.
     *
     * Der praktisch häufigere Fall — ein `var/`-Verzeichnis, das dem Webserver-Nutzer
     * nicht gehört. `ensureDirectory()` gelingt dann, und erst `file_put_contents()`
     * scheitert. Geprüft war bislang nur der andere Zweig.
     */
    public function testAReadOnlyDirectoryIsCountedInsteadOfThrowing(): void
    {
        mkdir($this->directory, 0o700, true);

        if (!chmod($this->directory, 0o500)) {
            self::markTestSkipped('Rechte lassen sich in dieser Umgebung nicht setzen');
        }

        try {
            $spool = $this->spool();

            self::assertFalse($spool->append($this->frame('a')), 'Ein Fehlschlag wird gemeldet, nicht geworfen');
            self::assertSame(1, $spool->discardedUnwritable(), 'Und er wird gezählt — Konzept 4.');
            self::assertSame(0, $spool->discardedFull(), 'Nicht als „voll": der Betreiber vergrößerte sonst die Platte');
        } finally {
            chmod($this->directory, 0o700);
        }
    }

    /**
     * Das Alter der ältesten wartenden Datei — Konzept 3.4 nennt es als die EINZIGE
     * Außenansicht eines nicht laufenden Drains.
     *
     * Es zählt jede wartende Datei, auch die noch aktive: Sonst meldete der Heartbeat
     * bei geringer Last dauerhaft „leer", obwohl Frames auf der Platte liegen.
     */
    public function testTheAgeOfTheOldestWaitingFileIsReported(): void
    {
        $spool = $this->spool();

        self::assertNull($spool->oldestWaitingAgeSeconds(), 'Ein leerer Spool hat kein Alter');

        $spool->append($this->frame('a'));

        self::assertSame(0, $spool->oldestWaitingAgeSeconds(), 'Frisch geschrieben — und trotzdem gezählt');
    }

    /**
     * Der Drain schickt den Frame UNVERÄNDERT weiter und setzt nur den dispatch_path.
     * Ein zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es falsch zu
     * machen.
     */
    public function testDrainResendsAndMarksThePath(): void
    {
        $spool = $this->filled($this->spool(), 'a', 'b');

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(2, $result['frames']);
        self::assertSame(2, $shipper->frameCount());
        self::assertSame(DispatchPath::Recovered->value, $shipper->frames()[0]['dispatch_path']);
        self::assertSame('a', $shipper->frames()[0]['events'][0]['payload']['marker']);
        self::assertSame([], $spool->pendingFiles(), 'Nach erfolgreichem Drain ist die Datei weg');
    }

    /**
     * Ein dauerhaft unversendbarer Frame darf die Datei nicht festhalten.
     *
     * Nach dem ersten erreichbarkeitsbedingten Fehlschlag bricht der Drainer ab und hebt
     * den GESAMTEN Rest auf — richtig bei einem Broker-Ausfall, fatal bei einem Frame,
     * der aus sich heraus nie durchgeht: eine einzelne vergiftete Zeile blockierte sonst
     * den Spool auf Dauer. UnshippableFrameException ist genau diese Unterscheidung.
     */
    public function testAnUnshippableFrameDoesNotBlockTheSpool(): void
    {
        $spool = $this->filled($this->spool(), 'vergiftet', 'danach');

        $shipper = new class implements ShipperInterface {
            /** @var list<array<string, mixed>> */
            public array $angekommen = [];

            /**
             * @param array<string, mixed> $frame
             */
            public function ship(array $frame): void
            {
                if ('vergiftet' === ($frame['events'][0]['payload']['marker'] ?? null)) {
                    throw new UnshippableFrameException('nicht kodierbar');
                }

                $this->angekommen[] = $frame;
            }

            /**
             * @param array<string, mixed> $payload
             */
            public function shipHeartbeat(array $payload): void
            {
            }
        };

        $result = (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(0, $result['failed'], 'Der vergiftete Frame ist kein Fehlschlag, sondern ein Verlust');
        self::assertSame(1, $result['discarded'], 'Aber ein GEZÄHLTER Verlust');
        self::assertSame(1, $result['frames'], 'Der Frame DAHINTER kommt durch');
        self::assertSame('danach', $shipper->angekommen[0]['events'][0]['payload']['marker']);
        self::assertSame([], $spool->pendingFiles(), 'Die Datei bleibt nicht liegen');
    }

    /**
     * Eine Zeile ohne `events` darf nicht als „nachgesendet" verschwinden.
     *
     * `MessengerShipper::ship()` kehrte bei fehlendem `events`-Feld still zurück. Der
     * Drainer wertete das als Erfolg, zählte den Frame als gesendet und LÖSCHTE die
     * Zeile; im Direktpfad erhöhte der FrameDispatcher sogar `sent`. Ein am Ende
     * abgeschnittener Spool-Eintrag — bei einem Stromausfall ohne fsync erwartbar, siehe
     * den Klassenkopf — verschwand damit als Erfolg gemeldet.
     *
     * Geworfen wird jetzt `UnshippableFrameException`: Der Drainer unterscheidet sie
     * schon immer vom Broker-Ausfall, verwirft die Zeile statt sie ewig zu wiederholen,
     * und der Verlust steht wenigstens im Protokoll.
     */
    public function testAFrameWithoutEventsIsNotCountedAsSent(): void
    {
        $spool = $this->spool();
        $frame = $this->frame('a');
        unset($frame['events']);
        $spool->append($frame);
        $spool->seal();

        $shipper = new class implements ShipperInterface {
            public int $versuche = 0;

            /**
             * @param array<string, mixed> $frame
             */
            public function ship(array $frame): void
            {
                ++$this->versuche;

                if (!\is_array($frame['events'] ?? null)) {
                    throw new UnshippableFrameException('kein events-Feld');
                }
            }

            /**
             * @param array<string, mixed> $payload
             */
            public function shipHeartbeat(array $payload): void
            {
            }
        };

        $result = (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(1, $shipper->versuche, 'Der Versand wird versucht');
        self::assertSame(0, $result['frames'], 'Aber NICHT als gesendet gezählt');
        self::assertSame(0, $result['failed'], 'Und auch kein Broker-Fehlschlag');
    }

    /**
     * DER Test für das Rennen zwischen Schreiber und Drainer.
     *
     * Vorher bestand der Dateiname nur aus PID und Thread-Zusatz und war damit für jeden
     * anderen Prozess rekonstruierbar. Der Drainer beanspruchte genau die Datei, in die
     * gerade geschrieben wurde; der Schreiber legte sie unter demselben Namen neu an, und
     * das abschließende `rename()` des Drainers überschrieb sie wortlos — mitsamt allem,
     * was während des ganzen Laufs erfasst worden war. `rename()` meldet das nicht einmal.
     *
     * Zwei Instanzen bilden zwei Prozesse so nah nach, wie es ohne zweiten Prozess geht:
     * Sie teilen das Verzeichnis, aber keinen Objektzustand — und vor allem keine Kennung.
     */
    public function testWritingDuringADrainLosesNothing(): void
    {
        $schreiber = $this->spool();
        $drainerSpool = $this->spool();

        // Der Schreiber hat einen Frame abgelegt und ist danach verstummt — genau der
        // Zustand, in dem der Drainer stellvertretend versiegelt und die Datei ÜBERNIMMT,
        // obwohl der Schreiber noch lebt.
        $schreiber->append($this->frame('vor-dem-drain'));

        foreach ($schreiber->waitingFiles() as $file) {
            touch($file, time() - 600);
        }

        $shipper = new CollectingShipper();
        (new SpoolDrainer($drainerSpool, $shipper, null, staleAfterSeconds: 300))->drain();

        self::assertSame(1, $shipper->frameCount(), 'Der übernommene Stapel geht raus');

        // Der Schreiber merkt davon nichts und hängt weiter an — unter SEINEM Namen, den
        // kein anderer Prozess vergibt. Die Zeile darf nicht im Nichts landen.
        $schreiber->append($this->frame('nach-der-uebernahme'));
        $schreiber->seal();

        $uebrig = $schreiber->pendingFiles();
        self::assertCount(1, $uebrig, 'Der Nachzügler muss auf der Platte liegen');

        $lines = file($uebrig[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $lines, 'Genau die eine Zeile, nichts überschrieben');
        self::assertStringContainsString('nach-der-uebernahme', $lines[0]);
    }

    /**
     * Eine aktive Datei, an die niemand mehr schreibt, muss trotzdem abfließen.
     *
     * Sonst hätte das Versiegeln ein Loch: Es passiert erst beim NÄCHSTEN Anhang. Ein
     * Prozess, der einen Frame erfasst und dann keinen Verkehr mehr bekommt — bei
     * geringer Last der Normalfall —, ließe ihn für immer liegen.
     */
    public function testAnIdleActiveFileIsSealedByTheDrainer(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('vergessen-im-leerlauf'));

        self::assertSame([], $spool->pendingFiles(), 'Aktiv heißt: noch nicht abholbar');

        // Der Prozess ist verstummt.
        foreach ($spool->waitingFiles() as $file) {
            touch($file, time() - 600);
        }

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer($spool, $shipper, null, staleAfterSeconds: 300))->drain();

        self::assertSame(1, $result['frames'], 'Der Drainer muss stellvertretend versiegeln');
        self::assertSame(
            'vergessen-im-leerlauf',
            $shipper->lastFrame()['events'][0]['payload']['marker'] ?? null,
        );
    }

    /**
     * Das stellvertretende Versiegeln folgt dem DRAIN-INTERVALL, nicht `stale_after_s`.
     *
     * Hier stand `stale_after_s` (Vorgabe 300 s), und das brach die Zusage, die derselbe
     * Umbau gab: Konzept 3.3.1 sagt für `deferred` „höchstens ein Drain-Intervall" zu und
     * empfiehlt dem Collector als Toleranz das Zweifache des gemeldeten
     * `drain_interval_s`, also 60 s. Ein Frame, der 300 s auf seine Versiegelung wartet,
     * kommt mit `spool_delay_ms ≈ 300 000` an und wird collectorseitig wie `recovered`
     * behandelt — KEINE Echtzeit-Regeln.
     *
     * Unter mod_php, wo der Spool der Regelweg ist, traf das jede Installation mit
     * geringer Last: also genau den Ausfall, gegen den es die drei Zustände aus 3.3.1
     * überhaupt gibt.
     */
    public function testAnIdleActiveFileIsSealedAfterOneDrainIntervalNotAfterStale(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('leiser-prozess'));

        // Älter als ein Drain-Intervall, aber weit unter stale_after_s.
        foreach ($spool->waitingFiles() as $file) {
            touch($file, time() - 45);
        }

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer(
            $spool,
            $shipper,
            null,
            staleAfterSeconds: 300,
            sealIdleAfterSeconds: 30,
        ))->drain();

        self::assertSame(1, $result['frames'], 'Nach einem Drain-Intervall muss der Frame abfließen');
        self::assertSame(
            'leiser-prozess',
            $shipper->lastFrame()['events'][0]['payload']['marker'] ?? null,
        );
    }

    /**
     * Innerhalb des Intervalls bleibt der Schreiber in Ruhe.
     *
     * Sonst würde der Drainer bei jedem Lauf eine Datei versiegeln, an die gerade
     * angehängt wird — funktional harmlos (der nächste Anhang legt sie neu an), aber es
     * erzeugte eine Datei je Drain-Lauf statt je Intervall.
     */
    public function testAFreshActiveFileIsLeftAlone(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('gerade-eben'));

        $result = (new SpoolDrainer(
            $spool,
            new CollectingShipper(),
            null,
            staleAfterSeconds: 300,
            sealIdleAfterSeconds: 30,
        ))->drain();

        self::assertSame(0, $result['frames']);
        self::assertSame([], $spool->pendingFiles(), 'Die Datei bleibt aktiv');
    }

    /**
     * Eine liegengebliebene `.draining`-Datei muss zurückkommen.
     *
     * `claim()` benennt vor dem Senden um. Stirbt der Prozess danach — SIGKILL, OOM,
     * Deploy, cron-Timeout —, war die Datei bisher für immer verloren: `pendingFiles()`
     * findet sie nicht, ihre Bytes zählen über `recount()` aber weiter gegen
     * `spool.max_bytes`. Der Spool lief also voll und meldete dabei „leer" — Heartbeat
     * und `setup-check` fragen beide `pendingFiles()`. Konzept 3.4 nennt
     * `oldest_pending_age_s` ausdrücklich als die einzige Stelle, an der ein nicht
     * laufender Drain von außen sichtbar wird.
     */
    public function testAStalledClaimIsReclaimedAndSentOnTheNextRun(): void
    {
        $spool = $this->filled($this->spool(), 'vergessen');

        // Ein abgestürzter Drain-Lauf: beansprucht, aber nie beendet.
        $file = $spool->pendingFiles()[0];
        $stalled = $file.FileSpool::DRAINING_SUFFIX;
        rename($file, $stalled);
        touch($stalled, time() - 600);

        self::assertSame([], $spool->pendingFiles(), 'Beansprucht heißt: für den Regelweg unsichtbar');
        self::assertGreaterThan(0, $spool->sizeInBytes(), 'Die Bytes belegen den Spool trotzdem');

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer($spool, $shipper, null, staleAfterSeconds: 300))->drain();

        self::assertSame(1, $result['frames'], 'Die zurückgeholte Datei muss nachgesendet werden');
        self::assertSame(
            'vergessen',
            $shipper->lastFrame()['events'][0]['payload']['marker'] ?? null,
        );
        self::assertSame([], $spool->stalledFiles(0), 'Danach liegt nichts mehr beansprucht herum');
    }

    /**
     * Ein LAUFENDER Drain-Lauf darf nicht bestohlen werden.
     */
    public function testAFreshClaimIsLeftAlone(): void
    {
        $spool = $this->filled($this->spool(), 'gerade-in-arbeit');

        $file = $spool->pendingFiles()[0];
        $claimed = $file.FileSpool::DRAINING_SUFFIX;
        rename($file, $claimed);

        $result = (new SpoolDrainer($spool, new CollectingShipper(), null, staleAfterSeconds: 300))->drain();

        self::assertSame(0, $result['frames']);
        self::assertFileExists($claimed, 'Die Datei bleibt beim laufenden Drain-Prozess');
        self::assertFileDoesNotExist($file, 'Und wird nicht zurückbenannt');
    }

    /**
     * Nach einem Drain-Lauf muss derselbe Prozess wieder schreiben können.
     *
     * Der mitgeführte Byte-Zähler wurde nur im Erfolgsfall fortgeschrieben. Erreichte ein
     * Prozess die Obergrenze, fror er damit ein und `refreshByteCount()` rechnete nie
     * wieder nach — der Prozess verwarf jeden weiteren Frame bis zu seinem Ende, auch
     * nachdem der Drain das Verzeichnis vollständig geleert hatte. Unter mod_php, wo der
     * Spool der Regelweg ist, war das der vollständige Ausfall dieses Kindprozesses.
     */
    public function testAfterDrainingTheSameInstanceCanWriteAgain(): void
    {
        // Gerade groß genug für einen Frame, nicht für zwei.
        $spool = new FileSpool($this->directory, maxBytes: \strlen($this->line('a')) + 10);

        self::assertTrue($spool->append($this->frame('a')));
        self::assertFalse($spool->append($this->frame('b')), 'Der zweite Frame passt nicht mehr');
        self::assertSame(1, $spool->discardedFull());
        $spool->seal();

        (new SpoolDrainer($spool, new CollectingShipper()))->drain();
        self::assertSame([], $spool->pendingFiles(), 'Der Drain hat geleert');

        self::assertTrue(
            $spool->append($this->frame('c')),
            'Nach dem Leeren muss derselbe Spool wieder aufnehmen',
        );
        self::assertSame(1, $spool->discardedFull(), 'Und nichts zusätzlich verwerfen');
    }

    /**
     * Der planmäßige Weg unter mod_php bleibt `deferred` — ohne jedes Zutun von außen.
     *
     * DAS ist der Fall, an dem die Echtzeit-Erkennung einer mod_php-Installation hängt.
     * Dort schreibt der Sensor jeden Frame planmäßig als `deferred` in den Spool, und der
     * dokumentierte cron-Eintrag (doc/05-versandweg.md) übergibt dem Drainer keinerlei
     * Angabe. Überschrieb er den Wert mit seiner Vorgabe `recovered`, nahm der Collector
     * laut Konzept 3.3.1 jeden dieser Frames von den Regeln R1–R7 aus — die Erkennung
     * war dort dauerhaft aus, ohne dass irgendetwas fehlschlug.
     */
    public function testAScheduledlySpooledFrameStaysDeferred(): void
    {
        $spool = $this->spool();
        $spool->append(array_merge($this->frame('a'), ['dispatch_path' => DispatchPath::Deferred->value]));
        $spool->seal();

        $shipper = new CollectingShipper();
        (new SpoolDrainer($spool, $shipper))->drain();

        $frame = $shipper->lastFrame();
        self::assertNotNull($frame);
        self::assertSame(
            DispatchPath::Deferred->value,
            $frame['dispatch_path'],
            'Ein planmäßig gespoolter Frame darf nicht zum Nachlauf herabgestuft werden',
        );
    }

    /**
     * Umgekehrt: ein Frame, der direkt gehen sollte und im Spool landete, ist Nachlauf
     * nach einer Störung. Nur er wird `recovered`.
     */
    public function testAFrameThatFailedToShipBecomesRecovered(): void
    {
        $spool = $this->filled($this->spool(), 'a');

        $shipper = new CollectingShipper();
        (new SpoolDrainer($spool, $shipper))->drain();

        $frame = $shipper->lastFrame();
        self::assertNotNull($frame);
        self::assertSame(DispatchPath::Recovered->value, $frame['dispatch_path']);
    }

    public function testDrainSetsTheMeasuredDelay(): void
    {
        $spool = $this->spool();
        $spool->append(array_merge(
            $this->frame('a'),
            ['flushed_at' => (new \DateTimeImmutable('-5 seconds'))->format('Y-m-d\TH:i:s.v\Z')],
        ));
        $spool->seal();

        $shipper = new CollectingShipper();
        (new SpoolDrainer($spool, $shipper))->drain();

        $frame = $shipper->lastFrame();
        self::assertNotNull($frame);
        $delay = $frame['spool_delay_ms'] ?? 0;
        self::assertGreaterThanOrEqual(4000, $delay);
        self::assertLessThan(20000, $delay);
    }

    /**
     * Scheitert der Versand, bleibt die Datei liegen und wird beim nächsten Lauf
     * erneut versucht — nichts darf verloren gehen.
     */
    public function testAFailedDrainLeavesTheFileBehind(): void
    {
        $spool = $this->filled($this->spool(), 'a');

        $result = (new SpoolDrainer($spool, new CollectingShipper(new \RuntimeException('Redis weg'))))->drain();

        self::assertSame(0, $result['frames']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $spool->pendingFiles(), 'Die Datei muss für den nächsten Lauf erhalten bleiben');
    }

    /**
     * Bricht der Versand mitten in einer Datei ab, dürfen die bereits gesendeten
     * Frames nicht erneut gesendet und die übrigen nicht verloren werden.
     */
    public function testAPartialDrainKeepsOnlyTheRemainder(): void
    {
        $spool = $this->filled($this->spool(), 'a', 'b', 'c');

        $shipper = new FailAfterFirstShipper();
        (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(1, $shipper->shipped, 'Der erste Frame ging durch');

        $remaining = $spool->pendingFiles();
        self::assertCount(1, $remaining);
        $lines = file($remaining[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(2, $lines, 'Genau die zwei nicht gesendeten Frames bleiben übrig');
    }

    public function testAnUnreadableLineIsDiscardedInsteadOfRetriedForever(): void
    {
        $spool = $this->filled($this->spool(), 'a');
        file_put_contents($spool->pendingFiles()[0], "kein-json\n", \FILE_APPEND);

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(1, $result['frames']);
        self::assertSame([], $spool->pendingFiles());
    }

    public function testAnEmptySpoolYieldsNothing(): void
    {
        $result = (new SpoolDrainer($this->spool(), new CollectingShipper()))->drain();

        self::assertSame(['files' => 0, 'frames' => 0, 'failed' => 0, 'skipped' => 0, 'discarded' => 0], $result);
    }

    private function spool(): FileSpool
    {
        return new FileSpool($this->directory);
    }

    /**
     * Schreibt die Frames und versiegelt.
     *
     * Seit der Trennung in aktive und versiegelte Dateien ist frisch Geschriebenes für
     * `pendingFiles()` und den Drainer bewusst unsichtbar — genau das löst das Rennen
     * zwischen Schreiber und Drainer auf. Ein Test, der abholen will, muss also erst
     * versiegeln, so wie es in Produktion die Größen- oder Altersschranke bzw. der
     * Drainer über `idleActiveFiles()` tut.
     */
    private function filled(FileSpool $spool, string ...$markers): FileSpool
    {
        foreach ($markers as $marker) {
            $spool->append($this->frame($marker));
        }

        $spool->seal();

        return $spool;
    }

    /**
     * Die Zeile, die {@see FileSpool::append()} für diesen Frame schreiben würde —
     * inklusive des abschließenden Zeilenumbruchs.
     */
    private function line(string $marker): string
    {
        return json_encode(
            $this->frame($marker),
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(string $marker): array
    {
        return [
            'v' => 1,
            'sensor' => ['application_id' => 'shop-api', 'instance_id' => 'web-03'],
            'flushed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'dispatch_path' => 'direct',
            'spool_delay_ms' => 0,
            'counters' => [],
            'events' => [['event_type' => 'kernel.request', 'payload' => ['marker' => $marker]]],
        ];
    }
}

/**
 * Lässt den ersten Frame durch und scheitert danach.
 */
final class FailAfterFirstShipper implements ShipperInterface
{
    public int $shipped = 0;

    public function ship(array $frame): void
    {
        if ($this->shipped > 0) {
            throw new \RuntimeException('Redis weg');
        }

        ++$this->shipped;
    }

    public function shipHeartbeat(array $payload): void
    {
        // Der Drainer versendet nie Heartbeats — hier nur, weil das Interface es verlangt.
    }
}
