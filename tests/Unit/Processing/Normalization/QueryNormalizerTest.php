<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Processing\Normalization\QueryNormalizer;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

/**
 * `payload.query` — das Feld, das bei JEDER Stufe mitreist.
 *
 * Anders als `raw` gibt es hier keine Severity-Schranke: Was der Normalisierer
 * durchlässt, verlässt den Sensor bei jedem einzelnen Request. Zugleich ist der
 * gesamte Inhalt angreifergesteuert — Zahl, Länge und Verschachtelung der Parameter
 * bestimmt der Client.
 */
#[CoversClass(QueryNormalizer::class)]
final class QueryNormalizerTest extends TestCase
{
    public function testHarmlessParametersPassThroughUnchanged(): void
    {
        self::assertSame(
            ['suche' => 'schuhe', 'seite' => '2'],
            $this->normalizer()->normalize(['suche' => 'schuhe', 'seite' => '2']),
        );
    }

    public function testSensitiveParametersAreRedacted(): void
    {
        $normalisiert = $this->normalizer()->normalize(['reset_token' => 'geheim-4711']);

        self::assertSame(['reset_token' => '[confidential]'], $normalisiert);
    }

    /**
     * Der Schlüssel wird für die AUSGABE gekürzt, für die ENTSCHEIDUNG nicht.
     *
     * `Rules::isSensitiveParameter()` sucht per `str_contains`. Stand `token` jenseits
     * von Zeichen 64, griff die Denylist nicht — während derselbe Wert im raw-Pfad
     * redigiert wurde. Zwei Ergebnisse für dieselben Daten.
     */
    public function testAKeyLongerThanTheLimitIsStillCheckedInFull(): void
    {
        $langerSchluessel = str_repeat('x', 70).'_token';

        $normalisiert = $this->normalizer()->normalize([$langerSchluessel => 'geheim-4711']);

        self::assertSame(['[confidential]'], array_values($normalisiert));
        self::assertSame(QueryNormalizer::MAX_KEY_LENGTH, mb_strlen((string) array_key_first($normalisiert)));
    }

    public function testTooManyParametersAreCappedWithAMarker(): void
    {
        $viele = [];

        for ($i = 0; $i < QueryNormalizer::MAX_PARAMS + 10; ++$i) {
            $viele['feld'.$i] = 'wert';
        }

        $normalisiert = $this->normalizer()->normalize($viele);

        self::assertTrue($normalisiert[QueryNormalizer::TRUNCATED_MARKER]);
        self::assertCount(QueryNormalizer::MAX_PARAMS + 1, $normalisiert, 'Die Kappung plus ihr Vermerk');
    }

    /**
     * Ein auf 512 Zeichen gekürztes Token ist immer noch ein Token — deshalb wird
     * redigiert, bevor gekürzt wird.
     */
    public function testALongValueIsTruncatedAndMarked(): void
    {
        $normalisiert = $this->normalizer()->normalize(['kommentar' => str_repeat('x', 5000)]);

        self::assertIsString($normalisiert['kommentar']);
        self::assertSame(QueryNormalizer::MAX_VALUE_LENGTH, mb_strlen($normalisiert['kommentar']));
        self::assertTrue($normalisiert[QueryNormalizer::TRUNCATED_MARKER]);
    }

    /**
     * Verschachtelte Query-Arrays (`a[b][c]=…`) werden zu JSON, damit die Zusage
     * „maximal zweistufig" aus Konzept Abschnitt 3 nicht durch die Gegenseite brechbar
     * ist.
     */
    public function testNestedArraysBecomeJson(): void
    {
        $normalisiert = $this->normalizer()->normalize(['filter' => ['farbe' => 'rot']]);

        self::assertSame('{"filter":{"farbe":"rot"}}', '{"filter":'.$normalisiert['filter'].'}');
    }

    /**
     * Der Vermerk darf nicht von außen kommen — sonst behauptet ein Client einen
     * Vollständigkeitsverlust, den es nie gab.
     */
    public function testTheTruncationMarkerCannotBeForged(): void
    {
        $normalisiert = $this->normalizer()->normalize([
            QueryNormalizer::TRUNCATED_MARKER => 'true',
            'echt' => 'sichtbar',
        ]);

        self::assertSame(['echt' => 'sichtbar'], $normalisiert);
    }

    public function testAnEmptyKeyIsDropped(): void
    {
        self::assertSame([], $this->normalizer()->normalize(['' => 'wert']));
    }

    /**
     * Der Referer ist die einzige Stelle, an der eine FREMDE vollständige URL in ein
     * Event gelangt — Herkunft und Pfad bleiben, die Query wird redigiert.
     */
    public function testAUrlKeepsOriginAndPathButLosesSecrets(): void
    {
        $bereinigt = $this->normalizer()->normalizeUrl('https://app.example/reset?reset_token=geheim-4711');

        self::assertStringStartsWith('https://app.example/reset?', $bereinigt);
        self::assertStringNotContainsString('geheim-4711', $bereinigt);
    }

    private function normalizer(): QueryNormalizer
    {
        return new QueryNormalizer(TestCleaner::default());
    }
}
