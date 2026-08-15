<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Contract\BusinessEventRecorderInterface;
use ProjektMotor\IdsSensor\Processing\Normalization\PayloadSanitizer;
use ProjektMotor\IdsSensor\Sensor\Business\CapturingEventDispatcher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\BrokenBusinessEvent;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\OddBusinessEvent;
use ProjektMotor\IdsSensor\Tests\Fixtures\OrderAmountOverridden;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use ProjektMotor\IdsSensor\Tests\Fixtures\UnnamedBusinessEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Business-Ebene in allen drei Erfassungsmodi.
 *
 * Der im Konzept 2.1.3 vorgeschlagene Weg — „generisch alle Events, die dieses
 * Interface implementieren" — existiert in Symfony nicht: der EventDispatcher löst
 * Listener über den exakten Event-Namen auf, nie über Interfaces. Diese Tests belegen,
 * dass die drei Ersatzwege tatsächlich greifen.
 */
final class BusinessLayerTest extends IntegrationTestCase
{
    /**
     * Der Standardmodus: die Anwendung dispatcht ihr Domain-Event wie gewohnt und
     * enthält KEINE IDS-Referenz.
     */
    public function testDispatcherModeInterceptsTheEvent(): void
    {
        $services = $this->services($this->boot('business-dispatcher'));

        $this->dispatcher($services)->dispatch(new OrderAmountOverridden());

        $events = $this->collector($services)->all();
        self::assertCount(1, $events);
        self::assertSame(Layer::Business, $events[0]->layer);
        self::assertSame('order.amount_overridden', $events[0]->eventType);
    }

    /**
     * Der Decorator ist im Dispatcher-Modus im Container.
     *
     * Geprüft wird die Definition, nicht die Laufzeitinstanz von `event_dispatcher`:
     * im Debug-Modus wickelt Symfony zusätzlich einen TraceableEventDispatcher darum,
     * und in welcher Reihenfolge die Hüllen liegen, ist ein Implementierungsdetail des
     * Frameworks. Dass die Erfassung wirkt, belegt
     * {@see testDispatcherModeInterceptsTheEvent()} verhaltensbasiert.
     */
    public function testTheDecoratorExistsInDispatcherMode(): void
    {
        $services = $this->services($this->boot('business-dispatcher'));

        self::assertTrue($services->has('ids_sensor.business.capturing_dispatcher'));
        self::assertInstanceOf(
            CapturingEventDispatcher::class,
            $services->get('ids_sensor.business.capturing_dispatcher'),
        );
    }

    /**
     * Im Recorder-Modus wird der Decorator vom Compiler-Pass ENTFERNT.
     *
     * Ein nicht gewählter Decorator, der trotzdem auf einem der zentralsten Services
     * von Symfony hängt, wäre genau die Art stiller Nebenwirkung, die dort niemand
     * haben will.
     */
    public function testInRecorderModeTheDecoratorIsRemoved(): void
    {
        $services = $this->services($this->boot('business-recorder', 'recorder'));

        self::assertFalse($services->has('ids_sensor.business.capturing_dispatcher'));
    }

    public function testInRecorderModeTheDispatcherDoesNotIntercept(): void
    {
        $services = $this->services($this->boot('business-recorder', 'recorder'));

        $this->dispatcher($services)->dispatch(new OrderAmountOverridden());

        self::assertTrue($this->collector($services)->isEmpty(), 'Ohne Decorator darf nichts erfasst werden');
    }

    /**
     * Der Recorder ist IMMER verfügbar — auch im Dispatcher-Modus. Eine Anwendung soll
     * einzelne Vorgänge explizit melden können.
     */
    public function testTheRecorderIsPublicAndWorksInEveryMode(): void
    {
        foreach (['dispatcher', 'recorder'] as $mode) {
            $kernel = $this->boot('business-'.$mode, $mode);

            /** @var BusinessEventRecorderInterface $recorder */
            $recorder = $kernel->getContainer()->get(BusinessEventRecorderInterface::class);
            $recorder->record(new OrderAmountOverridden());

            $events = $this->collector($this->services($kernel))->all();
            self::assertCount(1, $events, \sprintf('Modus "%s": der Recorder muss greifen', $mode));
        }
    }

    /**
     * Der `configured`-Modus registriert Listener für ausdrücklich benannte Klassen —
     * der einzige Weg, den Symfonys Dispatcher tatsächlich unterstützt.
     */
    public function testConfiguredModeListensToNamedClasses(): void
    {
        $services = $this->services($this->boot(
            'business-configured',
            'configured',
            [OrderAmountOverridden::class],
        ));

        $this->dispatcher($services)->dispatch(new OrderAmountOverridden());

        self::assertCount(1, $this->collector($services)->all());
    }

