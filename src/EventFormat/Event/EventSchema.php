<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Event;

/**
 * Die Version und die Feldnamen des normalisierten Event-Formats aus Konzept
 * Abschnitt 3 (Normalisierungsformat).
 *
 * Öffentliche API: Der Collector darf sich auf SCHEMA_VERSION und die
 * Feldnamen-Konstanten verlassen.
 *
 * Bewusst NICHT konfigurierbar: Der Sensor sendet genau eine Version. Wäre die
 * Version einstellbar, könnte eine kompromittierte Anwendung eine alte Version
 * behaupten und damit collectorseitig den nachsichtigen Pfad auslösen — dieselbe
 * Begründung, aus der laut Konzept 2. IdsSensorBundle die Erkennungskonfiguration
 * nicht beim Sensor liegt.
 */
final class EventSchema
{
    /**
     * Bump-Regeln:
     * - kein Bump bei additiven, optionalen Feldern (Collector ignoriert Unbekanntes)
     * - Bump bei Entfernen/Umbenennen/Umtypisieren eines Pflichtfeldes, geänderter
     *   Bedeutung eines Feldes oder geändertem Hash-Verfahren
     */
    public const SCHEMA_VERSION = 1;

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_EVENT_ID = 'event_id';
    public const FIELD_TIMESTAMP = 'timestamp';
    public const FIELD_LAYER = 'layer';
    public const FIELD_EVENT_TYPE = 'event_type';
    public const FIELD_CORRELATION_ID = 'correlation_id';
    public const FIELD_EVENT_SEVERITY = 'event_severity';
    public const FIELD_APPLICATION_ID = 'application_id';
    public const FIELD_INSTANCE_ID = 'instance_id';
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_PAYLOAD = 'payload';

    /** Optional, nur bei event_severity in (warning, critical) — siehe Konzept Abschnitt 3. */
    public const FIELD_RAW = 'raw';

    /**
     * Optional. Ergänzung zum Konzept: Abschnitt 4.2.3 verlangt, dass die
     * Sampling-Rate im Event mitreist, damit Aggregate hochgerechnet werden können.
     * Fehlt das Feld, gilt 1.0 (kein Sampling).
     */
    public const FIELD_SAMPLING_RATE = 'sampling_rate';

    public const ACTOR_USER = 'user';
    public const ACTOR_IP = 'ip';
    public const ACTOR_SESSION_ID_HASH = 'session_id_hash';
    public const ACTOR_CLIENT_FINGERPRINT = 'client_fingerprint';

    /**
     * Die Pflichtfelder aus Konzept Abschnitt 3: immer vorhanden, unabhängig von
     * der Ebene. Die vier actor.*-Felder sind ebenfalls immer vorhanden, aber
     * nullable — sie stehen deshalb in ACTOR_FIELDS.
     *
     * @var list<string>
     */
    public const MANDATORY_FIELDS = [
        self::FIELD_SCHEMA_VERSION,
        self::FIELD_EVENT_ID,
        self::FIELD_TIMESTAMP,
        self::FIELD_LAYER,
        self::FIELD_EVENT_TYPE,
        self::FIELD_CORRELATION_ID,
        self::FIELD_EVENT_SEVERITY,
        self::FIELD_APPLICATION_ID,
        self::FIELD_INSTANCE_ID,
        self::FIELD_ENVIRONMENT,
        self::FIELD_ACTOR,
        self::FIELD_PAYLOAD,
    ];

    /** @var list<string> */
    public const ACTOR_FIELDS = [
        self::ACTOR_USER,
        self::ACTOR_IP,
        self::ACTOR_SESSION_ID_HASH,
        self::ACTOR_CLIENT_FINGERPRINT,
    ];

    /**
     * Zeitstempelformat: UTC, Millisekundenpräzision, literales Z.
     *
     * Das Konzept zeigt in Abschnitt 3 nur ein Beispiel und legt kein Format fest.
     * Hier verbindlich gemacht, weil die Uhrendrift-Messung des Collectors
     * (Konzept 2.2.1 — Anwendungs- und Instanzkontext) ein stabiles Format braucht.
     */
    public const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private function __construct()
    {
    }
}
