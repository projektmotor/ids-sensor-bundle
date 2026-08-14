<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use Symfony\Component\HttpFoundation\Request;

final class ClientFingerprinterTest extends TestCase
{
    /**
     * Die Feldfolge aus Konzept 2.2.4 ist Teil des Vertrags: User-Agent,
     * Accept-Language, Accept-Encoding — in genau dieser Reihenfolge.
     */
    public function testHashesOverTheFixedFieldOrder(): void
    {
        $request = $this->requestWith([
            'User-Agent' => 'Mozilla/5.0',
            'Accept-Language' => 'de-DE',
            'Accept-Encoding' => 'gzip',
        ]);

        $expected = hash('sha256', implode("\n", ['Mozilla/5.0', 'de-DE', 'gzip']));

        self::assertSame($expected, (new ClientFingerprinter())->forRequest($request));
    }

    /**
     * Vertauschte Werte müssen einen anderen Fingerprint ergeben — sonst wäre die
     * Reihenfolge nicht Teil der Kennung und zwei verschiedene Clients könnten
     * kollidieren.
     */
    public function testOrderEntersTheHash(): void
    {
        $a = (new ClientFingerprinter())->forRequest($this->requestWith([
            'User-Agent' => 'A',
            'Accept-Language' => 'B',
        ]));
        $b = (new ClientFingerprinter())->forRequest($this->requestWith([
            'User-Agent' => 'B',
            'Accept-Language' => 'A',
        ]));

        self::assertNotSame($a, $b);
    }

    /**
     * Fehlt JEDER der Header, ist null die ehrliche Auskunft.
     *
     * Ein Hash über drei leere Zeichenketten wäre eine Kennung, die sich sämtliche
     * header-losen Clients teilen. Regel B9 schlägt an, wenn sich der Fingerprint
     * innerhalb einer Sitzung ändert — mit einer geteilten Sammelkennung würde sie
     * bei jedem Wechsel zwischen zwei solchen Clients grundlos feuern.
     */
    public function testWithoutAnyHeaderNoFingerprint(): void
    {
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        $request->headers->remove('Accept-Language');
        $request->headers->remove('Accept-Encoding');

        self::assertNull((new ClientFingerprinter())->forRequest($request));
    }

    public function testASinglePresentHeaderIsEnough(): void
    {
        $request = $this->requestWith(['User-Agent' => 'Mozilla/5.0']);

        self::assertNotNull((new ClientFingerprinter())->forRequest($request));
    }

    /**
     * Der Fingerprint muss stabil bleiben, wenn sich unbeteiligte Header ändern.
     * Andernfalls würde er bei jedem Request wechseln und B9 wäre unbrauchbar.
     */
    public function testUnrelatedHeadersDoNotChangeTheFingerprint(): void
    {
        $fingerprinter = new ClientFingerprinter();

        $first = $fingerprinter->forRequest($this->requestWith([
            'User-Agent' => 'Mozilla/5.0',
            'Accept-Language' => 'de-DE',
            'Accept-Encoding' => 'gzip',
        ]));
        $second = $fingerprinter->forRequest($this->requestWith([
            'User-Agent' => 'Mozilla/5.0',
            'Accept-Language' => 'de-DE',
            'Accept-Encoding' => 'gzip',
            'Referer' => 'https://example.test/andere-seite',
            'X-Irgendwas' => 'wechselt-dauernd',
        ]));

        self::assertSame($first, $second);
    }

    /**
     * Der ungekürzte User-Agent geht ein, obwohl payload.user_agent auf 512 Zeichen
     * begrenzt wird — sonst würde die eigene Kürzung den Fingerprint mitbestimmen.
     */
    public function testALongUserAgentIsNotTruncated(): void
    {
        $long = str_repeat('A', 1000);
        $longer = str_repeat('A', 1001);

        $fingerprinter = new ClientFingerprinter();

        self::assertNotSame(
            $fingerprinter->forRequest($this->requestWith(['User-Agent' => $long])),
            $fingerprinter->forRequest($this->requestWith(['User-Agent' => $longer])),
        );
    }

    public function testDisabledReturnsNull(): void
    {
        $fingerprinter = new ClientFingerprinter(enabled: false);

        self::assertNull($fingerprinter->forRequest($this->requestWith(['User-Agent' => 'Mozilla/5.0'])));
    }

    public function testCustomHeaderSelection(): void
    {
        $request = $this->requestWith(['User-Agent' => 'Mozilla/5.0', 'X-Eigen' => 'wert']);

        $fingerprinter = new ClientFingerprinter(['X-Eigen']);

        self::assertSame(hash('sha256', 'wert'), $fingerprinter->forRequest($request));
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestWith(array $headers): Request
    {
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        $request->headers->remove('Accept-Language');
        $request->headers->remove('Accept-Encoding');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
