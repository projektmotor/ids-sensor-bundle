<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Tests\Fixtures\ThrowingLogger;
use Psr\Log\AbstractLogger;

/**
 * Der teuerste Fehler überhaupt — und der unauffälligste.
 *
 * Wird Produktionsverkehr fälschlich als `dev` markiert, fällt er aus JEDER
 * Aggregation der Produktionsregeln heraus (Konzept 2.2.1). Der Sensor arbeitet,
 * meldet, zählt — und die Erkennung ist trotzdem blind. Deshalb ist der Rückfall
 * `prod` und nicht `dev`: Fälschlich als prod markierter Verkehr wird weiterhin
 * erkannt, nur seine Baseline ist leicht verunreinigt.
 */
#[CoversClass(EnvironmentResolver::class)]
final class EnvironmentResolverTest extends TestCase
{
    #[DataProvider('bekannteSchreibweisen')]
    public function testCommonSpellingsAreMapped(string $konfiguriert, Environment $erwartet): void
    {
        self::assertSame($erwartet, (new EnvironmentResolver($konfiguriert))->resolve());
    }

    /**
     * @return iterable<string, array{string, Environment}>
     */
    public static function bekannteSchreibweisen(): iterable
    {
        yield 'prod' => ['prod', Environment::Prod];
        yield 'production' => ['production', Environment::Prod];
        yield 'live' => ['live', Environment::Prod];
        yield 'staging' => ['staging', Environment::Staging];
        yield 'preprod' => ['preprod', Environment::Staging];
        yield 'development' => ['development', Environment::Dev];
        yield 'test' => ['test', Environment::Dev];
        yield 'Großschreibung' => ['PROD', Environment::Prod];
        yield 'Leerraum' => ['  prod  ', Environment::Prod];
    }

    /**
     * Ein unbekannter Wert wird zu `prod` — der Richtung, die die Erkennung am Leben
     * hält.
     */
    public function testAnUnknownValueFallsBackToProdAndWarns(): void
    {
        $logger = new SammelnderLogger();
        $resolver = new EnvironmentResolver('abnahme', logger: $logger);

        self::assertSame(Environment::Prod, $resolver->resolve());
        self::assertCount(1, $logger->meldungen);
        self::assertStringContainsString('abnahme', $logger->meldungen[0]);
    }

    /**
     * Gewarnt wird EINMAL pro Prozess, nicht pro Auflösung.
     *
     * In einer Worker-Laufzeit stünde die Meldung sonst in jedem Request — und eine
     * Meldung, die ununterbrochen kommt, liest niemand mehr.
     */
    public function testTheWarningIsEmittedOnlyOnce(): void
    {
        $logger = new SammelnderLogger();
        $resolver = new EnvironmentResolver('abnahme', logger: $logger);

        $resolver->resolve();
        $resolver->resolve();

        self::assertCount(1, $logger->meldungen);
    }

    /**
     * Der Rückfall ist konfigurierbar — für Betreiber, die den umgekehrten Kompromiss
     * bewusst wählen.
     */
    public function testTheFallbackIsConfigurable(): void
    {
        self::assertSame(
            Environment::Staging,
            (new EnvironmentResolver('abnahme', fallback: Environment::Staging))->resolve(),
        );
    }

    /**
     * `isResolvable()` trennt „läuft mit Rückfall" von „ist richtig konfiguriert".
     *
     * Im Request-Pfad ist der Rückfall richtig, im Deploy-Check nicht: Dort bricht
     * `ids:sensor:setup-check` mit Exit-Code ab, bevor die verunreinigte Baseline
     * entsteht.
     */
    public function testResolvableIsSeparateFromResolve(): void
    {
        self::assertTrue((new EnvironmentResolver('production'))->isResolvable());
        self::assertFalse((new EnvironmentResolver('abnahme'))->isResolvable());
    }

    /**
     * Ein werfender Logger darf die Auflösung nicht kosten.
     *
     * Der Resolver läuft im Request-Pfad, und fail-open gilt ohne Ausnahme
     * (Konzept 4.). Ein Monolog-Handler, der auf ein volles Dateisystem schreibt, ist
     * der realistische Fall.
     */
    public function testAThrowingLoggerDoesNotCostTheResolution(): void
    {
        self::assertSame(
            Environment::Prod,
            (new EnvironmentResolver('abnahme', logger: new ThrowingLogger()))->resolve(),
        );
    }
}

/**
 * Sammelt Meldungen, statt sie zu schreiben.
 */
final class SammelnderLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $meldungen = [];

    /**
     * Untypisierte Parameter — siehe `FailSafeLogger::log()`.
     *
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $text = (string) $message;

        foreach ($context as $schluessel => $wert) {
            if (\is_scalar($wert)) {
                $text = str_replace('{'.$schluessel.'}', (string) $wert, $text);
            }
        }

        $this->meldungen[] = $text;
    }
}
