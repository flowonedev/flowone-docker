#!/bin/bash
#
# DEVCON Fleet Manager - Node.js Installation
# Installs Node.js LTS (v22.x) for mailsync-server and collab-server
#
# Usage: ./nodejs-install.sh
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Retry a command up to 3 times with a short backoff (for transient network failures)
retry() {
    local n=1 max=3 delay=5
    while true; do
        "$@" && return 0
        if [ "$n" -ge "$max" ]; then
            log_error "Command failed after ${n} attempts: $*"
            return 1
        fi
        log_warn "Attempt ${n}/${max} failed, retrying in ${delay}s..."
        n=$((n + 1))
        sleep "$delay"
    done
}

echo "=========================================="
echo "DEVCON Fleet Manager - Node.js Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Check if Node.js is already installed and at correct major version
if command -v node &> /dev/null; then
    CURRENT_VERSION=$(node --version 2>/dev/null | sed 's/v//' | cut -d. -f1)
    if [ "$CURRENT_VERSION" -ge 22 ] 2>/dev/null; then
        log_info "Node.js v$(node --version) already installed"
        log_info "npm v$(npm --version)"
        exit 0
    else
        log_warn "Node.js v$(node --version) found, upgrading to v22.x LTS..."
    fi
fi

log_info "Adding NodeSource repository (Node.js 22.x LTS)..."
retry bash -c 'curl -fsSL --retry 3 --retry-delay 3 --connect-timeout 30 https://deb.nodesource.com/setup_22.x | bash -'

log_info "Installing Node.js..."
retry env DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs

# Verify installation
if ! command -v node &> /dev/null; then
    log_error "Node.js installation failed"
    exit 1
fi

if ! command -v npm &> /dev/null; then
    log_error "npm not found after Node.js installation"
    exit 1
fi

log_info "Node.js $(node --version) installed"
log_info "npm $(npm --version) installed"

# Install PM2 globally for process management (optional but useful)
log_info "Installing PM2 process manager..."
npm install -g pm2 2>/dev/null || log_warn "PM2 installation failed (optional)"

log_info "Node.js installation complete!"
echo ""
echo "Installed:"
echo "  Node.js: $(node --version)"
echo "  npm:     $(npm --version)"
if command -v pm2 &> /dev/null; then
    echo "  PM2:     $(pm2 --version)"
fi
echo ""

