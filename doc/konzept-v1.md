# IDS-Konzept: Generische Symfony-5.4-Anwendung

**Stand:** 13.08.2026
**Status:** In Bearbeitung — Neuansatz, ersetzt vorheriges Zwei-Profil-Komplexsystem (TYPO3 + API/Symfony)
**Versionshistorie:** Version 1 gesichert am 13.08.2026 (Stand nach Restrukturierung: Abschnitte 1–6, inkl. Zwei-Bundle-Auslieferung, Erkennungsstruktur mit Regel-Unterabschnitt 5.2)

---

## Inhaltsverzeichnis

- [1. Ausgangslage & Scope](#1-ausgangslage--scope)
- [2. IdsSensorBundle](#2-idssensorbundle)
  - [2.1 Sensorik](#21-sensorik)
    - [2.1.1 HttpKernel-Events](#211-httpkernel-events)
    - [2.1.2 Security-Component-Events](#212-security-component-events)
    - [2.1.3 Business-/Domain-Events](#213-business-domain-events)
  - [2.2 Normalisierungs-Mapping](#22-normalisierungs-mapping)
    - [2.2.1 Gemeinsame genutzte Eigenschaften (bei allen Sensoren gleich)](#221-gemeinsame-genutzte-eigenschaften-bei-allen-sensoren-gleich)
      - [Anwendungs- und Instanzkontext](#anwendungs--und-instanzkontext)
      - [Konkrete Ableitungsregeln für event_severity](#konkrete-ableitungsregeln-für-event_severity)
    - [2.2.2 Normalisierung Kernel-Ebene](#222-normalisierung-kernel-ebene)
      - [Nutzerkontext auf Kernel-Ebene](#nutzerkontext-auf-kernel-ebene)
    - [2.2.3 Normalisierung Security-Ebene](#223-normalisierung-security-ebene)
    - [2.2.4 Normalisierung Business-Ebene](#224-normalisierung-business-ebene)
      - [Bildung der Sitzungskontext-Felder](#bildung-der-sitzungskontext-felder)
- [3. Normalisierungsformat](#3-normalisierungsformat)
  - [3.1 Payload-Format pro Ebene / Events](#31-payload-format-pro-ebene--events)
    - [3.1.1 Kernel-Ebene / -Events](#311-kernel-ebene---events)
      - [`kernel.request`](#kernelrequest)
      - [`kernel.exception`](#kernelexception)
      - [`kernel.response`](#kernelresponse)
    - [3.1.2 Security-Ebene / -Events](#312-security-ebene---events)
      - [`security.authentication.success`](#securityauthenticationsuccess)
      - [`security.authentication.failure`](#securityauthenticationfailure)
      - [`security.access_decision`](#securityaccess_decision)
    - [3.1.3 Business-Ebene / -Events](#313-business-ebene---events)
      - [Generische Business-Events](#generische-business-events)
  - [3.2 Bewusste Feldredundanz zwischen Request- und Folge-Events](#32-bewusste-feldredundanz-zwischen-request--und-folge-events)
- [4. IdsBackendBundle - Zentrale Sammelstelle](#4-idsbackendbundle---zentrale-sammelstelle)
  - [4.1 Consumer](#41-consumer)
  - [4.2 PostgreSQL-Datenbank](#42-postgresql-datenbank)
    - [4.2.1 Tabellenschema](#421-tabellenschema)
    - [4.2.2 Indizierung](#422-indizierung)
    - [4.2.3 Retention & Partitionierung](#423-retention--partitionierung)
      - [Volumenbudget und gestufte Retention](#volumenbudget-und-gestufte-retention)
      - [Partitionierung mit pg_partman](#partitionierung-mit-pg_partman)
    - [4.2.4 Auswertungstabellen](#424-auswertungstabellen)
  - [4.3 Detection](#43-detection)
    - [4.3.1 Echtzeit-Regeln (pro Event, im Consumer)](#431-echtzeit-regeln-pro-event-im-consumer)
    - [4.3.2 Periodische Regeln (Batch, alle 1–5 Minuten)](#432-periodische-regeln-batch-alle-15-minuten)
    - [4.3.3 Ebenenübergreifende Korrelation](#433-ebenenübergreifende-korrelation)
    - [4.3.4 Positivpfad-Regeln (Prüfung erfolgreicher Vorgänge)](#434-positivpfad-regeln-prüfung-erfolgreicher-vorgänge)
    - [4.3.5 Anomaliebasierte Ergänzung](#435-anomaliebasierte-ergänzung)
    - [4.3.6 Detektions-Regeln Symfony-typische Angriffsszenarien](#436-detektions-regeln-symfony-typische-angriffsszenarien)
  - [4.4 Alerting - Vorfall statt Einzelalarm](#44-alerting---vorfall-statt-einzelalarm)
  - [4.5 Absicherung der Sammelstelle und Rohdatenschutz](#45-absicherung-der-sammelstelle-und-rohdatenschutz)
    - [4.5.1 Redaktion sensibler Werte in `raw`](#451-redaktion-sensibler-werte-in-raw)
    - [4.5.2 Zugriffstrennung in der Datenbank](#452-zugriffstrennung-in-der-datenbank)
    - [4.5.3 Weitere Maßnahmen](#453-weitere-maßnahmen)
- [6. Offene Punkte — priorisierte Gesamtübersicht](#6-offene-punkte--priorisierte-gesamtübersicht)
  - [6.1 Erledigt in dieser Fassung](#61-erledigt-in-dieser-fassung)
  - [6.2 Erkennung](#62-erkennung)
  - [6.3 Betrieb, Auslieferung, Validierung](#63-betrieb-auslieferung-validierung)
  - [6.4 Empfohlene Reihenfolge](#64-empfohlene-reihenfolge)

---

## 1. Ausgangslage & Scope

**Ziel:** Intrusion Detection (Angriffserkennung) für eine **generische Symfony-5.4-Anwendung**.

**Bewusste Eingrenzung — explizit NICHT Teil dieses Konzepts (vorerst):**
- Keine Überwachung des Webservers (Apache/Nginx)
- Keine Überwachung der Datenbank
- Keine Überwachung eines Reverse-Proxy
- Keine Überwachung der Netzwerk-/Infrastruktur-Ebene
- Kein Bezug auf ein konkretes, bestehendes Projekt — es geht um Symfony >5.4 als generisches Muster

Betrachtet wird ausschließlich das, was **innerhalb der Symfony-Anwendung selbst** beobachtbar ist.

**Aufbau:** Zwei Symfony-Bundles, die ausschließlich über die Message Queue miteinander kommunizieren.

| Paket | Läuft wo | Aufgabe |
|---|---|---|
| `IdsSensorBundle` | in der überwachten Symfony-Anwendung | Erfassung, Normalisierung, Redaktion (4.5.1), Versand an den Broker |
| `IdsBackendBundle` | läuft in eigenständiger Symfony-Anwendung (Backend / Dashboard), getrennt deployed| Empfang, Speicherung, sämtliche Regeln aus Abschnitt 4.3, Alerts, Applications verwalten, ... |

**Die Paketgrenze ist das normalisierte Event-Format aus Abschnitt 3.** Alle zur Erkennung nötigen Daten stecken darin — deshalb liegen *alle* Regeln im Collector, auch die Symfony-spezifischen. Sie prüfen normalisierte Feldwerte (`payload.path`, `payload.exception_class`), nicht Framework-Objekte, und brauchen zur Laufzeit keine Symfony-Kenntnis.

**`schema_version` im Event:** Da beide Bundles getrennt deployed werden, laufen sie zeitweise auseinander. Das Feld erlaubt dem Collector, ältere Sensoren zu verarbeiten, statt Events zu verwerfen.

---

## 2. IdsSensorBundle

**Architekturentscheidung:** Sensor und Normalisierer bilden **einen Baustein** (nicht zwei getrennte Komponenten) — z. B. ein Symfony-Event-Subscriber, der das Rohevent abfängt und direkt in normalisierter Form weitergibt.

**Kernel- und Security-Ebene (siehe 2.1 Sensorik und 2.2 Normalisierungs-Mapping) sind nach `composer require` ohne Anwendungscode aktiv** — die Event-Subscriber registrieren sich selbst. Die Business-Ebene erfordert zwingend Arbeit in der Anwendung (Implementierung der benötigten Events). Diese Asymmetrie entspricht der Wirksamkeitsaussage aus 2.1 und wird in der Auslieferung nicht verschleiert.

> **Hinweis zur Wirksamkeit:** Die drei Ebenen sind nicht gleichwertig ersetzbar. Kernel- und Security-Ebene erkennen zuverlässig *gescheiterte* Angriffe (Fehler, Denials, Fehlversuche). Erfolgreiche Angriffe, die die Anwendung bestimmungsgemäß benutzen, erzeugen dort **kein Signal** und sind ausschließlich über die Business-Ebene erkennbar. Wird die Business-Ebene nicht impleentiert, bleibt das System auf die Erkennung gescheiterter Angriffe beschränkt.

**Warum das IdsSensorBundle keinen Datenbankzugriff erhalten darf**

Trüge das Sensor-Bundle die PostgreSQL-Zugangsdaten, hätte die überwachte Anwendung Zugriff auf ihren eigenen Beweisspeicher. Ein Angreifer mit Codeausführung — also genau das Szenario aus S4 und S5 — könnte seine Spuren löschen. Die Manipulationsgrenze verläuft deshalb am Broker, mit **asymmetrischen Rechten**:

| | Anwendung (Sensor) | Collector |
|---|---|---|
| RabbitMQ | `write` auf den Exchange; kein `read`, kein `configure` | `read` auf die Queue; kein `write` |
| Redis Streams | nur `XADD` auf den Stream-Key | `XREADGROUP`, `XACK`, `XGROUP` |

Damit kann ein Angreifer in der Anwendung keine bereits abgesendeten Events löschen und die noch nicht konsumierten Events anderer Requests nicht mitlesen.

**Was dadurch nicht verhindert wird:** gefälschte Events einschleusen (der Sensor braucht Schreibrecht), die Queue fluten (Restrisiko aus Abschnitt 4), und den Sensor stilllegen. Letzteres ist lautlos und daher am gefährlichsten — deshalb sendet jeder Sensor im festen Intervall (Vorschlag: 60 s) einen **Heartbeat** mit `application_id` und `instance_id`. Bleibt er aus, erzeugt der Collector einen Alert (`rule_id = "ids.sensor_silent"`). Das macht aus dem Stilllegen ein detektierbares Ereignis.

Aus demselben Grund liegt die **Erkennungskonfiguration collectorseitig** (Pfad-Wissensbasis, Schwellwerte, Cooldowns) und wird nicht vom Sensor mitgeliefert — andernfalls könnte eine kompromittierte Anwendung sich die unangenehmen Regeln abschalten.

### 2.1 Sensorik

3 Beobachtungsebenen:

- HttpKernel-Events
- Security-Component-Events
- Business-/Domain-Events

**Entscheidungen:** 
- Alle drei Ebenen werden von Anfang an gemeinsam betrachtet (nicht sequenziell/optional). Ziel, Auswertungen / Analysen sollen auch Kombination der drei Ebene abbilden können.
- Jede der drei Beobachtungsebenen erhält einen **eigenen Sensor**, der jeweils **fest mit einem eigenen Normalisierer gekoppelt** ist (ein Baustein). Der Normalisierer sendet die normalisierten Daten anschließend an eine **zentrale Sammelstelle**.

> **Hinweis zur Wirksamkeit:** Die drei Ebenen sind nicht gleichwertig ersetzbar. Kernel- und Security-Ebene erkennen zuverlässig *gescheiterte* Angriffe (Fehler, Denials, Fehlversuche). Erfolgreiche Angriffe, die die Anwendung bestimmungsgemäß benutzen, erzeugen dort **kein Signal** und sind ausschließlich über die Business-Ebene erkennbar (siehe 2.1.3 und 4.3.4). Wird die Business-Ebene nicht instrumentiert, bleibt das System auf die Erkennung gescheiterter Angriffe beschränkt.

![Datenfluss und Regelklassen des Symfony-IDS](./symfony-ids-architektur.svg)

*Die Grafik zeigt den Gesamtaufbau über diesen Abschnitt hinaus: Erfassung (2.1.1–2.1.3), Transport (2.1), Speicherung (Abschnitt 4) und die vier Regelklassen der Detection (4.3). Nicht dargestellt sind die Pfad-Wissensbasis `known_paths.yaml` (4.3.1), die als Konfiguration in den Consumer geladen wird, und die Tabelle `metric_baselines` (4.2.4), die der Detection Job für Positivpfad- und Anomalieregeln liest und schreibt.*

**Transport-Entscheidung:** Übertragung erfolgt **asynchron über Queue/Message Bus** (z. B. Symfony Messenger) mit **echtem Broker** (z. B. Redis/RabbitMQ) als Transport-Infrastruktur. Der Broker selbst ist kein Monitoring-Ziel, sondern reines Implementierungs-Hilfsmittel.

**Latenzbudget:**

- Verbindliche Obergrenze: Alle drei Sensoren zusammen dürfen die Request-Latenz um höchstens **5 ms im 99. Perzentil** erhöhen

Daraus folgen drei Konstruktionsvorgaben:

- Im Request-Pfad findet **keine Datenbankabfrage** statt. Erfassung, Normalisierung und Dispatch an den Transport — nichts darüber hinaus.
- Die Echtzeitregeln (4.3.1) prüfen ausschließlich gegen Redis (`INCR` mit TTL), nicht gegen PostgreSQL. Das ist der Grund für die Aufteilung in Echtzeit- und Batch-Schicht — nicht die Komplexität der Regeln.
- Serialisierung und Versand dürfen den Request nicht blockieren; das Fehler- und Timeout-Verhalten ist in Abschnitt 4 festgelegt.

Wird das Budget überschritten, ist Sampling der `info`-Events (siehe 4.2.3) das vorgesehene Mittel, nicht das Abschalten einer Ebene.

#### 2.1.1 HttpKernel-Events

**Konkreter Vorschlag — drei Events:**

| Event | Warum relevant für IDS | Konkrete Felder |
|---|---|---|
| `kernel.request` | Jede eingehende Anfrage; Basis für Muster wie Scanning, ungewöhnliche Routen, Parameter-Manipulation | Zeitstempel, HTTP-Methode, Pfad/URI, Query-Parameter, Route (falls zu diesem Zeitpunkt bereits aufgelöst), Client-IP (aus Request-Objekt), Content-Length, User-Agent-Header, ausgewählte weitere Header (z. B. `Referer`), Request-ID (selbst erzeugt zur Korrelation) |
| `kernel.exception` | Exceptions sind ein klassischer Indikator für Exploit-Versuche (unerwartete Eingaben, Type-Errors, Fatal-Fehler durch manipulierte Payloads) | Zeitstempel, Exception-Klasse (FQCN), Exception-Message (ggf. gekürzt/redigiert), abgeleiteter HTTP-Statuscode, Pfad, Content-Length, Request-ID (Korrelation zu `kernel.request`) |
| `kernel.response` | Antwortverhalten (Statuscode-Verteilung, Response-Zeit) als Baseline/Anomalie-Signal | Zeitstempel, HTTP-Statuscode, Response-Zeit (Differenz zu `kernel.request`), Response-Größe, Pfad, Route, Request-ID |

`kernel.controller` und `kernel.terminate` werden bewusst nicht als eigene Sensor-Events geführt — ersteres liefert keine über `kernel.request` hinausgehende sicherheitsrelevante Information, letzteres ist redundant zu `kernel.response`.

#### 2.1.2 Security-Component-Events

**Konkreter Vorschlag — drei Events:**

| Event | Warum relevant für IDS | Konkrete Felder |
|---|---|---|
| `security.authentication.success` | Basis für Login-Muster, Session-Beginn | Zeitstempel, Benutzerkennung, Firewall-Name, Client-IP, Request-ID |
| `security.authentication.failure` | Klassischer Indikator für Brute-Force/Credential-Stuffing | Zeitstempel, versuchte Benutzerkennung (falls vorhanden), Fehlergrund/Exception-Typ (z. B. `BadCredentialsException`), Firewall-Name, Client-IP, Request-ID |
| `security.access_decision` — Autorisierungsentscheidung (Voter-Ergebnis, z. B. via `AuthorizationCheckerInterface`/`security.access_denied_url`-Listener) | Erkennung von Rechteausweitungsversuchen (Zugriff auf fremde Ressourcen) | Zeitstempel, Benutzerkennung, angefragtes Attribut/Recht, angefragte Ressource (Identifier, kein vollständiges Objekt), Entscheidung (granted/denied), Request-ID |

#### 2.1.3 Business-/Domain-Events

**Konkreter Vorschlag:** Da Business-Events pro Projekt naturgemäß unterschiedlich sind, wird kein fester Event-Katalog definiert, sondern ein **fester Vertrag (Interface)**, den jede Anwendung selbst implementiert, um Events an den Business-Sensor anzubinden:

```php
interface SecurityRelevantBusinessEvent
{
    public function getEventName(): string;   // z. B. "order.payment_amount_overridden"
    public function getSeverityHint(): string; // "info" | "warning" | "critical"
    public function getActorId(): ?string;     // z. B. Benutzerkennung
    public function getPayload(): array;       // event-spezifische Kernfelder, projektdefiniert
}
```

Der Business-Sensor abonniert generisch alle Events, die dieses Interface implementieren (z. B. via Symfony-EventDispatcher-Tagging), unabhängig vom konkreten Event-Inhalt. Damit bleibt Abschnitt 2.1.3 projektunabhängig, ohne auf feste Business-Events verzichten zu müssen — die Generizität liegt im Vertrag, nicht im Inhalt.

**Empfohlene Business-/Domain-Events**

Das Interface legt fest, *wie* Business-Events aussehen, aber nicht, *wofür* sie erzeugt werden. Ohne Orientierung bleibt die Business-Ebene in der Praxis leer — und damit die gesamte dritte Beobachtungsebene wirkungslos. Der folgende Katalog ist deshalb eine **Empfehlung**, welche Vorgangsklassen eine Anwendung instrumentieren sollte:

| # | Vorgangsklasse | Beispiel-Event | **Konsequenz bei Nichtimplementierung** |
|---|---|---|---|
| V1 | Änderung von Berechtigungen/Rollen | `user.roles_changed` | **Mass Assignment auf Rollenfelder (Szenario S6) bleibt vollständig unentdeckt.** Eine per Formular untergeschobene Rollenänderung ist auf Kernel-Ebene ein gültiger `200`-Request und erzeugt kein einziges Signal. |
| V2 | Änderung sicherheitsrelevanter Kontodaten | `user.email_changed`, `user.password_changed` | **Kontoübernahme wird nicht bemerkt.** Der klassische Ablauf einer Übernahme (Session stehlen → E-Mail ändern → Passwort zurücksetzen) läuft ohne dieses Event vollständig unsichtbar ab. |
| V3 | Zugriff auf Daten fremder Eigentümer | `resource.accessed_cross_owner` | **IDOR ohne Voter (Szenario S7) bleibt unentdeckt.** Fehlt der Voter, gibt es kein `denied`-Event — dieses Business-Event ist dann die einzige verbleibende Erkennungsmöglichkeit. |
| V4 | Wertverändernde Vorgänge oberhalb einer Schwelle | `order.amount_overridden`, `payment.refund_issued` | **Wirtschaftlicher Schaden durch Preis-/Betragsmanipulation wird nicht erkannt.** Ein auf 0,01 € manipulierter Bestellbetrag ist technisch ein einwandfreier Vorgang. |
| V5 | Massenoperationen | `export.bulk_generated`, `record.bulk_deleted` | **Datenabfluss über legitime Exportfunktionen bleibt unsichtbar.** Ein Massenexport unterscheidet sich auf Kernel-Ebene nicht von einem Einzelabruf. |
| V6 | Administrative Funktionen | `admin.action_performed` | **Missbrauch privilegierter Funktionen nach erfolgreichem Rechteerwerb wird nicht erfasst.** Ohne dieses Event fehlt der zweite Teil der Angriffskette (siehe Korrelationsregel X3). |

Der Katalog ist bewusst als **Vorgangsklassen** formuliert, nicht als feste Event-Namen — er bleibt damit projektunabhängig (Anforderung aus Abschnitt 1), gibt aber genug Struktur vor, dass die Business-Ebene nicht ungenutzt bleibt.

> **Wichtiger Hinweis zur Tragweite:** Jede nicht implementierte Vorgangsklasse ist kein gradueller Qualitätsverlust, sondern ein **vollständiger blinder Fleck** für die zugehörige Angriffsklasse. Die betroffenen Angriffe (S6, S7, S9) erzeugen auf Kernel- und Security-Ebene *keinerlei* Signal — sie sind aus Sicht der Anwendung fehlerfreie, autorisierte Vorgänge. Es gibt keine Ausweichmöglichkeit über verschärfte Kernel-Regeln: Wo kein Event erzeugt wird, kann keine Regel greifen. Anwendungen ohne Business-Instrumentierung erkennen zuverlässig nur *gescheiterte* Angriffe.


### 2.2 Normalisierungs-Mapping


Aufbauend auf den konkreten Events aus Abschnitt 2.1: Für jede Ebene wird festgelegt, wie die jeweiligen Rohfelder auf ein gemeinsames Set normalisierter Felder abgebildet werden. Ziel ist, dass die zentrale Sammelstelle unabhängig von der Herkunftsebene mit einer einheitlichen Struktur arbeiten kann.

#### 2.2.1 Gemeinsame genutzte Eigenschaften (bei allen Sensoren gleich)

- `schema_version` — Versionsnummer des normalisierten Event-Formats (siehe 3.)
- `event_id` — vom Normalisierer generierte UUID, eindeutig pro Event
- `layer` — fester Wert `"kernel"` / `"security"` / `"business"`, abhängig vom Baustein
- `event_severity` — bei Kernel/Security durch feste Regeln abgeleitet (siehe „Konkrete Ableitungsregeln für event_severity“), bei Business direkt aus `getSeverityHint()` übernommen
- `application_id` — Kennung der überwachten Anwendung, aus deren Konfiguration
- `instance_id` — Kennung des ausführenden Hosts/Containers
- `environment` — `"prod"` / `"staging"` / `"dev"`

##### Anwendungs- und Instanzkontext

Ohne diese drei Felder `application_id`, `instance_id` & `environment` kann eine gemeinsame Sammelstelle die Herkunft eines Events nicht bestimmen. Das hat unmittelbare Folgen für die Erkennung:

- Eine IP, die zwei Anwendungen besucht, wird bei jeder Schwellwertregel **doppelt gezählt** — Fehlalarme entstehen ohne Angriff.
- Last- und Testverkehr aus `staging` würde die Baselines der Anomalieschicht (4.3.5) verschieben und die Produktionserkennung unbrauchbar machen.
- Bei horizontaler Skalierung ließe sich nicht feststellen, ob ein Muster von einer Instanz oder verteilt auftritt.

> **Verbindliche Aggregationsregel:** Jede Aggregation und jeder Zeitfenster-Join in Abschnitt 4.3 erfolgt zwingend **innerhalb einer Kombination aus `application_id` und `environment`**. Regeln, die über diese Grenze hinweg aggregieren, sind fehlerhaft — auch wenn sie technisch funktionieren.

**Zusätzlich `received_at`:** `timestamp` wird von der Anwendung gesetzt und hängt damit an der Uhr des Anwendungsservers. Der Consumer setzt deshalb zusätzlich `received_at`. Die Differenz beider Werte macht Uhrendrift messbar — bei verteilten Instanzen sonst eine stille Fehlerquelle für alle Zeitfensterregeln, da ein nachlaufender Server Events in bereits ausgewertete Fenster einsortiert.
application_id

##### Konkrete Ableitungsregeln für event_severity

**Abgrenzung zur Alert-Severity:** `event_severity` ist eine **kontextfreie Vorbewertung des Einzelevents** durch den Normalisierer — sie kennt weder Häufungen noch Vorgeschichte. Die Erkennungsregeln aus Abschnitt 4.3 vergeben davon unabhängig eine eigene `alert_severity` und können dabei zu einer völlig anderen Einstufung kommen. Beispiel: Eine `kernel.response` mit Status 200 hat immer `event_severity = info`; betrifft sie den Pfad `/_profiler`, vergibt Regel R2b `alert_severity = critical`. Das ist kein Widerspruch, sondern die Trennung von Rohbewertung und Befund.

Gilt nur für Einzelevents (Bewertung ohne Kontext/Häufung — Muster über mehrere Events hinweg, z. B. wiederholte Login-Fehlversuche, sind Aufgabe der Erkennungslogik, nicht des Normalisierers).

| Event-Typ | Bedingung | `event_severity` |
|---|---|---|
| `kernel.request` | immer | `info` |
| `kernel.exception` | `http_status` 500–599 | `critical` |
| `kernel.exception` | `http_status` 400–499 | `warning` |
| `kernel.exception` | alle anderen Fälle | `info` |
| `kernel.response` | `http_status` 500–599 | `critical` |
| `kernel.response` | `http_status` ∈ {401, 403, 404, 429} | `warning` |
| `kernel.response` | übrige 4xx | `info` |
| `kernel.response` | 2xx/3xx | `info` |
| `security.authentication.success` | immer | `info` |
| `security.authentication.failure` | immer | `warning` |
| `security.access_decision` | `decision = "granted"` | `info` |
| `security.access_decision` | `decision = "denied"` | `warning` |
| Business-Event | — | direkt aus `getSeverityHint()` übernommen, keine eigene Ableitung |

`critical` wird als `event_severity` ausschließlich für Serverfehler (5xx) vergeben — alles, was auf ein tatsächliches Fehlverhalten der Anwendung hindeutet, nicht nur auf einen abgelehnten/nicht gefundenen Zugriff.

#### 2.2.2 Normalisierung Kernel-Ebene

| Normalisiertes Feld | Rohfeld (aus 2.1.1) |
|---|---|
| `timestamp` | Zeitstempel |
| `correlation_id` | Request-ID |
| `actor.ip` | Client-IP |
| `actor.user` (siehe „Nutzerkontext auf Kernel-Ebene“) | Benutzerkennung aus dem Security-Token, sofern zum Event-Zeitpunkt authentifiziert |
| `actor.session_id_hash` | Session-ID aus dem Request (gehasht, nie im Klartext) |
| `actor.client_fingerprint` | User-Agent + ausgewählte Header (gehasht) |
| `payload.*` (event-spezifisch, unverändert strukturiert übernommen) | HTTP-Methode, Pfad, Query-Parameter, Route, User-Agent, Content-Length |
| `payload.*` (bei `kernel.exception`) | Exception-Klasse, Exception-Message, Statuscode |
| `payload.*` (bei `kernel.response`) | Statuscode, Response-Zeit, Response-Größe |
| `event_type` | Event-Name (`kernel.request` / `kernel.exception` / `kernel.response`) |

##### Nutzerkontext auf Kernel-Ebene

**Entscheidung:** Der Kernel-Normalisierer setzt `actor.user` aus dem Security-Token, sofern zum Zeitpunkt des Events eine Authentifizierung vorliegt.

- Bei `kernel.request` ist der Token in der Regel **noch nicht** verfügbar (die Firewall greift erst später im Request-Lifecycle) → `actor.user` bleibt dort meist `null`.
- Bei `kernel.response` und `kernel.exception` ist der Token praktisch immer verfügbar → `actor.user` ist dort gesetzt.
- Ohne diese Zuordnung wären die nutzerbezogenen Kernel-Regeln B7, P1 und P2 nicht umsetzbar, da sie Kernel-Events pro Nutzer aggregieren. Sie stützen sich deshalb auf `kernel.response`, nicht auf `kernel.request`.

#### 2.2.3 Normalisierung Security-Ebene

| Normalisiertes Feld | Rohfeld (aus 2.1.2) |
|---|---|
| `timestamp` | Zeitstempel |
| `correlation_id` | Request-ID |
| `actor.ip` | Client-IP |
| `actor.user` | Benutzerkennung (angemeldet oder versucht) |
| `actor.session_id_hash` | Session-ID (gehasht) |
| `actor.client_fingerprint` | User-Agent + ausgewählte Header (gehasht) |
| `payload.*` | Firewall-Name, Fehlergrund, angefragtes Attribut/Ressource, Entscheidung |
| `event_type` | Event-Name |

#### 2.2.4 Normalisierung Business-Ebene

| Normalisiertes Feld | Rohfeld (aus 2.1.3, Interface) |
|---|---|
| `actor.user` | `getActorId()` |
| `actor.ip = null`, sofern nicht im Payload mitgeliefert | — (keine IP auf Business-Ebene garantiert) |
| `actor.session_id_hash`, `actor.client_fingerprint` (bei CLI-/Worker-Kontext `null`) | Session-Kontext aus dem laufenden Request, sofern vorhanden |
| `event_type` | `getEventName()` |
| `severity` | `getSeverityHint()` |
| `payload.*` (unverändert durchgereicht — Business-Sensor kennt die projektspezifische Struktur nicht) | `getPayload()` |

##### Bildung der Sitzungskontext-Felder

- **`session_id_hash`** — HMAC-SHA256 der Session-ID mit einem dedizierten, nur dem IDS bekannten Schlüssel (nicht `APP_SECRET`). Die Session-ID selbst wird **niemals** gespeichert: andernfalls wäre die Event-Datenbank selbst ein Session-Hijacking-Vektor und würde die Angriffsfläche vergrößern, die sie überwachen soll. Der Hash erfüllt seinen Zweck (Verkettung von Events derselben Sitzung) vollständig, ohne die Sitzung übernehmbar zu machen.
- **`client_fingerprint`** — SHA256 über eine feste, dokumentierte Feldfolge: `User-Agent`, `Accept-Language`, `Accept-Encoding`. Bewusst eine schmale, stabile Auswahl — je mehr Header einfließen, desto häufiger ändert sich der Fingerprint aus harmlosen Gründen und desto mehr False Positives erzeugt Regel B9.
- Beide Felder sind **nullable**: Bei zustandslosen API-Requests existiert keine Session, bei CLI-/Worker-Ausführung existiert kein HTTP-Kontext.

---

## 3. Normalisierungsformat

Aus dem Normalisierungs-Mapping aus Abschnitt 2.2 ergibt sich folgendes gemeinsame Event-Schema, das alle drei Bausteine an die zentrale Sammelstelle senden. Es ist verbindlich festgelegt, nicht mehr Vorschlag.

**Entscheidung:** Ein zusätzliches `raw`-Feld mit der unverarbeiteten Original-Nutzlast wird ins Format aufgenommen. Datenschutz- und Speicherfragen werden hier bewusst nachrangig behandelt — Priorität hat die Verfügbarkeit der Rohdaten für Nachanalyse.

```json
{
  "schema_version": 1,
  "event_id": "b3f1e6b0-6e3a-4c9a-9f2e-2a6a2f4b9c11",
  "timestamp": "2026-08-13T10:15:32.421Z",
  "layer": "kernel",
  "event_type": "kernel.exception",
  "correlation_id": "req-7f2a1c",
  "event_severity": "warning",
  "application_id": "shop-api",
  "instance_id": "web-03",
  "environment": "prod",
  "actor": {
    "user": null,
    "ip": "203.0.113.42",
    "session_id_hash": "a3f9c1d8e4b27a05",
    "client_fingerprint": "c71b04ae9f3d62"
  },
  "payload": {
    "exception_class": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException",
    "exception_message": "No route found for GET /wp-admin/setup-config.php",
    "http_status": 404
  },
  "raw": {
    "note": "unverarbeitete Original-Nutzlast des Rohevents, Struktur abhängig von event_type"
  }
}
```

**Pflichtfelder im übertragenen Event (immer vorhanden, unabhängig von der Ebene):**
`schema_version`, `event_id`, `timestamp`, `layer`, `event_type`, `correlation_id`, `event_severity`, `application_id`, `instance_id`, `environment`, `actor.user`, `actor.ip`, `actor.session_id_hash`, `actor.client_fingerprint`

`raw` ist **kein Pflichtfeld** mehr: Es wird nur für Events mit `event_severity` in (`warning`, `critical`) sowie für alle Events übertragen und gespeichert, die einen Alert ausgelöst haben (Begründung: siehe „Volumenbudget und gestufte Retention“). `received_at` wird nicht vom Sensor, sondern vom Consumer gesetzt (siehe „Anwendungs- und Instanzkontext“) und ist deshalb nicht Teil des übertragenen Events.

Die vier `actor.*`-Felder sind **immer vorhanden, aber nullable** — je nach Ebene und Ausführungskontext ist nicht jeder Wert bestimmbar (siehe „Bildung der Sitzungskontext-Felder“).

**Variabler Teil:**
`payload` — Struktur abhängig von `event_type`; siehe Abschnitt 3.1. Immer ein flaches oder maximal zweistufig verschachteltes JSON-Objekt.

### 3.1 Payload-Format pro Ebene / Events

#### 3.1.1 Kernel-Ebene / -Events

##### `kernel.request`
```json
{
  "method": "GET",
  "path": "/api/orders/42",
  "query": { "expand": "items" },
  "route": "app_order_show",
  "user_agent": "Mozilla/5.0 ...",
  "referer": null,
  "content_length": 0
}
```
- `query`: flaches Objekt aus den Query-Parametern (keine Arrays von Arrays; verschachtelte Query-Strukturen werden auf einer Ebene abgeflacht bzw. als String belassen)
- `route`: `null`, falls zum Zeitpunkt von `kernel.request` noch nicht aufgelöst

##### `kernel.exception`
```json
{
  "exception_class": "Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException",
  "exception_message": "No route found for GET /wp-admin/setup-config.php",
  "http_status": 404,
  "path": "/wp-admin/setup-config.php",
  "content_length": 0
}
```
- `exception_message`: auf 500 Zeichen gekürzt, um übergroße Payloads zu vermeiden
- `path` und `content_length` werden aus dem zugehörigen Request **redundant übernommen** (siehe 3.2)

##### `kernel.response`
```json
{
  "http_status": 200,
  "response_time_ms": 42,
  "response_size_bytes": 1523,
  "path": "/api/orders/42",
  "route": "app_order_show"
}
```
- `path` und `route` werden aus dem zugehörigen Request **redundant übernommen** (siehe 3.2)

#### 3.1.2 Security-Ebene / -Events

##### `security.authentication.success`
```json
{
  "firewall": "main",
  "authenticator": "form_login"
}
```
- `authenticator`: Kurzname des verwendeten Authenticators (z. B. `form_login`, `api_token`, `json_login`)

##### `security.authentication.failure`
```json
{
  "firewall": "main",
  "failure_reason": "BadCredentialsException"
}
```
- Die versuchte Benutzerkennung steht in `actor.user` (siehe 2.2.3), nicht im Payload — Vermeidung von Redundanz

##### `security.access_decision`
```json
{
  "attribute": "ROLE_ADMIN",
  "resource": "Order#42",
  "decision": "denied"
}
```
- `resource`: Identifier-String (`Klasse#ID`), niemals das vollständige Objekt
- `decision`: `"granted"` oder `"denied"`

#### 3.1.3 Business-Ebene / -Events

##### Generische Business-Events
Für Business-Events wird **keine feste Payload-Struktur** vorgegeben — `payload` entspricht 1:1 dem Rückgabewert von `getPayload()` aus dem Interface (siehe 2.1.3) und ist damit projektspezifisch. Empfehlung für Projekte, die das Interface implementieren:
- flache Struktur (keine verschachtelten Objekte/Entities)
- nur primitive Typen (string, number, bool, null) als Werte
- Feldnamen `snake_case`, konsistent zu den übrigen Ebenen

Beispiel (projektspezifisch, nicht Teil des generischen Konzepts):
```json
{
  "order_id": 42,
  "original_amount": 19.99,
  "overridden_amount": 0.01
}
```

### 3.2 Bewusste Feldredundanz zwischen Request- und Folge-Events

**Entscheidung:** `path` (und bei `kernel.response` zusätzlich `route`, bei `kernel.exception` zusätzlich `content_length`) werden aus dem ursprünglichen Request in die Folge-Events **kopiert**, obwohl sie dort fachlich bereits über die `correlation_id` auffindbar wären.

**Begründung:** Nahezu alle Batch-Regeln (B1, B7, S10-Muster) aggregieren Statuscodes *und* Pfade gemeinsam. Ohne Redundanz bräuchte jede dieser Abfragen einen Self-Join auf `kernel.request` über die `correlation_id` — bei Millionen Events pro Partition der teuerste Teil der Abfrage. Der Mehrverbrauch an Speicher (wenige hundert Byte pro Event) wird bewusst in Kauf genommen, um die Erkennungsabfragen einfach und schnell zu halten.

**Konsequenz für die Implementierung:** Der Kernel-Sensor muss den Request-Kontext über den gesamten Request-Lifecycle mitführen (z. B. in einem Request-Scoped Service), damit `kernel.response` und `kernel.exception` darauf zugreifen können.

---

## 4. IdsBackendBundle - Zentrale Sammelstelle

Ist der entscheidende Teil einer späteren Backend-Anwendung. Diese Anwendung wird außerdem ein Dashboard mit allen Alerts, aufgetretenen Anomalien, sowie einen Einblick in die Live-Daten enthalten. Sie wird dem Nutzer Einsicht in den Status seiner Systeme geben können und weitere Funktionen bieten, wie das Einrichten neuer Applications (Symfony-Projekte inkl. IdsSensorBundle). Die genaue Funktion und Umsetzung wird später in einem anderen Konzept definiert.

**Entscheidung:** PostgreSQL als Datenhaltung. Feste Felder des normalisierten Schemas (Abschnitt 3) werden als eigene, indexierbare Spalten geführt; `payload` und `raw` bleiben als JSONB, da sie strukturell variabel sind.

**Der Collector nutzt DBAL direkt, kein Doctrine ORM.** Partitionierte Tabellen, eine `UNION ALL`-View als Leseziel, JSONB, Upserts mit `ON CONFLICT` und `pg_partman` sind mit ORM-Entities nicht sinnvoll abbildbar. Migrationen als reines SQL.

**Grundsatzentscheidung: fail-open.** Eine Störung des IDS darf die überwachte Anwendung unter keinen Umständen beeinträchtigen. Ein IDS, das bei eigenem Ausfall Requests blockiert, wird nach dem ersten Vorfall abgeschaltet — und ist damit dauerhaft wirkungslos.

Konkret im Sensor:

- Der Dispatch an den Transport läuft in `try/catch`; Fehler werden **nie** an die Anwendung propagiert.
- Hartes Timeout von 50 ms; danach Abbruch des Versands, der Request läuft normal weiter.
- Bei nicht erreichbarem Broker: lokaler Datei-Spool als Puffer, begrenzt auf eine feste Maximalgröße. Ist der Puffer voll, werden weitere Events **verworfen** statt gepuffert — unbegrenztes Puffern würde den Plattenplatz der Anwendung erschöpfen und aus einer IDS-Störung einen Anwendungsausfall machen.

**Zustellgarantie: at-least-once.** Duplikate sind damit möglich und werden im Consumer abgefangen:

```sql
INSERT INTO events (...) VALUES (...)
ON CONFLICT (event_id, "timestamp") DO NOTHING;
```

**Der Consumer schreibt in einer Transaktion** in `events` und — sofern zutreffend (siehe „Volumenbudget und gestufte Retention“) — `events_raw`. Ein teilweise geschriebenes Event darf nicht entstehen.

> **Restrisiko, das aus fail-open folgt:** Ein Angreifer kann die Erkennung abschalten, indem er den Broker oder Consumer überlastet, und den eigentlichen Angriff anschließend unbeobachtet ausführen. Denial of Service gegen das IDS wird damit zu einem sinnvollen Vorbereitungsschritt. Die Gegenmaßnahme ist keine technische Härtung des Transports, sondern **Sichtbarkeit**: Jeder verworfene oder verlorene Event wird gezählt und löst ab einem Schwellwert einen eigenen Alert aus (`rule_id = "ids.event_loss"`, siehe 4.2.4). Ein stiller Ausfall ist gefährlicher als ein sichtbarer, weil er Schutz suggeriert, den es nicht gibt.

### 4.1 Consumer

Liest die normalisierten Events vom Message Broker (siehe 2.1) und schreibt sie unverändert in die Datenbank; keine weitere Transformation, reines Mapping der bereits normalisierten Top-Level-Felder auf Spalten. Er entscheidet anhand von `event_severity`, in welche Event-Tabelle geschrieben wird, setzt `received_at` und führt die Echtzeitregeln aus (4.3.1)

### 4.2 PostgreSQL-Datenbank

die Event-Tabellen `events_relevant` und `events_info` (strukturgleich, getrennt wegen gestufter Retention, gemeinsam abfragbar über die View `events`), die Rohdatentabelle `events_raw` (nur selektiv gefüllt) sowie die Auswertungstabellen `alerts` und `metric_baselines` (siehe 4.5)

#### 4.2.1 Tabellenschema

```sql
CREATE TYPE layer_type AS ENUM ('kernel', 'security', 'business');
CREATE TYPE severity_level AS ENUM ('info', 'warning', 'critical');

CREATE TYPE env_type AS ENUM ('prod', 'staging', 'dev');

CREATE TABLE events_relevant (
    schema_version      SMALLINT NOT NULL,
    event_id            UUID NOT NULL,
    "timestamp"         TIMESTAMPTZ NOT NULL,
    received_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    layer               layer_type NOT NULL,
    event_type          TEXT NOT NULL,
    correlation_id      TEXT NOT NULL,
    event_severity      severity_level NOT NULL,
    application_id      TEXT NOT NULL,
    instance_id         TEXT NOT NULL,
    environment         env_type NOT NULL,
    actor_user          TEXT,
    actor_ip            INET,
    actor_session_hash  TEXT,
    actor_fingerprint   TEXT,
    payload             JSONB NOT NULL,
    PRIMARY KEY (event_id, "timestamp")
) PARTITION BY RANGE ("timestamp");

CREATE TABLE events_info (LIKE events_relevant INCLUDING ALL)
    PARTITION BY RANGE ("timestamp");

CREATE VIEW events AS
    SELECT * FROM events_relevant
    UNION ALL
    SELECT * FROM events_info;

CREATE TABLE events_raw (
    event_id        UUID NOT NULL,
    "timestamp"     TIMESTAMPTZ NOT NULL,
    raw             JSONB NOT NULL,
    PRIMARY KEY (event_id, "timestamp")
) PARTITION BY RANGE ("timestamp");
```

**Aufteilung in zwei Tabellen:** `raw` ist mit Abstand am datenintensivsten, aber am schnellsten irrelevant (nur für kurzfristige forensische Nachanalyse). Die Trennung von `events` (kompakt, normalisiert) und `events_raw` (groß, kurzlebig) ermöglicht unterschiedliche Retention-Zeiträume je Tabelle (siehe 4.2.3). Der Consumer schreibt beim Einfügen eines Events in beide Tabellen (gleiche `event_id`/`timestamp`, eine Transaktion).

- `PRIMARY KEY (event_id, "timestamp")` statt nur `event_id` — bei partitionierten Tabellen muss der Partitionierungsschlüssel (`timestamp`) Teil jedes Unique/Primary-Key-Constraints sein
- `actor_ip` als `INET` statt `TEXT` — ermöglicht später IP-Bereichsabfragen (z. B. Subnetz-Filter) direkt in SQL
- `actor_session_hash` / `actor_fingerprint` als Top-Level-Spalten (nicht im `payload`) — sie werden von den Regeln B8/B9 über Event-Typen hinweg verglichen und müssen dafür indizierbar sein; ein JSONB-Zugriff wäre hier deutlich teurer
- `application_id`, `instance_id`, `environment` als `NOT NULL` — die Aggregationsregel aus „Anwendungs- und Instanzkontext“ setzt voraus, dass jedes Event zugeordnet ist; ein `NULL` würde stillschweigend über Anwendungsgrenzen hinweg aggregieren
- `received_at` mit `DEFAULT now()` — wird beim Schreiben vom Consumer gesetzt, nicht vom Sensor übertragen (Uhrendrift-Erkennung, siehe „Anwendungs- und Instanzkontext“)

**Aufteilung in `events_relevant` und `events_info`:** Beide Tabellen sind strukturgleich; die Trennung dient allein der gestuften Retention (siehe „Volumenbudget und gestufte Retention“). Der Consumer entscheidet beim Schreiben anhand von `event_severity`, in welche Tabelle das Event geht. Die View `events` fasst beide zusammen — alle Regelabfragen aus Abschnitt 4.3 arbeiten gegen die View und müssen die Aufteilung nicht kennen. Für Regeln, die ausschließlich relevante Events betrachten (die meisten), ist die direkte Abfrage von `events_relevant` die schnellere Variante.
- `layer` und `event_severity` als ENUM statt freiem Text — verhindert Tippfehler/Inkonsistenzen bei festen Wertebereichen
- `event_type` bleibt `TEXT` (nicht ENUM), da Business-Ebene beliebige, projektdefinierte Event-Namen liefert

#### 4.2.2 Indizierung

Die Indizes werden auf **beiden** Event-Tabellen angelegt (hier am Beispiel `events_relevant`; für `events_info` identisch, da über `LIKE ... INCLUDING ALL` übernommen):

```sql
CREATE INDEX idx_evr_timestamp ON events_relevant ("timestamp");
CREATE INDEX idx_evr_correlation_id ON events_relevant (correlation_id);
CREATE INDEX idx_evr_layer_event_type ON events_relevant (layer, event_type);
CREATE INDEX idx_evr_actor_ip ON events_relevant (actor_ip);
CREATE INDEX idx_evr_scope ON events_relevant (application_id, environment, "timestamp");
CREATE INDEX idx_evr_actor_user_ts ON events_relevant (actor_user, "timestamp");
CREATE INDEX idx_evr_session_hash ON events_relevant (actor_session_hash);
CREATE INDEX idx_evr_payload_gin ON events_relevant USING GIN (payload);
```

- `idx_evr_correlation_id`: ermöglicht das Zusammenführen aller Events einer einzelnen Anfrage über alle drei Ebenen hinweg (z. B. `kernel.request` + `security.authentication.failure` + `kernel.response` mit derselben `correlation_id`)
- `idx_evr_scope`: Grundlage der verbindlichen Aggregationsregel aus „Anwendungs- und Instanzkontext“ — jede Regelabfrage filtert zuerst auf Anwendung und Umgebung
- `idx_evr_actor_user_ts`: zusammengesetzter Index für die nutzerbezogenen Zeitfenster-Regeln (B4, B7, X2, X3, P1–P3), die durchgängig nach `actor_user` innerhalb eines Zeitraums filtern
- `idx_evr_session_hash`: Grundlage für die sitzungsbezogenen Regeln B8/B9 (Kontextwechsel innerhalb einer Sitzung)
- GIN-Index auf `payload`: erlaubt spätere Abfragen auf einzelne Payload-Felder (z. B. `payload->>'http_status'`), ohne dass jedes mögliche Feld vorab bekannt sein muss
- `events_raw` erhält keine eigenen Indizes über `event_id`/`timestamp` hinaus — die Tabelle wird ausschließlich für gezielte Einzelabfragen per `event_id` genutzt, nicht für Analysen

#### 4.2.3 Retention & Partitionierung

##### Volumenbudget und gestufte Retention

Die Retention muss aus dem tatsächlichen Datenvolumen abgeleitet werden, nicht umgekehrt. Rechnung für eine Anwendung mit **50 Requests/s** (≈ 4,3 Mio. Requests/Tag, ~3 Events pro Request, also ~13 Mio. Events/Tag):

| Tabelle | Größe je Zeile | pro Tag | bei ungestufter Retention |
|---|---|---|---|
| `events` | ~600 B | ~7,8 GB | **~2,8 TB** bei 12 Monaten |
| `events_raw` | ~3 KB | ~39 GB | **~1,2 TB** bei 30 Tagen |

**Befund:** Eine pauschale Aufbewahrung ist bei mittlerem Traffic nicht tragfähig. Drei Korrekturen, die das Volumen um mehr als eine Größenordnung senken, ohne Erkennungsfähigkeit zu verlieren:

**1. `raw` nur selektiv erfassen.** Rohdaten werden ausschließlich für Events mit `event_severity` in (`warning`, `critical`) sowie für alle Events übertragen, die einen Alert ausgelöst haben. Das entfernt über 95 % des `raw`-Volumens und trifft genau die Events, die forensisch überhaupt in Frage kommen. Keine Regel aus Abschnitt 4.3 liest `raw` — es dient allein der manuellen Nachanalyse (siehe auch 4.5).

**2. Gestufte Retention nach `event_severity`.** `info`-Events tragen die Masse des Volumens, aber kaum Erkenntniswert über Wochen hinaus:

| Kategorie | Retention |
|---|---|
| `events` mit `event_severity = info` | 30 Tage |
| `events` mit `warning` / `critical` | 12 Monate |
| `events_raw` (nur selektiv erfasst) | 30 Tage |

Umsetzung: `info`-Events und relevante Events werden in getrennte, jeweils monatlich partitionierte Tabellen geschrieben (`events_info`, `events_relevant`), über eine gemeinsame View `events` abfragbar. Sub-Partitionierung nach ENUM-Wert innerhalb einer Zeitpartition wäre die Alternative, ist in `pg_partman` aber deutlich aufwändiger zu verwalten.

**3. Baselines aus Aggregaten statt aus Rohevents.** Die Anomalieschicht (4.3.5) braucht Stundenzähler, keine Einzelevents über zwölf Monate. Ein täglicher Aggregationslauf schreibt die Kennzahlen nach `metric_baselines`; die Langzeithaltung von `info`-Events wird dadurch überflüssig — genau deshalb sind 30 Tage dort ausreichend.

**Sampling als Reservemaßnahme:** Reicht das nicht (Anwendungen deutlich über 50 Req/s), werden `info`-Events auf Sensorebene gesampelt, z. B. jedes n-te `kernel.request`. `warning`/`critical`-Events und alle Security- und Business-Events werden **nie** gesampelt. Sampling verfälscht Baselines und muss daher als Sampling-Rate im Event mitgeführt werden, damit Aggregate hochgerechnet werden können.

##### Partitionierung mit pg_partman

**Entscheidung:** Alle drei Event-Tabellen werden über die Erweiterung **`pg_partman`** monatlich partitioniert und automatisiert bereinigt, mit den in „Volumenbudget und gestufte Retention“ abgeleiteten Aufbewahrungsdauern:

| Tabelle | Retention | Begründung |
|---|---|---|
| `events_info` | 30 Tage | Massenvolumen, Erkenntniswert nur kurzfristig; Langzeitauswertung läuft über `metric_baselines` |
| `events_relevant` | 12 Monate | `warning`/`critical` — kompakt, Basis für Langzeitauswertung und Korrelation |
| `events_raw` | 30 Tage | groß, nur selektiv erfasst, ausschließlich für forensische Nachanalyse |

**Einrichtung:**

```sql
CREATE EXTENSION IF NOT EXISTS pg_partman;

SELECT partman.create_parent(
    p_parent_table := 'public.events_info',
    p_control       := 'timestamp',
    p_type          := 'range',
    p_interval      := '1 month',
    p_premake       := 3
);
UPDATE partman.part_config
SET retention = '30 days', retention_keep_table = false
WHERE parent_table = 'public.events_info';

SELECT partman.create_parent(
    p_parent_table := 'public.events_relevant',
    p_control       := 'timestamp',
    p_type          := 'range',
    p_interval      := '1 month',
    p_premake       := 3
);
UPDATE partman.part_config
SET retention = '12 months', retention_keep_table = false
WHERE parent_table = 'public.events_relevant';

SELECT partman.create_parent(
    p_parent_table := 'public.events_raw',
    p_control       := 'timestamp',
    p_type          := 'range',
    p_interval      := '1 month',
    p_premake       := 3
);
UPDATE partman.part_config
SET retention = '30 days', retention_keep_table = false
WHERE parent_table = 'public.events_raw';
```

- `retention_keep_table = false`: abgelaufene Partitionen werden per `DROP TABLE` entfernt (nicht nur geleert) — kein Bloat, kein `VACUUM`-Bedarf
- `p_premake := 3`: `pg_partman` legt automatisch die nächsten drei Monatspartitionen im Voraus an
- **Wichtig:** `pg_partman` benötigt einen regelmäßig laufenden Wartungsjob (`partman.run_maintenance_proc()`), der die eigentliche Erstellung/Bereinigung der Partitionen auslöst — üblich über die Erweiterung `pg_cron`, alternativ ein externer Scheduler, der die Prozedur periodisch aufruft (z. B. stündlich)

#### 4.2.4 Auswertungstabellen

Neben den Event-Tabellen hält die Sammelstelle zwei Tabellen für die Ergebnisse der Detection (4.3). Sie stehen hier, damit alle Datenbankobjekte an einer Stelle definiert sind.

```sql
CREATE TYPE alert_source AS ENUM ('realtime', 'batch', 'positive_path', 'anomaly');

CREATE TABLE alerts (
    alert_id            UUID PRIMARY KEY,
    dedup_key           TEXT NOT NULL,
    first_seen          TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen           TIMESTAMPTZ NOT NULL DEFAULT now(),
    occurrence_count    INTEGER NOT NULL DEFAULT 1,
    rule_id             TEXT NOT NULL,        -- z. B. "R3", "B1", "X2", "anomaly:login_failure_rate"
    alert_severity      severity_level NOT NULL,
    source              alert_source NOT NULL,
    application_id      TEXT NOT NULL,
    environment         env_type NOT NULL,
    knowledge_version   TEXT,                 -- Stand der Pfad-Wissensbasis (4.3.1), bei R2/R2b/X4
    correlation_ids     TEXT[],               -- betroffene correlation_id(s), falls vorhanden
    actor_user          TEXT,
    actor_ip            INET,
    actor_session_hash  TEXT,
    description         TEXT NOT NULL,
    details             JSONB                 -- regel-spezifische Zusatzinfos (Zählerstand, Baseline-Wert)
);

CREATE UNIQUE INDEX idx_alerts_dedup ON alerts (dedup_key);

CREATE TABLE metric_baselines (
    metric_name     TEXT NOT NULL,
    bucket          SMALLINT NOT NULL,        -- z. B. Stunde des Tages (0–23)
    mean            DOUBLE PRECISION NOT NULL,
    stddev          DOUBLE PRECISION NOT NULL,
    sample_count    INTEGER NOT NULL,
    computed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (metric_name, bucket)
);

CREATE INDEX idx_alerts_created_at ON alerts (created_at);
CREATE INDEX idx_alerts_rule_id ON alerts (rule_id);
CREATE INDEX idx_alerts_actor_ip ON alerts (actor_ip);
```

**Hinweise:**

- `alert_source` ist ein ENUM über alle **vier** Schichten aus 4.3 — `positive_path` war in einer früheren Fassung nicht enthalten.
- Die Tabelle nimmt auch **Befunde über das IDS selbst** auf, etwa `rule_id = "ids.event_loss"` bei verworfenen Events (Abschnitt 4). Das ist bewusst dieselbe Tabelle: Ein Erkennungsausfall ist ein sicherheitsrelevanter Befund und darf nicht in einem separaten Betriebskanal untergehen.
- `knowledge_version` erfüllt die Zusage aus 4.3.1, den Stand der Pfad-Wissensbasis im Alert mitzuführen; bei Regeln ohne Bezug zur Wissensbasis bleibt das Feld `null`.
- `actor_session_hash` ist ergänzt, damit sitzungsbezogene Alerts (B8/B9) ohne Rückgriff auf `details` auswertbar sind.
- Alerts referenzieren die auslösenden Events **nicht** per Fremdschlüssel auf `event_id` (ein Alert kann auf mehreren/aggregierten Events beruhen), sondern lose über `correlation_ids`/`actor_ip`/`actor_user`/`actor_session_hash`, die bei Bedarf gegen `events` nachgeschlagen werden können.

### 4.3 Detection

Vier Schichten:

| Schicht | Wann | Datenquelle | Zweck |
|---|---|---|---|
| **Echtzeit-Regeln** | pro Event, sofort | In-Memory-Zähler (Redis o. ä.) + Event selbst | Einfache, günstige Prüfungen ohne DB-Query — sofortige Reaktion |
| **Periodische Regeln** | alle 1–5 Min. (Batch) | SQL-Aggregation auf `events` | Aufwändigere/aggregierte Muster, auch ebenenübergreifend über `correlation_id` |
| **Positivpfad-Regeln** | periodisch | `events` + Nutzer-Baseline | Prüfung *erfolgreicher* Vorgänge auf Plausibilität (siehe 4.3.4) |
| **Anomalie-Ergänzung** | periodisch (z. B. täglich Baseline, dann laufender Vergleich) | historische `events`-Daten | Erkennung unbekannter Abweichungen ohne feste Regel |

Neue Komponente: **Redis** als In-Memory-Store für die Echtzeit-Zähler — analog zum Message Broker ein reines Implementierungs-Hilfsmittel (siehe Scope-Präzisierung, Abschnitt 1), kein Monitoring-Ziel. Wird Redis bereits als Messenger-Transport eingesetzt (2.1), kann dieselbe Instanz genutzt werden; die Zählerschlüssel sind dann durch ein eigenes Präfix zu trennen.

Neue Komponente: **Detection Job** (periodisch, z. B. Symfony-Console-Command per Cron/systemd-Timer) für die periodischen, positivpfad- und anomaliebasierten Regeln — getrennt vom Consumer, da andere Ausführungsfrequenz und andere Datenquelle (DB statt Stream).

Neue Komponente: **Pfad-Wissensbasis** (`known_paths.yaml`, siehe 4.3.1) — die konfigurierbare Liste Symfony-spezifischer Pfade, gegen die der Consumer im Echtzeitpfad prüft.

Ergebnis aller Schichten: Einträge in der Tabelle `alerts` (Schema siehe 4.2.4).

#### 4.3.1 Echtzeit-Regeln (pro Event, im Consumer)

| Regel | Ebene | Bedingung | Auslöser |
|---|---|---|---|
| R1 | Kernel | `kernel.exception` mit `event_severity = critical` (5xx), **sofern keine spezifischere Regel (R6, R7) gegriffen hat** | jede einzelne Instanz — kein Schwellwert nötig, Serverfehler ist immer relevant |
| R2 | Kernel | `kernel.request.path` matcht einen Eintrag der Pfad-Wissensbasis (siehe unten) | signaturbasierte Erkennung von Scan-/Sondierungsversuchen; `alert_severity` aus der Kategorie |
| R2b | Kernel | Pfad-Wissensbasis-Treffer **und** zugehörige `kernel.response` mit `http_status = 200` | **bestätigte Exposition** — `alert_severity` aus `severity_if_status_200` der Kategorie, in der Regel `critical` |
| R3 | Security | `security.authentication.failure`-Zähler pro `actor_ip` in Redis (Schlüssel `authfail:{ip}`, TTL 60s) übersteigt 5 | Brute-Force-Verdacht |
| R4 | Security | Autorisierung `denied`-Zähler pro `actor_user` in Redis (TTL 60s) übersteigt 5 | Rechteausweitungsversuch |
| R5 | Business | jedes Event mit `event_severity = critical` (aus `getSeverityHint()`) | sofort — Business-Ebene bewertet ihre Kritikalität selbst, wird vertraut |
| R6 | Kernel | `kernel.exception` mit `exception_class` beginnend `Twig\` | Template-Injection-Verdacht (S4) — eine Twig-Syntaxfehler zur Laufzeit bedeutet praktisch immer, dass Nutzereingabe in einen Template-Kontext gelangt ist |
| R7 | Kernel | `kernel.exception` mit `exception_class` im Namensraum `Symfony\Component\Serializer\Exception\` | Deserialisierungs-Verdacht (S5); `critical` statt `warning`, wenn zusätzlich `payload.content_length` einen Schwellwert überschreitet |

R3/R4 sind bewusst noch "Echtzeit", weil sie ausschließlich einen Redis-`INCR`+TTL-Check brauchen (kein DB-Zugriff) — das hält die Latenz im Consumer niedrig.

**Vorrangregel (Vermeidung von Doppelalarmen):** R1 ist eine **Auffangregel**. Ein Twig-Fehler mit Status 500 erfüllt gleichzeitig R1 und R6, ein Serializer-Fehler gleichzeitig R1 und R7 — ohne Vorrang entstünden pro Event zwei Alerts mit unterschiedlicher Aussagekraft. Deshalb gilt: Pro Event wird **genau ein** Alert erzeugt, und zwar von der spezifischsten zutreffenden Regel. Auswertungsreihenfolge: R6/R7 (spezifische Exception-Klassen) vor R1 (generisches 5xx). R2/R2b und R3–R5 betreffen andere Event-Typen und kollidieren nicht.

**Warum R7 eng gefasst ist:** Ursprünglich war R7 auch auf `TypeError`/`Error` vorgesehen. Diese Klassen treten in jeder realen Anwendung durch gewöhnliche Programmierfehler auf — die Regel hätte dauerhaft Fehlalarme erzeugt und wäre nach kurzer Zeit ignoriert worden. R7 beschränkt sich deshalb auf den Serializer-Namensraum, der außerhalb von Deserialisierungskontexten praktisch nicht auftritt.

**Umsetzungshinweis zu R2b:** `kernel.request` und `kernel.response` sind zwei getrennte Events. Der Consumer hält den Pfadlisten-Treffer daher kurzzeitig in Redis unter der `correlation_id` (TTL ~30s) vor und wertet ihn aus, sobald das zugehörige `kernel.response`-Event eintrifft.

**Pfad-Wissensbasis (`known_paths.yaml`)**

Die Pfadliste ist der Ort, an dem Symfony-spezifisches Framework-Wissen ins System einfließt. Sie wird deshalb **nicht hartkodiert**, sondern als deklarative, versionierte Konfigurationsdatei außerhalb des Codes geführt und zur Laufzeit geladen:

```yaml
# config/ids/known_paths.yaml
version: "2026-08-13"
categories:
  framework_internals:
    description: "Symfony-interne Endpunkte, in Produktion nie legitim erreichbar"
    default_severity: warning
    severity_if_status_200: critical
    patterns:
      - { match: prefix, value: "/_profiler" }
      - { match: prefix, value: "/_wdt" }
      - { match: exact,  value: "/_fragment" }
      - { match: exact,  value: "/app_dev.php" }
      - { match: exact,  value: "/config.php" }

  exposed_configuration:
    description: "Konfigurations-/Metadateien, die bei falschem Document Root erreichbar werden"
    default_severity: warning
    severity_if_status_200: critical
    patterns:
      - { match: exact,  value: "/.env" }
      - { match: prefix, value: "/.env." }
      - { match: exact,  value: "/composer.json" }
      - { match: exact,  value: "/composer.lock" }
      - { match: prefix, value: "/.git/" }
      - { match: prefix, value: "/config/" }
      - { match: prefix, value: "/var/log/" }

  foreign_stack_probes:
    description: "Pfade fremder Technologien — in einer Symfony-App immer Scanning"
    default_severity: info
    severity_if_status_200: critical
    patterns:
      - { match: prefix, value: "/wp-admin" }
      - { match: prefix, value: "/wp-content" }
      - { match: exact,  value: "/xmlrpc.php" }
      - { match: prefix, value: "/typo3" }
```

**Designentscheidungen:**

- **Kategorien statt flacher Liste.** Ein `/wp-admin`-Treffer in einer Symfony-App ist Rauschen (Massenscanner, permanent, überall) — ein `/_profiler`-Treffer ist ein gezielter, informierter Zugriff. Beides gleich zu behandeln würde die Alerts mit Rauschen fluten und das eigentliche Signal begraben. Die Kategorie trägt deshalb die Severity, nicht der einzelne Pfad.
- **`severity_if_status_200` pro Kategorie.** Ein erfolgreicher Zugriff ist ein qualitativ anderer Befund als ein abgewiesener. Die Unterscheidung ist damit Teil der Konfiguration statt Sonderlogik im Code.
- **Explizite `match`-Strategie statt Regex.** `prefix`/`exact` sind ohne Regex-Engine auswertbar, damit ohne ReDoS-Risiko und deutlich schneller — relevant, weil diese Prüfung für *jeden* Request im Echtzeitpfad läuft. Ein `regex`-Typ kann später ergänzt werden, sollte dann aber auf die Batch-Ebene beschränkt bleiben.
- **`version`-Feld.** Wird im Alert mitgeführt, damit nachvollziehbar bleibt, gegen welchen Stand der Wissensbasis erkannt wurde.

**Betrieb:** Die Datei ist bewusst so gestaltet, dass sie ohne Codeänderung und ohne Deployment aktualisiert werden kann (Reload beim Start des Consumers bzw. per Signal). Ihre Pflege ist ein eigener, wiederkehrender Vorgang — analog zur Signaturpflege klassischer IDS —, kein Nebenprodukt der Anwendungsentwicklung.

#### 4.3.2 Periodische Regeln (Batch, alle 1–5 Minuten)

| Regel | Ebene | Bedingung (Beispiel-SQL-Logik) | Auslöser |
|---|---|---|---|
| B1 | Kernel | `actor_ip` mit >20 Events mit `http_status` 403/404 in 5 Min. über >5 unterschiedliche Pfade | Scanning-Verhalten |
| B2 | Kernel | `actor_ip` mit Gesamt-Requestrate >X/Min. (Schwellwert konfigurierbar) | mögliches DoS/Scraping |
| B3 | Security | dieselbe `actor_ip` mit Fehlversuchen gegen >5 unterschiedliche `actor_user` in 5 Min. | Credential Stuffing |
| B4 | Security | derselbe `actor_user` mit Fehlversuchen von >5 unterschiedlichen `actor_ip` in 5 Min. | Distributed Brute-Force |
| B5 | Security | `security.authentication.success` für `actor_ip`, die innerhalb der vorherigen 5 Min. ≥5 Fehlversuche hatte | erfolgreicher Login nach Brute-Force-Serie |
| B6 | Business | >3 Business-Events mit `event_severity = critical` desselben `event_type` von demselben `actor_user` in 10 Min. | Häufung kritischer Geschäftsvorgänge |
| B7 | Kernel | derselbe `actor_user` greift in 5 Min. auf >N numerisch benachbarte Ressourcen-Identifier desselben Typs zu — **unabhängig vom Statuscode** | IDOR-Enumeration (S7), auch bei erfolgreichen Zugriffen und damit auch bei fehlendem Voter |

**Beispielhafte SQL-Struktur für B1:**
```sql
SELECT actor_ip, count(*) AS hits, count(DISTINCT payload->>'path') AS distinct_paths
FROM events
WHERE application_id = :app
  AND environment = :env
  AND layer = 'kernel'
  AND event_type = 'kernel.response'
  AND (payload->>'http_status')::int IN (403, 404)
  AND "timestamp" > now() - interval '5 minutes'
GROUP BY actor_ip
HAVING count(*) > 20 AND count(DISTINCT payload->>'path') > 5;
```

Die Filterung auf `application_id` und `environment` ist keine Option, sondern Pflicht für jede Regelabfrage (siehe Aggregationsregel in „Anwendungs- und Instanzkontext“). Da `kernel.response` mit Status 403/404 die `event_severity` `warning` erhält (siehe „Konkrete Ableitungsregeln für event_severity“), könnte diese Abfrage auch direkt gegen `events_relevant` statt gegen die View laufen — schneller, weil die `info`-Partitionen dann gar nicht berührt werden.

#### 4.3.3 Ebenenübergreifende Korrelation

Regeln, die Events **verschiedener Ebenen oder verschiedener Anfragen** zusammenführen. Der verbindende Schlüssel ist je nach Regel unterschiedlich: `correlation_id` (eine Anfrage), `actor_user`, `actor_ip` oder `actor_session_hash`.

| Regel | Verknüpft über | Bedingung | Auslöser |
|---|---|---|---|
| X1 | `correlation_id` | `kernel.exception` und `security.authentication.failure` mit derselben `correlation_id` | Angriff, der gezielt den Login-Mechanismus selbst zum Fehlerverhalten bringt (z. B. Injection im Login-Formular) |
| X2 | `actor_user` | `security.access_decision` mit `denied` für ein `actor_user`/Ressourcen-Paar, gefolgt von erneutem Zugriffsversuch auf dieselbe Ressource innerhalb 2 Min. | Persistenzversuch trotz Ablehnung |
| X3 | `actor_user` | `security.authentication.success`, gefolgt von kritischem Business-Event desselben `actor_user` innerhalb 1 Min. | Login unmittelbar gefolgt von kritischer Aktion — potenzielles Konto-Übernahme-Muster |
| X4 | `actor_ip` | Treffer aus der Pfad-Wissensbasis (Kategorie `framework_internals` oder `exposed_configuration`), gefolgt von `/_fragment`-Zugriffen innerhalb 24 Std. | Fragment-Handler-Missbrauch mit geleaktem `APP_SECRET` (S3, Variante b) — der Einzelzugriff auf `/_fragment` ist bei gültiger Signatur unauffällig, erst die Vorgeschichte macht ihn verdächtig |
| B8 | `actor_session_hash` | Wechsel der `actor_ip` bei gleichem `actor_session_hash` ohne dazwischenliegendes `security.authentication.success` | Session-Übernahme (S9) |
| B9 | `actor_session_hash` | Wechsel des `actor_fingerprint` bei gleichem `actor_session_hash` | Session-Übernahme (S9) — präziser als B8, da ein Client-Wechsel mitten in der Sitzung deutlich seltener legitim ist als ein Netzwechsel |

Technisch: X1 lässt sich direkt per `correlation_id`-Self-Join auf `events` umsetzen. X2/X3 brauchen einen Zeitfenster-Join auf `actor_user`, X4 auf `actor_ip`, B8/B9 auf `actor_session_hash` — eine einzelne `correlation_id` deckt nur eine Anfrage ab und reicht dafür nicht.

**Zu B8/B9 — bekannte False-Positive-Quelle:** Ein IP-Wechsel innerhalb einer Sitzung ist bei mobilen Nutzern (Mobilfunk↔WLAN, Carrier-NAT) durchaus normal. B8 wird deshalb voraussichtlich deutlich mehr Fehlalarme erzeugen als B9 und ist ein vorrangiger Kandidat für die Kalibrierung (Abschnitt 6). B9 ist das belastbarere Signal, weil sich der `client_fingerprint` (User-Agent, Sprach- und Encoding-Header) während einer laufenden Sitzung praktisch nie ändert.

Die Regeln B8/B9 behalten ihre B-Nummerierung, weil sie wie die übrigen Batch-Regeln im periodischen Detection Job laufen; sie stehen hier, weil sie ebenenübergreifend korrelieren.

#### 4.3.4 Positivpfad-Regeln (Prüfung erfolgreicher Vorgänge)

**Ausgangspunkt:** Die Regeln R1–R7, B1–B7 und X1–X4 hängen nahezu durchgängig an Fehlerzuständen — Exceptions, Denials, Fehlversuche, abgewiesene Statuscodes. Daraus folgt ein struktureller Blindfleck: Angriffe, die die Anwendung *bestimmungsgemäß* benutzen und nur semantisch falsch sind (S6 Mass Assignment, S7 IDOR ohne Voter, S9 Session-Übernahme), erzeugen keinen einzigen Fehlerzustand. Sie sind aus Sicht der Anwendung fehlerfreie, autorisierte Vorgänge.

Diese Regelklasse prüft deshalb nicht, ob etwas fehlgeschlagen ist, sondern ob das *Gelungene* plausibel ist:

| Regel | Prüft | Erkennt |
|---|---|---|
| P1 | Anzahl erfolgreicher Zugriffe (`200`) desselben `actor_user` auf denselben Ressourcentyp pro Zeitfenster gegen dessen **eigene** Baseline | IDOR-Enumeration mit Erfolg (S7 ohne Voter) |
| P2 | Erfolgreiche Zugriffe auf Ressourcen-Identifier außerhalb der für diesen Nutzer historisch üblichen Wertemenge | Zugriff auf fremde Objekte |
| P3 | Kritisches Business-Event von einem `actor_user`, der diese Vorgangsklasse noch nie ausgelöst hat | erstmalige Rechteausübung nach Rechteausweitung (S6) |

**Einordnung:** P1–P3 sind durchgängig baseline-abhängig (Historie pro Nutzer statt fester Schwellwert) und laufen daher gemeinsam mit der anomaliebasierten Schicht (4.3.5).

**Abgegrenzt gegen B9:** Eine ursprünglich vorgesehene vierte Regel („erfolgreicher Vorgang bei gewechseltem `actor_fingerprint` innerhalb derselben Sitzung") wurde gestrichen — sie wäre eine echte Teilmenge von B9 (4.3.3) gewesen und hätte für dasselbe Ereignis einen zweiten Alert erzeugt. S9 wird vollständig durch B8/B9 abgedeckt.

**Warum feste Schwellwerte hier prinzipiell versagen:** Bei Fehlerzuständen (Brute-Force, Scanning) funktionieren feste Schwellwerte gut, weil ein Fehler an sich schon ungewöhnlich ist. Bei erfolgreichen, technisch legitimen Vorgängen gibt es keinen Schwellwert für "das war zwar erlaubt, passt aber nicht zu diesem Nutzer" — die Bewertung ist nur relativ zum bisherigen Verhalten möglich. Das ist die inhaltliche Rechtfertigung des anomaliebasierten Teils des Hybridansatzes.

**Ehrliche Einordnung der Grenzen:** Diese Regelklasse erzeugt naturgemäß mehr False Positives als signaturbasierte Regeln, weil "ungewöhnlich" nicht "bösartig" bedeutet. Das ist kein behebbarer Mangel des Ansatzes, sondern eine Eigenschaft des Problems: Angriffe, die die Anwendung bestimmungsgemäß benutzen, sind nur über Kontext und Erwartung erkennbar, nie über eine Signatur. P1–P3 setzen zudem voraus, dass überhaupt genug Historie pro Nutzer vorliegt — bei Neunutzern und selten aktiven Konten sind sie wirkungslos.

> **Abhängigkeit von der Business-Instrumentierung:** P3 setzt voraus, dass die Anwendung die Vorgangsklassen aus 2.1.3 tatsächlich instrumentiert. Ohne diese Events existiert für P3 keine Datengrundlage — die Regel läuft dann wirkungslos ins Leere, ohne dass dies im Betrieb auffällt. Gleiches gilt für S6 und für S7 bei fehlendem Voter: Die Positivpfad-Regeln schließen die Lücke nur so weit, wie die Anwendung überhaupt Signale liefert.

#### 4.3.5 Anomaliebasierte Ergänzung

Bewusst **keine ML-Infrastruktur** in diesem Konzept — statt eines trainierten Modells eine einfache statistische Baseline:

1. **Baseline-Job** (täglich): berechnet für definierte Metriken (z. B. Requestrate pro Route und Stunde, Fehlerquote 4xx/5xx pro Stunde, Login-Fehlversuchsquote pro Stunde) Mittelwert und Standardabweichung über ein rollierendes 30-Tage-Fenster, geschrieben in eine Tabelle `metric_baselines` (`metric_name`, `bucket` [z. B. Stunde des Tages], `mean`, `stddev`).
2. **Vergleich** (im selben periodischen Detection Job wie 4.3.2/4.3.4): aktueller Wert der Metrik im laufenden Zeitfenster wird gegen `mean ± 3·stddev` der passenden Baseline verglichen; Überschreitung → Alert `"Anomalie: <Metrik> weicht von Baseline ab"`.
3. **Bewusste Grenze:** Dieser Ansatz erkennt nur Abweichungen bei Metriken, die explizit als beobachtet definiert wurden — kein unüberwachtes Lernen unbekannter Muster. Ausbau zu echten ML-Verfahren (z. B. Isolation Forest, saisonale Zeitreihenmodelle) ist eine spätere, bewusst offen gelassene Erweiterung.

#### 4.3.6 Detektions-Regeln Symfony-typische Angriffsszenarien

Die folgenden Szenarien sind spezifisch für Symfony-Anwendungen (bzw. deren typische Fehlkonfigurationen und Komponenten) — im Unterschied zu generischen Webangriffen. Für jedes Szenario ist beschrieben, wie es sich im normalisierten Event-Strom (Abschnitt 3) niederschlägt und welche Regeln greifen.

| Szenario | Abgedeckt durch | Status |
|---|---|---|
| S1 | R2, R2b | abgedeckt |
| S2 | R2, R2b, B1 | abgedeckt |
| S3 | R2 (Variante a), X4 (Variante b) | abgedeckt |
| S4 | R1, R6, Baseline | abgedeckt |
| S5 | R1, R7 | abgedeckt |
| S6 | R5, X3, P3 | **nur bei implementierter Business-Instrumentierung** (V1, V4 — siehe 2.1.3) |
| S7 | R4 (mit Voter), B7, P1, P2 | abgedeckt; ohne Voter zusätzlich auf V3 angewiesen |
| S8 | R3, B3, B4, B5 | abgedeckt |
| S9 | B8, B9 | abgedeckt, setzt Sitzungskontext (siehe „Bildung der Sitzungskontext-Felder“) voraus |
| S10 | B1, Baseline | abgedeckt |

---

**S1 — Profiler/Web-Debug-Toolbar in Produktion erreichbar**

**Angriff:** Der Symfony Profiler (`/_profiler`, `/_wdt/*`) ist eine der ergiebigsten Informationsquellen überhaupt: Konfiguration, Umgebungsvariablen, DB-Queries, Session-Inhalte, teils Klartext-Secrets. Angreifer prüfen diese Pfade routinemäßig. Verwandt: der Legacy-Front-Controller `/app_dev.php` aus Symfony-2/3-Zeiten, der bei migrierten Projekten übrig geblieben sein kann.

**Signatur im Event-Strom:**
- `kernel.request` mit `payload.path` beginnend mit `/_profiler`, `/_wdt`, oder `/app_dev.php`
- Bei korrekt konfigurierter Prod-Umgebung folgt `kernel.response` mit `http_status = 404`
- **Kritischer Fall:** Antwort ist `200` → der Profiler ist tatsächlich exponiert

**Detektion:**
- **R2** (Echtzeit-Pfadliste) — diese Pfade sind in der Kategorie `framework_internals` der Wissensbasis (4.3.1) enthalten
- **R2b** (4.3.1) — greift mit `severity = critical`, wenn einer dieser Pfade mit `http_status = 200` beantwortet wird. Das ist kein Angriffsversuch mehr, sondern eine bestätigte Exposition — der Unterschied zwischen "jemand hat geklopft" und "die Tür stand offen" muss sich in der Severity abbilden.

---

**S2 — Konfigurationsdatei-Zugriff (`.env`, `composer.json`, `/.git`)**

**Angriff:** Symfony legt Umgebungskonfiguration konventionell in `.env` im Projektwurzelverzeichnis ab. Ist der Document Root falsch gesetzt (auf das Projektwurzelverzeichnis statt auf `public/`), sind `.env` (mit `DATABASE_URL`, `APP_SECRET`), `composer.json`/`composer.lock` (exakte Paketversionen → gezielte CVE-Auswahl) und `.git/` direkt abrufbar.

**Signatur:** `kernel.request` auf `/.env`, `/composer.json`, `/composer.lock`, `/.git/config`, `/config/packages/*`, `/var/log/*`

**Detektion:**
- **R2** — abgedeckt über die Kategorie `exposed_configuration` (4.3.1)
- **R2b** (siehe S1) greift auch hier: `200` auf diese Pfade = bestätigte Exposition, nicht nur Versuch
- **B1** — bei systematischem Durchprobieren mehrerer solcher Pfade zusätzlich als Scanning-Muster

---

**S3 — Fragment-Handler-Missbrauch (`/_fragment`)**

**Angriff:** Symfonys Fragment-Handler rendert Controller-Fragmente über `/_fragment` mit einer per `APP_SECRET` signierten URI. Zwei Angriffsvarianten: (a) Aufruf mit ungültiger/fehlender Signatur, um das Verhalten zu sondieren; (b) bei geleaktem `APP_SECRET` (z. B. aus S1/S2) die Erzeugung *gültiger* Signaturen und damit Aufruf beliebiger Controller mit kontrollierten Parametern.

**Signatur:**
- Variante (a): `kernel.request` auf `/_fragment` → `kernel.exception` mit `http_status = 403` (ungültige Signatur)
- Variante (b): `kernel.request` auf `/_fragment` mit `http_status = 200` — äußerlich unauffällig, da die Signatur gültig ist

**Detektion:**
- Variante (a): **R2** (`/_fragment` in Kategorie `framework_internals`, 4.3.1) + **B1** bei Wiederholung
- Variante (b) ist mit Pfad-Signaturen allein **nicht** erkennbar. Hier greift die Korrelation: Zugriffe auf `/_fragment` von einer `actor_ip`, die zuvor `.env` oder `/_profiler` abgerufen hat, sind hochverdächtig.
- **X4** (4.3.3) — `actor_ip` mit einem Treffer aus der Pfad-Wissensbasis, gefolgt von `/_fragment`-Zugriffen innerhalb von 24 Stunden → `critical`. Das ist der Fall, in dem die ebenenübergreifende Korrelation echten Mehrwert gegenüber Einzelregeln liefert.

---

**S4 — Template-Injection in Twig (SSTI)**

**Angriff:** Wird Nutzereingabe als Template-String (statt als Template-Variable) verarbeitet, kann über Twig-Syntax Code ausgeführt werden. Typischer Einstiegspunkt sind Funktionen, die Benutzertext dynamisch rendern (z. B. konfigurierbare E-Mail-Vorlagen, CMS-artige Inhaltsfelder).

**Signatur:** Sondierungsversuche erzeugen meist Twig-Exceptions, bevor ein funktionierender Payload gefunden wird:
- `kernel.exception` mit `exception_class` aus dem Twig-Namensraum (`Twig\Error\SyntaxError`, `Twig\Error\RuntimeError`)
- Häufig `http_status = 500` → damit `event_severity = critical`

**Detektion:**
- **R1** (jede 5xx-Exception in Echtzeit) greift bereits
- **R6** (4.3.1) — Regel auf `exception_class LIKE 'Twig\\%'` — eine Twig-Syntax-Exception zur Laufzeit ist in einer produktiven Anwendung praktisch immer ein Anzeichen dafür, dass Nutzereingabe in einen Template-Kontext gelangt. Diese Regel ist Symfony-spezifisch und wertvoller als die generische 5xx-Regel, weil sie den Verdacht sofort einordnet.
- **Baseline (4.3.5):** ein Anstieg der Twig-Exception-Rate über die Baseline hinaus deutet auf systematisches Ausprobieren hin

---

**S5 — Deserialisierungs-Angriffe (Symfony Serializer / PHP `unserialize`)**

**Angriff:** Nimmt die Anwendung serialisierte Daten von außen entgegen (API-Payloads, Cookies, Cache-Schlüssel) und deserialisiert diese, können präparierte Objektgraphen ("Gadget Chains") beim Deserialisieren Code auslösen. Symfony-Projekte sind hier durch die große Zahl mitgelieferter Klassen im Autoloader potenziell exponiert.

**Signatur:**
- `kernel.exception` mit `exception_class` aus `Symfony\Component\Serializer\Exception\*` (z. B. `NotEncodableValueException`, `UnexpectedValueException`); fehlgeschlagene Gadget-Versuche äußern sich zusätzlich als `TypeError`/`Error`, die aber bewusst **nicht** in R7 aufgenommen wurden (siehe 4.3.1)
- Oft ungewöhnlich große `payload.content_length` bei `kernel.request`

**Detektion:**
- **R1** bei 5xx
- **R7** (4.3.1) — `exception_class` im Serializer-Namensraum → `warning`, kombiniert mit `content_length` oberhalb eines Schwellwerts → `critical`
- Das `raw`-Feld (Abschnitt 3) zeigt hier seinen Wert: der ursprüngliche Payload ist für die forensische Nachanalyse innerhalb der 30-Tage-Retention vollständig verfügbar

---

**S6 — Mass Assignment über Symfony Forms**

**Angriff:** Ein Formular, das Extra-Felder toleriert oder direkt auf eine Entity gemappt ist, kann Felder setzen, die im UI nicht vorgesehen sind (z. B. `roles`, `isVerified`, `balance`). Der Angriff ist unauffällig: die Anfrage ist syntaktisch gültig, die Antwort ist `200`.

**Signatur:** Auf Kernel-Ebene **nicht erkennbar** — ein erfolgreicher Mass-Assignment-Request unterscheidet sich technisch nicht von einem legitimen Formular-Submit.

**Detektion:**
- Ausschließlich über die **Business-Ebene** (2.1.3): Die Anwendung muss selbst ein Event auslösen, wenn ein sicherheitsrelevantes Feld verändert wird (z. B. `user.roles_changed` mit `getSeverityHint() = 'critical'`).
- Dann greift **R5** (kritisches Business-Event in Echtzeit) und **X3** (Login gefolgt von kritischer Aktion).
- **Dies ist das wichtigste Argument für die Business-Ebene überhaupt:** Angriffe, die die Anwendung *bestimmungsgemäß* benutzen und nur semantisch falsch sind, sind auf Infrastruktur- und Kernel-Ebene grundsätzlich unsichtbar. Ohne Business-Events hat das IDS hier einen blinden Fleck, den keine Verschärfung der Kernel-Regeln schließen kann.

---

**S7 — Insecure Direct Object Reference (IDOR)**

**Angriff:** Zugriff auf fremde Ressourcen durch Manipulation von Identifiern in der URL (`/orders/42` → `/orders/43`). Bei korrekt implementierten Symfony-Votern wird der Zugriff abgelehnt; fehlt der Voter, gelingt er.

**Signatur:**
- Mit Voter: Serie von Autorisierungs-Events mit `decision = "denied"`, gleiche `actor_user`, aufeinanderfolgende `payload.resource`-Identifier
- Ohne Voter: `200`-Antworten — auf Security-Ebene unsichtbar

**Detektion:**
- **R4** (5 Denials/60s pro Nutzer) und **B-Regeln** greifen im Voter-Fall
- **B7** (4.3.2) — Erkennung *sequenzieller* Ressourcen-Identifier — mehr als N Zugriffe desselben `actor_user` auf denselben Ressourcentyp mit numerisch benachbarten IDs in kurzer Zeit. Das erkennt IDOR-Enumeration **auch dann, wenn die Zugriffe erfolgreich sind** (`decision = "granted"`), und schließt damit teilweise die Lücke des fehlenden Voters.

---

**S8 — Brute-Force und Credential Stuffing gegen die Symfony-Firewall**

**Angriff:** Automatisierte Login-Versuche gegen den `form_login`- oder `json_login`-Authenticator, entweder gegen ein Konto mit vielen Passwörtern (Brute-Force) oder mit geleakten Zugangsdaten gegen viele Konten (Credential Stuffing).

**Signatur:** Serien von `security.authentication.failure` mit `payload.failure_reason = "BadCredentialsException"`

**Detektion:** Vollständig durch die bestehenden Regeln abgedeckt:
- **R3** — >5 Fehlversuche pro IP in 60s (Echtzeit)
- **B3** — eine IP gegen >5 verschiedene Nutzer (Credential Stuffing)
- **B4** — ein Nutzer von >5 verschiedenen IPs (verteilter Angriff)
- **B5** — erfolgreicher Login nach Fehlversuchsserie → der eigentlich kritische Fall, weil er die *erfolgreiche* Kontoübernahme markiert

---

**S9 — Session-Fixation und Remember-Me-Token-Missbrauch**

**Angriff:** Übernahme einer Sitzung durch untergeschobene Session-ID oder gestohlenes `REMEMBER_ME`-Cookie. Ein erfolgreicher Angriff erzeugt legitime, authentifizierte Requests.

**Signatur:** Keine Fehler-Events — der Angreifer ist aus Sicht der Anwendung ein gültiger Nutzer. Auffällig ist nur der **Kontextwechsel**: derselbe `actor_user` erscheint plötzlich von einer anderen `actor_ip` bzw. mit anderem `payload.user_agent`.

**Detektion:**
- **B8** (4.3.3) — Wechsel der `actor_ip` für denselben `actor_user` innerhalb eines kurzen Zeitfensters (z. B. <10 Min.), ohne dazwischenliegendes `security.authentication.success` → `warning`. Ein Nutzer wechselt selten mitten in der Sitzung das Netz; passiert es doch (Mobilfunk/WLAN-Wechsel), ist das ein bekannter False-Positive-Kandidat, der bei der Kalibrierung (Abschnitt 6) zu berücksichtigen ist.
- **B9** (4.3.3) — analog für Wechsel des `actor_fingerprint` bei gleichem `actor_session_hash` — deutlich seltener legitim als ein IP-Wechsel und damit das präzisere Signal.

---

**S10 — Enumeration bekannter Symfony-Bundle-Routen**

**Angriff:** Bevor eine bekannte Schwachstelle in einem Bundle ausgenutzt wird, prüft der Angreifer, ob das Bundle überhaupt installiert ist — durch Abruf charakteristischer Routen (Admin-Bundles, API-Dokumentations-Endpunkte, Upload-Handler). Die Statuscode-Verteilung verrät dabei die installierte Bundle-Landschaft, selbst wenn die Endpunkte geschützt sind: `403` bedeutet "vorhanden, aber geschützt", `404` bedeutet "nicht installiert".

**Signatur:** Viele `kernel.response` mit `403`/`404` von derselben `actor_ip` über eine breite Streuung unterschiedlicher `payload.path`-Werte, meist in schneller Folge

**Detektion:**
- **B1** ist genau dafür gebaut (>20 Treffer über >5 unterschiedliche Pfade in 5 Min.)
- **Baseline (4.3.5):** Ein Anstieg der 404-Quote über die Norm hinaus erkennt auch langsame, über Stunden verteilte Enumeration, die unterhalb der B1-Schwelle bleibt — genau die Lücke, für die der anomaliebasierte Teil vorgesehen ist

---

### 4.4 Alerting - Vorfall statt Einzelalarm

**Problem:** Ohne Deduplizierung feuert R3, sobald der Redis-Zähler die Schwelle überschreitet — also bei *jedem weiteren* Fehlversuch. Ein Brute-Force-Angriff mit 500 Versuchen erzeugt rund 495 Alerts für **einen** Vorfall. Das macht die Alert-Tabelle unbrauchbar und trainiert jeden Betrachter darauf, sie zu ignorieren.

**Lösung:** `alerts` ist keine Ereignis-, sondern eine **Vorfallstabelle**. Ein Vorfall wird einmal angelegt und danach fortgeschrieben.

**Bildung des `dedup_key`** — zusammengesetzt aus:
- `rule_id`
- dem für die Regel maßgeblichen Akteur: `actor_ip` (R2, R3, B1, B2, X4), `actor_user` (R4, B4, B6, B7, X2, X3, P1–P3) oder `actor_session_hash` (B8, B9)
- `application_id` und `environment` (Konsequenz der Aggregationsregel aus „Anwendungs- und Instanzkontext“)
- einem Fensterkennzeichen (siehe unten)

**Schreibvorgang** — Upsert statt Insert:

```sql
INSERT INTO alerts (alert_id, dedup_key, rule_id, alert_severity, source,
                    application_id, environment, description, details)
VALUES (...)
ON CONFLICT (dedup_key) DO UPDATE
SET last_seen = now(),
    occurrence_count = alerts.occurrence_count + 1,
    details = EXCLUDED.details;
```

**Vorfallsende:** Ein Vorfall gilt als abgeschlossen, wenn er länger als das Cooldown-Fenster seiner Regel nicht mehr aufgetreten ist (Vorschlag: 30 Minuten). Das Fensterkennzeichen im `dedup_key` sorgt dafür, dass ein erneutes Auftreten danach einen **neuen** Vorfall erzeugt statt einen Monate alten Eintrag wiederzubeleben.

**Cooldown im Echtzeitpfad:** Damit R1–R7 nicht pro Event einen Datenbank-Upsert auslösen, hält der Consumer je `dedup_key` einen Redis-Schlüssel mit der Cooldown-Dauer als TTL. Innerhalb dieses Fensters wird nur der Redis-Zähler erhöht; der Upsert erfolgt gebündelt. Das ist zugleich Voraussetzung für das Latenzbudget aus 2.1.

**Nebeneffekt, der eigene Aussagekraft hat:** `occurrence_count` ist selbst ein Signal. 500 Fehlversuche statt 6 unterscheiden einen automatisierten Angriff von einem vergesslichen Nutzer — ohne dass es dafür eine eigene Regel braucht. Für Priorisierung und Eskalation ist der Zählerstand oft aussagekräftiger als die `alert_severity`.

**Retention:** `alerts` wird bewusst **nicht** partitioniert und **nicht** automatisch bereinigt. Das Volumen liegt um Größenordnungen unter dem der `events`-Tabelle, und Alerts sind der eigentliche Auswertungsgegenstand — eine langfristige Historie ist hier erwünscht, nicht lästig. Sollte das Volumen unerwartet wachsen (etwa durch unkalibrierte Schwellwerte), ist eine Partitionierung nach `created_at` analog zu 4.2.3 jederzeit nachrüstbar. `metric_baselines` wird bei jedem Baseline-Lauf überschrieben und wächst nicht.

---

### 4.5 Absicherung der Sammelstelle und Rohdatenschutz

Die Sammelstelle ist das wertvollste Einzelziel der gesamten Architektur: Sie enthält, was ein Angreifer in der überwachten Anwendung erst mühsam einsammeln müsste — und das für alle Nutzer gleichzeitig.

#### 4.5.1 Redaktion sensibler Werte in `raw`

**Auflösung eines Widerspruchs:** In „Bildung der Sitzungskontext-Felder“ wird das Hashen der Session-ID damit begründet, dass die Event-Datenbank sonst selbst zum Session-Hijacking-Vektor würde. Über ein unredigiertes `raw`-Feld wäre sie das trotzdem — dort lägen Cookies, `Authorization`-Header und Login-Formulardaten im Klartext. Die Begründung wird deshalb konsequent durchgezogen statt zurückgenommen.

**Ausführungsort: der Sensor, nicht der Consumer.** Andernfalls würden Klartext-Zugangsdaten über den Broker laufen und dort in Queues, Logs und Spool-Dateien landen.

Redaktionsliste — Werte werden durch `[redacted]` ersetzt, Feldnamen bleiben erhalten:

| Kategorie | Einträge |
|---|---|
| Header | `Cookie`, `Set-Cookie`, `Authorization`, `Proxy-Authorization`, `X-API-Key`, `X-Auth-Token`, `X-CSRF-Token` |
| Parameter (Namensmuster) | `password`, `passwd`, `pwd`, `secret`, `token`, `_token`, `api_key`, `apikey`, `private_key`, `credit_card`, `cvv`, `iban` |

Die Liste wird wie die Pfad-Wissensbasis (4.3.1) als versionierte Konfiguration geführt, nicht hartkodiert.

> **Ehrliche Einordnung:** Dies ist eine Denylist und teilt deren grundsätzliche Schwäche — unbekannte Feldnamen werden nicht erfasst. Auch vollständig redigiert bleibt `raw` sensibel, weil es Geschäftsdaten und personenbezogene Formularinhalte enthält. Die Redaktion senkt das Schadensmaß bei einer Kompromittierung, sie beseitigt es nicht.

#### 4.5.2 Zugriffstrennung in der Datenbank

Drei getrennte Rollen statt eines gemeinsamen Zugangs:

| Rolle | Rechte | Verwendet von |
|---|---|---|
| `ids_writer` | nur `INSERT` auf `events_relevant`, `events_info`, `events_raw` | Consumer |
| `ids_analyst` | nur `SELECT` auf die Event-Tabellen und `metric_baselines`, `INSERT`/`UPDATE` auf `alerts` — **kein Zugriff auf `events_raw`** | Detection Job |
| `ids_forensics` | `SELECT` auf `events_raw`, personengebunden, Zugriffe protokolliert | manuelle Nachanalyse |

Der Ausschluss von `events_raw` für `ids_analyst` ist möglich, weil **keine einzige Regel aus Abschnitt 4.3 auf `raw` zugreift**. Damit ist der sensibelste Datenbestand kein Bestandteil des laufenden Betriebs, sondern nur bei begründetem Anlass erreichbar — die Standardkompromittierung eines Dienstkontos erreicht ihn nicht.

#### 4.5.3 Weitere Maßnahmen

- **Transport:** Broker-Verbindungen ausschließlich über TLS und mit Authentifizierung; der Broker ist nicht öffentlich erreichbar.
- **Log-Injection:** `path`, `user_agent` und `payload` sind angreiferkontrolliert. Sie werden ausschließlich als JSONB-Werte gespeichert, nie in Textlogzeilen interpoliert, und müssen in jeder späteren Auswertungsoberfläche als Daten behandelt werden, nicht als Markup.
- **Datenschutz:** Die Entscheidung, Datenschutzaspekte bei `raw` nachrangig zu behandeln, ist bewusst getroffen worden (Priorität auf forensische Vollständigkeit). Sie ist vor einem produktiven Einsatz mit echten Nutzerdaten erneut zu prüfen — betroffen sind Rechtsgrundlage, Aufbewahrungsfristen und Auskunftsfähigkeit. Als offener Punkt geführt (Abschnitt 6, B8).

---

## 6. Offene Punkte — priorisierte Gesamtübersicht

Stand nach Einarbeitung der fünf kritischen Punkte (K1–K5). Priorität: **H** = hoch, **M** = mittel. Die Spalte „Blockiert" nennt Punkte, die ohne diesen nicht bearbeitbar sind.

### 6.1 Erledigt in dieser Fassung

| ID | Thema | Umgesetzt in |
|---|---|---|
| K1 | Rohdatenschutz, Zugriffstrennung, Absicherung der Sammelstelle | 4.5 |
| K2 | Ausfall- und Überlastverhalten (fail-open, at-least-once, Spool) | Abschnitt 4 |
| K3 | Latenz- und Volumenbudget, gestufte Retention | 2.1, 4.2.3 |
| K4 | Anwendungs- und Instanzkontext, Uhrendrift | 2.2.1, 3, 4.2.1, 4.2.3 |
| K5 | Vorfallsbegriff, Deduplizierung, Cooldown | 4.4 |
| B2 | Auslieferungsform: zwei Bundles, Paketgrenze, Broker-Rechte, Heartbeat | 1, 2 |

### 6.2 Erkennung

| ID | Thema | Prio | Blockiert |
|---|---|---|---|
| O1 | **Befundklasse `exposures`** — ein `404` auf `/_profiler` ist ein Befund über den Angreifer, ein `200` einer über die eigene Anwendung: anderer Adressat (Betrieb statt Sicherheitsüberwachung), anderer Lebenszyklus. R2b erzeugt derzeit einen Vorfall; eine bestätigte Fehlkonfiguration ist aber ein Zustand, der bis zur Behebung offen bleibt. Zu klären: Abgrenzung zu `alerts`, Lebenszyklus (offen/behoben/erneut aufgetreten), aktive vs. passive Prüfung. | H | — |
| O2 | **`resource_type` / `resource_id` ableiten** — B7, P1 und P2 vergleichen „benachbarte IDs desselben Ressourcentyps", der `kernel.response`-Payload enthält aber nur `path` und `route`. Ohne Extraktion aus Route und Routenparametern sind die drei Regeln nur über String-Analyse umsetzbar. | H | B7, P1, P2 |
| E1 | **CLI- und Worker-Kontext** — Console-Commands, Messenger-Worker und Cronjobs erzeugen keine HttpKernel-Events. Ein Angreifer mit Codeausführung arbeitet genau dort. Symfony bietet `console.command` und `console.error`; im Konzept bisher nicht vorgesehen. | H | — |
| E4 | **Metrikkatalog der Anomalieschicht** — 4.3.5 nennt Beispiele, aber keinen verbindlichen Satz. `metric_baselines` existiert damit ohne definierten Inhalt. Nach 4.2.3 ist die Aggregation zugleich Voraussetzung für die kurze `info`-Retention. | H | 4.2.3 (3), P1–P3 |
| O4 | **Baseline-Verfahren für P1–P3** — Mindesthistorie pro Nutzer, Verhalten bei Neunutzern, Umgang mit selten aktiven Konten. | H | P1–P3 |
| E2 | **Baseline-Poisoning** — wer langsam anfängt, trainiert die 30-Tage-Baseline auf sein eigenes Verhalten. Klassische Schwäche anomaliebasierter Verfahren, in 4.3.5 nicht adressiert. | M | — |
| E3 | **„Low and slow"** — wer die Schwellwerte kennt, bleibt darunter. Abgedeckt nur über die Baseline, die laut 4.3.4 bei Neunutzern wirkungslos ist. | M | E2, O4 |
| O3 | **Schwellwert-Kalibrierung** an echtem Traffic; vorrangiger Kandidat ist B8 (IP-Wechsel bei Mobilnutzern). Startwerte stehen, Validierung fehlt. | M | B1 |
| E5 | **Reaktionsverhalten** — das System ist rein passiv. Ob das Absicht ist (Detection ohne Prevention), sollte als Scope-Entscheidung in Abschnitt 1 festgehalten werden. | M | — |

### 6.3 Betrieb, Auslieferung, Validierung

| ID | Thema | Prio | Blockiert |
|---|---|---|---|
| B1 | **Teststrategie** — es gibt kein Verfahren, um zu prüfen, ob eine Regel tatsächlich anschlägt. Ohne simulierte Angriffe ist weder die Inbetriebnahme verifizierbar noch O3 durchführbar. | H | O3 |
| B4 | **Selbstüberwachung** — Broker-Lag, Verarbeitungsrate, Spool-Füllstand, Trefferquote je Regel. Direkte Voraussetzung des Restrisikos aus Abschnitt 4: fail-open ist nur vertretbar, wenn Verluste sichtbar werden. | H | — |
| B3 | **Konfigurierbarkeit pro Anwendung** — die Grundstruktur steht in Abschnitt 1 (IdsBackendBundle: Applications verwalten); offen sind das collectorseitige Anwendungsregister (`application_id`, Technologieprofil, erwartetes Heartbeat-Intervall, regelspezifische Schwellwerte) und die Sampling-Rate aus 4.2.3. | M | — |
| B6 | **`correlation_id`-Erzeugung** — wer erzeugt sie, und wird eine vorhandene Request-ID eines Reverse-Proxy übernommen? | M | — |
| B5 | **Symfony-Versionsbindung** — das Konzept zielt auf 5.4, das Authenticator-System hat sich zu 6.x/7.x geändert. Migrationspfad offen. | M | — |
| B8 | **Datenschutz-Entscheidung** — bewusst nachrangig behandelt (4.5.3), vor produktivem Einsatz mit echten Nutzerdaten erneut zu prüfen: Rechtsgrundlage, Aufbewahrungsfristen, Auskunftsfähigkeit. | M | — |
| O5 | **Alert-Weiterverarbeitung** (Benachrichtigung, Dashboard, Eskalation) — bewusst außerhalb des Scopes, hier nur zur Vollständigkeit. | — | — |

### 6.4 Empfohlene Reihenfolge

Aus den Abhängigkeiten ergibt sich eine natürliche Folge: **B1** (Teststrategie) und **B4** (Selbstüberwachung) zuerst, weil ohne sie keine Regel validierbar und kein Ausfall bemerkbar ist. Dann **O2** und **E4**, die konkrete Regeln beziehungsweise die Retention-Entscheidung blockieren. **O3** zuletzt, weil Kalibrierung ohne Messverfahren nicht möglich ist.