    public function testConfiguredModeIgnoresUnnamedClasses(): void
    {
        $services = $this->services($this->boot(
            'business-configured',
            'configured',
            [OrderAmountOverridden::class],
        ));

        // Nicht in der Liste: bleibt unsichtbar. Das ist der dokumentierte Preis
        // dieses Modus.
        $this->dispatcher($services)->dispatch(new BrokenBusinessEvent(breakActor: false));

        self::assertTrue($this->collector($services)->isEmpty());
    }

    /**
     * Der Durchstich bis zum Wire: Severity aus dem Hint, Payload durchgereicht,
     * Pflichtfelder vollständig.
     */
    public function testEventReachesTheTransportWithAllMandatoryFields(): void
    {
        $kernel = $this->boot('business-dispatcher');
        $services = $this->services($kernel);

        $this->dispatcher($services)->dispatch(new OrderAmountOverridden());
        $kernel->terminate(Request::create('/'), new Response());

        /** @var \ProjektMotor\IdsSensor\Delivery\Transport\Shipper\NullShipper $shipper */
        $shipper = $services->get('ids_sensor.shipper');
        self::assertSame(1, $shipper->shippedEvents());
    }

    public function testSeverityComesStraightFromTheHint(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OrderAmountOverridden());

        self::assertSame(Severity::Critical->value, $event[EventSchema::FIELD_EVENT_SEVERITY]);
    }

    public function testThePayloadIsPassedThrough(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OrderAmountOverridden());

        self::assertSame(42, $event[EventSchema::FIELD_PAYLOAD]['order_id']);
        self::assertSame(19.99, $event[EventSchema::FIELD_PAYLOAD]['original_amount']);
        self::assertSame(0.01, $event[EventSchema::FIELD_PAYLOAD]['overridden_amount']);
    }

    public function testActorIdIsAdopted(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OrderAmountOverridden());

        self::assertSame('alice', $event[EventSchema::FIELD_ACTOR][EventSchema::ACTOR_USER]);
    }

    /**
     * Ein werfender Getter der Anwendung darf das Event kosten, aber nie den Request.
     * Die übrigen Felder müssen erhalten bleiben.
     */
    public function testAThrowingGetterDoesNotLoseTheEvent(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new BrokenBusinessEvent(breakActor: true));

        self::assertSame('user.roles_changed', $event[EventSchema::FIELD_EVENT_TYPE]);
        self::assertSame(Severity::Critical->value, $event[EventSchema::FIELD_EVENT_SEVERITY]);
        self::assertSame(['ROLE_ADMIN'], $event[EventSchema::FIELD_PAYLOAD]['roles']);
    }

    /**
     * Ein kaputter Getter ist im Frame von einem leeren Rückgabewert unterscheidbar.
     *
     * Vorher nicht: `getEventName()`, das wirft, ergab denselben Ersatzwert wie
     * `getEventName()`, das `''` liefert — im Frame stand `business.unnamed` und ein
     * Vermerk mit LEEREM Originalnamen. Das las sich wie „die Anwendung hat ihr Event
     * nicht benannt" und war in Wahrheit ein Defekt in der überwachten Anwendung, den
     * niemand je erfuhr. Die Fixture konnte das seit jeher (`breakName`,
     * `breakPayload`) — benutzt hat es kein Test.
     */
    public function testABrokenGetterIsNamedInTheFrame(): void
    {
        $event = $this->normalizeThrough(
            'business-dispatcher',
            new BrokenBusinessEvent(breakName: true, breakActor: false, breakPayload: true),
        );

        $payload = $event[EventSchema::FIELD_PAYLOAD];

        self::assertSame('business.unnamed', $event[EventSchema::FIELD_EVENT_TYPE]);
        self::assertSame(
            ['event_name', 'payload'],
            $payload[PayloadSanitizer::RESERVED_PREFIX.'unreadable'],
            'Genau die beiden Getter, die geworfen haben — und keiner mehr',
        );
    }

    /**
     * Und umgekehrt: ein wirklich leerer Name erzeugt KEINEN solchen Vermerk.
     */
    public function testAnEmptyNameIsNotReportedAsBroken(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new UnnamedBusinessEvent());

        self::assertSame('business.unnamed', $event[EventSchema::FIELD_EVENT_TYPE]);
        self::assertArrayNotHasKey(
            PayloadSanitizer::RESERVED_PREFIX.'unreadable',
            $event[EventSchema::FIELD_PAYLOAD],
        );
    }

    /**
     * Ein unbrauchbarer Hint wird auf warning eingestuft — nicht auf info.
     *
     * Nicht info, weil ein Tippfehler der Anwendung das Event sonst still in die
     * 30-Tage-Retention verschöbe (Konzept 4.2.3) und damit heimlich die Aufbewahrung
     * eines sicherheitsrelevanten Vorgangs verkürzte. Nicht verwerfen, weil
     * Business-Events die einzige Signalklasse für erfolgreiche Angriffe sind.
     */
    public function testAnUnusableHintBecomesWarningAndStaysTraceable(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OddBusinessEvent());

        self::assertSame(Severity::Warning->value, $event[EventSchema::FIELD_EVENT_SEVERITY]);
        self::assertSame(
            'sehr kritisch',
            $event[EventSchema::FIELD_PAYLOAD][PayloadSanitizer::RESERVED_PREFIX.'severity_hint_raw'],
            'Der Originalwert muss in den DATEN sichtbar bleiben, nicht nur im Log',
        );
    }

    public function testDeviatingNameIsSanitizedAndOriginalKept(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OddBusinessEvent());

        self::assertMatchesRegularExpression('/^[a-z0-9._]+$/', $event[EventSchema::FIELD_EVENT_TYPE]);
        self::assertSame(
            'Order Amount Überschrieben!',
            $event[EventSchema::FIELD_PAYLOAD][PayloadSanitizer::RESERVED_PREFIX.'event_name_raw'],
        );
    }

    /**
     * Der reservierte Präfix schützt die Vermerke des Sensors: eine Anwendung darf sie
     * nicht fälschen können.
     */
    public function testReservedKeysFromTheApplicationAreStripped(): void
    {
        $event = $this->normalizeThrough('business-dispatcher', new OddBusinessEvent(
            'order.amount_overridden',
            'info',
            [PayloadSanitizer::RESERVED_PREFIX.'severity_hint_raw' => 'gefaelscht', 'echt' => 'wert'],
        ));

        self::assertSame('wert', $event[EventSchema::FIELD_PAYLOAD]['echt']);
        self::assertArrayNotHasKey(
            PayloadSanitizer::RESERVED_PREFIX.'severity_hint_raw',
            $event[EventSchema::FIELD_PAYLOAD],
            'Ein gefälschter Vermerk darf nicht durchkommen',
        );
    }

    /**
     * Konzept 3.1.2 verlangt für payload.resource ausdrücklich einen Identifier und
     * niemals das vollständige Objekt. Dieselbe Regel gilt sinngemäß für die
     * Business-Nutzlast — sonst landet ein ganzer Objektgraph im Beweisspeicher.
     */
    public function testObjectsInThePayloadAreReducedToAnIdentifier(): void
    {
        $entity = new class {
            public function getId(): int
            {
                return 4711;
            }
        };

        $event = $this->normalizeThrough('business-dispatcher', new OddBusinessEvent(
            'order.amount_overridden',
            'warning',
            ['order' => $entity],
        ));

        self::assertIsString($event[EventSchema::FIELD_PAYLOAD]['order']);
        self::assertStringEndsWith('#4711', $event[EventSchema::FIELD_PAYLOAD]['order']);
    }

    /**
     * @return array<string, mixed> das erste normalisierte Event als Array
     */
    private function normalizeThrough(string $variant, object $event): array
    {
        $kernel = $this->boot($variant);
        $services = $this->services($kernel);

        $this->dispatcher($services)->dispatch($event);

        $captured = $this->collector($services)->all();
        self::assertNotSame([], $captured, 'Es wurde nichts erfasst');

        /** @var \ProjektMotor\IdsSensor\Processing\Normalization\BusinessEventNormalizer $normalizer */
        $normalizer = $services->get('ids_sensor.normalizer.business');
        /** @var \ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider $identity */
        $identity = $services->get('ids_sensor.identity_provider');

        return $normalizer->normalize($captured[0], $identity->get())->toArray();
    }

    /**
     * Der Kürzungsvermerk darf nicht fälschbar sein.
     *
     * `PayloadSanitizer` schrieb `__truncated` als Literal und filterte nur `_ids_`. Eine
     * Anwendung — oder ein Angreifer, der Werte in ein Domain-Event schleust — konnte den
     * Schlüssel mitliefern und einen Vollständigkeitsverlust vortäuschen, den es nie gab.
     * Genau das schließt die Begründung des `_ids_`-Präfixes aus; für diesen Marker war
     * sie nicht eingelöst.
     */
    public function testTheTruncationMarkerCannotBeForged(): void
    {
        $sanitizer = new PayloadSanitizer(TestCleaner::default());

        $bereinigt = $sanitizer->sanitize([
            PayloadSanitizer::TRUNCATED_MARKER => true,
            'echt' => 'sichtbar',
        ]);

        self::assertArrayNotHasKey(
            PayloadSanitizer::TRUNCATED_MARKER,
            $bereinigt,
            'Eine vorgetäuschte Kürzung darf nicht durchkommen',
        );
        self::assertSame('sichtbar', $bereinigt['echt'], 'Der Rest bleibt unangetastet');
    }

    private function collector(ContainerInterface $services): EventBuffer
    {
        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');

        return $collector;
    }

    private function dispatcher(ContainerInterface $services): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $services->get('event_dispatcher');

        return $dispatcher;
    }

    /**
     * @param list<string> $eventClasses
     */
    private function boot(string $variant, string $mode = 'dispatcher', array $eventClasses = []): TestKernel
    {
        $kernel = new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
            'layers' => ['business' => ['capture_mode' => $mode, 'event_classes' => $eventClasses]],
        ], $variant);
        $kernel->boot();

        return $kernel;
    }
}
