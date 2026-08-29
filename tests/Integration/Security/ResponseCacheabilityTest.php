<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Security;

use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\SecurityConfig;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;

/**
 * Der Sensor darf die Antwort der überwachten Anwendung nicht verändern.
 *
 * WAS HIER SCHIEFGEHEN KANN
 *
 * `ActorFactory` braucht den Token, um actor.user zu füllen. Symfony bietet dafür zwei
 * Speicher an, und der naheliegende ist der falsche:
 *
 *  - `security.token_storage` ist eine UsageTrackingTokenStorage. Ihr `getToken()` fasst
 *    `$session->getMetadataBag()` an und erhöht damit den Session-Usage-Index.
 *  - `AbstractSessionListener::onKernelResponse` bricht nur ab, wenn dieser Index 0 ist.
 *    Andernfalls setzt er `Expires`, `private` und `must-revalidate`.
 *
 * Folge: allein dadurch, dass der Sensor mitliest, würde eine öffentlich cachebare Antwort
 * der Anwendung uncachebar. Eine Verhaltensänderung, die niemand bestellt hat — und die
 * einzige Stelle, an der das Bundle die Anwendung messbar verändern würde.
 *
 * Deshalb wird `security.untracked_token_storage` injiziert. Für diese Zusage gab es lange
 * keinen Test; sie stand nur als Kommentar in der Verdrahtung.
 *
 * WARUM DIESER TEST DAUERHAFT WICHTIG BLEIBT
 *
 * `TokenStorageInterface` ist von SecurityBundle auf `security.token_storage` aliasiert,
 * `security.untracked_token_storage` hat keinen Autowiring-Alias. Jeder Griff zu Autowiring
 * an dieser Stelle — und jede spätere Injektion von `Security` oder
 * `AuthorizationCheckerInterface` in einen Sensor — bricht die Zusage lautlos. Dieser Test
 * hält sie unabhängig davon, WIE sie umgesetzt ist.
 */
final class ResponseCacheabilityTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        'session_hash' => ['key' => self::SESSION_KEY],
        'budget' => ['capture_us' => 0],
        'heartbeat' => ['enabled' => false],
    ];

    /**
     * Die eigentliche Zusage, als Verhalten geprüft.
     */
    public function testAPubliclyCacheableResponseStaysCacheable(): void
    {
        $kernel = $this->boot('cachebar');

        $request = Request::create('/cachebar');
        $request->cookies->set('PHPSESSID', 'eine-bestehende-sitzung');

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate($request, $response);

        $cacheControl = (string) $response->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl, 'Die Antwort war als public gedacht');
        self::assertStringNotContainsString(
            'private',
            $cacheControl,
            'Der Sensor hat die Antwort uncachebar gemacht — vermutlich greift er auf '
            .'security.token_storage statt auf security.untracked_token_storage zu.',
        );
        self::assertStringNotContainsString('must-revalidate', $cacheControl);
    }

    /**
     * Die Verdrahtung selbst, als zweite Stufe.
     *
     * Zeigt im Fehlerfall sofort WO, nicht nur DASS. Und sie hält auch dann noch, wenn
     * jemand ActorFactory umbaut und der Verhaltenstest aus anderem Grund grün wird.
     */
    public function testTheSensorGetsTheUntrackedTokenStorage(): void
    {
        $services = $this->services($this->boot('speicher'));

        /** @var ActorFactory $actorFactory */
        $actorFactory = $services->get('ids_sensor.actor_factory');

        $property = new \ReflectionProperty(ActorFactory::class, 'untrackedTokenStorage');
        $injected = $property->getValue($actorFactory);

        self::assertNotInstanceOf(
            UsageTrackingTokenStorage::class,
            $injected,
            'Der Sensor bekommt den getrackten Token-Speicher — sein getToken() erhöht den '
            .'Session-Usage-Index und macht Antworten der Anwendung uncachebar.',
        );
        self::assertSame($services->get('security.untracked_token_storage'), $injected);
    }

    private function boot(string $variant): TestKernel
    {
        // Zustandsbehaftete Firewall: nur dort schaltet Symfonys ContextListener die
        // Nutzungszählung überhaupt ein. Gegen eine stateless Firewall wäre dieser Test
        // grün, ohne etwas zu belegen.
        $kernel = new TestKernel(self::CONFIG, 'cacheability-'.$variant, true, true, SecurityConfig::stateful());
        $kernel->boot();

        return $kernel;
    }
}
