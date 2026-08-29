# 08 — Konfigurationsreferenz

Alle Schlüssel unter `ids_sensor`, mit Vorgabewert und Wirkung. Der Baum steht in
`DependencyInjection\ConfigurationTree`; **alle `ids_sensor`-Schlüssel gehören zur
öffentlichen API** und unterliegen Semver.

Vollständige Ausgabe der aktuell wirksamen Werte:

```bash
php bin/console config:dump-reference ids_sensor
php bin/console debug:config ids_sensor
```

## Mindestkonfiguration

Vier Angaben sind Pflicht:

```yaml
# config/packages/ids_sensor.yaml
ids_sensor:
    application_id: '%env(IDS_APPLICATION_ID)%'
    environment_id: '%env(IDS_ENVIRONMENT_ID)%'
    sensor_id: '%env(IDS_SENSOR_ID)%'
    session_hash:
        key: '%env(IDS_SESSION_HASH_KEY)%'
    collector:
        base_uri: '%env(IDS_COLLECTOR_URL)%'
        username: '%env(IDS_COLLECTOR_USER)%'
        password: '%env(IDS_COLLECTOR_PASSWORD)%'
```

## Herkunftskennung

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | `false` schaltet alle Sensoren ab, ohne das Bundle zu entfernen |
| `application_id` | **Pflicht** | UUID der überwachten Anwendung |
| `environment_id` | **Pflicht** | UUID der Umgebung |
| `sensor_id` | **Pflicht** | UUID dieser Installation — **je Node verschieden** |

Alle drei vergibt der Collector beim Registrieren. Der Sensor leitet keine davon ab: Den
Anzeigenamen einer Umgebung führt das Anwendungsregister, und er darf sich ändern, ohne
dass hier etwas nachzuziehen ist.

**`sensor_id`**: Tragen zwei Replicas dieselbe, sind sie in jeder Auswertung *ein* Sensor,
und `ids.sensor_silent` schweigt, wenn einzelne ausfallen. In Kubernetes teilen sich alle
Replikate eines Deployments eine ConfigMap — die Kennung muss also je Node kommen: eigenes
Secret, Downward API oder knotenspezifische Datei. `application_id` und `environment_id`
dürfen geteilt sein.

Die mitgelieferte Abbildung — eigene Einträge werden **hinzugemischt**, nicht dagegen
ausgetauscht:

| Rohwert | → | Rohwert | → |
|---|---|---|---|
| `prod`, `production`, `live` | `prod` | `dev`, `develop`, `development` | `dev` |
| `staging`, `stage`, `preprod` | `staging` | `local`, `test` | `dev` |

```yaml
ids_sensor:
    environment_map:
        prod_eu_west: prod      # ergänzt, die Vorgaben bleiben
    environment_fallback: prod
```

Der Rückfall ist `prod` und nicht `dev`: fälschlich als `prod` markierter Verkehr wird
weiterhin erkannt; fälschlich als `dev` markierter fällt aus **jeder**
Produktionsauswertung heraus. `ids:sensor:setup-check` bricht mit Rückgabewert 1 ab, wenn
der Wert nicht abbildbar ist.

## `session_hash`

Die rohe Session-ID wird **nie** übertragen — sonst wäre der Beweisspeicher selbst ein
Session-Hijacking-Vektor (*2.2.4*). Übertragen wird ein HMAC.

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | `false` arbeitet bewusst ohne Sitzungsverkettung |
| `key` | `null` | dedizierter HMAC-Schlüssel, ≥ 32 Zeichen |
| `min_key_length` | `32` | Untergrenze der Prüfung |
| `cookie_name` | `null` | `null` ermittelt ihn aus der Framework-Konfiguration |

Der Schlüssel ist ausdrücklich **nicht** `APP_SECRET`: die überwachte Anwendung kennt
`APP_SECRET` und könnte aus einer gestohlenen Event-Datenbank die Hashes nachrechnen.

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Fehlt der Schlüssel, bricht die **Container-Kompilierung** ab — der einzige Punkt, an dem
dieses Bundle nicht fail-open ist. Ein stilles `null` würde die sitzungsbezogenen Regeln
unsichtbar abschalten.

Steckt im Schlüssel ein `%env()%`-Platzhalter — der empfohlene Fall —, sind Länge und
Gleichheit mit `APP_SECRET` beim Kompilieren **nicht** prüfbar: der Wert ist dort noch nicht
aufgelöst. Diese beiden Prüfungen holt `ids:sensor:setup-check` im Deploy nach, gegen den
tatsächlich benutzten Schlüssel. Ein zu kurzer oder mit `APP_SECRET` identischer Wert ist
dort ein Befund mit Rückgabewert 1.

