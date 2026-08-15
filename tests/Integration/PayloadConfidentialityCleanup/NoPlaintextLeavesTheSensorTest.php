<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration\PayloadConfidentialityCleanup;

use ProjektMotor\IdsSensor\Delivery\Transport\MessageSerializer;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool;
use ProjektMotor\IdsSensor\IdsSensorBundle;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestCleaner;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * DIE Abnahmeprüfung für Konzept 4.5.1.
 *
 * Der Test schickt einen einzigen Request, der jeden Eintrag der Redaktionsliste
 * mitbringt, und prüft anschließend auf BYTE-EBENE, dass kein einziger dieser Werte den
 * Sensor verlässt — und zwar auf BEIDEN Wegen:
 *
 *  1. auf der Leitung, also im JSON, das der Messenger-Serializer an Redis gibt;
 *  2. im Spool, also in der Datei auf der Platte des Anwendungshosts.
 *
 * Beide Wege getrennt zu prüfen ist keine Doppelung. Der Spool ist der Weg, den man
 * vergisst: er ist eine gewöhnliche Datei neben der Anwendung, niemand behandelt ihn als
 * Beweisspeicher, und unter mod_php läuft JEDER Frame darüber (Plan: Laufzeitmodelle).
 * Eine Redaktion, die nur den Broker-Pfad abdeckt, hätte dort eine Klartextkopie
 * hinterlassen.
 *
 * Geprüft wird auf dem Rohstring und nicht auf dekodierten Feldern: nur so fällt ein Wert
 * auch dann auf, wenn er an einer Stelle landet, an die niemand gedacht hat.
 */
final class NoPlaintextLeavesTheSensorTest extends IntegrationTestCase
{
    /**
     * Werte, die NIRGENDS auftauchen dürfen.
     *
     * Jeder ist eindeutig UND lang. Beides ist nötig: eindeutig, damit ein Treffer
     * benennbar ist — und lang, weil die Suche auf dem Rohstring läuft. Ein kurzer Wert
     * kollidiert dort zufällig mit den Hex-Zeichen von event_id, process_epoch oder den
     * Hashes. Ein dreistelliges „737" als CVV hat den Test genau so zum Fehlschlagen
     * gebracht, ohne dass etwas durchgekommen war. Für das Geprüfte ist die Länge
     * bedeutungslos: der Wert wird vollständig ersetzt, nicht gekürzt.
     * {@see testTheSearchTermsAreCollisionSafe()} hält die Regel fest.
     */
    private const SECRETS = [
        'cookie-header' => 'sess-4f8a2b1c9d0e',
        'cookie-remember' => 'remember-7c1e5f',
        'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.geheim',
        'proxy-authorization' => 'Basic cHJveHk6Z2VoZWlt',
        'x-api-key' => 'ak_live_9f3c7b21',
        'x-auth-token' => 'auth_token_44d1e8_geheim',
        'x-csrf-token' => 'csrf_token_bb27f0_geheim',
        'query-password' => 'hunter2-in-query',
        'query-reset-token' => 'reset_token_e91c4a_geheim',
        'query-apikey' => 'api_key_query_5522_geheim',
        'body-password' => 'hunter2-im-body',
        'body-nested-password' => 'hunter2-verschachtelt',
        'body-token' => 'formtoken_71ba',
        'body-credit-card' => '4111111111111111',
        'body-cvv' => 'cvv-wert-737-nicht-uebertragen',
        'body-iban' => 'DE89370400440532013000',
        'body-secret' => 'client_secret_1a2b3c_geheim',
        // Der Referer ist eine FREMDE vollständige URL samt Query. Er lief bis zuletzt
        // nur durch eine Kürzung, nicht durch die Denylist — und payload.referer reist
        // laut Konzept 3.1.1 bei JEDER Stufe mit, also auch bei info.
        'referer-token' => 'reset_token_referer_3e7d_geheim',
        // Ein sensibler Teil des Schlüsselnamens JENSEITS von Zeichen 64: Der
        // QueryNormalizer kürzte den Schlüssel auf 64 Zeichen und gab den GEKÜRZTEN an
        // die Denylist, die per str_contains sucht — `token` lag dahinter und wurde nicht
        // gefunden. Im raw-Pfad ging der volle Schlüssel an den Cleaner: zwei Ergebnisse
        // für dieselben Daten.
        'query-langer-schluessel' => 'wert_hinter_langem_schluessel_9c4e_geheim',
        // `payload.exception_message` lief an der Denylist vorbei — sanitizeMessage()
        // strich nur Steuerzeichen und kürzte. Die Meldung ist angreiferbeeinflusst und
        // trägt oft die angefragte URI samt Query; das Feld reist laut Konzept 3.1.1 bei
        // JEDER Stufe mit, nicht nur bei warning/critical wie raw.
        'exception-message-token' => 'exception_msg_token_6b0d_geheim',
    ];

