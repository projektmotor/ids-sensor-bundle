# 06 — Vertraulichkeit

Sensible Werte werden **im Sensor** unkenntlich gemacht, bevor etwas den Prozess verlässt
(*4.5.1*). Nicht im Collector — andernfalls liefen Klartext-Zugangsdaten über die Leitung
und landeten dort in Queues, Logs und Spool-Dateien.

Die Feldnamen bleiben erhalten: dass eine Anfrage ein Feld `password` mitbrachte, ist
forensisch relevant; sein Inhalt nicht.

## Sechs Eintrittspunkte, eine Liste

```mermaid
flowchart LR
    subgraph inputs["Was durch die Liste läuft"]
        direction TB
        h["Request- und<br/>Response-Header"]
        q["payload.query"]
        f["Formularfelder<br/>in raw"]
        j["JSON-Anfragekörper<br/>in raw"]
        b["Business-Payload"]
        m["payload.exception_message<br/>und payload.referer"]
    end

    subgraph chain["Support/PayloadConfidentialityCleanup/"]
        direction TB
        rules["`**Rules**<br/><small>die Denylist,<br/>vergleichsfertig</small>`"]
        cleaner["`**Cleaner**<br/><small>ersetzt Werte durch<br/>[confidential]</small>`"]
        rules --> cleaner
    end

    h -->|"RawPayload\\Builder"| cleaner
    f -->|"RawPayload\\Builder"| cleaner
    j -->|"RawPayload\\Builder"| cleaner
    q -->|"QueryNormalizer"| cleaner
    b -->|"PayloadSanitizer"| cleaner
    m -->|"KernelEventNormalizer"| cleaner

    cleaner --> out["Frame<br/><small>kein Klartext mehr</small>"]

    loader["`**RulesLoader**<br/><small>lädt die YAML-Liste zur<br/>Container-Compile-Zeit</small>`"]
    loader --> rules

    classDef capture fill:#E1F5EE,stroke:#0F6E56,color:#085041
    classDef transport fill:#F1EFE8,stroke:#5F5E5A,color:#3A3936
    classDef data fill:#EEEDFE,stroke:#534AB7,color:#332C7A
    class h,q,f,j,b,m data
    class rules,cleaner,loader transport
    class out capture
    style inputs fill:#FCFCFF,stroke:#534AB7,color:#332C7A
    style chain fill:#FBFBF9,stroke:#5F5E5A,color:#3A3936
```

Sechs verschiedene Wege führen in **denselben** `Cleaner` mit **derselben** Liste. Das ist
Absicht: eine zweite Liste wäre eine zweite Gelegenheit, sie unvollständig zu halten.

Die Liste wird zur **Container-Compile-Zeit** gelesen, nicht pro Request — eine YAML-Datei
je Request zu parsen wäre Dateizugriff im Request-Pfad und damit nach (*2.1*)
ausgeschlossen. Ändert sich die Datei, invalidiert das den Container-Cache.

## Redigiert wird beim Aufbau, nicht danach

Der `Cleaner` wird **während** des Zusammenbaus der Daten aufgerufen, nicht als
nachgelagerter Durchlauf über eine fertige Struktur. Der Unterschied ist keine
Geschmacksfrage: so existiert ein unredigierter Wert **zu keinem Zeitpunkt** in einer
serialisierbaren Struktur. Ein nachgelagerter Durchlauf ließe zwischen Aufbau und
Redaktion ein Fenster offen, in dem ein Fehler, ein `var_dump` oder ein Exception-Trace
den Klartext sichtbar machte.

## Was mitgeliefert wird

`config/payload_confidentiality_cleanup.dist.yaml`, versioniert. Die Fassung reist als
`cleanup_version` in jedem `raw` mit, damit nachvollziehbar bleibt, nach welchen Regeln
ein Beleg entstanden ist.

| Kategorie | Vergleich | Einträge |
|---|---|---|
| Header | vollständiger Name | `Cookie`, `Set-Cookie`, `Authorization`, `Proxy-Authorization`, `X-API-Key`, `X-Auth-Token`, `X-CSRF-Token`, `X-Debug-Exception`, `X-Debug-Exception-File` |
| Parameter | Teilzeichenkette im Namen | `password`, `passwd`, `pwd`, `secret`, `token`, `_token`, `api_key`, `apikey`, `private_key`, `credit_card`, `cvv`, `iban` |

