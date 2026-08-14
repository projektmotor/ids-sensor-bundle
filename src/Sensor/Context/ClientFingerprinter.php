<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Bildet actor.client_fingerprint nach Konzept 2.2.4 — Bildung der
 * Sitzungskontext-Felder: SHA256 über eine feste, dokumentierte Feldfolge aus
 * User-Agent, Accept-Language und Accept-Encoding.
 *
 * Die Auswahl ist bewusst schmal. Konzept dazu: „je mehr Header einfließen, desto
 * häufiger ändert sich der Fingerprint aus harmlosen Gründen und desto mehr False
 * Positives erzeugt Regel B9". B9 schlägt an, wenn sich der Fingerprint innerhalb
 * einer Sitzung ändert — sie ist das präzisere Signal für Session-Übernahme als ein
 * IP-Wechsel, aber nur solange der Fingerprint stabil ist.
 *
 * Verwendet den ungekürzten User-Agent, obwohl payload.user_agent auf 512 Zeichen
 * begrenzt wird: sonst würde die eigene Kürzung den Fingerprint mitbestimmen.
 *
 * @internal
 */
final class ClientFingerprinter
{
    /**
     * Die Reihenfolge ist Teil des Vertrags — eine Änderung ändert JEDEN Fingerprint
     * und macht B9 für die Dauer der Umstellung blind.
     *
     * @var list<string>
     */
    public const DEFAULT_HEADERS = ['User-Agent', 'Accept-Language', 'Accept-Encoding'];

    /**
     * @param list<string> $headers
     */
    public function __construct(
        private readonly array $headers = self::DEFAULT_HEADERS,
        private readonly bool $enabled = true,
    ) {
    }

    public function forRequest(Request $request): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $values = [];
        $anyPresent = false;

        foreach ($this->headers as $header) {
            $value = $request->headers->get($header);

            if (null !== $value) {
                $anyPresent = true;
            }

            $values[] = $value ?? '';
        }

        // Fehlt jeder der Header, gibt es kein Signal — dann ist null die ehrliche
        // Auskunft. Ein Hash über drei leere Zeichenketten wäre eine Kennung, die
        // sich sämtliche header-losen Bots teilen; Regel B9 würde bei jedem Wechsel
        // zwischen zwei solchen Clients grundlos anschlagen.
        if (!$anyPresent) {
            return null;
        }

        return hash('sha256', implode("\n", $values));
    }
}
