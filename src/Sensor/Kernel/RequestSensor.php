<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshot;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erfasst kernel.request — zweiphasig.
 *
 * Die Zweiphasigkeit ist der Kern dieses Sensors und der Grund, warum er nicht mit
 * einem einzelnen Listener auskommt. Symfonys eigene REQUEST-Listener liegen bei:
 * ValidateRequestListener 256, SessionListener 128, FragmentListener 48,
 * RouterListener 32, Firewall 8.
 *
 * PHASE 1 bei Priorität 1024 — Erfassung. Ganz vorne, weil sonst genau der Verkehr
 * unsichtbar bleibt, um den es geht: `/wp-admin/setup-config.php` (das Beispiel aus
 * Konzept Abschnitt 3) wird vom RouterListener bei Priorität 32 mit einer Exception
 * abgebrochen — ein Listener bei 31 läuft für diesen Request nie. Dasselbe gilt für
 * einen Rate-Limiter oder eine Wartungsseite, die früh eine Antwort zurückgibt. Auch
 * der rohe `_path`-Parameter eines /_fragment-Aufrufs ist hier noch vorhanden; der
 * FragmentListener entfernt ihn erst bei 48.
 *
 * Der Preis: `route` ist noch nicht aufgelöst (erlaubt laut Konzept 3.1.1) und
 * `actor.user` noch nicht bekannt (erlaubt laut Konzept 2.2.2 — Nutzerkontext auf
 * Kernel-Ebene).
 *
 * PHASE 2 bei Priorität 7 — Nachtrag. Direkt nach der Firewall bei 8. Trägt route
 * und actor.user in das bereits gepufferte Event nach. Das ist kostenlos, weil das
 * Event zu diesem Zeitpunkt noch unversendet im Arbeitsspeicher liegt — es wird erst
 * nach dem Absenden der Antwort serialisiert.
 *
 * @internal
 */
final class RequestSensor implements EventSubscriberInterface
{
    /** Erfassung: vor allen Listenern, die den Request abbrechen könnten. */
    public const PRIORITY_CAPTURE = 1024;

    /** Nachtrag: direkt nach der Firewall (Priorität 8). */
    public const PRIORITY_ENRICH = 7;

