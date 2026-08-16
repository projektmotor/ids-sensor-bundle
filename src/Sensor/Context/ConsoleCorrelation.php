<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

/**
 * Hält die correlation_id eines Console-Laufs.
 *
 * WOZU
 *
 * Konzept Abschnitt 3 führt `correlation_id` als Pflichtfeld, 4.2.1 als `TEXT NOT NULL`.
 * Außerhalb eines Requests gab es dafür keinen Wert: die Sensoren setzten den Leerstring,
 * und damit trugen ALLE Events aller Console-Läufe und aller Worker dieselbe Kennung. Der
 * `correlation_id`-Self-Join aus Konzept 3.2 — indiziert über `idx_evr_correlation_id`
 * (4.2.2) — führte sie zu einer einzigen „Anfrage" zusammen, die mit jedem weiteren Lauf
 * wuchs. Der Constraint hielt, die Semantik nicht.
 *
 * Ein Console-Lauf ist die Entsprechung zum Request: ein abgeschlossener Durchlauf mit
 * einem Anfang. {@see ConsoleCorrelationListener} erzeugt die Kennung an
 * `console.command`, {@see CapturedEventBinder} setzt sie an jedes Event, das keinen
 * Request hinter sich hat.
 *
 * GRENZE, DIE BENANNT GEHÖRT
 *
 * `messenger:consume` IST ein Command. Ein Worker, der Stunden läuft, bündelt damit alle
 * seine Business-Events unter einer Kennung. Das ist gegenüber dem Leerstring ein Gewinn
 * — die Spur endet am Prozess statt an der Installation —, aber es ist keine Kennung je
 * Nachricht. Wer die braucht, löst sie über ein eigenes Feld im Payload.
 *
 * KEIN ResetInterface
 *
 * Absichtlich nicht: Symfonys `services_resetter` läuft im Worker nach jeder Nachricht.
 * Würde die Kennung dabei verworfen, käme sie nie zurück — `console.command` feuert im
 * ganzen Worker-Lauf genau einmal, und die Events ab der zweiten Nachricht fielen auf den
 * Leerstring zurück, den diese Klasse gerade beseitigt.
 *
 * @internal
 */
final class ConsoleCorrelation
{
    private ?string $correlationId = null;

    public function __construct(private readonly CorrelationIdFactory $factory)
    {
    }

    /**
     * Beginnt einen Lauf — sofern nicht schon einer läuft.
     *
     * Ein Command, der einen anderen aufruft (`$application->run()`), löst ein zweites
     * `console.command` aus. Beide gehören zu demselben Durchlauf, also behält der äußere
     * seine Kennung: eine neue würde die Events desselben Laufs auseinanderreißen und
     * hinterließe nach dem inneren Command eine Kennung, die keinen Anfang hat.
     */
    public function begin(): void
    {
        $this->correlationId ??= $this->factory->generate();
    }

    /**
     * Die Kennung dieses Laufs, oder null außerhalb eines Console-Laufs.
     */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }
}
