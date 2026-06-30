#!/bin/bash
#
# DEVCON Fleet Manager - Redis Installation
# Installs and configures Redis server for caching
#
# Usage: ./redis-install.sh [password]
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

REDIS_PASS="${1:-}"

echo "=========================================="
echo "DEVCON Fleet Manager - Redis Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Check if Redis is already installed and running
if command -v redis-server &> /dev/null; then
    if systemctl is-active --quiet redis-server 2>/dev/null || systemctl is-active --quiet redis 2>/dev/null; then
        log_info "Redis is already installed and running"
        redis-cli INFO server 2>/dev/null | grep -E "redis_version|tcp_port" || true
        exit 0
    fi
fi

log_info "Installing Redis server..."
retry env DEBIAN_FRONTEND=noninteractive apt-get install -y redis-server redis-tools

# Configure Redis for production
log_info "Configuring Redis..."

REDIS_CONF="/etc/redis/redis.conf"

if [ -f "$REDIS_CONF" ]; then
    # Backup original config
    cp "$REDIS_CONF" "${REDIS_CONF}.bak"
    
    # Bind to localhost only (security)
    sed -i 's/^bind .*/bind 127.0.0.1 ::1/' "$REDIS_CONF"
    
    # Enable protected mode
    sed -i 's/^# protected-mode yes/protected-mode yes/' "$REDIS_CONF"
    sed -i 's/^protected-mode no/protected-mode yes/' "$REDIS_CONF"
    
    # Set max memory (256MB default, adjust per server)
    if ! grep -q "^maxmemory " "$REDIS_CONF"; then
        echo "maxmemory 256mb" >> "$REDIS_CONF"
    fi
    
    # Set eviction policy
    if ! grep -q "^maxmemory-policy " "$REDIS_CONF"; then
        echo "maxmemory-policy allkeys-lru" >> "$REDIS_CONF"
    fi
    
    # Disable RDB persistence for cache-only usage (faster)
    # Keep AOF disabled too - this is a cache, not a database
    sed -i 's/^save /#save /' "$REDIS_CONF"
    if ! grep -q "^save \"\"" "$REDIS_CONF"; then
        echo 'save ""' >> "$REDIS_CONF"
    fi
    
    # Set password if provided
    if [ -n "$REDIS_PASS" ]; then
        # Remove any existing requirepass
        sed -i 's/^requirepass .*//' "$REDIS_CONF"
        sed -i 's/^# requirepass .*//' "$REDIS_CONF"
        echo "requirepass ${REDIS_PASS}" >> "$REDIS_CONF"
        log_info "Redis password set"
    fi
    
    # Set supervised to systemd
    sed -i 's/^supervised .*/supervised systemd/' "$REDIS_CONF"
    if ! grep -q "^supervised " "$REDIS_CONF"; then
        echo "supervised systemd" >> "$REDIS_CONF"
    fi
    
    log_info "Redis configuration updated"
else
    log_warn "Redis config not found at $REDIS_CONF, using defaults"
fi

log_info "Enabling and starting Redis..."
systemctl enable redis-server
systemctl restart redis-server

# Wait for Redis to start
sleep 2

# Verify Redis is running
if systemctl is-active --quiet redis-server 2>/dev/null; then
    log_info "Redis is running"
else
    # Some distros use 'redis' instead of 'redis-server'
    systemctl enable redis 2>/dev/null || true
    systemctl restart redis 2>/dev/null || true
    
    if systemctl is-active --quiet redis 2>/dev/null; then
        log_info "Redis is running (as 'redis' service)"
    else
        log_error "Redis failed to start"
        journalctl -u redis-server --no-pager -n 10 || true
        exit 1
    fi
fi

# Test connection
if [ -n "$REDIS_PASS" ]; then
    PONG=$(redis-cli -a "$REDIS_PASS" ping 2>/dev/null)
else
    PONG=$(redis-cli ping 2>/dev/null)
fi

if [ "$PONG" = "PONG" ]; then
    log_info "Redis connection verified"
else
    log_warn "Redis ping test failed, but service is running"
fi

log_info "Redis installation complete!"
echo ""
echo "Configuration:"
echo "  Bind:       127.0.0.1 (localhost only)"
echo "  Port:       6379"
echo "  Max memory: 256MB"
echo "  Eviction:   allkeys-lru"
if [ -n "$REDIS_PASS" ]; then
    echo "  Password:   SET"
fi
echo ""

