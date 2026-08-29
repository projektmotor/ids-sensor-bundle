<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Processing\Normalization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Payload\ResourceReference;
use ProjektMotor\IdsSensor\Processing\Normalization\RouteResourceResolver;

/**
 * Die Ableitung von `resource_type` und `resource_id` — Konzept 3.1.1, offener Punkt O2.
 *
 * Geprüft wird vor allem die REIHENFOLGE der Kandidatensuche und das, was ausdrücklich
 * NICHT als Kennung durchgeht. Beides entscheidet, ob Regel B7 die richtigen Zugriffe
 * gruppiert — eine falsche Kennung erzeugt keinen Fehler, sondern eine Regel, die
 * daneben zählt.
 */
#[CoversClass(RouteResourceResolver::class)]
final class RouteResourceResolverTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    #[DataProvider('kennungen')]
    public function testItPicksTheIdentifyingParameter(array $parameters, ?string $erwartet): void
    {
        self::assertSame($erwartet, $this->resolve('app_order_show', $parameters)[ResourceReference::FIELD_RESOURCE_ID]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string|null}>
     */
    public static function kennungen(): iterable
    {
        yield 'id gewinnt' => [['id' => '42', 'slug' => 'bestellung'], '42'];
        yield 'orderId, wenn id fehlt' => [['orderId' => '42'], '42'];
        yield 'order_id ebenso' => [['order_id' => '42'], '42'];
        // Ein einzelner Parameter ist eindeutig, auch ohne "id" im Namen.
        yield 'einzelner Parameter' => [['slug' => 'bestellung-42'], 'bestellung-42'];
        // Zwei ohne id-Namen sind es nicht — raten wäre schlimmer als schweigen.
        yield 'zwei mehrdeutige' => [['slug' => 'a', 'jahr' => '2026'], null];
        yield 'keine Parameter' => [[], null];
        yield 'Zahlen werden Zeichenketten' => [['id' => 42], '42'];
    }

    /**
     * Die internen Parameter des Frameworks benennen keine Ressource.
     *
     * `_locale` steht in `_route_params`, sobald es im Pfad vorkommt. Ohne den
     * Ausschluss wäre `/de/impressum` eine Ressource mit der Kennung `de` — und die
     * Nachbarschaftsregel zählte Sprachwechsel.
     */
    public function testFrameworkParametersAreNotIdentifiers(): void
    {
        $resolved = $this->resolve('app_imprint', ['_locale' => 'de', '_format' => 'html']);

        self::assertNull($resolved[ResourceReference::FIELD_RESOURCE_ID]);
    }

    /**
     * Der einzelne übrige Parameter greift erst, NACHDEM die internen weg sind — sonst
     * wäre `/de/bestellungen/42` mehrdeutig und fiele auf null zurück.
     */
    public function testTheSingleParameterRuleIgnoresFrameworkParameters(): void
    {
        $resolved = $this->resolve('app_order_show', ['_locale' => 'de', 'slug' => 'b-42']);

        self::assertSame('b-42', $resolved[ResourceReference::FIELD_RESOURCE_ID]);
    }

    /**
     * Nicht-skalare Parameter kommen vor (Routen mit Standardwerten aus Arrays) und
     * sind keine Kennung.
     */
    public function testNonScalarParametersAreIgnored(): void
    {
        $resolved = $this->resolve('app_order_show', ['id' => ['a', 'b']]);

        self::assertNull($resolved[ResourceReference::FIELD_RESOURCE_ID]);
    }

    /**
     * Der Typ ist der ROUTENNAME — der einzige Name, den diese Ebene beobachtet statt
     * ihn zu raten. Kleingeschrieben, weil der Collector danach gruppiert und zwei
     * Schreibweisen zwei Typen wären.
     */
    public function testTheTypeIsTheLowercasedRouteName(): void
    {
        $resolved = $this->resolve('App_Order_Show', ['id' => '42']);

        self::assertSame('app_order_show', $resolved[ResourceReference::FIELD_RESOURCE_TYPE]);
    }

    /**
     * Ohne Route keine Ressource: Ein routenloser Pfad ist genau das Scanning-Signal
     * aus Konzept 2.1.1, und ein erfundener Typ machte daraus eine Ressourcengruppe.
     */
    public function testWithoutARouteThereIsNoType(): void
    {
        self::assertNull($this->resolve(null, ['id' => '42'])[ResourceReference::FIELD_RESOURCE_TYPE]);
        self::assertNull($this->resolve('', ['id' => '42'])[ResourceReference::FIELD_RESOURCE_TYPE]);
    }

    /**
     * Beide Werte sind angreifernah — die Kennung kommt aus dem Pfad. Die Grenzen des
     * Drahtformats gelten deshalb hier und nicht erst beim Collector.
     */
    public function testBothValuesAreCapped(): void
    {
        $resolved = $this->resolve(str_repeat('r', 500), ['id' => str_repeat('x', 500)]);

        self::assertSame(ResourceReference::MAX_TYPE_LENGTH, mb_strlen((string) $resolved[ResourceReference::FIELD_RESOURCE_TYPE]));
        self::assertSame(ResourceReference::MAX_ID_LENGTH, mb_strlen((string) $resolved[ResourceReference::FIELD_RESOURCE_ID]));
    }

    /**
     * Eine leere Kennung ist keine — sie benennt nichts, und der Collector gruppiert
     * danach.
     */
    public function testAnEmptyIdentifierIsNone(): void
    {
        self::assertNull($this->resolve('app_order_show', ['id' => ''])[ResourceReference::FIELD_RESOURCE_ID]);
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, string|null>
     */
    private function resolve(?string $route, array $parameters): array
    {
        return (new RouteResourceResolver())->resolve($route, $parameters);
    }
}
