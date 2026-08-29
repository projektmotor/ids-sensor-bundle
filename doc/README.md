# Dokumentation des IdsSensorBundle

Diese Ebene erklärt die Kernkonzepte. Wer nur installieren und loslegen will, ist im
[README am Repo-Stamm](../README.md) besser aufgehoben — hier steht, *warum* das Bundle
tut, was es tut, und *wann* welcher Code läuft.

## Leseweg

Die Dateien bauen aufeinander auf. Wer sie der Reihe nach liest, hat danach ein
vollständiges Bild; wer eine bestimmte Frage hat, springt direkt.

| | Dokument | Beantwortet |
|---|---|---|
| 01 | [Überblick](01-ueberblick.md) | Was tut das Bundle, wo hört es auf, und was bedeutet „zwei Phasen"? |
| 02 | [Beobachtungsebenen](02-beobachtungsebenen.md) | Was sieht Kernel, was Security, was Business — und was sieht keine davon? |
| 03 | [Ereignisformat](03-ereignisformat.md) | Wie sieht ein Event aus, wie ein Frame, und was ist am Format verbindlich? |
| 04 | [Request-Lebenszyklus](04-request-lebenszyklus.md) | Welcher Hook feuert wann, was kostet das, und wie wird gesampelt? |
| 05 | [Versandweg](05-versandweg.md) | Broker oder Spool? Was macht der Circuit Breaker? |
| 06 | [Vertraulichkeit](06-vertraulichkeit.md) | Wo greift die Denylist, und was schützt sie nicht? |
| 07 | [Betrieb](07-betrieb.md) | Heartbeat, Verlustzähler, Broker-Rechte, Fehlersuche |
| 08 | [Konfiguration](08-konfiguration.md) | Vollständige Referenz aller `ids_sensor`-Schlüssel |
| 09 | [Business-Ebene](09-business-ebene.md) | Die drei Anbindungswege und wann welcher passt |

## Die drei Dokumentarten in diesem Ordner

Der Ordner enthält Dokumente mit verschiedenen Lesern. Wer das verwechselt, sucht an der
falschen Stelle:

| Dokument | Leser | Frage |
|---|---|---|
| `01`–`09` (diese Reihe) | wer das Bundle **benutzt** oder betreibt | Was tut es, und warum so? |
| [`concept/concept-v1.md`](concept/concept-v1.md) | wer beide Bundles **entwirft** | Was *soll* das System können? |
| [`concept/structure.md`](concept/structure.md) | wer am Bundle **mitarbeitet** | Wie ist `src/` geschnitten, und warum? |

[`concept/concept-v1.md`](concept/concept-v1.md) ist die Spezifikation für **beide** Bundles — also
inklusive Collector, Datenbankschema und Erkennungsregeln, die dieses Repository gar nicht
enthält. Die Reihe 01–09 beschreibt, was das Sensor-Bundle *tut*; das Konzept legt fest,
was es *soll*. Wo beide dasselbe beschreiben, gewinnt das Konzept — es ist die
gesicherte Fassung, auf die sich der Collector verlässt.

> **Seit dem 29.08.2026 gehen beide beim Transport auseinander, und das ist Absicht.** Das
> Konzept beschreibt den Versand per REST an den Collector (Abschnitt 3.6); die Reihe 01–09
> beschreibt den Redis-Streams-Transport, den der Quellcode tatsächlich ausliefert. Bis die
> Umsetzung folgt, ist beides an seiner Stelle richtig: Wer wissen will, was das Bundle
> *heute tut*, liest 05 und 07. Wer wissen will, wohin es geht, liest das Konzept.

Alle Abschnittsverweise in Klammern, etwa (*2.1*), beziehen sich auf `concept/concept-v1.md`.

## Zu den Diagrammen

Die Diagramme sind [Mermaid](https://mermaid.js.org/) und werden von GitHub direkt im
Markdown gerendert. Wer sie in einem Viewer ohne Mermaid-Unterstützung liest, sieht den
Quelltext — deshalb steht **unter jedem Diagramm ein Satz, der seine Aussage wiederholt**.
Kein Diagramm trägt Information, die nur im Bild steht.

Die Rollenfarben sind über alle Diagramme gleich:

| Farbe | Rolle |
|---|---|
| teal | Erfassung — läuft im Request, unter dem Latenzbudget |
| grau | Transport und Ausführung — läuft nach dem Absenden der Antwort |
| violett | Daten und Befunde — was übertragen oder gespeichert wird |
