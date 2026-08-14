# Struktur von `src/`

Die oberste Ebene ist die Pipelinephase, die zweite die Stufe innerhalb der Phase, die
dritte die Beobachtungsebene. Wer den Konzeptabschnitt kennt, findet den Ordner; wer den
Ordner sieht, weiß, wann der Code läuft.

Die Aufteilung folgt der Reihenfolge aus Konzept Abschnitt 1 — *„Erfassung,
Normalisierung, Redaktion (4.5.1), Versand an den Broker"*.

| Namespace | Konzept | Wann läuft das? |
|---|---|---|
| `EventFormat/Frame/` | 3.3 + 3.3.1 | gar nicht — reine Daten und Konstanten |
| `EventFormat/Event/` | Abschnitt 3 | gar nicht |
| `EventFormat/Payload/` | 3.1.1 + 3.1.2 | gar nicht |
| `EventFormat/Vocabulary/` | 2.1 + 2.2.1 | gar nicht |
| `Contract/` | 2.1.3 | in der überwachten Anwendung |
| `Sensor/` | 2.1 Sensorik | **Phase A** — im Request, unter dem 5-ms-Budget |
| `Processing/Normalization/` | 2.2 + 3.1 | **Phase B** — nach `Response::send()` |
| `Delivery/Dispatch/` | 2.1 „Dispatch an den Transport" | Phase B — `kernel.terminate` |
| `Delivery/Transport/` | 3.3 Transportformat | Phase B und CLI (`spool:flush`) |
| `Delivery/Heartbeat/` | 3.4 | Phase B und CLI (`heartbeat`) |
| `Support/PayloadConfidentialityCleanup/` | 4.5.1 | **beide Phasen** — Regeln beim Kompilieren |
| `Support/RawPayload/` | 3 + 3.1 | Gate in Phase B, Builder ab Phase A (träge) |
| `Support/Identity/` | 2.2.1 | beim ersten Zugriff, dann gecacht |
| `Support/Telemetry/` | Abschnitt 4 | überall, nur Zähler |
| `Command/` | — | CLI |
| `DependencyInjection/` | — | beim Kompilieren des Containers |
| `Exception/` | 4. fail-open | die eine Klasse, an der eine Entscheidung hängt |
| `IdsSensorBundle.php` | — | beim Kompilieren und beim Booten |

## Die beiden öffentlichen Namespaces

`Contract/` und `EventFormat/` sind die Semver-Fläche. Alles andere trägt `@internal`.
Die Regel ist nicht Prosa, sondern Test: `tests/Unit/ArchitectureTest.php` prüft, dass
`@internal` genau außerhalb dieser beiden Verzeichnisse steht.

Beide stehen bewusst **oben** und nicht in einer der drei Gruppen. Es sind die einzigen
zwei Namensräume, die ein Nutzer je tippt; jede Ebene darüber verlängert die einzigen
Namen, die nach außen zählen.

**`Contract/`** — die drei Interfaces, die die überwachte Anwendung anfasst:
`SecurityRelevantBusinessEvent` (implementieren), `BusinessEventRecorderInterface`
(injizieren), `IdsResourceIdentifier` (optional implementieren).

**`EventFormat/`** — der Vertrag mit dem Collector. Die vier Untergruppen spiegeln die
Verschachtelung des Drahtformats; von oben nach unten gelesen ist das das JSON von außen
nach innen:

```
Frame/        Frame  DispatchPath              3.3   — was auf der Leitung liegt
Event/        EventSchema  NormalizedEvent     3.    — was im Frame liegt
              Actor  SensorIdentity
Payload/      KernelPayload  SecurityPayload   3.1   — was im Event liegt
Vocabulary/   Layer  Severity  Environment           — die geschlossenen Wertelisten
```

`EventFormat/` importiert **nichts Fremdes** — weder aus dem Bundle noch aus Symfony;
untereinander greifen die vier Gruppen sechsmal, und das ist erlaubt. Das ist Absicht und
wird getestet, in `use`-Zeilen wie in Docblocks: der Namespace soll als eigenes Paket
ausgelöst werden, das IdsSensorBundle und IdsBackendBundle gemeinsam konsumieren. Solange
die Bedingung hält, ist die Auslösung ein Verzeichnis-Move plus `composer.json` — und
genau deshalb liegt über ihm keine `Api/`-Ebene, die dabei wieder verschwinden müsste.

Die Probe dafür ist ein Einzeiler und steht so auch im Test:

```bash
grep -rn 'ProjektMotor\\IdsSensor\\' src/EventFormat/ | grep -v 'EventFormat\\'
```

### Was in `Vocabulary/` gehört — und was nicht

> Ein neuer Fall in `Vocabulary/` ist eine Migration am collectorseitigen ENUM-Typ
> (Konzept 4.2.1 Tabellenschema). Was das nicht auslöst, gehört nicht hierher.

`Layer`, `Severity` und `Environment` erfüllen das: ihre Docblocks sagen alle denselben
Satz, jeder von ihnen hat drei Werte, für immer. Zwei Klassen, bei denen die Frage
naheliegt und die das Kriterium abweist:

