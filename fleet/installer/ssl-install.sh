#!/bin/bash
#
# DEVCON Fleet Manager - SSL Certificate Installation
# Obtains Let's Encrypt certificates using Certbot
#
# Usage: ./ssl-install.sh <domain> <email> [additional_domains...]
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

if [ -z "$1" ] || [ -z "$2" ]; then
    log_error "Usage: ./ssl-install.sh <domain> <email> [additional_domains...]"
    exit 1
fi

DOMAIN="$1"
EMAIL="$2"
shift 2
ADDITIONAL_DOMAINS="$@"

echo "=========================================="
echo "DEVCON Fleet Manager - SSL Install"
echo "=========================================="
echo ""
log_info "Primary domain: $DOMAIN"
log_info "Email: $EMAIL"
if [ -n "$ADDITIONAL_DOMAINS" ]; then
    log_info "Additional domains: $ADDITIONAL_DOMAINS"
fi
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

log_info "Installing Certbot..."
DEBIAN_FRONTEND=noninteractive apt-get install -y certbot

# Build domain arguments
DOMAIN_ARGS="-d $DOMAIN"
for d in $ADDITIONAL_DOMAINS; do
    DOMAIN_ARGS="$DOMAIN_ARGS -d $d"
done

# Stop web server temporarily
log_info "Stopping web server..."
systemctl stop lshttpd 2>/dev/null || true

log_info "Obtaining SSL certificate..."
certbot certonly \
    --standalone \
    --non-interactive \
    --agree-tos \
    --email "$EMAIL" \
    $DOMAIN_ARGS

# Start web server
log_info "Starting web server..."
systemctl start lshttpd 2>/dev/null || true

# Setup auto-renewal
log_info "Setting up auto-renewal..."
cat > /etc/cron.d/certbot-renewal << 'EOF'
# Certbot auto-renewal
0 3 * * * root certbot renew --quiet --pre-hook "systemctl stop lshttpd" --post-hook "systemctl start lshttpd"
EOF

log_info "SSL certificate installation complete!"
echo ""
echo "Certificate location:"
echo "  Full chain: /etc/letsencrypt/live/$DOMAIN/fullchain.pem"
echo "  Private key: /etc/letsencrypt/live/$DOMAIN/privkey.pem"
echo ""
echo "Auto-renewal has been configured."

