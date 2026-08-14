<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Erzeugt die correlation_id.
 *
 * Konzept Abschnitt 3 führt correlation_id als Pflichtfeld, Konzept 6.3 (offener
 * Punkt B6) lässt aber offen, wer sie erzeugt und ob eine vorhandene Request-ID eines
 * Reverse-Proxy übernommen wird. Diese Umsetzung entscheidet: selbst erzeugen,
 * Übernahme nur ausdrücklich eingeschaltet.
 *
 * Begründung für den restriktiven Standard — der Sensor sitzt im Request-Pfad, ein
 * eingehender Header ist also angreifergesteuert, solange kein Reverse-Proxy ihn
 * überschreibt. Wird er blind übernommen, kann ein Angreifer:
 *
 *  - für tausende Anfragen denselben Wert setzen und damit seinen gesamten Verkehr zu
 *    einer einzigen „Spur" verschmelzen, was die 1:n-Annahme zwischen Request und
 *    Folge-Events bricht, auf der die Self-Joins des Collectors beruhen;
 *  - die correlation_id eines Opfers wiederverwenden und seine eigenen Events an
 *    dessen Spur anhängen. Da die correlation_id genau der Schlüssel ist, über den
 *    Request-Kontext rekonstruiert wird (Konzept 3.2), ist das ein Angriff auf die
 *    Beweisintegrität.
 *
 * Deshalb zusätzlich zur ausdrücklichen Einschaltung: Trusted-Proxy-Prüfung und ein
 * strenger Formatfilter.
 *
 * @internal
 */
final class CorrelationIdFactory
{
    private const HEADER_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function __construct(
        private readonly ?string $inboundHeader = null,
        private readonly bool $trustIncomingHeader = false,
        private readonly bool $requireTrustedProxy = true,
    ) {
    }

    public function forRequest(Request $request): string
    {
        return $this->adoptFromHeader($request) ?? $this->generate();
    }

    /**
     * Für Kontexte ohne HTTP-Request: eine ID pro Console-Aufruf beziehungsweise pro
     * verarbeiteter Worker-Nachricht.
     */
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    private function adoptFromHeader(Request $request): ?string
    {
        if (!$this->trustIncomingHeader || null === $this->inboundHeader || '' === $this->inboundHeader) {
            return null;
        }

        // Ohne konfigurierte trusted_proxies hat niemand bestätigt, dass ein Proxy
        // den Header überschreibt — dann ist er reine Client-Eingabe.
        if ($this->requireTrustedProxy && !$request->isFromTrustedProxy()) {
            return null;
        }

        $value = $request->headers->get($this->inboundHeader);

        if (!\is_string($value) || 1 !== preg_match(self::HEADER_PATTERN, $value)) {
            return null;
        }

        return $value;
    }
}
