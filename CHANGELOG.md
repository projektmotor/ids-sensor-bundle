# Changelog

Alle nennenswerten Änderungen an diesem Paket werden hier festgehalten.

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [Semantic Versioning](https://semver.org/lang/de/).

Semver gilt für `Contract\*`. Alles andere trägt `@internal` und kann sich
jederzeit ändern — durchgesetzt von
`tests/Unit/ArchitectureTest::testOnlyContractIsPublic()`. Das Drahtformat hat
ein eigenes Paket und einen eigenen Changelog:
[`projektmotor/ids-event-data`](https://github.com/projektmotor/ids-event-data).

## [0.3.0] — 2026-08-30

Setzt `projektmotor/ids-event-data` `^0.5` voraus.

### Breaking — `schema_version` 4: jeder Frame trägt eine `frame_id`

Der Collector bekommt eine Tabelle `frames`, in der jede Sendung als Zeile steht
(Konzept 4.2.1). Sie braucht einen Schlüssel, und bei at-least-once-Zustellung muss ein
erneut zugestellter Frame erkennbar sein — dieselbe Aufgabe, die `event_id` seit jeher
eine Ebene tiefer löst. Ohne ihn zählte jede Auswertung über die Zustellqualität eine
Wiederholung als zweite Sendung.

**Der Collector muss vor dem Sensor stehen.** Ein Sensor der Fassung 4 gegen einen
Collector der Fassung 3 ist nach Konzept 3.7 zwar verträglich — unbekannte
Top-Level-Felder landen in `unknown_fields` —, aber die `frames`-Tabelle bliebe leer, und
sichtbar wäre das nirgends.

### Added — die Kennung entsteht im `FrameDispatcher`, einmal je Sendung

Sie wird **vor** der Verzweigung Collector/Spool gezogen. Das ist der Punkt, an dem die
Sendung entsteht; alles Spätere sind Wege, die sie nimmt. Zöge der Spool-Zweig eine
eigene Kennung, wäre ein fehlgeschlagener Direktversuch mit anschließendem Nachsenden
zweimal dieselbe Sendung unter zwei Schlüsseln — und die Duplikaterkennung des
Collectors träfe genau den Fall nicht, für den sie gebaut wurde.

Der Generator ist ein Pflichtargument, aus demselben Grund wie `$runtime` und `$spool` an
derselben Stelle: Ein fehlendes Argument bedeutete Frames ohne Zeilenschlüssel, also eine
unerkennbare Doppelzustellung — die stille Richtung.

### Changed — ein Generator für beide Kennungen

`EventIdGeneratorInterface`/`UuidV7EventIdGenerator` heißen jetzt
`UuidGeneratorInterface`/`UuidV7Generator`, der Dienst `ids_sensor.event_id_generator`
heißt `ids_sensor.uuid_generator`. Zwei identische Vier-Zeilen-Klassen nebeneinander
wären Duplikation gewesen; der alte Name an der neuen Verwendungsstelle wäre eine
Unwahrheit — eine `frame_id` ist keine `event_id`. Die Begründung für v7 gilt für beide
wortgleich: `frames` ist nach `flushed_at` partitioniert und hat
`PRIMARY KEY (frame_id, flushed_at)`, zeitgeordnete Kennungen schreiben dort in
benachbarte Index-Bereiche. Beides trägt `@internal`, Semver ist nicht berührt.

Nebenwirkung im Container-Abdruck: Der Dienst trägt jetzt `container.hot_path`, weil der
`frame_dispatcher` von ihm abhängt. Das ist richtig — er läuft im Request-Pfad.

### Fixed — der Spool ist ein Bestandsformat, und der Drainer behandelt ihn so

Beim Sensor-Update liegen Zeilen mit `schema_version: 3` und ohne `frame_id` auf der
Platte. Sie werden **unverändert** nachgesendet: `SpoolDrainer::markPath()` setzt
weiterhin nur `dispatch_path` und `spool_delay_ms`, und `HttpShipper::ship()` bekommt
**keine** Prüfung auf `frame_id`. Eine solche Prüfung hätte punktgenau den Altbestand
verworfen — den Teil des Spools, der am ehesten schon einen Ausfall überlebt hat.

Ebenso wenig wird beim Drain eine Kennung nachgereicht. Sie wäre bei jedem
Zustellversuch eine andere, und Zustellversuche wiederholen sich: `reclaimStalled()` holt
eine `.draining`-Datei zurück, deren Zeilen bereits gesendet sein können. Eine erfundene
Kennung machte aus der erkennbaren Doppelzustellung eine unerkennbare — das Gegenteil
dessen, wozu das Feld eingeführt wurde. Der Collector leitet sie stattdessen aus
`sensor_id | process_epoch | pid | flushed_at` ab (Konzept 3.3).

Neuer Regressionstest
`FileSpoolTest::testALegacyLineWithoutFrameIdIsStillDelivered()`. Nebenbefund derselben
Stelle: Der Frame-Helfer dort trug noch das seit Fassung 2 entfallene `v => 1` — er
bildet jetzt die aktuelle Form ab, und der veraltete Zustand steht absichtlich in einem
eigenen `legacyFrame()`.

### Changed — Konzept und Dokumentation nachgezogen

Konzept 3.3 (Frame-Beispiel und die Kennung), 3.4 (Heartbeat — er bekommt **kein**
`frame_id`, seine Fassung steigt trotzdem), 3.6, 3.7 (die Bump-Regel für ein neues
Pflichtfeld) und 4.1. Abschnitt 4.2 ist dabei neu gegliedert: 4.2.1 trägt das
vollständige, ausführbare Schema an einem Stück, 4.2.2 die gesamte Begründung samt der
neuen Tabellen `frames` und `heartbeats`. `doc/03`, `doc/05` und `doc/07` folgen.

## [0.2.0] — 2026-08-29

Die erste Fassung, die den REST-Transport ausliefert — und die erste, in der die
Beobachtung nicht mehr am HttpKernel endet.

**Was bricht.** Der Transport ist vollständig ausgetauscht: Der Sensor spricht den
Collector über HTTPS an, statt in einen Redis-Stream zu schreiben. `schema_version` steht
auf 3, die Kennungen sind drei UUIDs, und `symfony/messenger` ist von einer harten zu einer
optionalen Abhängigkeit geworden.

**Der Umstieg von 0.1.x ist ohne Anpassung nicht möglich** und läuft in dieser Reihenfolge:

1. Am Collector registrieren — er vergibt `application_id`, `environment_id`, `sensor_id`
   (alle drei UUIDs) sowie Benutzername und Passwort. Der Sensor leitet nichts mehr ab.
2. `sensor_id` **je Node** setzen, nicht aus einer geteilten ConfigMap: Sonst sind
   Replikate ununterscheidbar und `ids.sensor_silent` schweigt beim Ausfall einzelner.
3. `transport.*` durch `collector.*` ersetzen. Die vollständige Gegenüberstellung
   entfallener und neuer Schlüssel steht unten unter *Breaking — Umsetzung: REST-Transport,
   drei UUID-Kennungen, schema_version 2*.
4. `ids:sensor:setup-check` laufen lassen — er prüft fehlende Zugangsdaten, eine
   `base_uri` ohne HTTPS und ein abgeschaltetes `verify_tls`.

Der Collector muss dabei **zuerst** stehen: Er ist die Quelle der Kennungen, und ein Sensor
ohne sie kommt nicht am Ingest vorbei.

**Was nicht bricht.** `Contract\*` ist unverändert. `IdsResourceIdentifier` und
`SecurityRelevantBusinessEvent` sehen genauso aus wie in 0.1.1, obwohl die
Ressourcenangabe darunter zerlegt wurde — das war eine der Randbedingungen dieser Fassung
und keine glückliche Fügung.

**Fünf offene Punkte des Konzepts sind geschlossen** (E1, OB10, O2, OB11, OB8). Zwei davon
schließen echte Beobachtungslücken: Konsolenläufe und die Rechteübernahme per User-Switch
erzeugten bis hierher überhaupt kein Ereignis. **`schema_version` bleibt dabei bei 3** —
alle neuen Ereignistypen und Felder sind additiv im Sinne von Konzept 3.7, der Collector
braucht keine Migration.

Setzt `projektmotor/ids-event-data` `^0.4` voraus.

### Changed — Datenschutz ist entschieden, nicht offen (offener Punkt OB8)

Der Punkt stand als „bewusst nachrangig behandelt, vor produktivem Einsatz erneut zu
prüfen" — eine Vertagung ohne Adressat. Er ist jetzt beantwortet, und die Antwort ist eine
Abgrenzung: **Das Bundle stellt Vertraulichkeit von Zugangsdaten her, nicht Datenschutz,
und kann es nicht.** Ob eine Verarbeitung zulässig ist, wie lange sie zulässig bleibt und
wer Auskunft bekommt, entscheidet sich an der Anwendung und ihrem Betrieb, nicht an einer
Denylist.

`doc/06` formuliert die drei Fragen deshalb als **Betreiberpflichten** aus, jeweils mit dem
Hebel, der dazugehört. Zwei Feststellungen daraus verdienen es, hier zu stehen:

**Die vier `actor.*`-Felder sind nicht abschaltbar.** `session_id_hash` und
`client_fingerprint` haben Schalter, `actor.user` und `actor.ip` nicht — ohne Akteur
arbeitet keine der nutzerbezogenen Regeln. Wer sie nicht verarbeiten darf, kann das Bundle
nicht betreiben. Das ist die ehrliche Auskunft und keine Einstellung.

**Der Spool ist ein Datenspeicher.** Bei erreichbarem Collector bleibt auf der Platte
nichts; bei einem Ausfall liegen dort vollständige Frames samt `raw`, unverschlüsselt, auf
der Platte der überwachten Anwendung — genau dort, wo ein Angreifer mit Codeausführung sie
fände. `doc/07` führt das jetzt als eigenen Abschnitt mit drei Betriebspflichten:
Verzeichnisrechte, laufender cron, Aufbewahrung.

Dazu ein Hinweis zur Auskunftsfähigkeit, der bislang nirgends stand: `actor.user` ist bei
Anmeldefehlversuchen die *versuchte* Kennung und damit angreiferkontrolliert. Eine Auskunft,
die stur danach filtert, gibt fremde Ereignisse heraus.

Kein Code — die Änderung liegt in `doc/06`, `doc/07` und Konzept 6.3.

### Added — `raw.always_for`: Belege für Befunde auf `info`-Events (offener Punkt OB11)

Ob `raw` mitreist, hing ausschließlich an `event_severity`. Ein Alarm entsteht aber erst im
Collector und kann nicht zurückwirken — ein Befund wie R2b („Pfadlisten-Treffer mit Status
200") stand deshalb ohne forensischen Beleg da: Das Event ist `info`, das `raw` war längst
verworfen, als der Alarm entstand.

Der Sensor kann das nicht selbst entscheiden. Er kennt die Erkennungsregeln des Collectors
nicht, und nach Konzept 2. soll er sie nicht kennen. Entschieden wird deshalb zweistufig:

1. **Sensorseitig benennt der Betreiber Kandidaten** — `raw.always_for.event_types` (genaue
   Übereinstimmung) und `raw.always_for.path_patterns` (PCRE gegen `payload.path`).
2. **Collectorseitig wird weiter gefiltert.** Nur der Collector kennt die Regeln und kann
   endgültig entscheiden, was er behält. Konzept 3.5 und 4.5.2 halten diese Hälfte fest.

Die Aufgabenteilung ist damit die richtige herum: Der Sensor liefert, der Collector wählt
aus. **Beide Listen sind leer als Vorgabe** — wer nichts einstellt, bekommt das bisherige
Verhalten unverändert.

**Wer die Liste benutzt, gibt eine Grenze auf.** `raw` macht über 95 % des Datenvolumens
aus, und `info` ist die Masse aller Events; ein Muster wie `#^/#` hebt das Volumenbudget um
Größenordnungen. Gedacht ist sie für einzelne, benannte Pfade. `raw.enabled: false` schlägt
die Liste — wer das Feld ganz abschaltet, hat eine Entscheidung getroffen, die eine
Kandidatenliste nicht unterlaufen darf.

`Gate::allows()` bekommt dafür Kontext (`event_type`, `path`) und damit eine neue Signatur.
Der Pfad kommt aus dem bereits gebauten **Payload**, nicht aus dem erfassten Rohwert: Dort
steht er gekürzt und, wo er durch die Redaktion läuft, bereinigt — ein Muster gegen den
Rohwert könnte auf einem Wert treffen, der so nie versendet wird.

**Nicht umgesetzt bleibt der dritte im Konzept erwogene Weg:** der Collector fordert `raw`
nach, der Sensor hält Kandidaten kurz vor. Er kostete Speicher im Request-Pfad und steht
damit gegen das Latenzbudget aus 2.1.

### Added — `resource_type` und `resource_id` auf beiden Ebenen (offener Punkt O2)

Regel **B7** ist eine Kernel-Regel und vergleicht „numerisch benachbarte
Ressourcen-Identifier desselben Typs"; **P1** und **P2** arbeiten auf erfolgreichen
Zugriffen und damit ebenfalls auf `kernel.response`. Dort standen bislang nur `path` und
`route` — die Nachbarschaft war damit nur über Zeichenkettenanalyse im Collector zu haben,
für jede Zeile erneut. Auf der Security-Ebene stand die Angabe als kombinierter String
(`Order#42`), der dasselbe Problem eine Ebene später erzeugte.

Beide Ebenen tragen jetzt `resource_type` und `resource_id`. **`resource` bleibt
unverändert bestehen** — die beiden ersetzen es nicht, sie zerlegen es: Der kombinierte
String ist der Beleg für einen Menschen, der einen Vorfall liest, die zerlegten Felder sind
der Gruppierschlüssel für die Regeln. `Contract\IdsResourceIdentifier` ist unangetastet;
zerlegt wird sensorseitig, damit die Semver-Fläche nicht bricht.

**Das Vokabular des Typs hängt an der Quelle, und das ist eine Entscheidung.** Die
Security-Ebene benennt ihn nach der Klasse des Voter-Subjekts (`order`), die Kernel-Ebene
nach dem Routennamen (`app_order_show`). Der Collector gruppiert deshalb **innerhalb einer
Ebene**. Ein gemeinsames Vokabular wäre nur um den Preis einer geratenen Übersetzung zu
haben: Aus `/api/orders/42` den Typ `order` abzuleiten verlangte eine Pfadgrammatik samt
Singularbildung — sprachabhängig, projektabhängig und lautlos falsch, wo sie danebenliegt.
Ein Gruppierschlüssel, der manchmal danebenliegt, ist schlechter als einer, der ehrlich nur
innerhalb seiner Ebene gilt.

**Welcher Routenparameter die Kennung ist**, entscheidet eine feste Reihenfolge: `id`,
sonst der erste Parameter, dessen Name auf `id` endet, sonst — und nur dann — ein einzelner
übrig gebliebener. Der letzte Fall deckt `{slug}` und `{uuid}` ab, ohne bei zweien zu
raten. Parameter mit führendem Unterstrich zählen nicht mit: `_locale` steht in den
Routenparametern, sobald es im Pfad vorkommt, und ohne den Ausschluss wäre `/de/impressum`
eine Ressource mit der Kennung `de` — die Nachbarschaftsregel zählte Sprachwechsel.

Ohne Route keine Ressource: Ein routenloser Pfad wie `/wp-admin/setup-config.php` ist genau
das Scanning-Signal aus 2.1.1, und ein erfundener Typ machte daraus eine Ressourcengruppe.

**Die Ableitung läuft in Phase B**, also nach dem Absenden der Antwort und außerhalb des
5-ms-Budgets. Der Sensor reicht nur die Routenparameter durch, die der Router ohnehin schon
aufgelöst hat — unter `CapturedEvent::KEY_ROUTE_PARAMETERS`. Der führende Unterstrich ist
dabei die neue Regel dieser Klasse: So beginnende Schlüssel sind Rohstoff für die
Normalisierung und **nie** ein Feld des Ereignisses. Ein eigener Test hält fest, dass sie
den Prozess nicht verlassen — sonst gingen `_locale`, `_format` und jeder andere
Routenparameter unredigiert an den Collector, an der Denylist vorbei, weil niemand dieses
Feld kennt.

Neu ist außerdem `Sensor\Security\ResolvedResource`: ein Wertobjekt, aus dem alle drei
Felder in EINEM Durchlauf entstehen. Zwei getrennte Auflösungen könnten auseinanderlaufen —
bei einem Doctrine-Proxy, dessen `getId()` beim zweiten Aufruf doch lädt, oder schlicht,
wenn jemand später nur eine der beiden Stellen anfasst.

### Added — Die Rechteübernahme hinterlässt jetzt eine Spur (offener Punkt OB10)

Symfonys `SwitchUserListener` erzeugt **keines** der drei Security-Ereignisse — weder eine
Anmeldung noch eine Autorisierungsentscheidung über den übernommenen Nutzer. Ein
Administrator, der in ein Kundenkonto wechselte, hinterließ deshalb überhaupt keine Spur,
und alles, was er danach tat, sah aus wie eine Handlung des Kunden. Der bisherige
Workaround — die Anwendung solle es selbst als Business-Event der Klasse V6 melden —
entfällt.

Neu ist `Sensor\Security\SwitchUserSensor` mit zwei Ereignissen:

| `event_type` | Wann | Stufe |
|---|---|---|
| `security.switch_user` | eine fremde Identität wird übernommen | `warning` |
| `security.switch_user.exit` | die Übernahme wird beendet | `info` |

**Zwei Typen und nicht der eine, den Konzept 6.3 angedacht hatte.** Ohne das Ende bliebe
jede spätere Handlung unter der fremden Identität **dauerhaft** von einer echten Handlung
des Übernommenen ununterscheidbar — das Ereignis wäre eine Feststellung ohne Konsequenz.
Erst die beiden klammern das Zeitfenster, in dem die Zuordnung nicht stimmt. Symfony feuert
für beide Richtungen dasselbe Framework-Event; welche vorliegt, erkennt der Sensor am
Token: beim Wechsel hinein ein `SwitchUserToken`, beim Verlassen der wiederhergestellte
ursprüngliche. Das ist keine Heuristik, sondern die Bauweise des Listeners.

Entgegen der Annahme im Konzept war dafür **keine neue Fassung** nötig: `event_type` ist
nach 3.7 ein offenes Vokabular, `schema_version` bleibt 3.

**Die Richtung der Zuordnung ist die eigentliche Aussage.** `actor.user` trägt den
Übernehmenden, `payload.target_user` den Übernommenen. Andersherum wäre der Vorgang von
einer gewöhnlichen Handlung des Kunden nicht zu unterscheiden. Der Übernehmende kommt aus
dem ORIGINALTOKEN des `SwitchUserToken` und nicht aus dem Token-Speicher — der Listener
setzt den neuen Token erst nach dem Ereignis.

**Kein `firewall` im Payload.** `SwitchUserEvent` trägt keinen, und `TokenInterface` kennt
`getFirewallName()` nicht; nur einzelne Token-Klassen tun das. Ihn über eine Typprüfung
herbeizuraten hieße, ein Feld des Drahtformats von der Bauart eines fremden Tokens abhängig
zu machen. `security.access_decision` kommt aus demselben Grund ohne ihn aus.

**Kein eigener Schalter.** Der Sensor hängt an `layers.security.authentication`. Der
Wechsel in eine fremde Identität ist keine Bauart von Anmeldung, sondern die Voraussetzung
dafür, dass die drei anderen Ereignisse überhaupt der richtigen Person zugeordnet werden;
ihn abschaltbar zu machen hieße, die Zuordnung abschaltbar zu machen.

**Nebenbefund aus dem Integrationstest:** Symfonys eigene Prüfung auf
`ROLE_ALLOWED_TO_SWITCH` läuft durch den `AccessDecisionManager` und erzeugte schon bisher
ein `security.access_decision`. Das ersetzt die beiden neuen Ereignisse nicht — es sagt,
dass jemand die Übernahme *durfte*, nicht, *wen* er übernommen hat.

### Added — Die Kernel-Ebene sieht jetzt auch die Konsole (offener Punkt E1)

Console-Commands, Messenger-Worker und Cronjobs erzeugten keines der drei
HttpKernel-Ereignisse — ein Angreifer mit Codeausführung arbeitet aber genau dort. Das
Bundle sah von der Konsole bislang nur die Korrelationskennung
(`ConsoleCorrelationListener`) und den Versandzeitpunkt (`FlushListener`); beobachtet
wurde nichts.

Neu ist `Sensor\Console\CommandSensor` mit zwei Ereignissen:

| `event_type` | Wann | Stufe | Payload |
|---|---|---|---|
| `console.command` | jeder Konsolenlauf, auch `messenger:consume` | `info` | `command` |
| `console.error` | jeder mit einer Ausnahme gescheiterte Befehl | `warning` | `command`, `exception_class`, `exception_message`, `exit_code` |

**Die Ebene bleibt `kernel`, `schema_version` bleibt 3.** `Vocabulary\Layer` bildet den
collectorseitigen ENUM `layer_type` ab und ist ein geschlossenes Vokabular — ein vierter
Wert wäre ein Fassungswechsel samt Datenbankmigration, bevor auch nur ein Sensor senden
dürfte. `event_type` ist nach Konzept 3.7 offen. Die Ebene heißt damit nach dem
Einstiegspunkt des Frameworks statt nach HTTP; die Ereignisse tragen trotzdem ihr eigenes
Präfix, weil `kernel.console.*` denselben Wert mit einer anderen Bedeutung belegt hätte.

**Kein Feld für die Aufrufargumente.** Eine Befehlszeile führt regelmäßig genau die Werte,
die Konzept 4.5.1 unkenntlich machen soll: `--password=`, ein Token als
Stellungsargument, eine Verbindungszeichenkette. Anders als beim Anfragekörper gäbe es
keine Grammatik, an der sich Parameternamen erkennen ließen — ein solches Feld wäre ein
Weg an der Redaktion vorbei ohne jede Gegenmaßnahme. Der Befehlsname selbst wird redigiert
und auf 128 Zeichen gekürzt: Bei einem unbekannten Befehl IST er die Eingabe des Aufrufers.

**`console.error` ist `warning`, nicht `critical`.** Auf der Konsole gibt es kein
Gegenstück zur Aufteilung 5xx/4xx aus 2.2.1 — ein vertippter Befehlsname und ein
abgestürzter Worker enden beide mit einer Ausnahme und Exit-Code 1. Jeden Konsolenfehler
`critical` zu nennen entwertete den Begriff, den 2.2.1 Serverfehlern vorbehält, und die
Alarmschwelle des Collectors hinge an der Tippsicherheit des Betreibers. Die Forensik
verliert nichts: `warning` trägt `raw`, der Stacktrace reist also mit.

**`layers.kernel.console.ignored_commands` ist die einzige Ausschlussliste mit einer
Vorgabe.** `#^ids:sensor:#` nimmt die eigenen Befehle des Bundles aus. Ohne den Ausschluss
erzeugte der minütliche `ids:sensor:spool:flush` ein Ereignis, das der nächste Lauf
versendet, um dabei das nächste zu erzeugen — eine Spur, die ausschließlich die eigene
Maschinerie beschreibt und mit der cron-Frequenz wächst. Dass der Sensor lebt, meldet der
Heartbeat billiger. Der Unterschied zu `ignored_paths`, wo eine Vorgabe ausdrücklich
abgelehnt wird: Dort ginge Signal über die überwachte **Anwendung** verloren, hier fällt
Selbstbeobachtung weg. **Wer eigene Muster ergänzt, ersetzt die Vorgabe** — die Zeile
gehört dann mit in die eigene Liste.

Neue Schlüssel: `layers.kernel.console.enabled` (Vorgabe `true`) und
`layers.kernel.console.ignored_commands`. Bei `enabled: false` wird gar kein Listener
registriert, statt einen zur Laufzeit abzufragen — derselbe Grundsatz wie bei
`ids_sensor.enabled`. Ohne `symfony/console` fällt der Sensor lautlos aus; die Komponente
steht weiterhin nur unter `suggest`.

**Eine Grenze, die benannt gehört:** Alle Ereignisse eines Worker-Laufs teilen sich eine
Korrelationskennung, weil `console.command` je Prozess einmal feuert. Bei einem
stundenlangen `messenger:consume` ist das eine Spur je Prozess, keine je Nachricht — der
Vorbehalt, den `ConsoleCorrelation` schon für die Kennung festhält, gilt für die Ereignisse
genauso.

### Fixed — Zwei Verweise zeigten auf die falsche Kennung

Konzept 6 hat die offenen Betriebspunkte von `B*` auf `OB*` umbenannt, weil `B1`–`B10`
mit den Batch-Regeln aus 4.3.2 kollidierten. Die Kollision war nicht theoretisch — die
Umbenennung selbst begründet sich mit einem Verweis, der `B1` als Scanning-Regel las
statt als Teststrategie. Zwei Verweise blieben trotzdem in der alten Form stehen und
zeigten seitdem lautlos auf eine Regel:

| Stelle | vorher | gemeint |
|---|---|---|
| `doc/06-vertraulichkeit.md` | „Offener Punkt **B8**" | `OB8` — die Datenschutz-Entscheidung |
| `Sensor\Context\CorrelationIdFactory` (Docblock) | „offener Punkt **B6**" | `OB6` — die `correlation_id`-Erzeugung |

Beides sind Verweise, die sich nicht als Fehler bemerkbar machen: Sie lesen sich richtig
und führen zum falschen Absatz. `DocumentationTest::ABGESCHAFFT` bewacht die Form jetzt
über `doc/` und `src/` hinweg — der bestehende Mechanismus trug den Eintrag ohne neue
Testmethode. Ausgenommen bleibt das Konzept selbst: Es muss beide Kennungen nennen
dürfen, um die Umbenennung überhaupt begründen zu können.

### Fixed — Tiefenabgleich Konzept ↔ Quellcode ↔ Dokumentation

Ein vollständiger Abgleich der drei Parteien hat 16 Abweichungen in fünf Gruppen ergeben.
Das Muster ist eindeutig: **Was ein Test abdeckt, stimmt.** Konfigurationsschlüssel,
Vorgabewerte, Verweise, Anker und die Ereignis-Beispiele sind sauber — sie stehen unter
`DocumentationTest` und `ArchitectureTest`. Auseinander liefen genau die drei Flächen ohne
Test: die normative Antwortcode-Tabelle aus Konzept 3.6, die als „vollständig und
geschlossen" bezeichnete Zählerliste aus 3.4, und Fließtext samt Mermaid-Knoten.

**Der Versandpfad hielt Konzept 3.6 nicht ein — die einzige Verhaltenslücke.**
`HttpShipper` unterschied „geht nie" von „später erneut" korrekt und warf
`UnshippableFrameException`; `FrameDispatcher::ship()` fing darunter pauschal `\Throwable`
und warf die Unterscheidung wieder weg. Eine mit `400`, `403`, `413` oder `422` abgelehnte
Sendung wurde deshalb **gespoolt**, zählte `ship_failed` und öffnete den Circuit Breaker.
Drei Folgen auf einmal: Der Drainer schickte den Frame später an denselben Collector und
lief in denselben Fehler — genau das Head-of-Line-Blocking, gegen das es diese Ausnahme
laut eigenem Docblock gibt. Der Breaker öffnete nach drei Ablehnungen gegen einen völlig
gesunden Collector. Und `ship_failed` schickte den Betreiber zum Collector, während die
Ursache im Payload lag.

**`Retry-After` wurde nicht gelesen**, obwohl 3.6 es für `429` normativ vorschreibt. Neu ist
`Exception\ThrottledException`; sie trägt die Wartezeit, und der Breaker öffnet damit
**sofort** statt erst ab der Fehlerschwelle. Ohne das widersprächen sich die beiden Hälften
der `429`-Zeile: Unterhalb der Schwelle ginge der nächste Frame unmittelbar wieder hinaus,
und die Wartezeit wäre entgegengenommen und im selben Atemzug ignoriert. `BreakerState`
führt die vorgesehene Dauer jetzt mit — sonst verwürfe die Uhr-Rücksprung-Prüfung dort
jede Sperre oberhalb von `open_for_s` als unplausibel. Gekappt wird bei 15 Minuten: Der
Wert kommt von der Gegenseite und gilt nach 4.5.3 als angreiferkontrolliert.

**Die Zählerliste war an fünf Stellen uneins.** Sie speist `ids.event_loss`, weshalb 3.4 sie
ausdrücklich geschlossen nennt:

| Schlüssel | vorher | jetzt |
|---|---|---|
| `spooled_events` | hieß `spooled` | umbenannt |
| `dropped_rejected` | fehlte | neu |
| `dropped_spool_unwritable` | nur als `spool.discarded_unwritable` | bei den Zählern |
| `dropped_spool_unencodable` | nur als `spool.discarded_unencodable` | bei den Zählern |
| `dropped_reset` | fehlte im Konzept | in 3.4 aufgenommen |

Der `spool`-Block des Heartbeats meldet damit nur noch Bestandsgrößen, wie 3.4 es verlangt.
Die drei Verwerfungsgründe standen dort unter den Namen `discarded_*`, die derselbe
Abschnitt abgeschafft hatte („es gilt durchgehend die `dropped_*`-Schreibweise") — und weil
sie außerhalb von `counters` standen, fehlten sie dem Collector in `ids.event_loss`
vollständig.

**Nebenbefund derselben Stelle:** `FrameDispatcher::spool()` zählte **jedes** gescheiterte
`append()` als `dropped_spool_full`. Für zwei der drei Gründe war das falsch — ein nicht
beschreibbares Verzeichnis und ein nicht kodierbarer Payload wurden als „Platte voll"
gemeldet und schickten den Betreiber zu einer Maßnahme, die nichts bewirkt. Der Spool
unterschied sie längst.

**`Counters::all()` lieferte nur berührte Zähler.** 3.4 verlangt, dass jeder mitreist, auch
mit dem Wert `0`: Ein fehlender Schlüssel ist für den Collector sonst zweideutig — „nichts
verloren" oder „diese Sensorfassung kennt den Zähler nicht". Genau diese Unterscheidung
braucht er, wenn Sensoren verschiedener Fassungen gleichzeitig laufen.

**Das Konzept widersprach sich an sieben Stellen.** Die Ingest-Route stand dreimal in der
früheren Einzel-ID-Form `POST /api/v1/sensor/{sensor_id}`, während 3.6 das vollständige
Tripel festlegt; Frame- und Heartbeat-Beispiel zeigten `schema_version: 1`; der Kopfvermerk
sagte noch, der Quellcode liefere weiterhin den Redis-Streams-Transport aus. Dazu: 4.1
verlangte einen `sub`-Claim-Abgleich, den 3.6 nicht kennt (verbindlich sind `iat` und
`exp`, die Kontrolle ist die Eigentümerkette); `events_raw` hatte einmal 30 statt 45 Tage
Retention; `metric_baselines` galt einmal als überschrieben und einmal als fortgeschrieben;
B8 war einmal über `actor_session_hash` und einmal über `actor_user` definiert — was
verschiedene Regeln sind, weil ein Nutzer auf zwei Geräten zwei rechtmäßige Sitzungen haben
kann; und der `alerts`-Upsert nannte eine Spalte `environment` statt `environment_id`.

**Zwei Zeilen in Konzept 2.3 behaupteten eine Durchsetzung, die es nicht gibt.** „Spool-
Verzeichnis node-lokal" und „Umgebung im Anwendungsregister vorhanden" standen mit
`setup-check` in der Spalte; der Command prüft beides nicht und kann es auch nicht. Der
Abschnitt zieht diese Linie zwei Absätze später selbst — sie ist jetzt auf die eigene
Tabelle angewandt.

**Sampling und Broker lebten in der Prosa weiter.** Fünfmal „sampeln" (zwei Mermaid-Knoten,
eine Leitfrage, ein Docblock, `structure.md`), dazu Broker und Redis in `README.md`,
`structure.md` und `CLAUDE.md` — die drei Dateien, die der Nachzug in `263a830` nicht
erfasst hatte. `structure.md` führte außerdem `IdsEventData\Vocabulary\Environment`,
gelöscht seit `ids-event-data` 0.2.0; es ist derselbe Fehler, den `263a830` in `doc/03`
behoben hat, an der einen übersehenen Stelle. Das README-Diagramm modellierte weiterhin die
Broker-Topologie: ein Knoten außerhalb des Collectors, aus dem der Consumer *liest*.

**Kleineres:** `doc/06` führte sieben der neun mitgelieferten Header — ausgerechnet die
beiden `X-Debug-*` fehlten, die dieselbe Datei 110 Zeilen später erklärt. Die README nannte
15 statt 14 Konfigurationsvarianten, „five promises" statt sechs und `ext-json` ohne
`ext-mbstring`.

**Damit das nicht wiederkehrt**, zwei Ergänzungen in `DocumentationTest`, die neun der 16
Befunde gefangen hätten: `testNoRetiredConceptSurvivesInProse()` prüft Markdown **und**
`src/` gegen eine Liste abgeschaffter Begriffe, mit zeilengenauer Ausnahmeliste für die
Stellen, die bewusst mit dem früheren Entwurf vergleichen.
`testNoDocumentNamesADeletedEventFormatClass()` gleicht jeden genannten
`IdsEventData\*`-Namen gegen die Dateien im Paket ab — die Gegenrichtung zu
`ArchitectureTest::testDocblockReferencesDoNotDangle()`, die für das Fremdpaket blind ist.

**Der Fassungswechsel ist vollzogen: `schema_version` steht auf 3.** Konzept 3.7 sagt jetzt
ausgeschrieben, dass die Umbenennung eines Zählers ein Bump ist — die Regel fehlte, weil
eine Umbenennung durch beide Listen fiel: Sie ist Zugang *und* Wegfall, und der Vorgang
entfällt gerade nicht. `spooled` → `spooled_events` ist damit einer, und seit 3.4 verlangt,
dass jeder Zähler auch mit dem Wert `0` mitreist, ist jeder Zählerschlüssel ein Pflichtfeld
— für die galt die Regel immer schon.

**Abhängigkeit:** `projektmotor/ids-event-data` steigt auf `^0.3.1`. Dort ändert sich allein
`EventSchema::SCHEMA_VERSION`; die PHP-API des Pakets bleibt unberührt. Am Event und am
Frame ändert sich **kein Feld** — die Fassung steigt nur wegen des `counters`-Blocks.

**Für den Collector:** Die Auswertung von `ids.event_loss` muss um `dropped_rejected`,
`dropped_spool_unwritable` und `dropped_spool_unencodable` erweitert und von `spooled` auf
`spooled_events` umgestellt werden. Ein Collector der Fassung 2 läse `spooled` sonst
dauerhaft als `0`.

Der Container-Abdruck ändert sich dabei in 14 Dateien um **je eine Zeile** — den Parameter
`ids_sensor.schema_version`. Nichts an der Verdrahtung; genau das macht der Abdruck
sichtbar.

### Fixed — die Dokumentation beschreibt wieder, was ausgeliefert wird

Die Umstellung auf REST und `schema_version` 2 hat Quellcode und Teile der Doku nachgezogen,
`doc/02`, `doc/03` und `doc/09` aber gar nicht angefasst. Keine öffentliche API ändert sich;
korrigiert wird, was schlicht falsch dastand.

**Das Ereignisformat-Kapitel beschrieb Fassung 1.** `doc/03-ereignisformat.md` und die README
zeigten ein Ereignis mit `schema_version` (steht seit Fassung 2 im **Frame**), `instance_id`
(entfallen), `environment` als Enum (heute `environment_id` als UUID) und `application_id` als
freiem Namen. Wer dem Beispiel folgte, baute gegen ein Format, das der Collector mit `422`
abweist. Die Tabelle der geschlossenen Wertelisten führte zudem
`IdsEventData\Vocabulary\Environment` — **die Klasse ist seit `ids-event-data` 0.2.0
gelöscht**; an ihre Stelle tritt `Frame\DispatchPath`.

**Damit das nicht wiederkehrt:** `DocumentationTest::testTheEventExamplesMatchTheSchema()`
liest die JSON-Beispiele aus Doku und README und gleicht sie gegen `EventSchema` ab —
Pflichtfelder vorhanden, `actor` genau die vier Felder, kein Feld, das das Schema nicht kennt,
und kein `schema_version` im Ereignis. Die bisherigen Prüfungen deckten Konfigurationsschlüssel,
Verweise, Anker, Vorgabewerte und Mermaid ab, aber kein einziges Beispiel.

**`environment_map` und `environment_fallback` standen in der Konfigurationsreferenz**, obwohl
es sie im `ConfigurationTree` nicht mehr gibt. `doc/08-konfiguration.md` erklärt jetzt
stattdessen, warum der Sensor nichts mehr abbildet. Der vorhandene Test fand das nicht: Er
prüft Tabellenzellen, die Fundstellen lagen in einem YAML-Block und im Fließtext.

**Weitere Berichtigungen:** Die Heartbeat-Drosselung war mit `instance_id` erklärt, benutzt
aber `application_id` + `sensor_id` (`doc/07`). Die Fehlersuche-Tabelle nannte `NOPERM … XGROUP`
und `auto_setup` — Redis-Reste; an ihre Stelle treten Zeilen für `403` und `422`. Das
Übersichtsdiagramm in `doc/01` modellierte noch `sensor → broker → lesen → consumer` statt des
direkten `POST` auf den Ingest-Endpunkt. Und `FrameDispatcher::tooLarge()` begründete die
Größengrenze mit Redis' `proto-max-bulk-len` statt mit dem `413` des Collectors.

**Ein Wort je Konzept.** 84 Stellen in Quellcode, Konfiguration und Tests sagten „Broker" —
darunter zwei Meldungen des `setup-check`, die Fehlermeldung von `ids:sensor:heartbeat` und die
Beschreibung von `ids:sensor:spool:flush`, die in `bin/console list` erscheint. Sie sagen jetzt
„Collector". Stehen bleiben die beiden Stellen, die bewusst mit dem *früheren* Entwurf
vergleichen.

**Nebenbefund aus derselben Umstellung:** `symfony/http-client` stand in `require` **und**
`require-dev`. `composer validate --strict` bricht darauf ab — der Schritt läuft in CI, die
also seit `539a230` rot war. Der Eintrag in `require-dev` ist entfallen. Dazu entfernt:
`diag.php`, eine leere Datei, die versehentlich mitgecommittet wurde.

### Breaking — Sampling entfällt, der Sitzungshash kommt ohne Schlüssel aus

Die acht zurückgestellten Befunde der Tiefenprüfung sind eingearbeitet (Konzept 2.2.4, 2.3,
3.7, 4.2.1–4.2.4, 4.3, 4.3.5, 4.5.3, 6.2). Zwei davon lösen sich durch eine Entscheidung
auf statt durch eine Ergänzung — nur diese beiden berühren den Quellcode.

**Die Konfigurationsfläche schrumpft, und alle `ids_sensor`-Schlüssel gehören zur
öffentlichen API:**

| entfällt | Ersatz |
|---|---|
| `sampling.info_rate` | keiner — siehe unten |
| `sampling.keep_if_request_relevant` | keiner |
| `session_hash.key` | keiner; es gibt keinen Schlüssel mehr |
| `session_hash.min_key_length` | keiner |

Wer einen der vier Schlüssel gesetzt hat, bekommt beim Kompilieren eine Meldung über eine
unbekannte Option — kein stilles Ignorieren.

**Sampling ist vollständig entfernt (Befund S-1).** `Delivery\Dispatch\CoherentInfoSampler`,
der Zähler `dropped_sampling` und das Ereignisfeld `sampling_rate` fallen weg. Der Grund:
Eine Rate lässt sich hochrechnen, ein verpasster Signaturtreffer nicht. Bei R2b und X4 gibt
es keinen Schwellwert, den man hochrechnen könnte — und ausgerechnet dort liegt der Treffer
auf einem 200er, sodass der ganze Request nur aus `info` besteht und `keep_if_request_relevant`
nicht greift. Die **bestätigte Exposition**, der schwerwiegendste Befund des Konzepts, wäre
also genau der gewesen, den Sampling verschluckt. Der einzige Gegenschnitt, der ohne die
collectorseitige Pfad-Wissensbasis auskäme („nie sampeln, was keine aufgelöste Route trifft"),
hilft dort ebenfalls nicht: Ein erreichbarer `/_profiler` bedeutet, dass `WebProfilerBundle`
geladen ist — die Route löst auf.

Als Stellräder gegen Volumen bleiben `layers.security.access_decision`,
`layers.security.capture_granted` und `layers.kernel.ignored_paths`. Alle drei nehmen etwas
**Benanntes** weg; Sampling nahm einen zufälligen Anteil von allem weg.

**`actor.session_id_hash` ist jetzt ein ungeschlüsselter SHA-256 (Befund S-5).** Der
dedizierte HMAC-Schlüssel ist entfallen, weil beide Begründungen nicht trugen: „Sonst lässt
sich die ID zurückrechnen" gilt nur für schwache IDs (PHP erzeugt vorgabemäßig 130–160 Bit),
und „die Anwendung kennt `APP_SECRET`" traf den IDS-Schlüssel genauso — er steht in
derselben Konfiguration, sonst könnte der Sensor nicht hashen. Gegen einen Angreifer mit
Codeausführung wirkte er nie.

Damit fällt der **einzige Kompilierzeit-Abbruch** dieses Bundles weg: Es ist zur Laufzeit
durchgängig fail-open. An die Stelle der Schlüsselprüfung tritt in `ids:sensor:setup-check`
eine Prüfung der Session-ID-Entropie (`session.sid_length × session.sid_bits_per_character`,
Untergrenze 128 Bit) — ein Befund mit Rückgabewert 1. Und die Frage nach einem
Rotationsweg, die S-5 überhaupt aufwarf, entfällt mit dem Schlüssel.

> **Beim Update ändert sich jeder `actor_session_hash` einmalig.** B8 und B9 sehen für die
> Dauer der längsten laufenden Sitzung einen Sitzungswechsel, wo keiner ist. Das ist
> derselbe Effekt, den eine Schlüsselrotation gehabt hätte — nur einmal statt bei jeder
> Rotation.

**`capture_granted` war falsch dokumentiert.** `doc/04`, `doc/08` und der Konfigurationsbaum
sagten, `false` koste die Positivpfad-Regeln. Das stimmt nicht: P1/P2 lesen `kernel.response`
mit 200, P3 Business-Events — keine liest die Voter-Entscheidung. Es kostet keine heutige
Regel, aber die Historie für den neuen offenen Punkt E6 (Befund S-2).

**Abhängigkeit:** `projektmotor/ids-event-data` steigt auf `^0.3.0` — dort entfallen
`EventSchema::FIELD_SAMPLING_RATE`, `NormalizedEvent::$samplingRate` und
`withSamplingRate()`. **`schema_version` bleibt bei 2:** Ein optionales Feld zu entfernen,
dessen Fehlen bereits „nicht gesampelt, Faktor 1" bedeutete, ist nach den Bump-Regeln
additiv — die Regel ist in Konzept 3.7 jetzt ausgeschrieben.

**Nur im Konzept, ohne Codeanteil:** `granted`-Entscheidungen bekommen ihren benannten
Zustand samt offenem Punkt E6 (S-2); gefälschte Events gegen Dritte sind als verbindliche
Randbedingung von E5 geführt (S-3); die Anomalieschwelle bekommt Mindestfallzahl,
Streuungsuntergrenze und Median/MAD als Alternative, und `metric_baselines.sample_count`
bekommt endlich einen Zweck (S-4); der GIN-Index auf `payload` weicht Ausdrucksindizes, weil
`jsonb_ops` `->>`-Vergleiche nachweislich nicht bedient (P-1); `events_info` bekommt einen
eigenen, kleineren Indexsatz mit benanntem Leser je Index (P-2); `realtime_counters` bekommt
`fillfactor = 70` und ein eigenes Autovacuum, weil beim Fensterwechsel kein HOT-Update mehr
möglich ist (P-4).

### Breaking — Umsetzung: REST-Transport, drei UUID-Kennungen, schema_version 2

Der Quellcode zieht das Konzept nach. Die beiden vorherigen Einträge beschreiben die
Entscheidungen; hier steht, was sich am Bundle ändert.

**Die Konfigurationsfläche ändert sich, und alle `ids_sensor`-Schlüssel gehören zur
öffentlichen API.** Ein Update ist ohne Anpassung nicht möglich:

| entfällt | ersetzt durch |
|---|---|
| `instance_id` | `sensor_id` — UUID, **je Node verschieden** |
| `environment`, `environment_map`, `environment_fallback` | `environment_id` — UUID |
| `application_id` als freier Name | `application_id` als UUID |
| `transport.dsn`, `.name`, `.register_transport`, `.options` | `collector.base_uri`, `.username`, `.password`, `.token_leeway_s`, `.verify_tls` |

Alle drei Kennungen vergibt der Collector beim Registrieren. Der Sensor leitet nichts mehr
ab — weder die Instanz aus dem Hostnamen noch die Umgebung über eine Zuordnungstabelle.
Damit entfallen zwei Fehlerquellen, die beide lautlos waren: ein beim Image-Bau
eingebackener Hostname, der in jedem Replikat derselbe ist, und eine nicht abbildbare
Umgebung, die über den Vorgabewert `prod` in der falschen Auswertung landete.

**`sensor_id` darf nicht aus einer geteilten Konfiguration kommen.** In Kubernetes teilen
sich alle Replikate eines Deployments eine ConfigMap; läge die Kennung dort, wären die
Replikate ununterscheidbar, und `ids.sensor_silent` schwiege beim Ausfall einzelner.

**Neu im Transport:** `Delivery\Transport\Http\HttpShipper`, `TokenProvider` und
`TokenStore`. Der Token-Cache liegt prozessübergreifend (APCu, sonst Datei) — ohne ihn
holte sich jedes PHP-FPM-Kind sein eigenes Token. Erneuert wird vorausschauend mit Vorlauf,
nicht erst auf ein `401`: Das wäre ein zweiter Roundtrip innerhalb des Versandbudgets.

**Entfallen:** `MessengerShipper`, `MessageSerializer`, `Transport\Message\*`,
`LazyTransportPass`, `Support\Identity\InstanceIdProvider` und `EnvironmentResolver`.

**Antwortcodes statt Broker-Fehler.** `UnshippableFrameException` kannte bisher nur
Kodierfehler, weil `XADD` keine Ablehnung kennt. Jetzt trennt sie „geht nie" von „später
erneut": `400`, `403`, `413` und `422` verwerfen und zählen; `429`, `5xx`, Timeout und
Verbindungsfehler spoolen und zählen einen Breaker-Fehler.

**Abhängigkeiten:** `symfony/http-client` kommt hinzu. `symfony/messenger` wird von einer
harten zu einer **optionalen** Abhängigkeit — vorhanden hängt sich der Sensor weiter an
`WorkerMessageHandledEvent` und `WorkerMessageFailedEvent`, fehlt es, entfallen diese
beiden Flush-Punkte und sonst nichts. `ext-redis` und `symfony/redis-messenger` entfallen,
ebenso der Redis-Dienst aus `docker-compose.yml`, die ACL unter `docker/redis/` und das
Make-Ziel `test-redis`.

**`ids:sensor:setup-check` prüft neu:** fehlende Zugangsdaten, eine `base_uri` ohne HTTPS
und ein abgeschaltetes `verify_tls` (Konzept 4.5.3). Die Prüfung auf den eigenen Hostnamen
ist entfallen; an ihre Stelle tritt ein Hinweis zur `sensor_id` je Node — dass Replikate
sie teilen, ist von einem einzelnen Prozess aus nicht feststellbar, und ein Hinweis ist
ehrlicher als eine Prüfung, die es nicht gibt.

**Noch nicht umgesetzt: der gebündelte Versandmodus.** `flush.policy: spool` funktioniert,
aber der Drain-Lauf sendet weiterhin einen POST je Frame. Die Bündelung mehrerer Frames in
eine Sendung hängt an einer Festlegung, die das Konzept ausdrücklich offenlässt (OB13): Was
gilt, wenn eine Sammelsendung teilweise scheitert? Solange das offen ist, wären
`spool.max_post_frames` und `max_post_bytes` Optionen, die etwas versprechen und nichts
bewirken.

### Changed — Konzept: Ergebnisse der Tiefenprüfung

**Wieder nur `doc/concept/`. Am Quellcode wurde keine Zeile angefasst**, und `doc/01`–`doc/09`
beschreiben weiterhin korrekt den ausgelieferten Stand.

Eine systematische Prüfung des Konzepts auf Widersprüche, Lücken und technologische Fehler.
Vier Befunde waren kritisch:

- **Die Zeitachse war angreifbar.** Jede Zeitfensterregel filterte auf `timestamp`, den der
  Sensor setzt — also ein Prozess, der laut Abschnitt 2 als kompromittierbar gilt. Sechs
  Minuten Zurückdatieren genügten, um aus **jedem** Fenster von B1–B9 und X1–X4 zu fallen.
  Neu ist `effective_at`: Der Collector klemmt `timestamp` in ein an `received_at`
  verankertes Fenster, statt es zu ersetzen — ein bloßer Feldtausch hätte den Fehler nur
  umgedreht und Nachläufe als „gerade eben" gewertet.
- **Autorisierung und Datenpartitionierung hingen nicht zusammen.** Das Token band eine
  `sensor_id`, gespeichert wurde nach `application_id`. Neu tragen die Routen das vollständige
  Tripel als UUID, und die Kontrolle ist ein Satz: Der angemeldete Nutzer muss Eigentümer der
  Kette Anwendung → Umgebung → Sensor sein.
- **`actor.ip` ohne `trusted_proxies`** ist hinter jedem Reverse Proxy für alle Events
  dieselbe Adresse. Sieben Regeln liefen damit ins Leere, ohne Fehlermeldung — jetzt als
  Betriebsvoraussetzung geführt.
- **„Sensor" bedeutete dreierlei.** `instance_id` entfällt zugunsten von `sensor_id`: Ein
  Sensor **ist** eine laufende Installation, je Node wird eine registriert. Die drei
  Erfassungsbausteine heißen nicht mehr so.

Dazu elf Widersprüche und vierzehn Lücken, darunter: `occurrence_count` zählte Cooldown-Fenster
statt Vorkommen; der Detection Job durfte `metric_baselines` nicht schreiben, obwohl er sie
füllt; `LIKE … INCLUDING ALL` stand vor den `CREATE INDEX`, `events_info` hätte keinen
einzigen bekommen; die Bump-Regeln, auf die 3.4 seit jeher verweist, gab es nicht (jetzt 3.7);
`sampling_rate`, `dispatch_path` und `spool_delay_ms` hatten keine Spalte; für die
Stundenaggregate fehlte die Tabelle (jetzt `metric_samples`).

**Neu im Drahtformat:** Die Routen tragen `application_id`/`environment_id`/`sensor_id`, alle
als UUID; maßgeblich ist der Rumpf, der Pfad wird dagegen geprüft. Der Heartbeat bekommt einen
eigenen Endpunkt, womit alle `X-Ids-*`-Header entfallen — die Route trägt die Nachrichtenart.
`schema_version` steht nur noch im Frame (`v` entfällt), Events tragen keine eigene Fassung.
Umgebungen werden collectorseitig frei benannt; `env_type`, `environment_map` und
`environment_fallback` entfallen ersatzlos.

**Das ist ein `schema_version`-Bump und reicht damit in
[`projektmotor/ids-event-data`](https://github.com/projektmotor/ids-event-data) hinein** — die
erste Konzeptänderung, die das tut. Vorher unterschätzt: Frei benannte Umgebungen und „Rumpf
gewinnt" zusammen erzwingen, dass der Rumpf `environment_id` statt des Namens trägt, sonst
legte jede Umbenennung im Collector alle Sensoren mit `422` lahm.

**Ausdrücklich nicht in dieser Runde** und für einen zweiten Durchgang vorgemerkt: die Befunde
zu Sampling gegen Signaturerkennung, `granted`-Entscheidungen ohne Leser, Anomalieschwellen bei
kleinen Zahlen, Schlüsselrotation sowie die Indexfragen (der GIN-Index auf `payload` bedient
`->>`-Abfragen nachweislich nicht).

**Vormerkung für die Umsetzung:** Zu den bereits vorgemerkten Transportschlüsseln kommt, dass
`application_id` den Typ wechselt und `environment_id` sowie `sensor_id` hinzukommen.

### Changed — Konzeptentscheidung: Transport auf REST am Collector, Redis entfällt vollständig

**Nur `doc/concept/concept-v1.md` und die zugehörige Grafik sind geändert. Am Quellcode
wurde keine Zeile angefasst.** Das Bundle liefert weiterhin den Redis-Streams-Transport
aus, und die Dokumentationsreihe `doc/01`–`doc/09` sowie die README beschreiben diesen
ausgelieferten Stand korrekt. Konzept und Auslieferung laufen bis zur Umsetzung bewusst
auseinander; `doc/README.md` sagt das jetzt an der Stelle, an der es geregelt ist.

Der Sensor sendet künftig per HTTPS an `POST /api/v1/sensor/{sensor_id}`, angemeldet über
vom Collector ausgegebene Zugangsdaten (`sensor_id`, Benutzername, Passwort) und ein daraus
geholtes, prozessübergreifend gecachtes JWT. Neuer Abschnitt 3.6 im Konzept legt Endpunkt,
Umschlag, Anmeldung, Antwortcodes und die beiden Versandmodelle fest.

**Der Grund ist der Betriebsweg, nicht die Technik.** Ein Message Broker verlangt vom
Betreiber der überwachten Anwendung einen Netzwerkpfad zu fremder Infrastruktur und eine
Broker-ACL, die getrennt vom Anwendungsregister gepflegt wird — beides in fremden
Rechenzentren jedes Mal neu zu verhandeln. Ein HTTPS-Endpunkt geht überall durch, und die
Zugangsdaten entstehen dort, wo eine Application ohnehin angelegt wird.

**Redis fällt in beiden Rollen weg.** Es war Transport *und* In-Memory-Zählerspeicher der
Echtzeitregeln R2b/R3/R4 und der Cooldowns aus 4.4. Die Zähler liegen jetzt in einer
`UNLOGGED`-Tabelle `realtime_counters` in der ohnehin vorhandenen PostgreSQL-Datenbank —
kein WAL, dieselbe Haltbarkeit wie zuvor, ein `INSERT … ON CONFLICT … RETURNING` auf eine
Zeile. Die Begründung der Zweiteilung in Echtzeit- und Batch-Schicht bleibt bestehen, aber
sie lautet nicht mehr „Redis statt PostgreSQL", sondern „ein Indexzugriff statt einer
Aggregation über Millionen Zeilen". **Ergebnis: Das System besteht aus zwei Bausteinen
statt vier** — der überwachten Anwendung und dem Collector.

Was sich am Sicherheitsargument verschiebt, steht im Konzept ausgeschrieben und ist hier
nur benannt: Die Manipulationsgrenze verläuft am Ingest-Endpunkt statt am Broker und wird
dabei **schärfer** (kein gemeinsamer Stream, nur `POST` auf den eigenen Pfad, `sub`-Claim
gegen Pfad geprüft). Dagegen steht, dass der Endpunkt öffentlich erreichbar ist, ein Broker
im eigenen Netz nicht war — Gegenmittel sind Ratengrenze je `sensor_id` und sperrbare
Zugangsdaten. Zwei Argumente, die an der Redis-ACL hingen, sind neu begründet (4.2.3) oder
als offener Punkt vermerkt (OB11).

**Das Drahtformat ändert sich nicht.** Frame, Event und Heartbeat aus Abschnitt 3 bleiben
Feld für Feld gleich, deshalb kein `schema_version`-Bump und keine Änderung an
`projektmotor/ids-event-data`. Neu ist allein der Verlustzähler `dropped_rejected` für vom
Collector abgelehnte Sendungen — additiv und nach den Bump-Regeln unkritisch.

**Vormerkung für die Umsetzung: Sie wird ein Breaking Change.** Alle `ids_sensor`-Schlüssel
gehören laut `doc/08-konfiguration.md` zur öffentlichen API unter Semver, und
`transport.dsn`, `transport.name`, `transport.register_transport` sowie `transport.options`
entfallen dann ersatzlos. An ihre Stelle treten Collector-URL, `sensor_id`, Zugangsdaten und
der Token-Vorlauf. `symfony/messenger` verliert seinen einzigen Zweck im Sendepfad,
`ext-redis` und `symfony/redis-messenger` entfallen aus `require-dev` und `suggest`, und
`symfony/http-client` kommt neu hinzu.

Bis dahin gilt: Wer den Transport anfasst, liest **erst** 3.6 im Konzept. Diese Entscheidung
stand bisher nur in einem einzigen Satz des Konzepts und hatte keine Spur im Changelog.

---

Ergebnis eines zweiten Tiefenchecks, diesmal gegen `doc/concept/concept-v1.md`. Drei der
Befunde sind stille Erkennungsausfälle, und zwei davon standen im Changelog zu 0.1.1
bereits als erledigt — siehe „Berichtigt" am Ende dieses Abschnitts. Jede
Verhaltensänderung trägt einen Test, der ohne sie fehlschlägt.

### Fixed — die CI lief seit ihrem ersten Durchlauf rot

Kein Lauf des Workflows war je grün. Drei Ursachen, alle in der Testumgebung und keine im
ausgelieferten Code — die statische Analyse war durchgehend grün:

- **`memory_limit`.** `shivammathur/setup-php` legt `php.ini-production` zugrunde, also
  128 MB. Ein Integrationslauf steht am Ende bei rund 150 MB und wurde deshalb mitten aus
  der Suite mit einem Fatal Error abgeschnitten — das sah nach einem Testfehler aus, war
  aber ein abgebrochener Prozess. Der Workflow zieht den Wert jetzt mit
  `docker/php/php.ini` gleich (512 MB); im Entwickler-Container stand er immer schon dort,
  weshalb lokal nichts auffiel.
- **Die Container-Abdrücke banden an ein Arbeitsverzeichnis.** `ids_sensor.spool.dir` ist
  aus `kernel.project_dir` zusammengesetzt, und der Pfad landete wörtlich in allen 15
  Referenzdateien — als `/app/...`, dem Pfad im Entwickler-Container. Auf dem Runner liegt
  das Repository unter `/home/runner/work/...`, also schlugen alle 15 Varianten fehl, mit
  einem Unterschied, der wie eine geänderte Verdrahtung aussieht. `ContainerFingerprintPass`
  maskiert das Projektverzeichnis jetzt als `<project_dir>`. Das traf nicht nur die CI:
  jeder Mitwirkende außerhalb von `/app` sah dieselben 15 Fehlschläge.
- **Der Budget-Test war ein Wettlauf gegen die Uhr.**
  `FlushListenerTest::testTheHeartbeatIsSkippedWhenTheDispatchBudgetIsSpent()` verließ sich
  darauf, dass Rechenarbeit im Raw-Builder ein Budget von 1 ms überschreitet. Gemessen
  kostete diese Arbeit 0,4 bis 1,05 ms — der Ausgang hing an der Maschine. Auf dem Runner,
  rund dreimal schneller als die Entwicklungsumgebung, kippte der Test von Lauf zu Lauf,
  ohne dass sich am geprüften Code etwas geändert hätte. Die Dauer kommt jetzt aus einer
  festen Wartezeit von 5 ms; ein Ereignis genügt dafür statt der bisherigen 200, von denen
  `EventBuffer::maxEvents` ohnehin nur 64 durchließ. Nebenwirkung: die Suite läuft lokal
  etwa doppelt so schnell.
- **Der ACL-Test verband gegen einen fest verdrahteten Hostnamen.**
  `RedisStreamTest::testTheSensorUserMayNeitherReadNorDelete()` rief `connect('redis', …)`
  auf — den Namen des Compose-Dienstes. Die `setUp()` desselben Tests leitet Host und Port
  längst aus der DSN ab; diese eine Stelle tat es nicht und scheiterte auf dem Runner, wo
  der Broker unter `127.0.0.1` läuft, mit `getaddrinfo for redis failed`. Die Auflösung
  liegt jetzt in `hostAndPortOf()` und wird von beiden Verbindungen benutzt.
- **Die Prüfung des Dist-Archivs führte `doc/` als unerwünscht.** Sie stammt aus der Zeit,
  als `/doc` auf `export-ignore` stand. Seit der Wiederaufnahme — die README verweist
  elfmal dorthin, siehe `.gitattributes` — widersprach der Workflow einer Zusage, die
  `DocumentationTest::testNoLinkedDirectoryIsExcludedFromTheDistArchive()` aktiv
  durchsetzt. Der Job scheiterte damit an der richtigen Auslieferung.

### Changed — Konzept und Dokumentationsreihe gegeneinander abgeglichen

Ein Abgleich von `doc/concept/concept-v1.md`, der Reihe `doc/01`–`doc/09` und dem Quellcode hat 22
Abweichungen ergeben. Drei davon sind oben als Code-Änderungen aufgeführt; die übrigen
betreffen die Dokumente. Die wichtigsten:

- **`doc/04` beschrieb `budget.max_events_per_process`** als gültige Option. Der Schlüssel
  ist entfallen und lässt den Container heute scheitern — wer der Dokumentation folgte,
  bekam kein wirkungsloses Setting, sondern eine Anwendung, die nicht bootet. Der Abschnitt
  nennt jetzt stattdessen die Pflicht-Event-Reserve und erklärt, warum es keine
  Prozessgrenze gibt.
- **Die Security-Event-Namen in `doc/02` und `doc/04`** waren Konstantennamen
  (`security.auth_success`) statt der übertragenen Werte
  (`security.authentication.success`). Diese Zeichenketten sind Paketgrenze; ein Filter
  nach der alten Angabe trifft nichts, und zwar lautlos.
- **Der V-Katalog in `doc/02`** stimmte weder in Nummerierung noch in Inhalt mit Konzept
  2.1.3 überein: V1 und V4 waren vertauscht, V2 (Kontoübernahme) fehlte vollständig, und
  V6 führte den User-Switch als Katalogeintrag, obwohl das im Konzept ein offener Punkt
  ist. Die Nummern sind Querverweisanker aus 4.3.6.
- **Die `raw`-Zusage „für alle Events, die einen Alert ausgelöst haben"** ist im Konzept
  gestrichen. Der Sensor kann sie nicht erfüllen — der Alert entsteht erst im Collector,
  und die Rechtetrennung schließt aus, dass der Sensor davon erfährt. Die Folge steht als
  offener Punkt OB11.
- **Die offenen Punkte im Konzept tragen jetzt das Präfix `OB`.** `B1`–`B10` kollidierten
  mit den Batch-Regeln aus 4.3.2/4.3.3, und 6.2 verwies für O3 bereits auf die falsche.
- **Sechs Bausteine der Umsetzung sind ins Konzept nachgezogen:** Erfassungsbudget und
  Circuit Breaker (2.1), Sub-Requests, fatale Fehler und `ignored_paths` (2.1.1),
  `environment_map`/`environment_fallback` (2.2.1).
- **`IdsResourceIdentifier`** — bisher öffentliche API ohne Nutzerdokumentation — ist in
  `doc/02` erklärt, samt der Verbindung zu Regel B7/P1/P2 und dem offenen Punkt O2.
- **`--strict`** und die sechs bis dahin nicht aufgeführten Verlustzähler stehen in
  `doc/07`; die Zählertabelle war die einzige Stelle, die dem Betreiber sagt, was ein
  Zählerstand im Heartbeat bedeutet, und las sich als vollständig.

Dazu kleinere Berichtigungen: der Kürzungsvermerk heißt `__truncated` und nicht
`_ids_truncated` (`doc/09`), die `raw`-Tabelle in `doc/03` führt die Business-Zeile, die
Redaktionsliste in Konzept 4.5.1 steht auf `version: 2`, der `alerts`-Index liegt auf
`first_seen` statt auf einer Spalte `created_at`, die es nie gab, und Verweise auf einen
„Abschnitt 5" zeigen auf 4.3.6.

### Fixed — Events ohne Request tragen jetzt eine eigene `correlation_id`

Konzept Abschnitt 3 führt `correlation_id` als Pflichtfeld, 4.2.1 als `TEXT NOT NULL`.
Außerhalb eines Requests gab es dafür keinen Wert: der Sensor setzte den Leerstring. Der
Constraint hielt damit, die Semantik nicht — **alle** Events aller Console-Läufe und aller
Worker trugen dieselbe Kennung, und der `correlation_id`-Self-Join aus Konzept 3.2, im
Collector über `idx_evr_correlation_id` (4.2.2) indiziert, führte sie zu einer einzigen
„Anfrage" zusammen, die mit jedem Lauf weiter wuchs.

Ein Console-Lauf ist die Entsprechung zum Request: ein abgeschlossener Durchlauf mit einem
Anfang. `Sensor\Context\ConsoleCorrelationListener` erzeugt an `console.command` eine
UUIDv7 — dieselbe Form wie im Request-Pfad —, `Sensor\Context\ConsoleCorrelation` hält sie
für den Lauf, und `CapturedEventBinder` setzt sie an jedes Event ohne Request. Ein
verschachtelter Command behält die Kennung des äußeren; eine zweite risse die Events eines
Durchlaufs auseinander.

**Benannte Grenze:** `messenger:consume` ist ein Command. Ein Worker, der Stunden läuft,
bündelt damit alle seine Events unter einer Kennung. Gegenüber dem Leerstring ist das ein
Gewinn — die Spur endet am Prozess statt an der Installation —, aber es ist keine Kennung
je Nachricht. Konzept 2.2.4 führt das ausdrücklich als solche Grenze.

Der Leerstring bleibt für Prozesse, die weder Request noch Command sind, und bedeutet dort
„kein zuordenbarer Durchlauf".

### Fixed — `setup-check` meldet die abgeschaltete Business-Ebene

`doc/02` und `doc/04` sagen beide zu: „`ids:sensor:setup-check` meldet eine abgeschaltete
Ebene als Befund." Für `layers.business.enabled: false` stimmte das nicht — es gab weder
Befund noch Hinweis auf den Schalter. Das traf ausgerechnet die Ebene, deren Ausfall
`doc/02` selbst als die wichtigste Aussage der Dokumentation bezeichnet, und der
Deploy-Check schwieg dazu.

Zu unterscheiden sind zwei Dinge, die vorher zusammenfielen: dass die Business-Ebene ohne
Anwendungscode wirkungslos ist, ist die im Konzept 2. beschriebene **Asymmetrie** — kein
Fehler, deshalb weiterhin ein unbedingter Hinweis. Dass jemand die Ebene **abgeschaltet**
hat, ist ein Befund wie bei Kernel und Security.

### Fixed — `drain_interval_s` nannte sich selbst wirkungslos

Die `->info()` im Konfigurationsbaum sagte „Nur Dokumentationswert: reist im Heartbeat
mit". Der Wert ist an vier Stellen wirksam verdrahtet: er versiegelt die aktive
Spool-Datei (`FileSpool::$sealAfterSeconds`), lässt ruhende Dateien fremder Prozesse
adoptieren (`SpoolDrainer::$sealIdleAfterSeconds`), ist im `setup-check` die Schwelle für
„Spool zu alt" — und reist außerdem im Heartbeat mit. `doc/08` beschrieb ihn immer schon
korrekt als „den Takt"; falsch war allein die Stelle, an der Betreiber nachschlagen:
`config:dump-reference ids_sensor`.

### Fixed — der Test für die beschädigte Breaker-Zustandsdatei übersprang sich immer

`SharedStateStoreTest::testACorruptStateFileReadsAsClosed` begann mit
`markTestSkipped('Mit aktivem APCu wird die Datei gar nicht gelesen.')`. Die Testumgebung
aktiviert APCu in der CLI ausdrücklich (`.github/workflows/ci.yml`), also übersprang sich
der Test in **jedem** Lauf — der Dateirückfall wurde nie gegen eine beschädigte Datei
geprüft.

Es ist dieselbe Lücke, vor der zwei Methoden weiter oben wörtlich gewarnt wird: „Ein Test,
der sich hier überspringt, prüfte genau in der Konstellation nichts, in der der Fehler
steckte." Und sie ist nicht theoretisch: Der Rückfall ist der Pfad, den jede Installation
ohne APCu dauerhaft benutzt, und eine halb geschriebene `breaker.state` ist nach einem
abgebrochenen Deploy oder auf voller Platte der Normalfall. Läse sie als „offen", spoolte
der Sensor durchgehend, obwohl der Broker längst wieder läuft.

Geprüft wird jetzt im Unterprozess mit `-d apc.enable_cli=0`, wie bei den beiden
Nachbartests — mit einer vorgeschalteten Gegenprobe auf eine GÜLTIGE Datei, damit „closed"
nicht auch dann grün ist, wenn der Unterprozess die Datei gar nicht anfasst. Der Testlauf
hat damit keine übersprungenen Tests mehr.

### Added — der JSON-Anfragekörper kommt in `raw` mit (Konzeptwiderspruch aufgelöst)

Zwei Festlegungen des Konzepts waren nicht gleichzeitig erfüllbar. Abschnitt 3.5 sagte
„gelesen wird ausschließlich, was das Framework bereits geparst hat; der rohe
Eingabestrom wird nicht angefasst" — Szenario S5 sagte für denselben Beleg zu, „der
ursprüngliche Payload ist für die forensische Nachanalyse vollständig verfügbar".

Symfony parst nur **formularkodierte** Körper in `$request->request`. Ein JSON-Körper
landet dort nie. Für jede API-Anfrage blieb `raw.request_params` also leer — und
Deserialisierungs-Angriffe, um die es in S5 geht, kommen über JSON-APIs. Die Zusage war
dort nicht ungenau, sondern unerfüllt, und zwar unbemerkt: Ein leeres Feld sieht aus wie
„die Anfrage hatte keinen Körper".

**3.5 ist präzisiert statt zurückgenommen.** Der Satz schützte vor zwei Schäden, und
beide hängen an Bedingungen, nicht am Vorgang — die Nutzlast wegzulesen, die die
Anwendung noch braucht, und unbegrenzt viel zu lesen. Gelesen wird deshalb nur, wenn der
Körper als JSON deklariert ist, seine Länge bekannt ist und unter
`raw.max_request_body_bytes` liegt. Das geschieht in der `raw`-Closure, also **nach** dem
Absenden der Antwort und nur für `warning`/`critical`: Der `info`-Pfad, die Masse aller
Events, zahlt dafür nichts.

Der dekodierte Körper steht als `raw.request_body` und läuft durch **dieselbe** Denylist
wie Formularfelder und Business-Payload — der sechste Eintrittspunkt derselben Liste.
Getrennt von `request_params`, damit die Herkunft ablesbar bleibt: dort steht, was das
Framework gelesen hat, hier das, was der Sensor selbst gelesen hat.

Jede Ablehnung nennt ihren Grund in `raw.request_body_omitted` — `disabled`, `multipart`,
`not_json`, `unknown_length`, `too_large`, `undecodable`, `unreadable`. Ohne den Vermerk
wäre „wir haben weggesehen" von „es gab nichts" nicht zu unterscheiden, und genau das war
der Zustand vorher. Ein Formular erzeugt **keinen** Vermerk: Dort ist nichts ausgelassen.

Ein nicht dekodierbarer Körper geht ausdrücklich **nicht** als Text mit. Die Redaktion aus
4.5.1 greift über Feldnamen; ohne Struktur gibt es keine, und ein roher Textkörper wäre
der eine Eintrittspunkt, an dem die Liste nichts ausrichtet. XML und PHP-serialisierte
Körper bleiben aus demselben Grund draußen — eine Grammatik je Format wäre eine zweite
Redaktionsimplementierung.

Neue Option `raw.max_request_body_bytes` (Vorgabe `32768`), geprüft am `Content-Length`
**vor** dem Lesen. Sie greift mit `raw.max_bytes` ineinander: Bei gleichen Vorgaben
überleben Körper bis etwa 28 KiB, darüber verwirft die Kappung sie wieder. Ist die
Körpergrenze **größer** als das raw-Budget, ist jeder Körper an der Grenze garantiert
verloren — das meldet `ids:sensor:setup-check` als Hinweis.

`doc/concept/concept-v1.md` trägt die Änderung als datierten Eintrag im Kopf; sie ist die erste,
die inhaltlich etwas verschiebt und nicht nur Wörter angleicht.

### Fixed — `actor.session_id_hash` blieb bei eigenem Session-Cookie-Namen immer `null`

`Sensor\Context\SessionIdHasher` ermittelte den Namen des Session-Cookies über
`ini_get('session.name')`. Der Konfigurationsbaum und `doc/08:81` sagen aber „`null`
ermittelt ihn aus der **Framework-Konfiguration**", und das ist etwas anderes: Symfony
schreibt `framework.session.name` erst dann nach php.ini, wenn `NativeSessionStorage`
konstruiert wird — ein lazy Dienst, der erst beim ersten `$request->getSession()`
entsteht. Der `RequestSensor` läuft bei Priorität 1024, der `SessionListener` bei 128.
Zum Erfassungszeitpunkt stand dort praktisch immer noch `PHPSESSID`.

Jede Anwendung mit eigenem Session-Namen lieferte damit `actor.session_id_hash: null`
in **jedem** Event. Die sitzungsbezogenen Regeln B8/B9 (Konzept 4.3.3) konnten nicht
feuern, und Szenario S9 — Session-Fixation und Remember-Me-Missbrauch — war unerkennbar.
Der Wert war nicht einmal stabil: Wurde die Session irgendwo im Request doch
materialisiert, trug derselbe Request `null` im `kernel.request` und einen Hash im
`kernel.response`.

Der Name wird jetzt in `prependExtension()` aus `framework.session.name` gelesen —
derselbe Weg, den `transport.dsn` und die Broker-Timeouts schon gehen, weil der
ContainerBuilder in `loadExtension()` keine Extension-Konfigurationen trägt.
`ini_get()` bleibt der letzte Rückfall.

### Fixed — der HMAC-Schlüssel wurde im dokumentierten Standardfall nie geprüft

`IdsSensorBundle::assertSessionHashKeyIsUsable()` prüft Länge und Gleichheit mit
`APP_SECRET` beim Kompilieren. Beide Prüfungen können einen Schlüssel hinter einer
Umgebungsvariable nicht bewerten — dort steht zu diesem Zeitpunkt ein Platzhalter, nicht
der Wert. Die Kommentare verwiesen dafür ausdrücklich auf `ids:sensor:setup-check`;
eingelöst war das nicht, geprüft wurde dort ausschließlich `session_hash.enabled`.

Das traf genau den empfohlenen Weg: Die Fehlermeldung des Bundles und `doc/08:25`
schlagen `key: '%env(IDS_SESSION_HASH_KEY)%'` vor. Wer der Empfehlung folgte, hatte
**weder** die Längen- **noch** die APP_SECRET-Prüfung — `IDS_SESSION_HASH_KEY=geheim`
lief durch, und der HMAC aus Konzept 2.2.4 war entsprechend schwach. Der bestehende
Test `testATooShortSessionHashKeyIsRejected()` lief daran vorbei, weil er einen
literalen Wert benutzt.

`setup-check` prüft jetzt den aufgelösten Schlüssel: zu kurz oder identisch mit
`APP_SECRET` ist ein Befund mit Rückgabewert 1. Ein leerer Wert zur Laufzeit ebenfalls —
die Umgebungsvariable kann fehlen, ohne dass beim Kompilieren etwas auffiel.

### Fixed — `layers.kernel.capture_fatal_errors` bewirkte nichts

`loadExtension()` setzte den Parameter, und niemand las ihn: `services_kernel.yaml`
registrierte den `FatalErrorFlushListener` bedingungslos. `capture_fatal_errors: false`
war wirkungslos, während `doc/08:131` der Option eine Wirkung zuschrieb.

Der Listener steht jetzt in `config/services_kernel_fatal_errors.yaml` und wird nur bei
`true` importiert. Ein Schalter, der den Dienst stehen ließe und ihn zur Laufzeit
abfragte, wäre keiner — die Shutdown-Funktion würde trotzdem registriert.

### Fixed — Zugangsdaten in einer URL ohne Query blieben stehen

`Cleaner::cleanUrl()` sagt zu, `https://nutzer:geheim@host/` um die Zugangsdaten zu
kürzen. Der Neuaufbau, der das tut, wurde aber nur erreicht, **wenn die URL eine Query
hatte** — ohne sie kam die Zeichenkette unverändert zurück, samt `nutzer:geheim@`.

Betroffen war `payload.referer`, das laut Konzept 3.1.1 bei **jeder** Stufe mitreist und
nicht nur bei `warning`/`critical` wie `raw`, sowie die Header `referer`, `location` und
`content-location` in `raw.request_headers` und `raw.response_headers`. Neu aufgebaut
wird jetzt, sobald **entweder** Zugangsdaten **oder** eine Query vorhanden sind.

### Fixed — unter mod_php wartete ein Frame bis zu 300 s auf seine Versiegelung

`SpoolDrainer::sealIdleFiles()` versiegelt stellvertretend, woran erkennbar niemand mehr
schreibt — und benutzte dafür `spool.stale_after_s` (Vorgabe 300 s). Das brach die
Zusage, die derselbe Umbau vier Zeilen später gab: Konzept 3.3.1 sagt für `deferred`
„höchstens ein Drain-Intervall" zu und empfiehlt dem Collector als Toleranzschwelle das
Zweifache des gemeldeten `drain_interval_s`, also 60 s.

Ein Frame, der 300 s auf die Versiegelung wartet, kommt mit `spool_delay_ms ≈ 300 000`
an und wird collectorseitig wie `recovered` behandelt: **keine Echtzeit-Regeln**. Unter
mod_php, wo der Spool der Regelweg ist, traf das jede Installation mit geringer Last —
also genau den Ausfall, gegen den es die drei Zustände aus 3.3.1 überhaupt gibt.

Die beiden Fristen sind jetzt getrennt: `drain_interval_s` steuert das stellvertretende
Versiegeln einer **aktiven** Datei, `stale_after_s` weiterhin das Zurückholen einer vom
Drainer **beanspruchten**. Die kürzere Frist ist gefahrlos, weil der Name einer aktiven
Datei die Kennung ihres Schreibers trägt: Sein nächster Anhang legt sie einfach neu an,
und was zwischen Dateiende und Abschluss noch hereinlief, hebt der Längenvergleich auf.

### Added — `setup-check` erkennt einen Spool, der nichts aufnimmt

`spool.max_bytes: 0` heißt: `FileSpool::hasRoomFor()` ist immer falsch, jeder Frame wird
verworfen und als `dropped_spool_full` gezählt. Unter mod_php ist das der vollständige
Erfassungsausfall, sichtbar nur als wachsender Zähler. Der Konfigurationsbaum kann die 0
nicht ablehnen (sie ist der Typ-Platzhalter für `int`) und weist die Prüfung dem
verbrauchenden Dienst zu — für den Circuit Breaker war das eingelöst, für den Spool
nicht. `max_file_bytes: 0` erscheint als Hinweis: Der Schreiber versiegelt dann nach
jedem Frame.

### Fixed — der Shutdown-Pfad meldete Events als gerettet, die der Spool verworfen hatte

`FrameDispatcher::dispatchToSpool()` gab `$frame->count()` zurück, ohne das Ergebnis des
Spool-Versuchs anzusehen — und `spool()` lieferte für beide Ausgänge `0`, war als
Auskunft also wertlos. Der `FatalErrorFlushListener` protokollierte daraufhin „n Events
wurden gerettet", während derselbe Vorgang sie als `dropped_spool_full` zählte. Der
Zähler stimmte, das Protokoll widersprach ihm — und wer nach einem Fatal Error nachsieht,
sieht zuerst das Protokoll. `spool()` gibt jetzt `bool` zurück.

### Fixed — `layers.security.active` meldete `true` ohne Security-Dienste

Der Parameter verrechnete `layers.security.enabled` mit der Verfügbarkeit des
SecurityBundle, der Import eine Zeile später verlangt aber zusätzlich die Kernel-Ebene —
`ActorFactory` und `RequestSnapshotRegistry` sind dort verdrahtet. Mit
`layers.kernel.enabled: false` stand der Parameter auf `true`, obwohl kein einziger
Security-Dienst existierte, und `ids:sensor:setup-check` schrieb „Security-Ebene: aktiv".
Die Ausgabe des Commands nennt die Abhängigkeit jetzt ebenfalls.

### Fixed — drei Docblocks beschrieben einen Zustand, den es nicht gibt

- `Rules::none()` nannte `payload_confidentiality_cleanup.enabled: false` als
  Verwendungszweck. Diese Option existiert nicht und soll es laut
  `services_payload_confidentiality_cleanup.yaml` auch nicht — „einen Weg, Werte MIT
  Klartext zu übertragen, gibt es bewusst nicht".
- `NullShipper` führte `ids_sensor.enabled: false` als zweite Verwendung. Bei dem Wert
  kehrt `loadExtension()` zurück, **bevor** `services.yaml` importiert wird — es gibt
  dann überhaupt keinen Shipper.
- `Histogram` gab die Reichweite mit „~8,4 Sekunden" an; 25 Klassen mit Deckelung bei
  Index 24 reichen bis 2²⁴ − 1, bei Mikrosekunden also rund 16,8 Sekunden.

### Fixed — `ConfigurationReachTest` übersprang eine Prüfung mit falscher Begründung

Die Ausnahmeliste führte `session_hash.key` mit „ein Parameter machte den HMAC-Schlüssel
per `debug:container` einsehbar". Der Parameter existiert und wird von
`services_kernel.yaml` gelesen; die Begründung beschrieb einen Zustand, den es nicht gab.
Ein Eintrag, der eine Prüfung mit falscher Begründung überspringt, ist genau das
Schlupfloch, gegen das dieser Test gebaut wurde — er ist entfallen, nicht korrigiert.

### Berichtigt — zwei Aussagen im Changelog zu 0.1.1

Beide betreffen Einträge, die eine Zusage als eingelöst beschrieben, die es nicht war.
Sie stehen hier, damit die Historie nicht zwei Zustände behauptet:

- **„Damit werden `layers.kernel.capture_fatal_errors` und `budget.fatal_dispatch_ms`
  wirksam."** Für `budget.fatal_dispatch_ms` stimmte das. `capture_fatal_errors` blieb
  ein Parameter, den niemand las — der Satz „damit sind alle 16 wirkungslosen
  Konfigurationsoptionen abgearbeitet" traf also für 15 zu.
- **„Die Altersschranke ist zugleich die Zusage aus Konzept 3.3.1 für `deferred`: Ein
  Frame wartet höchstens ein Drain-Intervall."** Für den Schreiber stimmte das
  (`sealAfterSeconds` = `drain_interval_s`); für das stellvertretende Versiegeln durch
  den Drainer nicht, das lief über `stale_after_s`. Derselbe Absatz nannte beide Fristen
  und behauptete beides.

### Documented — was `heartbeat.interval_s: 0` bedeutet

Dass die 0 das automatische Senden einstellt, stand nur im Docblock eines Unit-Tests;
`doc/08` sagte „Drosselungsintervall". Dort steht jetzt, wie sich die drei Wege
unterscheiden, den Heartbeat leiser zu stellen — `enabled: false`, `interval_s: 0` und
`mode: command` bedeuten für den Collector Verschiedenes. `ids:sensor:heartbeat --force`
sendet weiterhin; die Option sagt ausdrücklich, dass sie das Intervall übergeht.

## [0.1.1] — 2026-08-16

Ergebnis eines vollständigen Code-Checks: 48 Einträge, die meisten davon mehrere
Einzelbefunde. Jede Korrektur mit Verhaltensänderung trägt einen Test, der ohne sie
nachweislich fehlschlägt; wo bestehendes Verhalten nur abgesichert wurde, ist das
stattdessen durch gezielte Mutationen im Quelltext geprüft. Der Schwerpunkt liegt auf
drei Klassen von Fehlern, die alle dasselbe gemeinsam hatten — sie waren im Betrieb
unsichtbar:

- **Verluste, die als Erfolg gezählt wurden.** Ein Rennen zwischen Schreiber und
  Drainer im Spool, ein Frame ohne `events`, den der Shipper stillschweigend
  durchwinkte, ein voller Spool, der auch nach dem Drain weiter verwarf.
- **Zusagen, die niemand durchsetzte.** Sechzehn Konfigurationsoptionen ohne jede
  Wirkung, `auto_setup` als Bitte statt als Sperre, `ignored_paths` als PCRE ohne
  Prüfung, ein Circuit Breaker, der sich unter Last verzählte.
- **Geheimnisse auf Wegen, die die Denylist nicht sah.** Der Referer, die
  Exception-Meldung, Symfonys Debug-Header, ein Query-Schlüssel jenseits von
  Zeichen 64.

**Vor dem Aktualisieren lesen:** Acht Konfigurationsoptionen sind entfernt und drei
Transport-Optionen gesperrt. Wer eine davon gesetzt hat, bekommt einen Fehler beim
Kompilieren des Containers — absichtlich, denn bisher wurden sie stillschweigend
ignoriert. Die Redaktionsliste steht auf Fassung 2; wer eine eigene Liste mit
`merge_defaults: false` führt, ergänzt `X-Debug-Exception` und
`X-Debug-Exception-File` selbst. `Contract\*` ist unverändert.

### Fixed — Symfonys Debug-Header trug die Exception-Meldung im Klartext in `raw`

`X-Debug-Exception` enthält im Debug-Modus die vollständige, URL-kodierte
Exception-Meldung, und `raw.response_headers` kopierte sie ungefiltert — während
dieselbe Meldung in `payload.exception_message` durch die Denylist läuft. Ein
`?password=` im angefragten Pfad stand also im Payload redigiert und im `raw`-Feld
lesbar.

Die Redaktionsliste steht damit auf **Fassung 2**; `X-Debug-Exception` und
`X-Debug-Exception-File` sind aufgenommen. Wer eine eigene Liste mit
`merge_defaults: false` führt, muss beide selbst ergänzen.

Aufgefallen ist das unter Symfony 6.4 — der unteren Grenze der eigenen Abhängigkeiten,
die nur `make test-lowest` prüft.

### Fixed — das Bundle war unter `psr/log: ^1.1` nicht ladbar

`FailSafeLogger::log()` deklarierte Parametertypen, die die Elternschnittstelle in 1.x
nicht hat. PHP lehnt das mit einem Fatal Error beim Laden der Klasse ab — das Bundle
war unter der unteren Grenze seiner eigenen Abhängigkeiten unbenutzbar. Die Parameter
sind jetzt untypisiert, mit Typangaben im Docblock.

### Fixed — ein werfender Logger konnte den Frame kosten

Das Bundle protokolliert an 19 Stellen, alle im Request- oder Versandpfad, und alle
riefen den Logger ungeschützt auf. Ein Monolog-Handler auf einem vollen Dateisystem,
ein weggebrochener Syslog-Socket — und die Ausnahme entwich in die überwachte
Anwendung. Konzept 4. lässt dafür keinen Spielraum.

Zwei Stellen waren schlimmer als das: Im `FrameDispatcher` steht der Logaufruf im
catch-Zweig **vor** dem Spool-Rettungsversuch, im `FileSpool` vor dem `return false`,
mit dem der Aufrufer vom verworfenen Frame erfährt. Ausgerechnet der Versuch, einen
Verlust zu melden, machte ihn größer.

Neu ist `Support\Telemetry\FailSafeLogger`: ein Dekorator an der Grenze zur fremden
Bibliothek, den jeder Dienst über `ids_sensor.logger` bekommt. Eine Zusage, die jede
Aufrufstelle einzeln einhalten muss, ist keine — dieselbe Begründung, mit der der
`$onError`-Rückruf entfallen ist. Die sechs `monolog.logger`-Tags der Einzeldienste
entfallen; der Kanal steht jetzt an einer Stelle.

### Removed — toter Code

`CaptureBudget::limitMicroseconds()`, `RequestSnapshotRegistry::has()`,
`CapturedEvent::has()`, ein ungenutzter `Actor`-Import und `RequestSnapshot::$parentPath`
samt seiner Befüllung. Letzteres war nicht nur ungelesen, sondern kostete bei jedem
Sub-Request einen `getParentRequest()`-Aufruf im Erfassungspfad — der unter dem
5-ms-Budget aus Konzept 2.1 liegt. Damit entfällt auch die `RequestStack`-Abhängigkeit
des `RequestSensor`.

Nicht entfernt: `EventBuffer::all()` (die einzige nicht-destruktive Leseschnittstelle,
sie trägt ein Dutzend Integrationstests) und `EventBuffer::isFull()` (eine Zusicherung,
auf der zwei Tests stehen).

### Fixed — vier veraltete Aussagen im Quelltext

`MessengerShipper` und `ShipperInterface` schrieben dem `EventFlusher` zu, was seit der
Entflechtung der `FrameDispatcher` tut; `IdsSensorBundle` behauptete, der Shipper sei
ein Konstruktorargument des Flushers. `RulesLoader` und die mitgelieferte Regeldatei
sagten, die Listenversion reise „in jeden Frame" mit — sie steht als `cleanup_version`
im `raw`-Feld, das es nur bei `warning` und `critical` gibt. Und `Counters` kündigte
eine dateibasierte Materialisierung an, die „mit dem Spool folgt": Der Spool ist seit
langem da, und die Materialisierung ist keine offene Baustelle, sondern durch den
Prozess-Schlüssel erübrigt — das steht jetzt so da.

### Fixed — die README hatte über Composer installiert elf tote Verweise

`/doc` stand in `.gitattributes` auf `export-ignore`. Wer das Bundle über Composer
installierte, bekam eine README, die elfmal ins Leere zeigte — ausgerechnet auf
„Betrieb" und „Konfiguration", die ein Betreiber beim Deploy braucht. Das Verzeichnis
wird jetzt mit ausgeliefert (232 KB Markdown), und
`DocumentationTest::testNoLinkedDirectoryIsExcludedFromTheDistArchive()` hält es fest.
Die bisherige Verweisprüfung konnte das nicht sehen: Sie läuft im Repository, wo jede
Datei existiert.

### Fixed — `ext-mbstring` fehlte in `composer.json`

26 Aufrufe von `mb_*` in `src/`, aber nur `ext-json` unter `require`. Die Abhängigkeit
kam bis dahin zufällig über `projektmotor/ids-event-data` mit.

### Changed — `SpoolInterface` beantwortet, was der Heartbeat braucht

`waitingFiles()` und das neue `oldestWaitingAgeSeconds()` stehen jetzt am Interface.
Die `PayloadFactory` besorgte sich das erste mit `method_exists()` — eine Prüfung, die
kein Vertrag ist und eine Umbenennung stillschweigend als „gibt es nicht" gelesen
hätte, womit der Heartbeat genau das Feld verloren hätte, das Konzept 3.4 verlangt. Die
Altersberechnung stand zweimal im Quelltext und liegt jetzt dort, wo die Dateien
liegen.

### Fixed — drei Widersprüche in den beiden Commands

**`ids:sensor:heartbeat` machte den cron-Fehlerkanal wertlos.** Bei
`heartbeat.mode: request` gab er `FAILURE` zurück — bei JEDEM Lauf, dauerhaft. Sein
eigener Hilfetext schließt genau das aus („nicht bei jedem gedrosselten Lauf"), und die
Lage ist keine Störung: Der Request-Pfad sendet weiter. Sie erscheint jetzt als Warnung
mit Rückgabewert 0.

**`ids:sensor:setup-check` zeigte `mode=auto`,** während wirksam und im Heartbeat `both`
steht — `auto` wird zur Compile-Zeit aufgelöst. Wer beides verglich, sah einen
Widerspruch, den es nicht gibt. Angezeigt wird jetzt `both (aus auto)`.

**Der Hostname-Hinweis war ein Falsch-Positiv,** sobald der Hostname bereinigt oder
gekürzt werden muss (ein FQDN über 64 Zeichen genügt): Verglichen wurde die bereinigte
`instance_id` mit dem rohen `gethostname()`. Mit `--strict` ergab das einen Exit 1 für
eine völlig richtige Konfiguration. Die Regel liegt jetzt als
`InstanceIdProvider::matchesHostname()` dort, wo auch die Bereinigung liegt.

### Added — `setup-check` erkennt einen Heartbeat, der nie entstehen kann

`heartbeat.mode: request` unter einer Laufzeit ohne abkoppelbare Antwort (mod_php)
heißt: gar kein Lebenszeichen. Der Request-Pfad sendet dort bewusst nichts, und der
cron-Command ist in diesem Modus nicht zuständig — der Collector meldet dauerhaft
`ids.sensor_silent`, obwohl der Sensor arbeitet.

### Added — `setup-check` erkennt einen wirkungslosen Circuit Breaker

`circuit_breaker.open_for_s: 0` ist die stillste denkbare Fehlkonfiguration: Der
Breaker zählt Fehlschläge, meldet `half_open` — und sperrt nie. Jeder Request zahlt
bei einem Broker-Ausfall weiterhin die vollen Timeouts, also genau das, wogegen es ihn
gibt. Der Konfigurationsbaum kann die 0 nicht ablehnen (sie ist der Typ-Platzhalter
für `int`) und weist die Prüfung dem verbrauchenden Dienst zu; für den Breaker tat sie
niemand. `failure_threshold: 0` erscheint als Hinweis.

### Added — `_ids_unreadable` unterscheidet kaputt von leer

Ein werfender Getter eines Business-Events war im Frame nicht von einem leeren
Rückgabewert zu unterscheiden: `getEventName()`, das wirft, ergab denselben Ersatzwert
wie `getEventName()`, das `''` liefert — `business.unnamed` plus ein Vermerk mit
LEEREM Originalnamen. Das las sich wie „die Anwendung hat ihr Event nicht benannt" und
war in Wahrheit ein Defekt in der überwachten Anwendung, den niemand je erfuhr.

Der neue Vermerk nennt die Getter, die geworfen haben. `doc/09-business-ebene.md`
führt jetzt alle vier `_ids_`-Vermerke in einer Tabelle.

### Fixed — die Fehlermeldung des `RulesLoader` war unerreichbar

Bei einem Tippfehler im Pfad zur Redaktionsliste — dem wahrscheinlichsten Fehler
überhaupt — warf `new FileResource()` zuerst, mit „The file … does not exist". Die
ausführliche Begründung des Laders („ohne sie würde ungeprüft ausgeliefert, was Konzept
4.5.1 redigieren will") sah niemand. Die Lesbarkeitsprüfung steht jetzt davor.

### Fixed — die Breite des `Cleaner` war angreifergesteuert

`MAX_DEPTH` begrenzte die Verschachtelung, die Zahl der Elemente je Ebene begrenzte
nichts — und `RawPayload\Builder` übergibt dem Cleaner den unbereinigten
Business-Payload samt aller Formularfelder. Gebremst wurde erst danach, von
`capped()`, und zwar durch **Verwerfen** des ganzen `payload`-Zweiges. Wer 5000 Felder
schickte, bekam damit genau das, was er wollte: ein leeres `raw`.

Neu ist `Cleaner::MAX_PARAMETERS` (200) mit demselben, nicht fälschbaren
Kürzungsvermerk wie anderswo. Der Anfang bleibt erhalten, `raw` behält seinen
forensischen Wert.

### Changed — die drei Tiefengrenzen sind als Staffelung benannt

3 im `PayloadSanitizer`, 4 im `Cleaner`, abgeflacht in `raw`: Das war eine stille
Divergenz und ist jetzt eine ausdrückliche Ordnung — von der engsten
(Payload, schemagebunden nach Konzept 3) zur weitesten (raw, größengebunden, mit der
von Konzept 2.1.3 gewollten Tiefe). Nachzulesen in `doc/09-business-ebene.md` und in
den Docblocks beider Konstanten. Der Marker `__truncated` ist von `QueryNormalizer`
nach `Cleaner` gewandert, weil ihn inzwischen drei Wege setzen.

### Fixed — `payload.exception_message` lief an der Denylist vorbei

Das Feld wurde nur von Steuerzeichen befreit und gekürzt. Die Meldung ist
angreiferbeeinflusst und trägt oft die angefragte URI samt Query — und sie reist bei
**jeder** Stufe mit, nicht nur bei `warning`/`critical` wie `raw`. Eine
`AccessDeniedException` oder `NotFoundHttpException` mit `?reset_token=…` im Pfad ging
damit im Klartext auf die Leitung.

Neu ist `Cleaner::cleanFreeText()`: Es redigiert `name=wert`-Paare in
Query-Schreibweise über dieselbe Denylist wie überall sonst. Ein Geheimnis in Prosa
oder in SQL-Syntax bleibt bewusst unangetastet — die Grenze und ihre Begründung stehen
jetzt in `doc/06-vertraulichkeit.md`, das von vier auf fünf Eintrittspunkte
fortgeschrieben ist.

### Fixed — `ignored_paths` war eine stille Falle

**Ein Muster ohne Trennzeichen wurde nie angewandt.** Die Einträge sind PCRE-Ausdrücke,
und `isIgnored()` prüft sie mit `@preg_match` — `/health` kompilierte anstandslos und
traf dann nichts. Der Betreiber glaubte, einen Pfad ausgeschlossen zu haben, und bekam
ihn weiter erfasst; weder Konfigurationsbaum noch Doku erwähnten die
Trennzeichenpflicht. Ungültige Muster werden jetzt beim Kompilieren abgelehnt, und die
Doku nennt die Schreibweise.

**Der Filter galt in zwei von drei Sensoren nur mit Snapshot.** Fehlt er — weil ein
Listener mit höherer Priorität als 1024 bereits geantwortet hat —, erfassten
`ResponseSensor` und `ExceptionSensor` einen ausdrücklich ausgeschlossenen Pfad
trotzdem. Ausgerechnet Gesundheitsprüfungen laufen oft über solche
Kurzschluss-Listener. Beide fallen jetzt auf den Pfad des Requests zurück, wie der
`RequestSensor` es seit jeher tut.

### Fixed — vier Ungereimtheiten im Erfassungspfad

**Die Roh-Session-ID überlebte den Request im Klartext.** `SessionIdHasher` hielt sie
in einem Instanzfeld und implementierte als einziger Dienst im Erfassungspfad kein
`ResetInterface` — in einer Worker-Laufzeit (FrankenPHP, RoadRunner, Swoole) stand sie
dort bis zum nächsten Request mit einer anderen ID. Genau die Klartext-Speicherung,
die der Docblock der Klasse als „niemals" bezeichnet, nur eine Ebene tiefer.

**`actor.user` war mal `null`, mal `''`.** Konzept 2.2.4 kennt zwei Zustände; `''` ist
keiner von beiden und verhält sich in einer Gruppierung des Collectors wie ein eigener
Nutzer. Drei Aufrufstellen normalisierten selbst, der `AuthenticationSensor` an vier
Stellen nicht — und Anmeldefehlschläge sind der Fall, für den das Feld da ist. Die
Regel steht jetzt einmal in `CapturedEvent::setActorUser()`.

**`payload.resource` konnte `''` werden** (`is_scalar('')`, `(string) false`), obwohl
der Auflöser im Docblock „immer eine Auskunft" zusagt. Und der Dedup-Schlüssel schrieb
„keine Ressource" als `'-'` — ein Bindestrich ist eine gültige Ressourcenkennung,
womit zwei verschiedene Entscheidungen auf denselben Schlüssel fielen und die zweite
ungezählt verschwand. Der Platzhalter ist jetzt ein Nullbyte.

**Der Fingerabdruck wurde bei header-losen Clients bis zu 200-mal je Request neu
berechnet.** Gemerkt wurde mit `??=`, und `null` ist beim Fingerabdruck ein gültiges
Ergebnis — die Ersparnis blieb ausgerechnet bei Bots und Scannern aus, für die der
Docblock den Schreibzugriff auf das fremde Objekt rechtfertigt.

### Fixed — vier Lücken für ein Geheimnis oder eine stille Abschaltung

**Ein Geheimnis hinter Zeichen 64 eines Query-Schlüssels blieb im Klartext.** Der
`QueryNormalizer` kürzte den Schlüssel auf 64 Zeichen und gab den **gekürzten** an
die Denylist, die per `str_contains` sucht. Stand `token` dahinter, fand sie nichts.
Der `raw`-Pfad bekam denselben Wert mit dem vollen Schlüssel und redigierte ihn —
zwei Ergebnisse für dieselben Daten. Der volle Schlüssel entscheidet jetzt, der
gekürzte steht weiterhin als Ausgabeschlüssel im Frame.

**Der Kürzungsvermerk im Business-Payload war fälschbar.** `PayloadSanitizer`
schrieb `_ids_truncated` als Literal und filterte eingehend nur den `_ids_`-Präfix
nach — nicht diesen Schlüssel. Eine Anwendung konnte damit einen
Vollständigkeitsverlust vortäuschen, den es nie gab. Genau das schließt die
Begründung des Präfixes aus; für diesen Marker war sie nicht eingelöst.

**Ein Tippfehler in `raw.severities` schaltete `raw` lautlos ab.** `['warnings']`
oder `['WARNING']` kompilierte anstandslos, und der Gate fand die Stufe nie in
seiner Liste: kein Fehler, keine Meldung, kein Zähler.

### Changed — `transport.options` kann drei Vorgaben nicht mehr überschreiben

`auto_setup`, `lazy` und `serializer` werden beim Kompilieren abgelehnt. Sie standen
als Bitte in der Doku und als ausführliche Begründung im Quelltext — durchgesetzt hat
sie niemand, `array_merge` ließ die Anwendung gewinnen. `lazy: false` etwa öffnet die
Verbindung beim **Bauen** des Dienstes, außerhalb jedes `try/catch` des Sensors, und
bricht damit fail-open.

Der `setup-check` verliert dafür seinen `auto_setup`-Befund: Er war die schwächere
Antwort auf dieselbe Frage — später und mit `|| true` abschaltbar.

### Fixed — zwei Verlustquellen, die niemand zählte

**Ein Fehler in der Erfassung verschwand spurlos.** `CaptureBudget` bot dafür einen
optionalen `$onError`-Rückruf an — und **keine** der acht Aufrufstellen übergab
ihn. Der Zweig war toter Produktionscode: kein Zähler, kein Logeintrag, ein
defekter Sensor von einem ruhigen Request nicht zu unterscheiden. Das widersprach
Konzept 4. und wörtlich dem Docblock von `CapturingEventDispatcher` („Der Sensor
selbst protokolliert seine Fehler").

Der Rückruf ist entfallen. Als opt-in-Mechanismus galt die Zusage nur, wenn jede
Aufrufstelle daran dachte — eine Zusage, die jede Aufrufstelle einzeln einhalten
muss, ist keine. Das Budget zählt und protokolliert jetzt selbst, und der neue
`dropped_capture_error` reist im Frame und im Heartbeat mit.

**Der `SpoolDrainer` hatte überhaupt keinen Zähler.** Eine verworfene Zeile —
unlesbar oder dauerhaft unversendbar — hinterließ nur einen Logeintrag, und
`$logger` ist optional. `ids:sensor:spool:flush` meldete sogar „Nichts
nachzusenden", nachdem er eine ganze Datei restlos verworfen hatte. Neu ist
`dropped_spool_unreadable`; der Command zeigt die Zahl und warnt.

Beim Umbau ist nebenbei aufgefallen, dass der neue Logger im `catch`-Zweig des
Budgets selbst entweichen konnte — derselbe Fehler, der schon im `EventFlusher`
steckte. Der Test dazu fand ihn beim ersten Lauf.

### Fixed — drei Zähler sagten die Unwahrheit

- **`latency.in_request_overhead_us` war dauerhaft leer.** `LatencyRecorder::recordCapture()`
  hatte keinen einzigen Produktionsaufrufer: `CaptureBudget` maß die Erfassungszeit seit
  jeher mit und behielt die Zahl für sich. Vier Stellen behaupteten das Gegenteil, unter
  anderem `PayloadFactory` wörtlich. Eingesammelt wird sie jetzt beim Flush über
  `DeferredCounters` — nicht in Phase A, wo ein zusätzlicher Dienst unter dem
  Erfassungsbudget stünde. Damit ist die 5-ms-Zusage aus Konzept 2.1 erstmals im Betrieb
  überprüfbar, so wie Konzept 3.4 es verlangt.
- **`captured` zählte nach den Verlusten statt davor.** Der Docblock der Konstante sagt
  „Erfasste Events, bevor irgendetwas verworfen wurde", erhöht wurde aber am Ende der
  Normalisierung auf die übrig gebliebene Zahl. Collectorseitig ging die Bilanz
  `captured = sent + spooled + Σ dropped_*` damit nicht auf: `dropped_no_normalizer` und
  `dropped_normalize_error` waren doppelt abgezogen.
- **`discarded_full` zählte auch Kodierfehler.** `SpoolInterface` definiert ihn als „wegen
  Überschreitung der Maximalgröße verworfen", und der Wert geht unter diesem Namen in den
  Heartbeat — der Betreiber vergrößerte also die Platte, während die Ursache ein Payload
  war, den `json_encode` nicht abbilden kann. Neu ist `discarded_unencodable`.

Der Test, der die Latenzmessung bisher „prüfte", war grün, weil der Zähler *immer* 0 war.
Er belegt jetzt das Gegenteil und schlägt ohne die Korrektur fehl.

### Added — der Puffer überlebt einen Fatal Error

Bei einem Fatal Error — erschöpfter Speicher, überschrittene Ausführungszeit, ein
`E_ERROR` aus einer Erweiterung — endet PHP sofort. Kein `kernel.terminate`, kein
Flush: Der Puffer starb mitsamt allen Events des Requests, ungezählt und
unprotokolliert, von einem stillen Sensor nicht zu unterscheiden. Konzept 4.
schließt das aus, und die betroffenen Requests sind selten die uninteressanten —
ein OOM ist ein möglicher Ausgang eines Speicherangriffs.

Neu ist `Delivery\Dispatch\FatalErrorFlushListener`. Er registriert eine
Shutdown-Funktion und schreibt das Erfasste **nur in den Spool**: Der Prozess
stirbt gerade, sein Zustand ist unzuverlässig, und ein Broker-Versuch mit 20 ms
Timeout überschritte das Budget aus `budget.fatal_dispatch_ms` schon für sich
genommen. Der Frame trägt `deferred`, nicht `recovered` — der Weg über den Spool
ist hier planmäßig.

Damit werden **`layers.kernel.capture_fatal_errors` und
`budget.fatal_dispatch_ms`** wirksam. Die Option verspricht allerdings bewusst
weniger als früher: Ein `kernel.exception` wird **nicht** synthetisiert. Das
Konzept verlangt es nirgends — Abschnitt 2.1.1 nennt Fatal-Fehler nur als
Begründung dafür, warum `kernel.exception` wichtig ist, und ein PHP-`TypeError`
ist ein `\Error` und wird dort ohnehin erfasst. Ein erfundenes Ereignis wäre eine
Beobachtung, die niemand gemacht hat. Die Doku ist entsprechend korrigiert.

**Damit sind alle 16 wirkungslosen Konfigurationsoptionen abgearbeitet** — neun
umgesetzt oder verdrahtet, acht entfernt (`spool.drain` zählt doppelt, weil
Knoten und Parameter getrennt geführt wurden). Die Schuldlisten in
`ConfigurationReachTest` sind leer und ersatzlos entfallen: Wer künftig eine
wirkungslose Option hinzufügt, bekommt einen roten Test und muss entscheiden,
statt eine Zeile nachzutragen.

### Added — `flush.max_frame_bytes` wirkt

Die Option war dokumentiert („Obergrenze je Frame") — eine Frame-Größengrenze gab
es überhaupt nicht. Redis lehnt eine Nachricht oberhalb von `proto-max-bulk-len`
ab; ein zu großer Frame kam also aus sich heraus nie durch.

Er wird jetzt verworfen und als `dropped_frame_too_large` gezählt — bewusst
**nicht** gespoolt: Der Drainer schickte ihn später an denselben Broker und liefe
in denselben Fehler, die Zeile blockierte den Spool bei jedem Lauf, bis er voll
ist. Genau das Head-of-Line-Blocking, gegen das es `UnshippableFrameException`
gibt. Der eigene Zähler statt `dropped_spool_full`: Der eine sagt „die Platte ist
voll", der andere „diese Sendung ist zu groß" — die erste Auskunft führt zu mehr
Plattenplatz, die zweite zu einer Untersuchung des Payloads.

### Removed — `budget.max_events_per_process`

**Breaking Change.** Die Option hatte keine kohärente Bedeutung, und der Docblock
von `Sensor\EventBuffer` versprach mit „Zwei Obergrenzen" entsprechend etwas, das
sich nicht umsetzen ließ:

- Als Grenze für den aktuellen Pufferinhalt wäre sie wirkungslos — `drain()` leert
  den Puffer, sein Inhalt liegt ohnehin immer unter `max_events_per_request`, und
  die Vorgabe 200 lag über den 64 des Requests.
- Als kumulative Grenze über die Prozesslebenszeit wäre sie schädlich: Ein
  Messenger-Worker läuft Stunden und hätte nach 200 Events dauerhaft aufgehört zu
  erfassen — ein blinder Sensor, der weiterläuft.

Der Fall, den die Begründung nannte („langlebige Prozesse, in denen kein
`kernel.terminate` den Puffer leert"), tritt nicht ein: `FlushListener` hängt
zusätzlich an `console.terminate` und an den Worker-Ereignissen.

### Fixed — `logging.channel` war wirkungslos

Der Monolog-Kanal stand hart in acht `monolog.logger`-Tags; die dokumentierte
Option las niemand.

Bemerkenswert am Weg dorthin: Der naheliegende Weg — `channel:
'%ids_sensor.logging.channel%'` in der YAML — funktioniert **nicht**.
`ResolveParameterPlaceHoldersPass` fasst von den Tags ausschließlich `proxy` an;
jedes andere Tag-Attribut bleibt die Zeichenkette, die dort steht. MonologBundle
hätte einen Kanal namens `%ids_sensor.logging.channel%` bekommen, und niemand
hätte es gemerkt, weil das Protokollieren weiterläuft — nur eben in einen Kanal,
den keine Konfiguration kennt. Aufgefallen ist es am Container-Abdruck, der den
Platzhalter unaufgelöst zeigte.

Der Kanal wird deshalb in `loadExtension()` gesetzt, nach allen Importen, damit
auch die bedingt geladenen Dienste erfasst sind.

### Removed — sieben Konfigurationsoptionen ohne Wirkung

**Breaking Change.** Alle sieben standen im Konfigurationsbaum, waren in
`doc/08-konfiguration.md` mit Wirkung dokumentiert — und wurden von niemandem
gelesen. Wer sie setzte, bekam eine Bestätigung durch `debug:config` und kein
verändertes Verhalten.

| Option | Was sie versprach |
|---|---|
| `spool.drain` | `off` · `command` · `opportunistic` · `both` — vollständig validiert; `off` sendete trotzdem nach, `opportunistic` gab es nie |
| `spool.drain_min_interval_s` | — |
| `budget.drain_ms` | „Zeitfenster je Drain-Lauf" |
| `flush.batch` | „bündelt alle Events eines Requests zu einem Frame" — gebündelt wird immer, und Konzept 3.3 legt das fest: ein Schalter dagegen wäre ein Konzeptverstoß |
| `circuit_breaker.half_open_probes` | „Proben nach Ablauf der Offenzeit" — der Breaker hat keinen Probe-Zähler, `half_open` ist ein abgeleitetes Etikett |
| `telemetry.profiler_collector` | „Panel im Symfony-Profiler" — es existiert kein DataCollector |
| `logging.enabled` | — alle Logger sind bereits `@?logger` |

Wer eine davon in seiner Konfiguration stehen hat, bekommt beim nächsten Deploy
einen Konfigurationsfehler. Das ist beabsichtigt: Symfonys Config-Komponente lehnt
unbekannte Schlüssel ab, und ein Abbruch ist besser, als weiter zu glauben, die
Option wirke. `BundleBootTest::testARemovedOptionIsRejectedInsteadOfIgnored()`
hält das für alle sieben fest.

Für `spool.drain: off` gibt es keinen Ersatz und braucht auch keinen: Wer nicht
drainen will, richtet den cron nicht ein.

### Fixed — `session_hash.min_key_length` prüfte nichts

`doc/08:80` nennt den Wert „Untergrenze der Prüfung", `README.md:154` verspricht
„≥ 32 Zeichen" — geprüft wurde nichts. `key: 'geheim'` kompilierte, lief und war
im `setup-check` grün. Ist der Schlüssel zu kurz, lässt sich aus einer gestohlenen
Event-Datenbank die Session-ID zurückrechnen: genau der Session-Hijacking-Vektor,
den das Hashen nach Konzept 2.2.4 verhindern soll.

Geprüft wird jetzt beim Kompilieren, und zwar NACH der APP_SECRET-Prüfung — deren
Meldung nennt die Ursache, „zu kurz" nur das Symptom, und ein APP_SECRET ist meist
ohnehin zu kurz.

### Fixed — `budget.connect_timeout_ms` und `read_timeout_ms` waren wirkungslos

Wirksam waren die hartkodierten `0.02` und `0.03` in `TRANSPORT_DEFAULTS` —
numerisch identisch mit den Vorgaben der beiden Optionen. Das machte den Fehler
besonders schwer zu sehen: Wer sie änderte, bekam eine plausible Bestätigung durch
`debug:config` und keine Wirkung.

Die Werte kommen jetzt aus der Konfiguration. Gelesen werden sie in
`prependExtension()` aus der noch unverarbeiteten Konfiguration — dieselbe
Vorgehensweise wie beim Transport, denn die Optionen entstehen, bevor irgendeine
Extension geladen ist.

### Fixed — `budget.dispatch_ms` war nie verdrahtet

Konzept 4. verlangt wörtlich: „Hartes Timeout von 50 ms; danach Abbruch des
Versands, der Request läuft normal weiter." Die Vorgabe im Konfigurationsbaum ist
exakt diese Zahl — jemand hat sie übernommen und nie verbunden. Wer sie änderte,
bekam eine plausible Bestätigung durch `debug:config` und keine Wirkung.

Durchgesetzt wird die Frist jetzt im `FlushListener`, und zwar an der einzigen
Naht, an der PHP sie überhaupt prüfen kann: zwischen dem Frame und dem
Lebenszeichen. Ein laufender Syscall lässt sich nicht abbrechen — genau so steht
es auch in `doc/08-konfiguration.md`. Hat der Frame das Budget verbraucht, entfällt
der Heartbeat; er ist die verzichtbare der beiden Sendungen, weil er sich im
nächsten Intervall von selbst wiederholt, während die Events eines Requests
einmalig sind.

Neu ist dafür `Delivery\Heartbeat\EmitterInterface`. Nicht wegen
Austauschbarkeit — es wird auf absehbare Zeit einen `Emitter` geben —, sondern
weil die Schranke sonst ungeprüft geblieben wäre: Den finalen `Emitter` zu bauen
verlangt eine `PayloadFactory` mit zehn Abhängigkeiten, für einen Test, der nur
wissen will, ob überhaupt gesendet wurde. Ein untestbarer Schutzmechanismus ist
keiner.

### Added — drei Tests, die tote Konfiguration sichtbar machen

Der Code-Check hat 16 Optionen gefunden, die im Baum stehen, in
`doc/08-konfiguration.md` mit Wirkung dokumentiert sind — und die niemand liest.
`BundleBootTest` beschreibt genau diesen Fehler seit Langem wörtlich, aber nur für
einen einzelnen, längst entfernten Schalter. Nichts hielt ihn allgemein auf.

Neu ist `tests/Unit/ConfigurationReachTest` mit zwei Richtungen entlang der Kette
Baum → Parameter → Dienst, die an beiden Gliedern reißen kann:

- `testEveryConfigKeyBecomesAParameter()` — jeder Blattknoten wird zu einem
  Container-Parameter oder steht mit Begründung in einer Ausnahmeliste.
- `testEveryParameterReachesAService()` — jeder gesetzte Parameter wird von
  mindestens einer Dienstdefinition gelesen. Diese Fälle sind die tückischeren:
  `debug:config` UND `debug:container` zeigen den Wert, er sieht an jeder Stelle
  wirksam aus, an der man nachsehen würde.

Die heute toten Optionen stehen in zwei getrennten Listen — `NOCH_NICHT_VERDRAHTET`
und `PARAMETER_OHNE_WIRKUNG` —, bewusst nicht bei den begründeten Ausnahmen. Jeder
Eintrag nennt die dokumentierte Zusage, die derzeit nicht gilt, und verschwindet
mit ihrer Umsetzung oder mit der Entfernung des Knotens. Die Trennung verhindert
genau die Bequemlichkeit, die den Befund erzeugt hat: einen toten Knoten unter die
Ausnahmen zu schieben und für erledigt zu halten.

Dazu zwei Gegenrichtungen in `DocumentationTest`:
`testEveryConfigKeyIsDocumented()` (die vorhandene Prüfung ging nur den Weg „die
Doku erfindet keine Schlüssel") und `testDocumentedDefaultsMatchTheTree()` — ein
falscher Vorgabewert ist schlimmer als ein fehlender, weil wer ihn liest den
Schlüssel weglässt.

### Fixed — der Circuit Breaker verzählte sich unter Last

`CircuitBreaker` zählte Fehlschläge mit `read()` + `write()`, und das ist ein Lost
Update. Fällt der Broker aus, laufen n FPM-Kinder gleichzeitig durch diesen Pfad,
lesen alle `failures = 0` und schreiben alle `1` — der Zähler stieg nicht mit der
Zahl der Fehlschläge, sondern wurde ständig zurückgesetzt. Die Schwelle wurde im
ungünstigen Fall **nie** erreicht, und `openCount` verzählte sich mit.

Ausgerechnet unter Last, also in genau dem Szenario, für das der Breaker laut
seinem eigenen Docblock existiert: „Ein FPM-Pool mit 32 Kindprozessen bei 200
Requests pro Sekunde ist damit erschöpft." Ein Messlauf mit vier Prozessen à 25
Fehlschlägen kam auf 58 statt 100.

`BreakerStateStoreInterface` hat dafür eine dritte Methode bekommen:
`mutate(\Closure $mutator): BreakerState` liest, wendet an und schreibt unteilbar.
Die Entscheidung, was aus dem Zustand wird, bleibt beim Breaker — der Mutator ist
eine reine Funktion, der Speicher liefert nur die Unteilbarkeit. Umgesetzt mit
einem blockierenden `flock` auf einer eigenen Sperrdatei; das Betriebssystem gibt
sie frei, wenn ein Prozess stirbt, ein Verklemmen ist also ausgeschlossen.
`recordSuccess()` liest weiterhin erst ungesperrt vor und sperrt nur, wenn es
tatsächlich etwas zu ändern gibt — dieser Pfad läuft nach jedem erfolgreichen
Versand.

### Fixed — drei Wege, auf denen ein Verlust als Erfolg gemeldet wurde

- **`MessengerShipper::ship()` kehrte bei fehlendem `events`-Feld still zurück.**
  Der Drainer wertete das als Erfolg, zählte den Frame als nachgesendet und löschte
  die Zeile; im Direktpfad erhöhte der `FrameDispatcher` sogar `sent`. Ein am Ende
  abgeschnittener Spool-Eintrag — ohne `fsync` erwartbar und im Klassenkopf von
  `FileSpool` ausdrücklich in Kauf genommen — verschwand damit als Erfolg gemeldet.
  Geworfen wird jetzt `UnshippableFrameException`; der Drainer unterscheidet sie
  schon immer vom Broker-Ausfall. Ein **leerer** `events`-Array bleibt wie bisher
  ein stiller Rücksprung: Da ist nichts zu verlieren.
- **`Heartbeat\Scheduler::isDue()` klemmte die Zeitdifferenz nicht.** Ein Stempel
  aus der Zukunft — NTP-Rücksprung, oder ein Stempel von einem anderen Host auf
  einem geteilten Volume — machte die Differenz negativ, und der Heartbeat blieb
  aus, bis die Wanduhr aufgeholt hatte. Der Collector meldete dann
  `ids.sensor_silent` für einen gesunden Sensor. Die Nachbarmethode
  `secondsSinceLastSend()` klemmte denselben Fall schon immer mit `max(0, …)` — die
  beiden widersprachen sich.
- **`BreakerState::isOpenAt()` glaubte jedem Zielzeitpunkt.** `openUntil` ist
  absolute Wanduhrzeit und überlebt im Dateirückfall Prozess und Neustart; eine TTL,
  die einen Uhr-Rücksprung kappt, gibt es dort nicht. Ein Rücksprung um eine Stunde
  hielt den Breaker eine Stunde offen: Der Sensor spoolte durchgehend, obwohl der
  Broker längst wieder lief, und der Heartbeat meldete `state: open` ohne einen
  einzigen frischen Fehlschlag. Ein Zielzeitpunkt jenseits der konfigurierten
  Offen-Zeit wird jetzt ignoriert.

### Fixed — das Rennen zwischen Schreiber und Drainer im Spool

Der Dateiname bestand aus PID und einem Thread-Zusatz und war damit für jeden
anderen Prozess jederzeit rekonstruierbar. Der Drainer benannte genau die Datei
um, in die gerade geschrieben wurde. Daraus folgten **drei** Verlustwege:

1. Ein Anhang, der zwischen `open` und `write` des Drainer-`rename()` fiel, landete
   hinter dem gelesenen Dateiende und wurde mit `unlink()` entfernt.
2. Nach dem Beanspruchen legte der Schreiber unter demselben Namen eine **neue**
   Datei an. Das abschließende `rename()` des Drainers überschrieb sie wortlos —
   Verlust war dann nicht eine Zeile, sondern **alles, was während des ganzen
   Drain-Laufs erfasst wurde**.
3. Die mit dem vorigen Eintrag eingeführte Wiederaufnahme liegengebliebener
   `.draining`-Dateien hatte denselben Klobber: Sie benannte auf einen Namen
   zurück, unter dem ein lebender Prozess schrieb.

**Neu: aktive und versiegelte Dateien sind getrennt.** Der Schreiber hängt an
`frames-<pid>-<kennung>.active` an; erst wenn sie `spool.max_file_bytes`
überschreitet **oder** älter als `spool.drain_interval_s` ist, versiegelt er sie
zu `frames-<pid>-<kennung>-<nr>.jsonl`. Nur Versiegeltes darf der Drainer
abholen. Die je Instanz gezogene Kennung macht den Namen einer aktiven Datei für
fremde Prozesse unbildbar; `finish()` und die Wiederaufnahme legen ihre Ergebnisse
grundsätzlich unter frischen Namen ab. Der Drainer versiegelt zusätzlich
stellvertretend, woran seit `spool.stale_after_s` niemand mehr geschrieben hat —
sonst bliebe der Frame eines still gewordenen Prozesses für immer liegen. Was
zwischen Dateiende und Abschluss noch hereinkam, rettet ein Längenvergleich.

Die Altersschranke ist zugleich die Zusage aus Konzept 3.3.1 für `deferred`:
Ein Frame wartet höchstens ein Drain-Intervall.

**Mit erledigt:** Die kaputte Rotation (war die Basisdatei einmal zu groß, entstand
eine neue Datei je Wanduhrsekunde, und die rotierten wurden nie selbst geprüft)
und `threadSuffix()`, das `zend_thread_id()` aufrief — eine Funktion, die es im
PHP-Kern nicht gibt, weshalb der Rückfall das Objekt statt des Threads
identifizierte. Außerdem sortiert `pendingFiles()` nicht mehr mit unmaskiertem
`filemtime()`.

**Neu für die Meldung: `waitingFiles()`.** Heartbeat und `ids:sensor:setup-check`
zählen ab jetzt alles, was auf der Platte liegt — aktiv, versiegelt oder gerade
beansprucht. `pendingFiles()` beantwortet weiterhin die andere Frage („was darf
der Drainer abholen"). Ohne die Trennung hätte das Versiegeln eine Zusage
gebrochen: Konzept 3.4 nennt `oldest_pending_age_s` als die einzige Stelle, an der
ein nicht laufender Drain von außen sichtbar wird.

### Fixed — abgebrochene Drain-Läufe verkeilten den Spool unsichtbar

`SpoolDrainer::claim()` benennt eine Datei vor dem Senden auf `.draining` um.
Stirbt der Prozess danach — SIGKILL, OOM, Deploy, cron-Timeout —, blieb sie für
immer liegen: `FileSpool::pendingFiles()` filtert auf `.jsonl` und fand sie nicht
mehr, `recount()` globt ohne Suffix und zählte ihre Bytes weiter gegen
`spool.max_bytes`. Zwei Methoden derselben Klasse mit verschiedenem Muster.

Die Folge ist genau der Zustand, den Konzept 4. ausschließt: Der Spool läuft
voll und verwirft, während jede Auskunft des Sensors über sich selbst „leer"
meldet — Heartbeat und `ids:sensor:setup-check` fragen beide `pendingFiles()`.
Konzept 3.4 nennt `oldest_pending_age_s` ausdrücklich als die einzige Stelle, an
der ein nicht laufender Drain von außen sichtbar wird.

`ids:sensor:spool:flush` holt beanspruchte Dateien jetzt zurück, sobald sie älter
als `spool.stale_after_s` sind — ein laufender Drain-Lauf wird dadurch nicht
bestohlen. Damit wird zugleich **`spool.stale_after_s` überhaupt erst wirksam**;
der Knoten war dokumentiert („ab wann eine Datei als liegengeblieben gilt") und
wurde von niemandem gelesen.

Zusätzlich protokolliert `finish()` jetzt, wenn der Rest einer Datei nicht
zurückgeschrieben werden konnte. Der Zweig war leer — und die häufigste Ursache
ist die volle Platte, also genau die Lage, in der nachgesendet wird.

### Fixed — der Circuit Breaker war in jedem CLI-Prozess wirkungslos

`Delivery\Transport\Breaker\SharedStateStore` entschied über
`ini_get('apc.enabled')`, ob APCu benutzbar ist. Maßgeblich ist in der CLI aber
`apc.enable_cli`, per Vorgabe 0. `apc.enabled` meldete trotzdem 1, also galt APCu
als verwendbar — während `apcu_store()` folgenlos blieb und `apcu_fetch()` immer
`$success = false` lieferte. `read()` gab damit dauerhaft `closed()` zurück, und
der **Dateirückfall wurde nie erreicht**: genau der Rückfall, den der Docblock
der Klasse als unverzichtbar begründet.

Betroffen war unter anderem `ids:sensor:spool:flush` per cron gegen einen
ausgefallenen Broker — der Breaker öffnete nie, jeder Lauf lief in das volle
Timeout. Geprüft wird jetzt mit `apcu_enabled()`; dieselbe Antwort, die
`Heartbeat\Scheduler` und `SetupCheckCommand` schon immer benutzt haben. Es gab
drei Prüfungen für dieselbe Frage.

`SharedStateStore` hatte bis hierher **keinen einzigen Test** — weder Unit noch
Integration; `CircuitBreakerTest` benutzt durchgehend eine In-Memory-Attrappe.
Der Regressionstest läuft in einem Unterprozess mit `-d apc.enable_cli=0`, weil
die Testumgebung APCu in der CLI ausdrücklich aktiviert und ein übersprungener
Test genau die fragliche Konstellation nicht geprüft hätte.

### Fixed — `ids:sensor:setup-check` scheiterte auf der Mindestkonfiguration

Zwei Fehler in derselben Prüfung, beide auf dem dokumentierten Standardweg:

1. Der Command las `spool.dir` aus der **rohen** Konfiguration. Dort ist der Wert
   per Vorgabe `null`; erst das Bundle setzt daraus
   `%kernel.project_dir%/var/ids-spool`. Da die Datei `declare(strict_types=1)`
   trägt, warf `is_dir(null)` einen TypeError. Gelesen wird jetzt der aufgelöste
   Pfad vom Spool-Dienst selbst. Ein falsches `@var` (`dir: string` statt
   `string|null`) hatte PHPStan davon abgehalten, das zu sehen.
2. Existierte das Spool-Verzeichnis noch nicht, prüfte der Command nur das
   unmittelbare Elternverzeichnis auf Schreibbarkeit. In einer frischen
   Installation fehlt regelmäßig auch `var/` selbst — der Command meldete dann
   einen Befund für einen gesunden Zustand. Geprüft wird jetzt der nächste
   vorhandene Vorfahre; `FileSpool` legt fehlende Zwischenebenen ohnehin an.

Der Command ist laut `doc/07-betrieb.md` im Deploy Pflicht und soll ausdrücklich
nicht mit `|| true` entschärft werden — er war auf der Standardinstallation rot.
Kein Test hatte das gedeckt, weil jede Variante `spool.dir` ausdrücklich setzte.

Nebenbei entfallen zwei Altlasten derselben Datei: der unerreichbare Zweig „Kein
Spool konfiguriert" (der Spool wird unbedingt registriert) und die
`method_exists()`-Prüfung auf `pendingFiles()`. Der Command ist jetzt gegen
`FileSpool` typisiert — dieselbe Begründung wie beim `SpoolDrainer`: er benutzt
die Leseseite, die `SpoolInterface` absichtlich verbirgt.

### Fixed — Klartext-Leck über den Referer

`Referer` trägt als Wert eine **vollständige fremde URL samt Query** und lief an
der Redaktion vorbei: In `payload.referer` wurde er nur gekürzt, in
`raw.request_headers.referer` unverändert übernommen. Die Denylist greift über
Namen, und als Headername ist `Referer` zu Recht harmlos — sein Wert ist es
nicht.

Auslöser ohne jedes Zutun eines Angreifers: Wer `https://app.example/reset?token=…`
öffnet und dort einen Link anklickt oder eine Ressource lädt, schickt das Token
im `Referer` mit. `payload` reist laut Konzept 3.1.1 bei **jeder** Stufe mit, also
auch bei `info` — das Token stand damit in praktisch jedem Folge-Request im
Beweisspeicher. Dieselbe Klasse: `?signature=`, OAuth-`?code=`, Magic-Links.

Neu ist `Support\PayloadConfidentialityCleanup\Cleaner::cleanUrl()`: Der
Query-String läuft durch dieselbe Parameter-Denylist wie `payload.query`, Host und
Pfad bleiben stehen, Zugangsdaten in der URL entfallen ganz. Angewandt auf beide
Felder und zusätzlich auf `Location` und `Content-Location`.
`NoPlaintextLeavesTheSensorTest` prüft den Referer jetzt mit — er hatte ihn als
einzigen Eintrittspunkt nicht abgedeckt.

### Fixed — ein einmal voller Spool verwarf dauerhaft, auch nach dem Drain

`Delivery\Transport\Spool\FileSpool` führt den Belegungsstand mit und rechnet ihn
alle 256 Schreibvorgänge nach. Der Zähler dafür wurde aber nur im Erfolgsfall
erhöht: Erreichte ein Prozess die Obergrenze, fror er ein, und
`refreshByteCount()` rechnete **nie wieder** nach. Der Prozess verwarf jeden
weiteren Frame bis zu seinem Ende — auch nachdem `ids:sensor:spool:flush` das
Verzeichnis vollständig geleert hatte. Bei einem FPM-Kind mit
`pm.max_requests = 0` sind das Stunden; unter mod_php, wo der Spool der Regelweg
ist, war es der vollständige Ausfall der Erfassung dieses Kindprozesses. Sichtbar
war nur ein wachsendes `dropped_spool_full`, während `sizeInBytes()` dem
Heartbeat weiterhin den eingefrorenen Stand meldete — „Spool voll" bei leerer
Platte.

Vor dem Verwerfen wird jetzt einmal hart nachgerechnet. Die Kosten fallen nur auf
diesem Pfad an.

### Fixed — `spool:flush` ohne Broker löschte den Spool und meldete Erfolg

Ohne konfigurierte DSN bleibt `ids_sensor.shipper` der `NullShipper` — auch für
den Drainer. Der wirft nie, also galt jede Zeile als versendet, und `finish()`
löschte die Datei. Unter mod_php mit vergessener oder entfernter DSN leerte der
Minuten-cron damit stillschweigend den Spool und meldete „nachgesendet".

`ids:sensor:spool:flush` bricht jetzt mit Rückgabewert 1 und einem Befund ab,
solange kein Broker konfiguriert ist. Der Spool sammelt dann, bis er voll ist,
und verwirft gezählt — sichtbar statt lautlos.

### Fixed — unter mod_php war die Echtzeit-Erkennung dauerhaft abgeschaltet

`Delivery\Transport\Spool\SpoolDrainer::drain()` nahm den `dispatch_path` als
Argument mit `recovered` als Vorgabe und schrieb ihn bedingungslos in jeden
Frame. Unter mod_php schreibt der Sensor aber jeden Frame planmäßig als
`deferred` in den Spool, und der dokumentierte cron-Eintrag
(`doc/05-versandweg.md`) übergibt keine Angabe. Jeder Frame kam damit als
`recovered` an — und Konzept 3.3.1 nimmt genau diesen Wert von der
Echtzeit-Auswertung aus. Die Regeln R1–R7 haben auf einer mod_php-Installation
nie gefeuert, ohne dass irgendetwas fehlschlug oder auffiel.

Der Drainer leitet den Wert jetzt je Frame aus dem Frame selbst ab: `deferred`
bleibt `deferred`, `direct` wird zu `recovered`. Damit ist er wieder das, was
Konzept 3.3.1 verlangt — „kein Schalter, sondern ein vom Sensor abgeleiteter
Tatsachenwert".

### Removed — `ids:sensor:spool:flush --deferred`

Die Option ließ die Betriebsseite den `dispatch_path` setzen, den Konzept 3.3.1
ausdrücklich für nicht setzbar erklärt — und zwar in die günstige Richtung. Sie
ist mit der Ableitung oben überflüssig; ein einzelner Wert für einen ganzen Lauf
wäre für mindestens einen der beiden Fälle ohnehin falsch. Wer sie im cron
stehen hat, entfernt sie ersatzlos; das Verhalten wird dadurch korrekter, nicht
schlechter.

### Fixed — viele Rechteprüfungen verdrängten den Statuscode

`Sensor\CaptureBudget::guardMandatory()` begründet sich wörtlich damit, dass „mit
`kernel.response` der Statuscode verloren ginge — das wichtigste Einzelfeld
überhaupt". `Sensor\EventBuffer` kannte diesen Unterschied aber nicht: dort
zählte jedes Event gleich. Mit den Vorgaben `budget.max_events_per_request: 64`
und `layers.security.max_decisions_per_request: 200` genügte eine
Übersichtsseite mit 64 Rechteprüfungen — der ResponseSensor läuft bei Priorität
−2048 zuletzt und fand den Puffer voll. Verloren waren damit `http_status`, die
Severity-Ableitung und das gesamte `raw` des Austauschs.

Der Puffer hat jetzt zwei Aufnahmewege, passend zu den zwei Budgetwegen:
`append()` für Ereignisse, deren Anzahl nach oben offen ist, und
`appendMandatory()` mit acht zusätzlichen Plätzen für die konstruktionsbedingt
begrenzten. Auch die Reserve ist endlich; was darüber hinausgeht, wird verworfen
und als `dropped_buffer_full` gezählt.

### Fixed — ein Angreifer konnte seinen eigenen Verkehr aus der Erfassung nehmen

Zwei Aufrufe im Erfassungspfad werfen bei Eingaben, die der Client frei wählt.
Beide standen VOR dem Puffern, beide Würfe schluckte `CaptureBudget::guard*()`
wie vorgesehen — und übrig blieb nichts: kein Event, kein Zähler, kein
Logeintrag. Genau der gezielt auslösbare blinde Fleck, den Konzept 2.1
ausschließen will.

- **`X-HTTP-Method-Override`.** `Request::getMethod()` liest den Header
  bedingungslos und wirft `SuspiciousOperationException`, sobald der Wert nicht
  nur aus Großbuchstaben besteht. Ein `POST` mit `X-HTTP-Method-Override: fo-o`
  genügte, ohne jede Konfiguration. Da der Aufruf im Snapshot-Bau steht und der
  vor `registry->set()` läuft, kostete er die gesamte Erfassung der Anfrage —
  auch die Folge-Events fanden danach keinen Snapshot mehr. Erfasst wird jetzt
  die tatsächlich gesendete Methode (`getRealMethod()`); für die Erkennung ist
  das ohnehin die ehrlichere Angabe.
- **Widersprüchliche Proxy-Header.** `Request::getClientIps()` wirft
  `ConflictingHeadersException`, wenn bei gesetztem `framework.trusted_proxies`
  ein `Forwarded`- einem `X-Forwarded-For`-Header widerspricht. Das kostete
  `kernel.request` UND `kernel.response`. Rückfall ist jetzt `REMOTE_ADDR` — die
  tatsächliche Gegenstelle, unvollständig hinter einem Proxy, aber niemals
  gefälscht.

### Fixed — Events konnten die correlation_id einer fremden Anfrage tragen

`Sensor\Context\RequestSnapshotRegistry::get()` fiel auf den Snapshot des
Haupt-Requests zurück, wenn es zum angefragten Request keinen gab. Der Aufrufer
erbte damit dessen `correlationId`, `path`, `route`, `contentLength` und
`startedAt` — `elapsedMs()` rechnete gegen eine fremde Startzeit, und die Events
zweier verschiedener Anfragen hingen an derselben Spur. Genau die Verkettung, auf
der die Regeln X1–X4 aus Konzept 4.3.3 aufbauen.

`get()` liefert jetzt ausschließlich den Snapshot genau dieses Requests. Die
Sensoren kommen ohne aus: Pfad und Antwortgröße lesen sie dann unmittelbar aus
Request und Response, und `CapturedEventBinder` baut den Akteur weiterhin aus dem
Request — verloren geht nur, was ohne Snapshot tatsächlich unbekannt ist. Für den
einen Fall, in dem der Haupt-Request wirklich gemeint ist (Vererbung der
correlation_id an Sub-Requests), gibt es `mainSnapshot()`.

### Fixed — zwei Wege, auf denen eine Exception die Anwendung erreichen konnte

Beide verletzten die Grundsatzentscheidung aus Konzept 4.: „Eine Störung des IDS
darf die überwachte Anwendung unter keinen Umständen beeinträchtigen." Gefunden
bei einem Code-Check, nicht im Betrieb.

**1. Der Transport wurde in `kernel.terminate` gebaut.** Der `FlushListener`
entsteht erst, wenn das Ereignis feuert; sein Dienstgraph reicht über
`EventFlusher` und `FrameDispatcher` bis zum Messenger-Transport. Dessen Factory
wirft `No transport supports Messenger DSN …`, sobald keine Factory die DSN
unterstützt — der häufigste Fall ist ein fehlendes `symfony/redis-messenger`,
das dieses Bundle nur als Entwicklungsabhängigkeit führt. Der Wurf entstand beim
*Erzeugen* des Dienstes und damit außerhalb jedes `try/catch` im Sensor.

Neu: `DependencyInjection\Compiler\LazyTransportPass` baut den Transport des
Sensors lazy. Der Factory-Aufruf fällt damit auf den ersten `send()` und liegt
im abgesicherten Pfad: aus einem Absturz wird ein gezählter `ship_failed` und
ein Frame im Spool. Angefasst wird ausschließlich der eine Transport, auf den
`ids_sensor.transport` zeigt.

**2. `EventFlusher::flush()` konnte über den eigenen Fehlerpfad entweichen.**
Sein `finally`-Zweig ruft den `LatencyRecorder`, sein `catch`-Zweig den Logger.
Wirft eines von beidem — ein Monolog-StreamHandler auf voller Platte genügt —,
verließ die Exception `flush()`. `FlushListener::flushAndBeat()` fasst den Aufruf
jetzt in `try/catch`, so wie den Heartbeat daneben schon vorher.

### Fixed — verlorene Events ohne Zähler bei fehlendem Spool

`Delivery\Dispatch\FrameDispatcher` nahm den Spool als optionales Argument und
gab bei `null` stumm `0` zurück — ohne Zähler, direkt unter einem Docblock, der
das Gegenteil zusagt („Jeder verworfene oder verlorene Event wird gezählt",
Konzept 4.). Das Argument ist jetzt verpflichtend, aus demselben Grund, aus dem
`$runtime` es schon war: ein fehlendes Argument bedeutete die gefährliche
Richtung.

### Added — erste Tests für den Fehlerpfad selbst

`tests/Unit/Delivery/Dispatch/FlushListenerTest` (die Klasse hatte bisher keinen
eigenen Test) und
`ResilienceTest::testAnUnusableTransportDsnDoesNotReachTheApplication`. Beide
schlagen ohne die Korrekturen oben nachweislich fehl.

## [0.1.0] — 2026-08-15

Erste Ausgabe. Alles darunter ist die Entstehungsgeschichte: Das Bundle war bis
hierher ungetaggt, die „Breaking"-Einträge betrafen deshalb niemanden außerhalb
dieses Repositoriums. Sie stehen trotzdem vollständig da, weil sie erklären,
warum die Teile liegen, wo sie liegen. Ab dieser Ausgabe gilt Semver.

Eine `0.x`-Ausgabe: `Contract\*` ist tragfähig, aber noch nicht in fremden
Anwendungen erprobt. Bis `1.0.0` kann sich dort etwas ändern — dann mit
Eintrag hier.

`composer.json` trägt bewusst kein `version`-Feld; die Ausgabe steht am Tag.

**Die Paketgrenze bleibt unberührt.** `Delivery\Transport\MessageSerializer`
schreibt weiterhin `ids.event_batch` und `ids.heartbeat` und niemals einen
Klassennamen. Für Konsumenten des JSON ändert sich durch nichts hiervon etwas.

### Fixed — `projektmotor/ids-event-data` stand in `require-dev`

Bei der Ausgliederung landete die neue Abhängigkeit im falschen Block. Für die
Entwicklung fiel das nicht auf, weil dort ohnehin alles installiert wird; ein
Konsument mit `composer install --no-dev` dagegen bekam das Ereignisformat nicht
mitgeliefert. Die Kompilierung brach dann schon beim Aufbau des
Konfigurationsbaums ab, weil `ConfigurationTree` `Vocabulary\Environment` für
den Vorgabewert von `environment` auflöst — also lange bevor das erste Ereignis
entsteht.

Das Paket steht jetzt unter `require`. Ein Auftrag in der CI installiert ohne
Dev-Abhängigkeiten und bootet einen Kernel; damit kann derselbe Fehler nicht
zurückkehren. Die Testsuite konnte ihn seiner Natur nach nie sehen.

### Fixed — `prependExtension()` hängte unter Symfony 6.4 an, statt voranzustellen

`ContainerConfigurator::extension()` hat den dritten Parameter `$prepend` erst
ab Symfony 7.0. Unter 6.4 verschluckt PHP das überzählige Argument
stillschweigend — kein Fehler, aber die Transportkonfiguration wurde angehängt
statt vorangestellt. Damit hätte das Bundle ausdrückliche `framework.messenger`-
Angaben der Anwendung überschrieben, also genau die Rangfolge, die der Docblock
ausschließt.

Ersetzt durch `ContainerBuilder::prependExtensionConfig()`, das es in beiden
Zweigen unverändert gibt. Der kompilierte Container unter Symfony 7 bleibt
identisch — die 15 Abdrücke laufen unverändert durch.

### Changed — `symfony/security-core` ausdrücklich auf `^6.4|^7.0`

Ein `composer update` zog über `symfony/security-bundle` bisher
`symfony/security-core` in Version 8 herein, also über die deklarierte Spanne
hinaus. Dort hat `Voter::voteOnAttribute()` einen vierten Parameter bekommen,
woran die Testvorrichtung zerbrach. Die Dev-Umgebung bleibt jetzt in der Spanne,
die das Bundle zusagt.

### Added — Continuous Integration und die Gruppe `fingerprint`

`.github/workflows/ci.yml` fährt beide Ränder des Constraints (PHP 8.2 mit
Symfony 6.4 `--prefer-lowest`, PHP 8.4 mit dem jeweils Neuesten) sowie den
Standardfall, dazu Redis mit der produktiven ACL, PHPStan, Coding Standards und
den Installierbarkeitsauftrag von oben.

`ContainerFingerprintTest::testTheContainerMatchesTheFingerprint()` trägt neu
`#[Group('fingerprint')]` und wird auf dem unteren Zweig ausgelassen. Der Abdruck
hält den gesamten Container fest, also auch Symfonys eigene Dienste, und die
unterscheiden sich zwischen 6.4 und 7. Die Alternative wäre ein zweiter Satz
Referenzdateien gewesen — doppelte Pflege für eine Zusage über die eigene
Verdrahtung. Dass diese unter 6.4 trägt, sichern die übrigen Integrationstests.

### Removed — `tree.php`, und `CLAUDE.md` reist nicht mehr mit

Eine leere, versehentlich eingecheckte Datei im Repo-Stamm ist entfernt.
`CLAUDE.md` ist jetzt `export-ignore`d: die Datei richtet sich an die
Entwicklung dieses Bundles und hat im Dist-Archiv nichts zu suchen. Der
Kommentar in `.gitattributes` nannte außerdem ein `resources/`-Verzeichnis, das
es nicht gibt.

### Breaking — `EventFormat\` ist ein eigenes Paket

`src/EventFormat/` ist nach
[`projektmotor/ids-event-data`](https://github.com/projektmotor/ids-event-data)
ausgezogen und dort als `0.1.0` getaggt. Das Bundle konsumiert es jetzt als
gewöhnliche Abhängigkeit.

**Warum.** Das Format ist der Vertrag zwischen zwei Paketen: dieses Bundle
schreibt es, das IdsBackendBundle liest es. Solange es hier lag, hätte das
Backend Symfony Messenger, HttpFoundation und Redis mitziehen müssen, nur um
drei Enums zu kennen.

Die Ausgliederung war vorbereitet und kostete deshalb nichts:
`src/EventFormat/` importierte per Test nichts Fremdes — weder aus dem Bundle
noch aus Symfony, in `use`-Zeilen wie in Docblocks. Der Umbau war ein
Verzeichnis-Move plus Namensraum-Ersetzung, wie in `doc/struktur.md`
angekündigt.

**Der Namensraum verkürzt sich doppelt:**

```
ProjektMotor\IdsSensor\EventFormat\   →   ProjektMotor\IdsEventData\
```

`IdsSensor` fällt weg, weil das Paket keinem der beiden Konsumenten gehört; die
Zwischenebene `EventFormat\` fällt weg, weil der Paketname sie bereits sagt. Die
vier Untergruppen `Frame/`, `Event/`, `Payload/` und `Vocabulary/` bleiben
unverändert.

| vorher | jetzt |
|---|---|
| `EventFormat\Event\NormalizedEvent` | `ProjektMotor\IdsEventData\Event\NormalizedEvent` |
| `EventFormat\Frame\Frame` | `ProjektMotor\IdsEventData\Frame\Frame` |
| `EventFormat\Payload\KernelPayload` | `ProjektMotor\IdsEventData\Payload\KernelPayload` |
| `EventFormat\Vocabulary\Severity` | `ProjektMotor\IdsEventData\Vocabulary\Severity` |

**Am JSON ändert sich nichts.** `schema_version` bleibt `1`, `v` im Frame bleibt
`1`, kein Feldname und kein Enum-Wert wurde angefasst. Belegt durch die 14
Container-Fingerprints unter `tests/Fixtures/container-fingerprints/` und durch
`tests/Functional/RedisStreamTest.php`, die beide unverändert durchlaufen.

### Changed — `ArchitectureTest` ohne EventFormat

`testEventFormatImportsNothingForeign()` ist entfallen; die Zusage lebt im neuen
Paket als `ArchitectureTest::testImportsNothingForeign()` weiter, dort zusätzlich
gegen Symfony und PSR geprüft. An ihre Stelle tritt
`testTheEventFormatStaysInItsOwnPackage()` — die Gegenrichtung: das Bundle darf
das Format nicht zurückholen, denn eine zweite Fassung des Drahtformats fiele
erst beim Collector auf.

`testOnlyContractAndEventFormatArePublic()` heißt jetzt
`testOnlyContractIsPublic()`, und die vier `EventFormat/*`-Einträge sind aus der
Rangfolge verschwunden. Ein Fremdpaket gehört nicht in die Schichtungstabelle —
es importiert seinerseits nichts und liegt damit per Konstruktion unter allem.

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