    /** Diese Werte MÜSSEN durchkommen — sonst redigiert der Test alles und beweist nichts. */
    private const HARMLESS = [
        'query-plain' => 'sichtbar-in-query',
        'body-plain' => 'sichtbar-im-body',
    ];

    /**
     * Die Untergrenze, ab der ein Suchbegriff nicht mehr zufällig in einem Hex-Wert
     * auftaucht. 12 Zeichen reichen dafür mit großem Abstand.
     */
    private const MIN_SECRET_LENGTH = 12;

    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir().'/ids-cleanup-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->spoolDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->spoolDir);
    }

    /**
     * Schützt die übrigen Tests dieser Klasse vor Zufallstreffern.
     *
     * Ein Test, der gelegentlich rot ist, wird irgendwann mit `--exclude` versehen — und
     * dann prüft niemand mehr, ob Zugangsdaten den Sensor verlassen.
     */
    public function testTheSearchTermsAreCollisionSafe(): void
    {
        foreach (self::SECRETS as $bezeichnung => $wert) {
            self::assertGreaterThanOrEqual(
                self::MIN_SECRET_LENGTH,
                \strlen($wert),
                \sprintf(
                    'Der Suchbegriff "%s" ist zu kurz und könnte zufällig in einer UUID oder einem '
                    .'Hash auftauchen. Die Länge ist für das Geprüfte bedeutungslos — der Wert wird '
                    .'vollständig ersetzt.',
                    $bezeichnung,
                ),
            );
        }
    }

    public function testNoSensitiveValueReachesTheWire(): void
    {
        $body = $this->wireBody('wire');

        $this->assertNoSecretsIn($body, 'auf der Leitung');
    }

    public function testNoSensitiveValueReachesTheSpool(): void
    {
        $content = $this->spoolContent('spool');

        $this->assertNoSecretsIn($content, 'im Spool');
    }

    /**
     * Die Gegenprobe. Ohne sie könnte der Test grün sein, weil raw gar nicht gebaut wird
     * oder weil alles redigiert ist — beides wäre kein Beweis für eine funktionierende
     * Denylist, sondern für eine kaputte Erfassung.
     */
    public function testFieldNamesArePreservedAndHarmlessValuesPassThrough(): void
    {
        $body = $this->wireBody('gegenprobe');

        // Konzept 4.5.1: „Werte werden durch [confidential] ersetzt, Feldnamen bleiben
        // erhalten." Dass eine Anfrage ein Feld `password` mitbrachte, ist die
        // forensisch entscheidende Auskunft.
        foreach (['password', 'credit_card', 'iban', 'cvv', 'authorization', 'x-api-key'] as $feldname) {
            self::assertStringContainsString(
                $feldname,
                $body,
                \sprintf('Der Feldname "%s" muss erhalten bleiben — nur sein Wert wird ersetzt', $feldname),
            );
        }

        foreach (self::HARMLESS as $bezeichnung => $wert) {
            self::assertStringContainsString(
                $wert,
                $body,
                \sprintf('Der harmlose Wert "%s" darf nicht redigiert werden', $bezeichnung),
            );
        }

        self::assertStringContainsString(Cleaner::DEFAULT_PLACEHOLDER, $body, 'Es muss überhaupt redigiert worden sein');
    }

    /**
     * Cookie-NAMEN sind erwünscht, Cookie-WERTE nicht.
     *
     * Der Name zeigt, welche Sitzungs- und Tracking-Cookies eine Anfrage mitbrachte —
     * bei einem Angriffsversuch eine brauchbare Spur. Der Wert wäre der
     * Session-Hijacking-Vektor, den Konzept 4.5.1 ausschließt.
     */
    public function testCookieNamesTravelAlongButNoCookieValues(): void
    {
        $body = $this->wireBody('cookies');

        self::assertStringContainsString('PHPSESSID', $body);
        self::assertStringContainsString('REMEMBERME', $body);
        self::assertStringNotContainsString(self::SECRETS['cookie-header'], $body);
        self::assertStringNotContainsString(self::SECRETS['cookie-remember'], $body);
    }

    /**
     * Die rohe Session-ID darf nirgends stehen — die eigentliche Zusage aus Konzept
     * 2.2.4, Bildung der Sitzungskontext-Felder. Über ein unredigiertes raw wäre die
     * Ereignisdatenbank trotz Hashen wieder ein Hijacking-Vektor; genau diesen
     * Widerspruch löst 4.5.1 auf.
     */
    public function testTheRawSessionIdAppearsNowhere(): void
    {
        $body = $this->wireBody('session');
        $spool = $this->spoolContent('session-spool');

        foreach (['auf der Leitung' => $body, 'im Spool' => $spool] as $ort => $inhalt) {
            self::assertStringNotContainsString(
                self::SECRETS['cookie-header'],
                $inhalt,
                \sprintf('Die rohe Session-ID steht %s', $ort),
            );
        }
    }

    /**
     * Die Fassung der Liste reist mit. Ohne sie ließe sich nach einer Erweiterung nicht
     * mehr feststellen, ob ein fehlender Wert redigiert oder nie vorhanden war.
     */
    public function testTheRulesVersionTravelsAlong(): void
    {
        $body = $this->wireBody('version');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $events */
        $events = $decoded['events'];
        $withRaw = array_values(array_filter($events, static fn (array $e): bool => isset($e['raw'])));

        self::assertNotSame([], $withRaw, 'Bei warning/critical muss raw vorhanden sein');

        foreach ($withRaw as $event) {
            /** @var array<string, mixed> $raw */
            $raw = $event['raw'];
            self::assertSame(TestCleaner::rules()->version, $raw['cleanup_version']);
        }
    }

    /**
     * Konzept Abschnitt 3: raw nur bei warning und critical. Das info-Event desselben
     * Requests darf es nicht tragen — sonst wäre das Volumenbudget aus 4.2.3 sinnlos.
     */
    public function testInfoEventsCarryNoRaw(): void
    {
        $body = $this->wireBody('nur-warning');
        /** @var array{events: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        foreach ($decoded['events'] as $event) {
            if ('info' === $event['event_severity']) {
                self::assertArrayNotHasKey(
                    'raw',
                    $event,
                    \sprintf('Das info-Event "%s" trägt raw', (string) $event['event_type']),
                );
            }
        }
    }

    /**
     * getTraceAsString() inlined die Aufrufargumente — ein Passwort im Stack landete
     * damit im Klartext im Beweisspeicher, und zwar an einer Stelle, die keine Denylist
     * erreicht, weil dort kein Feldname steht.
     */
    public function testTheTraceContainsNoCallArguments(): void
    {
        $body = $this->wireBody('trace');
        /** @var array{events: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $exception = null;

        foreach ($decoded['events'] as $event) {
            if ('kernel.exception' === $event['event_type']) {
                $exception = $event;
            }
        }

        self::assertNotNull($exception, 'Der Request muss ein kernel.exception erzeugt haben');
        /** @var array{trace: list<array<string, mixed>>} $raw */
        $raw = $exception['raw'];

        self::assertNotSame([], $raw['trace']);

        foreach ($raw['trace'] as $frame) {
            self::assertSame(
                ['file', 'line', 'class', 'function'],
                array_keys($frame),
                'Ein Trace-Rahmen darf nur Ort und Aufrufname enthalten, niemals args',
            );
        }
    }

    /**
     * Ein Request, der alles mitbringt.
     *
     * Die Route ist `/geschuetzt`, weil sie eine AccessDeniedException wirft: damit ist
     * der Request warning-behaftet, raw wird gebaut und es entstehen alle drei
     * Kernel-Events. Auf einer 200er-Route bliebe raw leer und der Test bewiese nichts.
     */
    private function loadedRequest(): Request
    {
        $query = [
            'password' => self::SECRETS['query-password'],
            'reset_token' => self::SECRETS['query-reset-token'],
            'apikey' => self::SECRETS['query-apikey'],
            'msg_token' => self::SECRETS['exception-message-token'],
            str_repeat('x', 70).'_token' => self::SECRETS['query-langer-schluessel'],
            'harmless' => self::HARMLESS['query-plain'],
        ];

        $body = [
            'password' => self::SECRETS['body-password'],
            'user' => ['password' => self::SECRETS['body-nested-password']],
            '_token' => self::SECRETS['body-token'],
            'credit_card' => self::SECRETS['body-credit-card'],
            'cvv' => self::SECRETS['body-cvv'],
            'iban' => self::SECRETS['body-iban'],
            'client_secret' => self::SECRETS['body-secret'],
            'kommentar' => self::HARMLESS['body-plain'],
        ];

        $request = Request::create('/geschuetzt?'.http_build_query($query), 'POST', $body);

        $request->headers->set('Authorization', self::SECRETS['authorization']);
        $request->headers->set('Proxy-Authorization', self::SECRETS['proxy-authorization']);
        $request->headers->set('X-API-Key', self::SECRETS['x-api-key']);
        $request->headers->set('X-Auth-Token', self::SECRETS['x-auth-token']);
        $request->headers->set('X-CSRF-Token', self::SECRETS['x-csrf-token']);
        $request->headers->set(
            'Referer',
            'https://app.example/passwort-neu?reset_token='.self::SECRETS['referer-token'],
        );
        $request->cookies->set('PHPSESSID', self::SECRETS['cookie-header']);
        $request->cookies->set('REMEMBERME', self::SECRETS['cookie-remember']);
        // Der Cookie-HEADER getrennt gesetzt: Request::create leitet ihn nicht aus
        // $cookies ab, und er ist der Eintrag, der in der Denylist steht.
        $request->headers->set(
            'Cookie',
            'PHPSESSID='.self::SECRETS['cookie-header'].'; REMEMBERME='.self::SECRETS['cookie-remember'],
        );

        return $request;
    }

    private function wireBody(string $variant): string
    {
        $kernel = $this->boot($variant, 'in-memory://');
        $services = $this->services($kernel);

        $request = $this->loadedRequest();
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate($request, $response);

        $batches = $this->batches($services);

        self::assertCount(1, $batches, 'Ein Request ergibt genau einen Frame');

        $message = $batches[0];
        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);

        return $serializer->encode(new Envelope($message))['body'];
    }

    private function spoolContent(string $variant): string
    {
        // Ein Port, auf dem nichts lauscht: der Frame landet zwangsläufig im Spool.
        $kernel = $this->boot($variant, 'redis://127.0.0.1:6391/ids:cleanup/group/consumer');
        $services = $this->services($kernel);

        $request = $this->loadedRequest();
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $kernel->terminate($request, $response);

        /** @var FileSpool $spool */
        $spool = $services->get('ids_sensor.spool');
        $files = $spool->waitingFiles();

        self::assertNotSame([], $files, 'Ohne erreichbaren Broker muss gespoolt werden');

        $content = '';

        foreach ($files as $file) {
            $content .= (string) file_get_contents($file);
        }

        self::assertNotSame('', $content);

        return $content;
    }

    private function assertNoSecretsIn(string $haystack, string $ort): void
    {
        foreach (self::SECRETS as $bezeichnung => $wert) {
            self::assertStringNotContainsString(
                $wert,
                $haystack,
                \sprintf('Der Wert "%s" steht im Klartext %s', $bezeichnung, $ort),
            );
        }
    }

    private function boot(string $variant, string $dsn): TestKernel
    {
        $kernel = new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
            'transport' => ['dsn' => $dsn, 'options' => ['timeout' => 0.05, 'read_timeout' => 0.05]],
            'spool' => ['dir' => $this->spoolDir],
            // Kein Budget-Deckel: geprüft wird die Redaktion, nicht die Latenz.
            'budget' => ['capture_us' => 0],
        ], 'cleanup-'.$variant);
        $kernel->boot();

        return $kernel;
    }
}