Groß-/Kleinschreibung sowie `-` und `_` werden vor dem Vergleich normalisiert: `api_key`,
`api-key` und `apikey` treffen dasselbe Muster.

Eigene Einträge gehören **nicht** in die mitgelieferte Datei — sie wird mit dem Bundle
aktualisiert. Stattdessen eine eigene Liste:

```yaml
ids_sensor:
    payload_confidentiality_cleanup:
        config: '%kernel.project_dir%/config/ids_cleanup.yaml'
        merge_defaults: true   # ergänzt die mitgelieferte Liste
```

`merge_defaults: true` ist der Regelfall und die Vorgabe. Andernfalls würde eine Anwendung,
die nur `x_tenant_secret` hinzufügen will, versehentlich `Cookie` und `Authorization`
freischalten. Wer die Liste *verkleinern* muss, setzt `false` und übernimmt die
Verantwortung ausdrücklich.

## Cookies: nur die Namen

Der `Cookie`-Header ist über die Liste bereits unkenntlich. Zusätzlich werden die
Cookie-**Namen** einzeln übertragen — der Kompromiss, mit dem sichtbar bleibt, welche
Sitzungs- und Tracking-Cookies eine Anfrage mitbrachte, ohne einen einzigen Wert
auszuschreiben. Ein Wert hier wäre exakt der Session-Hijacking-Vektor, den (*4.5.1*)
ausschließen will.

