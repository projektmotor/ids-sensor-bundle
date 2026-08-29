# 07 — Betrieb

Was nach der Installation dauerhaft laufen muss, was gemeldet wird und woran man erkennt,
dass etwas nicht stimmt.

## Der Heartbeat ist nicht optional

Konzept 2. nennt die **lautlose Stilllegung** des Sensors als die gefährlichste
Angriffsform: ein Angreifer, der den Sensor abschaltet, ist von einer Anwendung ohne
Verkehr nicht zu unterscheiden. Deshalb sendet der Sensor ein Lebenszeichen; bleibt es
aus, erzeugt der Collector den Alarm `ids.sensor_silent`.

Der Heartbeat ist ein **eigener Nachrichtentyp**, kein Event (*3.4*) — er hat keinen
`layer`. Ein vierter Wert dort würde collectorseitig einen Insert-Fehler auslösen.

```mermaid
flowchart TB
    mode{"heartbeat.mode<br/><small>Vorgabe: auto</small>"}

    mode -->|"auto"| rt{"Laufzeit?"}
    rt -->|"FPM, LiteSpeed,<br/>FrankenPHP"| both["`**both**<br/><small>Request UND Command</small>`"]
    rt -->|"mod_php"| cmdonly["`**command**<br/><small>request-Pfad ist<br/>abgeschaltet</small>`"]

    both --> reqpath["request-getrieben<br/><small>wirkt sofort,<br/>schweigt ohne Verkehr</small>"]
    both --> cmdpath["command-getrieben<br/><small>braucht cron/systemd,<br/>auch ohne Verkehr verlässlich</small>"]
    cmdonly --> cmdpath

    cmdpath --> cron["ids:sensor:heartbeat"]
    reqpath --> term["kernel.terminate"]

    cron --> sched
    term --> sched
    sched["`**Scheduler**<br/><small>drosselt auf interval_s,<br/>prozessübergreifend</small>`"]
    sched --> send["Heartbeat an den Collector"]

    classDef capture fill:#E1F5EE,stroke:#0F6E56,color:#085041
    classDef transport fill:#F1EFE8,stroke:#5F5E5A,color:#3A3936
    classDef data fill:#EEEDFE,stroke:#534AB7,color:#332C7A
    class mode,rt transport
    class both,cmdonly,reqpath,cmdpath capture
    class sched,cron,term transport
    class send data
```

**Unter mod_php ist der Command der einzige Weg** — der request-getriebene Pfad ist dort
abgeschaltet, weil er Netzwerkzugriff im Request bedeutete. Ohne cron meldet der Collector
dauerhaft `ids.sensor_silent`, obwohl der Sensor arbeitet.

```cron
* * * * * php /pfad/bin/console ids:sensor:heartbeat --quiet
```

Der Modus reist im Payload mit, damit der Collector die Aussagekraft eines ausbleibenden
Heartbeats kennt: bei `request` heißt Schweigen möglicherweise nur „kein Verkehr", bei
`command` heißt es „etwas ist kaputt".

### Warum die Drosselung die `sensor_id` kennt

Der `Scheduler` drosselt **prozessübergreifend** — anders wäre er im request-getriebenen
Modus wirkungslos, weil unter PHP-FPM jeder Request in einem anderen Prozess laufen kann
und jeder für sich „noch nie gesendet" feststellen würde.

Prozessübergreifend heißt bei APCu aber auch: geteilt zwischen **allen** Anwendungen, die
dieses PHP-Pool benutzen. Der Schlüssel setzt sich deshalb aus `application_id` **und**
`sensor_id` zusammen. Ohne die `sensor_id` darin würde der erste Sensor, der einen
Heartbeat sendet, die Heartbeats aller anderen für ein Intervall unterdrücken — der
Collector sähe einen einzigen lebenden Sensor und meldete für alle übrigen
`ids.sensor_silent`. Also einen Dauerfalschalarm genau für die Sensoren, die in Ordnung
sind.

Die Ablage ist zweistufig: APCu zuerst, Datei als Rückfall. Der CLI-Prozess des
Heartbeat-Commands sieht das APCu-Segment von PHP-FPM **nicht** — es sind getrennte
Shared-Memory-Segmente. Ohne die Datei wüssten Command- und Request-Pfad nichts
voneinander und würden im Modus `both` doppelt senden.

## fail-open, und was es kostet

Eine Störung des IDS darf die überwachte Anwendung **unter keinen Umständen**
beeinträchtigen (*4.*). Jeder Fehler wird verschluckt. Der Preis: **Events können verloren
gehen** — deshalb wird jeder Verlust gezählt und reist im Frame sowie im Heartbeat mit.

Die Zähler sind nach der Phase geordnet, in der der Verlust entsteht. Sie sind bewusst
feiner geschnitten, als es zum Zählen nötig wäre: Jeder Zähler steht für **eine** Ursache
und damit für ein anderes Gegenmittel — eine gemeinsame Zahl ließe nicht erkennen, welches
greift.

