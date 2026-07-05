<#
    MADRIX Watchdog - Einrichtung (interaktiv)
    Wird von Install.cmd (mit Adminrechten) aufgerufen.

    - kopiert Watchdog (+ EXE, falls vorhanden) nach C:\MadrixWatchdog
    - erkennt die MADRIX.exe automatisch und fragt die wichtigsten Werte ab
    - schreibt die Konfig BOM-frei (kein Notepad-Fallstrick)
    - legt die Autostart-Aufgabe "MADRIX Watchdog" an (Start bei Anmeldung)
#>

$ErrorActionPreference = "Stop"

$Src      = $PSScriptRoot
$Target   = "C:\MadrixWatchdog"
$TaskName = "MADRIX Watchdog"
$cfgTarget = Join-Path $Target "madrix-watchdog.config.json"

function Ask($label, $default) {
    $v = Read-Host ("{0} [{1}]" -f $label, $default)
    if ([string]::IsNullOrWhiteSpace($v)) { return $default } else { return $v.Trim() }
}
function Ask-YesNo($label, $default) {
    $d = if ($default) { "J" } else { "N" }
    return ((Ask "$label (J/N)" $d) -match '^[JjYy]')
}
function Find-MadrixExe {
    foreach ($r in @($env:ProgramFiles, ${env:ProgramFiles(x86)}) | Where-Object { $_ }) {
        $hit = Get-ChildItem -Path $r -Recurse -Filter "MADRIX.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($hit) { return $hit.FullName }
    }
    return $null
}
function Save-Config($cfg) {
    $json = $cfg | ConvertTo-Json
    [System.IO.File]::WriteAllText($cfgTarget, $json, (New-Object System.Text.UTF8Encoding($false)))
}

Write-Host "=== MADRIX Watchdog Einrichtung ===" -ForegroundColor Cyan
Write-Host "Ziel: $Target`n"

# 1) Zielordner + Programmdatei(en)
if (-not (Test-Path $Target)) { New-Item -ItemType Directory -Path $Target -Force | Out-Null }

$exeSrc = Join-Path $Src "MadrixWatchdog.exe"
$useExe = Test-Path $exeSrc
if ($useExe) {
    Copy-Item $exeSrc (Join-Path $Target "MadrixWatchdog.exe") -Force
    Write-Host "MadrixWatchdog.exe kopiert."
} else {
    Copy-Item (Join-Path $Src "madrix-watchdog.ps1") (Join-Path $Target "madrix-watchdog.ps1") -Force
    Write-Host "madrix-watchdog.ps1 kopiert."
}

# 2) Konfiguration
$makeNew = $true
if (Test-Path $cfgTarget) {
    Write-Host ""
    $makeNew = -not (Ask-YesNo "Vorhandene Konfiguration behalten?" $true)
}

if ($makeNew) {
    Write-Host "`n--- Konfiguration ---" -ForegroundColor Cyan
    Write-Host "Suche MADRIX.exe ..."
    $detected = Find-MadrixExe
    if ($detected) { Write-Host "  gefunden: $detected" -ForegroundColor Green }
    else           { Write-Host "  nicht automatisch gefunden." -ForegroundColor Yellow }

    $exeDefault = if ($detected) { $detected } else { "C:\Program Files\MADRIX5\MADRIX.exe" }
    $exe = Ask "Pfad zur MADRIX.exe" $exeDefault
    if (-not (Test-Path $exe)) { Write-Host "  WARN: '$exe' existiert derzeit nicht." -ForegroundColor Yellow }

    $useHttp = Ask-YesNo "HTTP-Check nutzen? (erkennt auch Freezes; Port/Login noetig)" $false
    $httpPort=80; $httpUser=""; $httpPass=""
    if ($useHttp) {
        $httpPort = [int](Ask "  MADRIX HTTP-Port (wie in Symcon)" 80)
        $httpUser = Ask "  HTTP-Benutzer (leer = keiner)" ""
        $httpPass = Ask "  HTTP-Passwort (leer = keins)" ""
    }
    $interval = [int](Ask "Pruefintervall in Sekunden" 15)

    $cfg = [ordered]@{
        MadrixExe=$exe; ProcessName="madrix"; UseHttpCheck=$useHttp;
        HttpHost="127.0.0.1"; HttpPort=$httpPort; HttpUser=$httpUser; HttpPass=$httpPass; HttpTimeoutSec=4;
        LoadSetupId=0; CheckIntervalSec=$interval; FailThreshold=3; GraceSec=30; StartupWaitSec=60; LogFile=""
    }
    Save-Config $cfg
    Write-Host "Konfig gespeichert (ohne BOM): $cfgTarget" -ForegroundColor Green
} else {
    Write-Host "Bestehende Konfig beibehalten: $cfgTarget"
}

# 3) Autostart-Aufgabe
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
Write-Host "`nAutostart-Aufgabe '$TaskName' angelegt (Benutzer: $user)." -ForegroundColor Green

# 4) Start
Write-Host ""
if (Ask-YesNo "Watchdog jetzt (neu) starten?" $true) {
    Stop-ScheduledTask  -TaskName $TaskName -ErrorAction SilentlyContinue
    Start-ScheduledTask -TaskName $TaskName
    Start-Sleep 5
    Write-Host "`n--- letzte Log-Zeilen ---" -ForegroundColor Cyan
    $log = Join-Path $env:ProgramData "MadrixWatchdog\madrix-watchdog.log"
    if (Test-Path $log) { Get-Content $log -Tail 6 }
    Write-Host "`nFertig. Zum Testen MADRIX schliessen - es sollte binnen Sekunden neu starten." -ForegroundColor Green
} else {
    Write-Host "Spaeter starten: Status.cmd oder Aufgabenplanung -> '$TaskName' -> Ausfuehren."
}
