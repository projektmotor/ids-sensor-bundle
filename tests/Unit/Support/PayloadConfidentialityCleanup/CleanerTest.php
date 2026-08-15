<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\PayloadConfidentialityCleanup;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

/**
 * Das Ersetzen selbst: Werte weg, Feldnamen bleiben (Konzept 4.5.1).
 */
final class CleanerTest extends TestCase
{
    private Cleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = TestCleaner::default();
    }

    public function testHeaderValuesAreReplacedAndNamesRemain(): void
    {
        $cleaned = $this->cleaner->cleanHeaders([
            'authorization' => ['Bearer geheim'],
            'user-agent' => ['Mozilla/5.0'],
            'cookie' => ['PHPSESSID=abc123'],
        ]);

        self::assertSame([
            'authorization' => Cleaner::DEFAULT_PLACEHOLDER,
            'user-agent' => 'Mozilla/5.0',
            'cookie' => Cleaner::DEFAULT_PLACEHOLDER,
        ], $cleaned);
    }

    /**
     * Mehrfach gesetzte Header behalten ihre Anzahl nicht — der Wert wird zusammengefasst.
     * Bei unauffälligen Headern ist das die brauchbare Auskunft; bei sensiblen wird ohnehin
     * ersetzt.
     */
    public function testRepeatedHeadersAreJoined(): void
    {
        $cleaned = $this->cleaner->cleanHeaders(['accept' => ['text/html', 'application/json']]);

        self::assertSame(['accept' => 'text/html, application/json'], $cleaned);
    }

    /**
     * DER Umgehungsversuch, den eine schlüsselweise Prüfung ohne Teilbaum-Regel
     * durchlassen würde: `password[confirm]=…`.
     */
    public function testASensitiveKeyMakesTheWholeSubtreeSensitive(): void
    {
        $cleaned = $this->cleaner->cleanParameters([
            'password' => ['first' => 'hunter2', 'second' => 'hunter2'],
        ]);

        self::assertSame(['password' => Cleaner::DEFAULT_PLACEHOLDER], $cleaned);
    }

    public function testNestedHarmlessStructuresArePreserved(): void
    {
        $cleaned = $this->cleaner->cleanParameters([
            'order' => ['id' => 42, 'items' => ['a', 'b']],
            'user' => ['email' => 'a@b.de', 'password' => 'hunter2'],
        ]);

        self::assertSame([
            'order' => ['id' => 42, 'items' => ['a', 'b']],
            'user' => ['email' => 'a@b.de', 'password' => Cleaner::DEFAULT_PLACEHOLDER],
        ], $cleaned);
    }

    /**
     * Die Verschachtelungstiefe ist angreifergesteuert (`a[b][c][d]…`). Ohne Grenze wäre
     * die Rekursion es auch.
     */
    public function testTheDepthIsBounded(): void
    {
        $tief = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'unten']]]]]];

        $cleaned = $this->cleaner->cleanParameters($tief);
        $json = json_encode($cleaned, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('unten', $json);
        self::assertStringContainsString('depth limit', $json);
    }

    public function testLongValuesAreTruncated(): void
    {
        $cleaned = $this->cleaner->cleanParameters(['kommentar' => str_repeat('x', 5000)]);

        self::assertIsString($cleaned['kommentar']);
        self::assertSame(Cleaner::MAX_VALUE_LENGTH, mb_strlen($cleaned['kommentar']));
    }

    /**
     * __toString() wird nicht aufgerufen — es könnte alles ausschreiben.
     */
    public function testObjectsAreReportedAsTypeNameOnly(): void
    {
        $objekt = new class {
            public function __toString(): string
            {
                return 'geheim@example.com';
            }
        };

        $cleaned = $this->cleaner->cleanParameters(['feld' => $objekt]);

        self::assertIsString($cleaned['feld']);
        self::assertStringNotContainsString('@example.com', $cleaned['feld']);
    }

    public function testTheReplacementTextIsConfigurable(): void
    {
        $cleaner = new Cleaner(TestCleaner::rules(), '***');

        self::assertSame(['token' => '***'], $cleaner->cleanParameters(['token' => 'abc']));
        self::assertSame('***', $cleaner->placeholder());
    }

    public function testTheListVersionCanBeQueried(): void
    {
        self::assertSame(TestCleaner::rules()->version, $this->cleaner->rulesVersion());
    }

    /**
     * Die Breite war als einzige Größe dieser Klasse angreifergesteuert.
     *
     * `MAX_DEPTH` begrenzte die Verschachtelung, die Zahl der Elemente je Ebene nichts.
     * Gebremst wurde erst `RawPayload\Builder::capped()` — durch VERWERFEN des ganzen
     * `payload`-Zweiges. Wer 5000 Formularfelder schickte, bekam damit genau das, was er
     * wollte: ein leeres raw.
     */
    public function testTooManyParametersAreCappedInsteadOfWalked(): void
    {
        $viele = [];

        for ($i = 0; $i < Cleaner::MAX_PARAMETERS + 50; ++$i) {
            $viele['feld'.$i] = 'wert';
        }

        $bereinigt = $this->cleaner->cleanParameters($viele);

        self::assertCount(Cleaner::MAX_PARAMETERS + 1, $bereinigt, 'Die Kappung plus ihr Vermerk');
        self::assertTrue($bereinigt[Cleaner::TRUNCATED_MARKER], 'Ohne Vermerk entstünde der Eindruck von Vollständigkeit');
        self::assertSame('wert', $bereinigt['feld0'], 'Der Anfang bleibt erhalten — raw behält seinen Wert');
    }

    /**
     * Der Vermerk darf nicht von außen kommen — sonst ist ein Vollständigkeitsverlust
     * behauptbar, den es nie gab.
     */
    public function testTheTruncationMarkerCannotBeForged(): void
    {
        $bereinigt = $this->cleaner->cleanParameters([
            Cleaner::TRUNCATED_MARKER => true,
            'echt' => 'sichtbar',
        ]);

        self::assertArrayNotHasKey(Cleaner::TRUNCATED_MARKER, $bereinigt);
        self::assertSame('sichtbar', $bereinigt['echt']);
    }
}
