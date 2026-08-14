<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\SeverityResolver;

/**
 * Prüft die Ableitungstabelle aus Konzept 2.2.1 Zeile für Zeile.
 */
final class SeverityResolverTest extends TestCase
{
    /**
     * Jede Zeile der Konzepttabelle bekommt einen eigenen Datensatz. Die Namen der
     * Datensätze entsprechen den Tabellenzeilen, damit der Abgleich mit dem Konzept
     * mechanisch möglich bleibt — siehe
     * {@see testEveryRowOfTheConceptTableIsCovered()}.
     *
     * @return iterable<string, array{string, int|null, Severity}>
     */
    public static function kernelProvider(): iterable
    {
        yield 'kernel.request | immer | info' => ['kernel.request', null, Severity::Info];
        yield 'kernel.exception | 500-599 | critical' => ['kernel.exception', 500, Severity::Critical];
        yield 'kernel.exception | 400-499 | warning' => ['kernel.exception', 404, Severity::Warning];
        yield 'kernel.exception | sonst | info' => ['kernel.exception', 200, Severity::Info];
        yield 'kernel.response | 500-599 | critical' => ['kernel.response', 503, Severity::Critical];
        yield 'kernel.response | 401/403/404/429 | warning' => ['kernel.response', 403, Severity::Warning];
        yield 'kernel.response | uebrige 4xx | info' => ['kernel.response', 418, Severity::Info];
        yield 'kernel.response | 2xx/3xx | info' => ['kernel.response', 200, Severity::Info];
    }

    #[DataProvider('kernelProvider')]
    public function testKernelDerivation(string $eventType, ?int $status, Severity $expected): void
    {
        self::assertSame($expected, (new SeverityResolver())->forKernel($eventType, $status));
    }

    /**
     * Sicherung gegen stilles Auseinanderlaufen von Konzept und Umsetzung: kommt im
     * Konzept eine Zeile hinzu, muss auch der Datensatz dafür entstehen.
     */
    public function testEveryRowOfTheConceptTableIsCovered(): void
    {
        $expectedRows = [
            'kernel.request | immer | info',
            'kernel.exception | 500-599 | critical',
            'kernel.exception | 400-499 | warning',
            'kernel.exception | sonst | info',
            'kernel.response | 500-599 | critical',
            'kernel.response | 401/403/404/429 | warning',
            'kernel.response | uebrige 4xx | info',
            'kernel.response | 2xx/3xx | info',
        ];

        $covered = array_keys(iterator_to_array(self::kernelProvider()));

        self::assertSame($expectedRows, $covered);
    }

    /**
     * Die Reihenfolge in der Umsetzung ist wesentlich: die vier ausgezeichneten
     * 4xx-Codes müssen VOR dem allgemeinen 4xx-Zweig geprüft werden. Andernfalls
     * würden 403 und 404 als info eingestuft — und damit verlöre die
     * Scanning-Erkennung ihr wichtigstes Signal, weil Regel B1 auf gehäuften
     * 403/404-Antworten aufbaut.
     */
    #[DataProvider('responseWarningStatusProvider')]
    public function testDistinguished4xxCodesAreCheckedBeforeTheGeneralBranch(int $status): void
    {
        self::assertSame(
            Severity::Warning,
            (new SeverityResolver())->forKernel('kernel.response', $status),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function responseWarningStatusProvider(): iterable
    {
        foreach (SeverityResolver::RESPONSE_WARNING_STATUSES as $status) {
            yield (string) $status => [$status];
        }
    }

    /**
     * critical ist laut Konzept 2.2.1 ausschließlich Serverfehlern vorbehalten —
     * nicht abgelehnten oder nicht gefundenen Zugriffen.
     */
    public function testCriticalOnlyForServerErrors(): void
    {
        $resolver = new SeverityResolver();

        foreach ([400, 401, 403, 404, 418, 429, 200, 302] as $status) {
            self::assertNotSame(
                Severity::Critical,
                $resolver->forKernel('kernel.response', $status),
                \sprintf('Status %d darf nicht critical sein', $status),
            );
            self::assertNotSame(
                Severity::Critical,
                $resolver->forKernel('kernel.exception', $status),
                \sprintf('Status %d darf nicht critical sein', $status),
            );
        }
    }

    #[DataProvider('securityProvider')]
    public function testSecurityDerivation(string $eventType, ?string $decision, Severity $expected): void
    {
        self::assertSame($expected, (new SeverityResolver())->forSecurity($eventType, $decision));
    }

    /**
     * @return iterable<string, array{string, string|null, Severity}>
     */
    public static function securityProvider(): iterable
    {
        yield 'authentication.success | immer | info' => ['security.authentication.success', null, Severity::Info];
        yield 'authentication.failure | immer | warning' => ['security.authentication.failure', null, Severity::Warning];
        yield 'access_decision | granted | info' => ['security.access_decision', 'granted', Severity::Info];
        yield 'access_decision | denied | warning' => ['security.access_decision', 'denied', Severity::Warning];
    }

    public function testBusinessHintIsAdoptedDirectly(): void
    {
        $resolver = new SeverityResolver();

        self::assertSame(Severity::Info, $resolver->forBusiness('info'));
        self::assertSame(Severity::Warning, $resolver->forBusiness('warning'));
        self::assertSame(Severity::Critical, $resolver->forBusiness('critical'));
    }

    /**
     * Ein unbrauchbarer Hint darf keine Exception auslösen — er ist durch einen
     * Tippfehler der Anwendung erreichbar, und fail-open verbietet, die überwachte
     * Anwendung dafür zu bestrafen.
     */
    public function testAnUnknownBusinessHintIsClassifiedAsWarning(): void
    {
        self::assertSame(Severity::Warning, (new SeverityResolver())->forBusiness('kritisch'));
        self::assertSame(Severity::Warning, (new SeverityResolver())->forBusiness(''));
        self::assertSame(Severity::Warning, (new SeverityResolver())->forBusiness('CRITICAL'));
    }

    /**
     * Nicht info: sonst verschöbe ein Tippfehler der Anwendung das Event still in
     * die 30-Tage-Retention (Konzept 4.2.3) und verkürzte damit heimlich die
     * Aufbewahrung eines sicherheitsrelevanten Vorgangs.
     */
    public function testAnUnknownHintDoesNotLandInTheShortRetention(): void
    {
        self::assertNotSame(Severity::Info, (new SeverityResolver())->forBusiness('unfug'));
    }

    public function testAnUnknownEventTypeFallsBackToInfo(): void
    {
        $resolver = new SeverityResolver();

        self::assertSame(Severity::Info, $resolver->forKernel('kernel.terminate', 500));
        self::assertSame(Severity::Info, $resolver->forSecurity('security.irgendwas'));
    }
}
