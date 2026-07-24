@echo off
REM ===========================================================================
REM  Servora dev runner -- launches backend + scheduler + frontend
REM  in three separate windows so you can read each one's logs.
REM
REM  Usage:  start.bat
REM
REM  Robust against:
REM   - PHP not being on PATH  (auto-detects C:\php\php.exe)
REM   - project path containing spaces  (paths quoted; uses start /D, no cd)
REM
REM  Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>
REM ===========================================================================

chcp 65001 >nul 2>&1
setlocal
set "ROOT=%~dp0"

REM -- Locate PHP: prefer PATH, else fall back to C:\php\php.exe --
set "PHP="
where php >nul 2>&1 && set "PHP=php"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP goto no_php

REM -- Node/npm must be on PATH --
where npm >nul 2>&1 || goto no_npm

REM -- Launch each dev server in its own window. start /D sets the working
REM    directory, so paths with spaces need no fragile cd command. --
start "Servora backend"   /D "%ROOT%backend"  cmd /k "%PHP% artisan serve --host=127.0.0.1 --port=8000"
start "Servora scheduler" /D "%ROOT%backend"  cmd /k "%PHP% artisan schedule:work"
start "Servora frontend"  /D "%ROOT%frontend" cmd /k "npm run dev"

echo.
echo Servora launched in three windows:
echo    Backend     http://127.0.0.1:8000
echo    Frontend    http://localhost:3000
echo    Scheduler   background
echo.
echo Using PHP: %PHP%
echo Close each window to stop that service.
echo.
goto end

:no_php
echo.
echo [!] PHP was not found on PATH or at C:\php\php.exe
echo     Install PHP 8.2+ and add it to PATH, or put php.exe in C:\php\
echo.
pause
goto end

:no_npm
echo.
echo [!] npm was not found. Install Node.js 20+ from nodejs.org then retry.
echo.
pause
goto end

:end
endlocal
