@echo off
rem === MADRIX Watchdog - Deinstallation ===
rem Entfernt die Autostart-Aufgabe. Der Ordner C:\MadrixWatchdog (Konfig + Log)
rem bleibt erhalten und kann bei Bedarf manuell geloescht werden.

net session >nul 2>&1
if %errorlevel% neq 0 (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

schtasks /End    /TN "MADRIX Watchdog" >nul 2>&1
schtasks /Delete /TN "MADRIX Watchdog" /F
echo.
echo Aufgabe entfernt. Ordner C:\MadrixWatchdog wurde NICHT geloescht.
echo.
pause
