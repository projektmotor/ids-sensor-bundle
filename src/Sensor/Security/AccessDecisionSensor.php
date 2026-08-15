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
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Erfasst Autorisierungsentscheidungen (Konzept 2.1.2, event_type
 * security.access_decision).
 *
 * WARUM EIN DECORATOR AUF DEM AccessDecisionManager
 *
 * Konzept 2.1.2 verweist auf „AuthorizationCheckerInterface /
 * security.access_denied_url-Listener". Beide Wege sind untauglich, und das ist gegen
 * die Quellen geprüft:
 *
 *  - AuthorizationChecker dekorieren: Symfonys AccessListener ruft decide() DIREKT auf.
 *    Alle access_control-Entscheidungen — also die wertvollsten Ablehnungen — liefen
 *    vorbei.
 *  - TraceableAccessDecisionManager: `@internal`, liest per Reflection auf den inneren
 *    Manager und wird nur in security_debug.php registriert. In Produktion existiert er
 *    nicht.
 *  - VoteEvent: Name `debug.security.authorization.vote`, ebenfalls `@internal` und
 *    debug-only, und pro VOTER statt pro Entscheidung.
 *  - AccessDeniedException in kernel.exception: liefert nur Ablehnungen, nie `granted`
 *    (Konzept 2.2.1 verlangt beides), und nur solche, die propagieren.
 *
 * DIE SIGNATUR-FALLE
 *
 * Das Interface ist stabil, die konkrete Klasse nicht: AccessDecisionManager hat in
 * Symfony 7.x zwei Zusatzparameter (`bool|AccessDecision|null $accessDecision`,
 * `bool $allowMultipleAttributes`), in 6.4 nur einen — und echte Aufrufer übergeben sie
 * POSITIONAL. AccessListener etwa übergibt `true` als vierten Parameter. Deshalb wird
 * variadisch weitergereicht: eine feste Parameterliste würde je nach Symfony-Version
 * Argumente verschlucken und damit das Verhalten der Anwendung ändern.
 *
 * MENGENKONTROLLE
 *
 * decide() läuft bei JEDEM isGranted() — eine Übersichtsseite mit einem Voter pro Zeile
 * erzeugt beliebig viele Aufrufe. Ohne Begrenzung wäre das der teuerste Sensor des
 * Bundles. Deshalb: Dedup identischer Entscheidungen pro Request, harte Obergrenze, und
 * `granted` abschaltbar. Ablehnungen werden NIE wegoptimiert — sie sind das Signal, an
 * dem Regel R4 hängt.
 *
 * @internal
 */
