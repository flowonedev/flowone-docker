#!/bin/bash
#
# Fleet Agent - Build Package Script
# Creates a distributable tar.gz package from the Fleet Agent source
#
# Usage: ./build.sh [version]
# Example: ./build.sh 1.0.0
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FLEET_DIR="$(dirname "$(dirname "$SCRIPT_DIR")")"
AGENT_SOURCE="$FLEET_DIR/agent"
OUTPUT_DIR="$SCRIPT_DIR"
BUILD_DIR="/tmp/agent-build-$$"

# Version
VERSION="${1:-1.0.0}"
PACKAGE_NAME="agent-v${VERSION}.tar.gz"

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
echo "  Fleet Agent - Package Builder"
echo "  Version: $VERSION"
echo "========================================="
echo ""

# Verify source exists
if [ ! -d "$AGENT_SOURCE" ]; then
    log_error "Agent source not found at: $AGENT_SOURCE"
    exit 1
fi

log_info "Source: $AGENT_SOURCE"
log_info "Output: $OUTPUT_DIR/$PACKAGE_NAME"

# Clean up any previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/agent"

# Copy Agent files
log_info "Copying Agent files..."
cp -r "$AGENT_SOURCE/"* "$BUILD_DIR/agent/"

# Copy installer script
log_info "Including installer..."
cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/agent/"

# Create systemd service file
log_info "Creating systemd service..."
cat > "$BUILD_DIR/agent/fleet-agent.service" << 'EOF'
[Unit]
Description=Fleet Manager Agent
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/fleet-agent
ExecStart=/usr/bin/php /opt/fleet-agent/agent.php --foreground
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF

# Create version file
echo "$VERSION" > "$BUILD_DIR/agent/VERSION"
echo "$(date -Iseconds)" > "$BUILD_DIR/agent/BUILD_DATE"

# Create the tarball
log_info "Creating package..."
cd "$BUILD_DIR"
tar -czf "$PACKAGE_NAME" agent/

# Move to output directory
mv "$PACKAGE_NAME" "$OUTPUT_DIR/"

# Create/update latest symlink
cd "$OUTPUT_DIR"
rm -f agent-latest.tar.gz
ln -s "$PACKAGE_NAME" agent-latest.tar.gz

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
echo "  Symlink: $OUTPUT_DIR/agent-latest.tar.gz"
echo ""

