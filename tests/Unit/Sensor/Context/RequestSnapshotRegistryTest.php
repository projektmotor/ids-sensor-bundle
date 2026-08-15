<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshot;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshotRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die Zuordnung Request → Snapshot, auf der die Verkettung der Events beruht.
 *
 * Der Rückfall auf den Haupt-Request, den diese Klasse früher hatte, war für ein
 * Beweissystem die falsche Richtung: Wer den Snapshot eines FREMDEN Requests bekommt,
 * erbt dessen `correlationId`, `path` und `startedAt` — und die Events zweier
 * verschiedener Anfragen hingen an derselben Spur. Genau die Verkettung, auf der die
 * Regeln X1–X4 aus Konzept 4.3.3 aufbauen, wäre still verfälscht.
 */
#[CoversClass(RequestSnapshotRegistry::class)]
final class RequestSnapshotRegistryTest extends TestCase
{
    public function testASnapshotIsFoundForItsOwnRequest(): void
    {
        $registry = new RequestSnapshotRegistry();
        $request = Request::create('/eins');
        $snapshot = $this->snapshot('korrelation-1', '/eins');

        $registry->set($request, $snapshot);

        self::assertSame($snapshot, $registry->get($request));
    }

    /**
     * Ein fremder Request bekommt NICHTS — nicht den Haupt-Snapshot.
     */
    public function testAForeignRequestGetsNothingInsteadOfTheMainSnapshot(): void
    {
        $registry = new RequestSnapshotRegistry();
        $registry->set(Request::create('/eins'), $this->snapshot('korrelation-1', '/eins'));

        self::assertNull(
            $registry->get(Request::create('/zwei')),
            'Ein fremder Snapshot verfälschte Korrelation, Pfad und Startzeit',
        );
    }

    public function testWithoutARequestThereIsNoSnapshot(): void
    {
        self::assertNull((new RequestSnapshotRegistry())->get(null));
    }

    /**
     * Für den einen Fall, in dem der Haupt-Request wirklich gemeint ist: die Vererbung
     * der correlation_id an Sub-Requests.
     */
    public function testOnlyAMainRequestBecomesTheMainSnapshot(): void
    {
        $registry = new RequestSnapshotRegistry();
        $haupt = $this->snapshot('korrelation-1', '/eins');
        $sub = $this->snapshot('korrelation-1', '/fragment', isMainRequest: false);

        $registry->set(Request::create('/eins'), $haupt);
        $registry->set(Request::create('/fragment'), $sub);

        self::assertSame($haupt, $registry->mainSnapshot(), 'Ein Sub-Request darf den Haupt-Snapshot nicht verdrängen');
    }

    /**
     * In einer Worker-Laufzeit hielte die starke Referenz sonst den vorigen Request am
     * Leben — und der nächste erbte seine correlation_id.
     */
    public function testResetReleasesTheMainSnapshot(): void
    {
        $registry = new RequestSnapshotRegistry();
        $registry->set(Request::create('/eins'), $this->snapshot('korrelation-1', '/eins'));

        $registry->reset();

        self::assertNull($registry->mainSnapshot());
    }

    private function snapshot(string $korrelation, string $pfad, bool $isMainRequest = true): RequestSnapshot
    {
        return new RequestSnapshot($korrelation, microtime(true), $isMainRequest, 'GET', $pfad);
    }
}
