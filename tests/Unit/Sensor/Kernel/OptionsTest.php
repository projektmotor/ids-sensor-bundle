<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Kernel\Options;
use ProjektMotor\IdsSensor\Sensor\Kernel\SubRequestMode;

/**
 * Die Schalter der Kernel-Ebene — insbesondere der Pfadfilter.
 *
 * `isIgnored()` ist die einzige Stelle des Bundles, an der die überwachte Anwendung
 * die Erfassung ganz abstellen kann. Ein Muster, das versehentlich zu viel trifft,
 * macht die Ebene stumm, ohne dass irgendetwas es meldet.
 */
#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    /**
     * Die Vorgabe ist leer, und das ist eine Entscheidung: Regel R2b lebt davon,
     * Zugriffe auf `/_profiler` zu SEHEN.
     */
    public function testNothingIsIgnoredByDefault(): void
    {
        $options = new Options();

        self::assertFalse($options->isIgnored('/_profiler'));
        self::assertFalse($options->isIgnored('/'));
    }

    public function testAMatchingPatternIgnoresThePath(): void
    {
        $options = new Options(ignoredPaths: ['#^/health$#']);

        self::assertTrue($options->isIgnored('/health'));
        self::assertFalse($options->isIgnored('/health/detail'), 'Der Anker gilt — sonst träfe das Muster zu viel');
    }

    public function testTheFirstMatchingPatternWins(): void
    {
        $options = new Options(ignoredPaths: ['#^/nichts#', '#^/health#']);

        self::assertTrue($options->isIgnored('/health'));
    }

    /**
     * Die Vorgabe der Sub-Request-Behandlung, an einer Stelle festgehalten.
     */
    public function testTheDefaultSubRequestModeIsExceptionsOnly(): void
    {
        $options = new Options();

        self::assertSame(SubRequestMode::ExceptionsOnly, $options->subRequests);
        self::assertFalse($options->subRequests->allowsRequestEvents());
        self::assertTrue($options->subRequests->allowsExceptionEvents());
    }
}
