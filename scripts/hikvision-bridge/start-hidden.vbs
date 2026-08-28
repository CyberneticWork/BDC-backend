' Starts the fingerprint bridge with no Command Prompt window.
Option Explicit
Dim fso, sh, folder, bat
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
folder = fso.GetParentFolderName(WScript.ScriptFullName)
bat = folder & "\start-bridge.bat"
sh.CurrentDirectory = folder
sh.Run """" & bat & """", 0, False
