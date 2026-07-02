#!/bin/bash
#
# VPS Admin Panel - Remote Installer Script
# This script is run on the target server after the package is extracted
#
# Usage: ./install.sh --domain=panel.example.com --db-name=panel_db ...
#
# Required variables (passed as arguments or environment):
#   PANEL_DOMAIN    - Panel domain (e.g., panel.example.com)
#   DB_NAME         - Database name
#   DB_USER         - Database username  
#   DB_PASS         - Database password
#   ADMIN_EMAIL     - Admin user email
#   ADMIN_PASS      - Admin user password (will be hashed)
#   AGENT_TOKEN     - Token for agent authentication
#   FLEET_URL       - Fleet Manager API URL (e.g., https://fleet.example.com)
#
# Optional:
#   SKIP_DB         - Skip database setup (1/0)
#   SKIP_AGENT      - Skip agent setup (1/0)
#   SKIP_VHOST      - Skip vhost creation (1/0)
#   --update-only   - Only update code files, preserve configs
#

# NOTE: No 'set -e' here! We handle errors explicitly so the installer
# doesn't abort on non-critical failures (e.g., missing optional extensions).
# Critical errors call log_error which exits explicitly.

# Update mode flag
UPDATE_ONLY=0

# Database host. Native installs use the local unix socket (localhost). DOCKER
# installs pass --db-host=127.0.0.1 so every mysql client + the panel runtime
# talk to the containerized MariaDB over TCP (published on the host loopback);
# there is NO native mariadb service to manage in that mode.
DB_HOST="localhost"

# Installation paths
INSTALL_PATH="/var/www/vps-admin"
AGENT_PATH="/opt/vps-admin"
OLS_CONF="/usr/local/lsws/conf"

# Parse arguments
for arg in "$@"; do
    case $arg in
        --domain=*) PANEL_DOMAIN="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --db-host=*) DB_HOST="${arg#*=}" ;;
        --db-user=*) DB_USER="${arg#*=}" ;;
        --db-pass=*) DB_PASS="${arg#*=}" ;;
        --db-root-pass=*) DB_ROOT_PASS="${arg#*=}" ;;
        --admin-email=*) ADMIN_EMAIL="${arg#*=}" ;;
        --admin-pass=*) ADMIN_PASS="${arg#*=}" ;;
        --agent-token=*) AGENT_TOKEN="${arg#*=}" ;;
        --fleet-url=*) FLEET_URL="${arg#*=}" ;;
        --email-api-key=*) EMAIL_API_KEY="${arg#*=}" ;;
        --skip-db) SKIP_DB=1 ;;
        --skip-agent) SKIP_AGENT=1 ;;
        --skip-vhost) SKIP_VHOST=1 ;;
        --skip-verify) SKIP_VERIFY=1 ;;
        --update-only) UPDATE_ONLY=1 ;;
    esac
done

# Colors + logging helpers MUST be defined before any log_* call below.
# (Previously these lived after the UPDATE-ONLY block, so `install.sh --update-only`
# called log_info before it existed.)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
log_error_no_exit() { echo -e "${RED}[ERROR]${NC} $1"; }

# If update-only mode, skip db, agent, vhost, and config creation
if [ "$UPDATE_ONLY" = "1" ]; then
    SKIP_DB=1
    SKIP_AGENT=1
    SKIP_VHOST=1
    SKIP_VERIFY=1
    log_info "Running in UPDATE-ONLY mode - preserving existing configs"
fi

