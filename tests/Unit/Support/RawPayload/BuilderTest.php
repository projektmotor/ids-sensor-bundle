<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\RawPayload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Aufbau des `raw`-Feldes.
 *
 * 349 Zeilen, die entscheiden, was bei einem Angriffsversuch in den Beweisspeicher
 * gelangt — und was auf keinen Fall. Geprüft war davon bislang nur, was der
 * Klartext-Test nebenbei berührt; die Schalter, die Kappung und die Obergrenzen
 * standen ungeprüft da.
 */
#[CoversClass(Builder::class)]
final class BuilderTest extends TestCase
{
    public function testTheExchangeCarriesHeadersQueryBodyAndCookieNames(): void
    {
        $raw = ($this->builder()->forExchange($this->beladenerRequest(), new Response()))();

        self::assertSame(TestCleaner::rules()->version, $raw[Builder::FIELD_CLEANUP_VERSION]);
        self::assertArrayHasKey('request_headers', $raw);
        self::assertArrayHasKey('response_headers', $raw);
        self::assertSame(['suche' => 'schuhe'], $raw['query']);
        self::assertSame(['kommentar' => 'harmlos'], $raw['request_params']);
        self::assertSame(['PHPSESSID'], $raw['cookie_names']);
    }

    /**
     * Nur die NAMEN der Cookies — ein Wert hier wäre exakt der
     * Session-Hijacking-Vektor, den Konzept 4.5.1 ausschließen will.
     */
    public function testCookieValuesNeverAppear(): void
    {
        $request = $this->beladenerRequest();
        $request->cookies->set('PHPSESSID', 'geheimer-sitzungswert-4711');

        $raw = ($this->builder()->forExchange($request, new Response()))();

        self::assertStringNotContainsString('geheimer-sitzungswert-4711', json_encode($raw, \JSON_THROW_ON_ERROR));
    }

    public function testAtMostThirtyCookieNamesTravel(): void
    {
        $request = Request::create('/');

        for ($i = 0; $i < Builder::MAX_COOKIE_NAMES + 10; ++$i) {
            $request->cookies->set('keks'.$i, 'wert');
        }

        $raw = ($this->builder()->forExchange($request, new Response()))();

        self::assertCount(Builder::MAX_COOKIE_NAMES, $raw['cookie_names']);
    }

    /**
     * Für Anwendungen, denen die Denylist als Schutz nicht reicht — Zahlungs- oder
     * Gesundheitsdaten über Formulare.
     */
    public function testIncludeRequestBodyFalseOmitsTheBodyEntirely(): void
    {
        $builder = new Builder(TestCleaner::default(), includeRequestBody: false);

        $raw = ($builder->forExchange($this->beladenerRequest(), new Response()))();

        self::assertArrayNotHasKey('request_params', $raw);
        self::assertArrayHasKey('query', $raw, 'Die Query ist nicht der Body und bleibt');
    }

    /**
     * Ein Upload kann hunderte Megabyte groß sein; `content_length` im payload zeigt die
     * Größe bereits.
     */
    public function testMultipartRequestsSkipTheBody(): void
    {
        $request = $this->beladenerRequest();
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=xyz');

        $raw = ($this->builder()->forExchange($request, new Response()))();

        self::assertArrayNotHasKey('request_params', $raw);
    }

    public function testMultipartCanBeIncludedOnDemand(): void
    {
        $request = $this->beladenerRequest();
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=xyz');

        $builder = new Builder(TestCleaner::default(), skipMultipart: false);

        self::assertArrayHasKey('request_params', ($builder->forExchange($request, new Response()))());
    }

    /**
     * `getTraceAsString()` inlined die Aufrufargumente — ein
     * `$user->setPassword('hunter2')` im Stack landete damit als Klartext im
     * Beweisspeicher, und zwar über keine Denylist erreichbar, weil dort kein Feldname
     * steht.
     */
    public function testTheTraceCarriesNoCallArguments(): void
    {
        $raw = ($this->builder()->forException($this->exceptionMitGeheimnis()))();

        self::assertStringNotContainsString('hunter2', json_encode($raw, \JSON_THROW_ON_ERROR));

        foreach ($raw['trace'] as $rahmen) {
            self::assertSame(['file', 'line', 'class', 'function'], array_keys($rahmen));
        }
    }

