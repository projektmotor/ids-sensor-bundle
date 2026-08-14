<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Delivery\Dispatch;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Delivery\Dispatch\CoherentInfoSampler;
use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Environment;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;

/**
 * Das Volumen-Stellrad aus Konzept 4.2.3.
 *
 * Die Ziehung ist in den Tests festgelegt: `alwaysDrop()` und `alwaysKeep()` übergeben eine
 * eigene Ziehfunktion. Ein statistischer Test („bei 0,5 etwa die Hälfte") wäre bei jedem
 * Lauf mit einer gewissen Wahrscheinlichkeit rot — und ein Test, der manchmal fehlschlägt,
 * wird irgendwann ignoriert.
 */
final class CoherentInfoSamplerTest extends TestCase
{
    /**
     * Die Vorgabe schaltet den ganzen Schritt ab: keine Ziehung, keine Kopie, kein
     * sampling_rate im Event.
     */
    public function testRateOneIsCompletelyInert(): void
    {
        $sampler = new CoherentInfoSampler(1.0);
        $events = [$this->event(Layer::Kernel, Severity::Info)];

        self::assertFalse($sampler->isActive());
        self::assertSame($events, $sampler->sample($events), 'Nicht einmal kopiert');
    }

    public function testASurvivingRequestKeepsAllEventsAndCarriesTheRate(): void
    {
        $sampler = $this->alwaysKeep(0.1);

        $sampled = $sampler->sample([
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Kernel, Severity::Info, 'kernel.response'),
        ]);

        self::assertCount(2, $sampled);

