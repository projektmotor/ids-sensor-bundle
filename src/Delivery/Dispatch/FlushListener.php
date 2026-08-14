<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Emitter;
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
        private readonly ?Emitter $heartbeat = null,
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
     */
    private function flushAndBeat(): void
    {
        $this->flusher->flush();

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
}
