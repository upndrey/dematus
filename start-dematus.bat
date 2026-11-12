@echo off
setlocal

cd /d "%~dp0"

if /I "%~1"=="stop" (
    echo Stopping Dematus local servers...
    powershell -NoProfile -ExecutionPolicy Bypass -Command ^
      "$ports = 8000,5173,5174; foreach ($port in $ports) { $connections = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue; foreach ($connection in $connections) { Stop-Process -Id $connection.OwningProcess -Force -ErrorAction SilentlyContinue } }; Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -match 'artisan queue:work database --queue=stratz-import' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"
    echo Done.
    pause
    endlocal
    exit /b 0
)

set "PHP_BIN=C:\Users\zaneo\scoop\shims\php.exe"
set "PHP_HOME=C:\Users\zaneo\scoop\apps\php\8.5.4"
set "PHP_INI_DIR=C:\Users\zaneo\scoop\apps\php\current\cli"
set "NODE_BIN=C:\Users\zaneo\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe"
set "PHPRC=%PHP_INI_DIR%"
set "PHP_INI_SCAN_DIR=%PHP_INI_DIR%\conf.d"
set "PATH=%PHP_HOME%;%PHP_HOME%\ext;%PATH%"

echo Starting Dematus...
echo.
echo Dematus will build the frontend and start two local services in this window:
echo - Laravel backend: http://127.0.0.1:8000
echo - STRATZ import queue worker
echo.
echo Keep those windows open while you use Dematus.
echo To stop Dematus later, run start-dematus.bat stop.
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

echo Building frontend...
if exist "public\hot" del /f /q "public\hot"
"%NODE_BIN%" node_modules\vite\bin\vite.js build
if errorlevel 1 (
    echo Frontend build failed. Tell Codex about this message.
    pause
    exit /b 1
)

echo Checking STRATZ connection...
"%PHP_BIN%" artisan dematus:check-stratz
if errorlevel 1 (
    set "STRATZ_AVAILABLE=0"
    echo.
    echo WARNING: Dematus cannot reach STRATZ from this Windows session.
) else (
    set "STRATZ_AVAILABLE=1"
)

echo.

powershell -NoProfile -Command "exit [int]($null -ne (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1))"
if errorlevel 1 (
    echo Laravel backend is already running.
) else (
    start "" /B "%PHP_BIN%" -c "%PHP_INI_DIR%\php.ini" artisan serve --host=127.0.0.1 --port=8000 > "storage\logs\dematus-backend.log" 2>&1
)

if "%STRATZ_AVAILABLE%"=="1" (
    powershell -NoProfile -Command "$worker = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -match 'artisan queue:work database --queue=stratz-import' } | Select-Object -First 1; exit [int]($null -ne $worker)"
    if errorlevel 1 (
        echo STRATZ import worker is already running.
    ) else (
        start "" /B powershell -NoProfile -ExecutionPolicy Bypass -Command "$php = '%PHP_BIN%'; $ini = '%PHP_INI_DIR%\php.ini'; while ($true) { & $php -d memory_limit=512M -c $ini artisan queue:work database --queue=stratz-import --once --tries=12 --timeout=180; Start-Sleep -Seconds 1 }" > "storage\logs\dematus-queue.log" 2>&1
    )
) else (
    echo STRATZ import worker was not started. Restore STRATZ access, then run start-dematus.bat again.
)

timeout /t 3 >nul
start http://127.0.0.1:8000

echo.
echo All Dematus services are running in this one window.
echo To stop them, run start-dematus.bat stop.
pause

endlocal
