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
 * @internal
 */
final class UuidV7EventIdGenerator implements EventIdGeneratorInterface
{
    public function generate(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
