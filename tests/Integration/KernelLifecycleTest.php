<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Kernel\RequestSensor;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Der Durchstich durch den echten Request-Lebenszyklus.
 *
 * Prüft die strukturell schwierigste Anforderung des Konzepts: dass ein Request drei
 * zusammengehörige Events erzeugt, die über eine gemeinsame correlation_id verkettet
 * sind und die Feldredundanz aus Konzept 3.2 tragen.
 */
final class KernelLifecycleTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => 'shop-api',
        'environment' => 'prod',
        'session_hash' => ['key' => self::SESSION_KEY],
    ];

    public function testASuccessfulRequestProducesRequestAndResponseEvent(): void
    {
        $events = $this->handle(Request::create('/ok'));

        self::assertSame(
            [KernelPayload::EVENT_REQUEST, KernelPayload::EVENT_RESPONSE],
            array_map(static fn (CapturedEvent $e): string => $e->eventType, $events),
        );
    }

    public function testAFailingRequestProducesThreeEvents(): void
    {
        $events = $this->handle(Request::create('/boom'), catchExceptions: true);

        self::assertSame(
            [
                KernelPayload::EVENT_REQUEST,
                KernelPayload::EVENT_EXCEPTION,
                KernelPayload::EVENT_RESPONSE,
            ],
            array_map(static fn (CapturedEvent $e): string => $e->eventType, $events),
        );
    }

    /**
     * Die Verkettung, auf der jeder Self-Join des Collectors beruht.
     */
    public function testAllEventsOfARequestShareTheCorrelationId(): void
    {
        $events = $this->handle(Request::create('/boom'), catchExceptions: true);

        $ids = array_unique(array_map(static fn (CapturedEvent $e): ?string => $e->correlationId(), $events));

        self::assertCount(1, $ids);
        self::assertNotSame([null], $ids, 'Die correlation_id ist Pflichtfeld und darf nicht null sein');
    }

    /**
     * Konzept 3.2 Bewusste Feldredundanz: path muss in allen drei Events stehen, damit
     * die Batch-Regeln Statuscodes und Pfade ohne Self-Join aggregieren können.
     */
    public function testThePathIsInAllThreeEvents(): void
    {
        $events = $this->handle(Request::create('/boom'), catchExceptions: true);

        foreach ($events as $event) {
            self::assertSame(
                '/boom',
                $event->get(KernelPayload::FIELD_PATH),
                \sprintf('Event "%s" trägt keinen Pfad', $event->eventType),
            );
        }
    }

    /**
     * DER Regressionsschutz für die Prioritätsentscheidung.
     *
     * `/wp-admin/setup-config.php` hat keine Route. Der RouterListener bricht bei
     * Priorität 32 mit einer NotFoundHttpException ab — ein Sensor bei Priorität 31
     * würde für diesen Request nie laufen. Genau dieser Verkehr ist aber das
     * Scanning-Signal, auf dem Regel B1 und Szenario S10 aufbauen.
     *
     * Schlägt dieser Test fehl, hat jemand die Priorität gesenkt.
     */
    public function testARoutelessScannerPathIsCapturedAnyway(): void
    {
        $events = $this->handle(Request::create('/wp-admin/setup-config.php'), catchExceptions: true);

        $types = array_map(static fn (CapturedEvent $e): string => $e->eventType, $events);

        self::assertContains(KernelPayload::EVENT_REQUEST, $types);
        self::assertContains(KernelPayload::EVENT_EXCEPTION, $types);

        $request = $this->firstOfType($events, KernelPayload::EVENT_REQUEST);
        self::assertSame('/wp-admin/setup-config.php', $request->get(KernelPayload::FIELD_PATH));
        self::assertNull($request->get(KernelPayload::FIELD_ROUTE), 'Ohne Route bleibt route null');
    }

    /**
     * Konzept 2.2.2 — Nutzerkontext auf Kernel-Ebene und Konzept 3.1.1: route ist bei
     * kernel.request noch nicht aufgelöst und wird bei Priorität 7 nachgetragen,
     * kernel.response übernimmt sie redundant.
     */
    public function testTheRouteIsBackfilledAndAdoptedIntoTheResponse(): void
    {
        $events = $this->handle(Request::create('/ok'));

        $request = $this->firstOfType($events, KernelPayload::EVENT_REQUEST);
        $response = $this->firstOfType($events, KernelPayload::EVENT_RESPONSE);

        self::assertSame('test_ok', $request->get(KernelPayload::FIELD_ROUTE), 'Nachtrag bei Priorität 7');
        self::assertSame('test_ok', $response->get(KernelPayload::FIELD_ROUTE), 'Redundanz nach Konzept 3.2');
    }

    public function testQueryParametersAreCaptured(): void
    {
        $events = $this->handle(Request::create('/ok?expand=items&page=2'));

        $request = $this->firstOfType($events, KernelPayload::EVENT_REQUEST);

        self::assertSame(['expand' => 'items', 'page' => '2'], $request->get(KernelPayload::FIELD_QUERY));
    }

    public function testStatusAndResponseTimeAreCaptured(): void
    {
        $events = $this->handle(Request::create('/ok'));

        $response = $this->firstOfType($events, KernelPayload::EVENT_RESPONSE);

        self::assertSame(200, $response->get(KernelPayload::FIELD_HTTP_STATUS));
        self::assertIsInt($response->get(KernelPayload::FIELD_RESPONSE_TIME_MS));
        self::assertGreaterThan(0, $response->get(KernelPayload::FIELD_RESPONSE_SIZE_BYTES));
    }

    /**
     * Bei einer StreamedResponse ist die Größe nicht ermittelbar, ohne den Inhalt zu
     * erzeugen. null ist dort die ehrliche Auskunft — 0 würde eine leere Antwort
     * behaupten. sendContent() darf dabei niemals aufgerufen werden.
     */
    public function testAStreamedResponseReportsSizeAsNull(): void
    {
        $events = $this->handle(Request::create('/stream'));

        $response = $this->firstOfType($events, KernelPayload::EVENT_RESPONSE);

        self::assertNull($response->get(KernelPayload::FIELD_RESPONSE_SIZE_BYTES));
    }

    /**
     * Die Exception-Klasse muss die ORIGINALE sein. Liefen wir hinter Symfonys
     * Security-ExceptionListener (Priorität 1), stünde hier die umgewandelte
     * AccessDeniedHttpException — für die Forensik die schlechtere Auskunft.
     */
    public function testTheOriginalExceptionClassIsCaptured(): void
    {
        $events = $this->handle(Request::create('/geschuetzt'), catchExceptions: true);

        $exception = $this->firstOfType($events, KernelPayload::EVENT_EXCEPTION);

        self::assertSame(
            \Symfony\Component\Security\Core\Exception\AccessDeniedException::class,
            $exception->get(KernelPayload::FIELD_EXCEPTION_CLASS),
        );
        // Und der Status ist 403, nicht 500 — sonst wäre ein abgelehnter Zugriff als
        // Serverfehler gemeldet (Konzept 2.2.1 behält critical Serverfehlern vor).
        self::assertSame(403, $exception->get(KernelPayload::FIELD_HTTP_STATUS));
    }

    public function testCorrelationIdIsExposedAsRequestAttribute(): void
    {
        $kernel = $this->boot('lifecycle');
        $request = Request::create('/ok');

        $kernel->handle($request);

        self::assertIsString(
            $request->attributes->get(RequestSensor::REQUEST_ATTRIBUTE),
            'Die Anwendung soll die correlation_id in ihre eigenen Logs übernehmen können',
        );
    }

    /**
     * Ohne konfigurierten Trusted Proxy darf ein eingehender Request-ID-Header nicht
     * übernommen werden: er ist reine Client-Eingabe, und ein Angreifer könnte damit
     * die Spur eines Opfers übernehmen.
     */
    public function testAnIncomingHeaderIsNotAdoptedByDefault(): void
    {
        $request = Request::create('/ok');
        $request->headers->set('X-Request-Id', 'vom-angreifer-gesetzt');

        $events = $this->handle($request);

        $correlationId = $events[0]->correlationId();
        self::assertNotSame('vom-angreifer-gesetzt', $correlationId);
        self::assertIsString($correlationId);
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

        self::fail(\sprintf('Kein Event vom Typ "%s" erfasst', $eventType));
    }

    /**
     * @return list<CapturedEvent>
     */
    private function handle(Request $request, bool $catchExceptions = false): array
    {
        $kernel = $this->boot('lifecycle');
        $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, $catchExceptions);

        /** @var EventBuffer $collector */
        $collector = $this->services($kernel)->get('ids_sensor.event_buffer');

        return $collector->all();
    }

    private function boot(string $variant): TestKernel
    {
        $kernel = new TestKernel(self::CONFIG, $variant);
        $kernel->boot();

        return $kernel;
    }
}
