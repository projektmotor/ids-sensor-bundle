<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;

/**
 * Bringt eine projektdefinierte Nutzlast in eine Form, die das Event-Schema verträgt.
 *
 * Nötig, weil Konzept 3.1.3 die Business-Nutzlast 1:1 durchreicht — sie ist also
 * vollständig anwendungsdefiniert. Das Konzept EMPFIEHLT flache Struktur und primitive
 * Typen, kann es aber nicht erzwingen. Ohne Bereinigung würde eine Anwendung, die eine
 * Entity in getPayload() zurückgibt, entweder die Serialisierung sprengen oder einen
 * ganzen Objektgraphen in den Beweisspeicher schreiben — letzteres widerspricht
 * derselben Regel, die Konzept 3.1.2 für payload.resource aufstellt: Identifier, nie
 * das vollständige Objekt.
 *
 * @internal
 */
final class PayloadSanitizer
{
    /** Konzept Abschnitt 3: „maximal zweistufig verschachteltes JSON-Objekt". */
    public const MAX_DEPTH = 3;

    public const MAX_ELEMENTS = 100;

    public const MAX_STRING_LENGTH = 2048;

    /**
     * Für das Bundle reservierter Schlüsselpräfix.
     *
     * Der Normalisierer hinterlegt damit eigene Vermerke (etwa einen ungültigen
     * Severity-Hint). Eingehende Schlüssel mit diesem Präfix werden entfernt, damit
     * eine Anwendung solche Vermerke nicht fälschen kann.
     */
    public const RESERVED_PREFIX = '_ids_';

    /**
     * Auch der Business-Payload läuft durch die Denylist aus Konzept 4.5.1.
     *
     * Der Inhalt ist hier vollständig anwendungsdefiniert: ein Domain-Event darf laut
     * Konzept 2.1.3 tragen, was die Anwendung für relevant hält — einschließlich eines
     * Feldes `new_password`. Anders als raw wird payload bei JEDER Stufe übertragen und
     * nie weggelassen; eine Lücke hier wäre also die folgenreichere.
     */
    public function __construct(
        private readonly Cleaner $cleaner,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->sanitizeArray($payload, 1);
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $input, int $depth): array
    {
        $result = [];
        $count = 0;

        foreach ($input as $key => $value) {
            if ($count >= self::MAX_ELEMENTS) {
                $result['__truncated'] = true;
                break;
            }

            $stringKey = (string) $key;

            // Reservierte Schlüssel entfernen: sonst könnte eine Anwendung die
            // Vermerke des Sensors fälschen.
            if (str_starts_with($stringKey, self::RESERVED_PREFIX)) {
                continue;
            }

            // Vor dem Bereinigen: ein sensibler Schlüssel macht den ganzen Teilbaum
            // sensibel. Andernfalls käme `password[confirm]` an der Prüfung vorbei.
            if ($this->cleaner->cleansParameter($stringKey)) {
                $result[$stringKey] = $this->cleaner->placeholder();
                ++$count;

                continue;
            }

            $result[$stringKey] = $this->sanitizeValue($value, $depth);
            ++$count;
        }

        return $result;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            // NAN und INF sind nicht JSON-kodierbar und würden den ganzen Frame
            // scheitern lassen.
            return is_finite($value) ? $value : null;
        }

        if (\is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING_LENGTH
                ? mb_substr($value, 0, self::MAX_STRING_LENGTH)
                : $value;
        }

        if (\is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                // Zu tief: als JSON-Zeichenkette erhalten, statt die Tiefenzusage des
                // Schemas zu brechen.
                return $this->encodeDeep($value);
            }

            return $this->sanitizeArray($value, $depth + 1);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        // Objekte werden auf eine Kennung reduziert, niemals ausgeschrieben. Kein
        // __toString(): das könnte ein Lazy-Load auslösen oder personenbezogene Daten
        // ausschreiben.
        if (\is_object($value)) {
            return $this->describeObject($value);
        }

        return get_debug_type($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encodeDeep(array $value): string
    {
        $encoded = json_encode(
            $value,
            \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if (false === $encoded) {
            return '[nicht kodierbar]';
        }

        return mb_strlen($encoded) > self::MAX_STRING_LENGTH
            ? mb_substr($encoded, 0, self::MAX_STRING_LENGTH)
            : $encoded;
    }

    private function describeObject(object $value): string
    {
        $short = (new \ReflectionClass($value))->getShortName();

        // getId() nur, wenn es ohne Argumente auskommt und der Wert skalar ist. Bei
        // einem uninitialisierten Doctrine-Proxy antwortet getId() üblicherweise ohne
        // Nachladen; scheitert es doch, bleibt der Klassenname.
        if (method_exists($value, 'getId')) {
            try {
                $reflection = new \ReflectionMethod($value, 'getId');

                if ($reflection->isPublic() && 0 === $reflection->getNumberOfRequiredParameters()) {
                    $id = $value->getId();

                    if (\is_scalar($id)) {
                        return $short.'#'.$id;
                    }
                }
            } catch (\Throwable) {
                // Kennung nicht ermittelbar — der Klassenname ist die verbleibende
                // Auskunft. Kein Grund, das Event zu verlieren.
            }
        }

        return $short;
    }
}