    /**
     * Die MELDUNGEN der inneren Exceptions bleiben draußen — eine
     * Datenbank-Exception kann Anwendungsdaten und Query-Parameter enthalten.
     */
    public function testTheExceptionChainCarriesClassesButNoMessages(): void
    {
        $innen = new \RuntimeException('SELECT * FROM nutzer WHERE token = geheim4711');
        $raw = ($this->builder()->forException(new \LogicException('außen', 0, $innen)))();

        self::assertStringNotContainsString('geheim4711', json_encode($raw, \JSON_THROW_ON_ERROR));
        self::assertSame(\LogicException::class, $raw['exception_chain'][0]['class']);
        self::assertSame(\RuntimeException::class, $raw['exception_chain'][1]['class']);
    }

    public function testTheExceptionChainIsBounded(): void
    {
        $exception = new \RuntimeException('tiefste');

        for ($i = 0; $i < Builder::MAX_EXCEPTION_CHAIN + 5; ++$i) {
            $exception = new \RuntimeException('ebene'.$i, 0, $exception);
        }

        $raw = ($this->builder()->forException($exception))();

        self::assertCount(Builder::MAX_EXCEPTION_CHAIN, $raw['exception_chain']);
    }

    /**
     * Ein unbrauchbarer Severity-Hinweis gehört in die DATEN, nicht nur ins Log.
     *
     * Konzept 2.2.1 stuft ihn auf `warning` ein; im Log ginge die Fehlkonfiguration
     * unter, im Frame steht sie neben dem Event, das sie betrifft.
     */
    public function testAnInvalidSeverityHintIsKeptInTheData(): void
    {
        $raw = ($this->builder()->forBusiness(['bestellung' => 42], 'kritisch!'))();

        self::assertSame('kritisch!', $raw['invalid_severity_hint']);
        self::assertSame(['bestellung' => 42], $raw['payload']);
    }

    /**
     * Die Kappung baut vom Verzichtbaren zum Unverzichtbaren ab — und hinterlässt einen
     * Vermerk, damit bei der Nachanalyse nicht der Eindruck von Vollständigkeit entsteht.
     */
    public function testOversizedRawIsCappedWithAMarker(): void
    {
        $builder = new Builder(TestCleaner::default(), maxBytes: 256);

        $raw = ($builder->forBusiness(['gross' => str_repeat('x', 5000)]))();

        self::assertTrue($raw[Builder::FIELD_TRUNCATED]);
        self::assertArrayNotHasKey('payload', $raw);
        self::assertSame(TestCleaner::rules()->version, $raw[Builder::FIELD_CLEANUP_VERSION], 'Die Buchführung bleibt in jedem Fall');
    }

    /**
     * `max_bytes: 0` heißt „keine Kappung" — dieselbe Null-Semantik wie bei
     * `capture_us`, dokumentiert in `doc/08`.
     */
    public function testMaxBytesZeroMeansNoCapping(): void
    {
        $builder = new Builder(TestCleaner::default(), maxBytes: 0);

        $raw = ($builder->forBusiness(['gross' => str_repeat('x', 5000)]))();

        self::assertArrayHasKey('payload', $raw);
        self::assertArrayNotHasKey(Builder::FIELD_TRUNCATED, $raw);
    }

    /**
     * Symfonys Debug-Header trägt die Exception-MELDUNG in die Antwort — URL-kodiert.
     *
     * `raw.response_headers` kopierte sie damit im Klartext, obwohl dieselbe Meldung in
     * `payload.exception_message` durch die Denylist läuft: Ein `?password=` im
     * angefragten Pfad stand im Payload redigiert und im raw-Feld lesbar. Aufgefallen
     * ist das unter Symfony 6.4 — die untere Grenze der eigenen Abhängigkeiten, die
     * `--prefer-lowest` prüft und die `--testsuite unit,integration` allein nicht sieht.
     *
     * Forensisch ist der Header wertlos: Die Meldung steht bereits im Payload.
     */
    public function testTheDebugExceptionHeaderIsRedacted(): void
    {
        $response = new Response();
        $response->headers->set('X-Debug-Exception', rawurlencode('kein Zugriff auf /x?password=hunter2-geheim'));

        $raw = ($this->builder()->forExchange($this->beladenerRequest(), $response))();

        self::assertStringNotContainsString('hunter2-geheim', json_encode($raw, \JSON_THROW_ON_ERROR));
        self::assertSame('[confidential]', $raw['response_headers']['x-debug-exception']);
    }

