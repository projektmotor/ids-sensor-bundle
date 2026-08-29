<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Event\EventSchema;
use ProjektMotor\IdsSensor\DependencyInjection\ConfigurationTree;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Hält die Dokumentation zusammen.
 *
 * WOZU
 *
 * Die Dokumentation ist zweistufig: ein README als Schaufenster und elf Dokumente
 * unter doc/, die aufeinander verweisen. Damit hat sie dasselbe Problem wie jeder
 * Verweis im Code — sie verfällt beim ersten Umbenennen, und niemand merkt es, weil
 * kein Test darüber läuft. Vier README-Verweise nach doc/ waren aus genau diesem
 * Grund bereits tot, und die mitgelieferte Denylist verwies auf zwei
 * Konfigurationsschlüssel, die es im ConfigurationTree nie gegeben hat.
 *
 * Geprüft wird deshalb dasselbe, was ArchitectureTest::testDocblockReferencesDoNotDangle()
 * für Docblocks prüft: dass ein Verweis auf etwas zeigt, das es gibt.
 *
 * NICHT geprüft wird der Inhalt. Ob ein Satz stimmt, weiß kein Test.
 *
 * @internal
 */
final class DocumentationTest extends TestCase
{
    private const WURZEL = __DIR__.'/../..';

    /**
     * Was in einem Ereignis-Beispiel überhaupt vorkommen darf.
     *
     * Die neun Pflichtfelder plus die beiden Behälter (actor, payload) plus raw als
     * einziges optionales Feld. Absichtlich aus EventSchema abgeleitet statt hier
     * aufgezählt: Eine zweite Liste liefe genauso auseinander wie die Doku es tat.
     *
     * @var list<string>
     */
    private const ERLAUBTE_EREIGNISFELDER = [
        ...EventSchema::MANDATORY_FIELDS,
        EventSchema::FIELD_ACTOR,
        EventSchema::FIELD_PAYLOAD,
        EventSchema::FIELD_RAW,
    ];

    /**
     * Zwischenknoten: Die Referenz führt sie als Überschrift oder als gemeinsames
     * Präfix ihrer Zeilen, nicht als eigene Tabellenzeile.
     *
     * Sie tragen keinen eigenen Wert — erklärt wird der Abschnitt. `layers.kernel.console`
     * steht dabei nicht als Überschrift, sondern als Präfix zweier Zeilen in der
     * Kernel-Tabelle (`console.enabled`, `console.ignored_commands`): Für zwei
     * Schlüssel wäre ein eigener Abschnitt mehr Gliederung als Inhalt.
     *
     * @var list<string>
     */
    private const ABSCHNITTE = [
        'session_hash', 'layers', 'layers.kernel', 'layers.kernel.events',
        'layers.kernel.console', 'layers.security',
        'layers.business', 'raw', 'payload_confidentiality_cleanup', 'budget',
        'flush', 'collector', 'spool', 'circuit_breaker', 'heartbeat', 'telemetry', 'logging',
        'correlation', 'fingerprint',
    ];

    /**
     * Schlüssel, die die Referenz in einer ZUSAMMENGEFASSTEN Zelle erklärt.
     *
     * `| events.request / .response / .exception | true | einzelne Hooks |` beschreibt
     * drei Schlüssel in einer Zeile. Das ist gute Doku und schlechtes Futter für einen
     * Parser, der genau einen Bezeichner je Zelle erwartet — die Zusammenfassung
     * aufzubrechen, nur damit ein Test sie findet, hieße die Doku dem Werkzeug
     * unterzuordnen.
     *
     * @var list<string>
     */
    private const ZUSAMMENGEFASST = [
        'layers.kernel.events.request',
        'layers.kernel.events.response',
        'layers.kernel.events.exception',
    ];

