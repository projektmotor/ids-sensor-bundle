<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Exception;

/**
 * Dieser Frame wird nie versendbar sein — ein zweiter Versuch heilt ihn nicht.
 *
 * WARUM ES GENAU EINE EIGENE EXCEPTION GIBT
 *
 * Das Bundle ist fail-open (Konzept 4.) und wirft grundsätzlich nicht nach außen. Wer
 * nach außen nicht wirft, braucht auch keinen Typ zum Fangen — deshalb existiert für
 * die übrigen zehn throw-Stellen keine eigene Klasse: sie liegen alle in der
 * Compile-Zeit oder in Programmierfehler-Pfaden.
 *
 * Hier ist es anders, und der Grund liegt im Spool. {@see \ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolDrainer}
 * unterscheidet zwei Fälle:
 *
 *  - eine unlesbare Zeile wird verworfen („verwerfen statt endlos wiederholen"),
 *  - ein gescheiterter Versand wird aufgehoben und beim nächsten Lauf erneut versucht
 *    — und danach bricht der Drainer ab und hebt den GESAMTEN Rest der Datei auf.
 *
 * Für einen Broker-Ausfall ist das genau richtig. Für einen Frame, der aus sich heraus
 * nie versendbar ist, bedeutet es Head-of-Line-Blocking: eine einzelne vergiftete Zeile
 * hält die ganze Spool-Datei auf Dauer fest, weil der Drainer „Broker weg" nicht von
 * „dieser Frame geht nie" unterscheiden kann.
 *
 * Diese Exception ist genau diese Unterscheidung. Sie wird vor dem allgemeinen
 * \Throwable gefangen; die Zeile wird verworfen statt aufgehoben, und der Rest der
 * Datei läuft weiter.
 *
 * @internal
 */
final class UnshippableFrameException extends \RuntimeException
{
}
