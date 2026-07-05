<#
    MADRIX Watchdog
    ---------------
    Ueberwacht den MADRIX-Prozess auf dem lokalen Windows-PC und startet ihn nach
    Absturz ODER Freeze automatisch neu.

    Laeuft in der interaktiven Desktop-Session (per Aufgabenplanung "Bei Anmeldung").
    Ein Windows-DIENST ist ungeeignet, weil MADRIX eine GUI-Anwendung ist und in
    Session 0 (Dienste) keinen Desktop hat.

    KONFIGURATION erfolgt ueber die Datei  madrix-watchdog.config.json  im selben
    Ordner wie dieses Skript/die EXE. Dieses Skript selbst muss NICHT editiert werden.

    Kann per build-exe.ps1 (ps2exe) zu MadrixWatchdog.exe kompiliert werden.
    Einrichtung: Install.cmd (Doppelklick). Details: README.md.
#>

$ErrorActionPreference = "SilentlyContinue"

# ---------------------------------------------------------------------
#  Konfiguration laden (externe JSON-Datei; fehlende Werte -> Defaults)
# ---------------------------------------------------------------------

function Get-BaseDir {
    if ($PSScriptRoot) { return $PSScriptRoot }
    try {
        $exe = [System.Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
        $dir = [System.IO.Path]::GetDirectoryName($exe)
        if ($dir) { return $dir }
    } catch { }
    return (Get-Location).Path
}

$BaseDir    = Get-BaseDir
$ConfigPath = Join-Path $BaseDir "madrix-watchdog.config.json"

# Defaults (werden von der JSON ueberschrieben, falls vorhanden)
$cfg = [ordered]@{
    MadrixExe        = "C:\Program Files\MADRIX 5\madrix.exe"
    ProcessName      = "madrix"
    UseHttpCheck     = $true
    HttpHost         = "127.0.0.1"
    HttpPort         = 80
    HttpUser         = ""
    HttpPass         = ""
    HttpTimeoutSec   = 4
    LoadSetupId      = 0
    CheckIntervalSec = 15
    FailThreshold    = 3
    GraceSec         = 30
    StartupWaitSec   = 60
    LogFile          = ""      # leer = %ProgramData%\MadrixWatchdog\madrix-watchdog.log
}

$ConfigLoaded = $false
$ConfigError  = ""
if (Test-Path $ConfigPath) {
    try {
        $raw = Get-Content -Path $ConfigPath -Raw -Encoding UTF8
        # BOM entfernen (Notepad speichert UTF-8 teils mit BOM -> ConvertFrom-Json in
        # Windows PowerShell 5.1 wirft sonst "Invalid JSON primitive")
        if ($raw) { $raw = $raw.TrimStart([char]0xFEFF, [char]0xFFFE) }
        $json = $raw | ConvertFrom-Json
        foreach ($k in @($cfg.Keys)) {
            if ($json.PSObject.Properties.Name -contains $k) { $cfg[$k] = $json.$k }
        }
        $ConfigLoaded = $true
    } catch {
        $ConfigError = $_.Exception.Message
    }
}

# Werte in Variablen uebernehmen
$MadrixExe        = [string]$cfg.MadrixExe
$ProcessName      = [string]$cfg.ProcessName
$UseHttpCheck     = [bool]  $cfg.UseHttpCheck
$HttpHost         = [string]$cfg.HttpHost
$HttpPort         = [int]   $cfg.HttpPort
$HttpUser         = [string]$cfg.HttpUser
$HttpPass         = [string]$cfg.HttpPass
$HttpTimeoutSec   = [int]   $cfg.HttpTimeoutSec
$LoadSetupId      = [int]   $cfg.LoadSetupId
$CheckIntervalSec = [int]   $cfg.CheckIntervalSec
$FailThreshold    = [int]   $cfg.FailThreshold
$GraceSec         = [int]   $cfg.GraceSec
$StartupWaitSec   = [int]   $cfg.StartupWaitSec

if ([string]::IsNullOrWhiteSpace($cfg.LogFile)) {
    $LogFile = Join-Path $env:ProgramData "MadrixWatchdog\madrix-watchdog.log"
} else {
    $LogFile = [string]$cfg.LogFile
}
$MaxLogBytes = 2MB

# ---------------------------------------------------------------------
#  Hilfsfunktionen
# ---------------------------------------------------------------------

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $line = "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    try {
        $dir = Split-Path -Parent $LogFile
        if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt $MaxLogBytes)) {
            Move-Item -Path $LogFile -Destination ($LogFile + ".old") -Force
        }
        Add-Content -Path $LogFile -Value $line
    } catch { }
    Write-Output $line
}

function Get-MadrixProcess {
    return Get-Process -Name $ProcessName -ErrorAction SilentlyContinue | Select-Object -First 1
}

