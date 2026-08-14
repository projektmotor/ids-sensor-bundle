<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Payload;

/**
 * Die event_type-Werte und payload-Feldnamen der Kernel-Ebene aus Konzept 3.1.1.
 *
 * Öffentliche API: Der Collector wertet genau diese Zeichenketten aus — die
 * Echtzeitregeln aus Konzept 4.3.1 prüfen `payload.path` und
 * `payload.exception_class`, nicht Framework-Objekte.
 *
 * WARUM NICHT IM NORMALISIERER
 *
 * Diese Konstanten standen bis zur Umstrukturierung in KernelEventNormalizer, und
 * die Sensoren importierten den Normalisierer allein, um sie zu lesen. Das koppelte
 * Phase A an Phase B in der falschen Richtung: der Sensor läuft im Request unter dem
 * 5-ms-Budget aus Konzept 2.1, der Normalisierer erst nach dem Absenden der Antwort.
 * Eine Abhängigkeit von dort nach hier lud zu der Verwechslung ein, die das Budget
 * kostet.
 *
 * Sachlich gehören sie ohnehin hierher: sie sind kein Detail der Übersetzung,
 * sondern das Format, in das übersetzt wird. Sensor und Normalisierer sind damit
 * beide Leser desselben Vertrags statt einer vom anderen.
 */
final class KernelPayload
{
    public const EVENT_REQUEST = 'kernel.request';
    public const EVENT_EXCEPTION = 'kernel.exception';
    public const EVENT_RESPONSE = 'kernel.response';

    public const FIELD_METHOD = 'method';
    public const FIELD_PATH = 'path';
    public const FIELD_QUERY = 'query';
    public const FIELD_ROUTE = 'route';
    public const FIELD_USER_AGENT = 'user_agent';
    public const FIELD_REFERER = 'referer';
    public const FIELD_CONTENT_LENGTH = 'content_length';
    public const FIELD_HTTP_STATUS = 'http_status';
    public const FIELD_EXCEPTION_CLASS = 'exception_class';
    public const FIELD_EXCEPTION_MESSAGE = 'exception_message';
    public const FIELD_RESPONSE_TIME_MS = 'response_time_ms';
    public const FIELD_RESPONSE_SIZE_BYTES = 'response_size_bytes';

    /**
     * Konzept 3.1.1 — kernel.exception: „auf 500 Zeichen gekürzt, um übergroße
     * Payloads zu vermeiden".
     */
    public const MAX_EXCEPTION_MESSAGE_LENGTH = 500;

    public const MAX_PATH_LENGTH = 2048;

    public const MAX_USER_AGENT_LENGTH = 512;

    private function __construct()
    {
    }
}
