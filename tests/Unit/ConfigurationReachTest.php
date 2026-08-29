<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\DependencyInjection\ConfigurationTree;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

/**
 * Jede Konfigurationsoption muss irgendwo ankommen.
 *
 * WOZU
 *
 * Ein Code-Check hat 16 Optionen gefunden, die im Baum stehen, in
 * `doc/08-konfiguration.md` mit Wirkung dokumentiert sind — und die niemand liest.
 * Darunter `layers.kernel.capture_fatal_errors` („synthetisiert bei Fatal Errors ein
 * kernel.exception"), `session_hash.min_key_length` („Untergrenze der Prüfung") und
 * `budget.connect_timeout_ms`, dessen Vorgabe 20 zufällig identisch mit dem hartkodierten
 * Wert ist, der tatsächlich wirkt. Wer sie ändert, bekommt eine plausible Bestätigung
 * durch `debug:config` und keine Wirkung.
 *
 * `BundleBootTest::testTheRemovedSpoolSwitchIsRejectedInsteadOfIgnored()` beschreibt
 * genau diesen Fehler bereits wörtlich — für einen einzelnen, längst entfernten
 * Schalter: „Eine Konfiguration, die etwas verspricht und nichts bewirkt, ist
 * gefährlicher als eine, die es gar nicht gibt: Sie kann bestätigt und abgehakt werden."
 *
 * DIE SCHULDLISTEN SIND LEER — UND DESHALB WEG
 *
 * Solange die 16 Befunde abgearbeitet wurden, standen sie in zwei ausdrücklichen Listen
 * neben den begründeten Ausnahmen: jede Zeile mit der Zusage, die damals nicht galt.
 * Beide sind jetzt leer und ersatzlos entfallen. Ein leeres Schlupfloch lädt nur dazu
 * ein, es wieder zu füllen; wer künftig eine wirkungslose Option hinzufügt, bekommt
 * einen roten Test und muss entscheiden, statt eine Zeile nachzutragen.
 *
 * ZWEI RICHTUNGEN, ZWEI TESTS
 *
 * Die Kette ist zweigliedrig — Baum → Parameter → Dienst —, und sie kann an beiden
 * Gliedern reißen:
 *
 *  - Ein Knoten wird nie zu einem Parameter (`flush.batch`, `min_key_length`).
 *  - Ein Parameter wird gesetzt und von keinem Dienst gelesen (`flush.max_frame_bytes`,
 *    `spool.drain`).
 *
 * WARUM STATISCH UND NICHT AM CONTAINER
 *
 * Nach dem Kompilieren sind `%parameter%`-Verweise durch ihre Werte ersetzt; welcher
 * Dienst welchen Parameter liest, ist dann nicht mehr feststellbar. Ein Compiler-Pass vor
 * der Auflösung wäre möglich, brächte aber einen Kernel-Start für eine Frage, die im
 * Quelltext wörtlich beantwortbar ist. Die Parameternamen werden ausnahmslos als Literale
 * gesetzt und als Literale gelesen.
 *
 * @internal
 */
final class ConfigurationReachTest extends TestCase
{
    private const WURZEL = __DIR__.'/../..';

    private const PRAEFIX = 'ids_sensor.';

