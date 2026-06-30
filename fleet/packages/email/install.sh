#!/bin/bash
#
# MailFlow Email App - Remote Installer Script
# This script is run on the target server after the package is extracted
#
# Usage: ./install.sh --domain=email.example.com --db-name=email_db ...
#
# Required variables (passed as arguments or environment):
#   EMAIL_DOMAIN    - Email app domain (e.g., email.example.com)
#   MAIL_DOMAIN     - Mail server domain (e.g., mail.example.com)
#   DB_NAME         - Database name
#   DB_USER         - Database username  
#   DB_PASS         - Database password
#
# Optional:
#   PANEL_API_URL   - VPS Panel API URL for storage config
#   PANEL_API_KEY   - VPS Panel API key
#   MAIL_DB_NAME    - Mail server database name (default: mailserver)
#   MAIL_DB_USER    - Mail server database user (default: mailuser)
#   MAIL_DB_PASS    - Mail server database password
#   REDIS_PASS      - Redis password (if set)
#   MEILI_MASTER_KEY - Meilisearch master key
#   MEILI_SEARCH_KEY - Meilisearch search key
#   LIVEKIT_API_KEY  - LiveKit API key
#   LIVEKIT_API_SECRET - LiveKit API secret
#   LIVEKIT_WS_URL   - LiveKit WebSocket URL
#   SKIP_DB         - Skip database setup (1/0)
#   SKIP_VHOST      - Skip vhost creation (1/0)
#   SKIP_COLLAB     - Skip collaboration server setup (1/0)
#   SKIP_MAILSYNC   - Skip mailsync server setup (1/0)
#   SKIP_OFFICE     - Skip OnlyOffice Document Server setup (1/0)
#   --nas           - Server has the FlowOne NAS mount; Drive stores on /mnt/nas-drive
#                     (default: local storage under INSTALL_PATH/storage/drive)
#   --update-only   - Only update code files, preserve configs
#

# NOTE: No 'set -e' here! We handle errors explicitly so the installer
# doesn't abort on non-critical failures (e.g., missing optional extensions).
# Critical errors call log_error which exits explicitly.

# Update mode flag
UPDATE_ONLY=0

# Installation paths
INSTALL_PATH="/var/www/vps-email"
OLS_CONF="/usr/local/lsws/conf"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
log_error_no_exit() { echo -e "${RED}[ERROR]${NC} $1"; }

# Parse arguments
for arg in "$@"; do
    case $arg in
        --domain=*) EMAIL_DOMAIN="${arg#*=}" ;;
        --mail-domain=*) MAIL_DOMAIN="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --db-user=*) DB_USER="${arg#*=}" ;;
        --db-pass=*) DB_PASS="${arg#*=}" ;;
        --db-root-pass=*) DB_ROOT_PASS="${arg#*=}" ;;
        --panel-api-url=*) PANEL_API_URL="${arg#*=}" ;;
        --panel-api-key=*) PANEL_API_KEY="${arg#*=}" ;;
        --mail-db-name=*) MAIL_DB_NAME="${arg#*=}" ;;
        --mail-db-user=*) MAIL_DB_USER="${arg#*=}" ;;
        --mail-db-pass=*) MAIL_DB_PASS="${arg#*=}" ;;
        --redis-pass=*) REDIS_PASS="${arg#*=}" ;;
        --meili-master-key=*) MEILI_MASTER_KEY="${arg#*=}" ;;
        --meili-search-key=*) MEILI_SEARCH_KEY="${arg#*=}" ;;
        --livekit-api-key=*) LIVEKIT_API_KEY="${arg#*=}" ;;
        --livekit-api-secret=*) LIVEKIT_API_SECRET="${arg#*=}" ;;
        --livekit-ws-url=*) LIVEKIT_WS_URL="${arg#*=}" ;;
        --turn-secret=*) TURN_SECRET="${arg#*=}" ;;
        --turn-host=*) TURN_HOST="${arg#*=}" ;;
        --skip-db) SKIP_DB=1 ;;
        --skip-vhost) SKIP_VHOST=1 ;;
        --skip-collab) SKIP_COLLAB=1 ;;
        --skip-mailsync) SKIP_MAILSYNC=1 ;;
        --skip-office) SKIP_OFFICE=1 ;;
        --nas) NAS_ENABLED=1 ;;
        --update-only) UPDATE_ONLY=1 ;;
    esac
done

# PHP binary for cron jobs and config replacement. The live server runs every
# email cron under lsphp83, so prefer that and fall back to system php.
PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php 2>/dev/null || echo php)"

# If update-only mode, skip db, vhost, and config creation
if [ "$UPDATE_ONLY" = "1" ]; then
    SKIP_DB=1
    SKIP_VHOST=1
    log_info "Running in UPDATE-ONLY mode - preserving existing configs"
fi

# Validate required variables
validate_vars() {
    # Skip validation in update-only mode
    if [ "$UPDATE_ONLY" = "1" ]; then
        return 0
    fi

    local missing=0
    
    [ -z "$EMAIL_DOMAIN" ] && { log_error_no_exit "EMAIL_DOMAIN is required"; missing=1; }
    [ -z "$DB_NAME" ] && { log_error_no_exit "DB_NAME is required"; missing=1; }
    [ -z "$DB_USER" ] && { log_error_no_exit "DB_USER is required"; missing=1; }
    [ -z "$DB_PASS" ] && { log_error_no_exit "DB_PASS is required"; missing=1; }
    
    if [ $missing -eq 1 ]; then
        echo ""
        echo "Usage: $0 --domain=email.example.com --db-name=email_db --db-user=email --db-pass=secret"
        echo "   or: $0 --update-only   (to update code files only)"
        exit 1
    fi
    
    # Default mail domain to email domain if not specified
    if [ -z "$MAIL_DOMAIN" ]; then
        MAIL_DOMAIN="mail.${EMAIL_DOMAIN#*.}"
    fi

    # Default mail database settings
    MAIL_DB_NAME="${MAIL_DB_NAME:-mailserver}"
    MAIL_DB_USER="${MAIL_DB_USER:-mailuser}"
    MAIL_DB_PASS="${MAIL_DB_PASS:-}"
}

# Verify OpenLiteSpeed is installed (non-fatal — provisioning already set it up)
verify_ols() {
    log_info "Verifying OpenLiteSpeed installation..."
    
    # Check if OLS binary exists
    if [ ! -f "/usr/local/lsws/bin/litespeed" ]; then
        log_warn "OpenLiteSpeed binary not found — provisioning should have installed it"
        log_warn "Continuing with installation anyway (OLS can be fixed after)"
        return 0
    fi
    
    # Try to start if not running, but don't fail the entire install
    if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
        log_info "OLS not running, attempting to start..."
        
        # Kill any stale processes that might hold the port
        killall -9 litespeed 2>/dev/null || true
        sleep 1
        
        systemctl daemon-reload
        systemctl enable lshttpd 2>/dev/null || true
        systemctl start lshttpd 2>/dev/null || {
            log_warn "OLS failed to start — will retry after installation completes"
            log_warn "Check config: /usr/local/lsws/conf/httpd_config.conf"
            journalctl -u lshttpd --no-pager -n 10 2>/dev/null || true
            return 0
        }
    fi
    
    log_info "OpenLiteSpeed verified and running"
}

# Verify MariaDB is running
verify_mariadb() {
    log_info "Verifying MariaDB..."
    
    if ! systemctl is-active --quiet mariadb 2>/dev/null; then
        log_info "Starting MariaDB service..."
        systemctl start mariadb || log_error "MariaDB failed to start"
    fi
    
    # Test connection — try with root password first (provisioning sets one), then unix_socket fallback
    if [ -n "$DB_ROOT_PASS" ]; then
        if ! MYSQL_PWD="$DB_ROOT_PASS" mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
            # Fallback to unix_socket
            if ! mysql -e "SELECT 1" >/dev/null 2>&1; then
                log_warn "Cannot connect to MariaDB - provisioning should have configured it, continuing..."
            fi
        fi
    else
        if ! mysql -e "SELECT 1" >/dev/null 2>&1; then
            log_warn "Cannot connect to MariaDB without password - provisioning should have configured it"
        fi
    fi
    
    log_info "MariaDB verified and running"
}

# Verify mail services
verify_mail_services() {
    log_info "Verifying mail services..."
    
    # Postfix
    if ! systemctl is-active --quiet postfix 2>/dev/null; then
        log_warn "Postfix not running, attempting to start..."
        systemctl start postfix 2>/dev/null || log_warn "Postfix failed to start"
    fi
    
    # Dovecot
    if ! systemctl is-active --quiet dovecot 2>/dev/null; then
        log_warn "Dovecot not running, attempting to start..."
        systemctl start dovecot 2>/dev/null || log_warn "Dovecot failed to start"
    fi
}

