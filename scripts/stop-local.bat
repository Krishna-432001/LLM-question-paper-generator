@echo off
rem Double-click to stop ExamForge (everything start-local.bat started).
rem   stop-local.bat -DryRun          show what would be stopped, stop nothing
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0stop-local.ps1" %*
set "EF_EXIT=%ERRORLEVEL%"
if not "%EF_EXIT%"=="0" (
    echo.
    echo Something needs attention. Read the messages above.
    pause
)
if "%EF_EXIT%"=="0" timeout /t 10
exit /b %EF_EXIT%
