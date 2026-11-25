@echo off
setlocal EnableExtensions EnableDelayedExpansion
title CSU UniScholar - apply the Nov 22 update

rem ==========================================================================
rem  CSU UniScholar update (Nov 22, 2026): processing batches, unmatched rows,
rem  scholar records per school year, Data Bank filters, demo reset.
rem
rem  HOW TO USE
rem   1. Extract this zip INTO your project folder (C:\Projects\CSU-Unischolar),
rem      so backend\, frontend\ and ai-service\ merge with yours. Say "Yes"
rem      to replace the files.
rem   2. Start MySQL in XAMPP.
rem   3. Double-click APPLY_UPDATE.bat (in the project folder).
rem
rem  It will: back up the database, run the new migrations (they only ADD
rem  tables and columns), clear Laravel's cache, run the backend tests, the AI
rem  tests and a frontend build. It never runs migrate:fresh and never pushes
rem  anything to GitHub.
rem ==========================================================================

cd /d "%~dp0"

if not exist "backend\artisan" (
  echo [STOP] backend\artisan was not found next to this file.
  echo        Extract the zip into the project folder ^(C:\Projects\CSU-Unischolar^) and run it from there.
  goto :fail
)
if not exist "backend\.env" (
  echo [STOP] backend\.env was not found. This must be your working project folder.
  goto :fail
)

where php >nul 2>nul
if errorlevel 1 (
  if exist "C:\xampp\php\php.exe" (
    set "PATH=C:\xampp\php;%PATH%"
  ) else (
    echo [STOP] PHP was not found. Add C:\xampp\php to PATH or install XAMPP.
    goto :fail
  )
)

rem ---- read the database settings from backend\.env -------------------------
set "DB_CONNECTION=" & set "DB_DATABASE=" & set "DB_USERNAME=" & set "DB_PASSWORD=" & set "DB_HOST=" & set "DB_PORT="
for /f "usebackq eol=# tokens=1,* delims==" %%A in ("backend\.env") do (
  if /i "%%A"=="DB_CONNECTION" set "DB_CONNECTION=%%B"
  if /i "%%A"=="DB_DATABASE" set "DB_DATABASE=%%B"
  if /i "%%A"=="DB_USERNAME" set "DB_USERNAME=%%B"
  if /i "%%A"=="DB_PASSWORD" set "DB_PASSWORD=%%B"
  if /i "%%A"=="DB_HOST" set "DB_HOST=%%B"
  if /i "%%A"=="DB_PORT" set "DB_PORT=%%B"
)
set "DB_CONNECTION=%DB_CONNECTION:"=%"
set "DB_DATABASE=%DB_DATABASE:"=%"
set "DB_USERNAME=%DB_USERNAME:"=%"
set "DB_PASSWORD=%DB_PASSWORD:"=%"
if "%DB_HOST%"=="" set "DB_HOST=127.0.0.1"
if "%DB_PORT%"=="" set "DB_PORT=3306"

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "STAMP=%%i"
if not exist "backups" mkdir "backups"

echo.
echo ===== 1/6  Backing up the database =====
if /i "%DB_CONNECTION%"=="sqlite" (
  if exist "backend\database\database.sqlite" (
    copy /y "backend\database\database.sqlite" "backups\database-before-batches-%STAMP%.sqlite" >nul
    echo Saved backups\database-before-batches-%STAMP%.sqlite
  ) else (
    echo No SQLite file to back up.
  )
) else (
  set "DUMP=mysqldump"
  where mysqldump >nul 2>nul
  if errorlevel 1 set "DUMP=C:\xampp\mysql\bin\mysqldump.exe"
  set "PASSARG="
  if not "%DB_PASSWORD%"=="" set "PASSARG=-p%DB_PASSWORD%"
  "!DUMP!" -h %DB_HOST% -P %DB_PORT% -u %DB_USERNAME% !PASSARG! --routines --single-transaction %DB_DATABASE% > "backups\db-before-batches-%STAMP%.sql"
  if errorlevel 1 (
    echo [STOP] The database backup failed. Is MySQL running in XAMPP? Nothing was changed.
    goto :fail
  )
  for %%F in ("backups\db-before-batches-%STAMP%.sql") do if %%~zF LSS 1000 (
    echo [STOP] The backup file is almost empty. Check that MySQL is running. Nothing was changed.
    goto :fail
  )
  echo Saved backups\db-before-batches-%STAMP%.sql
)

echo.
echo ===== 2/6  Running the new migrations (adds tables and columns only) =====
pushd backend
call php artisan migrate --force
if errorlevel 1 (
  popd
  echo [STOP] A migration failed. Your data is in backups\ ^(restore it in phpMyAdmin: Import^).
  goto :fail
)
call php artisan migrate:status | findstr /c:"2026_11_22"

echo.
echo ===== 3/6  Clearing Laravel's cache =====
call php artisan optimize:clear

echo.
echo ===== 4/6  Backend tests (on a separate test database, your data is not touched) =====
call php artisan test
if errorlevel 1 (set "BACKEND_TESTS=FAILED") else (set "BACKEND_TESTS=passed")
popd

echo.
echo ===== 5/6  AI-service tests =====
set "AI_TESTS=skipped (Python not found)"
where python >nul 2>nul
if not errorlevel 1 (
  pushd ai-service
  set "PY=python"
  if exist "venv\Scripts\python.exe" set "PY=venv\Scripts\python.exe"
  if exist ".venv\Scripts\python.exe" set "PY=.venv\Scripts\python.exe"
  echo Installing the AI-service packages ^(adds pytesseract^)...
  call !PY! -m pip install -q -r requirements.txt
  call !PY! -m pytest -q
  if errorlevel 1 (set "AI_TESTS=FAILED") else (set "AI_TESTS=passed")
  popd
)

echo.
echo ===== 6/6  Frontend build check =====
set "FRONTEND=skipped (npm not found)"
where npm >nul 2>nul
if not errorlevel 1 (
  pushd frontend
  if not exist "node_modules" call npm install
  call npm run build
  if errorlevel 1 (set "FRONTEND=FAILED") else (set "FRONTEND=built")
  popd
)

echo.
echo ==========================================================================
echo  DONE
echo    Database backup : backups\  (%STAMP%)
echo    Migrations      : done
echo    Backend tests   : %BACKEND_TESTS%
echo    AI tests        : %AI_TESTS%
echo    Frontend        : %FRONTEND%
echo.
echo  Next: start the system as usual (php artisan serve, the AI service,
echo  npm run dev) and open Processing Batches in the staff sidebar.
echo  OCR: install Tesseract once:  winget install -e --id UB-Mannheim.TesseractOCR
echo  then check http://127.0.0.1:8001/health  ^(ocr_engine must not be none^).
echo  Optional in backend\.env:  MAIL_MAILER=log   and, on the demo laptop
echo  only, DEMO_RESET_ALLOWED=true  (then: php artisan demo:reset).
echo  Check the changes with "git status" before you commit.
echo ==========================================================================
pause
exit /b 0

:fail
echo.
pause
exit /b 1
