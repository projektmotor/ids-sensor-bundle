<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Aktiviert genau den konfigurierten Erfassungsweg der Business-Ebene.
 *
 * Als Compiler-Pass und nicht als bedingter Import, weil der `configured`-Modus
 * Listener für Event-Klassen registrieren muss, die erst aus der Konfiguration bekannt
 * sind — und weil der Dispatcher-Decorator ENTFERNT werden muss, wenn er nicht gewählt
 * ist. Ein nicht gewählter Decorator, der trotzdem im Container hängt, wäre genau die
 * Art stiller Nebenwirkung, die auf einem zentralen Service niemand haben will.
 *
 * @internal
 */
final class BusinessCaptureModePass implements CompilerPassInterface
{
    public const MODE_PARAMETER = 'ids_sensor.layers.business.capture_mode';

    public const CLASSES_PARAMETER = 'ids_sensor.layers.business.event_classes';

    private const DECORATOR_ID = 'ids_sensor.business.capturing_dispatcher';

    private const LISTENER_ID = 'ids_sensor.business.configured_listener';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::MODE_PARAMETER)) {
            return;
        }

        $mode = $container->getParameter(self::MODE_PARAMETER);

        if ('dispatcher' !== $mode && $container->hasDefinition(self::DECORATOR_ID)) {
            $container->removeDefinition(self::DECORATOR_ID);
        }

        if ('configured' === $mode) {
            $this->registerConfiguredListeners($container);

            return;
        }

        if ($container->hasDefinition(self::LISTENER_ID)) {
            $container->removeDefinition(self::LISTENER_ID);
        }
    }

    private function registerConfiguredListeners(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LISTENER_ID) || !$container->hasParameter(self::CLASSES_PARAMETER)) {
            return;
        }

        $classes = $container->getParameter(self::CLASSES_PARAMETER);

        if (!\is_array($classes)) {
            return;
        }

        $definition = $container->getDefinition(self::LISTENER_ID);

        foreach ($classes as $eventClass) {
            if (!\is_string($eventClass) || '' === $eventClass) {
                continue;
            }

            // Exakter Event-Name — der einzige Weg, den Symfonys Dispatcher
            // tatsächlich unterstützt.
            $definition->addTag('kernel.event_listener', [
                'event' => $eventClass,
                'method' => '__invoke',
            ]);
        }
    }
}
