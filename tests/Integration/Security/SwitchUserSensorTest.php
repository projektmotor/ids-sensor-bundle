<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Security;

use ProjektMotor\IdsEventData\Payload\SecurityPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\SecurityConfig;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Die Rechteübernahme durch Symfonys echten SwitchUserListener — Konzept 2.1.2,
 * offener Punkt OB10.
 *
 * WARUM DAS EIN INTEGRATIONSTEST SEIN MUSS
 *
 * Die ganze Lücke bestand darin, dass Symfonys Übernahme KEINES der drei übrigen
 * Security-Ereignisse auslöst. Ein Unit-Test, der ein SwitchUserEvent selbst baut,
 * bewiese diese Aussage nicht — er prüfte nur, was der Sensor mit einem Ereignis macht,
 * das er bekommt. Hier löst der Listener es aus.
 */
final class SwitchUserSensorTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        'budget' => ['capture_us' => 0],
    ];

    /**
     * DER Test: Ohne ihn hinterlässt ein Administrator, der in ein Kundenkonto wechselt,
     * überhaupt keine Spur.
     */
    public function testTakingOverAnIdentityProducesAnEvent(): void
    {
        $captured = $this->switchUserEvent('uebernahme', SecurityConfig::USER);

        self::assertSame(Layer::Security, $captured->layer);
        self::assertSame(SecurityPayload::EVENT_SWITCH_USER, $captured->eventType);
    }

    /**
     * Die Richtung der Zuordnung ist die eigentliche Aussage: `actor.user` ist der
     * Übernehmende, `target_user` der Übernommene. Andersherum wäre der Vorgang von
     * einer gewöhnlichen Handlung des Kunden nicht zu unterscheiden.
     */
    public function testTheActorIsTheImpersonatorAndNotTheTarget(): void
    {
        $captured = $this->switchUserEvent('richtung', SecurityConfig::USER);

        self::assertSame(SecurityConfig::ADMIN, $captured->actor()->user);
        self::assertSame(SecurityConfig::USER, $captured->get(SecurityPayload::FIELD_TARGET_USER));
    }

    /**
     * Das Ende der Übernahme ist ein eigener Typ. Ohne es bliebe jede spätere Handlung
     * unter der fremden Identität dauerhaft von einer echten Handlung des Übernommenen
     * ununterscheidbar.
     */
    public function testLeavingTheIdentityProducesTheExitEvent(): void
    {
        $captured = $this->switchUserEvent('rueckkehr', '_exit', switchedTo: SecurityConfig::USER);

        self::assertSame(SecurityPayload::EVENT_SWITCH_USER_EXIT, $captured->eventType);
        self::assertSame(SecurityConfig::ADMIN, $captured->get(SecurityPayload::FIELD_TARGET_USER));
    }

    /**
     * Die Anmeldung des Administrators steht VOR der Übernahme, nicht danach.
     *
     * Das ist keine Selbstverständlichkeit, sondern die Voraussetzung dafür, dass
     * `actor.user` überhaupt stimmt: Erst die Anmeldung stellt die Identität her, aus
     * deren Token die Übernahme ihren Akteur liest. Käme sie danach, stünde im
     * Übernahme-Ereignis kein Übernehmender.
     */
    public function testTheImpersonatorAuthenticatesBeforeTheSwitch(): void
    {
        $events = $this->eventsOf('reihenfolge', SecurityConfig::USER);

        $securityTypes = array_values(array_filter(
            array_map(static fn (CapturedEvent $e): string => $e->eventType, $events),
            static fn (string $type): bool => str_starts_with($type, 'security.'),
        ));

        self::assertSame(
            [
                SecurityPayload::EVENT_AUTH_SUCCESS,
                // Symfonys eigene Prüfung auf ROLE_ALLOWED_TO_SWITCH — sie läuft durch
                // den AccessDecisionManager und wird deshalb miterfasst.
                SecurityPayload::EVENT_ACCESS_DECISION,
                SecurityPayload::EVENT_SWITCH_USER,
            ],
            $securityTypes,
        );
    }

    /**
     * Ohne Übernahme kein Ereignis — der Sensor hängt an einem Vorgang, nicht an jedem
     * Request.
     */
    public function testAnOrdinaryRequestProducesNoSwitchEvent(): void
    {
        $events = $this->eventsOf('ohne-uebernahme', null);

        foreach ($events as $event) {
            self::assertNotSame(SecurityPayload::EVENT_SWITCH_USER, $event->eventType);
            self::assertNotSame(SecurityPayload::EVENT_SWITCH_USER_EXIT, $event->eventType);
        }
    }

    private function switchUserEvent(string $variant, string $switchTo, ?string $switchedTo = null): CapturedEvent
    {
        foreach ($this->eventsOf($variant, $switchTo, $switchedTo) as $event) {
            if (\in_array($event->eventType, [SecurityPayload::EVENT_SWITCH_USER, SecurityPayload::EVENT_SWITCH_USER_EXIT], true)) {
                return $event;
            }
        }

        self::fail('Kein switch_user-Ereignis erfasst.');
    }

    /**
     * Führt einen Request als Administrator aus, optional mit `_switch_user`.
     *
     * `$switchedTo` bereitet den Fall „Übernahme verlassen" vor: Das Verlassen setzt
     * voraus, dass eine Übernahme LÄUFT, also muss ein Request vorher hineingewechselt
     * haben und der SwitchUserToken den Requestwechsel überleben. Deshalb die
     * zustandsbehaftete Firewall und das Weiterreichen des Sitzungscookies — genau der
     * Weg, den ein Browser nimmt.
     *
     * @return list<CapturedEvent>
     */
    private function eventsOf(string $variant, ?string $switchTo, ?string $switchedTo = null): array
    {
        $kernel = new TestKernel(self::CONFIG, $variant, securityConfig: SecurityConfig::withSwitchUser());
        $kernel->boot();

        if (null === $switchedTo) {
            $kernel->handle($this->authenticatedRequest($switchTo), HttpKernelInterface::MAIN_REQUEST, true);

            return $this->buffer($kernel)->all();
        }

        $response = $kernel->handle($this->authenticatedRequest($switchedTo), HttpKernelInterface::MAIN_REQUEST, true);
        $this->buffer($kernel)->drain();

        // OHNE Zugangsdaten. Ein zweites Mal mitgeschickt, meldete der
        // HttpBasicAuthenticator den Administrator neu an und ersetzte damit den
        // SwitchUserToken — der Listener fände keinen Token, zu dem er zurückkehren
        // könnte, und `_exit` liefe in eine Ausnahme. Ein Browser schickt hier auch nur
        // das Sitzungscookie.
        $kernel->handle($this->sessionRequest($switchTo, self::cookiesOf($response)), HttpKernelInterface::MAIN_REQUEST, true);

        return $this->buffer($kernel)->all();
    }

    /**
     * Die gesetzten Cookies als Name-Wert-Paare — das, was ein Browser im Folgerequest
     * zurückschickt.
     *
     * @return array<string, string>
     */
    private static function cookiesOf(Response $response): array
    {
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $value = $cookie->getValue();

            if (null !== $value) {
                $cookies[$cookie->getName()] = $value;
            }
        }

        return $cookies;
    }

    private function buffer(TestKernel $kernel): EventBuffer
    {
        $buffer = $this->services($kernel)->get('ids_sensor.event_buffer');

        self::assertInstanceOf(EventBuffer::class, $buffer);

        return $buffer;
    }

    private function authenticatedRequest(?string $switchTo): Request
    {
        $request = self::sessionRequest($switchTo, []);
        $request->headers->set('PHP_AUTH_USER', SecurityConfig::ADMIN);
        $request->headers->set('PHP_AUTH_PW', SecurityConfig::PASSWORD);

        return $request;
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function sessionRequest(?string $switchTo, array $cookies): Request
    {
        return Request::create(
            null === $switchTo ? '/ok' : '/ok?_switch_user='.$switchTo,
            cookies: $cookies,
        );
    }
}
