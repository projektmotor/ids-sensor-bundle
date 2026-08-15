<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\PayloadConfidentialityCleanup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Rules;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;

/**
 * Die Vergleichslogik der Redaktionsliste aus Konzept 4.5.1.
 *
 * Geprüft wird gegen die AUSGELIEFERTE Liste, nicht gegen eine im Test erfundene — sonst
 * prüfte der Test eine Fassung, die niemand installiert.
 */
final class RulesTest extends TestCase
{
    /**
     * Header werden vollständig verglichen: HTTP-Headernamen sind ein geschlossener
     * Wortschatz, und `Cookie` darf nicht `Cookie-Policy` mitredigieren.
     */
    #[DataProvider('sensitiveHeaders')]
    public function testSensitiveHeadersAreDetected(string $header): void
    {
        self::assertTrue(TestCleaner::rules()->isSensitiveHeader($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveHeaders(): iterable
    {
        // Die Tabelle aus Konzept 4.5.1, wörtlich.
        foreach (['Cookie', 'Set-Cookie', 'Authorization', 'Proxy-Authorization', 'X-API-Key', 'X-Auth-Token', 'X-CSRF-Token'] as $header) {
            yield $header => [$header];
        }

        // Schreibweisen, wie sie tatsächlich ankommen: HeaderBag::all() gibt
        // Kleinschreibung zurück.
        yield 'kleingeschrieben' => ['authorization'];
        yield 'x-api-key klein' => ['x-api-key'];
        yield 'mit Unterstrichen' => ['X_API_KEY'];
    }

    #[DataProvider('harmlessHeaders')]
    public function testHarmlessHeadersStayUntouched(string $header): void
    {
        self::assertFalse(TestCleaner::rules()->isSensitiveHeader($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessHeaders(): iterable
    {
        yield 'User-Agent' => ['User-Agent'];
        yield 'Referer' => ['Referer'];
        yield 'Content-Type' => ['Content-Type'];
        // Der wichtige Fall: eine Teilzeichenkette darf bei Headern NICHT greifen.
        yield 'Cookie-Policy' => ['Cookie-Policy'];
        yield 'X-Authorization-Info' => ['X-Authorization-Info'];
    }

    /**
     * Parameter werden als TEILZEICHENKETTE verglichen — Konzept 4.5.1 nennt sie
     * ausdrücklich „Namensmuster". Anwendungen benennen Felder `user_password` oder
     * `new_password_confirm`; ein vollständiger Vergleich würde dort stumm versagen.
     */
    #[DataProvider('sensitiveParameters')]
    public function testSensitiveParametersAreDetected(string $parameter): void
    {
        self::assertTrue(TestCleaner::rules()->isSensitiveParameter($parameter));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveParameters(): iterable
    {
        foreach (['password', 'passwd', 'pwd', 'secret', 'token', '_token', 'api_key', 'apikey', 'private_key', 'credit_card', 'cvv', 'iban'] as $parameter) {
            yield $parameter => [$parameter];
        }

        yield 'user_password' => ['user_password'];
        yield 'new_password_confirm' => ['new_password_confirm'];
        yield 'PASSWORD' => ['PASSWORD'];
        yield 'csrf_token' => ['csrf_token'];
        yield 'refresh_token' => ['refresh_token'];
        yield 'api-key mit Bindestrich' => ['api-key'];
        yield 'clientSecret' => ['clientSecret'];
        yield 'creditCardNumber' => ['creditCardNumber'];
    }

    #[DataProvider('harmlessParameters')]
    public function testHarmlessParametersStayUntouched(string $parameter): void
    {
        self::assertFalse(TestCleaner::rules()->isSensitiveParameter($parameter));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessParameters(): iterable
    {
        yield 'page' => ['page'];
        yield 'expand' => ['expand'];
        yield 'order_id' => ['order_id'];
        yield 'email' => ['email'];
        yield 'leer' => [''];
    }

    /**
     * Über-Redaktion ist die sichere Richtung und deshalb hier ausdrücklich festgehalten,
     * statt sie als Überraschung zu hinterlassen: `secretary` enthält `secret`.
     */
    public function testOverCleaningIsDeliberatelyAccepted(): void
    {
        self::assertTrue(TestCleaner::rules()->isSensitiveParameter('secretary_note'));
        self::assertTrue(TestCleaner::rules()->isSensitiveParameter('tokenizer_config'));
    }

    /**
     * `payload_confidentiality_cleanup.enabled` gibt es nicht — aber leere Regeln müssen als Objekt existieren,
     * damit kein nullbarer Cleaner durch die Verdrahtung wandert.
     */
    public function testEmptyRulesDetectNothing(): void
    {
        $rules = Rules::none();

        self::assertTrue($rules->isEmpty());
        self::assertSame(0, $rules->version, 'Version 0 macht in den Daten ablesbar, dass nicht redigiert wurde');
        self::assertFalse($rules->isSensitiveHeader('Authorization'));
        self::assertFalse($rules->isSensitiveParameter('password'));
    }

    public function testTheShippedListCarriesAVersion(): void
    {
        // Die EINE bewusst gepflegte Zahl. Sie steht hier als Literal, damit eine
        // Änderung der ausgelieferten Liste eine Entscheidung bleibt und kein Nebeneffekt:
        // Die Version reist in jedem raw-Feld mit, und der Collector unterscheidet daran,
        // ob ein fehlender Wert redigiert oder nie vorhanden war. Alle anderen Tests
        // lesen sie über TestCleaner ab, statt sie zu wiederholen.
        self::assertSame(2, TestCleaner::rules()->version);
    }
}
