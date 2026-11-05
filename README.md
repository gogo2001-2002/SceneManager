# SceneManager (Szenen-Manager für IP-Symcon)

Ein flexibles und intuitives Szenen-Steuerungs-Modul für [IP-Symcon](https://www.symcon.de/) (kompatibel mit Symcon 7.1, 8.x und 9.x) mit nativer Kachel-Visualisierung (HTML-SDK) und vollständiger Unterstützung für:

- 💡 **Licht**: An / Aus
- 🔌 **Schalten**: An / Aus (Steckdosen, Relais, Verbraucher)
- 🪟 **Rollo**: Hoch / Runter / Positionsangabe (0–100%)
- ❄️ **Klimaanlage**: An / Aus

Jede Szene erhält automatisch eine eigene **Boolean-Statusvariable**, mit der die Szene direkt per Klick in der Visu, per Script, Workflow (Ablaufplan) oder Sprachassistent (Alexa, Google Assistant, HomeKit) gestartet werden kann.

---

## 🌟 Funktionsumfang

1. **Szenen zusammenstellen & anpassen direkt in der Visu**:
   - **Teach-In per Knopfdruck**: Stelle deine Lichter, Rollos und Geräte im Raum wie gewünscht ein und klicke auf `💾 Zustand speichern` – die Werte werden sofort persistent in die Szene übernommen.
   - **Live-Editor in der Visu-Kachel**: Öffne den Detail-Editor direkt in der Visu (`⚙`), passe Geräte live an (Licht/Schalter/Klima Ein/Aus, Rollo-Slider & Schnellwahl 0%/50%/100%), deaktiviere einzelne Geräte aus der Szene und speichere die Anpassungen ab.
   - **Modus-Umschaltung**: Zwischen *Ausführen* und *Speichern* per Klick umschaltbar.

2. **Direktes Starten & Abrufen**:
   - **Dedizierte Boolean-Variable pro Szene**: Jede konfigurierte Szene erhält eine Variable (`Scene_<ID>`), die zum Starten dient.
   - **Aktive Szene**: Das Modul hebt die zuletzt ausgeführte Szene optisch hervor und aktualisiert die Statusvariable `Aktive Szene`.
   - **Optionale Speichern-Buttons**: Auf Wunsch kann für jede Szene auch eine dedizierte `Speichern`-Variable in der Baumstruktur angelegt werden.

3. **Moderne HTML-SDK Kachel für Kachel-Visualisierung (Tile Visu)**:
   - Vollständig integriert via IP-Symcon HTML-SDK (ab Symcon 7.1).
   - Automatische Erkennung von Light- und Darkmode.
   - Toast-Benachrichtigungen bei Ausführung oder Speicherung.

4. **Ausfallsicherheit & sanftes Schalten**:
   - Konfigurierbare Schaltverzögerung (in Millisekunden) zwischen einzelnen Schaltbefehlen, um Funkkollisionen (z. B. bei KNX-RF, ZigBee, Homematic) zu vermeiden.
   - Ein Ausfall eines einzelnen Geräts blockiert nicht die Ausführung der restlichen Szene (`try-catch` Absicherung).

---

## 📁 Repository-Struktur

```text
SceneManager/
├── library.json                 # Symcon Bibliotheks-Definition
├── README.md                    # Dokumentation
├── .gitignore
└── SceneManager/                # Das Modul
    ├── module.json              # Modul-Metadaten & GUIDs
    ├── module.php               # PHP-Modullogik
    ├── module.html              # HTML-SDK Kachel-Visualisierung
    ├── form.json                # Instanz-Konfigurationsformular
    └── locale.json              # Deutsche & englische Lokalisierung
```

---

## 🚀 Installation & Einrichtung

### 1. In IP-Symcon hinzufügen
Das Repository kann direkt in IP-Symcon über das **Module Control** eingebunden werden:
1. Öffne die Symcon Verwaltungskonsole.
2. Navigiere zu **Kern-Instanzen** -> **Modules**.
3. Klicke auf **Hinzufügen** (`+`) und gib die Repository-URL ein:
   `https://github.com/gogo2001-2002/SceneManager.git`

### 2. Instanz anlegen
1. Klicke im Objektbaum auf **Instanz hinzufügen** (`+`).
2. Suche nach `SceneManager` oder `Szenen-Manager`.
3. Gib der Instanz einen Namen (z. B. `Szenen Wohnzimmer`) und wähle die gewünschte Zielkategorie.

### 3. Konfiguration
Öffne die Instanz:
- **Geräte**: Füge beliebig viele Geräte hinzu:
  - *Name*: z. B. `Deckenlampe`, `TV Steckdose`, `Rollo Süd`
  - *Gerätetyp*: Wähle `Licht`, `Schalten`, `Rollo` oder `Klimaanlage`
  - *Zielvariable*: Wähle die entsprechende Statusvariable des Geräts
- **Szenen**: Lege deine Szenen an:
  - *ID*: Eindeutige Zahl (z. B. 1, 2, 3)
  - *Name*: z. B. `Kinoabend`, `Guten Morgen`, `Alles Aus`
  - *Icon*: z. B. `Film`, `Sun`, `Moon`, `Power`
  - *Speichern-Variable*: Optional aktivieren, um eine separate Variable zum Speichern in der Visu zu erhalten.
- **Optionen**:
  - *HTML-SDK Kachel aktivieren*: Aktiviert die interaktive Touch-Kachel für die Kachel-Visualisierung.
  - *Schaltverzögerung (ms)*: z. B. 50 ms.

Klicke auf **Änderungen übernehmen**.

---

## 📱 Bedienung in der Visualisierung

### Methode A: Über die interaktive HTML-Kachel
1. **Szene starten**: Klicke einfach auf `▶ Starten` bei der gewünschten Szene.
2. **Aktuelle Werte als Szene speichern (Teach-In)**:
   - Stelle im Raum deine gewünschte Beleuchtung, Rollo-Höhe und Klimaanlage ein.
   - Klicke in der Kachel auf das Disketten-Symbol `💾` der gewünschten Szene.
   - Fertig! Alle aktuellen Gerätezustände sind in der Szene gespeichert.
3. **Werte manuell bearbeiten**:
   - Klicke auf das Zahnrad `⚙`.
   - Passe Lichter, Schalter, Rollos (per Slider oder 0%/50%/100%) und Klimaanlage direkt an.
   - Klicke auf `💾 Szene speichern`.

### Methode B: Über Standard-Variablen (Tasten / Schalter)
- Für jede Szene existiert eine Boolean-Variable (`Scene_<ID>`).
- Ein Klick in der Visu auf die Variable (bzw. `RequestAction($variableID, true)`) ruft die Szene auf.
- Über den Schalter `Modus` (Ausführen / Speichern) kann die gesamte Kachel in den Speichermodus versetzt werden: Bei aktivem Speichermodus überschreibt ein Klick auf die Szenen-Taste die Szene mit den aktuellen Werten!

---

## 💻 PHP-Befehle für eigene Skripte & Automationen

Das Modul stellt folgende PHP-Funktionen bereit:

```php
// Szene mit ID 1 ausführen
SM_ExecuteScene($instanceID, 1);

// Aktuelle Werte aller konfigurierten Geräte in Szene 1 abspeichern (Teach-In)
SM_SaveScene($instanceID, 1);

// Alle konfigurierten Szenen abrufen
$scenes = SM_GetScenes($instanceID);

// Alle konfigurierten Geräte und deren Live-Werte abrufen
$devices = SM_GetDevices($instanceID);

// Gezielt einen Wert für ein Gerät in einer Szene setzen
// SM_SetSceneDeviceValue(int $InstanceID, int $SceneID, int $DeviceID, mixed $Value, bool $Enabled = true)
SM_SetSceneDeviceValue($instanceID, 1, 3, 50, true); // Setzt Rollo (ID 3) in Szene 1 auf 50%
```

---

## 📄 Lizenz
MIT License
