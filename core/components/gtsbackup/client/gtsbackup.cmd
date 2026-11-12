@echo off
rem gtsBackup client for Windows 10 (1803+) / 11. Help: gtsbackup.cmd help
rem Nothing to install: PowerShell, curl.exe and tar.exe are built into Windows.
setlocal
chcp 65001 >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0gtsbackup.ps1" %*
exit /b %ERRORLEVEL%
