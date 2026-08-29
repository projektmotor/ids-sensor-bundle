<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Tests\Unit\Support\Identity;

use Psr\Log\AbstractLogger;

/**
 * Ein Logger, der die Meldungen aufhebt statt sie zu schreiben.
 *
 * Stand bis schema_version 1 in EnvironmentResolverTest, der mit der Umgebungsauflösung
 * entfallen ist. Er gehört in eine eigene Datei, weil ihn jetzt nur noch der
 * SensorIdentityProviderTest benutzt und eine Klasse in einer fremden Testdatei beim
 * nächsten Aufräumen wieder verschwinden würde.
 *
 * @internal
 */
final class SammelnderLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $meldungen = [];

    /**
     * Untypisierte Parameter — siehe `FailSafeLogger::log()`.
     *
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $text = (string) $message;

        foreach ($context as $schluessel => $wert) {
            if (\is_scalar($wert)) {
                $text = str_replace('{'.$schluessel.'}', (string) $wert, $text);
            }
        }

        $this->meldungen[] = $text;
    }
}
