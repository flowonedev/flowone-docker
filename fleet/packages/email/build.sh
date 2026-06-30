#!/bin/bash
#
# MailFlow Email App - Build Package Script
# Creates a distributable tar.gz package from the Email App source
#
# Usage: ./build.sh [version] [--source=/path/to/email/app] [--output=/path/to/output]
# Example: ./build.sh 1.0.0
# Example: ./build.sh --version=1.0.0 --source=/var/www/vps-email
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="$SCRIPT_DIR"
BUILD_DIR="/tmp/email-build-$$"

# Parse arguments
VERSION="1.0.0"
EMAIL_SOURCE="/var/www/vps-email"

for arg in "$@"; do
    case $arg in
        --source=*) EMAIL_SOURCE="${arg#*=}" ;;
        --output=*) OUTPUT_DIR="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        *) VERSION="$arg" ;;
    esac
done

PACKAGE_NAME="email-v${VERSION}.tar.gz"

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
echo "  MailFlow Email App - Package Builder"
echo "  Version: $VERSION"
echo "========================================="
echo ""

# Verify source exists
if [ ! -d "$EMAIL_SOURCE" ]; then
    log_error "Email App source not found at: $EMAIL_SOURCE"
    exit 1
fi

log_info "Source: $EMAIL_SOURCE"
log_info "Output: $OUTPUT_DIR/$PACKAGE_NAME"

# Clean up any previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/email"

# ============================================
# 1. Backend (PHP API)
# ============================================
log_info "Copying Backend..."
mkdir -p "$BUILD_DIR/email/backend"
cp -r "$EMAIL_SOURCE/backend/src" "$BUILD_DIR/email/backend/"
cp -r "$EMAIL_SOURCE/backend/public" "$BUILD_DIR/email/backend/"
cp "$EMAIL_SOURCE/backend/composer.json" "$BUILD_DIR/email/backend/"
cp "$EMAIL_SOURCE/backend/composer.lock" "$BUILD_DIR/email/backend/" 2>/dev/null || true
cp "$EMAIL_SOURCE/backend/routes.php" "$BUILD_DIR/email/backend/"
[ -f "$EMAIL_SOURCE/backend/.htaccess" ] && cp "$EMAIL_SOURCE/backend/.htaccess" "$BUILD_DIR/email/backend/"

# Copy migrations
if [ -d "$EMAIL_SOURCE/backend/migrations" ]; then
    cp -r "$EMAIL_SOURCE/backend/migrations" "$BUILD_DIR/email/backend/"
fi

# Copy cron scripts
if [ -d "$EMAIL_SOURCE/backend/cron" ]; then
    cp -r "$EMAIL_SOURCE/backend/cron" "$BUILD_DIR/email/backend/"
fi

# Copy helper scripts + top-level cron entrypoints referenced by /etc/cron.d/mailflow-email
# (security-scan.sh runs daily; aggregate-stats.php is the hourly stats roll-up)
if [ -d "$EMAIL_SOURCE/backend/scripts" ]; then
    cp -r "$EMAIL_SOURCE/backend/scripts" "$BUILD_DIR/email/backend/"
fi

# Copy server-side test suites (office-editor-test.php, drive-system-test.php, ...)
# so deployed servers can run post-install smoke checks and diagnostics.
if [ -d "$EMAIL_SOURCE/backend/tests" ]; then
    cp -r "$EMAIL_SOURCE/backend/tests" "$BUILD_DIR/email/backend/"
    log_info "  -> Copied backend/tests/"
else
    log_warn "No backend/tests/ at source — post-install smoke checks will be skipped."
fi
[ -f "$EMAIL_SOURCE/backend/aggregate-stats.php" ] && cp "$EMAIL_SOURCE/backend/aggregate-stats.php" "$BUILD_DIR/email/backend/"

# Create empty storage structure (don't copy user data)
mkdir -p "$BUILD_DIR/email/backend/storage"/{cache,config,drive}
mkdir -p "$BUILD_DIR/email/backend/logs"

# ============================================
# 2. Frontend (Built assets)
# ============================================
log_info "Copying Frontend assets..."
if [ -d "$EMAIL_SOURCE/assets" ]; then
    cp -r "$EMAIL_SOURCE/assets" "$BUILD_DIR/email/"
    log_info "  -> Copied assets/"
fi

# Copy root-level frontend files
[ -f "$EMAIL_SOURCE/index.html" ] && cp "$EMAIL_SOURCE/index.html" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/favicon.svg" ] && cp "$EMAIL_SOURCE/favicon.svg" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/favicon.ico" ] && cp "$EMAIL_SOURCE/favicon.ico" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/apple-touch-icon.png" ] && cp "$EMAIL_SOURCE/apple-touch-icon.png" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/manifest.webmanifest" ] && cp "$EMAIL_SOURCE/manifest.webmanifest" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/sw.js" ] && cp "$EMAIL_SOURCE/sw.js" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/registerSW.js" ] && cp "$EMAIL_SOURCE/registerSW.js" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/workbox-"*.js ] && cp "$EMAIL_SOURCE/workbox-"*.js "$BUILD_DIR/email/" 2>/dev/null || true
[ -f "$EMAIL_SOURCE/pwa-192x192.png" ] && cp "$EMAIL_SOURCE/pwa-192x192.png" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/pwa-512x512.png" ] && cp "$EMAIL_SOURCE/pwa-512x512.png" "$BUILD_DIR/email/"
[ -f "$EMAIL_SOURCE/.htaccess" ] && cp "$EMAIL_SOURCE/.htaccess" "$BUILD_DIR/email/"

