@echo off
setlocal enabledelayedexpansion

REM ============================================================
REM   FlowOne Pro - One-click Android APK builder
REM   - installs deps (mobile + frontend) if missing
REM   - builds the web bundle (vite)
REM   - syncs Capacitor (npx cap sync android)
REM   - builds the debug APK (gradlew assembleDebug)
REM   - copies the APK next to this script for easy transfer
REM   Just double-click this file.
REM ============================================================

REM Locate the FlowOneMobile project dir whether this script lives INSIDE it
REM or one level up (e.g. in the email\ folder). This makes the script work
REM no matter where it is double-clicked from.
set "MOBILE_DIR="
if exist "%~dp0capacitor.config.ts" set "MOBILE_DIR=%~dp0"
if not defined MOBILE_DIR if exist "%~dp0FlowOneMobile\capacitor.config.ts" set "MOBILE_DIR=%~dp0FlowOneMobile\"
if not defined MOBILE_DIR (
    echo [ERROR] Could not find the FlowOneMobile project ^(capacitor.config.ts^).
    echo         Keep this script in the FlowOneMobile folder ^(or its parent^).
    goto :fail
)
cd /d "%MOBILE_DIR%"

title FlowOne Pro - Build Android APK

echo ============================================================
echo    FlowOne Pro - Build Android APK (debug)
echo ============================================================
echo.

REM --- Toolchain fallbacks (only set if not already in the environment) ---
if not defined JAVA_HOME set "JAVA_HOME=D:\dev\jdk17"
if not defined ANDROID_HOME set "ANDROID_HOME=D:\dev\android-sdk"
set "PATH=%JAVA_HOME%\bin;%ANDROID_HOME%\platform-tools;%PATH%"

echo [INFO] JAVA_HOME    = %JAVA_HOME%
echo [INFO] ANDROID_HOME = %ANDROID_HOME%
echo.

REM --- Make sure npm / node are available ------------------------------
where npm >nul 2>nul
if errorlevel 1 (
    echo [ERROR] npm / Node.js was not found in PATH.
    echo         Install Node.js 18+ from https://nodejs.org and try again.
    goto :fail
)

REM --- Make sure a JDK is available ------------------------------------
if not exist "%JAVA_HOME%\bin\java.exe" (
    echo [ERROR] No JDK found at %JAVA_HOME%.
    echo         Set JAVA_HOME to a JDK 17 install and try again.
    goto :fail
)

REM --- Make sure the Android SDK is available --------------------------
if not exist "%ANDROID_HOME%\platform-tools" (
    echo [ERROR] Android SDK not found at %ANDROID_HOME%.
    echo         Install the Android SDK ^(via Android Studio^) and set ANDROID_HOME.
    goto :fail
)

REM --- Warn if Firebase config is missing (push won't work without it) -
if not exist "android\app\google-services.json" (
    echo [WARN] android\app\google-services.json is MISSING.
    echo        The APK will still build, but FCM push notifications will NOT
    echo        work until you download google-services.json from the Firebase
    echo        console and place it in android\app\.
    echo.
)

REM --- Install mobile app dependencies if missing ----------------------
if not exist "node_modules" (
    echo [STEP] Installing FlowOneMobile dependencies...
    call npm install
    if errorlevel 1 goto :fail
    echo.
)

REM --- Install frontend dependencies if missing (build:web needs them) -
if not exist "..\frontend\node_modules" (
    echo [STEP] Installing frontend dependencies...
    pushd "..\frontend"
    call npm install
    if errorlevel 1 (
        popd
        goto :fail
    )
    popd
    echo.
)

REM --- Build web bundle + sync Capacitor ------------------------------
echo [STEP] Building web bundle and syncing Capacitor (android)...
call npm run build:android
if errorlevel 1 goto :fail
echo.

REM --- Build the debug APK with Gradle --------------------------------
echo [STEP] Building debug APK (gradlew assembleDebug)...
pushd "android"
call gradlew.bat assembleDebug
if errorlevel 1 (
    popd
    goto :fail
)
popd
echo.

REM --- Locate and copy the APK next to this script --------------------
set "APK_SRC=%cd%\android\app\build\outputs\apk\debug\app-debug.apk"
set "APK_OUT=%cd%\FlowOne-Pro-debug.apk"

if not exist "%APK_SRC%" (
    echo [ERROR] Build reported success but the APK was not found at:
    echo         %APK_SRC%
    goto :fail
)

copy /Y "%APK_SRC%" "%APK_OUT%" >nul

echo ============================================================
echo    BUILD COMPLETE
echo ============================================================
echo APK:
echo    %APK_OUT%
echo.

REM --- Optionally install on a connected device via ADB ---------------
set "ADB=%ANDROID_HOME%\platform-tools\adb.exe"
if exist "%ADB%" (
    set "DEVICE="
    for /f "skip=1 tokens=1,2" %%a in ('"%ADB%" devices') do (
        if "%%b"=="device" set "DEVICE=%%a"
    )
    if defined DEVICE (
        echo [INFO] Connected device detected: !DEVICE!
        set "DOINSTALL="
        set /p "DOINSTALL=Install the APK on it now? [y/N]: "
        if /i "!DOINSTALL!"=="y" (
            echo [STEP] Installing on !DEVICE!...
            "%ADB%" install -r "%APK_OUT%"
        )
        echo.
    ) else (
        echo [INFO] No Android device connected over ADB.
        echo        To install: enable USB debugging, plug in the phone, then run:
        echo            "%ADB%" install -r "%APK_OUT%"
        echo        Or copy FlowOne-Pro-debug.apk to the phone and tap to install.
        echo.
    )
)

REM --- Open Explorer with the new APK selected ------------------------
if exist "%APK_OUT%" (
    explorer /select,"%APK_OUT%"
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
