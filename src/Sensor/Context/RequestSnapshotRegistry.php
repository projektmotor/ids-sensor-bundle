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
     * Liefert den Snapshot des Requests, mit Rückfall auf den Haupt-Request.
     *
     * Der Rückfall ist nötig, weil eine Exception auch auf einem Request auftreten
     * kann, den wir nie gesehen haben — etwa wenn ein Listener mit noch höherer
     * Priorität als unserer abbricht. Ohne Rückfall hätte das Folge-Event dann keinen
     * Pfad, und die Feldredundanz aus Konzept 3.2 wäre gerade im Fehlerfall
     * unvollständig.
     */
    public function get(?Request $request): ?RequestSnapshot
    {
        if (null !== $request && isset($this->snapshots[$request])) {
            return $this->snapshots[$request];
        }

        return $this->mainSnapshot;
    }

    public function mainSnapshot(): ?RequestSnapshot
    {
        return $this->mainSnapshot;
    }

    public function has(Request $request): bool
    {
        return isset($this->snapshots[$request]);
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
