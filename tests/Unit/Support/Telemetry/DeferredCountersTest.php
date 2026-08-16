<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Telemetry;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\CapturedEventBinder;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelation;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Sensor\Security\AccessDecisionSensor;
use ProjektMotor\IdsSensor\Sensor\Security\ResourceIdentifierResolver;
use ProjektMotor\IdsSensor\Support\Telemetry\Counters;
use ProjektMotor\IdsSensor\Support\Telemetry\DeferredCounters;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Die Verlustzähler aus Phase A müssen den Sensor verlassen.
 *
 * Konzept 4. IdsBackendBundle (Restrisiko): fail-open ist nur vertretbar, wenn jeder
 * verworfene Event gezählt wird und der Stand nach außen kommt. Ein Zähler, der nur im
 * erfassenden Objekt steht, erfüllt das nicht — von außen wäre stiller Verlust nicht von
 * vollständiger Erfassung zu unterscheiden.
 *
 * @internal
 */
final class DeferredCountersTest extends TestCase
{
    public function testTheBufferLossesAreCollected(): void
    {
        $buffer = new EventBuffer(maxEvents: 1);
        $buffer->append($this->anyEvent());
        $buffer->append($this->anyEvent());

        $counters = new Counters('epoch-1', 4711);
        $this->collectorFor($counters, $buffer)->collect();

        self::assertSame(1, $counters->get(Counters::DROPPED_BUFFER_FULL));
    }

    /**
     * Die Obergrenze des AccessDecisionSensor ist eine eigene Verlustquelle und bekommt
     * einen eigenen Zähler — sonst wäre „diese Seite prüft mehr Rechte als vorgesehen"
     * nicht von „die Erfassungszeit war alle" zu trennen.
     */
    public function testTheDecisionOverflowIsCollected(): void
    {
        $counters = new Counters('epoch-1', 4711);
        $sensor = $this->sensorWithCap(1);

        $sensor->decide(new NullToken(), ['EDIT'], 'erstes');
        $sensor->decide(new NullToken(), ['DELETE'], 'zweites');

        $this->collectorFor($counters, new EventBuffer(), $sensor)->collect();

        self::assertSame(1, $counters->get(Counters::DROPPED_DECISION_CAP));
    }

    /**
     * Ohne Security-Ebene gibt es den Sensor nicht. Das ist kein Sonderfall, sondern die
     * Vorgabe für jede Anwendung, die nur die Kernel-Ebene betreibt.
     */
    public function testAMissingDecisionSensorIsNotAnError(): void
    {
        $counters = new Counters('epoch-1', 4711);

        $this->collectorFor($counters, new EventBuffer())->collect();

        self::assertSame(0, $counters->get(Counters::DROPPED_DECISION_CAP));
    }

    /**
     * Konzept 3.4: „Die Zähler sind absolut, nicht als Zuwachs." Bei at-least-once würde
     * ein rückwärts laufender Stand als Zählerrücksprung ankommen und dort einen
     * Prozessneustart vortäuschen.
     */
    public function testAnAlreadyHigherCountIsNotLowered(): void
    {
        $counters = new Counters('epoch-1', 4711);
        $counters->raiseTo(Counters::DROPPED_BUFFER_FULL, 7);

        $this->collectorFor($counters, new EventBuffer())->collect();

        self::assertSame(7, $counters->get(Counters::DROPPED_BUFFER_FULL));
    }

    private function collectorFor(
        Counters $counters,
        EventBuffer $buffer,
        ?AccessDecisionSensor $sensor = null,
    ): DeferredCounters {
        // capture_us = 0 schaltet das Zeitbudget ab: hier wird der Sammler geprüft, nicht
        // die Uhr.
        return new DeferredCounters($counters, $buffer, new CaptureBudget(0), $sensor);
    }

    private function sensorWithCap(int $maxPerRequest): AccessDecisionSensor
    {
        $inner = new class implements AccessDecisionManagerInterface {
            /**
             * @param array<array-key, mixed> $attributes
             */
            public function decide(TokenInterface $token, array $attributes, mixed $object = null, mixed ...$rest): bool
            {
                return false;
            }
        };

        return new AccessDecisionSensor(
            $inner,
            new EventBuffer(),
            new CapturedEventBinder(
                new RequestSnapshotRegistry(),
                new ActorFactory(
                    new SessionIdHasher(null, null, false),
                    new ClientFingerprinter(enabled: false),
                    new TokenStorage(),
                ),
                new ConsoleCorrelation(new CorrelationIdFactory()),
            ),
            new ResourceIdentifierResolver(),
            new CaptureBudget(0),
            new RequestStack(),
            maxPerRequest: $maxPerRequest,
        );
    }

    private function anyEvent(): CapturedEvent
    {
        return CapturedEvent::now(Layer::Kernel, 'kernel.request', []);
    }
}
