<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\CaptureBudget;
use ProjektMotor\IdsSensor\Tests\Fixtures\ThrowingLogger;

final class CaptureBudgetTest extends TestCase
{
    public function testRunsTheCaptureAndMeasuresIt(): void
    {
        $budget = new CaptureBudget(1500);
        $ran = false;

        $budget->guard(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
        self::assertSame(0, $budget->skipped());
        self::assertGreaterThan(0.0, $budget->spentMicroseconds());
    }

    /**
     * Die zentrale fail-open-Zusage aus Konzept 4. IdsBackendBundle: ein Fehler im
     * Sensor darf die überwachte Anwendung unter keinen Umständen beeinträchtigen.
     */
    public function testGuardLetsNoExceptionEscape(): void
    {
        $budget = new CaptureBudget(1500);

        $budget->guard(static function (): void {
            throw new \RuntimeException('Sensor kaputt');
        });

        $this->expectNotToPerformAssertions();
    }

    /**
     * Ein defekter Logger darf den Schutz nicht aushebeln.
     *
     * Der Fehlerpfad ist die empfindlichste Stelle: Wirft der Logger dort — ein
     * Monolog-Handler auf voller Platte genügt —, entwiche die Exception ausgerechnet
     * beim Behandeln eines Fehlers.
     */
    public function testAThrowingLoggerDoesNotEscape(): void
    {
        $budget = new CaptureBudget(1500, new ThrowingLogger());

        $budget->guard(static function (): void {
            throw new \RuntimeException('Sensor kaputt');
        });

        self::assertSame(1, $budget->failed(), 'Gezählt wird trotzdem');
    }

    /**
     * Ein Fehler in der Erfassung wird GEZÄHLT, nicht bloß geschluckt.
     *
     * Hier stand ein optionaler `$onError`-Rückruf — und keine der acht Aufrufstellen
     * übergab ihn. Der Zweig war toter Produktionscode, und ein Defekt im Sensor war von
     * einem ruhigen Request nicht zu unterscheiden: kein Zähler, kein Logeintrag. Das
     * widersprach Konzept 4. („Jeder verworfene oder verlorene Event wird gezählt") und
     * wörtlich dem Docblock von CapturingEventDispatcher.
     *
     * Als Rückruf war die Zusage opt-in — sie galt nur, wenn jede Aufrufstelle daran
     * dachte. Jetzt zählt das Budget selbst, und niemand kann es vergessen.
     */
    public function testAFailedCaptureIsCounted(): void
    {
        $budget = new CaptureBudget(1500);

        $budget->guard(static function (): void {
            throw new \RuntimeException('Sensor kaputt');
        });
        $budget->guardMandatory(static function (): void {
            throw new \RuntimeException('auch kaputt');
        });

        self::assertSame(2, $budget->failed());
        self::assertSame(0, $budget->skipped(), 'Ein Fehler ist keine Budgetüberschreitung');
    }

    public function testEvenFailedCaptureCostsBudget(): void
    {
        $budget = new CaptureBudget(1500);

        $budget->guard(static function (): void {
            throw new \RuntimeException('Sensor kaputt');
        });

        self::assertGreaterThan(0.0, $budget->spentMicroseconds());
    }

    /**
     * Ist das Budget erschöpft, wird nicht mehr erfasst — und der Verlust wird
     * gezählt, damit er collectorseitig sichtbar wird.
     */
    public function testAnExceededBudgetStopsCapture(): void
    {
        // Ein Limit von 1 µs ist mit einem usleep sicher zu überschreiten.
        // (0 wäre falsch: das bedeutet laut Vertrag „unbegrenzt".)
        $budget = new CaptureBudget(1);

        // Erster Aufruf verbraucht das Mikro-Budget.
        $budget->guard(static function (): void {
            usleep(2000);
        });

        $ran = false;
        $budget->guard(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertFalse($ran);
        self::assertSame(1, $budget->skipped());
    }

    /**
     * Die konstruktionsbedingt begrenzten Events dürfen NICHT wegen erschöpften
     * Budgets entfallen.
     *
     * Der Hintergrund ist gemessen, nicht theoretisch: die erste Erfassung eines
     * Prozesses kostet rund 2,4 ms (Laden aller beteiligten Klassen), im
     * eingeschwungenen Zustand unter 200 µs. Mit einem pauschalen Budget von 1500 µs
     * war damit im ersten Request jedes neu gestarteten FPM-Kindprozesses der
     * Response-Sensor stillgelegt — bei pm.max_requests = 500 systematisch jeder
     * 500. Request ohne Statuscode, und zwar ohne jede Meldung.
     *
     * Mit kernel.response ginge das wichtigste Einzelfeld verloren: daran hängen die
     * Severity-Ableitung und die Scanning-Erkennung über gehäufte 403/404-Antworten.
     */
    public function testMandatoryCaptureRunsEvenOnAnExhaustedBudget(): void
    {
        $budget = new CaptureBudget(1);
        $budget->guard(static function (): void {
            usleep(2000);
        });
        self::assertTrue($budget->isExhausted());

        $ran = false;
        $budget->guardMandatory(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran, 'Ein begrenztes Kernel-Event darf nicht dem Budget zum Opfer fallen');
    }

    public function testMandatoryCaptureIsMeasuredNonetheless(): void
    {
        $budget = new CaptureBudget(0);

        $budget->guardMandatory(static function (): void {
            usleep(500);
        });

        self::assertGreaterThan(
            0.0,
            $budget->spentMicroseconds(),
            'Die Messung soll die Wahrheit über die eigenen Kosten sagen, auch wenn sie nicht zum Überspringen führt',
        );
    }

    public function testMandatoryCaptureLetsNoExceptionEscape(): void
    {
        $budget = new CaptureBudget(1500);

        $budget->guardMandatory(static function (): void {
            throw new \RuntimeException('Sensor kaputt');
        });

        $this->expectNotToPerformAssertions();
    }

    public function testLimitZeroMeansUnlimited(): void
    {
        $budget = new CaptureBudget(0);

        $budget->guard(static function (): void {
            usleep(1000);
        });

        self::assertFalse($budget->isExhausted());

        $ran = false;
        $budget->guard(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    public function testResetRewindsConsumptionButNotTheCounter(): void
    {
        $budget = new CaptureBudget(1);
        $budget->guard(static function (): void {
            usleep(2000);
        });
        $budget->guard(static function (): void {});
        self::assertSame(1, $budget->skipped());

        $budget->reset();

        self::assertFalse($budget->isExhausted());
        self::assertSame(0.0, $budget->spentMicroseconds());
        self::assertSame(1, $budget->skipped(), 'skipped ist eine Prozess-Statistik');
    }
}
