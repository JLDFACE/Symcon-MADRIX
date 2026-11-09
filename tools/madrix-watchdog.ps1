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
    AlertUrl         = ""      # leer = Alarmierung aus (z.B. Symcon-WebHook: http://symcon:3777/hook/madrix-alert)
    AlertMethod      = "POST"  # POST (JSON-Body) oder GET (Query-Parameter)
    AlertUser        = ""      # Basic-Auth fuer die Alarm-URL (leer = keine)
    AlertPass        = ""
    AlertTimeoutSec  = 5
    AlertAfterFails  = 3       # Alarm nach N erfolglosen Neustarts in Folge
    AlertRepeatMin   = 30      # Folgealarm alle N Minuten, solange die Stoerung anhaelt (0 = nur einmal)
    AlertOnRecovery  = $true   # Entwarnung senden, sobald MADRIX wieder laeuft
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
$AlertUrl         = [string]$cfg.AlertUrl
$AlertMethod      = ([string]$cfg.AlertMethod).ToUpper()
$AlertUser        = [string]$cfg.AlertUser
$AlertPass        = [string]$cfg.AlertPass
$AlertTimeoutSec  = [int]   $cfg.AlertTimeoutSec
$AlertAfterFails  = [int]   $cfg.AlertAfterFails
$AlertRepeatMin   = [int]   $cfg.AlertRepeatMin
$AlertOnRecovery  = [bool]  $cfg.AlertOnRecovery

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

