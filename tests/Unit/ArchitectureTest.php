<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hält die Struktur aus doc/struktur.md fest.
 *
 * WOZU
 *
 * Die Aufteilung von src/ trägt fünf Zusagen, die man einer einzelnen Datei nicht
 * ansieht und die deshalb ohne Test binnen weniger Commits still verfallen. Alle fünf
 * sind billig zu prüfen und teuer zu reparieren:
 *
 *  - Welche Klassen unter Semver stehen (heute: Prosa in der README).
 *  - Dass EventFormat/ als eigenes Paket herauslösbar bleibt.
 *  - Dass Phase A nicht an Phase B hängt — die einzige harte Zusage gegenüber der
 *    überwachten Anwendung (5 ms p99, Konzept 2.1).
 *  - Dass Dispatch/ die Spitze der Pipeline bleibt und keine Sammelstelle wird.
 *  - Dass die Abhängigkeiten zwischen den Namensräumen in eine Richtung zeigen und
 *    kein Namensraum unbemerkt hinzukommt.
 *
 * Bewusst über Dateiinhalte statt über Reflection: der Test soll auch dann etwas
 * sagen, wenn eine Klasse gar nicht ladbar ist.
 *
 * @internal
 */
final class ArchitectureTest extends TestCase
{
    private const SRC = __DIR__.'/../../src';

    /**
     * Wer darf wen kennen — als Zahl statt als Absicht.
     *
     * Die Tabelle ist feiner als der Verzeichnisbaum, und das ist kein Versehen. Die
     * Ordner Processing/, Delivery/ und Support/ beantworten „welcher Phase gehört
     * das?"; diese Tabelle beantwortet „wer darf wen importieren?". Zwei verschiedene
     * Fragen, deshalb zwei verschiedene Antworten.
     *
     * EventFormat/ hat keinen Sammel-Eintrag, sondern vier — einen je Untergruppe.
     * Das ist Absicht: eine Datei, die jemand direkt unter EventFormat/ ablegt, lässt
     * den Test fehlschlagen, bis sie eingeordnet ist. Die Ränge halten dabei die
     * Verschachtelung des Drahtformats fest: Vocabulary/ und Payload/ importieren
     * nichts, Event/ liest aus Vocabulary/, Frame/ liest aus Event/.
     *
     * Am deutlichsten an Support/: der Ordner sammelt, was keiner Phase gehört, aber
     * seine vier Mitglieder verteilen sich über drei Ränge.
     *
     *  - PayloadConfidentialityCleanup/ und Identity/ importieren nur aus dem
     *    EventFormat und stehen damit unter allem anderen.
     *  - RawPayload/ liegt eine Stufe darüber, weil der Builder den Cleaner benutzt:
     *    redigiert wird beim AUFBAU, nicht in einem nachgelagerten Durchlauf.
     *  - Sensor/ folgt, weil ResponseSensor und ExceptionSensor den Builder
     *    injiziert bekommen — der raw-Aufbau hängt damit im Request-Pfad, auch wenn
     *    die Closure erst in Phase B ausgewertet wird.
     *  - Telemetry/ steht über Sensor/, weil DeferredCounters CaptureBudget,
     *    EventBuffer und AccessDecisionSensor liest. Das ist die einzige Stelle, an
     *    der ein phasenloser Namensraum an Phase A hängt.
     *
     * @var array<string, int>
     */
    private const RANGFOLGE = [
        'EventFormat/Vocabulary' => 0,
        'EventFormat/Payload' => 0,
        'EventFormat/Event' => 1,
        'EventFormat/Frame' => 2,
        'Contract' => 0,
        'Exception' => 0,
        'Support/PayloadConfidentialityCleanup' => 1,
        'Support/Identity' => 1,
        'Support/RawPayload' => 2,
        'Sensor' => 3,
        'Support/Telemetry' => 4,
        'Processing/Normalization' => 5,
        'Delivery/Transport' => 6,
        'Delivery/Heartbeat' => 7,
        'Delivery/Dispatch' => 8,
        'Command' => 9,
        'DependencyInjection' => 9,
        'IdsSensorBundle.php' => 9,
    ];