# Validate required variables
validate_vars() {
    # Skip validation in update-only mode
    if [ "$UPDATE_ONLY" = "1" ]; then
        return 0
    fi

    local missing=0
    
    [ -z "$PANEL_DOMAIN" ] && { log_error_no_exit "PANEL_DOMAIN is required"; missing=1; }
    [ -z "$DB_NAME" ] && { log_error_no_exit "DB_NAME is required"; missing=1; }
    [ -z "$DB_USER" ] && { log_error_no_exit "DB_USER is required"; missing=1; }
    [ -z "$DB_PASS" ] && { log_error_no_exit "DB_PASS is required"; missing=1; }
    
    if [ $missing -eq 1 ]; then
        echo ""
        echo "Usage: $0 --domain=panel.example.com --db-name=panel_db --db-user=panel --db-pass=secret"
        echo "   or: $0 --update-only   (to update code files only)"
        exit 1
    fi
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

# Ensure PHP MySQL extensions are enabled
verify_php_extensions() {
    log_info "Verifying PHP MySQL extensions..."
    
    PHP_MODS_DIR="/usr/local/lsws/lsphp83/etc/php/8.3/mods-available"
    PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
    
    # Ensure PHP binary is executable
    if [ -f "$PHP_BIN" ] && [ ! -x "$PHP_BIN" ]; then
        log_info "Fixing PHP binary permissions..."
        chmod +x /usr/local/lsws/lsphp83/bin/* 2>/dev/null || true
    fi
    
    # Create ini files if missing (these are required but may not be auto-created)
    if [ ! -f "$PHP_MODS_DIR/pdo_mysql.ini" ]; then
        mkdir -p "$PHP_MODS_DIR"
        echo "extension=pdo_mysql.so" > "$PHP_MODS_DIR/pdo_mysql.ini"
        log_info "Created pdo_mysql.ini"
    fi
    
    if [ ! -f "$PHP_MODS_DIR/mysqli.ini" ]; then
        mkdir -p "$PHP_MODS_DIR"
        echo "extension=mysqli.so" > "$PHP_MODS_DIR/mysqli.ini"
        log_info "Created mysqli.ini"
    fi
    
    # Verify extensions load (use system php if lsphp fails)
    if [ -x "$PHP_BIN" ]; then
        if $PHP_BIN -m 2>/dev/null | grep -q pdo_mysql; then
            log_info "PHP MySQL extensions verified"
        else
            log_warn "pdo_mysql extension may not be loaded - check PHP config"
        fi
    elif command -v php >/dev/null 2>&1; then
        if php -m 2>/dev/null | grep -q pdo_mysql; then
            log_info "PHP MySQL extensions verified (via system php)"
        else
            log_warn "pdo_mysql extension may not be loaded - check PHP config"
        fi
    else
        log_warn "Could not verify PHP extensions - PHP binary not accessible"
    fi
}

# Verify MariaDB is running
# MySQL client host option: empty (local socket) for native, "-h <host>" for the
# Docker/TCP case. Set once verify_mariadb runs; reused by the DB-setup block.
MYSQL_HOSTOPT=""

verify_mariadb() {
    log_info "Verifying MariaDB (host: ${DB_HOST:-localhost})..."

    case "${DB_HOST:-localhost}" in
        ""|localhost)
            # Native install: a local mariadb service must be up.
            if ! systemctl is-active --quiet mariadb 2>/dev/null; then
                log_info "Starting MariaDB service..."
                systemctl start mariadb || log_error "MariaDB failed to start"
            fi
            MYSQL_HOSTOPT=""
            ;;
        *)
            # Docker/TCP install: the DB is a container published on the host
            # loopback. There is NO native mariadb unit to start — connect over TCP.
            MYSQL_HOSTOPT="-h ${DB_HOST}"
            ;;
    esac

    # Test connectivity with retries — a freshly (re)started container DB can still
    # be warming up. NEVER hard-fail here: the DB lifecycle is managed elsewhere
    # (compose) on Docker boxes, and provisioning already verified it on native.
    local ok=0 i
    for i in $(seq 1 10); do
        if [ -n "$DB_ROOT_PASS" ] && MYSQL_PWD="$DB_ROOT_PASS" mysql $MYSQL_HOSTOPT -u root -e "SELECT 1" >/dev/null 2>&1; then
            ok=1; break
        fi
        # Socket unix_socket auth fallback (native only).
        if [ -z "$MYSQL_HOSTOPT" ] && mysql -e "SELECT 1" >/dev/null 2>&1; then
            ok=1; break
        fi
        sleep 3
    done

    if [ "$ok" = "1" ]; then
        log_info "MariaDB reachable."
    else
        log_warn "Cannot confirm MariaDB connectivity on '${DB_HOST:-localhost}' - continuing (provisioning/compose manages the DB)."
    fi
}

echo ""
echo "========================================="
if [ "$UPDATE_ONLY" = "1" ]; then
    echo "  VPS Admin Panel - Code Update"
else
    echo "  VPS Admin Panel Installer"
fi
echo "========================================="
echo ""

# Get script directory (where package was extracted)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

validate_vars

if [ "$UPDATE_ONLY" = "1" ]; then
    log_info "Updating VPS Admin Panel code (configs preserved)..."
else
    log_info "Installing VPS Admin Panel..."
    log_info "Domain: $PANEL_DOMAIN"
fi
log_info "Install path: $INSTALL_PATH"

# Pre-flight checks
verify_ols
verify_php_extensions
verify_mariadb

# ============================================
# 1. Create directories
# ============================================
log_info "Creating directories..."

mkdir -p "$INSTALL_PATH"/{api,assets}
mkdir -p "$INSTALL_PATH/storage"/{logs,cache,backups}
mkdir -p "$INSTALL_PATH"/{var,agent,backups}
mkdir -p "$AGENT_PATH"/{agent,var,backups,logs}
mkdir -p "$AGENT_PATH/backups"/{configs,databases,deleted_vhosts,deleted_sites,deleted_mail}

# ============================================
# 2. Copy files
# ============================================
log_info "Copying files..."

# Copy API
if [ -d "$SCRIPT_DIR/api" ]; then
    cp -r "$SCRIPT_DIR/api/"* "$INSTALL_PATH/api/"
    log_info "API files copied"
else
    log_error "API directory not found in package!"
fi

# Copy Dashboard/Assets (handle both old and new package structure)
DASHBOARD_COPIED=0

# New structure: assets/ at root
if [ -d "$SCRIPT_DIR/assets" ]; then
    cp -r "$SCRIPT_DIR/assets/"* "$INSTALL_PATH/assets/"
    DASHBOARD_COPIED=1
    log_info "Dashboard assets copied (new structure)"
fi

# Old structure: dashboard/dist/
if [ -d "$SCRIPT_DIR/dashboard/dist" ] && [ $DASHBOARD_COPIED -eq 0 ]; then
    cp -r "$SCRIPT_DIR/dashboard/dist/"* "$INSTALL_PATH/"
    DASHBOARD_COPIED=1
    log_info "Dashboard files copied (old structure)"
fi

if [ $DASHBOARD_COPIED -eq 0 ]; then
    log_warn "No dashboard files found in package - frontend may not work"
fi

# Copy root-level files (index.html, favicon.svg, .htaccess, VERSION)
for file in index.html favicon.svg .htaccess VERSION BUILD_DATE; do
    if [ -f "$SCRIPT_DIR/$file" ]; then
        cp "$SCRIPT_DIR/$file" "$INSTALL_PATH/"
    fi
done

# Deploy self-hosted fonts to the docroot. index.html loads /fonts/core.css;
# without these the Material Symbols icons render as ligature TEXT
# (e.g. "settings", "language") all over the panel.
if [ -d "$SCRIPT_DIR/fonts" ]; then
    mkdir -p "$INSTALL_PATH/fonts"
    cp -r "$SCRIPT_DIR/fonts/"* "$INSTALL_PATH/fonts/"
    FONT_FAMILIES=$(ls -d "$INSTALL_PATH"/fonts/*/ 2>/dev/null | wc -l)
    log_info "Fonts deployed (${FONT_FAMILIES} families)"
else
    log_warn "No fonts/ in package - panel icons will render as text ligatures!"
fi

# Local JS libs (tailwind.min.js etc.) served from /js/
if [ -d "$SCRIPT_DIR/js" ]; then
    mkdir -p "$INSTALL_PATH/js"
    cp -r "$SCRIPT_DIR/js/"* "$INSTALL_PATH/js/"
    log_info "Local JS assets deployed"
fi

# Copy config directory if exists
if [ -d "$SCRIPT_DIR/config" ]; then
    mkdir -p "$INSTALL_PATH/config"
    cp -r "$SCRIPT_DIR/config/"* "$INSTALL_PATH/config/" 2>/dev/null || true
fi

# Copy templates if exists
if [ -d "$SCRIPT_DIR/templates" ]; then
    mkdir -p "$INSTALL_PATH/templates"
    cp -r "$SCRIPT_DIR/templates/"* "$INSTALL_PATH/templates/" 2>/dev/null || true
fi

# Copy database schema/migrations
if [ -d "$SCRIPT_DIR/database" ]; then
    mkdir -p "$INSTALL_PATH/database"
    cp -r "$SCRIPT_DIR/database/"* "$INSTALL_PATH/database/" 2>/dev/null || true
fi

# Copy phpMyAdmin if exists
if [ -d "$SCRIPT_DIR/phpmyadmin" ]; then
    cp -r "$SCRIPT_DIR/phpmyadmin" "$INSTALL_PATH/"
    log_info "phpMyAdmin copied"
fi

# Copy Agent
if [ -d "$SCRIPT_DIR/agent" ]; then
    cp -r "$SCRIPT_DIR/agent/"* "$AGENT_PATH/agent/"
    log_info "Agent files copied"
else
    log_warn "Agent directory not found in package"
fi

# ============================================
# 3. Create API config (skip in update-only mode)
# ============================================
if [ "$UPDATE_ONLY" = "1" ] && [ -f "$INSTALL_PATH/api/config.local.php" ]; then
    log_info "Preserving existing API configuration..."
else
    log_info "Creating API configuration..."

    # Generate JWT secret (HS256 fallback) and RSA key pair (RS256 primary)
    JWT_SECRET=$(openssl rand -hex 32)

    mkdir -p "$INSTALL_PATH/var"
    if [ ! -f "$INSTALL_PATH/var/jwt-private.pem" ]; then
        openssl genrsa -out "$INSTALL_PATH/var/jwt-private.pem" 2048 2>/dev/null
        openssl rsa -in "$INSTALL_PATH/var/jwt-private.pem" -pubout -out "$INSTALL_PATH/var/jwt-public.pem" 2>/dev/null
        chown www-data:www-data "$INSTALL_PATH/var/jwt-private.pem" "$INSTALL_PATH/var/jwt-public.pem"
        chmod 600 "$INSTALL_PATH/var/jwt-private.pem"
        chmod 644 "$INSTALL_PATH/var/jwt-public.pem"
        log_info "Generated JWT RSA key pair"
    fi

    # Use single-quoted heredoc to prevent bash expansion, then replace placeholders with PHP
    cat > "$INSTALL_PATH/api/config.local.php" << 'EOF'
<?php
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => '__DB_NAME__',
        'user' => '__DB_USER__',
        'password' => '__DB_PASS__',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret' => '__JWT_SECRET__',
        'issuer' => 'https://__PANEL_DOMAIN__',
    ],
    'agent' => [
        'socket' => '/run/vps-admin/agent.sock',
        'token_file' => '__AGENT_PATH__/var/agent.token',
    ],
    'app' => [
        'name' => 'VPS Admin Panel',
        'url' => 'https://__PANEL_DOMAIN__',
        'debug' => false,
    ],
    'external_api' => [
        'keys' => [
            'email_app' => '__EMAIL_API_KEY__',
        ],
    ],
];
EOF

    # Find PHP binary
    PHP_BIN="php"
    [ ! -x "$(command -v php 2>/dev/null)" ] && PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
    
    # Generate EMAIL_API_KEY if not provided (Email App uses this to call Panel API)
    if [ -z "$EMAIL_API_KEY" ]; then
        EMAIL_API_KEY=$(openssl rand -hex 32)
        log_info "Generated Email App API key: ${EMAIL_API_KEY:0:8}..."
    fi
    
    # Replace placeholders safely using PHP (handles any special chars in passwords)
    $PHP_BIN -r '
        $file = $argv[1];
        $content = file_get_contents($file);
        $content = str_replace(
            ["__DB_NAME__", "__DB_USER__", "__DB_PASS__", "__JWT_SECRET__", "__PANEL_DOMAIN__", "__AGENT_PATH__", "__EMAIL_API_KEY__"],
            [$argv[2], $argv[3], $argv[4], $argv[5], $argv[6], $argv[7], $argv[8]],
            $content
        );
        file_put_contents($file, $content);
    ' "$INSTALL_PATH/api/config.local.php" \
      "$DB_NAME" "$DB_USER" "$DB_PASS" "$JWT_SECRET" "$PANEL_DOMAIN" "$AGENT_PATH" "$EMAIL_API_KEY" \
    2>/dev/null || log_warn "Config replacement with PHP failed - check config.local.php manually"
