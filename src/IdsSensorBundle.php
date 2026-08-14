<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\DependencyInjection\Compiler\BusinessCaptureModePass;
use ProjektMotor\IdsSensor\DependencyInjection\ConfigurationTree;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\RulesLoader;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
 * löschen. Die Manipulationsgrenze verläuft am Broker (Konzept 2.).
 *
 * Nutzt AbstractBundle statt Bundle+Extension+Configuration. Der ausschlaggebende
 * Grund ist getPath(): es liefert dirname($classFile, 2), also bei
 * src/IdsSensorBundle.php das Repository-Wurzelverzeichnis — genau das Layout
 * dieses Pakets. Mit der klassischen Bundle-Basisklasse müsste getPath()
 * überschrieben werden.
 */
final class IdsSensorBundle extends AbstractBundle
{
    public const SERIALIZER_ID = 'ids_sensor.transport.serializer';

    /**
     * Alias auf den Messenger-Transport, über den der Shipper versendet.
     *
     * Nötig, weil die eigentliche Service-ID den konfigurierbaren Transportnamen enthält
     * (`messenger.transport.<name>`) und eine statische YAML-Datei den nicht kennen kann.
     * Denselben Weg geht Symfony selbst für `messenger.failure_transports.default`.
     */
    public const TRANSPORT_ID = 'ids_sensor.transport';

    /**
     * Sichere Vorgaben für den Redis-Transport. Anwendungsseitige Optionen werden
     * darüber gemischt.
     *
     * `auto_setup: false` ist PFLICHT und der wahrscheinlichste
     * Erstinstallationsfehler: der Messenger-Standard sendet beim ersten Zugriff
     * `XGROUP CREATE ... MKSTREAM`. Die XADD-only-Rechte aus Konzept 2. lehnen das ab.
     * Das Tückische daran ist, dass es in der Entwicklung mit unbeschränktem
     * Redis-Nutzer funktioniert und erst beim ersten Versand in Produktion scheitert.
     * Die Consumer-Gruppe erzeugt der Collector.
     *
     * Die Timeouts sind die einzige Zeitgrenze, die PHP beim Broker-Zugriff wirklich
     * durchsetzen kann (siehe {@see Dispatch\EventFlusher}).
     * Ohne ausdrückliche Angabe wartet phpredis unbegrenzt.
     *
     * @var array<string, scalar>
     */
    private const TRANSPORT_DEFAULTS = [
        'auto_setup' => false,
        'timeout' => 0.02,
        'read_timeout' => 0.03,

        // MUSS true sein: Symfonys Vorgabe ist false, und dann öffnet
        // Connection::__construct() die Verbindung sofort — beim ERZEUGEN des Services, nicht
        // beim Senden.
        //
        // Das ist unvereinbar mit fail-open. Der Shipper ist ein Konstruktorargument des
        // EventFlusher, und der wird vom FlushListener in kernel.terminate angefordert. Eine
        // Verbindungs-Exception entstünde dort also, WÄHREND der Container den Listener baut
        // — also außerhalb des try/catch im Flusher und damit unmittelbar in der überwachten
        // Anwendung. Genau der Fall, den Konzept 4. ausschließt.
        //
        // Mit lazy: true fällt der Verbindungsversuch auf den ersten send()-Aufruf, und der
        // liegt im abgesicherten Pfad. Solange der Versand über einen Message-Bus lief, war
        // das zufällig richtig — der Bus erzeugte den Transport erst beim Dispatch. Diese
        // Absicherung war also nie beabsichtigt, sondern ein Nebeneffekt.
        'lazy' => true,

        // \Redis::SERIALIZER_NONE. Als Zahl, weil das Bundle ohne ext-redis
        // installierbar bleiben muss und eine Klassenkonstante die Erweiterung schon
        // beim Bauen des Containers verlangen würde.
        //
        // MUSS auf 0 stehen. Symfonys Redis-Transport verwendet als Vorgabe
        // \Redis::SERIALIZER_PHP; phpredis serialisiert den Wert dann auf
        // Verbindungsebene, und im Stream landet `s:956:"{"body":…"` statt reinem
        // JSON. Zwei Folgen, beide unerwünscht:
        //
        //  - Der Collector müsste unserialize() aufrufen — also genau der Pfad, den
        //    der eigene JSON-Serializer vermeidet (siehe MessageSerializer).
        //  - Die Paketgrenze wäre nicht mehr das Format aus Konzept Abschnitt 3: ein
        //    Leser ohne PHP könnte den Stream nicht auswerten.
        'serializer' => 0,
    ];

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