`cookie_name` wird aus `framework.session.name` gelesen, nicht aus `php.ini`: Symfony
schreibt den konfigurierten Namen erst dann nach `php.ini`, wenn die Session-Storage
tatsächlich entsteht — und das ist im Erfassungspfad regelmäßig zu spät.

**Eine Rotation bricht die Sitzungsverkettung**: Events von vor und nach der Rotation
tragen für dieselbe Sitzung verschiedene Hashes.

## `fingerprint`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `headers` | `User-Agent`, `Accept-Language`, `Accept-Encoding` | feste Feldfolge laut (*2.2.4*) |

Bewusst schmal: je mehr Header einfließen, desto häufiger ändert sich der Fingerprint aus
harmlosen Gründen. Eine Änderung ändert **jeden** Fingerprint und macht die
sitzungsbezogene Regel B9 für die Übergangszeit blind.

## `correlation`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `trust_incoming_header` | `false` | einen eingehenden Request-ID-Header übernehmen |
| `incoming_header` | `X-Request-Id` | dessen Name |
| `require_trusted_proxy` | `true` | Header nur von einem konfigurierten Trusted Proxy übernehmen |
| `expose_request_attribute` | `true` | legt die `correlation_id` als Request-Attribut ab, damit die Anwendung sie mitloggen kann |

`trust_incoming_header` ist aus gutem Grund aus: ein eingehender Header ist
angreifergesteuert, solange kein Reverse Proxy ihn überschreibt. Ein Angreifer könnte damit
die `correlation_id` eines Opfers übernehmen und die forensische Zuordnung vergiften.

## `layers`

### `layers.kernel`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `events.request` / `.response` / `.exception` | `true` | einzelne Hooks |
| `ignored_paths` | `[]` | PCRE-Muster **mit Trennzeichen** (`#^/health$#`); **absichtlich leer** — Regel R2b lebt davon, Zugriffe auf `/_profiler` zu sehen |
| `sub_requests` | `exceptions_only` | `none` · `exceptions_only` · `all` |
| `capture_fatal_errors` | `true` | rettet den Puffer in den Spool, wenn der Prozess vor `kernel.terminate` stirbt |

### `layers.security`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `authentication` | `true` | An- und Abmeldeversuche |
| `access_decision` | `true` | dekoriert den `AccessDecisionManager`, feuert bei jedem `isGranted()` |
| `capture_granted` | `true` | `false` erfasst nur Ablehnungen — halbiert das Volumen, kostet die Positivpfad-Regeln |
| `max_decisions_per_request` | `200` | Hard-Cap; Überlauf zählt `dropped_decision_cap` |

### `layers.business`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `capture_mode` | `dispatcher` | `dispatcher` · `recorder` · `configured` — siehe [09](09-business-ebene.md) |
| `event_classes` | `[]` | nur für `configured`: Liste der Event-FQCNs |
| `user_from_token` | `true` | ergänzt `actor.user` aus dem Security-Token, wenn `getActorId()` `null` liefert |
| `ip_from_request` | `true` | ergänzt `actor.ip` aus dem laufenden Request |

## `raw`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | `false` lässt das Feld ganz weg |
| `severities` | `warning`, `critical` | lässt sich nur **verkleinern**, nicht erweitern; unbekannte Stufen werden beim Kompilieren abgelehnt |
| `max_bytes` | `32768` | Kappungsgrenze je `raw` |
| `include_request_body` | `true` | der sensibelste Teil, deshalb ein eigener Schalter — gilt für Formularfelder **und** JSON-Körper |
| `skip_multipart` | `true` | Datei-Uploads würden den Frame sprengen |
| `max_request_body_bytes` | `32768` | Obergrenze des JSON-Körpers, geprüft am `Content-Length` **vor** dem Lesen; `0` nimmt keinen Körper auf |

**`max_request_body_bytes` und `max_bytes` greifen ineinander.** Die erste Grenze lässt den
Körper herein, die zweite wirft ihn wieder hinaus — bei der Kappung steht `request_body` an
erster Stelle der Abbaureihenfolge. Bei den Vorgaben (beide `32768`) überleben Körper bis
etwa 28 KiB; darüber werden sie gelesen, redigiert und dann verworfen. Wer größere Körper
wirklich behalten will, hebt `max_bytes` mit an. Ist `max_request_body_bytes` **größer** als
`max_bytes`, meldet `ids:sensor:setup-check` einen Hinweis: Dann ist jeder Körper, der die
erste Grenze ausschöpft, garantiert verloren.

