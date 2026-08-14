<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Bildet actor.session_id_hash nach Konzept 2.2.4 — Bildung der
 * Sitzungskontext-Felder.
 *
 * Zwei Eigenschaften sind hier wesentlich und beide leicht falsch zu machen:
 *
 * 1. Die Session-ID wird NIEMALS im Klartext übertragen. Andernfalls wäre die
 *    Event-Datenbank selbst ein Session-Hijacking-Vektor und würde genau die
 *    Angriffsfläche vergrößern, die sie überwachen soll. Der HMAC erfüllt den Zweck
 *    — Events derselben Sitzung verketten — vollständig, ohne die Sitzung
 *    übernehmbar zu machen.
 *
 * 2. Der Schlüssel ist ein eigener IDS-Schlüssel und ausdrücklich NICHT APP_SECRET.
 *    Die überwachte Anwendung kennt APP_SECRET, ein Angreifer mit Codeausführung
 *    also auch — er könnte aus einer gestohlenen Event-Datenbank die Hashes
 *    nachrechnen und wäre wieder am Ausgangspunkt.
 *
 * Gelesen wird der Cookie-Wert, NICHT $request->getSession()->getId(). Der
 * Unterschied ist keine Feinheit: getSession() materialisiert die Lazy-Factory und
 * startet gegebenenfalls eine Session. Unter einem PDO- oder Redis-Session-Handler
 * ist das ein Datenbank- oder Netzwerkzugriff — verboten laut Konzept 2.1 Sensorik —
 * und es setzt zusätzlich ein Cookie in einer Antwort, die vorher keines hatte. Der
 * Cookie-Wert ist genau die ID, die der Client gesendet hat, und damit exakt das,
 * was zur Verkettung gebraucht wird.
 *
 * @internal
 */
final class SessionIdHasher
{
    /**
     * Session-IDs bestehen bei PHP aus alphanumerischen Zeichen, Komma und
     * Bindestrich. Der Filter verhindert, dass ein Angreifer beliebige Inhalte über
     * ein manipuliertes Cookie in den Hash-Eingang schiebt.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9,-]{8,128}$/';

    private const DEFAULT_COOKIE_NAME = 'PHPSESSID';

    private ?string $memoRawId = null;

    private ?string $memoHash = null;

    public function __construct(
        private readonly ?string $key,
        private readonly ?string $cookieName = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function forRequest(Request $request): ?string
    {
        if (!$this->enabled || null === $this->key || '' === $this->key) {
            return null;
        }

        $rawId = $this->readSessionId($request);

        if (null === $rawId) {
            return null;
        }

        // Einmal pro ID merken, nicht pro Request: bei der Anmeldung wechselt die
        // Session-ID (SessionStrategyListener). Ein Request-weiter Zwischenspeicher
        // würde danach den alten Hash weiterliefern und die Sitzungsverkettung des
        // Collectors an genau der interessantesten Stelle zerreißen. Der Schlüssel
        // des Zwischenspeichers ist deshalb die ID selbst.
        if ($rawId === $this->memoRawId) {
            return $this->memoHash;
        }

        $this->memoRawId = $rawId;

        return $this->memoHash = hash_hmac('sha256', $rawId, $this->key);
    }

    private function readSessionId(Request $request): ?string
    {
        $name = $this->cookieName ?? (\ini_get('session.name') ?: self::DEFAULT_COOKIE_NAME);

        $value = $request->cookies->get($name);

        if (!\is_string($value) || 1 !== preg_match(self::ID_PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && null !== $this->key && '' !== $this->key;
    }
}
