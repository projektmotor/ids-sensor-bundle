<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup;

/**
 * Die Redaktionsliste aus Konzept 4.5.1, in vergleichsfertiger Form.
 *
 * ZWEI UNTERSCHIEDLICHE VERGLEICHSARTEN
 *
 * Das ist keine Inkonsequenz, sondern folgt dem Konzept: die Header-Tabelle nennt
 * konkrete Namen, die Parameter-Tabelle ausdrücklich „Namensmuster".
 *
 *  - Header werden VOLLSTÄNDIG verglichen. `Cookie` darf nicht `Cookie-Policy`
 *    mitredigieren, und HTTP-Headernamen sind ein geschlossener, bekannter Wortschatz.
 *  - Parameter werden als TEILZEICHENKETTE verglichen. Anwendungen benennen Felder
 *    `user_password`, `new_password_confirm` oder `csrf_token`; ein vollständiger
 *    Vergleich würde bei jedem dieser Namen versagen — und zwar stumm, was bei einer
 *    Redaktionsliste die gefährlichste Versagensart ist.
 *
 * Beide Vergleiche sind unterscheidungsfrei, weil Feldnamen aus Formularen und
 * Query-Strings in beliebiger Schreibweise ankommen.
 *
 * @internal
 */
final class Rules
{
    /**
     * @param array<string, true> $headers          Kleinschreibung, als Schlüsselmenge für O(1)-Zugriff
     * @param list<string>        $parameterNeedles Kleinschreibung
     */
    private function __construct(
        public readonly int $version,
        private readonly array $headers,
        private readonly array $parameterNeedles,
    ) {
    }

    /**
     * @param list<string> $headers
     * @param list<string> $parameters
     */
    public static function fromLists(int $version, array $headers, array $parameters): self
    {
        $headerSet = [];

        foreach ($headers as $header) {
            $normalized = self::normalize($header);

            if ('' !== $normalized) {
                $headerSet[$normalized] = true;
            }
        }

        $needles = [];

        foreach ($parameters as $parameter) {
            $normalized = self::normalize($parameter);

            if ('' !== $normalized && !\in_array($normalized, $needles, true)) {
                $needles[] = $normalized;
            }
        }

        return new self($version, $headerSet, $needles);
    }

    /**
     * Leere Regeln — die Nullvariante für Tests und für Aufrufer ohne Liste.
     *
     * ES GIBT KEINEN SCHALTER, DER SIE IM BETRIEB ERZEUGT. Hier stand
     * `payload_confidentiality_cleanup.enabled: false` als Verwendungszweck; diese Option
     * existiert nicht und soll es laut `services_payload_confidentiality_cleanup.yaml`
     * auch nicht — „einen Weg, Werte MIT Klartext zu übertragen, gibt es bewusst nicht".
     * Wer die Liste verkleinern muss, benutzt `merge_defaults: false`.
     *
     * Bewusst ein Objekt mit leeren Listen und nicht `null`: ein nullbarer Cleaner wäre
     * die naheliegendste Art, die Redaktion versehentlich abzuschalten — ein vergessener
     * Konstruktor-Parameter genügte. Version 0 macht in den Daten ablesbar, dass NICHT
     * redigiert wurde.
     */
    public static function none(): self
    {
        return new self(0, [], []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->headers && [] === $this->parameterNeedles;
    }

    public function isSensitiveHeader(string $name): bool
    {
        return isset($this->headers[self::normalize($name)]);
    }

    public function isSensitiveParameter(string $name): bool
    {
        $normalized = self::normalize($name);

        if ('' === $normalized) {
            return false;
        }

        foreach ($this->parameterNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unterstriche und Bindestriche fallen weg, damit `api_key`, `api-key` und `apikey`
     * dasselbe Muster treffen. Ohne diese Angleichung bräuchte die Liste jede
     * Schreibvariante als eigenen Eintrag — und die erste vergessene wäre eine Lücke.
     */
    private static function normalize(string $name): string
    {
        return str_replace(['-', '_'], '', mb_strtolower(trim($name)));
    }
}
