<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\RawPayload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Support\RawPayload\Gate;

/**
 * Die eine Stelle, an der entschieden wird, ob `raw` einen Frame verlässt.
 *
 * Sie ist in beide Richtungen heikel: Lässt sie zu viel durch, reißt das
 * Volumenbudget aus Konzept 4.2.3 und personenbezogene Formularinhalte reisen bei
 * jedem Zugriff mit. Lässt sie zu wenig durch, fehlt bei der Nachanalyse eines
 * erfolgreichen Angriffs genau das, wofür es `raw` gibt.
 */
#[CoversClass(Gate::class)]
final class GateTest extends TestCase
{
    public function testWarningAndCriticalCarryRawByDefault(): void
    {
        $gate = new Gate();

        self::assertTrue($gate->allows(Severity::Warning, 'kernel.response'));
        self::assertTrue($gate->allows(Severity::Critical, 'kernel.response'));
    }

    public function testInfoNeverCarriesRaw(): void
    {
        $gate = new Gate();

        self::assertFalse($gate->allows(Severity::Info, 'kernel.response'), 'Konzept Abschnitt 3 legt „nur warning und critical" fest');
    }

    /**
     * Die Konfiguration darf die Menge VERKLEINERN.
     *
     * Der dokumentierte Anwendungsfall aus `doc/08`: Wer das Volumenbudget reißt,
     * schränkt auf `critical` allein ein.
     */
    public function testTheConfigurationCanNarrowTheSelection(): void
    {
        $gate = new Gate(severities: ['critical']);

        self::assertFalse($gate->allows(Severity::Warning, 'kernel.response'));
        self::assertTrue($gate->allows(Severity::Critical, 'kernel.response'));
    }

    /**
     * Und sie darf sie NICHT vergrößern.
     *
     * `severities: [info, warning, critical]` ist konfigurierbar, wirkt aber nicht: raw
     * für jedes info-Event würde das Volumenbudget aus Konzept 4.2.3 um Größenordnungen
     * reißen. Ohne diese zweite, eingebaute Schranke wäre die Zusage aus Abschnitt 3
     * durch eine Konfigurationszeile abschaltbar.
     */
    public function testTheConfigurationCannotWidenTheSelection(): void
    {
        $gate = new Gate(severities: ['info', 'warning', 'critical']);

        self::assertFalse($gate->allows(Severity::Info, 'kernel.response'));
    }

    public function testDisablingRemovesRawEntirely(): void
    {
        $gate = new Gate(enabled: false);

        self::assertFalse($gate->allows(Severity::Critical, 'kernel.response'));
    }

    /**
     * Eine leere Liste ist kein Rückfall auf die Vorgabe.
     *
     * Sie bedeutet „keine Stufe" und muss auch so wirken — ein stillschweigendes
     * Zurückfallen auf `warning`/`critical` wäre die gefährlichere Auslegung, weil sie
     * mehr überträgt, als dasteht.
     */
    public function testAnEmptyListAllowsNothing(): void
    {
        $gate = new Gate(severities: []);

        self::assertFalse($gate->allows(Severity::Warning, 'kernel.response'));
        self::assertFalse($gate->allows(Severity::Critical, 'kernel.response'));
    }

    /**
     * Die Ausnahmeliste ist die EINZIGE Stelle, an der die Stufengrenze nach oben
     * durchbrochen werden kann — Konzept 4.5.2, offener Punkt OB11.
     *
     * Ohne sie stand ein Befund wie R2b („Pfadlisten-Treffer mit Status 200") ohne
     * forensischen Beleg da: Das Event ist `info`, also war das `raw` längst verworfen,
     * als der Alarm im Collector entstand.
     */
    public function testANamedPathCarriesRawEvenOnInfo(): void
    {
        $gate = new Gate(alwaysPathPatterns: ['#^/_profiler#']);

        self::assertTrue($gate->allows(Severity::Info, 'kernel.response', '/_profiler/latest'));
        self::assertFalse($gate->allows(Severity::Info, 'kernel.response', '/bestellungen/42'));
    }

    public function testANamedEventTypeCarriesRawEvenOnInfo(): void
    {
        $gate = new Gate(alwaysEventTypes: ['console.command']);

        self::assertTrue($gate->allows(Severity::Info, 'console.command'));
        self::assertFalse($gate->allows(Severity::Info, 'kernel.response'));
    }

    /**
     * Ereignistypen werden GENAU verglichen, nicht als Muster.
     *
     * Ein Präfixvergleich machte aus `kernel.` versehentlich drei Ereignistypen, und
     * `kernel.response` ist die Masse aller Events — das Volumenbudget wäre weg, ohne
     * dass jemand es angeordnet hätte.
     */
    public function testEventTypesMatchExactly(): void
    {
        $gate = new Gate(alwaysEventTypes: ['kernel']);

        self::assertFalse($gate->allows(Severity::Info, 'kernel.response'));
    }

    /**
     * Ohne Pfad greift kein Muster. Security- und Business-Events führen keinen, und
     * ein Rückfall auf „trifft alles" wäre dort die gefährlichere Auslegung.
     */
    public function testWithoutAPathNoPatternMatches(): void
    {
        $gate = new Gate(alwaysPathPatterns: ['#.#']);

        self::assertFalse($gate->allows(Severity::Info, 'security.access_decision'));
    }

    /**
     * `raw.enabled: false` schlägt die Ausnahmeliste. Wer das Feld ganz abschaltet,
     * hat eine Entscheidung getroffen, die eine Kandidatenliste nicht unterlaufen darf.
     */
    public function testTheKillSwitchBeatsTheCandidateList(): void
    {
        $gate = new Gate(enabled: false, alwaysPathPatterns: ['#^/_profiler#']);

        self::assertFalse($gate->allows(Severity::Info, 'kernel.response', '/_profiler/latest'));
    }

    /**
     * Ohne Eintrag ist der Zweig wirkungslos — die Vorgabe ist das bisherige Verhalten.
     */
    public function testAnEmptyCandidateListChangesNothing(): void
    {
        $gate = new Gate();

        self::assertFalse($gate->allows(Severity::Info, 'kernel.response', '/_profiler/latest'));
    }
}
