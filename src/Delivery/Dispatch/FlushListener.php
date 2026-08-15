<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\EmitterInterface;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Löst den Flush aus — an jedem Punkt, an dem ein Durchlauf endet.
 *
 * `kernel.terminate` ist der wichtigste davon: dieses Event läuft, NACHDEM die
 * Antwort den Client erreicht hat. Unter PHP-FPM ruft Response::send() intern
 * fastcgi_finish_request() auf, die Verbindung ist dann geschlossen und das Skript
 * läuft weiter. Alles ab hier kostet keine Antwortzeit mehr.
 *
 * Kein Widerspruch zu Konzept 2.1.1: dort wird kernel.terminate bewusst NICHT als
 * Sensor-Event geführt, weil es keine über kernel.response hinausgehende Information
 * liefert. Wir emittieren hier auch kein Event — wir nutzen den Zeitpunkt lediglich
 * als Versandfenster. Bitte nicht „korrigieren".
 *
 * Die Console- und Worker-Ereignisse sind nötig, weil dort nie ein
 * kernel.terminate feuert: ein Messenger-Worker oder ein Import-Command würde seine
 * Business-Events sonst bis zum Prozessende puffern oder bei SIGTERM verlieren.
 *
 * @internal
 */
final class FlushListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EventFlusher $flusher,
        private readonly ?EmitterInterface $heartbeat = null,
        // Konzept 4.: „Hartes Timeout von 50 ms; danach Abbruch des Versands, der Request
        // läuft normal weiter." 0 hebt die Frist auf.
        private readonly int $dispatchBudgetMs = 50,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            // Hohe Priorität, damit der Versand läuft, bevor andere
            // terminate-Listener der Anwendung eventuell den Prozess beenden.
            KernelEvents::TERMINATE => ['onKernelTerminate', 1024],
        ];

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', 1024];
        }

        if (class_exists(WorkerMessageHandledEvent::class)) {
            $events[WorkerMessageHandledEvent::class] = ['onWorkerMessageHandled', 1024];
            $events[WorkerMessageFailedEvent::class] = ['onWorkerMessageFailed', 1024];
        }

        return $events;
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->flushAndBeat();
    }

    public function onConsoleTerminate(): void
    {
        $this->flushAndBeat();
    }

    /**
     * Nach jeder verarbeiteten Nachricht flushen, nicht erst am Prozessende: ein
     * Worker läuft Stunden, und ein SIGKILL zwischendurch verliert alles Gepufferte.
     */
    public function onWorkerMessageHandled(): void
    {
        $this->flushAndBeat();
    }

    public function onWorkerMessageFailed(): void
    {
        $this->flushAndBeat();
    }

    /**
     * Erst versenden, dann das Lebenszeichen.
     *
     * Die Reihenfolge ist inhaltlich: der Heartbeat trägt die Zählerstände, und die sollen
     * den gerade abgeschlossenen Durchlauf einschließen. Umgekehrt wäre jeder Heartbeat um
     * einen Request veraltet — bei einer Instanz mit wenig Verkehr um Minuten.
     *
     * `Mode::Request` heißt hier „durch Anwendungsaktivität ausgelöst" und deckt
     * auch console.terminate und die Worker-Ereignisse ab. Der Gegenbegriff ist nicht
     * „HTTP", sondern der eigens dafür eingerichtete Command: nur der liefert auch dann,
     * wenn die Anwendung gar nichts tut.
     *
     * BEIDE AUFRUFE SIND GEFASST
     *
     * Dass der Flusher selbst jedes Throwable fängt, genügt nicht: sein `finally`-Zweig
     * ruft den LatencyRecorder und sein `catch`-Zweig den Logger. Wirft eines von beidem
     * — ein Monolog-Handler auf voller Platte genügt —, verlässt die Exception `flush()`
     * und landet unmittelbar in kernel.terminate der überwachten Anwendung. Genau der
     * Fall, den Konzept 4. ausschließt.
     *
     * NICHT abgedeckt ist der Wurf beim ERZEUGEN des Flushers: sein Dienstgraph reicht
     * bis zum Messenger-Transport, und dessen Factory wirft bei unbrauchbarer DSN. Das
     * passiert, bevor diese Methode läuft. Dagegen hilft nur, den Transport lazy zu
     * bauen — {@see \ProjektMotor\IdsSensor\DependencyInjection\Compiler\LazyTransportPass}.
     */
    private function flushAndBeat(): void
    {
        $begonnen = hrtime(true);

        try {
            $this->flusher->flush();
        } catch (\Throwable) {
            // bewusst still und ohne Zähler: wer hier ankommt, hat entweder keinen
            // Zähler mehr (der Dienstgraph steht nicht) oder ihn beim Loggen verloren.
        }

        if ($this->budgetSpent($begonnen)) {
            return;
        }

        // Der Emitter fängt selbst jedes Throwable. Der zweite Schutz hier ist trotzdem
        // richtig: dieser Listener läuft in kernel.terminate der überwachten Anwendung, und
        // fail-open gilt ohne Ausnahme (Konzept 4.).
        try {
            $this->heartbeat?->emitIfDue(Mode::Request);
        } catch (\Throwable) {
            // bewusst still — ein Lebenszeichen ist keine Aufgabe, für die eine fremde
            // Anwendung einen Fehler sehen darf.
        }
    }

    /**
     * Ob das Versandbudget aus Konzept 4. aufgebraucht ist.
     *
     * „Hartes Timeout von 50 ms; danach Abbruch des Versands, der Request läuft normal
     * weiter." Durchsetzbar ist das nur ZWISCHEN Broker-Operationen — PHP kann einen
     * laufenden Syscall nicht abbrechen, und genau so steht es auch in
     * doc/08-konfiguration.md.
     *
     * Im Request-Pfad gibt es genau eine solche Naht: zwischen dem Frame und dem
     * Lebenszeichen. Hat der Frame das Budget schon verbraucht — ein Broker, der
     * schleppend antwortet, statt sauber zu scheitern —, entfällt der Heartbeat. Er ist
     * die verzichtbare der beiden Sendungen: Er wiederholt sich im nächsten Intervall
     * von selbst, während die Events dieses Requests einmalig sind.
     *
     * Dass die Grenze damit nicht scharf ist, steht schon im Konzept. Sie begrenzt, was
     * der Sensor OBENDRAUF legt, nicht die Dauer eines einzelnen Syscalls; dafür sind die
     * Broker-Timeouts in `TRANSPORT_DEFAULTS` zuständig.
     */
    private function budgetSpent(float|int $begonnen): bool
    {
        if ($this->dispatchBudgetMs <= 0) {
            return false;
        }

        return (hrtime(true) - $begonnen) > $this->dispatchBudgetMs * 1_000_000;
    }
}
