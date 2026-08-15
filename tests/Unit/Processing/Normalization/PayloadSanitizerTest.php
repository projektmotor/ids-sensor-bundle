<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Processing\Normalization\PayloadSanitizer;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

/**
 * Der Business-Payload ist vollständig anwendungsdefiniert — und reist bei jeder Stufe.
 *
 * Konzept 2.1.3 erlaubt einem Domain-Event zu tragen, was die Anwendung für relevant
 * hält, einschließlich eines Feldes `new_password`. Anders als `raw` wird `payload`
 * nie weggelassen; eine Lücke hier ist also die folgenreichere. Zugleich muss die
 * Zusage aus Konzept Abschnitt 3 halten: maximal zweistufig verschachteltes JSON,
 * lauter Skalare.
 */
#[CoversClass(PayloadSanitizer::class)]
final class PayloadSanitizerTest extends TestCase
{
    public function testScalarsPassThroughUnchanged(): void
    {
        $bereinigt = $this->sanitizer()->sanitize([
            'menge' => 3,
            'preis' => 19.99,
            'storniert' => false,
            'notiz' => null,
        ]);

        self::assertSame(['menge' => 3, 'preis' => 19.99, 'storniert' => false, 'notiz' => null], $bereinigt);
    }

    /**
     * Ein sensibler Schlüssel macht den GANZEN Teilbaum sensibel — sonst käme
     * `password[confirm]` an der Prüfung vorbei.
     */
    public function testASensitiveKeyRedactsItsWholeSubtree(): void
    {
        $bereinigt = $this->sanitizer()->sanitize(['password' => ['confirm' => 'hunter2']]);

        self::assertSame(['password' => '[confidential]'], $bereinigt);
    }

    /**
     * NAN und INF sind nicht JSON-kodierbar und ließen den ganzen Frame scheitern —
     * ein einziges Domain-Event könnte damit alle Events seines Requests mitnehmen.
     */
    public function testNonFiniteFloatsBecomeNull(): void
    {
        $bereinigt = $this->sanitizer()->sanitize(['a' => \NAN, 'b' => \INF]);

        self::assertNull($bereinigt['a']);
        self::assertNull($bereinigt['b']);
    }

    /**
     * Kein `__toString()`: Das könnte ein Lazy-Load auslösen oder personenbezogene
     * Daten ausschreiben.
     */
    public function testObjectsAreReducedToAnIdentifier(): void
    {
        $objekt = new class {
            public function __toString(): string
            {
                return 'geheim@example.com';
            }
        };

        $bereinigt = $this->sanitizer()->sanitize(['kunde' => $objekt]);

        self::assertIsString($bereinigt['kunde']);
        self::assertStringNotContainsString('@example.com', $bereinigt['kunde']);
    }

    public function testDateTimesAndEnumsBecomeScalars(): void
    {
        $bereinigt = $this->sanitizer()->sanitize([
            'zeitpunkt' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
        ]);

        self::assertSame('2026-01-02T03:04:05+00:00', $bereinigt['zeitpunkt']);
    }

    /**
     * Zu tief: als JSON-Zeichenkette erhalten, statt die Tiefenzusage des Schemas zu
     * brechen. Der Inhalt bleibt für die Nachanalyse lesbar.
     */
    public function testTooDeepStructuresBecomeJsonStrings(): void
    {
        $bereinigt = $this->sanitizer()->sanitize(['a' => ['b' => ['c' => ['d' => 'tief']]]]);

        self::assertIsString($bereinigt['a']['b']['c']);
        self::assertStringContainsString('tief', $bereinigt['a']['b']['c']);
    }

    public function testTooManyElementsAreCappedWithAMarker(): void
    {
        $viele = [];

        for ($i = 0; $i < PayloadSanitizer::MAX_ELEMENTS + 10; ++$i) {
            $viele['feld'.$i] = 'wert';
        }

        $bereinigt = $this->sanitizer()->sanitize($viele);

        self::assertTrue($bereinigt[PayloadSanitizer::TRUNCATED_MARKER]);
        self::assertCount(PayloadSanitizer::MAX_ELEMENTS + 1, $bereinigt);
    }

    public function testLongStringsAreTruncated(): void
    {
        $bereinigt = $this->sanitizer()->sanitize(['text' => str_repeat('x', 5000)]);

        self::assertIsString($bereinigt['text']);
        self::assertSame(PayloadSanitizer::MAX_STRING_LENGTH, mb_strlen($bereinigt['text']));
    }

    /**
     * Die Vermerke des Sensors dürfen nicht fälschbar sein — sonst behauptet eine
     * Anwendung eine Kürzung oder eine Fehlkonfiguration, die es nie gab.
     */
    public function testReservedKeysFromTheApplicationAreDropped(): void
    {
        $bereinigt = $this->sanitizer()->sanitize([
            PayloadSanitizer::RESERVED_PREFIX.'event_name_raw' => 'erfunden',
            PayloadSanitizer::TRUNCATED_MARKER => true,
            'echt' => 'sichtbar',
        ]);

        self::assertSame(['echt' => 'sichtbar'], $bereinigt);
    }

    private function sanitizer(): PayloadSanitizer
    {
        return new PayloadSanitizer(TestCleaner::default());
    }
}
