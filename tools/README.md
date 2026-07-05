# MADRIX Watchdog

Startet MADRIX auf dem Windows-PC nach **Absturz** oder **Freeze** automatisch neu.

## Warum lokal auf dem PC?

Symcon (SymBox/Linux) kann per MADRIX-HTTP-Remote zwar *erkennen*, dass MADRIX nicht
mehr antwortet, aber einen abgestürzten Windows-Prozess **nicht aus der Ferne neu
starten**. Deshalb besteht die Lösung aus zwei Teilen:

| Teil | Wo | Aufgabe |
|------|----|---------|
| **`madrix-watchdog.ps1`** | Madrix-PC | erkennt Absturz/Freeze und startet MADRIX neu |
| **MADRIX-Controller-Modul** | Symcon | überwacht den Online-Status und **meldet** Ausfälle/Recovery |

Beide Teile arbeiten unabhängig – der PC-Watchdog funktioniert auch bei Netzausfall.

## Voraussetzungen auf dem Madrix-PC

Damit nach einem Neustart nicht nur der Prozess läuft, sondern die **Show wieder
sichtbar** ist, muss MADRIX sich selbst versorgen:

1. **Windows-Auto-Login** aktiv (nach Reboot existiert sofort eine interaktive Session).
2. **MADRIX → Preferences → Startup**: letztes/gewünschtes Setup automatisch laden **und**
   Ausgabe (DMX/Art-Net) automatisch starten.
3. **MADRIX → Preferences → Remote Control → HTTP**: aktiviert und gespeichert
   (überlebt den Neustart). Nur nötig, wenn der HTTP-Health-Check (`$UseHttpCheck`)
   genutzt wird. Ohne HTTP erkennt der Watchdog Freezes über `Process.Responding`.

## Installation

1. Ordner `C:\MadrixWatchdog\` anlegen und `madrix-watchdog.ps1` hineinkopieren.
2. Im Skript oben den **KONFIGURATION**-Block anpassen:
   - `$MadrixExe` → tatsächlicher Pfad zur `madrix.exe` (Version prüfen!)
   - `$HttpPort`, `$HttpUser`, `$HttpPass` → passend zur MADRIX-HTTP-Konfiguration
     (oder `$UseHttpCheck = $false`, wenn kein HTTP-Remote genutzt wird)
   - ggf. `$LoadSetupId`, Intervalle
3. Aufgabe anlegen (eine der beiden Varianten):

   **Variante A – XML importieren**
   - `madrix-watchdog-task.xml` nach `C:\MadrixWatchdog\` kopieren.
   - In der Datei `AUTOLOGIN_USER` durch den angemeldeten Benutzer ersetzen
     (oder nach dem Import unter *Allgemein → Benutzer/Gruppe ändern* setzen).
   - Aufgabenplanung → *Aufgabe importieren…* → Datei wählen. Passwort des Benutzers
     eingeben, wenn verlangt.

   **Variante B – PowerShell (als Administrator)**
   ```powershell
   $action  = New-ScheduledTaskAction -Execute "powershell.exe" `
       -Argument '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\MadrixWatchdog\madrix-watchdog.ps1"'
   $trigger = New-ScheduledTaskTrigger -AtLogOn
   $settings = New-ScheduledTaskSettingsSet -RestartInterval (New-TimeSpan -Minutes 1) `
       -RestartCount 999 -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
   Register-ScheduledTask -TaskName "MADRIX Watchdog" -Action $action -Trigger $trigger `
       -Settings $settings -RunLevel Highest -User $env:USERNAME
   ```

4. Aufgabe testweise starten oder einmal ab-/anmelden.

## Funktionsweise

Alle `$CheckIntervalSec` Sekunden:

- **Prozess weg** → sofort `madrix.exe` starten (Absturz).
- **Prozess da, reagiert nicht** (`Process.Responding` und/oder HTTP schlägt fehl)
  → erst nach `$FailThreshold` aufeinanderfolgenden Fehlern als Freeze werten,
  Prozess hart beenden, `$GraceSec` warten, neu starten (Entprellung gegen
  Kill-Restart-Schleifen).
- Nach dem Start wird bis `$StartupWaitSec` auf HTTP-Erreichbarkeit gewartet und
  optional `$LoadSetupId` per HTTP geladen.

Log: `%ProgramData%\MadrixWatchdog\madrix-watchdog.log` (einfache Rotation bei 2 MB).

## Zusammenspiel mit Symcon

Das MADRIX-Controller-Modul führt jetzt eine Statusvariable **`Online`** und ruft bei
jedem echten Statuswechsel (online↔offline, entprellt über *OfflineThreshold*) ein
optionales **Benachrichtigungs-Skript** auf (Instanz-Property *NotifyScriptID*).
Das Skript erhält `$_IPS['Online']` (bool) und `$_IPS['Message']` und kann daran
z. B. Push/Telegram/E-Mail hängen. So läuft der Neustart lokal, während Symcon
zentral protokolliert und alarmiert.
