#Requires -Version 5.1
<#
================================================================================
  FlowOne Email — Local Development Setup
================================================================================

  WHO IS THIS FOR
  ---------------
  New developers joining the project. Run this ONCE on a fresh machine, and
  it'll bring up the full local stack so you can start coding immediately.
  You can also run it again later — it skips any step that's already done.


--------------------------------------------------------------------------------
  STEP 0 — BEFORE YOU RUN THIS SCRIPT
--------------------------------------------------------------------------------

  Install these (free):
    1. Docker Desktop ............ https://www.docker.com/products/docker-desktop
       After installing, OPEN Docker Desktop and let it start.
       The whale icon in the system tray must be solid (not animating).
    2. Node.js 18 or newer ....... https://nodejs.org   (LTS version is fine)
    3. Git ....................... https://git-scm.com

  Get the project on your machine:
    git clone <repo-url-from-Robert>
    cd <the-cloned-folder>/email

  Get the DB dump from Robert (a single .sql file).
    Drop it INSIDE the email/ folder next to this script.
    Rename it to:   db-dump.sql
    (without it the app starts but most pages will look broken — no users,
     no boards, no saved settings.)

  Allow PowerShell to run local scripts (one-time, per user):
    Open PowerShell and run:
      Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
    Answer Y when prompted. You only need to do this once on this machine.


--------------------------------------------------------------------------------
  STEP 1 — RUN THIS SCRIPT
