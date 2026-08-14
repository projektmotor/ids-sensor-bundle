<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\EventFormat\Event\Actor;
use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Support\RawPayload\Gate;

/**
 * Baut den gemeinsamen Envelope aus Konzept Abschnitt 3 um eine ebenenspezifische
 * Nutzlast.
 *
 * Alle drei Sensorebenen laufen hier durch. Das ist der Grund, warum die
 * Pflichtfelder nicht ebenenweise auseinanderlaufen können: die 14 Felder aus
 * Konzept Abschnitt 3 entstehen an genau einer Stelle.
 *
 * Läuft in Phase B, also nach dem Absenden der Antwort.
 *
 * @internal
 */
final class EventFactory
{
    public function __construct(
        private readonly EventIdGeneratorInterface $idGenerator,
        private readonly ?Gate $rawGate = null,
    ) {
    }

    /**
     * Der event_type wird AUSDRÜCKLICH übergeben und nicht aus dem erfassten Event
     * gelesen.
     *
     * Grund: auf der Business-Ebene ist der Name anwendungsdefiniert und wird beim
     * Normalisieren bereinigt (Konzept 2.1.3 empfiehlt punktgetrennte
     * snake_case-Segmente, kann es aber nicht erzwingen). Läse die Factory still
     * $captured->eventType, ginge die Bereinigung verloren — sie wäre berechnet und
     * verworfen. Kernel- und Security-Ebene übergeben hier einfach den erfassten Namen.
     *
     * @param array<string, mixed> $payload ebenenspezifisch, Struktur nach Konzept 3.1
     */
    public function create(
        CapturedEvent $captured,
        SensorIdentity $identity,
        string $eventType,
        string $correlationId,
        Actor $actor,
        Severity $severity,
        array $payload,
    ): NormalizedEvent {
        return new NormalizedEvent(
            $this->idGenerator->generate(),
            self::toDateTime($captured->occurredAt),
            $captured->layer,
            $eventType,
            $correlationId,
            $severity,
            $identity,
            $actor,
            $payload,
            // raw wird HIER verworfen und nicht erst beim Serialisieren: die Closure baut
            // Header, Redaktion und Trace erst beim Aufruf auf. Ein null hier heißt, dass
            // diese Arbeit nie stattfindet — bei info-Events, also der Masse, ist das der
            // Unterschied zwischen kostenlos und teuer.
            null === $this->rawGate || $this->rawGate->allows($severity)
                ? $captured->rawBuilder()
                : null,
        );
    }

    /**
     * Wandelt den im Request billig erfassten Unix-Zeitstempel in ein
     * DateTimeImmutable in UTC.
     *
     * Passiert bewusst erst hier: die Erzeugung eines DateTimeImmutable kostet
     * rund 1 µs, und bei bis zu 200 Autorisierungsentscheidungen pro Request wäre
     * das ein messbarer Anteil des Erfassungsbudgets aus Konzept 2.1.
     */
    public static function toDateTime(float $unixTimestamp): \DateTimeImmutable
    {
        $formatted = number_format($unixTimestamp, 6, '.', '');
        $dateTime = \DateTimeImmutable::createFromFormat('U.u', $formatted);

        if (false === $dateTime) {
            // Kann bei absurden Werten (NAN, INF) auftreten. Ein Event mit
            // Ersatzzeitstempel ist besser als eine Exception im Sensor.
            $dateTime = new \DateTimeImmutable('@0');
        }

        return $dateTime->setTimezone(new \DateTimeZone('UTC'));
    }
}
