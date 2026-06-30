#!/bin/bash
#
# VPS Admin Panel - Build Package Script
# Creates a distributable tar.gz package from the VPS Admin source
#
# Usage: ./build.sh [version] [--source=/path/to/panel] [--output=/path/to/output]
# Example: ./build.sh 1.0.0
# Example: ./build.sh --version=1.0.0 --source=/var/www/vps-admin
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="$SCRIPT_DIR"
BUILD_DIR="/tmp/panel-build-$$"

# Parse arguments
VERSION="1.0.0"
PANEL_SOURCE="/var/www/vps-admin"

for arg in "$@"; do
    case $arg in
        --source=*) PANEL_SOURCE="${arg#*=}" ;;
        --output=*) OUTPUT_DIR="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        *) VERSION="$arg" ;;
    esac
done

PACKAGE_NAME="panel-v${VERSION}.tar.gz"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo ""
echo "========================================="
echo "  VPS Admin Panel - Package Builder"
echo "  Version: $VERSION"
echo "========================================="
echo ""

# Verify source exists
if [ ! -d "$PANEL_SOURCE" ]; then
    log_error "Panel source not found at: $PANEL_SOURCE"
    exit 1
fi

log_info "Source: $PANEL_SOURCE"
log_info "Output: $OUTPUT_DIR/$PACKAGE_NAME"

# Clean up any previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/panel"

# ============================================
# 1. API (PHP Backend)
# ============================================
log_info "Copying API..."
mkdir -p "$BUILD_DIR/panel/api"
cp -r "$PANEL_SOURCE/api/src" "$BUILD_DIR/panel/api/"
[ -d "$PANEL_SOURCE/api/public" ] && cp -r "$PANEL_SOURCE/api/public" "$BUILD_DIR/panel/api/"
cp "$PANEL_SOURCE/api/composer.json" "$BUILD_DIR/panel/api/"
cp "$PANEL_SOURCE/api/config.php" "$BUILD_DIR/panel/api/"
[ -f "$PANEL_SOURCE/api/config.local.example.php" ] && cp "$PANEL_SOURCE/api/config.local.example.php" "$BUILD_DIR/panel/api/"
cp "$PANEL_SOURCE/api/routes.php" "$BUILD_DIR/panel/api/"
[ -f "$PANEL_SOURCE/api/schema.sql" ] && cp "$PANEL_SOURCE/api/schema.sql" "$BUILD_DIR/panel/api/"
[ -f "$PANEL_SOURCE/api/.htaccess" ] && cp "$PANEL_SOURCE/api/.htaccess" "$BUILD_DIR/panel/api/"
mkdir -p "$BUILD_DIR/panel/api/logs"

# ============================================
# 2. Agent (Server monitoring agent)
# ============================================
if [ -d "$PANEL_SOURCE/agent" ]; then
    log_info "Copying Agent..."
    cp -r "$PANEL_SOURCE/agent" "$BUILD_DIR/panel/"
    # Remove any install scripts (we have our own)
    rm -f "$BUILD_DIR/panel/agent/install.sh"
    log_info "  -> Copied agent/"
fi

# ============================================
# 3. Assets (Built frontend)
# ============================================
if [ -d "$PANEL_SOURCE/assets" ]; then
    log_info "Copying Assets (built frontend)..."
    cp -r "$PANEL_SOURCE/assets" "$BUILD_DIR/panel/"
    log_info "  -> Copied assets/"
fi

# Root-level frontend files
[ -f "$PANEL_SOURCE/index.html" ] && cp "$PANEL_SOURCE/index.html" "$BUILD_DIR/panel/"
[ -f "$PANEL_SOURCE/favicon.svg" ] && cp "$PANEL_SOURCE/favicon.svg" "$BUILD_DIR/panel/"
[ -f "$PANEL_SOURCE/favicon.ico" ] && cp "$PANEL_SOURCE/favicon.ico" "$BUILD_DIR/panel/"
[ -f "$PANEL_SOURCE/.htaccess" ] && cp "$PANEL_SOURCE/.htaccess" "$BUILD_DIR/panel/"

# ============================================
# 3.5 Self-hosted fonts (Material Symbols, Outfit, JetBrains Mono)
# ============================================
# index.html loads <link href="/fonts/core.css">. Without these woff2 files the
# UI renders icon ligatures as raw text ("settings", "language", ...). Use -L to
# dereference symlinks so the real font files are captured, not a dangling link.
if [ -d "$PANEL_SOURCE/fonts" ]; then
    log_info "Copying self-hosted fonts..."
    cp -rL "$PANEL_SOURCE/fonts" "$BUILD_DIR/panel/"
    log_info "  -> Copied fonts/"
