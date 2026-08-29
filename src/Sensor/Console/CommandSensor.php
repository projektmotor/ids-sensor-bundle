<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Console;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Erfasst console.command und console.error.
 *
 * WOZU
 *
 * Konzept 6.2 führt das als offenen Punkt E1: Console-Commands, Messenger-Worker und
 * Cronjobs erzeugen keines der HttpKernel-Ereignisse, und ein Angreifer mit
 * Codeausführung arbeitet genau dort. Bis hierher sah das Bundle von der Konsole nur
 * die Korrelationskennung ({@see \ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelationListener})
 * und den Versandzeitpunkt ({@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener});
 * beobachtet wurde nichts.
 *
 * WARUM DIE EBENE `kernel` BLEIBT
 *
 * Ein vierter Wert in `Vocabulary\Layer` wäre ein neuer Fall in einem geschlossenen
 * Vokabular und damit ein Fassungswechsel des Ereignisformats samt Migration der
 * collectorseitigen ENUM-Spalte (Konzept 3.7). `event_type` ist dagegen offen. Die
 * Ebene heißt deshalb nach dem Einstiegspunkt des Frameworks statt nach HTTP.
 *
 * PRIORITÄT 512
 *
 * Unter dem ConsoleCorrelationListener (1024), damit die Kennung des Laufs bereits
 * steht — sonst trüge ausgerechnet das erste Ereignis des Laufs den Leerstring und
 * fiele aus der Spur heraus, die es eröffnet.
 *
 * @internal
 */
final class CommandSensor implements EventSubscriberInterface
{
    public const PRIORITY = 512;

    /**
     * Der Platzhalter für einen Lauf ohne auflösbaren Befehl.
     *
     * `getCommand()` ist nullable, und der Fall ist real: Bei einem unbekannten
     * Befehlsnamen wirft die Application, bevor ein Command-Objekt existiert. Ein
     * Ereignis ohne Namen ist trotzdem eines — es sagt, dass jemand etwas versucht
     * hat, das es nicht gibt.
     */
    public const UNKNOWN_COMMAND = '(unbekannt)';

    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly CapturedEventBinder $binder,
        private readonly CaptureBudget $budget,
        private readonly Options $options,
        private readonly Builder $rawBuilder,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // symfony/console steht nur unter `suggest`. Ohne die Komponente gibt es die
        // Ereignisse nicht, und ein Abonnement darauf ließe den Container brechen.
        if (!class_exists(ConsoleEvents::class)) {
            return [];
        }

        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', self::PRIORITY],
            ConsoleEvents::ERROR => ['onConsoleError', self::PRIORITY],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            $command = self::nameOf($event->getCommand()?->getName());

            if (!$this->shouldCapture($command)) {
                return;
            }

            $this->emit(CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_CONSOLE_COMMAND, [
                KernelPayload::FIELD_COMMAND => $command,
            ]));
        });
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->budget->guardMandatory(function () use ($event): void {
            $command = self::nameOf($event->getCommand()?->getName());

            if (!$this->shouldCapture($command)) {
                return;
            }

            $throwable = $event->getError();

            $captured = CapturedEvent::now(Layer::Kernel, KernelPayload::EVENT_CONSOLE_ERROR, [
                KernelPayload::FIELD_COMMAND => $command,
                KernelPayload::FIELD_EXCEPTION_CLASS => $throwable::class,
                KernelPayload::FIELD_EXCEPTION_MESSAGE => $throwable->getMessage(),
                KernelPayload::FIELD_EXIT_CODE => $event->getExitCode(),
            ]);

            // Wie beim kernel.exception Rahmen für Rahmen aus getTrace() — niemals über
            // getTraceAsString(), das die Aufrufargumente einbettet.
            $captured->setRawBuilder($this->rawBuilder->forException($throwable));

            $this->emit($captured);
        });
    }

    /**
     * Heftet Kennung und Akteur an und legt das Ereignis in den Puffer.
     *
     * Ohne Request: der Binder setzt die Kennung des Console-Laufs und liest die
     * Benutzerkennung aus dem Token, falls eines im Speicher liegt — bei
     * `messenger:consume` unter einem angemeldeten Nutzer ist das der Fall.
     */
    private function emit(CapturedEvent $captured): void
    {
        $this->binder->bindWithUser($captured, null, null);

        $this->buffer->appendMandatory($captured);
    }

    private function shouldCapture(string $command): bool
    {
        return $this->options->enabled && !$this->options->isIgnored($command);
    }

    private static function nameOf(?string $name): string
    {
        return null === $name || '' === $name ? self::UNKNOWN_COMMAND : $name;
    }
}
