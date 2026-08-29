<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\DependencyInjection;

use ProjektMotor\IdsEventData\Vocabulary\Severity;
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

    /** @var list<string> */
    public const HEARTBEAT_MODES = ['auto', 'request', 'command', 'off'];

    /** @var list<string> */
    public const SUB_REQUEST_MODES = ['none', 'exceptions_only', 'all'];

    /**
     * Die eigenen Befehle des Bundles, von der Konsolen-Erfassung ausgenommen.
     *
     * Die einzige nicht-leere Ausschlussvorgabe im ganzen Baum, und sie braucht ihre
     * Begründung: `ids:sensor:spool:flush` läuft laut Konzept 3.6 je Minute per cron.
     * Ohne den Ausschluss erzeugte er ein `console.command`, das der nächste Lauf
     * versendet, um dabei das nächste zu erzeugen — eine Spur, die ausschließlich die
     * eigene Maschinerie beschreibt und mit der cron-Frequenz wächst.
     *
     * Der Unterschied zu `ignored_paths`, wo eine Vorgabe ausdrücklich abgelehnt wird:
     * Dort ginge Signal über die überwachte ANWENDUNG verloren (`/_profiler` ist ein
     * Angriffsziel). Hier fällt Selbstbeobachtung weg. Dass der Sensor lebt, meldet der
     * Heartbeat (Konzept 3.4), und zwar billiger.
     *
     * @var list<string>
     */
    public const DEFAULT_IGNORED_COMMANDS = ['#^ids:sensor:#'];

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

                // Herkunftskennung: drei UUIDs, die der Collector beim Registrieren
                // vergibt. Alle drei sind Pflicht und collectorseitig NOT NULL
                // (Konzept 2.2.1 und 4.2.1 Tabellenschema).
                //
                // KEIN Prüfmuster hier, obwohl es UUIDs sind: Die Werte kommen
                // typischerweise als %env()%-Platzhalter, und die sind zum Zeitpunkt
                // der Validierung nicht aufgelöst. Geprüft wird zur Laufzeit in
                // SensorIdentity — protokollierend, nicht werfend — und hart im
                // Deploy über ids:sensor:setup-check.
                ->scalarNode('application_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('UUID der überwachten Anwendung, vom Collector vergeben.')
                ->end()
                ->scalarNode('environment_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info(
                        'UUID der Umgebung, vom Collector vergeben. Den Anzeigenamen führt das '
                        .'Anwendungsregister; er darf sich ändern, ohne dass hier etwas nachzuziehen ist.'
                    )
                ->end()
                ->scalarNode('sensor_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info(
                        'UUID dieser Installation, vom Collector vergeben. JE NODE VERSCHIEDEN — '
                        .'teilen sich Replikate eine Kennung, etwa über eine gemeinsame ConfigMap, '
                        .'sind sie ununterscheidbar, und ids.sensor_silent schweigt beim Ausfall '
                        .'einzelner (Konzept 2.3).'
                    )
                ->end()

                ->append(self::sessionHashNode())
                ->append(self::fingerprintNode())
                ->append(self::correlationNode())
                ->append(self::layersNode())
                ->append(self::rawNode())
                ->append(self::payloadConfidentialityCleanupNode())
                ->append(self::budgetNode())
                ->append(self::flushNode())
                ->append(self::collectorNode())
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
            ->info('SHA-256 der Session-ID. Die Session-ID selbst wird niemals übertragen.')
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
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

    /**
     * Die Konsolen-Erfassung — Konzept 3.1.1, offener Punkt E1.
     *
     * Hängt unter `layers.kernel`, weil die Ereignisse auf der Kernel-EBENE liegen:
     * `Vocabulary\Layer` ist ein geschlossenes Vokabular, ein vierter Wert wäre ein
     * Fassungswechsel. Ein eigener Knoten und nicht zwei weitere Schalter unter
     * `events`, weil die Ausschlussliste dazugehört und `events` nur Wahrheitswerte
     * führt.
     */
    private static function consoleNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('console');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info(
                        'Erfasst console.command und console.error. Deckt auch Messenger-Worker ab — '
                        .'messenger:consume ist ein Command.'
                    )
                ->end()
                ->arrayNode('ignored_commands')
                    ->scalarPrototype()
                        ->validate()
                            // Dieselbe Falle wie bei ignored_paths: ein Muster ohne
                            // Trennzeichen kompiliert nicht und trifft deshalb nie.
                            ->ifTrue(static fn (mixed $pattern): bool => !\is_string($pattern) || false === @preg_match($pattern, ''))
                            ->thenInvalid(
                                'Ungültiger regulärer Ausdruck %s. ignored_commands sind PCRE-Muster MIT '
                                .'Trennzeichen — also "#^app:cron:#" statt "app:cron:".'
                            )
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_IGNORED_COMMANDS)
                    ->info(
                        'PCRE-Muster MIT Trennzeichen gegen den Befehlsnamen. Anders als ignored_paths '
                        .'NICHT leer: die Vorgabe schließt die eigenen Befehle des Bundles aus. '
                        .'ids:sensor:spool:flush läuft je Minute per cron und erzeugte sonst ein '
                        .'Ereignis, das der nächste Lauf versendet, um dabei das nächste zu erzeugen.'
                    )
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
                        ->append(self::consoleNode())
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
                            ->info(
                                'false erfasst nur Denials — halbiert das Volumen. Kostet keine Regel des '
                                .'Konzepts: P1/P2 lesen kernel.response mit 200, P3 Business-Events. Kostet '
                                .'aber die Historie, auf die der offene Punkt E6 später zurückgreifen soll.'
                            )
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

    /**
     * Die Ausnahmeliste, die `raw` auch an `info`-Events hängt — Konzept 4.5.2,
     * offener Punkt OB11.
     *
     * Die Stufenregel allein erzeugte eine Lücke: Ob `raw` mitreist, hängt an
     * `event_severity`, ein Alarm entsteht aber erst im Collector und kann nicht
     * zurückwirken. Ein Befund wie R2b („Pfadlisten-Treffer mit Status 200") stand
     * damit ohne forensischen Beleg da.
     *
     * Der Sensor kennt die Erkennungsregeln des Collectors nicht und soll sie nicht
     * kennen (Konzept 2.). Deshalb entscheidet hier der BETREIBER, welche Fälle er als
     * Kandidaten mitschicken will; der Collector filtert anschließend weiter. Leer als
     * Vorgabe — wer nichts einstellt, bekommt das bisherige Verhalten.
     */
    private static function rawAlwaysForNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('always_for');
        $node
            ->addDefaultsIfNotSet()
            ->info(
                'Hängt raw auch an info-Events. ACHTUNG: raw macht über 95 % des Datenvolumens aus, '
                .'und info ist die Masse aller Events — die Liste ist für einzelne, benannte Fälle '
                .'gedacht. Wer sie weit fasst, hebt das Volumenbudget um Größenordnungen.'
            )
            ->children()
                ->arrayNode('event_types')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('event_type-Werte, etwa "kernel.response". Genaue Übereinstimmung, kein Muster.')
                ->end()
                ->arrayNode('path_patterns')
                    ->scalarPrototype()
                        ->validate()
                            // Dieselbe Falle wie bei ignored_paths und ignored_commands:
                            // ein Muster ohne Trennzeichen kompiliert nicht und trifft
                            // deshalb nie. Hier wäre der Schaden der stillste von allen —
                            // der Betreiber glaubte, einen Beleg zu sichern, und bekäme
                            // keinen.
                            ->ifTrue(static fn (mixed $pattern): bool => !\is_string($pattern) || false === @preg_match($pattern, ''))
                            ->thenInvalid(
                                'Ungültiger regulärer Ausdruck %s. path_patterns sind PCRE-Muster MIT '
                                .'Trennzeichen — also "#^/_profiler#" statt "/_profiler".'
                            )
                        ->end()
                    ->end()
                    ->defaultValue([])
                    ->info('PCRE-Muster MIT Trennzeichen gegen payload.path — greift auf den Ebenen, die einen führen.')
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
                            // Fehler, keine Meldung, kein Zähler.
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
                    ->info(
                        'Der Body ist der sensibelste Teil und hat deshalb einen eigenen Schalter. '
                        .'Gilt für Formularfelder UND für den JSON-Körper.'
                    )
                ->end()
                ->integerNode('max_request_body_bytes')
                    ->defaultValue(32768)
                    ->min(0)
                    ->info(
                        'Obergrenze des JSON-Körpers, geprüft am Content-Length VOR dem Lesen. '
                        .'0 nimmt keinen Körper auf. SOLLTE KLEINER ALS max_bytes SEIN: sonst füllt '
                        .'ein großer Körper das ganze raw-Budget allein, und die Kappung wirft ihn '
                        .'anschließend wieder weg — gelesen, redigiert, verworfen. '
                        .'ids:sensor:setup-check meldet diesen Fall.'
                    )
                ->end()
                ->booleanNode('skip_multipart')
                    ->defaultTrue()
                    ->info('multipart/form-data wird nicht erfasst — Datei-Uploads würden den Frame sprengen.')
                ->end()
                ->append(self::rawAlwaysForNode())
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
                        'Versandbudget nach dem Absenden der Antwort. Wird als Frist ZWISCHEN zwei '
                        .'Sendungen geprüft — PHP kann einen laufenden Syscall nicht abbrechen.'
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

    /**
     * Die Verbindung zum Collector (Konzept 3.6).
     *
     * Hier stand bis schema_version 1 ein Messenger-Transport mit DSN und einer Liste
     * gesperrter Optionen. Beides ist entfallen: Es gibt keinen Broker mehr, dessen
     * Verbindungsaufbau man entschärfen müsste, und der HTTP-Client verbindet ohnehin
     * erst beim Senden.
     *
     * Ohne base_uri bleibt der NullShipper stehen. Das Bundle ist damit installierbar,
     * bevor ein Collector bereitsteht — nützlich, um das Erfassungsbudget aus Konzept
     * 2.1 zu messen, ohne dass Netzlatenz und Sensorkosten sich vermischen.
     */
    private static function collectorNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('collector');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('base_uri')
                    ->defaultNull()
                    ->info('Basisadresse des Collectors, z. B. https://ids.example. null lässt den NullShipper stehen.')
                ->end()
                ->scalarNode('username')
                    ->defaultNull()
                    ->info('Benutzername der Zugangsdaten, vom Collector vergeben.')
                ->end()
                ->scalarNode('password')
                    ->defaultNull()
                    ->info('Passwort der Zugangsdaten. Gehört in eine Umgebungsvariable, nicht in die Datei.')
                ->end()
                ->integerNode('token_leeway_s')
                    ->defaultValue(60)
                    ->min(0)
                    ->info(
                        'Vorlauf, mit dem das Zugangstoken vorausschauend erneuert wird. Erst auf ein '
                        .'401 zu erneuern wäre ein zweiter Roundtrip innerhalb des Versandbudgets aus '
                        .'Konzept 4 — genau das, was das Budget verhindern soll.'
                    )
                ->end()
                ->booleanNode('verify_tls')
                    ->defaultTrue()
                    ->info(
                        'Zertifikatsprüfung. false verwandelt eine authentifizierte Verbindung in eine, '
                        .'die jeder auf dem Weg übernehmen kann, und das fällt im Betrieb nicht auf — '
                        .'Konzept 4.5.3 verlangt die Prüfung.'
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
                    ->info(
                        'Der erwartete Takt des Drainers. Versiegelt die aktive Spool-Datei nach Ablauf, '
                        .'lässt ruhende Dateien fremder Prozesse adoptieren, ist die Schwelle für '
                        .'„Spool zu alt" im setup-check — und reist im Heartbeat mit, damit der Collector '
                        .'die normale Verzögerung kennt.'
                    )
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
                'Ohne Circuit Breaker kostet ein Collector-Ausfall jeden Request ein Timeout und erschöpft '
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
                    ->info('Der Drosselungsschlüssel enthält die sensor_id — sonst unterdrückt ein Sensor die Heartbeats aller anderen.')
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
