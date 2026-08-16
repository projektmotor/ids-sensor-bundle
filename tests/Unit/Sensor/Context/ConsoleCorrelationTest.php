<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelation;
use ProjektMotor\IdsSensor\Sensor\Context\ConsoleCorrelationListener;
use ProjektMotor\IdsSensor\Sensor\Context\CorrelationIdFactory;
use Symfony\Component\Console\ConsoleEvents;

/**
 * Die correlation_id eines Console-Laufs (Konzept 2.2.4).
 */
final class ConsoleCorrelationTest extends TestCase
{
    public function testOutsideAConsoleRunThereIsNoCorrelationId(): void
    {
        self::assertNull($this->correlation()->correlationId());
    }

    public function testARunGetsAnIdentifier(): void
    {
        $correlation = $this->correlation();
        $correlation->begin();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $correlation->correlationId(),
            'Dieselbe UUIDv7 wie im Request-Pfad',
        );
    }

    /**
     * Zwei Läufe teilen sich keine Kennung — sonst wäre nichts gewonnen.
     */
    public function testTwoRunsDoNotShareAnIdentifier(): void
    {
        $ersterLauf = $this->correlation();
        $ersterLauf->begin();

        $zweiterLauf = $this->correlation();
        $zweiterLauf->begin();

        self::assertNotSame($ersterLauf->correlationId(), $zweiterLauf->correlationId());
    }

    /**
     * Ein Command, der einen anderen aufruft, bleibt derselbe Lauf.
     *
     * Eine zweite Kennung risse die Events eines Durchlaufs auseinander und ließe nach
     * dem inneren Command eine Kennung ohne Anfang zurück.
     */
    public function testANestedCommandKeepsTheIdentifierOfTheRun(): void
    {
        $correlation = $this->correlation();
        $correlation->begin();
        $ausDemAeusserenCommand = $correlation->correlationId();

        $correlation->begin();

        self::assertSame($ausDemAeusserenCommand, $correlation->correlationId());
    }

    public function testTheListenerOpensTheScopeOnConsoleCommand(): void
    {
        $correlation = $this->correlation();

        (new ConsoleCorrelationListener($correlation))->onConsoleCommand();

        self::assertNotNull($correlation->correlationId());
    }

    /**
     * Vor dem Command, nicht danach: ein Business-Event aus dem Dienstgraphen, den der
     * Command anfordert, gehört bereits zu diesem Lauf.
     */
    public function testTheListenerRunsBeforeTheCommand(): void
    {
        $abonniert = ConsoleCorrelationListener::getSubscribedEvents();

        self::assertSame(['onConsoleCommand', 1024], $abonniert[ConsoleEvents::COMMAND]);
    }

    private function correlation(): ConsoleCorrelation
    {
        return new ConsoleCorrelation(new CorrelationIdFactory());
    }
}
