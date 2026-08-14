<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use Symfony\Component\HttpFoundation\Request;

final class CorrelationIdFactoryTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testGeneratesItsOwnUuidByDefault(): void
    {
        $id = (new CorrelationIdFactory())->forRequest(Request::create('/'));

        self::assertMatchesRegularExpression(self::UUID_PATTERN, $id);
    }

    public function testEveryRequestGetsItsOwnId(): void
    {
        $factory = new CorrelationIdFactory();

        self::assertNotSame(
            $factory->forRequest(Request::create('/')),
            $factory->forRequest(Request::create('/')),
        );
    }

    /**
     * Der wichtigste Test dieser Klasse.
     *
     * Der Sensor sitzt im Request-Pfad, ein eingehender Header ist also
     * angreifergesteuert. Würde er blind übernommen, könnte ein Angreifer die
     * correlation_id eines Opfers wiederverwenden und seine eigenen Events an dessen
     * Spur anhängen. Da die correlation_id genau der Schlüssel ist, über den der
     * Collector Request-Kontext rekonstruiert (Konzept 3.2), wäre das ein Angriff auf
     * die Beweisintegrität.
     */
    public function testAnIncomingHeaderIsIgnoredByDefault(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'vom-angreifer-gesetzt');

        $factory = new CorrelationIdFactory('X-Request-Id');
        $id = $factory->forRequest($request);

        self::assertNotSame('vom-angreifer-gesetzt', $id);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $id);
    }

    /**
     * Auch eingeschaltet reicht der Header allein nicht: ohne konfigurierte
     * trusted_proxies hat niemand bestätigt, dass ein Proxy ihn überschreibt.
     */
    public function testEnabledButWithoutTrustedProxyIsNotAdopted(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'client-gesetzt-1234');

        $factory = new CorrelationIdFactory('X-Request-Id', trustIncomingHeader: true);

        self::assertNotSame('client-gesetzt-1234', $factory->forRequest($request));
    }

    public function testWithATrustedProxyTheHeaderIsAdopted(): void
    {
        Request::setTrustedProxies(['192.168.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.0.1']);
        $request->headers->set('X-Request-Id', 'vom-proxy-gesetzt-1234');

        $factory = new CorrelationIdFactory('X-Request-Id', trustIncomingHeader: true);

        self::assertSame('vom-proxy-gesetzt-1234', $factory->forRequest($request));
    }

    public function testWithoutTheTrustedProxyRequirementTheHeaderIsAdopted(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', 'ausdruecklich-erlaubt-1234');

        $factory = new CorrelationIdFactory(
            'X-Request-Id',
            trustIncomingHeader: true,
            requireTrustedProxy: false,
        );

        self::assertSame('ausdruecklich-erlaubt-1234', $factory->forRequest($request));
    }

    /**
     * Auch ein erlaubter Header muss dem Format entsprechen. Sonst könnte ein
     * Angreifer einen 1-MB-Header schicken oder Steuerzeichen einschmuggeln.
     */
    #[DataProvider('unusableHeaderProvider')]
    public function testUnusableHeaderValuesAreDiscarded(string $value): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Request-Id', $value);

        $factory = new CorrelationIdFactory(
            'X-Request-Id',
            trustIncomingHeader: true,
            requireTrustedProxy: false,
        );

        $id = $factory->forRequest($request);

        self::assertNotSame($value, $id);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableHeaderProvider(): iterable
    {
        yield 'zu kurz' => ['kurz'];
        yield 'zu lang' => [str_repeat('a', 200)];
        yield 'Leerzeichen' => ['mit leerzeichen drin'];
        yield 'Zeilenumbruch' => ["zeile1\nzeile2"];
        yield 'Sonderzeichen' => ['<script>alert(1)</script>'];
        yield 'leer' => [''];
    }

    public function testGenerateReturnsAUuidForContextsWithoutRequest(): void
    {
        self::assertMatchesRegularExpression(self::UUID_PATTERN, (new CorrelationIdFactory())->generate());
    }
}
