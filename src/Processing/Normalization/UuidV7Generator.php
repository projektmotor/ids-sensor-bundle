<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Processing\Normalization;

use Symfony\Component\Uid\Uuid;

/**
 * Erzeugt zeitgeordnete UUIDs (Version 7).
 *
 * Das Konzept schreibt in 2.2.1 nur „vom Normalisierer generierte UUID" vor; das
 * Beispiel in Abschnitt 3 zeigt eine v4. v7 ist spaltenkompatibel (beides UUID)
 * und hat einen konkreten Vorteil auf der Collector-Seite: die Event-Tabellen sind
 * nach timestamp partitioniert und haben PRIMARY KEY (event_id, timestamp)
 * (Konzept 4.2.1). Zeitgeordnete IDs schreiben dort in benachbarte Index-Bereiche
 * statt über den ganzen Baum gestreut.
 *
 * Für die frame_id gilt dasselbe Argument wortgleich: frames ist nach flushed_at
 * partitioniert und hat PRIMARY KEY (frame_id, flushed_at).
 *
 * @internal
 */
final class UuidV7Generator implements UuidGeneratorInterface
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
