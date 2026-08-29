# Update auf `schema_version` 2 — Drahtformat und Sensor-Bundle

**Stand:** 29.08.2026 · **Grundlage:** `doc/concept/concept-v1.md` (Commits `fc6d3a9`, `becfdc2`)

Das Konzept ist in zwei Durchgängen umgeschrieben worden, der Quellcode nicht. Diese Anleitung
beschreibt, wie der Code nachzieht. Sie ist keine Aufgabenliste zum Abhaken, sondern eine
Reihenfolge mit Begründungen — wo eine Entscheidung schon gefallen ist, steht sie hier; wo
nicht, ist es vermerkt.

## Was sich geändert hat, in einem Absatz

Der Transport wechselt vom Redis-Stream auf eine REST-Schnittstelle des Collectors. Redis
entfällt dabei vollständig, auch als Zählerspeicher der Echtzeitregeln. Gleichzeitig ändert
sich das Drahtformat: Die Identität besteht künftig aus drei UUIDs (`application_id`,
`environment_id`, `sensor_id`), `instance_id` entfällt, `schema_version` steht nur noch im
Frame, und die Zeitachse der Regeln hängt collectorseitig an `effective_at` statt am
sensorgesetzten `timestamp`.

**Das ist ein Bruch, kein additiver Zuwachs.** Nach den Regeln in Konzept 3.7 erzwingt jede
dieser Änderungen für sich schon einen Fassungswechsel.

---

## Reihenfolge — und warum sie zwingend ist

```
1. ids-event-data          ─┐
2. Sensor: Identität        │  ein Bruch, eine Fassung,
3. Sensor: Transport        │  eine zusammenhängende Auslieferung
4. Sensor: Konfiguration   ─┘
5. Dokumentationsreihe 01–09
```

`ids-event-data` zuerst, weil beide Bundles davon abhängen — das Sensor-Bundle schreibt das
Format, das IdsBackendBundle liest es. Der Schnitt 2 bis 4 gehört dagegen in **eine**
Auslieferung: Wer die Identität ändert, ohne den Transport nachzuziehen, hat einen Sensor, der
UUIDs in einen Redis-Stream schreibt, den niemand so erwartet.

Das Schwesterrepo liegt unter `../ids-event-data`.

---

## Schritt 1 — `projektmotor/ids-event-data` auf Fassung 2

Es ist das erste Mal, dass eine Konzeptänderung dieses Paket berührt. Der Grund ist nicht
eine Einzelentscheidung, sondern eine Kombination: Umgebungen werden collectorseitig **frei
benannt**, und maßgeblich ist der **Rumpf** der Sendung. Trüge der Rumpf den Umgebungs*namen*,
legte jede Umbenennung im Collector alle Sensoren mit `422` lahm.

### `src/Event/SensorIdentity.php`

| bisher | künftig |
|---|---|
| `string $applicationId` (freier Name, ≤ 64 Zeichen) | `string $applicationId` — UUID |
| `string $instanceId` | **entfällt** |
| `Environment $environment` | `string $environmentId` — UUID |
| — | `string $sensorId` — UUID |

Die Prüfmuster ändern sich mit: `ID_CHARACTERS`/`MAX_ID_LENGTH` waren auf frei gewählte Namen
zugeschnitten, künftig ist es eine UUID-Prüfung. **Nicht ändern** sollte man das Verhalten bei
ungültigen Werten — die Klasse wirft bewusst nicht, sondern sammelt Beanstandungen in
`validate()`; ein Wurf hier verstieße gegen fail-open (Konzept 4).

### `src/Vocabulary/Environment.php`

**Löschen.** Das Enum kannte genau `prod|staging|dev`. Frei benannte Umgebungen passen nicht
hinein, und der Sensor kennt den Namen ohnehin nicht mehr — nur die UUID. Den Anzeigenamen
löst der Collector aus seinem Register auf.

Damit entfällt auch die Notwendigkeit, in `Severity`/`Layer` etwas anzufassen: Beide bleiben
geschlossene Vokabulare im Sinne von Konzept 3.7.

### `src/Event/EventSchema.php`

- `SCHEMA_VERSION` von `1` auf `2`
- `FIELD_INSTANCE_ID` → `FIELD_SENSOR_ID`, `FIELD_ENVIRONMENT` → `FIELD_ENVIRONMENT_ID`
- `FIELD_SCHEMA_VERSION` **aus dem Event entfernen** — die Fassung steht im Frame
- `MANDATORY_FIELDS` entsprechend nachziehen

