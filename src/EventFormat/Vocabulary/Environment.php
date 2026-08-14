<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\EventFormat\Vocabulary;

/**
 * Die zulässigen Umgebungen aus Konzept 2.2.1.
 *
 * Die Werte entsprechen exakt dem collectorseitigen ENUM env_type
 * (Konzept 4.2.1 Tabellenschema). Das ist der Grund, warum
 * %kernel.environment% nicht direkt durchgereicht werden darf: dort sind
 * beliebige Strings erlaubt ("test", "prod_eu", …), collectorseitig aber nicht.
 * Ein unzulässiger Wert würde den Insert scheitern lassen und damit alle Events
 * dieser Instanz still verlieren — von einem toten Sensor nicht unterscheidbar.
 * Die Übersetzung übernimmt der EnvironmentResolver.
 */
enum Environment: string
{
    case Prod = 'prod';
    case Staging = 'staging';
    case Dev = 'dev';
}
