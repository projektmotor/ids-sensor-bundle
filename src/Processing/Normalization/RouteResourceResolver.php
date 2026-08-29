<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsEventData\Payload\ResourceReference;

/**
 * Leitet `resource_type` und `resource_id` aus Routenname und Routenparametern ab
 * (Konzept 3.1.1, offener Punkt O2).
 *
 * WOZU
 *
 * Regel B7 ist eine KERNEL-Regel (Konzept 4.3.2): „derselbe actor_user greift in 5 Min.
 * auf mehr als N numerisch benachbarte Ressourcen-Identifier desselben Typs zu". P1 und
 * P2 arbeiten auf erfolgreichen Zugriffen, also ebenfalls auf `kernel.response`. Der
 * Payload trug dort bislang nur `path` und `route` — die Nachbarschaft war damit nur über
 * Zeichenkettenanalyse im Collector zu haben, für jede Zeile erneut.
 *
 * WARUM DER ROUTENNAME DER TYP IST
 *
 * Weil er der einzige Name ist, den diese Ebene BEOBACHTET statt zu raten. Aus
 * `/api/orders/42` einen Typ „order" abzuleiten hieße, eine Pfadgrammatik samt
 * Singularbildung zu erfinden — sprachabhängig, projektabhängig und lautlos falsch, wo
 * sie danebenliegt. Der Routenname dagegen ist stabil: Alle Zugriffe auf
 * `/orders/{id}` tragen ihn, und genau diese Menge will die Regel gruppieren.
 *
 * Er ist damit ein anderes Vokabular als auf der Security-Ebene, wo der Typ aus dem
 * Klassennamen kommt. Das ist kein Versehen: Beide Ebenen benennen den Ressourcentyp so,
 * wie sie ihn sehen können, und der Collector gruppiert innerhalb einer Ebene. Ein
 * gemeinsames Vokabular gäbe es nur um den Preis einer geratenen Übersetzung.
 *
 * LÄUFT IN PHASE B
 *
 * Nach dem Absenden der Antwort, also außerhalb des 5-ms-Budgets aus Konzept 2.1. Der
 * Sensor legt nur die Routenparameter ab, die ohnehin schon im Request stehen.
 *
 * @internal
 */
final class RouteResourceResolver
{
    /**
     * Die Kennung, die eine Ressource benennt — in dieser Reihenfolge gesucht.
     *
     * Die Reihenfolge ist die Entscheidung. `id` zuerst, weil es die verbreitetste
     * Schreibweise ist; danach alles, was auf `id` endet (`orderId`, `order_id`); und
     * erst wenn beides fehlt, ein einzelner übriger Parameter — das deckt `{slug}` und
     * `{uuid}` ab, ohne bei zwei Parametern zu raten, welcher gemeint ist.
     */
    private const PRIMARY_PARAMETER = 'id';

    public function __construct(private readonly int $maxIdLength = ResourceReference::MAX_ID_LENGTH)
    {
    }

    /**
     * @param array<array-key, mixed> $routeParameters
     *
     * @return array<string, string|null> Typ und Kennung unter ihren Drahtformat-Schlüsseln
     */
    public function resolve(?string $route, array $routeParameters): array
    {
        return [
            ResourceReference::FIELD_RESOURCE_TYPE => $this->type($route),
            ResourceReference::FIELD_RESOURCE_ID => $this->id($routeParameters),
        ];
    }

    private function type(?string $route): ?string
    {
        if (null === $route || '' === $route) {
            return null;
        }

        return FieldValue::truncate(mb_strtolower($route), ResourceReference::MAX_TYPE_LENGTH);
    }

    /**
     * @param array<array-key, mixed> $routeParameters
     */
    private function id(array $routeParameters): ?string
    {
        $candidates = self::usableParameters($routeParameters);

        if (isset($candidates[self::PRIMARY_PARAMETER])) {
            return $this->cap($candidates[self::PRIMARY_PARAMETER]);
        }

        foreach ($candidates as $name => $value) {
            if (str_ends_with(mb_strtolower($name), self::PRIMARY_PARAMETER)) {
                return $this->cap($value);
            }
        }

        return 1 === \count($candidates) ? $this->cap(reset($candidates)) : null;
    }

    /**
     * Skalare Parameter ohne die internen des Frameworks.
     *
     * Der führende Unterstrich schließt `_locale`, `_format` und Verwandte aus: Sie
     * stehen in `_route_params`, sobald sie im Pfad vorkommen, benennen aber keine
     * Ressource. Ohne den Ausschluss wäre `/de/impressum` eine Ressource des Typs
     * `app_imprint` mit der Kennung `de` — und die Nachbarschaftsregel zählte
     * Sprachwechsel.
     *
     * @param array<array-key, mixed> $routeParameters
     *
     * @return array<string, string>
     */
    private static function usableParameters(array $routeParameters): array
    {
        $usable = [];

        foreach ($routeParameters as $name => $value) {
            if (!\is_string($name) || str_starts_with($name, '_') || !\is_scalar($value)) {
                continue;
            }

            $usable[$name] = (string) $value;
        }

        return $usable;
    }

    private function cap(string $value): ?string
    {
        return '' === $value ? null : FieldValue::truncate($value, $this->maxIdLength);
    }
}
