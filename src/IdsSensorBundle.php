<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor;

use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\DependencyInjection\Compiler\BusinessCaptureModePass;
use ProjektMotor\IdsSensor\DependencyInjection\ConfigurationTree;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\RulesLoader;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Das Sensor-Bundle, installiert IN der überwachten Anwendung.
 *
 * Umsetzt Abschnitt 2 des Konzepts. Kernel- und Security-Ebene sind nach
 * `composer require` ohne Anwendungscode aktiv; die Business-Ebene erfordert
 * zwingend Arbeit in der Anwendung. Diese Asymmetrie ist beabsichtigt und wird in
 * der Dokumentation nicht verschleiert.
 *
 * Erhält bewusst KEINEN Datenbankzugriff: trüge das Bundle die
 * PostgreSQL-Zugangsdaten, hätte die überwachte Anwendung Zugriff auf ihren
 * eigenen Beweisspeicher, und ein Angreifer mit Codeausführung könnte seine Spuren
 * löschen. Die Manipulationsgrenze verläuft am Ingest-Endpunkt des Collectors
 * (Konzept 2.): Der Sensor kennt drei Adressen, und alle drei nehmen nur entgegen.
 *
 * Nutzt AbstractBundle statt Bundle+Extension+Configuration. Der ausschlaggebende
 * Grund ist getPath(): es liefert dirname($classFile, 2), also bei
 * src/IdsSensorBundle.php das Repository-Wurzelverzeichnis — genau das Layout
 * dieses Pakets. Mit der klassischen Bundle-Basisklasse müsste getPath()
 * überschrieben werden.
 */
