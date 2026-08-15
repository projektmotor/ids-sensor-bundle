<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsSensor\Delivery\Transport\MessageSerializer;
use ProjektMotor\IdsSensor\IdsSensorBundle;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Sampling durch den echten Container (Konzept 4.2.3).
 *
 * Die Raten 0.0 und 1.0 sind die beiden Ränder und deterministisch — damit ist die
 * Verdrahtung prüfbar, ohne die Ziehung von außen manipulieren zu müssen.
 */
final class SamplingTest extends IntegrationTestCase
{
    /**
     * Ein gewöhnlicher 200er-Request besteht nur aus info-Events. Bei Rate 0 fällt er
     * vollständig weg — und mit ihm der ganze Frame, denn es bleibt nichts zu senden.
     */
    public function testAtRateZeroAPureInfoRequestIsDropped(): void
    {
        $kernel = $this->boot('rate-null', 0.0);
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        self::assertSame([], $this->batches($services), 'Kein Frame, wenn alle Events weggesampelt sind');

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(2, $counters->get(Counters::DROPPED_SAMPLING), 'kernel.request und kernel.response');
        self::assertSame(0, $counters->get(Counters::SENT));
    }

    /**
     * Der wichtigere Fall: ein FEHLERHAFTER Request behält bei Rate 0 alles — auch seine
     * info-Events. Sonst käme bei einem 500er gerade der Anfragekontext nicht an.
     */
    public function testAFailingRequestSurvivesRateZeroEntirely(): void
    {
        $kernel = $this->boot('relevant', 0.0);
        $services = $this->services($kernel);

        $request = Request::create('/boom');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        $batches = $this->batches($services);
        self::assertCount(1, $batches);
        self::assertSame(3, $batches[0]->eventCount(), 'request, exception und response');

        /** @var Counters $counters */
        $counters = $services->get('ids_sensor.counters');
        self::assertSame(0, $counters->get(Counters::DROPPED_SAMPLING));
    }

    /**
     * Die Vorgabe 1.0 darf das Feld NICHT setzen: `sampling_rate` ist ein optionales Feld
     * (Ergänzung zu Konzept Abschnitt 3), und es in jedem Event mitzuschicken würde jedes
     * Event ohne Erkenntnisgewinn verbreitern.
     */
    public function testAtTheDefaultNoSamplingRateIsInTheEvent(): void
    {
        $kernel = $this->boot('vorgabe', 1.0);
        $services = $this->services($kernel);

        $request = Request::create('/ok');
        $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

        /** @var array{events: list<array<string, mixed>>} $frame */
        $frame = json_decode($this->wireBody($services), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotSame([], $frame['events']);

        foreach ($frame['events'] as $event) {
            self::assertArrayNotHasKey('sampling_rate', $event);
        }
    }

    /**
     * Und wenn gesampelt wurde, MUSS die Rate mitreisen — ohne sie wäre jedes Aggregat des
     * Collectors um den Faktor 1/rate zu klein, ohne Möglichkeit zur Korrektur.
     */
    public function testASampledEventCarriesTheRateOnTheWire(): void
    {
        // Rate 1.0 wäre wirkungslos, Rate 0.0 verwirft alles. Ein Wert dazwischen ist
        // nötig; die Ziehung ist zufällig, deshalb wird bis zu einem Überleben wiederholt.
        // Bei 0.9 ist die Wahrscheinlichkeit, dass 50 Versuche alle scheitern, etwa
        // 10^-50 — praktisch ausgeschlossen und trotzdem beschränkt.
        $kernel = $this->boot('mitrate', 0.9);
        $services = $this->services($kernel);

        $gefunden = null;

        for ($versuch = 0; $versuch < 50 && null === $gefunden; ++$versuch) {
            $request = Request::create('/ok');
            $kernel->terminate($request, $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true));

            $batches = $this->batches($services);

            if ([] === $batches) {
                continue;
            }

            /** @var array{events: list<array<string, mixed>>} $frame */
            $frame = json_decode($this->wireBody($services), true, 512, \JSON_THROW_ON_ERROR);
            $gefunden = $frame['events'][0] ?? null;
        }

        self::assertNotNull($gefunden, 'Bei Rate 0,9 muss innerhalb von 50 Versuchen ein Request überleben');
        self::assertSame(0.9, $gefunden['sampling_rate']);
    }

    private function wireBody(ContainerInterface $services): string
    {
        /** @var MessageSerializer $serializer */
        $serializer = $services->get(IdsSensorBundle::SERIALIZER_ID);

        return $serializer->encode(new Envelope($this->batches($services)[0]))['body'];
    }

    private function boot(string $variant, float $rate): TestKernel
    {
        $kernel = new TestKernel([
            'application_id' => 'shop-api',
            'environment' => 'prod',
            'session_hash' => ['key' => self::SESSION_KEY],
            'transport' => ['dsn' => 'in-memory://'],
            'sampling' => ['info_rate' => $rate],
            'budget' => ['capture_us' => 0],
            // Der Heartbeat würde die Nachrichtenzählung dieses Tests nur verrauschen.
            'heartbeat' => ['enabled' => false],
        ], 'sampling-'.$variant);
        $kernel->boot();

        return $kernel;
    }
}
