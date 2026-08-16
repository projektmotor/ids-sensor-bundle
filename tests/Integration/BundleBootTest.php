<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Processing\Normalization\EventFactory;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class BundleBootTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const MINIMAL_CONFIG = [
        'application_id' => 'shop-api',
        'environment' => 'prod',
        'session_hash' => ['key' => self::SESSION_KEY],
    ];

    public function testContainerCompilesWithMinimalConfiguration(): void
    {
        $kernel = $this->boot(self::MINIMAL_CONFIG, 'minimal');

        self::assertTrue($kernel->getContainer()->getParameter('ids_sensor.enabled'));
        self::assertSame('shop-api', $kernel->getContainer()->getParameter('ids_sensor.application_id'));
        self::assertSame(
            EventSchema::SCHEMA_VERSION,
            $kernel->getContainer()->getParameter('ids_sensor.schema_version'),
        );
    }

    /**
     * Die Innereien dürfen nicht zur API werden: außer den Contract- und
     * Schema-Klassen bietet das Bundle bewusst keine öffentlichen Service-IDs an.
     * Im regulären Container ist deshalb nichts davon erreichbar — nur über den
     * Test-Container.
     */
    public function testInternalServicesAreNotPublic(): void
    {
        // Ohne den Test-Expose-Pass — sonst prüfte der Test seine eigene Krücke.
        $kernel = new TestKernel(self::MINIMAL_CONFIG, 'privacy', exposeServices: false);
        $kernel->boot();

        self::assertFalse($kernel->getContainer()->has('ids_sensor.event_factory'));
        self::assertFalse($kernel->getContainer()->has('ids_sensor.identity_provider'));
        self::assertFalse($kernel->getContainer()->has('ids_sensor.event_buffer'));
    }

    public function testIdentityIsAssembledAtRuntime(): void
    {
        $identity = $this->identityFrom(self::MINIMAL_CONFIG, 'minimal');

        self::assertSame('shop-api', $identity->applicationId);
        self::assertSame(Environment::Prod, $identity->environment);
        self::assertNotSame('', $identity->instanceId, 'instance_id fällt auf den Hostnamen zurück');
        self::assertSame([], $identity->validate(), 'Die ermittelte Kennung muss dem erlaubten Muster entsprechen');
    }

    /**
     * "test" ist eine gültige Symfony-Umgebung, aber kein gültiger Wert für das
     * collectorseitige ENUM env_type. Ohne Abbildung würde der Insert scheitern
     * und alle Events dieser Instanz still verlieren — von einem toten Sensor
     * nicht unterscheidbar.
     */
    public function testAnySymfonyEnvironmentIsMappedOntoTheEnum(): void
    {
        $identity = $this->identityFrom(
            array_merge(self::MINIMAL_CONFIG, ['environment' => 'test']),
            'env-test',
        );

        self::assertSame(Environment::Dev, $identity->environment);
    }

    public function testAnUnknownEnvironmentFallsBackToProd(): void
    {
        $identity = $this->identityFrom(
            array_merge(self::MINIMAL_CONFIG, ['environment' => 'prod_eu_west']),
            'env-unknown',
        );

        // prod, nicht dev: fälschlich als prod markierter Verkehr wird weiterhin
        // erkannt, nur seine Baseline ist leicht verunreinigt. Fälschlich als dev
        // markierter Produktionsverkehr fällt dagegen aus JEDER Aggregation der
        // Produktionsregeln heraus und erzeugt einen vollständigen blinden Fleck.
        self::assertSame(Environment::Prod, $identity->environment);
    }

    public function testACustomEnvironmentMapIsMergedOverTheDefaults(): void
    {
        $identity = $this->identityFrom(
            array_merge(self::MINIMAL_CONFIG, [
                'environment' => 'abnahme',
                'environment_map' => ['abnahme' => 'staging'],
            ]),
            'env-custom',
        );

        self::assertSame(Environment::Staging, $identity->environment);
        // Die Vorgaben bleiben daneben bestehen, werden also nicht ersetzt.
        self::assertSame(
            Environment::Dev,
            $this->identityFrom(
                array_merge(self::MINIMAL_CONFIG, [
                    'environment' => 'test',
                    'environment_map' => ['abnahme' => 'staging'],
                ]),
                'env-custom-2',
            )->environment,
        );
    }

    /**
     * Der Durchstich: verdrahtete Factory, echte Identität, serialisiertes Event
     * mit allen Pflichtfeldern aus Konzept Abschnitt 3.
     */
    public function testTheWiredFactoryProducesACompleteEvent(): void
    {
        $kernel = $this->boot(self::MINIMAL_CONFIG, 'minimal');

        /** @var EventFactory $factory */
        $factory = $this->services($kernel)->get('ids_sensor.event_factory');
        /** @var SensorIdentityProvider $identityProvider */
        $identityProvider = $this->services($kernel)->get('ids_sensor.identity_provider');

        $event = $factory->create(
            CapturedEvent::now(Layer::Kernel, 'kernel.request'),
            $identityProvider->get(),
            'kernel.request',
            'req-7f2a1c',
            Actor::anonymous(),
            Severity::Info,
            ['method' => 'GET', 'path' => '/api/orders/42'],
        );

        $data = $event->toArray();
        foreach (EventSchema::MANDATORY_FIELDS as $field) {
            self::assertArrayHasKey($field, $data, \sprintf('Pflichtfeld "%s" fehlt', $field));
        }
        self::assertSame('shop-api', $data[EventSchema::FIELD_APPLICATION_ID]);
        self::assertSame('prod', $data[EventSchema::FIELD_ENVIRONMENT]);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $data[EventSchema::FIELD_EVENT_ID],
            'Die verdrahtete Implementierung muss UUIDv7 liefern',
        );
    }

    /**
     * Ein fehlender HMAC-Schlüssel bricht bewusst die Kompilierung ab. fail-open
     * gilt für den Request-Pfad, nicht für Deployment-Fehler: ein stilles null in
     * actor.session_id_hash würde die sitzungsbezogenen Regeln B8/B9 unsichtbar
     * abschalten.
     */
    public function testAMissingSessionKeyAbortsCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/session_hash\.key ist erforderlich/');

        $this->boot(['application_id' => 'shop-api', 'environment' => 'prod'], 'no-key');
    }

    public function testSessionHashingCanBeDeliberatelyDisabled(): void
    {
        $kernel = $this->boot([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['enabled' => false],
        ], 'hash-off');

        self::assertTrue($kernel->getContainer()->getParameter('ids_sensor.enabled'));
    }

    /**
     * APP_SECRET als IDS-Schlüssel würde genau den Session-Hijacking-Vektor
     * öffnen, den das Hashen verhindern soll — die überwachte Anwendung kennt
     * APP_SECRET, ein Angreifer mit Codeausführung also auch.
     */
    /**
     * Ein zu kurzer HMAC-Schlüssel muss die Kompilierung abbrechen.
     *
     * `doc/08:80` nennt `min_key_length` „Untergrenze der Prüfung", `README.md:154`
     * verspricht „≥ 32 Zeichen" — geprüft wurde bis zuletzt nichts, `key: 'geheim'` lief
     * durch. Ist der Schlüssel zu kurz, lässt sich aus einer gestohlenen Event-Datenbank
     * die Session-ID zurückrechnen: genau der Session-Hijacking-Vektor, den das Hashen
     * nach Konzept 2.2.4 verhindern soll.
     */
    public function testATooShortSessionHashKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/mindestens 32 sind verlangt/');

        (new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => 'geheim'],
        ], 'kurzer-schluessel'))->boot();
    }

    public function testAppSecretAsKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/identisch mit APP_SECRET/');

        $this->boot([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => 'test-app-secret'],
        ], 'secret-reuse');
    }

    public function testMissingApplicationIdIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(['environment' => 'prod'], 'no-app-id');
    }

    public function testMissingEnvironmentIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(['application_id' => 'shop-api'], 'no-env');
    }

    public function testAnInvalidCaptureModeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['layers' => ['business' => ['capture_mode' => 'telepathie']]]),
            'bad-mode',
        );
    }

    public function testAnInvalidFlushPolicyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['flush' => ['policy' => 'irgendwie']]),
            'bad-policy',
        );
    }

    /**
     * Der Spool ist nicht abschaltbar, und das Abweisen ist der Punkt.
     *
     * `spool.enabled` gab es als Knoten, gesetzt wurde daraus ein Parameter, und gelesen
     * hat den Parameter niemand — `enabled: false` hat schlicht nichts getan. Eine
     * Konfiguration, die etwas verspricht und nichts bewirkt, ist gefährlicher als eine,
     * die es gar nicht gibt: Sie kann bestätigt und abgehakt werden.
     *
     * Der Spool trägt die fail-open-Zusage aus Konzept 4 und ist unter mod_php laut 3.3.1
     * der einzige Transportweg. Wer ihn nicht selbst leeren will, stellt `drain: off`.
     */
    public function testTheRemovedSpoolSwitchIsRejectedInsteadOfIgnored(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['spool' => ['enabled' => false]]),
            'spool-switch',
        );
    }

    /**
     * Die sieben entfernten Optionen werden abgelehnt, nicht stillschweigend übergangen.
     *
     * Sie standen im Baum, waren in `doc/08-konfiguration.md` mit Wirkung dokumentiert und
     * wurden von niemandem gelesen — `spool.drain` sogar mit vollständiger Validierung
     * seiner vier Werte. Wer `off` einstellte, bekam trotzdem alles nachgesendet.
     *
     * Nach dem Entfernen ist das Verhalten das richtige: Symfonys Config-Komponente lehnt
     * einen unbekannten Schlüssel ab. Wer eine dieser Optionen in seiner Konfiguration
     * stehen hat, erfährt es beim nächsten Deploy — statt weiter zu glauben, sie wirke.
     *
     * @param array<string, mixed> $konfiguration
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entfernteOptionen')]
    public function testARemovedOptionIsRejectedInsteadOfIgnored(array $konfiguration): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(array_merge(self::MINIMAL_CONFIG, $konfiguration), 'entfernt-'.md5(serialize($konfiguration)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function entfernteOptionen(): iterable
    {
        yield 'spool.drain' => [['spool' => ['drain' => 'off']]];
        yield 'spool.drain_min_interval_s' => [['spool' => ['drain_min_interval_s' => 5]]];
        yield 'budget.drain_ms' => [['budget' => ['drain_ms' => 25]]];
        yield 'flush.batch' => [['flush' => ['batch' => false]]];
        yield 'circuit_breaker.half_open_probes' => [['circuit_breaker' => ['half_open_probes' => 3]]];
        yield 'telemetry.profiler_collector' => [['telemetry' => ['profiler_collector' => false]]];
        yield 'logging.enabled' => [['logging' => ['enabled' => false]]];
        yield 'budget.max_events_per_process' => [['budget' => ['max_events_per_process' => 500]]];
    }

    /**
     * Der konfigurierte Monolog-Kanal kommt an den Diensten an.
     *
     * `logging.channel` war dokumentiert und wirkungslos: Der Kanal stand hart in acht
     * `monolog.logger`-Tags. Der naheliegende Weg — `channel: '%ids_sensor.logging.channel%'`
     * in der YAML — funktioniert NICHT: `ResolveParameterPlaceHoldersPass` fasst von den
     * Tags ausschließlich `proxy` an. Monolog bekäme einen Kanal, der wörtlich
     * `%ids_sensor.logging.channel%` heißt, und niemand merkte es, weil das
     * Protokollieren weiterläuft.
     */
    public function testTheConfiguredLogChannelReachesTheServices(): void
    {
        // Über den Container-ABDRUCK: Der kompilierte Container gibt keine Definitionen
        // mehr her, und genau die Tags sind hier die Frage.
        $target = sys_get_temp_dir().'/ids-fingerprints/log-kanal.json';
        @unlink($target);

        (new TestKernel(
            array_merge(self::MINIMAL_CONFIG, ['logging' => ['channel' => 'eigener_kanal']]),
            'log-kanal',
            false,
            true,
            null,
            $target,
        ))->boot();

        $abdruck = (string) file_get_contents($target);

        self::assertStringContainsString('"channel": "eigener_kanal"', $abdruck);
        self::assertStringNotContainsString('%ids_sensor.logging.channel%', $abdruck);
    }

    /**
     * Die drei Sicherheitsvorgaben des Transports sind nicht überschreibbar.
     *
     * Sie standen als Bitte in der Doku („auto_setup muss false bleiben") und als
     * ausführliche Begründung in `TRANSPORT_DEFAULTS` — durchgesetzt hat sie niemand:
     * `array_merge` ließ die Optionen der Anwendung gewinnen. `lazy: false` etwa öffnet
     * die Verbindung beim BAUEN des Dienstes, also außerhalb jedes try/catch des Sensors,
     * und bricht damit fail-open.
     *
     * Der wahrscheinlichste Erstinstallationsfehler `auto_setup: true` wird damit beim
     * Kompilieren abgelehnt statt erst im Deploy-Check — die stärkere Antwort auf
     * dieselbe Frage, weil sie früher kommt und sich nicht mit `|| true` abschalten lässt.
     *
     * @param array<string, mixed> $optionen
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('geschuetzteTransportOptionen')]
    public function testProtectedTransportOptionsAreRejected(array $optionen): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, [
                'transport' => ['dsn' => 'redis://127.0.0.1:6379/ids:events/group/consumer', 'options' => $optionen],
            ]),
            'transport-'.md5(serialize($optionen)),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function geschuetzteTransportOptionen(): iterable
    {
        yield 'auto_setup' => [['auto_setup' => true]];
        yield 'lazy' => [['lazy' => false]];
        yield 'serializer' => [['serializer' => 1]];
    }

    /**
     * Ein Tippfehler in `raw.severities` darf `raw` nicht lautlos abschalten.
     *
     * `['warnings']` oder `['WARNING']` kompilierte anstandslos, und der Gate fand die
     * Stufe nie in seiner Liste: kein Fehler, keine Meldung, kein Zähler — `raw` reiste
     * einfach nie mehr mit.
     */
    public function testAnUnknownRawSeverityIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['raw' => ['severities' => ['warnings']]]),
            'raw-tippfehler',
        );
    }

    /**
     * Ein Pfadmuster ohne Trennzeichen wird abgelehnt statt still ignoriert.
     *
     * `ignored_paths` sind PCRE-Ausdrücke, und `isIgnored()` prüft sie mit `@preg_match`.
     * `/health` kompilierte anstandslos und traf dann nie — der Betreiber glaubte, einen
     * Pfad ausgeschlossen zu haben, und bekam ihn weiter erfasst. Weder Baum noch Doku
     * erwähnten die Trennzeichenpflicht.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigePfadmuster')]
    public function testAnInvalidIgnoredPathPatternIsRejected(string $muster): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['layers' => ['kernel' => ['ignored_paths' => [$muster]]]]),
            'muster-'.md5($muster),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltigePfadmuster(): iterable
    {
        yield 'ohne Trennzeichen' => ['/health'];
        yield 'unbalancierte Klammer' => ['#^/(health$#'];
    }

    public function testAValidIgnoredPathPatternIsAccepted(): void
    {
        $kernel = $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['layers' => ['kernel' => ['ignored_paths' => ['#^/health$#']]]]),
            'muster-gueltig',
        );

        /** @var list<string> $muster */
        $muster = $kernel->getContainer()->getParameter('ids_sensor.layers.kernel.ignored_paths');

        self::assertSame(['#^/health$#'], $muster);
    }

    /**
     * `capture_fatal_errors: false` muss den Dienst ENTFERNEN, nicht bloß einen Parameter
     * setzen.
     *
     * Der Listener registriert eine Shutdown-Funktion. Bliebe er im Container stehen und
     * fragte zur Laufzeit einen Schalter ab, wäre die Funktion trotzdem gesetzt — und die
     * Option hätte eine plausible Bestätigung durch `debug:config` und keine Wirkung.
     * Genau das war der Zustand: `loadExtension()` setzte den Parameter,
     * `services_kernel.yaml` registrierte den Listener bedingungslos, und niemand las den
     * Parameter. Der CHANGELOG-Eintrag zu 0.1.1 behauptete, die Option sei „wirksam"
     * geworden.
     */
    public function testFatalErrorRescueIsRegisteredByDefault(): void
    {
        $kernel = $this->boot(self::MINIMAL_CONFIG, 'minimal');

        self::assertTrue($kernel->getContainer()->has('ids_sensor.fatal_error_flush_listener'));
    }

    public function testFatalErrorRescueCanBeSwitchedOff(): void
    {
        $kernel = $this->boot(
            array_merge(self::MINIMAL_CONFIG, ['layers' => ['kernel' => ['capture_fatal_errors' => false]]]),
            'fatal-aus',
        );

        self::assertFalse(
            $kernel->getContainer()->has('ids_sensor.fatal_error_flush_listener'),
            'capture_fatal_errors: false muss den Dienst entfernen — ein Schalter, der ihn stehen '
            .'lässt, registriert die Shutdown-Funktion trotzdem',
        );
    }

    /**
     * Der Name des Session-Cookies kommt aus `framework.session.name`, nicht aus php.ini.
     *
     * Symfony schreibt den konfigurierten Namen erst dann nach php.ini, wenn
     * `NativeSessionStorage` konstruiert wird — ein lazy Dienst, der erst beim ersten
     * `$request->getSession()` entsteht. Der RequestSensor läuft bei Priorität 1024, der
     * SessionListener bei 128: Zum Erfassungszeitpunkt stand dort praktisch immer noch
     * `PHPSESSID`, und `SessionIdHasher` fand das Cookie nie. Jede Anwendung mit eigenem
     * Session-Namen lieferte damit `actor.session_id_hash: null` in JEDEM Event — die
     * Regeln B8/B9 aus Konzept 4.3.3 waren still abgeschaltet.
     */
    public function testTheSessionCookieNameComesFromTheFrameworkConfiguration(): void
    {
        $kernel = new TestKernel(self::MINIMAL_CONFIG, 'session-name', sessionName: 'MYAPPSESSID');
        $kernel->boot();

        self::assertSame(
            'MYAPPSESSID',
            $kernel->getContainer()->getParameter('ids_sensor.session_hash.cookie_name'),
        );
    }

    /**
     * Eine ausdrückliche Angabe gewinnt weiterhin gegen die Framework-Konfiguration.
     */
    public function testAnExplicitCookieNameWins(): void
    {
        $kernel = new TestKernel(
            // Die Teilkonfiguration wird GEMISCHT, nicht ersetzt: array_merge auf der
            // obersten Ebene nähme session_hash.key mit, und ohne den bricht die
            // Kompilierung ab.
            array_merge(self::MINIMAL_CONFIG, [
                'session_hash' => array_merge(self::MINIMAL_CONFIG['session_hash'], ['cookie_name' => 'EIGENES']),
            ]),
            'session-name-eigen',
            sessionName: 'MYAPPSESSID',
        );
        $kernel->boot();

        self::assertSame(
            'EIGENES',
            $kernel->getContainer()->getParameter('ids_sensor.session_hash.cookie_name'),
        );
    }

    public function testDisabledBundleRegistersNoServices(): void
    {
        $kernel = $this->boot(
            ['enabled' => false, 'application_id' => 'shop-api', 'environment' => 'prod'],
            'disabled',
        );

        self::assertFalse($kernel->getContainer()->getParameter('ids_sensor.enabled'));
        self::assertFalse($kernel->getContainer()->hasParameter('ids_sensor.application_id'));
    }

    /**
     * Die Vorgabe für ignored_paths muss leer bleiben: Regel R2b lebt davon,
     * Zugriffe auf /_profiler zu sehen. Ein gut gemeinter Default würde genau das
     * Signal löschen, das das Szenario S1 erkennbar macht.
     */
    public function testIgnoredPathsIsEmptyByDefault(): void
    {
        $kernel = $this->boot(self::MINIMAL_CONFIG, 'minimal');

        /** @var array{layers: array{kernel: array{ignored_paths: list<string>}}} $config */
        $config = $kernel->getContainer()->getParameter('ids_sensor.config');

        self::assertSame([], $config['layers']['kernel']['ignored_paths']);
    }

    /**
     * Eine eigene Redaktionsliste ERGÄNZT die mitgelieferte — sie ersetzt sie nicht.
     *
     * `merge_defaults: true` ist die Vorgabe und laut `doc/06` das, was verhindert, dass
     * eine Anwendung, die nur `x_tenant_secret` hinzufügen will, versehentlich `Cookie`
     * und `Authorization` freischaltet. Geprüft hat das bislang nichts — und ein Fehler
     * hier ist unsichtbar: Der Container kompiliert, die Anwendung läuft, und
     * Zugangsdaten stehen im Klartext im Frame.
     */
    public function testAnOwnRuleListExtendsTheBundledOne(): void
    {
        $kernel = $this->boot($this->mitEigenerListe(true), 'cleanup-merge');

        /** @var list<string> $header */
        $header = $kernel->getContainer()->getParameter('ids_sensor.payload_confidentiality_cleanup.headers');
        /** @var int $version */
        $version = $kernel->getContainer()->getParameter('ids_sensor.payload_confidentiality_cleanup.version');

        self::assertContains('x-tenant-secret', $header, 'Der eigene Eintrag muss ankommen');
        self::assertContains('Cookie', $header, 'Und der mitgelieferte darf dabei nicht verschwinden');
        self::assertSame(99, $version, 'Die Version der Anwendungsliste gewinnt — sie ist die, die der Betreiber pflegt');
    }

    /**
     * Wer verkleinern muss, kann es — ausdrücklich und auf eigene Verantwortung.
     */
    public function testMergeDefaultsFalseReplacesTheBundledList(): void
    {
        $kernel = $this->boot($this->mitEigenerListe(false), 'cleanup-ersetzen');

        /** @var list<string> $header */
        $header = $kernel->getContainer()->getParameter('ids_sensor.payload_confidentiality_cleanup.headers');

        self::assertSame(['x-tenant-secret'], $header);
    }

    /**
     * @return array<string, mixed>
     */
    private function mitEigenerListe(bool $mergeDefaults): array
    {
        $pfad = sys_get_temp_dir().'/ids-cleanup-'.($mergeDefaults ? 'merge' : 'ersetzen').'.yaml';
        file_put_contents($pfad, "version: 99\nheaders:\n    - x-tenant-secret\nparameters:\n    - tenant_key\n");

        return array_merge(self::MINIMAL_CONFIG, [
            'payload_confidentiality_cleanup' => ['config' => $pfad, 'merge_defaults' => $mergeDefaults],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config, string $variant): TestKernel
    {
        $kernel = new TestKernel($config, $variant);
        $kernel->boot();

        return $kernel;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function identityFrom(array $config, string $variant): SensorIdentity
    {
        /** @var SensorIdentityProvider $provider */
        $provider = $this->services($this->boot($config, $variant))->get('ids_sensor.identity_provider');

        return $provider->get();
    }

    /**
     * Das Bundle muss auch in Anwendungen OHNE SecurityBundle installierbar sein.
     *
     * Die Security-Ebene ist standardmäßig an; ohne diese Prüfung würde ein Container,
     * in dem es kein security.access.decision_manager gibt, beim Kompilieren mit
     * „You have requested a non-existent service" abbrechen — und zwar in jeder
     * Anwendung, die das Bundle bloß installiert.
     */
    public function testTheBundleBootsWithoutSecurityBundle(): void
    {
        $kernel = new TestKernel(self::MINIMAL_CONFIG, 'ohne-security');
        $kernel->boot();

        $container = $kernel->getContainer();

        self::assertFalse($container->has('ids_sensor.sensor.authentication'));
        self::assertFalse($container->has('ids_sensor.sensor.access_decision'));
        self::assertFalse($container->has('ids_sensor.normalizer.security'));
        self::assertFalse(
            $container->getParameter('ids_sensor.layers.security.active'),
            'Ohne SecurityBundle ist die Security-Ebene inaktiv — und das muss ablesbar sein',
        );

        // Die Kernel-Ebene läuft trotzdem: der Sensor ist nicht von Security abhängig.
        self::assertTrue($container->has('ids_sensor.sensor.kernel_request'));
    }
}
