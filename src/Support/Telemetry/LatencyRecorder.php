<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Telemetry;

/**
 * Hält die beiden Laufzeit-Histogramme des Sensors.
 *
 * Warum zwei getrennte: Konzept 2.1 Sensorik nennt 5 ms Antwortzeit, Konzept
 * 4. IdsBackendBundle nennt 50 ms Versand-Timeout. Beide messen verschiedene
 * Dinge und dürfen nicht in einen Topf:
 *
 *  - in_request_overhead_us: die Zeit, für die der Client tatsächlich wartet.
 *    Das ist die Zahl, an der die 5-ms-Zusage hängt.
 *  - dispatch_ms: Normalisieren, Redigieren und Versenden nach dem Absenden der
 *    Antwort. Kostet keine Antwortzeit, sondern Belegung des Worker-Prozesses.
 *
 * Beide gehen in den Heartbeat. Damit ist die 5-ms-Zusage im laufenden Betrieb
 * überprüfbar und nicht nur in einem Benchmark — ein A/B-Lasttest wäre hier das
 * schwächere Verfahren, weil das p99 eines ganzen Requests stärker rauscht als die
 * zu messende Größe.
 *
 * @internal
 */
final class LatencyRecorder
{
    private Histogram $inRequestOverheadUs;

    private Histogram $dispatchMs;

    public function __construct(
        private readonly bool $enabled = true,
    ) {
        $this->inRequestOverheadUs = new Histogram();
        $this->dispatchMs = new Histogram();
    }

    /**
     * @param int $nanoseconds gemessen per hrtime(true)
     */
    public function recordCapture(int $nanoseconds): void
    {
        if ($this->enabled) {
            $this->inRequestOverheadUs->record(intdiv($nanoseconds, 1000));
        }
    }

    /**
     * @param int $nanoseconds gemessen per hrtime(true)
     */
    public function recordDispatch(int $nanoseconds): void
    {
        if ($this->enabled) {
            $this->dispatchMs->record(intdiv($nanoseconds, 1_000_000));
        }
    }

    public function inRequestOverheadUs(): Histogram
    {
        return $this->inRequestOverheadUs;
    }

    public function dispatchMs(): Histogram
    {
        return $this->dispatchMs;
    }

    /**
     * @return array{in_request_overhead_us: array{count: int, sum: int, max: int, p50: int, p90: int, p99: int}, dispatch_ms: array{count: int, sum: int, max: int, p50: int, p90: int, p99: int}}
     */
    public function snapshot(): array
    {
        return [
            'in_request_overhead_us' => $this->inRequestOverheadUs->snapshot(),
            'dispatch_ms' => $this->dispatchMs->snapshot(),
        ];
    }
}
