$ErrorActionPreference = 'Stop'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$startup = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\Startup'
if (-not (Test-Path $startup)) {
  New-Item -ItemType Directory -Path $startup | Out-Null
}
$lnkPath = Join-Path $startup 'HikvisionAttendanceBridge.lnk'
$ws = New-Object -ComObject WScript.Shell
$lnk = $ws.CreateShortcut($lnkPath)
$lnk.TargetPath = (Join-Path $env:SystemRoot 'System32\wscript.exe')
$lnk.Arguments = '"' + (Join-Path $dir 'start-hidden.vbs') + '"'
$lnk.WorkingDirectory = $dir
$lnk.WindowStyle = 7
$lnk.Description = 'Hikvision attendance bridge'
$lnk.Save()
