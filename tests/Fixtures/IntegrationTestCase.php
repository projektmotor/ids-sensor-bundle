<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\VarExporter\LazyObjectInterface;

/**
 * Gemeinsame Basis für Tests, die den TestKernel booten.
 *
 * Enthält bewusst NUR das, was in allen Ableitungen wörtlich gleich war: der Zugriff
 * auf den Testcontainer und der Sitzungsschlüssel. Die boot()-Helfer bleiben in den
 * einzelnen Testklassen — ihre dreizehn Signaturen unterscheiden sich sachlich
 * (Konfigurations-Overrides, Security-Konfiguration, Breaker-Werte), und sie hier zu
 * einer Methode mit optionalen Parametern zusammenzuziehen hieße, dreizehn Fälle
 * hinter einer Signatur zu verstecken.
 *
 * @internal
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Der Sitzungsschlüssel der Tests.
     *
     * Muss mindestens 32 Zeichen haben (ConfigurationTree) und darf laut Konzept 2.2.4
     * ausdrücklich nicht APP_SECRET sein — IdsSensorBundle bricht sonst die
     * Kompilierung ab. Stand vorher in siebzehn Dateien wörtlich.
     */
    public const SESSION_KEY = 'ein-dedizierter-ids-schluessel-mit-32-zeichen';

    /**
     * Der Testcontainer, über den auch private Services erreichbar sind.
     *
     * Das Bundle registriert seine Dienste ausdrücklich als `public: false` — ohne
     * `test.service_container` käme kein Test an sie heran.
     */
    protected function services(TestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $testContainer */
        $testContainer = $kernel->getContainer()->get('test.service_container');

        return $testContainer;
    }

    /**
     * Der Transport des Sensors, ausgepackt aus seinem Lazy-Proxy.
     *
     * Seit {@see \ProjektMotor\IdsSensor\DependencyInjection\Compiler\LazyTransportPass}
     * liefert der Container einen Proxy, der ausschließlich `TransportInterface`
     * implementiert — in Produktion genau richtig, denn der Shipper ruft nur `send()`.
     * Die Tests brauchen aber `InMemoryTransport::getSent()`, und das steht nicht im
     * Interface.
     *
     * Auspacken statt den Proxy abschalten: Die Tests sollen denselben Container prüfen,
     * den die Anwendung bekommt. Ein Transport, der im Test nicht lazy ist, wäre genau
     * der Unterschied, den ContainerFingerprintTest verhindern soll.
     */
    protected function transport(ContainerInterface $services, string $name = 'ids_events'): InMemoryTransport
    {
        $transport = $services->get('messenger.transport.'.$name);

        if ($transport instanceof LazyObjectInterface) {
            $transport = $transport->initializeLazyObject();
        }

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Die Frames, die tatsächlich auf dem Transport gelandet sind.
     *
     * Gezielt nach {@see EventBatch} gefiltert: derselbe Transport befördert auch den
     * Heartbeat, und der ist ein eigener Nachrichtentyp mit eigenem Payload.
     *
     * @return list<EventBatch>
     */
    protected function batches(ContainerInterface $services, string $name = 'ids_events'): array
    {
        $batches = [];

        foreach ($this->transport($services, $name)->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof EventBatch) {
                $batches[] = $message;
            }
        }

        return $batches;
    }
}
