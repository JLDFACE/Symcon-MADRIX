@echo off
rem === MADRIX Watchdog - Installer ===
rem Doppelklick genuegt. Holt sich bei Bedarf Administratorrechte und richtet
rem den Watchdog inkl. Autostart-Aufgabe ein.

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo Fordere Administratorrechte an...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install.ps1"
echo.
pause
