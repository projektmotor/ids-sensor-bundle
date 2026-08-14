<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\Security;

use ProjektMotor\IdsSensor\EventFormat\Payload\SecurityPayload;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\SecurityConfig;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Autorisierungsentscheidungen durch den echten AccessDecisionManager.
 *
 * Der Test läuft mit debug: true, also mit dem TraceableAccessDecisionManager von
 * SecurityBundle im Spiel. Das ist Absicht: unser Decorator muss AUSSERHALB davon sitzen
 * (decoration_priority 255), sonst beobachtet er eine Zwischenstufe.
 */
final class AccessDecisionSensorTest extends IntegrationTestCase
{
    /**
     * capture_us: 0 heißt „unbegrenzt".
     *
     * Diese Tests prüfen die Erfassungs-SEMANTIK, nicht das Latenzbudget — dafür gibt es
     * einen eigenen Regressionstest. Ohne die Abschaltung wären sie von der
     * Aufwärmreihenfolge des PHP-Prozesses abhängig: die allererste Erfassung eines
     * Prozesses zahlt die Einmalkosten für das Kompilieren aller beteiligten Klassen
     * (gemessen 2486 µs gegenüber 516 µs im eingeschwungenen Zustand), womit das
     * Standardbudget von 1500 µs bereits beim kernel.request-Event aufgebraucht ist und
     * ALLE Autorisierungsentscheidungen entfallen. Der Test wäre dann grün oder rot je
     * nachdem, welcher Test in derselben PHPUnit-Prozessinstanz vorher lief.
     *
     * Dass die Entscheidungen bei erschöpftem Budget tatsächlich entfallen — und gezählt
     * werden — prüft {@see testAnExhaustedBudgetLetsDecisionsBeSkipped()}
     * ausdrücklich.
     *
     * @var array<string, mixed>
     */
    private const CONFIG = [
        'application_id' => 'shop-api',
        'environment' => 'prod',
        'session_hash' => ['key' => self::SESSION_KEY],
        'budget' => ['capture_us' => 0],
    ];

    /**
     * Der Controller prüft vier Mal, zwei Mal davon mit identischen Argumenten.
     * Erwartet werden DREI Events — der doppelte Aufruf wird dedupliziert.
     */
    public function testDecisionsAreCapturedAndDeduplicated(): void
    {
        $decisions = $this->decisions($this->handle($this->authenticated('/entscheide')));

        self::assertCount(3, $decisions, 'Der wiederholte identische Aufruf darf kein zweites Event erzeugen');

        $paare = array_map(
            static fn (CapturedEvent $e): string => \sprintf(
                '%s|%s|%s',
                $e->get(SecurityPayload::FIELD_ATTRIBUTE),
                $e->get(SecurityPayload::FIELD_RESOURCE) ?? '-',
                $e->get(SecurityPayload::FIELD_DECISION),
            ),
            $decisions,
        );

        self::assertSame([
            'ROLE_USER|-|granted',
            'VIEW|TestOrder#43|denied',
            'ROLE_ADMIN|-|denied',
        ], $paare);
    }

    /**
     * Konzept 3.1.2 verlangt einen Identifier wie `Order#42` und ausdrücklich nie das
     * vollständige Objekt.
     */
    public function testTheResourceIdentifierIsBuiltWithoutTheObject(): void
    {
        $decisions = $this->decisions($this->handle($this->authenticated('/entscheide')));

        $view = $this->withAttribute($decisions, 'VIEW');

        self::assertSame('TestOrder#43', $view->get(SecurityPayload::FIELD_RESOURCE));
    }

    /**
     * Rollenprüfungen ohne Subjekt haben keine Ressource. null ist dort die ehrliche
     * Auskunft — ein Platzhalter würde eine Ressource behaupten, die es nicht gibt.
     */
    public function testARoleCheckHasNoResource(): void
    {
        $decisions = $this->decisions($this->handle($this->authenticated('/entscheide')));

        self::assertNull($this->withAttribute($decisions, 'ROLE_ADMIN')->get(SecurityPayload::FIELD_RESOURCE));
    }

    /**
     * DER Test, der die Wahl des Hooks rechtfertigt.
     *
     * access_control-Regeln wertet Symfonys AccessListener aus, und der ruft decide()
     * DIREKT am Manager auf — am AuthorizationChecker vorbei. Hätten wir den Checker
     * dekoriert, fehlte hier jedes Event, und damit gerade die wertvollsten Ablehnungen.
     */
    public function testAccessControlDenialIsCaptured(): void
    {
        $events = $this->handle(
            $this->authenticated('/nur-fuer-admins'),
            'acl',
            SecurityConfig::withAccessControl(),
        );

        $decisions = $this->decisions($events);
        $attribute = array_map(
            static fn (CapturedEvent $e): mixed => $e->get(SecurityPayload::FIELD_ATTRIBUTE),
            $decisions,
        );

        self::assertContains('ROLE_ADMIN', $attribute);

        $denied = $this->withAttribute($decisions, 'ROLE_ADMIN');
        self::assertSame('denied', $denied->get(SecurityPayload::FIELD_DECISION));
        // AccessListener übergibt den Request als Subjekt — ohne Sonderbehandlung trüge
        // jede access_control-Ablehnung resource: null.
        self::assertSame('Request#/nur-fuer-admins', $denied->get(SecurityPayload::FIELD_RESOURCE));
    }

