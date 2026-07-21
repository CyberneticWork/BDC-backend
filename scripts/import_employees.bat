@echo off
setlocal

cd /d "%~dp0.."

echo ============================================
echo  HR Employee Import (LOCAL DATABASE ONLY)
echo ============================================
echo.

if not exist ".env" (
    echo [INFO] No .env found. Creating from .env.local.example ...
    copy /Y ".env.local.example" ".env" >nul
    php artisan key:generate
    echo.
)

echo [STEP 0] Ensure local DB is migrated and employment types exist ...
php artisan migrate --force
php artisan tinker --execute="App\Models\employment_type::insertOrIgnore([['id'=>1,'name'=>'Permanent'],['id'=>2,'name'=>'Training'],['id'=>3,'name'=>'Contract'],['id'=>4,'name'=>'Daily Wages Salary'],['id'=>5,'name'=>'Probation']]); echo 'employment types ok';"
echo.

echo [STEP 1] Dry run - validate Excel without saving ...
php artisan employees:import-excel "%USERPROFILE%\Downloads\Company & Employee Personal Details.xlsx" --dry-run
if errorlevel 1 (
    echo.
    echo Dry run failed. Fix errors above before importing.
    pause
    exit /b 1
)

echo.
echo [STEP 2] Live import into LOCAL database ...
php artisan employees:import-excel "%USERPROFILE%\Downloads\Company & Employee Personal Details.xlsx"
echo.
pause
