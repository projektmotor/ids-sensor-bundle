<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Transport\Shipper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\EventBatch;
use ProjektMotor\IdsSensor\Delivery\Transport\Message\Heartbeat;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\MessengerShipper;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Der Übergang vom Frame zum Transport — und die eine Stelle, an der ein Verlust
 * früher als Erfolg gezählt wurde.
 *
 * Ein Frame ohne `events` kehrte still zurück. Im Direktpfad zählte der
 * `FrameDispatcher` ihn anschließend als `sent`, obwohl nichts gesendet wurde; im
 * Drain-Pfad wertete der `SpoolDrainer` den Rücksprung als Erfolg und LÖSCHTE die
 * Zeile. Beides verletzt Konzept 4. an der Stelle, an der man es am wenigsten bemerkt.
 */
#[CoversClass(MessengerShipper::class)]
final class MessengerShipperTest extends TestCase
{
    public function testAFrameWithEventsIsSentAsAnEventBatch(): void
    {
        $transport = $this->transport();
        $frame = ['v' => 1, 'events' => [['event_type' => 'kernel.request']]];

        (new MessengerShipper($transport))->ship($frame);

        self::assertCount(1, $transport->gesendet);
        self::assertInstanceOf(EventBatch::class, $transport->gesendet[0]->getMessage());
        self::assertSame($frame, $transport->gesendet[0]->getMessage()->frame);
    }

    /**
     * Ein abgeschnittener Frame ist dauerhaft unversendbar — ein zweiter Versuch heilt
     * ihn nicht. Genau das bedeutet die Exception, und der Drainer verwirft die Zeile
     * daraufhin, statt sie ewig zu wiederholen.
     */
    public function testAFrameWithoutEventsThrowsInsteadOfPretendingSuccess(): void
    {
        $this->expectException(UnshippableFrameException::class);

        (new MessengerShipper($this->transport()))->ship(['v' => 1]);
    }

    /**
     * Ein LEERER Frame ist dagegen kein Fehler: Ein vollständig weggesampelter
     * Durchlauf könnte ihn erzeugen. Nichts zu senden ist dann die richtige Antwort —
     * und kein Verlust, denn es gibt nichts zu verlieren.
     */
    public function testAnEmptyFrameIsNeitherSentNorAnError(): void
    {
        $transport = $this->transport();

        (new MessengerShipper($transport))->ship(['v' => 1, 'events' => []]);

        self::assertSame([], $transport->gesendet);
    }

    public function testAHeartbeatTravelsAsItsOwnMessageType(): void
    {
        $transport = $this->transport();

        (new MessengerShipper($transport))->shipHeartbeat(['v' => 1, 'kind' => 'heartbeat']);

        self::assertInstanceOf(Heartbeat::class, $transport->gesendet[0]->getMessage());
    }

    /**
     * Fängt bewusst KEINE Fehler: Der `FrameDispatcher` entscheidet über Spool und
     * Circuit Breaker. Ein Shipper, der Fehler selbst verschluckt, nähme ihm diese
     * Entscheidung und machte den Verlust unsichtbar.
     */
    public function testATransportErrorIsNotSwallowed(): void
    {
        $transport = $this->transport(new \RuntimeException('Broker weg'));

        $this->expectException(\RuntimeException::class);

        (new MessengerShipper($transport))->ship(['v' => 1, 'events' => [['a' => 1]]]);
    }

    /**
     * @return TransportInterface&object{gesendet: list<Envelope>}
     */
    private function transport(?\Throwable $failWith = null): TransportInterface
    {
        return new class($failWith) implements TransportInterface {
            /** @var list<Envelope> */
            public array $gesendet = [];

            public function __construct(private readonly ?\Throwable $failWith)
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                if (null !== $this->failWith) {
                    throw $this->failWith;
                }

                $this->gesendet[] = $envelope;

                return $envelope;
            }

            /**
             * @return iterable<Envelope>
             */
            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };
    }
}
