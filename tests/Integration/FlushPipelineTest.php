<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\NullShipper;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prüft den Weg vom Puffer bis zum Transport durch den echten Container.
 */
final class FlushPipelineTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
    ];

    /**
     * Der Flush muss an kernel.terminate hängen und nicht an kernel.response —
     * terminate läuft erst, nachdem die Antwort den Client erreicht hat. Hinge er
     * früher, wäre der Versand Teil der Antwortzeit und das Budget aus Konzept 2.1
     * verletzt.
     */
    public function testFlushListenerHooksIntoKernelTerminate(): void
    {
        $kernel = $this->boot();
        $dispatcher = $this->services($kernel)->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class, $dispatcher);

        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $listeners = $dispatcher->getListeners(KernelEvents::TERMINATE);

        $found = false;
        foreach ($listeners as $listener) {
            if (\is_array($listener) && $listener[0] instanceof \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'FlushListener ist nicht auf kernel.terminate registriert');

        // Der Versand darf ausschließlich am terminate hängen. Läge er auf
        // kernel.response, wäre er Teil der Antwortzeit — genau das, was die
        // Zweiteilung vermeiden soll.
        foreach ($dispatcher->getListeners(KernelEvents::RESPONSE) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            self::assertNotInstanceOf(
                \ProjektMotor\IdsSensor\Delivery\Dispatch\FlushListener::class,
                $target,
                'Der Flush darf nicht auf kernel.response laufen',
            );
        }
    }

    /**
     * Der Durchstich: ein gepuffertes Event wird beim terminate normalisiert,
     * gebündelt und dem Transport übergeben.
     */
    public function testTerminateShipsTheBufferedEvents(): void
    {
        $kernel = $this->boot();
        $services = $this->services($kernel);

        /** @var EventBuffer $collector */
        $collector = $services->get('ids_sensor.event_buffer');
        /** @var NullShipper $shipper */
        $shipper = $services->get('ids_sensor.shipper');

        $event = CapturedEvent::now(Layer::Kernel, 'kernel.request', [
            KernelPayload::FIELD_METHOD => 'GET',
            KernelPayload::FIELD_PATH => '/wp-admin/setup-config.php',
        ]);
        $event->setCorrelationId('req-7f2a1c');
        $collector->append($event);

        self::assertSame(0, $shipper->shippedEvents());

        $kernel->terminate(Request::create('/'), new Response());

        self::assertSame(1, $shipper->shippedEvents(), 'Das Event wurde beim terminate versendet');
        self::assertTrue($collector->isEmpty(), 'Der Puffer ist danach geleert');
    }

    /**
     * Ohne Events darf kein Frame entstehen — ein Request, der nichts erfasst, soll
     * auch nichts kosten.
     */
    public function testTerminateWithoutEventsShipsNothing(): void
    {
        $kernel = $this->boot();

        /** @var NullShipper $shipper */
        $shipper = $this->services($kernel)->get('ids_sensor.shipper');

        $kernel->terminate(Request::create('/'), new Response());

        self::assertSame(0, $shipper->shippedEvents());
    }

    private function boot(): TestKernel
    {
        $kernel = new TestKernel(self::CONFIG, 'flush-pipeline');
        $kernel->boot();

        return $kernel;
    }
}
