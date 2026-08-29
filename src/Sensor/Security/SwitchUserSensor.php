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
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Erfasst die Übernahme einer fremden Identität und ihr Ende (Konzept 2.1.2).
 *
 * WOZU
 *
 * Konzept 6.3 führte das als offenen Punkt OB10: Symfonys `SwitchUserListener` erzeugt
 * KEINES der drei übrigen Security-Ereignisse — weder eine Anmeldung noch eine
 * Autorisierungsentscheidung. Ein Administrator, der in ein Kundenkonto wechselt,
 * hinterließ damit überhaupt keine Spur, und alles, was er danach tat, sah aus wie eine
 * Handlung des Kunden.
 *
 * ZWEI EREIGNISSE, WEIL EINES NICHT REICHT
 *
 * Ohne das Ende der Übernahme bliebe jede spätere Handlung unter der fremden Identität
 * dauerhaft von einer echten Handlung des Übernommenen ununterscheidbar. Erst die beiden
 * Typen klammern das Zeitfenster, in dem die Zuordnung nicht stimmt.
 *
 * Symfony feuert für beide Richtungen dasselbe Framework-Event. Unterschieden wird am
 * Token: Beim Wechsel HINEIN trägt das Ereignis den frisch gebauten
 * {@see SwitchUserToken}, beim Verlassen den wiederhergestellten ursprünglichen. Das ist
 * keine Heuristik, sondern die Bauweise des Listeners.
 *
 * WER IST DER AKTEUR
 *
 * `actor.user` ist der Übernehmende, `payload.target_user` der Übernommene — beim
 * Wechsel hinein also der Administrator beziehungsweise der Kunde. Andersherum wäre der
 * Vorgang von einer gewöhnlichen Handlung des Kunden nicht zu unterscheiden, und genau
 * diese Unterscheidung ist der Zweck des Ereignisses.
 *
 * Der Übernehmende steht dabei im ORIGINALTOKEN des SwitchUserToken und nicht im
 * Token-Speicher: Der Listener setzt den neuen Token erst NACH dem Ereignis. Beim
 * Verlassen gibt es kein Original mehr — dort ist der Akteur der Wiederhergestellte und
 * damit identisch mit dem Ziel.
 *
 * KEIN `firewall` IM PAYLOAD
 *
 * `SwitchUserEvent` trägt keinen Firewall-Namen, und `TokenInterface` kennt
 * `getFirewallName()` nicht — nur einzelne Token-Klassen tun das. Ihn über eine
 * Typprüfung herbeizuraten hieße, ein Pflichtfeld des Drahtformats von der Bauart eines
 * fremden Tokens abhängig zu machen. Die Autorisierungsentscheidung
 * ({@see AccessDecisionSensor}) kommt aus demselben Grund ohne ihn aus.
 *
 * @internal
 */
final class SwitchUserSensor implements EventSubscriberInterface
{
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly CaptureBudget $budget,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        // Symfonys SecurityEvents::SWITCH_USER ist ein Name, kein Klassenbezug — die
        // Klasse selbst genügt als Schlüssel und kommt ohne SecurityBundle aus.
        return [SwitchUserEvent::class => 'onSwitchUser'];
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        // Höchstens ein Wechsel je Request: der Listener bricht danach ab. Damit ist die
        // Erfassung konstruktionsbedingt begrenzt und gehört nicht ins Budget.
        $this->budget->guardMandatory(function () use ($event): void {
            $token = $event->getToken();

            $captured = CapturedEvent::now(
                Layer::Security,
                self::eventTypeFor($token),
                [SecurityPayload::FIELD_TARGET_USER => self::identifierOf($event->getTargetUser())],
            );

            $request = $event->getRequest();
            $this->binder->bind($captured, $request, $this->binder->snapshotFor($request));
            $captured->setActorUser(self::impersonatorOf($token));

            $this->buffer->appendMandatory($captured);
        });
    }

    /**
     * Hinein oder hinaus — abgelesen am Token.
     *
     * Der Rückfall bei einem FEHLENDEN Token ist die Übernahme, nicht ihr Ende. Der
     * Fall entsteht nicht im Framework — beide Pfade des Listeners übergeben einen —,
     * sondern nur, wenn fremder Code das Ereignis selbst auslöst. Dann ist die Richtung
     * unbekannt, und von zwei Fehlern ist der kleinere, ein Ende als Übernahme zu
     * melden: Eine übersehene Rechteübernahme ist genau die Lücke, gegen die dieses
     * Ereignis existiert.
     */
    private static function eventTypeFor(?TokenInterface $token): string
    {
        return $token instanceof SwitchUserToken || null === $token
            ? SecurityPayload::EVENT_SWITCH_USER
            : SecurityPayload::EVENT_SWITCH_USER_EXIT;
    }

    /**
     * Wer die Übernahme ausführt.
     *
     * Beim Wechsel hinein der Nutzer des Originaltokens — beim Verlassen gibt es keines
     * mehr, dort ist es der Wiederhergestellte selbst. Beide Kennungen laufen durch
     * {@see Actor::truncateUser()}, weil eine Benutzerkennung in Symfony bis zu 4096
     * Zeichen lang sein darf.
     */
    private static function impersonatorOf(?TokenInterface $token): ?string
    {
        if ($token instanceof SwitchUserToken) {
            return self::truncate($token->getOriginalToken()->getUserIdentifier());
        }

        return null === $token ? null : self::truncate($token->getUserIdentifier());
    }

    private static function identifierOf(UserInterface $user): ?string
    {
        return self::truncate($user->getUserIdentifier());
    }

    private static function truncate(string $identifier): ?string
    {
        return '' === $identifier ? null : Actor::truncateUser($identifier);
    }
}
