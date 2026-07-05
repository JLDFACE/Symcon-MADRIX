# MADRIX Watchdog

Startet MADRIX auf dem Windows-PC nach **Absturz** oder **Freeze** automatisch neu.

## Warum lokal auf dem PC (und kein Dienst)?

Symcon (SymBox/Linux) kann per MADRIX-HTTP-Remote zwar *erkennen*, dass MADRIX nicht
mehr antwortet, aber einen abgestürzten Windows-Prozess **nicht aus der Ferne neu
starten**. Deshalb besteht die Lösung aus zwei Teilen:

| Teil | Wo | Aufgabe |
|------|----|---------|
| **Watchdog** (dieser Ordner) | Madrix-PC | erkennt Absturz/Freeze und startet MADRIX neu |
| **MADRIX-Controller-Modul** | Symcon | überwacht Online-Status und **meldet** Ausfälle/Recovery |

**Kein Windows-Dienst:** MADRIX ist eine GUI-Anwendung. Dienste laufen in „Session 0"
ohne Desktop und können MADRIX nicht sichtbar/bedienbar starten. Der Watchdog läuft
deshalb in der **interaktiven Benutzersitzung** und wird per Autostart-Aufgabe
gestartet. Genau das erledigt der Installer.

## Schnellstart (empfohlen)

1. Diesen `tools`-Ordner auf den Madrix-PC kopieren (OneDrive/USB/GitHub).
2. **`Install.cmd` doppelklicken.** Der Installer holt sich Adminrechte, kopiert alles
   nach `C:\MadrixWatchdog`, legt die Autostart-Aufgabe *„MADRIX Watchdog"* an und
   öffnet die Konfigdatei.
3. In der geöffneten **`madrix-watchdog.config.json`** anpassen und speichern:
   - `MadrixExe` → Pfad zur `madrix.exe`
   - `HttpPort` / `HttpUser` / `HttpPass` → passend zum MADRIX-HTTP-Remote
     (oder `"UseHttpCheck": false`, wenn du HTTP nicht nutzt → Freeze wird dann nur
     über die Fensterreaktion erkannt)
4. Auf die Frage **„Watchdog jetzt starten?"** mit *J* antworten. Fertig.

Deinstallieren: **`Uninstall.cmd`** (entfernt nur die Aufgabe, Ordner bleibt).

## Als echte .exe (optional)

Wer statt der PS1 eine `MadrixWatchdog.exe` möchte:

1. Einmalig auf einem Windows-PC im `tools`-Ordner `build-exe.ps1` ausführen
   (installiert bei Bedarf das Modul *ps2exe* und erzeugt `MadrixWatchdog.exe`).
2. Danach `Install.cmd` ausführen – der Installer erkennt die EXE und nutzt sie
   automatisch für die Aufgabe.

Die EXE liest weiterhin `madrix-watchdog.config.json` aus ihrem Ordner – Konfiguration
bleibt also extern, **kein Neu-Kompilieren** bei Änderungen.

## Konfiguration (`madrix-watchdog.config.json`)

| Schlüssel | Bedeutung | Standard |
|-----------|-----------|----------|
| `MadrixExe` | Pfad zur `madrix.exe` | `C:\Program Files\MADRIX 5\madrix.exe` |
| `ProcessName` | Prozessname ohne `.exe` | `madrix` |
| `UseHttpCheck` | HTTP-Health-Check nutzen (erkennt auch Freezes) | `true` |
| `HttpHost` / `HttpPort` | MADRIX HTTP-Remote | `127.0.0.1` / `80` |
| `HttpUser` / `HttpPass` | Basic-Auth (leer = keine) | `""` |
| `HttpTimeoutSec` | Timeout HTTP-Check | `4` |
| `LoadSetupId` | Setup-Register-Nr., die nach Neustart per HTTP geladen wird (0 = aus) | `0` |
| `CheckIntervalSec` | Prüfintervall | `15` |
| `FailThreshold` | aufeinanderfolgende Fehler bis „Freeze" | `3` |
| `GraceSec` | Wartezeit nach Kill/Start (gegen Kill-Schleifen) | `30` |
| `StartupWaitSec` | max. Wartezeit bis MADRIX nach Start antwortet | `60` |
| `LogFile` | Logpfad (leer = `%ProgramData%\MadrixWatchdog\madrix-watchdog.log`) | `""` |

## Voraussetzungen in MADRIX / Windows

Damit nach einem Neustart die **Show sichtbar** ist (nicht nur der Prozess läuft):

1. **Windows-Auto-Login** aktiv (nach Reboot existiert sofort eine interaktive Session).
2. **MADRIX → Preferences → Startup:** gewünschtes Setup automatisch laden **und**
   Ausgabe (DMX/Art-Net) automatisch starten.
3. **MADRIX → Preferences → Remote Control → HTTP:** aktiviert und gespeichert
   (nur nötig bei `UseHttpCheck: true`).

## Funktionsweise

Alle `CheckIntervalSec` Sekunden:

- **Prozess weg** → sofort `madrix.exe` starten (Absturz).
- **Prozess da, reagiert nicht** (Fensterreaktion und/oder HTTP schlägt fehl) → erst
  nach `FailThreshold` Fehlern als Freeze werten, hart beenden, `GraceSec` warten,
  neu starten (entprellt gegen Kill-Restart-Schleifen).
- Nach dem Start bis `StartupWaitSec` auf HTTP-Erreichbarkeit warten und optional
  `LoadSetupId` laden.

## Manueller Test (ohne Autostart)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\MadrixWatchdog\madrix-watchdog.ps1"
```
Dann MADRIX im Task-Manager beenden → binnen `CheckIntervalSec` startet es neu.
Ereignisse: `%ProgramData%\MadrixWatchdog\madrix-watchdog.log`.

## Zusammenspiel mit Symcon

Das MADRIX-Controller-Modul führt eine Statusvariable **`Online`** und ruft bei jedem
echten Statuswechsel (online↔offline, entprellt über *OfflineThreshold*) ein optionales
**Benachrichtigungs-Skript** auf (Instanz-Property *NotifyScriptID*, erhält
`$_IPS['Online']` und `$_IPS['Message']`). So läuft der Neustart lokal, während Symcon
zentral protokolliert und alarmiert.

## Dateien

| Datei | Zweck |
|-------|-------|
| `Install.cmd` | Einrichtung per Doppelklick (elevated) |
| `Install.ps1` | wird von Install.cmd aufgerufen (Kopieren + Aufgabe) |
| `Uninstall.cmd` | Autostart-Aufgabe entfernen |
| `madrix-watchdog.ps1` | der Watchdog |
| `madrix-watchdog.config.json` | **die einzige Datei, die du anpasst** |
| `build-exe.ps1` | optional: EXE bauen (ps2exe) |
| `madrix-watchdog-task.xml` | manuelle Aufgabenplanung-Importvorlage (Alternative zum Installer) |
