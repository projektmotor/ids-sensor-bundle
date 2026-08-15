<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\DependencyInjection;

use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Der Konfigurationsbaum von ids_sensor.
 *
 * Ausgelagert aus dem Bundle, damit er ohne Container getestet werden kann.
 *
 * Zwei Regeln, die sich aus dem Zusammenspiel von Config-Komponente und
 * Umgebungsvariablen ergeben und hier durchgängig angewandt werden:
 *
 * 1. KEIN enumNode() für Werte, die aus einer Umgebungsvariable kommen können.
 *    ValidateEnvPlaceholdersPass prüft jede Extension-Konfiguration mit
 *    Typ-Platzhaltern ('string' => ''), und EnumNode kennt keine
 *    Platzhalterbehandlung — die Prüfung läuft gegen '' und wirft. Stattdessen
 *    scalarNode() plus ->validate()->ifNotInArray(), was mit Platzhaltern
 *    verträglich ist.
 *
 * 2. Numerische Untergrenzen schließen 0 ein. Aus demselben Grund: der
 *    Typ-Platzhalter für int ist 0, und ->min(1) würde ihn zurückweisen. Die
 *    fachlich sinnvolle Untergrenze prüft der verbrauchende Service.
 *
 * ->cannotBeEmpty() ist dagegen platzhaltersicher: ScalarNode::isValueEmpty()
 * gibt bei Platzhaltern false zurück.
 *
 * @internal
 */
final class ConfigurationTree
{
    /** @var list<string> */
    public const FLUSH_POLICIES = ['auto', 'direct', 'spool'];

    /** @var list<string> */
    public const CAPTURE_MODES = ['dispatcher', 'recorder', 'configured'];

    /**
     * Transport-Optionen, die die Anwendung nicht überschreiben darf.
     *
     * Jede von ihnen trägt eine Sicherheitsaussage — die Begründungen stehen bei
     * {@see \ProjektMotor\IdsSensor\IdsSensorBundle::TRANSPORT_DEFAULTS}.
     *
     * @var list<string>
     */
    public const PROTECTED_TRANSPORT_OPTIONS = ['auto_setup', 'lazy', 'serializer'];

    /** @var list<string> */
    public const HEARTBEAT_MODES = ['auto', 'request', 'command', 'off'];

    /** @var list<string> */
    public const SUB_REQUEST_MODES = ['none', 'exceptions_only', 'all'];

    /**
     * Die feste, dokumentierte Feldfolge aus Konzept 2.2.4 — Bildung der
     * Sitzungskontext-Felder. Bewusst schmal: je mehr Header einfließen, desto
     * häufiger ändert sich der Fingerprint aus harmlosen Gründen und desto mehr
     * Fehlalarme erzeugt die sitzungsbezogene Regel B9.
     *
     * @var list<string>
     */
    public const FINGERPRINT_HEADERS = ['User-Agent', 'Accept-Language', 'Accept-Encoding'];

    private function __construct()
    {
    }

