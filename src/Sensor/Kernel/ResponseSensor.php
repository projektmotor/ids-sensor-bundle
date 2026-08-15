<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erfasst kernel.response.
 *
 * Priorität -2048, also ganz hinten. Symfonys AbstractSessionListener liegt bei
 * -1000 und schreibt dort noch Cache-Control-Header, StreamedResponseListener bei
 * -1024. Erst danach sind Statuscode und Header die, die tatsächlich gesendet werden.
 *
 * RESPONSE wird vor FINISH_REQUEST ausgelöst, der Security-Token ist also noch im
 * Speicher — deshalb ist actor.user hier gesetzt, während er bei kernel.request meist
 * null bleibt (Konzept 2.2.2 — Nutzerkontext auf Kernel-Ebene). Genau darauf stützen
 * sich die nutzerbezogenen Kernel-Regeln B7, P1 und P2: sie aggregieren über
 * kernel.response, nicht über kernel.request.
 *
 * @internal
 */
final class ResponseSensor implements EventSubscriberInterface
{
    public const PRIORITY = -2048;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly CaptureBudget $budget,
        private readonly Options $options,
        private readonly Builder $rawBuilder,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', self::PRIORITY]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            if (!$this->options->captureResponse) {
                return;
            }

            if (!$event->isMainRequest() && !$this->options->subRequests->allowsResponseEvents()) {
                return;
            }

            $request = $event->getRequest();
            $snapshot = $this->binder->snapshotFor($request);
            $response = $event->getResponse();
            $status = $response->getStatusCode();

            if (null !== $snapshot && $this->options->isIgnored($snapshot->path)) {
                return;
            }

            // Ohne Snapshot: möglich, wenn ein Listener mit noch höherer Priorität als
            // unserer eine Antwort zurückgibt und kernel.request dadurch nie bei uns
            // ankommt. Dann sind Pfad und Korrelation nur direkt aus dem Request zu
            // bekommen.
            $captured = CapturedEvent::now(
                Layer::Kernel,
                KernelPayload::EVENT_RESPONSE,
                [
                    KernelPayload::FIELD_HTTP_STATUS => $status,
                    KernelPayload::FIELD_RESPONSE_TIME_MS => null !== $snapshot ? $snapshot->elapsedMs() : null,
                    KernelPayload::FIELD_RESPONSE_SIZE_BYTES => self::responseSize($response),
                    // Redundant aus dem Request übernommen (Konzept 3.2).
                    KernelPayload::FIELD_PATH => null !== $snapshot ? $snapshot->path : $request->getPathInfo(),
                    KernelPayload::FIELD_ROUTE => null !== $snapshot ? $snapshot->route : null,
                ],
            );

            // Dieses Event trägt das raw des GESAMTEN Austauschs — Anfrage- wie
            // Antwortseite. Grund: seine Stufe spiegelt den Ausgang des Requests, und nur
            // bei warning/critical wird raw überhaupt übertragen. Am kernel.request-Event,
            // das immer `info` ist, wäre es garantiert verworfen worden.
            $captured->setRawBuilder($this->rawBuilder->forExchange($request, $response));

            $this->binder->bindWithUser($captured, $request, $snapshot);

            $this->buffer->append($captured);
        });
    }

    /**
     * Ermittelt die Antwortgröße, ohne den Inhalt zu erzeugen.
     *
     * sendContent() wird NIE aufgerufen — das würde die Antwort ein zweites Mal
     * ausgeben. Bei StreamedResponse und BinaryFileResponse liefert getContent()
     * false; dann ist null die ehrliche Auskunft und nicht 0, weil 0 eine leere
     * Antwort behaupten würde.
     */
    private static function responseSize(Response $response): ?int
    {
        $header = $response->headers->get('Content-Length');

        if (is_numeric($header)) {
            return (int) $header;
        }

        $content = $response->getContent();

        return \is_string($content) ? \strlen($content) : null;
    }
}