fi

# ============================================
# 4. Setup Database
# ============================================
if [ "${SKIP_DB:-0}" != "1" ]; then
    log_info "Setting up database..."
    
    # Build mysql command with or without root password.
    # MYSQL_HOSTOPT (set by verify_mariadb) is empty on native (socket) or
    # "-h 127.0.0.1" on Docker/TCP. Env var for the password avoids shell quoting
    # issues with special chars.
    if [ -n "$DB_ROOT_PASS" ]; then
        export MYSQL_PWD="$DB_ROOT_PASS"
        MYSQL_CMD="mysql $MYSQL_HOSTOPT -u root"
    else
        MYSQL_CMD="mysql $MYSQL_HOSTOPT"
    fi

    # On native the app connects over the local socket (user@'localhost'); on
    # Docker the native panel connects to the CONTAINER over TCP and arrives from
    # the docker bridge, so the user must be granted for '%' (the compose init
    # already creates it — this is an idempotent safety net).
    if [ -n "$MYSQL_HOSTOPT" ]; then DB_USER_HOST='%'; else DB_USER_HOST='localhost'; fi

    # Create database and user (these should already exist from provisioning, but ensure they're there)
    # Use printf to avoid bash expansion issues with special chars in passwords ($, !, etc.)
    printf "CREATE DATABASE IF NOT EXISTS \`%s\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n" "$DB_NAME" | $MYSQL_CMD 2>&1 || log_warn "DB create may have failed"
    printf "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_USER" "$DB_USER_HOST" "$DB_PASS" | $MYSQL_CMD 2>&1 || log_warn "DB user create may have failed"
    printf "GRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%s';\n" "$DB_NAME" "$DB_USER" "$DB_USER_HOST" | $MYSQL_CMD 2>&1 || log_warn "DB grant may have failed"
    printf "FLUSH PRIVILEGES;\n" | $MYSQL_CMD 2>&1 || true
    
    # Run schema (remove CREATE DATABASE and USE statements to use our DB_NAME)
    if [ -f "$SCRIPT_DIR/database/schema.sql" ]; then
        log_info "Importing database schema..."
        # Use sed to remove CREATE DATABASE block and USE statement
        sed -e '/^CREATE DATABASE/,/;/d' -e '/^USE /d' "$SCRIPT_DIR/database/schema.sql" | \
        $MYSQL_CMD "$DB_NAME" 2>&1 || {
            log_warn "Schema import had errors (tables may already exist) - continuing"
        }
        log_info "Schema imported"
    else
        log_warn "No schema.sql found in package"
    fi

    # Layer the authoritative source schema on top. api/schema.sql is maintained
    # with the code (mail/DNS/imap_migrations/mail_security_* tables, their
    # current columns like mail_accounts.force_password_change, and the
    # mail_security_settings / attachment-policy seed rows). It is fully
    # idempotent (CREATE TABLE IF NOT EXISTS + INSERT IGNORE), so it safely
    # fills whatever the - possibly stale - database/schema.sql dump missed.
    if [ -f "$SCRIPT_DIR/api/schema.sql" ]; then
        log_info "Applying authoritative api/schema.sql on top (idempotent)..."
        sed -e '/^CREATE DATABASE/,/;/d' -e '/^USE /d' "$SCRIPT_DIR/api/schema.sql" | \
        $MYSQL_CMD "$DB_NAME" 2>&1 || {
            log_warn "api/schema.sql apply had errors - continuing"
        }
        log_info "api/schema.sql applied"
    else
        log_warn "No api/schema.sql in package - mail security tables will be created lazily on first use"
    fi
    
    # Ensure sessions table has all required columns (may be missing in older schemas)
    log_info "Ensuring database tables have all required columns..."
    echo "ALTER TABLE sessions ADD COLUMN device_name VARCHAR(255) NULL AFTER user_agent;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE sessions ADD COLUMN last_activity TIMESTAMP NULL AFTER device_name;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE sessions ADD COLUMN location VARCHAR(255) NULL AFTER last_activity;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    
    # Ensure admin_users table has 2FA columns
    echo "ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0 AFTER status;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(255) NULL AFTER totp_enabled;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE admin_users ADD COLUMN totp_backup_codes TEXT NULL AFTER totp_secret;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true

    # Ensure audit_logs table has required columns
    echo "ALTER TABLE audit_logs ADD COLUMN source_app VARCHAR(50) NOT NULL DEFAULT 'panel' AFTER id;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE audit_logs ADD COLUMN severity ENUM('critical','high','medium','low','info') NOT NULL DEFAULT 'info' AFTER source_app;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45) NULL AFTER actor;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    echo "ALTER TABLE audit_logs ADD COLUMN user_email VARCHAR(255) NULL AFTER ip_address;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true

    # Ensure mail_accounts has the force-password-change flag (used by the IMAP
    # migration tool: migrated users must pick a new password on first webmail
    # login). CREATE TABLE IF NOT EXISTS cannot add this to a pre-existing table.
    echo "ALTER TABLE mail_accounts ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Require a password change on next webmail login' AFTER last_login;" | $MYSQL_CMD "$DB_NAME" 2>/dev/null || true
    
    # Run migrations
    for migration in "$SCRIPT_DIR/database/"migrate_*.sql; do
        if [ -f "$migration" ]; then
            $MYSQL_CMD "$DB_NAME" < "$migration" 2>/dev/null || true
        fi
    done
    
    # Create admin user if provided
    if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASS" ]; then
        # Find a working PHP binary for password hashing
        PHP_BIN=""
        if command -v php >/dev/null 2>&1; then
            PHP_BIN="php"
        elif [ -x "/usr/local/lsws/lsphp83/bin/php" ]; then
            PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
        fi
        
        if [ -n "$PHP_BIN" ]; then
            ADMIN_HASH=$($PHP_BIN -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS" 2>/dev/null)
            if [ -n "$ADMIN_HASH" ]; then
                # Use printf to avoid bash expanding $ in bcrypt hash ($2y$10$...)
                printf "INSERT INTO admin_users (username, email, password_hash, role, status) VALUES ('pxradmin', '%s', '%s', 'super_admin', 'active') ON DUPLICATE KEY UPDATE email='%s', password_hash='%s';\n" "$ADMIN_EMAIL" "$ADMIN_HASH" "$ADMIN_EMAIL" "$ADMIN_HASH" | $MYSQL_CMD "$DB_NAME" 2>&1 || {
                    log_warn "Could not create admin user - may need manual setup"
                }
                log_info "Admin user created/updated"
            else
                log_warn "Could not hash admin password - admin user not created"
            fi
        else
            log_warn "No PHP binary available for password hashing - admin user not created"
        fi
    fi
fi

# ============================================
# 5. Install Composer dependencies
# ============================================
log_info "Installing PHP dependencies..."

cd "$INSTALL_PATH/api"
if [ -f "composer.json" ]; then
    # Try system composer first, then manually with lsphp83
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || {
            log_warn "Composer install failed with system composer, trying with lsphp83..."
            /usr/local/lsws/lsphp83/bin/php /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || {
                log_warn "Composer install failed - may need manual intervention"
            }
        }
    elif [ -x "/usr/local/lsws/lsphp83/bin/php" ]; then
        # No system composer, try downloading and running with lsphp83
        log_info "System composer not found, using lsphp83..."
        if [ ! -f "/usr/local/bin/composer" ]; then
            curl -sS --retry 3 --retry-delay 3 --connect-timeout 30 https://getcomposer.org/installer | /usr/local/lsws/lsphp83/bin/php -- --install-dir=/usr/local/bin --filename=composer 2>&1 || true
        fi
        /usr/local/lsws/lsphp83/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || {
            log_warn "Composer install failed - may need manual intervention"
        }
    else
        log_warn "No PHP binary found for composer - skipping dependency install"
    fi
else
    log_warn "No composer.json found in API directory"
fi

# ============================================
# 6. Setup Agent
# ============================================
if [ "${SKIP_AGENT:-0}" != "1" ]; then
    log_info "Setting up agent..."
    
    # Create agent config (must match structure expected by VPS Admin agent.php)
    cat > "$AGENT_PATH/agent/config.php" << EOF
<?php
return [
    // Socket configuration
    'socket' => [
        'path' => '/run/vps-admin/agent.sock',
        'permissions' => 0660,
        'group' => 'www-data',
    ],

    // Paths
    'paths' => [
        'base' => '${AGENT_PATH}',
        'token_file' => '${AGENT_PATH}/var/agent.token',
        'log_file' => '${AGENT_PATH}/logs/agent.log',
        'backups' => '${AGENT_PATH}/backups',
        'logs' => '${AGENT_PATH}/logs',
        'ols_config' => '/usr/local/lsws/conf/httpd_config.conf',
        'ols_vhosts' => '/usr/local/lsws/conf/vhosts',
        'ols_bin' => '/usr/local/lsws/bin',
        'ssl_certs' => '/etc/letsencrypt/live',
        'webroot' => '/home',
    ],

    // Allowed services for management
    'allowed_services' => [
        'lsws',
        'mysql',
        'mariadb',
        'redis',
        'postfix',
        'dovecot',
        'vpsadmin-agent',
        'fail2ban',
        'firewalld',
        'pdns',
        'mailsync-server',
        'collab-server',
        'meilisearch',
        'spamd',
        'spamassassin',
    ],

    // Security
    'security' => [
        'allowed_clients' => ['www-data', 'vpsadmin'],
        'require_auth_token' => true,
    ],

    // Logging
    'logging' => [
        'level' => 'info',
        'max_size' => 10 * 1024 * 1024,
        'max_files' => 5,
    ],

    // Extraction settings
    'extraction' => [
        'max_file_size' => 5 * 1024 * 1024,
        'timeout' => 300,
    ],

    // Backup settings
    'backup' => [
        'max_age_days' => 30,
        'max_count' => 100,
    ],

    // Database (for app installer tracking)
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '${DB_NAME}',
        'user' => '${DB_USER}',
        'pass' => '${DB_PASS}',
    ],
];
EOF

    # Create socket directory (survives reboots via tmpfiles.d)
    mkdir -p /run/vps-admin
    chown root:www-data /run/vps-admin
    
    # Create tmpfiles.d entry for socket directory persistence
    cat > /etc/tmpfiles.d/vps-admin.conf << 'TMPEOF'