echo ""
echo "========================================="
if [ "$UPDATE_ONLY" = "1" ]; then
    echo "  MailFlow Email App - Code Update"
else
    echo "  MailFlow Email App Installer"
fi
echo "========================================="
echo ""

# Get script directory (where package was extracted)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

validate_vars

if [ "$UPDATE_ONLY" = "1" ]; then
    log_info "Updating MailFlow Email App code (configs preserved)..."
else
    log_info "Installing MailFlow Email App..."
    log_info "Domain: $EMAIL_DOMAIN"
    log_info "Mail domain: $MAIL_DOMAIN"
fi
log_info "Install path: $INSTALL_PATH"

# Pre-flight checks
verify_ols
verify_mariadb
verify_mail_services

# ============================================
# 1. Create directories
# ============================================
log_info "Creating directories..."

mkdir -p "$INSTALL_PATH/backend" "$INSTALL_PATH/dist"
mkdir -p "$INSTALL_PATH/backend/storage/cache" "$INSTALL_PATH/backend/storage/config" "$INSTALL_PATH/backend/storage/drive" "$INSTALL_PATH/backend/storage/logs"
mkdir -p "$INSTALL_PATH/backend/logs"
mkdir -p "$INSTALL_PATH/data/settings"
mkdir -p "$INSTALL_PATH/storage/drive"

# ============================================
# 2. Copy files
# ============================================
log_info "Copying files..."

# Copy Backend
if [ -d "$SCRIPT_DIR/backend" ]; then
    cp -r "$SCRIPT_DIR/backend/"* "$INSTALL_PATH/backend/"
    log_info "Backend files copied"
else
    log_error "Backend directory not found in package!"
fi

# Copy Frontend (handle both old and new package structure)
FRONTEND_COPIED=0

# New structure: assets/ + index.html at root
if [ -f "$SCRIPT_DIR/index.html" ]; then
    cp "$SCRIPT_DIR/index.html" "$INSTALL_PATH/dist/"
    [ -d "$SCRIPT_DIR/assets" ] && cp -r "$SCRIPT_DIR/assets" "$INSTALL_PATH/dist/"
    # Copy PWA/service worker files
    for pwa_file in favicon.svg favicon.ico apple-touch-icon.png manifest.webmanifest sw.js registerSW.js pwa-192x192.png pwa-512x512.png .htaccess; do
        [ -f "$SCRIPT_DIR/$pwa_file" ] && cp "$SCRIPT_DIR/$pwa_file" "$INSTALL_PATH/dist/" 2>/dev/null || true
    done
    # Copy workbox files
    cp "$SCRIPT_DIR"/workbox-*.js "$INSTALL_PATH/dist/" 2>/dev/null || true
    FRONTEND_COPIED=1
    log_info "Frontend files copied (new structure - root level)"
fi

# dist/ at root (check for index.html to verify it has content)
if [ -f "$SCRIPT_DIR/dist/index.html" ] && [ $FRONTEND_COPIED -eq 0 ]; then
    cp -r "$SCRIPT_DIR/dist/"* "$INSTALL_PATH/dist/" 2>/dev/null || true
    cp -r "$SCRIPT_DIR/dist/".* "$INSTALL_PATH/dist/" 2>/dev/null || true
    FRONTEND_COPIED=1
    log_info "Frontend files copied (dist/ structure)"
fi

# Old structure: frontend/dist/
if [ -f "$SCRIPT_DIR/frontend/dist/index.html" ] && [ $FRONTEND_COPIED -eq 0 ]; then
    cp -r "$SCRIPT_DIR/frontend/dist/"* "$INSTALL_PATH/dist/" 2>/dev/null || true
    cp -r "$SCRIPT_DIR/frontend/dist/".* "$INSTALL_PATH/dist/" 2>/dev/null || true
    FRONTEND_COPIED=1
    log_info "Frontend files copied (old structure - frontend/dist/)"
fi

if [ $FRONTEND_COPIED -eq 0 ]; then
    log_warn "No frontend files found in package - frontend may not work"
fi

