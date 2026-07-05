<#
    Baut aus madrix-watchdog.ps1 eine eigenstaendige MadrixWatchdog.exe (per ps2exe).
    EINMALIG auf einem Windows-PC ausfuehren (Rechtsklick -> Mit PowerShell ausfuehren,
    oder in einer PowerShell im tools-Ordner:  .\build-exe.ps1 ).

    Die erzeugte EXE liest weiterhin madrix-watchdog.config.json aus IHREM Ordner -
    Konfiguration also unveraendert extern, kein Neu-Kompilieren noetig.
#>

$ErrorActionPreference = "Stop"

$here = $PSScriptRoot
$in   = Join-Path $here "madrix-watchdog.ps1"
$out  = Join-Path $here "MadrixWatchdog.exe"

if (-not (Get-Module -ListAvailable -Name ps2exe)) {
    Write-Host "Installiere Modul 'ps2exe' (CurrentUser)..." -ForegroundColor Cyan
    Install-Module -Name ps2exe -Scope CurrentUser -Force -AllowClobber
}
Import-Module ps2exe

Write-Host "Kompiliere: $in -> $out" -ForegroundColor Cyan
Invoke-ps2exe -inputFile $in -outputFile $out `
    -noConsole `
    -title "MADRIX Watchdog" `
    -company "FACE GmbH" `
    -product "MADRIX Watchdog" `
    -description "Startet MADRIX nach Absturz/Freeze automatisch neu."

Write-Host "Fertig: $out" -ForegroundColor Green
Write-Host "Danach Install.cmd ausfuehren - der Installer nutzt automatisch die EXE."
