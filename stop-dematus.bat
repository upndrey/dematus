@echo off
setlocal

echo Stopping Dematus local servers...

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ports = 8000,5173; foreach ($port in $ports) { $connections = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue; foreach ($connection in $connections) { Stop-Process -Id $connection.OwningProcess -Force -ErrorAction SilentlyContinue } }"

echo Done.
pause

endlocal
