<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Command;

use ProjektMotor\IdsSensor\Delivery\Heartbeat\Emitter;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Mode;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sendet ein Lebenszeichen — für cron oder systemd-Timer.
 *
 * WARUM ES DIESEN WEG BRAUCHT, OBWOHL DER REQUEST-PFAD EXISTIERT
 *
 * Der request-getriebene Heartbeat schweigt, wenn kein Verkehr da ist. Für den Collector ist
 * das nicht von einem stillgelegten Sensor zu unterscheiden — und Konzept 2. nennt die
 * lautlose Stilllegung ausdrücklich als die gefährlichste Angriffsform. Eine nachts
 * unbenutzte Anwendung würde also entweder jede Nacht Falschalarm erzeugen oder der Alarm
 * müsste so weit entschärft werden, dass er eine echte Stilllegung nicht mehr erkennt.
 *
 * Dieser Command löst das: er läuft unabhängig vom Verkehr.
 *
 * Empfohlenes Intervall: die Hälfte von `heartbeat.interval_s`. Der Command respektiert die
 * Drosselung, ein zu häufiger Lauf schadet also nicht — ein zu seltener dagegen erzeugt
 * Lücken.
 *
 * @internal
 */
#[AsCommand(
    name: 'ids:sensor:heartbeat',
    description: 'Sendet ein Lebenszeichen des Sensors an den Collector.',
)]
final class HeartbeatCommand extends Command
{
    public function __construct(
        private readonly Emitter $emitter,
        private readonly Scheduler $scheduler,
        private readonly string $configuredMode,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Sendet auch dann, wenn das Intervall noch nicht abgelaufen ist. Für Deploy-Prüfungen.',
            )
            ->setHelp(<<<'HELP'
                Für cron:

                    * * * * * php bin/console ids:sensor:heartbeat --quiet

                Oder als systemd-Timer mit OnUnitActiveSec=30s. Der Command respektiert
                heartbeat.interval_s, ein häufigerer Lauf sendet also nicht häufiger.

                Rückgabewert 0 heißt „gesendet, noch nicht fällig oder in dieser Betriebsart
                nicht zuständig". Nur ein FEHLGESCHLAGENER Versand ergibt 1 — damit ein
                cron-Fehlerbericht auch einen echten Befund bedeutet und nicht bei jedem
                gedrosselten Lauf feuert.

                Bei heartbeat.mode = "request" ist dieser Command wirkungslos. Das ist eine
                Fehlkonfiguration, aber keine Störung: Der Request-Pfad sendet weiter. Sie
                erscheint deshalb als Warnung mit Rückgabewert 0 und als Befund in
                "ids:sensor:setup-check" — nicht als minütlicher cron-Fehlerbericht.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = true === $input->getOption('force');

        if (!$force && !$this->scheduler->isDue()) {
            $io->comment(\sprintf(
                'Noch nicht fällig (letzter Versand vor %s s).',
                (string) ($this->scheduler->secondsSinceLastSend() ?? 0),
            ));

            return Command::SUCCESS;
        }

        $sent = $force
            ? $this->emitter->emit(Mode::Command)
            : $this->emitter->emitIfDue(Mode::Command);

        if ($sent) {
            $io->success('Heartbeat gesendet.');

            return Command::SUCCESS;
        }

        // Nicht gesendet, obwohl fällig: entweder ist der Modus auf `request` gestellt —
        // dann ist dieser cron-Eintrag überflüssig — oder der Versand ist gescheitert.
        //
        // Der Modus `request` ergibt SUCCESS, und zwar entgegen der ersten Fassung. Sie
        // gab hier FAILURE, und damit feuerte der cron-Fehlerbericht bei JEDEM Lauf,
        // dauerhaft — genau das, was der Hilfetext eine Zeile weiter oben ausschließt
        // („nicht bei jedem gedrosselten Lauf"). Ein Fehlerkanal, der ununterbrochen
        // meldet, meldet nichts mehr. Und die Lage ist keine Störung: Der Request-Pfad
        // sendet weiter, es fehlt kein Lebenszeichen. Sichtbar bleibt sie als Warnung
        // hier und als Befund im Deploy-Check, der nicht minütlich läuft.
        if ('request' === $this->configuredMode) {
            $io->warning(
                'heartbeat.mode ist "request": dieser Command ist wirkungslos. '
                .'Für cron-Betrieb auf "command" oder "both" stellen.',
            );

            return Command::SUCCESS;
        }

        $io->error('Heartbeat konnte nicht gesendet werden — Broker nicht erreichbar oder Circuit Breaker offen.');

        return Command::FAILURE;
    }
}
