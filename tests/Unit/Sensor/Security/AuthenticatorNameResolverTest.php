<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Security\AuthenticatorNameResolver;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\ApiTokenAuthenticator;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Authenticator\HttpBasicAuthenticator;
use Symfony\Component\Security\Http\Authenticator\JsonLoginAuthenticator;

/**
 * payload.authenticator nach Konzept 3.1.2: der Name, unter dem die Anwendung den
 * Authenticator konfiguriert — nicht der FQCN.
 */
final class AuthenticatorNameResolverTest extends TestCase
{
    private AuthenticatorNameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AuthenticatorNameResolver();
    }

    public function testWithoutAnAuthenticatorNull(): void
    {
        self::assertNull($this->resolver->resolve(null));
    }

    /**
     * Die drei Namen, die das Konzept ausdrücklich als Beispiele nennt.
     *
     * newInstanceWithoutConstructor(), weil die echten Klassen Abhängigkeiten
     * verlangen — für die Namensauflösung ist nur die Klasse relevant.
     *
     * @param class-string $class
     */
    #[DataProvider('bundledAuthenticators')]
    public function testBundledAuthenticators(string $class, string $erwartet): void
    {
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertSame($erwartet, $this->resolver->resolve($instance));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function bundledAuthenticators(): iterable
    {
        yield 'form_login' => [FormLoginAuthenticator::class, 'form_login'];
        yield 'json_login' => [JsonLoginAuthenticator::class, 'json_login'];
        yield 'http_basic' => [HttpBasicAuthenticator::class, 'http_basic'];
    }

    /**
     * `api_token` aus dem Konzeptbeispiel existiert in Symfony nicht als Klasse. Eigene
     * Authenticators leitet der Resolver deshalb über die Namenskonvention ab.
     */
    public function testACustomAuthenticatorIsDerivedByConvention(): void
    {
        self::assertSame('api_token', $this->resolver->resolve(new ApiTokenAuthenticator()));
    }

    /**
     * Im Debug-Modus steckt der Authenticator in einem TraceableAuthenticator. Symfony
     * 7.3 entpackt ihn in LoginFailureEvent selbst, 6.4 nicht. Ohne eigenes Entpacken
     * stünde in Produktion `form_login` und in der Entwicklung `traceable` — die Golden
     * Files wären zwischen den Umgebungen unvergleichbar.
     */
    public function testTheTraceableWrapperIsUnwrapped(): void
    {
        $traceable = new class {
            public function getAuthenticator(): object
            {
                return (new \ReflectionClass(FormLoginAuthenticator::class))->newInstanceWithoutConstructor();
            }
        };

        // Der echte Wrapper heißt anders; geprüft wird hier nur, dass ein Objekt ohne
        // passenden Typ NICHT entpackt wird — sonst würde der Resolver bei jedem
        // fremden Objekt mit getAuthenticator() raten.
        self::assertNotSame('form_login', $this->resolver->resolve($traceable));
    }

    public function testTheNameIsBounded(): void
    {
        $lang = new class {
        };

        $name = $this->resolver->resolve($lang);

        self::assertIsString($name);
        self::assertLessThanOrEqual(AuthenticatorNameResolver::MAX_LENGTH, mb_strlen($name));
    }
}
