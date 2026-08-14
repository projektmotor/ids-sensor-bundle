<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport;

use ProjektMotor\IdsSensor\EventFormat\Frame\DispatchPath;

/**
 * Stellt fest, ob die Antwort vom laufenden Skript abkoppelbar ist.
 *
 * WORUM ES GEHT
 *
 * Phase B des Sensors läuft auf `kernel.terminate`, also nach `Response::send()`. Unter
 * PHP-FPM ruft Symfony dort intern `fastcgi_finish_request()` auf: die Verbindung zum
 * Client ist geschlossen, das Skript läuft weiter. Alles danach kostet keine Antwortzeit
 * mehr, nur noch Belegung eines Worker-Prozesses. Deshalb darf dort das Netzwerk benutzt
 * werden.
 *
 * Unter mod_php gibt es diese Funktion NICHT, und es existiert kein zuverlässiges
 * Äquivalent. Die Verbindung bleibt bis zum Skriptende offen.
 *
 * WARUM DAS NICHT „PRAKTISCH DOCH GEHT"
 *
 * Man könnte argumentieren, die Antwort sei auch ohne `fastcgi_finish_request()` beim
 * Client: sobald `Content-Length` gesetzt und der Puffer geleert ist, rendert der Browser.
 * Darauf darf man sich aber nicht verlassen. Sobald die Antwort CHUNKED übertragen wird —
 * kein `Content-Length`, eine StreamedResponse, oder Apache schaltet per `mod_deflate` auf
 * `Transfer-Encoding: chunked` um — wartet der Client auf den abschließenden Null-Chunk,
 * und der kommt erst beim Skriptende. Jeder Netzwerkzugriff in Phase B wäre dann ECHTE
 * Antwortzeit, und das Latenzbudget aus Konzept 2.1 wäre verletzt, ohne dass es jemand
 * merkt.
 *
 * Deshalb: wo die Antwort nicht abkoppelbar ist, redet Phase B überhaupt nicht mit dem
 * Broker. Der fertige Frame geht in den Spool — ein einzelner fwrite, typisch 10–100 µs,
 * kein Netzwerk, kein fsync. Der Versand übernimmt `ids:sensor:spool:flush` als eigener
 * Prozess auf demselben Host.
 *
 * WARUM `auto` DIE VORGABE IST
 *
 * Die Laufzeitumgebung ist eine Eigenschaft des SERVERS, nicht der Anwendung: dieselbe
 * Anwendung läuft beim einen Kunden unter FPM und beim anderen unter mod_php. Müsste der
 * Wert von Hand gesetzt werden, wäre die wahrscheinlichste Fehlkonfiguration genau die
 * gefährliche Richtung — `direct` auf einer mod_php-Installation, wo dann bei
 * chunked-Antworten unbemerkt Antwortzeit verbraucht wird.
 *
 * @internal
 */
final class RuntimeProfile
{
    public const POLICY_AUTO = 'auto';
    public const POLICY_DIRECT = 'direct';
    public const POLICY_SPOOL = 'spool';

    /**
     * Die Funktionen, mit denen eine Laufzeit die Antwort abkoppelt.
     *
     * FrankenPHP und RoadRunner laufen im Worker-Modus unter der CLI-SAPI und haben
     * ohnehin keine an das Skript gekoppelte Antwortzeit; FrankenPHP stellt zusätzlich
     * `fastcgi_finish_request()` bereit.
     */
    private const FINISH_FUNCTIONS = [
        'fastcgi_finish_request',
        'litespeed_finish_request',
    ];

    /**
     * SAPIs ohne wartenden Client. Dort gibt es keine Antwortzeit, auf die zu achten
     * wäre — ein Command oder ein Messenger-Worker darf blockieren.
     */
    private const OFFLINE_SAPIS = ['cli', 'phpdbg', 'embed'];

    private ?bool $detachable = null;

    public function __construct(
        private readonly string $policy = self::POLICY_AUTO,
        private readonly string $sapi = \PHP_SAPI,
        private readonly int $drainIntervalSeconds = 30,
    ) {
    }

    /**
     * Darf Phase B den Broker direkt ansprechen?
     */
    public function shipsDirectly(): bool
    {
        return match ($this->policy) {
            self::POLICY_DIRECT => true,
            self::POLICY_SPOOL => false,
            default => $this->responseIsDetachable(),
        };
    }

    /**
     * Der Wert, mit dem ein Frame dieser Laufzeit beim Collector ankommt.
     *
     * Kein Schalter, sondern ein abgeleiteter Tatsachenwert — die Anwendung kann ihn
     * nicht setzen. `Deferred` heißt „planmäßig über den Spool, Verzögerung begrenzt auf
     * ein Drain-Intervall"; der Collector darf die Echtzeit-Regeln weiter anwenden.
     * `Recovered` dagegen — gesetzt vom Drainer nach einem Broker-Ausfall — heißt
     * „unbegrenzt verzögert".
     *
     * Diese Unterscheidung ist der Grund, warum es kein binäres `late`-Flag gibt: unter
     * mod_php läuft JEDER Frame über den Spool. Ein Flag hätte eine mod_php-Installation
     * dauerhaft von der Echtzeit-Erkennung ausgeschlossen.
     */
    public function dispatchPath(): DispatchPath
    {
        return $this->shipsDirectly() ? DispatchPath::Direct : DispatchPath::Deferred;
    }

    public function responseIsDetachable(): bool
    {
        if (null !== $this->detachable) {
            return $this->detachable;
        }

        if (\in_array($this->sapi, self::OFFLINE_SAPIS, true)) {
            return $this->detachable = true;
        }

        foreach (self::FINISH_FUNCTIONS as $function) {
            if (\function_exists($function)) {
                return $this->detachable = true;
            }
        }

        return $this->detachable = false;
    }

    public function policy(): string
    {
        return $this->policy;
    }

    public function sapi(): string
    {
        return $this->sapi;
    }

    public function drainIntervalSeconds(): int
    {
        return $this->drainIntervalSeconds;
    }

    /**
     * Für Heartbeat und `ids:sensor:setup-check`.
     *
     * Der Collector braucht das, um zu wissen, welche Verzögerung pro Instanz NORMAL ist
     * — nur so fällt eine Verzögerung auf, die es nicht ist.
     *
     * @return array{policy: string, sapi: string, response_detachable: bool, dispatch_path: string, drain_interval_s: int}
     */
    public function describe(): array
    {
        return [
            'policy' => $this->policy,
            'sapi' => $this->sapi,
            'response_detachable' => $this->responseIsDetachable(),
            'dispatch_path' => $this->dispatchPath()->value,
            'drain_interval_s' => $this->drainIntervalSeconds,
        ];
    }
}
