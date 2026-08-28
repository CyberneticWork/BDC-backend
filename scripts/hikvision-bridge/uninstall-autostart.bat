@echo off
setlocal
echo Removing Hikvision bridge auto-start...
schtasks /Delete /TN "HikvisionAttendanceBridge" /F >nul 2>&1
del /F /Q "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\HikvisionAttendanceBridge.lnk" >nul 2>&1
del /F /Q "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\HikvisionAttendanceBridge.vbs" >nul 2>&1
echo Removed. The bridge will not start after the next login.
pause
