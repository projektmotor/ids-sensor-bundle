<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Command;

use ProjektMotor\IdsEventData\Frame\DispatchPath;
use ProjektMotor\IdsSensor\Delivery\Transport\Spool\SpoolDrainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sendet die im Spool liegenden Frames an den Broker nach.
 *
 * MUSS auf demselben System laufen wie die überwachte Anwendung — nur dort gibt es
 * Zugriff auf die Spool-Dateien. Der Collector liest den Spool nie.
 *
 * Betriebliche Falle bei Containern: der Prozess braucht dasselbe Spool-Verzeichnis
 * wie der Webserver. Entweder cron im selben Container oder ein Sidecar, der dasselbe
 * Volume mountet. Ein Kubernetes-CronJob in einem EIGENEN Pod funktioniert nicht — er
 * sieht den Spool des Web-Pods nicht und würde stillschweigend nichts versenden.
 *
 * Unter mod_php ist dieser Command der EINZIGE Transportweg und damit
 * Installationspflicht: dort schreibt der Sensor grundsätzlich in den Spool, weil die
 * Antwort nicht abkoppelbar ist und jeder Netzwerkzugriff echte Antwortzeit wäre.
 *
 * Der Befehlsname `ids:sensor:spool:flush` ist dokumentiert und stabil; die Klasse
 * selbst ist es nicht.
 *
 * @internal
 */
#[AsCommand(
    name: 'ids:sensor:spool:flush',
    description: 'Sendet die im Spool liegenden IDS-Frames an den Broker nach',
)]
final class SpoolFlushCommand extends Command
{
    public function __construct(
        private readonly SpoolDrainer $drainer,
        private readonly int $defaultMaxFiles = 2,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-files',
                null,
                InputOption::VALUE_REQUIRED,
                'Höchstzahl der Spool-Dateien pro Lauf',
                (string) $this->defaultMaxFiles,
            )
            ->addOption(
                'deferred',
                null,
                InputOption::VALUE_NONE,
                'Markiert die Frames als planmäßig verzögert (mod_php) statt als Nachlauf nach einem Ausfall. '
                .'Der Unterschied entscheidet, ob der Collector sie noch für die Echtzeit-Regeln verwendet.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $maxFiles = (int) $input->getOption('max-files');
        $path = true === $input->getOption('deferred')
            ? DispatchPath::Deferred
            : DispatchPath::Recovered;

        $result = $this->drainer->drain(max(1, $maxFiles), $path);

        if (0 === $result['frames'] && 0 === $result['failed']) {
            $io->success('Nichts nachzusenden.');

            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Dateien' => (string) $result['files']],
            ['Frames gesendet' => (string) $result['frames']],
            ['Fehlgeschlagen' => (string) $result['failed']],
            ['Übersprungen' => (string) $result['skipped']],
            ['dispatch_path' => $path->value],
        );

        if ($result['failed'] > 0) {
            $io->warning(
                'Nicht alles konnte nachgesendet werden. Die betroffenen Dateien bleiben liegen '
                .'und werden beim nächsten Lauf erneut versucht.'
            );

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d Frames nachgesendet.', $result['frames']));

        return Command::SUCCESS;
    }
}
