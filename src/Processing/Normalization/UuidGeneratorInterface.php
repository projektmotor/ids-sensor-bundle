<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

/**
 * Erzeugt die Kennungen, die der Sensor selbst vergibt: event_id und frame_id.
 *
 * Hieß bis Fassung 3 EventIdGeneratorInterface. Seit der Frame eine eigene Kennung
 * trägt (Konzept 3.3), vergibt derselbe Dienst beide — ein zweites Paar aus
 * Interface und Vier-Zeilen-Klasse wäre Duplikation gewesen, der alte Name an der
 * neuen Verwendungsstelle eine Unwahrheit: Eine frame_id ist keine event_id.
 *
 * Als Interface, damit Tests deterministische IDs einsetzen können — sonst wären
 * die Golden Files (der Formatvertrag gegenüber dem Collector) nicht vergleichbar.
 *
 * @internal
 */
interface UuidGeneratorInterface
{
    public function generate(): string;
}
