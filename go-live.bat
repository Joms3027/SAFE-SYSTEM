@echo off
title WPU SAFE System - Go Live
echo.
echo ========================================
echo   WPU SAFE Profile System - Go Live
echo ========================================
echo.

set BASE_URL=http://localhost/SAFE_SYSTEM/FP
set XAMPP=c:\xampp

:: Start Apache if not running (XAMPP default path)
if exist "%XAMPP%\apache_start.bat" (
    echo Starting Apache...
    start /B "" "%XAMPP%\apache_start.bat"
    timeout /t 2 /nobreak >nul
)
if exist "%XAMPP%\mysql_start.bat" (
    echo Starting MySQL...
    start /B "" "%XAMPP%\mysql_start.bat"
    timeout /t 2 /nobreak >nul
)

echo.
echo Opening system in browser: %BASE_URL%
echo.
echo If the page does not load:
echo   1. Open XAMPP Control Panel and start Apache + MySQL
echo   2. Ensure database "wpu_faculty_system" exists (import wpu_faculty_system_full.sql if needed)
echo   3. Visit: %BASE_URL%
echo.

start "" "%BASE_URL%/"

exit /b 0
