<#
    MADRIX Watchdog
    ---------------
    Ueberwacht den MADRIX-Prozess auf dem lokalen Windows-PC und startet ihn nach
    Absturz ODER Freeze automatisch neu. Laeuft als Endlosschleife in der
    interaktiven Desktop-Session (per Aufgabenplanung "Bei Anmeldung" starten).

    Symcon kann einen abgestuerzten Windows-Prozess NICHT aus der Ferne neu
    starten - dieses Skript ist der lokale "Actuator". Symcon uebernimmt nur die
    Ueberwachung/Meldung (siehe MadrixController-Modul).

    Einrichtung: siehe README.md in diesem Ordner.
#>

# =====================================================================
#  KONFIGURATION - hier pro Installation anpassen
# =====================================================================

# Pfad zur MADRIX-Programmdatei (anpassen an installierte Version!)
$MadrixExe        = "C:\Program Files\MADRIX 5\madrix.exe"

# Prozessname OHNE ".exe" (fuer Get-Process). Meist "madrix".
$ProcessName      = "madrix"

# HTTP-Remote der lokalen MADRIX-Instanz (optionaler, tieferer Health-Check).
# Muss in MADRIX unter Preferences > Remote Control > HTTP aktiviert sein.
$UseHttpCheck     = $true
$HttpHost         = "127.0.0.1"
$HttpPort         = 80
$HttpUser         = ""              # Basic-Auth Benutzer (leer = keine Auth)
$HttpPass         = ""              # Basic-Auth Passwort
$HttpTimeoutSec   = 4

# Nach einem Neustart optional ein bestimmtes Setup per HTTP laden
# (0 = nichts laden; MADRIX sollte das Setup ohnehin per Startup-Option laden).
$LoadSetupId      = 0

# Zeitsteuerung
$CheckIntervalSec = 15              # Pruefintervall
$FailThreshold    = 3              # aufeinanderfolgende Fehler bis "Freeze"
$GraceSec         = 30             # Wartezeit nach Kill/Start (verhindert Kill-Schleifen)
$StartupWaitSec   = 60             # max. Wartezeit bis MADRIX nach Start wieder antwortet

# Logdatei
$LogFile          = Join-Path $env:ProgramData "MadrixWatchdog\madrix-watchdog.log"
$MaxLogBytes      = 2MB            # einfache Log-Rotation

# =====================================================================
#  Ab hier keine Anpassung noetig
# =====================================================================

$ErrorActionPreference = "SilentlyContinue"

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $line = "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    try {
        $dir = Split-Path -Parent $LogFile
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
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
    # Fuehrt ein RemoteCommand aus. Rueckgabe: $true bei HTTP 200, sonst $false.
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
    # Liefert $true, wenn MADRIX laeuft UND (soweit geprueft) reagiert.
    param($proc)
    if ($null -eq $proc) { return $false }

    # GUI-Freeze-Erkennung: reagiert das Hauptfenster noch auf Windows-Messages?
    try {
        if ($proc.MainWindowHandle -ne 0 -and -not $proc.Responding) { return $false }
    } catch { }

    # Optionaler HTTP-Health-Check (harmloses GET).
    if ($UseHttpCheck) {
        return (Invoke-MadrixHttp -Command "GetSetupRegisterCurrentId")
    }
    return $true
}

function Start-Madrix {
    if (-not (Test-Path $MadrixExe)) {
        Write-Log "MADRIX-EXE nicht gefunden: $MadrixExe" "ERROR"
        return
    }
    Write-Log "Starte MADRIX: $MadrixExe"
    Start-Process -FilePath $MadrixExe | Out-Null

    # Auf Wiedererreichbarkeit warten.
    $deadline = (Get-Date).AddSeconds($StartupWaitSec)
    do {
        Start-Sleep -Seconds 3
        $proc = Get-MadrixProcess
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

Write-Log "MADRIX Watchdog gestartet. Intervall=${CheckIntervalSec}s, FailThreshold=$FailThreshold, HttpCheck=$UseHttpCheck."

$failCount = 0

while ($true) {
    try {
        $proc = Get-MadrixProcess

        if ($null -eq $proc) {
            # Absturz: Prozess ganz weg -> sofort neu starten.
            Write-Log "MADRIX-Prozess nicht vorhanden (Absturz erkannt)." "WARN"
            $failCount = 0
            Start-Madrix
            Start-Sleep -Seconds $GraceSec
        }
        elseif (Test-MadrixHealthy -proc $proc) {
            # Gesund.
            if ($failCount -gt 0) {
                Write-Log "MADRIX reagiert wieder (nach $failCount Fehlversuch(en))."
            }
            $failCount = 0
        }
        else {
            # Laeuft, reagiert aber nicht -> Freeze-Verdacht, entprellt.
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
