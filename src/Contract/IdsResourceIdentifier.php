<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Contract;

/**
 * Optional: liefert die Kennung, die als payload.resource übertragen wird.
 *
 * Konzept 3.1.2 verlangt für Autorisierungsentscheidungen einen Identifier-String der
 * Form `Klasse#ID` und ausdrücklich „niemals das vollständige Objekt". Ohne Mitwirkung
 * der Anwendung muss der Sensor raten — er versucht getId(), fällt auf den
 * Klassennamen zurück und darf dabei unter keinen Umständen ein Nachladen auslösen.
 *
 * Wer das nicht dem Raten überlassen will, implementiert dieses Interface an seinen
 * Aggregatwurzeln. Das ist der einzige Weg, die Kennung verlässlich zu bestimmen —
 * empfehlenswert überall dort, wo die Erkennung von Rechteausweitung (Szenario S7,
 * Regeln B7/P1/P2) auf brauchbare Ressourcen-Identifier angewiesen ist.
 */
interface IdsResourceIdentifier
{
    /**
     * Eine kurze, stabile Kennung — etwa "Order#42".
     *
     * MUSS ohne Datenbankzugriff auskommen: die Methode wird im Request-Pfad
     * aufgerufen, und Konzept 2.1 Sensorik verbietet dort jede Abfrage.
     *
     * null bedeutet „keine Kennung verfügbar"; dann greift die übliche Auflösung.
     */
    public function getIdsResourceId(): ?string;
}
