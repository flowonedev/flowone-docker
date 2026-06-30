#!/bin/bash
#
# Fleet Manager - Installation Script
# Installs Fleet Manager on a fresh server
#
# Usage: ./install.sh [--domain DOMAIN] [--db-name NAME] [--db-user USER] [--db-pass PASS]
#

set -e

# Default configuration
INSTALL_DIR="/var/www/vps-fleet"
DOMAIN=""
DB_NAME="fleet_manager"
DB_USER="fleet_manager"
DB_PASS=""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --domain) DOMAIN="$2"; shift 2 ;;
        --db-name) DB_NAME="$2"; shift 2 ;;
        --db-user) DB_USER="$2"; shift 2 ;;
        --db-pass) DB_PASS="$2"; shift 2 ;;
        --install-dir) INSTALL_DIR="$2"; shift 2 ;;
        *) shift ;;
    esac
done

echo ""
echo "========================================="
echo "  Fleet Manager - Installer"
echo "========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Get script directory (where the package was extracted)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Check for VERSION file
if [ ! -f "$SCRIPT_DIR/VERSION" ]; then
    log_error "VERSION file not found. Are you running from the extracted package?"
    exit 1
fi

VERSION=$(cat "$SCRIPT_DIR/VERSION")
log_info "Installing Fleet Manager v$VERSION"
log_info "Install directory: $INSTALL_DIR"

# ============================================
# 1. Create installation directory
# ============================================
log_info "Creating installation directory..."
mkdir -p "$INSTALL_DIR"

# ============================================
# 2. Copy application files
# ============================================
log_info "Copying application files..."

# API
if [ -d "$SCRIPT_DIR/api" ]; then
    cp -r "$SCRIPT_DIR/api" "$INSTALL_DIR/"
    log_info "  -> Copied api/"
fi

# Agent
if [ -d "$SCRIPT_DIR/agent" ]; then
    cp -r "$SCRIPT_DIR/agent" "$INSTALL_DIR/"
    log_info "  -> Copied agent/"
fi

# Assets (frontend)
if [ -d "$SCRIPT_DIR/assets" ]; then
    cp -r "$SCRIPT_DIR/assets" "$INSTALL_DIR/"
    log_info "  -> Copied assets/"
fi

# Database
if [ -d "$SCRIPT_DIR/database" ]; then
    cp -r "$SCRIPT_DIR/database" "$INSTALL_DIR/"
    log_info "  -> Copied database/"
fi

# Templates
if [ -d "$SCRIPT_DIR/templates" ]; then
    cp -r "$SCRIPT_DIR/templates" "$INSTALL_DIR/"
    log_info "  -> Copied templates/"
fi

# Packages
if [ -d "$SCRIPT_DIR/packages" ]; then
    cp -r "$SCRIPT_DIR/packages" "$INSTALL_DIR/"
    log_info "  -> Copied packages/"
fi

# Error pages
if [ -d "$SCRIPT_DIR/error" ]; then
    cp -r "$SCRIPT_DIR/error" "$INSTALL_DIR/"
    log_info "  -> Copied error/"
fi

# Root files
[ -f "$SCRIPT_DIR/index.html" ] && cp "$SCRIPT_DIR/index.html" "$INSTALL_DIR/"
[ -f "$SCRIPT_DIR/favicon.svg" ] && cp "$SCRIPT_DIR/favicon.svg" "$INSTALL_DIR/"
[ -f "$SCRIPT_DIR/.htaccess" ] && cp "$SCRIPT_DIR/.htaccess" "$INSTALL_DIR/"
[ -f "$SCRIPT_DIR/VERSION" ] && cp "$SCRIPT_DIR/VERSION" "$INSTALL_DIR/"
[ -f "$SCRIPT_DIR/BUILD_DATE" ] && cp "$SCRIPT_DIR/BUILD_DATE" "$INSTALL_DIR/"

# ============================================
# 3. Create required directories
# ============================================
log_info "Creating required directories..."
mkdir -p "$INSTALL_DIR/var"
mkdir -p "$INSTALL_DIR/keys"
mkdir -p "$INSTALL_DIR/api/logs"

# ============================================
# 4. Install PHP dependencies
# ============================================
if [ -f "$INSTALL_DIR/api/composer.json" ]; then
    log_info "Installing PHP dependencies..."
    cd "$INSTALL_DIR/api"
    if command -v composer &> /dev/null; then
        composer install --no-dev --optimize-autoloader 2>/dev/null || log_warn "Composer install failed - manual installation may be required"
    else
        log_warn "Composer not found - please install dependencies manually"
    fi
fi

# ============================================
# 5. Create database configuration
# ============================================
if [ -n "$DB_PASS" ] && [ ! -f "$INSTALL_DIR/api/config.local.php" ]; then
    log_info "Creating database configuration..."
    cat > "$INSTALL_DIR/api/config.local.php" << EOF
<?php
return [
    'database' => [
        'host' => 'localhost',
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'password' => '$DB_PASS',
    ],
    'jwt' => [
        'secret' => '$(openssl rand -hex 32)',
    ],
];
EOF
    log_info "  -> Created config.local.php"
fi

# ============================================
# 6. Set permissions
# ============================================
log_info "Setting permissions..."
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod 700 "$INSTALL_DIR/keys"
chmod 700 "$INSTALL_DIR/var"
[ -f "$INSTALL_DIR/api/config.local.php" ] && chmod 600 "$INSTALL_DIR/api/config.local.php"

# ============================================
# 7. Run database migrations
# ============================================
if [ -d "$INSTALL_DIR/database/migrations" ] && [ -n "$DB_PASS" ]; then
    log_info "Running database migrations..."
    for migration in "$INSTALL_DIR/database/migrations"/*.sql; do
        if [ -f "$migration" ]; then
            mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>/dev/null || true
        fi
    done
    log_info "  -> Migrations completed"
fi

# ============================================
# Done
# ============================================
echo ""
echo "========================================="
echo -e "${GREEN}  Installation Complete!${NC}"
echo "========================================="
echo ""
echo "  Fleet Manager v$VERSION installed to: $INSTALL_DIR"
echo ""
echo "  Next steps:"
echo "    1. Configure your web server to serve $INSTALL_DIR"
echo "    2. Set up database if not done: mysql -u root -p"
echo "       CREATE DATABASE $DB_NAME;"
echo "       CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY 'your_password';"
echo "       GRANT ALL ON $DB_NAME.* TO '$DB_USER'@'localhost';"
echo "    3. Edit $INSTALL_DIR/api/config.local.php with database credentials"
echo "    4. Access the dashboard at https://$DOMAIN (or your configured domain)"
echo ""

