<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\EventFactory;
use ProjektMotor\IdsSensor\Processing\Normalization\KernelEventNormalizer;
use ProjektMotor\IdsSensor\Processing\Normalization\QueryNormalizer;
use ProjektMotor\IdsSensor\Processing\Normalization\SeverityResolver;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Tests\Fixtures\SequentialEventIdGenerator;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

/**
 * Prüft die Payload-Strukturen aus Konzept 3.1.1 feldgenau.
 */
final class KernelEventNormalizerTest extends TestCase
{
    public function testSupportsOnlyTheKernelLayer(): void
    {
        $normalizer = $this->normalizer();

        self::assertTrue($normalizer->supports(CapturedEvent::now(Layer::Kernel, 'kernel.request')));
        self::assertFalse($normalizer->supports(CapturedEvent::now(Layer::Security, 'security.authentication.failure')));
        self::assertFalse($normalizer->supports(CapturedEvent::now(Layer::Business, 'order.amount_overridden')));
    }

    /**
     * Konzept 3.1.1 — kernel.request: genau diese sieben Felder.
     */
    public function testRequestPayloadHasTheFieldsFromTheConcept(): void
    {
        $event = $this->normalize('kernel.request', [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/api/orders/42',
            KernelPayload::FIELD_QUERY => ['expand' => 'items'],
            KernelPayload::FIELD_ROUTE => 'app_order_show',
            KernelPayload::FIELD_USER_AGENT => 'Mozilla/5.0',
            KernelPayload::FIELD_REFERER => null,
            KernelPayload::FIELD_CONTENT_LENGTH => 0,
        ]);

        self::assertSame([
            'method' => 'GET',
            'path' => '/api/orders/42',
            'query' => ['expand' => 'items'],
            'route' => 'app_order_show',
            'user_agent' => 'Mozilla/5.0',
            'referer' => null,
            'content_length' => 0,
        ], $event->payload);
    }

    /**
     * Konzept 3.1.1 — kernel.exception: fünf Felder, darunter die redundant aus dem
     * Request übernommenen path und content_length (Konzept 3.2).
     */
    public function testExceptionPayloadHasTheFieldsFromTheConcept(): void
    {
        $event = $this->normalize('kernel.exception', [
            KernelPayload::FIELD_EXCEPTION_CLASS => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
            KernelPayload::FIELD_EXCEPTION_MESSAGE => 'No route found for GET /wp-admin/setup-config.php',
            KernelPayload::FIELD_HTTP_STATUS => 404,
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
            KernelPayload::FIELD_CONTENT_LENGTH => 0,
        ]);

        self::assertSame([
            'exception_class' => 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
            'exception_message' => 'No route found for GET /wp-admin/setup-config.php',
            'http_status' => 404,
            'path' => '/wp-admin/setup-config.php',
            'content_length' => 0,
        ], $event->payload);
        self::assertSame(Severity::Warning, $event->severity);
    }

    /**
     * Konzept 3.1.1 — kernel.response: fünf Felder, path und route redundant.
     */
    public function testResponsePayloadHasTheFieldsFromTheConcept(): void
    {
        $event = $this->normalize('kernel.response', [
            KernelPayload::FIELD_HTTP_STATUS => 200,
            KernelPayload::FIELD_RESPONSE_TIME_MS => 42,
            KernelPayload::FIELD_RESPONSE_SIZE_BYTES => 1523,
            KernelPayload::FIELD_PATH => '/api/orders/42',
            KernelPayload::FIELD_ROUTE => 'app_order_show',
        ]);

        self::assertSame([
            'http_status' => 200,
            'response_time_ms' => 42,
            'response_size_bytes' => 1523,
            'path' => '/api/orders/42',
            'route' => 'app_order_show',
        ], $event->payload);
        self::assertSame(Severity::Info, $event->severity);
    }

    /**
     * Konzept 3.1.1: „exception_message: auf 500 Zeichen gekürzt, um übergroße
     * Payloads zu vermeiden".
     */
    public function testExceptionMessageIsTruncatedTo500Characters(): void
    {
        $event = $this->normalize('kernel.exception', [
            KernelPayload::FIELD_EXCEPTION_MESSAGE => str_repeat('x', 5000),
            KernelPayload::FIELD_HTTP_STATUS => 500,
        ]);

        self::assertSame(
            KernelPayload::MAX_EXCEPTION_MESSAGE_LENGTH,
            mb_strlen((string) $event->payload['exception_message']),
        );
    }

