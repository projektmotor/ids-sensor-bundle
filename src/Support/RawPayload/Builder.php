<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\RawPayload;

use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baut das raw-Feld — je Event-Typ unterschiedlich, redigiert und träge.
 *
 * ERGÄNZUNG ZUM KONZEPT
 *
 * Abschnitt 3 sagt zu raw nur „Struktur abhängig von event_type" und lässt offen, was
 * drinsteht. Ohne festgelegten Inhalt ist die Redaktion aus 4.5.1 nicht prüfbar: man kann
 * nicht testen, dass ein Cookie-Wert nicht durchkommt, wenn nicht definiert ist, ob
 * überhaupt Header übertragen werden. Diese Klasse legt es je Typ fest.
 *
 * WARUM TRÄGE (Closure)
 *
 * raw wird laut Konzept 3 nur für `warning` und `critical` übertragen. Die Masse aller
 * Events ist `info`. Würde raw beim Erfassen gebaut, zahlte der info-Pfad im Request die
 * vollen Kosten für Header-Kopie, Redaktion und Trace-Aufbau — und würde sie anschließend
 * wegwerfen. Deshalb hält {@see \ProjektMotor\IdsSensor\Sensor\CapturedEvent} nur eine
 * Closure, die {@see \ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent::toArray()} genau dann
 * auswertet, wenn die Stufe raw überhaupt trägt.
 *
 * KEINE DOPPELUNG ÜBER DIE EVENTS EINES REQUESTS
 *
 * Ein fehlgeschlagener Request erzeugt bis zu vier Events. Würde jedes die Request-Header
 * mitschicken, wäre raw viermal fast dasselbe — bei einem Feld, das laut Konzept 4.2.3
 * über 95 % des Datenvolumens ausmacht. Stattdessen trägt jeder Typ genau das, was die
 * anderen nicht haben:
 *
 *  - `kernel.response`  → der gesamte Austausch: Anfrage-Header, Query, Formularfelder,
 *                         Cookie-NAMEN und Antwort-Header
 *  - `kernel.exception` → die Aufrufkette und der Exception-Verlauf
 *  - `kernel.request`   → NICHTS, siehe unten
 *  - Security-Events    → nichts; ihr payload ist vollständig, und der Austausch steht im
 *                         kernel.response-Event derselben correlation_id
 *
 * Diese Verkettung über die correlation_id ist genau der Zweck der Feldredundanz aus
 * Konzept 3.2 — sie hier zu wiederholen wäre Volumen ohne Erkenntnisgewinn.
 *
 * WARUM DIE ANFRAGESEITE AM RESPONSE-EVENT HÄNGT UND NICHT AM REQUEST-EVENT
 *
 * Das ist keine Geschmacksfrage, sondern folgt zwingend aus zwei Konzeptfestlegungen, die
 * zusammengenommen eine Falle bilden:
 *
 *  - Abschnitt 3: raw wird nur bei `warning` und `critical` übertragen.
 *  - Abschnitt 2.2.1, Ableitungsregeln: `kernel.request` ist IMMER `info`.
 *
 * Ein raw am kernel.request-Event würde deshalb ausnahmslos verworfen — die Header, die
 * Formularfelder und die Cookie-Namen eines Angriffsversuchs erreichten den Beweisspeicher
 * NIE. Genau die Daten, für die raw laut Abschnitt 3 überhaupt aufgenommen wurde.
 *
 * Das kernel.response-Event ist der richtige Träger: seine Stufe spiegelt den AUSGANG des
 * Requests (403, 429, 500 → warning/critical), und Konzept 3.2 macht es über die redundant
 * kopierten Felder ohnehin zum zusammenfassenden Event des Requests.
 *
 * Bleibende Grenze, die in die README gehört: stirbt der Prozess, bevor eine Antwort
 * entsteht, gibt es kein kernel.response — und damit kein raw der Anfrageseite. Das
 * Notnagel-Shutdown versendet die gepufferten Events trotzdem, nur eben ohne diesen Teil.
 *
 * @internal
 */
final class Builder
{
    public const MAX_TRACE_FRAMES = 20;

    public const MAX_COOKIE_NAMES = 30;

    public const MAX_EXCEPTION_CHAIN = 5;

    /** Kennzeichnet die Fassung der Redaktionsliste, nach der dieses raw entstanden ist. */
    public const FIELD_CLEANUP_VERSION = 'cleanup_version';

    /** Wird gesetzt, wenn wegen max_bytes Teile entfallen sind. */
    public const FIELD_TRUNCATED = '_truncated';

    public function __construct(
        private readonly Cleaner $cleaner,
        private readonly bool $includeRequestBody = true,
        private readonly bool $skipMultipart = true,
        private readonly int $maxBytes = 32768,
    ) {
    }

