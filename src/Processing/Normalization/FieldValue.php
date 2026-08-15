<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

/**
 * Die beiden Umformungen, die jedes normalisierte Feld durchläuft.
 *
 * Ein erfasstes Event trägt seine Nutzlast als `mixed` — die Sensoren legen dort ab, was
 * die Anwendung liefert, und prüfen im Request bewusst nichts nach (Konzept 2.1: unter dem
 * Erfassungsbudget wird gesammelt, nicht geformt). Erst hier wird daraus ein Wert, den das
 * Drahtformat zulässt: ein String oder null, und nie länger als die Obergrenze, die
 * {@see \ProjektMotor\IdsEventData\Payload\KernelPayload} beziehungsweise
 * {@see \ProjektMotor\IdsEventData\Payload\SecurityPayload} für das Feld nennt.
 *
 * Stand vorher zweimal wörtlich in KernelEventNormalizer und SecurityEventNormalizer, das
 * Stringisieren zusätzlich unter zwei Namen (`stringOrNull` und `str`). Zwei Namen für
 * dieselbe Sache ist genau der Fall, vor dem CLAUDE.md §1.1 warnt — man liest sie als zwei
 * Dinge und sucht nach dem Unterschied.
 *
 * Statisch und ohne Zustand: das sind Funktionen, keine Abhängigkeiten. §1.8 verbietet
 * statische Aufrufe für Kollaborateure, nicht für reine Umformungen.
 *
 * NICHT hier: {@see \ProjektMotor\IdsSensor\Sensor\Security\ResourceIdentifierResolver}
 * kürzt ebenfalls, liegt aber in Phase A. Ein Import von dort nach Normalization/ ist
 * verboten (ArchitectureTest::testSensorDoesNotKnowNormalization), und er kürzt auf eine
 * eigene Obergrenze, die kein Feld des Drahtformats ist.
 *
 * @internal
 */
final class FieldValue
{
    /**
     * Null bleibt null, Skalares wird String, alles andere gilt als nicht darstellbar.
     *
     * Arrays und Objekte werden ausdrücklich zu null und nicht etwa serialisiert: was ein
     * Feld des Drahtformats aufnimmt, steht in Konzept Abschnitt 3, und ein
     * hineingedrücktes `Array` oder ein Klassenname wäre dort ein Wert, den keine Regel
     * auswerten kann.
     */
    public static function asString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * Kürzt auf $max Zeichen — mb_*, weil die Obergrenzen Zeichen zählen und nicht Bytes.
     *
     * Ein bytebasiertes substr() könnte eine UTF-8-Sequenz mitten durchschneiden; das
     * Ergebnis wäre kein gültiger JSON-String mehr und der ganze Frame unversendbar.
     */
    public static function truncate(?string $value, int $max): ?string
    {
        if (null === $value) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
