<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\EventFormat\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;

final class NormalizedEventTest extends TestCase
{
    /**
     * Der Formatvertrag gegenüber dem Collector: die Pflichtfelder aus Konzept
     * Abschnitt 3 müssen ausnahmslos vorhanden sein. Der Test liest die Liste aus
     * EventSchema, damit ein späteres Feld nicht vergessen werden kann.
     */
    public function testSerializationContainsAllMandatoryFields(): void
    {
        $data = $this->event()->toArray();

        foreach (EventSchema::MANDATORY_FIELDS as $field) {
            self::assertArrayHasKey($field, $data, \sprintf('Pflichtfeld "%s" fehlt', $field));
        }
    }

    /**
     * Die vier actor.*-Felder sind laut Konzept Abschnitt 3 immer vorhanden, aber
     * nullable — auch im CLI-Kontext ohne HTTP-Request darf keines fehlen.
     */
    public function testActorFieldsAreAlwaysPresentEvenWhenNull(): void
    {
        $data = $this->event(actor: Actor::anonymous())->toArray();

        self::assertIsArray($data[EventSchema::FIELD_ACTOR]);
        foreach (EventSchema::ACTOR_FIELDS as $field) {
            self::assertArrayHasKey($field, $data[EventSchema::FIELD_ACTOR]);
            self::assertNull($data[EventSchema::FIELD_ACTOR][$field]);
        }
    }

    public function testTheSchemaVersionIsHardWired(): void
    {
        $data = $this->event()->toArray();

        self::assertSame(1, $data[EventSchema::FIELD_SCHEMA_VERSION]);
    }

    public function testTimestampInUtcWithMilliseconds(): void
    {
        $timestamp = new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00');

        $data = $this->event(timestamp: $timestamp)->toArray();

        self::assertSame('2026-08-14T10:15:32.421Z', $data[EventSchema::FIELD_TIMESTAMP]);
    }

    /**
     * Konzept Abschnitt 3: raw wird nur für warning und critical übertragen. Für
     * info darf der raw-Builder nicht einmal aufgerufen werden — der info-Pfad ist
     * die Masse aller Events und soll nichts dafür zahlen.
     */
    #[DataProvider('rawGatingProvider')]
    public function testRawOnlyForWarningAndCritical(Severity $severity, bool $expectRaw): void
    {
        $builderCalled = false;
        $rawBuilder = static function () use (&$builderCalled): array {
            $builderCalled = true;

            return ['note' => 'raw'];
        };

        $data = $this->event(severity: $severity, rawBuilder: $rawBuilder)->toArray();

        self::assertSame($expectRaw, \array_key_exists(EventSchema::FIELD_RAW, $data));
        self::assertSame($expectRaw, $builderCalled, 'Der raw-Builder darf bei info nicht aufgerufen werden');
    }

    /**
     * @return iterable<string, array{Severity, bool}>
     */
    public static function rawGatingProvider(): iterable
    {
        yield 'info ohne raw' => [Severity::Info, false];
        yield 'warning mit raw' => [Severity::Warning, true];
        yield 'critical mit raw' => [Severity::Critical, true];
    }

    public function testEmptyRawIsOmitted(): void
    {
        $data = $this->event(
            severity: Severity::Critical,
            rawBuilder: static fn (): array => [],
        )->toArray();

        self::assertArrayNotHasKey(EventSchema::FIELD_RAW, $data);
    }

    /**
     * Ohne Sampling soll das Feld gar nicht auftauchen — 1.0 ist der dokumentierte
     * Default und würde jedes Event unnötig verbreitern.
     */
    public function testTheSamplingRateIsOmittedWithoutSampling(): void
    {
        self::assertArrayNotHasKey(
            EventSchema::FIELD_SAMPLING_RATE,
            $this->event()->toArray(),
        );
        self::assertArrayNotHasKey(
            EventSchema::FIELD_SAMPLING_RATE,
            $this->event()->withSamplingRate(1.0)->toArray(),
        );
    }

    public function testTheSamplingRateIsTransmittedWhenSamplingIsActive(): void
    {
        $data = $this->event()->withSamplingRate(0.1)->toArray();

        self::assertSame(0.1, $data[EventSchema::FIELD_SAMPLING_RATE]);
    }

    public function testEnumsAreSerializedAsString(): void
    {
        $data = $this->event()->toArray();

        self::assertSame('kernel', $data[EventSchema::FIELD_LAYER]);
        self::assertSame('info', $data[EventSchema::FIELD_EVENT_SEVERITY]);
        self::assertSame('prod', $data[EventSchema::FIELD_ENVIRONMENT]);
    }

    public function testSerializationIsJsonCapable(): void
    {
        $json = json_encode($this->event()->toArray(), \JSON_THROW_ON_ERROR);

        self::assertJson($json);
    }

    private function event(
        ?\DateTimeImmutable $timestamp = null,
        Severity $severity = Severity::Info,
        ?Actor $actor = null,
        ?\Closure $rawBuilder = null,
    ): NormalizedEvent {
        return new NormalizedEvent(
            'b3f1e6b0-6e3a-4c9a-9f2e-2a6a2f4b9c11',
            $timestamp ?? new \DateTimeImmutable('2026-08-14T10:15:32.421000+00:00'),
            Layer::Kernel,
            'kernel.request',
            'req-7f2a1c',
            $severity,
            new SensorIdentity('shop-api', 'web-03', Environment::Prod),
            $actor ?? new Actor('alice', '203.0.113.42', 'a3f9c1d8', 'c71b04ae'),
            ['method' => 'GET', 'path' => '/api/orders/42'],
            $rawBuilder,
        );
    }
}
