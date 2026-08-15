<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

#[\PHPUnit\Framework\Attributes\CoversClass(CapturedEvent::class)]
final class CapturedEventTest extends TestCase
{
    /**
     * `actor.user` kennt zwei Zustände, nicht drei.
     *
     * Konzept 2.2.4 nennt eine Kennung oder „nicht vorhanden". `''` ist keiner von
     * beiden — für den Collector verhält es sich in einer Gruppierung wie ein eigener
     * Nutzer. Drei Aufrufstellen normalisierten das selbst, der `AuthenticationSensor`
     * an vier Stellen nicht: `getUserIdentifier()` gibt bei einem Token ohne Kennung
     * `''` zurück, und Anmeldefehlschläge sind genau der Fall, für den das Feld da ist.
     */
    public function testAnEmptyUserIdentifierBecomesNull(): void
    {
        $event = $this->event();

        $event->setActorUser('');

        self::assertNull($event->actor()->user, 'Die leere Kennung ist keine Kennung');
    }

    public function testARealUserIdentifierSurvives(): void
    {
        $event = $this->event();

        $event->setActorUser('anna');

        self::assertSame('anna', $event->actor()->user);
    }

    private function event(): CapturedEvent
    {
        return CapturedEvent::now(Layer::Security, 'security.authentication_failure');
    }
}
