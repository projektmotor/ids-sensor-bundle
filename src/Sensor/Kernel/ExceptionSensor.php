<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erfasst kernel.exception.
 *
 * Priorität 1024, also über Symfonys Security-ExceptionListener (Priorität 1) und
 * über ErrorListener und RouterListener (-64). Drei Gründe:
 *
 *  1. Wir sehen die ORIGINALKLASSE. Der Security-ExceptionListener ersetzt eine
 *     AccessDeniedException durch eine AccessDeniedHttpException — liefen wir später,
 *     stünde in payload.exception_class die Klasse nach der Umwandlung, was für die
 *     Forensik die schlechtere Auskunft ist.
 *  2. Wir sehen Exceptions, die spätere Listener vollständig verschlucken.
 *  3. Wir können nicht von einem Listener überholt werden, der das Event in eine
 *     Antwort verwandelt.
 *
 * Der Preis: den Statuscode müssen wir selbst ableiten. Genau dafür existiert
 * {@see HttpStatusResolver} — ohne ihn wäre eine rohe AccessDeniedException ein 500
 * und damit event_severity = critical, also ein gemeldeter Serverfehler für einen
 * bloß abgelehnten Zugriff. Konzept 2.2.1 behält critical ausdrücklich Serverfehlern
 * vor.
 *
 * @internal
 */
final class ExceptionSensor implements EventSubscriberInterface
{
    public const PRIORITY = 1024;

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly HttpStatusResolver $statusResolver,
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
        return [KernelEvents::EXCEPTION => ['onKernelException', self::PRIORITY]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            if (!$this->options->captureException) {
                return;
            }

            if (!$event->isMainRequest() && !$this->options->subRequests->allowsExceptionEvents()) {
                return;
            }

            $request = $event->getRequest();
            $snapshot = $this->binder->snapshotFor($request);
            $throwable = $event->getThrowable();
            $status = $this->statusResolver->resolve($throwable);

            if (null !== $snapshot && $this->options->isIgnored($snapshot->path)) {
                return;
            }

            $captured = CapturedEvent::now(
                Layer::Kernel,
                KernelPayload::EVENT_EXCEPTION,
                [
                    KernelPayload::FIELD_EXCEPTION_CLASS => $throwable::class,
                    KernelPayload::FIELD_EXCEPTION_MESSAGE => $throwable->getMessage(),
                    KernelPayload::FIELD_HTTP_STATUS => $status,
                    // Redundant aus dem Request übernommen (Konzept 3.2), damit die
                    // Batch-Regeln Statuscodes und Pfade ohne Self-Join aggregieren.
                    KernelPayload::FIELD_PATH => null !== $snapshot ? $snapshot->path : $request->getPathInfo(),
                    KernelPayload::FIELD_CONTENT_LENGTH => null !== $snapshot ? $snapshot->contentLength : 0,
                ],
            );

            // Der Trace wird Rahmen für Rahmen aus getTrace() gebaut — niemals über
            // getTraceAsString(), das die Aufrufargumente einbettet und damit Passwörter
            // im Klartext in den Beweisspeicher schreiben würde.
            $captured->setRawBuilder($this->rawBuilder->forException($throwable));

            $this->binder->bindWithUser($captured, $request, $snapshot);

            $this->buffer->append($captured);
        });
    }
}
