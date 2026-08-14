<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Vocabulary;

/**
 * Die drei Stufen aus Konzept 2.2.1 — Konkrete Ableitungsregeln für event_severity.
 *
 * Die Werte entsprechen exakt dem collectorseitigen ENUM severity_level
 * (Konzept 4.2.1 Tabellenschema).
 *
 * Bewusst NICHT im Rückgabetyp von `Contract\SecurityRelevantBusinessEvent::getSeverityHint()`
 * verwendet — dort bleibt string, damit Implementierer nicht gezwungen sind, dieses
 * Enum zu importieren, und damit eine späte Verengung nicht zum BC-Bruch wird.
 * Dieses Enum ist reiner Komfort: `return Severity::Critical->value;`
 *
 * Als Prosa und nicht als {@see}: `Contract\` gehört dem Sensor-Bundle und bleibt
 * dort, wenn dieses Verzeichnis ein eigenes Paket wird.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Ob für Events dieser Stufe das raw-Feld übertragen wird.
     *
     * Konzept Abschnitt 3: raw wird nur für warning und critical übertragen.
     * Das zweite dort genannte Kriterium („alle Events, die einen Alert ausgelöst
     * haben") ist Collector-Wissen und im Sensor nicht umsetzbar.
     */
    public function carriesRaw(): bool
    {
        return self::Info !== $this;
    }

    /**
     * Ob Events dieser Stufe gesampelt werden dürfen.
     *
     * Konzept 4.2.3 — Volumenbudget und gestufte Retention: warning und critical
     * werden nie gesampelt. Zusätzlich gilt sensorseitig, dass ausschließlich die
     * Kernel-Ebene sampelbar ist; das prüft der Sampler separat.
     */
    public function isSampleable(): bool
    {
        return self::Info === $this;
    }
}
