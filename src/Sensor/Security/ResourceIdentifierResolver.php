<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Security;

use ProjektMotor\IdsSensor\Contract\IdsResourceIdentifier;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ermittelt payload.resource aus einem beliebigen Voter-Subjekt.
 *
 * Konzept 3.1.2 verlangt einen Identifier-String wie `Order#42` und „niemals das
 * vollständige Objekt". Das Subjekt ist aber alles, was die Anwendung an isGranted()
 * übergibt: eine Entity, ein Request, ein Skalar, ein Enum oder null.
 *
 * ZWEI HARTE REGELN
 *
 * 1. KEIN Datenbankzugriff. getId() auf einem uninitialisierten Doctrine-Proxy löst ein
 *    Lazy-Load aus — im Request-Pfad laut Konzept 2.1 Sensorik verboten. Die Auflösung
 *    prüft deshalb den Initialisierungszustand und bricht bei jeder Exception ab.
 * 2. KEIN __toString(). Das könnte ebenfalls nachladen und schreibt im Zweifel
 *    personenbezogene Daten aus — genau das, was die Regel „niemals das vollständige
 *    Objekt" verhindern soll.
 *
 * @internal
 */
final class ResourceIdentifierResolver
{
    public const MAX_LENGTH = 256;

    private const PROXY_INTERFACE = 'Doctrine\Persistence\Proxy';

    private const LAZY_OBJECT_INTERFACE = 'Symfony\Component\VarExporter\LazyObjectInterface';

    public function resolve(mixed $subject): ?string
    {
        if (null === $subject) {
            // Rollenprüfungen ohne Subjekt (isGranted('ROLE_ADMIN')) haben keine
            // Ressource. null ist die richtige Auskunft.
            return null;
        }

        try {
            return $this->truncate($this->doResolve($subject));
        } catch (\Throwable) {
            // Auflösung fehlgeschlagen — das Event ist trotzdem wertvoll. Lieber
            // resource: null als kein Event.
            return null;
        }
    }

    /**
     * Der null-Fall ist bereits in resolve() abgefangen — ab hier gibt es immer eine
     * Auskunft, und sei es nur der Klassenname.
     */
    private function doResolve(mixed $subject): string
    {
        // 1. Die ausdrückliche Angabe der Anwendung hat Vorrang.
        if ($subject instanceof IdsResourceIdentifier) {
            $explicit = $subject->getIdsResourceId();

            if (null !== $explicit && '' !== $explicit) {
                return $explicit;
            }
        }

        // 2. Der access_control-Fall: AccessListener übergibt den Request als Subjekt.
        // Ohne diese Sonderbehandlung trüge jede access_control-Ablehnung
        // resource: null — und Regelautoren verlören den abgelehnten Pfad.
        if ($subject instanceof Request) {
            return 'Request#'.$subject->getPathInfo();
        }

        if ($subject instanceof \UnitEnum) {
            return (new \ReflectionClass($subject))->getShortName().'#'.$subject->name;
        }

        if (\is_scalar($subject)) {
            return (string) $subject;
        }

        if (\is_array($subject)) {
            return 'array#'.\count($subject);
        }

        if (\is_object($subject)) {
            return $this->fromObject($subject);
        }

        return get_debug_type($subject);
    }

    private function fromObject(object $subject): string
    {
        $short = (new \ReflectionClass($subject))->getShortName();

        // Doctrine-Proxys tragen einen generierten Klassennamen; der Elternname ist die
        // brauchbare Auskunft.
        //
        // Die Interfaces stehen in Variablen, weil `instanceof` rechts nur einen
        // Klassennamen oder eine Variable erlaubt — `instanceof self::KONSTANTE` ist ein
        // Parse-Fehler. Als Strings statt als ::class, damit weder Doctrine noch
        // VarExporter zur Abhängigkeit werden.
        $proxy = self::PROXY_INTERFACE;
        $lazy = self::LAZY_OBJECT_INTERFACE;

        if ($subject instanceof $proxy || $subject instanceof $lazy) {
            $parent = get_parent_class($subject);

            if (false !== $parent) {
                $short = (new \ReflectionClass($parent))->getShortName();
            }
        }

        $id = $this->readId($subject);

        return null === $id ? $short : $short.'#'.$id;
    }

    /**
     * Liest die Kennung, wenn das ohne Nebenwirkung möglich ist.
     *
     * Doctrine-Proxys beantworten getId() üblicherweise aus dem Identifier, ohne zu
     * laden. Sicher ist das aber nicht — deshalb steht der Aufruf in einem try, und
     * jeder Fehlschlag führt zum Klassennamen statt zu einer Exception im Sensor.
     */
    private function readId(object $subject): int|string|null
    {
        if (!method_exists($subject, 'getId')) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($subject, 'getId');

            if (!$method->isPublic() || 0 !== $method->getNumberOfRequiredParameters()) {
                return null;
            }

            $id = $subject->getId();
        } catch (\Throwable) {
            return null;
        }

        if (\is_int($id) || \is_string($id)) {
            return $id;
        }

        // Zusammengesetzte Schlüssel: nur skalare Bestandteile, verbunden.
        if (\is_array($id)) {
            $parts = array_filter($id, static fn (mixed $part): bool => \is_scalar($part));

            return [] === $parts ? null : implode('-', array_map(static fn (mixed $p): string => (string) $p, $parts));
        }

        return null;
    }

    private function truncate(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return mb_strlen($value) > self::MAX_LENGTH ? mb_substr($value, 0, self::MAX_LENGTH) : $value;
    }
}