Zur `MANDATORY_FIELDS`-Liste ein Hinweis, der bisher unbemerkt blieb: Sie führt heute `actor`
und `payload` als Einträge, während Konzept Abschnitt 3 stattdessen die vier `actor.*`-
Unterfelder auflistet und `payload` unter „variabler Teil" führt. Beide Listen sind semantisch
verträglich, aber nicht identisch — wer sie gegeneinander testet, bekommt eine Abweichung. Beim
Bump ist der richtige Zeitpunkt, sie anzugleichen.

### `src/Event/NormalizedEvent.php`

`toArray()` gibt kein `schema_version` mehr aus und schreibt `sensor_id`/`environment_id`
statt `instance_id`/`environment`.

### `src/Frame/Frame.php`

- `FRAME_VERSION` → der Schlüssel im Array heißt `schema_version` statt `v`
- der `sensor`-Block trägt die drei UUIDs

Ein Feld `v` und ein Feld `schema_version` meinten immer dasselbe; künftig gibt es nur noch
eines, und es steht hier.

### Veröffentlichen

Fassung `0.2.0` (das Paket steht auf `0.x`, ein Bruch ist dort in der Minor-Stelle zulässig).
Der eigene `CHANGELOG.md` des Pakets bekommt den Eintrag — nicht der des Bundles.

---

## Schritt 2 — Sensor-Bundle: Identität

### Löschen

| Datei | Warum |
|---|---|
| `src/Support/Identity/InstanceIdProvider.php` | Es gibt keine `instance_id` mehr. Die Ableitung aus dem Hostnamen entfällt samt ihrer Falle (ein beim Container-Bau aufgelöster Name ist in jedem Replikat derselbe). |
| `src/Support/Identity/EnvironmentResolver.php` | `environment_map` und `environment_fallback` sind ersatzlos entfallen — die Zuordnung geschieht einmalig bei der Registrierung im Collector statt bei jedem Request im Sensor. Damit verschwindet auch der Vorgabewert `prod`, den das Konzept selbst als Wahl zwischen zwei Fehlern beschreibt. |

Zugehörige Tests: `tests/Unit/Support/Identity/InstanceIdProviderTest.php` und
`EnvironmentResolverTest.php`.

### Ändern

`src/Support/Identity/SensorIdentityProvider.php` liest künftig drei UUIDs aus der
Konfiguration und leitet nichts mehr ab. Der Dienst bleibt trotzdem ein **Dienst und kein
Container-Parameter**: Die Werte kommen aus Umgebungsvariablen und dürfen nicht in einen
gewärmten Container-Cache gebacken werden.

> **Ein Sensor je Node.** Konzept 2.3 macht daraus eine Betriebsvorschrift. In Kubernetes
> teilen sich alle Replikate eines Deployments eine ConfigMap — läge die `sensor_id` dort,
> trügen alle dieselbe, die Replikate wären ununterscheidbar, und `ids.sensor_silent` schwiege
> beim Ausfall einzelner. Sie muss je Node kommen: eigenes Secret, Downward API oder
> knotenspezifische Konfiguration. `application_id` und `environment_id` dürfen geteilt sein.

---

## Schritt 3 — Sensor-Bundle: Transport

Die Naht ist gut gewählt und bleibt: `Delivery/Transport/Shipper/ShipperInterface.php` mit
`ship(array $frame)` und `shipHeartbeat(array $payload)`. Beide dürfen werfen — der
`FrameDispatcher` fängt jedes `Throwable` und entscheidet über Spool und Circuit Breaker. Ein
Shipper, der Fehler selbst verschluckt, nähme ihm genau diese Entscheidung.

### Was ersetzt wird

| Datei | Schicksal |
|---|---|
| `Shipper/MessengerShipper.php` | → `HttpShipper` mit `HttpClientInterface` |
| `MessageSerializer.php` | entfällt — es ist ein Messenger-`SerializerInterface` |
| `Message/EventBatch.php`, `Message/Heartbeat.php` | entfallen — Envelope-Hüllen |
| `DependencyInjection/Compiler/LazyTransportPass.php` | entfällt — ein HTTP-Client ist von Natur aus träge |

### Was **unverändert** bleibt

`Spool/`, `Breaker/`, `RuntimeProfile`, `FrameDispatcher`, `EventFlusher`, `FlushListener`,
`Heartbeat/`, sämtliche Sensoren und Normalisierer. `RuntimeProfile` insbesondere: Die Klasse
beantwortet „darf Phase B Netzwerk anfassen", nicht „welches Netzwerk" — ein HTTP-Roundtrip
kostet unter mod_php genauso Antwortzeit wie ein Redis-Befehl.

### Die drei Routen

```
POST /api/v1/sensor-data/{application_id}/{environment_id}/{sensor_id}   Liste von Frames
POST /api/v1/sensor-heartbeat/{application_id}/{environment_id}/{sensor_id}   ein Heartbeat
POST /api/v1/token                                                        Anmeldung
```

