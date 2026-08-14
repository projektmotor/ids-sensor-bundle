<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Security;

use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Security\ResourceIdentifierResolver;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectEnum;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithCompositeId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithExplicitId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithNullExplicitId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithoutId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithParametrizedId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithThrowingId;
use ProjektMotor\IdsSensor\Tests\Fixtures\Security\SubjectWithToString;
use Symfony\Component\HttpFoundation\Request;

/**
 * payload.resource nach Konzept 3.1.2: ein Identifier wie `Order#42`, „niemals das
 * vollständige Objekt".
 */
final class ResourceIdentifierResolverTest extends TestCase
{
    private ResourceIdentifierResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ResourceIdentifierResolver();
    }

    /**
     * Rollenprüfungen wie isGranted('ROLE_ADMIN') haben kein Subjekt. null ist dort die
     * richtige Auskunft — ein Platzhalter würde eine Ressource behaupten.
     */
    public function testWithoutASubjectNull(): void
    {
        self::assertNull($this->resolver->resolve(null));
    }

    public function testEntityWithIdentifier(): void
    {
        self::assertSame('SubjectWithId#42', $this->resolver->resolve(new SubjectWithId(42)));
        self::assertSame('SubjectWithId#A-7', $this->resolver->resolve(new SubjectWithId('A-7')));
    }

    public function testWithoutAnIdentifierOnlyTheClassName(): void
    {
        self::assertSame('SubjectWithoutId', $this->resolver->resolve(new SubjectWithoutId()));
    }

    /**
     * DER wichtigste Test dieser Klasse: getId() auf einem uninitialisierten
     * Doctrine-Proxy kann ein Lazy-Load auslösen und damit fehlschlagen. Konzept 2.1
     * Sensorik verbietet Datenbankzugriffe im Request-Pfad; ein Fehlschlag darf hier
     * niemals nach außen wirken.
     */
    public function testAThrowingIdentifierDoesNotRaise(): void
    {
        self::assertSame('SubjectWithThrowingId', $this->resolver->resolve(new SubjectWithThrowingId()));
    }

    public function testAGetterWithARequiredArgumentIsNotCalled(): void
    {
        self::assertSame('SubjectWithParametrizedId', $this->resolver->resolve(new SubjectWithParametrizedId()));
    }

    public function testACompositeIdentifierIsJoined(): void
    {
        self::assertSame('SubjectWithCompositeId#7-A-42', $this->resolver->resolve(new SubjectWithCompositeId()));
    }

    /**
     * __toString() könnte nachladen und schreibt im Zweifel personenbezogene Daten aus —
     * genau das, was „niemals das vollständige Objekt" verhindern soll.
     */
    public function testToStringIsNotUsed(): void
    {
        $resource = $this->resolver->resolve(new SubjectWithToString());

        self::assertSame('SubjectWithToString', $resource);
        self::assertStringNotContainsString('@example.com', (string) $resource);
    }

    public function testExplicitApplicationSettingTakesPrecedence(): void
    {
        self::assertSame('Invoice#2026-0815', $this->resolver->resolve(new SubjectWithExplicitId()));
    }

    public function testNullFromTheSettingFallsBackToDerivation(): void
    {
        self::assertSame('SubjectWithNullExplicitId#5', $this->resolver->resolve(new SubjectWithNullExplicitId()));
    }

    /**
     * Der access_control-Fall: Symfonys AccessListener übergibt den Request als Subjekt.
     * Ohne diese Sonderbehandlung trüge JEDE access_control-Ablehnung resource: null —
     * und Regelautoren verlören den abgelehnten Pfad.
     */
    public function testARequestBecomesThePath(): void
    {
        self::assertSame('Request#/admin/users', $this->resolver->resolve(Request::create('/admin/users')));
    }

    public function testEnumBecomesNameAndCase(): void
    {
        self::assertSame('SubjectEnum#Draft', $this->resolver->resolve(SubjectEnum::Draft));
    }

    public function testScalarsAndArrays(): void
    {
        self::assertSame('42', $this->resolver->resolve(42));
        self::assertSame('order-42', $this->resolver->resolve('order-42'));
        self::assertSame('array#3', $this->resolver->resolve([1, 2, 3]));
    }

    /**
     * Das Subjekt kann angreifergesteuert sein — etwa eine Zeichenkette aus einem
     * Request-Parameter. Ohne Obergrenze wäre payload.resource beliebig aufblähbar.
     */
    public function testOverlongValuesAreTruncated(): void
    {
        $resource = $this->resolver->resolve(str_repeat('X', 1000));

        self::assertIsString($resource);
        self::assertSame(ResourceIdentifierResolver::MAX_LENGTH, mb_strlen($resource));
    }
}
