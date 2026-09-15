@echo off
rem ============================================================
rem  U EPMS - One-click launcher
rem  Starts the PHP dev server and opens the app in the browser.
rem  Close this window to stop the server.
rem ============================================================
cd /d "%~dp0"

echo.
echo  Starting U EPMS on http://127.0.0.1:3000 ...
echo  (Login: gimeno / factory123  -  close this window to stop)
echo.

start "" cmd /c "timeout /t 2 >nul && start http://127.0.0.1:3000"
C:\xampp\php\php.exe -S 127.0.0.1:3000 router.php
