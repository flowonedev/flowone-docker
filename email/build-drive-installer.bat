@echo off
setlocal enabledelayedexpansion

REM ============================================================
REM   FlowOne Drive - One-click Windows installer builder
REM   - bumps the version in package.json
REM   - compiles main + preload + renderer
REM   - builds the NSIS installer only (.exe)
REM   Just double-click this file.
REM ============================================================

REM Always run from this script's own folder (the FlowOneDrive project root)
cd /d "%~dp0"

title FlowOne Drive - Build Installer

echo ============================================================
echo    FlowOne Drive - Build Windows Installer
echo ============================================================
echo.

REM --- Make sure npm / node are available -------------------------------
where npm >nul 2>nul
if errorlevel 1 (
    echo [ERROR] npm / Node.js was not found in PATH.
    echo         Install Node.js 18+ from https://nodejs.org and try again.
    goto :fail
)

REM --- Pick the version bump type --------------------------------------
REM Accept it as an argument ^(patch^|minor^|major^|none^) or ask interactively.
set "BUMP=%~1"
if not "%BUMP%"=="" goto :have_bump

echo Select version bump type:
echo    [1] patch   ( x.y.Z  +1 )   [default]
echo    [2] minor   ( x.Y.0  +1 )
echo    [3] major   ( X.0.0  +1 )
echo    [4] none    ( keep current version )
echo.
set "CHOICE="
set /p "CHOICE=Enter choice [1]: "
if "!CHOICE!"=="" set "CHOICE=1"
if "!CHOICE!"=="1" set "BUMP=patch"
if "!CHOICE!"=="2" set "BUMP=minor"
if "!CHOICE!"=="3" set "BUMP=major"
if "!CHOICE!"=="4" set "BUMP=none"

:have_bump
if not "%BUMP%"=="patch" if not "%BUMP%"=="minor" if not "%BUMP%"=="major" if not "%BUMP%"=="none" (
    echo [ERROR] Invalid bump type "%BUMP%". Use patch, minor, major, or none.
    goto :fail
)
echo.

REM --- Install dependencies if they are missing ------------------------
if not exist "node_modules" (
    echo [STEP] node_modules not found - installing dependencies...
    call npm install
    if errorlevel 1 goto :fail
    echo.
)

REM --- Bump the version -------------------------------------------------
if /i not "%BUMP%"=="none" (
    echo [STEP] Bumping version ^(%BUMP%^)...
    call npm version %BUMP% --no-git-tag-version
    if errorlevel 1 goto :fail
    echo.
)

REM --- Read the resulting version from package.json --------------------
for /f "delims=" %%v in ('node -p "require('./package.json').version"') do set "VERSION=%%v"
echo [INFO] Building FlowOne Drive v!VERSION!
echo.

REM --- Compile main + preload + renderer -------------------------------
echo [STEP] Compiling app ^(main + preload + renderer^)...
call npm run build
if errorlevel 1 goto :fail
echo.

REM --- Build the Windows installer only --------------------------------
echo [STEP] Building Windows NSIS installer...
call npx electron-builder --win nsis
if errorlevel 1 goto :fail
echo.

set "INSTALLER=%cd%\release\FlowOne Drive-!VERSION!-x64.exe"

echo ============================================================
echo    BUILD COMPLETE  -  v!VERSION!
echo ============================================================
echo Installer:
echo    !INSTALLER!
echo.

REM --- Open Explorer with the new installer selected -------------------
if exist "!INSTALLER!" (
    explorer /select,"!INSTALLER!"
) else (
    echo [WARN] Expected installer file was not found - opening release folder.
    explorer "%cd%\release"
)

echo Done. Press any key to close this window.
pause >nul
exit /b 0

:fail
echo.
echo ============================================================
echo    BUILD FAILED  -  see the messages above
echo ============================================================
echo Press any key to close this window.
pause >nul
exit /b 1