Keine `X-Ids-*`-Header. Die Route trägt die Nachrichtenart, `Authorization` das Token, der
Körper die Nutzlast.

### Anmeldung und Token-Cache

Der Sensor holt an `/api/v1/token` mit Benutzername und Passwort ein JWT und legt es
**prozessübergreifend** ab. Dafür gibt es bereits ein Muster im Haus:
`Delivery/Transport/Breaker/SharedStateStore.php` löst genau dieselbe Aufgabe für den
Breaker-Zustand (APCu, sonst Datei mit `flock`, Schreiben über Temp-Datei und `rename`). Das
ist die Vorlage — ohne sie holte sich jedes FPM-Kind sein eigenes Token, und aus einer
Anmeldung je Stunde würden Tausende.

Erneuert wird **vorausschauend** mit Vorlauf, nicht erst auf `401`. Eine Erneuerung im
Fehlerfall wäre ein zweiter Roundtrip innerhalb des 50-ms-Versandbudgets — genau das, was das
Budget verhindern soll. Auf ein `401` folgt genau ein Neuanmelde- und ein Wiederholungsversuch.

Ein Fehlschlag beim Token-Holen zählt in Budget und Breaker mit: Ein Collector, der keine
Token ausgibt, ist ein nicht erreichbarer Collector.

### Antwortcodes — hier liegt die eigentliche Arbeit

Konzept 3.6 enthält die normative Tabelle. Der Punkt, der Aufmerksamkeit braucht:
`Exception/UnshippableFrameException.php` existiert heute nur für Kodierfehler, weil Redis'
`XADD` keine Ablehnung kennt. Über HTTP wird daraus eine Unterscheidung mit Bedeutung:

- `400`, `413`, `422`, `403` → **verwerfen**, `dropped_rejected` zählen, **nicht** spoolen
- `429`, `5xx`, Timeout, Verbindungsfehler → **spoolen**, Breaker zählt einen Fehler
- `202` → `sent`, Breaker schließt

`SpoolDrainer` unterscheidet bereits `DrainOutcome::Discarded` von `Retryable` — die
Zuordnung der Statuscodes ist der neue Teil, das Gerüst steht.

### Zwei Versandmodelle

`flush.policy` hat bereits `auto|direct|spool`; eine neue Einstellung braucht es nicht. Neu ist
allein, dass der Drain-Lauf **mehrere Frames in einen POST** packt, begrenzt durch eine
Sendungsgrenze. Der Grund steht in Konzept 3.6: Unter PHP-FPM lässt sich keine Verbindung
zwischen Requests wiederverwenden, es fällt also je Sendung ein TLS-Handshake an. Er liegt
hinter dem Absenden der Antwort und kostet keine Antwortzeit — belegt aber das FPM-Kind.

### `composer.json`

```diff
-"symfony/messenger": "^6.4|^7.0"
+"symfony/http-client": "^6.4|^7.0"
```

`symfony/messenger` wird heute ausschließlich für `TransportInterface`, `Envelope`,
`SerializerInterface` und die Worker-Events im `FlushListener` gebraucht. **Vorsicht bei den
Worker-Events:** `WorkerMessageHandledEvent` und `WorkerMessageFailedEvent` sind Flush-Punkte
für Anwendungen, die selbst Messenger benutzen. Sie fallen nicht weg — Messenger wird von
einer harten Abhängigkeit zu einer optionalen, und die Listener registrieren sich nur, wenn
die Klassen vorhanden sind.

Aus `require-dev` und `suggest` entfallen `ext-redis` und `symfony/redis-messenger`.
`docker-compose.yml` verliert den Redis-Dienst, `docker/redis/` entfällt, `Makefile` das Ziel
`test-redis`.

---

## Schritt 4 — Sensor-Bundle: Konfiguration

`src/DependencyInjection/ConfigurationTree.php`. **Alle `ids_sensor`-Schlüssel gehören zur
öffentlichen API unter SemVer** (`doc/08-konfiguration.md`) — das hier ist ein Breaking Change
und gehört so in den `CHANGELOG.md`.

### Entfällt

`environment`, `environment_map`, `environment_fallback`, der gesamte `transport`-Knoten
(`name`, `dsn`, `register_transport`, `options`) samt `PROTECTED_TRANSPORT_OPTIONS`.

Mit dem `transport`-Knoten verschwindet die längste Fehlermeldung des Baums — die über
`auto_setup`, `lazy` und `serializer`. Sie war richtig und ist gegenstandslos.

### Kommt hinzu

