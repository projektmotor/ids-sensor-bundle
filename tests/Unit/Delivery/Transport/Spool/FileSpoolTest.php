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

        $files = $spool->pendingFiles();
        self::assertCount(1, $files, 'Eine Datei pro Prozess');
        self::assertCount(2, file($files[0], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function testEveryLineIsSelfContainedJson(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('a'));
        $spool->append($this->frame('b'));

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
     * Der Drain schickt den Frame UNVERÄNDERT weiter und setzt nur den dispatch_path.
     * Ein zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es falsch zu
     * machen.
     */
    public function testDrainResendsAndMarksThePath(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('a'));
        $spool->append($this->frame('b'));

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
        $spool = $this->spool();
        $spool->append($this->frame('vergiftet'));
        $spool->append($this->frame('danach'));

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
        self::assertSame(1, $result['frames'], 'Der Frame DAHINTER kommt durch');
        self::assertSame('danach', $shipper->angekommen[0]['events'][0]['payload']['marker']);
        self::assertSame([], $spool->pendingFiles(), 'Die Datei bleibt nicht liegen');
    }

    /**
     * Der planmäßige Weg unter mod_php: begrenzt verzögert, für die Echtzeit-Regeln
     * weiterhin brauchbar. Ohne diese Unterscheidung wäre dort die Echtzeit-Erkennung
     * dauerhaft abgeschaltet.
     */
    public function testDeferredMarksScheduledDispatch(): void
    {
        $spool = $this->spool();
        $spool->append($this->frame('a'));

        $shipper = new CollectingShipper();
        (new SpoolDrainer($spool, $shipper))->drain(2, DispatchPath::Deferred);

        $frame = $shipper->lastFrame();
        self::assertNotNull($frame);
        self::assertSame(DispatchPath::Deferred->value, $frame['dispatch_path']);
    }

    public function testDrainSetsTheMeasuredDelay(): void
    {
        $spool = $this->spool();
        $spool->append(array_merge(
            $this->frame('a'),
            ['flushed_at' => (new \DateTimeImmutable('-5 seconds'))->format('Y-m-d\TH:i:s.v\Z')],
        ));

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
        $spool = $this->spool();
        $spool->append($this->frame('a'));

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
        $spool = $this->spool();
        $spool->append($this->frame('a'));
        $spool->append($this->frame('b'));
        $spool->append($this->frame('c'));

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
        $spool = $this->spool();
        $spool->append($this->frame('a'));
        file_put_contents($spool->pendingFiles()[0], "kein-json\n", \FILE_APPEND);

        $shipper = new CollectingShipper();
        $result = (new SpoolDrainer($spool, $shipper))->drain();

        self::assertSame(1, $result['frames']);
        self::assertSame([], $spool->pendingFiles());
    }

    public function testAnEmptySpoolYieldsNothing(): void
    {
        $result = (new SpoolDrainer($this->spool(), new CollectingShipper()))->drain();

        self::assertSame(['files' => 0, 'frames' => 0, 'failed' => 0, 'skipped' => 0], $result);
    }

    private function spool(): FileSpool
    {
        return new FileSpool($this->directory);
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