# Deploy self-hosted fonts into the docroot (dist/). The SPA loads
# /fonts/core.css; missing fonts => Material Symbols render as ligature TEXT.
if [ -d "$SCRIPT_DIR/fonts" ]; then
    mkdir -p "$INSTALL_PATH/dist/fonts"
    cp -r "$SCRIPT_DIR/fonts/"* "$INSTALL_PATH/dist/fonts/"
    FONT_FAMILIES=$(ls -d "$INSTALL_PATH"/dist/fonts/*/ 2>/dev/null | wc -l)
    log_info "Fonts deployed (${FONT_FAMILIES} families)"
else
    log_warn "No fonts/ in package - email icons will render as text ligatures!"
fi

# Local JS libs (tailwind.min.js etc.) served from /js/
if [ -d "$SCRIPT_DIR/js" ]; then
    mkdir -p "$INSTALL_PATH/dist/js"
    cp -r "$SCRIPT_DIR/js/"* "$INSTALL_PATH/dist/js/"
    log_info "Local JS assets deployed"
fi

# Copy VERSION and BUILD_DATE to install root
[ -f "$SCRIPT_DIR/VERSION" ] && cp "$SCRIPT_DIR/VERSION" "$INSTALL_PATH/"
[ -f "$SCRIPT_DIR/BUILD_DATE" ] && cp "$SCRIPT_DIR/BUILD_DATE" "$INSTALL_PATH/"

# ============================================
# 3. Generate RS256 JWT key pair
# ============================================
JWT_KEY_DIR="$INSTALL_PATH/backend/storage/config"
JWT_PRIVATE_KEY="$JWT_KEY_DIR/jwt-private.pem"
JWT_PUBLIC_KEY="$JWT_KEY_DIR/jwt-public.pem"

if [ ! -f "$JWT_PRIVATE_KEY" ] || [ ! -f "$JWT_PUBLIC_KEY" ]; then
    log_info "Generating RS256 JWT key pair..."
    mkdir -p "$JWT_KEY_DIR"
    openssl genrsa -out "$JWT_PRIVATE_KEY" 2048 2>/dev/null
    openssl rsa -in "$JWT_PRIVATE_KEY" -pubout -out "$JWT_PUBLIC_KEY" 2>/dev/null
    chmod 600 "$JWT_PRIVATE_KEY"
    chmod 644 "$JWT_PUBLIC_KEY"
    log_info "JWT RS256 key pair generated"
else
    log_info "JWT key pair already exists, preserving"
fi

# Generate IMAP encryption key if not set
IMAP_ENCRYPTION_KEY=$(openssl rand -hex 32)

# Generate AI encryption key
AI_ENCRYPTION_KEY=$(openssl rand -hex 32)

# ============================================
# 4. Generate VAPID keys for web push
# ============================================
VAPID_PUBLIC_KEY=""
VAPID_PRIVATE_KEY=""

if command -v node &> /dev/null; then
    log_info "Generating VAPID keys for web push notifications..."
    VAPID_KEYS=$(node -e "
        try {
            const crypto = require('crypto');
            const ecdh = crypto.createECDH('prime256v1');
            ecdh.generateKeys();
            const publicKey = ecdh.getPublicKey('base64url');
            const privateKey = ecdh.getPrivateKey('base64url');
            console.log(publicKey + ':' + privateKey);
        } catch(e) {
            process.exit(1);
        }
    " 2>/dev/null || true)

    if [ -n "$VAPID_KEYS" ]; then
        VAPID_PUBLIC_KEY=$(echo "$VAPID_KEYS" | cut -d: -f1)
        VAPID_PRIVATE_KEY=$(echo "$VAPID_KEYS" | cut -d: -f2)
        log_info "VAPID keys generated"
    else
        log_warn "Could not generate VAPID keys - web push notifications will not work"
    fi
else
    log_warn "Node.js not installed - skipping VAPID key generation"
fi

# ============================================
# 5. Create Backend config (skip in update-only mode)
# ============================================
if [ "$UPDATE_ONLY" = "1" ] && [ -f "$INSTALL_PATH/backend/src/config.local.php" ]; then
    log_info "Preserving existing backend configuration..."
else
    log_info "Creating backend configuration..."

    # Keep a legacy JWT secret for backward compatibility during migration
    JWT_LEGACY_SECRET=$(openssl rand -hex 32)

    # Use a single-quoted heredoc ('CONFIGEOF') to prevent bash variable expansion,
    # then use sed to replace placeholders. This avoids issues with passwords containing $ or !
    cat > "$INSTALL_PATH/backend/src/config.local.php" << 'CONFIGEOF'
<?php
return [
    // Database Settings
    'db' => [
        'host' => '127.0.0.1',
        'name' => '__DB_NAME__',
        'user' => '__DB_USER__',
        'pass' => '__DB_PASS__',
    ],

    // Mail Server Database (Dovecot/Postfix) - for colleague sync
    'mail_db' => [
        'host' => '127.0.0.1',
        'name' => '__MAIL_DB_NAME__',
        'user' => '__MAIL_DB_USER__',
        'pass' => '__MAIL_DB_PASS__',
    ],

    // JWT Settings (RS256 asymmetric)
    'jwt' => [
        'secret' => '__JWT_LEGACY_SECRET__',
        'algorithm' => 'RS256',
        'private_key_path' => '__JWT_PRIVATE_KEY__',
        'public_key_path' => '__JWT_PUBLIC_KEY__',
        'expiry' => 43200,
        'refresh_expiry' => 604800,
    ],

    // IMAP password encryption key (separate from JWT)
    'imap_encryption_key' => '__IMAP_ENCRYPTION_KEY__',

    // AI encryption key
    'encryption_key' => '__AI_ENCRYPTION_KEY__',

    // App Settings
    'app' => [
        'api_url' => 'https://__EMAIL_DOMAIN__/api',
        'frontend_url' => 'https://__EMAIL_DOMAIN__',
    ],

    // General storage path
    'storage_path' => '__INSTALL_PATH__/backend/storage',

    // Drive Storage Settings
    'drive' => [
        'storage_path' => '__DRIVE_STORAGE_PATH__',
    ],

    // Redis Cache Settings
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => __REDIS_PASSWORD__,
        'database' => 0,
        'prefix' => 'webmail:',
    ],

    // Collaborative Editing Settings
    'collab' => [
        'prefix' => 'collab_',
        'ws_port' => 1234,
        'ws_host' => 'localhost',
        'ws_url' => 'wss://__EMAIL_DOMAIN__/collab-ws',
    ],

    // Web Push Notification Settings (VAPID)
    'push' => [
        'vapid_public_key' => '__VAPID_PUBLIC_KEY__',
        'vapid_private_key' => '__VAPID_PRIVATE_KEY__',
        'vapid_subject' => 'mailto:__VAPID_SUBJECT__',
    ],

    // Meilisearch Settings
    'meilisearch' => [
        'host' => 'http://127.0.0.1:7700',
        'master_key' => '__MEILI_MASTER_KEY__',
        'search_key' => '__MEILI_SEARCH_KEY__',
        'index_name' => 'documents',
        'batch_size' => 1000,
    ],

    // LiveKit SFU Server
    'livekit' => [
        'api_key' => '__LIVEKIT_API_KEY__',
        'api_secret' => '__LIVEKIT_API_SECRET__',
        'ws_url' => '__LIVEKIT_WS_URL__',
    ],

    // WebRTC / coTURN ICE — CallService::getIceServers() reads this and mints
    // HMAC-SHA1 time-limited TURN credentials from the shared static-auth-secret.
    'webrtc' => [
        'turn_url' => '__TURN_URL__',
        'stun_url' => '__STUN_URL__',
        'secret' => '__TURN_SECRET__',
        'turn_ttl' => 86400,
    ],
];
CONFIGEOF

    # Use the global PHP_BIN (lsphp83-first) to safely replace placeholders
    # (handles any special chars in values)
    # Build redis password value (PHP null or quoted string)
    REDIS_PHP_VAL="null"
    [ -n "$REDIS_PASS" ] && REDIS_PHP_VAL="'$REDIS_PASS'"
    
    # Build VAPID subject
    VAPID_SUBJECT_DOMAIN="${EMAIL_DOMAIN#*.}"

    # Build WebRTC ICE URLs from the TURN host (coTURN listens on 3478)
    TURN_URL=""; STUN_URL=""
    [ -n "$TURN_HOST" ] && { TURN_URL="turn:${TURN_HOST}:3478"; STUN_URL="stun:${TURN_HOST}:3478"; }

    # Drive storage: local by default. /mnt/nas-drive only makes sense on
    # servers with the FlowOne NAS/VPN stack (--nas); on anything else it
    # forces every upload through the NAS-unavailable fallback path and
    # queues never-completing rows in drive_pending_nas_migration.
    if [ "${NAS_ENABLED:-0}" = "1" ]; then
        DRIVE_STORAGE_PATH="/mnt/nas-drive"
    else
        DRIVE_STORAGE_PATH="$INSTALL_PATH/storage/drive"
    fi
    log_info "Drive storage path: $DRIVE_STORAGE_PATH"
    
    $PHP_BIN -r '
        $file = $argv[1];
        $replacements = [
            "__DB_NAME__" => $argv[2],
            "__DB_USER__" => $argv[3],
            "__DB_PASS__" => $argv[4],
            "__MAIL_DB_NAME__" => $argv[5],
            "__MAIL_DB_USER__" => $argv[6],
            "__MAIL_DB_PASS__" => $argv[7],
            "__JWT_LEGACY_SECRET__" => $argv[8],
            "__JWT_PRIVATE_KEY__" => $argv[9],
            "__JWT_PUBLIC_KEY__" => $argv[10],
            "__IMAP_ENCRYPTION_KEY__" => $argv[11],
            "__AI_ENCRYPTION_KEY__" => $argv[12],
            "__EMAIL_DOMAIN__" => $argv[13],
            "__INSTALL_PATH__" => $argv[14],
            "__REDIS_PASSWORD__" => $argv[15],
            "__VAPID_PUBLIC_KEY__" => $argv[16],
            "__VAPID_PRIVATE_KEY__" => $argv[17],
            "__VAPID_SUBJECT__" => "admin@" . $argv[18],
            "__MEILI_MASTER_KEY__" => $argv[19],
            "__MEILI_SEARCH_KEY__" => $argv[20],
            "__LIVEKIT_API_KEY__" => $argv[21],
            "__LIVEKIT_API_SECRET__" => $argv[22],
            "__LIVEKIT_WS_URL__" => $argv[23],
            "__TURN_SECRET__" => $argv[24],
            "__TURN_URL__" => $argv[25],
            "__STUN_URL__" => $argv[26],
            "__DRIVE_STORAGE_PATH__" => $argv[27],
        ];
        $content = file_get_contents($file);
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        file_put_contents($file, $content);
    ' "$INSTALL_PATH/backend/src/config.local.php" \
      "$DB_NAME" "$DB_USER" "$DB_PASS" \
      "$MAIL_DB_NAME" "$MAIL_DB_USER" "$MAIL_DB_PASS" \
      "$JWT_LEGACY_SECRET" "$JWT_PRIVATE_KEY" "$JWT_PUBLIC_KEY" \
      "$IMAP_ENCRYPTION_KEY" "$AI_ENCRYPTION_KEY" "$EMAIL_DOMAIN" "$INSTALL_PATH" \
      "$REDIS_PHP_VAL" \
      "$VAPID_PUBLIC_KEY" "$VAPID_PRIVATE_KEY" "$VAPID_SUBJECT_DOMAIN" \
      "${MEILI_MASTER_KEY:-}" "${MEILI_SEARCH_KEY:-}" \
      "${LIVEKIT_API_KEY:-}" "${LIVEKIT_API_SECRET:-}" "${LIVEKIT_WS_URL:-}" \
      "${TURN_SECRET:-}" "${TURN_URL:-}" "${STUN_URL:-}" \
      "$DRIVE_STORAGE_PATH" \
    2>/dev/null || log_warn "Config replacement with PHP failed - check config.local.php manually"

    # Add Panel API config if provided
    if [ -n "$PANEL_API_URL" ]; then
        # Remove the closing ]; and add panel config
        sed -i '$ d' "$INSTALL_PATH/backend/src/config.local.php"
        $PHP_BIN -r '
            $file = $argv[1];
            $content = file_get_contents($file);
            $content .= "\n    // Panel Integration\n";
            $content .= "    '\''panel'\'' => [\n";
            $content .= "        '\''api_url'\'' => '\''". $argv[2] ."'\'',\n";
            $content .= "        '\''api_key'\'' => '\''". $argv[3] ."'\'',\n";
            $content .= "        '\''storage_cache_ttl'\'' => 300,\n";
            $content .= "    ],\n];\n";
            file_put_contents($file, $content);
        ' "$INSTALL_PATH/backend/src/config.local.php" "$PANEL_API_URL" "${PANEL_API_KEY:-}" \
        2>/dev/null || log_warn "Panel config append failed"
    fi

    log_info "Backend configuration created with RS256 JWT, Redis, Meilisearch, VAPID"
fi

# ============================================
# 6. Setup Database
# ============================================
if [ "${SKIP_DB:-0}" != "1" ]; then
    log_info "Setting up database..."
    
    # Build mysql command with or without root password
    # Provisioning sets a root password, so we need MYSQL_PWD to authenticate
    if [ -n "$DB_ROOT_PASS" ]; then
        export MYSQL_PWD="$DB_ROOT_PASS"
        MYSQL_CMD="mysql -u root"
    else
        MYSQL_CMD="mysql"
    fi
    
    # Create database and user — use printf to avoid bash expansion issues with special chars in passwords
    printf "CREATE DATABASE IF NOT EXISTS \`%s\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" "$DB_NAME" | $MYSQL_CMD 2>&1 || log_warn "DB create may have failed"
    printf "CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';\n" "$DB_USER" "$DB_PASS" | $MYSQL_CMD 2>&1 || log_warn "DB user create may have failed"
    printf "GRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'localhost';\n" "$DB_NAME" "$DB_USER" | $MYSQL_CMD 2>&1 || log_warn "DB grant may have failed"
    printf "FLUSH PRIVILEGES;\n" | $MYSQL_CMD 2>&1 || true
    
    # Run migrations (use DB user credentials since DB exists now).
    # Two passes: some migrations depend on tables that later migrations (or
    # schema-repair migrations like 192) create, so a single ordered pass can
    # fail on a fresh database. Pass 2 resolves those order dependencies;
    # only pass-2 errors are reported.
    if [ -d "$SCRIPT_DIR/backend/migrations" ]; then
        export MYSQL_PWD="$DB_PASS"
        MIGRATE_CMD="mysql -u $DB_USER"
        MIGRATION_ERRORS=0
        for MIGRATION_PASS in 1 2; do
            MIGRATION_ERRORS=0
            for migration in "$SCRIPT_DIR/backend/migrations/"*.sql; do
                if [ -f "$migration" ]; then
                    MNAME=$(basename "$migration")
                    MOUT=$($MIGRATE_CMD "$DB_NAME" < "$migration" 2>&1) || {
                        if echo "$MOUT" | grep -qi "duplicate\|already exists"; then
                            true
                        else
                            [ "$MIGRATION_PASS" = "2" ] && log_warn "Migration $MNAME had issues: $MOUT"
                            MIGRATION_ERRORS=$((MIGRATION_ERRORS + 1))
                        fi
                    }
                fi
            done
            [ $MIGRATION_ERRORS -eq 0 ] && break
        done
        unset MYSQL_PWD
        if [ $MIGRATION_ERRORS -gt 0 ]; then
            log_warn "$MIGRATION_ERRORS migration(s) had non-trivial errors after 2 passes - check above"
        else
            log_info "All migrations completed"
        fi
    fi
    
    log_info "Database setup complete"
fi

# ============================================
# 7. Install Composer dependencies
# ============================================
log_info "Installing PHP dependencies..."

COMPOSER_OK=0
cd "$INSTALL_PATH/backend"
if [ -f "composer.json" ]; then
    # Locate (or fetch) the composer phar, then run it under lsphp83 so the
    # platform requirement checks (ext-zip, ext-gd, ...) validate against the
    # PHP that actually serves requests - not the system CLI PHP.
    COMPOSER_BIN="$(command -v composer 2>/dev/null || true)"
    if [ -z "$COMPOSER_BIN" ]; then
        log_info "Composer not found - downloading..."
        curl -sS --retry 3 --retry-delay 3 --connect-timeout 30 https://getcomposer.org/installer \
            | "$PHP_BIN" -- --install-dir=/usr/local/bin --filename=composer 2>&1 || true
        COMPOSER_BIN="/usr/local/bin/composer"
    fi
    if [ -f "$COMPOSER_BIN" ]; then
        if "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction 2>&1; then
            COMPOSER_OK=1
        else
            log_warn "composer install under $PHP_BIN failed - retrying with system composer..."
            if composer install --no-dev --optimize-autoloader --no-interaction 2>&1; then
                COMPOSER_OK=1
            else
                log_warn "Composer install FAILED - office/drive features will be broken until vendor/ is installed"
            fi
        fi
    else
        log_warn "No composer binary available - skipping dependency install"
    fi
else
    log_warn "No composer.json found in backend directory"
fi

# ============================================
# 7b. Runtime dependency check + schema warm-up
# ============================================
# Verify the office stack's hard dependencies load under the REQUEST runtime
# (lsphp83): missing ext-zip or missing phpoffice vendor packages produce a
# silent 500 on POST /office/files/new in production.
PHPOFFICE_OK=0
if [ -f "$INSTALL_PATH/backend/vendor/autoload.php" ]; then
    if "$PHP_BIN" -r '
        require $argv[1];
        exit((class_exists("ZipArchive")
            && class_exists("PhpOffice\\PhpWord\\PhpWord")
            && class_exists("PhpOffice\\PhpSpreadsheet\\Spreadsheet")
            && class_exists("PhpOffice\\PhpPresentation\\PhpPresentation")) ? 0 : 1);
    ' "$INSTALL_PATH/backend/vendor/autoload.php" 2>/dev/null; then
        PHPOFFICE_OK=1
        log_info "PhpOffice runtime check passed (ZipArchive + PhpWord/PhpSpreadsheet/PhpPresentation)"
    else
        log_warn "PhpOffice runtime check FAILED under $PHP_BIN - office file creation will 500"
    fi
else
    log_warn "vendor/autoload.php missing - composer install did not complete"
fi

# Warm up the lazily-created schema (boards, ProjectHub, Drive). Without this,
# tables get created on the first user request AFTER migrations already ran,
# leaving columns like webmail_board_cards.parent_card_id missing forever.
SCHEMA_OK=0
if [ -f "$INSTALL_PATH/backend/src/config.local.php" ] && [ -f "$INSTALL_PATH/backend/scripts/ensure-schema.php" ] && [ -f "$INSTALL_PATH/backend/vendor/autoload.php" ]; then
    if "$PHP_BIN" "$INSTALL_PATH/backend/scripts/ensure-schema.php" --verbose 2>&1; then
        SCHEMA_OK=1
        log_info "Schema warm-up complete"
    else
        log_warn "Schema warm-up reported missing columns - run ensure-schema.php --verbose to inspect"
    fi
else
    # Nothing to verify against (no config or no vendor); don't flag the install.
    SCHEMA_OK=1
fi

# ============================================
# 8. Setup Collaboration Server (optional)
# ============================================
if [ "${SKIP_COLLAB:-0}" != "1" ] && [ -d "$SCRIPT_DIR/collab/server" ]; then
    log_info "Setting up collaboration server..."

    # Preserve the repo layout (server/ + shared/ + backend/): config.js
    # resolves ../../shared/collabConstants.js relative to server/src/, so
    # flattening the tree breaks the import.
    mkdir -p "$INSTALL_PATH/collab"
    cp -r "$SCRIPT_DIR/collab/server" "$INSTALL_PATH/collab/"
    if [ -d "$SCRIPT_DIR/collab/shared" ]; then
        cp -r "$SCRIPT_DIR/collab/shared" "$INSTALL_PATH/collab/"
    else
        log_warn "collab/shared missing from package - collab server will not start"
    fi

    # Copy collab PHP backend (routes, controllers, services used by main backend)
    if [ -d "$SCRIPT_DIR/collab/backend" ]; then
        mkdir -p "$INSTALL_PATH/collab/backend"
        cp -r "$SCRIPT_DIR/collab/backend/"* "$INSTALL_PATH/collab/backend/"
        log_info "Collab PHP backend files deployed"
    fi

    cd "$INSTALL_PATH/collab/server"
    if [ -f "package.json" ]; then
        npm install --production 2>/dev/null || {
            log_warn "npm install failed for collab server"
        }
    fi

    # Create .env (DB + JWT must match the PHP backend; the systemd unit
    # requires this file via EnvironmentFile). Preserve it on update runs.
    if [ "$UPDATE_ONLY" != "1" ] || [ ! -f "$INSTALL_PATH/collab/server/.env" ]; then
        # Direct WSS on :1234 needs the domain cert; enable only when present
        COLLAB_SSL_ENABLED="false"
        if [ -f "/etc/letsencrypt/live/${EMAIL_DOMAIN}/fullchain.pem" ]; then
            COLLAB_SSL_ENABLED="true"
        else
            log_warn "No SSL cert for ${EMAIL_DOMAIN} - collab WSS disabled; set COLLAB_SSL_ENABLED=true in $INSTALL_PATH/collab/server/.env after certbot and restart collab-server"
        fi
        {
            printf "# Collab Server Configuration - Generated by Fleet Manager\n"
            printf "COLLAB_WS_HOST=0.0.0.0\n"
            printf "COLLAB_WS_PORT=1234\n"
            printf "COLLAB_SSL_ENABLED=%s\n" "$COLLAB_SSL_ENABLED"
            printf "COLLAB_SSL_KEY=/etc/letsencrypt/live/%s/privkey.pem\n" "$EMAIL_DOMAIN"
            printf "COLLAB_SSL_CERT=/etc/letsencrypt/live/%s/fullchain.pem\n" "$EMAIL_DOMAIN"
            printf "DB_HOST=127.0.0.1\n"
            printf "DB_PORT=3306\n"
            printf "DB_NAME=%s\n" "$DB_NAME"
            printf "DB_USER=%s\n" "$DB_USER"
            printf "DB_PASS=%s\n" "$DB_PASS"
            printf "JWT_PUBLIC_KEY_PATH=%s\n" "$JWT_PUBLIC_KEY"
            printf "JWT_ALGORITHM=RS256\n"
            printf "PHP_BACKEND_URL=https://%s/api\n" "$EMAIL_DOMAIN"
            printf "LOG_LEVEL=info\n"
        } > "$INSTALL_PATH/collab/server/.env"
        chmod 640 "$INSTALL_PATH/collab/server/.env"
    fi

    # Create systemd service for collab server
    # Use collab-server.service name to match Panel agent expectations
    if [ -f "$SCRIPT_DIR/collab/server/collab-server.service" ]; then
        cp "$SCRIPT_DIR/collab/server/collab-server.service" /etc/systemd/system/collab-server.service
        
        # Update paths in service file
        sed -i "s|/var/www/email|$INSTALL_PATH|g" /etc/systemd/system/collab-server.service
        sed -i "s|/var/www/vps-email|$INSTALL_PATH|g" /etc/systemd/system/collab-server.service
        sed -i "s|/opt/collab-server|$INSTALL_PATH/collab/server|g" /etc/systemd/system/collab-server.service
        
        # Create compatibility symlink for legacy name
        ln -sf /etc/systemd/system/collab-server.service /etc/systemd/system/mailflow-collab.service
        
        systemctl daemon-reload
        systemctl enable collab-server
        systemctl restart collab-server || log_warn "Collab server failed to start"
        log_info "Collab server deployed and started"
    else
        log_warn "Collab server service file not found in package"
    fi
else
    if [ "${SKIP_COLLAB:-0}" = "1" ]; then
        log_info "Skipping collaboration server setup (--skip-collab)"
    fi
fi

# ============================================
# 8b. Setup OnlyOffice Document Server (optional)
# ============================================
# Runs install-onlyoffice.sh from the package: builds the whitelabeled
# Docker image (presence plugin baked in), starts the flowone-office
# container on :8443, and writes backend/storage/office-config.json.
# Requires Docker and a Let's Encrypt cert for the email domain.
if [ "${SKIP_OFFICE:-0}" != "1" ] && [ -d "$SCRIPT_DIR/office" ]; then
    log_info "Setting up OnlyOffice Document Server..."

    # Deploy the office stack to the install path (also serves
    # /office/branding/*.svg from the web root for the editor logo).
    mkdir -p "$INSTALL_PATH/office"
    cp -r "$SCRIPT_DIR/office/"* "$INSTALL_PATH/office/"

    # Ensure Docker is available (warn-and-skip on failure, never abort)
    OFFICE_READY=1
    if ! command -v docker >/dev/null 2>&1; then
        log_info "Docker not found - installing..."
        if command -v apt-get >/dev/null 2>&1; then
            apt-get install -y -qq docker.io 2>&1 || OFFICE_READY=0
        elif command -v dnf >/dev/null 2>&1; then
            dnf install -y -q docker 2>&1 || OFFICE_READY=0
        elif command -v yum >/dev/null 2>&1; then
            yum install -y -q docker 2>&1 || OFFICE_READY=0
        else
            OFFICE_READY=0
        fi
        if [ "$OFFICE_READY" = "1" ]; then
            systemctl enable docker 2>/dev/null
            systemctl start docker 2>/dev/null || OFFICE_READY=0
        fi
        [ "$OFFICE_READY" = "0" ] && log_warn "Docker install failed - OnlyOffice skipped (install Docker, then run: bash $INSTALL_PATH/office/install-onlyoffice.sh --domain=$EMAIL_DOMAIN --backend=$INSTALL_PATH/backend)"
    fi

    # The OnlyOffice installer needs the Let's Encrypt cert for HTTPS on :8443.
    # On first provision SSL may not exist yet - defer instead of failing.
    if [ "$OFFICE_READY" = "1" ] && [ ! -f "/etc/letsencrypt/live/${EMAIL_DOMAIN}/fullchain.pem" ]; then
        OFFICE_READY=0
        log_warn "No SSL cert for ${EMAIL_DOMAIN} yet - OnlyOffice deferred. After certbot, run: bash $INSTALL_PATH/office/install-onlyoffice.sh --domain=$EMAIL_DOMAIN --backend=$INSTALL_PATH/backend"
    fi

    if [ "$OFFICE_READY" = "1" ]; then
        if [ "$UPDATE_ONLY" = "1" ] && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx flowone-office; then
            # Code update on a live server: refresh the presence plugin only,
            # don't rebuild the whole Document Server image.
            bash "$INSTALL_PATH/office/install-presence-plugin.sh" 2>&1 \
                && log_info "OnlyOffice presence plugin refreshed" \
                || log_warn "Presence plugin refresh failed - run install-presence-plugin.sh manually"
        else
            cd "$INSTALL_PATH/office"
            if bash install-onlyoffice.sh --domain="$EMAIL_DOMAIN" --backend="$INSTALL_PATH/backend" 2>&1; then
                log_info "OnlyOffice Document Server installed (https://${EMAIL_DOMAIN}:8443)"
            else
                log_warn "OnlyOffice install failed - office editing disabled until: bash $INSTALL_PATH/office/install-onlyoffice.sh --domain=$EMAIL_DOMAIN --backend=$INSTALL_PATH/backend"
            fi
        fi

        # Keep the container's SSL certs fresh on Let's Encrypt renewals
        mkdir -p /etc/letsencrypt/renewal-hooks/deploy
        cat > /etc/letsencrypt/renewal-hooks/deploy/flowone-office-certs.sh << HOOKEOF
#!/bin/bash
bash ${INSTALL_PATH}/office/install-onlyoffice.sh --refresh-certs --domain=${EMAIL_DOMAIN} --backend=${INSTALL_PATH}/backend
HOOKEOF
        chmod +x /etc/letsencrypt/renewal-hooks/deploy/flowone-office-certs.sh
    fi
else
    if [ "${SKIP_OFFICE:-0}" = "1" ]; then
        log_info "Skipping OnlyOffice setup (--skip-office)"
    fi
fi

# ============================================
# 9. Setup Mailsync Server (real-time email sync)
# ============================================
if [ "${SKIP_MAILSYNC:-0}" != "1" ] && [ -d "$SCRIPT_DIR/mailsync/server" ]; then
    log_info "Setting up mailsync server..."

    mkdir -p "$INSTALL_PATH/mailsync"
    cp -r "$SCRIPT_DIR/mailsync/server/"* "$INSTALL_PATH/mailsync/"

    cd "$INSTALL_PATH/mailsync"
    if [ -f "package.json" ]; then
        npm install --production 2>/dev/null || {
            log_warn "npm install failed for mailsync server"
        }
    fi

    # Create .env config for mailsync server — use printf to avoid shell expansion of $
    {
        printf "# Mailsync Server Configuration - Generated by Fleet Manager\n"
        printf "PORT=1235\n"
        printf "DB_HOST=127.0.0.1\n"
        printf "DB_NAME=%s\n" "$DB_NAME"
        printf "DB_USER=%s\n" "$DB_USER"
        printf "DB_PASS=%s\n" "$DB_PASS"
        printf "REDIS_HOST=127.0.0.1\n"
        printf "REDIS_PORT=6379\n"
        printf "REDIS_PASSWORD=%s\n" "${REDIS_PASS:-}"
        printf "JWT_PUBLIC_KEY_PATH=%s\n" "$JWT_PUBLIC_KEY"
        printf "VAPID_PUBLIC_KEY=%s\n" "$VAPID_PUBLIC_KEY"
        printf "VAPID_PRIVATE_KEY=%s\n" "$VAPID_PRIVATE_KEY"
        printf "VAPID_SUBJECT=mailto:admin@%s\n" "${EMAIL_DOMAIN#*.}"
    } > "$INSTALL_PATH/mailsync/.env"

    # Create systemd service for mailsync server
    # Use mailsync-server.service name to match Panel agent expectations
    if [ -f "$SCRIPT_DIR/mailsync/server/mailsync-server.service" ]; then
        cp "$SCRIPT_DIR/mailsync/server/mailsync-server.service" /etc/systemd/system/mailsync-server.service
        
        # Update paths in service file
        sed -i "s|/var/www/email|$INSTALL_PATH|g" /etc/systemd/system/mailsync-server.service
        sed -i "s|/var/www/vps-email|$INSTALL_PATH|g" /etc/systemd/system/mailsync-server.service
    else
        # Create service file from scratch
        cat > /etc/systemd/system/mailsync-server.service << SVCEOF
[Unit]
Description=MailFlow Mailsync WebSocket Server
After=network.target mariadb.service redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${INSTALL_PATH}/mailsync
ExecStart=/usr/bin/node index.js
Restart=always
RestartSec=5
Environment=NODE_ENV=production
EnvironmentFile=${INSTALL_PATH}/mailsync/.env

[Install]
WantedBy=multi-user.target
SVCEOF
    fi

    # Create compatibility symlink for legacy name
    ln -sf /etc/systemd/system/mailsync-server.service /etc/systemd/system/mailflow-mailsync.service

    systemctl daemon-reload
    systemctl enable mailsync-server
    systemctl start mailsync-server || log_warn "Mailsync server failed to start"
    log_info "Mailsync server deployed and started"
else
    if [ "${SKIP_MAILSYNC:-0}" = "1" ]; then
        log_info "Skipping mailsync server setup (--skip-mailsync)"
    else
        log_warn "Mailsync server directory not found in package"
    fi
fi

# ============================================
# 10. Set permissions
# ============================================
log_info "Setting permissions..."

# Fleet-provisioned servers run the email vhost's PHP as a dedicated user
# (extUser in the OLS vhost conf, e.g. email_app) - NOT www-data. Chowning
# to www-data here locks that user out of storage/ on every package update,
# which breaks Drive uploads and office file creation with permission
# denied. Detect the real ext user from the existing vhost conf; fall back
# to www-data for standalone installs (where install.sh writes a vhconf
# without extUser and PHP runs as the server default).
WEB_USER="www-data"
EXISTING_VHCONF="$OLS_CONF/vhosts/$EMAIL_DOMAIN/vhconf.conf"
if [ -f "$EXISTING_VHCONF" ]; then
    # gsub strips CR in case the vhconf was generated from a CRLF template
    DETECTED_USER=$(awk '/^[[:space:]]*extUser[[:space:]]/{gsub(/\r/,""); print $2; exit}' "$EXISTING_VHCONF")
    if [ -n "$DETECTED_USER" ] && id "$DETECTED_USER" >/dev/null 2>&1; then
        WEB_USER="$DETECTED_USER"
    fi
fi
log_info "Web PHP user for ownership: $WEB_USER"

chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_PATH"
chmod -R 755 "$INSTALL_PATH"
chmod -R 770 "$INSTALL_PATH/backend/storage"
chmod -R 770 "$INSTALL_PATH/storage" 2>/dev/null || true
chmod -R 770 "$INSTALL_PATH/data" 2>/dev/null || true
chmod 600 "$JWT_PRIVATE_KEY" 2>/dev/null || true

# ============================================
# 11. Create OpenLiteSpeed vhost
# ============================================
if [ "${SKIP_VHOST:-0}" != "1" ]; then
    log_info "Creating OpenLiteSpeed vhost..."
    
    # Verify OLS is still running
    if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
        log_warn "OpenLiteSpeed not running, attempting to start..."
        systemctl start lshttpd || log_warn "Failed to start OLS"
    fi
    
    VHOST_DIR="$OLS_CONF/vhosts/$EMAIL_DOMAIN"
    mkdir -p "$VHOST_DIR"
    
    cat > "$VHOST_DIR/vhconf.conf" << EOF
docRoot                   ${INSTALL_PATH}/dist
vhDomain                  ${EMAIL_DOMAIN}
enableGzip                1
enableBr                  1

index {
  useServer               0
  indexFiles              index.html
}

context /api/ {
  type                    appserver
  location                ${INSTALL_PATH}/backend/public/
  binPath                 lsphp83
  appType                 php
  addDefaultCharset       off
  
  rewrite {
    enable                1
    rules                 <<<END_RULES
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ /index.php [L,QSA]
END_RULES
  }
}

rewrite {
  enable                  1
  rules                   <<<END_RULES
RewriteEngine On
# Mail client auto-configuration (PUBLIC XML endpoints served by the PHP
# backend). autodiscover.<domain> / autoconfig.<domain> are CNAMEd at this
# server and wildcard-mapped to this vhost, so Outlook / Thunderbird / Apple
# Mail requests land here and must reach the backend router, not the SPA.
RewriteRule ^/?autodiscover/autodiscover\.xml$ /api/autodiscover/autodiscover.xml [NC,L]
RewriteRule ^/?mail/config-v1\.1\.xml$ /api/mail/config-v1.1.xml [L]
RewriteRule ^/?autoconfig/mail/config-v1\.1\.xml$ /api/autoconfig/mail/config-v1.1.xml [L]
RewriteRule ^/?\.well-known/autoconfig/mail/config-v1\.1\.xml$ /api/.well-known/autoconfig/mail/config-v1.1.xml [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.html [L]
END_RULES
}
EOF

    log_info "Vhost config created at: $VHOST_DIR/vhconf.conf"
    
    # Add vhost to httpd_config.conf (remove any stale entry first)
    HTTPD_CONF="$OLS_CONF/httpd_config.conf"
    if [ -f "$HTTPD_CONF" ]; then
        sed -i "/^[[:space:]]*[vV]irtual[hH]ost[[:space:]]*${EMAIL_DOMAIN}[[:space:]]*{/,/^}/d" "$HTTPD_CONF" 2>/dev/null || true

        log_info "Adding vhost to httpd_config.conf..."
        cat >> "$HTTPD_CONF" << EOF

virtualHost $EMAIL_DOMAIN {
  vhRoot                  $VHOST_DIR
  configFile              \$VH_ROOT/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  restrained              0
}
EOF

        # Add listener mappings if not present
        for LISTENER in Default SSL; do
            if grep -qi "listener ${LISTENER}" "$HTTPD_CONF"; then
                if ! sed -n "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/,/}/p" "$HTTPD_CONF" | grep -q "map.*${EMAIL_DOMAIN}"; then
                    sed -i "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/a\\  map                     ${EMAIL_DOMAIN} ${EMAIL_DOMAIN}" "$HTTPD_CONF"
                    log_info "Added ${EMAIL_DOMAIN} mapping to ${LISTENER} listener"
                fi
                # Wildcard mail-client autodiscovery hostnames -> this vhost.
                # DNS provisioning CNAMEs autodiscover.<domain> / autoconfig.<domain>
                # at this server for EVERY hosted mail domain; one wildcard map per
                # listener routes them all to the email app, whose rewrite rules
                # hand the XML endpoints to the PHP backend. Exact per-site maps
                # (e.g. "map site.tld mail.site.tld" added by the panel saga) still
                # take precedence over these wildcards.
                if ! sed -n "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/,/}/p" "$HTTPD_CONF" | grep -q "autodiscover\.\*"; then
                    sed -i "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/a\\  map                     ${EMAIL_DOMAIN} autodiscover.*, autoconfig.*" "$HTTPD_CONF"
                    log_info "Added autodiscover.*/autoconfig.* wildcard mapping to ${LISTENER} listener"
                fi
            fi
        done
    fi
    
    # Reload OLS to apply changes
    log_info "Reloading OpenLiteSpeed..."
    /usr/local/lsws/bin/lswsctrl reload 2>/dev/null || {
        log_warn "Failed to reload OLS gracefully, attempting restart..."
        systemctl restart lshttpd || log_warn "Failed to restart OLS"
    }
fi

# ============================================
# 12. Setup cron jobs (mirror the live MailFlow schedule)
# ============================================
# Installed to /etc/cron.d/mailflow-email as root, matching how the source server
# runs these (root crontab). Tenant/site-specific and host-infra crons are
# intentionally NOT cloned: customer wp-cron, per-site backup-runner jobs,
# rsync-to-NAS, the NAS mount watchdog, owner-only E2E tests, certbot, cpguard,
# sftp-jail, etc. The FlowOne shared-storage crons (storage dispatcher,
# nas-backup, tenant-retention) are installed by the shared library installer.
log_info "Setting up MailFlow cron jobs..."

CRON_FILE="/etc/cron.d/mailflow-email"
CRON_DIR="$INSTALL_PATH/backend/cron"
LOGS="$INSTALL_PATH/backend/storage/logs"
P="$PHP_BIN"

# flock lock dir + the log dirs the entries reference
mkdir -p /root/cronlocks
mkdir -p "$LOGS" "$INSTALL_PATH/storage/logs"
mkdir -p /var/log/flowone 2>/dev/null || true

# Migrate away from older versions that wrote these to root's user crontab
if command -v crontab >/dev/null 2>&1 && crontab -l 2>/dev/null | grep -q "$INSTALL_PATH/backend/cron/"; then
    crontab -l 2>/dev/null \
        | grep -v "$INSTALL_PATH/backend/cron/" \
        | grep -v "$INSTALL_PATH/backend/aggregate-stats.php" \
        | grep -v "$INSTALL_PATH/backend/scripts/security-scan.sh" \
        | crontab - 2>/dev/null || true
fi

# Append a cron line only when its target script/exec is present in this build
CRON_BODY=""
add_cron() {
    if [ -e "$1" ]; then
        CRON_BODY="${CRON_BODY}$2"$'\n'
    fi
}

# --- Mail sync, delivery & automation ---
add_cron "$CRON_DIR/reconcile-mailboxes.php"        "0 * * * * root flock -n /root/cronlocks/reconcile.lock $P $CRON_DIR/reconcile-mailboxes.php >> /var/log/mailbox-reconcile.log 2>&1"
add_cron "$CRON_DIR/process-email-queue.php"        "* * * * * root flock -n /root/cronlocks/email-queue.lock $P $CRON_DIR/process-email-queue.php --batch-size=10 >> /var/log/email-queue.log 2>&1"
add_cron "$CRON_DIR/process-scheduled-emails.php"   "* * * * * root for i in 0 1 2 3 4 5; do flock -n /root/cronlocks/scheduled-emails.lock $P $CRON_DIR/process-scheduled-emails.php --batch=5; sleep 10; done"
add_cron "$CRON_DIR/process-scheduled-chat.php"     "* * * * * root flock -n /root/cronlocks/scheduled-chat.lock $P $CRON_DIR/process-scheduled-chat.php >> /var/log/scheduled-chat.log 2>&1"
add_cron "$CRON_DIR/drain-outbox.php"               "* * * * * root $P $CRON_DIR/drain-outbox.php >> $LOGS/drain-outbox-cron.log 2>&1"
add_cron "$CRON_DIR/sync-mailbox.php"               "*/5 * * * * root flock -n /root/cronlocks/sync-mailbox.lock timeout 280 $P $CRON_DIR/sync-mailbox.php >> $LOGS/sync-mailbox-cron.log 2>&1"
add_cron "$CRON_DIR/process-automation-hub.php"     "* * * * * root flock -n /root/cronlocks/automation-hub.lock $P $CRON_DIR/process-automation-hub.php >> /var/log/automation-hub-cron.log 2>&1"
add_cron "$CRON_DIR/process-crm-automation.php"     "*/5 * * * * root flock -n /root/cronlocks/crm-automation.lock $P $CRON_DIR/process-crm-automation.php >> /var/log/crm-automation.log 2>&1"

# --- Board Pro & Project Hub background checks ---
add_cron "$CRON_DIR/process-boardpro-automation.php" "*/5 * * * * root flock -n /root/cronlocks/boardpro-automation.lock timeout 240 $P $CRON_DIR/process-boardpro-automation.php --verbose >> /var/log/boardpro-automation.log 2>&1"
add_cron "$CRON_DIR/process-scope-radar.php"        "0 9 * * * root flock -n /root/cronlocks/scope-radar.lock timeout 600 $P $CRON_DIR/process-scope-radar.php --verbose >> /var/log/scope-radar.log 2>&1"
add_cron "$CRON_DIR/run-projecthub-inactivity.php"  "30 7 * * * root flock -n /root/cronlocks/projecthub-inactivity.lock timeout 300 $P $CRON_DIR/run-projecthub-inactivity.php --verbose >> /var/log/projecthub-inactivity.log 2>&1"

# --- Search / attachment indexing (cd into backend; scripts use relative paths) ---
add_cron "$CRON_DIR/index-attachments.php"          "*/5 * * * * root cd $INSTALL_PATH/backend && flock -n /root/cronlocks/index-attachments.lock timeout 120 $P cron/index-attachments.php >> /var/log/attachment-indexer.log 2>&1"
add_cron "$CRON_DIR/register-attachments.php"       "*/15 * * * * root cd $INSTALL_PATH/backend && flock -n /root/cronlocks/register-attachments.lock timeout 180 $P cron/register-attachments.php >> /var/log/attachment-register.log 2>&1"
add_cron "$CRON_DIR/index-meilisearch.php"          "17 */1 * * * root flock -n /root/cronlocks/index-meilisearch.lock timeout 600 $P $CRON_DIR/index-meilisearch.php >> $LOGS/index-meilisearch-cron.log 2>&1"

# --- Calendar / OAuth ---
add_cron "$CRON_DIR/refresh-oauth-tokens.php"       "*/15 * * * * root flock -n /root/cronlocks/refresh-oauth-tokens.lock timeout 60 $P $CRON_DIR/refresh-oauth-tokens.php >> $LOGS/refresh-oauth-cron.log 2>&1"
add_cron "$CRON_DIR/sync-google-calendars.php"      "*/5 * * * * root flock -n /root/cronlocks/sync-google-calendars.lock timeout 240 $P $CRON_DIR/sync-google-calendars.php >> $LOGS/calendar-sync-cron.log 2>&1"
add_cron "$CRON_DIR/renew-calendar-push-channels.php" "0 */1 * * * root flock -n /root/cronlocks/renew-calendar-push-channels.lock timeout 120 $P $CRON_DIR/renew-calendar-push-channels.php >> $LOGS/calendar-channels-cron.log 2>&1"

# --- UI counters / news ---
add_cron "$CRON_DIR/refresh-unread-counts.php"      "*/2 * * * * root flock -n /root/cronlocks/refresh-unread-counts.lock timeout 90 $P $CRON_DIR/refresh-unread-counts.php >> $LOGS/refresh-unread-cron.log 2>&1"
add_cron "$CRON_DIR/news-refresh.php"               "*/15 * * * * root flock -n /root/cronlocks/news-refresh.lock $P $CRON_DIR/news-refresh.php >> $INSTALL_PATH/storage/logs/news-refresh.log 2>&1"

# --- Folder identity / maintenance ---
add_cron "$CRON_DIR/folder-rename-analyzer.php"     "* * * * * root flock -n /root/cronlocks/folder-rename-analyzer.lock timeout 55 $P $CRON_DIR/folder-rename-analyzer.php >> /var/log/folder-rename-analyzer.log 2>&1"
add_cron "$CRON_DIR/prune-folder-snapshots.php"     "23 * * * * root flock -n /root/cronlocks/prune-folder-snapshots.lock $P $CRON_DIR/prune-folder-snapshots.php >> /var/log/prune-folder-snapshots.log 2>&1"
add_cron "$CRON_DIR/verify-folder-identity-consistency.php" "25 2 * * * root flock -n /root/cronlocks/verify-folder-identity-consistency.lock $P $CRON_DIR/verify-folder-identity-consistency.php >> /var/log/folder-identity-consistency.log 2>&1"
add_cron "$CRON_DIR/backfill-folder-ids.php"        "17 */6 * * * root flock -n /root/cronlocks/backfill-folder-ids.lock $P $CRON_DIR/backfill-folder-ids.php --batch-size=500 >> /var/log/backfill-folder-ids.log 2>&1"
add_cron "$CRON_DIR/dual-write-readiness.php"       "5 2 * * 0 root $P $CRON_DIR/dual-write-readiness.php"

# --- Drive cleanup & FlowOne storage tiering ---
add_cron "$CRON_DIR/cleanup-drive.php"              "0 * * * * root flock -n /tmp/cleanup-drive.lock timeout 120 $P $CRON_DIR/cleanup-drive.php"
add_cron "$CRON_DIR/drive-tier-backfill.php"        "13 * * * * root /usr/bin/flock -n /var/lock/flowone-drive-tier-backfill.lock $P $CRON_DIR/drive-tier-backfill.php --apply >> /var/log/flowone/drive-tier-backfill.log 2>&1"
add_cron "$CRON_DIR/drive-tier-down.php"            "23 * * * * root /usr/bin/flock -n /var/lock/flowone-drive-tier-down.lock $P $CRON_DIR/drive-tier-down.php --apply >> /var/log/flowone/drive-tier-down.log 2>&1"

# --- Stats & security scan ---
add_cron "$INSTALL_PATH/backend/aggregate-stats.php"      "0 * * * * root flock -n /root/cronlocks/aggregate-stats.lock $P $INSTALL_PATH/backend/aggregate-stats.php >> /var/log/webmail-stats.log 2>&1"
add_cron "$INSTALL_PATH/backend/scripts/security-scan.sh" "0 3 * * * root flock -n /root/cronlocks/security-scan.lock $INSTALL_PATH/backend/scripts/security-scan.sh >> /var/log/security-scan.log 2>&1"

if [ -n "$CRON_BODY" ]; then
    {
        echo "# Managed by Fleet Manager - MailFlow Email App cron jobs"
        echo "# Tenant sites, per-site backups and host infra crons are intentionally excluded."
        echo "SHELL=/bin/bash"
        echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
        echo ""
        printf '%s' "$CRON_BODY"
    } > "$CRON_FILE"
    chmod 644 "$CRON_FILE"
    [ -f "$INSTALL_PATH/backend/scripts/security-scan.sh" ] && chmod +x "$INSTALL_PATH/backend/scripts/security-scan.sh" 2>/dev/null || true
    log_info "MailFlow cron jobs installed to $CRON_FILE"
else
    log_warn "No cron scripts found to schedule"
fi

# ============================================
# 13. Final verification
# ============================================
log_info "Running final verification..."

INSTALL_STATUS="SUCCESS"
WARNINGS=""

# Verify files exist
if [ ! -f "$INSTALL_PATH/dist/index.html" ]; then
    WARNINGS="${WARNINGS}  - dist/index.html missing\n"
fi
if [ ! -f "$INSTALL_PATH/backend/public/index.php" ]; then
    WARNINGS="${WARNINGS}  - backend/public/index.php missing\n"
fi

# Verify JWT keys
if [ ! -f "$JWT_PRIVATE_KEY" ]; then
    WARNINGS="${WARNINGS}  - JWT private key missing\n"
    INSTALL_STATUS="PARTIAL"
fi
if [ ! -f "$JWT_PUBLIC_KEY" ]; then
    WARNINGS="${WARNINGS}  - JWT public key missing\n"
    INSTALL_STATUS="PARTIAL"
fi

# Verify services
if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
    WARNINGS="${WARNINGS}  - OpenLiteSpeed not running\n"
    INSTALL_STATUS="PARTIAL"
fi
if ! systemctl is-active --quiet mariadb 2>/dev/null; then
    WARNINGS="${WARNINGS}  - MariaDB not running\n"
    INSTALL_STATUS="PARTIAL"
fi
if ! systemctl is-active --quiet postfix 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Postfix not running\n"
fi
if ! systemctl is-active --quiet dovecot 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Dovecot not running\n"
fi
if ! systemctl is-active --quiet redis-server 2>/dev/null && ! systemctl is-active --quiet redis 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Redis not running\n"
fi
if ! systemctl is-active --quiet meilisearch 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Meilisearch not running (search won't work)\n"
fi
if [ "${SKIP_COLLAB:-0}" != "1" ] && ! systemctl is-active --quiet collab-server 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Collab server not running\n"
fi
if [ "${SKIP_MAILSYNC:-0}" != "1" ] && ! systemctl is-active --quiet mailsync-server 2>/dev/null; then
    WARNINGS="${WARNINGS}  - Mailsync server not running\n"
fi
if [ "${SKIP_OFFICE:-0}" != "1" ] && [ -d "$INSTALL_PATH/office" ]; then
    if ! command -v docker >/dev/null 2>&1 || ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx flowone-office; then
        WARNINGS="${WARNINGS}  - OnlyOffice container (flowone-office) not running - office editing disabled\n"
    fi
fi

# Verify database connection (use MYSQL_PWD to avoid shell quoting issues)
if [ "${SKIP_DB:-0}" != "1" ]; then
    if ! MYSQL_PWD="$DB_PASS" mysql -u "$DB_USER" -e "USE $DB_NAME" 2>/dev/null; then
        WARNINGS="${WARNINGS}  - Cannot connect to database\n"
        INSTALL_STATUS="PARTIAL"
    fi
fi

# Verify runtime dependencies + schema (computed in section 7/7b)
if [ "${COMPOSER_OK:-1}" != "1" ]; then
    WARNINGS="${WARNINGS}  - composer install failed - vendor/ incomplete\n"
    INSTALL_STATUS="PARTIAL"
fi
if [ "${PHPOFFICE_OK:-1}" != "1" ]; then
    WARNINGS="${WARNINGS}  - PhpOffice/ZipArchive unavailable under lsphp83 - office file creation will 500\n"
    INSTALL_STATUS="PARTIAL"
fi
if [ "${SCHEMA_OK:-1}" != "1" ]; then
    WARNINGS="${WARNINGS}  - Schema warm-up incomplete - run backend/scripts/ensure-schema.php --verbose\n"
    INSTALL_STATUS="PARTIAL"
fi

# Office editor smoke test (shipped with backend/tests). Preflight always;
# the blank-file-creation group runs only when the Document Server container
# is up. Test data is flowone_test_-prefixed and self-cleaning.
OFFICE_TEST="$INSTALL_PATH/backend/tests/office-editor-test.php"
if [ "${SKIP_OFFICE:-0}" != "1" ] && [ -f "$OFFICE_TEST" ] && [ -f "$INSTALL_PATH/backend/vendor/autoload.php" ]; then
    OFFICE_GROUPS="preflight"
    if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx flowone-office; then
        OFFICE_GROUPS="preflight,files"
    fi
    if "$PHP_BIN" "$OFFICE_TEST" --only="$OFFICE_GROUPS" --skip-send --json > /tmp/office-smoke.json 2>&1; then
        log_info "Office smoke test passed (groups: $OFFICE_GROUPS)"
    else
        log_warn "Office smoke test FAILED (groups: $OFFICE_GROUPS) - see /tmp/office-smoke.json"
        WARNINGS="${WARNINGS}  - Office smoke test failed (${OFFICE_GROUPS}) - office editing may be broken\n"
    fi
fi

# ============================================
# Done
# ============================================
echo ""
echo "========================================="
if [ "$INSTALL_STATUS" = "SUCCESS" ]; then
    echo -e "${GREEN}  Installation Complete!${NC}"
else
    echo -e "${YELLOW}  Installation Completed with Warnings${NC}"
fi
echo "========================================="
echo ""
echo "  Email App URL:  https://${EMAIL_DOMAIN}"
echo "  Mail Domain:    ${MAIL_DOMAIN}"
echo "  Install path:   ${INSTALL_PATH}"
echo ""

if [ -n "$WARNINGS" ]; then
    echo -e "${YELLOW}Warnings:${NC}"
    echo -e "$WARNINGS"
    echo ""
fi

echo "Service Status:"
systemctl is-active lshttpd 2>/dev/null && echo "  OpenLiteSpeed:  RUNNING" || echo "  OpenLiteSpeed:  NOT RUNNING"
systemctl is-active mariadb 2>/dev/null && echo "  MariaDB:        RUNNING" || echo "  MariaDB:        NOT RUNNING"
systemctl is-active postfix 2>/dev/null && echo "  Postfix:        RUNNING" || echo "  Postfix:        NOT RUNNING"
systemctl is-active dovecot 2>/dev/null && echo "  Dovecot:        RUNNING" || echo "  Dovecot:        NOT RUNNING"
systemctl is-active redis-server 2>/dev/null || systemctl is-active redis 2>/dev/null && echo "  Redis:          RUNNING" || echo "  Redis:          NOT RUNNING"
systemctl is-active meilisearch 2>/dev/null && echo "  Meilisearch:    RUNNING" || echo "  Meilisearch:    NOT RUNNING"
[ "${SKIP_COLLAB:-0}" != "1" ] && (systemctl is-active collab-server 2>/dev/null && echo "  Collab Server:  RUNNING" || echo "  Collab Server:  NOT RUNNING")
[ "${SKIP_MAILSYNC:-0}" != "1" ] && (systemctl is-active mailsync-server 2>/dev/null && echo "  Mailsync:       RUNNING" || echo "  Mailsync:       NOT RUNNING")
[ "${SKIP_OFFICE:-0}" != "1" ] && (docker ps --format '{{.Names}}' 2>/dev/null | grep -qx flowone-office && echo "  OnlyOffice:     RUNNING (:8443)" || echo "  OnlyOffice:     NOT RUNNING")
echo ""

if [ "${SKIP_VHOST:-0}" = "1" ]; then
    echo "Next steps:"
    echo "  1. Add vhost to OLS httpd_config.conf"
    echo "  2. Reload OLS: /usr/local/lsws/bin/lswsctrl reload"
fi
echo "  3. Setup SSL: certbot --webroot -w ${INSTALL_PATH}/dist -d ${EMAIL_DOMAIN}"
echo "  4. Verify Dovecot and Postfix are configured for ${MAIL_DOMAIN}"
echo ""
