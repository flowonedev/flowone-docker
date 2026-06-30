#!/bin/bash

# Deployment script for Fleet Manager
# Copies from staging to production

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

log() { echo -e "[$(timestamp)] $1"; }
log_success() { echo -e "${GREEN}[$(timestamp)] $1${NC}"; }
log_error() { echo -e "${RED}[$(timestamp)] $1${NC}"; }
log_warning() { echo -e "${YELLOW}[$(timestamp)] $1${NC}"; }

log "Starting Fleet Manager deployment..."

# Paths
STAGING="/home/fleet.devcon1.hu/public_html"
PROD="/var/www/vps-fleet"

# Check staging exists
if [ ! -d "$STAGING" ]; then
    log_error "Staging directory not found: $STAGING"
    exit 1
fi

# Clean old dashboard assets
log "Cleaning old dashboard assets..."
if [ -d "$PROD/assets" ]; then
    find "$PROD/assets" -type f \( -name "*.css" -o -name "*.js" \) -delete 2>/dev/null || true
fi

# Copy dashboard (frontend dist)
log "Copying dashboard files..."
if [ -d "$STAGING/dist" ]; then
    cp -r "$STAGING/dist"/* "$PROD/"
    log_success "Dashboard files copied"
else
    log_warning "No dist folder found, skipping dashboard"
fi

# Copy API
log "Copying API files..."
if [ -d "$STAGING/api" ]; then
    cp -r "$STAGING/api/src" "$PROD/api/"
    cp -r "$STAGING/api/public" "$PROD/api/"
    cp "$STAGING/api/routes.php" "$PROD/api/"
    cp "$STAGING/api/config.php" "$PROD/api/"
    cp "$STAGING/api/composer.json" "$PROD/api/"
    # Copy CLI scripts (provision.php etc)
    if [ -d "$STAGING/api/cli" ]; then
        mkdir -p "$PROD/api/cli"
        cp -r "$STAGING/api/cli"/* "$PROD/api/cli/"
        log_success "API CLI scripts copied"
    fi
    # Copy server-side test scripts
    if [ -d "$STAGING/api/tests" ]; then
        mkdir -p "$PROD/api/tests"
        cp -r "$STAGING/api/tests"/* "$PROD/api/tests/"
        log_success "API test scripts copied"
    fi
    # Don't overwrite config.local.php if it exists
    if [ ! -f "$PROD/api/config.local.php" ] && [ -f "$STAGING/api/config.local.php" ]; then
        cp "$STAGING/api/config.local.php" "$PROD/api/"
    fi
    log_success "API files copied"
fi

# Copy packages
log "Copying packages..."
if [ -d "$STAGING/packages" ]; then
    mkdir -p "$PROD/packages"
    cp -r "$STAGING/packages"/* "$PROD/packages/"
    log_success "Packages copied"
fi

# Copy database files
log "Copying database files..."
if [ -d "$STAGING/database" ]; then
    mkdir -p "$PROD/database"
    cp -r "$STAGING/database"/* "$PROD/database/"
    log_success "Database files copied"
fi

# Copy installer scripts
log "Copying installer scripts..."
if [ -d "$STAGING/installer" ]; then
    mkdir -p "$PROD/installer"
    cp -r "$STAGING/installer"/* "$PROD/installer/"
    chmod +x "$PROD/installer"/*.sh 2>/dev/null || true
    log_success "Installer scripts copied"
fi

# Copy extractor
log "Copying extractor..."
if [ -d "$STAGING/extractor" ]; then
    mkdir -p "$PROD/extractor"
    cp -r "$STAGING/extractor"/* "$PROD/extractor/"
    chmod +x "$PROD/extractor"/*.sh 2>/dev/null || true
    log_success "Extractor copied"
fi

# Copy templates
log "Copying templates..."
if [ -d "$STAGING/templates" ]; then
    # Ensure template subdirectories exist
    mkdir -p "$PROD/templates"
    find "$STAGING/templates" -mindepth 1 -type d | while read dir; do
        reldir="${dir#$STAGING/templates/}"
        mkdir -p "$PROD/templates/$reldir"
    done
    cp -r "$STAGING/templates"/* "$PROD/templates/" 2>/dev/null || true
    # Remove any cached schema dumps under 500 bytes (force regeneration from static fallbacks)
    find "$PROD/templates/database" -name '*-schema.sql' -size -500c -delete 2>/dev/null || true
    log_success "Templates copied"
fi

# Copy agent
log "Copying agent files..."
if [ -d "$STAGING/agent" ]; then
    mkdir -p "$PROD/agent/Actions"
    mkdir -p "$PROD/agent/Lib"
    cp "$STAGING/agent/agent.php" "$PROD/agent/"
    cp "$STAGING/agent/heartbeat.php" "$PROD/agent/"
    cp "$STAGING/agent/config.php" "$PROD/agent/"
    cp "$STAGING/agent/install.sh" "$PROD/agent/"
    cp "$STAGING/agent/fleet-agent.service" "$PROD/agent/"
    cp -r "$STAGING/agent/Actions"/* "$PROD/agent/Actions/"
    cp -r "$STAGING/agent/Lib"/* "$PROD/agent/Lib/"
    # VERSION is optional but lets the deploy overlay report an accurate agent version
    [ -f "$STAGING/agent/VERSION" ] && cp "$STAGING/agent/VERSION" "$PROD/agent/"
    chmod +x "$PROD/agent/agent.php"
    chmod +x "$PROD/agent/install.sh"
    log_success "Agent files copied"
fi

# Ensure var directory exists for agent token
mkdir -p "$PROD/var"

# Fix ownership
log "Fixing file ownership..."
chown -R www-data:www-data "$PROD"
# Agent files need to be root-owned
if [ -d "$PROD/agent" ]; then
    chown -R root:root "$PROD/agent"
fi
chmod 600 "$PROD/api/config.local.php" 2>/dev/null || true
chmod 640 "$PROD/var/agent.token" 2>/dev/null || true

# Restart PHP
log "Restarting PHP-FPM..."
systemctl restart lsphp83 2>/dev/null || systemctl restart php8.3-fpm 2>/dev/null || log_warning "Could not restart PHP"

# Sync the LIVE agent install on this box (the fleet-agent service runs from
# /opt/fleet-agent, not from $PROD/agent). Code files only - never config.php,
# which holds this machine's panel URL + token.
if [ -d /opt/fleet-agent ] && [ -d "$STAGING/agent" ]; then
    log "Syncing live agent at /opt/fleet-agent..."
    cp "$STAGING/agent/agent.php" /opt/fleet-agent/
    cp "$STAGING/agent/heartbeat.php" /opt/fleet-agent/
    mkdir -p /opt/fleet-agent/Actions /opt/fleet-agent/Lib
    cp -r "$STAGING/agent/Actions"/* /opt/fleet-agent/Actions/
    cp -r "$STAGING/agent/Lib"/* /opt/fleet-agent/Lib/
    chown -R root:root /opt/fleet-agent
    log_success "Live agent synced"
fi

# Restart agent if it's running
if systemctl is-active --quiet fleet-agent; then
    log "Restarting fleet-agent..."
    systemctl restart fleet-agent
    log_success "Agent restarted"
fi

log_success "Fleet Manager deployment completed!"

