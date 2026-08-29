<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Http\HttpShipper;
use ProjektMotor\IdsSensor\Delivery\Transport\Http\TokenProvider;
use ProjektMotor\IdsSensor\Delivery\Transport\Http\TokenStore;
use ProjektMotor\IdsSensor\Exception\ThrottledException;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Die Antwortcode-Tabelle aus Konzept 3.6 ist normativ — hier steht sie als Test.
 *
 * WOZU
 *
 * Der Sensor muss „geht nie" von „später erneut" unterscheiden können, sonst hält eine
 * einzelne dauerhaft abgelehnte Sendung den ganzen Spool fest. Die Unterscheidung
 * entsteht in dieser Klasse und wird stromabwärts nur noch ausgewertet; falsch sortiert
 * sie hier, hilft kein Zweig im FrameDispatcher mehr.
 *
 * @internal
 */
#[CoversClass(HttpShipper::class)]
final class HttpShipperTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ids-token-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
    }

    /**
     * @return list<array{int}>
     */
    public static function dauerhafteAblehnungen(): array
    {
        return [[400], [403], [413], [422]];
    }

    #[DataProvider('dauerhafteAblehnungen')]
    public function testAPermanentRejectionIsUnshippable(int $status): void
    {
        $this->expectException(UnshippableFrameException::class);

        $this->shipper([$status])->ship($this->frame());
    }

    /**
     * `429` ist wiederholbar und darf NICHT als unversendbar gelten.
     *
     * Beide sind 4xx und sehen damit ähnlich aus; die Folgen sind entgegengesetzt. Läge
     * 429 in derselben Liste, verwürfe der Sensor Events wegen einer vorübergehenden
     * Ratengrenze endgültig.
     */
    public function testARateLimitIsRetryableAndNotUnshippable(): void
    {
        $this->expectException(ThrottledException::class);

        $this->shipper([429])->ship($this->frame());
    }

    /**
     * `Retry-After` in Sekunden — die häufigere der beiden Schreibweisen aus RFC 9110.
     */
    public function testRetryAfterInSecondsIsRead(): void
    {
        try {
            $this->shipper([429], ['Retry-After: 120'])->ship($this->frame());
            self::fail('Erwartet wurde eine ThrottledException.');
        } catch (ThrottledException $e) {
            self::assertSame(120.0, $e->retryAfterSeconds);
        }
    }

    /**
     * Die zweite zulässige Schreibweise ist ein HTTP-Zeitpunkt.
     */
    public function testRetryAfterAsAnHttpDateIsRead(): void
    {
        $zeitpunkt = gmdate('D, d M Y H:i:s \G\M\T', time() + 90);

        try {
            $this->shipper([429], ['Retry-After: '.$zeitpunkt])->ship($this->frame());
            self::fail('Erwartet wurde eine ThrottledException.');
        } catch (ThrottledException $e) {
            self::assertNotNull($e->retryAfterSeconds);
            self::assertEqualsWithDelta(90.0, $e->retryAfterSeconds, 2.0);
        }
    }

    /**
     * Ein `429` ohne `Retry-After` ist zulässig und kein Fehler.
     *
     * Dann gilt die konfigurierte Offen-Zeit des Breakers. Der Header ist nach RFC 9110
     * optional, und Konzept OB12 lässt die Werte der Gegenseite ausdrücklich offen.
     */
    public function testAMissingRetryAfterIsNotAnError(): void
    {
        try {
            $this->shipper([429])->ship($this->frame());
            self::fail('Erwartet wurde eine ThrottledException.');
        } catch (ThrottledException $e) {
            self::assertNull($e->retryAfterSeconds);
        }
    }

    /**
     * Eine unsinnig lange Wartezeit wird gekappt.
     *
     * Der Wert kommt von der Gegenseite und gilt nach Konzept 4.5.3 als
     * angreiferkontrolliert. Ungekappt legte ein `Retry-After: 86400` den Sensor einen
     * Tag still, während der lokale Spool die ganze Zeit allein trüge (Konzept 2.1).
     */
    public function testAnAbsurdRetryAfterIsCapped(): void
    {
        try {
            $this->shipper([429], ['Retry-After: 86400'])->ship($this->frame());
            self::fail('Erwartet wurde eine ThrottledException.');
        } catch (ThrottledException $e) {
            self::assertSame(900.0, $e->retryAfterSeconds);
        }
    }

    /**
     * `5xx` ist wiederholbar, aber keine Ratengrenze.
     */
    public function testAServerErrorIsPlainlyRetryable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Der Collector antwortete mit 503.');

        $this->shipper([503])->ship($this->frame());
    }

    /**
     * Nach einem `401` meldet der Sensor sich einmal neu an und wiederholt einmal.
     */
    public function testASingleReauthenticationIsAttemptedAfterA401(): void
    {
        $this->shipper([401, 202])->ship($this->frame());

        // Kein Wurf: der zweite Versuch war erfolgreich.
        $this->expectNotToPerformAssertions();
    }

    /**
     * Ein leerer Frame kostet keinen Verbindungsversuch.
     */
    public function testAnEmptyFrameIsNotSent(): void
    {
        $this->shipper([500])->ship(['events' => []]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param list<int>    $datenStatus Antwortcodes der Datenroute, der Reihe nach
     * @param list<string> $headers     Zusätzliche Kopfzeilen jeder Antwort
     */
    private function shipper(array $datenStatus, array $headers = []): HttpShipper
    {
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$datenStatus, $headers): MockResponse {
                if (str_contains($url, '/api/v1/token')) {
                    return new MockResponse(
                        (string) json_encode(['token' => 'jwt', 'expires_at' => time() + 3600]),
                        ['http_code' => 200],
                    );
                }

                return new MockResponse('', [
                    'http_code' => array_shift($datenStatus) ?? 202,
                    'response_headers' => $headers,
                ]);
            },
        );

        $store = new TokenStore($this->directory, 'test-'.bin2hex(random_bytes(4)));

        return new HttpShipper(
            $client,
            $this->identityProvider(),
            new TokenProvider($client, $store, 'https://collector.test', 'sensor', 'geheim'),
            'https://collector.test',
        );
    }

    private function identityProvider(): SensorIdentityProvider
    {
        return new SensorIdentityProvider(
            '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
            '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
            'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(): array
    {
        return ['schema_version' => 2, 'events' => [['event_id' => '01a0']]];
    }
}
