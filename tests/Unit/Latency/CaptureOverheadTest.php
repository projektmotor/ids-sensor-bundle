<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Latency;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Support\Telemetry\LatencyRecorder;

/**
 * Regressionsschutz für das Erfassungsbudget aus Konzept 2.1 Sensorik.
 *
 * Der Zweck ist NICHT, die 5 ms im 99. Perzentil zu beweisen — das ist eine Aussage
 * über echten Verkehr und wird im Betrieb über die Histogramme im Heartbeat
 * fortlaufend gemessen. Dieser Test fängt eine andere, häufigere Fehlerklasse: dass
 * jemand versehentlich etwas Teures in den Erfassungspfad einbaut.
 *
 * Der klassische Fall ist $request->getSession(): das startet unter einem PDO- oder
 * Redis-Session-Handler eine Session und macht daraus einen Netzwerk- oder
 * Datenbankzugriff — verboten laut Konzept 2.1, und im Code-Review leicht zu
 * übersehen. Dasselbe gilt für getId() auf einem uninitialisierten Doctrine-Proxy.
 *
 * Die Schwelle ist deshalb bewusst großzügig: sie soll nicht bei CI-Last
 * fehlschlagen, aber jeden I/O-Zugriff sofort fangen. I/O liegt drei
 * Größenordnungen darüber.
 */
#[Group('latency')]
final class CaptureOverheadTest extends TestCase
{
    /**
     * Erwartet werden wenige Mikrosekunden. 100 µs lässt reichlich Luft für
     * CI-Rauschen und fängt trotzdem jeden Netzwerk- oder Plattenzugriff
     * (typisch 1000 µs und mehr).
     */
    private const MAX_MICROSECONDS_PER_EVENT = 100.0;

    private const ITERATIONS = 2000;

    public function testCaptureStaysWellBelowTheThreshold(): void
    {
        $budget = new CaptureBudget(0); // unbegrenzt, wir wollen alle Läufe messen
        $collector = new EventBuffer(maxEvents: self::ITERATIONS);
        $recorder = new LatencyRecorder();

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $started = hrtime(true);

            $budget->guard(static function () use ($collector, $i): void {
                $event = CapturedEvent::now(Layer::Kernel, 'kernel.request', [
                    KernelPayload::FIELD_METHOD => 'GET',
                    KernelPayload::FIELD_PATH => '/api/orders/'.$i,
                    KernelPayload::FIELD_QUERY => ['expand' => 'items'],
                    KernelPayload::FIELD_USER_AGENT => 'Mozilla/5.0 (compatible)',
                    KernelPayload::FIELD_REFERER => null,
                    KernelPayload::FIELD_CONTENT_LENGTH => 0,
                ]);
                $event->setCorrelationId('req-'.$i);
                $event->setActor(new Actor(null, '203.0.113.42', 'hash', 'fingerprint'));
                $collector->append($event);
            });

            $recorder->recordCapture(hrtime(true) - $started);
        }

        $histogram = $recorder->inRequestOverheadUs();
        $average = $budget->spentMicroseconds() / self::ITERATIONS;

        self::assertSame(self::ITERATIONS, $collector->count(), 'Alle Events wurden erfasst');
        self::assertLessThan(
            self::MAX_MICROSECONDS_PER_EVENT,
            $average,
            \sprintf(
                'Erfassung kostet im Mittel %.2f µs pro Event (Grenze %.0f µs). '
                .'Wurde versehentlich I/O in den Erfassungspfad eingebaut? '
                .'Häufige Ursachen: $request->getSession(), getId() auf einem '
                .'uninitialisierten Doctrine-Proxy, gethostname() ohne Memoisierung.',
                $average,
                self::MAX_MICROSECONDS_PER_EVENT,
            ),
        );
        self::assertLessThan(
            (int) self::MAX_MICROSECONDS_PER_EVENT,
            $histogram->percentile(0.99),
            'Auch das 99. Perzentil muss unter der Schwelle bleiben',
        );
    }

    /**
     * Der Nachweis der Zweiteilung: die Erfassung darf keine Serialisierung
     * enthalten. Wäre json_encode Teil des Erfassungspfades, würde dieser Test
     * unauffällig bleiben — deshalb wird hier die Kostendifferenz gemessen.
     */
    public function testSerializationIsNotPartOfCapture(): void
    {
        $payload = [
            KernelPayload::FIELD_METHOD => 'POST',
            KernelPayload::FIELD_PATH => '/api/orders',
            KernelPayload::FIELD_QUERY => array_fill_keys(range('a', 'z'), 'wert'),
        ];

        $captureStart = hrtime(true);
        for ($i = 0; $i < 500; ++$i) {
            CapturedEvent::now(Layer::Kernel, 'kernel.request', $payload);
        }
        $captureNs = hrtime(true) - $captureStart;

        $encodeStart = hrtime(true);
        for ($i = 0; $i < 500; ++$i) {
            json_encode($payload, \JSON_THROW_ON_ERROR);
        }
        $encodeNs = hrtime(true) - $encodeStart;

        self::assertLessThan(
            $encodeNs,
            $captureNs,
            'Das Erfassen eines Events muss billiger sein als es zu serialisieren — '
            .'sonst passiert im Request-Pfad mehr als das Ablegen von Skalaren.',
        );
    }
}