    /**
     * Der gesamte Austausch: Anfrage und Antwort in einem flachen Objekt.
     *
     * Formularparameter werden ausdrücklich MITGENOMMEN und redigiert, nicht ausgelassen.
     * Konzept 4.5.1 nennt „Login-Formulardaten" als Beispiel für das, was redigiert
     * gehört — nicht für das, was fehlen soll. Dass eine Anfrage ein Feld `password`
     * mitbrachte, ist bei der Auswertung eines Angriffsversuchs die entscheidende
     * Auskunft; sein Inhalt ist es nicht.
     *
     * Gelesen wird nur, was Symfony bereits geparst hat: `$request->request` ist bei
     * Formular-POSTs gefüllt. Der rohe Eingabestrom wird NICHT angefasst — er ist bei
     * JSON-Anfragen die Nutzlast, die die Anwendung noch braucht, und bei Uploads
     * beliebig groß.
     *
     * Flach und nicht verschachtelt, damit die Kappung unter `raw.max_bytes` einzelne
     * Teile gezielt abbauen kann.
     *
     * @return \Closure(): array<string, mixed>
     */
    public function forExchange(Request $request, Response $response): \Closure
    {
        return function () use ($request, $response): array {
            $raw = [
                self::FIELD_CLEANUP_VERSION => $this->cleaner->rulesVersion(),
                'request_headers' => $this->cleaner->cleanHeaders($request->headers->all()),
                // Set-Cookie steht in der Denylist: die Antwort auf einen erfolgreichen
                // Login trägt dort die neue Session-ID.
                'response_headers' => $this->cleaner->cleanHeaders($response->headers->all()),
            ];

            $query = $request->query->all();

            if ([] !== $query) {
                $raw['query'] = $this->cleaner->cleanParameters($query);
            }

            $parameters = $this->requestParameters($request);

            if ([] !== $parameters) {
                $raw['request_params'] = $this->cleaner->cleanParameters($parameters);
            }

            // Nur die NAMEN. Der Cookie-Header ist bereits über die Denylist redigiert;
            // die Namen einzeln zu übertragen ist der Kompromiss, mit dem sichtbar bleibt,
            // welche Sitzungs- und Tracking-Cookies eine Anfrage mitbrachte, ohne einen
            // einzigen Wert auszuschreiben. Ein Wert hier wäre exakt der
            // Session-Hijacking-Vektor, den Konzept 4.5.1 ausschließen will.
            $cookieNames = array_keys($request->cookies->all());

            if ([] !== $cookieNames) {
                $raw['cookie_names'] = \array_slice(array_map('strval', $cookieNames), 0, self::MAX_COOKIE_NAMES);
            }

            return $this->capped($raw, ['request_params', 'query', 'request_headers', 'response_headers']);
        };
    }

    /**
     * Der Formular-Body — nur, was Symfony ohnehin geparst hat.
     *
     * Zwei Schalter, weil der Body der sensibelste und der größte Teil ist:
     *
     *  - `include_request_body: false` lässt ihn ganz weg. Für Anwendungen, die
     *    Zahlungsdaten oder Gesundheitsdaten über Formulare annehmen und bei denen die
     *    Denylist als Schutz nicht ausreicht.
     *  - `skip_multipart: true` (Vorgabe) lässt Datei-Uploads weg. Bei multipart-Anfragen
     *    stünden in $request->request nur die Textfelder, aber die Anfrage kann hunderte
     *    Megabyte groß sein; der Aufwand für ihre Textfelder lohnt den Sonderfall nicht,
     *    und `content_length` im payload zeigt die Größe bereits.
     *
     * @return array<array-key, mixed>
     */
    private function requestParameters(Request $request): array
    {
        if (!$this->includeRequestBody) {
            return [];
        }

        if ($this->skipMultipart && str_contains((string) $request->headers->get('Content-Type', ''), 'multipart/')) {
            return [];
        }

        return $request->request->all();
    }

    /**
     * Die Aufrufkette.
     *
     * getTraceAsString() wird NICHT benutzt: es inlined die Aufrufargumente. Ein
     * `$user->setPassword('hunter2')` im Stack landete damit als Klartext im
     * Beweisspeicher — genau das, was Konzept 4.5.1 verhindern soll, und über keine
     * Denylist erreichbar, weil dort kein Feldname steht. Deshalb wird der Trace aus
     * getTrace() Rahmen für Rahmen aufgebaut und nur file/line/class/function übernommen.
     *
     * @return \Closure(): array<string, mixed>
     */
    public function forException(\Throwable $exception): \Closure
    {
        return function () use ($exception): array {
            $raw = [
                self::FIELD_CLEANUP_VERSION => $this->cleaner->rulesVersion(),
                'trace' => self::trace($exception),
                'exception_chain' => self::chain($exception),
            ];

            return $this->capped($raw, ['trace']);
        };
    }