function Find-MadrixExe {
    foreach ($r in @($env:ProgramFiles, ${env:ProgramFiles(x86)}) | Where-Object { $_ }) {
        $hit = Get-ChildItem -Path $r -Recurse -Filter "MADRIX.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($hit) { return $hit.FullName }
    }
    return $null
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

function Send-Alert {
    param([string]$Event, [string]$Message)
    if ([string]::IsNullOrWhiteSpace($AlertUrl)) { return }

    $payload = [ordered]@{
        host    = $env:COMPUTERNAME
        event   = $Event          # "restart-loop" oder "recovered"
        message = $Message
        fails   = $script:RestartFails
        time    = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
    }
    $headers = @{}
    if ($AlertUser -ne "") {
        $pair = "{0}:{1}" -f $AlertUser, $AlertPass
        $headers["Authorization"] = "Basic " + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
    }
    try {
        if ($AlertMethod -eq "GET") {
            $qs  = ($payload.GetEnumerator() | ForEach-Object {
                       "{0}={1}" -f $_.Key, [uri]::EscapeDataString([string]$_.Value)
                   }) -join "&"
            $sep = if ($AlertUrl.Contains("?")) { "&" } else { "?" }
            Invoke-WebRequest -Uri ($AlertUrl + $sep + $qs) -Headers $headers `
                -TimeoutSec $AlertTimeoutSec -UseBasicParsing | Out-Null
        } else {
            $body = $payload | ConvertTo-Json -Compress
            Invoke-WebRequest -Uri $AlertUrl -Method Post -Body $body `
                -ContentType "application/json; charset=utf-8" -Headers $headers `
                -TimeoutSec $AlertTimeoutSec -UseBasicParsing | Out-Null
        }
        Write-Log "Alarm gesendet ($Event): $Message"
    } catch {
        Write-Log "Alarm konnte nicht gesendet werden ($AlertUrl): $($_.Exception.Message)" "ERROR"
    }
}

# Bewertet das Ergebnis eines Neustartversuchs und loest ggf. Alarm/Entwarnung aus.
function Register-RestartOutcome {
    param([bool]$Success)
    if ($Success) {
        if ($script:AlertActive -and $AlertOnRecovery) {
            Send-Alert "recovered" ("MADRIX laeuft wieder (nach {0} erfolglosen Neustarts)." -f $script:RestartFails)
        }
        $script:RestartFails = 0
        $script:AlertActive  = $false
        return
    }
    $script:RestartFails++
    Write-Log "Neustart nicht bestaetigt ($($script:RestartFails). Versuch in Folge)." "WARN"
    $repeatDue = ($AlertRepeatMin -gt 0) -and
                 (((Get-Date) - $script:LastAlertTime).TotalMinutes -ge $AlertRepeatMin)
    if ($script:RestartFails -ge $AlertAfterFails -and (-not $script:AlertActive -or $repeatDue)) {
        Send-Alert "restart-loop" ("MADRIX kommt trotz {0} Neustartversuchen nicht hoch - manueller Eingriff noetig." -f $script:RestartFails)
        $script:AlertActive   = $true
        $script:LastAlertTime = Get-Date
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
    $script:StartupConfirmed = $false
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

    # Ohne HTTP-Check zaehlt oben nur "Prozess existiert" - kurz warten und pruefen,
    # ob er den Start ueberlebt (erkennt Absturzschleifen direkt beim Hochfahren).
    if ($ready -and -not $UseHttpCheck) {
        Start-Sleep -Seconds ([Math]::Min($GraceSec, 20))
        $ready = ($null -ne (Get-MadrixProcess))
    }

    if ($ready) {
        $script:StartupConfirmed = $true
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
# Wenn die konfigurierte EXE nicht existiert: einmalig automatisch suchen
if (-not (Test-Path $MadrixExe)) {
    Write-Log "Konfigurierte MadrixExe existiert nicht: $MadrixExe - suche automatisch ..." "WARN"
    $auto = Find-MadrixExe
    if ($auto) {
        $MadrixExe = $auto
        Write-Log "MADRIX.exe automatisch gefunden und verwendet: $MadrixExe"
    } else {
        Write-Log "ACHTUNG: keine MADRIX.exe gefunden - bitte MadrixExe in der Konfig setzen." "ERROR"
    }
}
$AlertInfo = if ([string]::IsNullOrWhiteSpace($AlertUrl)) { "aus" } else { "$AlertUrl (nach $AlertAfterFails Fehlversuchen)" }
Write-Log "MADRIX Watchdog gestartet. EXE='$MadrixExe', Intervall=${CheckIntervalSec}s, FailThreshold=$FailThreshold, HttpCheck=$UseHttpCheck, Alarm=$AlertInfo."

$failCount               = 0
$script:RestartFails     = 0      # erfolglose Neustarts in Folge
$script:AlertActive      = $false
$script:LastAlertTime    = [datetime]::MinValue
$script:StartupConfirmed = $false

while ($true) {
    try {
        $proc = Get-MadrixProcess

        if ($null -eq $proc) {
            Write-Log "MADRIX-Prozess nicht vorhanden (Absturz erkannt)." "WARN"
            $failCount = 0
            Start-Madrix
            Register-RestartOutcome -Success $script:StartupConfirmed
            Start-Sleep -Seconds $GraceSec
        }
        elseif (Test-MadrixHealthy -proc $proc) {
            if ($failCount -gt 0) { Write-Log "MADRIX reagiert wieder (nach $failCount Fehlversuch(en))." }
            $failCount = 0
            # MADRIX kam evtl. spaeter als StartupWaitSec hoch -> Entwarnung nachreichen
            if ($script:RestartFails -gt 0) { Register-RestartOutcome -Success $true }
        }
        else {
            $failCount++
            Write-Log "MADRIX reagiert nicht ($failCount/$FailThreshold)." "WARN"
            if ($failCount -ge $FailThreshold) {
                Stop-Madrix -proc $proc
                Start-Sleep -Seconds $GraceSec
                Start-Madrix
                Register-RestartOutcome -Success $script:StartupConfirmed
                $failCount = 0
                Start-Sleep -Seconds $GraceSec
            }
        }
    } catch {
        Write-Log "Unerwarteter Fehler in der Watchdog-Schleife: $_" "ERROR"
    }

    Start-Sleep -Seconds $CheckIntervalSec
}