    /**
     * capture_granted: false halbiert das Volumen. Ablehnungen müssen aber IMMER
     * erhalten bleiben — an ihnen hängt Regel R4.
     */
    public function testCaptureGrantedFalseCapturesOnlyDenials(): void
    {
        $events = $this->handle(
            $this->authenticated('/entscheide'),
            'nur-denials',
            SecurityConfig::basic(),
            ['layers' => ['security' => ['capture_granted' => false]]],
        );

        $decisions = $this->decisions($events);

        self::assertNotSame([], $decisions);

        foreach ($decisions as $decision) {
            self::assertSame('denied', $decision->get(SecurityPayload::FIELD_DECISION));
        }
    }

    /**
     * Die Obergrenze ist der Schutz gegen Übersichtsseiten mit einem Voter pro Zeile.
     * Der Verlust muss zählbar sein, sonst behauptet der Frame Vollständigkeit.
     */
    public function testTheUpperLimitAppliesAndCountsTheOverflow(): void
    {
        $kernel = $this->boot(
            'cap',
            SecurityConfig::basic(),
            ['layers' => ['security' => ['max_decisions_per_request' => 1]]],
        );
        $kernel->handle($this->authenticated('/entscheide'), HttpKernelInterface::MAIN_REQUEST, true);

        /** @var EventBuffer $collector */
        $collector = $this->services($kernel)->get('ids_sensor.event_buffer');
        /** @var AccessDecisionSensor $sensor */
        $sensor = $this->services($kernel)->get('ids_sensor.sensor.access_decision');

        self::assertCount(1, $this->decisions($collector->all()));
        self::assertGreaterThan(0, $sensor->overflowCount(), 'Der Verlust muss zählbar sein');
    }

    /**
     * Zählbar genügt nicht — der Verlust muss den Sensor verlassen.
     *
     * Konzept 4. IdsBackendBundle (Restrisiko): „Jeder verworfene oder verlorene Event
     * wird gezählt und löst ab einem Schwellwert einen eigenen Alert aus
     * (rule_id = ids.event_loss)." Ein Zähler, den nur der Sensor selbst kennt, erfüllt
     * das nicht: von außen wäre eine Übersichtsseite oberhalb der Obergrenze nicht von
     * einer vollständigen Erfassung zu unterscheiden.
     */
    public function testTheOverflowReachesTheCountersOnFlush(): void
    {
        $kernel = $this->boot(
            'cap-zaehler',
            SecurityConfig::basic(),
            ['layers' => ['security' => ['max_decisions_per_request' => 1]]],
        );

        $request = $this->authenticated('/entscheide');
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate($request, $response);

        /** @var Counters $counters */
        $counters = $this->services($kernel)->get('ids_sensor.counters');

        self::assertGreaterThan(
            0,
            $counters->get(Counters::DROPPED_DECISION_CAP),
            'Der Überlauf wird im Sensor gezählt, aber beim Flush nicht eingesammelt — '
            .'collectorseitig ist der Verlust damit unsichtbar.',
        );
    }

    /**
     * Das Gegenstück zur Budget-Abschaltung in {@see CONFIG}: mit realem Budget dürfen
     * Autorisierungsentscheidungen entfallen — sie sind pro Request nach oben offen, und
     * genau davor schützt das Budget (Konzept 2.1 Sensorik — Latenzbudget).
     *
     * Wichtig ist nur, dass der Verlust NICHT stumm ist: er wird gezählt und reist als
     * dropped_capture_budget im Frame mit. Ein Blindwerden ohne Zähler wäre das lautlose
     * Versagen, das Konzept 2. als besonders gefährlich beschreibt.
     */
    public function testAnExhaustedBudgetLetsDecisionsBeSkipped(): void
    {
        // 1 µs: nach dem kernel.request-Event ist das Budget garantiert aufgebraucht.
        $kernel = $this->boot('budget-erschoepft', SecurityConfig::basic(), ['budget' => ['capture_us' => 1]]);
        $events = $this->handleWith($kernel, $this->authenticated('/entscheide'));

        /** @var \ProjektMotor\IdsSensor\Sensor\CaptureBudget $budget */
        $budget = $this->services($kernel)->get('ids_sensor.capture_budget');

        self::assertSame([], $this->decisions($events), 'Bei erschöpftem Budget wird nicht mehr erfasst');
        self::assertGreaterThan(0, $budget->skipped(), 'Der Verlust muss gezählt werden');
    }