    public const REQUEST_ATTRIBUTE = '_ids_correlation_id';

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly RequestSnapshotRegistry $registry,
        private readonly CorrelationIdFactory $correlationIdFactory,
        private readonly ActorFactory $actorFactory,
        private readonly CaptureBudget $budget,
        private readonly RequestStack $requestStack,
        private readonly Options $options,
    ) {
    }

    /**
     * @return array<string, list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onRequestCapture', self::PRIORITY_CAPTURE],
                ['onRequestEnrich', self::PRIORITY_ENRICH],
            ],
        ];
    }

    public function onRequestCapture(RequestEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            $request = $event->getRequest();
            $snapshot = $this->createSnapshot($event);
            $this->registry->set($request, $snapshot);

            if ($this->options->exposeCorrelationAttribute) {
                // Der einzige Schreibzugriff auf den Request-Zustand, und ein
                // ausdrücklich konfigurierter: die Anwendung kann die ID damit in
                // ihre eigenen Logs übernehmen.
                $request->attributes->set(self::REQUEST_ATTRIBUTE, $snapshot->correlationId);
            }

            if (!$this->shouldCapture($event, $snapshot)) {
                return;
            }

            $captured = new CapturedEvent(
                Layer::Kernel,
                KernelPayload::EVENT_REQUEST,
                $snapshot->startedAt,
                [
                    KernelPayload::FIELD_METHOD => $snapshot->method,
                    KernelPayload::FIELD_PATH => $snapshot->path,
                    KernelPayload::FIELD_QUERY => $snapshot->query,
                    KernelPayload::FIELD_ROUTE => null,
                    KernelPayload::FIELD_USER_AGENT => $snapshot->userAgent,
                    KernelPayload::FIELD_REFERER => $snapshot->referer,
                    KernelPayload::FIELD_CONTENT_LENGTH => $snapshot->contentLength,
                ],
            );
            $captured->setCorrelationId($snapshot->correlationId);
            // KEIN raw an diesem Event: kernel.request ist laut Konzept 2.2.1 immer
            // `info`, und raw wird laut Abschnitt 3 nur bei warning/critical übertragen.
            // Ein raw hier wäre garantierter Abfall. Die Anfrageseite hängt deshalb am
            // kernel.response-Event, dessen Stufe den Ausgang des Requests spiegelt
            // (siehe Builder).
            $captured->setActor($this->actorFactory->forRequestWithoutUser($request, $snapshot));

            $snapshot->requestEvent = $captured;
            $this->buffer->append($captured);
        });
    }

    /**
     * Trägt route und actor.user nach.
     *
     * Läuft auch dann, wenn kein kernel.request-Event erfasst wurde — route wird im
     * Snapshot gebraucht, damit kernel.response sie nach Konzept 3.2 redundant
     * übernehmen kann.
     */
    public function onRequestEnrich(RequestEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            $request = $event->getRequest();
            $snapshot = $this->registry->get($request);

            if (null === $snapshot) {
                return;
            }

            $route = $request->attributes->get('_route');
            $snapshot->route = \is_string($route) && '' !== $route ? $route : null;

            $captured = $snapshot->requestEvent;

            if (null === $captured) {
                return;
            }

            $captured->set(KernelPayload::FIELD_ROUTE, $snapshot->route);
            $captured->setActorUser($this->actorFactory->currentUser());
        });
    }

    private function shouldCapture(RequestEvent $event, RequestSnapshot $snapshot): bool
    {
        if (!$this->options->captureRequest) {
            return false;
        }

        if (!$event->isMainRequest() && !$this->options->subRequests->allowsRequestEvents()) {
            return false;
        }

        return !$this->options->isIgnored($snapshot->path);
    }

    private function createSnapshot(RequestEvent $event): RequestSnapshot
    {
        $request = $event->getRequest();
        $isMainRequest = $event->isMainRequest();
        $parent = $isMainRequest ? null : $this->requestStack->getParentRequest();

        return new RequestSnapshot(
            $this->correlationIdFor($event),
            self::startedAt($request),
            $isMainRequest,
            $request->getMethod(),
            $request->getPathInfo(),
            $request->query->all(),
            self::contentLength($request),
            $request->headers->get('User-Agent'),
            $request->headers->get('Referer'),
            $parent?->getPathInfo(),
        );
    }

    /**
     * Sub-Requests erben die correlation_id des Haupt-Requests, damit ihre Events dem
     * auslösenden Vorgang zuzuordnen bleiben.
     */
    private function correlationIdFor(RequestEvent $event): string
    {
        if (!$event->isMainRequest()) {
            $main = $this->registry->mainSnapshot();

            if (null !== $main) {
                return $main->correlationId;
            }
        }

        return $this->correlationIdFactory->forRequest($event->getRequest());
    }

    /**
     * REQUEST_TIME_FLOAT umfasst auch das Booten des Frameworks — und genau dort
     * zeigt sich eine Überlastung zuerst. Fehlt der Wert (etwa in CLI-getriebenen
     * Laufzeiten), ist die aktuelle Zeit die beste verfügbare Näherung.
     */
    private static function startedAt(Request $request): float
    {
        $value = $request->server->get('REQUEST_TIME_FLOAT');

        return is_numeric($value) ? (float) $value : microtime(true);
    }

    /**
     * Bei Chunked-Uploads fehlt der Header — dann ist 0 das Ergebnis. Ein ehrlicher
     * blinder Fleck für Volumenregeln, der dokumentiert gehört.
     */
    private static function contentLength(Request $request): int
    {
        $value = $request->headers->get('Content-Length');

        return is_numeric($value) ? (int) $value : 0;
    }
}
