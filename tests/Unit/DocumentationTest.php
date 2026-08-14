<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
