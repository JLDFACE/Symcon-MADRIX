# FACE MADRIX (IP-Symcon)

Dieses Repository enthält Module zur Steuerung von MADRIX-Instanzen über Remote HTTP als Zentral-Instanz + Devices.

## Module

### 1) MADRIX Controller (Zentrale Instanz)
- Host/Port + optional BasicAuth
- Online + LastError (Diagnose)
- Polling (Slow/Fast + FastAfterChange)
- Auto-Anlage (beim Hinzufügen/Apply):
  - Kategorie "Devices"
  - MADRIX Master Device
  - MADRIX Deck A Device
  - MADRIX Deck B Device

### 2) MADRIX Master (Device)
- Master (0..255), Blackout
- Groups: automatisch angelegt, Name aus MADRIX, Intensity (0..255)
- Global Colors: konfigurierbare Liste (ID + Name), pro ID eine ~HexColor Variable

### 3) MADRIX Deck (Device)
- Deck A/B + Storage in der Device-Konfiguration
- Place (int): Variablenname dynamisch, z. B. `Deck A Place: 1 "Intro"`
- Speed (float): -10..10

## Polling / Performance

- PollSlow (Default 15s): kompletter Status (Master, Decks, alle Groups, alle Global Colors)
- PollFast (Default 2s): nur Master + Decks + Pending-Items
  - Groups/Colors werden im Fast-Poll nur für aktuell pending IDs abgefragt
- FastAfterChange: nach Actions oder erkannten Änderungen für X Sekunden im Fast-Poll

## Installation

1. Module Control → Repository hinzufügen:
   - https://github.com/JLDFACE/Symcon-MADRIX
2. Instanz "MADRIX Controller" erstellen, Host/Port konfigurieren
3. Devices werden automatisch unterhalb der Controller-Instanz angelegt (Kategorie "Devices")

## Hinweise

- Remote HTTP wird per kurzlebigen HTTP-Requests genutzt (kein IO-Modul erforderlich).
- UI-Stabilität: Pending-Logik verhindert Flippen nach Sollwert-Änderungen.
