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
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
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
     * Ein Angreifer darf sich nicht selbst unsichtbar machen können.
     *
     * `X-HTTP-Method-Override` wird von `Request::getMethod()` bedingungslos gelesen; ein
     * Wert, der nicht nur aus Großbuchstaben besteht, wirft `SuspiciousOperationException`.
     * Der Aufruf steht im Snapshot-Bau, und der läuft vor `registry->set()` — ungefangen
     * kostete ein einziger Header damit die gesamte Erfassung dieser Anfrage: kein
     * kernel.request, kein Snapshot für die Folge-Events, kein Zähler, kein Log.
     */
    public function testAnInvalidMethodOverrideDoesNotSilenceTheCapture(): void
    {
        $request = Request::create('/ok', 'POST');
        $request->headers->set('X-HTTP-Method-Override', 'fo-o');

        $events = $this->handle($request, catchExceptions: true);

        $captured = $this->firstOfType($events, KernelPayload::EVENT_REQUEST);

        self::assertNotNull($captured->correlationId(), 'Die Anfrage muss eine Korrelation bekommen');
        self::assertSame(
            'POST',
            $captured->get(KernelPayload::FIELD_METHOD),
            'Erfasst wird die tatsächlich gesendete Methode, nicht die versuchte Übersteuerung',
        );
    }

    /**
     * Und die Folge-Events derselben Anfrage bleiben verkettet.
     */
    public function testTheOverrideAttemptStillYieldsAChainedResponseEvent(): void
    {
        $request = Request::create('/ok', 'POST');
        $request->headers->set('X-HTTP-Method-Override', 'fo-o');

        $events = $this->handle($request, catchExceptions: true);
        $ids = array_unique(array_map(static fn (CapturedEvent $e): ?string => $e->correlationId(), $events));

        self::assertCount(1, $ids, 'Alle Events der Anfrage teilen eine correlation_id');
        self::assertNotSame([''], $ids, 'Und die ist nicht leer');
    }

    /**
     * Widersprüchliche Proxy-Header dürfen kein Event kosten.
     *
     * `Request::getClientIps()` wirft `ConflictingHeadersException`, wenn ein
     * `Forwarded`- einem `X-Forwarded-For`-Header widerspricht und beide von einem
     * vertrauten Proxy kommen. Beide Header darf der Client schicken. Der Aufruf steht im
     * Akteursaufbau, und der läuft vor `buffer->append()` — ungefangen verschwanden
     * kernel.request UND kernel.response ungezählt.
     */
    public function testConflictingProxyHeadersDoNotCostTheEvents(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_FORWARDED);

        try {
            $request = Request::create('/ok', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $request->headers->set('X-Forwarded-For', '203.0.113.9');
            $request->headers->set('Forwarded', 'for=198.51.100.7');

            $events = $this->handle($request, catchExceptions: true);

            self::assertSame(
                [KernelPayload::EVENT_REQUEST, KernelPayload::EVENT_RESPONSE],
                array_map(static fn (CapturedEvent $e): string => $e->eventType, $events),
                'Beide Events müssen trotz widersprüchlicher Header erfasst sein',
            );
            self::assertSame(
                '127.0.0.1',
                $this->firstOfType($events, KernelPayload::EVENT_REQUEST)->actor()->ip,
                'Der Rückfall ist die tatsächliche Gegenstelle, nie ein geratener Wert',
            );
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    /**
     * `ignored_paths` gilt auch dann, wenn kernel.request unseren Sensor nie erreicht.
     *
     * `ResponseSensor` und `ExceptionSensor` fragten den Filter NUR mit Snapshot. Fehlt
     * er — weil ein Listener mit höherer Priorität als 1024 bereits geantwortet hat —,
     * wurde ein ausdrücklich ausgeschlossener Pfad trotzdem erfasst. Ausgerechnet
     * Gesundheitsprüfungen laufen oft über genau solche Kurzschluss-Listener; wer sie
     * ausschließt, will sie in keinem Fall erfassen. Der `RequestSensor` prüfte seit
     * jeher immer.
     */
    public function testAnIgnoredPathStaysIgnoredWithoutASnapshot(): void
    {
        $kernel = new TestKernel(
            array_merge(self::CONFIG, ['layers' => ['kernel' => ['ignored_paths' => ['#^/ok$#']]]]),
            'ignored-paths',
        );
        $kernel->boot();

        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $this->services($kernel)->get('event_dispatcher');
        $dispatcher->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::REQUEST,
            static function (\Symfony\Component\HttpKernel\Event\RequestEvent $event): void {
                $event->setResponse(new \Symfony\Component\HttpFoundation\Response('kurzgeschlossen'));
            },
            RequestSensor::PRIORITY_CAPTURE + 1,
        );

        $kernel->handle(Request::create('/ok'), HttpKernelInterface::MAIN_REQUEST, false);

        /** @var EventBuffer $buffer */
        $buffer = $this->services($kernel)->get('ids_sensor.event_buffer');

        self::assertSame([], $buffer->all(), 'Der ausgeschlossene Pfad darf kein Event erzeugen');
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
