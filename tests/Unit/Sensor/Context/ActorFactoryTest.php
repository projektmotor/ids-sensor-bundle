<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\ActorFactory;
use ProjektMotor\IdsSensor\Sensor\Context\ClientFingerprinter;
use ProjektMotor\IdsSensor\Sensor\Context\RequestSnapshot;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use Symfony\Component\HttpFoundation\Request;

#[\PHPUnit\Framework\Attributes\CoversClass(ActorFactory::class)]
final class ActorFactoryTest extends TestCase
{
    /**
     * Ein `null`-Fingerabdruck wird gemerkt wie jeder andere.
     *
     * Gemerkt wurde mit `??=`, und beim Fingerabdruck ist `null` ein GÜLTIGES Ergebnis:
     * ein Client ohne die betrachteten Header bekommt keinen. `??=` hielt das für „noch
     * nichts da" und rechnete bei jeder der bis zu 200 Autorisierungsentscheidungen neu
     * — ausgerechnet bei header-losen Clients, also bei Bots und Scannern, für die der
     * Docblock der Methode den Schreibzugriff auf das fremde Objekt rechtfertigt.
     *
     * Nachweisbar am Ergebnis: Kommen die Header nach dem ersten Aufruf hinzu, muss der
     * zweite den gemerkten Wert liefern und nicht neu rechnen.
     */
    public function testAnAbsentFingerprintIsMemoizedToo(): void
    {
        // Request::create() setzt User-Agent und Accept-Language selbst — der
        // header-lose Client, um den es hier geht, entsteht erst durch das Entfernen.
        $request = Request::create('/');

        foreach (ClientFingerprinter::DEFAULT_HEADERS as $header) {
            $request->headers->remove($header);
        }

        $snapshot = $this->snapshot();
        $factory = $this->factory();

        self::assertNull($factory->forRequest($request, $snapshot)->clientFingerprint, 'Vorbedingung: keine Header, kein Abdruck');

        $request->headers->set('User-Agent', 'Mozilla/5.0');

        self::assertNull(
            $factory->forRequest($request, $snapshot)->clientFingerprint,
            'Der gemerkte Wert gilt — sonst war nichts gemerkt',
        );
    }

    public function testAComputedFingerprintIsReused(): void
    {
        $request = Request::create('/');
        $request->headers->set('User-Agent', 'Mozilla/5.0');

        $snapshot = $this->snapshot();
        $factory = $this->factory();

        $erster = $factory->forRequest($request, $snapshot)->clientFingerprint;

        self::assertNotNull($erster, 'Vorbedingung: mit Header gibt es einen Abdruck');

        $request->headers->set('User-Agent', 'ein anderer Client');

        self::assertSame($erster, $factory->forRequest($request, $snapshot)->clientFingerprint);
    }

    private function factory(): ActorFactory
    {
        return new ActorFactory(
            new SessionIdHasher(null, false),
            new ClientFingerprinter(),
        );
    }

    private function snapshot(): RequestSnapshot
    {
        return new RequestSnapshot('korrelation', microtime(true), true, 'GET', '/');
    }
}