d /run/vps-admin 0750 root www-data -
TMPEOF

    # Generate agent token
    if [ -n "$AGENT_TOKEN" ]; then
        echo "$AGENT_TOKEN" > "$AGENT_PATH/var/agent.token"
    else
        openssl rand -hex 32 > "$AGENT_PATH/var/agent.token"
    fi
    
    chmod 640 "$AGENT_PATH/var/agent.token"
    chown root:www-data "$AGENT_PATH/var/agent.token"
    
    # Create systemd service for the Panel agent (vpsadmin-agent)
    # NOTE: This is separate from fleet-agent which handles Fleet Manager heartbeats
    cat > /etc/systemd/system/vpsadmin-agent.service << 'EOF'
[Unit]
Description=VPS Admin Panel Agent
After=network.target mariadb.service

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=/opt/vps-admin/agent
ExecStart=/usr/bin/php /opt/vps-admin/agent/agent.php --foreground
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

    # Set permissions
    chown -R root:root "$AGENT_PATH/agent"
    chmod -R 750 "$AGENT_PATH/agent"
    chown root:www-data "$AGENT_PATH/var"
    chmod 750 "$AGENT_PATH/var"
    chown root:www-data "$AGENT_PATH/backups"
    chmod 750 "$AGENT_PATH/backups"
    chown root:www-data "$AGENT_PATH/logs"
    chmod 750 "$AGENT_PATH/logs"

    # Symlink agent into web path so Panel health check can find handlers
    ln -sf "$AGENT_PATH/agent/Actions" "$INSTALL_PATH/agent/Actions" 2>/dev/null || true
    ln -sf "$AGENT_PATH/agent/Lib" "$INSTALL_PATH/agent/Lib" 2>/dev/null || true
    ln -sf "$AGENT_PATH/agent/agent.php" "$INSTALL_PATH/agent/agent.php" 2>/dev/null || true
    
    # Enable and start service
    systemctl daemon-reload
    systemctl enable vpsadmin-agent
    systemctl restart vpsadmin-agent
    
    log_info "VPS Admin agent service started"

    # ----------------------------------------------------------------
    # Provisioning pipeline: SecretVault key + worker + reconciler
    # ----------------------------------------------------------------
    # Sites V2 ("Provision site") and the Fleet base-domain registration
    # both enqueue jobs into site_jobs and depend on the worker daemon to
    # drive the saga end-to-end. Without it the queue fills but nothing
    # ever runs (sites stay in 'provisioning' forever). The reconciler
    # timer retries deferred SSL (pending_dns) once DNS propagates and
    # self-heals drift.
    log_info "Setting up provisioning worker + reconciler..."

    # Resolve a PHP CLI binary. Prefer the lsphp build the cron jobs use.
    WORKER_PHP="/usr/local/lsws/lsphp83/bin/php"
    [ -x "$WORKER_PHP" ] || WORKER_PHP="$(command -v php 2>/dev/null || echo /usr/bin/php)"

    # SecretVault master key: 32 RAW bytes, owner-only (0400). Per-host,
    # generated once. NEVER overwrite an existing key, or previously
    # vaulted secrets (e.g. site DB passwords) become undecryptable.
    mkdir -p /etc/flowone
    if [ ! -f /etc/flowone/master.key ]; then
        log_info "Generating /etc/flowone/master.key (per-host SecretVault key)"
        head -c 32 /dev/urandom > /etc/flowone/master.key
        chown root:root /etc/flowone/master.key
        chmod 0400 /etc/flowone/master.key
    else
        log_info "/etc/flowone/master.key already present - keeping it"
    fi

    # Worker runtime state dir (worker.paused pause-file + fs allowedRoots).
    mkdir -p /var/lib/flowone

    # Worker daemon: claims one job at a time and runs the saga.
    cat > /etc/systemd/system/vpsadmin-worker.service << EOF
