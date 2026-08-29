# CLAUDE.md

Diese Datei definiert die Arbeitsweise und Qualitätsstandards für die Entwicklung dieses Symfony Bundles. Claude soll sich bei jeder Code-Änderung an diesen Regeln orientieren.

## Projektkontext

- **Typ:** Symfony Bundle (PHP)
- **PHP-Version:** >= 8.2
- **Symfony-Version:** ^6.4 / ^7.0
- **Coding Standard:** PSR-12 + Symfony Coding Standard
- **Static Analysis:** PHPStan (Level max, mindestens 8)
- **Tests:** PHPUnit (Unit + Functional/Integration Tests)
- **Namespace-Konvention:** `Vendor\BundleName\...`

---

## 1. Grundhaltung: Clean Code (Robert C. Martin)

Jede Codezeile wird öfter gelesen als geschrieben. Ziel ist Code, der sich selbst erklärt, leicht änderbar ist und keine Überraschungen enthält ("Prinzip der geringsten Verwunderung"). Die folgenden Regeln aus *Clean Code* sind verbindlich.

### 1.1 Aussagekräftige Namen

- Namen müssen ihre Absicht offenbaren: **Was** macht die Variable/Methode, **warum** existiert sie, **wie** wird sie benutzt.
- Keine Abkürzungen, keine kryptischen Bezeichner (`$mgr`, `$tmp`, `$d`).
- Klassen und Objekte: Substantive (`InvoiceRepository`, `PriceCalculator`).
- Methoden: Verben (`calculateTotal()`, `isValid()`, `hasExpired()`).
- Ein Wort pro Konzept konsistent verwenden (nicht mal `fetch`, mal `get`, mal `retrieve` für dieselbe Sache).
- Keine "Disinformation" — z.B. keine Collection `$accountList`, wenn es kein `List`-Objekt ist.
- Suchbare Namen statt Magic Numbers/Strings verwenden (Konstanten, Enums).

### 1.2 Funktionen/Methoden

- **Klein.** Eine Methode sollte im Idealfall nicht mehr als 15-20 Zeilen umfassen.
- **Eine Aufgabe (Single Level of Abstraction).** Eine Methode tut genau eine Sache und tut sie gut. Wenn du "und" brauchst, um zu beschreiben was eine Methode tut, ist sie zu groß.
- **Wenige Parameter.** Maximal 3 Parameter (niladisch/monadisch/dyadisch bevorzugt). Bei mehr Parametern: Value Object / DTO einführen.
  - **Bei Konstruktoren zuerst den Dienstschnitt prüfen, nicht die Zahl.** Ein DTO um zwölf injizierte Services zu wickeln verpackt die Kopplung nur; ein besser geschnittener Dienst löst sie. Maßstab ist §1.8 (Kohäsion): Tritt eine Gruppe von Feldern nur in denselben Methoden auf, gehört sie in eine eigene Klasse. Erst wenn der Schnitt nachweislich stimmt, darf ein Konstruktor über 3 liegen — mit Begründung im Docblock. Beispiele: `Delivery\Dispatch\FrameDispatcher`, `Sensor\Context\CapturedEventBinder`.
  - **Ausgenommen: DTOs, die ein festes Drahtformat abbilden.** `IdsEventData\Event\NormalizedEvent` bildet 1:1 die Pflichtfelder aus Konzept Abschnitt 3 ab. Diese Klassen *sind* bereits die Value Objects, die die Regel als Lösung fordert.
- **Keine Flag-Parameter** (`function save(bool $isUpdate)`) — stattdessen zwei explizite Methoden.
- **Keine Seiteneffekte.** Eine Methode namens `isValid()` darf keine Datenbankänderung auslösen.
- **Command Query Separation:** Eine Methode ändert entweder einen Zustand ODER gibt eine Information zurück, niemals beides.
- **Fail Fast:** Guard Clauses statt tief verschachtelter `if`-Kaskaden verwenden.

### 1.3 Kommentare

- Guter Code braucht wenige Kommentare, weil er sich selbst erklärt. Ein Kommentar ist oft ein Eingeständnis, dass der Code nicht klar genug ist — zuerst versuchen, den Code selbst klarer zu machen (extrahierte Methode mit sprechendem Namen statt Kommentar).
- Erlaubt und sinnvoll: PHPDoc für öffentliche API (Bundle-Konfiguration, Extension Points), Erklärung von *Warum* (Geschäftslogik, Workarounds, Legacy-Constraints), `@internal`, `@deprecated`.
- Verboten: auskommentierter Code (Git-Historie nutzen statt Code "aufzubewahren"), redundante Kommentare, die nur wiederholen was der Code sagt.

