<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\EventSchema;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
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
