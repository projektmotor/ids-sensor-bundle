<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;

/**
 * Die Kennung, die eine Instanz von allen anderen unterscheidet.
 *
 * Konzept 2.2.1 aggregiert über sie: Sind zwei Replicas nicht unterscheidbar, sehen
 * alle Auswertungen sie als eine — und eine Schwellwertregel feuert bei doppelter Last
 * oder gar nicht mehr.
 */
#[CoversClass(InstanceIdProvider::class)]
final class InstanceIdProviderTest extends TestCase
{
    #[DataProvider('bereinigungen')]
    public function testTheIdIsBroughtIntoTheExpectedPattern(string $roh, string $erwartet): void
    {
        self::assertSame($erwartet, InstanceIdProvider::sanitize($roh));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bereinigungen(): iterable
    {
        yield 'unverändert' => ['web-01', 'web-01'];
        // Unterstrich, Punkt, Doppelpunkt und Bindestrich sind ZUGELASSEN — ein
        // Container-Hostname wie `shop_web_1` bleibt unangetastet.
        yield 'Unterstrich bleibt' => ['shop_web_1', 'shop_web_1'];
        yield 'FQDN bleibt' => ['web01.rz.example.com', 'web01.rz.example.com'];
        yield 'Leerzeichen' => ['mein host', 'mein-host'];
        yield 'Schrägstrich' => ['ns/1', 'ns-1'];
        yield 'führende Sonderzeichen werden abgeschnitten' => ['/web01/', 'web01'];
        yield 'nur Sonderzeichen' => ['///', 'unknown'];
        yield 'leer' => ['', 'unknown'];
        yield 'zu lang' => [str_repeat('a', 80), str_repeat('a', 64)];
    }

    /**
     * Der Vergleich, auf dem der Hinweis in `ids:sensor:setup-check` steht.
     *
     * Er verglich die BEREINIGTE Kennung mit dem ROHEN Hostnamen. Der praktisch
     * relevante Auslöser ist die LÄNGE: Ein FQDN über 64 Zeichen wird gekürzt, und der
     * Check meldete dann einen Hinweis für eine völlig richtige Konfiguration — mit
     * `--strict` einen Exit 1. Zeichen außerhalb des Musters lösen dasselbe aus, sind
     * bei Hostnamen aber selten (Unterstrich und Punkt sind zugelassen).
     */
    public function testASanitizedHostnameStillCountsAsTheHostname(): void
    {
        self::assertTrue(
            InstanceIdProvider::matchesHostname(str_repeat('a', 64), str_repeat('a', 80)),
            'Ein Hostname über 64 Zeichen wird gekürzt und bleibt derselbe Host',
        );
        self::assertTrue(
            InstanceIdProvider::matchesHostname('ns-1', 'ns/1'),
            'Ein bereinigtes Zeichen macht aus dem Host keinen anderen',
        );
    }

    public function testADeliberatelySetIdIsRecognisedAsDifferent(): void
    {
        self::assertFalse(InstanceIdProvider::matchesHostname('pod-abc123', 'web01'));
    }
}
