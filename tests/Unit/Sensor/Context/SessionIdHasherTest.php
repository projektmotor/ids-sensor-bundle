<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Sensor\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProjektMotor\IdsSensor\Sensor\Context\SessionIdHasher;
use Symfony\Component\HttpFoundation\Request;

final class SessionIdHasherTest extends TestCase
{
    private const KEY = 'ein-dedizierter-ids-schluessel-mit-32-zeichen';

    private const SESSION_ID = 'abc123def456ghi789';

    public function testBuildsHmacOverTheCookieValue(): void
    {
        $hash = $this->hasher()->forRequest($this->requestWithSession(self::SESSION_ID));

        self::assertSame(hash_hmac('sha256', self::SESSION_ID, self::KEY), $hash);
        self::assertSame(64, \strlen((string) $hash), 'SHA256 als Hex sind 64 Zeichen');
    }

    /**
     * DIE Zusage aus Konzept 2.2.4: „Die Session-ID selbst wird niemals gespeichert:
     * andernfalls wäre die Event-Datenbank selbst ein Session-Hijacking-Vektor und
     * würde die Angriffsfläche vergrößern, die sie überwachen soll."
     */
    public function testTheRawSessionIdDoesNotAppearInTheResult(): void
    {
        $hash = $this->hasher()->forRequest($this->requestWithSession(self::SESSION_ID));

        self::assertNotNull($hash);
        self::assertStringNotContainsString(self::SESSION_ID, $hash);
    }

    /**
     * Der zweite Teil derselben Zusage: der Schlüssel ist ein eigener und
     * ausdrücklich NICHT APP_SECRET. Verschiedene Schlüssel müssen verschiedene
     * Hashes ergeben — sonst wäre der Schlüssel wirkungslos.
     */
    public function testDifferentKeysYieldDifferentHashes(): void
    {
        $request = $this->requestWithSession(self::SESSION_ID);

        $a = (new SessionIdHasher('schluessel-a-mit-ausreichender-laenge'))->forRequest($request);
        $b = (new SessionIdHasher('schluessel-b-mit-ausreichender-laenge'))->forRequest($request);

        self::assertNotSame($a, $b);
    }

    /**
     * Der Kern der Umsetzung: gelesen wird der COOKIE, nicht $request->getSession().
     *
     * getSession() würde die Lazy-Factory materialisieren und gegebenenfalls eine
     * Session starten — unter einem PDO- oder Redis-Handler ein Datenbank- oder
     * Netzwerkzugriff, den Konzept 2.1 Sensorik im Request-Pfad verbietet, und
     * zusätzlich ein gesetztes Cookie in einer Antwort, die vorher keines hatte.
     *
     * Der Test hinterlegt eine Session-Factory, die beim Aufruf wirft. Läuft der
     * Hasher trotzdem durch, ist bewiesen, dass er sie nicht anfasst.
     */
    public function testDoesNotTouchTheSession(): void
    {
        $request = $this->requestWithSession(self::SESSION_ID);
        $request->setSessionFactory(static function (): never {
            throw new \LogicException('getSession() wurde aufgerufen — das darf nicht passieren');
        });

        $hash = $this->hasher()->forRequest($request);

        self::assertNotNull($hash);
        // hasSession() ohne Argument meldet schon true, sobald eine Session-FACTORY
        // hinterlegt ist. Erst skipIfUninitialized sagt aus, ob die Session
        // tatsächlich materialisiert wurde — und genau das darf nicht passieren.
        self::assertFalse(
            $request->hasSession(skipIfUninitialized: true),
            'Es darf keine Session materialisiert worden sein',
        );
    }

    public function testWithoutACookieNoHash(): void
    {
        self::assertNull($this->hasher()->forRequest(Request::create('/')));
    }

