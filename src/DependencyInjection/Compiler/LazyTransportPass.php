<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Baut den Messenger-Transport des Sensors lazy.
 *
 * WOZU
 *
 * Der FlushListener wird in `kernel.terminate` erzeugt. Sein Dienstgraph reicht über
 * EventFlusher und FrameDispatcher bis zum Shipper, und der verlangt den
 * Messenger-Transport. Dessen Definition trägt als Factory
 * `messenger.transport_factory::createTransport()`, und die wirft
 * `InvalidArgumentException("No transport supports Messenger DSN …")`, sobald keine
 * Factory die DSN unterstützt — der häufigste Fall ist ein fehlendes
 * `symfony/redis-messenger`, das dieses Bundle nur als Entwicklungsabhängigkeit führt
 * und das die Anwendung deshalb selbst verlangen muss (doc/07-betrieb.md).
 *
 * Dieser Wurf entsteht beim BAUEN des Dienstes und damit außerhalb jedes try/catch im
 * Sensor — unmittelbar in der überwachten Anwendung, nachdem die Antwort bereits
 * gesendet wurde. Genau der Fall, den Konzept 4. ausschließt.
 *
 * Lazy verschiebt den Factory-Aufruf auf den ersten Methodenaufruf am Transport. Der
 * liegt in {@see \ProjektMotor\IdsSensor\Delivery\Transport\Shipper\MessengerShipper::ship()}
 * und damit innerhalb des try/catch von
 * {@see \ProjektMotor\IdsSensor\Delivery\Dispatch\FrameDispatcher::ship()}: aus einem
 * Absturz der Anwendung wird ein gezählter `ship_failed` und ein Frame im Spool.
 *
 * WARUM NICHT `lazy: true` IN DER YAML
 *
 * Der Transport gehört nicht diesem Bundle. Er entsteht in Symfonys FrameworkExtension
 * unter einem Namen, der erst aus der Konfiguration folgt (`transport.name`), und eine
 * statische Datei kann ihn nicht nennen — dieselbe Begründung, aus der es
 * {@see \ProjektMotor\IdsSensor\IdsSensorBundle::TRANSPORT_ID} als Alias gibt.
 *
 * Die Definition trägt `TransportInterface` als Klasse; Symfony erzeugt daraus einen
 * Lazy-Proxy, der das Interface implementiert. Angefasst wird ausschließlich der eine
 * Transport, auf den der Sensor zeigt — fremde Transports der Anwendung bleiben
 * unberührt.
 *
 * @internal
 */
final class LazyTransportPass implements CompilerPassInterface
{
    public const NAME_PARAMETER = 'ids_sensor.transport.name';

    private const TRANSPORT_PREFIX = 'messenger.transport.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::NAME_PARAMETER)) {
            return;
        }

        $name = $container->getParameter(self::NAME_PARAMETER);

        if (!\is_string($name) || '' === $name) {
            return;
        }

        $id = self::TRANSPORT_PREFIX.$name;

        if (!$container->hasDefinition($id)) {
            // Ohne DSN registriert das Bundle keinen Transport, und der NullShipper
            // braucht keinen. Kein Befund, nichts zu tun.
            return;
        }

        $container->getDefinition($id)->setLazy(true);
    }
}