    /**
     * Begriffe, deren Sache es nicht mehr gibt — als Muster, mit dem Ersatz als Meldung.
     *
     * `sampeln` und seine Beugungen brauchen ein eigenes Muster: Die deutsche Form
     * enthält die Zeichenkette „sampl" gerade NICHT, weshalb eine Suche nach „sampling"
     * fünf Fundstellen übersah.
     *
     * Der letzte Eintrag bewacht keinen Begriff, sondern eine Kennung. Konzept 6 hat die
     * offenen Betriebspunkte von `B*` auf `OB*` umbenannt, weil `B1`–`B10` mit den
     * Batch-Regeln aus 4.3.2 kollidierten. Zwei Verweise blieben trotzdem stehen — einer
     * in `doc/06`, einer im Docblock von {@see \ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory} —
     * und zeigten danach lautlos auf eine Regel statt auf den gemeinten Punkt. Genau die
     * Verwechslung, gegen die die Umbenennung gemacht war. Das Konzept selbst steht in
     * {@see self::AUSGENOMMEN}: Es muss beide Kennungen nennen dürfen.
     *
     * @var array<string, string>
     */
    private const ABGESCHAFFT = [
        '\bsamp(el|le)\w*' => 'Sampling ist vollständig entfallen (Konzept 4.2.3).',
        '\bsampling\b' => 'Sampling ist vollständig entfallen (Konzept 4.2.3).',
        '\bbroker\b' => 'Der Transport geht per REST an den Collector (Konzept 3.6).',
        '\bredis\b' => 'Redis ist in beiden Rollen entfallen (Konzept 2.1, 4.2.1).',
        '\binstance_id\b' => 'Heißt seit Fassung 2 sensor_id (Konzept 1).',
        '\benvironment_(map|fallback)\b' => 'Ersatzlos entfallen; der Collector vergibt environment_id (Konzept 2.2.1).',
        '\bdiscarded_(full|unwritable|unencodable)\b' => 'Heißt dropped_* und steht bei den Zählern (Konzept 3.4).',
        'Punkte?\s+\*{0,2}B\d+' => 'Offene Punkte heißen seit der Umbenennung OB*; B\d sind die Batch-Regeln aus Konzept 4.3.2.',
    ];

    /**
     * Zwei Dateien sind ganz ausgenommen.
     *
     * Beide vergleichen durchgehend mit dem früheren Entwurf, und dafür müssen sie ihn
     * benennen können. Der Changelog IST die Historie; das Konzept begründet an vielen
     * Stellen, warum etwas entfallen ist, und eine Begründung ohne den alten Namen wäre
     * keine.
     *
     * @var list<string>
     */
    private const AUSGENOMMEN = [
        'CHANGELOG.md',
        'doc/concept/concept-v1.md',
    ];

    /**
     * Einzelne Zeilen, die einen abgeschafften Begriff tragen dürfen.
     *
     * Erkannt an einem Textausschnitt und nicht an einer Zeilennummer: Eine Nummer
     * verschiebt sich beim nächsten Absatz darüber, und die Ausnahme gälte dann für die
     * falsche Zeile — lautlos.
     *
     * Jeder Eintrag braucht einen Grund. Es gibt genau zwei zulässige:
     *
     *  - **Rückblick.** Die Stelle erklärt, was sich geändert hat, und muss den alten
     *    Namen dafür nennen. Ein Leser, der von der früheren Fassung kommt, sucht genau
     *    danach.
     *  - **Fehlalarm.** Das Wort meint hier etwas anderes. `Redis` als
     *    PHP-Session-Handler hat mit dem entfallenen Transport nichts zu tun; die Klasse
     *    beschreibt, was ein `session_start()` unter diesem Handler kostet, und das gilt
     *    unverändert.
     *
     * @var list<string>
     */
    private const ERLAUBTE_ZEILEN = [
        // Rückblick — für Nutzer, die von der früheren Fassung aktualisieren.
        'the sensor now talks to the collector over HTTPS and brings its own client',
        'Das ist der Vorteil gegenüber einer Broker-ACL',
        'Beides ist entfallen: Es gibt keinen Broker mehr',
        'schema_version 1 entstand die instance_id aus dem Hostnamen',
        'den Namen `discarded_full`, `discarded_unwritable` und `discarded_unencodable`',
        'dropped_spool_unwritable`, `dropped_spool_unencodable',
        // Fehlalarm — ein PHP-Session-Handler, nicht der entfallene Transport.
        'Unter einem PDO- oder Redis-Session-Handler',
    ];

    /** Diagrammtypen, die GitHub rendert. */
    private const MERMAID_TYPEN = [
        'flowchart', 'graph', 'sequenceDiagram', 'stateDiagram-v2', 'stateDiagram',
        'classDiagram', 'erDiagram', 'journey', 'gantt', 'pie', 'mindmap', 'timeline',
    ];

