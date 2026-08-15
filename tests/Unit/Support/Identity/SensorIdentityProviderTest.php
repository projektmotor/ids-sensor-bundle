<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Environment;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use ProjektMotor\IdsSensor\Support\Telemetry\FailSafeLogger;
use ProjektMotor\IdsSensor\Tests\Fixtures\ThrowingLogger;

/**
 * Die drei Felder, an denen der Collector die Instanz erkennt (Konzept 2.).
 *
 * Eine unbrauchbare Kennung wird protokolliert, nicht geworfen: Die Werte stammen aus
 * Umgebungsvariablen und sind erst zur Laufzeit bekannt. Eine Exception hier verstieße
 * gegen fail-open — der harte Abbruch gehört in `ids:sensor:setup-check`, das im
 * Deploy läuft.
 */
#[CoversClass(SensorIdentityProvider::class)]
final class SensorIdentityProviderTest extends TestCase
{
    public function testTheIdentityCarriesAllThreeFields(): void
    {
        $identity = $this->provider('shop-api')->get();

        self::assertSame('shop-api', $identity->applicationId);
        self::assertNotSame('', $identity->instanceId);
        self::assertSame(Environment::Prod, $identity->environment);
    }

    /**
     * Aufgelöst wird EINMAL: Die Kennung ändert sich während der Prozesslaufzeit nicht,
     * und `get()` liegt im Erfassungspfad unter dem 5-ms-Budget aus Konzept 2.1.
     */
    public function testTheIdentityIsResolvedOnlyOnce(): void
    {
        $provider = $this->provider('shop-api');

        self::assertSame($provider->get(), $provider->get());
    }

    /**
     * Eine unbrauchbare Kennung wird gemeldet — und der Sensor läuft weiter.
     */
    public function testAnUnusableIdentityIsReportedButDoesNotThrow(): void
    {
        $logger = new SammelnderLogger();

        $identity = $this->provider('shop api/mit leerzeichen', $logger)->get();

        self::assertNotSame([], $logger->meldungen, 'Ein Betreiber muss davon erfahren');
        self::assertSame('shop api/mit leerzeichen', $identity->applicationId, 'Aber der Sensor arbeitet weiter');
    }

    /**
     * Gemeldet wird EINMAL pro Prozess — sonst stünde die Meldung in jedem Request.
     */
    public function testTheComplaintIsLoggedOnlyOnce(): void
    {
        $logger = new SammelnderLogger();
        $provider = $this->provider('shop api', $logger);

        $provider->get();
        $provider->get();

        self::assertCount(1, $logger->meldungen);
    }

    /**
     * Ein werfender Logger darf die Kennung nicht kosten — sie ist Pflichtfeld jedes
     * Frames.
     */
    public function testAThrowingLoggerDoesNotCostTheIdentity(): void
    {
        $provider = $this->provider('shop api', new FailSafeLogger(new ThrowingLogger()));

        self::assertSame('shop api', $provider->get()->applicationId);
    }

    private function provider(string $applicationId, ?\Psr\Log\LoggerInterface $logger = null): SensorIdentityProvider
    {
        return new SensorIdentityProvider(
            $applicationId,
            new InstanceIdProvider('web-01'),
            new EnvironmentResolver('prod'),
            $logger,
        );
    }
}
