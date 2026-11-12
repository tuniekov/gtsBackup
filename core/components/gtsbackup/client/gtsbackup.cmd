@echo off
rem gtsBackup client. Help: gtsbackup.cmd help
rem Needs PHP 7.4+ with curl and zlib. php.exe: env GTSBACKUP_PHP,
rem or php\php.exe next to this file, or php from PATH.
setlocal
chcp 65001 >nul
set "GB_DIR=%~dp0"
if not defined GTSBACKUP_PHP (
    if exist "%GB_DIR%php\php.exe" (
        set "GTSBACKUP_PHP=%GB_DIR%php\php.exe"
    ) else (
        set "GTSBACKUP_PHP=php"
    )
)
"%GTSBACKUP_PHP%" -d memory_limit=1024M -d max_execution_time=0 "%GB_DIR%gtsbackup.php" %*
exit /b %ERRORLEVEL%