    /**
     * Der JSON-Körper kommt redigiert an — die Auflösung des Widerspruchs aus Konzept M4.
     *
     * Konzept 3.5 las „ausschließlich, was das Framework bereits geparst hat", und Symfony
     * parst nur formularkodierte Körper. Für Szenario S5 — Deserialisierung über
     * API-Payloads — war `raw` damit immer leer, obwohl das Konzept dort zusagt, der
     * Payload sei „vollständig verfügbar". Ein leeres Feld war von „kein Körper" nicht zu
     * unterscheiden.
     */
    public function testAJsonBodyIsCapturedAndRedacted(): void
    {
        $raw = ($this->builder()->forExchange($this->jsonRequest([
            'kommentar' => 'harmlos',
            'password' => 'hunter2',
        ]), new Response()))();

        self::assertSame('harmlos', $raw[Builder::FIELD_REQUEST_BODY]['kommentar']);
        self::assertSame('[confidential]', $raw[Builder::FIELD_REQUEST_BODY]['password']);
        self::assertStringNotContainsString('hunter2', json_encode($raw, \JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY_OMITTED, $raw);
    }

    /**
     * `application/merge-patch+json` und `application/ld+json` sind JSON.
     *
     * `Request::getContentTypeFormat()` vergleicht exakte Typen und kennt beide nicht —
     * genau die Endpunkte von API Platform und JSON-Patch, an denen S5 stattfindet, wären
     * damit durchgefallen.
     */
    public function testJsonSuffixMediaTypesCount(): void
    {
        $request = $this->jsonRequest(['feld' => 'wert'], 'application/merge-patch+json');

        self::assertSame(['feld' => 'wert'], ($this->builder()->forExchange($request, new Response()))()[Builder::FIELD_REQUEST_BODY]);
    }

    /**
     * Ein Formular steht in `request_params` — und wird NICHT als ausgelassen vermerkt.
     *
     * Ein „omitted" neben dem vorhandenen Inhalt wäre schlicht falsch und träfe den
     * häufigsten Fall überhaupt.
     */
    public function testAFormBodyStaysInRequestParamsWithoutAnOmissionMarker(): void
    {
        $raw = ($this->builder()->forExchange($this->beladenerRequest(), new Response()))();

        self::assertSame(['kommentar' => 'harmlos'], $raw['request_params']);
        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY, $raw);
        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY_OMITTED, $raw);
    }

    /**
     * Ohne Körper gibt es nichts zu melden — sonst stünde der Vermerk an jedem GET.
     */
    public function testARequestWithoutABodyCarriesNoMarker(): void
    {
        $raw = ($this->builder()->forExchange(Request::create('/ok'), new Response()))();

        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY_OMITTED, $raw);
    }

    /**
     * Jede Ablehnung nennt ihren Grund. Ein fehlendes Feld wäre von „kein Körper" nicht zu
     * unterscheiden, und bei einem Deserialisierungsversuch ist das der Unterschied
     * zwischen „die Anfrage war leer" und „wir haben weggesehen".
     *
     * @param \Closure(): Request $request
     */
    #[DataProvider('abgelehnteKoerper')]
    public function testARefusedBodyNamesItsReason(\Closure $request, string $grund, ?Builder $builder = null): void
    {
        $raw = (($builder ?? $this->builder())->forExchange($request(), new Response()))();

        self::assertSame($grund, $raw[Builder::FIELD_REQUEST_BODY_OMITTED] ?? null);
        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY, $raw);
    }

    /**
     * @return iterable<string, array{0: \Closure(): Request, 1: string, 2?: Builder}>
     */
    public static function abgelehnteKoerper(): iterable
    {
        yield 'kein JSON' => [
            static fn (): Request => self::rohkoerper('<xml/>', 'application/xml'),
            Builder::OMITTED_NOT_JSON,
        ];

        yield 'chunked, Länge unbekannt' => [
            static function (): Request {
                $request = self::rohkoerper('{"a":1}', 'application/json');
                $request->headers->remove('Content-Length');
                $request->headers->set('Transfer-Encoding', 'chunked');

                return $request;
            },
            Builder::OMITTED_UNKNOWN_LENGTH,
        ];

        yield 'zu groß' => [
            static fn (): Request => self::rohkoerper(
                (string) json_encode(['gross' => str_repeat('x', 4000)]),
                'application/json',
            ),
            Builder::OMITTED_TOO_LARGE,
            new Builder(TestCleaner::default(), maxRequestBodyBytes: 512),
        ];

        yield 'kaputtes JSON' => [
            static fn (): Request => self::rohkoerper('{"a":', 'application/json'),
            Builder::OMITTED_UNDECODABLE,
        ];
    }

    /**
     * Ein nicht dekodierbarer Körper darf NICHT als Text mitkommen.
     *
     * Die Redaktion aus Konzept 4.5.1 ist eine Denylist über Feldnamen. Ohne Struktur gibt
     * es keine Feldnamen — ein roher Textkörper wäre der eine Eintrittspunkt, an dem die
     * Liste nichts ausrichtet.
     */
    public function testAnUndecodableBodyLeavesNoTextBehind(): void
    {
        $request = self::rohkoerper('{"password": "hunter2-geheim", kaputt', 'application/json');

        $raw = ($this->builder()->forExchange($request, new Response()))();

        self::assertStringNotContainsString('hunter2-geheim', json_encode($raw, \JSON_THROW_ON_ERROR));
        self::assertSame(Builder::OMITTED_UNDECODABLE, $raw[Builder::FIELD_REQUEST_BODY_OMITTED]);
    }

    public function testIncludeRequestBodyFalseAlsoDropsTheJsonBody(): void
    {
        $builder = new Builder(TestCleaner::default(), includeRequestBody: false);

        $raw = ($builder->forExchange($this->jsonRequest(['feld' => 'wert']), new Response()))();

        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY, $raw);
        self::assertSame(Builder::OMITTED_DISABLED, $raw[Builder::FIELD_REQUEST_BODY_OMITTED]);
    }

    /**
     * Die Wechselwirkung der beiden Grenzen, festgehalten statt erklärt.
     *
     * `max_request_body_bytes` lässt den Körper herein, `max_bytes` wirft ihn wieder
     * hinaus — er steht als erstes in der Abbaureihenfolge. Bei gleichen Vorgaben (beide
     * 32768) trifft das Körper nahe der Grenze. Das ist die dokumentierte Folge und keine
     * Fehlfunktion; `ids:sensor:setup-check` meldet nur den Fall, in dem die Körpergrenze
     * ECHT größer ist.
     */
    public function testABodyWithinItsOwnLimitCanStillLoseToTheRawCap(): void
    {
        $builder = new Builder(TestCleaner::default(), maxBytes: 256, maxRequestBodyBytes: 4096);

        $raw = ($builder->forExchange(
            self::rohkoerper((string) json_encode(['gross' => str_repeat('x', 1000)]), 'application/json'),
            new Response(),
        ))();

        self::assertArrayNotHasKey(Builder::FIELD_REQUEST_BODY, $raw);
        self::assertTrue($raw[Builder::FIELD_TRUNCATED]);
    }

    private function builder(): Builder
    {
        return new Builder(TestCleaner::default());
    }

    private function beladenerRequest(): Request
    {
        $request = Request::create('/suche?suche=schuhe', 'POST', ['kommentar' => 'harmlos']);
        $request->cookies->set('PHPSESSID', 'sitzungswert');

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body, string $contentType = 'application/json'): Request
    {
        return self::rohkoerper((string) json_encode($body, \JSON_THROW_ON_ERROR), $contentType);
    }

    /**
     * Ein Request mit rohem Körper — so, wie eine API-Anfrage tatsächlich ankommt.
     *
     * `Request::create()` mit `$content` lässt `$request->request` LEER, genau wie Symfony
     * es im Betrieb tut: geparst wird nur formularkodiert. Das ist der Zustand, in dem
     * `raw.request_params` bis hierher immer leer blieb.
     */
    private static function rohkoerper(string $content, string $contentType): Request
    {
        $request = Request::create('/api/orders', 'POST', [], [], [], [], $content);
        $request->headers->set('Content-Type', $contentType);
        $request->headers->set('Content-Length', (string) \strlen($content));

        return $request;
    }

    /**
     * Eine Exception, deren Aufrufkette ein Geheimnis als ARGUMENT trägt.
     */
    private function exceptionMitGeheimnis(): \Throwable
    {
        try {
            (static function (string $passwort): never {
                throw new \RuntimeException('gescheitert');
            })('hunter2');
        } catch (\Throwable $exception) {
            return $exception;
        }
    }
}
