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
     *
     * Höher als die 3 des
     * {@see \ProjektMotor\IdsSensor\Processing\Normalization\PayloadSanitizer}, und
     * das mit Absicht: Dort bindet die Grenze das Schema aus Konzept Abschnitt 3, hier
     * die forensische Kopie, für die Konzept 2.1.3 mehr Tiefe vorsieht. Die vollständige
     * Begründung der Staffelung steht beim Sanitizer.
     */
    public const MAX_DEPTH = 4;

    public const MAX_VALUE_LENGTH = 512;

    /**
     * Elementbegrenzung je Ebene — das Gegenstück zu {@see self::MAX_DEPTH} in der Breite.
     *
     * Ohne sie war die Breite als einzige Größe dieser Klasse angreifergesteuert:
     * `cleanParameters()` lief über beliebig viele Elemente, und `RawPayload\Builder`
     * übergibt ihm den UNBEREINIGTEN Business-Payload sowie sämtliche Formularfelder.
     * Gebremst wurde erst danach — von `Builder::capped()`, und zwar durch VERWERFEN des
     * ganzen `payload`-Zweiges. Ein Angreifer bekam damit für 5000 Formularfelder genau
     * das, was er wollte: ein leeres raw. Mit einer Kappung bleiben die ersten 200
     * Elemente erhalten und raw behält seinen forensischen Wert.
     *
     * 200 und nicht 100 wie im {@see \ProjektMotor\IdsSensor\Processing\Normalization\PayloadSanitizer}:
     * dessen Grenze bindet das SCHEMA aus Konzept Abschnitt 3, hier geht es um die
     * forensische Kopie, für die Konzept 2.1.3 ausdrücklich mehr Tiefe vorsieht.
     */
    public const MAX_PARAMETERS = 200;

    /**
     * Vermerk über weggelassene Elemente.
     *
     * Steht HIER und nicht im QueryNormalizer, obwohl er ihn zuerst brauchte: Inzwischen
     * setzen ihn drei Bereinigungswege, und der Cleaner ist der einzige, den alle drei
     * kennen dürfen — die Rangfolge in `ArchitectureTest::testGroupsFormALayering()`
     * verbietet den umgekehrten Weg.
     */
    public const TRUNCATED_MARKER = '__truncated';

    /**
     * Header, deren WERT eine URL ist und deren Query deshalb redigiert werden muss.
     *
     * Sie stehen bewusst NICHT in der Denylist: ihr Wert ist forensisch wertvoll — die
     * Herkunft eines Zugriffs ist bei jeder Scanning- und Rechteausweitungsregel eine
     * Auskunft. Vollständig zu ersetzen wäre zu viel, unverändert zu übernehmen zu
     * wenig. {@see cleanUrl()} nimmt genau den Teil heraus, der ein Geheimnis sein kann.
     *
     * Hier hartkodiert und nicht in der Redaktionsliste: es geht nicht darum, WAS
     * geheim ist — das entscheidet weiterhin die Liste über die Parameternamen —,
     * sondern darum, welche Felder überhaupt URLs enthalten. Das ist eine Eigenschaft
     * von HTTP, keine des Projekts.
     *
     * @var list<string>
     */
    private const URL_VALUED_HEADERS = ['referer', 'location', 'content-location'];

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

            if (\in_array(mb_strtolower($name), self::URL_VALUED_HEADERS, true)) {
                $result[$name] = $this->cleanUrl($this->flattenHeaderValue($value));

                continue;
            }

            $result[$name] = $this->flattenHeaderValue($value);
        }

        return $result;
    }

    /**
     * Redigiert den Query-String einer URL und lässt Herkunft und Pfad stehen.
     *
     * Nötig, weil eine URL sensible Werte in einem Feld trägt, dessen NAME unauffällig
     * ist — die Denylist greift über Namen und läuft hier ins Leere. Der Referer ist der
     * praktisch wichtigste Fall: Wer `https://app.example/reset?token=…` öffnet und dort
     * einen Link anklickt, schickt das Token mit. Dieselbe Klasse: `?signature=`,
     * OAuth-`?code=`, Magic-Links.
     *
     * Host und Pfad bleiben stehen; forensisch ist die Herkunft die eigentliche
     * Auskunft. Zugangsdaten in der URL (`https://nutzer:geheim@host/`) werden nicht
     * redigiert, sondern weggelassen — sie sind nie eine Auskunft, aber immer ein
     * Geheimnis. Eine nicht zerlegbare Zeichenkette wird vollständig ersetzt: was wir
     * nicht verstehen, können wir auch nicht redigieren.
     *
     * ZWEI GRÜNDE ZUM NEUAUFBAU, UND DER ZWEITE FEHLTE
     *
     * Der Frühausstieg prüfte nur auf eine Query. Eine URL OHNE Query wurde unverändert
     * zurückgegeben — samt `nutzer:geheim@`. Genau die Zusage im Absatz darüber galt damit
     * nur im Nebenfall, und zwar an einem Feld, das bei JEDER Stufe mitreist
     * (`payload.referer`, Konzept 3.1.1) und zusätzlich in `raw.request_headers.referer`,
     * `location` und `content-location`.
     *
     * Neu aufgebaut wird deshalb, sobald ENTWEDER Zugangsdaten ODER eine Query vorhanden
     * sind. Ist beides nicht der Fall, gibt es nichts zu entfernen und die Zeichenkette
     * bleibt, wie sie ist — ein Neuaufbau würde dort nur Schreibweisen verändern.
     */
    public function cleanUrl(string $url): string
    {
        if ('' === $url) {
            return '';
        }

        $parts = parse_url($url);

        if (false === $parts) {
            return $this->placeholder;
        }

        $hatQuery = isset($parts['query']) && '' !== $parts['query'];
        $hatZugangsdaten = isset($parts['user']) || isset($parts['pass']);

        if (!$hatQuery && !$hatZugangsdaten) {
            return $url;
        }

        $rebuilt = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $rebuilt .= $parts['host'] ?? '';
        $rebuilt .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';

        if ($hatQuery) {
            parse_str((string) $parts['query'], $query);
            $cleaned = $this->cleanParameters($query);
            $rebuilt .= [] === $cleaned ? '' : '?'.http_build_query($cleaned);
        }

        // Das Fragment erreicht den Server ohnehin nie; es steht nur dann hier, wenn die
        // Zeichenkette gar kein echter Referer war.
        return $rebuilt.(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * Redigiert `name=wert`-Paare in einem freien Text.
     *
     * Der Fall, für den es das gibt, ist `payload.exception_message`: Konzept 3.1.1
     * verlangt die Meldung im Payload, und sie ist angreiferbeeinflusst — die häufigste
     * Meldung überhaupt ist „No route found for GET /pfad?token=…". Bis hierher lief
     * dieses Feld an der Denylist vorbei, und zwar bei JEDER Stufe, nicht nur bei
     * `warning`/`critical` wie das raw-Feld.
     *
     * WAS DAS ABDECKT UND WAS NICHT
     *
     * Erfasst wird die Query-Schreibweise `name=wert`, abgegrenzt durch `?`, `&`,
     * Leerraum oder Anfang — also genau die Form, in der URLs und Formulardaten in
     * Meldungen landen. Der Name darf dabei bis zu 256 Zeichen lang sein und nicht nur
     * 64 wie der AUSGABE-Schlüssel im `QueryNormalizer`: Hier entscheidet der Name über
     * die Redaktion, und ein Geheimnis hinter einem übermäßig langen Schlüssel bliebe
     * sonst stehen — derselbe Fehler, nur eine Feldebene weiter. Nicht erfasst wird ein Geheimnis, das in Prosa oder in
     * SQL-Syntax steht (`WHERE password = 'geheim'`): dafür bräuchte es eine Grammatik
     * je Quellsprache, und ein Muster, das jedes Wort neben einem Denylist-Namen
     * schwärzt, machte die Meldung als Erkennungsgrundlage wertlos. Diese Grenze steht
     * in `doc/06-vertraulichkeit.md` und ist eine Entscheidung, kein Versehen.
     */
    public function cleanFreeText(string $text): string
    {
        if ('' === $text) {
            return $text;
        }

        $redigiert = preg_replace_callback(
            '/(^|[?&\s])([A-Za-z0-9_.\[\]-]{1,256})=([^\s&]*)/u',
            fn (array $treffer): string => $this->rules->isSensitiveParameter($treffer[2])
                ? $treffer[1].$treffer[2].'='.$this->placeholder
                : $treffer[0],
            $text,
        );

        // preg_replace_callback liefert bei ungültigem UTF-8 null. Der Fall tritt bei
        // Scanner-Verkehr tatsächlich auf, und dann ist die ungeprüfte Meldung genau
        // das, was hier nicht durchgehen darf.
        return null === $redigiert ? $this->placeholder : $redigiert;
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
            if (\count($result) >= self::MAX_PARAMETERS) {
                $result[self::TRUNCATED_MARKER] = true;

                break;
            }

            $name = (string) $key;

            // Der Vermerk darf nicht von außen kommen: sonst täuschte eine Anwendung —
            // oder ein Angreifer, der Werte in ein Formular schreibt — einen
            // Vollständigkeitsverlust vor, den es nie gab.
            if (self::TRUNCATED_MARKER === $name) {
                continue;
            }

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