    /**
     * Jeder relative Verweis zeigt auf eine Datei, die es gibt.
     */
    public function testEveryRelativeLinkResolves(): void
    {
        $geprueft = 0;

        foreach (self::dokumente() as $relativ => $inhalt) {
            foreach (self::verweise($inhalt) as $ziel) {
                $pfad = explode('#', $ziel)[0];

                if ('' === $pfad) {
                    continue;
                }

                ++$geprueft;
                self::assertFileExists(
                    self::aufloesen($relativ, $pfad),
                    \sprintf('%s verweist auf %s — die Datei gibt es nicht.', $relativ, $ziel),
                );
            }
        }

        self::assertGreaterThan(50, $geprueft, 'Zu wenige Verweise gefunden — greift der Test noch?');
    }

    /**
     * Was die README verlinkt, muss auch im Dist-Archiv liegen.
     *
     * {@see self::testEveryRelativeLinkResolves()} läuft im Repository und kann das nicht
     * sehen: Dort existiert jede Datei. Über Composer installiert war `doc/` per
     * `export-ignore` ausgeschlossen — die README verwies elfmal ins Leere, ausgerechnet
     * auf „Betrieb" und „Konfiguration", die ein Betreiber beim Deploy braucht.
     */
    public function testNoLinkedDirectoryIsExcludedFromTheDistArchive(): void
    {
        $ausgeschlossen = self::exportIgnorierteVerzeichnisse();
        $geprueft = 0;

        foreach (self::verweise((string) file_get_contents(self::WURZEL.'/README.md')) as $ziel) {
            $pfad = explode('#', $ziel)[0];

            if ('' === $pfad || str_starts_with($pfad, 'http')) {
                continue;
            }

            $oberstes = explode('/', ltrim($pfad, './'))[0];

            // `tests/` ist ausgenommen und bleibt ausgeschlossen: Diese beiden Verweise
            // richten sich an Mitwirkende („was ArchitectureTest durchsetzt"), nicht an
            // Konsumenten. Ihr Ziel ist Quelltext, den ein Dist-Archiv nicht mitliefern
            // soll — anders als `doc/`, das der Betreiber beim Deploy braucht.
            if ('tests' === $oberstes) {
                continue;
            }

            ++$geprueft;

            self::assertNotContains(
                $oberstes,
                $ausgeschlossen,
                \sprintf('Die README verweist auf %s, aber /%s steht in .gitattributes auf export-ignore.', $ziel, $oberstes),
            );
        }

        self::assertGreaterThan(5, $geprueft, 'Zu wenige README-Verweise gefunden — greift der Test noch?');
    }

