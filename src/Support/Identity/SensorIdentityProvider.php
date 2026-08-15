<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Identity;

use ProjektMotor\IdsEventData\Event\SensorIdentity;
use Psr\Log\LoggerInterface;

/**
 * Setzt die Herkunftskennung zur Laufzeit zusammen.
 *
 * Bewusst ein Service und kein Container-Parameter: sowohl instance_id
 * (Hostname) als auch environment (Auflösung über die Map) dürfen nicht in einen
 * gewärmten Container-Cache gebacken werden. Siehe {@see InstanceIdProvider} für
 * die Folgen.
 *
 * @internal
 */
final class SensorIdentityProvider
{
    private ?SensorIdentity $identity = null;

    private bool $validated = false;

    public function __construct(
        private readonly string $applicationId,
        private readonly InstanceIdProvider $instanceIdProvider,
        private readonly EnvironmentResolver $environmentResolver,
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
            $this->instanceIdProvider->get(),
            $this->environmentResolver->resolve(),
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
