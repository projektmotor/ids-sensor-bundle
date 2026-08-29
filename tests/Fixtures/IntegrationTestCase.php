<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
     * Der aufzeichnende HTTP-Client, über den der Sensor im Test versendet.
     *
     * Der TestKernel ersetzt damit `ids_sensor.http_client`. Der echte Pfad durch
     * HttpShipper und TokenProvider bleibt erhalten — geprüft werden Routenbildung,
     * Anmeldung und Antwortcodes, nicht nur „irgendetwas wurde übergeben".
     */
    protected function client(ContainerInterface $services): RecordingHttpClient
    {
        $client = $services->get('ids_sensor.http_client');

        self::assertInstanceOf(RecordingHttpClient::class, $client);

        return $client;
    }

    /**
     * Die Frames, die tatsächlich auf der Datenroute gelandet sind.
     *
     * Die Route nimmt eine Liste; hier sind die Listen bereits ausgepackt, weil kein
     * Test sich für die Bündelung interessiert, der nicht ausdrücklich danach fragt.
     *
     * @return list<array<string, mixed>>
     */
    protected function frames(ContainerInterface $services): array
    {
        return $this->client($services)->frames();
    }
}
