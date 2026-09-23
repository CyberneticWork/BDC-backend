@echo off
cd /d "%~dp0"
set "NODE="
if exist "%ProgramFiles%\nodejs\node.exe" set "NODE=%ProgramFiles%\nodejs\node.exe"
if not defined NODE if exist "%ProgramFiles(x86)%\nodejs\node.exe" set "NODE=%ProgramFiles(x86)%\nodejs\node.exe"
if not defined NODE set "NODE=node"
echo ===== %DATE% %TIME% starting Hikvision bridge =====>> "%~dp0bridge.log"
:run
"%NODE%" "%~dp0bridge.js" >> "%~dp0bridge.log" 2>&1
echo ===== %DATE% %TIME% bridge stopped, restarting in 20s =====>> "%~dp0bridge.log"
timeout /t 20 /nobreak >nul
goto run
