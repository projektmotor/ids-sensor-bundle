<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Kernel;

/**
 * Wie mit Sub-Requests umgegangen wird — im Konzept nicht geregelt, hier entschieden.
 *
 * Sub-Requests entstehen bei Twig-render(), ESI-Fragmenten und Fehlerseiten.
 * InlineFragmentRenderer baut sie über Request::duplicate(), ihr Pfad ist also
 * meistens eine Kopie des Elternpfades. Würden sie vollständig erfasst, zählte jede
 * Schwellwertregel pro Pfad und IP mehrfach — eine Seite mit fünf render()-Aufrufen
 * erzeugte sechs fast identische Request-Events. Das ist genau die Fehlalarmquelle,
 * die Konzept 2.2.1 beim Fehlen des Anwendungskontexts beschreibt.
 *
 * Sub-Request-EXCEPTIONS sind der umgekehrte Fall: InlineFragmentRenderer verschluckt
 * sie bei ignore_errors vollständig, sie existieren also in keinem anderen Event.
 * Genau das will ein IDS sehen.
 *
 * Szenario S3 (Fragment-Handler-Missbrauch) bleibt davon unberührt: ein über HTTP
 * eingehender /_fragment-Aufruf ist ein MAIN-Request. Symfonys FragmentListener
 * prüft die Signatur ausdrücklich nur für Main-Requests. Unterdrückt wird also nur
 * das interne Rendern, nicht der Angriffsversuch.
 *
 * Die Werte sind Konfigurationswerte von `layers.kernel.sub_requests` und damit
 * stabil; das Enum als PHP-Typ ist es nicht.
 *
 * @internal
 */
enum SubRequestMode: string
{
    /** Keine Events aus Sub-Requests. */
    case None = 'none';

    /** Nur Exceptions — die Vorgabe. */
    case ExceptionsOnly = 'exceptions_only';

    /** Alle drei Kernel-Events auch aus Sub-Requests. */
    case All = 'all';

    public function allowsRequestEvents(): bool
    {
        return self::All === $this;
    }

    public function allowsResponseEvents(): bool
    {
        return self::All === $this;
    }

    public function allowsExceptionEvents(): bool
    {
        return self::None !== $this;
    }
}
