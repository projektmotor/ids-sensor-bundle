<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Spool;

use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\EventFormat\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use Psr\Log\LoggerInterface;

/**
 * Leert den Spool in Richtung Broker.
 *
 * Läuft auf DEMSELBEN System wie die überwachte Anwendung — nur dort gibt es Zugriff
 * auf die Spool-Dateien — und verwendet dieselben XADD-only-Zugangsdaten wie der
 * Sensor im Request. Für den Collector ändert sich nichts, und die asymmetrische
 * Rechteverteilung aus Konzept 2. bleibt vollständig intakt.
 *
 * Als CLI-Prozess ohne Latenzbudget darf hier blockiert werden: es wartet kein Browser.
 *
 * Der Frame wird UNVERÄNDERT weitergeschickt — nicht erneut normalisiert oder
 * redigiert. Ein zweiter Redaktionsdurchlauf wäre eine zweite Gelegenheit, es falsch
 * zu machen. Gesetzt wird nur der dispatch_path, damit der Collector die Verzögerung
 * einordnen kann.
 *
 * WARUM FileSpool UND NICHT SpoolInterface
 *
 * Weil der Drainer keine Nutzung des Spools ist, sondern seine andere Hälfte.
 * {@see SpoolInterface} beschreibt die Schreibseite und verbirgt gerade, WOHIN die
 * Frames gehen; der Drainer öffnet Dateien, benennt sie um und braucht dafür
 * `pendingFiles()` und {@see FileSpool::DRAINING_SUFFIX} — beides ausdrücklich nicht
 * Teil der Schreibseite. Ein zweites Interface dafür hätte einen Implementierer und
 * einen Aufrufer und wäre damit ein Name statt einer Naht (CLAUDE.md §1.9).
 *
 * @internal
 */
final class SpoolDrainer
{
    public function __construct(
        private readonly FileSpool $spool,
        private readonly ShipperInterface $shipper,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param DispatchPath $path Recovered nach einem Broker-Ausfall, Deferred im
     *                           planmäßigen Spool-First-Betrieb (mod_php)
     *
     * @return array{files: int, frames: int, failed: int, skipped: int}
     */
    public function drain(
        int $maxFiles = 2,
        DispatchPath $path = DispatchPath::Recovered,
    ): array {
        $result = ['files' => 0, 'frames' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->spool->pendingFiles() as $file) {
            if ($result['files'] >= $maxFiles) {
                ++$result['skipped'];
                continue;
            }

            $claimed = $this->claim($file);

            if (null === $claimed) {
                // Ein anderer Prozess war schneller. Kein Fehler.
                ++$result['skipped'];
                continue;
            }

            ++$result['files'];
            $outcome = $this->drainFile($claimed, $path);
            $result['frames'] += $outcome['sent'];
            $result['failed'] += $outcome['failed'];

            if ($outcome['failed'] > 0) {
                // Beim ersten Fehlschlag abbrechen: ist der Broker weg, bringt es
                // nichts, die restlichen Dateien durchzuprobieren. Der Rest bleibt
                // liegen und wird beim nächsten Lauf erneut versucht.
                break;
            }
        }

        return $result;
    }

    /**
     * Beansprucht eine Datei durch Umbenennen. rename() ist innerhalb eines
     * Dateisystems atomar, damit kann nicht zweimal dasselbe gesendet werden.
     */
    private function claim(string $file): ?string
    {
        $claimed = $file.FileSpool::DRAINING_SUFFIX;

        return @rename($file, $claimed) ? $claimed : null;
    }

    /**
     * @return array{sent: int, failed: int}
     */
    private function drainFile(string $file, DispatchPath $path): array
    {
        $handle = @fopen($file, 'rb');

        if (false === $handle) {
            $this->logger?->error('ids_sensor: Spool-Datei {file} nicht lesbar.', ['file' => $file]);

            return ['sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;
        /** @var list<string> $remaining */
        $remaining = [];

        try {
            while (false !== ($line = fgets($handle))) {
                $line = trim($line);

                if ('' === $line) {
                    continue;
                }

                if ($failed > 0) {
                    // Nach dem ersten Fehlschlag nichts mehr versuchen, sondern den
                    // Rest aufheben.
                    $remaining[] = $line;
                    continue;
                }

                $outcome = $this->sendLine($line, $path);

                if (DrainOutcome::Sent === $outcome) {
                    ++$sent;
                    continue;
                }

                // Discarded fällt hier bewusst durch: die Zeile ist weg und wird nicht
                // aufgehoben. Nur ein erreichbarkeitsbedingter Fehlschlag hält die Datei.
                if (DrainOutcome::Retryable === $outcome) {
                    ++$failed;
                    $remaining[] = $line;
                }
            }
        } finally {
            fclose($handle);
        }

        $this->finish($file, $remaining);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Dekodiert eine Zeile und versendet sie.
     *
     * Die beiden Verwerfen-Fälle sind hier zusammengefasst, weil sie dasselbe bedeuten:
     * ein zweiter Versuch würde an derselben Stelle scheitern.
     */
    private function sendLine(string $line, DispatchPath $path): DrainOutcome
    {
        $frame = $this->decode($line);

        if (null === $frame) {
            $this->logger?->warning('ids_sensor: unlesbare Spool-Zeile verworfen.');

            return DrainOutcome::Discarded;
        }

        try {
            $this->shipper->ship($this->markPath($frame, $path));

            return DrainOutcome::Sent;
        } catch (UnshippableFrameException $e) {
            $this->logger?->warning(
                'ids_sensor: dauerhaft unversendbarer Spool-Frame verworfen: {message}',
                ['message' => $e->getMessage()],
            );

            return DrainOutcome::Discarded;
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'ids_sensor: Nachsenden aus dem Spool fehlgeschlagen: {message}',
                ['message' => $e->getMessage()],
            );

            return DrainOutcome::Retryable;
        }
    }

    /**
     * Setzt dispatch_path und die gemessene Verzögerung.
     *
     * Die Events selbst bleiben unangetastet. Nur der Umschlag lernt, auf welchem Weg
     * er gereist ist — der Collector braucht das, um zu entscheiden, ob die Events
     * noch für die Echtzeit-Regeln taugen.
     *
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function markPath(array $frame, DispatchPath $path): array
    {
        $frame['dispatch_path'] = $path->value;

        $flushedAt = $frame['flushed_at'] ?? null;

        if (\is_string($flushedAt)) {
            $timestamp = strtotime($flushedAt);

            if (false !== $timestamp) {
                $frame['spool_delay_ms'] = max(0, (int) round((microtime(true) - $timestamp) * 1000));
            }
        }

        return $frame;
    }

    /**
     * @param list<string> $remaining
     */
    private function finish(string $file, array $remaining): void
    {
        if ([] === $remaining) {
            @unlink($file);

            return;
        }

        // Rest zurückschreiben: über temporäre Datei und rename(), damit bei einem
        // Abbruch keine halbe Datei entsteht. Der Name verliert den
        // .draining-Zusatz, damit der nächste Lauf ihn wieder findet.
        $target = preg_replace('/'.preg_quote(FileSpool::DRAINING_SUFFIX, '/').'$/', '', $file) ?? $file;
        $temporary = $file.'.tmp';

        if (false !== @file_put_contents($temporary, implode("\n", $remaining)."\n")) {
            @rename($temporary, $target);
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
