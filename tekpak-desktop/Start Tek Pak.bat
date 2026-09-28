@echo off
setlocal EnableExtensions
cd /d "%~dp0"

if not exist "%~dp0index.html" (
  echo ERROR: index.html not found in:
  echo %~dp0
  echo.
  pause
  exit /b 1
)

REM Build a file:/// URL Edge/Chrome understand with --app
for %%I in ("%~dp0index.html") do set "FULL=%%~fI"
set "URL=file:///%FULL:\=/%"

set "EDGE1=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
set "EDGE2=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
set "CHROME1=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
set "CHROME2=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
set "CHROME3=%LocalAppData%\Google\Chrome\Application\chrome.exe"

if exist "%EDGE1%" (
  start "" "%EDGE1%" --new-window --app="%URL%"
  exit /b 0
)
if exist "%EDGE2%" (
  start "" "%EDGE2%" --new-window --app="%URL%"
  exit /b 0
)
if exist "%CHROME1%" (
  start "" "%CHROME1%" --new-window --app="%URL%"
  exit /b 0
)
if exist "%CHROME2%" (
  start "" "%CHROME2%" --new-window --app="%URL%"
  exit /b 0
)
if exist "%CHROME3%" (
  start "" "%CHROME3%" --new-window --app="%URL%"
  exit /b 0
)

REM Always-works fallback: open in your default browser
start "" "%~dp0index.html"
if errorlevel 1 (
  echo Could not open Tek Pak.
  echo Try double-clicking:  OPEN TEK PAK.html
  echo.
  pause
)
exit /b 0
