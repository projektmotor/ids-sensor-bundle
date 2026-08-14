<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use ProjektMotor\IdsSensor\IdsSensorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimaler Kernel für Integrationstests.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed>      $sensorConfig
     * @param bool                      $exposeServices  macht die privaten ids_sensor.*-Services im
     *                                                   Test-Container erreichbar. Nötig, weil private
     *                                                   Services ohne Referenz beim Kompilieren entfernt
     *                                                   werden — und im Bundle referenziert bislang
     *                                                   nichts die Bausteine, weil die Sensoren noch
     *                                                   nicht verdrahtet sind.
     * @param array<string, mixed>|null $securityConfig  null lässt SecurityBundle ganz weg — das
     *                                                   Bundle muss auch ohne installierbar sein.
     *                                                   Ein Array registriert es und übergibt die
     *                                                   Konfiguration unverändert.
     * @param string|null               $fingerprintFile Pfad, in den {@see ContainerFingerprintPass}
     *                                                   den Abdruck des kompilierten Containers
     *                                                   schreibt. Wird nur beim KOMPILIEREN gefüllt —
     *                                                   ein aus dem Cache geladener Container führt
     *                                                   keine Compiler-Pässe aus.
     */
    public function __construct(
        private readonly array $sensorConfig = [],
        private readonly string $variant = 'default',
        private readonly bool $exposeServices = true,
        bool $debug = true,
        private readonly ?array $securityConfig = null,
        private readonly ?string $fingerprintFile = null,
    ) {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        if (null !== $this->securityConfig) {
            yield new SecurityBundle();
        }

        yield new IdsSensorBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-app-secret',
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            // mock_file statt native: PHPUnit läuft in der CLI, wo session_start()
            // keine Header senden kann.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);

        if (null !== $this->securityConfig) {
            $container->extension('security', $this->securityConfig);
        }

        if ([] !== $this->sensorConfig) {
            $container->extension('ids_sensor', $this->sensorConfig);
        }

        $controller = $container->services()->set(TestController::class)->public();

        if (null !== $this->securityConfig) {
            $controller->args([service('security.authorization_checker')]);
            $container->services()->set(TestOrderVoter::class)->tag('security.voter');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('test_ok', '/ok')->controller([TestController::class, 'ok']);
        $routes->add('test_boom', '/boom')->controller([TestController::class, 'boom']);
        $routes->add('test_not_found', '/gibt-es-nicht-mehr')->controller([TestController::class, 'notFound']);
        $routes->add('test_denied', '/geschuetzt')->controller([TestController::class, 'denied']);
        $routes->add('test_streamed', '/stream')->controller([TestController::class, 'streamed']);
        $routes->add('test_cacheable', '/cachebar')->controller([TestController::class, 'cacheable']);
        $routes->add('test_decide', '/entscheide')->controller([TestController::class, 'decide']);
        // Wird per access_control geschützt — der Pfad, den nur der Manager-Decorator sieht.
        $routes->add('test_admin_only', '/nur-fuer-admins')->controller([TestController::class, 'ok']);
    }

    protected function build(ContainerBuilder $container): void
    {
        $this->registerRedisTransportFactory($container);

        if (null !== $this->fingerprintFile) {
            // AFTER_REMOVING: erst dort sind autowired Argumente und autokonfigurierte Tags
            // aufgelöst. Früher gelesen verglich der Abdruck eine ausgeschriebene Definition
            // mit einer leeren.
            $container->addCompilerPass(
                new ContainerFingerprintPass($this->fingerprintFile),
                PassConfig::TYPE_AFTER_REMOVING,
            );
        }

        if (!$this->exposeServices) {
            return;
        }

        // BEFORE_REMOVING, damit die Services öffentlich sind, bevor
        // RemoveUnusedDefinitionsPass sie wegräumt.
        $container->addCompilerPass(new ExposeSensorServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * Registriert die Redis-Transport-Factory von Hand.
     *
     * Nötig, weil Symfonys FrameworkExtension Bridge-Factories über
     * ContainerBuilder::willBeAvailable() registriert, und das gibt für ein Paket, das
     * nur in `require-dev` des Root-Pakets steht, bewusst `false` zurück. Für dieses
     * Bundle ist symfony/redis-messenger genau das — eine Entwicklungsabhängigkeit,
     * weil der Transport die Wahl der Anwendung ist und ext-redis nicht erzwungen
     * werden soll.
     *
     * WICHTIGE FOLGE FÜR DIE AUSLIEFERUNG: eine konsumierende Anwendung muss
     * `symfony/redis-messenger` selbst in `require` aufnehmen. Eine
     * Entwicklungsabhängigkeit dieses Bundles registriert die Factory dort nicht. Das
     * gehört in die Installationsanleitung, sonst ist der erste Befund
     * „No transport supports Messenger DSN redis://…".
     */
    private function registerRedisTransportFactory(ContainerBuilder $container): void
    {
        $factory = 'Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory';

        if (!class_exists($factory) || $container->hasDefinition('messenger.transport.redis.factory')) {
            return;
        }

        $container->register('messenger.transport.redis.factory', $factory)
            ->addTag('messenger.transport_factory');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    /**
     * Der Containerklassenname MUSS die Variante enthalten.
     *
     * Symfony leitet ihn sonst nur aus Kernel-Klasse, Umgebung und Debug-Flag ab —
     * für alle Varianten also identisch. Ist die Klasse einmal aus dem
     * Cache-Verzeichnis der einen Variante geladen, kann PHP sie nicht erneut
     * deklarieren: ein Kernel mit anderer Konfiguration bekäme stillschweigend den
     * bereits geladenen Container der ersten Variante, samt Verweisen auf deren
     * Verzeichnis. Das äußert sich als „Failed opening required ...Ghost....php",
     * sobald das erste Verzeichnis aufgeräumt wurde — und im schlimmeren Fall gar
     * nicht, nämlich als Test, der unbemerkt die falsche Konfiguration prüft.
     */
    protected function getContainerClass(): string
    {
        $variant = preg_replace('/[^A-Za-z0-9_]/', '_', $this->variant) ?? 'default';

        return \sprintf(
            'IdsSensorTestContainer_%s_%s_%s%s%s',
            $variant,
            $this->exposeServices ? 'exposed' : 'private',
            $this->environment,
            $this->debug ? '_debug' : '',
            // Auch der Abdruck-Schalter gehört in den Klassennamen, nicht nur ins
            // Verzeichnis: sonst trüge ein Kernel MIT Abdruck denselben Klassennamen wie
            // einer ohne, und der zweite bekäme die bereits geladene Klasse des ersten —
            // mit Verweisen auf ein fremdes Cache-Verzeichnis.
            null === $this->fingerprintFile ? '' : '_fp',
        );
    }

    /**
     * Erzwingt eine frische Kompilierung.
     *
     * Der Abdruck entsteht in einem Compiler-Pass, und Compiler-Pässe laufen nicht, wenn der
     * Container aus dem Cache kommt. Ohne diesen Schalter wäre der Abdruckvergleich beim
     * zweiten Lauf lautlos wirkungslos — er verglich die Datei des ersten Laufs mit sich
     * selbst.
     */
    private function fingerprintCacheSuffix(): string
    {
        return null === $this->fingerprintFile ? '' : '/fingerprint-'.substr(md5($this->fingerprintFile), 0, 8);
    }

    /**
     * Muss pro Konfigurationsvariante unterschiedlich sein — sonst teilen sich
     * verschieden konfigurierte Kernel denselben kompilierten Container und der
     * zweite Test prüft die Konfiguration des ersten. Der Expose-Schalter gehört
     * mit in den Schlüssel, weil er den Container verändert.
     */
    public function getCacheDir(): string
    {
        return \sprintf(
            '%s/ids-sensor-tests/%s-%s/%s%s',
            sys_get_temp_dir(),
            $this->variant,
            $this->exposeServices ? 'exposed' : 'private',
            $this->environment,
            $this->fingerprintCacheSuffix(),
        );
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }
}
