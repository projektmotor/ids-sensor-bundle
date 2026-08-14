<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;

/**
 * Flacht Query-Parameter auf eine Ebene ab.
 *
 * Konzept 3.1.1 — kernel.request: „flaches Objekt aus den Query-Parametern (keine
 * Arrays von Arrays; verschachtelte Query-Strukturen werden auf einer Ebene
 * abgeflacht bzw. als String belassen)". Konzept Abschnitt 3 verlangt zusätzlich, dass
 * payload „immer ein flaches oder maximal zweistufig verschachteltes JSON-Objekt" ist.
 *
 * Die Obergrenzen stehen nicht im Konzept, sind aber unverzichtbar: Query-Strings
 * sind vollständig angreifergesteuert und unbegrenzt lang. Ohne Kappung könnte ein
 * Angreifer mit einer einzigen Anfrage einen Frame erzeugen, der die
 * Größenbegrenzung sprengt und damit alle Events dieses Requests verwirft — ein
 * gezielt auslösbarer blinder Fleck.
 *
 * @internal
 */
final class QueryNormalizer
{
    public const MAX_PARAMS = 50;

    public const MAX_KEY_LENGTH = 64;

    public const MAX_VALUE_LENGTH = 512;

    /** Wird gesetzt, wenn Parameter wegen der Obergrenzen entfallen sind. */
    public const TRUNCATED_MARKER = '__truncated';

    /**
     * Der Cleaner ist PFLICHT und nicht nullbar.
     *
     * Konzept 4.5.1 legt die Denylist für raw fest. payload.query braucht sie genauso —
     * ein `?reset_token=…` steht dort im Klartext, und payload wird im Gegensatz zu raw
     * bei JEDER Stufe übertragen. Ein nullbarer Cleaner wäre die naheliegendste Art,
     * diese Redaktion versehentlich abzuschalten: ein vergessenes Argument in der
     * Verdrahtung, kein Fehler, keine Meldung.
     */
    public function __construct(
        private readonly Cleaner $cleaner,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<string, scalar|null>
     */
    public function normalize(array $query): array
    {
        $normalized = [];
        $truncated = false;

        foreach ($query as $key => $value) {
            if (\count($normalized) >= self::MAX_PARAMS) {
                $truncated = true;
                break;
            }

            $normalizedKey = mb_substr((string) $key, 0, self::MAX_KEY_LENGTH);
            if ('' === $normalizedKey || self::TRUNCATED_MARKER === $normalizedKey) {
                continue;
            }

            // Erst redigieren, dann normalisieren: der Wert eines sensiblen Parameters
            // darf nicht einmal gekürzt durchkommen. Ein auf 512 Zeichen gekürztes Token
            // ist immer noch ein Token.
            $normalized[$normalizedKey] = $this->normalizeValue(
                $this->cleaner->cleanParameterValue($normalizedKey, $value),
                $truncated,
            );
        }

        if ($truncated) {
            $normalized[self::TRUNCATED_MARKER] = true;
        }

        return $normalized;
    }

    /**
     * Verschachtelte Strukturen werden zu JSON, damit die Zusage „maximal
     * zweistufig" nicht durch beliebig tiefe Query-Arrays gebrochen wird. Der
     * Inhalt bleibt für die Nachanalyse lesbar.
     */
    private function normalizeValue(mixed $value, bool &$truncated): string|int|float|bool|null
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value)) {
            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $truncated = true;

                return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
            }

            return $value;
        }

        if (\is_array($value)) {
            $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (false === $encoded) {
                return null;
            }

            if (mb_strlen($encoded) > self::MAX_VALUE_LENGTH) {
                $truncated = true;

                return mb_substr($encoded, 0, self::MAX_VALUE_LENGTH);
            }

            return $encoded;
        }

        // Objekte und Ressourcen kommen in Query-Parametern nicht vor; falls doch,
        // ist der Typname die einzige sinnvolle Auskunft.
        return get_debug_type($value);
    }
}
