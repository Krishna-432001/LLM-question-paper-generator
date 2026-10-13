@echo off
rem Double-click to start ExamForge. Arguments are passed on, for example:
rem   start-local.bat -CheckOnly      check the PC only, start nothing
rem   start-local.bat -DryRun         show every step, change nothing
rem   start-local.bat -NoBrowser      do not open the browser at the end
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-local.ps1" %*
set "EF_EXIT=%ERRORLEVEL%"
if not "%EF_EXIT%"=="0" (
    echo.
    echo Something needs attention. Read the messages above.
    pause
)
rem On success, leave time to read the phone address before the window closes.
if "%EF_EXIT%"=="0" timeout /t 60
exit /b %EF_EXIT%
