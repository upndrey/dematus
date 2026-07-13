@echo off
cd /d "%~dp0"

set "PHP_HOME=C:\Users\zaneo\scoop\apps\php\8.5.4"
set "PHP_INI_DIR=C:\Users\zaneo\scoop\apps\php\current\cli"
set "PHPRC=%PHP_INI_DIR%"
set "PHP_INI_SCAN_DIR=%PHP_INI_DIR%\conf.d"
set "PATH=%PHP_HOME%;%PHP_HOME%\ext;%PATH%"

"%PHP_HOME%\php.exe" -c "%PHP_INI_DIR%\php.ini" -S 127.0.0.1:8000 -t public public\index.php