- **`DispatchPath`** liegt in `Frame/`. Drei feste Werte hat es zwar, aber es ist eine
  Eigenschaft der Sendung und laut eigenem Docblock eine „Ergänzung zum Konzept" — kein
  ENUM-Typ auf der Gegenseite.
- **`SensorIdentity`** liegt in `Event/`. `application_id` und `instance_id` haben gar
  keine geschlossene Wertemenge; der Betreiber wählt sie frei, und `isValidId()`
  beschränkt nur ihre Form. Strukturell ist sie ohnehin das Geschwister von `Actor` —
  beides Feldgruppen auf derselben Ebene, `Actor` sagt *wer*, `SensorIdentity` sagt *wo
  beobachtet*.

`EventSchema` liegt in `Event/`, weil es die Schemadefinition des Events ist. Dass der
`Frame` von dort vier Konstanten liest, ist die einzige Stelle, an der eine äußere Ebene
in eine innere greift — und sie ist richtig: der Frame wiederholt die Identitätsfelder
des Events auf seiner eigenen Ebene (Konzept 3.3).

## Die Phasengrenze

Der teuerste Fehler in diesem Bundle wäre, Arbeit aus Phase B in Phase A zu ziehen: das
kostet Antwortzeit der überwachten Anwendung, und kein Test wird davon langsamer.
Deshalb ist die Grenze am Pfad ablesbar.

```
Phase A  (im Request, Budget 1500 µs)      Phase B  (nach Response::send())
─────────────────────────────────────      ─────────────────────────────────────
Sensor/                                    Processing/     →     Delivery/
  Kernel/  Security/  Business/              Normalization/        Dispatch/
  Context/  EventBuffer  CaptureBudget                             Transport/
                                                                   Heartbeat/

            Support/  —  gehört keiner der beiden Phasen
              PayloadConfidentialityCleanup/   RawPayload/
              Identity/                        Telemetry/
```

`Sensor/` importiert `Processing/` nicht — auch das ist getestet. Möglich wird das
dadurch, dass die gemeinsamen Schlüssel im `EventFormat/` liegen und nicht in den
Normalisierern: beide Seiten sind Leser desselben Vertrags, keine hängt an der anderen.

Die Gegenrichtung ist erlaubt und richtig: `Processing/` liest `Sensor\CapturedEvent`.

## Die drei Gruppen

**`Processing/`** ist der erste Takt von Phase B: aus dem Erfassten wird das Ereignis
aus Konzept Abschnitt 3. Heute steht dort nur `Normalization/`. Der Ordner trägt
trotzdem seinen eigenen Namen, damit die drei Phasenordner nebeneinander lesbar sind —
und damit die nächste Phase-B-Arbeit einen Platz hat, an dem niemand überlegen muss.

**`Delivery/`** ist der zweite Takt: das Ereignis verlässt den Prozess. `Dispatch/`
entscheidet (flushen, sampeln, Broker oder Spool), `Transport/` führt aus, `Heartbeat/`
meldet, dass es den Sensor noch gibt. `Dispatch/` bleibt dabei die Spitze der Pipeline:
niemand importiert von dort, auch nicht innerhalb der Gruppe.

**`Support/`** sammelt, was zu keiner Phase gehört, weil beide es benutzen. Die Regel
ist eng zu lesen — ein Ordner mit der Beschreibung „gehört keiner Phase" wird sonst
binnen weniger Commits zur Rumpelkammer für alles, wofür sich niemand entscheiden will.
Deshalb steht jeder seiner Mitglieder einzeln in der Rangfolge unten, und ein neuer
Namensraum ohne Eintrag lässt `testGroupsFormALayering()` fehlschlagen.

### Warum die Vertraulichkeit nicht beim raw-Feld liegt

`Support/RawPayload/` und `Support/PayloadConfidentialityCleanup/` lagen bis zum Umbau
in einem Ordner namens `Redaction/`. Der trug damit drei Rollen, die einander nichts
angehen, und genau daran war er nicht zu benennen:

| Klasse | Rolle |
|---|---|
| `RawPayload\Gate::allows()` | entscheidet, **ob** `raw` mitreist — fasst keinen Wert an |
| `RawPayload\Builder::forExchange/forException/forBusiness()` | legt fest, **was** in `raw` steht |
| `PayloadConfidentialityCleanup\Cleaner` | macht **Zugangsdaten unkenntlich** |

Die ersten beiden gehören zum `raw`-Feld. Der `Cleaner` nicht: er wirkt auch dort, wo es
kein `raw` gibt — `PayloadSanitizer` und `QueryNormalizer` schicken Business-Payload und
`payload.query` durch dieselbe Denylist. Ihn unter `RawPayload/` abzulegen wäre eine
Behauptung, die er nicht einhält.

Dass beide phasenlos sind und nicht unter `Processing/` liegen, hat denselben Grund wie
zuvor: `RawPayload\Builder` wird in `ResponseSensor` und `ExceptionSensor` injiziert,
hängt also in Phase A. Die Arbeit selbst passiert träge erst in Phase B — der Dienst
aber ist Teil des Request-Pfads.

