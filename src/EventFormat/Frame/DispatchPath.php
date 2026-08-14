<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Frame;

/**
 * Auf welchem Weg ein Frame den Broker erreicht hat.
 *
 * Ergänzung zum Konzept, auf Frame-Ebene. Ein binäres „late"-Flag wäre hier falsch:
 * es würde planmäßig verzögerten Versand und Nachlauf nach einem Broker-Ausfall in
 * einen Topf werfen. Unter mod_php läuft aber JEDER Frame planmäßig über den Spool —
 * mit einem Flag wäre die Echtzeit-Erkennung dort dauerhaft abgeschaltet, weil alles
 * als „zu spät" markiert ankäme.
 *
 * Der Wert ist kein Schalter, sondern ein abgeleiteter Tatsachenwert: die Anwendung
 * kann ihn nicht setzen.
 *
 * Öffentliche API: Der Collector wertet diesen Wert aus — die Tabelle in Konzept 3.3.1
 * schreibt ihm je Zustand ein anderes Verhalten vor.
 */
enum DispatchPath: string
{
    /** In Phase B unmittelbar an den Broker gesendet. Keine Verzögerung. */
    case Direct = 'direct';

    /**
     * Planmäßig über den Spool, weil die Laufzeit die Antwort nicht abkoppeln kann
     * (mod_php) oder Spool-First erzwungen ist. Die Verzögerung ist begrenzt: höchstens
     * ein Drain-Intervall. Für die Echtzeit-Regeln weiterhin brauchbar, solange
     * spool_delay_ms unter der collectorseitigen Toleranz liegt.
     */
    case Deferred = 'deferred';

    /**
     * Nachlauf nach einem Broker-Ausfall. Die Verzögerung ist unbegrenzt und die
     * zugehörigen Zeitfenster sind längst ausgewertet — der Collector darf hier keine
     * Echtzeit-Zähler mehr erhöhen, sonst entstehen Phantom-Ausschläge. Nur Speicherung
     * und Batch-Regeln.
     */
    case Recovered = 'recovered';

    /**
     * Ob Events dieses Frames für die Echtzeit-Auswertung des Collectors in Frage
     * kommen. Die endgültige Toleranzentscheidung bei Deferred trifft der Collector
     * anhand von spool_delay_ms.
     */
    public function isEligibleForRealtime(): bool
    {
        return self::Recovered !== $this;
    }
}