    /**
     * @return list<string>
     */
    private static function exportIgnorierteVerzeichnisse(): array
    {
        $zeilen = file(self::WURZEL.'/.gitattributes', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $ausgeschlossen = [];

        foreach ($zeilen ?: [] as $zeile) {
            if (str_starts_with($zeile, '#') || !str_contains($zeile, 'export-ignore')) {
                continue;
            }

            $ausgeschlossen[] = trim(explode(' ', trim($zeile))[0], '/');
        }

        return $ausgeschlossen;
    }

    /**
     * Jeder #anker zeigt auf eine Überschrift, die es gibt.
     *
     * Ankertote Verweise sind unsichtbar: der Browser springt nicht, aber die Seite
     * lädt. Ohne Prüfung fällt das erst auf, wenn jemand klickt — und dann schweigt er
     * meistens darüber.
     */
    public function testEveryAnchorResolves(): void
    {
        $dokumente = self::dokumente();

        foreach ($dokumente as $relativ => $inhalt) {
            foreach (self::verweise($inhalt) as $ziel) {
                [$pfad, $anker] = array_pad(explode('#', $ziel, 2), 2, '');

                if ('' === $anker) {
                    continue;
                }

                $datei = '' === $pfad ? self::WURZEL.'/'.$relativ : self::aufloesen($relativ, $pfad);

                self::assertContains(
                    $anker,
                    self::anker((string) file_get_contents($datei)),
                    \sprintf('%s verweist auf #%s in %s — die Überschrift gibt es nicht.', $relativ, $anker, $pfad ?: $relativ),
                );
            }
        }
    }

    /**
     * Die Konfigurationsreferenz erfindet keine Schlüssel.
     *
     * Eine Referenz, die Schlüssel nennt, die es nicht gibt, ist schlimmer als keine:
     * wer ihr folgt, bekommt einen Konfigurationsfehler und sucht ihn bei sich. Genau
     * das ist im Repo schon passiert.
     *
     * Geprüft werden Tabellenzellen, die aus GENAU einem Bezeichner in Backticks
     * bestehen. Zellen mit mehreren Token sind Aufzählungen von Werten, nicht von
     * Schlüsseln — die Umgebungsabbildung listet dort `prod`, `production`, `live`.
     */
    public function testConfigurationReferenceInventsNoKeys(): void
    {
        $bekannt = self::konfigurationspfade();
        $geprueft = 0;

        foreach (self::referenzschluessel() as $schluessel) {
            ++$geprueft;
            self::assertContains(
                $schluessel,
                $bekannt,
                \sprintf(
                    'doc/08-konfiguration.md nennt ids_sensor.%s — im ConfigurationTree gibt es '
                    .'den Schlüssel nicht.',
                    $schluessel,
                ),
            );
        }

        self::assertGreaterThan(50, $geprueft, 'Zu wenige Schlüssel gefunden — greift der Test noch?');
    }

    /**
     * Die Gegenrichtung: Der Baum hat keine Schlüssel, die niemand erklärt.
     *
     * `testConfigurationReferenceInventsNoKeys()` prüft nur, dass die Doku nichts
     * ERFINDET. Eine Option, die es gibt und die niemand beschreibt, fällt damit nicht
     * auf — und wer sie nicht kennt, benutzt sie nicht. Beides zusammen ergibt erst eine
     * Referenz, auf die man sich verlassen kann.
     */
    public function testEveryConfigKeyIsDocumented(): void
    {
        $dokumentiert = self::referenzschluessel();
        $undokumentiert = [];

        foreach (self::konfigurationspfade() as $pfad) {
            // Zwischenknoten tragen keinen Wert; erklärt wird der Abschnitt, nicht der
            // Knoten. Die Referenz führt sie deshalb als Überschrift, nicht als Zeile.
            if (\in_array($pfad, self::ABSCHNITTE, true)
                || \in_array($pfad, self::ZUSAMMENGEFASST, true)
            ) {
                continue;
            }

            if (!\in_array($pfad, $dokumentiert, true)) {
                $undokumentiert[] = $pfad;
            }
        }

        self::assertSame([], $undokumentiert, \sprintf(
            'Diese Schlüssel gibt es im ConfigurationTree, aber nicht in '
            ."doc/08-konfiguration.md:\n  %s\n\nEine Option, die niemand erklärt, benutzt niemand.",
            implode("\n  ", $undokumentiert),
        ));
    }

    /**
     * Die Vorgabewerte der Referenz stimmen mit dem Baum überein.
     *
     * Ein falscher Vorgabewert in der Doku ist schlimmer als ein fehlender: Wer ihn
     * liest, verlässt sich darauf und lässt den Schlüssel weg — und bekommt etwas
     * anderes, als dort steht.
     */
    public function testDocumentedDefaultsMatchTheTree(): void
    {
        $abweichungen = [];

        foreach (self::referenzvorgaben() as $pfad => $dokumentiert) {
            $tatsaechlich = self::vorgabeImBaum($pfad);

            if (null === $tatsaechlich || $tatsaechlich === $dokumentiert) {
                continue;
            }

            $abweichungen[] = \sprintf('%s: Doku sagt %s, der Baum sagt %s', $pfad, $dokumentiert, $tatsaechlich);
        }

        self::assertSame([], $abweichungen, \sprintf(
            "Die Vorgabewerte laufen auseinander:\n  %s",
            implode("\n  ", $abweichungen),
        ));
    }

    /**
     * Die Ereignis-Beispiele in der Doku zeigen das Format, das der Sensor wirklich sendet.
     *
     * WOZU
     *
     * Die Umstellung auf Fassung 2 hat `instance_id` durch `sensor_id` ersetzt,
     * `environment` durch `environment_id` und `schema_version` aus dem Ereignis in den
     * Frame verschoben. Der Quellcode zog nach, `doc/03-ereignisformat.md` und die README
     * nicht — beide zeigten monatelang ein Ereignis, das der Collector mit `422`
     * abgewiesen hätte. Kein Test hat es bemerkt, weil die vorhandenen Prüfungen
     * Konfigurationsschlüssel, Verweise, Anker, Vorgabewerte und Mermaid abdecken, aber
     * kein einziges Beispiel.
     *
     * Maßgeblich ist {@see EventSchema} und nicht diese Klasse: Beim nächsten
     * Fassungswechsel ändert sich dort eine Liste, und dieser Test zeigt, welche Absätze
     * nachzuziehen sind.
     */
    public function testTheEventExamplesMatchTheSchema(): void
    {
        $geprueft = 0;

        foreach (self::ereignisbeispiele() as $herkunft => $beispiel) {
            ++$geprueft;
            $felder = array_keys($beispiel);

            foreach (EventSchema::MANDATORY_FIELDS as $pflichtfeld) {
                self::assertContains($pflichtfeld, $felder, \sprintf(
                    '%s: Das Pflichtfeld "%s" aus EventSchema fehlt im Beispiel.',
                    $herkunft,
                    $pflichtfeld,
                ));
            }

            self::assertSame([], array_diff($felder, self::ERLAUBTE_EREIGNISFELDER), \sprintf(
                '%s: Das Beispiel zeigt Felder, die EventSchema nicht kennt. Genau so sind '
                .'instance_id und environment nach dem Wechsel auf Fassung 2 stehen geblieben.',
                $herkunft,
            ));

            self::assertArrayNotHasKey(
                EventSchema::FIELD_SCHEMA_VERSION,
                $beispiel,
                \sprintf(
                    '%s: schema_version gehört seit Fassung 2 in den Frame, nicht ins Ereignis '
                    .'(Konzept 3.3).',
                    $herkunft,
                ),
            );

            /** @var array<string, mixed> $actor */
            $actor = $beispiel[EventSchema::FIELD_ACTOR];

            self::assertSame(EventSchema::ACTOR_FIELDS, array_keys($actor), \sprintf(
                '%s: actor trägt genau die vier Felder aus EventSchema::ACTOR_FIELDS — '
                .'alle vorhanden, alle nullable (Konzept 2.2.4).',
                $herkunft,
            ));
        }

        self::assertGreaterThanOrEqual(2, $geprueft, 'Zu wenige Beispiele gefunden — greift der Test noch?');
    }

    /**
     * Abgeschaffte Begriffe überleben nicht in der Prosa.
     *
     * WOZU
     *
     * Die vorhandenen Prüfungen decken Konfigurationsschlüssel, Vorgabewerte, Verweise,
     * Anker und Ereignis-Beispiele ab — also alles, was eine Struktur hat. Fließtext und
     * Mermaid-Knoten haben keine, und genau dort blieben zwei abgeschaffte Konzepte
     * stehen: Nach dem vollständigen Wegfall des Samplings stand „sampeln" noch in zwei
     * Diagrammen, einer Leitfrage und einem Docblock; nach dem Wechsel auf REST hieß der
     * Collector an mehreren Stellen weiter „Broker". Beides las sich wie eine Zusage und
     * war keine.
     *
     * Der Test ist bewusst grob. Er beweist nicht, dass ein Satz stimmt — er verhindert
     * nur, dass ein Wort überlebt, dessen Sache es nicht mehr gibt.
     */
    public function testNoRetiredConceptSurvivesInProse(): void
    {
        $funde = [];

        foreach (self::prosaquellen() as $relativ => $inhalt) {
            foreach (explode("\n", $inhalt) as $nummer => $zeile) {
                if (self::istErlaubt($zeile)) {
                    continue;
                }

                foreach (self::ABGESCHAFFT as $begriff => $ersatz) {
                    if (1 !== preg_match('/'.$begriff.'/iu', $zeile)) {
                        continue;
                    }

                    $funde[] = \sprintf('%s:%d — %s', $relativ, $nummer + 1, $ersatz);
                }
            }
        }

        self::assertSame([], $funde, \sprintf(
            "Abgeschaffte Begriffe stehen noch da:\n  %s",
            implode("\n  ", $funde),
        ));
    }

    /**
     * Kein Dokument nennt eine Klasse des Ereignisformats, die es nicht mehr gibt.
     *
     * Die Gegenrichtung zu {@see ArchitectureTest::testDocblockReferencesDoNotDangle()}:
     * Jene prüft `{@see}`-Verweise auf `ProjektMotor\IdsSensor\*` und ist damit für das
     * FREMDPAKET blind. Genau dort ist es passiert — `IdsEventData\Vocabulary\Environment`
     * ist seit `ids-event-data` 0.2.0 gelöscht und stand danach weiter in `doc/03` und in
     * `doc/concept/structure.md`. In `doc/03` fiel es beim Nachziehen auf, in
     * `structure.md` nicht.
     */
    public function testNoDocumentNamesADeletedEventFormatClass(): void
    {
        $vorhanden = self::ereignisformatklassen();
        $geprueft = 0;

        foreach (self::prosaquellen() as $relativ => $inhalt) {
            preg_match_all('/IdsEventData\\\\([A-Za-z0-9\\\\]+)/', $inhalt, $treffer);

            foreach ($treffer[1] as $klasse) {
                ++$geprueft;
                self::assertContains($klasse, $vorhanden, \sprintf(
                    '%s nennt IdsEventData\\%s — die Klasse gibt es im Paket nicht (mehr).',
                    $relativ,
                    $klasse,
                ));
            }
        }

        self::assertGreaterThan(5, $geprueft, 'Zu wenige Klassennamen gefunden — greift der Test noch?');
    }

    /**
     * Die Klassen, die `projektmotor/ids-event-data` wirklich mitbringt, in Punktform
     * ohne Wurzel-Namensraum.
     *
     * @return list<string>
     */
    private static function ereignisformatklassen(): array
    {
        $wurzel = self::WURZEL.'/vendor/projektmotor/ids-event-data/src';
        $klassen = [];

        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));

