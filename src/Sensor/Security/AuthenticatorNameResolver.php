<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Security;

/**
 * Übersetzt eine Authenticator-Klasse in den Kurznamen aus Konzept 3.1.2.
 *
 * Das Konzept nennt dort `form_login`, `api_token` und `json_login` als Beispiele — also
 * die Namen, unter denen die Anwendung sie konfiguriert, nicht die FQCN.
 *
 * Zwei Feinheiten:
 *
 *  - Im Debug-Modus ist der Authenticator in einen TraceableAuthenticator gewickelt.
 *    Symfony 7.3 entpackt ihn in LoginFailureEvent selbst, Symfony 6.4 NICHT. Der
 *    Resolver muss beides vertragen, sonst steht in Produktion `form_login` und in der
 *    Entwicklung `traceable`.
 *  - `api_token` aus dem Konzeptbeispiel existiert in Symfony nicht als Klasse; das
 *    Gegenstück heißt AccessTokenAuthenticator. Eigene Authenticator-Klassen werden
 *    über die Namenskonvention abgeleitet, weshalb `App\Security\ApiTokenAuthenticator`
 *    tatsächlich zu `api_token` wird.
 *
 * @internal
 */
final class AuthenticatorNameResolver
{
    public const MAX_LENGTH = 64;

    /**
     * Die mitgelieferten Authenticators von Symfony.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        'FormLoginAuthenticator' => 'form_login',
        'JsonLoginAuthenticator' => 'json_login',
        'HttpBasicAuthenticator' => 'http_basic',
        'HttpBasicLdapAuthenticator' => 'http_basic_ldap',
        'FormLoginLdapAuthenticator' => 'form_login_ldap',
        'JsonLoginLdapAuthenticator' => 'json_login_ldap',
        'X509Authenticator' => 'x509',
        'RemoteUserAuthenticator' => 'remote_user',
        'LoginLinkAuthenticator' => 'login_link',
        'RememberMeAuthenticator' => 'remember_me',
        'AccessTokenAuthenticator' => 'access_token',
    ];

    private const TRACEABLE = 'Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator';

    public function resolve(?object $authenticator): ?string
    {
        if (null === $authenticator) {
            return null;
        }

        $authenticator = $this->unwrap($authenticator);
        $short = (new \ReflectionClass($authenticator))->getShortName();

        if (isset(self::KNOWN[$short])) {
            return self::KNOWN[$short];
        }

        return $this->fromClassName($short);
    }

    /**
     * Entpackt den TraceableAuthenticator des Debug-Modus.
     *
     * Ohne das stünde in der Entwicklung ein anderer Wert als in Produktion — und die
     * Golden Files wären zwischen den Umgebungen unvergleichbar.
     */
    private function unwrap(object $authenticator): object
    {
        $traceable = self::TRACEABLE;

        if (!$authenticator instanceof $traceable) {
            return $authenticator;
        }

        // Der Zugriff läuft über Reflection und nicht über einen direkten Methodenaufruf.
        // TraceableAuthenticator ist als @internal markiert: seine Signatur ist keine
        // Zusage, und der Sensor darf an einem Wrapper des Debug-Modus nicht scheitern.
        // Ein fehlendes getAuthenticator() wirft hier eine ReflectionException und führt
        // zum Wrapper selbst — ein schlechterer Name, aber kein Fehler in der Anwendung.
        try {
            $inner = (new \ReflectionMethod($authenticator, 'getAuthenticator'))->invoke($authenticator);
        } catch (\Throwable) {
            return $authenticator;
        }

        return \is_object($inner) ? $inner : $authenticator;
    }

    /**
     * `ApiTokenAuthenticator` → `api_token`.
     */
    private function fromClassName(string $short): string
    {
        $withoutSuffix = preg_replace('/Authenticator$/', '', $short) ?? $short;

        if ('' === $withoutSuffix) {
            $withoutSuffix = $short;
        }

        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $withoutSuffix));
        $snake = trim((string) preg_replace('/[^a-z0-9_]/', '_', $snake), '_');
        $snake = (string) preg_replace('/_{2,}/', '_', $snake);

        return '' === $snake ? 'unknown' : mb_substr($snake, 0, self::MAX_LENGTH);
    }
}
