<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Dispatch;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rettet den Puffer, wenn der Prozess stirbt, bevor `kernel.terminate` läuft.
 *
 * WAS OHNE IHN PASSIERT
 *
 * Bei einem Fatal Error — erschöpfter Speicher, überschrittene Ausführungszeit, ein
 * `E_ERROR` aus einer Erweiterung — endet PHP sofort. Kein `kernel.terminate`, kein
 * Flush: Der Puffer stirbt mitsamt allen Events dieses Requests. Nicht gezählt, nicht
 * protokolliert, von einem stillen Sensor nicht zu unterscheiden.
 *
 * Konzept 4. schließt genau das aus: „Jeder verworfene oder verlorene Event wird
 * gezählt", weil ein stiller Ausfall gefährlicher ist als ein sichtbarer. Und die
 * betroffenen Requests sind selten die uninteressanten — ein OOM ist ein möglicher
 * Ausgang eines Speicherangriffs.
 *
 * WAS ER AUSDRÜCKLICH NICHT TUT
 *
 * Er synthetisiert KEIN `kernel.exception`-Event. Die Konfigurationsoption hieß einmal
 * `capture_fatal_errors` und versprach das („synthetisiert bei Fatal Errors ein
 * kernel.exception mit Status 500"), aber im Konzept steht davon nichts: Abschnitt 2.1.1
 * nennt Fatal-Fehler nur als Begründung dafür, warum `kernel.exception` wichtig ist — und
 * ein PHP-`TypeError` ist ein `\Error` und wird dort ohnehin erfasst. Ein erfundenes
 * Ereignis wäre eine Beobachtung, die niemand gemacht hat.
 *
 * Gerettet wird also, was der Sensor TATSÄCHLICH gesehen hat, bevor der Prozess starb.
 *
 * NUR IN DEN SPOOL
 *
 * Kein Collector-Versuch: Der Prozess stirbt gerade, sein Zustand ist unzuverlässig, und
 * ein Verbindungsversuch mit 20 ms Timeout überschritte das Shutdown-Budget aus
 * `budget.fatal_dispatch_ms` schon für sich genommen. Der Spool ist genau dafür da.
 *
 * @internal
 */
final class FatalErrorFlushListener implements EventSubscriberInterface
{
    /**
     * Fehlerarten, die den Prozess beenden und keinen Handler mehr zulassen.
     *
     * `E_USER_ERROR` gehört dazu: Es beendet den Prozess ebenso, auch wenn es die
     * Anwendung selbst ausgelöst hat.
     */
    private const FATAL = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR;

    private bool $registered = false;

    public function __construct(
        private readonly EventFlusher $flusher,
        // Konzept-nah, aber ohne eigene Grundlage: `budget.fatal_dispatch_ms` begrenzt,
        // was der Sensor im Shutdown noch tun darf. Überschritten wird die Frist nur
        // erkannt, nicht abgebrochen — PHP kann einen laufenden Schreibvorgang nicht
        // unterbrechen; der Wert dient dem Protokoll und der späteren Beurteilung.
        private readonly int $budgetMs = 15,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Sehr früh, damit die Shutdown-Funktion auch dann steht, wenn der Request
        // gleich darauf abbricht.
        return [KernelEvents::REQUEST => ['onRequest', 1024]];
    }

    /**
     * Registriert die Shutdown-Funktion genau einmal je Prozess.
     *
     * Nicht im Konstruktor: Der Dienst würde dann bei jedem Container-Aufbau eine
     * Funktion registrieren, auch in Konsolenläufen, die nie einen Request sehen.
     */
    public function onRequest(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        register_shutdown_function($this->onShutdown(...));
    }

    /**
     * Läuft bei JEDEM Prozessende — und tut fast immer nichts.
     *
     * Zwei Schranken davor, in dieser Reihenfolge:
     *
     *  1. Ein regulärer Ablauf hat den Puffer über `kernel.terminate` längst geleert;
     *     `drain()` im Flusher liefert dann nichts und wir sind sofort fertig.
     *  2. Nur ein FATALER Fehler rechtfertigt den Umweg. Bei einem sauberen Ende ohne
     *     Terminate — etwa `exit()` in einem Controller — bleibt der Puffer ebenfalls
     *     gefüllt, aber das ist eine Entscheidung der Anwendung und kein Verlust, den
     *     der Sensor melden müsste.
     */
    public function onShutdown(): void
    {
        $letzter = error_get_last();

        if (null === $letzter || 0 === ($letzter['type'] & self::FATAL)) {
            return;
        }

        $begonnen = hrtime(true);

        // Wirft nicht: flushToSpool() fängt alles. Ein Wurf hier überschriebe die
        // Fehlerausgabe der Anwendung — der letzte Ort, an dem der Sensor stören darf.
        $gerettet = $this->flusher->flushToSpool();

        if (0 === $gerettet || $this->budgetMs <= 0) {
            return;
        }

        $verbraucht = (hrtime(true) - $begonnen) / 1_000_000;

        if ($verbraucht <= $this->budgetMs) {
            return;
        }

        // Kein Abbruch, nur eine Spur: Der Schreibvorgang ist zu diesem Zeitpunkt
        // vorbei, und ihn zu unterbrechen hätte den Verlust erst erzeugt. Wer das im
        // Protokoll sieht, weiß, dass der Shutdown-Pfad teurer war als vorgesehen.
        error_log(\sprintf(
            'ids_sensor: Shutdown-Flush hat %.1f ms gebraucht, vorgesehen sind %d ms '
            .'(budget.fatal_dispatch_ms). %d Events wurden gerettet.',
            $verbraucht,
            $this->budgetMs,
            $gerettet,
        ));
    }
}
