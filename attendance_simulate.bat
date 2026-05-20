@echo off
setlocal enabledelayedexpansion

:: ==========================================
:: CONFIGURATION
:: ==========================================
set "API_URL=http://127.0.0.1:8000/api/time-cards"
set "EMPLOYEE_ID=1"
set "START_DATE=2026-05-20"
set "END_DATE=2026-06-19"

:: ==========================================
:: TIME ARRAYS (Add or modify variation profiles here)
:: ==========================================
set IN_TIMES[0]=08:02
set IN_TIMES[1]=08:14
set IN_TIMES[2]=08:28
set IN_TIMES[3]=07:55
set IN_TIMES[4]=08:07
set IN_TIMES_COUNT=5

set OUT_TIMES[0]=17:01
set OUT_TIMES[1]=17:30
set OUT_TIMES[2]=18:00
set OUT_TIMES[3]=18:45
set OUT_TIMES[4]=18:15
set OUT_TIMES_COUNT=5

echo ======================================================
echo  Starting Attendance Simulation Engine (Randomized)
echo  Target Employee ID: %EMPLOYEE_ID%
echo  Period: %START_DATE% to %END_DATE%
echo ======================================================

set "current_date=%START_DATE%"

:LOOP
:: Use PowerShell to calculate day of week index (1 = Monday, ..., 6 = Saturday, 7 = Sunday)
for /f %%A in ('powershell -Command "[int](Get-Date '%current_date%').DayOfWeek"') do set "day_index=%%A"
for /f %%B in ('powershell -Command "(Get-Date '%current_date%').ToString('dddd')"') do set "day_name=%%B"

:: Check if it's a weekday (1, 2, 3, 4, or 5)
if %day_index% GEQ 1 if %day_index% LEQ 5 (
    echo ------------------------------------------------------
    echo Processing Weekday: %current_date% (!day_name!)
    echo ------------------------------------------------------

    :: Generate random array pointers using native %RANDOM% engine
    set /a "rand_in_idx=!RANDOM! %% %IN_TIMES_COUNT%"
    set /a "rand_out_idx=!RANDOM! %% %OUT_TIMES_COUNT%"

    :: Extract the random strings
    for %%i in (!rand_in_idx!) do set "chosen_in_time=!IN_TIMES[%%i]!"
    for %%j in (!rand_out_idx!) do set "chosen_out_time=!OUT_TIMES[%%j]!"

    echo -^> Sending IN Punch (!chosen_in_time!)...
    curl --request POST ^
      --url "%API_URL%" ^
      --header "Content-Type: application/json" ^
      --header "Accept: application/json" ^
      --data "{\"employee_id\": %EMPLOYEE_ID%, \"date\": \"%current_date%\", \"time\": \"!chosen_in_time!\", \"entry\": \"1\", \"status\": \"IN\"}"

    echo.
    echo.

    echo -^> Sending OUT Punch (!chosen_out_time!)...
    curl --request POST ^
      --url "%API_URL%" ^
      --header "Content-Type: application/json" ^
      --header "Accept: application/json" ^
      --data "{\"employee_id\": %EMPLOYEE_ID%, \"date\": \"%current_date%\", \"time\": \"!chosen_out_time!\", \"entry\": \"2\", \"status\": \"OUT\"}"

    echo.
    echo.
) else (
    echo Skipping Weekend: %current_date% (!day_name!)
)

:: Increment date by +1 day using PowerShell arithmetic
for /f %%C in ('powershell -Command "(Get-Date '%current_date%').AddDays(1).ToString('yyyy-MM-dd')"') do set "next_date=%%C"

:: Check boundary conditions
if "%current_date%"=="%END_DATE%" goto END
set "current_date=%next_date%"
goto LOOP

:END
echo ======================================================
echo  Simulation Complete! Check your DBeaver tables.
echo ======================================================
pause
