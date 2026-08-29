<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Payload\ResourceReference;
use ProjektMotor\IdsEventData\Payload\SecurityPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

/**
 * Normalisiert die Security-Ebene nach Konzept 2.2.3 und 3.1.2.
 *
 * @internal
 */
final class SecurityEventNormalizer implements EventNormalizerInterface
{
    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly SeverityResolver $severityResolver,
    ) {
    }

    public function supports(CapturedEvent $captured): bool
    {
        return Layer::Security === $captured->layer;
    }

    public function normalize(CapturedEvent $captured, SensorIdentity $identity): NormalizedEvent
    {
        $decision = $captured->get(SecurityPayload::FIELD_DECISION);
        $decision = \is_string($decision) ? $decision : null;

        return $this->eventFactory->create(
            $captured,
            $identity,
            $captured->eventType,
            $captured->correlationId() ?? '',
            $captured->actor(),
            $this->severityResolver->forSecurity($captured->eventType, $decision),
            $this->payloadFor($captured),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(CapturedEvent $captured): array
    {
        return match ($captured->eventType) {
            // Konzept 3.1.2: die versuchte Benutzerkennung steht in actor.user, NICHT
            // im Payload — ausdrücklich zur Vermeidung von Redundanz.
            SecurityPayload::EVENT_AUTH_SUCCESS => [
                SecurityPayload::FIELD_FIREWALL => FieldValue::asString($captured->get(SecurityPayload::FIELD_FIREWALL)),
                SecurityPayload::FIELD_AUTHENTICATOR => FieldValue::asString($captured->get(SecurityPayload::FIELD_AUTHENTICATOR)),
            ],
            SecurityPayload::EVENT_AUTH_FAILURE => [
                SecurityPayload::FIELD_FIREWALL => FieldValue::asString($captured->get(SecurityPayload::FIELD_FIREWALL)),
                SecurityPayload::FIELD_FAILURE_REASON => FieldValue::asString($captured->get(SecurityPayload::FIELD_FAILURE_REASON)),
            ],
            SecurityPayload::EVENT_ACCESS_DECISION => [
                SecurityPayload::FIELD_ATTRIBUTE => FieldValue::truncate(
                    FieldValue::asString($captured->get(SecurityPayload::FIELD_ATTRIBUTE)),
                    SecurityPayload::MAX_ATTRIBUTE_LENGTH,
                ),
                SecurityPayload::FIELD_RESOURCE => FieldValue::asString($captured->get(SecurityPayload::FIELD_RESOURCE)),
                ResourceReference::FIELD_RESOURCE_TYPE => FieldValue::truncate(
                    FieldValue::asString($captured->get(ResourceReference::FIELD_RESOURCE_TYPE)),
                    ResourceReference::MAX_TYPE_LENGTH,
                ),
                ResourceReference::FIELD_RESOURCE_ID => FieldValue::truncate(
                    FieldValue::asString($captured->get(ResourceReference::FIELD_RESOURCE_ID)),
                    ResourceReference::MAX_ID_LENGTH,
                ),
                SecurityPayload::FIELD_DECISION => FieldValue::asString($captured->get(SecurityPayload::FIELD_DECISION)),
            ],
            // Konzept 3.1.2: actor.user trägt den Übernehmenden, target_user den
            // Übernommenen. Kein firewall — SwitchUserEvent trägt keinen, siehe
            // {@see \ProjektMotor\IdsSensor\Sensor\Security\SwitchUserSensor}.
            SecurityPayload::EVENT_SWITCH_USER,
            SecurityPayload::EVENT_SWITCH_USER_EXIT => [
                SecurityPayload::FIELD_TARGET_USER => FieldValue::asString($captured->get(SecurityPayload::FIELD_TARGET_USER)),
            ],
            default => [],
        };
    }
}
