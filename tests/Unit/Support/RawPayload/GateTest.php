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

        self::assertTrue($gate->allows(Severity::Warning));
        self::assertTrue($gate->allows(Severity::Critical));
    }

    public function testInfoNeverCarriesRaw(): void
    {
        $gate = new Gate();

        self::assertFalse($gate->allows(Severity::Info), 'Konzept Abschnitt 3 legt „nur warning und critical" fest');
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

        self::assertFalse($gate->allows(Severity::Warning));
        self::assertTrue($gate->allows(Severity::Critical));
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

        self::assertFalse($gate->allows(Severity::Info));
    }

    public function testDisablingRemovesRawEntirely(): void
    {
        $gate = new Gate(enabled: false);

        self::assertFalse($gate->allows(Severity::Critical));
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

        self::assertFalse($gate->allows(Severity::Warning));
        self::assertFalse($gate->allows(Severity::Critical));
    }
}