Aus demselben Grund wird die Session-ID nie übertragen, sondern nur ihr SHA-256. Getragen
wird die Einwegbeziehung von der Entropie der ID — PHP erzeugt vorgabemäßig 130 bis 160
Bit —, nicht von einem Schlüssel. Einen solchen gab es bis Fassung 2, und er wirkte gegen
das Bedrohungsmodell dieses Bundles nie: Die überwachte Anwendung muss ihn lesen können,
ein Angreifer mit Codeausführung hat ihn also genauso wie `APP_SECRET`. Was an seine Stelle
tritt, ist eine Prüfung der Session-ID-Entropie in `ids:sensor:setup-check` (*siehe
[08](08-konfiguration.md#session_hash)*).

## Der JSON-Anfragekörper

Symfony parst nur **formularkodierte** Körper in `$request->request`. Ein JSON-Körper
landet dort nie — für jede API-Anfrage blieb `raw.request_params` deshalb leer, und mit ihm
der Beleg für *Szenario S5* (Deserialisierung über API-Payloads), dem das Konzept an anderer
Stelle „vollständige Verfügbarkeit" zusagte.

Der Körper wird deshalb gelesen, und zwar unter drei Bedingungen — sie sind der Grund,
warum das die Regel aus (*3.5*) nicht bricht, sondern präzisiert:

1. **nach** dem Absenden der Antwort (die `raw`-Closure läuft in Phase B), die Nutzlast der
   Anwendung ist also nicht betroffen;
2. nur für `warning`/`critical`, also nicht im Regelverkehr;
3. erst nachdem `Content-Length` gegen `raw.max_request_body_bytes` geprüft wurde — gelesen
   wird nie unbegrenzt.

Der dekodierte Körper läuft danach durch **dieselbe** Denylist wie Formularfelder und der
Business-Payload. Er steht in `raw.request_body`, getrennt von `request_params`, damit die
Herkunft ablesbar bleibt: dort steht, was das Framework gelesen hat, hier das, was der
Sensor selbst gelesen hat.

**Ein nicht dekodierbarer Körper kommt nicht als Text mit.** Die Redaktion greift über
Feldnamen; ohne Struktur gibt es keine Feldnamen und damit keine Redaktion. Übertragen wird
dann der Grund (`raw.request_body_omitted: undecodable`), nicht der Inhalt. Dasselbe gilt
für alles, was kein JSON ist.

## URLs sind ein eigener Eintrittspunkt

`Referer`, `Location` und `Content-Location` tragen als Wert eine **vollständige URL** —
und damit sensible Werte in einem Feld, dessen *Name* unauffällig ist. Die Denylist
greift über Namen und läuft hier ins Leere.

Praktisch wichtig ist der Referer: Wer `https://app.example/reset?token=…` öffnet und
dort einen Link anklickt, schickt das Token im `Referer` mit. Dieselbe Klasse:
`?signature=`, OAuth-`?code=`, Magic-Links. Betroffen sind **zwei** Felder —
`payload.referer` (reist bei jeder Stufe mit, also auch bei `info`) und
`raw.request_headers.referer`.

Diese Header werden deshalb weder durchgereicht noch vollständig ersetzt: ihr
Query-String läuft durch dieselbe Parameter-Denylist wie `payload.query`, Herkunft und
Pfad bleiben stehen. Die Herkunft eines Zugriffs ist bei jeder Scanning- und
Rechteausweitungsregel eine Auskunft; vollständig zu ersetzen wäre zu viel. Zugangsdaten
in der URL (`https://nutzer:geheim@host/`) werden weggelassen — sie sind nie eine
Auskunft, aber immer ein Geheimnis. Eine nicht zerlegbare Zeichenkette wird vollständig
ersetzt: was der Sensor nicht versteht, kann er auch nicht redigieren.

## Exception-Meldungen: redigiert, aber nur in Query-Schreibweise

`payload.exception_message` ist der dritte Eintrittspunkt derselben Klasse. Die Meldung
ist angreiferbeeinflusst — das Konzept nennt selbst „No route found for GET
/wp-admin/setup-config.php" —, und sie trägt oft die angefragte URI **samt Query**. Wie
`payload.referer` reist sie bei **jeder** Stufe mit, nicht nur bei `warning`/`critical`
wie `raw`.

Redigiert wird darin die Query-Schreibweise `name=wert`, abgegrenzt durch `?`, `&`,
Leerraum oder Zeilenanfang — also genau die Form, in der URLs und Formulardaten in
Meldungen landen. Der Name entscheidet über dieselbe Denylist wie überall sonst.

**Nicht** erfasst wird ein Geheimnis, das in Prosa oder in fremder Syntax steht — etwa
`WHERE password = 'geheim'` in einer `PDOException`. Das ist eine Entscheidung, kein
Versehen: Dafür bräuchte es eine Grammatik je Quellsprache, und ein Muster, das jedes
Wort neben einem Denylist-Namen schwärzt, machte die Meldung als Erkennungsgrundlage
wertlos. Wer Datenbank-Exceptions mit Parameterwerten erwartet, schaltet sie in der
Anwendung ab (`PDO::ATTR_ERRMODE` bzw. Doctrines Fehlerbehandlung) — dort, wo sie
entstehen.

Die Meldungen der **inneren** Exceptions einer Kette stehen ohnehin nirgends: `raw`
führt die Kette nur als Klassennamen mit Ort. Die äußerste Meldung ist die einzige
Ausnahme, weil Konzept 3.1.1 sie im Payload verlangt.

## Symfonys Debug-Header

`X-Debug-Exception` und `X-Debug-Exception-File` setzt Symfonys `ErrorListener` im
Debug-Modus in die **Antwort** — und der erste trägt die vollständige, URL-kodierte
Exception-Meldung. `raw.response_headers` kopierte sie damit im Klartext, während
dieselbe Meldung in `payload.exception_message` durch die Denylist läuft: Ein
`?password=` im angefragten Pfad stand im Payload redigiert und im `raw`-Feld lesbar.

Beide stehen deshalb seit Fassung **2** der Liste in der Denylist. Forensisch kostet das
nichts — die Meldung steht bereits im Payload.

Aufgefallen ist das unter Symfony 6.4, der unteren Grenze der Abhängigkeiten dieses
Pakets. Der reguläre Testlauf sieht sie nicht; `make test-lowest` schon.

## Was das nicht leistet

> **Ehrliche Einordnung** (*4.5.1*): Dies ist eine Denylist und teilt deren grundsätzliche
> Schwäche — unbekannte Feldnamen werden nicht erfasst. Auch vollständig redigiert bleibt
> `raw` sensibel, weil es Geschäftsdaten und personenbezogene Formularinhalte enthält. Die
> Redaktion senkt das Schadensmaß bei einer Kompromittierung, sie beseitigt es nicht.

Der Ordner heißt `PayloadConfidentialityCleanup` und nicht `Privacy`, und das ist kein
Zufall: er stellt **Vertraulichkeit von Zugangsdaten** her, nicht Datenschutz. Ein Feld
`geburtsdatum` steht danach immer noch im Klartext in `raw`.

**Diese Formate erreicht die Liste nicht**, und das ist eine Entscheidung: XML- und
PHP-serialisierte Anfragekörper werden nicht dekodiert und deshalb auch nicht übertragen —
eine Grammatik je Format wäre eine zweite Redaktionsimplementierung mit einer zweiten
Gelegenheit, sie unvollständig zu halten. `multipart/form-data` bleibt ebenfalls draußen
(`raw.skip_multipart`). In allen drei Fällen nennt `raw.request_body_omitted` den Grund; die
Größe des Körpers steht ohnehin als `payload.content_length` im Event, die *Signatur* eines
Deserialisierungsversuchs geht also nicht verloren.

Umgekehrt gilt: Ein JSON-Körper enthält in der Regel **mehr** personenbezogene Daten als ein
Formular, nicht weniger. Was daraus folgt, steht unten unter „Betreiberpflichten".

`raw` enthält personenbezogene Daten. Die Entscheidung, Datenschutzaspekte im Bundle
nachrangig zu behandeln, ist im Konzept bewusst getroffen — Priorität auf forensische
Vollständigkeit. Sie ist damit **nicht** die Aussage, dass es keine gibt, sondern die, dass
sie außerhalb des Bundles liegen (*OB8*).

Wer `raw` ganz vermeiden will: `raw.enabled: false`. Wer nur den sensibelsten Teil
weglassen will: `raw.include_request_body: false`. Beides kostet forensische Tiefe, nicht
Erkennung — die Erkennungsregeln arbeiten auf `payload`, nicht auf `raw`.

### Die Gegenrichtung: `raw.always_for`

Die Stufenregel schneidet auch dort, wo man nicht schneiden will. Ob `raw` mitreist, hängt
an `event_severity`; ein Alarm entsteht aber erst im Collector und kann nicht zurückwirken.
Ein Befund wie R2b („Pfadlisten-Treffer mit Status 200") stand deshalb ohne Beleg da — das
Event ist `info`, das `raw` war längst verworfen. Das war der offene Punkt (*OB11*).

`raw.always_for.event_types` und `raw.always_for.path_patterns` benennen Ausnahmen. Sie
sind die **einzige** Stelle, an der die Stufengrenze nach oben durchbrochen werden kann,
und leer als Vorgabe. Der Sensor liefert damit Kandidaten, der Collector filtert weiter —
nur er kennt die Regeln.

**Datenschutzrechtlich ist das ein Hebel in die andere Richtung:** Was hier eingetragen
wird, überträgt personenbezogene Daten bei Vorgängen, die bislang keine übertrugen. Die
Liste gehört deshalb zu den Punkten, die im Abschnitt „Betreiberpflichten" unten
dokumentiert sein wollen — und nicht weiter gefasst, als der Beleg es verlangt.

## Betreiberpflichten (*OB8*)

Das Bundle stellt Vertraulichkeit von **Zugangsdaten** her. Datenschutz stellt es nicht her,
und es kann es nicht: Ob eine Verarbeitung zulässig ist, wie lange sie zulässig bleibt und
wer darüber Auskunft bekommt, entscheidet sich an der Anwendung und ihrem Betrieb, nicht an
einer Denylist. Die drei Fragen gehören deshalb **vor** den produktiven Einsatz mit echten
Nutzerdaten geklärt — hier steht, welcher Hebel des Bundles jeweils dazugehört.

### 1. Rechtsgrundlage

Der Sensor verarbeitet personenbezogene Daten in drei Feldgruppen, unabhängig von jeder
Konfiguration:

| Wo | Was | Abschaltbar über |
|---|---|---|
| `actor.user` | die Benutzerkennung, häufig eine E-Mail-Adresse | — (Pflichtfeld, siehe unten) |
| `actor.ip` | die Client-IP | — (Pflichtfeld) |
| `actor.session_id_hash`, `actor.client_fingerprint` | Wiedererkennungsmerkmale | `session_hash.enabled`, `fingerprint.enabled` |
| `raw` | Formularfelder, JSON-Körper, Header, Query | `raw.enabled`, `raw.include_request_body` |

Die vier `actor.*`-Felder sind laut (*2.2.4*) „immer vorhanden, aber nullable" — sie lassen
sich nicht wegkonfigurieren, weil ohne Akteur keine der nutzerbezogenen Regeln arbeitet.
Wer sie nicht verarbeiten darf, kann das Bundle nicht betreiben; das ist die ehrliche
Auskunft und keine Einstellung.

`raw` ist der einzige Block, der ganz entfallen kann. Das kostet forensische Tiefe, nicht
Erkennung — die Regeln arbeiten auf `payload`.

### 2. Aufbewahrungsfristen

**Der Sensor speichert nichts dauerhaft.** Er hält Ereignisse für die Dauer eines Requests
im Puffer und, wenn der Collector nicht erreichbar ist, im Spool auf der Platte. Zwei
Stellen gehören trotzdem in eine Löschfrist-Betrachtung:

| Ort | Inhalt | Grenze |
|---|---|---|
| `spool.dir` | vollständige Frames samt `raw`, unverschlüsselt | `spool.max_bytes`; geleert vom minütlichen `ids:sensor:spool:flush` |
| Logdateien | keine Ereignisdaten, aber Fehlermeldungen des Sensors | Logrotation der Anwendung |

Der Spool ist der Punkt, der überrascht: Bei einem längeren Ausfall des Collectors liegen
dort personenbezogene Daten auf der Platte der überwachten Anwendung — genau dort, wo ein
Angreifer mit Codeausführung sie fände. Er gehört auf ein Verzeichnis mit
Betriebssystemrechten, die der Webserver-Nutzer allein hat, und er gehört überwacht:
Läuft der cron nicht, wächst er bis `spool.max_bytes` und verwirft dann.

**Die eigentliche Aufbewahrung liegt beim Collector** (*4.2.3*) und ist dort gestuft. Wer
Fristen festlegt, legt sie dort fest, nicht hier.

### 3. Auskunftsfähigkeit

Ein Auskunftsersuchen trifft den Collector, nicht den Sensor — dort liegen die Daten. Was
der Sensor dafür liefern muss, ist die **Auffindbarkeit**: `actor.user` ist das Feld, über
das sich die Ereignisse einer Person zusammenführen lassen, und es steht in jedem Ereignis,
in dem eine Identität bekannt war.

Zwei Dinge, die dabei zu bedenken sind:

- **`actor.user` ist angreiferkontrolliert** (*4.5.3*). Bei einem gescheiterten
  Anmeldeversuch steht dort die *versuchte* Kennung — die kann jede beliebige Zeichenkette
  sein, auch die einer unbeteiligten Person. Eine Auskunft, die stur nach `actor.user`
  filtert, gibt fremde Ereignisse heraus.
- **`session_id_hash` und `client_fingerprint` sind Pseudonyme, keine Anonymisierung.** Sie
  verketten Ereignisse derselben Sitzung beziehungsweise desselben Clients und fallen
  damit unter dieselbe Betrachtung wie die Kennung selbst.

### Was der Betreiber zusätzlich entscheidet

Zwei Einstellungen erweitern die Verarbeitung über die Vorgabe hinaus und gehören deshalb
ausdrücklich dokumentiert, wenn sie benutzt werden:

- **`raw.always_for`** überträgt Belege bei Vorgängen, die bislang keine übertrugen (siehe
  oben). Nicht weiter fassen, als der Beleg es verlangt.
- **eine eigene Redaktionsliste mit `merge_defaults: false`** verkleinert die Denylist und
  kann Felder freischalten, die die mitgelieferte Liste abdeckt.

## Abgrenzung: drei Dinge, die alle „bereinigen"

Nur eines davon stellt Vertraulichkeit her. Die Verwechslung ist naheliegend genug, dass
sie hier ausdrücklich steht:

| Klasse | Stellt her | Beispiel |
|---|---|---|
| `PayloadConfidentialityCleanup\Cleaner` | **Vertraulichkeit** | `password` → `[confidential]` |
| `Processing\Normalization\PayloadSanitizer` | Formkonformität | Objekt → Skalar, Tiefe ≤ 3, `_ids_`-Schlüssel entfernt |
| `Processing\Normalization\QueryNormalizer` | Struktur | Query-String → flaches Objekt |

Der `PayloadSanitizer` ist dabei kein Konkurrent, sondern ein **Konsument**: er ruft den
`Cleaner` selbst auf.
