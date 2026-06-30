#!/bin/bash

# Deployment script for VPS Admin Panel
# Clears old files, copies new files, clears cache, and restarts services

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Timestamp function
timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

# Log function
log() {
    echo -e "[$(timestamp)] $1"
}

log_success() {
    echo -e "${GREEN}[$(timestamp)] ✓ $1${NC}"
}

log_error() {
    echo -e "${RED}[$(timestamp)] ✗ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}[$(timestamp)] ⚠ $1${NC}"
}

# Start deployment
log "Starting VPS Admin Panel deployment..."

# Source and destination paths
STAGING_DASHBOARD="/home/panel.devcon1.hu/public_html/dashboard/dist"
STAGING_API="/home/panel.devcon1.hu/public_html/api"
STAGING_AGENT="/home/panel.devcon1.hu/public_html/agent"
STAGING_TESTS="/home/panel.devcon1.hu/public_html/tests"
STAGING_DATABASE="/home/panel.devcon1.hu/public_html/database"
# shared/ ships under panel/ locally (panel/shared/) but deploys to its
# own sibling directory in production (/var/www/shared/) — that's where
# the composer autoloaders in both vps-email/backend and vps-admin/api
# look for it via "../../shared/src/Storage/".
STAGING_SHARED="/home/panel.devcon1.hu/public_html/shared"

PROD_DASHBOARD="/var/www/vps-admin"
PROD_API="/var/www/vps-admin/api"
PROD_AGENT="/var/www/vps-admin/agent"
PROD_TESTS="/var/www/vps-admin/tests"
PROD_DATABASE="/var/www/vps-admin/database"
PROD_SHARED="/var/www/shared"

# Check if staging directories exist
if [ ! -d "$STAGING_DASHBOARD" ]; then
    log_error "Staging dashboard directory not found: $STAGING_DASHBOARD"
    exit 1
fi

if [ ! -d "$STAGING_API" ]; then
    log_error "Staging API directory not found: $STAGING_API"
    exit 1
fi

if [ ! -d "$STAGING_AGENT" ]; then
    log_error "Staging agent directory not found: $STAGING_AGENT"
    exit 1
fi

if [ ! -d "$STAGING_TESTS" ]; then
    log_warning "Staging tests directory not found: $STAGING_TESTS (skipping tests)"
fi

if [ ! -d "$STAGING_DATABASE" ]; then
    log_warning "Staging database directory not found: $STAGING_DATABASE (skipping migrations)"
fi

# Create production directories if they don't exist
mkdir -p "$PROD_DASHBOARD"
mkdir -p "$PROD_API"
mkdir -p "$PROD_AGENT"
mkdir -p "$PROD_TESTS"
mkdir -p "$PROD_DATABASE"

log "Cleaning old CSS and JS files from dashboard..."

# Remove old CSS and JS files (but keep index.html and other assets)
if [ -d "$PROD_DASHBOARD/assets" ]; then
    find "$PROD_DASHBOARD/assets" -type f \( -name "*.css" -o -name "*.js" \) -delete
    log_success "Old CSS/JS files removed"
else
    log_warning "Assets directory not found, skipping cleanup"
fi

