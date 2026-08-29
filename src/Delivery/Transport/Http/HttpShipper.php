<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Http;

use ProjektMotor\IdsEventData\Event\SensorIdentity;
use ProjektMotor\IdsSensor\Delivery\Transport\Shipper\ShipperInterface;
use ProjektMotor\IdsSensor\Exception\ThrottledException;
use ProjektMotor\IdsSensor\Exception\UnshippableFrameException;
use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sendet Frames und Heartbeats per HTTPS an den Collector (Konzept 3.6).
 *
 * Zwei Routen, weil die Nachrichtenart in den Endpunkt gehört und nicht in einen
 * Header: Damit unterscheidet der Collector Frame und Heartbeat, ohne den Körper zu
 * parsen — die Zusage aus Konzept 3.4, auf dem natürlicheren Weg eingelöst. Eigene
 * `X-Ids-*`-Header gibt es deshalb nicht.
 *
 * Die drei Kennungen stehen im Pfad, damit der Collector weiterleiten, protokollieren
 * und Raten begrenzen kann, bevor er Kryptografie oder Rumpf anfasst. Maßgeblich ist
 * aber der Rumpf; der Pfad wird dagegen geprüft und eine Abweichung mit 422
 * abgewiesen.
 *
 * @internal
 */
final class HttpShipper implements ShipperInterface
{
    /**
     * Antwortcodes, bei denen ein zweiter Versuch nichts ändert (Konzept 3.6).
     *
     * 400/413/422: die Sendung ist aus sich heraus nicht annehmbar.
     * 403: der angemeldete Nutzer ist nicht Eigentümer der Kette im Pfad, oder die
     * Zugangsdaten sind gesperrt — ein Konfigurationsfehler heilt nicht durch Warten,
     * und Spoolen füllte den Puffer mit Sendungen, die nie angenommen werden.
     */
    private const PERMANENT = [400, 403, 413, 422];

    /**
     * Obergrenze für `Retry-After`, in Sekunden.
     *
     * Der Wert kommt von der Gegenseite und ist damit nach Konzept 4.5.3 nicht
     * vertrauenswürdig. Ohne Kappung legte ein `Retry-After: 86400` den Sensor einen Tag
     * still, während der Spool die ganze Zeit allein trüge.
     */
    private const MAX_RETRY_AFTER_SECONDS = 900.0;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly SensorIdentityProvider $identityProvider,
        private readonly TokenProvider $tokens,
        private readonly string $baseUri,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function ship(array $frame): void
    {
        if (!\is_array($frame['events'] ?? null)) {
            throw new UnshippableFrameException('Frame ohne events-Array — vermutlich eine abgeschnittene Spool-Zeile.');
        }

        if ([] === $frame['events']) {
            return;
        }

        // Die Datenroute nimmt eine LISTE von Frames: im Direktversand eine mit einem
        // Element, im gebündelten Modus mehrere. Eine Form je Endpunkt.
        $this->post('sensor-data', [$frame]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shipHeartbeat(array $payload): void
    {
        // Die Heartbeat-Route nimmt ein Objekt. Heartbeats werden nie gebündelt
        // (Konzept 3.4), eine Liste wäre hier ein Sonderfall ohne Zweck.
        $this->post('sensor-heartbeat', $payload);
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $body
     *
     * @throws UnshippableFrameException bei einer dauerhaften Ablehnung
     * @throws \Throwable                bei jeder Störung, die ein erneuter Versuch beheben kann
     */
    private function post(string $route, array $body): void
    {
        $antwort = $this->request($route, $body);
        $status = $antwort->getStatusCode();

        if (202 === $status || ($status >= 200 && $status < 300)) {
            return;
        }

        if (\in_array($status, self::PERMANENT, true)) {
            throw new UnshippableFrameException(\sprintf('Der Collector hat die Sendung mit %d abgelehnt.', $status));
        }

        if (429 === $status) {
            $wartezeit = $this->retryAfter($antwort);

            throw new ThrottledException(\sprintf('Der Collector hat die Ratengrenze gemeldet (429)%s.', null === $wartezeit ? '' : \sprintf(' und %d s Wartezeit verlangt', (int) $wartezeit)), $wartezeit);
        }

        // 5xx und alles Übrige: erneut versuchen. Das Spoolen und das Zählen übernimmt
        // der FrameDispatcher, der dieses Throwable fängt.
        throw new \RuntimeException(\sprintf('Der Collector antwortete mit %d.', $status));
    }

    /**
     * Setzt die Anfrage ab und meldet sich einmal neu an, wenn das Token abgelaufen war.
     *
     * @param list<array<string, mixed>>|array<string, mixed> $body
     */
    private function request(string $route, array $body): ResponseInterface
    {
        $url = \sprintf(
            '%s/api/v1/%s/%s/%s/%s',
            rtrim($this->baseUri, '/'),
            $route,
            rawurlencode($this->identity()->applicationId),
            rawurlencode($this->identity()->environmentId),
            rawurlencode($this->identity()->sensorId),
        );

        $antwort = $this->send($url, $body, $this->tokens->get());

        // Genau ein Neuanmelde- und ein Wiederholungsversuch (Konzept 3.6). Ein
        // zweites 401 ist ein Fehlschlag wie jeder andere.
        if (401 === $antwort->getStatusCode()) {
            $antwort = $this->send($url, $body, $this->tokens->renew());
        }

        return $antwort;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $body
     */
    private function send(string $url, array $body, string $token): ResponseInterface
    {
        return $this->client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);
    }

    /**
     * Die Wartezeit aus `Retry-After`, in Sekunden.
     *
     * Konzept 3.6 verlangt bei `429`, den Header zu beachten. RFC 9110 lässt zwei
     * Schreibweisen zu, und beide kommen vor: eine Anzahl Sekunden, oder ein
     * HTTP-Zeitpunkt. Ein fehlender oder unbrauchbarer Header ist kein Fehler — dann gilt
     * die konfigurierte Offen-Zeit des Breakers.
     *
     * Gekappt wird nach oben, und das ist Absicht: Ein Collector, der eine Stunde
     * verlangt, legte den Sensor für eine Stunde still, und der Spool trüge die ganze Zeit
     * allein (Konzept 2.1, „der lokale Spool ist die einzige Reserve"). Eine Viertelstunde
     * ist mehr als jede Ratengrenze braucht und weniger, als der Spool überlebt.
     */
    private function retryAfter(ResponseInterface $response): ?float
    {
        try {
            $header = $response->getHeaders(false)['retry-after'][0] ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (null === $header) {
            return null;
        }

        if (1 === preg_match('/^\s*\d+\s*$/', $header)) {
            return min((float) trim($header), self::MAX_RETRY_AFTER_SECONDS);
        }

        $zeitpunkt = strtotime($header);

        if (false === $zeitpunkt) {
            return null;
        }

        return min(max(0.0, $zeitpunkt - time()), self::MAX_RETRY_AFTER_SECONDS);
    }

    private function identity(): SensorIdentity
    {
        return $this->identityProvider->get();
    }
}
