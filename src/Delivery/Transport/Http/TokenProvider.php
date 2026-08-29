<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Besorgt das Zugangstoken des Collectors und hält es vor (Konzept 3.6).
 *
 * Die Anmelderoute trägt keine Kennung: Sie leistet nur eines, authentifizieren.
 * Das Kennungstripel steht in der Konfiguration des Sensors und wandert von dort in
 * den Pfad der Datenroute — der Collector leitet nichts ab, er prüft.
 *
 * Erneuert wird VORAUSSCHAUEND, mit einem Vorlauf vor dem Ablauf. Erst auf ein 401 zu
 * erneuern wäre ein zweiter Netzwerk-Roundtrip innerhalb des Versandbudgets aus
 * Konzept 4 — genau das, was das Budget verhindern soll.
 *
 * @internal
 */
final class TokenProvider
{
    private const ROUTE = '/api/v1/token';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly TokenStore $store,
        private readonly string $baseUri,
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly int $leewaySeconds = 60,
    ) {
    }

    /**
     * Das gültige Token — aus dem Cache, sonst frisch geholt.
     *
     * @throws \Throwable wenn die Anmeldung scheitert. Der Wurf ist Absicht: Ein
     *                    Collector, der keine Token ausgibt, ist ein nicht erreichbarer
     *                    Collector, und der Fehlschlag gehört in Breaker und Spool.
     */
    public function get(): string
    {
        return $this->store->read($this->leewaySeconds) ?? $this->fetch();
    }

    /**
     * Verwirft das gespeicherte Token und holt ein neues.
     *
     * Nach einem 401: Der Collector hat es abgelehnt, also ist es ungültig — was auch
     * immer sein Ablaufzeitpunkt behauptet.
     */
    public function renew(): string
    {
        $this->store->forget();

        return $this->fetch();
    }

    private function fetch(): string
    {
        $response = $this->client->request('POST', rtrim($this->baseUri, '/').self::ROUTE, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['username' => $this->username, 'password' => $this->password],
        ]);

        $status = $response->getStatusCode();

        if (200 !== $status) {
            throw new \RuntimeException(\sprintf('Anmeldung am Collector scheiterte mit %d.', $status));
        }

        $data = $response->toArray(false);

        if (!\is_string($data['token'] ?? null)) {
            throw new \RuntimeException('Die Antwort der Anmeldung enthält kein Token.');
        }

        $this->store->write($data['token'], $this->expiryFrom($data));

        return $data['token'];
    }

    /**
     * Der Ablaufzeitpunkt aus der Antwort.
     *
     * Ist er unbrauchbar, gilt eine knappe Frist statt gar keiner: Ein Token ohne
     * Ablauf endlos zu behalten hieße, auf 401 zu warten — und genau das soll der
     * Vorlauf vermeiden.
     *
     * @param array<string, mixed> $data
     */
    private function expiryFrom(array $data): int
    {
        $expiresAt = $data['expires_at'] ?? null;

        if (\is_string($expiresAt)) {
            $zeit = strtotime($expiresAt);

            if (false !== $zeit) {
                return $zeit;
            }
        }

        if (\is_int($expiresAt)) {
            return $expiresAt;
        }

        return time() + 300;
    }
}
