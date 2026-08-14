<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\IdsSensorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Eine Anwendung, die Messenger SELBST benutzt.
 *
 * Der reguläre {@see TestKernel} konfiguriert Messenger nicht — dort ist der Bus des Bundles
 * zwangsläufig der einzige und damit auch der Standard-Bus. Genau dadurch bleibt die
 * Wechselwirkung mit einer Anwendung, die eigene Buses benennt, ungeprüft. Dieser Kernel
 * schließt die Lücke.
 *
 * @internal
 */
final class AppWithOwnBusKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $sensorConfig
     * @param array<string, mixed> $messengerConfig die messenger-Konfiguration der ANWENDUNG
     */
    public function __construct(
        private readonly array $sensorConfig,
        private readonly array $messengerConfig,
        private readonly string $variant,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new IdsSensorBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-app-secret',
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'messenger' => $this->messengerConfig,
        ]);

        $container->extension('ids_sensor', $this->sensorConfig);

        // Ein gewöhnlicher Handler der Anwendung. Läuft er nach dem Dispatch nicht, hat der
        // Standard-Bus der Anwendung kein handle_message-Middleware mehr.
        $container->services()
            ->set(PlainMessageHandler::class)
            ->public()
            ->tag('messenger.message_handler');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    protected function build(ContainerBuilder $container): void
    {
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    protected function getContainerClass(): string
    {
        return 'IdsSensorOwnBusContainer_'.(preg_replace('/[^A-Za-z0-9_]/', '_', $this->variant) ?? 'x');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ids-sensor-tests/ownbus-'.$this->variant;
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }
}
