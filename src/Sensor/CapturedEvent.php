<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor;

use ProjektMotor\IdsEventData\Event\Actor;
use ProjektMotor\IdsEventData\Vocabulary\Layer;

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
    /**
     * Die Routenparameter, wie der Router sie aufgelöst hat.
     *
     * KEIN Drahtformatfeld. Der führende Unterstrich ist die Regel dieser Klasse:
     * Schlüssel, die so beginnen, sind Rohstoff für die Normalisierung und nie ein Feld
     * des Ereignisses — die Normalisierer bauen ihre Payloads aus einer Positivliste.
     * Das Gegenstück auf der Business-Ebene heißt `_ids_` (Konzept 3.1.3).
     *
     * Die Konstante steht HIER und nicht beim RouteResourceResolver der Normalisierung,
     * der sie liest: Der Sensor läuft in Phase A unter dem Latenzbudget aus Konzept 2.1,
     * der Normalisierer erst nach dem Absenden der Antwort. Ein Import in diese Richtung
     * kehrte die Schichtung um, und `testSensorDoesNotKnowProcessing()` liest dafür den
     * ganzen Dateiinhalt — auch Docblocks. Der Verweis steht deshalb als Prosa da,
     * dieselbe Regel wie im Ereignisformat-Paket für fremde Namensräume.
     */
    public const KEY_ROUTE_PARAMETERS = '_route_parameters';

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
     *
     * DIE LEERE ZEICHENKETTE IST HIER `null`
     *
     * Konzept 2.2.4 kennt für `actor.user` zwei Zustände: eine Kennung oder „nicht
     * vorhanden". `''` ist keiner von beiden — für den Collector ist es ein dritter,
     * der sich in einer Gruppierung wie ein eigener Nutzer verhält. Drei Aufrufstellen
     * normalisierten das selbst, der {@see Security\AuthenticationSensor} an vier
     * Stellen nicht: `getUserIdentifier()` gibt bei einem Token ohne Kennung `''`
     * zurück, und genau die Anmeldefehlschläge sind der Fall, für den das Feld da ist.
     *
     * Diese Methode ist der eine Ort, durch den jede Kennung geht — deshalb steht die
     * Regel hier und nicht siebenmal davor.
     */
    public function setActorUser(?string $user): void
    {
        $this->actor = $this->actor()->withUser('' === $user ? null : $user);
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