    /**
     * Für Business-Events: der Payload der Anwendung, redigiert.
     *
     * Anders als bei den Kernel-Events ist der Inhalt hier vollständig anwendungsdefiniert
     * — die Anwendung kann in ihr Domain-Event legen, was sie will, einschließlich
     * Zugangsdaten. Deshalb läuft er durch dieselbe Denylist.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return \Closure(): array<string, mixed>
     */
    public function forBusiness(array $payload, ?string $invalidSeverityHint = null): \Closure
    {
        return function () use ($payload, $invalidSeverityHint): array {
            $raw = [
                self::FIELD_CLEANUP_VERSION => $this->cleaner->rulesVersion(),
                'payload' => $this->cleaner->cleanParameters($payload),
            ];

            // Konzept 2.2.1 stuft einen unbrauchbaren Hinweis auf warning ein; der
            // Originalwert gehört in die Daten, damit die Fehlkonfiguration sichtbar
            // bleibt und nicht nur im Log steht.
            if (null !== $invalidSeverityHint) {
                $raw['invalid_severity_hint'] = mb_substr($invalidSeverityHint, 0, 64);
            }

            return $this->capped($raw, ['payload']);
        };
    }

    /**
     * Hält raw unter `ids_sensor.raw.max_bytes`.
     *
     * WARUM DAS NÖTIG IST: raw ist das einzige Feld, dessen Größe die Gegenseite
     * bestimmt. Ein Angreifer kann 200 Formularfelder mit je 512 Zeichen senden oder eine
     * tief verschachtelte Exception-Kette auslösen. Ohne Obergrenze könnte er mit einer
     * einzigen Anfrage einen Frame erzeugen, der die Größengrenze des Spools oder des
     * Brokers reißt — und damit ALLE Events dieses Requests verwirft. Das wäre ein
     * gezielt auslösbarer blinder Fleck, also genau das Gegenteil eines IDS.
     *
     * Abgebaut wird in der Reihenfolge der übergebenen Schlüssel, also vom Verzichtbaren
     * zum Unverzichtbaren. Der Marker bleibt, damit bei der Nachanalyse nicht der Eindruck
     * von Vollständigkeit entsteht.
     *
     * @param array<string, mixed> $raw
     * @param list<string>         $dropOrder
     *
     * @return array<string, mixed>
     */
    private function capped(array $raw, array $dropOrder): array
    {
        if (0 === $this->maxBytes) {
            return $raw;
        }

        foreach ($dropOrder as $key) {
            if (self::size($raw) <= $this->maxBytes) {
                return $raw;
            }

            if (!\array_key_exists($key, $raw)) {
                continue;
            }

            unset($raw[$key]);
            $raw[self::FIELD_TRUNCATED] = true;
        }

        // Selbst nach dem Abbau zu groß: dann bleibt nur die Buchführung. Ein leeres raw
        // ist besser als ein verworfener Frame.
        if (self::size($raw) > $this->maxBytes) {
            return [
                self::FIELD_CLEANUP_VERSION => $this->cleaner->rulesVersion(),
                self::FIELD_TRUNCATED => true,
            ];
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function size(array $raw): int
    {
        $encoded = json_encode($raw, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return false === $encoded ? \PHP_INT_MAX : \strlen($encoded);
    }

    /**
     * @return list<array{file: string, line: int, class: string|null, function: string|null}>
     */
    private static function trace(\Throwable $exception): array
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            if (\count($frames) >= self::MAX_TRACE_FRAMES) {
                break;
            }

            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            $frames[] = [
                'file' => \is_string($frame['file'] ?? null) ? $frame['file'] : '[intern]',
                'line' => \is_int($frame['line'] ?? null) ? $frame['line'] : 0,
                'class' => \is_string($class) ? $class : null,
                'function' => \is_string($function) ? $function : null,
            ];
        }

        return $frames;
    }

    /**
     * Die Exception-Kette als Klassennamen mit Ort.
     *
     * Die MELDUNGEN bleiben draußen — auch die der inneren Exceptions. Eine
     * CustomUserMessageAuthenticationException oder eine Datenbank-Exception kann
     * Anwendungsdaten und Query-Parameter enthalten. Die äußerste Meldung steht bereits
     * im payload (Konzept 3.1.1) und ist damit eine bewusste, einzelne Ausnahme.
     *
     * @return list<array{class: string, file: string, line: int}>
     */
    private static function chain(\Throwable $exception): array
    {
        $chain = [];
        $current = $exception;

        while (\count($chain) < self::MAX_EXCEPTION_CHAIN) {
            $chain[] = [
                'class' => $current::class,
                'file' => $current->getFile(),
                'line' => $current->getLine(),
            ];

            $previous = $current->getPrevious();

            if (null === $previous) {
                break;
            }

            $current = $previous;
        }

        return $chain;
    }
}
