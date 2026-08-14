<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Event;

use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;

/**
 * Die Herkunftskennung jedes Events: application_id, instance_id, environment.
 *
 * Alle drei sind laut Konzept 2.2.1 Pflicht und collectorseitig NOT NULL
 * (Konzept 4.2.1 Tabellenschema). Warum das keine Formalie ist — Konzept
 * 2.2.1 — Anwendungs- und Instanzkontext nennt die Folgen fehlender Zuordnung:
 *
 *  - Eine IP, die zwei Anwendungen besucht, wird bei jeder Schwellwertregel
 *    doppelt gezählt: Fehlalarme ohne Angriff.
 *  - Last- und Testverkehr aus staging verschiebt die Baselines der
 *    Anomalieschicht und macht die Produktionserkennung unbrauchbar.
 *  - Bei horizontaler Skalierung ist nicht feststellbar, ob ein Muster von einer
 *    Instanz stammt oder verteilt auftritt.
 *
 * Daraus folgt die verbindliche Aggregationsregel des Konzepts: jede Aggregation
 * und jeder Zeitfenster-Join erfolgt innerhalb einer Kombination aus
 * application_id und environment.
 *
 * Öffentliche API: application_id, instance_id und environment sind collectorseitig
 * NOT NULL und Grundlage jeder Aggregation.
 */
final class SensorIdentity
{
    public const MAX_ID_LENGTH = 64;

    private const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    public function __construct(
        public readonly string $applicationId,
        public readonly string $instanceId,
        public readonly Environment $environment,
    ) {
    }

    /**
     * Ob eine Kennung dem erwarteten Muster entspricht.
     *
     * Bewusst nur eine Prüffunktion und keine Exception im Konstruktor: die Werte
     * stammen typischerweise aus Umgebungsvariablen und sind erst zur Laufzeit
     * bekannt. Ein Wurf hier verstieße gegen fail-open. Die Prüfung wird beim
     * Bootstrap protokolliert und vom Command ids:sensor:setup-check zum Deploy-Zeitpunkt
     * hart geprüft.
     */
    public static function isValidId(string $id): bool
    {
        return 1 === preg_match(self::ID_PATTERN, $id);
    }

    /**
     * @return list<string> Liste der Beanstandungen, leer wenn alles in Ordnung ist
     */
    public function validate(): array
    {
        $problems = [];

        if ('' === $this->applicationId) {
            $problems[] = 'application_id ist leer';
        } elseif (!self::isValidId($this->applicationId)) {
            $problems[] = \sprintf(
                'application_id "%s" entspricht nicht dem Muster [A-Za-z0-9._:-]{1,%d}',
                $this->applicationId,
                self::MAX_ID_LENGTH,
            );
        }

        if ('' === $this->instanceId) {
            $problems[] = 'instance_id ist leer';
        } elseif (!self::isValidId($this->instanceId)) {
            $problems[] = \sprintf(
                'instance_id "%s" entspricht nicht dem Muster [A-Za-z0-9._:-]{1,%d}',
                $this->instanceId,
                self::MAX_ID_LENGTH,
            );
        }

        return $problems;
    }
}