    /**
     * Die Exception-Message ist angreiferbeeinflusst — sie enthält oft den angefragten
     * Pfad. Steuerzeichen darin könnten in einer späteren Auswertungsoberfläche die
     * Darstellung zerlegen; Konzept 4.5.3 verlangt, solche Werte als Daten zu
     * behandeln.
     */
    public function testControlCharactersInTheExceptionMessageAreNormalized(): void
    {
        $event = $this->normalize('kernel.exception', [
            KernelPayload::FIELD_EXCEPTION_MESSAGE => "Zeile1\nZeile2\r\n\tEingerueckt\x00Null",
            KernelPayload::FIELD_HTTP_STATUS => 500,
        ]);

        $message = (string) $event->payload['exception_message'];

        self::assertStringNotContainsString("\n", $message);
        self::assertStringNotContainsString("\r", $message);
        self::assertStringNotContainsString("\t", $message);
        self::assertStringNotContainsString("\x00", $message);
        self::assertStringContainsString('Zeile1', $message);
    }

    /**
     * Scanner senden ungültiges UTF-8 — das ist Alltag und darf nicht zum Verlust des
     * ganzen Events führen.
     */
    public function testInvalidUtf8InTheMessageDoesNotCauseLoss(): void
    {
        $event = $this->normalize('kernel.exception', [
            KernelPayload::FIELD_EXCEPTION_MESSAGE => "kaputt\xC3\x28ungueltig",
            KernelPayload::FIELD_HTTP_STATUS => 500,
        ]);

        self::assertIsString($event->payload['exception_message']);
    }

    public function testAnOverlongPathIsTruncated(): void
    {
        $event = $this->normalize('kernel.request', [
            KernelPayload::FIELD_PATH => '/'.str_repeat('a', 5000),
        ]);

        self::assertSame(
            KernelPayload::MAX_PATH_LENGTH,
            mb_strlen((string) $event->payload['path']),
        );
    }

    public function testAnOverlongUserAgentIsTruncated(): void
    {
        $event = $this->normalize('kernel.request', [
            KernelPayload::FIELD_USER_AGENT => str_repeat('A', 5000),
        ]);

        self::assertSame(
            KernelPayload::MAX_USER_AGENT_LENGTH,
            mb_strlen((string) $event->payload['user_agent']),
        );
    }

    public function testMissingValuesBecomeNullRespectivelyZero(): void
    {
        $event = $this->normalize('kernel.request', []);

        self::assertNull($event->payload['method']);
        self::assertNull($event->payload['path']);
        self::assertNull($event->payload['route']);
        self::assertSame([], $event->payload['query']);
        self::assertSame(0, $event->payload['content_length'], 'content_length ist laut Konzept 0, nicht null');
    }

    /**
     * response_size_bytes bleibt null, wenn die Größe nicht ermittelbar war
     * (StreamedResponse) — 0 würde eine leere Antwort behaupten.
     */
    public function testAnUndeterminableResponseSizeStaysNull(): void
    {
        $event = $this->normalize('kernel.response', [
            KernelPayload::FIELD_HTTP_STATUS => 200,
            KernelPayload::FIELD_RESPONSE_SIZE_BYTES => null,
        ]);

        self::assertNull($event->payload['response_size_bytes']);
    }

    public function testAnUnknownEventTypeYieldsAnEmptyPayload(): void
    {
        $event = $this->normalize('kernel.terminate', ['irgendwas' => 'wert']);

        self::assertSame([], $event->payload);
    }

    public function testAdoptsCorrelationAndActorFromTheCapturedEvent(): void
    {
        $captured = CapturedEvent::now(Layer::Kernel, 'kernel.response', [
            KernelPayload::FIELD_HTTP_STATUS => 403,
        ]);
        $captured->setCorrelationId('req-7f2a1c');
        $captured->setActor(new Actor('alice', '203.0.113.42', 'hash', 'fingerprint'));

        $event = $this->normalizer()->normalize($captured, $this->identity());

        self::assertSame('req-7f2a1c', $event->correlationId);
        self::assertSame('alice', $event->actor->user);
        self::assertSame(Severity::Warning, $event->severity, '403 ist laut Konzept 2.2.1 warning');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalize(string $eventType, array $data): \ProjektMotor\IdsEventData\Event\NormalizedEvent
    {
        $captured = CapturedEvent::now(Layer::Kernel, $eventType, $data);
        $captured->setCorrelationId('req-1');

        return $this->normalizer()->normalize($captured, $this->identity());
    }

    private function normalizer(): KernelEventNormalizer
    {
        return new KernelEventNormalizer(
            new EventFactory(new SequentialEventIdGenerator()),
            new SeverityResolver(),
            new QueryNormalizer(TestCleaner::default()),
        );
    }

    private function identity(): SensorIdentity
    {
        return new SensorIdentity('shop-api', 'web-03', Environment::Prod);
    }
}
