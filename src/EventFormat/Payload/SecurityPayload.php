<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Payload;

/**
 * Die event_type-Werte und payload-Feldnamen der Security-Ebene aus Konzept 3.1.2.
 *
 * Öffentliche API: Der Collector wertet genau diese Zeichenketten aus. Die
 * DECISION_*-Werte sind dabei besonders empfindlich — auf ihnen ruhen die
 * Denial-Schwellwerte aus Konzept 4.3.1 und die Rechteausweitungs-Regeln B7/P1/P2.
 *
 * Zur Begründung, warum diese Konstanten nicht im Normalisierer stehen:
 * {@see KernelPayload}.
 *
 * Nicht enthalten: die versuchte Benutzerkennung. Sie steht laut Konzept 3.1.2 in
 * actor.user und ausdrücklich NICHT im Payload.
 */
final class SecurityPayload
{
    public const EVENT_AUTH_SUCCESS = 'security.authentication.success';
    public const EVENT_AUTH_FAILURE = 'security.authentication.failure';
    public const EVENT_ACCESS_DECISION = 'security.access_decision';

    public const FIELD_FIREWALL = 'firewall';
    public const FIELD_AUTHENTICATOR = 'authenticator';
    public const FIELD_FAILURE_REASON = 'failure_reason';
    public const FIELD_ATTRIBUTE = 'attribute';
    public const FIELD_RESOURCE = 'resource';
    public const FIELD_DECISION = 'decision';

    public const DECISION_GRANTED = 'granted';
    public const DECISION_DENIED = 'denied';

    public const MAX_ATTRIBUTE_LENGTH = 128;

    private function __construct()
    {
    }
}
