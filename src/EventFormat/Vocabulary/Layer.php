<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Vocabulary;

/**
 * Die drei Beobachtungsebenen aus Konzept 2.1 Sensorik.
 *
 * Die Werte entsprechen exakt dem collectorseitigen ENUM layer_type
 * (Konzept 4.2.1 Tabellenschema). Ein vierter Wert würde dort einen
 * Insert-Fehler auslösen — deshalb hat der Heartbeat bewusst keinen Layer,
 * sondern einen eigenen Nachrichtentyp.
 */
enum Layer: string
{
    case Kernel = 'kernel';
    case Security = 'security';
    case Business = 'business';
}