--------------------------------------------------------------------------------

  In PowerShell, from the email/ folder:
    .\setup-local.ps1

  If your dump is named something other than db-dump.sql:
    .\setup-local.ps1 -DbDump 'C:\path\to\my-dump.sql'

  When it finishes, open the URL it prints (http://localhost:3001) and log in
  with your real email + password (the live IMAP creds — local talks to the
  live mail server for IMAP since there's no local IMAP server).


--------------------------------------------------------------------------------
  WHAT THIS SCRIPT DOES (in order)
--------------------------------------------------------------------------------
    1. Verifies Docker + Node.js are installed and Docker is actually running.
    2. Creates three local-only config files (gitignored — never reach live):
         - backend/.env             ......... PHP backend env vars
         - mailsync/server/.env     ......... Node WebSocket server env vars
         - frontend/.env.local      ......... points the WS at localhost,
                                              not production
       The JWT secret is the SAME across all three on purpose. If they ever
       get out of sync you'll get login working but bootstrap returning 401.
    3. Builds and starts the Docker stack (MySQL, Redis, Meilisearch, PHP).
    4. Waits for MySQL to come up, then imports the DB dump (if present and
       the DB is still empty).
    5. Hits the PHP container once to trigger the auto-migration runner that
       brings the imported schema up to the version the code expects.
    6. Runs `npm install` in the frontend and the mailsync server.
    7. Opens TWO new PowerShell windows:
         - mailsync server (Node)         keep this open while you work
         - Vite dev server (frontend)     keep this open while you work
    8. Prints the URLs you'll use day to day.


--------------------------------------------------------------------------------
  STEP 2 — DAY-TO-DAY (after first run)
--------------------------------------------------------------------------------

  You can re-run .\setup-local.ps1 any time — it skips work that's already
  done. Or use these directly:

  Start everything (after a reboot, etc.):
    docker compose -f docker-compose.local.yml up -d        # backend stack
    cd mailsync\server ; npm start                          # window 1
    cd frontend        ; npm run dev                        # window 2

  Stop everything:
    docker compose -f docker-compose.local.yml down
    (and Ctrl+C / close the two npm windows)

  See what containers are running:
    docker compose -f docker-compose.local.yml ps

  Watch backend (PHP) logs in real time — extremely useful when debugging:
    docker compose -f docker-compose.local.yml logs -f php

  Watch DB logs:
    docker compose -f docker-compose.local.yml logs -f db

  Open a MySQL shell on the local DB:
    docker compose -f docker-compose.local.yml exec db mysql -uflowone -pflowone flowone_local

  Wipe + re-import the DB (e.g. Robert sent a fresh dump):
    docker compose -f docker-compose.local.yml down -v      # -v drops volumes
    .\setup-local.ps1                                       # re-imports

  After pulling new code (git pull), the volume mount on backend/ means PHP
  changes are live immediately. Rebuild the PHP image only if Dockerfile.local
  or apache.local.conf changed:
    docker compose -f docker-compose.local.yml up -d --build php


--------------------------------------------------------------------------------
  TROUBLESHOOTING
--------------------------------------------------------------------------------
    * "Cannot connect to the Docker daemon" / "docker: command not found"
        => Docker Desktop isn't installed or isn't running. Start it and wait
           until the whale icon stops animating.

    * Login works but everything 401s in the browser console
        => Migrations probably didn't finish. Refresh the page once and the
           PHP container will run pending migrations automatically. If it
           persists, check:
             docker compose -f docker-compose.local.yml logs --tail=80 php

    * Dev console floods with /auth/me requests
        => frontend/.env.local was missing or wrong. Delete it and re-run this
           script. The file should contain:
             VITE_MAILSYNC_WS_URL=ws://localhost:3001/mailsync_ws

    * Addons don't appear after onboarding
        => The Panel API isn't reachable locally (expected). The backend
           reads your selections from the user-settings JSON file on disk.
           If they still don't show up, hard-refresh (Ctrl+Shift+R) and
           clear the addon cache:
             docker compose -f docker-compose.local.yml exec redis redis-cli FLUSHDB

    * 409 Conflict on creating a board/space/etc.
        => Not a bug — the imported live DB already contains items with that
           name. Use a different name, or work with what's already there.

    * Port already in use (3001, 8000, 3306, 6379, 7700, 1235)
        => Something else on your machine is using the port. Stop it, or
           edit docker-compose.local.yml / vite.config.js / mailsync .env
           to use different ports.

================================================================================
#>

param(
    # Optional path to the DB dump. If omitted, the script looks for
    # db-dump.sql / dump.sql / flowone.sql in the current folder.
    [string]$DbDump = ""
)

$ErrorActionPreference = "Stop"

# Always operate from this script's folder, no matter where it was invoked.
Set-Location -LiteralPath $PSScriptRoot

# -----------------------------------------------------------------------------
# Tiny helpers for nicer output
# -----------------------------------------------------------------------------
function Write-Step { param($Msg) Write-Host "`n>>> $Msg" -ForegroundColor Cyan }
function Write-Ok   { param($Msg) Write-Host "    OK   $Msg" -ForegroundColor Green }
function Write-Warn { param($Msg) Write-Host "    WARN $Msg" -ForegroundColor Yellow }
function Write-Err  { param($Msg) Write-Host "    ERR  $Msg" -ForegroundColor Red }

function Test-Command {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

# Wraps `docker compose ...` so we don't repeat the -f flag fifteen times.
function Invoke-DC {
    param([Parameter(ValueFromRemainingArguments=$true)]$Args)
    & docker compose -f docker-compose.local.yml @Args
}

# -----------------------------------------------------------------------------
# 1. Prerequisites — fail fast with a clear message if anything is missing.
# -----------------------------------------------------------------------------
Write-Step "Checking prerequisites"

if (-not (Test-Command "docker")) {
    Write-Err "Docker is not installed."
    Write-Host "    Install Docker Desktop: https://www.docker.com/products/docker-desktop"
    exit 1
}
if (-not (Test-Command "node")) {
    Write-Err "Node.js is not installed."
    Write-Host "    Install Node 18+: https://nodejs.org"
    exit 1
}

# `docker info` fails if the daemon isn't running. Suppress its noisy output.
docker info *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Err "Docker is installed but not running. Start Docker Desktop and re-run."
    exit 1
}
Write-Ok "Docker + Node.js available"

# -----------------------------------------------------------------------------
# 2. Local config files (all gitignored — they never reach the live server).
# -----------------------------------------------------------------------------
Write-Step "Creating local config files (gitignored, never ship to live)"

# Shared JWT secret. The PHP backend, the Node mailsync server, and any other
# service that signs/verifies JWTs MUST agree on this value, otherwise tokens
# minted by one are rejected by the others. Same value as docker-compose.local.yml.
$JwtSecret = "localdev_jwt_secret_change_this_if_you_care"

# Dedicated IMAP / OAuth token encryption key. Must NOT change once data is
# encrypted under it, or the boot canary will refuse to start the app.
# This is the local-dev counterpart of IMAP_ENCRYPTION_KEY on the server.
# OAUTH_KEYS=v1:<this> is also wired implicitly by config.php.
$ImapEncKey = "localdev_imap_encryption_key_change_this_if_you_care"

# 2a. Backend .env. The Docker container ALSO gets these via docker-compose,
# but PHP reads .env directly via public/index.php as a fallback.
if (-not (Test-Path "backend/.env")) {
    $backendEnv = @"
# Local development environment - do not upload to server.
DB_HOST=db
DB_NAME=flowone_local
DB_USER=flowone
DB_PASS=flowone

MAIL_DB_HOST=
MAIL_DB_NAME=
MAIL_DB_USER=
MAIL_DB_PASS=

# JWT - HS256 for local dev (no PEM keys required).
JWT_SECRET=$JwtSecret
JWT_ALGORITHM=HS256

# OAuth + IMAP token encryption (do NOT change after first use).
IMAP_ENCRYPTION_KEY=$ImapEncKey

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

MEILI_HOST=http://meilisearch:7700
MEILI_MASTER_KEY=localdevmasterkey
MEILI_SEARCH_KEY=localdevmasterkey

PANEL_API_URL=https://panel.devcon1.hu/api
PANEL_API_KEY=

APP_DEBUG=true
"@
    Set-Content -LiteralPath "backend/.env" -Value $backendEnv -Encoding UTF8
    Write-Ok "Created backend/.env"
} else {
    Write-Ok "backend/.env already exists - leaving as-is"
}

# 2b. Mailsync server .env. JWT_SECRET must match the backend.
if (-not (Test-Path "mailsync/server/.env")) {
    Copy-Item -LiteralPath "mailsync/server/env.example.txt" -Destination "mailsync/server/.env"
    # Patch the placeholder secret to match the backend.
    (Get-Content -LiteralPath "mailsync/server/.env") `
        -replace 'JWT_SECRET=.*', "JWT_SECRET=$JwtSecret" |
        Set-Content -LiteralPath "mailsync/server/.env" -Encoding UTF8
    Write-Ok "Created mailsync/server/.env"
} else {
    Write-Ok "mailsync/server/.env already exists - leaving as-is"
}

# 2c. Frontend .env.local. Points the WebSocket at the Vite proxy
# (which forwards to localhost:1235) so we don't hammer production with
# locally-issued JWTs and trigger an infinite 4001 -> token-refresh loop.
if (-not (Test-Path "frontend/.env.local")) {
    Set-Content -LiteralPath "frontend/.env.local" `
        -Value "VITE_MAILSYNC_WS_URL=ws://localhost:3001/mailsync_ws" -Encoding UTF8
    Write-Ok "Created frontend/.env.local"
} else {
    Write-Ok "frontend/.env.local already exists - leaving as-is"
}

# -----------------------------------------------------------------------------
# 3. Build & start Docker stack: MySQL + Redis + Meilisearch + PHP/Apache.
# -----------------------------------------------------------------------------
Write-Step "Building and starting Docker stack"
Invoke-DC up -d --build
if ($LASTEXITCODE -ne 0) {
    Write-Err "docker compose up failed. Scroll up for the actual error."
    exit 1
}
Write-Ok "Docker stack running"

# -----------------------------------------------------------------------------
# 4. Wait for MySQL. The php container starts in parallel but might race the
# DB; this avoids spurious "connection refused" errors on the first request.
# -----------------------------------------------------------------------------
Write-Step "Waiting for MySQL to accept connections"
$tries = 0
while ($tries -lt 60) {
    Invoke-DC exec -T db mysqladmin ping -uflowone -pflowone --silent *> $null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 1
    $tries++
}
if ($tries -ge 60) {
    Write-Err "MySQL did not become ready in 60 seconds. Check 'docker compose logs db'."
    exit 1
}
Write-Ok "MySQL is ready"

# -----------------------------------------------------------------------------
# 5. Import DB dump if the database is empty.
# Dump is provided separately by Robert. If you don't have it yet, the script
# still finishes; you just won't have any data until you import one later.
# -----------------------------------------------------------------------------
Write-Step "Checking database state"
$tableCountRaw = Invoke-DC exec -T db mysql -uflowone -pflowone flowone_local -N -e `
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='flowone_local';"
$tableCount = 0
[int]::TryParse(($tableCountRaw -as [string]).Trim(), [ref]$tableCount) | Out-Null

if ($tableCount -eq 0) {
    if ([string]::IsNullOrWhiteSpace($DbDump)) {
        # Auto-detect a dump file in common locations.
        foreach ($candidate in @("db-dump.sql", "dump.sql", "flowone.sql", "..\db-dump.sql")) {
            if (Test-Path -LiteralPath $candidate) { $DbDump = $candidate; break }
        }
    }

    if ([string]::IsNullOrWhiteSpace($DbDump) -or -not (Test-Path -LiteralPath $DbDump)) {
        Write-Warn "Database is empty and no DB dump was found."
        Write-Warn "Get the dump from Robert, save it as 'db-dump.sql' here, then re-run this script."
        Write-Warn "Skipping DB import for now - the app will start, but most features will look broken."
    } else {
        Write-Host "    Importing $DbDump (this may take a minute)..." -ForegroundColor Gray
        # Pipe the SQL file into the mysql client inside the db container.
        Get-Content -LiteralPath $DbDump -Raw -Encoding UTF8 |
            Invoke-DC exec -T db mysql -uflowone -pflowone flowone_local
        if ($LASTEXITCODE -ne 0) {
            Write-Err "DB import failed. Check the dump file is valid SQL."
            exit 1
        }
        Write-Ok "DB dump imported"
    }
} else {
    Write-Ok "Database already has $tableCount tables - skipping import"
}

# -----------------------------------------------------------------------------
# 6. Trigger migrations. The PHP container runs MigrationService on every
# request; one harmless GET is enough to make it apply all pending migrations.
# -----------------------------------------------------------------------------
Write-Step "Triggering pending migrations"
try {
    Invoke-WebRequest -Uri "http://localhost:8000/api/auth/google/enabled" `
        -UseBasicParsing -TimeoutSec 30 *> $null
    Write-Ok "Migrations triggered (PHP runs them automatically on first request)"
} catch {
    Write-Warn "Couldn't reach the PHP container. It might still be booting."
    Write-Warn "Loading the app in the browser will trigger migrations anyway."
}

# -----------------------------------------------------------------------------
# 7. Install npm dependencies. Skips automatically if node_modules is present.
# -----------------------------------------------------------------------------
Write-Step "Installing frontend dependencies"
if (-not (Test-Path "frontend/node_modules")) {
    Push-Location frontend
    npm install
    $exit = $LASTEXITCODE
    Pop-Location
    if ($exit -ne 0) { Write-Err "Frontend npm install failed."; exit 1 }
    Write-Ok "Installed"
} else {
    Write-Ok "frontend/node_modules already present - skipping"
}

Write-Step "Installing mailsync server dependencies"
if (-not (Test-Path "mailsync/server/node_modules")) {
    Push-Location mailsync/server
    npm install
    $exit = $LASTEXITCODE
    Pop-Location
    if ($exit -ne 0) { Write-Err "Mailsync npm install failed."; exit 1 }
    Write-Ok "Installed"
} else {
    Write-Ok "mailsync/server/node_modules already present - skipping"
}

# -----------------------------------------------------------------------------
# 8. Launch the two long-running dev servers in their own windows so they're
# easy to read and Ctrl+C separately when you want to stop one.
# -----------------------------------------------------------------------------
Write-Step "Launching mailsync server in a new window"
Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd '$PSScriptRoot\mailsync\server'; Write-Host '=== Mailsync server (Ctrl+C to stop) ===' -ForegroundColor Cyan; npm start"
)