    /**
     * Ohne Filter könnte ein Angreifer über ein manipuliertes Cookie beliebige Inhalte
     * in den Hash-Eingang schieben.
     *
     * @param non-empty-string $cookieValue
     */
    #[DataProvider('implausibleCookieProvider')]
    public function testImplausibleCookieValuesAreDiscarded(string $cookieValue): void
    {
        $request = Request::create('/');
        $request->cookies->set('PHPSESSID', $cookieValue);

        self::assertNull($this->hasher()->forRequest($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function implausibleCookieProvider(): iterable
    {
        yield 'zu kurz' => ['abc'];
        yield 'Sonderzeichen' => ['abc123$%^&*()def'];
        yield 'Pfadangabe' => ['../../etc/passwd'];
        yield 'zu lang' => [str_repeat('a', 200)];
        yield 'leer' => [' '];
    }

    /**
     * Der Zwischenspeicher darf NICHT pro Request greifen, sondern pro ID.
     *
     * Bei der Anmeldung wechselt die Session-ID (Symfonys SessionStrategyListener).
     * Ein Request-weiter Zwischenspeicher würde danach den alten Hash weiterliefern
     * und die Sitzungsverkettung des Collectors an genau der interessantesten Stelle
     * zerreißen — nämlich beim Übergang von anonym zu angemeldet.
     */
    public function testChangingTheSessionIdYieldsANewHash(): void
    {
        $hasher = $this->hasher();

        $before = $hasher->forRequest($this->requestWithSession('vor-der-anmeldung-1234'));
        $after = $hasher->forRequest($this->requestWithSession('nach-der-anmeldung-5678'));

        self::assertNotSame($before, $after);
    }

    public function testTheSameIdYieldsTheSameHash(): void
    {
        $hasher = $this->hasher();

        self::assertSame(
            $hasher->forRequest($this->requestWithSession(self::SESSION_ID)),
            $hasher->forRequest($this->requestWithSession(self::SESSION_ID)),
        );
    }

    public function testDisabledReturnsNull(): void
    {
        $hasher = new SessionIdHasher(self::KEY, 'PHPSESSID', enabled: false);

        self::assertNull($hasher->forRequest($this->requestWithSession(self::SESSION_ID)));
        self::assertFalse($hasher->isEnabled());
    }

    public function testAMissingKeyReturnsNull(): void
    {
        $hasher = new SessionIdHasher(null);

        self::assertNull($hasher->forRequest($this->requestWithSession(self::SESSION_ID)));
        self::assertFalse($hasher->isEnabled());
    }

    public function testCustomCookieName(): void
    {
        $request = Request::create('/');
        $request->cookies->set('MEINE_SESSION', self::SESSION_ID);

        $hasher = new SessionIdHasher(self::KEY, 'MEINE_SESSION');

        self::assertSame(hash_hmac('sha256', self::SESSION_ID, self::KEY), $hasher->forRequest($request));
    }

    /**
     * Nach dem Request darf keine Roh-Session-ID mehr im Objekt liegen.
     *
     * In einer Worker-Laufzeit überlebte sie im Klartext bis zum nächsten Request. Der
     * Test greift auf das private Feld zu, weil genau dessen Inhalt die Zusage ist —
     * über die öffentliche Schnittstelle ist „ist weg" nicht von „wird neu berechnet"
     * zu unterscheiden.
     */
    public function testResetForgetsTheRawSessionId(): void
    {
        $hasher = $this->hasher();
        $hasher->forRequest($this->requestWithSession(self::SESSION_ID));

        $gemerkt = new \ReflectionProperty($hasher, 'memoRawId');

        self::assertSame(self::SESSION_ID, $gemerkt->getValue($hasher), 'Vorbedingung: die ID liegt im Objekt');

        $hasher->reset();

        self::assertNull($gemerkt->getValue($hasher), 'Nach reset() darf keine Klartext-ID mehr im Objekt liegen');
    }

    private function hasher(): SessionIdHasher
    {
        return new SessionIdHasher(self::KEY, 'PHPSESSID');
    }

    private function requestWithSession(string $sessionId): Request
    {
        $request = Request::create('/');
        $request->cookies->set('PHPSESSID', $sessionId);

        return $request;
    }
}