        $transport = self::rawTransportConfig($builder);

        if (null === $transport['dsn'] || '' === $transport['dsn']) {
            // Ohne DSN bleibt es beim NullShipper. Das Bundle ist damit
            // installierbar, ohne dass ein Broker existiert — nützlich, um das
            // Erfassungsbudget zu messen, bevor Infrastruktur bereitsteht.
            return;
        }

        $messenger = [];

        // NUR `transports`. Ausdrücklich KEIN `buses` und KEIN `routing`.
        //
        // Ein eigener Bus wäre die naheliegende Lösung und war es auch — bis auffiel, dass
        // sie die überwachte Anwendung beschädigt: sobald das Bundle einen Wert für `buses`
        // beisteuert, greift Symfonys Vorgabe `messenger.bus.default` nicht mehr. Bei einer
        // Anwendung ohne ausdrückliche Buses blieb dann nur noch der sendende Bus des
        // Sensors übrig und wurde ihr Standard-Bus; bei einer Anwendung MIT eigenem Bus
        // brach die Kompilierung ab („You must specify the default_bus").
        //
        // Der Shipper spricht deshalb den Transport direkt an. Routing wird damit ebenfalls
        // überflüssig — es ordnet Nachrichtenklassen einem Transport zu, und den kennt der
        // Shipper bereits.
        if ($transport['register_transport']) {
            $messenger['transports'] = [
                $transport['name'] => [
                    'dsn' => $transport['dsn'],
                    'serializer' => self::SERIALIZER_ID,
                    'options' => array_merge(self::TRANSPORT_DEFAULTS, $transport['options']),
                    // Wirkt nur auf Consumer-Seite. Ausdrücklich gesetzt, damit nicht
                    // der Eindruck entsteht, der Sensor würde erneut versuchen — er
                    // spoolt stattdessen.
                    'retry_strategy' => ['max_retries' => 0],
                ],
            ];
        }

