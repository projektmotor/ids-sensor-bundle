<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\EventFactory;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialEventIdGenerator;

final class EventFactoryTest extends TestCase
{
    public function testAdoptsLayerAndTypeFromTheCapturedEvent(): void
    {
        $captured = new CapturedEvent(Layer::Security, 'security.authentication.failure', 1_786_000_532.421);

        $event = $this->factory()->create(
            $captured,
            $this->identity(),
            $captured->eventType,
            'req-1',
            Actor::anonymous(),
            Severity::Warning,
            ['firewall' => 'main'],
        );

        self::assertSame(Layer::Security, $event->layer);
        self::assertSame('security.authentication.failure', $event->eventType);
        self::assertSame(Severity::Warning, $event->severity);
        self::assertSame(['firewall' => 'main'], $event->payload);
    }

    public function testAssignsConsecutivelyUniqueEventIds(): void
    {
        $factory = $this->factory();
        $captured = CapturedEvent::now(Layer::Kernel, 'kernel.request');

        $first = $factory->create($captured, $this->identity(), 'kernel.request', 'req-1', Actor::anonymous(), Severity::Info, []);
        $second = $factory->create($captured, $this->identity(), 'kernel.request', 'req-1', Actor::anonymous(), Severity::Info, []);

        self::assertNotSame($first->eventId, $second->eventId);
    }

    /**
     * Der Zeitstempel entsteht aus dem im Request billig erfassten float — nicht
     * aus der Uhr zum Flush-Zeitpunkt. Sonst trügen alle Events eines Requests
     * denselben, zu späten Zeitpunkt.
     */
    public function testTheTimestampComesFromTheMomentOfCapture(): void
    {
        $captured = new CapturedEvent(Layer::Kernel, 'kernel.request', self::epochOf('2026-08-14T10:15:32.421000+00:00'));

        $event = $this->factory()->create(
            $captured,
            $this->identity(),
            $captured->eventType,
            'req-1',
            Actor::anonymous(),
            Severity::Info,
            [],
        );

        self::assertSame(
            '2026-08-14T10:15:32.421Z',
            $event->toArray()[EventSchema::FIELD_TIMESTAMP],
        );
    }

    /**
     * Der Collector misst laut Konzept 2.2.1 die Uhrendrift aus der Differenz von
     * timestamp und received_at. Läge der Zeitstempel in der lokalen Zeitzone des
     * Anwendungsservers, wäre diese Messung um den Zonenoffset verschoben.
     */
    public function testTheTimestampIsAlwaysUtcRegardlessOfTheServerTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $dateTime = EventFactory::toDateTime(self::epochOf('2026-08-14T10:15:32.421000+00:00'));

            self::assertSame('UTC', $dateTime->getTimezone()->getName());
            self::assertSame('2026-08-14T10:15:32.421Z', $dateTime->format(EventSchema::TIMESTAMP_FORMAT));
        } finally {
            date_default_timezone_set($original);
        }
    }

    private static function epochOf(string $iso8601): float
    {
        return (float) (new \DateTimeImmutable($iso8601))->format('U.u');
    }

    /**
     * Ein unbrauchbarer Zeitstempel darf keine Exception im Sensor auslösen —
     * fail-open gilt auch hier. Ein Ersatzwert ist besser als ein Abbruch.
     */
    public function testAnUnusableTimestampDoesNotThrow(): void
    {
        $dateTime = EventFactory::toDateTime(\NAN);

        self::assertSame('UTC', $dateTime->getTimezone()->getName());
    }

    public function testPassesTheRawBuilderThrough(): void
    {
        $captured = CapturedEvent::now(
            Layer::Kernel,
            'kernel.exception',
            [],
            static fn (): array => ['exception' => ['class' => 'RuntimeException']],
        );

        $event = $this->factory()->create(
            $captured,
            $this->identity(),
            $captured->eventType,
            'req-1',
            Actor::anonymous(),
            Severity::Critical,
            [],
        );

        $data = $event->toArray();
        self::assertArrayHasKey(EventSchema::FIELD_RAW, $data);
        self::assertSame(['exception' => ['class' => 'RuntimeException']], $data[EventSchema::FIELD_RAW]);
    }

    private function factory(): EventFactory
    {
        return new EventFactory(new SequentialEventIdGenerator());
    }

    private function identity(): SensorIdentity
    {
        return new SensorIdentity('shop-api', 'web-03', Environment::Prod);
    }
}