        // Ohne die Rate wäre jede collectorseitige Zählung um den Faktor 1/rate zu klein,
        // und niemand könnte das im Nachhinein korrigieren (Konzept 4.2.3).
        foreach ($sampled as $event) {
            self::assertSame(0.1, $event->samplingRate);
        }
    }

    /**
     * DER Kern des Namens: die Entscheidung gilt für den ganzen Request. Ein
     * kernel.response ohne den zugehörigen kernel.request wäre für den Collector nicht von
     * einem Verbindungsabbruch zu unterscheiden, und jeder Self-Join über die
     * correlation_id (Konzept 3.2) liefe ins Leere.
     */
    public function testADroppedRequestLosesALLItsInfoEventsNotSingleOnes(): void
    {
        $sampled = $this->alwaysDrop(0.1)->sample([
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Kernel, Severity::Info, 'kernel.response'),
        ]);

        self::assertSame([], $sampled, 'Alles oder nichts — niemals ein halber Request');
    }

    /**
     * Enthält der Request irgendein warning/critical, bleiben seine info-Events. Sonst käme
     * bei einem 500er gerade der kernel.request nicht an — also Pfad, Methode, Query und
     * User-Agent. Die Exception allein sagt, DASS etwas kaputtging, nicht WORAUF.
     */
    public function testARelevantRequestKeepsItsInfoContext(): void
    {
        $sampled = $this->alwaysDrop(0.1)->sample([
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Kernel, Severity::Critical, 'kernel.exception'),
            $this->event(Layer::Kernel, Severity::Critical, 'kernel.response'),
        ]);

        self::assertCount(3, $sampled);
    }

    public function testTheContextRuleCanBeDisabled(): void
    {
        $sampler = new CoherentInfoSampler(0.1, false, static fn (): int => 999_999);

        $sampled = $sampler->sample([
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Kernel, Severity::Critical, 'kernel.exception'),
        ]);

        self::assertCount(1, $sampled, 'Nur das critical bleibt');
        self::assertSame(Severity::Critical, $sampled[0]->severity);
    }

    /**
     * warning und critical werden laut Konzept 4.2.3 NIE gesampelt — sie tragen die
     * Erkennung.
     */
    public function testWarningAndCriticalAreNeverDropped(): void
    {
        $sampled = $this->alwaysDrop(0.0)->sample([
            $this->event(Layer::Kernel, Severity::Warning, 'kernel.response'),
            $this->event(Layer::Kernel, Severity::Critical, 'kernel.exception'),
        ]);

        self::assertCount(2, $sampled);
    }

    /**
     * Security- und Business-Events bleiben, auch als info.
     *
     * Ein erfolgreicher Login ist info, ist aber die Voraussetzung für Regel B5 (Erfolg
     * nach Fehlversuchsserie). Ein Business-Event ist laut Konzept 2.1.3 die EINZIGE
     * Signalklasse für erfolgreiche Angriffe. Beide sind selten — sie zu sampeln spart
     * kein Volumen und kostet Erkennung.
     */
    public function testSecurityAndBusinessEventsAreNeverSampled(): void
    {
        $sampled = $this->alwaysDrop(0.0)->sample([
            $this->event(Layer::Security, Severity::Info, 'security.authentication.success'),
            $this->event(Layer::Business, Severity::Info, 'order.amount_overridden'),
        ]);

        self::assertCount(2, $sampled);
    }

    /**
     * Der wichtige Mischfall: die info-Events der Kernel-Ebene fallen weg, die
     * Security-Events desselben Requests bleiben. Sonst verlöre ein weggesampelter Request
     * seinen Login.
     */
    public function testInMixedCasesTheUnsampleableEventsArePreserved(): void
    {
        $sampled = $this->alwaysDrop(0.1)->sample([
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Security, Severity::Info, 'security.authentication.success'),
            $this->event(Layer::Kernel, Severity::Info, 'kernel.response'),
        ]);

        self::assertCount(1, $sampled);
        self::assertSame('security.authentication.success', $sampled[0]->eventType);
    }

    /**
     * Rate 0.0 heißt „alle info-Events der Kernel-Ebene weg" — ohne Ziehung.
     */
    public function testRateZeroDropsWithoutDrawing(): void
    {
        $gezogen = false;
        $sampler = new CoherentInfoSampler(0.0, true, static function () use (&$gezogen): int {
            $gezogen = true;

            return 0;
        });

        self::assertSame([], $sampler->sample([$this->event(Layer::Kernel, Severity::Info)]));
        self::assertFalse($gezogen, 'Bei Rate 0 braucht es keine Ziehung');
    }

    /**
     * Der Verlust ist absichtlich, aber nicht unsichtbar: ohne Zähler wäre eine zu niedrig
     * gesetzte Rate von einem Sensordefekt nicht zu unterscheiden.
     */
    public function testTheLossIsCountable(): void
    {
        $before = [
            $this->event(Layer::Kernel, Severity::Info, 'kernel.request'),
            $this->event(Layer::Kernel, Severity::Info, 'kernel.response'),
        ];

        $dropping = $this->alwaysDrop(0.1);
        $keeping = $this->alwaysKeep(0.1);

        self::assertSame(2, $dropping->droppedCount($before, $dropping->sample($before)));
        self::assertSame(0, $keeping->droppedCount($before, $keeping->sample($before)));
    }

    private function alwaysKeep(float $rate): CoherentInfoSampler
    {
        // Ziehung 0 liegt immer unter der Schwelle.
        return new CoherentInfoSampler($rate, true, static fn (): int => 0);
    }

    private function alwaysDrop(float $rate): CoherentInfoSampler
    {
        // Ziehung am oberen Rand liegt immer über der Schwelle.
        return new CoherentInfoSampler($rate, true, static fn (): int => 999_999);
    }

    private function event(Layer $layer, Severity $severity, string $eventType = 'kernel.request'): NormalizedEvent
    {
        return new NormalizedEvent(
            'e-'.$eventType.'-'.$severity->value,
            new \DateTimeImmutable('2026-08-14T10:00:00Z'),
            $layer,
            $eventType,
            '11111111-1111-4111-8111-111111111111',
            $severity,
            new SensorIdentity('shop-api', 'web-03', Environment::Prod),
            new Actor('alice', '203.0.113.7'),
        );
    }
}
