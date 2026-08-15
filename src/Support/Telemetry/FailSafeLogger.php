<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Telemetry;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Ein Logger, der nicht wirft — die eine Grenze, an der fail-open sonst leckt.
 *
 * WARUM ES DIESE KLASSE GIBT
 *
 * Das Bundle protokolliert an 19 Stellen, und jede davon lag im Request- oder
 * Versandpfad. Ein Logger kann werfen: Ein Monolog-Handler auf einem vollen
 * Dateisystem, ein Syslog-Socket, der wegbricht, ein Handler, der eine Netzwerkadresse
 * anspricht. Konzept 4. lässt dafür keinen Spielraum — „Eine Störung des IDS darf die
 * überwachte Anwendung unter keinen Umständen beeinträchtigen".
 *
 * Zwei dieser Stellen waren schlimmer als „Ausnahme entweicht". Im
 * {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher} steht der Logaufruf
 * im catch-Zweig VOR dem Spool-Rettungsversuch: Wirft er, wird der Frame nicht mehr
 * gespoolt und ist verloren. Im {@see \ProjektMotor\IdsSensor\Delivery\Transport\Spool\FileSpool}
 * steht er vor dem `return false`, mit dem der Aufrufer vom verworfenen Frame erfährt.
 * In beiden Fällen macht ausgerechnet der Versuch, einen Verlust zu MELDEN, den Verlust
 * größer.
 *
 * WARUM EIN DEKORATOR UND KEINE 19 try/catch
 *
 * Eine Zusage, die jede Aufrufstelle einzeln einhalten muss, ist keine — dieselbe
 * Begründung, mit der der `$onError`-Rückruf im
 * {@see \ProjektMotor\IdsSensor\Sensor\CaptureBudget} entfallen ist. Hier gilt sie
 * einmal, an der Grenze zur fremden Bibliothek (CLAUDE.md §1.7), und gilt damit auch
 * für jede künftig hinzukommende Aufrufstelle.
 *
 * Ein gescheiterter Logaufruf wird NICHT gezählt: Der Zähler reiste im Frame mit, und
 * der Frame geht über einen Weg, der gerade nachweislich unzuverlässig ist. Was hier
 * verloren geht, ist eine Diagnosemeldung — nicht ein Event.
 *
 * @internal
 */
final class FailSafeLogger extends AbstractLogger
{
    public function __construct(
        private readonly ?LoggerInterface $inner = null,
    ) {
    }

    /**
     * Die Parameter sind BEWUSST untypisiert.
     *
     * `composer.json` erlaubt `psr/log: ^1.1|^2.0|^3.0`, und 1.x deklariert
     * `log($level, $message, array $context = [])` ohne Typen. Ein Parametertyp hier
     * verengte die Signatur der Elternklasse — PHP lehnt das ab, und zwar mit einem
     * Fatal Error beim LADEN der Klasse. Das Bundle wäre unter der unteren Grenze seiner
     * eigenen Abhängigkeiten nicht installierbar. Der Rückgabetyp ist dagegen in beide
     * Richtungen zulässig.
     *
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        try {
            $this->inner?->log($level, $message, $context);
        } catch (\Throwable) {
            // Bewusst still und ohne zweiten Versuch: Wer hier scheitert, scheitert auch
            // beim Melden des Scheiterns.
        }
    }
}
