<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Öffnet den Korrelationsbereich eines Console-Laufs.
 *
 * Hängt an `console.command` mit hoher Priorität, damit die Kennung steht, bevor der
 * Command seine erste Zeile ausführt — ein Business-Event aus dem Konstruktor eines
 * Dienstes, den der Command anfordert, gehört bereits dazu.
 *
 * Ein Gegenstück an `console.terminate` gibt es bewusst nicht: dort läuft der Versand
 * ({@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener}), und was danach noch
 * erfasst wird, gehört immer noch zu diesem Lauf. Der Prozess endet ohnehin gleich; das
 * Aufräumen wäre eine Aufräumhandlung ohne Nutzen und mit einer Reihenfolgefalle.
 *
 * @internal
 */
final class ConsoleCorrelationListener implements EventSubscriberInterface
{
    public function __construct(private readonly ConsoleCorrelation $correlation)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        if (!class_exists(ConsoleEvents::class)) {
            return [];
        }

        return [ConsoleEvents::COMMAND => ['onConsoleCommand', 1024]];
    }

    public function onConsoleCommand(): void
    {
        $this->correlation->begin();
    }
}
