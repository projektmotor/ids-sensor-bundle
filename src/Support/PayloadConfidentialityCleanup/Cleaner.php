<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup;

/**
 * Ersetzt sensible Werte durch `[confidential]` und behält die Feldnamen (Konzept 4.5.1).
 *
 * AUSFÜHRUNGSORT: DER SENSOR
 *
 * Konzept 4.5.1 legt das ausdrücklich fest und begründet es: liefe die Redaktion erst im
 * Consumer, liefen Klartext-Zugangsdaten über den Broker und landeten dort in Queues,
 * Logs und Spool-Dateien. In dieser Umsetzung gilt das doppelt, weil der Spool eine Datei
 * auf dem Anwendungshost ist — ein zweiter Ort, an dem Klartext liegen bliebe, und einer,
 * den niemand als Beweisspeicher behandelt und entsprechend schützt.
 *
 * Umgesetzt ist das dadurch, dass die Redaktion beim AUFBAU der Daten stattfindet und
 * nicht als nachgelagerter Durchlauf: {@see Builder}
 * ruft den Cleaner, während er raw zusammensetzt. Ein unredigierter Wert existiert damit
 * zu keinem Zeitpunkt in einer serialisierbaren Struktur — es gibt keine Reihenfolge, in
 * der man die Redaktion vergessen könnte, und keinen zweiten Pfad, der sie umgeht.
 *
 * @internal
 */
final class Cleaner
{
    /**
     * Der Vorgabewert aus Konzept 4.5.1. Über
     * `payload_confidentiality_cleanup.replacement` änderbar — etwa,
     * wenn ein Auswertungswerkzeug auf einen anderen Marker angewiesen ist.
     */
    public const DEFAULT_PLACEHOLDER = '[confidential]';

    /**
     * Tiefenbegrenzung für verschachtelte Strukturen.
     *
     * Ein Angreifer kann verschachtelte Formular- und Query-Strukturen beliebig tief
     * senden (`a[b][c][d]…`). Ohne Grenze wäre die Rekursion angreifergesteuert.
     */
    public const MAX_DEPTH = 4;

    public const MAX_VALUE_LENGTH = 512;

    public function __construct(
        private readonly Rules $rules,
        private readonly string $placeholder = self::DEFAULT_PLACEHOLDER,
    ) {
    }

    public function placeholder(): string
    {
        return $this->placeholder;
    }

    public function rulesVersion(): int
    {
        return $this->rules->version;
    }

    /**
     * Header, wie {@see \Symfony\Component\HttpFoundation\HeaderBag::all()} sie liefert:
     * Name in Kleinschreibung, Wert als Liste.
     *
     * Mehrfach gesetzte Header behalten ihre Anzahl — bei `Set-Cookie` ist die Zahl der
     * gesetzten Cookies eine Information, ihr Inhalt nicht.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    public function cleanHeaders(array $headers, int $maxHeaders = 60): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if (\count($result) >= $maxHeaders) {
                break;
            }

            $name = (string) $name;

            if ($this->rules->isSensitiveHeader($name)) {
                $result[$name] = $this->placeholder;

                continue;
            }

            $result[$name] = $this->flattenHeaderValue($value);
        }

        return $result;
    }

    /**
     * Parameter aus Query-String oder Formular.
     *
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function cleanParameters(array $parameters, int $depth = 0): array
    {
        $result = [];

        foreach ($parameters as $key => $value) {
            $name = (string) $key;

            if ($this->rules->isSensitiveParameter($name)) {
                // Auch wenn der Wert ein Array ist: alles darunter gilt als sensibel.
                // Andernfalls würde `password[confirm]=…` die Prüfung umgehen.
                $result[$name] = $this->placeholder;

                continue;
            }

            $result[$name] = $this->cleanValue($value, $depth);
        }

        return $result;
    }

    /**
     * Der Einzelwert-Weg für Aufrufer, die den Feldnamen kennen, aber keine Struktur
     * übergeben — etwa der QueryNormalizer, der bereits abflacht.
     */
    public function cleanParameterValue(string $name, mixed $value): mixed
    {
        return $this->rules->isSensitiveParameter($name) ? $this->placeholder : $value;
    }

    /**
     * Für Aufrufer, die den Wert selbst ersetzen — etwa weil sie einen ganzen Teilbaum
     * verwerfen, statt ihn zu durchlaufen.
     */
    public function cleansParameter(string $name): bool
    {
        return $this->rules->isSensitiveParameter($name);
    }

    private function cleanValue(mixed $value, int $depth): mixed
    {
        if (\is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return \sprintf('[depth limit, %d entries]', \count($value));
            }

            return $this->cleanParameters($value, $depth + 1);
        }

        if (\is_string($value)) {
            return mb_strlen($value) > self::MAX_VALUE_LENGTH
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH)
                : $value;
        }

        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        // Objekte gehören nicht in Request-Parameter. Falls doch: der Typname, niemals
        // der Inhalt — ein __toString() könnte alles ausschreiben.
        return get_debug_type($value);
    }

    private function flattenHeaderValue(mixed $value): string
    {
        if (\is_array($value)) {
            $parts = [];

            foreach ($value as $part) {
                $parts[] = \is_scalar($part) ? (string) $part : get_debug_type($part);
            }

            $value = implode(', ', $parts);
        } elseif (\is_scalar($value)) {
            $value = (string) $value;
        } else {
            $value = get_debug_type($value);
        }

        return mb_strlen($value) > self::MAX_VALUE_LENGTH
            ? mb_substr($value, 0, self::MAX_VALUE_LENGTH)
            : $value;
    }
}
