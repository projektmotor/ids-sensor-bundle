<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport\Breaker;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerState;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\BreakerStateStoreInterface;
use ProjektMotor\IdsSensor\Delivery\Transport\Breaker\CircuitBreaker;

final class CircuitBreakerTest extends TestCase
{
    public function testStartsClosed(): void
    {
        self::assertFalse($this->breaker()->isOpen());
    }

    public function testSingleFailuresBelowTheThresholdDoNotOpen(): void
    {
        $breaker = $this->breaker(threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();

        self::assertFalse($breaker->isOpen(), 'Zwei von drei Fehlschlägen dürfen nicht öffnen');
    }

    /**
     * Der eigentliche Zweck: nach der Schwelle findet KEIN Verbindungsversuch mehr
     * statt.
     *
     * Ohne das kostet ein Broker-Ausfall jeden Request ein Timeout. Bei 20 ms Connect-
     * und 30 ms Read-Timeout sind das bis zu 50 ms Belegung pro Request — ein
     * FPM-Pool mit 32 Kindprozessen bei 200 Requests pro Sekunde ist damit erschöpft.
     * fail-open würde unter Last ins Gegenteil kippen.
     */
    public function testOpensAtTheThreshold(): void
    {
        $breaker = $this->breaker(threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        self::assertTrue($breaker->isOpen());
    }

    public function testSuccessClosesTheBreakerAgain(): void
    {
        $breaker = $this->breaker(threshold: 2);
        $breaker->recordFailure();
        $breaker->recordFailure();
        self::assertTrue($breaker->isOpen());

        $breaker->recordSuccess();

        self::assertFalse($breaker->isOpen());
    }

    /**
     * Nach Ablauf der Offen-Zeit muss wieder ein Versuch möglich sein — sonst bliebe
     * der Breaker für immer offen und der Sensor dauerhaft stumm.
     */
    public function testAfterTheOpenPeriodAnAttemptIsPossibleAgain(): void
    {
        $store = new InMemoryBreakerStore();
        $breaker = new CircuitBreaker($store, failureThreshold: 1, openForSeconds: 30);

        $breaker->recordFailure();
        self::assertTrue($breaker->isOpen());

        // Offen-Zeit künstlich in die Vergangenheit legen.
        $store->write(new BreakerState(1, microtime(true) - 1.0, 1));

        self::assertFalse($breaker->isOpen(), 'Halb-offen: die nächste Anfrage ist die Probe');
    }

    /**
     * Scheitert die Probe im Halb-offen-Zustand, muss sofort wieder geöffnet werden —
     * nicht erst nach erneutem Erreichen der Schwelle.
     */
    public function testAFailedProbeReopensImmediately(): void
    {
        $store = new InMemoryBreakerStore();
        $breaker = new CircuitBreaker($store, failureThreshold: 2, openForSeconds: 30);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $store->write(new BreakerState(2, microtime(true) - 1.0, 1));
        self::assertFalse($breaker->isOpen());

        $breaker->recordFailure();

        self::assertTrue($breaker->isOpen());
    }

    public function testDisabledStaysClosedForever(): void
    {
        $breaker = new CircuitBreaker(new InMemoryBreakerStore(), 1, 30, enabled: false);

        $breaker->recordFailure();
        $breaker->recordFailure();

        self::assertFalse($breaker->isOpen());
    }

    /**
     * Der Betreiber muss sehen können, ob und wie oft der Breaker gegriffen hat —
     * sonst ist ein Broker-Ausfall genau die stille Störung, die das Konzept
     * vermeiden will.
     */
    public function testSnapshotReportsTheState(): void
    {
        $breaker = $this->breaker(threshold: 1, openFor: 30);

        self::assertSame('closed', $breaker->snapshot()['state']);

        $breaker->recordFailure();
        $snapshot = $breaker->snapshot();

        self::assertSame('open', $snapshot['state']);
        self::assertSame(1, $snapshot['open_count']);
        self::assertGreaterThan(0, $snapshot['open_for_ms']);
    }

    public function testSuccessInNormalOperationWritesNothing(): void
    {
        $store = new InMemoryBreakerStore();
        $breaker = new CircuitBreaker($store, 3, 30);

        $breaker->recordSuccess();
        $breaker->recordSuccess();

        self::assertSame(0, $store->writes, 'Der häufigste Pfad soll nichts kosten');
    }

    private function breaker(int $threshold = 3, int $openFor = 30): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryBreakerStore(), $threshold, $openFor);
    }
}

/**
 * Zustandsspeicher im Arbeitsspeicher, für Tests.
 */
final class InMemoryBreakerStore implements BreakerStateStoreInterface
{
    public int $writes = 0;

    private BreakerState $state;

    public function __construct()
    {
        $this->state = BreakerState::closed();
    }

    public function read(): BreakerState
    {
        return $this->state;
    }

    public function write(BreakerState $state): void
    {
        ++$this->writes;
        $this->state = $state;
    }
}
