<?php

declare(strict_types=1);

namespace ProjektMotor\IdsSensor\Sensor\Context;

use ProjektMotor\IdsEventData\Event\Actor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Baut die vier actor.*-Felder aus Konzept Abschnitt 3.
 *
 * WICHTIG zur Token-Quelle: Injiziert wird `security.untracked_token_storage`,
 * niemals `security.token_storage`.
 *
 * Der Unterschied hat eine sichtbare Nebenwirkung auf die überwachte Anwendung.
 * `security.token_storage` ist eine UsageTrackingTokenStorage; ihr getToken() greift
 * auf die Session-Metadaten zu und erhöht damit den Session-Usage-Index. Symfonys
 * AbstractSessionListener bricht in onKernelResponse nur dann früh ab, wenn dieser
 * Index 0 ist — andernfalls schreibt er `Cache-Control: private, must-revalidate` in
 * die Antwort. Ein Sensor, der den getrackten Speicher benutzt, macht also
 * öffentlich cachebare Antworten der überwachten Anwendung uncachebar. Das gehört in
 * dieselbe Kategorie wie das Starten einer Session für eine zustandslose Anfrage:
 * eine Verhaltensänderung, die niemand bestellt hat.
 *
 * Beide Service-IDs existieren in Symfony 6.4 und 7.x, der Unterschied ist ein
 * Argument in der Verdrahtung.
 *
 * @internal
 */
final class ActorFactory
{
    public function __construct(
        private readonly SessionIdHasher $sessionIdHasher,
        private readonly ClientFingerprinter $fingerprinter,
        private readonly ?TokenStorageInterface $untrackedTokenStorage = null,
    ) {
    }

    public function forRequest(Request $request, ?RequestSnapshot $snapshot): Actor
    {
        return $this->build($request, $snapshot, $this->currentUser());
    }

    /**
     * Für Erfassungspunkte, an denen die Benutzerkennung nicht zu holen ist oder gleich
     * ausdrücklich gesetzt wird.
     *
     * Bei kernel.request greift die Firewall noch nicht (Konzept 2.2.2 — Nutzerkontext
     * auf Kernel-Ebene), ein Aufruf wäre reine Arbeit ohne Ergebnis. Beim
     * Anmeldefehlschlag liegt ohnehin kein Token im Speicher, und die versuchte Kennung
     * trägt der Sensor selbst nach.
     */
    public function forRequestWithoutUser(Request $request, ?RequestSnapshot $snapshot): Actor
    {
        return $this->build($request, $snapshot, null);
    }

    private function build(Request $request, ?RequestSnapshot $snapshot, ?string $user): Actor
    {
        return new Actor(
            $user,
            self::clientIp($request),
            $this->sessionIdHasher->forRequest($request),
            $this->fingerprint($request, $snapshot),
        );
    }

    /**
     * Die Client-IP, ohne dass ein Angreifer die Erfassung damit abschalten kann.
     *
     * `Request::getClientIps()` wirft `ConflictingHeadersException`, sobald
     * `framework.trusted_proxies` gesetzt ist und ein `Forwarded`- einem
     * `X-Forwarded-For`-Header widerspricht. Beide Header darf der Client schicken; der
     * Wurf ist damit von außen auslösbar.
     *
     * Ungefangen kostete das je nach Erfassungspunkt das ganze Event: in RequestSensor
     * und ResponseSensor steht der Akteursaufbau VOR dem append(), das Event war also
     * gebaut und verschwand ungezählt. Ein Angreifer konnte seinen eigenen Verkehr so
     * gezielt unsichtbar machen — genau der auslösbare blinde Fleck, den Konzept 2.1
     * ausschließen will.
     *
     * Der Rückfall ist REMOTE_ADDR: die tatsächliche Gegenstelle. Hinter einem Proxy ist
     * das dessen Adresse und nicht die des Clients — unvollständig, aber niemals
     * gefälscht. Ein aus den widersprüchlichen Headern geratener Wert wäre schlechter
     * als gar keiner.
     */
    private static function clientIp(Request $request): ?string
    {
        try {
            return $request->getClientIp();
        } catch (\Throwable) {
            $remoteAddress = $request->server->get('REMOTE_ADDR');

            return \is_string($remoteAddress) && '' !== $remoteAddress ? $remoteAddress : null;
        }
    }

    /**
     * Der Fingerabdruck wird im Snapshot gemerkt und nicht je Event neu berechnet.
     *
     * Das ist ein Schreibzugriff auf das übergebene Objekt und damit mehr, als der Name
     * einer Fabrik verspricht — aber ein Request erzeugt bis zu 200
     * Autorisierungsentscheidungen, und der Fingerabdruck ist über alle derselbe. Ihn
     * je Event neu zu hashen wäre Arbeit im Erfassungspfad, für die das Budget aus
     * Konzept 2.1 nicht da ist.
     *
     * Ohne Snapshot gibt es nichts zu merken — dann wird gerechnet. Dieser Fall tritt nur
     * ein, wenn kernel.request diesen Request nie erreicht hat, und dort zählt
     * Vollständigkeit mehr als die eine Ersparnis.
     *
     * Gemerkt wird über ein eigenes „berechnet"-Flag und nicht über `??=`: `null` ist
     * beim Fingerabdruck ein gültiges Ergebnis, und `??=` hielt es für „noch nichts da".
     * Die Ersparnis blieb damit ausgerechnet dort aus, wo sie gemeint war — bei
     * header-losen Clients und bei abgeschaltetem Fingerabdruck.
     */
    private function fingerprint(Request $request, ?RequestSnapshot $snapshot): ?string
    {
        if (null === $snapshot) {
            return $this->fingerprinter->forRequest($request);
        }

        if (!$snapshot->clientFingerprintComputed) {
            $snapshot->clientFingerprint = $this->fingerprinter->forRequest($request);
            $snapshot->clientFingerprintComputed = true;
        }

        return $snapshot->clientFingerprint;
    }

    /**
     * Für Kontexte ohne HTTP-Request: Console-Commands, Messenger-Worker, Cron.
     * Konzept 2.2.4 sieht dort ip, session_id_hash und client_fingerprint als null
     * vor; die Benutzerkennung kann trotzdem bekannt sein.
     */
    public function forCli(): Actor
    {
        return new Actor($this->currentUser());
    }

    /**
     * Nur die Kennung, nichts weiter: kein Rollen-Lookup, kein Nachladen des Nutzers
     * über den User-Provider. Beides wäre potenziell ein Datenbankzugriff im
     * Request-Pfad.
     */
    public function currentUser(): ?string
    {
        $token = $this->untrackedTokenStorage?->getToken();

        if (null === $token) {
            return null;
        }

        $identifier = $token->getUserIdentifier();

        return '' === $identifier ? null : Actor::truncateUser($identifier);
    }
}