[Unit]
Description=FlowOne VPS Admin worker daemon (drives site_jobs queue)
After=mariadb.service network-online.target
Wants=mariadb.service

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${AGENT_PATH}/agent
ExecStart=${WORKER_PHP} ${AGENT_PATH}/agent/worker-daemon.php
TimeoutStopSec=90
KillSignal=SIGTERM
KillMode=mixed
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vpsadmin-worker
NoNewPrivileges=no
ReadWritePaths=/usr/local/lsws/conf /home ${INSTALL_PATH}/storage /var/lib/flowone ${AGENT_PATH} /etc/opendkim
LimitNOFILE=4096
LimitNPROC=256

[Install]
WantedBy=multi-user.target
EOF

    # Reconciler: periodic drift probe + deferred-SSL retry (oneshot + timer).
    cat > /etc/systemd/system/vpsadmin-reconciler.service << EOF
[Unit]
Description=FlowOne VPS Admin site reconciler (one-shot)
After=mariadb.service network-online.target vpsadmin-worker.service
Wants=mariadb.service

[Service]
Type=oneshot
User=root
Group=root
ExecStart=${WORKER_PHP} ${AGENT_PATH}/agent/reconcile-sites.php --json
TimeoutStartSec=60
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vpsadmin-reconciler
EOF

    cat > /etc/systemd/system/vpsadmin-reconciler.timer << 'EOF'