    /**
     * Die öffentliche Fläche ist ein Verzeichnis, keine Aufzählung.
     *
     * README-Abschnitt „Öffentliche API": Semver gilt für Contract\* und
     * EventFormat\*, alles andere ist @internal. Ohne diesen Test wäre das eine
     * Behauptung — jede künftig dort abgelegte Datei wäre unbemerkt ein
     * Semver-Versprechen, und jede @internal-Klasse, der jemand die Annotation
     * abnimmt, ebenfalls.
     */
    public function testOnlyContractAndEventFormatArePublic(): void
    {
        $oeffentlich = [];
        $intern = [];

        foreach (self::quelldateien() as $relativ => $inhalt) {
            $istOeffentlicherNamensraum = str_starts_with($relativ, 'Contract/')
                || str_starts_with($relativ, 'EventFormat/')
                || 'IdsSensorBundle.php' === $relativ;

            if (str_contains($inhalt, '@internal')) {
                $intern[] = $relativ;
            } else {
                $oeffentlich[] = $relativ;
            }

            if ($istOeffentlicherNamensraum) {
                self::assertStringNotContainsString(
                    '@internal',
                    $inhalt,
                    \sprintf('%s liegt in der öffentlichen Fläche, trägt aber @internal.', $relativ),
                );
            } else {
                self::assertStringContainsString(
                    '@internal',
                    $inhalt,
                    \sprintf(
                        '%s trägt kein @internal. Entweder gehört die Klasse nach Contract/ '
                        .'oder EventFormat/, oder die Annotation fehlt.',
                        $relativ,
                    ),
                );
            }
        }

        self::assertNotEmpty($oeffentlich);
        self::assertNotEmpty($intern);
    }

    /**
     * EventFormat/ muss ohne den Rest des Bundles übersetzbar bleiben.
     *
     * Das ist die Bedingung dafür, dass der Namespace später als eigenes Paket
     * ausgelöst und vom IdsBackendBundle mitbenutzt werden kann: ein einziger
     * Import aus Sensor/, Processing/ oder Delivery/ macht aus dem Verzeichnis-Move
     * eine Entflechtung.
     *
     * Auch KEIN Symfony: das Format ist reines PHP und soll es bleiben, damit ein
     * Consumer es ohne Framework lesen kann.
     *
     * WARUM NICHT „gar keine Importe"
     *
     * Bis zur Untergliederung verbot dieser Test jede use-Zeile. Das war damals
     * dasselbe wie „nichts Fremdes", weil alle elf Klassen flach in einem Namensraum
     * lagen — aber es war nie die Zusage. Seit Frame/, Event/, Payload/ und
     * Vocabulary/ existieren, greifen sie übereinander, und das muss erlaubt sein:
     * ein Paket darf sich selbst importieren.
     *
     * WARUM AUCH DOCBLOCKS
     *
     * Ein {@see \ProjektMotor\IdsSensor\Sensor\CapturedEvent} erzeugt keine
     * Abhängigkeit für den Übersetzer, wohl aber einen toten Verweis, sobald das
     * Verzeichnis ein eigenes Paket ist. Genau so einer stand in NormalizedEvent und
     * wäre ohne diese Erweiterung erst nach der Ausgliederung aufgefallen. Geprüft
     * wird deshalb nicht die use-Zeile, sondern jede Nennung des Wurzel-Namensraums.
     */
    public function testEventFormatImportsNothingForeign(): void
    {
        foreach (self::quelldateien('EventFormat') as $relativ => $inhalt) {
            preg_match_all('/^use\s+([^;]+);$/m', $inhalt, $treffer);

            foreach ($treffer[1] as $import) {
                self::assertStringStartsWith(
                    'ProjektMotor\\IdsSensor\\EventFormat\\',
                    $import,
                    \sprintf(
                        '%s importiert %s. Der Namensraum muss autark bleiben — '
                        .'sonst ist er nicht als eigenes Paket herauslösbar. Erlaubt sind '
                        .'ausschließlich Importe aus EventFormat/ selbst.',
                        $relativ,
                        $import,
                    ),
                );
            }

            preg_match_all('/ProjektMotor\\\\IdsSensor\\\\([A-Za-z]+)/', $inhalt, $nennungen);

            foreach ($nennungen[1] as $namensraum) {
                self::assertSame(
                    'EventFormat',
                    $namensraum,
                    \sprintf(
                        '%s nennt ProjektMotor\\IdsSensor\\%s — auch in einem '
                        .'Docblock ist das ein Verweis, der nach der Ausgliederung ins Leere '
                        .'zeigt. Als Prosa schreiben statt als {@see}.',
                        $relativ,
                        $namensraum,
                    ),
                );
            }
        }

        self::assertNotEmpty(self::quelldateien('EventFormat'));
    }

