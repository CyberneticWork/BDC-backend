@echo off
setlocal
cd /d "%~dp0"

echo.
echo  Hikvision fingerprint bridge — auto start
echo  This PC will start the bridge 1 minute after Windows login.
echo  Normal users do not need to open Command Prompt.
echo.

where node >nul 2>&1
if errorlevel 1 (
  if not exist "%ProgramFiles%\nodejs\node.exe" (
    if not exist "%ProgramFiles(x86)%\nodejs\node.exe" (
      echo ERROR: Node.js is not installed on this PC.
      echo Install Node.js LTS from https://nodejs.org then run this file again.
      pause
      exit /b 1
    )
  )
)

if not exist "%~dp0node_modules" (
  echo Installing bridge files ^(one time^)...
  call npm install
)

if not exist "%~dp0.env" if not exist "%~dp0.env.txt" (
  echo WARNING: No .env file in this folder.
  echo Copy your office .env here first, then run install-autostart.bat again.
  pause
  exit /b 1
)

set "TASK=HikvisionAttendanceBridge"
set "VBS=%~dp0start-hidden.vbs"

schtasks /Delete /TN "%TASK%" /F >nul 2>&1
schtasks /Create /TN "%TASK%" /SC ONLOGON /DELAY 0001:00 /RL LIMITED /F /TR "wscript.exe \"%VBS%\""
if errorlevel 1 (
  echo Scheduled task could not be created. Using Startup folder instead.
) else (
  echo Scheduled task created: %TASK%
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-startup-shortcut.ps1"
echo Startup shortcut created.

echo.
echo Done. From now on:
echo   1. Turn the laptop on
echo   2. Sign in to Windows
echo   3. Wait about 1 minute ^(Wi-Fi^)
echo The fingerprint bridge starts by itself. No npm start.
echo.
echo Log file: %~dp0bridge.log
echo To stop auto-start, run uninstall-autostart.bat
echo.
pause