[Unit]
Description=FlowOne VPS Admin site reconciler (periodic)

[Timer]
# Wall-clock every 5 minutes. OnCalendar (not monotonic OnBootSec/
# OnUnitActiveSec) so the timer reliably arms a NEXT run even when enabled
# long after boot, which is the norm for manual deploys onto live servers.
OnCalendar=*:0/5
RandomizedDelaySec=15s
Unit=vpsadmin-reconciler.service
Persistent=true

[Install]
WantedBy=timers.target
EOF

    # Dead-lease sweeper: requeues jobs whose worker died mid-saga
    # (JOB-side safety net; runs every minute, well under LEASE_TTL_S).
    cat > /etc/systemd/system/vpsadmin-lease-sweeper.service << EOF
[Unit]
Description=FlowOne VPS Admin dead-lease sweeper (one-shot)
After=mariadb.service network-online.target
Wants=mariadb.service

[Service]
Type=oneshot
User=root
Group=root
ExecStart=${WORKER_PHP} ${AGENT_PATH}/agent/dead-lease-sweep.php
TimeoutStartSec=30
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vpsadmin-lease-sweeper
EOF

    cat > /etc/systemd/system/vpsadmin-lease-sweeper.timer << 'EOF'
[Unit]
Description=FlowOne VPS Admin dead-lease sweeper (periodic)

