<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Delivery\Heartbeat;

use ProjektMotor\IdsSensor\Support\Identity\SensorIdentityProvider;

/**
 * Drosselt den Heartbeat auf das konfigurierte Intervall.
 *
 * WARUM DER SCHLÜSSEL DIE instance_id ENTHALTEN MUSS
 *
 * Die Drosselung ist prozessübergreifend — anders wäre sie im request-getriebenen Modus
 * wirkungslos, weil unter PHP-FPM jeder Request in einem anderen Prozess laufen kann und
 * jeder für sich „noch nie gesendet" feststellen würde.
 *
 * Prozessübergreifend heißt bei APCu aber auch: geteilt zwischen ALLEN Anwendungen, die
 * dieses PHP-Pool benutzen, und bei einem gemeinsamen Dateisystem sogar zwischen Hosts.
 * Ohne `instance_id` im Schlüssel würde die erste Instanz, die einen Heartbeat sendet, die
 * Heartbeats aller anderen für ein Intervall unterdrücken. Der Collector sähe eine einzige
 * lebende Instanz und würde für alle übrigen `ids.sensor_silent` melden — also einen
 * Dauerfalschalarm genau für die Instanzen, die in Ordnung sind.
 *
 * ZWEISTUFIGE ABLAGE, WIE BEIM CIRCUIT BREAKER
 *
 * APCu zuerst, Datei als Rückfall. Der Grund ist derselbe: der CLI-Prozess des
 * `ids:sensor:heartbeat`-Commands sieht das APCu-Segment von PHP-FPM NICHT — es sind
 * getrennte Shared-Memory-Segmente. Ohne die Datei wüssten Command- und Request-Pfad
 * nichts voneinander und würden im Modus `both` doppelt senden.
 *
 * @internal
 */
final class Scheduler
{
    private const APCU_PREFIX = 'ids_sensor.heartbeat.';

    /**
     * Die Kennungen kommen über den Provider und NICHT als Zeichenketten aus dem Container.
     *
     * Konzept 2.2.1 verlangt, dass die `instance_id` zur Laufzeit aufgelöst wird. Ein zur
     * Compile-Zeit eingebackener Wert wäre in einem gewärmten Container-Image der Hostname
     * EINER Instanz — und würde damit in allen Replicas denselben Drosselungsschlüssel
     * ergeben. Also genau der Fehler, den der Schlüssel verhindern soll.
     */
    public function __construct(
        private readonly SensorIdentityProvider $identityProvider,
        private readonly int $intervalSeconds = 60,
        private readonly ?string $stampFile = null,
    ) {
    }

    /**
     * Ist ein Heartbeat fällig?
     *
     * `$now` ist übergebbar, damit die Prüfung testbar ist, ohne auf Wanduhrzeit zu warten.
     */
    public function isDue(?int $now = null): bool
    {
        if ($this->intervalSeconds <= 0) {
            return false;
        }

        $now ??= time();
        $last = $this->lastSentAt();

        if (null === $last) {
            return true;
        }

        // Ein Stempel aus der ZUKUNFT heißt: die Uhr ist zurückgesprungen (NTP-Korrektur),
        // oder der Stempel stammt von einem anderen Host auf einem geteilten Volume — was
        // die Doku als Fehlkonfiguration ausdrücklich erwartet. Ohne diese Zeile wurde die
        // Differenz negativ und der Heartbeat blieb aus, bis die Wanduhr aufgeholt hatte.
        // Der Collector meldete dann `ids.sensor_silent` für einen gesunden Sensor —
        // genau der Falschalarm, den der Heartbeat verhindern soll.
        //
        // Die Nachbarmethode secondsSinceLastSend() klemmt mit max(0, …) und meldete
        // deshalb 0, während isDue() denselben Sachverhalt als „noch lange nicht fällig"
        // las. Die beiden widersprachen sich.
        if ($last > $now) {
            return true;
        }

        return ($now - $last) >= $this->intervalSeconds;
    }

    /**
     * Vermerkt den Versand. Wird NACH dem erfolgreichen Versand aufgerufen.
     *
     * Die Reihenfolge ist wichtig: würde vor dem Versand gestempelt, verschwiege ein
     * fehlgeschlagener Heartbeat das ganze nächste Intervall — und der Collector sähe eine
     * Lücke, ohne dass der Sensor sie bemerkt hätte.
     */
    public function markSent(?int $now = null): void
    {
        $now ??= time();
        $key = $this->key();

        if (\function_exists('apcu_store') && @apcu_enabled()) {
            @apcu_store($key, $now, max(1, $this->intervalSeconds * 10));
        }

        $file = $this->stampFile;

        if (null === $file) {
            return;
        }

        $directory = \dirname($file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o770, true);
        }

        // LOCK_EX, damit zwei gleichzeitig endende Requests keine halbe Zahl hinterlassen.
        @file_put_contents($file, (string) $now, \LOCK_EX);
    }

    public function lastSentAt(): ?int
    {
        $key = $this->key();

        if (\function_exists('apcu_fetch') && @apcu_enabled()) {
            $stored = @apcu_fetch($key, $success);

            if (true === $success && \is_int($stored)) {
                return $stored;
            }
        }

        $file = $this->stampFile;

        if (null === $file || !is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);

        if (false === $content || '' === trim($content)) {
            return null;
        }

        return (int) trim($content);
    }

    /**
     * Wie alt ist der letzte Versand? Für `ids:sensor:setup-check` und den Payload.
     */
    public function secondsSinceLastSend(?int $now = null): ?int
    {
        $last = $this->lastSentAt();

        return null === $last ? null : max(0, ($now ?? time()) - $last);
    }

    private function key(): string
    {
        $identity = $this->identityProvider->get();

        return self::APCU_PREFIX.$identity->applicationId.'.'.$identity->instanceId;
    }
}
