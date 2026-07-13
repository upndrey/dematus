@echo off
setlocal

cd /d "%~dp0"

set "PHP_BIN=C:\Users\zaneo\scoop\shims\php.exe"
set "PHP_HOME=C:\Users\zaneo\scoop\apps\php\8.5.4"
set "PHP_INI_DIR=C:\Users\zaneo\scoop\apps\php\current\cli"
set "NODE_BIN=C:\Users\zaneo\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe"
set "PHPRC=%PHP_INI_DIR%"
set "PHP_INI_SCAN_DIR=%PHP_INI_DIR%\conf.d"
set "PATH=%PHP_HOME%;%PHP_HOME%\ext;%PATH%"

echo Starting Dematus...
echo.
echo Dematus will open two local windows:
echo - Laravel backend: http://127.0.0.1:8000
echo - Vite frontend helper: http://127.0.0.1:5173
echo.
echo Keep those windows open while you use Dematus.
echo To stop Dematus later, run stop-dematus.bat.
echo.

if not exist "%PHP_BIN%" (
    echo PHP was not found at: %PHP_BIN%
    echo Tell Codex about this message.
    pause
    exit /b 1
)

if not exist "%NODE_BIN%" (
    echo Node.js was not found at: %NODE_BIN%
    echo Tell Codex about this message.
    pause
    exit /b 1
)

"%PHP_BIN%" -r "exit(extension_loaded('curl') ? 0 : 1);"
if errorlevel 1 (
    echo PHP curl extension is not enabled.
    echo.
    "%PHP_BIN%" --ini
    echo.
    "%PHP_BIN%" -m
    echo.
    echo Tell Codex about this message.
    pause
    exit /b 1
)

"%PHP_BIN%" artisan config:clear --no-interaction

echo Checking STRATZ connection...
"%PHP_BIN%" artisan dematus:check-stratz
if errorlevel 1 (
    echo.
    echo Dematus cannot reach STRATZ from this Windows session.
    echo Do not use the site until this check says STRATZ OK.
    pause
    exit /b 1
)

echo.

start "Dematus backend" "%~dp0run-dematus-backend.bat"
start "Dematus frontend" "%~dp0run-dematus-frontend.bat"

timeout /t 3 >nul
start http://127.0.0.1:8000

endlocal
