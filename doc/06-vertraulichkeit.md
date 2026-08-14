# 06 — Vertraulichkeit

Sensible Werte werden **im Sensor** unkenntlich gemacht, bevor etwas den Prozess verlässt
(*4.5.1*). Nicht im Collector — andernfalls liefen Klartext-Zugangsdaten über den Broker
und landeten dort in Queues, Logs und Spool-Dateien.

Die Feldnamen bleiben erhalten: dass eine Anfrage ein Feld `password` mitbrachte, ist
forensisch relevant; sein Inhalt nicht.

## Vier Eintrittspunkte, eine Liste

```mermaid
flowchart LR
    subgraph inputs["Was durch die Liste läuft"]
        direction TB
        h["Request- und<br/>Response-Header"]
        q["payload.query"]
        f["Formularfelder<br/>in raw"]
        b["Business-Payload"]
    end

    subgraph chain["Support/PayloadConfidentialityCleanup/"]
        direction TB
        rules["`**Rules**<br/><small>die Denylist,<br/>vergleichsfertig</small>`"]
        cleaner["`**Cleaner**<br/><small>ersetzt Werte durch<br/>[confidential]</small>`"]
        rules --> cleaner
    end

    h -->|"RawPayload\\Builder"| cleaner
    f -->|"RawPayload\\Builder"| cleaner
    q -->|"QueryNormalizer"| cleaner
    b -->|"PayloadSanitizer"| cleaner

    cleaner --> out["Frame<br/><small>kein Klartext mehr</small>"]

    loader["`**RulesLoader**<br/><small>lädt die YAML-Liste zur<br/>Container-Compile-Zeit</small>`"]
    loader --> rules

    classDef capture fill:#E1F5EE,stroke:#0F6E56,color:#085041
    classDef transport fill:#F1EFE8,stroke:#5F5E5A,color:#3A3936
    classDef data fill:#EEEDFE,stroke:#534AB7,color:#332C7A
    class h,q,f,b data
    class rules,cleaner,loader transport
    class out capture
    style inputs fill:#FCFCFF,stroke:#534AB7,color:#332C7A
    style chain fill:#FBFBF9,stroke:#5F5E5A,color:#3A3936
```

Vier verschiedene Wege führen in **denselben** `Cleaner` mit **derselben** Liste. Das ist
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
| Header | vollständiger Name | `Cookie`, `Set-Cookie`, `Authorization`, `Proxy-Authorization`, `X-API-Key`, `X-Auth-Token`, `X-CSRF-Token` |
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

Aus demselben Grund wird die Session-ID nie übertragen, sondern nur ihr HMAC. Der
Schlüssel dafür ist ausdrücklich **nicht** `APP_SECRET`: die überwachte Anwendung kennt
`APP_SECRET` und könnte aus einer gestohlenen Event-Datenbank die Hashes nachrechnen.

## Was das nicht leistet

> **Ehrliche Einordnung** (*4.5.1*): Dies ist eine Denylist und teilt deren grundsätzliche
> Schwäche — unbekannte Feldnamen werden nicht erfasst. Auch vollständig redigiert bleibt
> `raw` sensibel, weil es Geschäftsdaten und personenbezogene Formularinhalte enthält. Die
> Redaktion senkt das Schadensmaß bei einer Kompromittierung, sie beseitigt es nicht.

Der Ordner heißt `PayloadConfidentialityCleanup` und nicht `Privacy`, und das ist kein
Zufall: er stellt **Vertraulichkeit von Zugangsdaten** her, nicht Datenschutz. Ein Feld
`geburtsdatum` steht danach immer noch im Klartext in `raw`.

`raw` enthält personenbezogene Daten. Die Entscheidung, Datenschutzaspekte dort nachrangig
zu behandeln, ist im Konzept bewusst getroffen (Priorität auf forensische Vollständigkeit)
und **vor einem produktiven Einsatz mit echten Nutzerdaten erneut zu prüfen** — betroffen
sind Rechtsgrundlage, Aufbewahrungsfristen und Auskunftsfähigkeit. Offener Punkt B8 in
(*6.3*).

Wer `raw` ganz vermeiden will: `raw.enabled: false`. Wer nur den sensibelsten Teil
weglassen will: `raw.include_request_body: false`. Beides kostet forensische Tiefe, nicht
Erkennung — die Erkennungsregeln arbeiten auf `payload`, nicht auf `raw`.

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