**Erfassung (Phase A, im Request):**

| Zähler | Bedeutung | Gegenmittel |
|---|---|---|
| `dropped_capture_budget` | Erfassungsbudget im Request erschöpft — die Zeit war alle | `budget.capture_us` erhöhen oder `access_decision` abschalten |
| `dropped_capture_error` | die Erfassung selbst hat geworfen — der Sensor ist defekt | Fehlerbericht; das Log nennt die Ausnahme |
| `dropped_decision_cap` | mehr `isGranted()` als `max_decisions_per_request` | Cap erhöhen — oder prüfen, ob die Seite wirklich so viele Voter braucht |
| `dropped_buffer_full` | mehr Events als der Puffer aufnimmt | `budget.max_events_per_request` |
| `dropped_reset` | der Puffer war beim Service-Reset noch gefüllt | ein Flush-Punkt fehlt; siehe [04](04-request-lebenszyklus.md) |

**Verarbeitung und Versand (Phase B, nach der Antwort):**

| Zähler | Bedeutung | Gegenmittel |
|---|---|---|
| `dropped_no_normalizer` | für die Ebene ist kein Normalisierer registriert | die Ebene ist abgeschaltet, das Event aber erfasst worden — `setup-check` |
| `dropped_normalize_error` | die Normalisierung ist fehlgeschlagen | Fehlerbericht; betrifft meist einen Payload der Anwendung |
| `dropped_frame_too_large` | die Sendung überschreitet `flush.max_frame_bytes` | Payload untersuchen — nicht Plattenplatz, sondern Inhalt |
| `dropped_spool_full` | Spool voll, Frame verworfen | `spool.max_bytes`, häufiger drainen |
| `dropped_spool_unwritable` | Spool-Verzeichnis nicht beschreibbar | Rechte korrigieren — nicht mehr Platz |
| `dropped_spool_unencodable` | der Frame ließ sich nicht kodieren | Payload untersuchen; praktisch nur eine Tiefenüberschreitung |
| `dropped_spool_unreadable` | unlesbare Spool-Zeile | die Spool-Datei ist beschädigt |
| `dropped_rejected` | der Collector hat dauerhaft abgewiesen (`400`, `403`, `413`, `422`) | den **Payload** prüfen, nicht den Collector — siehe [Fehlersuche](#fehlersuche) |
| `ship_failed` | Collector nicht erreichbar oder gestört (`429`, `5xx`, Timeout) | Collector prüfen; der Frame ging in den Spool |
| `heartbeat_failed` | Lebenszeichen konnte nicht gesendet werden | wie `ship_failed` |

Die letzten beiden sind die wichtigste Unterscheidung dieser Tabelle, und sie führen zu
**entgegengesetzten** Maßnahmen (*3.6*): `ship_failed` heißt „den Collector prüfen" und der
Frame liegt im Spool, `dropped_rejected` heißt „den Payload prüfen" und der Frame ist weg.
Eine gemeinsame Zahl ließe nicht erkennen, welche greift — und ein abgewiesener Frame im
Spool hielte dort bei jedem Drain-Lauf erneut die ganze Datei fest.

**Jeder Zähler reist mit, auch mit dem Wert `0`** (*3.4*). Ein fehlender Schlüssel wäre für
den Collector zweideutig: „nichts verloren" oder „diese Sensorfassung kennt den Zähler
nicht".

**Was nicht zählbar ist**, und das ist eine ehrliche Grenze: `SIGKILL`, der OOM-Killer,
Container-Eviction und ein Verlust im Collector nach der Annahme sind von innen nicht beobachtbar.
Stirbt der Prozess hart, sind die gepufferten Events weg — ohne Spur. `ids.event_loss`
deckt bewusste Verwerfungen und Spool-Überlauf, nicht das harte Wegsterben.

Eine weitere Grenze: entsteht keine Antwort, gibt es kein `kernel.response` — und damit
kein `raw` der Anfrageseite. Die gepufferten Events werden trotzdem versendet.

## Endpunkt-Rechte: nur schreiben

Der Sensor läuft **in** der Anwendung, die er überwacht. Ist sie kompromittiert, ist er es
auch. Deshalb darf er ausschließlich schreiben — kein Lesen, kein Löschen (*2.*).

Das ist keine Rechtekonfiguration mehr, sondern folgt aus dem Endpunktschnitt: Der Sensor
kennt drei Adressen, und alle drei nehmen nur entgegen.

```text
POST /api/v1/token                                              Anmeldung
POST /api/v1/sensor-data/{application_id}/{environment_id}/{sensor_id}
POST /api/v1/sensor-heartbeat/{application_id}/{environment_id}/{sensor_id}
```

Es gibt keinen Endpunkt, der gespeicherte Events zurückgibt, und kein `DELETE`. Ein
Angreifer in der Anwendung kann damit weder abgesendete Events löschen noch die anderer
Requests mitlesen.

**Die eigentliche Kontrolle ist die Eigentümerprüfung** (*3.6*): Der durch das Token
authentifizierte Nutzer muss Eigentümer der Kette Anwendung → Umgebung → Sensor sein, die
im Pfad steht. Ein Sensor kann in seine Konfiguration schreiben, was er will — maßgeblich
ist, ob es dem gehört, der sich angemeldet hat. Die UUIDs im Pfad sind nicht ratbar, aber
sie sind nicht die Kontrolle.

**Zugangsdaten und Sperre.** Der Collector gibt beim Registrieren `sensor_id`, Benutzername
und Passwort aus. Ein auffällig gewordener Sensor wird dort stillgelegt — ohne dass jemand
Infrastruktur anfassen muss. Das ist der Vorteil gegenüber einer Broker-ACL, die getrennt
gepflegt werden musste.

Übertragen wird ausschließlich JSON. Der Sensor braucht zwingend Schreibrecht, und alles,
was der Collector entgegennimmt, muss er als Daten behandeln — niemals als etwas, das er
selbst ausführt oder deserialisiert.

### Ihre Messenger-Einrichtung bleibt unberührt

Das Bundle registriert **keinen** Messenger-Transport, keine `buses`, kein `routing` und
keine Middleware. Der Sensor spricht den Collector unmittelbar über HTTP an.

`symfony/messenger` ist nur noch eine optionale Abhängigkeit: Ist es vorhanden, hängt sich
der Sensor an `WorkerMessageHandledEvent` und `WorkerMessageFailedEvent`, damit ein Worker
seine Business-Events nicht bis zum Prozessende puffert. Fehlt es, entfallen diese beiden
Flush-Punkte und sonst nichts.

## Trusted Proxies

Steht die Anwendung hinter einem Reverse Proxy und ist `framework.trusted_proxies` nicht
gesetzt, ist `actor.ip` bei **jedem** Event die Proxy-IP. Alle IP-basierten Regeln aus
(*4.3*) sind dann wirkungslos — ohne jede Fehlermeldung.

## Die drei Befehle

| Befehl | Zweck |
|---|---|
| `ids:sensor:setup-check` | Betriebsprüfung. Rückgabewert ≠ 0 heißt: die Erkennung ist wirkungslos. Für den Deploy. |
| `ids:sensor:spool:flush` | Leert den Spool in Richtung Collector. Unter mod_php Pflicht. |
| `ids:sensor:heartbeat` | Sendet ein Lebenszeichen. Für cron oder systemd-Timer. |

`ids:sensor:setup-check` bitte **nicht** mit `|| true` entschärfen. Der Sinn ist, dass eine
Fehlkonfiguration im Deploy auffällt und nicht erst bei der Nachanalyse eines Vorfalls.

Der Command unterscheidet **Befunde** (die Erkennung ist wirkungslos — Rückgabewert 1) von
**Hinweisen** (etwas ist eingeschränkt, aber möglicherweise gewollt — Rückgabewert 0).
`--strict` behandelt Hinweise wie Befunde:

```console
$ php bin/console ids:sensor:setup-check --strict
```

Das ist der Schalter für Deployments, die jede Einschränkung ausdrücklich abnicken wollen.
Er ist praktisch immer rot, und das ist Absicht: Der Hinweis auf die fehlende
Business-Instrumentierung erscheint **unabhängig** von jeder Konfiguration, weil der Sensor
nicht wissen kann, ob die Anwendung Events auslöst. Wer `--strict` grün haben will, muss
die Business-Ebene tatsächlich anbinden — siehe
[09 — Business-Ebene](09-business-ebene.md).

## Fehlersuche

| Symptom | Wahrscheinliche Ursache |
|---|---|
| `Anmeldung am Collector scheiterte mit 401` | Benutzername oder Passwort stimmen nicht — `collector.username`/`collector.password` prüfen |
| Collector antwortet `403` | die Zugangsdaten gehören nicht zu dieser Kette Anwendung → Umgebung → Sensor; der Collector prüft die Eigentümerschaft, nicht den Pfad (*3.6*) |
| Collector antwortet `422` | eine der drei Kennungen ist im Anwendungsregister nicht eingetragen — meist die `environment_id` |
| Collector meldet `ids.sensor_silent`, Sensor läuft | Heartbeat-cron fehlt (unter mod_php Pflicht) |
| Gar nichts kommt an, keine Fehlermeldung | unter mod_php: `spool:flush` läuft nicht, oder der Drain-Prozess sieht ein anderes Verzeichnis |
| Alle Events tragen dieselbe `sensor_id` | die Kennung stammt aus einer geteilten Konfiguration (ConfigMap) statt je Node — siehe (*2.3*) |
| Alle Events tragen die Proxy-IP | `framework.trusted_proxies` fehlt |

Der erste Griff ist in allen Fällen `ids:sensor:setup-check`; er prüft genau diese Punkte.
