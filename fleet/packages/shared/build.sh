#!/bin/bash
#
# FlowOne Shared Library (flowone/storage) - Build Package Script
# Creates a distributable tar.gz from a /var/www/shared source tree.
#
# Usage: ./build.sh [version] [--source=/var/www/shared] [--output=/path/to/output]
# Example: ./build.sh 1.0.0
# Example: ./build.sh --version=1.0.0 --source=/var/www/shared
#
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_DIR="$SCRIPT_DIR"
BUILD_DIR="/tmp/shared-build-$$"

VERSION="1.0.0"
SHARED_SOURCE="/var/www/shared"

for arg in "$@"; do
    case $arg in
        --source=*) SHARED_SOURCE="${arg#*=}" ;;
        --output=*) OUTPUT_DIR="${arg#*=}" ;;
        --version=*) VERSION="${arg#*=}" ;;
        *) VERSION="$arg" ;;
    esac
done

PACKAGE_NAME="shared-v${VERSION}.tar.gz"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo ""
echo "========================================="
echo "  FlowOne Shared Library - Package Builder"
echo "  Version: $VERSION"
echo "========================================="
echo ""

if [ ! -d "$SHARED_SOURCE" ]; then
    log_error "Shared library source not found at: $SHARED_SOURCE"
    exit 1
fi

log_info "Source: $SHARED_SOURCE"
log_info "Output: $OUTPUT_DIR/$PACKAGE_NAME"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/shared"

# Copy code only (exclude vendor/, .git/, runtime state). The library has no
# external composer deps; panel/email autoload FlowOne\Storage via psr-4 paths.
for item in bin config cron docs src systemd tests composer.json composer.lock INSTALL.md; do
    if [ -e "$SHARED_SOURCE/$item" ]; then
        cp -r "$SHARED_SOURCE/$item" "$BUILD_DIR/shared/"
        log_info "  -> included $item"
    fi
done

# Include the installer + metadata
cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/shared/"
echo "$VERSION" > "$BUILD_DIR/shared/VERSION"
date -Iseconds > "$BUILD_DIR/shared/BUILD_DATE"

log_info "Creating package..."
cd "$BUILD_DIR"
tar -czf "$PACKAGE_NAME" shared/
mv "$PACKAGE_NAME" "$OUTPUT_DIR/"

cd "$OUTPUT_DIR"
rm -f shared-latest.tar.gz
ln -s "$PACKAGE_NAME" shared-latest.tar.gz

rm -rf "$BUILD_DIR"

PACKAGE_SIZE=$(du -h "$OUTPUT_DIR/$PACKAGE_NAME" | cut -f1)
echo ""
echo "========================================="
echo -e "${GREEN}  Package created successfully!${NC}"
echo "========================================="
echo "  Package: $OUTPUT_DIR/$PACKAGE_NAME"
echo "  Size:    $PACKAGE_SIZE"
echo "  Symlink: $OUTPUT_DIR/shared-latest.tar.gz"
echo ""
