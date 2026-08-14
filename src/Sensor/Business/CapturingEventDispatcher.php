<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Business;

use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hört am EventDispatcher mit und greift Business-Events ab (capture_mode: dispatcher).
 *
 * WARUM ES DIESEN DECORATOR ÜBERHAUPT GIBT
 *
 * Konzept 2.1.3 schlägt vor, der Sensor solle „generisch alle Events, die dieses
 * Interface implementieren" abonnieren, „z. B. via Symfony-EventDispatcher-Tagging".
 * Diese Mechanik existiert nicht. Symfonys EventDispatcher löst Listener über den
 * exakten Event-Namen auf:
 *
 *     $eventName ??= $event::class;
 *     $listeners = $this->getListeners($eventName);   // reiner Array-Key-Lookup
 *
 * Kein instanceof, kein Ablaufen der Klassenhierarchie, keine Interface-Prüfung. Ein
 * Listener für SecurityRelevantBusinessEvent::class feuert deshalb NIE — und zwar
 * stumm, ohne Fehlermeldung. Die Business-Ebene wäre scheinbar aktiv und faktisch
 * leer: genau der blinde Fleck, den Konzept 2.1.3 als „vollständigen blinden Fleck"
 * für die Szenarien S6, S7 und S9 beschreibt.
 *
 * DER VORTEIL DIESES WEGS
 *
 * Die Fachlogik bleibt frei von IDS-Referenzen: sie dispatcht ihr Domain-Event wie
 * gewohnt. Damit ist das Bundle rückstandslos entfernbar — ohne es läuft die Anwendung
 * weiter und dispatcht Events, denen niemand zuhört. Und man kann das Melden nicht
 * vergessen, wenn ohnehin dispatcht wird.
 *
 * DER PREIS UND ZWEI HARTE REGELN
 *
 * Dieser Decorator sitzt auf einem der zentralsten Services von Symfony. Daraus folgt:
 *
 *  1. `$this->inner->dispatch()` liegt NIEMALS im try-Block. Nur die eigene Erfassung
 *     ist abgesichert. Andernfalls könnte ein Fehler im Sensor das Dispatchen der
 *     Anwendung verhindern — und aus einer IDS-Störung würde ein Anwendungsausfall.
 *  2. Registrierung mit decoration_priority 255, also AUSSERHALB von
 *     TraceableEventDispatcher. So bleibt das Dev-Profiling unangetastet.
 *
 * ZU DEN SIGNATUREN VON addListener/removeListener/getListenerPriority
 *
 * Sie lauten hier `callable|array`, obwohl EventDispatcherInterface nur `callable`
 * deklariert. Das ist Absicht und folgt Symfonys eigener EventDispatcher-Klasse, die
 * ebenfalls aufweitet: Lazy Listener werden als Array `[$serviceId, 'method']`
 * übergeben, und das ist noch KEIN gültiges Callable — die Auflösung passiert erst
 * beim Aufruf. Mit dem engeren `callable` scheitert die Weitergabe zur Laufzeit an
 * einem TypeError, sobald TraceableEventDispatcher einen solchen Listener durchreicht.
 * Statische Analyse, die sich am Interface orientiert, beanstandet das — die
 * Laufzeitrichtigkeit hat hier Vorrang (siehe ignoreErrors in phpstan.neon.dist).
 *
 * @internal
 */
final class CapturingEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $inner,
        private readonly EventSensor $sensor,
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        // Ein instanceof-Vergleich, im Nanosekundenbereich. Läuft für jedes
        // dispatchte Event, einschließlich der kernel.*-Events des Frameworks.
        if ($event instanceof SecurityRelevantBusinessEvent) {
            try {
                $this->sensor->capture($event);
            } catch (\Throwable) {
                // Bewusst stumm: an dieser Stelle steht kein Logger zur Verfügung, ohne
                // eine weitere Abhängigkeit auf einen zentralen Service zu nehmen. Der
                // Sensor selbst protokolliert seine Fehler.
            }
        }

        // MUSS außerhalb jedes try stehen und immer laufen.
        return $this->inner->dispatch($event, $eventName);
    }

    /**
     * @param callable|array<array-key, mixed> $listener
     */
    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    /**
     * @param callable|array<array-key, mixed> $listener
     */
    public function removeListener(string $eventName, callable|array $listener): void
    {
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    /**
     * @return array<callable[]|callable>
     */
    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    /**
     * @param callable|array<array-key, mixed> $listener
     */
    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }
}
