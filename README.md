# LoxBerry-Plugin „Tibber Data API“

Das Plugin liest alle Geräte aus der Tibber Data API aus: E-Autos (SoC, Reichweite, Ladelimit, Lade- und Steckerstatus), Wallboxen, Wärmepumpen bzw. Thermostate, Wechselrichter und Heimspeicher. Die Werte gehen per MQTT an den MQTT Gateway und von dort weiter an den Loxone Miniserver.

Voraussetzung ist LoxBerry 3.0 oder neuer. Zusätzliche Pakete braucht das Plugin nicht.

## Installation

1. In LoxBerry **Plugin Management → Plugin installieren** öffnen und die ZIP-Datei hochladen.
2. Das Plugin öffnen. Unter „Einstellungen“ steht in der aufklappbaren Hilfe, wie der OAuth-Client bei Tibber angelegt wird:
   - https://data-api.tibber.com/clients/manage aufrufen
   - als Redirect URI `http://localhost:8080/callback` eintragen
   - alle Scopes anhaken
3. Client-ID und Client-Secret eintragen und speichern.
4. **Mit Tibber verbinden** klicken, dann **Tibber-Anmeldung öffnen**. Anmelden und zustimmen.
5. Der Browser landet anschließend auf einer Fehlerseite unter `localhost:8080`. Das ist normal. Die komplette Adresse aus der Adresszeile kopieren, im Plugin einfügen und **Anmeldung abschließen** klicken.

Danach fragt das Plugin automatisch im eingestellten Intervall ab (Standard: 5 Minuten).

## In Loxone verwenden

Das Plugin meldet das Topic `tibberdata/#` automatisch beim MQTT Gateway an. Eine manuelle Subscription ist nicht nötig.

Die Schaltfläche **Werte & Loxone-Namen anzeigen** listet alle Werte mit dem passenden Namen für Loxone auf, zum Beispiel `tibberdata_device_ID_3_storage_stateOfCharge`. Diesen Namen trägst du in der Loxone Config bei einem Virtuellen Eingang ein (für Zahlen) bzw. bei einem Virtuellen Texteingang (für Texte).

Nützliche Werte:

| Wert | Bedeutung |
|---|---|
| `…_storage_stateOfCharge` | SoC in % |
| `…_storage_targetStateOfCharge` | Ladelimit in % |
| `…_range_remaining_km` | Reichweite in km |
| `…_connector_status_num` | 1 = eingesteckt, 0 = nicht eingesteckt |
| `…_charging_status_num` | 1 = lädt, 0 = lädt nicht |
| `…_lastSeen_ageMin` | Alter der Daten bei Tibber in Minuten |
| `tibberdata_status_ok` | 1 = letzte Abfrage erfolgreich, 0 = Fehler |

Alle weiteren Capabilities, die Tibber für ein Gerät liefert, werden ebenfalls gesendet.

## Hinweise

- Bei manchen Marken aktualisiert Tibber den SoC nur, während das Auto lädt. Ob ein Wert aktuell ist, zeigt `lastSeen_ageMin`.
- Lehnt Tibber die Anmeldung irgendwann ab, zeigt das Plugin das an und `tibberdata_status_ok` wird 0. Dann einfach **Neu verbinden** klicken.
- Konfiguration und Tokens bleiben bei Plugin-Updates erhalten.
