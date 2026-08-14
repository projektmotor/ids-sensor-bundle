<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Heartbeat;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Heartbeat\Scheduler;
use ProjektMotor\IdsSensor\Support\Identity\EnvironmentResolver;
use ProjektMotor\IdsSensor\Support\Identity\InstanceIdProvider;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;

/**
 * Die Drosselung des Heartbeats.
 *
 * Läuft ausschließlich über die Stempeldatei, indem für jeden Test eine eigene
 * application_id benutzt wird — der APCu-Schlüssel ist damit je Test eindeutig. Das ist
 * nötig, weil APCu prozessübergreifend ist: ohne Trennung würde ein Test den Stempel des
 * nächsten vorbelasten, und der wäre „noch nicht fällig", obwohl er nie gesendet hat.
 */
final class SchedulerTest extends TestCase
{
    private string $stampFile;

    protected function setUp(): void
    {
        $this->stampFile = sys_get_temp_dir().'/ids-heartbeat-'.bin2hex(random_bytes(6)).'.stamp';
    }

    protected function tearDown(): void
    {
        @unlink($this->stampFile);
    }

    public function testWithoutAPreviousDispatchDueImmediately(): void
    {
        self::assertTrue($this->scheduler()->isDue());
    }

    public function testAfterDispatchDueAgainOnlyAfterTheInterval(): void
    {
        $scheduler = $this->scheduler(60);
        $jetzt = 1_800_000_000;

        $scheduler->markSent($jetzt);

        self::assertFalse($scheduler->isDue($jetzt + 59));
        self::assertTrue($scheduler->isDue($jetzt + 60));
    }

    /**
     * Intervall 0 heißt „nie" — der ausdrückliche Weg, den Heartbeat abzuschalten, ohne
     * die Dienste zu entfernen.
     */
    public function testIntervalZeroNeverSends(): void
    {
        self::assertFalse($this->scheduler(0)->isDue());
    }

    /**
     * Der Stempel muss die Prozessgrenze überschreiten. Ohne das wäre die Drosselung im
     * request-getriebenen Modus wirkungslos: unter PHP-FPM läuft jeder Request in einem
     * anderen Prozess, und jeder würde „noch nie gesendet" feststellen.
     *
     * Zusätzlich ist die Datei der einzige gemeinsame Zustand zwischen CLI und FPM — beide
     * sehen getrennte APCu-Segmente.
     */
    public function testTheStampSurvivesTheProcess(): void
    {
        $jetzt = 1_800_000_000;
        $this->scheduler(60)->markSent($jetzt);

        // Ein frischer Scheduler mit derselben Kennung: er darf NICHT senden wollen.
        self::assertFalse($this->scheduler(60)->isDue($jetzt + 10));
        self::assertSame(10, $this->scheduler(60)->secondsSinceLastSend($jetzt + 10));
    }

    private function scheduler(int $interval = 60): Scheduler
    {
        return new Scheduler($this->identityProvider(), $interval, $this->stampFile);
    }

    private function identityProvider(): SensorIdentityProvider
    {
        // Die application_id enthält den Zufallsanteil der Stempeldatei: damit ist auch der
        // APCu-Schlüssel je Testlauf eindeutig.
        $application = 'shop-api-'.basename($this->stampFile, '.stamp');

        return new SensorIdentityProvider(
            $application,
            new InstanceIdProvider('web-03'),
            new EnvironmentResolver('prod', EnvironmentResolver::DEFAULT_MAP, \ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment::Prod),
        );
    }
}