final class IdsSensorBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new BusinessCaptureModePass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();

        // rootNode() ist als NodeDefinition|ArrayNodeDefinition deklariert; für den
        // Wurzelknoten einer Extension ist es immer eine ArrayNodeDefinition.
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException(\sprintf('Der Wurzelknoten von ids_sensor sollte eine %s sein, ist aber %s.', ArrayNodeDefinition::class, get_debug_type($root)));
        }

        ConfigurationTree::build($root);
    }

    /**
     * Registriert den Transport in der framework-Konfiguration.
     *
     * Läuft bevor irgendeine Extension geladen wird, deshalb liegt die eigene
     * Konfiguration hier noch unverarbeitet vor und muss defensiv gelesen werden.
     * Vorangestellt statt angehängt, damit ausdrückliche Angaben der Anwendung
     * gewinnen — die richtige Rangfolge für eine Bibliothek.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('framework')) {
            return;
        }

        // MUSS hier stehen und nicht in loadExtension(). Dort ist der ContainerBuilder der
        // temporäre aus MergeExtensionConfigurationPass, und der trägt KEINE
        // Extension-Konfigurationen — getExtensionConfig('framework') gäbe dort immer ein
        // leeres Array zurück. Den ParameterBag teilt er dagegen mit dem echten Container,
        // derselbe Weg wie bei kernel.bundles in securityBundleIsRegistered().
        $builder->setParameter(
            'ids_sensor.session_hash.framework_cookie_name',
            self::rawSessionCookieName($builder),
        );
    }

    /**
     * Der in {@see prependExtension()} ermittelte Cookie-Name, sofern es einen gibt.
     *
     * Über den ParameterBag statt über ein Feld: `prependExtension()` und
     * `loadExtension()` laufen auf verschiedenen ContainerBuildern, aber auf demselben
     * ParameterBag.
     */
    private static function frameworkCookieName(ContainerBuilder $builder): ?string
    {
        if (!$builder->hasParameter('ids_sensor.session_hash.framework_cookie_name')) {
            return null;
        }

        $name = $builder->getParameter('ids_sensor.session_hash.framework_cookie_name');

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * Der Name des Session-Cookies aus `framework.session.name`.
     *
     * WARUM DAS NICHT ini_get('session.name') SEIN DARF
     *
     * {@see Sensor\Context\SessionIdHasher} liest den Cookie-Wert, nicht die Session — das
     * ist richtig und in Konzept 2.1 begründet (kein I/O im Request-Pfad). Er braucht dafür
     * aber den NAMEN, und `ini_get('session.name')` ist dafür die falsche Quelle:
     * `framework.session.name` erreicht php.ini erst, wenn `NativeSessionStorage`
     * konstruiert wird, und das ist ein lazy Dienst, der erst beim ersten
     * `$request->getSession()` entsteht. Der RequestSensor läuft bei Priorität 1024, der
     * SessionListener bei 128 — zum Erfassungszeitpunkt steht dort praktisch immer noch der
     * php.ini-Wert.
     *
     * Die Folge war ein stiller Totalausfall der Sitzungsverkettung: Jede Anwendung mit
     * eigenem `framework.session.name` lieferte `actor.session_id_hash: null` in JEDEM
     * Event, und die Regeln B8/B9 (Konzept 4.3.3, Szenario S9) konnten nicht feuern. Der
     * Wert war nicht einmal stabil — wurde die Session irgendwo im Request doch
     * materialisiert, trug derselbe Request `null` im kernel.request und einen Hash im
     * kernel.response.
     *
     * Gelesen wird wie beim Transport aus der noch unverarbeiteten Konfiguration: der
     * zuletzt gesetzte Wert gewinnt, was der Merge-Reihenfolge der Config-Komponente
     * entspricht. Ein %env()%-Platzhalter wird unverändert durchgereicht und später
     * aufgelöst.
     */
    private static function rawSessionCookieName(ContainerBuilder $builder): ?string
    {
        $name = null;

        foreach ($builder->getExtensionConfig('framework') as $config) {
            if (!\is_array($config) || !isset($config['session']) || !\is_array($config['session'])) {
                continue;
            }

            $candidate = $config['session']['name'] ?? null;

            if (\is_string($candidate) && '' !== $candidate) {
                $name = $candidate;
            }
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Der Kill-Schalter registriert bewusst gar keine Listener, statt sie
        // zur Laufzeit abzufragen.
        if (false === $config['enabled']) {
            $builder->setParameter('ids_sensor.enabled', false);

            return;
        }

        $builder->setParameter('ids_sensor.enabled', true);
        $builder->setParameter('ids_sensor.application_id', $config['application_id']);
        $builder->setParameter('ids_sensor.environment_id', $config['environment_id']);
        $builder->setParameter('ids_sensor.sensor_id', $config['sensor_id']);
        $builder->setParameter('ids_sensor.schema_version', EventSchema::SCHEMA_VERSION);

        // Flache Parameter für die Werte, die die Verdrahtung braucht. Ein
        // verschachteltes Array als einzelner Parameter wäre in der YAML-Verdrahtung nicht
        // indexierbar — dort steht ein Parameter immer als ganzer Wert (%name%), nie ein
        // einzelner Schlüssel daraus.
        $builder->setParameter('ids_sensor.budget.capture_us', $config['budget']['capture_us']);
        $builder->setParameter('ids_sensor.budget.max_events_per_request', $config['budget']['max_events_per_request']);
        // Konzept 4.: „Hartes Timeout von 50 ms; danach Abbruch des Versands, der Request
        // läuft normal weiter." Durchgesetzt zwischen zwei Sendungen im
        // FlushListener — siehe dort, warum das die einzige durchsetzbare Lesart ist.
        $builder->setParameter('ids_sensor.budget.dispatch_ms', $config['budget']['dispatch_ms']);
        $builder->setParameter('ids_sensor.logging.channel', $config['logging']['channel']);
        $builder->setParameter('ids_sensor.budget.fatal_dispatch_ms', $config['budget']['fatal_dispatch_ms']);
        $builder->setParameter(
            'ids_sensor.layers.kernel.capture_fatal_errors',
            $config['layers']['kernel']['enabled'] && $config['layers']['kernel']['capture_fatal_errors'],
        );
        // In Millisekunden für die Konfiguration, in Sekunden für den HTTP-Client —
        // beide Fassungen unten, damit die Umrechnung nicht in der YAML als Zeichenkette
        // stehen bleibt.
        $builder->setParameter('ids_sensor.budget.connect_timeout_ms', $config['budget']['connect_timeout_ms']);
        $builder->setParameter('ids_sensor.budget.read_timeout_ms', $config['budget']['read_timeout_ms']);

        // Dieselben Grenzen in Sekunden. Symfonys HTTP-Client rechnet in Sekunden,
        // die Konfiguration steht in Millisekunden — die Umrechnung gehört hierher
        // und nicht in die YAML, wo sie als Zeichenkette stehen bliebe.
        $builder->setParameter(
            'ids_sensor.budget.connect_timeout_s',
            $config['budget']['connect_timeout_ms'] / 1000,
        );
        $builder->setParameter(
            'ids_sensor.budget.read_timeout_s',
            $config['budget']['read_timeout_ms'] / 1000,
        );
        $builder->setParameter(
            'ids_sensor.budget.dispatch_s',
            $config['budget']['dispatch_ms'] / 1000,
        );
        $builder->setParameter('ids_sensor.telemetry.latency_histogram', $config['telemetry']['latency_histogram']);

        $builder->setParameter('ids_sensor.session_hash.enabled', $config['session_hash']['enabled']);
        // Ohne ausdrückliche Angabe der Name aus `framework.session.name` — NICHT
        // ini_get('session.name'), das zum Erfassungszeitpunkt noch den php.ini-Wert
        // trägt. Begründung bei {@see rawSessionCookieName()}.
        $builder->setParameter(
            'ids_sensor.session_hash.cookie_name',
            $config['session_hash']['cookie_name'] ?? self::frameworkCookieName($builder),
        );
        $builder->setParameter('ids_sensor.fingerprint.enabled', $config['fingerprint']['enabled']);
        $builder->setParameter('ids_sensor.fingerprint.headers', $config['fingerprint']['headers']);

        $builder->setParameter('ids_sensor.correlation.inbound_header', $config['correlation']['incoming_header']);
        $builder->setParameter('ids_sensor.correlation.trust_incoming_header', $config['correlation']['trust_incoming_header']);
        $builder->setParameter('ids_sensor.correlation.require_trusted_proxy', $config['correlation']['require_trusted_proxy']);
        $builder->setParameter('ids_sensor.correlation.expose_request_attribute', $config['correlation']['expose_request_attribute']);

        $kernelLayer = $config['layers']['kernel'];
        $builder->setParameter('ids_sensor.layers.kernel.enabled', $kernelLayer['enabled']);
        $builder->setParameter('ids_sensor.layers.kernel.events.request', $kernelLayer['events']['request']);
        $builder->setParameter('ids_sensor.layers.kernel.events.response', $kernelLayer['events']['response']);
        $builder->setParameter('ids_sensor.layers.kernel.events.exception', $kernelLayer['events']['exception']);
        $builder->setParameter('ids_sensor.layers.kernel.sub_requests', $kernelLayer['sub_requests']);
        $builder->setParameter('ids_sensor.layers.kernel.ignored_paths', $kernelLayer['ignored_paths']);

        // Die vollständige Konfiguration bleibt als Parameter erhalten, damit
        // ids:sensor:setup-check sie zur Laufzeit anzeigen kann.
        $builder->setParameter('ids_sensor.config', $config);

        $this->loadPayloadConfidentialityCleanup($config, $container, $builder);

        $container->import('../config/services.yaml');

        // Die Kernel-Ebene ist nach `composer require` ohne Anwendungscode aktiv
        // (Konzept 2.). Abschaltbar, aber standardmäßig an.
        if (true === $kernelLayer['enabled']) {
            $container->import('../config/services_kernel.yaml');

            // Bedingt, nicht bedingungslos mit einem Laufzeit-Schalter: Der Listener
            // registriert eine Shutdown-Funktion, und die stünde sonst auch bei
            // `capture_fatal_errors: false`. Die Option hätte dann eine plausible
            // Bestätigung durch `debug:config` und keine Wirkung — genau das, was
            // ConfigurationReachTest verhindern soll. Bis hierher war sie ein
            // Parameter, den niemand las.
            if (true === $kernelLayer['capture_fatal_errors']) {
                $container->import('../config/services_kernel_fatal_errors.yaml');
            }
        }

        // Die Security-Ebene: nach `composer require` ohne Anwendungscode aktiv —
        // aber nur, wenn SecurityBundle überhaupt registriert ist. Das Bundle muss auch
        // ohne SecurityBundle installierbar bleiben.
        $securityLayer = $config['layers']['security'];
        $builder->setParameter('ids_sensor.layers.security.capture_granted', $securityLayer['capture_granted']);
        $builder->setParameter(
            'ids_sensor.layers.security.max_decisions_per_request',
            $securityLayer['max_decisions_per_request'],
        );

        $securityAvailable = self::securityBundleIsRegistered($builder);

        // Die Kernel-Ebene gehört in die Verrechnung, weil der Import eine Zeile weiter
        // unten sie verlangt: ActorFactory und RequestSnapshotRegistry sind dort
        // verdrahtet. Ohne sie meldete der Parameter `true`, während kein einziger
        // Security-Dienst existierte — und ids:sensor:setup-check schrieb „Security-Ebene:
        // aktiv" für eine Ebene, die es nicht gab.
        $builder->setParameter(
            'ids_sensor.layers.security.active',
            $securityLayer['enabled'] && $securityAvailable && $kernelLayer['enabled'],
        );

        if (true === $securityLayer['enabled'] && $securityAvailable && true === $kernelLayer['enabled']) {
            $container->import('../config/services_security.yaml');

            if (true === $securityLayer['authentication']) {
                $container->import('../config/services_security_auth.yaml');
            }

            // Der teuerste Sensor des Bundles: er feuert bei JEDEM isGranted().
            // Abgesichert durch Dedup und Hard-Cap, aber abschaltbar.
            if (true === $securityLayer['access_decision']) {
                $container->import('../config/services_access_decision.yaml');
            }
        }

        // Die Business-Ebene: nach `composer require` NICHT wirksam, weil sie
        // Event-Klassen braucht, die das Interface implementieren. Konzept 2. verlangt
        // ausdrücklich, diese Asymmetrie nicht zu verschleiern.
        $businessLayer = $config['layers']['business'];
        $builder->setParameter('ids_sensor.layers.business.capture_mode', $businessLayer['capture_mode']);
        $builder->setParameter('ids_sensor.layers.business.event_classes', $businessLayer['event_classes']);
        $builder->setParameter('ids_sensor.layers.business.user_from_token', $businessLayer['user_from_token']);
        $builder->setParameter('ids_sensor.layers.business.ip_from_request', $businessLayer['ip_from_request']);

        if (true === $businessLayer['enabled'] && true === $kernelLayer['enabled']) {
            // Hängt an der Kernel-Ebene, weil ActorFactory und
            // RequestSnapshotRegistry dort verdrahtet sind.
            $container->import('../config/services_business.yaml');
        }

        // Ohne Basisadresse bleibt der NullShipper aus services.yaml stehen.
        $collector = $config['collector'];
        $builder->setParameter('ids_sensor.collector.base_uri', $collector['base_uri'] ?? '');
        $builder->setParameter('ids_sensor.collector.username', $collector['username'] ?? '');
        $builder->setParameter('ids_sensor.collector.password', $collector['password'] ?? '');
        $builder->setParameter('ids_sensor.collector.token_leeway_s', $collector['token_leeway_s']);
        $builder->setParameter('ids_sensor.collector.verify_tls', $collector['verify_tls']);
        // Ob überhaupt ein Collector erreichbar wäre. Der SpoolFlushCommand verweigert
        // ohne ihn den Dienst, statt den Spool mit dem NullShipper stillschweigend zu
        // leeren.
        $builder->setParameter(
            'ids_sensor.transport.configured',
            null !== ($collector['base_uri'] ?? null) && '' !== $collector['base_uri'],
        );

        $spool = $config['spool'];
        $builder->setParameter(
            'ids_sensor.spool.dir',
            $spool['dir'] ?? '%kernel.project_dir%/var/ids-spool',
        );
        $builder->setParameter('ids_sensor.spool.max_bytes', $spool['max_bytes']);
        $builder->setParameter('ids_sensor.spool.max_file_bytes', $spool['max_file_bytes']);
        $builder->setParameter('ids_sensor.spool.drain_max_files_per_run', $spool['drain_max_files_per_run']);
        // War bis zur Wiederaufnahme liegengebliebener .draining-Dateien ein toter
        // Knoten: dokumentiert als „ab wann eine Datei als liegengeblieben gilt", aber
        // von niemandem gelesen.
        $builder->setParameter('ids_sensor.spool.stale_after_s', $spool['stale_after_s']);
        // Reiner Dokumentationswert: der Sensor kann nicht wissen, wie oft der cron
        // tatsächlich läuft. Er meldet ihn im Heartbeat weiter, damit collectorseitig
        // bekannt ist, welche Verzögerung für diese Instanz normal ist.
        $builder->setParameter('ids_sensor.spool.drain_interval_s', $spool['drain_interval_s']);

        $this->loadHeartbeat($config, $container, $builder);

        $flush = $config['flush'];
        $builder->setParameter('ids_sensor.flush.policy', $flush['policy']);
        $builder->setParameter('ids_sensor.flush.max_frame_bytes', $flush['max_frame_bytes']);

        $breaker = $config['circuit_breaker'];
        $builder->setParameter('ids_sensor.circuit_breaker.enabled', $breaker['enabled']);
        $builder->setParameter('ids_sensor.circuit_breaker.failure_threshold', $breaker['failure_threshold']);
        $builder->setParameter('ids_sensor.circuit_breaker.open_for_s', $breaker['open_for_s']);

        $container->import('../config/services_resilience.yaml');

        if (null !== ($collector['base_uri'] ?? null) && '' !== $collector['base_uri']) {
            $container->import('../config/services_transport.yaml');
        }

        self::applyLogChannel($builder, $config['logging']['channel']);
    }

    /**
     * Setzt den konfigurierten Monolog-Kanal an allen eigenen Diensten.
     *
     * WARUM NICHT `channel: '%ids_sensor.logging.channel%'` IN DER YAML
     *
     * Weil Symfony das nicht auflöst. `ResolveParameterPlaceHoldersPass` fasst von den
     * Tags ausschließlich `proxy` an — jedes andere Tag-Attribut bleibt die
     * Zeichenkette, die dort steht. MonologBundle bekäme also einen Kanal mit dem
     * Namen `%ids_sensor.logging.channel%`, und niemand würde es merken, weil das
     * Protokollieren weiterläuft: nur eben in einen Kanal, den keine Konfiguration
     * kennt.
     *
     * Aufgefallen ist das am Container-Abdruck, der den Platzhalter unaufgelöst
     * zeigte — genau der Zweck dieses Werkzeugs.
     *
     * Der Kanal wird nach ALLEN Importen gesetzt, damit auch die bedingt geladenen
     * Dienste erfasst sind. `ids_sensor.logging.channel` bleibt als Parameter bestehen:
     * Er macht die Einstellung per `debug:container` sichtbar.
     */
    private static function applyLogChannel(ContainerBuilder $builder, string $channel): void
    {
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'ids_sensor.')) {
                continue;
            }

            $tags = $definition->getTags();

            if (!isset($tags['monolog.logger'])) {
                continue;
            }

            foreach ($tags['monolog.logger'] as $index => $attribute) {
                $tags['monolog.logger'][$index] = ['channel' => $channel] + $attribute;
            }

            $definition->setTags($tags);
        }
    }

    /**
     * Der Heartbeat (Konzept 2.).
     *
     * `mode: auto` wird HIER aufgelöst, zur Compile-Zeit, und zwar auf `both`. Begründung:
     *
     *  - `request` allein schweigt bei fehlendem Verkehr, und der Collector kann das nicht
     *    von einer Stilllegung unterscheiden.
     *  - `command` allein liefert nichts, solange der cron nicht eingerichtet ist — und ein
     *    Bundle, das nach `composer require` still bleibt, verletzt genau die
     *    Ehrlichkeitspflicht aus Konzept 2.
     *
     * `both` ist die einzige Auflösung, die in beiden Fällen etwas liefert: der Request-Pfad
     * wirkt sofort, der Command übernimmt, sobald er eingerichtet ist. Die gemeinsame
     * Drosselung verhindert doppelte Meldungen.
     *
     * ZUR AUSSAGEKRAFT VON `triggered_by`
     *
     * Konzept 3.4 leitet aus „`mode: both`, aber `triggered_by` dauerhaft `request`" ab,
     * dass der cron-Eintrag fehlt. Hier stand dieselbe Aussage ohne Vorbehalt — sie hat
     * einen: `Mode::Request` heißt „durch Anwendungsaktivität ausgelöst" und deckt auch
     * `console.terminate` ab, also JEDEN Console-Command, einschließlich des
     * verpflichtenden `ids:sensor:spool:flush`. Läuft der häufiger als
     * `heartbeat.interval_s`, kommt er der gemeinsamen Drosselung stets zuvor, und der
     * eigentliche Heartbeat-cron findet dauerhaft „noch nicht fällig" — `triggered_by`
     * bliebe auf `request`, obwohl der Eintrag existiert.
     *
     * Die Richtung, die trägt, ist die andere: Ein einziger Heartbeat mit
     * `triggered_by: command` beweist, dass der cron läuft. Für die Gegenrichtung ist
     * `ids:sensor:setup-check` zuständig — der prüft die Konfiguration, statt aus einem
     * Payload-Feld zu raten.
     *
     * @param array<string, mixed> $config
     */
    private function loadHeartbeat(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{enabled: bool, mode: string, interval_s: int, stamp_file: string|null} $heartbeat */
        $heartbeat = $config['heartbeat'];

        $mode = $heartbeat['mode'];

        if ('auto' === $mode) {
            $mode = Mode::Both->value;
        }

        $builder->setParameter('ids_sensor.heartbeat.enabled', $heartbeat['enabled'] && 'off' !== $mode);
        $builder->setParameter('ids_sensor.heartbeat.mode', 'off' === $mode ? Mode::Both->value : $mode);
        $builder->setParameter('ids_sensor.heartbeat.interval_s', $heartbeat['interval_s']);

        // Die Stempeldatei ist der Rückfall, wenn APCu fehlt — und zwischen CLI und FPM der
        // EINZIGE gemeinsame Zustand: beide sehen getrennte APCu-Segmente. Ohne sie wüssten
        // Command- und Request-Pfad nichts voneinander und würden im Modus `both` doppelt
        // senden.
        $builder->setParameter(
            'ids_sensor.heartbeat.stamp_file',
            $heartbeat['stamp_file'] ?? '%kernel.cache_dir%/ids-sensor-heartbeat.stamp',
        );

        if (true === $builder->getParameter('ids_sensor.heartbeat.enabled')) {
            $container->import('../config/services_heartbeat.yaml');
        }
    }

    /**
     * Liest die Redaktionsliste (Konzept 4.5.1) zur Compile-Zeit in Parameter.
     *
     * @param array<string, mixed> $config
     */
    private function loadPayloadConfidentialityCleanup(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{config: string|null, merge_defaults: bool, replacement: string} $cleanup */
        $cleanup = $config['payload_confidentiality_cleanup'];
        /** @var array{enabled: bool, severities: list<string>, max_bytes: int, include_request_body: bool, skip_multipart: bool, max_request_body_bytes: int} $raw */
        $raw = $config['raw'];

        $defaults = \dirname(__DIR__).'/config/payload_confidentiality_cleanup.dist.yaml';
        $loader = new RulesLoader();

        if (null === $cleanup['config']) {
            $rules = $loader->load($defaults, [], [], $builder);
        } else {
            $rules = $loader->load($cleanup['config'], [], [], $builder);

            // merge_defaults: true ist der Regelfall — eine eigene Liste ERGÄNZT die
            // mitgelieferte. Andernfalls würde eine Anwendung, die nur `x_tenant_secret`
            // hinzufügen will, versehentlich Cookie und Authorization freischalten. Wer
            // die Liste verkleinern muss, setzt merge_defaults: false und übernimmt die
            // Verantwortung dafür ausdrücklich.
            if (true === $cleanup['merge_defaults']) {
                $bundled = $loader->load($defaults, [], [], $builder);
                $rules = [
                    // Die Version der ANWENDUNGSLISTE gewinnt: sie ist die, die der
                    // Betreiber pflegt und deren Fassung er benennen kann.
                    'version' => $rules['version'],
                    'headers' => array_values(array_unique(array_merge($bundled['headers'], $rules['headers']))),
                    'parameters' => array_values(array_unique(array_merge($bundled['parameters'], $rules['parameters']))),
                ];
            }
        }

        $builder->setParameter('ids_sensor.payload_confidentiality_cleanup.version', $rules['version']);
        $builder->setParameter('ids_sensor.payload_confidentiality_cleanup.headers', $rules['headers']);
        $builder->setParameter('ids_sensor.payload_confidentiality_cleanup.parameters', $rules['parameters']);
        $builder->setParameter('ids_sensor.payload_confidentiality_cleanup.replacement', $cleanup['replacement']);

        $builder->setParameter('ids_sensor.raw.enabled', $raw['enabled']);
        $builder->setParameter('ids_sensor.raw.severities', $raw['severities']);
        $builder->setParameter('ids_sensor.raw.max_bytes', $raw['max_bytes']);
        $builder->setParameter('ids_sensor.raw.include_request_body', $raw['include_request_body']);
        $builder->setParameter('ids_sensor.raw.skip_multipart', $raw['skip_multipart']);
        $builder->setParameter('ids_sensor.raw.max_request_body_bytes', $raw['max_request_body_bytes']);

        $container->import('../config/services_payload_confidentiality_cleanup.yaml');
        $container->import('../config/services_raw_payload.yaml');
    }

    /**
     * Ist SecurityBundle in dieser Anwendung registriert?
     *
     * WARUM NICHT hasExtension('security')
     *
     * Der ContainerBuilder, den loadExtension() erhält, ist NICHT der echte Container:
     * MergeExtensionConfigurationPass legt für jede Extension einen frischen
     * MergeExtensionConfigurationContainerBuilder an, dessen registerExtension() sogar
     * ausdrücklich wirft. Dort ist KEINE Extension registriert, also gibt
     * hasExtension() immer false zurück — die Security-Ebene wäre stillschweigend nie
     * geladen worden, auch in Anwendungen mit SecurityBundle. Genau das lautlose
     * Versagen, das Konzept 2. beim Stilllegen des Sensors als besonders gefährlich
     * beschreibt.
     *
     * hasDefinition() taugt ebenso wenig: die Definitionen von SecurityBundle entstehen
     * erst in dessen eigenem load(), und die Reihenfolge ist die Bundle-Reihenfolge der
     * Anwendung.
     *
     * Was funktioniert: der Parameter `kernel.bundles`. Der Kernel setzt ihn, bevor
     * irgendeine Extension lädt, und der temporäre Container teilt den ParameterBag mit
     * dem echten.
     */
    private static function securityBundleIsRegistered(ContainerBuilder $builder): bool
    {
        if (!$builder->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $builder->getParameter('kernel.bundles');

        if (!\is_array($bundles)) {
            return false;
        }

        if (isset($bundles['SecurityBundle'])) {
            return true;
        }

        // Ein eigener Kernel darf SecurityBundle unter anderem Namen registrieren.
        foreach ($bundles as $class) {
            if (\is_string($class) && is_a($class, SecurityBundle::class, true)) {
                return true;
            }
        }

        return false;
    }
}
