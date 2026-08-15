# 09 — Die Business-Ebene anbinden

Die einzige Signalklasse für **erfolgreiche** Angriffe — und die einzige, die
Anwendungscode verlangt. Warum das so ist, steht in
[02 — Beobachtungsebenen](02-beobachtungsebenen.md#die-grenze-die-keine-konfiguration-verschiebt).

Der Aufwand ist in allen Varianten eine Zeile pro Vorfall. Die Frage ist nur, welche.

## Die Event-Klasse

In allen drei Wegen dieselbe. Sie implementiert `Contract\SecurityRelevantBusinessEvent`:

```php
use ProjektMotor\IdsSensor\Contract\SecurityRelevantBusinessEvent;
use ProjektMotor\IdsEventData\Vocabulary\Severity;

final class OrderAmountOverridden implements SecurityRelevantBusinessEvent
{
    public function __construct(
        private readonly int $orderId,
        private readonly string $actorId,
        private readonly float $originalAmount,
        private readonly float $newAmount,
    ) {
    }

    public function getEventName(): string    { return 'order.amount_overridden'; }
    public function getSeverityHint(): string { return Severity::Warning->value; }
    public function getActorId(): ?string     { return $this->actorId; }

    public function getPayload(): array
    {
        return [
            'order_id' => $this->orderId,
            'original_amount' => $this->originalAmount,
            'new_amount' => $this->newAmount,
        ];
    }
}
```

`getSeverityHint()` gibt bewusst `string` zurück und nicht das Enum. So sind Implementierer
nicht gezwungen, `IdsEventData\Vocabulary\Severity` zu importieren, und eine spätere
Verengung wird kein BC-Bruch. Das Enum ist reiner Komfort:
`return Severity::Critical->value;`

## Weg 1: `dispatcher` — die Vorgabe

Die Anwendung dispatcht ihr Domain-Event wie gewohnt und enthält **keine IDS-Referenz**.
Der Sensor hört am dekorierten `event_dispatcher` mit.

```php
$this->eventDispatcher->dispatch(new OrderAmountOverridden(...));
```

Keine Konfiguration nötig — das ist die Vorgabe.

## Weg 2: `recorder` — die Anwendung übergibt aktiv

Der Einstieg für bestehenden Code, der noch keine Domain-Events dispatcht.

```yaml
ids_sensor:
    layers:
        business:
            capture_mode: recorder
```

```php
use ProjektMotor\IdsSensor\Contract\BusinessEventRecorderInterface;

public function __construct(private readonly BusinessEventRecorderInterface $ids) {}

$this->ids->record(new OrderAmountOverridden(...));
```

## Weg 3: `configured` — ausdrückliche Liste

Für Deployments, die eine Dekoration von `event_dispatcher` ablehnen. Listener werden aus
einer Liste registriert.

```yaml
ids_sensor:
    layers:
        business:
            capture_mode: configured
            event_classes:
                - 'App\Event\OrderAmountOverridden'
```

## Welchen Weg wählen?

| | `recorder` | `dispatcher` |
|---|---|---|
| Aufrufrichtung | Anwendung → IDS | IDS beobachtet den Durchfluss |
| Fachlogik kennt das IDS | ja | **nein** |
| Bundle entfernen | Anwendung bricht | Anwendung läuft weiter |
| „Melden vergessen" | möglich | unmöglich, wenn dispatcht wird |
| Event anderweitig nutzbar | nein | ja, echtes Domain-Event |
| Im Review greppbar | ja | nein, nur das Interface |

`dispatcher` ist Vorgabe, weil Geschäftscode frei von IDS-Referenzen bleibt und das Bundle
rückstandslos entfernbar ist. `recorder` gewinnt genau dann, wenn Sichtbarkeit im Review
wichtiger ist als Entkopplung.

Der im Konzept (*2.1.3*) genannte Weg über Interface-Tagging ist **nicht** umsetzbar:
Symfonys `EventDispatcher` löst Listener über den exakten Event-Namen auf, nicht über
implementierte Interfaces. Daher die drei Wege statt des einen.

## Zwei Verhaltensweisen, die überraschen können

**Ein ungültiger `getSeverityHint()` führt nicht zu einer Exception, sondern zu
`warning`.** Nicht zu `info` — ein Tippfehler verschöbe das Event sonst still in die
kürzere Aufbewahrung. Der Originalwert bleibt in `raw` sichtbar.

**`payload`-Schlüssel mit dem Präfix `_ids_` sind reserviert und werden entfernt.**

## Der Payload

Für die Business-Ebene gibt es bewusst **keine** feste Struktur (*3.1.3*) — was ein Vorfall
bedeutet, weiß nur die Anwendung. Vor der Übertragung läuft der Payload durch zwei
Stufen:

| Stufe | Klasse | Was passiert |
|---|---|---|
| Form | `Processing\Normalization\PayloadSanitizer` | Objekte, Enums und `DateTime` werden zu Skalaren; Tiefe ≤ 3, ≤ 100 Elemente, Strings ≤ 2048 Zeichen |
| Vertraulichkeit | `PayloadConfidentialityCleanup\Cleaner` | Werte sensibler Feldnamen werden `[confidential]` |

Auch der Business-Payload läuft also durch dieselbe Denylist wie alles andere — siehe
[06 — Vertraulichkeit](06-vertraulichkeit.md#vier-eintrittspunkte-eine-liste).

## Ergänzte Felder

`actor.user` und `actor.ip` müssen nicht selbst befüllt werden:

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `user_from_token` | `true` | ergänzt `actor.user` aus dem Security-Token, wenn `getActorId()` `null` liefert |
| `ip_from_request` | `true` | ergänzt `actor.ip` aus dem laufenden Request |

(*2.2.4*) sieht für `actor.ip` bei Business-Events `null` vor — das ist für den Worker-Fall
gedacht. Im Request-Fall würde das Unterdrücken einer vorhandenen IP die Korrelationsregel
X3 unnötig schwächen, deshalb ist `ip_from_request` voreingestellt.

## Auch außerhalb des Requests

Business-Events entstehen häufig in Messenger-Workern und Konsolenbefehlen, wo nie ein
`kernel.terminate` feuert. Der Versand ist dort trotzdem sichergestellt — siehe
[04 — Request-Lebenszyklus](04-request-lebenszyklus.md#wo-sonst-noch-geflusht-wird).
