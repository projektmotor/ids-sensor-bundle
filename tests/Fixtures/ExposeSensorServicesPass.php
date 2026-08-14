<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Macht die privaten ids_sensor.*-Services im Test erreichbar.
 *
 * Nur für Tests. In der Produktion bleiben alle Services privat, damit die
 * Innereien des Bundles nicht zur API werden — öffentlich sind ausschließlich die
 * Klassen unter Contract/ und Schema/ sowie die Konfigurationsschlüssel.
 */
final class ExposeSensorServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'ids_sensor.')) {
                $definition->setPublic(true);
            }
        }
    }
}