else
    log_warn "No fonts/ at $PANEL_SOURCE/fonts — panel icons will render as TEXT."
    log_warn "  Deploy fonts to the panel docroot, or pass the correct --source=."
fi

# Local JS libs (e.g. tailwind.min.js) served from /js/, if present
if [ -d "$PANEL_SOURCE/js" ]; then
    cp -rL "$PANEL_SOURCE/js" "$BUILD_DIR/panel/"
    log_info "  -> Copied js/"
fi

# ============================================
# 4. Config (Server configuration templates)
# ============================================
if [ -d "$PANEL_SOURCE/config" ]; then
    log_info "Copying Config..."
    cp -r "$PANEL_SOURCE/config" "$BUILD_DIR/panel/"
    log_info "  -> Copied config/"
fi

# ============================================
# 5. Database (Migrations and schema)
# ============================================
if [ -d "$PANEL_SOURCE/database" ]; then
    log_info "Copying Database files..."
    mkdir -p "$BUILD_DIR/panel/database"
    cp "$PANEL_SOURCE/database/"*.sql "$BUILD_DIR/panel/database/" 2>/dev/null || true
    cp "$PANEL_SOURCE/database/"*.php "$BUILD_DIR/panel/database/" 2>/dev/null || true
    log_info "  -> Copied database/"
fi

# ============================================
# 6. phpMyAdmin
# ============================================
if [ -d "$PANEL_SOURCE/phpmyadmin" ]; then
    log_info "Copying phpMyAdmin..."
    cp -r "$PANEL_SOURCE/phpmyadmin" "$BUILD_DIR/panel/"
    log_info "  -> Copied phpmyadmin/"
fi

# ============================================
# 7. Templates (Server config templates)
# ============================================
if [ -d "$PANEL_SOURCE/templates" ]; then
    log_info "Copying Templates..."
    cp -r "$PANEL_SOURCE/templates" "$BUILD_DIR/panel/"
    log_info "  -> Copied templates/"
fi

# ============================================
# 8. Var (Variable data directory)
# ============================================
if [ -d "$PANEL_SOURCE/var" ]; then
    log_info "Copying Var directory..."
    cp -r "$PANEL_SOURCE/var" "$BUILD_DIR/panel/"
    log_info "  -> Copied var/"
fi

# ============================================
# 9. Storage & Backups (create empty structure)
# ============================================
log_info "Creating storage directories..."
mkdir -p "$BUILD_DIR/panel/storage"
mkdir -p "$BUILD_DIR/panel/backups"
mkdir -p "$BUILD_DIR/panel/logs"

# ============================================
# 10. Installer and metadata
# ============================================
log_info "Including installer..."
cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/panel/"

# Create version file
echo "$VERSION" > "$BUILD_DIR/panel/VERSION"
echo "$(date -Iseconds)" > "$BUILD_DIR/panel/BUILD_DATE"

# ============================================
# Create the tarball
# ============================================
log_info "Creating package..."
cd "$BUILD_DIR"
tar -czf "$PACKAGE_NAME" panel/

# Move to output directory
mv "$PACKAGE_NAME" "$OUTPUT_DIR/"

# Create/update latest symlink
cd "$OUTPUT_DIR"
rm -f panel-latest.tar.gz
ln -s "$PACKAGE_NAME" panel-latest.tar.gz

# Cleanup
rm -rf "$BUILD_DIR"

# Show result
PACKAGE_SIZE=$(du -h "$OUTPUT_DIR/$PACKAGE_NAME" | cut -f1)

echo ""
echo "========================================="
echo -e "${GREEN}  Package created successfully!${NC}"
echo "========================================="
echo ""
echo "  Package: $OUTPUT_DIR/$PACKAGE_NAME"
echo "  Size:    $PACKAGE_SIZE"
echo "  Symlink: $OUTPUT_DIR/panel-latest.tar.gz"
echo ""
echo "  Contents:"
echo "    - agent/      (server monitoring)"
echo "    - api/        (PHP backend)"
echo "    - assets/     (built frontend)"
echo "    - config/     (server configs)"
echo "    - database/   (migrations)"
echo "    - phpmyadmin/ (DB management)"
echo "    - templates/  (config templates)"
echo "    - var/        (variable data)"
echo "    - storage/    (empty structure)"
echo "    - backups/    (empty structure)"
echo ""
