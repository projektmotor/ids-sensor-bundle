<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\MessageSerializer;
use ProjektMotor\IdsSensor\IdsSensorBundle;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft Bus, Serializer und Routing durch den echten Container.
 *
 * Der in-memory-Transport läuft OHNE `serialize=true`. Grund: mit dem Flag ruft
 * getSent() den Serializer auch zum Dekodieren auf — und decode() ist hier
 * absichtlich nicht unterstützt, weil der Sensor grundsätzlich nicht vom Broker
 * liest (Manipulationsgrenze aus Konzept 2.). Die Kodierbarkeit wird stattdessen
 * direkt am echten, vom Flusher erzeugten Frame geprüft; das ist die aussagekräftigere
 * Variante, weil dort echte Zeitstempel, UUIDs und Akteursdaten drinstehen.
 */
final class TransportTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => 'shop-api',
        'environment' => 'prod',
        'session_hash' => ['key' => self::SESSION_KEY],
        'transport' => ['dsn' => 'in-memory://'],
        // Der Heartbeat läuft über denselben Bus und würde hier mitgezählt. Ihn
        // abzuschalten ist nicht Bequemlichkeit, sondern beseitigt eine
        // Reihenfolgeabhängigkeit: seine Drosselung liegt prozessweit in APCu, also hinge
        // die Zahl der Nachrichten davon ab, ob in derselben PHPUnit-Prozessinstanz vorher
        // schon ein Test mit derselben application_id gelaufen ist. Der eigene Bus-Weg des
        // Heartbeats ist in HeartbeatTest geprüft.
        'heartbeat' => ['enabled' => false],
    ];

    public function testTheFrameGoesToTheTransportOverItsOwnBus(): void
    {
        $kernel = $this->boot('transport');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());

        $kernel->terminate(Request::create('/'), new Response());

        $sent = $this->transport($services)->getSent();

        self::assertCount(1, $sent, 'Ein Request ergibt genau eine Nachricht');
        self::assertInstanceOf(EventBatch::class, $sent[0]->getMessage());
    }

    /**
     * Ein Request bündelt seine Events zu EINER Nachricht. Sonst wären es N
     * Netzwerk-Roundtrips — Messengers Redis-Transport bündelt nicht selbst.
     */
    public function testSeveralEventsBecomeOneMessage(): void
    {
        $kernel = $this->boot('transport');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());
        $collector->append($this->kernelResponse());

        $kernel->terminate(Request::create('/'), new Response());

        $sent = $this->transport($services)->getSent();
        self::assertCount(1, $sent);

        /** @var EventBatch $message */
        $message = $sent[0]->getMessage();
        self::assertSame(2, $message->eventCount());
    }

    /**
     * Die eigentliche Zusage: ein ECHTER Frame — mit echten Zeitstempeln, UUIDs und
     * Akteursdaten, erzeugt vom Flusher — muss durch den Serializer gehen und alle
     * Pflichtfelder aus Konzept Abschnitt 3 behalten.
     *
     * Ein handgeschriebenes Array würde diese Zusage nicht prüfen; genau hier
     * verstecken sich nicht kodierbare Werte.
     */
    public function testARealFrameIsFullyEncodable(): void
    {
        $kernel = $this->boot('transport');
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        $collector->append($this->kernelRequest());
        $kernel->terminate(Request::create('/'), new Response());

        /** @var EventBatch $message */
        $message = $this->transport($services)->getSent()[0]->getMessage();

        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);
        $body = $serializer->encode(new \Symfony\Component\Messenger\Envelope($message))['body'];

        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('direct', $decoded['dispatch_path']);
        self::assertCount(1, $decoded['events']);

        foreach (EventSchema::MANDATORY_FIELDS as $field) {
            self::assertArrayHasKey(
                $field,
                $decoded['events'][0],
                \sprintf('Pflichtfeld "%s" hat den Transport nicht überlebt', $field),
            );
        }
    }

    /**
     * Der Sensor spricht den Transport unmittelbar an — es gibt KEINEN eigenen Bus mehr.
     *
     * Ein eigener Bus über `framework.messenger.buses` hat den Standard-Bus der überwachten
     * Anwendung ersetzt; die Begründung steht in MessengerShipper und der Nachweis in
     * MessengerInteroperabilityTest.
     */
    public function testTheTransportIsReachableUnderTheAliasAndThereIsNoOwnBus(): void
    {
        $services = $this->services($this->boot('transport'));

        self::assertTrue(
            $services->has(IdsSensorBundle::TRANSPORT_ID),
            'Der Alias auf messenger.transport.<name> fehlt',
        );
        self::assertFalse(
            $services->has('ids_sensor.bus'),
            'Es darf keinen eigenen Message-Bus mehr geben — er hat den Standard-Bus der Anwendung ersetzt',
        );
    }

    /**
     * Der Serializer muss JSON liefern und den Typ im Header führen, damit der
     * Collector Event-Batches von Heartbeats unterscheiden kann.
     */
    public function testTheSerializerReturnsJsonWithATypeHeader(): void
    {
        $services = $this->services($this->boot('transport'));

        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);

        $encoded = $serializer->encode(new \Symfony\Component\Messenger\Envelope(
            new EventBatch(['v' => 1, 'events' => [['event_type' => 'kernel.request']]]),
        ));

        self::assertJson($encoded['body']);
        self::assertSame(MessageSerializer::TYPE_EVENT_BATCH, $encoded['headers'][MessageSerializer::HEADER_TYPE]);
        self::assertSame(
            EventSchema::SCHEMA_VERSION,
            $encoded['headers'][MessageSerializer::HEADER_SCHEMA_VERSION],
        );

        // Kein PHP-serialisiertes Objekt auf dem Draht: das wäre ein
        // Deserialisierungs-Pfad in den Beweisspeicher.
        self::assertStringNotContainsString('O:', $encoded['body']);
        self::assertIsArray(json_decode($encoded['body'], true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * Angreiferkontrollierte Werte enthalten regelmäßig ungültiges UTF-8 — genau das
     * sendet ein Scanner. Ohne JSON_INVALID_UTF8_SUBSTITUTE würde json_encode false
     * liefern und der gesamte Frame wäre verloren: ein gezielt auslösbarer blinder
     * Fleck.
     */
    public function testInvalidUtf8DoesNotBreakDispatch(): void
    {
        $services = $this->services($this->boot('transport'));

        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);

        $encoded = $serializer->encode(new \Symfony\Component\Messenger\Envelope(
            new EventBatch(['events' => [['payload' => ['path' => "/kaputt\xC3\x28"]]]]),
        ));

        self::assertJson($encoded['body']);
    }

    /**
     * Der Sensor liest grundsätzlich nicht vom Broker — das ist die
     * Manipulationsgrenze aus Konzept 2., nicht eine fehlende Umsetzung.
     */
    public function testDecodeIsExplicitlyUnsupported(): void
    {
        $serializer = new MessageSerializer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/liest grundsätzlich nicht vom Broker/');

        $serializer->decode(['body' => '{}', 'headers' => []]);
    }

    /**
     * Ohne DSN bleibt der NullShipper stehen — das Bundle muss ohne Broker
     * installierbar sein.
     */
    public function testWithoutADsnTheNullShipperRemains(): void
    {
        $services = $this->services($this->boot('no-transport', [
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
        ]));

        self::assertInstanceOf(
            \ProjektMotor\IdsSensor\Delivery\Transport\Shipper\NullShipper::class,
            $services->get('ids_sensor.shipper'),
        );
    }

    private function kernelRequest(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_REQUEST, [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    private function kernelResponse(): CapturedEvent
    {
        $event = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_RESPONSE, [
            KernelPayload::FIELD_HTTP_STATUS => 404,
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
        ]);
        $event->setCorrelationId('req-7f2a1c');

        return $event;
    }

    /**
     * Hält die eine Doppelung fest, die das YAML-Format erzwingt.
     *
     * `config/services_transport.yaml` führt die beiden Service-IDs als Zeichenketten,
     * weil YAML in Schlüsseln keine Konstanten erlaubt. Dieselben Werte stehen als
     * Konstanten in IdsSensorBundle. Läuft beides auseinander, ist der Befund im Betrieb
     * eine fehlende Service-Definition — hier ist er ein roter Test mit Namen.
     */
    public function testTheServiceIdsMatchTheConstants(): void
    {
        $services = $this->services($this->boot('transport'));

        self::assertTrue(
            $services->has(IdsSensorBundle::SERIALIZER_ID),
            'IdsSensorBundle::SERIALIZER_ID und config/services_transport.yaml sind auseinandergelaufen',
        );
        self::assertTrue(
            $services->has(IdsSensorBundle::TRANSPORT_ID),
            'IdsSensorBundle::TRANSPORT_ID und der in loadExtension() gesetzte Alias sind auseinandergelaufen',
        );
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function boot(string $variant, ?array $config = null): TestKernel
    {
        $kernel = new TestKernel($config ?? self::CONFIG, $variant);
        $kernel->boot();

        return $kernel;
    }
}
