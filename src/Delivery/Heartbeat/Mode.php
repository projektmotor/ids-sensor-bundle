<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Heartbeat;

/**
 * Wie der Heartbeat ausgelöst wird.
 *
 * Der Modus REIST IM PAYLOAD MIT. Das ist wesentlich, denn er bestimmt, was ein
 * ausbleibender Heartbeat bedeutet:
 *
 *  - Im Modus `Request` heißt Schweigen entweder „Sensor tot" ODER „kein Verkehr". Der
 *    Collector kann beides nicht unterscheiden und darf `ids.sensor_silent` nur mit
 *    Vorbehalt auslösen — auf einer nachts unbenutzten Anwendung wäre der Alarm sonst
 *    jede Nacht falsch.
 *  - Im Modus `Command` heißt Schweigen „Sensor oder cron tot", und das ist immer ein
 *    Befund.
 *
 * Ohne diese Angabe müsste der Collector das Schlimmste annehmen (Falschalarme) oder das
 * Beste (er verpasst die Stilllegung) — und Konzept 2. nennt die lautlose Stilllegung
 * ausdrücklich als die gefährlichste Angriffsform.
 *
 * @internal
 */
enum Mode: string
{
    /**
     * Ausgelöst am Ende eines Requests, gedrosselt über eine Stempelablage.
     *
     * Braucht keine Ops-Einrichtung und funktioniert nach `composer require` sofort.
     * Schweigt aber bei fehlendem Verkehr.
     */
    case Request = 'request';

    /**
     * Ausgelöst von `ids:sensor:heartbeat` über cron oder systemd-Timer.
     *
     * Verlässlich auch ohne Verkehr — der Preis ist ein Einrichtungsschritt beim Betrieb.
     */
    case Command = 'command';

    /**
     * Beide: der Command ist die Zusage, der Request-Pfad der Rückfall.
     *
     * Das ist der Zielzustand für `auto`, sobald ein Command-Lauf nachgewiesen ist. Die
     * Drosselung greift für beide Wege gemeinsam, es gibt also keine doppelten Meldungen.
     */
    case Both = 'both';
}
