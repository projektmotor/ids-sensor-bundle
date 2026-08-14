# Changelog

Alle nennenswerten Änderungen an diesem Paket werden hier festgehalten.

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [Semantic Versioning](https://semver.org/lang/de/).

Semver gilt für `Contract\*` und `EventFormat\*`. Alles andere trägt `@internal`
und kann sich jederzeit ändern — durchgesetzt von
`tests/Unit/ArchitectureTest::testOnlyContractAndEventFormatArePublic()`.

## [Unreleased]

Das Paket ist noch nicht veröffentlicht (kein Tag, kein `version` in der
`composer.json`). Der folgende Umbau ist trotzdem vollständig eingetragen —
gerade **weil** er vor dem ersten Tag stattfindet und danach ein Bruch wäre.

**Die Paketgrenze bleibt unberührt.** `Delivery\Transport\MessageSerializer`
schreibt weiterhin `ids.event_batch` und `ids.heartbeat` und niemals einen
Klassennamen. Für Konsumenten des JSON ändert sich durch nichts hiervon etwas.

### Added — zweistufige Dokumentation

Das README trug 522 Zeilen und zwei unvereinbare Aufgaben: Einstieg und
Begründung. Nur etwa 35 Zeilen waren Quickstart, rund 200 erklärten, *warum*
etwas so ist. Beides steht jetzt getrennt.

**`README.md`** (englisch, 290 Zeilen) ist das Schaufenster: Systemdiagramm, ein
echtes emittiertes Event als JSON, die Erkennungsgrenze, Requirements,
Installation, Mindestkonfiguration, Befehle. Neu darin sind die
Requirements-Tabelle — sie stand bisher nur in der `composer.json` — und der
Registrierungsweg ohne Symfony Flex.

**`doc/`** (deutsch, 10 neue Dateien, ~1500 Zeilen) erklärt die Kernkonzepte,
eines je Datei, mit einem Diagramm je Konzept:

| | |
|---|---|
| `doc/README.md` | Leseweg und Index |
| `01-ueberblick.md` | Paketgrenze, die zwei Phasen, die drei Grundsatzentscheidungen |
| `02-beobachtungsebenen.md` | Kernel/Security/Business und die Wirksamkeitsasymmetrie |
| `03-ereignisformat.md` | Event, Frame, Payload, Vocabulary |
| `04-request-lebenszyklus.md` | Hooks, Erfassungsbudget, Sampling |
| `05-versandweg.md` | Broker oder Spool, Circuit Breaker, `dispatch_path` |
| `06-vertraulichkeit.md` | Cleanup-Kette, raw-Gate, Denylist |
| `07-betrieb.md` | Heartbeat, Verlustzähler, Broker-Rechte, Fehlersuche |
| `08-konfiguration.md` | vollständige Referenz aller `ids_sensor`-Schlüssel |
| `09-business-ebene.md` | die drei `capture_mode` |

Elf Mermaid-Diagramme, von GitHub nativ gerendert und mit `mermaid-cli` geprüft.
Drei davon zeigen etwas, das bisher nirgends stand: die Zustandsübergänge des
Circuit Breakers, die Versandweg-Weiche und die Wirksamkeitsasymmetrie der drei
Beobachtungsebenen.

`doc/konzept-v1.md` und `doc/struktur.md` bleiben unberührt — sie haben andere
Leser: das Konzept ist die Spezifikation beider Bundles, `struktur.md` richtet
sich an Beitragende.

Neu: **`tests/Unit/DocumentationTest.php`** hält die Dokumente zusammen — Links,
Anker, Konfigurationsschlüssel gegen den `ConfigurationTree` und die
Diagrammtypen.

Ebenfalls neu: **`LICENSE`** (MIT-Volltext; die `composer.json` sagte das seit
jeher, die Datei fehlte) und ein `authors`-Eintrag in der `composer.json`.

### Breaking — `EventFormat\` gegliedert

Elf Klassen lagen flach nebeneinander, obwohl das Format drei Ebenen hat: ein
Frame enthält Events, ein Event enthält einen Payload. Die Untergruppen
spiegeln diese Verschachtelung, damit der Baum die Form der Daten auf der
Leitung zeigt, bevor jemand eine Datei öffnet.

| vorher | nachher |
|---|---|
| `EventFormat\Frame` | `EventFormat\Frame\Frame` |
| `EventFormat\DispatchPath` | `EventFormat\Frame\DispatchPath` |
| `EventFormat\EventSchema` | `EventFormat\Event\EventSchema` |
| `EventFormat\NormalizedEvent` | `EventFormat\Event\NormalizedEvent` |
| `EventFormat\Actor` | `EventFormat\Event\Actor` |
| `EventFormat\SensorIdentity` | `EventFormat\Event\SensorIdentity` |
| `EventFormat\KernelPayload` | `EventFormat\Payload\KernelPayload` |
| `EventFormat\SecurityPayload` | `EventFormat\Payload\SecurityPayload` |
| `EventFormat\Layer` | `EventFormat\Vocabulary\Layer` |
| `EventFormat\Severity` | `EventFormat\Vocabulary\Severity` |
| `EventFormat\Environment` | `EventFormat\Vocabulary\Environment` |

**Das emittierte JSON ändert sich nicht.** Feldnamen, `schema_version` und
`frame_version` bleiben, wie sie sind — verschoben haben sich nur die
PHP-Klassennamen. Für Konsumenten des Formats auf der Leitung ist das kein
Ereignis; für Konsumenten der PHP-Klassen ist es ein Bruch.

`Vocabulary\` nimmt nur auf, was collectorseitig als ENUM-Typ existiert
(Konzept 4.2.1): ein neuer Fall dort ist eine Migration auf der Gegenseite.
`DispatchPath` und `SensorIdentity` erfüllen das nicht und liegen deshalb
woanders — drei feste Werte allein genügen nicht.

`ArchitectureTest::testEventFormatImportsNothing()` heißt jetzt
`testEventFormatImportsNothingForeign()` und prüft, was die Zusage immer war:
keine Fremdimporte. Importe innerhalb von `EventFormat\` sind erlaubt — ein
Paket darf sich selbst importieren. Neu erstreckt sich die Regel auf Docblocks,
weil ein `{@see}` in einen fremden Namensraum zwar keine
Übersetzungsabhängigkeit erzeugt, nach der Ausgliederung aber ins Leere zeigt.

### Breaking — `Redaction\` aufgespalten und umbenannt

Der Namensraum trug drei Rollen, die einander nichts angehen — und war genau
deshalb nicht zu benennen. `RawPolicy` entscheidet, **ob** `raw` mitreist,
`RawPayloadBuilder` legt fest, **was** darin steht, und `Redactor` macht
**Zugangsdaten unkenntlich**. Die ersten beiden gehören zum `raw`-Feld; der
dritte nicht, denn er wirkt auch auf `payload.query` und Business-Payloads.

| vorher | nachher |
|---|---|
| `Redaction\Redactor` | `PayloadConfidentialityCleanup\Cleaner` |
| `Redaction\Rules` | `PayloadConfidentialityCleanup\Rules` |
| `Redaction\RulesLoader` | `PayloadConfidentialityCleanup\RulesLoader` |
| `Redaction\RawPolicy` | `RawPayload\Gate` |
| `Redaction\RawPayloadBuilder` | `RawPayload\Builder` |

Methoden ziehen mit: `redactHeaders()`, `redactParameters()` und
`redactParameterValue()` heißen `cleanHeaders()`, `cleanParameters()` und
`cleanParameterValue()`; aus `isRedactedParameter()` wird `cleansParameter()`.

**Diese Änderung berührt die Semver-Fläche** — anders als die
Phasengruppierung darunter, die rein `@internal` war:

| Ebene | vorher | nachher |
|---|---|---|
| Konfiguration | `ids_sensor.redaction.*` | `ids_sensor.payload_confidentiality_cleanup.*` |
| Emittiertes JSON | `"redaction_version"` | `"cleanup_version"` |
| Emittiertes JSON | `"[redacted]"` | `"[confidential]"` |
| Mitgelieferte Liste | `config/redaction.dist.yaml` | `config/payload_confidentiality_cleanup.dist.yaml` |

`doc/konzept-v1.md` zieht mit, weil es beide JSON-Werte wörtlich vorschreibt
(3.1, 3.4, 4.5.1) und damit den Vertrag mit dem künftigen `IdsBackendBundle`
bildet. Die deutsche Prosa des Konzepts bleibt bei „Redaktion": geändert wurde,
was Vertrag ist, nicht die beschreibende Sprache einer gesicherten Fassung.

`ArchitectureTest::testGroupsFormALayering()` bekommt einen Rang mehr:
`RawPayload/` liegt über `PayloadConfidentialityCleanup/`, weil der `Builder`
den `Cleaner` beim Aufbau benutzt — redigiert wird beim Bauen, damit ein
unredigierter Wert zu keinem Zeitpunkt in einer serialisierbaren Struktur
existiert.

### Fixed

- Die mitgelieferte Denylist verwies auf
  `ids_sensor.redaction.additional_headers` und `additional_parameters`. Diese
  Schlüssel gab es im `ConfigurationTree` nie; wer der Anweisung folgte, bekam
  einen Konfigurationsfehler. Der Kommentar nennt jetzt den tatsächlichen Weg
  (`config` plus `merge_defaults`).
- `EventFormat\Severity` verwies per `{@see SecurityRelevantBusinessEvent}`
  unqualifiziert auf eine Klasse, die in `Contract\` liegt — der Verweis löste
  nach `EventFormat\SecurityRelevantBusinessEvent` auf und zeigte ins Leere.
- `EventFormat\EventSchema` nannte `doc/schema-versions.md` als Quelle der
  Bump-Regeln. Die Datei gibt es nicht; die Regeln stehen im selben Docblock.

### Breaking — `src/` nach Pipelinephasen gruppiert

Die oberste Ebene von `src/` trug 13 gleichrangige Namensräume; die
Phasenzugehörigkeit stand nur in `doc/struktur.md`. Sie ist jetzt der
Verzeichnisname. Aus 13 Einträgen werden 9:

| vorher | nachher |
|---|---|
| `Normalization\` | `Processing\Normalization\` |
| `Dispatch\` | `Delivery\Dispatch\` |
| `Transport\` | `Delivery\Transport\` |
| `Heartbeat\` | `Delivery\Heartbeat\` |
| `Redaction\` | `Support\Redaction\` |
| `Identity\` | `Support\Identity\` |
| `Telemetry\` | `Support\Telemetry\` |

`Contract\`, `EventFormat\`, `Sensor\`, `Command\`, `DependencyInjection\` und
`Exception\` bleiben, wo sie waren. **Die Semver-Fläche ändert sich damit nicht** —
`Contract\*` und `EventFormat\*` stehen weiterhin ohne Zwischenebene oben, unter
anderem, weil `EventFormat\` als eigenes Paket herauslösbar bleiben soll.

`Redaction\` liegt bewusst unter `Support\` und nicht unter `Processing\`:
`RawPayloadBuilder` wird in `ResponseSensor` und `ExceptionSensor` injiziert und
hängt damit in Phase A, auch wenn die Arbeit träge erst in Phase B passiert.

Neu erzwungen von `ArchitectureTest::testGroupsFormALayering()`: jeder Namensraum
trägt einen Rang, jeder Import muss auf gleichen oder kleineren Rang zeigen, und
ein Namensraum ohne Eintrag lässt den Test fehlschlagen.

### Breaking — öffentliche Fläche verschoben

Der Drahtvertrag aus Konzept Abschnitt 3 liegt vollständig in `EventFormat\`.
Der Namensraum importiert nichts und lässt sich später als eigenes Paket
herauslösen, das `IdsSensorBundle` und `IdsBackendBundle` gemeinsam benutzen.

| vorher | nachher |
|---|---|
| `Schema\EventSchema` | `EventFormat\EventSchema` |
| `Schema\Layer` | `EventFormat\Layer` |
| `Schema\Environment` | `EventFormat\Environment` |
| `Contract\Severity` | `EventFormat\Severity` |
| `Model\Actor` | `EventFormat\Actor` |
| `Model\SensorIdentity` | `EventFormat\SensorIdentity` |
| `Model\NormalizedEvent` | `EventFormat\NormalizedEvent` |
| `Dispatch\Frame` | `EventFormat\Frame` |
| `Dispatch\DispatchPath` | `EventFormat\DispatchPath` |

### Breaking — Klassen umbenannt

Präfixe gestrichen, die das Verzeichnis bereits trägt:

| vorher | nachher |
|---|---|
| `EventCollector` | `Sensor\EventBuffer` |
| `IdsEventBatch` | `Transport\Message\EventBatch` |
| `IdsHeartbeat` | `Transport\Message\Heartbeat` |
| `IdsEventSerializer` | `Transport\MessageSerializer` |
| `Heartbeat\HeartbeatEmitter` | `Heartbeat\Emitter` |
| `Heartbeat\HeartbeatMode` | `Heartbeat\Mode` |
| `Heartbeat\HeartbeatPayloadFactory` | `Heartbeat\PayloadFactory` |
| `Heartbeat\HeartbeatScheduler` | `Heartbeat\Scheduler` |
| `RedactionRules` | `Redaction\Rules` |
| `Kernel\KernelRequestSensor` | `Sensor\Kernel\RequestSensor` |
| `Kernel\KernelResponseSensor` | `Sensor\Kernel\ResponseSensor` |
| `Kernel\KernelExceptionSensor` | `Sensor\Kernel\ExceptionSensor` |
| `BusinessEventSensor` | `Sensor\Business\EventSensor` |

Dazu eine Umbenennung aus anderem Grund: `Command\DoctorCommand` heißt jetzt
`Command\SetupCheckCommand`, **und der Befehlsname wandert mit** —
`ids:sensor:doctor` → `ids:sensor:setup-check`. Das ist die praktisch wirksamere
Hälfte, weil sie Deploy-Skripte betrifft.

„Doctor" bezog seine Bedeutung nur aus einer Fremdkonvention (`brew doctor`,
`flutter doctor`) und offenbarte seine Absicht nicht aus sich heraus, wie §1.1
es verlangt. `ConfigCheck` wäre die Gegenrichtung und ebenso falsch gewesen: von
den zehn Prüfungen lesen sechs Laufzeit- und Umgebungszustand statt
Konfiguration — Hostname, Trusted Proxies, SAPI, Verzeichnisrechte, Dateialter
im Spool, Heartbeat-Alter und geladene PHP-Erweiterungen. `SetupCheck` trägt
über beides: auch die Altersprüfungen zeigen in ihren Befundtexten auf einen
fehlenden Einrichtungsschritt („fehlt vermutlich der cron- oder systemd-Eintrag").

### Breaking — nicht mehr `@internal`

`Actor`, `SensorIdentity`, `NormalizedEvent`, `Frame` und `DispatchPath` haben
ihre `@internal`-Annotation verloren. Sie sind seitdem öffentliche API und
stehen unter Semver.

### Breaking — Signaturen ohne Flag-Parameter

Nach CLAUDE.md §1.2 gibt es keine `bool`-Parameter mehr in Methodensignaturen
(Konstruktor-Bools sind Konfiguration und bleiben):

- `Sensor\Context\ActorFactory::forRequest(Request, RequestSnapshot, bool $withUser = true)`
  → `forRequest(Request, RequestSnapshot)` und `forRequestWithoutUser(Request, RequestSnapshot)`.
- `Sensor\Security\AccessDecisionSensor::record(…, bool $granted)`
  → `record(…, string $decision)` mit den Konstanten aus `EventFormat\SecurityPayload`.
- `Sensor\Kernel\RequestSensor::createSnapshot()` und `correlationIdFor()` nehmen
  das `RequestEvent` statt `Request` plus `bool $isMainRequest`.

### Breaking — entfernt

- Konfigurationsschlüssel `spool.enabled` und der Parameter
  `ids_sensor.spool.enabled`. Der Schalter hat nie etwas geschaltet:
  `services_resilience.yaml` wird bedingungslos importiert, den Parameter las
  niemand. Das Konzept sieht den Spool nicht als abschaltbares Merkmal — er
  trägt die fail-open-Zusage aus Abschnitt 4 und ist unter mod_php laut 3.3.1
  der einzige Transportweg. Wer den Spool nicht selbst leeren will, stellt
  `drain: off`. Der Schlüssel wird jetzt beim Kompilieren abgewiesen statt
  ignoriert.
- `Sensor\Context\CorrelationIdFactory::lastSource()` samt Feld und den beiden
  Konstanten `SOURCE_GENERATED` / `SOURCE_INBOUND_HEADER`. Der Docblock
  versprach „Wandert in raw.meta" — ein solches Feld gibt es im Drahtformat
  nicht, und das Konzept verlangt es auch nicht: es führt B6 in 6.3 als erledigt,
  mit genau der Begründung, die die Klasse umsetzt (Übernahme nur hinter einem
  vertrauenswürdigen Proxy). Nebeneffekt: `forRequest()` setzt keinen Zustand
  mehr nebenbei (CQS, §1.2).
- `Sensor\Context\RequestSnapshot::$relevant`. Das Feld wurde an fünf Stellen
  geschrieben und in `src/` nirgends gelesen. Sein Docblock behauptete, die
  Sampling-Entscheidung brauche es; tatsächlich leitet
  `Normalization\CoherentInfoSampler` die Relevanz aus der Severity ab.
  Konzept 3.2 (Feldredundanz) macht den Self-Join entbehrlich, für den
  Kohärenz überhaupt nötig wäre — das Verhalten ändert sich nicht.

### Added

- `EventFormat\KernelPayload` und `EventFormat\SecurityPayload` — die
  Nutzlastschlüssel, die vorher als Roh-Strings in den Normalisierern standen.
- `Sensor\Context\CapturedEventBinder` — heftet Request-Schnappschuss und Actor
  an ein erfasstes Event. Löst die fünffach duplizierte `attachContext()` auf.
- `Dispatch\FrameDispatcher` — die Versandentscheidung (direkt, Spool,
  Breaker offen, Versand wirft) als eigener Dienst, herausgetrennt aus
  `Dispatch\EventFlusher`.
- `Exception\UnshippableFrameException` — die einzige eigene Exception des
  Bundles. `Transport\MessageSerializer` wirft sie für einen Frame, der aus sich
  heraus nie versendbar ist; `Transport\Spool\SpoolDrainer` verwirft daraufhin
  genau diese Zeile, statt den Rest der Spool-Datei hinter ihr aufzuhalten
  (Head-of-Line-Blocking).
- `Transport\Spool\DrainOutcome` — `Sent` / `Discarded` / `Retryable`, das
  Ergebnis einer einzelnen Spool-Zeile.
- `EventFormat\Actor::withoutIp()`.
- `Normalization\FieldValue` — `asString()` und `truncate()`, die beiden
  Umformungen jedes normalisierten Feldes. Standen vorher zweimal wörtlich in
  den Normalisierern, das Stringisieren zusätzlich unter zwei Namen
  (`str()` und `stringOrNull()`).
- `Telemetry\DeferredCounters` — sammelt die Verlustzähler aus Phase A beim Flush
  ein. Herausgetrennt aus `Dispatch\EventFlusher`, der dafür drei
  Abhängigkeiten hielt, die er sonst nirgends benutzte.
- Zähler `dropped_decision_cap`. Der Überlauf der Autorisierungserfassung
  (`max_decisions_per_request`) wurde im Sensor gezählt und nie eingesammelt —
  collectorseitig war der Verlust unsichtbar, obwohl Konzept 4 (`ids.event_loss`)
  jeden verworfenen Event sichtbar verlangt. Eigener Zähler statt
  `dropped_capture_budget`: der eine sagt „die Zeit war alle", der andere
  „diese Seite prüft mehr Rechte als vorgesehen".
- `tests/Unit/ArchitectureTest` — hält die fünf Strukturzusagen fest
  (öffentliche Fläche, `EventFormat/` importfrei, Phase A kennt Phase B nicht,
  `Dispatch/` ist Senke, die Namensräume bilden eine Schichtung) und prüft
  `{@see}`-Verweise auf Existenz.
- `tests/Fixtures/IntegrationTestCase` — `services()` stand 13×, der
  Sitzungsschlüssel in 17 Dateien.
- `doc/struktur.md` — Begründung je Namensraum.
- Diese Datei.

### Changed — intern

- Service-ID `ids_sensor.event_collector` → `ids_sensor.event_buffer`;
  neu: `ids_sensor.captured_event_binder`, `ids_sensor.frame_dispatcher`.
- Argumentname `$collector` → `$buffer`; `SharedStateStore::$instanceId` →
  `$scopeKey`.
- `Dispatch\EventFlusher` nimmt `$deferredCounters` statt `$captureBudget`;
  Service-ID `ids_sensor.deferred_counters` neu.
- `Sensor\CaptureBudget::guard()` ist `void` statt `bool` — die Methode führt aus
  und schreibt Zähler fort, die Auskunft gibt `skipped()` (CQS, §1.2). Kein
  Produktivcode hat den Rückgabewert je ausgewertet.
- `Sensor\Context\SessionIdHasher::hashFor()` → `forRequest()`,
  `Sensor\Context\ClientFingerprinter::fingerprintFor()` → `forRequest()`. Die
  übrigen Erzeuger heißen bereits `forX` (§1.1).
- `Dispatch\CoherentInfoSampler::droppedCount()` ist eine Instanz- statt einer
  statischen Methode — der `EventFlusher` hat denselben Sampler injiziert (§1.8).
- `Transport\Spool\FileSpool::hasRoomFor()` ist wieder eine reine Frage; das
  Nachzählen der Bytes steckt in `refreshByteCount()` (CQS, §1.2).
- `Normalization\SeverityResolver` vergleicht gegen die Konstanten aus
  `EventFormat\`, nicht mehr gegen Roh-Strings.
- Vier zu lange Methoden aufgeteilt: `SpoolDrainer::drainFile()`,
  `Sensor\Kernel\ResponseSensor::onKernelResponse()`,
  `Dispatch\EventFlusher::doFlush()`, `Command\SetupCheckCommand::checkRuntimeAndSpool()`.
- PHPUnit-Metadaten als Attribute statt Docblock-Annotationen.
- 333 Testmethoden und 9 Datenlieferanten auf englische Namen umgestellt.
  Regel jetzt in CLAUDE.md §1.10: Bezeichner englisch, Prosa deutsch.
- `friendsofphp/php-cs-fixer`: `^3.58` → `^3.60`.
