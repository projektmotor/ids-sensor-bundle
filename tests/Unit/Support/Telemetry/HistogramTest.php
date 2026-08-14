<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Telemetry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\Telemetry\Histogram;

final class HistogramTest extends TestCase
{
    #[DataProvider('bucketProvider')]
    public function testBucketAssignment(int $value, int $expectedIndex): void
    {
        self::assertSame($expectedIndex, Histogram::bucketIndex($value));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function bucketProvider(): iterable
    {
        yield 'null' => [0, 0];
        yield 'eins' => [1, 1];
        yield 'zwei' => [2, 2];
        yield 'drei' => [3, 2];
        yield 'vier' => [4, 3];
        yield 'sieben' => [7, 3];
        yield 'acht' => [8, 4];
        yield 'negativ landet in der Nullklasse' => [-5, 0];
    }

    /**
     * Klasse i umfasst [2^(i-1), 2^i - 1] — die Obergrenze ist einschließend.
     */
    public function testBucketUpperBounds(): void
    {
        self::assertSame(0, Histogram::bucketUpperBound(0));
        self::assertSame(1, Histogram::bucketUpperBound(1));
        self::assertSame(3, Histogram::bucketUpperBound(2));
        self::assertSame(7, Histogram::bucketUpperBound(3));
        self::assertSame(63, Histogram::bucketUpperBound(6));
    }

    /**
     * Jeder Wert muss innerhalb der Grenzen seiner eigenen Klasse liegen — sonst
     * wären die berichteten Perzentile systematisch falsch.
     */
    public function testAValueAlwaysLiesWithinItsBucketBounds(): void
    {
        foreach ([1, 2, 3, 4, 7, 8, 42, 63, 512, 1000, 1023, 1024] as $value) {
            $index = Histogram::bucketIndex($value);

            self::assertLessThanOrEqual(
                Histogram::bucketUpperBound($index),
                $value,
                \sprintf('Wert %d liegt über der Obergrenze seiner Klasse %d', $value, $index),
            );
        }
    }

    public function testLargeValuesLandInTheLastBucketInsteadOfOverflowing(): void
    {
        $index = Histogram::bucketIndex(\PHP_INT_MAX);

        self::assertSame(Histogram::BUCKET_COUNT - 1, $index);
    }

    public function testCountsSumAndMaximum(): void
    {
        $histogram = new Histogram();
        $histogram->record(10);
        $histogram->record(20);
        $histogram->record(5);

        self::assertSame(3, $histogram->count());
        self::assertSame(35, $histogram->sum());
        self::assertSame(20, $histogram->max());
    }

    public function testAnEmptyHistogramReturnsZeros(): void
    {
        $histogram = new Histogram();

        self::assertSame(0, $histogram->count());
        self::assertSame(0, $histogram->percentile(0.99));
        self::assertSame(
            ['count' => 0, 'sum' => 0, 'max' => 0, 'p50' => 0, 'p90' => 0, 'p99' => 0],
            $histogram->snapshot(),
        );
    }

    /**
     * Perzentile sind klassenscharf und werden zusätzlich am beobachteten Maximum
     * gekappt — eine Klassenobergrenze oberhalb des größten gemessenen Wertes wäre
     * eine irreführende Auskunft.
     */
    public function testThePercentileIsCappedAtTheMaximum(): void
    {
        $histogram = new Histogram();
        for ($i = 0; $i < 100; ++$i) {
            $histogram->record(3);
        }

        self::assertSame(3, $histogram->percentile(0.99));
        self::assertSame(3, $histogram->max());
    }

    public function testAHighPercentileFindsTheOutlier(): void
    {
        $histogram = new Histogram();
        for ($i = 0; $i < 99; ++$i) {
            $histogram->record(1);
        }
        $histogram->record(1000);

        self::assertSame(1, $histogram->percentile(0.5));
        self::assertSame(1000, $histogram->percentile(1.0));
    }

    public function testPercentileArgumentsAreClamped(): void
    {
        $histogram = new Histogram();
        $histogram->record(42);

        self::assertSame(42, $histogram->percentile(-1.0));
        self::assertSame(42, $histogram->percentile(5.0));
    }

    public function testResetClearsEverything(): void
    {
        $histogram = new Histogram();
        $histogram->record(42);

        $histogram->reset();

        self::assertSame(0, $histogram->count());
        self::assertSame(0, $histogram->max());
    }
}