    public static function build(ArrayNodeDefinition $root): void
    {
        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('false schaltet alle Sensoren ab, ohne das Bundle zu entfernen.')
                ->end()

                // Herkunftskennung. Alle drei sind Pflicht und collectorseitig NOT NULL
                // (Konzept 2.2.1 und 4.2.1 Tabellenschema).
                ->scalarNode('application_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Kennung der überwachten Anwendung, z. B. "shop-api".')
                ->end()
                ->scalarNode('instance_id')
                    ->defaultNull()
                    ->info('Kennung des Hosts/Containers. null ermittelt sie zur Laufzeit aus dem Hostnamen.')
                ->end()
                ->scalarNode('environment')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Rohwert der Umgebung. Wird über environment_map auf prod|staging|dev abgebildet.')
                ->end()
                ->arrayNode('environment_map')
                    ->useAttributeAsKey('name')
                    ->prototype('scalar')->end()
                    ->defaultValue(EnvironmentResolver::DEFAULT_MAP)
                    ->info(
                        'Abbildung beliebiger Umgebungsnamen auf prod|staging|dev. Eigene Einträge '
                        .'werden über die hier gezeigten Vorgaben GEMISCHT, nicht dagegen ausgetauscht — '
                        .'wer nur einen Namen ergänzt, behält alle Vorgaben. Einzelne Vorgaben lassen '
                        .'sich überschreiben, indem derselbe Schlüssel neu belegt wird.'
                    )
                ->end()
                ->scalarNode('environment_fallback')
                    ->defaultValue(Environment::Prod->value)
                    ->validate()
                        ->ifNotInArray(self::enumValues(Environment::class))
                        ->thenInvalid('Ungültige Umgebung %s. Erlaubt: prod, staging, dev.')
                    ->end()
                    ->info('Wird verwendet, wenn environment nicht abbildbar ist.')
                ->end()

                ->append(self::sessionHashNode())
                ->append(self::fingerprintNode())
                ->append(self::correlationNode())
                ->append(self::layersNode())
                ->append(self::rawNode())
                ->append(self::payloadConfidentialityCleanupNode())
                ->append(self::samplingNode())
                ->append(self::budgetNode())
                ->append(self::flushNode())
                ->append(self::transportNode())
                ->append(self::spoolNode())
                ->append(self::circuitBreakerNode())
                ->append(self::heartbeatNode())
                ->append(self::telemetryNode())
                ->append(self::loggingNode())
            ->end();
    }

    private static function sessionHashNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('session_hash');
        $node
            ->addDefaultsIfNotSet()
            ->info('HMAC der Session-ID. Die Session-ID selbst wird niemals übertragen.')
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('key')
                    ->defaultNull()
                    ->cannotBeEmpty()
                    ->info(
                        'Dedizierter IDS-Schlüssel. Laut Konzept 2.2.4 ausdrücklich NICHT APP_SECRET: '
                        .'die überwachte Anwendung kennt APP_SECRET, könnte also aus einer gestohlenen '
                        .'Event-Datenbank die Session-Hashes nachrechnen.'
                    )
                ->end()
                ->integerNode('min_key_length')->defaultValue(32)->min(0)->end()
                ->scalarNode('cookie_name')
                    ->defaultNull()
                    ->info('Name des Session-Cookies. null ermittelt ihn aus der Framework-Konfiguration.')
                ->end()
            ->end();

