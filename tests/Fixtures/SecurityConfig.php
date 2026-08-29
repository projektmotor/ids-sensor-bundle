<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Die Security-Konfiguration der Tests an einer Stelle.
 *
 * http_basic statt form_login, weil der Fokus auf den Sensoren liegt und nicht auf einem
 * Login-Formular: HttpBasicAuthenticator läuft durch denselben AuthenticatorManager und
 * löst damit dieselben LoginSuccessEvent/LoginFailureEvent aus, braucht aber keine
 * Session, keine Login-Route und keinen Redirect.
 */
final class SecurityConfig
{
    public const USER = 'alice';
    public const ADMIN = 'admin';
    public const PASSWORD = 'geheim';

    /**
     * @return array<string, mixed>
     */
    public static function basic(): array
    {
        return [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => [
                'test_users' => [
                    'memory' => [
                        'users' => [
                            self::USER => ['password' => self::PASSWORD, 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'provider' => 'test_users',
                    'stateless' => true,
                    'http_basic' => true,
                ],
            ],
        ];
    }

    /**
     * Wie basic(), aber ZUSTANDSBEHAFTET.
     *
     * Nötig für alles, was mit dem Session-Usage-Index zu tun hat: Symfonys
     * UsageTrackingTokenStorage zählt den Zugriff nur, wenn die Firewall zustandsbehaftet
     * ist — bei `stateless: true` schaltet der ContextListener die Zählung gar nicht erst
     * ein. Ein Test gegen basic() wäre dort also grün, ohne etwas zu belegen.
     *
     * @return array<string, mixed>
     */
    public static function stateful(): array
    {
        $config = self::basic();
        unset($config['firewalls']['main']['stateless']);

        return $config;
    }

    /**
     * Wie basic(), zusätzlich ein Administrator und die Rechteübernahme.
     *
     * Der zweite Nutzer ist nötig, weil eine Übernahme zwei Identitäten braucht: den
     * Übernehmenden und den Übernommenen. `ROLE_ALLOWED_TO_SWITCH` ist die Rolle, die
     * Symfonys SwitchUserListener per Vorgabe verlangt.
     *
     * Baut auf {@see stateful()} und nicht auf {@see basic()}: Das VERLASSEN der Übernahme
     * setzt voraus, dass der SwitchUserToken den Request überlebt hat — er trägt den
     * ursprünglichen Token in sich, und ohne ihn findet der Listener keinen, zu dem er
     * zurückkehren könnte. Bei einer zustandslosen Firewall meldete sich der
     * Administrator in jedem Request neu an, und `_switch_user=_exit` liefe in eine
     * AuthenticationCredentialsNotFoundException.
     *
     * @return array<string, mixed>
     */
    public static function withSwitchUser(): array
    {
        $config = self::stateful();
        $config['providers']['test_users']['memory']['users'][self::ADMIN] = [
            'password' => self::PASSWORD,
            'roles' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
        ];
        $config['firewalls']['main']['switch_user'] = true;

        return $config;
    }

    /**
     * Wie basic(), zusätzlich eine access_control-Regel.
     *
     * Deckt den Pfad ab, für den der AuthorizationChecker-Ansatz untauglich wäre:
     * Symfonys AccessListener ruft decide() direkt am Manager auf.
     *
     * @return array<string, mixed>
     */
    public static function withAccessControl(): array
    {
        $config = self::basic();
        $config['access_control'] = [
            ['path' => '^/nur-fuer-admins', 'roles' => 'ROLE_ADMIN'],
        ];

        return $config;
    }
}
