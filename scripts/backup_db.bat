@echo off
REM =====================================================================
REM U EPMS - Nightly Database Backup (item 28)
REM Dumps factory_db to C:\epms-backups, keeps the newest 7 files.
REM Schedule with Windows Task Scheduler:
REM   schtasks /create /tn "EPMS Nightly Backup" /tr "C:\xampp\htdocs\U EPMS\scripts\backup_db.bat" /sc daily /st 23:30
REM =====================================================================

set DB_USER=epms_backup
set DB_PASS=EpmsBak#2026Local
REM (The dedicated backup account - item 29. Override by editing these two lines.)

set BACKUP_DIR=C:\epms-backups
set MYSQL=C:\xampp\mysql\bin\mysqldump.exe
set STAMP=%date:~6,4%-%date:~3,2%-%date:~0,2%_%time:~0,2%-%time:~3,2%
set STAMP=%STAMP: =0%

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

"%MYSQL%" -u %DB_USER% -p%DB_PASS% -h 127.0.0.1 --single-transaction --routines --triggers factory_db > "%BACKUP_DIR%\factory_db_%STAMP%.sql"

if %errorlevel% neq 0 (
    echo [%date% %time%] BACKUP FAILED >> "C:\xampp\htdocs\U EPMS\logs\backup.log"
    exit /b 1
)

echo [%date% %time%] Backup OK: factory_db_%STAMP%.sql >> "C:\xampp\htdocs\U EPMS\logs\backup.log"

REM Keep only the newest 7 backups
powershell -NoProfile -Command "Get-ChildItem 'C:\epms-backups\factory_db_*.sql' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 7 | Remove-Item -Force"
exit /b 0
