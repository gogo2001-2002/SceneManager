# SceneManager für IP-Symcon

Der Szenen-Manager speichert jede Szene als eigenständige Kombination aus Symcon-Variablen und Zielwerten. Geräte müssen nicht mehr zuvor in einer globalen Modulliste gepflegt werden.

## Bedienung

1. Öffne die HTML-Kachel des Szenen-Managers in der Tile-Visualisierung oder über die WEBVISU.
2. Wähle **Neue Szene** und vergib einen Namen.
3. Suche in der Symcon-Variablenliste nach einem Gerät. Angeboten werden Variablen mit einer Symcon-Schaltaktion.
4. Füge die gewünschten Variablen hinzu, stelle für jede den Zielwert ein und speichere.
5. Beim Bearbeiten einer Szene kannst du weitere Variablen hinzufügen, Zielwerte ändern oder Variablen aus genau dieser Szene entfernen.
6. **Ausführen** schaltet nur die Geräte, die in dieser Szene gespeichert sind. **Live-Werte der Szenengeräte übernehmen** aktualisiert nur deren Zielwerte.

Die Kachelübersicht zeigt für jede Szene die zugehörigen Geräte und gespeicherten Werte.

## Profilvorgaben

Die Auswahlfelder verwenden das effektive Variablenprofil: ein benutzerdefiniertes Profil hat Vorrang vor dem Standardprofil. Grenzen, Schrittweiten und Auswahl-Assoziationen werden live aus Symcon geladen. Vor dem Speichern und vor jeder Geräteaktion validiert das Modul den Zielwert erneut serverseitig. Variablen mit schreibgeschützten Profilen werden nicht zur Auswahl angeboten.

## Migration von Version 1.0

Beim ersten Anwenden der neuen Modulversion werden bisherige Szene-Ziele aus der globalen Geräteliste in die einzelnen Szenen übertragen, soweit dafür gespeicherte Zielwerte vorhanden sind. Die alte Geräteliste wird anschließend geleert. Die Szenen und ihre IDs bleiben erhalten.

## Funktionen für Skripte

```php
// Szene mit ID 1 ausführen
SM_ExecuteScene($instanceID, 1);

// Live-Werte der in Szene 1 enthaltenen Variablen übernehmen
SM_SaveScene($instanceID, 1);

// Szenen und aktuell verwendete Variablen
$scenes = SM_GetScenes($instanceID);
$devices = SM_GetDevices($instanceID);

// Suchbare Symcon-Variablen mit Schaltaktion
$matches = SM_SearchDevices($instanceID, 'Büro Rollo');

// Vollständiger Zustand für eine eigene Visualisierung
$state = SM_GetTileState($instanceID);

// Zielwert für eine konkrete Szene und Symcon-Variablen-ID setzen
SM_SetSceneDeviceValue($instanceID, 1, 58440, 50, true);

// Die Variable aus genau dieser Szene entfernen
SM_SetSceneDeviceValue($instanceID, 1, 58440, 0, false);
```

## Installation

Das Modul ist ein IP-Symcon Eigenmodul. Lege eine Instanz an, konfiguriere Szenenname und Icon und öffne danach die HTML-Kachel. Die Gerätezuordnung und Zielwerte pflegst du ausschließlich innerhalb der jeweiligen Szene.
