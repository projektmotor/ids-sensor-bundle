<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Payload\KernelPayload;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Support\PayloadConfidentialityCleanup\Cleaner;

/**
 * Normalisiert die Kernel-Ebene nach Konzept 2.2.2 und 3.1.1.
 *
 * Die Schlüssel stehen in {@see KernelPayload} und nicht hier: der Sensor legt die
 * Rohwerte unter ihnen ab, dieser Normalisierer liest sie aus, und keine der beiden
 * Seiten darf sich auf unabhängig getippte Zeichenketten verlassen. Ein gemeinsamer
 * dritter Ort ist die einzige Anordnung, bei der der Sensor den Normalisierer nicht
 * kennen muss — Phase A hängt dann nicht an Phase B.
 *
 * @internal
 */
final class KernelEventNormalizer implements EventNormalizerInterface
{
    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly SeverityResolver $severityResolver,
        private readonly QueryNormalizer $queryNormalizer,
        private readonly Cleaner $cleaner,
    ) {
    }

    public function supports(CapturedEvent $captured): bool
    {
        return Layer::Kernel === $captured->layer;
    }

    public function normalize(CapturedEvent $captured, SensorIdentity $identity): NormalizedEvent
    {
        $status = self::intOrNull($captured->get(KernelPayload::FIELD_HTTP_STATUS));

        return $this->eventFactory->create(
            $captured,
            $identity,
            $captured->eventType,
            $captured->correlationId() ?? '',
            $captured->actor(),
            $this->severityResolver->forKernel($captured->eventType, $status),
            $this->payloadFor($captured, $status),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(CapturedEvent $captured, ?int $status): array
    {
        return match ($captured->eventType) {
            KernelPayload::EVENT_REQUEST => $this->requestPayload($captured),
            KernelPayload::EVENT_EXCEPTION => $this->exceptionPayload($captured, $status),
            KernelPayload::EVENT_RESPONSE => $this->responsePayload($captured, $status),
            KernelPayload::EVENT_CONSOLE_COMMAND => $this->consoleCommandPayload($captured),
            KernelPayload::EVENT_CONSOLE_ERROR => $this->consoleErrorPayload($captured),
            default => [],
        };
    }

    /**
     * Konzept 3.1.1 — kernel.request.
     *
     * route ist null, falls zum Zeitpunkt von kernel.request noch nicht aufgelöst.
     * Das ist der Normalfall, weil der Sensor bei Priorität 1024 vor dem Router
     * läuft — sonst blieben routenlose Pfade wie /wp-admin/setup-config.php
     * unsichtbar, und genau die sind das Scanning-Signal.
     *
     * @return array<string, mixed>
     */
    private function requestPayload(CapturedEvent $captured): array
    {
        return [
            KernelPayload::FIELD_METHOD => FieldValue::asString($captured->get(KernelPayload::FIELD_METHOD)),
            KernelPayload::FIELD_PATH => FieldValue::truncate(
                FieldValue::asString($captured->get(KernelPayload::FIELD_PATH)),
                KernelPayload::MAX_PATH_LENGTH,
            ),
            KernelPayload::FIELD_QUERY => $this->queryNormalizer->normalize(
                \is_array($captured->get(KernelPayload::FIELD_QUERY)) ? $captured->get(KernelPayload::FIELD_QUERY) : [],
            ),
            KernelPayload::FIELD_ROUTE => FieldValue::asString($captured->get(KernelPayload::FIELD_ROUTE)),
            KernelPayload::FIELD_USER_AGENT => FieldValue::truncate(
                FieldValue::asString($captured->get(KernelPayload::FIELD_USER_AGENT)),
                KernelPayload::MAX_USER_AGENT_LENGTH,
            ),
            KernelPayload::FIELD_REFERER => FieldValue::truncate(
                $this->referer($captured),
                KernelPayload::MAX_USER_AGENT_LENGTH,
            ),
            KernelPayload::FIELD_CONTENT_LENGTH => self::intOrNull($captured->get(KernelPayload::FIELD_CONTENT_LENGTH)) ?? 0,
        ];
    }

    /**
     * Der Referer, redigiert statt nur gekürzt.
     *
     * Er ist der einzige Weg, auf dem eine FREMDE vollständige URL samt Query in ein
     * Event gelangt, und er lief bisher an der Redaktion vorbei — bei einem Feld, das
     * laut Konzept 3.1.1 bei JEDER Stufe mitreist, also auch bei `info`. Wer
     * `https://app.example/reset?token=…` öffnet und dort einen Link anklickt, schickt
     * das Token im Referer mit.
     *
     * null bleibt null: kein Referer ist etwas anderes als ein leerer.
     */
    private function referer(CapturedEvent $captured): ?string
    {
        $referer = FieldValue::asString($captured->get(KernelPayload::FIELD_REFERER));

        return null === $referer ? null : $this->queryNormalizer->normalizeUrl($referer);
    }

    /**
     * Konzept 3.1.1 — kernel.exception. path und content_length werden aus dem
     * zugehörigen Request redundant übernommen (Konzept 3.2), damit die Batch-Regeln
     * Statuscodes und Pfade gemeinsam aggregieren können, ohne einen Self-Join über
     * die correlation_id zu brauchen.
     *
     * @return array<string, mixed>
     */
    private function exceptionPayload(CapturedEvent $captured, ?int $status): array
    {
        return [
            KernelPayload::FIELD_EXCEPTION_CLASS => FieldValue::asString($captured->get(KernelPayload::FIELD_EXCEPTION_CLASS)),
            KernelPayload::FIELD_EXCEPTION_MESSAGE => $this->sanitizeMessage(
                FieldValue::asString($captured->get(KernelPayload::FIELD_EXCEPTION_MESSAGE)),
            ),
            KernelPayload::FIELD_HTTP_STATUS => $status,
            KernelPayload::FIELD_PATH => FieldValue::truncate(
                FieldValue::asString($captured->get(KernelPayload::FIELD_PATH)),
                KernelPayload::MAX_PATH_LENGTH,
            ),
            KernelPayload::FIELD_CONTENT_LENGTH => self::intOrNull($captured->get(KernelPayload::FIELD_CONTENT_LENGTH)) ?? 0,
        ];
    }

    /**
     * Konzept 3.1.1 — kernel.response. path und route ebenfalls redundant aus dem
     * Request (Konzept 3.2).
     *
     * response_size_bytes darf null sein: bei einer StreamedResponse oder
     * BinaryFileResponse ist die Größe nicht ermittelbar, ohne den Inhalt zu
     * erzeugen. null ist dort die ehrlichere Auskunft als 0.
     *
     * @return array<string, mixed>
     */
    private function responsePayload(CapturedEvent $captured, ?int $status): array
    {
        return [
            KernelPayload::FIELD_HTTP_STATUS => $status,
            KernelPayload::FIELD_RESPONSE_TIME_MS => self::intOrNull($captured->get(KernelPayload::FIELD_RESPONSE_TIME_MS)),
            KernelPayload::FIELD_RESPONSE_SIZE_BYTES => self::intOrNull($captured->get(KernelPayload::FIELD_RESPONSE_SIZE_BYTES)),
            KernelPayload::FIELD_PATH => FieldValue::truncate(
                FieldValue::asString($captured->get(KernelPayload::FIELD_PATH)),
                KernelPayload::MAX_PATH_LENGTH,
            ),
            KernelPayload::FIELD_ROUTE => FieldValue::asString($captured->get(KernelPayload::FIELD_ROUTE)),
        ];
    }

    /**
     * Konzept 3.1.1 — console.command.
     *
     * Nur der Befehlsname. Die Aufrufargumente bleiben ausdrücklich draußen: eine
     * Befehlszeile trägt regelmäßig genau die Werte, die Konzept 4.5.1 unkenntlich
     * machen soll — `--password=`, ein Token als Stellungsargument, eine
     * Verbindungszeichenkette.
     *
     * @return array<string, mixed>
     */
    private function consoleCommandPayload(CapturedEvent $captured): array
    {
        return [
            KernelPayload::FIELD_COMMAND => $this->command($captured),
        ];
    }

    /**
     * Konzept 3.1.1 — console.error.
     *
     * Die Exception-Message läuft durch dieselbe Redaktion wie beim kernel.exception:
     * Sie ist angreiferbeeinflusst, und ein Befehl, der eine URL mit Token entgegennimmt,
     * schreibt sie bei einem Fehlschlag genau dorthin.
     *
     * @return array<string, mixed>
     */
    private function consoleErrorPayload(CapturedEvent $captured): array
    {
        return [
            KernelPayload::FIELD_COMMAND => $this->command($captured),
            KernelPayload::FIELD_EXCEPTION_CLASS => FieldValue::asString($captured->get(KernelPayload::FIELD_EXCEPTION_CLASS)),
            KernelPayload::FIELD_EXCEPTION_MESSAGE => $this->sanitizeMessage(
                FieldValue::asString($captured->get(KernelPayload::FIELD_EXCEPTION_MESSAGE)),
            ),
            KernelPayload::FIELD_EXIT_CODE => self::intOrNull($captured->get(KernelPayload::FIELD_EXIT_CODE)),
        ];
    }

    /**
     * Der Befehlsname, redigiert und gekürzt.
     *
     * Redigiert, weil er bei einem unbekannten Befehl die Eingabe des Aufrufers IST —
     * `bin/console 'app:x --token=geheim'` landete sonst wortwörtlich im Ereignis.
     */
    private function command(CapturedEvent $captured): ?string
    {
        $command = FieldValue::asString($captured->get(KernelPayload::FIELD_COMMAND));

        if (null === $command) {
            return null;
        }

        return FieldValue::truncate(
            $this->cleaner->cleanFreeText($command),
            KernelPayload::MAX_COMMAND_LENGTH,
        );
    }

    /**
     * Redigiert, normalisiert Steuerzeichen und kürzt — in dieser Reihenfolge.
     *
     * Die Exception-Message ist angreiferbeeinflusst (sie enthält oft den
     * angefragten Pfad, siehe das Beispiel im Konzept: „No route found for GET
     * /wp-admin/setup-config.php"). Daraus folgen zwei Dinge:
     *
     * 1. Zeilenumbrüche und Steuerzeichen darin könnten in einer späteren
     *    Auswertungsoberfläche die Darstellung zerlegen — Konzept 4.5.3 fordert
     *    ausdrücklich, solche Werte als Daten und nie als Markup zu behandeln.
     * 2. Derselbe Pfad kann eine Query tragen, und `?token=…` in einer
     *    NotFoundHttpException ist ein Geheimnis in einem Feld, das bei JEDER Stufe
     *    mitreist — anders als das raw-Feld, das es nur bei warning/critical gibt.
     *    Die Denylist aus Konzept 4.5.1 sah dieses Feld bis dahin nie.
     *
     * Redigiert wird VOR dem Kürzen: sonst könnte ein Wert genau am Schnitt
     * abgeschnitten und damit unkenntlich für die Denylist werden, aber noch lesbar
     * genug für einen Angreifer.
     */
    private function sanitizeMessage(?string $message): ?string
    {
        if (null === $message) {
            return null;
        }

        $message = $this->cleaner->cleanFreeText($message);

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
        if (null === $normalized) {
            // preg_replace kann bei ungültigem UTF-8 null liefern — der Fall tritt
            // bei Scanner-Verkehr tatsächlich auf.
            $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        }

        return FieldValue::truncate(trim($normalized), KernelPayload::MAX_EXCEPTION_MESSAGE_LENGTH);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
