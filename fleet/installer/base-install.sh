#!/bin/bash
#
# DEVCON Fleet Manager - Base Installation Script
# Installs core dependencies and prepares the server
#
# Usage: ./base-install.sh
#

set -e

# Colors
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
echo "DEVCON Fleet Manager - Base Installation"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Check Ubuntu version
if ! grep -q "Ubuntu" /etc/os-release; then
    log_error "This script is designed for Ubuntu"
    exit 1
fi

log_info "Updating system packages..."
retry apt-get update
DEBIAN_FRONTEND=noninteractive apt-get upgrade -y

log_info "Installing base dependencies..."
retry env DEBIAN_FRONTEND=noninteractive apt-get install -y \
    curl \
    wget \
    git \
    unzip \
    zip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release \
    dnsutils \
    net-tools \
    htop \
    vim \
    nano \
    build-essential \
    openssl \
    cron \
    supervisor \
    gzip \
    bzip2 \
    xz-utils \
    nfs-common \
    openvpn \
    composer \
    poppler-utils \
    imagemagick \
    ghostscript \
    libmagickwand-dev \
    spamassassin

log_info "Setting timezone..."
timedatectl set-timezone UTC

log_info "Configuring hostname..."
if [ -n "$1" ]; then
    hostnamectl set-hostname "$1"
    log_info "Hostname set to: $1"
fi

log_info "Creating web directories..."
mkdir -p /var/www
mkdir -p /var/log/apps

log_info "Base installation complete!"
echo ""
echo "Installed:"
echo "  - Core utilities (curl, wget, git, unzip, etc.)"
echo "  - Build tools (build-essential, openssl)"
echo "  - Network tools (dnsutils, net-tools, nfs-common, openvpn)"
echo "  - Image/PDF tools (imagemagick, ghostscript, poppler-utils)"
echo "  - SpamAssassin (sa-learn for spam training)"
echo "  - Composer (PHP dependency manager)"
echo ""
echo "Next steps:"
echo "  1. Run nodejs-install.sh to install Node.js"
echo "  2. Run ols-install.sh to install OpenLiteSpeed"
echo "  3. Run redis-install.sh to install Redis"
echo "  4. Run meilisearch-install.sh to install Meilisearch"
echo "  5. Run mail-install.sh to install mail server"
echo "  6. Run security-install.sh to configure security"