# Self-hosted fonts (Material Symbols, Outfit, JetBrains Mono). The SPA loads
# /fonts/core.css; without these woff2 files the UI renders icon ligatures as
# raw text. Use -L to dereference symlinks so real files are captured.
if [ -d "$EMAIL_SOURCE/fonts" ]; then
    cp -rL "$EMAIL_SOURCE/fonts" "$BUILD_DIR/email/"
    log_info "  -> Copied fonts/"
else
    log_warn "No fonts/ at $EMAIL_SOURCE/fonts — email icons will render as TEXT."
fi

# Local JS libs (tailwind.min.js etc.) served from /js/
if [ -d "$EMAIL_SOURCE/js" ]; then
    cp -rL "$EMAIL_SOURCE/js" "$BUILD_DIR/email/"
    log_info "  -> Copied js/"
fi

# ============================================
# 3. Collab System (real-time collaboration)
# ============================================
if [ -d "$EMAIL_SOURCE/collab" ]; then
    log_info "Copying Collab System..."
    cp -r "$EMAIL_SOURCE/collab" "$BUILD_DIR/email/"
    log_info "  -> Copied collab/ (backend + shared)"
fi

# ============================================
# 3b. Office stack (OnlyOffice Document Server)
# ============================================
# Dockerfile + installers + presence plugin + branding. The installer
# builds the whitelabeled image and writes backend/storage/office-config.json.
# The branding/ SVGs double as web-root assets (/office/branding/*.svg is
# referenced by the editor config for the header logo).
if [ -d "$EMAIL_SOURCE/office" ]; then
    log_info "Copying Office stack..."
    cp -r "$EMAIL_SOURCE/office" "$BUILD_DIR/email/"
    log_info "  -> Copied office/ (Dockerfile, installers, plugins, branding)"
else
    log_warn "No office/ at $EMAIL_SOURCE/office — OnlyOffice will not be installable from this package."
fi

# ============================================
# 4. Data directory (templates, etc)
# ============================================
if [ -d "$EMAIL_SOURCE/data" ]; then
    log_info "Copying Data directory..."
    cp -r "$EMAIL_SOURCE/data" "$BUILD_DIR/email/"
    log_info "  -> Copied data/"
fi

# ============================================
# 5. Frontend source (for reference/rebuilding)
# ============================================
if [ -d "$EMAIL_SOURCE/frontend" ]; then
    log_info "Copying Frontend source..."
    mkdir -p "$BUILD_DIR/email/frontend"
    # Copy dist if exists
    if [ -d "$EMAIL_SOURCE/frontend/dist" ]; then
        cp -r "$EMAIL_SOURCE/frontend/dist" "$BUILD_DIR/email/frontend/"
    fi
    # Copy package.json for reference
    [ -f "$EMAIL_SOURCE/frontend/package.json" ] && cp "$EMAIL_SOURCE/frontend/package.json" "$BUILD_DIR/email/frontend/"
    log_info "  -> Copied frontend/"
fi

# ============================================
# 6. Mailsync (email synchronization)
# ============================================
if [ -d "$EMAIL_SOURCE/mailsync" ]; then
    log_info "Copying Mailsync..."
    cp -r "$EMAIL_SOURCE/mailsync" "$BUILD_DIR/email/"
    log_info "  -> Copied mailsync/"
fi

# ============================================
# 7. Storage (create empty structure)
# ============================================
log_info "Creating storage directories..."
mkdir -p "$BUILD_DIR/email/storage"
mkdir -p "$BUILD_DIR/email/logs"

# ============================================
# 8. Installer and metadata
# ============================================
log_info "Including installer..."
cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/email/"

# Create version file
echo "$VERSION" > "$BUILD_DIR/email/VERSION"
echo "$(date -Iseconds)" > "$BUILD_DIR/email/BUILD_DATE"

# ============================================
# Create the tarball
# ============================================
log_info "Creating package..."
cd "$BUILD_DIR"
tar -czf "$PACKAGE_NAME" email/

# Move to output directory
mv "$PACKAGE_NAME" "$OUTPUT_DIR/"

# Create/update latest symlink
cd "$OUTPUT_DIR"
rm -f email-latest.tar.gz
ln -s "$PACKAGE_NAME" email-latest.tar.gz

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
echo "  Symlink: $OUTPUT_DIR/email-latest.tar.gz"
echo ""
echo "  Contents:"
echo "    - assets/     (built frontend)"
echo "    - backend/    (PHP API)"
echo "    - collab/     (collaboration system)"
echo "    - office/     (OnlyOffice Document Server stack)"
echo "    - data/       (templates, resources)"
echo "    - frontend/   (source/dist)"
echo "    - mailsync/   (email sync)"
echo "    - storage/    (empty structure)"
echo ""
