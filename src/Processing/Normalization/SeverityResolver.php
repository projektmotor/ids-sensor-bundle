<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Payload\SecurityPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Severity;
use Psr\Log\LoggerInterface;

/**
 * Setzt die Ableitungstabelle aus Konzept 2.2.1 — Konkrete Ableitungsregeln für
 * event_severity — um.
 *
 * event_severity ist eine KONTEXTFREIE Vorbewertung des Einzelevents: sie kennt
 * weder Häufungen noch Vorgeschichte. Muster über mehrere Events hinweg (etwa
 * wiederholte Login-Fehlversuche) sind Aufgabe der Erkennungsregeln des Collectors,
 * nicht dieses Normalisierers. Der Collector vergibt davon unabhängig eine eigene
 * alert_severity und kann zu einer völlig anderen Einstufung kommen — eine
 * kernel.response mit Status 200 ist hier immer info, kann collectorseitig aber
 * critical auslösen, wenn sie den Pfad /_profiler betrifft.
 *
 * @internal
 */
final class SeverityResolver
{
    /**
     * Die 4xx-Codes, die laut Konzept 2.2.1 als warning gelten. Alle übrigen 4xx
     * bleiben info.
     *
     * @var list<int>
     */
    public const RESPONSE_WARNING_STATUSES = [401, 403, 404, 429];

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Kernel-Events: kernel.request ist immer info, exception und response hängen
     * am Statuscode.
     */
    public function forKernel(string $eventType, ?int $httpStatus): Severity
    {
        return match ($eventType) {
            KernelPayload::EVENT_REQUEST => Severity::Info,
            KernelPayload::EVENT_EXCEPTION => self::forExceptionStatus($httpStatus),
            KernelPayload::EVENT_RESPONSE => self::forResponseStatus($httpStatus),
            default => Severity::Info,
        };
    }

    /**
     * Konzept 2.2.1: 5xx critical, 4xx warning, alles andere info.
     *
     * Anders als bei kernel.response gibt es hier keine Sonderbehandlung einzelner
     * 4xx-Codes — eine Exception ist bereits ein Fehlerzustand, während eine
     * Response mit 4xx auch ein regulärer Vorgang sein kann.
     */
    private static function forExceptionStatus(?int $status): Severity
    {
        if (null === $status) {
            return Severity::Info;
        }

        if ($status >= 500 && $status <= 599) {
            return Severity::Critical;
        }

        if ($status >= 400 && $status <= 499) {
            return Severity::Warning;
        }

        return Severity::Info;
    }

    /**
     * Konzept 2.2.1: 5xx critical, {401,403,404,429} warning, übrige 4xx info,
     * 2xx/3xx info.
     *
     * Die Reihenfolge ist wesentlich: die Prüfung auf die vier genannten Codes muss
     * VOR dem allgemeinen 4xx-Zweig stehen, sonst würden 403 und 404 als info
     * eingestuft und die Scanning-Erkennung verlöre ihr wichtigstes Signal.
     */
    private static function forResponseStatus(?int $status): Severity
    {
        if (null === $status) {
            return Severity::Info;
        }

        if ($status >= 500 && $status <= 599) {
            return Severity::Critical;
        }

        if (\in_array($status, self::RESPONSE_WARNING_STATUSES, true)) {
            return Severity::Warning;
        }

        return Severity::Info;
    }

    /**
     * Security-Events: Anmeldeerfolg info, Anmeldefehler warning,
     * Autorisierungsentscheidung je nach Ausgang.
     */
    public function forSecurity(string $eventType, ?string $decision = null): Severity
    {
        return match ($eventType) {
            SecurityPayload::EVENT_AUTH_SUCCESS => Severity::Info,
            SecurityPayload::EVENT_AUTH_FAILURE => Severity::Warning,
            SecurityPayload::EVENT_ACCESS_DECISION => SecurityPayload::DECISION_DENIED === $decision ? Severity::Warning : Severity::Info,
            default => Severity::Info,
        };
    }

    /**
     * Business-Events: der Hinweis der Anwendung wird direkt übernommen (Konzept
     * 2.2.1: „direkt aus getSeverityHint() übernommen, keine eigene Ableitung").
     *
     * Ein unbrauchbarer Wert führt NICHT zu einer Exception — das verstieße gegen
     * fail-open und wäre durch einen Tippfehler der Anwendung auslösbar. Stattdessen
     * wird auf warning eingestuft:
     *
     *  - nicht info, weil ein Tippfehler der Anwendung das Event sonst still in die
     *    30-Tage-Retention verschöbe (Konzept 4.2.3) und damit die Aufbewahrung
     *    heimlich verkürzte;
     *  - nicht verwerfen, weil Business-Events die einzige Signalklasse für
     *    ERFOLGREICHE Angriffe sind (Konzept 2.1.3, Hinweis zur Tragweite).
     *
     * Der Originalwert wird im raw-Feld hinterlegt, damit die Fehlkonfiguration in
     * den Daten sichtbar bleibt und nicht nur im Log.
     */
    public function forBusiness(string $hint, ?string $eventType = null): Severity
    {
        $severity = Severity::tryFrom($hint);

        if (null !== $severity) {
            return $severity;
        }

        $this->logger?->warning(
            'ids_sensor: Business-Event "{event_type}" liefert den unbekannten Severity-Hint '
            .'"{hint}". Erlaubt sind info, warning, critical. Das Event wird als warning '
            .'eingestuft; der Originalwert steht im raw-Feld.',
            ['event_type' => $eventType ?? 'unbekannt', 'hint' => mb_substr($hint, 0, 64)],
        );

        return Severity::Warning;
    }
}
