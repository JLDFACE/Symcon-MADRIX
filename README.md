# Symcon-MADRIX (IP-Symcon)

Dieses Repository stellt IP-Symcon-Module zur Steuerung einer MADRIX-Instanz via **MADRIX Remote HTTP** bereit.

## Module

- **MADRIX Controller** (Zentrale Instanz)
  - Konfiguration: Host/Port/BasicAuth
  - Polling (Slow/Fast), FastAfterChange
  - Automatische Anlage von Devices (Master, Deck A, Deck B)
  - Place-Metadaten Cache (ThumbTimeStamp) + Bulk-Labeling für alle belegten Places (Scan)
- **MADRIX Master**
  - Master/Blackout
  - Groups (Intensity)
  - Global Colors (RGB)
  - Kategorien unter der Master-Instanz: `Groups`, `Global Colors`
- **MADRIX Deck**
  - Deck A / Deck B Place (Integer 1..256)
  - Deck A / Deck B Speed (-10..10)
  - Place-Profile-Beschriftung: `Place X "Name"` (Name aus MADRIX)

## Installation

1. IP-Symcon -> **Module Control** -> **Hinzufügen** -> GitHub URL eintragen:
   - https://github.com/JLDFACE/Symcon-MADRIX
2. Module installieren/aktualisieren.
3. Instanz **MADRIX Controller** anlegen und Host/Port konfigurieren.
4. Der Controller legt automatisch unterhalb eine Kategorie `Devices` an und darin:
   - `Master`
   - `Deck A`
   - `Deck B`

## Place-Namen / belegte Places

- Der Controller nutzt `GetStoragePlaceThumbTimeStamp=SxPy` als Änderungsindikator.
- Ein Scan der belegten Places kann über den Button in der Controller-Konfiguration ausgelöst werden.
  - Warnung: Der Scan iteriert Storages/Places (RemoteHTTP) und kann – abhängig von Belegung/Latenz – Sekunden bis Minuten dauern.
- Nach Scan werden alle **belegten** Places im Variablenprofil der Decks mit `Place X "Name"` beschriftet.

## Hinweise

- SymBox-sicher: keine strict types, keine PHP 8 Typen, keine globalen Funktionen außerhalb der Klassen.
- Fehler werden nicht als Fatal eskaliert; `Online` und `LastError` werden gepflegt.
