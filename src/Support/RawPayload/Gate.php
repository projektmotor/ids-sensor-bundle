<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\RawPayload;

use ProjektMotor\IdsEventData\Vocabulary\Severity;

/**
 * Entscheidet, ob ein Event sein raw-Feld überhaupt behalten darf.
 *
 * Konzept Abschnitt 3 legt „nur warning und critical" fest; `ids_sensor.raw.severities`
 * erlaubt, das weiter einzuschränken (etwa auf `critical` allein, wenn das Volumenbudget
 * aus 4.2.3 gerissen wird).
 *
 * Die Entscheidung fällt in der {@see \ProjektMotor\IdsSensor\Processing\Normalization\EventFactory} und
 * damit an der Stelle, an der Severity und raw-Closure zum ersten Mal beide vorliegen.
 * Wird raw hier verworfen, wird die Closure NIE aufgerufen — die Kosten für
 * Header-Kopie, Redaktion und Trace-Aufbau entstehen also gar nicht.
 *
 * DIE AUSNAHMELISTE (Konzept 4.5.2, offener Punkt OB11)
 *
 * Die Stufenregel allein erzeugte eine Lücke: Ob `raw` mitreist, hängt an
 * `event_severity`, ein Alarm entsteht aber erst im Collector und kann nicht
 * zurückwirken. Ein Befund wie R2b („Pfadlisten-Treffer mit Status 200") stand damit
 * ohne forensischen Beleg da — das Event ist `info`, also war das `raw` längst
 * verworfen, als der Alarm entstand.
 *
 * Der Sensor kann das nicht selbst entscheiden: Er kennt die Erkennungsregeln des
 * Collectors nicht, und das ist Absicht (Konzept 2.). Was er kann, ist auf Anweisung
 * des Betreibers zusätzliche Kandidaten mitschicken. `raw.always_for` ist diese
 * Anweisung — leer als Vorgabe, also unverändertes Verhalten für alle, die nichts
 * einstellen.
 *
 * Der Collector filtert danach weiter: Er kennt die Regeln und entscheidet endgültig,
 * was er behält. Die Aufgabenteilung ist damit die richtige herum — der Sensor liefert
 * Kandidaten, der Collector wählt aus.
 *
 * WER DIE LISTE BENUTZT, GIBT EINE GRENZE AUF
 *
 * `raw` macht laut Konzept 4.2.3 über 95 % des Datenvolumens aus, und `info` ist die
 * Masse aller Events. Eine weit gefasste Liste — `#^/#` etwa — hebt das Volumenbudget
 * um Größenordnungen. Die Liste ist für einzelne, benannte Pfade gedacht; wer sie
 * öffnet, tut es sehenden Auges.
 *
 * @internal
 */
final class Gate
{
    /** @var array<string, true> */
    private array $allowed = [];

    /** @var array<string, true> */
    private array $alwaysEventTypes = [];

    /**
     * @param list<string> $severities
     * @param list<string> $alwaysEventTypes   event_type-Werte, die raw auch bei info tragen
     * @param list<string> $alwaysPathPatterns PCRE-Muster gegen payload.path, gleiche Wirkung
     */
    public function __construct(
        private readonly bool $enabled = true,
        array $severities = ['warning', 'critical'],
        array $alwaysEventTypes = [],
        private readonly array $alwaysPathPatterns = [],
    ) {
        foreach ($severities as $severity) {
            $this->allowed[$severity] = true;
        }

        foreach ($alwaysEventTypes as $eventType) {
            $this->alwaysEventTypes[$eventType] = true;
        }
    }

    public function allows(Severity $severity, string $eventType, ?string $path = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->allowsBySeverity($severity)) {
            return true;
        }

        // Erst hier wird die eingebaute Stufengrenze durchbrochen, und zwar nur auf
        // ausdrückliche Anweisung. Ohne Eintrag ist der Zweig wirkungslos.
        return $this->isNamedCandidate($eventType, $path);
    }

    private function allowsBySeverity(Severity $severity): bool
    {
        // Die eingebaute Grenze aus Konzept Abschnitt 3: die Konfiguration kann die
        // Menge der Stufen verkleinern, nicht vergrößern. `raw` für JEDES info-Event
        // risse das Volumenbudget aus 4.2.3 um Größenordnungen — die Ausnahmeliste
        // unten benennt stattdessen einzelne Fälle.
        if (!$severity->carriesRaw()) {
            return false;
        }

        return isset($this->allowed[$severity->value]);
    }

    private function isNamedCandidate(string $eventType, ?string $path): bool
    {
        if (isset($this->alwaysEventTypes[$eventType])) {
            return true;
        }

        if (null === $path) {
            return false;
        }

        foreach ($this->alwaysPathPatterns as $pattern) {
            if (1 === @preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