## `payload_confidentiality_cleanup`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `config` | `null` | Pfad zur eigenen Liste; `null` nutzt die mitgelieferte |
| `merge_defaults` | `true` | `false` ersetzt die mitgelieferte Liste vollständig |
| `replacement` | `[confidential]` | der Ersetzungsmarker |

Details in [06 — Vertraulichkeit](06-vertraulichkeit.md).

## `sampling`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `info_rate` | `1.0` | gilt **nur** für `layer=kernel` **und** `severity=info` |
| `keep_if_request_relevant` | `true` | behält die info-Events eines Requests, der ein `warning`/`critical` enthält |

Details in [04 — Request-Lebenszyklus](04-request-lebenszyklus.md#sampling-einen-teil-gar-nicht-erst-senden).

## `budget`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `capture_us` | `1500` | Erfassungsbudget im Request; `0` = unbegrenzt (CLI/Worker) |
| `dispatch_ms` | `50` | Versandbudget nach dem Absenden der Antwort |
| `connect_timeout_ms` | `20` | Verbindungsaufbau zum Collector |
| `read_timeout_ms` | `30` | Antwort des Collectors |
| `fatal_dispatch_ms` | `15` | Zeitrahmen für die Rettung im Shutdown; Überschreitung wird protokolliert, nicht abgebrochen |
| `max_events_per_request` | `64` | Puffergrenze pro Durchlauf; Pflicht-Events haben acht Plätze darüber hinaus |

`dispatch_ms` wird als Frist **zwischen** den Operationen geprüft — PHP kann einen
laufenden Syscall nicht abbrechen.

**Warum Pflicht-Events eine eigene Reserve haben.** `max_events_per_request` begrenzt,
was eine Übersichtsseite mit einem Voter pro Zeile an Autorisierungsentscheidungen
erzeugen darf. `kernel.request`, `kernel.response`, `kernel.exception` und die
Anmeldeereignisse sind konstruktionsbedingt begrenzt und zählen gegen ein eigenes,
kleines Kontingent oberhalb dieser Grenze. Ohne das hätte eine Seite mit 64
Rechteprüfungen den `kernel.response` verdrängt — und damit `http_status`, an dem die
Severity-Ableitung und die Scanning-Erkennung über gehäufte 403/404 hängen. Auch die
Reserve ist endlich; was darüber hinausgeht, wird verworfen und als
`dropped_buffer_full` gezählt.

## `flush`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `policy` | `auto` | `auto` · `direct` · `spool` |
| `max_frame_bytes` | `262144` | Obergrenze je Frame |

`auto` erkennt, ob die Antwort abkoppelbar ist. Warum das die Vorgabe ist und was `direct`
auf einer mod_php-Installation anrichtet, steht in
[05 — Versandweg](05-versandweg.md#schranke-1-ist-die-antwort-abkoppelbar).

## `collector`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `base_uri` | `null` | Basisadresse des Collectors; `null` lässt den Sensor erfassen, aber nichts senden |
| `username` | `null` | Benutzername der Zugangsdaten |
| `password` | `null` | Passwort — gehört in eine Umgebungsvariable, nicht in die Datei |
| `token_leeway_s` | `60` | Vorlauf, mit dem das Zugangstoken **vorausschauend** erneuert wird |
| `verify_tls` | `true` | Zertifikatsprüfung |

**`token_leeway_s`**: Der Sensor holt sein Token an `/api/v1/token` und legt es
prozessübergreifend ab. Erneuert wird vor dem Ablauf, nicht erst auf ein `401` — eine
Erneuerung im Fehlerfall wäre ein zweiter Netzwerk-Roundtrip innerhalb des
50-ms-Versandbudgets, also genau das, was das Budget verhindern soll.

**`verify_tls: false`** verwandelt eine authentifizierte Verbindung in eine, die jeder auf
dem Weg übernehmen kann, und es fällt im Betrieb nicht auf. `ids:sensor:setup-check`
meldet es als Befund, ebenso eine `base_uri` ohne `https://`.

**Ohne `base_uri` bleibt der NullShipper stehen.** Das Bundle ist damit installierbar,
bevor ein Collector bereitsteht — nützlich, um das Erfassungsbudget zu messen, ohne dass
Netzlatenz und Sensorkosten sich vermischen. `ids:sensor:spool:flush` verweigert dann den
Dienst, statt den Spool stillschweigend zu leeren.

## `spool`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `dir` | `null` | `null` nutzt `%kernel.project_dir%/var/ids-spool`; **muss node-lokal sein** |
| `max_bytes` | `16777216` | Gesamtgrenze; Überlauf zählt `dropped_spool_full`. **`0` nimmt nichts auf** — `setup-check` meldet es als Befund |
| `max_file_bytes` | `4194304` | Grenze je Datei, ab der der Schreiber versiegelt |
| `drain_interval_s` | `30` | der Takt: nach dieser Zeit versiegelt der Schreiber seine Datei, und der Drainer versiegelt stellvertretend, woran niemand mehr schreibt. Reist zusätzlich im Heartbeat mit, damit der Collector die normale Verzögerung kennt |
| `drain_max_files_per_run` | `2` | |
| `stale_after_s` | `300` | ab wann eine vom Drainer **beanspruchte** Datei als liegengeblieben gilt und zurückgeholt wird |

`drain_interval_s` ist die Zusage aus *3.3.1* für `deferred`: ein Frame wartet höchstens ein
Drain-Intervall auf seine Versiegelung. `stale_after_s` ist eine andere Frage — es geht dort
um einen abgebrochenen Drain-Lauf, nicht um einen stillen Schreiber. Die beiden Fristen
dürfen deshalb nicht dieselbe sein: Mit 300 s käme ein Frame außerhalb der consumerseitigen
Toleranz an und würde wie `recovered` behandelt, also von den Echtzeit-Regeln ausgenommen.

Es gibt **keinen** `spool.enabled`-Schalter, und das ist Absicht — Begründung in
[05 — Versandweg](05-versandweg.md#der-spool).

## `circuit_breaker`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `failure_threshold` | `3` | Fehler bis zum Öffnen; `0` öffnet beim **ersten** |
| `open_for_s` | `30` | Offenzeit; **`0` macht den Breaker wirkungslos** |

`open_for_s: 0` zählt Fehlschläge, meldet `half_open` und sperrt nie — jeder Request
zahlt bei einem Collector-Ausfall weiterhin die vollen Timeouts. `ids:sensor:setup-check`
meldet das als Befund.

Die Zustandsübergänge stehen in
[05 — Versandweg](05-versandweg.md#schranke-2-der-circuit-breaker).

## `heartbeat`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `enabled` | `true` | |
| `mode` | `auto` | `auto` · `request` · `command` · `off` |
| `interval_s` | `60` | Drosselungsintervall; **`0` stellt das automatische Senden ein** |
| `stamp_file` | `null` | Rückfallablage neben APCu |

**Drei Wege, den Heartbeat leiser zu stellen — sie bedeuten Verschiedenes:**

| Einstellung | Wirkung | Was der Collector sieht |
|---|---|---|
| `enabled: false` | Die Dienste werden gar nicht erst registriert | Dauerhaft `ids.sensor_silent` |
| `interval_s: 0` | Dienste bleiben, es wird nichts mehr von selbst gesendet | Dauerhaft `ids.sensor_silent` |
| `mode: command` | Nur der cron sendet, der Request-Pfad nicht | Nichts, **solange der cron läuft** |

`interval_s: 0` ist damit ein Abschaltweg und keine Drosselung auf „so oft wie möglich".
`ids:sensor:heartbeat --force` sendet weiterhin — die Option sagt ausdrücklich, dass sie
das Intervall übergeht, und wer sie tippt, will senden. Für einen dauerhaft stillen
Sensor ist `enabled: false` der ehrlichere Weg: Er entfernt die Dienste, statt sie
wirkungslos mitlaufen zu lassen.

Details in [07 — Betrieb](07-betrieb.md#der-heartbeat-ist-nicht-optional).

## `telemetry` und `logging`

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `telemetry.latency_histogram` | `true` | macht die 5-ms-Zusage im laufenden Betrieb überprüfbar |
| `logging.channel` | `ids_sensor` | Monolog-Kanal |

## Zwei Eigenheiten des Baums

Beide folgen aus dem Zusammenspiel von Config-Komponente und Umgebungsvariablen und
erklären, warum der Baum an manchen Stellen lockerer aussieht, als er ist:

- **Kein `enumNode()` für Werte, die aus einer Umgebungsvariable kommen können.** Symfonys
  `ValidateEnvPlaceholdersPass` prüft mit Typ-Platzhaltern, und `EnumNode` kennt keine
  Platzhalterbehandlung — die Prüfung liefe gegen `''` und würfe. Stattdessen
  `scalarNode()` plus `->validate()->ifNotInArray()`.
- **Numerische Untergrenzen schließen `0` ein.** Der Typ-Platzhalter für `int` ist `0`, und
  `->min(1)` würde ihn zurückweisen. Die fachlich sinnvolle Untergrenze prüft der
  verbrauchende Dienst.
