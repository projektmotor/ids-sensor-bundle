<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Command;

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
        // Ohne DSN bleibt `ids_sensor.shipper` der NullShipper — auch für den Drainer.
        // Der wirft nie, also galt JEDE Zeile als versendet und `finish()` löschte die
        // Datei: der cron leerte den Spool stillschweigend und meldete Erfolg. Unter
        // mod_php mit vergessener DSN war das der lautlose Totalverlust.
        private readonly bool $deliveryConfigured = true,
    ) {
        parent::__construct();
    }

    /**
     * KEIN Schalter für den dispatch_path.
     *
     * Hier stand `--deferred`, und es war zweimal falsch. Erstens sachlich: Konzept
     * 3.3.1 nennt den Wert ausdrücklich „kein Schalter, sondern ein vom Sensor
     * abgeleiteter Tatsachenwert; die Anwendung kann ihn nicht setzen" — ein CLI-Flag
     * ließ genau das zu, und zwar in die günstige Richtung. Zweitens praktisch: Ohne
     * das Flag markierte der Drainer JEDEN Frame als `recovered`, auch die unter mod_php
     * planmäßig gespoolten. Der dokumentierte cron-Eintrag setzt es nicht, also war die
     * Echtzeit-Erkennung dort dauerhaft aus.
     *
     * Der Drainer leitet den Wert jetzt je Frame aus dem Frame selbst ab.
     */
    protected function configure(): void
    {
        $this->addOption(
            'max-files',
            null,
            InputOption::VALUE_REQUIRED,
            'Höchstzahl der Spool-Dateien pro Lauf',
            (string) $this->defaultMaxFiles,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->deliveryConfigured) {
            $io->error(
                'Es ist kein Broker konfiguriert (ids_sensor.transport.dsn fehlt). Ein Drain-Lauf '
                .'würde den Spool leeren, ohne dass ein einziger Frame ankommt — deshalb passiert '
                .'hier nichts. Unter mod_php ist dieser Command der einzige Transportweg; ohne DSN '
                .'sammelt der Spool, bis er voll ist, und verwirft dann gezählt.'
            );

            return Command::FAILURE;
        }

        $maxFiles = (int) $input->getOption('max-files');

        $result = $this->drainer->drain(max(1, $maxFiles));

        if (0 === $result['frames'] && 0 === $result['failed'] && 0 === $result['discarded']) {
            $io->success('Nichts nachzusenden.');

            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Dateien' => (string) $result['files']],
            ['Frames gesendet' => (string) $result['frames']],
            ['Fehlgeschlagen' => (string) $result['failed']],
            ['Übersprungen' => (string) $result['skipped']],
            // Verworfene Zeilen gehören sichtbar in die Ausgabe: Ohne sie meldete der
            // Command „Nichts nachzusenden", nachdem er eine ganze Datei restlos
            // verworfen hatte.
            ['Verworfen' => (string) $result['discarded']],
        );

        if ($result['failed'] > 0) {
            $io->warning(
                'Nicht alles konnte nachgesendet werden. Die betroffenen Dateien bleiben liegen '
                .'und werden beim nächsten Lauf erneut versucht.'
            );

            return Command::FAILURE;
        }

        if ($result['discarded'] > 0) {
            $io->warning(\sprintf(
                '%d Zeile(n) wurden verworfen — unlesbar oder dauerhaft unversendbar. Sie sind '
                .'als dropped_spool_unreadable gezählt und reisen im nächsten Heartbeat mit.',
                $result['discarded'],
            ));
        }

        $io->success(\sprintf('%d Frames nachgesendet.', $result['frames']));

        return Command::SUCCESS;
    }
}