| Schlüssel | Anmerkung |
|---|---|
| `application_id` | Typwechsel: UUID statt freier Name |
| `environment_id` | UUID, Pflicht |
| `sensor_id` | UUID, Pflicht — je Node verschieden (Schritt 2) |
| `collector.base_uri` | |
| `collector.username`, `collector.password` | |
| `collector.token_leeway_s` | Vorlauf für die vorausschauende Erneuerung |
| `collector.verify_tls` | Vorgabe `true`; in Produktion nicht abschaltbar (Konzept 4.5.3) |
| `spool.max_post_frames` / `max_post_bytes` | Sendungsgrenze im gebündelten Modus |

**Kein `enumNode`** für Werte, die aus einer Umgebungsvariablen kommen können — die Regel steht
im Klassen-Docblock von `ConfigurationTree` und gilt unverändert.

### Der `setup-check` bekommt Arbeit

Konzept 2.3 führt die Betriebsvoraussetzungen mit ihrer jeweiligen Durchsetzungsebene. Alles
mit „setup-check" in der letzten Spalte ist ein neuer Prüfschritt — darunter
`framework.trusted_proxies` (ohne das sieben Regeln stillschweigend nichts messen) und die
Frage, ob die `sensor_id` aus geteilter Konfiguration stammt.

---

## Schritt 5 — Dokumentationsreihe nachziehen

`doc/01`–`doc/09` beschreiben heute korrekt den **ausgelieferten** Stand; `doc/README.md`
vermerkt die bewusste Divergenz. Mit der Umsetzung fällt der Grund dafür weg, und der Vermerk
muss wieder verschwinden.

Am stärksten betroffen: `05-versandweg.md` (Broker → Collector, Antwortcodes),
`07-betrieb.md` (die Redis-ACL entfällt ersatzlos, dafür Anmeldung und Registrierung),
`08-konfiguration.md` (der ganze `transport`-Block), `01-ueberblick.md` (Systemdiagramm).

`doc/concept/structure.md` beschreibt den Schnitt von `src/`. Er ändert sich **nicht** — der
neue Shipper bleibt in `Delivery/Transport/Shipper/`, und die Namensraum-Rangfolge in
`ArchitectureTest::testGroupsFormALayering()` braucht keinen neuen Eintrag.

---

## Prüfliste

- [ ] `ids-event-data` 0.2.0 veröffentlicht, `composer.json` des Bundles zieht nach
- [ ] `grep -rn "instance_id\|environment_map\|environment_fallback" src/ config/` ist leer
- [ ] `grep -rn "Messenger\|redis" src/` trifft nur noch die optionalen Worker-Flush-Punkte
- [ ] PHPStan Level ≥ 8 grün, `php-cs-fixer` grün
- [ ] Die 69 Testdateien laufen; die 15 Container-Abdrücke unter
      `tests/Fixtures/container-fingerprints/` sind neu zu erzeugen — sie enthalten die
      Dienst-IDs und ändern sich mit dem Transport
- [ ] `ArchitectureTest` grün, insbesondere `testTheEventFormatStaysInItsOwnPackage()` und
      `testDocblockReferencesDoNotDangle()` (die Docblocks verweisen auf Konzeptabschnitte,
      und 2.3 sowie 3.7 sind neu)
- [ ] `ids:sensor:setup-check` prüft die Voraussetzungen aus Konzept 2.3
- [ ] `CHANGELOG.md` führt den Breaking Change der Konfigurationsfläche
- [ ] `doc/README.md`: Divergenzhinweis entfernt

---

## Was **nicht** dazugehört

**Die S- und P-Befunde der Tiefenprüfung sind offen** und werden gesondert geprüft: Sampling
gegen Signaturerkennung, `granted`-Entscheidungen ohne Leser, Anomalieschwellen bei kleinen
Zahlen, Rotation des Sitzungsschlüssels, sowie die Indexfragen — darunter, dass der GIN-Index
auf `payload` die `->>`-Abfragen aus Konzept 4.3.2 nachweislich nicht bedienen kann.

**Der Collectorteil.** Alles zu `effective_at`, `unknown_fields`, `realtime_counters`,
`metric_samples` und der Eigentümerprüfung ist im Konzept festgelegt, gehört aber ins
IdsBackendBundle. Für dieses Repository ist davon nur eines relevant: Der Sensor muss die
Felder liefern, aus denen der Collector `effective_at` bildet — `timestamp`, `dispatch_path`
und `spool_delay_ms` im Frame. Alle drei existieren bereits.

**Acht Umsetzungsabweichungen**, bei denen das Konzept recht hat und der Code abweicht. Sie
sind unabhängig von diesem Update und in der Tiefenprüfung als U-1 bis U-8 geführt; die
folgenreichste ist `circuit_breaker.open_for_ms`, das die Restlaufzeit meldet statt der
bisherigen Offenzeit — womit die Frage, für die das Feld existiert („ist seit vierzig Minuten
offen"), gerade nicht beantwortbar ist.