Write-Step "Launching Vite dev server in a new window"
Start-Process powershell -ArgumentList @(
    "-NoExit",
    "-Command",
    "cd '$PSScriptRoot\frontend'; Write-Host '=== Frontend (Ctrl+C to stop) ===' -ForegroundColor Cyan; npm run dev"
)

Start-Sleep -Seconds 3

# -----------------------------------------------------------------------------
# 9. Done. Print URLs and the everyday "stop / start" commands.
# -----------------------------------------------------------------------------
Write-Host ""
Write-Host "================================================================================" -ForegroundColor Green
Write-Host "  All set!" -ForegroundColor Green
Write-Host "================================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Open in your browser:"
Write-Host "    http://localhost:3001"
Write-Host ""
Write-Host "  URLs:"
Write-Host "    Frontend (Vite):    http://localhost:3001"
Write-Host "    Backend  (PHP API): http://localhost:8000/api/..."
Write-Host "    Mailsync (WS):      ws://localhost:1235"
Write-Host ""
Write-Host "  Two new PowerShell windows just opened — keep them running:"
Write-Host "    - Mailsync server (Node.js)"
Write-Host "    - Frontend dev server (Vite)"
Write-Host ""
Write-Host "  To stop everything:"
Write-Host "    docker compose -f docker-compose.local.yml down"
Write-Host "    (then close the two PowerShell windows)"
Write-Host ""
Write-Host "  To start again later (without re-running this whole script):"
Write-Host "    docker compose -f docker-compose.local.yml up -d"
Write-Host "    cd mailsync/server; npm start         # in one window"
Write-Host "    cd frontend; npm run dev              # in another window"
Write-Host ""
Write-Host "================================================================================" -ForegroundColor Green