    /**
     * Phase A darf Phase B nicht kennen.
     *
     * Sensor/ läuft im Request unter dem Erfassungsbudget aus Konzept 2.1,
     * Processing/ erst nach dem Absenden der Antwort. Ein Import in diese Richtung
     * ist der erste Schritt dahin, dass jemand Normalisierungsarbeit in den Request
     * zieht — und das merkt niemand, weil kein Test langsamer wird, sondern nur die
     * überwachte Anwendung.
     *
     * Die Gegenrichtung ist erlaubt und richtig: der Normalisierer liest das vom
     * Sensor erfasste Event.
     *
     * Der Test greift auf den ganzen Ordner, nicht nur auf Normalization/: was immer
     * künftig unter Processing/ landet, gehört per Definition hinter die Antwort.
     */
    public function testSensorDoesNotKnowProcessing(): void
    {
        foreach (self::quelldateien('Sensor') as $relativ => $inhalt) {
            self::assertStringNotContainsString(
                'IdsSensor\\Processing\\',
                $inhalt,
                \sprintf(
                    '%s importiert aus Processing/. Phase A darf nicht an Phase B hängen; '
                    .'gemeinsame Schlüssel gehören ins EventFormat.',
                    $relativ,
                ),
            );
        }
    }

    /**
     * Delivery/Dispatch/ ist die Spitze der Pipeline, nicht ihr Rest.
     *
     * Solange nichts aus Dispatch/ importiert, ist belegt, dass dort ausschließlich
     * Orchestrierung liegt. Sobald Command/, Heartbeat/ oder Transport/ wieder
     * hineingreifen, ist etwas hineingerutscht, das dort nicht hingehört — genau so
     * sind vor dem Umbau die beiden Zyklen entstanden.
     *
     * Die Rangtabelle in {@see self::testGroupsFormALayering()} kann das nicht
     * mitprüfen: Dispatch/ liegt mit Transport/ und Heartbeat/ in derselben Gruppe,
     * und innerhalb einer Gruppe sind Importe erlaubt.
     *
     * Docblock-Verweise ({@see …}) sind ausgenommen: sie erzeugen keine Abhängigkeit.
     */
    public function testNobodyImportsFromDispatch(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            if (str_starts_with($relativ, 'Delivery/Dispatch/')) {
                continue;
            }

            preg_match_all('/^use\s+ProjektMotor\\\\IdsSensor\\\\Delivery\\\\Dispatch\\\\[^;]+;$/m', $inhalt, $treffer);

            self::assertSame(
                [],
                $treffer[0],
                \sprintf('%s importiert aus Delivery/Dispatch/. Dispatch/ soll eine Senke bleiben.', $relativ),
            );
        }
    }

    /**
     * Jeder {@see}-Verweis auf eine eigene Klasse muss auflösbar sein.
     *
     * Die Docblocks sind in diesem Repo Primärdokumentation — die Begründungsessays
     * verweisen aufeinander. Weder PHPStan noch php-cs-fixer prüfen diese Verweise;
     * nach einer Umsortierung zeigen sie lautlos ins Leere.
     */
    public function testDocblockReferencesDoNotDangle(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            preg_match_all('/\{@see\s+\\\\?(ProjektMotor\\\\IdsSensor\\\\[A-Za-z0-9\\\\]+)/', $inhalt, $treffer);

            foreach ($treffer[1] as $fqcn) {
                $pfad = self::SRC.'/'.str_replace('\\', '/', substr($fqcn, \strlen('ProjektMotor\\IdsSensor\\'))).'.php';

                self::assertFileExists(
                    $pfad,
                    \sprintf('%s verweist auf %s — die Klasse gibt es nicht.', $relativ, $fqcn),
                );
            }
        }
    }

    /**
     * Die Namensräume von src/ bilden eine Schichtung, keine Wolke.
     *
     * Zyklenfreiheit war schon vor diesem Test wahr — sie stand nur in doc/struktur.md
     * und war damit eine Momentaufnahme. Ein einziger Import in die Gegenrichtung
     * bricht nichts und fällt in keinem Review auf; erst der zweite schließt den Kreis,
     * und dann ist die Reparatur teuer. Genau so sind die beiden Zyklen entstanden, die
     * dem Umbau von Dispatch/ vorausgingen.
     *
     * Der Test hat eine zweite Aufgabe, die wichtiger ist als die erste: ein neuer
     * Namensraum in src/ lässt ihn fehlschlagen, bis jemand ihn einordnet. Ohne das
     * würde Support/ binnen weniger Commits zur Rumpelkammer — ein Ordner, dessen
     * Regel „gehört keiner Phase" lautet, sammelt sonst alles ein, wofür sich niemand
     * entscheiden will.
     *
     * Dass {@see self::testSensorDoesNotKnowProcessing()} damit formal doppelt geprüft
     * wird, ist Absicht. Dieser Test meldet eine Rangverletzung; jener erklärt, dass
     * sie die Antwortzeit der überwachten Anwendung kostet.
     */
    public function testGroupsFormALayering(): void
    {
        foreach (self::quelldateien() as $relativ => $inhalt) {
            $eigenerRang = self::rang($relativ);
            self::assertNotNull($eigenerRang, self::fehlenderRang($relativ));

            foreach (self::eigeneImporte($inhalt) as $ziel) {
                $zielRang = self::rang($ziel);
                self::assertNotNull($zielRang, self::fehlenderRang($ziel));

                self::assertLessThanOrEqual($eigenerRang, $zielRang, \sprintf(
                    '%s (Rang %d) importiert aus %s (Rang %d). Abhängigkeiten zeigen nach '
                    .'unten, nie zurück — sonst ist der nächste Import ein Zyklus.',
                    $relativ,
                    $eigenerRang,
                    $ziel,
                    $zielRang,
                ));
            }
        }
    }

    private static function fehlenderRang(string $pfad): string
    {
        return \sprintf(
            '%s liegt in keinem Namensraum aus RANGFOLGE. Ein neuer Namensraum gehört dort '
            .'eingetragen — sonst wächst src/ still um eine Gruppe, die niemand eingeordnet '
            .'hat, und die Schichtung sagt nichts mehr aus.',
            $pfad,
        );
    }

    /**
     * @return list<string> Importe aus dem eigenen Wurzel-Namensraum, in Pfadform
     */
    private static function eigeneImporte(string $inhalt): array
    {
        preg_match_all('/^use\s+ProjektMotor\\\\IdsSensor\\\\([^;]+);$/m', $inhalt, $treffer);

        return array_map(
            static fn (string $fqcn): string => str_replace('\\', '/', $fqcn),
            $treffer[1],
        );
    }

    /**
     * Der längste passende Eintrag gewinnt — 'Support/RawPayload' vor 'Support'.
     */
    private static function rang(string $pfad): ?int
    {
        $laengsterTreffer = -1;
        $rang = null;

        foreach (self::RANGFOLGE as $namensraum => $kandidat) {
            $passt = $pfad === $namensraum || str_starts_with($pfad, $namensraum.'/');

            if ($passt && \strlen($namensraum) > $laengsterTreffer) {
                $laengsterTreffer = \strlen($namensraum);
                $rang = $kandidat;
            }
        }

        return $rang;
    }

    /**
     * @return array<string, string> Pfad relativ zu src/ => Dateiinhalt
     */
    private static function quelldateien(string $unterverzeichnis = ''): array
    {
        $wurzel = self::SRC.('' !== $unterverzeichnis ? '/'.$unterverzeichnis : '');

        $dateien = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel)),
            '/\.php$/',
        );

        $ergebnis = [];

        foreach ($dateien as $datei) {
            \assert($datei instanceof \SplFileInfo);

            $relativ = str_replace('\\', '/', substr($datei->getPathname(), \strlen(self::SRC) + 1));
            $ergebnis[$relativ] = file_get_contents($datei->getPathname()) ?: '';
        }

        ksort($ergebnis);

        return $ergebnis;
    }
}