    /**
     * Dieselbe Erfassung ohne Debug-Modus.
     *
     * In Produktion fehlen die Debug-Decorators, die SecurityBundle sonst registriert
     * (debug.security.access.decision_manager, TraceableAuthenticator,
     * TraceableEventDispatcher). Damit ändert sich der Objektgraph, in dem unser
     * Decorator sitzt — und zwar genau in der Umgebung, in der es zählt. Ein Bundle, das
     * nur in der Entwicklung erfasst, ist wertlos.
     */
    public function testCaptureWorksWithoutDebugModeToo(): void
    {
        $kernel = new TestKernel(
            self::CONFIG,
            'access-ohne-debug',
            true,
            false,
            SecurityConfig::basic(),
        );
        $kernel->boot();

        $decisions = $this->decisions($this->handleWith($kernel, $this->authenticated('/entscheide')));

        self::assertCount(3, $decisions);
        self::assertSame(
            'TestOrder#43',
            $this->withAttribute($decisions, 'VIEW')->get(SecurityPayload::FIELD_RESOURCE),
        );
    }

    public function testDisablingRemovesTheDecoratorEntirely(): void
    {
        $kernel = $this->boot(
            'ad-off',
            SecurityConfig::basic(),
            ['layers' => ['security' => ['access_decision' => false]]],
        );

        self::assertFalse($kernel->getContainer()->has('ids_sensor.sensor.access_decision'));

        $events = $this->decisions($this->handleWith($kernel, $this->authenticated('/entscheide')));
        self::assertSame([], $events);
    }

    /**
     * reset() wird in Worker-Laufzeiten zwischen zwei Requests aufgerufen. Ohne das
     * bliebe das Dedup-Gedächtnis prozessweit stehen und der zweite Request eines
     * Workers wäre blind.
     */
    public function testResetClearsTheDedupMemory(): void
    {
        $kernel = $this->boot('reset');
        $kernel->handle($this->authenticated('/entscheide'), HttpKernelInterface::MAIN_REQUEST, true);

        /** @var EventBuffer $collector */
        $collector = $this->services($kernel)->get('ids_sensor.event_buffer');
        /** @var AccessDecisionSensor $sensor */
        $sensor = $this->services($kernel)->get('ids_sensor.sensor.access_decision');

        $ersteAnzahl = \count($this->decisions($collector->all()));
        $collector->drain();
        $sensor->reset();

        $kernel->handle($this->authenticated('/entscheide'), HttpKernelInterface::MAIN_REQUEST, true);

        self::assertCount($ersteAnzahl, $this->decisions($collector->all()));
    }

    /**
     * Die Entscheidung selbst darf sich durch den Decorator nicht ändern — sonst wäre
     * das Bundle nicht mehr beobachtend, sondern eingreifend.
     */
    public function testTheApplicationsDecisionStaysUnchanged(): void
    {
        $kernel = $this->boot('durchleitung');
        $response = $kernel->handle($this->authenticated('/entscheide'), HttpKernelInterface::MAIN_REQUEST, true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['role_user' => true, 'view_order' => false, 'view_order_again' => false, 'role_admin' => false],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private function authenticated(string $path): Request
    {
        return Request::create($path, server: [
            'PHP_AUTH_USER' => SecurityConfig::USER,
            'PHP_AUTH_PW' => SecurityConfig::PASSWORD,
        ]);
    }

    /**
     * @param list<CapturedEvent> $events
     *
     * @return list<CapturedEvent>
     */
    private function decisions(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn (CapturedEvent $e): bool => SecurityPayload::EVENT_ACCESS_DECISION === $e->eventType,
        ));
    }

    /**
     * @param list<CapturedEvent> $decisions
     */
    private function withAttribute(array $decisions, string $attribute): CapturedEvent
    {
        foreach ($decisions as $decision) {
            if ($attribute === $decision->get(SecurityPayload::FIELD_ATTRIBUTE)) {
                return $decision;
            }
        }

        self::fail(\sprintf('Keine Entscheidung zum Attribut "%s"', $attribute));
    }

    /**
     * @param array<string, mixed>|null $securityConfig
     * @param array<string, mixed>      $overrides
     *
     * @return list<CapturedEvent>
     */
    private function handle(
        Request $request,
        string $variant = 'ad',
        ?array $securityConfig = null,
        array $overrides = [],
    ): array {
        return $this->handleWith($this->boot($variant, $securityConfig, $overrides), $request);
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
     * @param array<string, mixed>|null $securityConfig
     * @param array<string, mixed>      $overrides
     */
    private function boot(string $variant, ?array $securityConfig = null, array $overrides = []): TestKernel
    {
        $kernel = new TestKernel(
            // array_replace_recursive, NICHT array_merge_recursive: letzteres würde zwei
            // Skalare unter demselben Schlüssel zu einem Array verschmelzen, statt den
            // Wert zu überschreiben.
            array_replace_recursive(self::CONFIG, $overrides),
            'access-'.$variant,
            true,
            true,
            $securityConfig ?? SecurityConfig::basic(),
        );
        $kernel->boot();

        return $kernel;
    }
}
