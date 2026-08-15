<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Delivery\Transport\RuntimeProfile;

/**
 * Die Laufzeiterkennung, auf der die mod_php-Unterstützung beruht.
 *
 * Die SAPI wird als Konstruktorargument übergeben, damit alle Laufzeitmodelle in einem
 * einzigen PHPUnit-Lauf prüfbar sind. Das ist kein Umweg um die Realität: die einzige
 * Alternative wäre, jeden Fall nur im jeweiligen Docker-Service zu prüfen — und dann wäre
 * genau die Entscheidungslogik ungetestet, die falsch sein kann.
 */
final class RuntimeProfileTest extends TestCase
{
    /**
     * PHPUnit läuft unter der CLI-SAPI. Dort gibt es keinen wartenden Client, also auch
     * keine Antwortzeit — ein Command oder Worker darf blockieren.
     */
    public function testCliShipsDirectly(): void
    {
        $profile = new RuntimeProfile(RuntimeProfile::POLICY_AUTO, 'cli');

        self::assertTrue($profile->shipsDirectly());
        self::assertSame(DispatchPath::Direct, $profile->dispatchPath());
    }

    /**
     * DER Fall, um den es geht: mod_php hat kein fastcgi_finish_request() und kein
     * Äquivalent. Die Verbindung bleibt bis zum Skriptende offen, also darf in Phase B
     * nichts über das Netz gehen.
     */
    public function testModPhpSpools(): void
    {
        $profile = new RuntimeProfile(RuntimeProfile::POLICY_AUTO, 'apache2handler');

        // Unter PHPUnit (CLI) existiert fastcgi_finish_request() nicht — die Erkennung
        // greift also und liefert den mod_php-Fall.
        if (\function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request')) {
            self::markTestSkipped('Diese PHP-Installation stellt eine Abkoppelfunktion bereit.');
        }

        self::assertFalse($profile->shipsDirectly());
        self::assertSame(
            DispatchPath::Deferred,
            $profile->dispatchPath(),
            'deferred und NICHT recovered: die Verzögerung ist auf ein Drain-Intervall begrenzt, '
            .'und der Collector darf die Echtzeit-Regeln weiter anwenden',
        );
    }

    /**
     * Die Policy überstimmt die Erkennung in beide Richtungen — für FPM-Instanzen mit sehr
     * strengem Latenzbudget oder Netzwerksegmentierung.
     */
    public function testThePolicyOverridesDetection(): void
    {
        self::assertFalse(
            (new RuntimeProfile(RuntimeProfile::POLICY_SPOOL, 'cli'))->shipsDirectly(),
            'spool erzwingt den Spool auch dort, wo direkt möglich wäre',
        );
        self::assertTrue(
            (new RuntimeProfile(RuntimeProfile::POLICY_DIRECT, 'apache2handler'))->shipsDirectly(),
            'direct erzwingt den Direktversand — auf eigenes Risiko',
        );
    }

    /**
     * Der Heartbeat meldet das weiter, damit collectorseitig bekannt ist, welche
     * Verzögerung für diese Instanz NORMAL ist. Nur so fällt eine auf, die es nicht ist.
     */
    public function testTheDescriptionCarriesEverythingTheCollectorNeeds(): void
    {
        $profile = new RuntimeProfile(RuntimeProfile::POLICY_SPOOL, 'apache2handler', 30);

        self::assertSame([
            'policy' => 'spool',
            'sapi' => 'apache2handler',
            'response_detachable' => false,
            'dispatch_path' => 'deferred',
            'drain_interval_s' => 30,
        ], $profile->describe());
    }
}
