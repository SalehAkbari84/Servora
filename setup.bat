@echo off
REM ===========================================================================
REM  Servora setup script (Windows)
REM
REM  Single command to take a fresh checkout to a working dev environment.
REM  Laravel migrations are the canonical schema source -- no SQL files needed.
REM
REM  Usage:
REM    setup.bat            Additive install/update -- keeps existing data
REM    setup.bat --fresh    DROPS every table + rebuilds from scratch
REM    setup.bat --help     Show this help
REM
REM  Author: Saleh Akbari (saleh.akbari.programmer@gmail.com)
REM ===========================================================================

REM Arg parsing must happen BEFORE setlocal so it is robust across cmd quirks.
if /i "%~1"=="--help"  goto show_help
if /i "%~1"=="-h"      goto show_help
if /i "%~1"=="/?"      goto show_help

chcp 65001 >nul 2>&1
setlocal enabledelayedexpansion
title Servora setup

set "ROOT=%~dp0"
set "MODE=normal"
if /i "%~1"=="--fresh" set "MODE=fresh"

echo.
echo ============================================
echo   Servora -- Initial setup
echo ============================================
echo.

REM ── Prerequisite checks ───────────────────────────────────────────────────
echo [*] Checking prerequisites...
call :check_command php       PHP        || goto err
call :check_command composer  Composer   || goto err
call :check_command node      Node.js    || goto err
call :check_command npm       npm        || goto err

REM PHP extension checks (use temp script files to avoid cmd quoting hell)
echo ^<?php exit(extension_loaded('pdo_mysql') ? 0 : 1); > "%TEMP%\srv_chk.php"
php "%TEMP%\srv_chk.php"
if errorlevel 1 (
    echo     [X] PHP extension pdo_mysql is required but not loaded.
    del "%TEMP%\srv_chk.php" >nul 2>&1
    goto err
)
echo ^<?php exit(extension_loaded('fileinfo') ? 0 : 1); > "%TEMP%\srv_chk.php"
php "%TEMP%\srv_chk.php"
if errorlevel 1 (
    echo     [!] PHP extension fileinfo is OFF -- image uploads will fail.
    echo         Open php.ini, change ;extension=fileinfo to extension=fileinfo
)
del "%TEMP%\srv_chk.php" >nul 2>&1

REM ── Backend ───────────────────────────────────────────────────────────────
echo.
echo [*] Backend -- installing composer dependencies...
pushd "%ROOT%backend"
call composer install --no-interaction --prefer-dist
if errorlevel 1 goto err_pop

REM .env from template + APP_KEY on first run
if not exist .env (
    echo [*] Creating backend\.env from .env.example
    copy /Y .env.example .env >nul
    php artisan key:generate --force >nul
    call :first_run_message
    popd
    pause
    endlocal
    exit /b 0
)

REM Pre-create the database if missing
echo [*] Ensuring database exists...
php "%ROOT%scripts\ensure-db.php"
if errorlevel 1 goto err_pop

REM ── Migrations ────────────────────────────────────────────────────────────
if /i "%MODE%"=="fresh" (
    echo.
    echo     [!] --fresh mode: this DROPS every table and rebuilds.
    echo         All data will be lost.
    set /p "CONFIRM=Type yes to continue: "
    if /i not "!CONFIRM!"=="yes" (
        echo Aborted.
        popd
        endlocal
        exit /b 0
    )
    echo [*] Running migrate:fresh...
    php artisan migrate:fresh --force
    if errorlevel 1 goto err_pop
) else (
    echo [*] Running migrate...
    php artisan migrate --force
    if errorlevel 1 goto err_pop
)

REM Primary admin -- the only account able to reach the admin panel on a fresh
REM install. Idempotent (skips if it already exists), so it is safe every run.
REM Credentials come from ADMIN_USERNAME/ADMIN_PHONE/ADMIN_PASSWORD in .env.
echo [*] Seeding primary admin...
php artisan db:seed --class=Database\Seeders\AdminSeeder --force
if errorlevel 1 goto err_pop

REM Storage symlink (for user-uploaded avatars and logos under /storage)
echo [*] Linking public storage...
php artisan storage:link 2>nul

popd

REM ── Frontend ──────────────────────────────────────────────────────────────
echo.
echo [*] Frontend -- installing npm packages, this may take a minute...
pushd "%ROOT%frontend"
call npm install --silent
if errorlevel 1 goto err_pop
popd

REM ── Summary ───────────────────────────────────────────────────────────────
echo.
echo ============================================
echo   [OK] Setup complete
echo ============================================
echo.
echo Next steps:
echo   1^) Start the backend:    cd backend  ^&^&  php artisan serve
echo   2^) Start the frontend:   cd frontend ^&^&  npm run dev
echo.
echo Or use the bundled helper:   start.bat   ^(launches both^)
echo.
echo Migration status:
echo   cd backend  ^&^&  php artisan migrate:status
echo.
endlocal
exit /b 0

REM ===========================================================================
REM  Helpers (each ends with `exit /b N`)
REM ===========================================================================

:check_command
where %1 >nul 2>nul
if errorlevel 1 (
    echo     [X] %2 not found in PATH. Install it and re-run setup.bat
    exit /b 1
)
echo     [OK] %2
exit /b 0

:first_run_message
echo.
echo --------------------------------------------------------
echo  [!] First-time setup detected.
echo      Open  backend\.env  and fill in:
echo        DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
echo        SMS_IR_API_KEY        sms.ir production key, optional
echo        VAPID_PUBLIC_KEY      web push public key, optional
echo        VAPID_PRIVATE_KEY     web push private key, optional
echo.
echo      Then run  setup.bat  again to apply migrations.
echo --------------------------------------------------------
exit /b 0

:err_pop
popd
:err
echo.
echo [X] Setup aborted with errors.
echo     See messages above, fix and re-run  setup.bat
endlocal
pause
exit /b 1

REM ===========================================================================
REM  Help (kept outside setlocal so it works even if env is unset)
REM ===========================================================================
:show_help
echo.
echo Servora setup.bat -- Laravel-migrations-driven setup
echo.
echo USAGE
echo   setup.bat                  Install everything, run migrations additively
echo                              ^(safe on an existing database^)
echo   setup.bat --fresh          DROP every table and rebuild from scratch
echo                              ^(DESTROYS DATA -- requires yes confirm^)
echo   setup.bat --help           Show this help
echo.
echo WHAT IT DOES
echo   1. Verifies PHP, Composer, Node, npm are installed
echo   2. Verifies PHP extensions pdo_mysql ^(required^) and fileinfo ^(warning^)
echo   3. backend\.env from .env.example if missing, then APP_KEY generated
echo   4. composer install
echo   5. Creates the database via scripts\ensure-db.php
echo      ^(CREATE DATABASE IF NOT EXISTS using credentials from .env^)
echo   6. php artisan migrate   ^(or migrate:fresh with --fresh^)
echo   7. php artisan storage:link
echo   8. cd frontend ^& npm install
echo.
echo FILES TOUCHED
echo   backend\.env               ^(created if missing^)
echo   backend\vendor\            ^(composer^)
echo   backend\public\storage     ^(symlink to backend\storage\app\public^)
echo   frontend\node_modules\     ^(npm^)
echo.
echo TROUBLESHOOTING
echo   See docs\SETUP.md for detailed steps and common errors.
echo   Local install guide: docs\INSTALL-LOCAL.md
echo   Hosting install guide: docs\INSTALL-HOSTING.md
echo.
exit /b 0
