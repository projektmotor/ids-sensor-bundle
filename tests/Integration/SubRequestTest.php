<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Integration;

use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Sensor\EventBuffer;
use ProjektMotor\IdsSensor\Tests\Fixtures\IntegrationTestCase;
use ProjektMotor\IdsSensor\Tests\Fixtures\TestKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Sub-Requests — bislang null Tests für drei ausdrückliche Zusagen.
 *
 * `SubRequestMode` regelt einen Fall, den das Konzept offenlässt, und trifft dabei
 * eine Entscheidung in beide Richtungen: Request- und Response-Events aus
 * Sub-Requests werden unterdrückt (ihr Pfad ist eine Kopie des Elternpfades, jede
 * Schwellwertregel zählte sonst mehrfach — die Fehlalarmquelle aus Konzept 2.2.1),
 * ihre EXCEPTIONS dagegen durchgelassen (der `InlineFragmentRenderer` verschluckt sie
 * bei `ignore_errors` vollständig, sie stünden sonst nirgends).
 *
 * Gerendert wird über den echten `FragmentHandler`, also über `Request::duplicate()` —
 * denselben Weg, den Twigs `render()` und ESI nehmen.
 */
final class SubRequestTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private const CONFIG = [
        'application_id' => '9b1c4f80-2a77-4d3e-9c15-7e2b6a4f0d31',
        'environment_id' => '3f6d21ac-58b0-4e91-a7c4-11d9e0b8c522',
        'sensor_id' => 'c40a7e13-9d62-4b88-8f05-6a1e3c72b9d4',
        'session_hash' => ['key' => self::SESSION_KEY],
    ];

    /**
     * Die Vorgabe: eine Seite mit einem Fragment ergibt zwei Events, nicht vier.
     */
    public function testTheDefaultSuppressesRequestAndResponseOfASubRequest(): void
    {
        $events = $this->handle('/mit-fragment', 'sub-vorgabe');

        self::assertSame(
            ['kernel.request', 'kernel.response'],
            array_map(static fn (CapturedEvent $event): string => $event->eventType, $events),
        );
        self::assertSame('/mit-fragment', $events[0]->get(KernelPayload::FIELD_PATH));
    }

    /**
     * Die Exception eines Fragments ist die einzige Spur, die es von ihm gibt.
     */
    public function testAFragmentExceptionIsCapturedByDefault(): void
    {
        $events = $this->handle('/mit-kaputtem-fragment', 'sub-vorgabe');

        $exceptions = array_values(array_filter(
            $events,
            static fn (CapturedEvent $event): bool => KernelPayload::EVENT_EXCEPTION === $event->eventType,
        ));

        self::assertCount(1, $exceptions, 'Die Fragment-Exception muss erfasst werden');
        self::assertSame(
            \RuntimeException::class,
            $exceptions[0]->get(KernelPayload::FIELD_EXCEPTION_CLASS),
        );
    }

    /**
     * Sub-Request-Events erben die correlation_id des Haupt-Requests — sonst wäre die
     * Exception eines Fragments dem auslösenden Vorgang nicht zuzuordnen.
     */
    public function testASubRequestInheritsTheCorrelationId(): void
    {
        $events = $this->handle('/mit-kaputtem-fragment', 'sub-vorgabe');

        $ids = array_unique(array_map(
            static fn (CapturedEvent $event): ?string => $event->correlationId(),
            $events,
        ));

        self::assertCount(1, $ids, 'Alle Events eines Vorgangs teilen eine correlation_id');
    }

    /**
     * `none` schaltet auch die Exceptions ab — der Weg für Anwendungen, denen die
     * Fragment-Fehler ihrer eigenen Seiten nichts sagen.
     */
    public function testNoneSuppressesEvenTheException(): void
    {
        $events = $this->handle('/mit-kaputtem-fragment', 'sub-none', 'none');

        self::assertSame(
            [],
            array_filter(
                $events,
                static fn (CapturedEvent $event): bool => KernelPayload::EVENT_EXCEPTION === $event->eventType,
            ),
        );
    }

    /**
     * `all` erfasst den Sub-Request vollständig — mit genau der Verdopplung, die die
     * Vorgabe vermeidet.
     */
    public function testAllCapturesTheSubRequestAsWell(): void
    {
        $events = $this->handle('/mit-fragment', 'sub-all', 'all');

        $requests = array_filter(
            $events,
            static fn (CapturedEvent $event): bool => KernelPayload::EVENT_REQUEST === $event->eventType,
        );

        self::assertCount(2, $requests, 'Rahmen und Fragment — die Verdopplung, die exceptions_only vermeidet');
    }

    /**
     * @return list<CapturedEvent>
     */
    private function handle(string $pfad, string $variant, ?string $modus = null): array
    {
        $config = self::CONFIG;

        if (null !== $modus) {
            $config['layers'] = ['kernel' => ['sub_requests' => $modus]];
        }

        $kernel = new TestKernel($config, $variant);
        $kernel->boot();
        $kernel->handle(Request::create($pfad), HttpKernelInterface::MAIN_REQUEST, false);

        /** @var EventBuffer $buffer */
        $buffer = $this->services($kernel)->get('ids_sensor.event_buffer');

        return $buffer->all();
    }
}
