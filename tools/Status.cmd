@echo off
rem === MADRIX Watchdog - Status anzeigen ===
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Status.ps1"
echo.
pause
