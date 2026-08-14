<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

use Psr\Log\LoggerInterface;

/**
 * Legt Frames als JSON Lines auf die Platte, eine Zeile je Frame.
 *
 * Zwei Verwendungen:
 *  - Broker nicht erreichbar oder Circuit Breaker offen (Ausnahmefall)
 *  - Laufzeiten ohne abkoppelbare Antwort, also mod_php (Regelfall dort)
 *
 * EINE DATEI PRO PROZESS, nicht eine gemeinsame. Damit ist kein Verlass auf die
 * Atomizität von O_APPEND nötig — die gilt für kleine Schreibvorgänge, ein Frame kann
 * aber mehrere Kilobyte haben — und es entsteht keine Sperrkonkurrenz im Schreibpfad.
 * Unter threaded MPM mit ZTS-PHP kommt die Thread-Kennung dazu, sonst würden mehrere
 * Threads eines Prozesses in dieselbe Datei schreiben.
 *
 * KEIN fsync. Der Spool ist eine Zwischenablage, keine Buchhaltung; ein Stromausfall
 * darf Events kosten. Ein fsync pro Request wäre der teuerste Teil des ganzen Sensors.
 *
 * Die Größe wird mitgeführt statt bei jedem Schreibvorgang per glob() und filesize()
 * neu ermittelt — letzteres wären zwei Dateisystemzugriffe pro Request, nur um eine
 * Obergrenze zu prüfen.
 *
 * @internal
 */
final class FileSpool implements SpoolInterface
{
    public const FILE_PREFIX = 'frames-';

    public const FILE_SUFFIX = '.jsonl';

    public const DRAINING_SUFFIX = '.draining';

    /** Nach so vielen Schreibvorgängen wird die mitgeführte Größe nachgerechnet. */
    private const RECOUNT_INTERVAL = 256;

    private ?int $trackedBytes = null;

    private int $writesSinceRecount = 0;

    private int $discardedFull = 0;

    private int $discardedUnwritable = 0;

    private int $spooled = 0;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = 16777216,
        private readonly int $maxFileBytes = 4194304,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function append(array $frame): bool
    {
        $line = json_encode(
            $frame,
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if (false === $line) {
            ++$this->discardedFull;

            return false;
        }

        $line .= "\n";
        $length = \strlen($line);

        $this->refreshByteCount();

        if (!$this->hasRoomFor($length)) {
            // Konzept 4. IdsBackendBundle: „Ist der Puffer voll, werden weitere Events
            // VERWORFEN statt gepuffert — unbegrenztes Puffern würde den Plattenplatz
            // der Anwendung erschöpfen und aus einer IDS-Störung einen
            // Anwendungsausfall machen."
            ++$this->discardedFull;
            $this->logger?->warning(
                'ids_sensor: Spool ist voll ({bytes} von {max} Byte), Frame verworfen.',
                ['bytes' => $this->trackedBytes ?? 0, 'max' => $this->maxBytes],
            );

            return false;
        }

        if (!$this->ensureDirectory()) {
            ++$this->discardedUnwritable;

            return false;
        }

        $written = @file_put_contents($this->currentFile(), $line, \FILE_APPEND);

        if (false === $written) {
            ++$this->discardedUnwritable;
            $this->logger?->error(
                'ids_sensor: Spool-Verzeichnis {dir} ist nicht beschreibbar, Frame verworfen.',
                ['dir' => $this->directory],
            );

            return false;
        }

        $this->trackedBytes = ($this->trackedBytes ?? 0) + $written;
        ++$this->spooled;
        ++$this->writesSinceRecount;

        return true;
    }

    /**
     * @return list<string> vollständige Pfade der abholbereiten Dateien, älteste zuerst
     */
    public function pendingFiles(): array
    {
        $pattern = $this->directory.'/'.self::FILE_PREFIX.'*'.self::FILE_SUFFIX;
        $files = glob($pattern);

        if (false === $files) {
            return [];
        }

        // Älteste zuerst: bei einer Obergrenze pro Lauf sollen die ältesten Events
        // zuerst abfließen, sonst verhungern sie.
        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        return $files;
    }

    public function currentFile(): string
    {
        $base = \sprintf(
            '%s/%s%d%s',
            $this->directory,
            self::FILE_PREFIX,
            getmypid() ?: 0,
            $this->threadSuffix(),
        );

        // Rotation: eine zu große Einzeldatei erschwert das Abholen und macht einen
        // Teilfehler teuer.
        $candidate = $base.self::FILE_SUFFIX;

        if (is_file($candidate) && (int) @filesize($candidate) >= $this->maxFileBytes) {
            return \sprintf('%s-%d%s', $base, time(), self::FILE_SUFFIX);
        }

        return $candidate;
    }

    public function sizeInBytes(): int
    {
        return $this->trackedBytes ??= $this->recount();
    }

    public function discardedFull(): int
    {
        return $this->discardedFull;
    }

    public function discardedUnwritable(): int
    {
        return $this->discardedUnwritable;
    }

    public function spooledFrames(): int
    {
        return $this->spooled;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Rechnet den Belegungsstand nach, wenn er fehlt oder veraltet ist.
     *
     * Getrennt von {@see hasRoomFor()}, weil eine Methode entweder Zustand ändert oder
     * eine Auskunft gibt — eine Frage mit Seiteneffekt ist die Sorte Überraschung, die
     * beim nächsten Leser Zeit kostet.
     */
    private function refreshByteCount(): void
    {
        // Kaltstart oder fällige Nachrechnung: die mitgeführte Zahl kann von anderen
        // Prozessen unterlaufen sein.
        if (null === $this->trackedBytes || $this->writesSinceRecount >= self::RECOUNT_INTERVAL) {
            $this->trackedBytes = $this->recount();
            $this->writesSinceRecount = 0;
        }
    }

    private function hasRoomFor(int $length): bool
    {
        return ($this->trackedBytes ?? 0) + $length <= $this->maxBytes;
    }

    private function recount(): int
    {
        $pattern = $this->directory.'/'.self::FILE_PREFIX.'*';
        $files = glob($pattern);

        if (false === $files) {
            return 0;
        }

        $total = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            $total += false === $size ? 0 : $size;
        }

        return $total;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        return @mkdir($this->directory, 0o775, true) || is_dir($this->directory);
    }

    /**
     * Unter ZTS-PHP (threaded MPM) teilen mehrere Threads eine PID. Ohne eigene Datei
     * je Thread schrieben sie in dieselbe und die Zeilen könnten sich verschränken.
     */
    private function threadSuffix(): string
    {
        if (!\ZEND_THREAD_SAFE) {
            return '';
        }

        return '-t'.(\function_exists('zend_thread_id') ? (string) zend_thread_id() : substr(hash('crc32b', spl_object_hash($this)), 0, 8));
    }
}
