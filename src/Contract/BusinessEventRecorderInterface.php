<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Contract;

/**
 * Der explizite Übergabeweg für Business-Events (capture_mode: recorder).
 *
 * Öffentliche API, damit Anwendungen dagegen typisieren können.
 *
 * Wann dieser Weg statt des Dispatcher-Decorators sinnvoll ist:
 *  - Code, der keine Domain-Events über den EventDispatcher schickt
 *  - Deployments, die eine Dekoration von `event_dispatcher` ablehnen
 *  - Stellen, an denen die Meldung im Code-Review sichtbar sein soll
 *
 * Der Preis: die Fachlogik nimmt eine sichtbare Abhängigkeit auf das IDS. Beim
 * Dispatcher-Modus bleibt sie IDS-frei und das Bundle ist rückstandslos
 * entfernbar. Beide Wege münden in denselben Normalisierer.
 *
 * Implementierungen dürfen unter keinen Umständen eine Exception in die
 * aufrufende Anwendung werfen (fail-open, Konzept 4. IdsBackendBundle).
 */
interface BusinessEventRecorderInterface
{
    /**
     * Nimmt ein Business-Event zur Erfassung an.
     *
     * Der Aufruf ist billig: das Event wird nur gepuffert. Normalisierung,
     * Redaktion und Versand passieren erst nach dem Absenden der Antwort.
     */
    public function record(SecurityRelevantBusinessEvent $event): void;
}
