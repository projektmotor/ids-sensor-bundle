<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Security;

use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Payload\SecurityPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Erfasst Anmeldeerfolg und Anmeldefehler (Konzept 2.1.2).
 *
 * WARUM LoginSuccessEvent UND NICHT AuthenticationSuccessEvent
 *
 * Symfony dispatcht ein AuthenticationSuccessEvent unter dem Namen
 * `security.authentication.success` — genau der Zeichenkette, die Konzept 2.1.2 als
 * event_type verlangt. Das ist eine Falle: dieses Event trägt nur den Token, keinen
 * Request, keinen Firewall-Namen und keinen Authenticator. Damit ließe sich der Payload
 * aus Konzept 3.1.2 nicht füllen, und actor.ip bliebe leer. LoginSuccessEvent trägt
 * alles Nötige.
 *
 * Ein AuthenticationFailureEvent existiert im Authenticator-System nicht mehr — es hing
 * am entfernten AuthenticationProviderManager. LoginFailureEvent ist der einzige Weg.
 *
 * WARUM PRIORITÄT -128
 *
 * Symfonys SessionStrategyListener hört LoginSuccessEvent bei Priorität 0 und wechselt
 * dort die Session-ID (Schutz gegen Session-Fixation). Liefen wir davor, trüge das
 * Erfolgs-Event den Hash der ALTEN Session — und die Sitzungsverkettung des Collectors
 * bräche genau am interessantesten Punkt, dem Übergang von anonym zu angemeldet.
 * Nach -128 ist der Hash derselbe, den alle Folge-Requests tragen.
 *
 * @internal
 */
final class AuthenticationSensor implements EventSubscriberInterface
{
    public const PRIORITY = -128;

    /**
     * Konzept 3.1.2 führt die versuchte Kennung in actor.user. Sie ist
     * angreifergesteuert — Symfonys UserBadge erlaubt bis zu 4096 Zeichen — und wird
     * deshalb von {@see Actor::truncateUser()} gekürzt.
     */
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly AuthenticatorNameResolver $authenticatorNames,
        private readonly CaptureBudget $budget,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', self::PRIORITY],
            LoginFailureEvent::class => ['onLoginFailure', self::PRIORITY],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Anmeldevorgänge sind pro Request begrenzt — daher verpflichtend. Ein
        // erfolgreicher Login ist zudem das Signal, an dem Regel B5 hängt
        // (erfolgreicher Login nach Fehlversuchsserie).
        $this->budget->guardMandatory(function () use ($event): void {
            $captured = CapturedEvent::now(
                Layer::Security,
                SecurityPayload::EVENT_AUTH_SUCCESS,
                [
                    SecurityPayload::FIELD_FIREWALL => $event->getFirewallName(),
                    SecurityPayload::FIELD_AUTHENTICATOR => $this->authenticatorNames->resolve(
                        $event->getAuthenticator(),
                    ),
                ],
            );

            $request = $event->getRequest();
            $this->binder->bind($captured, $request, $this->binder->snapshotFor($request));
            $captured->setActorUser(self::authenticatedIdentifier($event));

            $this->buffer->appendMandatory($captured);
        });
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            $captured = CapturedEvent::now(
                Layer::Security,
                SecurityPayload::EVENT_AUTH_FAILURE,
                [
                    SecurityPayload::FIELD_FIREWALL => $event->getFirewallName(),
                    SecurityPayload::FIELD_FAILURE_REASON => self::failureReason($event->getException()),
                ],
            );

            $request = $event->getRequest();
            $this->binder->bind($captured, $request, $this->binder->snapshotFor($request));
            $captured->setActorUser($this->attemptedIdentifier($event));

            $this->buffer->appendMandatory($captured);
        });
    }

    /**
     * Der Grund als KURZER Klassenname, wie im Konzeptbeispiel („BadCredentialsException").
     *
     * Steigt in die Kette ab, weil der Authenticator-Manager eine
     * BadCredentialsException gelegentlich einwickelt. Gemeldet werden soll die
     * spezifischste Ursache, nicht die Hülle — Regelautoren unterscheiden anhand dieses
     * Werts zwischen Brute-Force (BadCredentials), Nutzer-Enumeration (UserNotFound)
     * und Drosselung (TooManyLoginAttempts).
     *
     * getMessage() wird NIE übertragen: CustomUserMessageAuthenticationException kann
     * Anwendungsdaten enthalten.
     */
    private static function failureReason(AuthenticationException $exception): string
    {
        $current = $exception;
        $depth = 0;

        while ($depth++ < 5) {
            $previous = $current->getPrevious();

            if (!$previous instanceof AuthenticationException) {
                break;
            }

            $current = $previous;
        }

        return (new \ReflectionClass($current))->getShortName();
    }

    /**
     * Die angemeldete Benutzerkennung — das Gegenstück zu {@see attemptedIdentifier()}.
     *
     * Sie kommt aus dem Token DES EVENTS und nicht aus dem Token-Speicher: bei Priorität
     * -128 hat der SessionStrategyListener die Sitzung bereits gewechselt, im Speicher
     * steht aber je nach Authenticator noch nichts. Das Event trägt den soeben
     * hergestellten Token und ist damit die einzige verlässliche Quelle.
     *
     * Eigene Methode, weil die Kette
     * `$event->getAuthenticatedToken()->getUserIdentifier()` an der Aufrufstelle nur
     * mitteilt, WIE der Wert beschafft wird, und nicht WAS er ist (CLAUDE.md §1.5,
     * Gesetz von Demeter).
     */
    private static function authenticatedIdentifier(LoginSuccessEvent $event): string
    {
        return $event->getAuthenticatedToken()->getUserIdentifier();
    }

    /**
     * Die versuchte Benutzerkennung — ohne jeden Datenbankzugriff.
     *
     * UserBadge hält die Kennung als Zeichenkette, unabhängig davon, ob der Nutzer
     * aufgelöst werden konnte. Deshalb funktioniert das auch bei einer
     * UserNotFoundException, und genau dieser Fall ist für die Erkennung von
     * Nutzer-Enumeration der interessante.
     *
     * Bewusst NICHT umgesetzt: das Lesen von `_username` aus dem Request-Body. Das
     * würde erfordern, den konfigurierten Parameternamen jedes Authenticators zu
     * erraten, und läse einen Body, den die Anwendung noch braucht.
     */
    private function attemptedIdentifier(LoginFailureEvent $event): ?string
    {
        $passport = $event->getPassport();

        if (null !== $passport && $passport->hasBadge(UserBadge::class)) {
            $badge = $passport->getBadge(UserBadge::class);

            if ($badge instanceof UserBadge) {
                return $badge->getUserIdentifier();
            }
        }

        $exception = $event->getException();

        if ($exception instanceof UserNotFoundException) {
            $identifier = $exception->getUserIdentifier();

            if (null !== $identifier && '' !== $identifier) {
                return $identifier;
            }
        }

        // Letzter Versuch: im Authenticator-System selten belegt, aber kostenlos.
        return $exception->getToken()?->getUserIdentifier();
    }
}