[Timer]
OnBootSec=30s
OnUnitActiveSec=1min
RandomizedDelaySec=5s
AccuracySec=1s
Unit=vpsadmin-lease-sweeper.service
Persistent=true

[Install]
WantedBy=timers.target
EOF

    # Stuck-site recovery: parks sites wedged in an in-flight
    # actual_state (or orphaned failed-creates stuck at 'absent') in a
    # reviewable failed/degraded state (SITE-side safety net).
    cat > /etc/systemd/system/vpsadmin-site-state-recover.service << EOF
[Unit]
Description=FlowOne VPS Admin stuck-site recovery sweep (one-shot)
After=mariadb.service network-online.target
Wants=mariadb.service

[Service]
Type=oneshot
User=root
Group=root
ExecStart=${WORKER_PHP} ${AGENT_PATH}/agent/site-state-recover.php --json
TimeoutStartSec=60
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vpsadmin-site-state-recover
EOF

    cat > /etc/systemd/system/vpsadmin-site-state-recover.timer << 'EOF'
[Unit]
Description=FlowOne VPS Admin stuck-site recovery sweep (periodic)

[Timer]
OnBootSec=90s
OnUnitActiveSec=5min
RandomizedDelaySec=15s
AccuracySec=10s
Unit=vpsadmin-site-state-recover.service
Persistent=true

[Install]
WantedBy=timers.target
EOF

    # Migration delta-sync scheduler: dispatches due delta/sweep imapsync runs
    # for imap_migrations rows that have auto-sync enabled or a pending cutover
    # sweep. The dispatcher is flock'd, so a 5-minute timer is safe.
    cat > /etc/systemd/system/vpsadmin-migration-scheduler.service << EOF
[Unit]
Description=FlowOne IMAP migration delta-sync scheduler (one-shot)
After=mariadb.service network-online.target
Wants=mariadb.service

[Service]
Type=oneshot
User=root
Group=root
WorkingDirectory=${INSTALL_PATH}/api
ExecStart=${WORKER_PHP} ${INSTALL_PATH}/api/scripts/run-due-migrations.php
TimeoutStartSec=300
StandardOutput=journal
StandardError=journal
SyslogIdentifier=vpsadmin-migration-scheduler
EOF

    cat > /etc/systemd/system/vpsadmin-migration-scheduler.timer << 'EOF'
[Unit]
Description=FlowOne IMAP migration delta-sync scheduler (periodic)

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
RandomizedDelaySec=20s
AccuracySec=15s
Unit=vpsadmin-migration-scheduler.service
Persistent=true

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload
    systemctl enable vpsadmin-worker >/dev/null 2>&1 || true
    systemctl restart vpsadmin-worker || log_warn "Failed to start vpsadmin-worker"
    systemctl enable vpsadmin-reconciler.timer >/dev/null 2>&1 || true
    systemctl start vpsadmin-reconciler.timer 2>/dev/null || true
    systemctl enable vpsadmin-lease-sweeper.timer >/dev/null 2>&1 || true
    systemctl start vpsadmin-lease-sweeper.timer 2>/dev/null || true
    systemctl enable vpsadmin-site-state-recover.timer >/dev/null 2>&1 || true
    systemctl start vpsadmin-site-state-recover.timer 2>/dev/null || true
    systemctl enable vpsadmin-migration-scheduler.timer >/dev/null 2>&1 || true
    systemctl start vpsadmin-migration-scheduler.timer 2>/dev/null || true
    log_info "Provisioning worker + reconciler + sweepers + migration scheduler installed"
fi

# ============================================
# 7. Set permissions
# ============================================
log_info "Setting permissions..."

chown -R www-data:www-data "$INSTALL_PATH"
chmod -R 755 "$INSTALL_PATH"

# Set OLS config permissions for www-data (agent) access
log_info "Setting OLS config permissions..."
chown -R lsadm:www-data /usr/local/lsws/conf/ 2>/dev/null || log_warn "Could not set OLS conf ownership"
chmod -R 750 /usr/local/lsws/conf/ 2>/dev/null || true
chmod -R 770 /usr/local/lsws/conf/vhosts/ 2>/dev/null || true

# ============================================
# 8. Create OpenLiteSpeed vhost
# ============================================
if [ "${SKIP_VHOST:-0}" != "1" ]; then
    log_info "Creating OpenLiteSpeed vhost..."
    
    # Verify OLS is still running before creating vhost
    if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
        log_warn "OpenLiteSpeed not running, attempting to start..."
        systemctl start lshttpd || log_warn "Failed to start OLS"
    fi
    
    VHOST_DIR="$OLS_CONF/vhosts/$PANEL_DOMAIN"
    mkdir -p "$VHOST_DIR"
    
    # Document root is the install path (contains index.html and assets/)
    cat > "$VHOST_DIR/vhconf.conf" << EOF
