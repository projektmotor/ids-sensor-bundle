<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Support\Identity;

/**
 * Liefert die instance_id — die Kennung des ausführenden Hosts oder Containers.
 *
 * Die Auflösung passiert bewusst zur LAUFZEIT und nicht zur Container-Compile-Zeit.
 * Das ist keine Stilfrage: Wird der Symfony-Cache beim Bauen eines Docker-Images
 * gewärmt, würde ein zur Compile-Zeit ermittelter Hostname in das Image gebacken.
 * Alle Replicas trügen dann dieselbe instance_id. Folgen:
 *
 *  - Die Aussage „von einer Instanz oder verteilt?" aus Konzept 2.2.1 ist nicht
 *    mehr beantwortbar.
 *  - Der Heartbeat-Ausfall einer einzelnen Instanz bleibt unsichtbar, weil die
 *    anderen unter derselben Kennung weitersenden — die Regel ids.sensor_silent
 *    feuert nie.
 *
 * Deshalb: statisch gemerkt pro Prozess, nie ein Container-Parameter.
 *
 * @internal
 */
final class InstanceIdProvider
{
    private ?string $instanceId = null;

    /**
     * @param string|null $configured expliziter Wert aus der Konfiguration; null bedeutet
     *                                „aus dem Hostnamen ermitteln"
     */
    public function __construct(
        private readonly ?string $configured = null,
    ) {
    }

    public function get(): string
    {
        if (null !== $this->instanceId) {
            return $this->instanceId;
        }

        $candidate = $this->configured;

        if (null === $candidate || '' === trim($candidate)) {
            $hostname = gethostname();
            $candidate = false !== $hostname ? $hostname : 'unknown';
        }

        return $this->instanceId = self::sanitize($candidate);
    }

    /**
     * Bringt den Wert in das Muster, das SensorIdentity erwartet. Hostnamen können
     * Zeichen enthalten, die dort nicht zugelassen sind; ein bereinigter Wert ist
     * besser als ein Event, das collectorseitig auffällt.
     */
    public static function sanitize(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._:-]/', '-', $value) ?? 'unknown';
        $sanitized = trim($sanitized, '-');

        if ('' === $sanitized) {
            return 'unknown';
        }

        return substr($sanitized, 0, 64);
    }
}