    /**
     * Knoten, die absichtlich keinen Container-Parameter bekommen — mit dem Grund.
     *
     * Diese Liste ist der eigentliche Ertrag des Tests: Sie zwingt dazu, für jeden
     * Knoten ohne Parameter eine Begründung hinzuschreiben. Wer eine Option hinzufügt und
     * vergisst, sie zu verdrahten, muss hier eintragen, warum — und merkt dabei, dass es
     * keinen Grund gibt.
     *
     * @var array<string, string>
     */
    private const OHNE_PARAMETER = [
        'session_hash' => 'Zwischenknoten ohne eigenen Wert.',
        'session_hash.min_key_length' => 'Wird in assertSessionHashKeyIsUsable() zur Compile-Zeit geprüft.',
        'enabled' => 'Der Kill-Schalter. Entscheidet in loadExtension(), ob überhaupt Dienste geladen werden.',
        'session_hash.enabled' => 'Entscheidet in loadExtension() über den Import von services_kernel.yaml.',
        // `session_hash.key` stand hier mit der Begründung, ein Parameter machte den
        // HMAC-Schlüssel per debug:container einsehbar. Die Begründung beschrieb einen
        // Zustand, den es nicht gab: `ids_sensor.session_hash.key` IST ein Parameter und
        // wird von services_kernel.yaml gelesen. Ein Eintrag, der eine Prüfung mit einer
        // falschen Begründung überspringt, ist genau die Sorte Schlupfloch, gegen die
        // dieser Test gebaut wurde — deshalb ist er entfallen, nicht korrigiert.
        'layers' => 'Zwischenknoten ohne eigenen Wert.',
        'layers.kernel' => 'Zwischenknoten.',
        'layers.kernel.events' => 'Zwischenknoten.',
        'layers.kernel.enabled' => 'Entscheidet in loadExtension() über den Import von services_kernel.yaml.',
        'layers.security' => 'Zwischenknoten.',
        'layers.security.enabled' => 'Wird mit der Verfügbarkeit des SecurityBundle zu ids_sensor.layers.security.active verrechnet.',
        'layers.business' => 'Zwischenknoten.',
        'layers.business.enabled' => 'Entscheidet in loadExtension() über den Import von services_business.yaml.',
        'raw' => 'Zwischenknoten.',
        'payload_confidentiality_cleanup' => 'Zwischenknoten.',
        'payload_confidentiality_cleanup.merge_defaults' => 'Wird beim Laden der Redaktionsliste zur Compile-Zeit ausgewertet.',
        'sampling' => 'Zwischenknoten.',
        'budget' => 'Zwischenknoten.',
        'flush' => 'Zwischenknoten.',
        'collector' => 'Zwischenknoten ohne eigenen Wert.',
        'collector.base_uri' => 'Entscheidet in loadExtension() zusätzlich über den Import von services_transport.yaml.',
        'spool' => 'Zwischenknoten.',
        'circuit_breaker' => 'Zwischenknoten.',
        'heartbeat' => 'Zwischenknoten.',
        'heartbeat.enabled' => 'Wird mit dem Modus zu ids_sensor.heartbeat.enabled verrechnet und entscheidet über den Import.',
        'telemetry' => 'Zwischenknoten.',
        'logging' => 'Zwischenknoten.',
        'correlation' => 'Zwischenknoten.',
        'fingerprint' => 'Zwischenknoten.',
        'correlation.incoming_header' => 'Wird als ids_sensor.correlation.inbound_header gesetzt — der Knoten heißt anders als der Parameter.',
        'layers.security.authentication' => 'Entscheidet in loadExtension() über den Import von services_security_auth.yaml.',
        'layers.security.access_decision' => 'Entscheidet in loadExtension() über den Import von services_access_decision.yaml.',
        'payload_confidentiality_cleanup.config' => 'Die Redaktionsliste wird zur Compile-Zeit geladen; der Pfad selbst reist nicht mit.',
    ];

    /**
     * Parameter, die absichtlich von keiner Dienstdefinition gelesen werden.
     *
     * @var array<string, string>
     */
    private const OHNE_LESER = [
        'ids_sensor.enabled' => 'Der Kill-Schalter: entscheidet in loadExtension(), ob überhaupt Dienste geladen werden; reist zusätzlich im Heartbeat mit.',
        'ids_sensor.config' => 'Die vollständige Konfiguration für ids:sensor:setup-check — wird als Ganzes injiziert.',
        'ids_sensor.layers.business.capture_mode' => 'Liest der BusinessCaptureModePass zur Compile-Zeit.',
        'ids_sensor.layers.business.event_classes' => 'dito',
        'ids_sensor.layers.kernel.capture_fatal_errors' => 'Entscheidet in loadExtension() über den Import von services_kernel_fatal_errors.yaml; bleibt als Parameter für ids:sensor:setup-check ablesbar.',
        'ids_sensor.layers.kernel.enabled' => 'Reine Auskunft für ids:sensor:setup-check über %ids_sensor.config%.',
        'ids_sensor.layers.security.active' => 'Entscheidet zur Compile-Zeit über die Sicherheitsdienste.',
        'ids_sensor.heartbeat.enabled' => 'Entscheidet in loadExtension() über den Import von services_heartbeat.yaml.',
        'ids_sensor.transport.configured' => 'Wird als $deliveryConfigured an den SpoolFlushCommand gegeben — der Leser steht in services_resilience.yaml.',
        'ids_sensor.budget.connect_timeout_ms' => 'Fließt in loadExtension() als Messenger-Option timeout an den Transport.',
        'ids_sensor.budget.read_timeout_ms' => 'dito als read_timeout.',
        'ids_sensor.logging.channel' => 'Wird in loadExtension() an die monolog.logger-Tags gesetzt — Symfony löst Platzhalter in Tag-Attributen nicht auf.',
        'ids_sensor.schema_version' => 'Reine Auskunft: macht die Formatversion per debug:container sichtbar, ohne dass jemand das Paket öffnen muss.',
        'ids_sensor.session_hash.framework_cookie_name' => 'Zwischenablage zwischen prependExtension() und loadExtension() — dort fließt der Wert in ids_sensor.session_hash.cookie_name, und den liest services_kernel.yaml.',
    ];

