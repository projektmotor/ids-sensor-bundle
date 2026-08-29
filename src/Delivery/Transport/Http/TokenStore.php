<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Transport\Http;

/**
 * Hält das Zugangstoken prozessübergreifend: APCu, sonst Datei.
 *
 * Dasselbe Muster wie {@see \ProjektMotor\IdsSensor\Delivery\Transport\Breaker\SharedStateStore}
 * und aus demselben Grund: Ohne geteilte Ablage holte sich jedes PHP-FPM-Kind sein
 * eigenes Token, und aus einer Anmeldung je Stunde würden Tausende. Das Token gilt
 * laut Konzept 3.6 rund eine Stunde — bei einem Pool aus 32 Kindern und stündlicher
 * Erneuerung ist der Unterschied 32 Anmeldungen je Stunde gegen eine.
 *
 * WICHTIG: node-lokal ablegen, wie Spool und Breaker-Zustand. Ein geteiltes Volume
 * verteilte das Token über Hosts hinweg — funktional unauffällig, aber es
 * vervielfacht die Zahl der Orte, an denen es liegt.
 *
 * @internal
 */
final class TokenStore
{
    private const APCU_KEY_PREFIX = 'ids_sensor.token.';

    /**
     * Der APCu-Eintrag verfällt mit dem Token selbst, nicht früher und nicht später.
     * Ein pauschaler Wert wäre entweder zu kurz (unnötige Anmeldungen) oder zu lang
     * (ein abgelaufenes Token bliebe im Cache und erzeugte je Sendung ein 401).
     */
    private readonly bool $useApcu;

    private readonly string $apcuKey;

    private readonly string $file;

    public function __construct(string $directory, string $scopeKey)
    {
        $this->apcuKey = self::APCU_KEY_PREFIX.$scopeKey;
        $this->file = rtrim($directory, '/').'/collector.token';
        $this->useApcu = \function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Das gültige Token, oder null.
     *
     * `$leewaySeconds` ist der Vorlauf aus Konzept 3.6: Ein Token, das in weniger als
     * dieser Spanne abläuft, gilt hier bereits als abgelaufen. Damit wird
     * vorausschauend erneuert statt erst auf ein 401 — eine Erneuerung im Fehlerfall
     * wäre ein zweiter Roundtrip innerhalb des Versandbudgets.
     */
    public function read(int $leewaySeconds, ?int $now = null): ?string
    {
        $now ??= time();
        $state = $this->load();

        if (null === $state) {
            return null;
        }

        if ($state['expires_at'] - $leewaySeconds <= $now) {
            return null;
        }

        return $state['token'];
    }

    public function write(string $token, int $expiresAt): void
    {
        $payload = json_encode(
            ['token' => $token, 'expires_at' => $expiresAt],
            \JSON_UNESCAPED_SLASHES,
        );

        if (false === $payload) {
            return;
        }

        if ($this->useApcu) {
            apcu_store($this->apcuKey, $payload, max(1, $expiresAt - time()));

            return;
        }

        $this->writeFile($payload);
    }

    /**
     * Verwirft das gespeicherte Token.
     *
     * Wird nach einem 401 aufgerufen: Der Collector hat es abgelehnt, also ist es
     * ungültig, gleichgültig was der Ablaufzeitpunkt behauptet.
     */
    public function forget(): void
    {
        if ($this->useApcu) {
            apcu_delete($this->apcuKey);

            return;
        }

        @unlink($this->file);
    }

    /**
     * @return array{token: string, expires_at: int}|null
     */
    private function load(): ?array
    {
        $raw = $this->useApcu ? $this->loadFromApcu() : $this->loadFromFile();

        if (null === $raw) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!\is_array($data)
            || !\is_string($data['token'] ?? null)
            || !\is_int($data['expires_at'] ?? null)
        ) {
            return null;
        }

        return ['token' => $data['token'], 'expires_at' => $data['expires_at']];
    }

    private function loadFromApcu(): ?string
    {
        $value = apcu_fetch($this->apcuKey, $ok);

        return true === $ok && \is_string($value) ? $value : null;
    }

    private function loadFromFile(): ?string
    {
        $raw = @file_get_contents($this->file);

        return \is_string($raw) && '' !== $raw ? $raw : null;
    }

    /**
     * Über Temp-Datei und rename(), damit ein gleichzeitiger Leser nie ein halb
     * geschriebenes Token sieht. Fehler werden verschluckt: Ein Token, das sich
     * nicht ablegen lässt, kostet die nächste Anmeldung — mehr nicht.
     */
    private function writeFile(string $payload): void
    {
        $verzeichnis = \dirname($this->file);

        if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0o770, true) && !is_dir($verzeichnis)) {
            return;
        }

        $temp = $this->file.'.'.bin2hex(random_bytes(4));

        if (false === @file_put_contents($temp, $payload)) {
            return;
        }

        @chmod($temp, 0o600);

        if (!@rename($temp, $this->file)) {
            @unlink($temp);
        }
    }
}
