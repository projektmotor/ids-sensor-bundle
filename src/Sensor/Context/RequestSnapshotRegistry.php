<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ordnet jedem Request seinen Snapshot zu.
 *
 * Umsetzung über \WeakMap und ausdrücklich NICHT über $request->attributes:
 *
 *  - attributes gehört der überwachten Anwendung. Ein Eintrag dort ist für
 *    Controller sichtbar, taucht in dump()-Ausgaben auf und wird von
 *    Request::duplicate() mitkopiert. Der Sensor soll nichts am Zustand der
 *    Anwendung verändern.
 *  - Eine WeakMap räumt sich auf, sobald der Request nicht mehr referenziert wird.
 *    Ein Array mit spl_object_id als Schlüssel würde in langlebigen Prozessen
 *    (Messenger-Worker) unbemerkt wachsen.
 *
 * Konzept 3.2 spricht von „einem Request-Scoped Service" — das ist hier erfüllt und
 * funktioniert zusätzlich bei verschachtelten Sub-Requests, wo ein einzelner
 * Speicherplatz falsch wäre.
 *
 * @internal
 */
final class RequestSnapshotRegistry implements ResetInterface
{
    /** @var \WeakMap<Request, RequestSnapshot> */
    private \WeakMap $snapshots;

    private ?RequestSnapshot $mainSnapshot = null;

    public function __construct()
    {
        /** @var \WeakMap<Request, RequestSnapshot> $map */
        $map = new \WeakMap();
        $this->snapshots = $map;
    }

    public function set(Request $request, RequestSnapshot $snapshot): void
    {
        $this->snapshots[$request] = $snapshot;

        if ($snapshot->isMainRequest) {
            $this->mainSnapshot = $snapshot;
        }
    }

    /**
     * Liefert den Snapshot GENAU DIESES Requests — oder null.
     *
     * KEIN RÜCKFALL AUF DEN HAUPT-REQUEST
     *
     * Hier stand einer, mit der Begründung, ein Folge-Event hätte sonst keinen Pfad.
     * Er bewertete fehlende Daten als schlimmer als falsche, und das ist für ein
     * Beweissystem die falsche Richtung: Wer den Snapshot eines FREMDEN Requests
     * bekommt, erbt dessen `correlationId`, `path`, `route`, `contentLength` und
     * `startedAt`. Die Folgen sind einzeln nachweisbar — `elapsedMs()` rechnet gegen
     * eine fremde Startzeit, und die Events zweier verschiedener Anfragen hängen an
     * derselben Spur. Genau die Verkettung, auf der die Regeln X1–X4 aus Konzept 4.3.3
     * aufbauen, wäre damit still verfälscht.
     *
     * Die Sensoren kommen ohne Snapshot aus: {@see \ProjektMotor\IdsSensor\Sensor\Kernel\ResponseSensor}
     * und {@see \ProjektMotor\IdsSensor\Sensor\Kernel\ExceptionSensor} lesen den Pfad
     * dann unmittelbar aus dem Request, und {@see CapturedEventBinder} baut den Akteur
     * weiterhin aus dem Request. Verloren geht nur, was ohne Snapshot tatsächlich
     * unbekannt ist.
     *
     * Für den einen Fall, in dem der Haupt-Request wirklich gemeint ist — die
     * Vererbung der correlation_id an Sub-Requests —, gibt es {@see mainSnapshot()}.
     */
    public function get(?Request $request): ?RequestSnapshot
    {
        if (null === $request) {
            return null;
        }

        return $this->snapshots[$request] ?? null;
    }

    public function mainSnapshot(): ?RequestSnapshot
    {
        return $this->mainSnapshot;
    }

    /**
     * Wird in Worker-Laufzeiten zwischen zwei Requests aufgerufen. Die WeakMap
     * selbst räumt sich auf; nur die starke Referenz auf den Haupt-Snapshot muss
     * gelöst werden, damit sie den vorigen Request nicht am Leben hält.
     */
    public function reset(): void
    {
        $this->mainSnapshot = null;
    }
}
