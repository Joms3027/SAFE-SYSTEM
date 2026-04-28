@echo off
REM Hourly database backup - run via Task Scheduler every hour
php "%~dp0backup_database.php"
if errorlevel 1 exit /b 1
exit /b 0