final class AccessDecisionSensor implements AccessDecisionManagerInterface, ResetInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    private int $captured = 0;

    private int $overflow = 0;

    public function __construct(
        private readonly AccessDecisionManagerInterface $inner,
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly ResourceIdentifierResolver $resourceResolver,
        private readonly CaptureBudget $budget,
        private readonly RequestStack $requestStack,
        private readonly bool $captureGranted = true,
        private readonly int $maxPerRequest = 200,
    ) {
    }

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
    {
        // Die Entscheidung der Anwendung ZUERST und außerhalb jeder eigenen Logik.
        // Variadisch weitergereicht, damit versionsabhängige Zusatzparameter erhalten
        // bleiben.
        $granted = $this->inner->decide($token, $attributes, $object, ...$rest);

        $decision = $granted
            ? SecurityPayload::DECISION_GRANTED
            : SecurityPayload::DECISION_DENIED;

        // Die Erfassung darf entfallen: Autorisierungsentscheidungen sind nach oben
        // offen, genau davor schützt das Budget.
        $this->budget->guard(function () use ($token, $attributes, $object, $decision): void {
            $this->record($token, $attributes, $object, $decision);
        });

        return $granted;
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @param string                  $decision   einer der SecurityPayload::DECISION_*-Werte
     */
    private function record(TokenInterface $token, array $attributes, mixed $object, string $decision): void
    {
        if (SecurityPayload::DECISION_GRANTED === $decision && !$this->captureGranted) {
            return;
        }

        $attribute = self::stringifyAttributes($attributes);
        $resource = $this->resourceResolver->resolve($object);

        // Dedup: eine Übersichtsseite prüft dasselbe Recht auf demselben Objekt oft
        // mehrfach. Ein Event je unterschiedlicher Entscheidung genügt.
        $key = $attribute.'|'.($resource ?? '-').'|'.$decision;

        if (isset($this->seen[$key])) {
            return;
        }

        if ($this->captured >= $this->maxPerRequest) {
            ++$this->overflow;

            return;
        }

        $this->seen[$key] = true;
        ++$this->captured;

        $captured = CapturedEvent::now(
            Layer::Security,
            SecurityPayload::EVENT_ACCESS_DECISION,
            [
                SecurityPayload::FIELD_ATTRIBUTE => $attribute,
                SecurityPayload::FIELD_RESOURCE => $resource,
                SecurityPayload::FIELD_DECISION => $decision,
            ],
        );

        $this->attachContext($captured, $token);
        $this->buffer->append($captured);
    }

    private function attachContext(CapturedEvent $captured, TokenInterface $token): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $this->binder->bind($captured, $request, $this->binder->snapshotFor($request));

        // Der HANDELNDE Nutzer, nicht der geprüfte.
        //
        // Symfony 7.3 hat AuthorizationChecker::isGrantedForUser() eingeführt, das einen
        // künstlichen Token für einen ANDEREN Nutzer in decide() gibt. actor.* meint
        // aber immer den Akteur — deshalb hat der Token aus dem Speicher Vorrang, und
        // der übergebene ist nur der Rückfall.
        $user = $this->binder->currentUser();

        if (null === $user && !$token instanceof NullToken) {
            $identifier = $token->getUserIdentifier();
            $user = '' === $identifier ? null : $identifier;
        }

        $captured->setActorUser($user);
    }

    /**
     * Attribute sind nicht garantiert Zeichenketten: über `allow_if` kommen
     * Expression-Objekte, außerdem Enums und Stringable.
     *
     * Mehrere Attribute werden verbunden statt in getrennte Events aufgeteilt. Grund:
     * mit `allowMultipleAttributes = true` faltet die Strategie die Einzelergebnisse
     * zusammen — welches Attribut die Entscheidung verursacht hat, ist nicht bekannt.
     * Ein Event pro Attribut würde also Daten erfinden.
     *
     * @param array<array-key, mixed> $attributes
     */
    private static function stringifyAttributes(array $attributes): string
    {
        if ([] === $attributes) {
            return '-';
        }

        $parts = [];

        foreach ($attributes as $attribute) {
            $parts[] = self::stringifyAttribute($attribute);
        }

        return 1 === \count($parts) ? $parts[0] : implode('|', $parts);
    }

    private static function stringifyAttribute(mixed $attribute): string
    {
        if (\is_string($attribute)) {
            return $attribute;
        }

        if ($attribute instanceof \UnitEnum) {
            return (new \ReflectionClass($attribute))->getShortName().'::'.$attribute->name;
        }

        if (class_exists(Expression::class) && $attribute instanceof Expression) {
            return 'expression('.$attribute.')';
        }

        if (\is_scalar($attribute)) {
            return (string) $attribute;
        }

        if (\is_object($attribute)) {
            return (new \ReflectionClass($attribute))->getShortName();
        }

        return get_debug_type($attribute);
    }

    /**
     * Wie viele Entscheidungen wegen der Obergrenze entfielen. Wird beim Flush in die
     * Zähler übernommen, damit der Verlust sichtbar bleibt.
     */
    public function overflowCount(): int
    {
        return $this->overflow;
    }

    /**
     * Wird in Worker-Laufzeiten zwischen zwei Requests aufgerufen. Dedup-Gedächtnis und
     * Zähler sind pro Request gemeint, nicht pro Prozess.
     */
    public function reset(): void
    {
        $this->seen = [];
        $this->captured = 0;
    }
}
