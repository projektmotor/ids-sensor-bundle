<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Identity;

use ProjektMotor\IdsEventData\Event\SensorIdentity;
use Psr\Log\LoggerInterface;

/**
 * Setzt die Herkunftskennung zur Laufzeit zusammen.
 *
 * Alle drei Werte kommen aus der Konfiguration; abgeleitet wird nichts mehr. Bis
 * schema_version 1 entstand die instance_id aus dem Hostnamen und die environment
 * aus einer Zuordnungstabelle — beides ist entfallen, weil der Collector die
 * Kennungen beim Registrieren vergibt (Konzept 1, Begriffstafel).
 *
 * Trotzdem ein Service und kein Container-Parameter: Die Werte kommen aus
 * Umgebungsvariablen und dürfen nicht in einen gewärmten Container-Cache gebacken
 * werden. Ein beim Image-Bau aufgelöster Wert wäre in jedem Replikat derselbe —
 * und die sensor_id ist je Node verschieden (Konzept 2.3).
 *
 * @internal
 */
final class SensorIdentityProvider
{
    private ?SensorIdentity $identity = null;

    private bool $validated = false;

    public function __construct(
        private readonly string $applicationId,
        private readonly string $environmentId,
        private readonly string $sensorId,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function get(): SensorIdentity
    {
        if (null !== $this->identity) {
            return $this->identity;
        }

        $identity = new SensorIdentity(
            $this->applicationId,
            $this->environmentId,
            $this->sensorId,
        );

        $this->warnOnceIfInvalid($identity);

        return $this->identity = $identity;
    }

    /**
     * Beanstandungen werden protokolliert, nicht geworfen: die Werte stammen aus
     * Umgebungsvariablen und sind erst zur Laufzeit bekannt. Eine Exception hier
     * verstieße gegen fail-open. Der harte Abbruch gehört in ids:sensor:setup-check,
     * das im Deploy läuft.
     */
    private function warnOnceIfInvalid(SensorIdentity $identity): void
    {
        if ($this->validated) {
            return;
        }

        $this->validated = true;

        foreach ($identity->validate() as $problem) {
            $this->logger?->error('ids_sensor: {problem}', ['problem' => $problem]);
        }
    }
}
