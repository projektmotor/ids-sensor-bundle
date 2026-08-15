<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use ProjektMotor\IdsEventData\Event\NormalizedEvent;
use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsEventData\Vocabulary\Layer;
use ProjektMotor\IdsEventData\Vocabulary\Severity;
use ProjektMotor\IdsSensor\Sensor\Business\EventSensor;
use ProjektMotor\IdsSensor\Sensor\CapturedEvent;
use ProjektMotor\IdsSensor\Support\RawPayload\Builder;

/**
 * Normalisiert die Business-Ebene nach Konzept 2.2.4 und 3.1.3.
 *
 * Anders als bei Kernel und Security gibt es hier KEINE feste Payload-Struktur: der
 * Inhalt ist projektdefiniert und wird 1:1 durchgereicht. Der Normalisierer bereinigt
 * ihn nur so weit, wie das Schema es verlangt (Tiefe, Größe, kodierbare Typen).
 *
 * Zwei Feinheiten aus dem Konzept, die leicht falsch gemacht werden:
 *
 *  1. Das Zielfeld heißt `event_severity`, nicht `severity`. Die Mapping-Tabelle in
 *     Konzept 2.2.4 nennt `severity`, das verbindliche Schema in Abschnitt 3 dagegen
 *     `event_severity`. Das Schema gewinnt — es ist als verbindlich deklariert.
 *  2. Der Hint wird DIREKT übernommen, ohne eigene Ableitung: die Business-Ebene
 *     bewertet ihre Kritikalität selbst, und dem wird vertraut.
 *
 * @internal
 */
final class BusinessEventNormalizer implements EventNormalizerInterface
{
    public const MAX_EVENT_NAME_LENGTH = 64;

    /**
     * Punktgetrennte snake_case-Segmente, wie in Konzept 2.1.3 vorgeschlagen
     * („order.payment_amount_overridden").
     */
    private const EVENT_NAME_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*(?:\.[a-z0-9]+(?:_[a-z0-9]+)*)+$/';

    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly SeverityResolver $severityResolver,
        private readonly PayloadSanitizer $sanitizer,
        private readonly ?Builder $rawBuilder = null,
    ) {
    }

    public function supports(CapturedEvent $captured): bool
    {
        return Layer::Business === $captured->layer;
    }

    public function normalize(CapturedEvent $captured, SensorIdentity $identity): NormalizedEvent
    {
        $rawName = (string) $captured->get(EventSensor::FIELD_EVENT_NAME, '');
        $hint = (string) $captured->get(EventSensor::FIELD_SEVERITY_HINT, '');
        $eventType = self::normalizeEventName($rawName);

        $severity = $this->severityResolver->forBusiness($hint, $eventType);
        $payload = $this->sanitizer->sanitize(
            \is_array($captured->get(EventSensor::FIELD_PAYLOAD)) ? $captured->get(EventSensor::FIELD_PAYLOAD) : [],
        );

        // Vermerke des Sensors: sie stehen im Payload, damit eine Fehlkonfiguration in
        // den DATEN sichtbar bleibt und nicht nur im Log — dort geht sie unter.
        $payload = array_merge($payload, self::markers($rawName, $eventType, $hint, $severity));

        // Ein Getter, der wirft, ist ein Defekt in der überwachten Anwendung — und ohne
        // diesen Vermerk von einem leeren Rückgabewert nicht zu unterscheiden.
        $unreadable = $captured->get(EventSensor::FIELD_UNREADABLE);

        if (\is_array($unreadable) && [] !== $unreadable) {
            $payload[PayloadSanitizer::RESERVED_PREFIX.'unreadable'] = array_values(array_map('strval', $unreadable));
        }

        // raw trägt hier den UNBEREINIGTEN Payload, redigiert: der Sanitizer kürzt,
        // flacht ab und verwirft ab 100 Elementen. Für die Nachanalyse eines
        // erfolgreichen Angriffs (Konzept 2.1.3 — die einzige Signalklasse dafür) ist
        // genau das Verworfene oft das Interessante.
        if (null !== $this->rawBuilder) {
            $original = $captured->get(EventSensor::FIELD_PAYLOAD);
            $captured->setRawBuilder($this->rawBuilder->forBusiness(
                \is_array($original) ? $original : [],
                $hint !== $severity->value ? $hint : null,
            ));
        }

        return $this->eventFactory->create(
            $captured,
            $identity,
            $eventType,
            $captured->correlationId() ?? '',
            $captured->actor(),
            $severity,
            $payload,
        );
    }

    /**
     * Ein abweichender Name führt NICHT zum Verwerfen des Events.
     *
     * Business-Events sind laut Konzept 2.1.3 die einzige Signalklasse für erfolgreiche
     * Angriffe. Ein Event wegen eines Namensverstoßes fallenzulassen wäre der
     * schlechteste mögliche Umgang damit — es wird bereinigt übertragen, der
     * Originalname bleibt im Payload erhalten.
     */
    public static function normalizeEventName(string $name): string
    {
        $trimmed = mb_substr(trim($name), 0, self::MAX_EVENT_NAME_LENGTH);

        if ('' === $trimmed) {
            return 'business.unnamed';
        }

        if (1 === preg_match(self::EVENT_NAME_PATTERN, $trimmed)) {
            return $trimmed;
        }

        $sanitized = strtolower((string) preg_replace('/[^A-Za-z0-9._]/', '_', $trimmed));
        $sanitized = trim((string) preg_replace('/_{2,}/', '_', $sanitized), '._');

        return '' === $sanitized ? 'business.unnamed' : $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function markers(
        string $rawName,
        string $eventType,
        string $hint,
        Severity $severity,
    ): array {
        $markers = [];

        if ($rawName !== $eventType) {
            $markers[PayloadSanitizer::RESERVED_PREFIX.'event_name_raw'] = mb_substr($rawName, 0, 128);
        }

        // Der Originalwert eines unbrauchbaren Hints bleibt erhalten. Ohne ihn wäre
        // später nicht mehr feststellbar, ob "warning" die Einschätzung der Anwendung
        // war oder unsere Ersatzeinstufung.
        if ($hint !== $severity->value) {
            $markers[PayloadSanitizer::RESERVED_PREFIX.'severity_hint_raw'] = mb_substr($hint, 0, 64);
        }

        return $markers;
    }
}
