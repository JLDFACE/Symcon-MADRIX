<#
    MADRIX Watchdog - Einrichtung
    Wird von Install.cmd (mit Adminrechten) aufgerufen.

    - kopiert Watchdog + Konfig nach C:\MadrixWatchdog
    - legt die Autostart-Aufgabe "MADRIX Watchdog" an (Start bei Anmeldung)
    - oeffnet die Konfigdatei zum Anpassen
#>

$ErrorActionPreference = "Stop"

$Src      = $PSScriptRoot
$Target   = "C:\MadrixWatchdog"
$TaskName = "MADRIX Watchdog"

Write-Host "=== MADRIX Watchdog Einrichtung ===" -ForegroundColor Cyan
Write-Host "Quelle: $Src"
Write-Host "Ziel:   $Target"

# 1) Zielordner + Dateien
if (-not (Test-Path $Target)) { New-Item -ItemType Directory -Path $Target -Force | Out-Null }

# EXE bevorzugen, falls vorhanden (per build-exe.ps1 erzeugt), sonst PS1
$exeSrc = Join-Path $Src "MadrixWatchdog.exe"
$ps1Src = Join-Path $Src "madrix-watchdog.ps1"
$useExe = Test-Path $exeSrc

if ($useExe) {
    Copy-Item $exeSrc (Join-Path $Target "MadrixWatchdog.exe") -Force
    Write-Host "MadrixWatchdog.exe kopiert."
} else {
    Copy-Item $ps1Src (Join-Path $Target "madrix-watchdog.ps1") -Force
    Write-Host "madrix-watchdog.ps1 kopiert."
}

# Konfig NUR anlegen, wenn noch nicht vorhanden (bestehende nicht ueberschreiben)
$cfgTarget = Join-Path $Target "madrix-watchdog.config.json"
if (-not (Test-Path $cfgTarget)) {
    Copy-Item (Join-Path $Src "madrix-watchdog.config.json") $cfgTarget -Force
    Write-Host "Konfig-Vorlage kopiert: $cfgTarget"
} else {
    Write-Host "Bestehende Konfig beibehalten: $cfgTarget"
}

# 2) Aufgabe (Start bei Anmeldung des aktuellen Benutzers)
if ($useExe) {
    $action = New-ScheduledTaskAction -Execute (Join-Path $Target "MadrixWatchdog.exe") -WorkingDirectory $Target
} else {
    $action = New-ScheduledTaskAction -Execute "powershell.exe" `
        -Argument ('-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f (Join-Path $Target "madrix-watchdog.ps1")) `
        -WorkingDirectory $Target
}
$trigger  = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -RestartInterval (New-TimeSpan -Minutes 1) `
    -RestartCount 999 -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

$user = "$env:USERDOMAIN\$env:USERNAME"
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
    -RunLevel Highest -User $user -Force | Out-Null
Write-Host "Autostart-Aufgabe '$TaskName' angelegt (Benutzer: $user)." -ForegroundColor Green

# 3) Konfig zum Anpassen oeffnen
Write-Host ""
Write-Host "WICHTIG - jetzt anpassen:" -ForegroundColor Yellow
Write-Host "  - MadrixExe : Pfad zur madrix.exe"
Write-Host "  - HttpPort / HttpUser / HttpPass : passend zum MADRIX HTTP-Remote"
Write-Host "  (Datei: $cfgTarget)"
Start-Process notepad.exe $cfgTarget

Write-Host ""
$answer = Read-Host "Watchdog jetzt starten? (J/N)"
if ($answer -match '^[JjYy]') {
    Start-ScheduledTask -TaskName $TaskName
    Write-Host "Watchdog gestartet. Log: %ProgramData%\MadrixWatchdog\madrix-watchdog.log" -ForegroundColor Green
} else {
    Write-Host "Spaeter starten: Aufgabenplanung -> '$TaskName' -> Ausfuehren, oder ab-/anmelden."
}
