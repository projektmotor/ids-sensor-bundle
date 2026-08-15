<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

/**
 * Die einmal pro Request festgehaltenen Fakten.
 *
 * Existiert, weil Konzept 3.2 Bewusste Feldredundanz verlangt, dass path (und bei
 * kernel.response zusätzlich route, bei kernel.exception zusätzlich content_length)
 * aus dem ursprünglichen Request in die Folge-Events kopiert werden. Beim
 * kernel.response ist der ursprüngliche Pfad sonst nicht mehr zuverlässig greifbar,
 * und der Collector müsste für jede Batch-Regel einen Self-Join über die
 * correlation_id fahren — laut Konzept der teuerste Teil der Abfrage.
 *
 * Enthält bewusst nur Skalare. Der Fingerprint wird gemerkt, weil er sich während
 * eines Requests nicht ändert und bei bis zu 200 Autorisierungsentscheidungen sonst
 * 200-mal berechnet würde. Der Session-Hash wird NICHT hier gemerkt: bei der
 * Anmeldung wechselt die Session-ID (Symfonys SessionStrategyListener), und
 * kernel.request der Anmelde-Anfrage trägt zu Recht noch die alte, alles danach die
 * neue. Beide werden über die gemeinsame correlation_id verbunden.
 *
 * @internal
 */
final class RequestSnapshot
{
    /**
     * Das gepufferte kernel.request-Event.
     *
     * Wird gehalten, damit der Nachtrag bei Priorität 7 — direkt nach der Firewall —
     * route und actor.user in genau dieses Event schreiben kann. Möglich, weil es zu
     * diesem Zeitpunkt noch unversendet im Puffer liegt.
     */
    public ?CapturedEvent $requestEvent = null;

    public ?string $route = null;

    public ?string $clientFingerprint = null;

    /**
     * Ob {@see ActorFactory} den Fingerabdruck
     * schon berechnet hat.
     *
     * Ein eigenes Feld, weil `null` beim Fingerabdruck ein GÜLTIGES Ergebnis ist: ein
     * Client ohne die betrachteten Header bekommt keinen, und mit
     * `fingerprint.enabled: false` bekommt ihn niemand. `??=` konnte den Fall nicht von
     * „noch nicht gerechnet" unterscheiden und rechnete deshalb bei jeder der bis zu 200
     * Autorisierungsentscheidungen neu — ausgerechnet bei header-losen Clients, also bei
     * Bots und Scannern.
     */
    public bool $clientFingerprintComputed = false;

    /**
     * @param array<array-key, mixed> $query
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly float $startedAt,
        public readonly bool $isMainRequest,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly int $contentLength = 0,
        public readonly ?string $userAgent = null,
        public readonly ?string $referer = null,
    ) {
    }

    /**
     * Vergangene Zeit seit Beginn des Requests in Millisekunden.
     *
     * Grundlage ist REQUEST_TIME_FLOAT, sofern verfügbar — dieser Wert umfasst auch
     * das Booten des Frameworks, und genau dort zeigt sich eine Überlastung zuerst.
     */
    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