    /**
     * Jeder Blattknoten des Baums wird zu einem Container-Parameter — oder ist begründet.
     */
    public function testEveryConfigKeyBecomesAParameter(): void
    {
        $gesetzt = self::gesetzteParameter();
        $geprueft = 0;
        $ohneWirkung = [];

        foreach (self::blattpfade() as $pfad) {
            if (\array_key_exists($pfad, self::OHNE_PARAMETER)) {
                continue;
            }

            ++$geprueft;

            if (!\in_array(self::PRAEFIX.$pfad, $gesetzt, true)) {
                $ohneWirkung[] = 'ids_sensor.'.$pfad;
            }
        }

        self::assertGreaterThan(30, $geprueft, 'Zu wenige Knoten geprüft — greift der Test noch?');

        // Alle auf einmal: Dieser Test ist zugleich die Arbeitsliste. Beim ersten
        // Verstoß abzubrechen hieße, sie in Einzelläufen zusammensuchen zu müssen.
        self::assertSame([], $ohneWirkung, \sprintf(
            'Diese Knoten stehen im Konfigurationsbaum, werden aber nie zu einem '
            ."Container-Parameter:\n  %s\n\nEntweder verdrahten, entfernen — oder mit Begründung "
            .'in ConfigurationReachTest::OHNE_PARAMETER eintragen. Eine Option, die etwas '
            .'verspricht und nichts bewirkt, ist gefährlicher als keine: Sie kann bestätigt '
            .'und abgehakt werden.',
            implode("\n  ", $ohneWirkung),
        ));
    }

    /**
     * Jeder gesetzte Parameter wird von mindestens einer Dienstdefinition gelesen.
     */
    public function testEveryParameterReachesAService(): void
    {
        $gelesen = self::geleseneParameter();
        $geprueft = 0;
        $ohneLeser = [];

        foreach (self::gesetzteParameter() as $parameter) {
            if (\array_key_exists($parameter, self::OHNE_LESER)) {
                continue;
            }

            ++$geprueft;

            if (!\in_array($parameter, $gelesen, true)) {
                $ohneLeser[] = $parameter;
            }
        }

        self::assertGreaterThan(20, $geprueft, 'Zu wenige Parameter geprüft — greift der Test noch?');

        self::assertSame([], $ohneLeser, \sprintf(
            "Diese Parameter werden gesetzt, aber von keiner Dienstdefinition gelesen:\n  %s\n\n"
            .'Der Wert erscheint in debug:config und in debug:container und wirkt trotzdem nicht. '
            .'Entweder verdrahten, entfernen — oder mit Begründung in '
            .'ConfigurationReachTest::OHNE_LESER eintragen.',
            implode("\n  ", $ohneLeser),
        ));
    }

    /**
     * Die Blattknoten des Baums — Zwischenknoten tragen selbst keinen Wert.
     *
     * Prototyp-Knoten (`arrayNode()->scalarPrototype()`) zählen als Blatt: Ihr Wert ist
     * die Liste, nicht deren Struktur.
     *
     * @return list<string>
     */
    private static function blattpfade(): array
    {
        $baum = new TreeBuilder('ids_sensor');
        ConfigurationTree::build($baum->getRootNode());

        $pfade = [];
        $sammeln = static function (ArrayNode $knoten, string $praefix) use (&$sammeln, &$pfade): void {
            foreach ($knoten->getChildren() as $name => $kind) {
                $pfad = '' === $praefix ? (string) $name : $praefix.'.'.$name;
                $pfade[] = $pfad;

                if ($kind instanceof ArrayNode && !$kind instanceof PrototypedArrayNode) {
                    $sammeln($kind, $pfad);
                }
            }
        };

        $wurzel = $baum->buildTree();
        \assert($wurzel instanceof ArrayNode);
        $sammeln($wurzel, '');

        return $pfade;
    }

    /**
     * Alle Parameternamen, die das Bundle setzt.
     *
     * @return list<string>
     */
    private static function gesetzteParameter(): array
    {
        $quelle = (string) file_get_contents(self::WURZEL.'/src/IdsSensorBundle.php');

        preg_match_all("/setParameter\(\s*'(".preg_quote(self::PRAEFIX, '/')."[^']+)'/", $quelle, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    /**
     * Alle Parameternamen, die in einer Dienstdefinition vorkommen.
     *
     * @return list<string>
     */
    private static function geleseneParameter(): array
    {
        $gelesen = [];

        foreach (glob(self::WURZEL.'/config/*.yaml') ?: [] as $datei) {
            preg_match_all(
                '/%('.preg_quote(self::PRAEFIX, '/').'[^%]+)%/',
                (string) file_get_contents($datei),
                $treffer,
            );

            foreach ($treffer[1] as $name) {
                $gelesen[$name] = true;
            }
        }

        return array_keys($gelesen);
    }
}
