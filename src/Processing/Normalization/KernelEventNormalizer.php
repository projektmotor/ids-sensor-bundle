<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsSensor\EventFormat\Event\NormalizedEvent;
use ProjektMotor\IdsSensor\EventFormat\Event\SensorIdentity;
use ProjektMotor\IdsSensor\EventFormat\Payload\KernelPayload;
use ProjektMotor\IdsSensor\EventFormat\Vocabulary\Layer;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;

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
                FieldValue::asString($captured->get(KernelPayload::FIELD_REFERER)),
                KernelPayload::MAX_USER_AGENT_LENGTH,
            ),
            KernelPayload::FIELD_CONTENT_LENGTH => self::intOrNull($captured->get(KernelPayload::FIELD_CONTENT_LENGTH)) ?? 0,
        ];
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
            KernelPayload::FIELD_EXCEPTION_MESSAGE => self::sanitizeMessage(
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
     * Normalisiert Steuerzeichen VOR dem Kürzen.
     *
     * Die Exception-Message ist angreiferbeeinflusst (sie enthält oft den
     * angefragten Pfad, siehe das Beispiel im Konzept: „No route found for GET
     * /wp-admin/setup-config.php"). Zeilenumbrüche und Steuerzeichen darin könnten
     * in einer späteren Auswertungsoberfläche die Darstellung zerlegen — Konzept
     * 4.5.3 fordert ausdrücklich, solche Werte als Daten und nie als Markup zu
     * behandeln.
     */
    private static function sanitizeMessage(?string $message): ?string
    {
        if (null === $message) {
            return null;
        }

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
