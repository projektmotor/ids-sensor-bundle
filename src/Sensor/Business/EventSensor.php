<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Business;

use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Nimmt Business-Events auf — der gemeinsame Endpunkt aller drei Erfassungsmodi.
 *
 * Egal ob das Event über den dekorierten EventDispatcher, den expliziten Recorder oder
 * einen konfigurierten Listener kommt: es landet hier. Damit existiert die
 * Normalisierung genau einmal.
 *
 * JEDER Aufruf in die Anwendung ist einzeln abgesichert. Das ist keine Vorsicht auf
 * Verdacht: getEventName(), getSeverityHint(), getActorId() und getPayload() sind von
 * der überwachten Anwendung implementiert und können werfen — etwa weil getActorId()
 * auf eine nicht geladene Beziehung zugreift. Ein Fehler dort darf das Event kosten,
 * aber nie den Request (Konzept 4. — fail-open).
 *
 * @internal
 */
final class EventSensor
{
    /**
     * Die Übergabeschlüssel an den Normalisierer.
     *
     * Bewusst hier und nicht im Normalisierer: geschrieben werden sie hier, gelesen
     * dort — und wer schreibt, legt den Schlüssel fest. Andernfalls hinge der Sensor
     * (Phase A, im Request unter dem Budget aus Konzept 2.1) am Normalisierer
     * (Phase B, nach dem Absenden der Antwort), und das ist genau die Richtung, die
     * die Phasengrenze verwischt.
     *
     * Der Unterstrich-Präfix hält sie aus dem übertragenen Payload heraus: Konzept
     * 2.1.3 reserviert solche Schlüssel, und der Normalisierer entfernt sie beim
     * Übersetzen. Sie stehen deshalb NICHT im Ereignisformat-Paket — sie sind nie auf
     * der Leitung.
     */
    public const FIELD_EVENT_NAME = '_event_name';
    public const FIELD_SEVERITY_HINT = '_severity_hint';
    public const FIELD_ACTOR_ID = '_actor_id';
    public const FIELD_PAYLOAD = '_payload';
    public const FIELD_EVENT_CLASS = '_event_class';

    /**
     * Welche Getter des Anwendungs-Events geworfen haben.
     *
     * Ohne diese Liste war ein kaputter Getter von einem leeren Wert nicht zu
     * unterscheiden: `getEventName()`, das wirft, ergab denselben Ersatzwert wie
     * `getEventName()`, das `''` zurückgibt — im Frame stand danach
     * `business.unnamed` und ein Vermerk mit LEEREM Originalnamen. Das las sich wie
     * „die Anwendung hat ihr Event nicht benannt" und war in Wahrheit ein Defekt in
     * der überwachten Anwendung, den niemand je erfuhr.
     *
     * @var string
     */
    public const FIELD_UNREADABLE = '_unreadable';

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly CaptureBudget $budget,
        private readonly RequestStack $requestStack,
        private readonly bool $userFromToken = true,
        private readonly bool $ipFromRequest = true,
    ) {
    }

    public function capture(SecurityRelevantBusinessEvent $event): void
    {
        // guard() und nicht guardMandatory(): Business-Events sind nach oben offen —
        // eine Massenoperation kann beliebig viele auslösen. Genau davor soll das
        // Budget schützen.
        $this->budget->guard(function () use ($event): void {
            $data = $this->readContract($event);

            $captured = CapturedEvent::now(
                Layer::Business,
                (string) $data[self::FIELD_EVENT_NAME],
                $data,
            );

            $this->attachContext($captured, $event);
            $this->buffer->append($captured);
        });
    }

    /**
     * Liest den Vertrag aus Konzept 2.1.3 — jeden Aufruf einzeln abgesichert.
     *
     * @return array<string, mixed>
     */
    private function readContract(SecurityRelevantBusinessEvent $event): array
    {
        /** @var list<string> $unreadable */
        $unreadable = [];

        return [
            self::FIELD_EVENT_NAME => $this->safely(
                static fn (): string => $event->getEventName(),
                '',
                'event_name',
                $unreadable,
            ),
            self::FIELD_SEVERITY_HINT => $this->safely(
                static fn (): string => $event->getSeverityHint(),
                '',
                'severity_hint',
                $unreadable,
            ),
            self::FIELD_ACTOR_ID => $this->safely(
                static fn (): ?string => $event->getActorId(),
                null,
                'actor_id',
                $unreadable,
            ),
            self::FIELD_PAYLOAD => $this->safely(
                static fn (): array => $event->getPayload(),
                [],
                'payload',
                $unreadable,
            ),
            self::FIELD_EVENT_CLASS => $event::class,
            self::FIELD_UNREADABLE => $unreadable,
        ];
    }

    /**
     * Der Ersatzwert ist nur die halbe Antwort — die andere ist, DASS er nötig war.
     *
     * @template T
     *
     * @param callable():T $read
     * @param T            $fallback
     * @param list<string> $unreadable wird um $field ergänzt, wenn der Getter wirft
     *
     * @return T
     */
    private function safely(callable $read, mixed $fallback, string $field, array &$unreadable): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            $unreadable[] = $field;

            return $fallback;
        }
    }

    /**
     * Setzt Korrelation und Akteur.
     *
     * Zur Abweichung bei actor.ip: Konzept 2.2.4 sieht dort null vor, „sofern nicht im
     * Payload mitgeliefert". Das ist für den Worker-Fall geschrieben. Läuft das Event
     * aber innerhalb eines HTTP-Requests, ist die IP nachweislich vorhanden — sie zu
     * unterdrücken würde die Korrelationsregel X3 (Login gefolgt von kritischer
     * Aktion) unnötig schwächen, ohne etwas zu gewinnen: dieselbe IP steht schon in den
     * Kernel-Events desselben Requests. Über `ip_from_request: false` lässt sich das
     * wörtliche Konzeptverhalten wiederherstellen.
     */
    private function attachContext(CapturedEvent $captured, SecurityRelevantBusinessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $actorId = $captured->get(self::FIELD_ACTOR_ID);
        $actorId = \is_string($actorId) && '' !== $actorId ? $actorId : null;

        $this->binder->bind($captured, $request, $this->binder->snapshotFor($request));

        if (!$this->ipFromRequest) {
            $captured->setActor($captured->actor()->withoutIp());
        }

        // getActorId() hat Vorrang; erst wenn es nichts liefert, greift der Token.
        // Ohne diesen Rückfall verlieren Events aus Diensten, die den Akteur nicht
        // kennen, den Nutzerbezug — und damit bricht die Verkettung mit der
        // Security-Ebene (Regel X3).
        if (null !== $actorId) {
            $captured->setActorUser($actorId);
        } elseif ($this->userFromToken) {
            $captured->setActorUser($this->binder->currentUser());
        }
    }
}
