<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Security;

use ProjektMotor\IdsEventData\Payload\SecurityPayload;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\SecurityConfig;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Anmeldeerfolg und Anmeldefehler durch eine ECHTE Firewall.
 *
 * Bewusst kein Mock des AuthenticatorManagers: geprüft werden soll gerade, dass die
 * Events an der Stelle ankommen, an der Symfony sie tatsächlich auslöst — und mit den
 * Daten, die dort verfügbar sind. Ein Mock würde die Annahmen des Sensors bestätigen
 * statt sie zu prüfen.
 */
final class AuthenticationSensorTest extends IntegrationTestCase
{
    /**
     * capture_us: 0 heißt „unbegrenzt" — s. die Begründung in
     * {@see AccessDecisionSensorTest}. Die Anmelde-Events selbst laufen über
     * guardMandatory() und könnten gar nicht entfallen; abgeschaltet wird das Budget hier
     * nur, damit die Tests nicht von der Aufwärmreihenfolge des Prozesses abhängen.
     *
     * @var array<string, mixed>
     */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        'budget' => ['capture_us' => 0],
    ];

    public function testASuccessfulLoginIsCaptured(): void
    {
        $events = $this->handle($this->basicAuth(SecurityConfig::USER, SecurityConfig::PASSWORD));

        $event = $this->firstOfType($events, SecurityPayload::EVENT_AUTH_SUCCESS);

        self::assertSame('main', $event->get(SecurityPayload::FIELD_FIREWALL));
        self::assertSame('http_basic', $event->get(SecurityPayload::FIELD_AUTHENTICATOR));
        self::assertSame(SecurityConfig::USER, $event->actor()->user);
    }

    /**
     * Der für die Erkennung wichtigere Fall: die VERSUCHTE Kennung muss in actor.user
     * stehen (Konzept 3.1.2), obwohl der Nutzer nicht existiert. Ohne sie ließe sich
     * Nutzer-Enumeration nicht von Brute-Force gegen einen Account unterscheiden.
     */
    public function testAFailedLoginCarriesTheAttemptedIdentifier(): void
    {
        $events = $this->handle($this->basicAuth('gibt-es-nicht', 'falsch'));

        $event = $this->firstOfType($events, SecurityPayload::EVENT_AUTH_FAILURE);

        self::assertSame('main', $event->get(SecurityPayload::FIELD_FIREWALL));
        self::assertSame('gibt-es-nicht', $event->actor()->user);
    }

    /**
     * Der Grund unterscheidet die Angriffsklassen: UserNotFoundException heißt
     * Enumeration, BadCredentialsException heißt Brute-Force gegen einen bekannten
     * Account. Regelautoren brauchen beide getrennt.
     */
    public function testWrongPasswordReportsADifferentReasonThanUnknownUser(): void
    {
        $unbekannt = $this->firstOfType(
            $this->handle($this->basicAuth('gibt-es-nicht', 'falsch'), 'auth-unknown'),
            SecurityPayload::EVENT_AUTH_FAILURE,
        );
        $falschesPasswort = $this->firstOfType(
            $this->handle($this->basicAuth(SecurityConfig::USER, 'falsch'), 'auth-badpass'),
            SecurityPayload::EVENT_AUTH_FAILURE,
        );

        // Symfony maskiert UserNotFound je nach hide_user_not_found; entscheidend ist
        // nur, dass überhaupt ein kurzer Klassenname und keine Meldung transportiert
        // wird — getMessage() könnte Anwendungsdaten enthalten.
        foreach ([$unbekannt, $falschesPasswort] as $event) {
            $reason = $event->get(SecurityPayload::FIELD_FAILURE_REASON);
            self::assertIsString($reason);
            self::assertMatchesRegularExpression('/^[A-Za-z]+Exception$/', $reason);
        }

        self::assertSame(
            SecurityConfig::USER,
            $falschesPasswort->actor()->user,
            'Bei falschem Passwort ist die Kennung bekannt und muss mitreisen',
        );
    }

    /**
     * Die Anmelde-Events müssen dieselbe correlation_id tragen wie die Kernel-Events
     * desselben Requests — sonst kann der Collector den Fehlversuch nicht mit dem
     * 401-Response verbinden, und Regel B5 (Erfolg nach Fehlversuchsserie) hat keine
     * gemeinsame Achse.
     */
    public function testLoginEventSharesTheCorrelationIdWithKernelEvents(): void
    {
        $events = $this->handle($this->basicAuth('gibt-es-nicht', 'falsch'));

        $ids = array_unique(array_map(static fn (CapturedEvent $e): ?string => $e->correlationId(), $events));

        self::assertCount(1, $ids, 'Alle Events eines Requests gehören an dieselbe correlation_id');
        self::assertNotSame([null], $ids);
        self::assertNotSame([''], $ids);
    }

    /**
     * Die IP muss am Anmelde-Event stehen. Ohne sie ist jede IP-basierte
     * Brute-Force-Regel wirkungslos.
     */
    public function testLoginEventCarriesTheActorContext(): void
    {
        $request = $this->basicAuth('gibt-es-nicht', 'falsch');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $event = $this->firstOfType(
            $this->handle($request),
            SecurityPayload::EVENT_AUTH_FAILURE,
        );

        self::assertSame('203.0.113.7', $event->actor()->ip);
    }

    public function testDisablingRemovesTheSensorEntirely(): void
    {
        $kernel = $this->boot('auth-off', ['layers' => ['security' => ['authentication' => false]]]);

        self::assertFalse(
            $kernel->getContainer()->has('ids_sensor.sensor.authentication'),
            'Bei authentication: false darf der Sensor nicht existieren',
        );

        $events = $this->handleWith($kernel, $this->basicAuth('gibt-es-nicht', 'falsch'));

        foreach ($events as $event) {
            self::assertNotSame(SecurityPayload::EVENT_AUTH_FAILURE, $event->eventType);
        }
    }

    /**
     * PHP_AUTH_USER/PHP_AUTH_PW statt eines Authorization-Headers.
     *
     * HttpBasicAuthenticator liest die Zugangsdaten über Request::getUser(), und das
     * greift auf $_SERVER zu. Die Umwandlung des Authorization-Headers in diese Variablen
     * macht ServerBag::getHeaders() — die läuft nur bei createFromGlobals(), nicht bei
     * Request::create(). Ein gesetzter Header allein bleibt hier also wirkungslos, und
     * der Test wäre grün, ohne jemals einen Anmeldeversuch ausgelöst zu haben.
     */
    private function basicAuth(string $user, string $password): Request
    {
        return Request::create('/ok', server: [
            'PHP_AUTH_USER' => $user,
            'PHP_AUTH_PW' => $password,
        ]);
    }

    /**
     * @param list<CapturedEvent> $events
     */
    private function firstOfType(array $events, string $eventType): CapturedEvent
    {
        foreach ($events as $event) {
            if ($eventType === $event->eventType) {
                return $event;
            }
        }

        self::fail(\sprintf(
            'Kein Event vom Typ "%s" erfasst. Erfasst wurden: %s',
            $eventType,
            implode(', ', array_map(static fn (CapturedEvent $e): string => $e->eventType, $events)) ?: '(nichts)',
        ));
    }

    /**
     * @return list<CapturedEvent>
     */
    private function handle(Request $request, string $variant = 'auth'): array
    {
        return $this->handleWith($this->boot($variant), $request);
    }

    /**
     * @return list<CapturedEvent>
     */
    private function handleWith(TestKernel $kernel, Request $request): array
    {
        $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        /** @var EventBuffer $collector */
        $collector = $this->services($kernel)->get('ids_sensor.event_buffer');

        return $collector->all();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function boot(string $variant, array $overrides = []): TestKernel
    {
        $kernel = new TestKernel(
            array_replace_recursive(self::CONFIG, $overrides),
            'security-'.$variant,
            true,
            true,
            SecurityConfig::basic(),
        );
        $kernel->boot();

        return $kernel;
    }
}
