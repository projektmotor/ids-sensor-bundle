<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Fixtures;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Ein HTTP-Client, der die Sendungen mitschreibt statt sie zu verschicken.
 *
 * Bewusst der Client und nicht der Shipper: Damit läuft der echte Pfad durch
 * {@see \ProjektMotor\IdsSensor\Delivery\Transport\Http\HttpShipper} und
 * {@see \ProjektMotor\IdsSensor\Delivery\Transport\Http\TokenProvider} — samt
 * Routenbildung, Anmeldung und Auswertung der Antwortcodes. Ein aufzeichnender
 * Shipper prüfte davon nichts.
 *
 * @internal
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    public array $requests = [];

    /**
     * Die Antworten kommen über MockHttpClient und nicht als frisch erzeugte
     * MockResponse: Letztere wirft beim Auslesen „MockResponse instances must be issued
     * by MockHttpClient before processing" — sie braucht den Client, der sie ausgestellt
     * hat.
     */
    private readonly MockHttpClient $inner;

    public function __construct()
    {
        $this->inner = new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                // .invalid ist die reservierte TLD für „diesen Namen gibt es nicht"
                // (RFC 2606). Tests, die einen nicht erreichbaren Collector brauchen,
                // sagen das damit im Konfigurationswert statt über einen Schalter.
                if (str_contains(parse_url($url, \PHP_URL_HOST) ?: '', '.invalid')) {
                    return new MockResponse([], ['error' => 'Name or service not known']);
                }

                return new MockResponse(
                    $this->antwortRumpf($url),
                    ['http_code' => $this->naechsterStatus($url)],
                );
            },
        );
    }

    /**
     * Antwortcodes je Route, der Reihe nach. Fehlt einer, gilt 202 — beziehungsweise
     * 200 für die Anmeldung.
     *
     * @var array<string, list<int>>
     */
    private array $statusQueue = [];

    /**
     * @param list<int> $codes
     */
    public function queueStatus(string $routeFragment, array $codes): void
    {
        $this->statusQueue[$routeFragment] = $codes;
    }

    /**
     * Die Rümpfe der Sendungen an eine Route, bereits dekodiert.
     *
     * @return list<mixed>
     */
    public function bodies(string $routeFragment): array
    {
        $treffer = [];

        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $routeFragment)) {
                $treffer[] = $request['body'];
            }
        }

        return $treffer;
    }

    /**
     * Alle Frames, die auf der Datenroute gelandet sind — die Listen ausgepackt.
     *
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        $frames = [];

        foreach ($this->bodies('/sensor-data/') as $body) {
            if (\is_array($body)) {
                foreach ($body as $frame) {
                    if (\is_array($frame)) {
                        $frames[] = $frame;
                    }
                }
            }
        }

        return $frames;
    }

    /**
     * Alle Heartbeats. Die Route nimmt ein Objekt, keine Liste.
     *
     * @return list<array<string, mixed>>
     */
    public function heartbeats(): array
    {
        $treffer = [];

        foreach ($this->bodies('/sensor-heartbeat/') as $body) {
            if (\is_array($body)) {
                $treffer[] = $body;
            }
        }

        return $treffer;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Wie der echte Client: eine Adresse ohne Schema und Host ist keine. Ohne diese
        // Prüfung nähme das Test-Double eine Fehlkonfiguration widerspruchslos an, die in
        // Produktion eine InvalidArgumentException auslöst.
        if (!preg_match('#^https?://[^/]+#', $url)) {
            throw new \InvalidArgumentException(\sprintf('Unbrauchbare Adresse "%s".', $url));
        }

        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'body' => $options['json'] ?? null,
        ];

        return $this->inner->request($method, $url, $options);
    }

    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('Der Sensor liest keine Ströme.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }

    private function naechsterStatus(string $url): int
    {
        foreach ($this->statusQueue as $fragment => $codes) {
            if (!str_contains($url, $fragment) || [] === $codes) {
                continue;
            }

            return (int) array_shift($this->statusQueue[$fragment]);
        }

        return str_contains($url, '/token') ? 200 : 202;
    }

    private function antwortRumpf(string $url): string
    {
        if (!str_contains($url, '/token')) {
            return '';
        }

        return json_encode([
            'token' => 'test-token',
            'expires_at' => time() + 3600,
        ], \JSON_THROW_ON_ERROR);
    }
}
