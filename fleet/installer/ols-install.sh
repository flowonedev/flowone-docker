#!/bin/bash
#
# DEVCON Fleet Manager - OpenLiteSpeed Installation
# Installs OpenLiteSpeed web server with PHP 8.3
#
# Usage: ./ols-install.sh [admin_password]
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

ADMIN_PASS="${1:-$(openssl rand -hex 16)}"

echo "=========================================="
echo "DEVCON Fleet Manager - OpenLiteSpeed Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

log_info "Adding LiteSpeed repository..."
retry bash -c 'wget -t 3 --timeout=30 -O - https://repo.litespeed.sh | bash'

log_info "Installing OpenLiteSpeed..."
retry env DEBIAN_FRONTEND=noninteractive apt-get install -y openlitespeed

log_info "Installing PHP 8.3 for LiteSpeed..."
retry env DEBIAN_FRONTEND=noninteractive apt-get install -y \
    lsphp83 \
    lsphp83-common \
    lsphp83-mysql \
    lsphp83-curl \
    lsphp83-imap \
    lsphp83-intl \
    lsphp83-opcache \
    lsphp83-imagick \
    lsphp83-memcached \
    lsphp83-redis

log_info "Linking PHP..."
ln -sf /usr/local/lsws/lsphp83/bin/php /usr/bin/php
ln -sf /usr/local/lsws/lsphp83/bin/lsphp /usr/local/lsws/fcgi-bin/lsphp

log_info "Setting admin password..."
/usr/local/lsws/admin/misc/admpass.sh <<EOF
admin
$ADMIN_PASS
$ADMIN_PASS
EOF

log_info "Starting OpenLiteSpeed..."
systemctl enable lshttpd
systemctl start lshttpd

log_info "OpenLiteSpeed installation complete!"
echo ""
echo "Admin panel: https://$(hostname -I | awk '{print $1}'):7080"
echo "Username: admin"
echo "Password: $ADMIN_PASS"
echo ""
echo "IMPORTANT: Save these credentials securely!"