        foreach ($dateien as $datei) {
            \assert($datei instanceof \SplFileInfo);

            if ('php' !== $datei->getExtension()) {
                continue;
            }

            $relativ = substr((string) $datei->getRealPath(), \strlen((string) realpath($wurzel)) + 1);
            $klassen[] = str_replace(['/', '.php'], ['\\', ''], $relativ);
        }

        sort($klassen);

        return $klassen;
    }

    private static function istErlaubt(string $zeile): bool
    {
        foreach (self::ERLAUBTE_ZEILEN as $ausschnitt) {
            if (str_contains($zeile, $ausschnitt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Markdown UND Quelltext — ein abgeschaffter Begriff im Docblock ist derselbe Fehler
     * wie einer in der Doku.
     *
     * @return array<string, string> Pfad relativ zur Wurzel => Inhalt
     */
    private static function prosaquellen(): array
    {
        $ergebnis = self::dokumente();

        foreach (self::AUSGENOMMEN as $datei) {
            unset($ergebnis[$datei]);
        }

        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::WURZEL.'/src'));

        foreach ($dateien as $datei) {
            \assert($datei instanceof \SplFileInfo);

            if ('php' !== $datei->getExtension()) {
                continue;
            }

            $relativ = str_replace(realpath(self::WURZEL).'/', '', (string) $datei->getRealPath());
            $ergebnis[$relativ] = (string) file_get_contents((string) $datei->getRealPath());
        }

        ksort($ergebnis);

        return $ergebnis;
    }

    /**
     * Jeder Mermaid-Block ist gefüllt und nennt einen Diagrammtyp.
     *
     * Billiger Schutz gegen abgeschnittene Blöcke und Tippfehler im Typ. Ob das
     * Diagramm inhaltlich stimmt oder hübsch aussieht, sagt der Test nicht — dafür
     * gibt es das Rendern.
     */
    public function testEveryMermaidBlockIsUsable(): void
    {
        $bloecke = 0;

        foreach (self::dokumente() as $relativ => $inhalt) {
            preg_match_all('/```mermaid\n(.*?)```/s', $inhalt, $treffer);

            foreach ($treffer[1] as $block) {
                ++$bloecke;
                $erste = strtok(trim($block), " \n");

                self::assertContains(
                    (string) $erste,
                    self::MERMAID_TYPEN,
                    \sprintf('%s: Mermaid-Block beginnt mit "%s" — kein bekannter Diagrammtyp.', $relativ, $erste),
                );
            }
        }

        self::assertGreaterThan(5, $bloecke, 'Zu wenige Diagramme gefunden — greift der Test noch?');
    }

    /**
     * Die JSON-Blöcke der Dokumentation, die ein Ereignis zeigen.
     *
     * Erkannt am Feld `event_id`: Die Doku enthält auch Frames, Heartbeats und
     * Antwortkörper, und die tragen es nicht. Ein Block, der sich nicht dekodieren
     * lässt, ist selbst ein Befund — ein Beispiel, das kein gültiges JSON ist, kann
     * niemand übernehmen.
     *
     * @return array<string, array<string, mixed>> Herkunft (Datei + Blocknummer) => Ereignis
     */
    private static function ereignisbeispiele(): array
    {
        $beispiele = [];

        foreach (self::dokumente() as $relativ => $inhalt) {
            preg_match_all('/```json\n(.*?)```/s', $inhalt, $treffer);

            foreach ($treffer[1] as $nummer => $block) {
                if (!str_contains($block, '"event_id"')) {
                    continue;
                }

                $dekodiert = json_decode($block, true);

                self::assertIsArray($dekodiert, \sprintf(
                    '%s, JSON-Block %d: lässt sich nicht dekodieren — %s',
                    $relativ,
                    $nummer + 1,
                    json_last_error_msg(),
                ));

                /** @var array<string, mixed> $dekodiert */
                $beispiele[\sprintf('%s, JSON-Block %d', $relativ, $nummer + 1)] = $dekodiert;
            }
        }

        return $beispiele;
    }

    /**
     * @return array<string, string> Pfad relativ zur Wurzel => Inhalt
     */
    private static function dokumente(): array
    {
        $dateien = array_merge(
            glob(self::WURZEL.'/*.md') ?: [],
            glob(self::WURZEL.'/doc/*.md') ?: [],
        );

        $ergebnis = [];

        foreach ($dateien as $datei) {
            $relativ = str_replace(realpath(self::WURZEL).'/', '', (string) realpath($datei));
            $ergebnis[$relativ] = (string) file_get_contents($datei);
        }

        ksort($ergebnis);

        return $ergebnis;
    }

    /**
     * Ziele aller Markdown-Verweise, ohne http(s) und mailto.
     *
     * @return list<string>
     */
    private static function verweise(string $inhalt): array
    {
        preg_match_all('/\[[^\]]+\]\(([^)\s]+)\)/', $inhalt, $treffer);

        return array_values(array_filter(
            $treffer[1],
            static fn (string $ziel): bool => 1 !== preg_match('#^(https?:|mailto:)#', $ziel),
        ));
    }

    private static function aufloesen(string $quelle, string $ziel): string
    {
        return self::WURZEL.'/'.\dirname($quelle).'/'.$ziel;
    }

    /**
     * Die Anker, die GitHub aus den Überschriften eines Dokuments bildet.
     *
     * Regel: Auszeichnung entfernen, kleinschreiben, Satzzeichen streichen, dann JEDES
     * Leerzeichen durch einen Bindestrich ersetzen — Läufe werden NICHT zusammengefasst.
     * „3.1 Payload-Format pro Ebene / Events" ergibt deshalb zwei Bindestriche vor
     * „events".
     *
     * @return list<string>
     */
    private static function anker(string $inhalt): array
    {
        preg_match_all('/^#{1,6}\s+(.*?)\s*$/m', $inhalt, $treffer);

        $anker = [];

        foreach ($treffer[1] as $ueberschrift) {
            $text = preg_replace('/`([^`]*)`|\*\*([^*]*)\*\*/u', '$1$2', $ueberschrift) ?? '';
            $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? '';
            $text = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower($text)) ?? '';
            $anker[] = (string) preg_replace('/\s/u', '-', trim($text));
        }

        return $anker;
    }

    /**
     * Die Schlüssel, die doc/08-konfiguration.md in der ersten Tabellenspalte nennt.
     *
     * Der Abschnitt liefert das Präfix: unter „## `session_hash`" ist `key` der
     * Schlüssel `session_hash.key`.
     *
     * Nur eine Überschrift, die AUSSCHLIESSLICH aus einem Knotennamen besteht, setzt
     * ein Präfix. „## `telemetry` und `logging`" nennt zwei Knoten und damit keinen —
     * dort stehen die Schlüssel in der Tabelle voll qualifiziert, wie bei den
     * Wurzelschlüsseln auch.
     *
     * @return list<string>
     */
    private static function referenzschluessel(): array
    {
        $zeilen = explode("\n", (string) file_get_contents(self::WURZEL.'/doc/08-konfiguration.md'));
        $abschnitt = '';
        $schluessel = [];

        foreach ($zeilen as $zeile) {
            if (1 === preg_match('/^#{2,3}\s/', $zeile)) {
                $abschnitt = 1 === preg_match('/^#{2,3}\s+`([^`]+)`\s*$/', $zeile, $kopf) ? $kopf[1] : '';

                continue;
            }

            $zelle = trim(explode('|', $zeile)[1] ?? '');

            if (1 === preg_match('/^`([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)`$/', $zelle, $token)) {
                $schluessel[] = '' === $abschnitt ? $token[1] : $abschnitt.'.'.$token[1];
            }
        }

        return $schluessel;
    }

    /**
     * Die Vorgabewerte, die doc/08-konfiguration.md in der zweiten Tabellenspalte nennt.
     *
     * Nur Zellen, die aus GENAU einem Wert in Backticks bestehen — alles andere ist
     * Prosa und keine Zusage über einen Vorgabewert.
     *
     * @return array<string, string> Pfad => dokumentierter Wert
     */
    private static function referenzvorgaben(): array
    {
        $zeilen = explode("\n", (string) file_get_contents(self::WURZEL.'/doc/08-konfiguration.md'));
        $abschnitt = '';
        $vorgaben = [];

        foreach ($zeilen as $zeile) {
            if (1 === preg_match('/^#{2,3}\s/', $zeile)) {
                $abschnitt = 1 === preg_match('/^#{2,3}\s+`([^`]+)`\s*$/', $zeile, $kopf) ? $kopf[1] : '';

                continue;
            }

            $spalten = explode('|', $zeile);
            $schluessel = trim($spalten[1] ?? '');
            $vorgabe = trim($spalten[2] ?? '');

            if (1 !== preg_match('/^`([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)`$/', $schluessel, $token)) {
                continue;
            }

            if (1 !== preg_match('/^`([^`]+)`$/', $vorgabe, $wert)) {
                continue;
            }

            $vorgaben['' === $abschnitt ? $token[1] : $abschnitt.'.'.$token[1]] = $wert[1];
        }

        return $vorgaben;
    }

    /**
     * Der Vorgabewert eines Pfades im Baum, in derselben Schreibweise wie die Doku.
     *
     * null heißt „nicht vergleichbar" — etwa bei Listen und verschachtelten Vorgaben,
     * die in der Referenz ohnehin in Prosa stehen.
     */
    private static function vorgabeImBaum(string $pfad): ?string
    {
        $baum = new TreeBuilder('ids_sensor');
        ConfigurationTree::build($baum->getRootNode());

        $wurzel = $baum->buildTree();
        \assert($wurzel instanceof ArrayNode);

        $knoten = $wurzel;

        foreach (explode('.', $pfad) as $segment) {
            $kinder = $knoten->getChildren();

            if (!isset($kinder[$segment])) {
                return null;
            }

            $kind = $kinder[$segment];

            if (!$kind instanceof ArrayNode) {
                return $kind->hasDefaultValue() ? self::alsText($kind->getDefaultValue()) : null;
            }

            $knoten = $kind;
        }

        return null;
    }

    private static function alsText(mixed $wert): ?string
    {
        return match (true) {
            null === $wert => 'null',
            \is_bool($wert) => $wert ? 'true' : 'false',
            \is_int($wert) => (string) $wert,
            // 1.0 und nicht 1: Die Referenz schreibt Fließkommavorgaben mit Nachkomma,
            // und `(string) 1.0` ergäbe „1" — eine Abweichung, die keine ist.
            \is_float($wert) => \sprintf('%s', json_encode($wert, \JSON_PRESERVE_ZERO_FRACTION)),
            \is_string($wert) => $wert,
            \is_array($wert) => [] === $wert ? '[]' : null,
            default => null,
        };
    }

    /**
     * Alle Pfade des echten Konfigurationsbaums, in Punktschreibweise.
     *
     * @return list<string>
     */
    private static function konfigurationspfade(): array
    {
        $baum = new TreeBuilder('ids_sensor');
        ConfigurationTree::build($baum->getRootNode());

        $pfade = [];
        $sammeln = static function (ArrayNode $knoten, string $praefix) use (&$sammeln, &$pfade): void {
            foreach ($knoten->getChildren() as $name => $kind) {
                $pfad = '' === $praefix ? (string) $name : $praefix.'.'.$name;
                $pfade[] = $pfad;

                if ($kind instanceof ArrayNode) {
                    $sammeln($kind, $pfad);
                }
            }
        };

        $wurzel = $baum->buildTree();
        \assert($wurzel instanceof ArrayNode);
        $sammeln($wurzel, '');

        return $pfade;
    }
}
