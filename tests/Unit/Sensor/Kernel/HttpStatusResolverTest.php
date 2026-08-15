<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Kernel;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\SeverityResolver;
use ProjektMotor\IdsSensor\Sensor\Kernel\HttpStatusResolver;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class HttpStatusResolverTest extends TestCase
{
    public function testAnHttpExceptionReportsItsOwnStatus(): void
    {
        $resolver = new HttpStatusResolver();

        self::assertSame(404, $resolver->resolve(new NotFoundHttpException()));
        self::assertSame(403, $resolver->resolve(new AccessDeniedHttpException()));
    }

    /**
     * Der eigentliche Grund für diese Klasse. Der Kernel-Sensor hört
     * kernel.exception bei Priorität 1024 ab, der Security-ExceptionListener wandelt
     * AccessDeniedException aber erst bei Priorität 1 in eine
     * AccessDeniedHttpException um. Wir sehen also die rohe Exception, die kein
     * HttpExceptionInterface implementiert.
     *
     * Ohne diese Auflösung wäre ein 403 ein 500 und damit event_severity = critical
     * — also ein gemeldeter Serverfehler, obwohl nur ein Zugriff abgelehnt wurde.
     */
    public function testARawAccessDeniedExceptionBecomes403(): void
    {
        self::assertSame(403, (new HttpStatusResolver())->resolve(new AccessDeniedException()));
    }

    public function testAuthenticationExceptionBecomes401(): void
    {
        $resolver = new HttpStatusResolver();

        self::assertSame(401, $resolver->resolve(new AuthenticationException()));
        self::assertSame(401, $resolver->resolve(new BadCredentialsException()));
    }

    public function testARequestExceptionBecomes400(): void
    {
        self::assertSame(400, (new HttpStatusResolver())->resolve(new BadRequestException()));
    }

    public function testAnUnknownExceptionBecomes500(): void
    {
        self::assertSame(
            HttpStatusResolver::FALLBACK_STATUS,
            (new HttpStatusResolver())->resolve(new \RuntimeException('kaputt')),
        );
    }

    /**
     * Verpackte Exceptions müssen genauso eingestuft werden wie Symfony sie selbst
     * einstuft — dessen ExceptionListener läuft ebenfalls die getPrevious()-Kette ab.
     */
    public function testTheChainIsTraversed(): void
    {
        $wrapped = new \RuntimeException('Wrapper', 0, new AccessDeniedException());

        self::assertSame(403, (new HttpStatusResolver())->resolve($wrapped));
    }

    public function testOutermostMatchWins(): void
    {
        $exception = new NotFoundHttpException('nicht gefunden', new AccessDeniedException());

        self::assertSame(404, (new HttpStatusResolver())->resolve($exception));
    }

    /**
     * Die Tiefenbegrenzung verhindert Endlosläufe und begrenzt die Kosten. Liegt der
     * Treffer jenseits der Grenze, ist 500 die Antwort.
     */
    public function testChainDepthIsBounded(): void
    {
        $exception = new AccessDeniedException();
        for ($i = 0; $i < HttpStatusResolver::MAX_CHAIN_DEPTH + 1; ++$i) {
            $exception = new \RuntimeException('Schicht '.$i, 0, $exception);
        }

        self::assertSame(
            HttpStatusResolver::FALLBACK_STATUS,
            (new HttpStatusResolver())->resolve($exception),
        );
    }

    /**
     * Das Zusammenspiel, auf das es ankommt: eine rohe AccessDeniedException muss
     * als warning ankommen, nicht als critical. critical ist laut Konzept 2.2.1
     * ausschließlich Serverfehlern vorbehalten.
     */
    public function testDeniedAccessIsWarningNotCritical(): void
    {
        $status = (new HttpStatusResolver())->resolve(new AccessDeniedException());
        $severity = (new SeverityResolver())->forKernel('kernel.exception', $status);

        self::assertSame(Severity::Warning, $severity);
    }

    public function testARealServerErrorStaysCritical(): void
    {
        $status = (new HttpStatusResolver())->resolve(new \RuntimeException('Datenbank weg'));
        $severity = (new SeverityResolver())->forKernel('kernel.exception', $status);

        self::assertSame(Severity::Critical, $severity);
    }
}
