<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Event;

/**
 * Der Akteur eines Events — die vier actor.*-Felder aus Konzept Abschnitt 3.
 *
 * Alle vier Felder sind laut Konzept „immer vorhanden, aber nullable": je nach
 * Ebene und Ausführungskontext ist nicht jeder Wert bestimmbar. Konkret:
 *  - bei kernel.request liegt meist noch kein Security-Token vor -> user = null
 *    (Konzept 2.2.2 — Nutzerkontext auf Kernel-Ebene)
 *  - bei zustandslosen API-Requests existiert keine Session -> sessionIdHash = null
 *  - bei CLI-/Worker-Ausführung existiert kein HTTP-Kontext -> ip, sessionIdHash
 *    und clientFingerprint sind null (Konzept 2.2.4)
 *
 * Öffentliche API: die vier actor.*-Felder sind Pflichtfelder des übertragenen Events.
 */
final class Actor
{
    /**
     * @param string|null $user              Benutzerkennung, gekürzt auf MAX_USER_LENGTH
     * @param string|null $ip                Client-IP; nur korrekt, wenn die Anwendung
     *                                       framework.trusted_proxies gesetzt hat
     * @param string|null $sessionIdHash     HMAC-SHA256 der Session-ID; die Session-ID
     *                                       selbst wird niemals übertragen
     * @param string|null $clientFingerprint SHA256 über User-Agent, Accept-Language,
     *                                       Accept-Encoding
     */
    public function __construct(
        public readonly ?string $user = null,
        public readonly ?string $ip = null,
        public readonly ?string $sessionIdHash = null,
        public readonly ?string $clientFingerprint = null,
    ) {
    }

    /**
     * Obergrenze für die Benutzerkennung.
     *
     * Nötig, weil die Kennung bei fehlgeschlagener Anmeldung angreifergesteuert ist:
     * Symfonys UserBadge erlaubt bis zu 4096 Zeichen. Ohne Begrenzung könnte ein
     * Angreifer jedes Fehlversuch-Event um 4 KB aufblähen — und genau diese Events
     * treten bei Brute-Force massenhaft auf.
     */
    public const MAX_USER_LENGTH = 200;

    /**
     * Ein Akteur ohne jede bestimmbare Eigenschaft — CLI-, Worker- und
     * Cron-Kontext.
     */
    public static function anonymous(): self
    {
        return new self();
    }

    public function withUser(?string $user): self
    {
        return new self(
            self::truncateUser($user),
            $this->ip,
            $this->sessionIdHash,
            $this->clientFingerprint,
        );
    }

    /**
     * Derselbe Akteur ohne IP.
     *
     * Für die Business-Ebene mit `ip_from_request: false`: Konzept 2.2.4 sieht actor.ip
     * dort als null vor, „sofern nicht im Payload mitgeliefert". Sitzungshash und
     * Fingerabdruck bleiben, weil sie nicht an der IP hängen.
     */
    public function withoutIp(): self
    {
        return new self(
            $this->user,
            null,
            $this->sessionIdHash,
            $this->clientFingerprint,
        );
    }

    public static function truncateUser(?string $user): ?string
    {
        if (null === $user) {
            return null;
        }

        return mb_substr($user, 0, self::MAX_USER_LENGTH);
    }

    /**
     * @return array{user: string|null, ip: string|null, session_id_hash: string|null, client_fingerprint: string|null}
     */
    public function toArray(): array
    {
        return [
            EventSchema::ACTOR_USER => $this->user,
            EventSchema::ACTOR_IP => $this->ip,
            EventSchema::ACTOR_SESSION_ID_HASH => $this->sessionIdHash,
            EventSchema::ACTOR_CLIENT_FINGERPRINT => $this->clientFingerprint,
        ];
    }
}
