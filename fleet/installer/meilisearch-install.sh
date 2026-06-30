#!/bin/bash
#
# DEVCON Fleet Manager - Meilisearch Installation
# Installs Meilisearch search engine for universal search
#
# Usage: ./meilisearch-install.sh [master_key]
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

MEILI_MASTER_KEY="${1:-$(openssl rand -hex 16)}"

echo "=========================================="
echo "DEVCON Fleet Manager - Meilisearch Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Check if Meilisearch is already installed and running
if command -v meilisearch &> /dev/null; then
    if systemctl is-active --quiet meilisearch 2>/dev/null; then
        log_info "Meilisearch is already installed and running"
        exit 0
    fi
fi

log_info "Downloading Meilisearch..."
retry bash -c 'curl -L --retry 3 --retry-delay 3 --connect-timeout 30 https://install.meilisearch.com | sh'

# Move binary to system path
if [ -f "./meilisearch" ]; then
    mv ./meilisearch /usr/local/bin/meilisearch
    chmod +x /usr/local/bin/meilisearch
    log_info "Meilisearch binary installed to /usr/local/bin/"
else
    log_error "Meilisearch binary not found after download"
    exit 1
fi

# Create data directory
log_info "Creating data directories..."
mkdir -p /var/lib/meilisearch/data
mkdir -p /var/lib/meilisearch/dumps
mkdir -p /var/lib/meilisearch/snapshots
mkdir -p /var/log/meilisearch

# Create dedicated user
log_info "Creating meilisearch user..."
useradd -r -s /usr/sbin/nologin -d /var/lib/meilisearch meilisearch 2>/dev/null || true
chown -R meilisearch:meilisearch /var/lib/meilisearch
chown -R meilisearch:meilisearch /var/log/meilisearch

# Create config file
log_info "Creating configuration..."
cat > /etc/meilisearch.toml << EOF
# Meilisearch Configuration
# Managed by DEVCON Fleet Manager

# Bind to localhost only for security
http_addr = "127.0.0.1:7700"

# Master key for API authentication
master_key = "${MEILI_MASTER_KEY}"

# Data storage
db_path = "/var/lib/meilisearch/data"
dump_dir = "/var/lib/meilisearch/dumps"
snapshot_dir = "/var/lib/meilisearch/snapshots"

# Logging
log_level = "INFO"

# Environment
env = "production"

# Max payload size (100MB)
http_payload_size_limit = "104857600"
EOF

chown meilisearch:meilisearch /etc/meilisearch.toml
chmod 600 /etc/meilisearch.toml

# Create systemd service
log_info "Creating systemd service..."
cat > /etc/systemd/system/meilisearch.service << 'EOF'
[Unit]
Description=Meilisearch Search Engine
Documentation=https://docs.meilisearch.com
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
ExecStart=/usr/local/bin/meilisearch --config-file-path /etc/meilisearch.toml
Restart=always
RestartSec=5
StandardOutput=append:/var/log/meilisearch/meilisearch.log
StandardError=append:/var/log/meilisearch/meilisearch-error.log
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
EOF

log_info "Enabling and starting Meilisearch..."
systemctl daemon-reload
systemctl enable meilisearch
systemctl start meilisearch

# Wait for startup
sleep 3

# Verify
if systemctl is-active --quiet meilisearch 2>/dev/null; then
    log_info "Meilisearch is running"
else
    log_error "Meilisearch failed to start"
    journalctl -u meilisearch --no-pager -n 15 || true
    exit 1
fi

# Get API keys
log_info "Retrieving API keys..."
sleep 2
KEYS_RESPONSE=$(curl -s -H "Authorization: Bearer ${MEILI_MASTER_KEY}" http://127.0.0.1:7700/keys 2>/dev/null || true)

SEARCH_KEY=$(echo "$KEYS_RESPONSE" | grep -o '"key":"[^"]*"' | head -1 | cut -d'"' -f4 2>/dev/null || true)

log_info "Meilisearch installation complete!"
echo ""
echo "Configuration:"
echo "  Bind:       127.0.0.1:7700 (localhost only)"
echo "  Data:       /var/lib/meilisearch/data"
echo "  Config:     /etc/meilisearch.toml"
echo "  Master key: ${MEILI_MASTER_KEY}"
if [ -n "$SEARCH_KEY" ]; then
    echo "  Search key: ${SEARCH_KEY}"
fi
echo ""
echo "IMPORTANT: Save the master key securely!"
echo ""