### 1.4 Formatierung

- PSR-12 verbindlich, automatisiert via `php-cs-fixer` oder `ecs` (Easy Coding Standard, Symfony-Standard).
- Vertikale Nähe: Zusammengehöriger Code steht nah beieinander; aufrufende Methode steht möglichst über der aufgerufenen Methode.
- Eine Klasse = eine Datei = ein Konzept.
- Strikte Typisierung überall: `declare(strict_types=1);` in jeder Datei.

### 1.5 Objekte & Datenstrukturen

- **Gesetz von Demeter:** Nur mit direkten "Freunden" sprechen. Keine Ketten wie `$order->getCustomer()->getAddress()->getCity()`. Stattdessen delegierende Methoden oder Tell-Don't-Ask anwenden.
- Objekte verstecken Daten hinter Verhalten (Kapselung), reine Datenstrukturen (DTOs, `readonly` Klassen) haben keine Business-Logik.
- Value Objects für fachliche Konzepte nutzen (z.B. `Money`, `EmailAddress`) statt primitive Typen durchzureichen ("Primitive Obsession" vermeiden).
  - **Ausgenommen: der Request-Pfad und `projektmotor/ids-event-data`.** Alles unter `src/Sensor/` läuft unter dem 5-ms-Budget aus Konzept 2.1; bei bis zu 200 Autorisierungsentscheidungen pro Request zählt jede Allokation. Und das Ereignisformat darf nichts Fremdes importieren, weil dasselbe Paket auch das IdsBackendBundle konsumiert — durchgesetzt dort von `ArchitectureTest::testImportsNothingForeign()`. `eventId`, `correlationId`, `applicationId` und `instanceId` bleiben deshalb `string`.

### 1.6 Fehlerbehandlung

