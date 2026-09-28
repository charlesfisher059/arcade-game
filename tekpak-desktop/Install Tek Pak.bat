@echo off
setlocal EnableExtensions
title Install Tek Pak
cd /d "%~dp0"

echo.
echo  ========================================
echo   TEK PAK — Install to this computer
echo   Diamonds Outta Dirt
echo  ========================================
echo.

if not exist "%~dp0index.html" (
  echo ERROR: Run this from inside the tekpak-desktop folder.
  echo index.html is missing.
  echo.
  pause
  exit /b 1
)

set "DEST=%LOCALAPPDATA%\DiamondsOuttaDirt\TekPak"
set "DESKTOP=%USERPROFILE%\Desktop"
set "STARTMENU=%APPDATA%\Microsoft\Windows\Start Menu\Programs"

echo Installing to:
echo   %DEST%
echo.

mkdir "%DEST%" 2>nul
copy /Y "%~dp0index.html" "%DEST%\index.html" >nul
copy /Y "%~dp0OPEN TEK PAK.html" "%DEST%\OPEN TEK PAK.html" >nul 2>nul
copy /Y "%~dp0Start Tek Pak.bat" "%DEST%\Start Tek Pak.bat" >nul
copy /Y "%~dp0Start Tek Pak.vbs" "%DEST%\Start Tek Pak.vbs" >nul 2>nul
copy /Y "%~dp0README.txt" "%DEST%\README.txt" >nul 2>nul

if not exist "%DEST%\index.html" (
  echo ERROR: Copy failed.
  pause
  exit /b 1
)

REM Create Desktop shortcut via VBScript
set "VBS=%TEMP%\tekpak_shortcut.vbs"
> "%VBS%" echo Set o = CreateObject("WScript.Shell")
>>"%VBS%" echo Set s = o.CreateShortcut("%DESKTOP%\Tek Pak.lnk")
>>"%VBS%" echo s.TargetPath = "%DEST%\Start Tek Pak.vbs"
>>"%VBS%" echo s.WorkingDirectory = "%DEST%"
>>"%VBS%" echo s.WindowStyle = 1
>>"%VBS%" echo s.Description = "Tek Pak — Diamonds Outta Dirt"
>>"%VBS%" echo s.Save
>>"%VBS%" echo Set s2 = o.CreateShortcut("%STARTMENU%\Tek Pak.lnk")
>>"%VBS%" echo s2.TargetPath = "%DEST%\Start Tek Pak.vbs"
>>"%VBS%" echo s2.WorkingDirectory = "%DEST%"
>>"%VBS%" echo s2.Description = "Tek Pak — Diamonds Outta Dirt"
>>"%VBS%" echo s2.Save

cscript //nologo "%VBS%"
del "%VBS%" >nul 2>nul

echo.
echo  Installed.
echo  Desktop shortcut:  Tek Pak
echo  Start Menu:        Tek Pak
echo.
echo  Opening Tek Pak now...
echo.

REM Launch
if exist "%DEST%\Start Tek Pak.vbs" (
  wscript //nologo "%DEST%\Start Tek Pak.vbs"
) else (
  start "" "%DEST%\index.html"
)

echo.
echo  Done. You can close this window.
echo  Next time: double-click "Tek Pak" on your Desktop.
echo.
pause
exit /b 0
