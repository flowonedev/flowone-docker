#!/bin/bash
#
# Fleet Manager - Build Package Script
# Creates a distributable tar.gz package from the Fleet Manager source
#
# Usage: ./build.sh [version] [--source=/path/to/fleet] [--output=/path/to/output]
# Example: ./build.sh 1.0.0
# Example: ./build.sh --version=1.0.0 --source=/var/www/vps-fleet
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="$SCRIPT_DIR"
BUILD_DIR="/tmp/fleet-build-$$"

# Parse arguments
VERSION="1.0.0"
FLEET_SOURCE="/var/www/vps-fleet"

for arg in "$@"; do
    case $arg in
        --source=*) FLEET_SOURCE="${arg#*=}" ;;
        --output=*) OUTPUT_DIR="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        *) VERSION="$arg" ;;
    esac
done

PACKAGE_NAME="fleet-v${VERSION}.tar.gz"

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
echo "  Fleet Manager - Package Builder"
echo "  Version: $VERSION"
echo "========================================="
echo ""

# Verify source exists
if [ ! -d "$FLEET_SOURCE" ]; then
    log_error "Fleet Manager source not found at: $FLEET_SOURCE"
    exit 1
fi

log_info "Source: $FLEET_SOURCE"
log_info "Output: $OUTPUT_DIR/$PACKAGE_NAME"

# Clean up any previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/fleet"

# ============================================
# 1. API (PHP Backend)
# ============================================
log_info "Copying API..."
mkdir -p "$BUILD_DIR/fleet/api"
cp -r "$FLEET_SOURCE/api/src" "$BUILD_DIR/fleet/api/"
[ -d "$FLEET_SOURCE/api/public" ] && cp -r "$FLEET_SOURCE/api/public" "$BUILD_DIR/fleet/api/"
[ -d "$FLEET_SOURCE/api/cli" ] && cp -r "$FLEET_SOURCE/api/cli" "$BUILD_DIR/fleet/api/"
cp "$FLEET_SOURCE/api/composer.json" "$BUILD_DIR/fleet/api/" 2>/dev/null || true
cp "$FLEET_SOURCE/api/config.php" "$BUILD_DIR/fleet/api/"
[ -f "$FLEET_SOURCE/api/config.local.example.php" ] && cp "$FLEET_SOURCE/api/config.local.example.php" "$BUILD_DIR/fleet/api/"
cp "$FLEET_SOURCE/api/routes.php" "$BUILD_DIR/fleet/api/"
[ -f "$FLEET_SOURCE/api/.htaccess" ] && cp "$FLEET_SOURCE/api/.htaccess" "$BUILD_DIR/fleet/api/"
mkdir -p "$BUILD_DIR/fleet/api/logs"

# ============================================
# 2. Agent (Fleet Agent for managed servers)
# ============================================
if [ -d "$FLEET_SOURCE/agent" ]; then
    log_info "Copying Agent..."
    cp -r "$FLEET_SOURCE/agent" "$BUILD_DIR/fleet/"
    log_info "  -> Copied agent/"
fi

# ============================================
# 3. Assets (Built frontend dashboard)
# ============================================
if [ -d "$FLEET_SOURCE/assets" ]; then
    log_info "Copying Assets (built dashboard)..."
    cp -r "$FLEET_SOURCE/assets" "$BUILD_DIR/fleet/"
    log_info "  -> Copied assets/"
fi

# Root-level frontend files
[ -f "$FLEET_SOURCE/index.html" ] && cp "$FLEET_SOURCE/index.html" "$BUILD_DIR/fleet/"
[ -f "$FLEET_SOURCE/favicon.svg" ] && cp "$FLEET_SOURCE/favicon.svg" "$BUILD_DIR/fleet/"
[ -f "$FLEET_SOURCE/favicon.ico" ] && cp "$FLEET_SOURCE/favicon.ico" "$BUILD_DIR/fleet/"
[ -f "$FLEET_SOURCE/.htaccess" ] && cp "$FLEET_SOURCE/.htaccess" "$BUILD_DIR/fleet/"

# ============================================
# 4. Database (Migrations and schema)
# ============================================
if [ -d "$FLEET_SOURCE/database" ]; then
    log_info "Copying Database files..."
    cp -r "$FLEET_SOURCE/database" "$BUILD_DIR/fleet/"
    log_info "  -> Copied database/"
fi

# ============================================
# 5. Templates (Blueprint templates)
# ============================================
if [ -d "$FLEET_SOURCE/templates" ]; then
    log_info "Copying Templates..."
    cp -r "$FLEET_SOURCE/templates" "$BUILD_DIR/fleet/"
    log_info "  -> Copied templates/"
fi

# ============================================
# 6. Packages (Panel, Email, Agent installers)
# ============================================
if [ -d "$FLEET_SOURCE/packages" ]; then
    log_info "Copying Packages..."
    mkdir -p "$BUILD_DIR/fleet/packages"
    # Copy build scripts and installers (not the built .tar.gz files)
    for pkg in panel email agent fleet; do
        if [ -d "$FLEET_SOURCE/packages/$pkg" ]; then
            mkdir -p "$BUILD_DIR/fleet/packages/$pkg"
            cp "$FLEET_SOURCE/packages/$pkg/"*.sh "$BUILD_DIR/fleet/packages/$pkg/" 2>/dev/null || true
        fi
    done
    [ -f "$FLEET_SOURCE/packages/README.md" ] && cp "$FLEET_SOURCE/packages/README.md" "$BUILD_DIR/fleet/packages/"
    log_info "  -> Copied packages/ (build scripts only)"
fi

# ============================================
# 7. Error pages
# ============================================
if [ -d "$FLEET_SOURCE/error" ]; then
    log_info "Copying Error pages..."
    cp -r "$FLEET_SOURCE/error" "$BUILD_DIR/fleet/"
    log_info "  -> Copied error/"
fi

# ============================================
# 8. Storage directories (create empty)
# ============================================
log_info "Creating storage directories..."
mkdir -p "$BUILD_DIR/fleet/var"
mkdir -p "$BUILD_DIR/fleet/keys"

# ============================================
# 9. Installer and metadata
# ============================================
log_info "Including installer..."
cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/fleet/"

# Create version file
echo "$VERSION" > "$BUILD_DIR/fleet/VERSION"
echo "$(date -Iseconds)" > "$BUILD_DIR/fleet/BUILD_DATE"

# ============================================
# Create the tarball
# ============================================
log_info "Creating package..."
cd "$BUILD_DIR"
tar -czf "$PACKAGE_NAME" fleet/

# Move to output directory
mv "$PACKAGE_NAME" "$OUTPUT_DIR/"

# Create/update latest symlink
cd "$OUTPUT_DIR"
rm -f fleet-latest.tar.gz
ln -s "$PACKAGE_NAME" fleet-latest.tar.gz

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
echo "  Symlink: $OUTPUT_DIR/fleet-latest.tar.gz"
echo ""
echo "  Contents:"
echo "    - agent/      (fleet agent)"
echo "    - api/        (PHP backend)"
echo "    - assets/     (built dashboard)"
echo "    - database/   (migrations)"
echo "    - templates/  (blueprint templates)"
echo "    - packages/   (build scripts)"
echo "    - error/      (error pages)"
echo ""

