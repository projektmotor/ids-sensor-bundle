<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Telemetry;

/**
 * Ein Histogramm mit logarithmisch gestuften Klassen (Zweierpotenzen).
 *
 * Zweck: die eigene Laufzeit des Sensors dauerhaft messbar machen, ohne
 * Einzelwerte zu speichern. Eine Messung kostet einen Bit-Shift-Loop und eine
 * Array-Inkrementierung — Größenordnung 40 ns.
 *
 * Warum kein Speichern aller Werte: Der Sensor läuft in jedem Request einer
 * Produktivanwendung. Eine Liste würde unbegrenzt wachsen; ein Histogramm mit 25
 * Klassen ist konstant groß und lässt sich im Heartbeat mitschicken.
 *
 * Perzentile sind damit klassenscharf, nicht exakt — sie werden auf die
 * Obergrenze der Klasse gerundet, in der das Perzentil liegt. Für die Frage
 * „bleiben wir unter 5 ms?" ist das ausreichend; für exakte Werte wäre ein
 * anderes Verfahren nötig, das sich im Request-Pfad nicht rechnet.
 *
 * @internal
 */
final class Histogram
{
    /**
     * 25 Klassen: [0,0], [1,1], [2,3], [4,7] … und als letzte [2^23, ∞).
     *
     * Der berichtete Perzentilwert ist die Obergrenze der Klasse, also höchstens
     * 2^24 − 1 ≈ 16,8 Millionen — bei Mikrosekunden rund 16,8 Sekunden. Gekappt wird
     * zusätzlich am beobachteten Maximum, siehe {@see percentile()}.
     */
    public const BUCKET_COUNT = 25;

    /** @var array<int, int> */
    private array $buckets = [];

    private int $count = 0;

    private int $sum = 0;

    private int $max = 0;

    public function record(int $value): void
    {
        if ($value < 0) {
            $value = 0;
        }

        ++$this->count;
        $this->sum += $value;

        if ($value > $this->max) {
            $this->max = $value;
        }

        $index = self::bucketIndex($value);
        $this->buckets[$index] = ($this->buckets[$index] ?? 0) + 1;
    }

    /**
     * Klassenindex: 0 für 0, sonst 1 + floor(log2(v)).
     * So landet 1 in Klasse 1, 2–3 in Klasse 2, 4–7 in Klasse 3 usw.
     */
    public static function bucketIndex(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $index = 1;
        while ($value > 1 && $index < self::BUCKET_COUNT - 1) {
            $value >>= 1;
            ++$index;
        }

        return $index;
    }

    /**
     * Einschließende Obergrenze der Klasse — der berichtete Perzentilwert.
     *
     * Klasse i (i >= 1) umfasst [2^(i-1), 2^i - 1]:
     *   Klasse 1 -> [1, 1], Klasse 2 -> [2, 3], Klasse 3 -> [4, 7], …
     *
     * Die letzte Klasse ist ein Sammelbecken für alle größeren Werte; ihre
     * nominelle Obergrenze wird durch die Kappung am beobachteten Maximum in
     * {@see percentile()} korrigiert.
     */
    public static function bucketUpperBound(int $index): int
    {
        if ($index <= 0) {
            return 0;
        }

        return (1 << $index) - 1;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function sum(): int
    {
        return $this->sum;
    }

    public function max(): int
    {
        return $this->max;
    }

    /**
     * @param float $percentile zwischen 0 und 1, z. B. 0.99
     */
    public function percentile(float $percentile): int
    {
        if (0 === $this->count) {
            return 0;
        }

        $percentile = max(0.0, min(1.0, $percentile));
        $target = (int) ceil($this->count * $percentile);
        if ($target < 1) {
            $target = 1;
        }

        $cumulative = 0;
        for ($i = 0; $i < self::BUCKET_COUNT; ++$i) {
            $cumulative += $this->buckets[$i] ?? 0;
            if ($cumulative >= $target) {
                // Die Klassenobergrenze kann den beobachteten Maximalwert
                // übersteigen; dann ist das Maximum die ehrlichere Auskunft.
                return min(self::bucketUpperBound($i), $this->max);
            }
        }

        return $this->max;
    }

    /**
     * @return array{count: int, sum: int, max: int, p50: int, p90: int, p99: int}
     */
    public function snapshot(): array
    {
        return [
            'count' => $this->count,
            'sum' => $this->sum,
            'max' => $this->max,
            'p50' => $this->percentile(0.5),
            'p90' => $this->percentile(0.9),
            'p99' => $this->percentile(0.99),
        ];
    }

    public function reset(): void
    {
        $this->buckets = [];
        $this->count = 0;
        $this->sum = 0;
        $this->max = 0;
    }
}
