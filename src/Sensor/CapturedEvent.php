<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor;

use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;

/**
 * Ein im Request erfasstes, noch NICHT normalisiertes Event.
 *
 * Bewusst billig: nur Skalare in einem Array, kein DateTimeImmutable (dessen
 * Erzeugung kostet ~1 µs — bei bis zu 200 Autorisierungsentscheidungen pro Request
 * wäre das ein messbarer Anteil des Erfassungsbudgets). Der Zeitpunkt wird als
 * float aus microtime(true) gehalten und erst in Phase B in ein
 * DateTimeImmutable übersetzt.
 *
 * Veränderbar, und zwar mit Absicht: der Kernel-Sensor erfasst kernel.request bei
 * Priorität 1024 — vor dem Router und vor der Firewall, weil sonst routenloser
 * Scanner-Traffic unsichtbar bliebe. route und actor.user sind dort noch nicht
 * bekannt und werden bei Priorität 7 nachgetragen. Das ist nur möglich, weil das
 * Event zu diesem Zeitpunkt noch unversendet im Puffer liegt.
 *
 * @internal
 */
final class CapturedEvent
{
    private ?Actor $actor = null;

    private ?string $correlationId = null;

    /**
     * @param array<string, mixed>                    $data       Rohwerte für die Normalisierung
     * @param (\Closure(): array<string, mixed>)|null $rawBuilder liefert das raw-Feld; wird nur bei
     *                                                            warning/critical aufgerufen
     */
    public function __construct(
        public readonly Layer $layer,
        public readonly string $eventType,
        public readonly float $occurredAt,
        private array $data = [],
        private ?\Closure $rawBuilder = null,
    ) {
    }

    /**
     * @param array<string, mixed>                    $data
     * @param (\Closure(): array<string, mixed>)|null $rawBuilder
     */
    public static function now(
        Layer $layer,
        string $eventType,
        array $data = [],
        ?\Closure $rawBuilder = null,
    ): self {
        return new self($layer, $eventType, microtime(true), $data, $rawBuilder);
    }

    /**
     * Der Akteur wird pro Event gehalten, nicht pro Request: laut Konzept 2.2.2 —
     * Nutzerkontext auf Kernel-Ebene ist bei kernel.request noch kein Security-Token
     * verfügbar (die Firewall greift später), bei kernel.response praktisch immer.
     * Dasselbe Request-weite Actor-Objekt wäre also für das eine Event falsch.
     */
    public function actor(): Actor
    {
        return $this->actor ??= Actor::anonymous();
    }

    public function setActor(Actor $actor): void
    {
        $this->actor = $actor;
    }

    /**
     * Trägt die Benutzerkennung nach — der Nachtrag bei Priorität 7, direkt nach der
     * Firewall. Möglich, weil das Event zu diesem Zeitpunkt noch unversendet im
     * Puffer liegt.
     */
    public function setActorUser(?string $user): void
    {
        $this->actor = $this->actor()->withUser($user);
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return (\Closure(): array<string, mixed>)|null
     */
    public function rawBuilder(): ?\Closure
    {
        return $this->rawBuilder;
    }

    /**
     * @param (\Closure(): array<string, mixed>)|null $rawBuilder
     */
    public function setRawBuilder(?\Closure $rawBuilder): void
    {
        $this->rawBuilder = $rawBuilder;
    }
}