Der Name `PayloadConfidentialityCleanup` ist absichtlich länger als nötig. Er grenzt
gegen zwei Nachbarn ab, die auch „bereinigen", aber etwas anderes meinen:
`Processing\Normalization\PayloadSanitizer` stellt Formkonformität her, `QueryNormalizer`
Struktur. Nur dieser Ordner stellt Vertraulichkeit her.

## Abhängigkeitsrichtung

```
Rang 0   EventFormat/Vocabulary, EventFormat/Payload, Contract, Exception
                                               (importieren nichts aus dem Bundle)
Rang 1   EventFormat/Event                     → Vocabulary
         Support/PayloadConfidentialityCleanup, Support/Identity  → EventFormat
Rang 2   EventFormat/Frame                     → Event
         Support/RawPayload                    → Cleanup, EventFormat
Rang 3   Sensor                                → EventFormat, Contract, RawPayload
Rang 4   Support/Telemetry                     → Sensor
Rang 5   Processing/Normalization              → EventFormat, Sensor, Cleanup, RawPayload
Rang 6   Delivery/Transport                    → EventFormat, Exception
Rang 7   Delivery/Heartbeat                    → Transport, Telemetry, Identity, EventFormat
Rang 8   Delivery/Dispatch                     → alles darüber
Rang 9   Command, DependencyInjection, IdsSensorBundle
```

Zyklenfrei, und seit `ArchitectureTest::testGroupsFormALayering()` nicht mehr nur als
Momentaufnahme: jeder Import muss auf gleichen oder kleineren Rang zeigen.

Die Tabelle ist feiner als der Verzeichnisbaum, und das ist kein Versehen. Die Ordner
beantworten „welcher Phase gehört das?", die Ränge beantworten „wer darf wen kennen?".
Am deutlichsten an `Support/`, dessen vier Mitglieder sich über drei Ränge verteilen:

- `PayloadConfidentialityCleanup/` und `Identity/` lesen nur aus dem `EventFormat/`.
- `RawPayload/` liegt darüber, weil der `Builder` den `Cleaner` benutzt — redigiert wird
  beim **Aufbau**, damit ein unredigierter Wert zu keinem Zeitpunkt in einer
  serialisierbaren Struktur existiert.
- `Telemetry/` steht über `Sensor/`, weil `DeferredCounters` `CaptureBudget`,
  `EventBuffer` und `AccessDecisionSensor` liest — die einzige Stelle, an der ein
  phasenloser Namensraum an Phase A hängt.

`Sensor/` und `Delivery/Dispatch/` sind Senken — niemand importiert aus ihnen. Für
`Dispatch/` ist das eigens getestet, weil die Rangfolge es nicht mitprüfen kann: es
liegt mit `Transport/` und `Heartbeat/` in derselben Gruppe, und innerhalb einer Gruppe
sind Importe erlaubt. Vor dem Umbau saßen dort zwei Zyklen: `Dispatch ↔ Message` über
den Frame und `Dispatch ↔ Heartbeat` über das RuntimeProfile.

## Was bewusst NICHT nach Klassen geschnitten ist

`tests/Integration/` und `tests/Unit/Latency/` folgen dem Verzeichnisbaum absichtlich
nicht. `NoPlaintextLeavesTheSensorTest` und `CaptureOverheadTest` prüfen je eine
**Zusage**, nicht eine Klasse — sie sollen auch dann noch am selben Ort stehen, wenn die
beteiligten Klassen umziehen. `tests/Unit/` dagegen spiegelt `src/` und ist bei der
Gruppierung mitgezogen.

## Verdrahtung

Die Aufteilung von `config/*.yaml` folgt den Abschaltgrenzen, nicht den Namespaces: eine
Datei je Ebene bzw. Feature, weil `IdsSensorBundle::loadExtension()` sie einzeln
importiert. Service-IDs sind dotted strings (`ids_sensor.*`) und ausdrücklich keine
FQCNs — die Begründung steht im Kopf von `config/services.yaml`.

Zwei Dateien folgen dennoch dem Namensraum: `services_payload_confidentiality_cleanup.yaml`
und `services_raw_payload.yaml`. Dort gibt es keine Abschaltgrenze, nach der zu schneiden
wäre — beide werden immer geladen, und `raw.enabled: false` entscheidet das `Gate` über
ein Konstruktorargument, nicht über den Dateiimport. Wo die Regel nichts vorgibt,
entscheidet die Trennung der Themen.

Folge für Umbauten: eine Umsortierung von `src/` berührt in `config/` nur `class:`- und
`factory:`-Werte. Der Container-Abdruck aus
`tests/Integration/ContainerFingerprintTest.php` macht daraus eine maschinelle Prüfung —
ändert sich mehr als der Namensraum, war es keine Umsortierung. Bei der Gruppierung nach
Phasen waren es 976 geänderte Zeilen und ausnahmslos Namensraumwerte; bei der Aufspaltung
von `Redaction/` kamen Service-IDs und Konfigurationsschlüssel hinzu, die Zahl der
Dienste blieb mit 778 gleich.