        if ([] !== $messenger) {
            $container->extension('framework', ['messenger' => $messenger], true);
        }
    }

    /**
     * Liest die Transportangaben aus der noch unverarbeiteten Konfiguration.
     *
     * @return array{name: string, dsn: string|null, options: array<string, mixed>, register_transport: bool}
     */
    private static function rawTransportConfig(ContainerBuilder $builder): array
    {
        $resolved = [
            'name' => 'ids_events',
            'dsn' => null,
            'options' => [],
            'register_transport' => true,
        ];

        foreach ($builder->getExtensionConfig('ids_sensor') as $config) {
            if (!\is_array($config) || !isset($config['transport']) || !\is_array($config['transport'])) {
                continue;
            }

            foreach (array_keys($resolved) as $key) {
                if (\array_key_exists($key, $config['transport'])) {
                    $resolved[$key] = $config['transport'][$key];
                }
            }
        }

        // %env()%-Platzhalter werden erst später aufgelöst; als Zeichenkette
        // durchreichen genügt hier.
        return [
            'name' => \is_string($resolved['name']) && '' !== $resolved['name'] ? $resolved['name'] : 'ids_events',
            'dsn' => \is_string($resolved['dsn']) ? $resolved['dsn'] : null,
            'options' => \is_array($resolved['options']) ? $resolved['options'] : [],
            'register_transport' => false !== $resolved['register_transport'],
        ];
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

        $this->assertSessionHashKeyIsUsable($config, $builder);

        $builder->setParameter('ids_sensor.enabled', true);
        $builder->setParameter('ids_sensor.application_id', $config['application_id']);
        $builder->setParameter('ids_sensor.instance_id', $config['instance_id']);
        $builder->setParameter('ids_sensor.environment', $config['environment']);

        // Die Anwendungskonfiguration wird über die Vorgaben GEMISCHT, nicht
        // dagegen ausgetauscht. Ein prototypisierter Array-Knoten ersetzt seinen
        // defaultValue vollständig, sobald irgendein Eintrag gesetzt ist — wer also
        // nur "abnahme" ergänzen will, verlöre sonst stillschweigend die Abbildung
        // von "test", "production" und allen anderen Vorgaben. Einzelne Vorgaben
        // lassen sich weiterhin überschreiben, indem derselbe Schlüssel neu belegt
        // wird.
        $builder->setParameter('ids_sensor.environment_map', array_merge(
            EnvironmentResolver::DEFAULT_MAP,
            $config['environment_map'],
        ));
        $builder->setParameter('ids_sensor.environment_fallback', $config['environment_fallback']);
        $builder->setParameter('ids_sensor.schema_version', EventFormat\Event\EventSchema::SCHEMA_VERSION);

        // Flache Parameter für die Werte, die die Verdrahtung braucht. Ein
        // verschachteltes Array als einzelner Parameter wäre in der YAML-Verdrahtung nicht
        // indexierbar — dort steht ein Parameter immer als ganzer Wert (%name%), nie ein
        // einzelner Schlüssel daraus.
        $builder->setParameter('ids_sensor.budget.capture_us', $config['budget']['capture_us']);
        $builder->setParameter('ids_sensor.budget.max_events_per_request', $config['budget']['max_events_per_request']);
        $builder->setParameter('ids_sensor.telemetry.latency_histogram', $config['telemetry']['latency_histogram']);

        $builder->setParameter('ids_sensor.session_hash.enabled', $config['session_hash']['enabled']);
        $builder->setParameter('ids_sensor.session_hash.key', $config['session_hash']['key']);
        $builder->setParameter('ids_sensor.session_hash.cookie_name', $config['session_hash']['cookie_name']);
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
        $builder->setParameter('ids_sensor.layers.security.active', $securityLayer['enabled'] && $securityAvailable);

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

        // Ohne DSN bleibt der NullShipper aus services.yaml stehen.
        $builder->setParameter('ids_sensor.transport.name', $config['transport']['name']);

        $spool = $config['spool'];
        $builder->setParameter(
            'ids_sensor.spool.dir',
            $spool['dir'] ?? '%kernel.project_dir%/var/ids-spool',
        );
        $builder->setParameter('ids_sensor.spool.max_bytes', $spool['max_bytes']);
        $builder->setParameter('ids_sensor.spool.max_file_bytes', $spool['max_file_bytes']);
        $builder->setParameter('ids_sensor.spool.drain_max_files_per_run', $spool['drain_max_files_per_run']);
        $builder->setParameter('ids_sensor.spool.drain', $spool['drain']);
        // Reiner Dokumentationswert: der Sensor kann nicht wissen, wie oft der cron
        // tatsächlich läuft. Er meldet ihn im Heartbeat weiter, damit collectorseitig
        // bekannt ist, welche Verzögerung für diese Instanz normal ist.
        $builder->setParameter('ids_sensor.spool.drain_interval_s', $spool['drain_interval_s']);

        $this->loadHeartbeat($config, $container, $builder);

        $sampling = $config['sampling'];
        $builder->setParameter('ids_sensor.sampling.info_rate', $sampling['info_rate']);
        $builder->setParameter('ids_sensor.sampling.keep_if_request_relevant', $sampling['keep_if_request_relevant']);

        $flush = $config['flush'];
        $builder->setParameter('ids_sensor.flush.policy', $flush['policy']);
        $builder->setParameter('ids_sensor.flush.max_frame_bytes', $flush['max_frame_bytes']);

        $breaker = $config['circuit_breaker'];
        $builder->setParameter('ids_sensor.circuit_breaker.enabled', $breaker['enabled']);
        $builder->setParameter('ids_sensor.circuit_breaker.failure_threshold', $breaker['failure_threshold']);
        $builder->setParameter('ids_sensor.circuit_breaker.open_for_s', $breaker['open_for_s']);

        $container->import('../config/services_resilience.yaml');

        if (null !== $config['transport']['dsn'] && '' !== $config['transport']['dsn']) {
            // Der Alias auf den tatsächlichen Transport-Service. Sein Name ist
            // konfigurierbar, eine statische YAML-Datei kann ihn deshalb nicht nennen.
            $builder->setAlias(self::TRANSPORT_ID, 'messenger.transport.'.$config['transport']['name']);

            $container->import('../config/services_transport.yaml');
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
     * Drosselung verhindert doppelte Meldungen. Dass der cron NOCH fehlt, ist am Payload
     * ablesbar — `triggered_by` steht dann dauerhaft auf `request`.
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
        /** @var array{enabled: bool, severities: list<string>, max_bytes: int, include_request_body: bool, skip_multipart: bool} $raw */
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

    /**
     * Ein fehlender HMAC-Schlüssel bricht die Container-Kompilierung ab — bewusst
     * hart, obwohl das Bundle sonst fail-open ist.
     *
     * Begründung: fail-open gilt für den Request-Pfad einer laufenden Anwendung.
     * Ein fehlender Schlüssel ist dagegen ein Deployment-Fehler, und ein stilles
     * `null` in actor.session_id_hash würde die sitzungsbezogenen Regeln B8/B9
     * unsichtbar abschalten. Ein sichtbarer Abbruch beim Deploy ist einem
     * lautlosen Erkennungsausfall im Betrieb vorzuziehen.
     *
     * Wer bewusst ohne Sitzungsverkettung arbeiten will, setzt
     * `session_hash.enabled: false`.
     *
     * @param array<string, mixed> $config
     */
    private function assertSessionHashKeyIsUsable(array $config, ContainerBuilder $builder): void
    {
        /** @var array{enabled: bool, key: string|null, min_key_length: int} $sessionHash */
        $sessionHash = $config['session_hash'];

        if (false === $sessionHash['enabled']) {
            return;
        }

        if (null === $sessionHash['key'] || '' === $sessionHash['key']) {
            throw new InvalidConfigurationException(<<<'TXT'
                ids_sensor.session_hash.key ist erforderlich, solange session_hash.enabled true ist.

                Der Schlüssel muss ein eigener IDS-Schlüssel sein und ausdrücklich NICHT APP_SECRET
                (Konzept 2.2.4 — Bildung der Sitzungskontext-Felder).

                Empfehlung:
                    ids_sensor:
                        session_hash:
                            key: '%env(IDS_SESSION_HASH_KEY)%'

                Wer bewusst ohne Sitzungsverkettung arbeiten will, setzt session_hash.enabled: false.
                Dann bleibt actor.session_id_hash immer null und die sitzungsbezogenen Regeln B8/B9
                sind wirkungslos.
                TXT);
        }

        // Nur bei literalen Werten prüfbar. Steckt in beiden ein %env()%-Platzhalter,
        // übernimmt ids:sensor:setup-check die Prüfung nach der Auflösung.
        if (!str_contains($sessionHash['key'], '%env(') && $builder->hasParameter('kernel.secret')) {
            $appSecret = $builder->getParameter('kernel.secret');

            if (\is_string($appSecret) && '' !== $appSecret && $appSecret === $sessionHash['key']) {
                throw new InvalidConfigurationException(<<<'TXT'
                    ids_sensor.session_hash.key ist identisch mit APP_SECRET. Das ist ausdrücklich
                    nicht zulässig (Konzept 2.2.4 — Bildung der Sitzungskontext-Felder).

                    Grund: Die überwachte Anwendung kennt APP_SECRET, ein Angreifer mit
                    Codeausführung also auch. Er könnte damit aus einer gestohlenen Event-Datenbank
                    die Session-Hashes nachrechnen und genau den Session-Hijacking-Vektor öffnen,
                    den das Hashen verhindern soll.

                    Bitte einen eigenen, unabhängigen Schlüssel verwenden.
                    TXT);
            }
        }
    }
}
