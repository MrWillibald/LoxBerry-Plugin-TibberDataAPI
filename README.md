# LoxBerry-Plugin „Tibber Data API“

Das Plugin liest alle Geräte aus der Tibber Data API aus: E-Autos (SoC, Reichweite, Ladelimit, Lade- und Steckerstatus), Wallboxen, Wärmepumpen bzw. Thermostate, Wechselrichter und Heimspeicher. Die Werte gehen per MQTT an den MQTT Gateway und von dort weiter an den Loxone Miniserver.

Voraussetzung ist LoxBerry 3.0 oder neuer (getestet mit LoxBerry 4.0). Zusätzliche Pakete braucht das Plugin nicht.

Das Plugin ist noch in der **Beta-Phase**. Beschreibung und Diskussion: [LoxBerry-Wiki](https://wiki.loxberry.de/plugins/tibberdata/start).

## Installation

1. Die ZIP-Datei des aktuellen Releases von der [Release-Seite](https://github.com/MrWillibald/LoxBerry-Plugin-TibberDataAPI/releases) herunterladen.
2. In LoxBerry **Plugin Management → Plugin installieren** öffnen und die ZIP-Datei hochladen.
3. Das Plugin öffnen. Im Reiter **Einstellungen** steht in der aufklappbaren Hilfe, wie der OAuth-Client bei Tibber angelegt wird:
   - https://data-api.tibber.com/clients/manage aufrufen
   - als Redirect URI `http://localhost:8080/callback` eintragen
   - alle Scopes anhaken
4. Client-ID und Client-Secret eintragen und speichern.
5. Im Reiter **Übersicht** auf **Mit Tibber verbinden** klicken, dann **Tibber-Anmeldung öffnen**. Anmelden und zustimmen.
6. Der Browser landet anschließend auf einer Fehlerseite unter `localhost:8080`. Das ist normal. Die komplette Adresse aus der Adresszeile kopieren, im Plugin einfügen und **Anmeldung abschließen** klicken.

Danach fragt das Plugin automatisch im eingestellten Intervall ab (Standard: 5 Minuten).

## In Loxone verwenden

Das Plugin meldet das Topic `tibberdata/#` automatisch beim MQTT Gateway an. Eine manuelle Subscription ist nicht nötig.

Die Schaltfläche **Werte & Loxone-Namen** im Reiter **Übersicht** listet alle Werte mit dem passenden Namen für Loxone auf, zum Beispiel `tibberdata_device_ID_3_storage_stateOfCharge`. Diesen Namen trägst du in der Loxone Config bei einem Virtuellen Eingang ein (für Zahlen) bzw. bei einem Virtuellen Texteingang (für Texte).

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

## Updates

Das Plugin unterstützt die automatischen Plugin-Updates von LoxBerry. In der Plugin-Verwaltung lässt sich pro Plugin einstellen, ob LoxBerry nur benachrichtigt oder Releases bzw. Pre-Releases selbst installiert.

## Neue Version veröffentlichen (für Entwickler)

1. Version in `plugin.cfg` und `bin/tibber_data_mqtt.py` erhöhen und committen.
2. `./build.sh` ausführen. Die ZIP liegt danach unter `dist/TibberDataAPI-<Version>.zip`.
3. Ein GitHub-Release mit dem Tag `v<Version>` anlegen und die ZIP anhängen.
4. `VERSION`, `ARCHIVEURL` und `INFOURL` in `prerelease.cfg` (Beta) bzw. `release.cfg` (stabil) anpassen und pushen. Ab dann bieten die LoxBerrys das Update an.
5. Version im LoxBerry-Wiki nachtragen.

## Lizenz

Apache License 2.0, siehe [LICENSE](LICENSE).
