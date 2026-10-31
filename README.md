# Mammotion Luba (IP-Symcon)

Symcon-Modul fuer luba-control (selbst gehosteter Docker-Dienst auf Basis von PyMammotion): Luba/Yuka-Maehroboter per MQTT steuern,
Status in Echtzeit, Push-Meldungen (steckt fest, Fehler, Akku leer ...).

## Voraussetzungen

1. In Symcon eine Instanz **MQTT Server** (Port 1883) anlegen, oder einen **MQTT Client** auf einen vorhandenen Broker.
2. In der `.env` von luba-control `MQTT_HOST` (+ ggf. `MQTT_USER`/`MQTT_PASSWORD`) auf diesen Server/Broker setzen,
   Container neu starten. Im Log: `MQTT connected to ...`.

## Installation

Ordner nach `modules/` kopieren (macOS: `/Library/Application Support/Symcon/modules/`, Docker/Linux:
`/var/lib/symcon/modules/`), Symcon-Dienst neu starten. Oder in der Modulverwaltung die Git-URL eintragen.

Instanz **Mammotion Luba** anlegen, als uebergeordnete Instanz den MQTT Server/Client waehlen.

| Einstellung | Bedeutung |
|---|---|
| Topic | `MQTT_PREFIX/Bluetooth-Name`, z.B. `luba/Luba-VSXXXXXX` (steht in der Web-UI bzw. im MQTT-Server unter den Topics) |
| Push-Meldungen an | Kachel-Visualisierung oder WebFront; leer = nur Variable "Letzte Meldung" |

## Variablen

| Ident | Typ | schaltbar | Inhalt |
|---|---|---|---|
| Action | int | ja | Pause, Fortsetzen, Zur Station, Beenden, Station verlassen, Aufwecken |
| Task | int | ja | in der App gespeicherte Aufgabe starten (Liste kommt aus luba-control) |
| Activity | int | | Bereit, Maeht, Pausiert, Faehrt zurueck, In der Station, ... Offline |
| Online, Charging | bool | | |
| Battery, Progress | int | | % |
| Error, Message | string | | aktueller Fehler, letzte Push-Meldung |
| BladeHeight | int | ja | Schnitthoehe mm (Bereich je Modell) |
| Speed | float | ja | Tempo m/s; wird nicht zurueckgemeldet, zeigt den zuletzt gesetzten Wert |
| Rain, Headlight, NightLight, SideLed | bool | ja | Regensensor, Licht |
| Latitude, Longitude | float | | RTK-Position |

## Funktionen fuer Skripte

```php
LUBA_SendCommand($id, 'dock');               // alle Befehle aus der luba-control README
LUBA_SendCommand($id, 'blade_height 50');
LUBA_SendCommand($id, 'start_task <plan_id>');
```

Die Antwort kommt asynchron auf `cmd/result`; Fehler erscheinen im Meldungsfenster.

## Test ohne Symcon

```bash
php -d zend.assertions=1 -d assert.exception=1 test/live.php
```

Spielt MQTT-Nachrichten wie von luba-control ein und prueft Variablen und gesendete Befehle.
