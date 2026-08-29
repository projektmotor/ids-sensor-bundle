<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Bildet actor.session_id_hash nach Konzept 2.2.4 — Bildung der
 * Sitzungskontext-Felder.
 *
 * Die Session-ID wird NIEMALS im Klartext übertragen. Andernfalls wäre die
 * Event-Datenbank selbst ein Session-Hijacking-Vektor und würde genau die
 * Angriffsfläche vergrößern, die sie überwachen soll. Der Hash erfüllt den Zweck —
 * Events derselben Sitzung über mehrere Requests hinweg verketten, Grundlage der
 * Regeln B8/B9 — vollständig, ohne die Sitzung übernehmbar zu machen.
 *
 * WARUM EIN BLANKER SHA-256 UND KEIN HMAC
 *
 * Getragen wird die Einwegbeziehung von der Entropie der Session-ID, nicht von einem
 * Schlüssel. PHP erzeugt vorgabemäßig 26 bis 32 Zeichen zu je 5 Bit, also 130 bis 160
 * Bit — ein SHA-256 darüber ist nicht durchprobierbar. `ids:sensor:setup-check` prüft
 * genau diese Voraussetzung, weil ohne Schlüssel alles daran hängt.
 *
 * Ein dedizierter HMAC-Schlüssel stand hier bis Fassung 2 und ist entfallen, weil seine
 * beiden Begründungen nicht trugen. „Sonst lässt sich die ID zurückrechnen" gilt nur für
 * schwache IDs, und gegen die hilft ein Schlüssel auch nicht — sie hätte dann in jedem
 * Fall zu wenig Entropie. Und „die Anwendung kennt APP_SECRET" traf den IDS-Schlüssel
 * genauso: Er steht in der Konfiguration derselben Anwendung, sonst könnte der Sensor
 * nicht hashen. Gegen einen Angreifer mit Codeausführung — das Bedrohungsmodell aus
 * Konzept Abschnitt 2 — wirkte er also nie. Er schützte allein gegen jemanden, der die
 * Event-Datenbank hat, aber nicht die Anwendung, und kostete dafür den einzigen
 * Kompilierzeit-Abbruch in einem fail-open-Bundle und einen Rotationsweg, den es nicht
 * gab. Der Nachbar {@see ClientFingerprinter} hasht ohnehin ungeschlüsselt, und zwar
 * über deutlich schwächere Entropie.
 *
 * Gelesen wird der Cookie-Wert, NICHT $request->getSession()->getId(). Der
 * Unterschied ist keine Feinheit: getSession() materialisiert die Lazy-Factory und
 * startet gegebenenfalls eine Session. Unter einem PDO- oder Redis-Session-Handler
 * ist das ein Datenbank- oder Netzwerkzugriff — verboten laut Konzept 2.1 Sensorik —
 * und es setzt zusätzlich ein Cookie in einer Antwort, die vorher keines hatte. Der
 * Cookie-Wert ist genau die ID, die der Client gesendet hat, und damit exakt das,
 * was zur Verkettung gebraucht wird.
 *
 * @internal
 */
final class SessionIdHasher implements ResetInterface
{
    /**
     * Session-IDs bestehen bei PHP aus alphanumerischen Zeichen, Komma und
     * Bindestrich. Der Filter verhindert, dass ein Angreifer beliebige Inhalte über
     * ein manipuliertes Cookie in den Hash-Eingang schiebt.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9,-]{8,128}$/';

    private const DEFAULT_COOKIE_NAME = 'PHPSESSID';

    private ?string $memoRawId = null;

    private ?string $memoHash = null;

    public function __construct(
        private readonly ?string $cookieName = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function forRequest(Request $request): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $rawId = $this->readSessionId($request);

        if (null === $rawId) {
            return null;
        }

        // Einmal pro ID merken, nicht pro Request: bei der Anmeldung wechselt die
        // Session-ID (SessionStrategyListener). Ein Request-weiter Zwischenspeicher
        // würde danach den alten Hash weiterliefern und die Sitzungsverkettung des
        // Collectors an genau der interessantesten Stelle zerreißen. Der Schlüssel
        // des Zwischenspeichers ist deshalb die ID selbst.
        if ($rawId === $this->memoRawId) {
            return $this->memoHash;
        }

        $this->memoRawId = $rawId;

        return $this->memoHash = hash('sha256', $rawId);
    }

    /**
     * DER NAME KOMMT AUS DER KONFIGURATION, NICHT AUS php.ini.
     *
     * `$cookieName` trägt entweder die ausdrückliche Angabe der Anwendung oder den Wert
     * von `framework.session.name`, den {@see \ProjektMotor\IdsSensor\IdsSensorBundle}
     * zur Compile-Zeit aufliest. `ini_get('session.name')` ist nur noch der letzte
     * Rückfall — als alleinige Quelle war es falsch: Symfony schreibt
     * `framework.session.name` erst dann nach php.ini, wenn `NativeSessionStorage`
     * konstruiert wird, und das ist ein lazy Dienst. Zum Erfassungszeitpunkt (RequestSensor
     * bei Priorität 1024, SessionListener bei 128) stand dort praktisch immer noch
     * `PHPSESSID`.
     *
     * Jede Anwendung mit eigenem Session-Namen lieferte deshalb `actor.session_id_hash:
     * null` in JEDEM Event — die Regeln B8/B9 aus Konzept 4.3.3 waren still abgeschaltet.
     */
    private function readSessionId(Request $request): ?string
    {
        $name = $this->cookieName ?? (\ini_get('session.name') ?: self::DEFAULT_COOKIE_NAME);

        $value = $request->cookies->get($name);

        if (!\is_string($value) || 1 !== preg_match(self::ID_PATTERN, $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Wirft die Roh-Session-ID weg, sobald der Request vorbei ist.
     *
     * Ohne das überlebte die ID im KLARTEXT in einem Instanzfeld — in einer
     * Worker-Laufzeit (FrankenPHP, RoadRunner, Swoole) bis zum nächsten Request, der
     * eine andere ID mitbringt. Genau die Klartext-Speicherung, die der Docblock dieser
     * Klasse als „niemals" bezeichnet, nur eine Ebene tiefer. Alle Nachbarn im
     * Erfassungspfad — {@see CaptureBudget}, {@see EventBuffer},
     * {@see RequestSnapshotRegistry} — setzen sich längst zurück; diese Klasse hatte es
     * am nötigsten und tat es als einzige nicht.
     *
     * Der Zwischenspeicher ist damit weiterhin auf die ID geschlüsselt (siehe
     * {@see self::forRequest()}) — er wird nur zusätzlich am Requestende geleert.
     */
    public function reset(): void
    {
        $this->memoRawId = null;
        $this->memoHash = null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