- Exceptions statt Rückgabecodes. Eigene Exception-Hierarchie im Bundle (`Vendor\BundleName\Exception\...`), abgeleitet von aussagekräftigen Basisklassen.
  - **Ausgenommen: dieses Bundle ist fail-open** (`doc/concept/concept-v1.md` Abschnitt 4: „Eine Störung des IDS darf die überwachte Anwendung unter keinen Umständen beeinträchtigen"). Wer nach außen grundsätzlich nicht wirft, braucht keinen Typ zum Fangen — die übrigen `throw`-Stellen liegen in der Compile-Zeit oder in Programmierfehler-Pfaden und benutzen bewusst SPL- und Symfony-Typen. Eine eigene Klasse gibt es genau dort, wo eine Entscheidung daran hängt: `Exception\UnshippableFrameException` sagt dem Spool-Drainer, dass ein erneuter Versuch zwecklos ist.
- Kontext in Exceptions mitgeben (was ist passiert, mit welchen Werten).
- `try/catch` nicht zur Steuerung des normalen Kontrollflusses missbrauchen.
- Keine `null`-Rückgaben, wo ein leeres Objekt/Collection oder eine Exception klarer ist (Null Object Pattern erwägen).
  - **Ausgenommen: die vier `actor.*`-Felder.** Konzept 2.2.4 schreibt sie als „immer vorhanden, aber nullable" vor. Das Null-Objekt existiert bereits und heißt `IdsEventData\Event\Actor::anonymous()`.
- Keine `null`-Parameter übergeben, wo es vermeidbar ist.

### 1.7 Grenzen (Boundaries)

- Symfony-Core-Klassen, Drittanbieter-Bibliotheken und externe APIs immer hinter eigenen Interfaces/Adaptern kapseln — niemals direkt im Domain-Code verstreut nutzen.
- Dependency Injection statt `new` innerhalb von Business-Logik (Symfony Service Container konsequent nutzen).
- Public API des Bundles so gestalten, dass sie sich bei internen Refactorings möglichst nicht ändert. **In diesem Bundle ist das `Contract/`** — alles andere trägt `@internal`, durchgesetzt von `ArchitectureTest`. Das Drahtformat liegt im eigenen Paket `projektmotor/ids-event-data` und ist dort vollständig öffentlich. `DependencyInjection/` und `config/` gehören ausdrücklich NICHT dazu.

### 1.8 Klassen

- **Single Responsibility Principle:** Ein Grund zur Änderung pro Klasse. Faustregel: Klassenname sollte die Verantwortung in ~25 Wörtern ohne "und"/"oder" beschreiben können.
- **Hohe Kohäsion:** Methoden einer Klasse nutzen möglichst viele der Instanzvariablen.
- **Kleine Klassen:** Lieber viele kleine, fokussierte Klassen als wenige große "Gottklassen".
- Abhängigkeiten über Konstruktor-Injection, niemals über statische Aufrufe oder Service Locator innerhalb der Domain-Logik.

### 1.9 Emergent Design (Kent Becks 4 Regeln, zitiert bei Martin)

Code gilt als "clean", wenn er in dieser Priorität:
1. Alle Tests bestehen.
2. Keine Duplikation enthält (DRY).
3. Die Absicht des Programmierers klar ausdrückt.
4. Die minimale Anzahl an Klassen/Methoden hat.

### 1.10 Tests (F.I.R.S.T.)

- **Fast:** Unit-Tests laufen ohne DB/Netzwerk.
- **Independent:** Tests dürfen sich nicht gegenseitig beeinflussen oder in Reihenfolge abhängig sein.
- **Repeatable:** Gleiches Ergebnis in jeder Umgebung.
- **Self-Validating:** Boolean Pass/Fail, keine manuelle Log-Prüfung.
- **Timely:** Tests werden zusammen mit (oder vor) dem Produktivcode geschrieben.
- Ein Assert-Konzept pro Test, sprechende Testnamen.
- **Bezeichner englisch, Prosa deutsch.** Klassen-, Methoden-, Variablen- und Testnamen sind englisch in CamelCase (`testCalculatesDiscountForPremiumCustomer`); Docblocks, Kommentare und Assertion-Meldungen sind deutsch. Das gilt für `tests/` genauso wie für `src/`.
- Tests genauso sauber halten wie Produktivcode — auch hier gelten die Namens- und Funktionsregeln.

---

## 2. Symfony-Bundle-spezifische Konventionen

### 2.1 Struktur

Geschnitten wird nach den Phasen der Pipeline, nicht nach technischen Schubladen. Ausführliche Begründung je Namensraum: [`doc/concept/structure.md`](doc/concept/structure.md).

```
src/
├── IdsSensorBundle.php      AbstractBundle — kein Extension/Configuration-Paar
├── Contract/                was die überwachte Anwendung implementiert
├── Sensor/                  PHASE A: Erfassung im Request, unter dem 5-ms-Budget
├── Processing/              PHASE B, 1. Takt: aus Erfasstem wird das Ereignis
│   └── Normalization/       Konzept 2.2 + 3.1
├── Delivery/                PHASE B, 2. Takt: das Ereignis verlässt den Prozess
│   ├── Dispatch/            Spitze der Pipeline — niemand importiert von hier
│   ├── Transport/           Collector-HTTP, Token, Spool, Breaker
│   └── Heartbeat/           Konzept 3.4
├── Support/                 gehört keiner Phase — beide benutzen es
│   ├── PayloadConfidentialityCleanup/
│   │                        macht Zugangsdaten unkenntlich (Konzept 4.5.1)
│   ├── RawPayload/          Gate: reist raw mit? Builder: was steht drin?
│   ├── Identity/            Sensoridentität
│   └── Telemetry/           Zähler, Latenzen
├── Command/                 Konsole
├── DependencyInjection/     ConfigurationTree
└── Exception/
config/                      am Repo-Stamm, nicht unter Resources/ —
                             getPath() liefert dirname($classFile, 2)
tests/
├── Unit/                    spiegelt src/ inkl. der drei Gruppen
├── Integration/             nach Zusagen geschnitten, nicht nach Namespaces
├── Functional/
└── Fixtures/

vendor/projektmotor/ids-event-data/   das Drahtformat (Konzept 3) — eigenes Paket,
                                      Namensraum ProjektMotor\IdsEventData\
```

Das Ereignisformat lag bis zur Ausgliederung als `src/EventFormat/` hier. Es ist jetzt
eine gewöhnliche Abhängigkeit, weil es der Vertrag zwischen **zwei** Paketen ist: der
Sensor schreibt das Format, das IdsBackendBundle liest es. Es importiert seinerseits
nichts — kein Symfony, kein PSR — und liegt damit unter allem in `src/`.

Die oberste Ebene ist die Pipelinephase. `Support/` ist eng zu lesen — was dort landet,
muss nachweislich von beiden Phasen benutzt werden, sonst wird der Ordner zur
Rumpelkammer. Erzwungen von `ArchitectureTest::testGroupsFormALayering()`: jeder
Namensraum steht einzeln in einer Rangfolge, ein neuer ohne Eintrag lässt den Test
fehlschlagen.

Abweichungen gegenüber der Symfony-Standardvorlage, jeweils begründet:

- Kein `Controller/`, `Entity/`, `Repository/`, `DTO/` — das Bundle ist ein Sensor, keine Anwendung.
- Kein `DependencyInjection/Configuration.php` und keine `*Extension.php`: `AbstractBundle` erledigt beides über `configure()` und `loadExtension()`.
- Kein `Resources/config/` — siehe `getPath()` oben.
- Kein Autowiring, keine FQCN-Service-IDs. Die IDs sind gepunktet (`ids_sensor.*`), weil FQCN-IDs die Interna zu injizierbarer API machen würden.

### 2.2 Regeln

- Services explizit deklarieren; keine impliziten Autowiring-Überraschungen. **Hier schärfer:** ausnahmslos alle Dienste sind `public: false` und tragen gepunktete IDs — kein Autowiring, keine FQCN-IDs (siehe §2.1).
- Konfigurationsbaum mit sprechenden Knotennamen, sinnvollen Defaults und Validierung (`->validate()`), damit Fehlkonfiguration früh auffällt (Fail Fast). Hier: `DependencyInjection\ConfigurationTree`, eingehängt über `AbstractBundle::configure()`.
- Extension Points (Interfaces, Events, Compiler Passes) klar dokumentieren — das ist die "Boundary" für Nutzer des Bundles.
- Keine harten Abhängigkeiten zu konkreten Symfony-Implementierungen, wo ein Interface reicht (Testbarkeit, Austauschbarkeit).
- Bundle muss ohne Seiteneffekte installierbar sein (keine impliziten DB-Schreibvorgänge in `boot()`).
- Semantische Versionierung einhalten; Breaking Changes im `CHANGELOG.md` klar kennzeichnen.

---

## 3. Definition of Done

Eine Änderung gilt erst als fertig, wenn:

- [ ] `declare(strict_types=1);` gesetzt und volle Typisierung (Parameter, Rückgabewerte, Properties) vorhanden ist
- [ ] PHPStan (Level ≥ 8) ohne Fehler durchläuft
- [ ] Code-Style-Check (`php-cs-fixer`/`ecs`) grün ist
- [ ] Unit-Tests für neue/geänderte Logik existieren und grün sind
- [ ] Keine Methode > ~20 Zeilen ohne triftigen Grund
- [ ] Keine Duplikation (DRY) eingeführt wurde
- [ ] Namen von Klassen/Methoden/Variablen ohne Kontext verständlich sind
- [ ] Keine auskommentierten Codezeilen im Commit
- [ ] Öffentliche API-Änderungen im `CHANGELOG.md` dokumentiert sind

---

## 4. Arbeitsweise für Claude

- Beim Schreiben von neuem Code: zuerst überlegen, welche Klasse welche einzelne Verantwortung trägt, bevor Code entsteht.
- Beim Review/Refactoring bestehenden Codes: aktiv auf Verstöße gegen obige Regeln hinweisen (lange Methoden, sprechende Namen fehlen, Law-of-Demeter-Verletzungen, fehlende Tests) und Verbesserungsvorschläge machen.
- Bei Unsicherheit zwischen "funktioniert schnell" und "clean, aber etwas mehr Aufwand": **Clean Code hat Vorrang**, außer explizit anders vom Nutzer gewünscht.
- Immer passende Tests mitliefern oder vorschlagen, wenn neue Logik entsteht.
- Immer die zugehörige Dokumentation anpassen, sofern eine existiert.
- Immer wenn eine Definition of Done erfüllt ist, wird ein neuer Commit mit aussagekräftigem aber kompakten (max 300 Zeichen) Kommentar erstellt - NENNE DICHT NICHT ALS CO-AUTHOR ODER AUTHOR!!!
- WICHTIG: es ist STRENGSTENS VERBOTEN, dass Claude Code selbstständig in das remote repository pushed 
- es herrscht ein direkter, inhaltlicher Zusammenhamg zwischen dem Konzept(doc/concept/concept-v1.md) <-> Dokumentation (doc-Verzeichnis) <-> Quellcode, FOLGERUNG: Änderst du den Quellcode, müssen die Änderungen in Dokumentation & Konzept auch angepasst werden
- Vermeidung von Ping-Ping-Änderungen: vor Änderungen prüfe das CHANGELOG.md, ob die Entscheidung zuvor bewusst anders getroffen wurde