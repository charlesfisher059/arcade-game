' Tek Pak Desktop launcher (Windows)
' Double-click this if the .bat file does nothing.
Option Explicit
Dim fso, sh, folder, html, url, edge, chrome, cmd
Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")
folder = fso.GetParentFolderName(WScript.ScriptFullName)
html = folder & "\index.html"
If Not fso.FileExists(html) Then
  MsgBox "index.html not found next to this launcher.", vbCritical, "Tek Pak"
  WScript.Quit 1
End If
url = "file:///" & Replace(html, "\", "/")

edge = sh.ExpandEnvironmentStrings("%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe")
If Not fso.FileExists(edge) Then edge = sh.ExpandEnvironmentStrings("%ProgramFiles%\Microsoft\Edge\Application\msedge.exe")
chrome = sh.ExpandEnvironmentStrings("%ProgramFiles%\Google\Chrome\Application\chrome.exe")
If Not fso.FileExists(chrome) Then chrome = sh.ExpandEnvironmentStrings("%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe")
If Not fso.FileExists(chrome) Then chrome = sh.ExpandEnvironmentStrings("%LocalAppData%\Google\Chrome\Application\chrome.exe")

On Error Resume Next
If fso.FileExists(edge) Then
  sh.Run """" & edge & """ --new-window --app=""" & url & """", 1, False
  WScript.Quit 0
End If
If fso.FileExists(chrome) Then
  sh.Run """" & chrome & """ --new-window --app=""" & url & """", 1, False
  WScript.Quit 0
End If

' Fallback — default browser
sh.Run """" & html & """", 1, False
If Err.Number <> 0 Then
  MsgBox "Could not open Tek Pak. Double-click OPEN TEK PAK.html instead.", vbExclamation, "Tek Pak"
End If