function Invoke-MadrixHttp {
    param([string]$Command)
    if (-not $UseHttpCheck) { return $true }

    $url = "http://{0}:{1}/RemoteCommands/{2}" -f $HttpHost, $HttpPort, $Command
    $headers = @{}
    if ($HttpUser -ne "") {
        $pair  = "{0}:{1}" -f $HttpUser, $HttpPass
        $token = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
        $headers["Authorization"] = "Basic $token"
    }
    try {
        $resp = Invoke-WebRequest -Uri $url -Headers $headers -TimeoutSec $HttpTimeoutSec -UseBasicParsing
        return ($resp.StatusCode -eq 200)
    } catch {
        return $false
    }
}

function Test-MadrixHealthy {
    param($proc)
    if ($null -eq $proc) { return $false }
    try {
        if ($proc.MainWindowHandle -ne 0 -and -not $proc.Responding) { return $false }
    } catch { }
    if ($UseHttpCheck) { return (Invoke-MadrixHttp -Command "GetSetupRegisterCurrentId") }
    return $true
}

function Start-Madrix {
    if (-not (Test-Path $MadrixExe)) {
        Write-Log "MADRIX-EXE nicht gefunden: $MadrixExe" "ERROR"
        return
    }
    Write-Log "Starte MADRIX: $MadrixExe"
    Start-Process -FilePath $MadrixExe | Out-Null

    $deadline = (Get-Date).AddSeconds($StartupWaitSec)
    do {
        Start-Sleep -Seconds 3
        $proc  = Get-MadrixProcess
        $ready = ($null -ne $proc) -and (Invoke-MadrixHttp -Command "GetSetupRegisterCurrentId")
    } while (-not $ready -and (Get-Date) -lt $deadline)

    if ($ready) {
        Write-Log "MADRIX ist wieder erreichbar (Recovery bestaetigt)."
        if ($LoadSetupId -gt 0) {
            if (Invoke-MadrixHttp -Command ("SetSetupRegisterLoadById={0}" -f $LoadSetupId)) {
                Write-Log "Setup $LoadSetupId per HTTP geladen."
            } else {
                Write-Log "Setup $LoadSetupId konnte nicht geladen werden." "WARN"
            }
        }
    } else {
        Write-Log "MADRIX antwortet nach $StartupWaitSec s noch nicht - naechster Zyklus prueft erneut." "WARN"
    }
}

function Stop-Madrix {
    param($proc)
    if ($null -eq $proc) { return }
    Write-Log "Beende haengenden MADRIX-Prozess (PID $($proc.Id))." "WARN"
    try { $proc | Stop-Process -Force -ErrorAction Stop } catch {
        Write-Log "Stop-Process fehlgeschlagen: $_" "ERROR"
    }
    Start-Sleep -Seconds 2
}

# ---------------------------------------------------------------------
#  Hauptschleife
# ---------------------------------------------------------------------

if ($ConfigLoaded) {
    Write-Log "Konfiguration geladen: $ConfigPath"
} elseif (Test-Path $ConfigPath) {
    Write-Log "Konfigdatei ungueltig ($ConfigPath): $ConfigError - nutze Defaults." "WARN"
} else {
    Write-Log "Keine Konfigdatei gefunden ($ConfigPath) - nutze Defaults." "WARN"
}
# Fruehwarnung, wenn die EXE nicht existiert (haeufigste Fehlkonfiguration)
if (-not (Test-Path $MadrixExe)) {
    Write-Log "ACHTUNG: konfigurierte MadrixExe existiert nicht: $MadrixExe" "ERROR"
}
Write-Log "MADRIX Watchdog gestartet. EXE='$MadrixExe', Intervall=${CheckIntervalSec}s, FailThreshold=$FailThreshold, HttpCheck=$UseHttpCheck."

$failCount = 0

while ($true) {
    try {
        $proc = Get-MadrixProcess

        if ($null -eq $proc) {
            Write-Log "MADRIX-Prozess nicht vorhanden (Absturz erkannt)." "WARN"
            $failCount = 0
            Start-Madrix
            Start-Sleep -Seconds $GraceSec
        }
        elseif (Test-MadrixHealthy -proc $proc) {
            if ($failCount -gt 0) { Write-Log "MADRIX reagiert wieder (nach $failCount Fehlversuch(en))." }
            $failCount = 0
        }
        else {
            $failCount++
            Write-Log "MADRIX reagiert nicht ($failCount/$FailThreshold)." "WARN"
            if ($failCount -ge $FailThreshold) {
                Stop-Madrix -proc $proc
                Start-Sleep -Seconds $GraceSec
                Start-Madrix
                $failCount = 0
                Start-Sleep -Seconds $GraceSec
            }
        }
    } catch {
        Write-Log "Unerwarteter Fehler in der Watchdog-Schleife: $_" "ERROR"
    }

    Start-Sleep -Seconds $CheckIntervalSec
}
