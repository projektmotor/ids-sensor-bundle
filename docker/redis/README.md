# Redis-ACL für die funktionalen Tests

`users.acl` setzt die asymmetrische Rechteverteilung aus Konzept 2. („Warum das
IdsSensorBundle keinen Datenbankzugriff erhalten darf") nach: die überwachte
Anwendung darf ausschließlich schreiben.

Die Erklärung steht hier und nicht in der Datei selbst, weil **Redis im ACL-Format
keine Kommentare erlaubt** — jede Zeile muss mit `user` beginnen, sonst bricht der
Server beim Start ab.

## Die beiden Nutzer

| Nutzer | Rolle im Test | Rechte |
|---|---|---|
| `default` | spielt den Collector: Stream und Consumer-Gruppe anlegen, auslesen | unbeschränkt |
| `ids_sensor` | der Sensor in der überwachten Anwendung | **nur** `XADD` |

```
user ids_sensor on >sensor-geheim resetkeys resetchannels -@all ~ids:events:* +xadd +ping +client|setinfo
```

- `resetkeys resetchannels -@all` — erst alles entziehen, dann einzeln erteilen.
- `~ids:events:*` — nur diese Schlüssel.
- `+xadd` — schreiben, und nichts sonst.
- `+ping` — Verbindungsprüfung.
- `+client|setinfo` — aktuelle phpredis-Versionen melden beim Verbinden Lib-Name und
  -Version. Ohne dieses Recht scheitert oder protokolliert der Verbindungsaufbau,
  abhängig von der Version. Ein Detail, das genau in einer gehärteten Umgebung
  auffällt und sonst nirgends.

**Ausdrücklich nicht erteilt:** `xgroup`, `xreadgroup`, `xread`, `xrange`, `xdel`,
`del`. Damit kann ein Angreifer in der Anwendung weder abgesendete Events löschen
noch die noch nicht konsumierten Events anderer Requests mitlesen.

## Warum der Test unter dieser ACL laufen muss

Unter einem unbeschränkten Redis-Nutzer funktioniert auch eine fehlerhafte
Konfiguration. Der wahrscheinlichste Erstinstallationsfehler ist `auto_setup: true`:
Messenger sendet dann beim ersten Zugriff `XGROUP CREATE ... MKSTREAM`. In der
Entwicklung fällt das nicht auf, in Produktion scheitert der erste Versand.

`RedisStreamTest` prüft beides — dass der Sensor mit `XADD` auskommt, und dass
`auto_setup: true` hier tatsächlich scheitert.