        return $node;
    }

    private static function fingerprintNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('fingerprint');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('headers')
                    ->scalarPrototype()->end()
                    ->defaultValue(self::FINGERPRINT_HEADERS)
                    ->info(
                        'Feste Feldfolge laut Konzept 2.2.4. Eine Änderung ändert JEDEN Fingerprint '
                        .'und macht die sitzungsbezogene Regel B9 für die Übergangszeit blind.'
                    )
                ->end()
            ->end();

        return $node;
    }

    private static function correlationNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('correlation');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('trust_incoming_header')
                    ->defaultFalse()
                    ->info(
                        'Standardmäßig aus: ein eingehender Request-ID-Header ist angreifergesteuert, '
                        .'solange kein Reverse-Proxy ihn überschreibt. Ein Angreifer könnte damit die '
                        .'correlation_id eines Opfers übernehmen und die forensische Zuordnung vergiften.'
                    )
                ->end()
                ->scalarNode('incoming_header')->defaultValue('X-Request-Id')->end()
                ->booleanNode('require_trusted_proxy')
                    ->defaultTrue()
                    ->info('Übernimmt den Header nur, wenn der Request von einem konfigurierten Trusted Proxy kommt.')
                ->end()
                ->booleanNode('expose_request_attribute')
                    ->defaultTrue()
                    ->info('Legt die correlation_id als Request-Attribut ab, damit die Anwendung sie mitloggen kann.')
                ->end()
            ->end();

        return $node;
    }

    private static function layersNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('layers');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('kernel')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->arrayNode('events')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('request')->defaultTrue()->end()
                                ->booleanNode('response')->defaultTrue()->end()
                                ->booleanNode('exception')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('ignored_paths')
                            ->scalarPrototype()
                                ->validate()
                                    // Die Einträge sind PCRE-Ausdrücke MIT Trennzeichen,
                                    // und `@preg_match` verschluckte jede Warnung: `/health`
                                    // ohne Trennzeichen kompilierte anstandslos und traf
                                    // dann nie — der Betreiber glaubte, einen Pfad
                                    // ausgeschlossen zu haben, und bekam ihn weiter erfasst.
                                    // Umgekehrt genauso still: ein Ausdruck, der nach einem
                                    // Tippfehler ZU VIEL trifft, schaltet die Kernel-Ebene
                                    // stumm. Beides ist beim Kompilieren feststellbar.
                                    ->ifTrue(static fn (mixed $pattern): bool => !\is_string($pattern) || false === @preg_match($pattern, ''))
                                    ->thenInvalid(
                                        'Ungültiger regulärer Ausdruck %s. ignored_paths sind PCRE-Muster MIT '
                                        .'Trennzeichen — also "#^/health$#" statt "/health".'
                                    )
                                ->end()
                            ->end()
                            ->defaultValue([])
                            ->info(
                                'PCRE-Muster MIT Trennzeichen ("#^/health$#"). Absichtlich LEER: Regel R2b lebt '
                                .'davon, Zugriffe auf /_profiler zu sehen — ein gut gemeinter Default würde genau '
                                .'das Signal löschen.'
                            )
                        ->end()
                        ->scalarNode('sub_requests')
                            ->defaultValue('exceptions_only')
                            ->validate()
                                ->ifNotInArray(self::SUB_REQUEST_MODES)
                                ->thenInvalid('Ungültiger Wert %s. Erlaubt: none, exceptions_only, all.')
                            ->end()
                            ->info(
                                'Sub-Requests erzeugen standardmäßig nur Exception-Events: ihr Pfad ist meist '
                                .'eine Kopie des Elternpfades, was jede Schwellwertregel doppelt zählen ließe. '
                                .'Exceptions dagegen werden von ignore_errors verschluckt und wären sonst nirgends '
                                .'sichtbar. /_fragment über HTTP ist ein Main-Request und bleibt unberührt.'
                            )
                        ->end()
                        ->booleanNode('capture_fatal_errors')
                            ->defaultTrue()
                            ->info(
                                'Rettet den Puffer in den Spool, wenn der Prozess vor kernel.terminate '
                                .'stirbt. Synthetisiert KEIN Ereignis: Gerettet wird, was der Sensor '
                                .'tatsächlich gesehen hat.'
                            )
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->booleanNode('authentication')->defaultTrue()->end()
                        ->booleanNode('access_decision')
                            ->defaultTrue()
                            ->info(
                                'Dekoriert den AccessDecisionManager und feuert damit bei jedem isGranted(). '
                                .'Abgesichert durch Dedup identischer Entscheidungen und ein Hard-Cap pro Request.'
                            )
                        ->end()
                        ->booleanNode('capture_granted')
                            ->defaultTrue()
                            ->info('false erfasst nur Denials — halbiert das Volumen, kostet aber die Positivpfad-Regeln.')
                        ->end()
                        ->integerNode('max_decisions_per_request')->defaultValue(200)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('business')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('capture_mode')
                            ->defaultValue('dispatcher')
                            ->validate()
                                ->ifNotInArray(self::CAPTURE_MODES)
                                ->thenInvalid('Ungültiger Modus %s. Erlaubt: dispatcher, recorder, configured.')
                            ->end()
                            ->info(
                                'dispatcher: der Sensor hört am dekorierten event_dispatcher mit, die Fachlogik '
                                .'bleibt IDS-frei. recorder: die Anwendung ruft BusinessEventRecorderInterface '
                                .'explizit auf. configured: Listener werden aus event_classes registriert. '
                                .'Der im Konzept 2.1.3 genannte Weg über Interface-Tagging ist nicht umsetzbar — '
                                .'Symfonys EventDispatcher löst Listener über den exakten Event-Namen auf.'
                            )
                        ->end()
                        ->arrayNode('event_classes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Nur für capture_mode: configured — Liste der Event-FQCNs.')
                        ->end()
                        ->booleanNode('user_from_token')
                            ->defaultTrue()
                            ->info('Ergänzt actor.user aus dem Security-Token, wenn getActorId() null liefert.')
                        ->end()
                        ->booleanNode('ip_from_request')
                            ->defaultTrue()
                            ->info(
                                'Ergänzt actor.ip aus dem laufenden Request. Konzept 2.2.4 sieht null vor; '
                                .'das ist für den Worker-Fall gedacht. Im Request-Fall würde das Unterdrücken '
                                .'einer vorhandenen IP die Korrelationsregel X3 unnötig schwächen.'
                            )
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private static function rawNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('raw');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('severities')
                    ->scalarPrototype()
                        ->validate()
                            // Ohne Prüfung schaltete ein Tippfehler `raw` LAUTLOS ab:
                            // `['warnings']` oder `['WARNING']` kompilierte anstandslos,
                            // und der Gate fand die Stufe nie in seiner Liste. Kein
                            // Fehler, keine Meldung, kein Zähler. Dieselbe Technik wie bei
                            // environment_fallback zwei Knoten weiter oben.
                            ->ifNotInArray(self::enumValues(Severity::class))
                            ->thenInvalid('Ungültige Stufe %s. Erlaubt: info, warning, critical.')
                        ->end()
                    ->end()
                    ->defaultValue([Severity::Warning->value, Severity::Critical->value])
                    ->info('Konzept Abschnitt 3: raw nur für warning und critical.')
                ->end()
                ->integerNode('max_bytes')->defaultValue(32768)->min(0)->end()
                ->booleanNode('include_request_body')
                    ->defaultTrue()
                    ->info('Der Body ist der sensibelste Teil und hat deshalb einen eigenen Schalter.')
                ->end()
                ->booleanNode('skip_multipart')
                    ->defaultTrue()
                    ->info('multipart/form-data wird nicht erfasst — Datei-Uploads würden den Frame sprengen.')
                ->end()
            ->end();

        return $node;
    }

    private static function payloadConfidentialityCleanupNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('payload_confidentiality_cleanup');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('config')
                    ->defaultNull()
                    ->info(
                        'Pfad zur versionierten Redaktionsliste. null nutzt die mitgelieferte Vorgabe. '
                        .'Die Datei wird zur Compile-Zeit gelesen — ein Parsen pro Request würde das '
                        .'Latenzbudget aus Konzept 2.1 verletzen.'
                    )
                ->end()
                ->booleanNode('merge_defaults')
                    ->defaultTrue()
                    ->info('false ersetzt die mitgelieferte Liste vollständig (nötig, um sie zu verkleinern).')
                ->end()
                ->scalarNode('replacement')->defaultValue('[confidential]')->cannotBeEmpty()->end()
            ->end();

        return $node;
    }

    private static function samplingNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('sampling');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->floatNode('info_rate')
                    ->defaultValue(1.0)
                    ->min(0.0)
                    ->max(1.0)
                    ->info(
                        'Gilt ausschließlich für layer=kernel UND severity=info. Security- und '
                        .'Business-Events werden nie gesampelt (Konzept 4.2.3).'
                    )
                ->end()
                ->booleanNode('keep_if_request_relevant')
                    ->defaultTrue()
                    ->info(
                        'Behält die info-Events eines Requests, der irgendein warning/critical enthält. '
                        .'Sonst fehlt bei einem 500er gerade der zugehörige Request-Kontext.'
                    )
                ->end()
            ->end();

        return $node;
    }

    private static function budgetNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('budget');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('capture_us')
                    ->defaultValue(1500)
                    ->min(0)
                    ->info('Erfassungsbudget im Request. 0 bedeutet unbegrenzt (CLI/Worker).')
                ->end()
                ->integerNode('dispatch_ms')
                    ->defaultValue(50)
                    ->min(0)
                    ->info(
                        'Versandbudget nach dem Absenden der Antwort. Wird als Frist ZWISCHEN '
                        .'Broker-Operationen geprüft — PHP kann einen laufenden Syscall nicht abbrechen.'
                    )
                ->end()
                ->integerNode('connect_timeout_ms')->defaultValue(20)->min(0)->end()
                ->integerNode('read_timeout_ms')->defaultValue(30)->min(0)->end()
                ->integerNode('fatal_dispatch_ms')->defaultValue(15)->min(0)->end()
                ->integerNode('max_events_per_request')->defaultValue(64)->min(0)->end()
            ->end();

        return $node;
    }

    private static function flushNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('flush');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('policy')
                    ->defaultValue('auto')
                    ->validate()
                        ->ifNotInArray(self::FLUSH_POLICIES)
                        ->thenInvalid('Ungültige Policy %s. Erlaubt: auto, direct, spool.')
                    ->end()
                    ->info(
                        'auto erkennt, ob die Antwort abkoppelbar ist: PHP-FPM, LiteSpeed, FrankenPHP und '
                        .'RoadRunner senden direkt, mod_php schreibt in den Spool. Der Default ist auto, '
                        .'weil die Laufzeit eine Eigenschaft des Servers ist und nicht der Anwendung.'
                    )
                ->end()
                ->integerNode('max_frame_bytes')->defaultValue(262144)->min(0)->end()
            ->end();

        return $node;
    }

    private static function transportNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('transport');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('name')->defaultValue('ids_events')->cannotBeEmpty()->end()
                ->scalarNode('dsn')
                    ->defaultNull()
                    ->info('null bedeutet: die Anwendung konfiguriert den Messenger-Transport selbst.')
                ->end()
                ->booleanNode('register_transport')
                    ->defaultTrue()
                    ->info(
                        'false überlässt die Registrierung des Transports der Anwendung. Der Sensor '
                        .'erwartet ihn dann unter transport.name. Ein Routing braucht er nicht — er '
                        .'spricht den Transport unmittelbar an, nicht über einen Bus.'
                    )
                ->end()
                ->arrayNode('options')
                    ->useAttributeAsKey('name')
                    ->prototype('variable')->end()
                    ->defaultValue([])
                    ->validate()
                        // Drei Optionen dürfen NICHT überschrieben werden, und die
                        // Begründung steht wörtlich in IdsSensorBundle::TRANSPORT_DEFAULTS:
                        //
                        //  - auto_setup: false — sonst XGROUP CREATE gegen XADD-only-Rechte
                        //  - lazy: true — sonst öffnet Connection::__construct() die
                        //    Verbindung beim BAUEN des Dienstes, „unvereinbar mit fail-open"
                        //  - serializer: 0 — sonst landet PHP-serialisiertes statt reines
                        //    JSON im Stream
                        //
                        // Bis hierher gewannen die Optionen der Anwendung, und die Doku bat
                        // nur darum, auto_setup false zu lassen. Eine Bitte ist keine
                        // Schranke; CLAUDE.md §2.2 verlangt Fail Fast.
                        ->ifTrue(static fn (array $options): bool => [] !== array_intersect(
                            array_keys($options),
                            self::PROTECTED_TRANSPORT_OPTIONS,
                        ))
                        ->thenInvalid(
                            'ids_sensor.transport.options darf auto_setup, lazy und serializer nicht '
                            .'überschreiben (%s). auto_setup: true sendet XGROUP CREATE, was die '
                            .'XADD-only-Rechte aus Konzept 2. ablehnen — in der Entwicklung unauffällig, '
                            .'beim ersten Prod-Versand ein Fehler. lazy: false öffnet die Verbindung '
                            .'beim Bauen des Dienstes, also außerhalb jedes try/catch des Sensors, und '
                            .'bricht damit fail-open. serializer ungleich 0 legt PHP-serialisierte Daten '
                            .'in den Beweisspeicher statt reines JSON.'
                        )
                    ->end()
                    ->info(
                        'Wird über die sicheren Vorgaben gemischt. auto_setup MUSS false bleiben: der '
                        .'Default sendet XGROUP CREATE, was die XADD-only-Rechte aus Konzept 2. ablehnen — '
                        .'das funktioniert in Dev und scheitert beim ersten Prod-Versand.'
                    )
                ->end()
            ->end();

        return $node;
    }

    private static function spoolNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('spool');
        $node
            ->addDefaultsIfNotSet()
            // KEIN enabled-Knoten. Der Spool ist kein Merkmal, sondern der Puffer, auf dem
            // die fail-open-Zusage aus Konzept 4 steht — und unter mod_php laut 3.3.1 der
            // EINZIGE Transportweg. Ein Schalter dafür hätte dort jede Erfassung lautlos
            // verworfen. Wer nicht drainen will, richtet den cron nicht ein — einen Schalter
            // dafür gab es, er bewirkte nie etwas und ist entfallen.
            ->children()
                ->scalarNode('dir')
                    ->defaultNull()
                    ->info(
                        'null nutzt %kernel.project_dir%/var/ids-spool. MUSS node-lokal sein — auf NFS '
                        .'oder einem geteilten Volume holt man sich genau den Netzwerkzugriff zurück, '
                        .'den der Spool aus dem Request entfernt. Für Container: /dev/shm/ids-spool.'
                    )
                ->end()
                ->integerNode('max_bytes')->defaultValue(16777216)->min(0)->end()
                ->integerNode('max_file_bytes')->defaultValue(4194304)->min(0)->end()
                ->integerNode('drain_interval_s')
                    ->defaultValue(30)
                    ->min(0)
                    ->info('Nur Dokumentationswert: reist im Heartbeat mit, damit der Collector die normale Verzögerung kennt.')
                ->end()
                ->integerNode('drain_max_files_per_run')->defaultValue(2)->min(0)->end()
                ->integerNode('stale_after_s')->defaultValue(300)->min(0)->end()
            ->end();

        return $node;
    }

    private static function circuitBreakerNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('circuit_breaker');
        $node
            ->addDefaultsIfNotSet()
            ->info(
                'Ohne Circuit Breaker kostet ein Broker-Ausfall jeden Request ein Timeout und erschöpft '
                .'den Worker-Pool — fail-open würde unter Last closed failen.'
            )
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('failure_threshold')->defaultValue(3)->min(0)->end()
                ->integerNode('open_for_s')->defaultValue(30)->min(0)->end()
            ->end();

        return $node;
    }

    private static function heartbeatNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('heartbeat');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('mode')
                    ->defaultValue('auto')
                    ->validate()
                        ->ifNotInArray(self::HEARTBEAT_MODES)
                        ->thenInvalid('Ungültiger Modus %s. Erlaubt: auto, request, command, off.')
                    ->end()
                    ->info(
                        'request funktioniert ohne Ops-Einrichtung, schweigt aber bei fehlendem Traffic. '
                        .'command braucht cron/systemd, ist dafür auch ohne Traffic verlässlich. '
                        .'Der Modus reist im Payload mit, damit der Collector die Aussagekraft kennt.'
                    )
                ->end()
                ->integerNode('interval_s')->defaultValue(60)->min(0)->end()
                ->scalarNode('stamp_file')
                    ->defaultNull()
                    ->info('Der Drosselungsschlüssel enthält die instance_id — sonst unterdrückt eine Instanz die Heartbeats aller anderen.')
                ->end()
            ->end();

        return $node;
    }

    private static function telemetryNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('telemetry');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('latency_histogram')
                    ->defaultTrue()
                    ->info('Macht die 5-ms-Zusage im laufenden Betrieb überprüfbar, nicht nur im Benchmark.')
                ->end()
            ->end();

        return $node;
    }

    private static function loggingNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('logging');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('channel')->defaultValue('ids_sensor')->cannotBeEmpty()->end()
            ->end();

        return $node;
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<string>
     */
    private static function enumValues(string $enum): array
    {
        return array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        );
    }
}