docRoot                   ${INSTALL_PATH}
vhDomain                  ${PANEL_DOMAIN}
enableGzip                1
enableBr                  1

index {
  useServer               0
  indexFiles              index.html
}

context /api/ {
  type                    appserver
  location                ${INSTALL_PATH}/api/public/
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

context /phpmyadmin/ {
  type                    appserver
  location                ${INSTALL_PATH}/phpmyadmin/
  binPath                 lsphp83
  appType                 php
  addDefaultCharset       off
}

rewrite {
  enable                  1
  rules                   <<<END_RULES
RewriteEngine On
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
        sed -i "/^[[:space:]]*[vV]irtual[hH]ost[[:space:]]*${PANEL_DOMAIN}[[:space:]]*{/,/^}/d" "$HTTPD_CONF" 2>/dev/null || true

        log_info "Adding vhost to httpd_config.conf..."
        cat >> "$HTTPD_CONF" << EOF

virtualHost $PANEL_DOMAIN {
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
                if ! sed -n "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/,/}/p" "$HTTPD_CONF" | grep -q "map.*${PANEL_DOMAIN}"; then
                    sed -i "/^[[:space:]]*listener[[:space:]]*${LISTENER}[[:space:]]*{/a\\  map                     ${PANEL_DOMAIN} ${PANEL_DOMAIN}" "$HTTPD_CONF"
                    log_info "Added ${PANEL_DOMAIN} mapping to ${LISTENER} listener"
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
# 9. Final verification
# ============================================
log_info "Running final verification..."

INSTALL_STATUS="SUCCESS"
WARNINGS=""

# Verify files exist
if [ ! -f "$INSTALL_PATH/index.html" ]; then
    WARNINGS="${WARNINGS}  - index.html missing\n"
fi
if [ ! -d "$INSTALL_PATH/assets" ] || [ -z "$(ls -A $INSTALL_PATH/assets 2>/dev/null)" ]; then
    WARNINGS="${WARNINGS}  - assets directory empty or missing\n"
fi
if [ ! -d "$INSTALL_PATH/fonts/material-symbols-rounded" ]; then
    WARNINGS="${WARNINGS}  - fonts/material-symbols-rounded missing (icons will render as text)\n"
fi
if [ ! -f "$INSTALL_PATH/api/public/index.php" ]; then
    WARNINGS="${WARNINGS}  - API index.php missing\n"
fi

# Verify services — try one more OLS start if it's not running
if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
    log_info "OLS not running, final restart attempt..."
    killall -9 litespeed 2>/dev/null || true
    sleep 1
    systemctl start lshttpd 2>/dev/null || true
    sleep 2
    if ! systemctl is-active --quiet lshttpd 2>/dev/null; then
        WARNINGS="${WARNINGS}  - OpenLiteSpeed not running (check config and restart manually)\n"
        INSTALL_STATUS="PARTIAL"
    else
        log_info "OpenLiteSpeed started successfully on retry"
    fi
fi
# Native only: a local mariadb unit should be up. On Docker/TCP the DB is a
# container (no local unit), so skip this check and rely on the connection test.
if [ -z "$MYSQL_HOSTOPT" ] && ! systemctl is-active --quiet mariadb 2>/dev/null; then
    WARNINGS="${WARNINGS}  - MariaDB not running\n"
    INSTALL_STATUS="PARTIAL"
fi

# Verify database connection (use app user credentials, not root)
if [ "${SKIP_DB:-0}" != "1" ]; then
    if ! MYSQL_PWD="$DB_PASS" mysql $MYSQL_HOSTOPT -u "$DB_USER" "$DB_NAME" -e "SELECT 1" 2>/dev/null; then
        WARNINGS="${WARNINGS}  - Cannot connect to database as ${DB_USER} (host ${DB_HOST:-localhost})\n"
        INSTALL_STATUS="PARTIAL"
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
echo "  Panel URL:    https://${PANEL_DOMAIN}"
echo "  Install path: ${INSTALL_PATH}"
echo "  Agent path:   ${AGENT_PATH}"
echo ""
if [ -n "$ADMIN_EMAIL" ]; then
    echo "  Admin login:  ${ADMIN_EMAIL}"
fi
echo ""
echo "  Agent token:  $(cat $AGENT_PATH/var/agent.token 2>/dev/null || echo 'N/A')"
echo ""

if [ -n "$WARNINGS" ]; then
    echo -e "${YELLOW}Warnings:${NC}"
    echo -e "$WARNINGS"
    echo ""
fi

echo "Service Status:"
systemctl is-active lshttpd 2>/dev/null && echo "  OpenLiteSpeed: RUNNING" || echo "  OpenLiteSpeed: NOT RUNNING"
systemctl is-active mariadb 2>/dev/null && echo "  MariaDB: RUNNING" || echo "  MariaDB: NOT RUNNING"
if [ "${SKIP_AGENT:-0}" != "1" ]; then
    systemctl is-active vpsadmin-agent 2>/dev/null && echo "  VPS Admin Agent: RUNNING" || echo "  VPS Admin Agent: NOT RUNNING"
fi
echo ""

if [ "${SKIP_VHOST:-0}" = "1" ]; then
    echo "Next steps:"
    echo "  1. Add vhost to OLS httpd_config.conf"
    echo "  2. Reload OLS: /usr/local/lsws/bin/lswsctrl reload"
fi
echo "  3. Setup SSL: certbot --webroot -w ${INSTALL_PATH} -d ${PANEL_DOMAIN}"
echo ""

