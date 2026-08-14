<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Payload\SecurityPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\EventFactory;
use ProjektMotor\IdsSensor\Processing\Normalization\SecurityEventNormalizer;
use ProjektMotor\IdsSensor\Processing\Normalization\SeverityResolver;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialEventIdGenerator;

/**
 * Prüft die Payload-Strukturen aus Konzept 3.1.2 feldgenau.
 */
final class SecurityEventNormalizerTest extends TestCase
{
    public function testSupportsOnlyTheSecurityLayer(): void
    {
        $normalizer = $this->normalizer();

        self::assertTrue($normalizer->supports(CapturedEvent::now(Layer::Security, 'security.access_decision')));
        self::assertFalse($normalizer->supports(CapturedEvent::now(Layer::Kernel, 'kernel.request')));
        self::assertFalse($normalizer->supports(CapturedEvent::now(Layer::Business, 'order.amount_overridden')));
    }

    /**
     * Konzept 3.1.2 — security.authentication.success: die Benutzerkennung steht in
     * actor.user und ausdrücklich NICHT im Payload.
     */
    public function testLoginSuccessHasTheFieldsFromTheConcept(): void
    {
        $event = $this->normalize(SecurityPayload::EVENT_AUTH_SUCCESS, [
            SecurityPayload::FIELD_FIREWALL => 'main',
            SecurityPayload::FIELD_AUTHENTICATOR => 'form_login',
        ]);

        self::assertSame(['firewall' => 'main', 'authenticator' => 'form_login'], $event->payload);
        self::assertSame(Severity::Info, $event->severity);
    }

    public function testLoginFailureIsWarningAndNamesTheReason(): void
    {
        $event = $this->normalize(SecurityPayload::EVENT_AUTH_FAILURE, [
            SecurityPayload::FIELD_FIREWALL => 'main',
            SecurityPayload::FIELD_FAILURE_REASON => 'BadCredentialsException',
        ]);

        self::assertSame(['firewall' => 'main', 'failure_reason' => 'BadCredentialsException'], $event->payload);
        self::assertSame(Severity::Warning, $event->severity);
    }

    public function testDeniedDecisionIsWarning(): void
    {
        $event = $this->normalize(SecurityPayload::EVENT_ACCESS_DECISION, [
            SecurityPayload::FIELD_ATTRIBUTE => 'VIEW',
            SecurityPayload::FIELD_RESOURCE => 'Order#42',
            SecurityPayload::FIELD_DECISION => 'denied',
        ]);

        self::assertSame(
            ['attribute' => 'VIEW', 'resource' => 'Order#42', 'decision' => 'denied'],
            $event->payload,
        );
        self::assertSame(Severity::Warning, $event->severity);
    }

    /**
     * Der Positivpfad ist info: er trägt die Regeln, die erfolgreiche Zugriffe
     * auswerten, darf aber die 30-Tage-Retention nicht mit Rauschen füllen.
     */
    public function testAGrantedDecisionIsInfo(): void
    {
        $event = $this->normalize(SecurityPayload::EVENT_ACCESS_DECISION, [
            SecurityPayload::FIELD_ATTRIBUTE => 'ROLE_USER',
            SecurityPayload::FIELD_RESOURCE => null,
            SecurityPayload::FIELD_DECISION => 'granted',
        ]);

        self::assertSame(Severity::Info, $event->severity);
    }

    /**
     * Attribute sind angreifernah: über `allow_if` kommen Expression-Objekte, und ein
     * Voter-Attribut kann aus einem Request-Parameter stammen. Ohne Obergrenze ließe
     * sich das Event beliebig aufblähen.
     */
    public function testAnOverlongAttributeIsTruncated(): void
    {
        $event = $this->normalize(SecurityPayload::EVENT_ACCESS_DECISION, [
            SecurityPayload::FIELD_ATTRIBUTE => str_repeat('A', 500),
            SecurityPayload::FIELD_DECISION => 'denied',
        ]);

        $attribute = $event->payload['attribute'];
        self::assertIsString($attribute);
        self::assertSame(SecurityPayload::MAX_ATTRIBUTE_LENGTH, mb_strlen($attribute));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function normalize(string $eventType, array $fields): NormalizedEvent
    {
        $captured = CapturedEvent::now(Layer::Security, $eventType, $fields);
        $captured->setCorrelationId('11111111-1111-4111-8111-111111111111');
        $captured->setActor(new Actor('alice', '203.0.113.7'));

        return $this->normalizer()->normalize($captured, $this->identity());
    }

    private function normalizer(): SecurityEventNormalizer
    {
        return new SecurityEventNormalizer(
            new EventFactory(new SequentialEventIdGenerator()),
            new SeverityResolver(),
        );
    }

    private function identity(): SensorIdentity
    {
        return new SensorIdentity('shop-api', 'web-03', Environment::Prod);
    }
}
