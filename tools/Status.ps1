<#
    MADRIX Watchdog - Statusuebersicht
    Zeigt auf einen Blick: laeuft die Aufgabe, laeuft der Watchdog, laeuft MADRIX,
    aktuelle Konfig und die letzten Log-Zeilen.
#>

$ErrorActionPreference = "SilentlyContinue"
$TaskName = "MADRIX Watchdog"
$Target   = "C:\MadrixWatchdog"
$Log      = Join-Path $env:ProgramData "MadrixWatchdog\madrix-watchdog.log"
$Cfg      = Join-Path $Target "madrix-watchdog.config.json"

function Line($label, $value, $color = "Gray") {
    Write-Host ("{0,-22}" -f $label) -NoNewline
    Write-Host $value -ForegroundColor $color
}

Write-Host "=== MADRIX Watchdog - Status ===" -ForegroundColor Cyan

# Aufgabe
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
    $info = $task | Get-ScheduledTaskInfo
    $col = if ($task.State -eq "Running") { "Green" } else { "Yellow" }
    Line "Aufgabe:" $task.State $col
    Line "Letzter Lauf:" $info.LastRunTime
} else {
    Line "Aufgabe:" "NICHT eingerichtet (Install.cmd ausfuehren)" "Red"
}

# Watchdog-Prozess (EXE-Variante ODER powershell mit dem Skript)
$wdRunning = @(Get-Process MadrixWatchdog -ErrorAction SilentlyContinue).Count -gt 0 -or
             @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
               Where-Object { $_.CommandLine -like "*madrix-watchdog.ps1*" }).Count -gt 0
Line "Watchdog laeuft:" ($(if ($wdRunning) { "JA" } else { "NEIN" })) ($(if ($wdRunning) { "Green" } else { "Red" }))

# MADRIX-Prozess
$madrix = Get-Process madrix -ErrorAction SilentlyContinue | Select-Object -First 1
if ($madrix) {
    Line "MADRIX laeuft:" ("JA (PID {0}, reagiert={1})" -f $madrix.Id, $madrix.Responding) "Green"
} else {
    Line "MADRIX laeuft:" "NEIN" "Yellow"
}

# Konfig
Write-Host "`n--- Konfiguration ---" -ForegroundColor Cyan
if (Test-Path $Cfg) {
    try {
        $raw = (Get-Content $Cfg -Raw -Encoding UTF8).TrimStart([char]0xFEFF)
        $c = $raw | ConvertFrom-Json
        $exeOk = Test-Path $c.MadrixExe
        Line "MadrixExe:" $c.MadrixExe ($(if ($exeOk) { "Green" } else { "Red" }))
        if (-not $exeOk) { Line "" "  ^ Pfad existiert nicht!" "Red" }
        Line "UseHttpCheck:" $c.UseHttpCheck
        if ($c.UseHttpCheck) { Line "HTTP:" ("{0}:{1}" -f $c.HttpHost, $c.HttpPort) }
        Line "Intervall/Threshold:" ("{0}s / {1}" -f $c.CheckIntervalSec, $c.FailThreshold)
    } catch {
        Line "Konfig:" "UNGUELTIG ($($_.Exception.Message))" "Red"
    }
} else {
    Line "Konfig:" "fehlt ($Cfg)" "Red"
}

# Log
Write-Host "`n--- letzte 12 Log-Zeilen ---" -ForegroundColor Cyan
if (Test-Path $Log) { Get-Content $Log -Tail 12 } else { Write-Host "(noch kein Log)" }
