<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Heftet Korrelation und Akteur an ein erfasstes Event.
 *
 * WOZU
 *
 * Fünf Sensoren taten das bis zur Zusammenlegung mit derselben acht Zeilen langen
 * if/else-Kaskade. Die Duplikation hatte bereits eine Abweichung erzeugt: der
 * Business-Sensor setzte im else-Zweig keine leere correlation_id, anders als seine
 * vier Geschwister. Folgenlos blieb das nur, weil alle drei Normalisierer mit
 * `correlationId() ?? ''` gegensteuern — also weil die Duplikation schon eine Ebene
 * tiefer durchgeschlagen war.
 *
 * ZWEI METHODEN STATT EINES SCHALTERS
 *
 * `bind()` und `bindWithUser()` sind kein verkleideter Flag-Parameter, sondern genau
 * die Achse, entlang der sich die Aufrufstellen systematisch unterscheiden — und die
 * beiden Rückfälle ohne HTTP-Kontext gehören widerspruchsfrei dazu:
 *
 *  - MIT Kennung, Rückfall {@see ActorFactory::forCli()}: Kernel-Ebene. Dort ist die
 *    Firewall durch, der Token liegt im Speicher, und ohne Request bleibt immerhin
 *    die Kennung (Console, Worker).
 *  - OHNE Kennung, Rückfall {@see Actor::anonymous()}: Security- und Business-Ebene.
 *    Dort trägt der Sensor die Kennung gleich selbst nach — bei kernel.request greift
 *    die Firewall noch nicht (Konzept 2.2.2), beim Anmeldefehlschlag gibt es keinen
 *    Token, und die Business-Ebene bevorzugt getActorId() gegenüber dem Token.
 *
 * Was danach kommt — Nachtrag der Kennung, die ip_from_request-Regel, die Wahl
 * zwischen guard() und guardMandatory() — bleibt im jeweiligen Sensor sichtbar. Der
 * Binder nimmt nur ab, was überall gleich war.
 *
 * @internal
 */
final class CapturedEventBinder
{
    public function __construct(
        private readonly RequestSnapshotRegistry $registry,
        private readonly ActorFactory $actorFactory,
        private readonly ConsoleCorrelation $consoleCorrelation,
    ) {
    }

    /**
     * Der Snapshot zu diesem Request, sofern es einen gibt.
     *
     * Toleriert null aus zwei Gründen: zwei Sensoren gehen über den RequestStack und
     * finden in CLI-, Worker- und Cron-Läufen keinen Request, und seit
     * {@see RequestSnapshotRegistry::get()} nicht mehr auf den Haupt-Request zurückfällt
     * kann es auch zu einem vorhandenen Request keinen Snapshot geben. Beides trennt
     * `bind()` sauber: ohne Request bleibt der Akteur anonym, ohne Snapshot wird er
     * weiterhin aus dem Request gebaut — nur die correlation_id fehlt dann.
     */
    public function snapshotFor(?Request $request): ?RequestSnapshot
    {
        return null !== $request ? $this->registry->get($request) : null;
    }

    /**
     * Setzt Korrelation und Akteur OHNE Benutzerkennung.
     */
    public function bind(CapturedEvent $captured, ?Request $request, ?RequestSnapshot $snapshot): void
    {
        if (null !== $request) {
            $captured->setCorrelationId(null !== $snapshot ? $snapshot->correlationId : '');
            $captured->setActor($this->actorFactory->forRequestWithoutUser($request, $snapshot));

            return;
        }

        $captured->setCorrelationId($this->correlationWithoutRequest());
        $captured->setActor(Actor::anonymous());
    }

    /**
     * Setzt Korrelation und Akteur EINSCHLIESSLICH der Benutzerkennung aus dem Token.
     */
    public function bindWithUser(CapturedEvent $captured, ?Request $request, ?RequestSnapshot $snapshot): void
    {
        if (null !== $request) {
            $captured->setCorrelationId(null !== $snapshot ? $snapshot->correlationId : '');
            $captured->setActor($this->actorFactory->forRequest($request, $snapshot));

            return;
        }

        $captured->setCorrelationId($this->correlationWithoutRequest());
        $captured->setActor($this->actorFactory->forCli());
    }

    /**
     * Die Kennung des angemeldeten Nutzers, ohne Rollen-Lookup und ohne Nachladen.
     */
    public function currentUser(): ?string
    {
        return $this->actorFactory->currentUser();
    }

    /**
     * Die Korrelation außerhalb eines Requests: die des Console-Laufs, sonst leer.
     *
     * Der Leerstring bleibt für Prozesse, die weder Request noch Command sind — ein
     * eingebundenes Skript, ein Test. Konzept 2.2.4 legt ihn ausdrücklich als „kein
     * zuordenbarer Durchlauf" fest; ein `null` verbietet 4.2.1 (`TEXT NOT NULL`).
     */
    private function correlationWithoutRequest(): string
    {
        return $this->consoleCorrelation->correlationId() ?? '';
    }
}
