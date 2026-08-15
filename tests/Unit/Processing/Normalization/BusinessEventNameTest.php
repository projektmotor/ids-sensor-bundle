<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Processing\Normalization\BusinessEventNormalizer;

/**
 * Die Namensregel für Business-Events — bereinigen statt verwerfen.
 *
 * Business-Events sind laut Konzept 2.1.3 die einzige Signalklasse für ERFOLGREICHE
 * Angriffe. Ein Event wegen eines Namensverstoßes fallenzulassen wäre der schlechteste
 * mögliche Umgang damit; es wird bereinigt übertragen, und der Originalname bleibt als
 * `_ids_event_name_raw` im Payload.
 */
#[CoversClass(BusinessEventNormalizer::class)]
final class BusinessEventNameTest extends TestCase
{
    #[DataProvider('namen')]
    public function testTheNameIsNormalisedNotRejected(string $roh, string $erwartet): void
    {
        self::assertSame($erwartet, BusinessEventNormalizer::normalizeEventName($roh));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namen(): iterable
    {
        yield 'gültig' => ['user.roles_changed', 'user.roles_changed'];
        yield 'mehrstufig' => ['shop.order.amount_overridden', 'shop.order.amount_overridden'];
        yield 'Großschreibung' => ['User.RolesChanged', 'user.roleschanged'];
        yield 'Leerzeichen' => ['user rollen geändert', 'user_rollen_ge_ndert'];
        yield 'Bindestriche' => ['user-roles-changed', 'user_roles_changed'];
        yield 'doppelte Trenner' => ['user__roles', 'user_roles'];
        yield 'führende Trenner' => ['._user.roles._', 'user.roles'];
        yield 'Leerraum außen' => ['  user.roles  ', 'user.roles'];
        yield 'leer' => ['', 'business.unnamed'];
        yield 'nur Trenner' => ['___', 'business.unnamed'];
    }

    /**
     * Ein ohne Punkt geschriebener Name bleibt erhalten — er ist zwar nicht
     * mustergültig, aber eindeutig und für den Regelautor brauchbar.
     */
    public function testANameWithoutADotSurvives(): void
    {
        self::assertSame('bestellung', BusinessEventNormalizer::normalizeEventName('bestellung'));
    }

    /**
     * Der Name ist angreifergesteuert, sobald die Anwendung ihn aus Eingaben baut —
     * also wird er gekappt.
     */
    public function testAnOverlongNameIsTruncated(): void
    {
        $normalisiert = BusinessEventNormalizer::normalizeEventName(str_repeat('a', 500));

        self::assertLessThanOrEqual(BusinessEventNormalizer::MAX_EVENT_NAME_LENGTH, mb_strlen($normalisiert));
    }
}
