@echo off
REM WebSocket Server Startup Script for Windows

SET SCRIPT_DIR=%~dp0
SET PID_FILE=%SCRIPT_DIR%server.pid
SET SERVER_SCRIPT=%SCRIPT_DIR%server.php
SET LOG_FILE=%SCRIPT_DIR%server.log
SET PHP_BINARY=C:\xampp\php\php.exe

REM Check if server is already running
if exist "%PID_FILE%" (
    echo WebSocket server is already running
    exit /b 0
)

REM Start the server in background
echo Starting WebSocket server...
start /B "%PHP_BINARY%" "%SERVER_SCRIPT%" >> "%LOG_FILE%" 2>&1

echo WebSocket server started
exit /b 0