log "Copying dashboard files..."
cp -r "$STAGING_DASHBOARD"/* "$PROD_DASHBOARD/"
log_success "Dashboard files copied"

log "Copying API files..."
cp -r "$STAGING_API"/* "$PROD_API/"
log_success "API files copied"

log "Copying agent files..."
cp -r "$STAGING_AGENT"/* "$PROD_AGENT/"
log_success "Agent files copied"

if [ -d "$STAGING_TESTS" ] && [ "$(ls -A "$STAGING_TESTS" 2>/dev/null)" ]; then
    log "Copying test files..."
    cp -r "$STAGING_TESTS"/* "$PROD_TESTS/"
    chmod +x "$PROD_TESTS"/*.php 2>/dev/null || true
    chmod +x "$PROD_TESTS"/*.sh 2>/dev/null || true
    log_success "Test files copied"
else
    log_warning "No test files found in staging, skipping"
fi

# ---------------------------------------------------------------------------
# Database migrations (panel/database/ -> /var/www/vps-admin/database/)
# Migrations are SQL files applied manually with mysql(1). Copying them to
# /var/www/vps-admin/database/ keeps the run paths stable across deploys.
# Migrations are NEVER auto-applied here - the operator runs them explicitly.
# ---------------------------------------------------------------------------
if [ -d "$STAGING_DATABASE" ] && [ "$(ls -A "$STAGING_DATABASE" 2>/dev/null)" ]; then
    log "Copying database migration files..."
    cp -r "$STAGING_DATABASE"/* "$PROD_DATABASE/"
    log_success "Database migration files copied to $PROD_DATABASE"
    log_warning "Migrations are NOT auto-applied - run them manually with mysql(1)"
else
    log_warning "No database files found in staging, skipping"
fi

# ---------------------------------------------------------------------------
# FlowOne shared library (panel/shared/ -> /var/www/shared/)
# Soft-skip when shared/ wasn't uploaded so existing panel deploys keep
# working unchanged. When it IS uploaded, run its dedicated installer in
# non-interactive mode so we get the same ownership / autoloader-regen /
# daemon-restart steps every time.
# ---------------------------------------------------------------------------
if [ -d "$STAGING_SHARED" ] && [ -f "$STAGING_SHARED/copy-shared.sh" ]; then
    log "Deploying FlowOne shared library..."
    if STAGING_SHARED="$STAGING_SHARED" bash "$STAGING_SHARED/copy-shared.sh"; then
        log_success "Shared library deployed to $PROD_SHARED"
    else
        log_error "Shared library deploy reported errors — see output above"
    fi
else
    log_warning "Staging shared directory not found: $STAGING_SHARED (skipping shared library)"
fi

log "Fixing file ownership..."
chown -R www-data:www-data /var/www/vps-admin/
log_success "File ownership updated"

log "Clearing Redis cache..."
# Try to clear Redis cache if redis-cli is available
if command -v redis-cli &> /dev/null; then
    # Try to flush Redis cache (adjust database number if needed)
    if redis-cli -n 0 FLUSHDB &> /dev/null; then
        log_success "Redis cache cleared"
    else
        log_warning "Could not clear Redis cache (may not be configured)"
    fi
else
    log_warning "redis-cli not found, skipping cache clear"
fi

# Clear PHP OPcache if available
log "Clearing PHP OPcache..."
if command -v php &> /dev/null; then
    # Try to clear OPcache via PHP
    php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared'; }" 2>/dev/null || log_warning "OPcache not available or already cleared"
fi

log "Installing/refreshing systemd unit files..."
# ---------------------------------------------------------------------------
# Systemd units shipped with the agent live under
# /var/www/vps-admin/agent/systemd/ after the staging copy above. We
# install them into /etc/systemd/system/ on every deploy so the
# vpsadmin-worker service + the lease-sweeper / reconciler timers
# are always in sync with the code that ships next to them.
#
# Units installed:
#   vpsadmin-worker.service        (Type=simple, drives site_jobs queue)
#   vpsadmin-lease-sweeper.{service,timer}  (sweeps dead leases every minute)
#   vpsadmin-reconciler.{service,timer}     (drift + pending_dns SSL retry every 5min)
# ---------------------------------------------------------------------------
AGENT_SYSTEMD_SRC="$PROD_AGENT/systemd"
UNITS_CHANGED=0
if [ -d "$AGENT_SYSTEMD_SRC" ]; then
    for unit_path in "$AGENT_SYSTEMD_SRC"/*.{service,timer}; do
        [ -e "$unit_path" ] || continue
        unit_name=$(basename "$unit_path")
        target="/etc/systemd/system/$unit_name"
        if ! cmp -s "$unit_path" "$target" 2>/dev/null; then
            cp "$unit_path" "$target"
            chmod 644 "$target"
            UNITS_CHANGED=1
            log_success "Installed/updated $unit_name"
        fi
    done

    if [ "$UNITS_CHANGED" -eq 1 ]; then
        systemctl daemon-reload
        log_success "systemd daemon-reload completed"
    else
        log "No unit changes — skipping daemon-reload"
    fi
else
    log_warning "Agent systemd dir not found at $AGENT_SYSTEMD_SRC — skipping unit install"
fi

log "Restarting services..."

# Restart agent service
log "Restarting vpsadmin-agent service..."
if systemctl is-active --quiet vpsadmin-agent; then
    systemctl restart vpsadmin-agent
    log_success "vpsadmin-agent restarted"
else
    log_warning "vpsadmin-agent service not running, attempting to start..."
    systemctl start vpsadmin-agent || log_error "Failed to start vpsadmin-agent"
fi

# Worker daemon: enable + restart so deployed step code is loaded.
log "Restarting vpsadmin-worker daemon..."
systemctl enable vpsadmin-worker.service >/dev/null 2>&1 || log_warning "Could not enable vpsadmin-worker (may not yet exist)"
if systemctl list-unit-files | grep -q '^vpsadmin-worker\.service'; then
    if systemctl is-active --quiet vpsadmin-worker; then
        systemctl restart vpsadmin-worker
        log_success "vpsadmin-worker restarted"
    else
        systemctl start vpsadmin-worker && log_success "vpsadmin-worker started" \
            || log_error "Failed to start vpsadmin-worker (see: journalctl -u vpsadmin-worker -n 50)"
    fi
fi

# Timers: enable + (re)start so they fire on the configured schedule.
for timer in vpsadmin-lease-sweeper.timer vpsadmin-reconciler.timer; do
    if systemctl list-unit-files | grep -q "^${timer}"; then
        systemctl enable "$timer" >/dev/null 2>&1 || true
        systemctl restart "$timer" 2>/dev/null \
            && log_success "${timer} (re)started" \
            || log_warning "Could not start ${timer}"
    else
        log_warning "${timer} not installed yet"
    fi
done

# Restart PHP-FPM if available (for API)
log "Restarting PHP-FPM..."
if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
    systemctl restart php8.3-fpm
    log_success "PHP-FPM restarted"
elif systemctl is-active --quiet php-fpm 2>/dev/null; then
    systemctl restart php-fpm
    log_success "PHP-FPM restarted"
else
    log_warning "PHP-FPM service not found or not running"
fi

# Restart OpenLiteSpeed if available
log "Restarting OpenLiteSpeed..."
if systemctl is-active --quiet lsws 2>/dev/null; then
    systemctl restart lsws
    log_success "OpenLiteSpeed restarted"
elif [ -f "/usr/local/lsws/bin/lswsctrl" ]; then
    /usr/local/lsws/bin/lswsctrl restart
    log_success "OpenLiteSpeed restarted"
else
    log_warning "OpenLiteSpeed not found or not running"
fi

log_success "Deployment completed successfully!"
log "Deployment finished at $(timestamp)"

