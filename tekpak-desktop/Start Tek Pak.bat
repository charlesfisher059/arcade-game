@echo off
REM Tek Pak Desktop — Diamonds Outta Dirt
REM Double-click this file to open Tek Pak as a desktop app window.
cd /d "%~dp0"

set "APP=%~dp0index.html"

REM Prefer Edge app mode, then Chrome, then default browser
where msedge >nul 2>&1
if %ERRORLEVEL%==0 (
  start "" msedge --app="%APP%"
  exit /b 0
)

if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
  start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app="%APP%"
  exit /b 0
)

if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
  start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app="%APP%"
  exit /b 0
)

where chrome >nul 2>&1
if %ERRORLEVEL%==0 (
  start "" chrome --app="%APP%"
  exit /b 0
)

if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
  start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app="%APP%"
  exit /b 0
)

if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
  start "" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" --app="%APP%"
  exit /b 0
)

REM Fallback: open in default browser
start "" "%APP%"
