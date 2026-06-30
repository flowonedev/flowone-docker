#!/bin/bash
#
# Fleet Agent - Remote Installer Script
# This script is run on the target server after the package is extracted
#
# Usage: ./install.sh --fleet-url=https://fleet.example.com --agent-token=xxx ...
#
# Required variables (passed as arguments or environment):
#   FLEET_URL       - Fleet Manager URL
#   AGENT_TOKEN     - Token for authenticating with Fleet Manager
#
# Optional:
#   PANEL_DOMAIN    - Panel domain (for local references)
#   EMAIL_DOMAIN    - Email domain (for local references)
#   HEARTBEAT       - Heartbeat interval in seconds (default: 60)
#   --update-only   - Only update code files, preserve configs
#

set -e

# Update mode flag
UPDATE_ONLY=0

# Installation path
INSTALL_PATH="/opt/fleet-agent"

# Parse arguments
for arg in "$@"; do
    case $arg in
        --fleet-url=*) FLEET_URL="${arg#*=}" ;;
        --agent-token=*) AGENT_TOKEN="${arg#*=}" ;;
        --panel-domain=*) PANEL_DOMAIN="${arg#*=}" ;;
        --email-domain=*) EMAIL_DOMAIN="${arg#*=}" ;;
        --heartbeat=*) HEARTBEAT="${arg#*=}" ;;
        --update-only) UPDATE_ONLY=1 ;;
    esac
done

# Colors + logging helpers MUST be defined before any log_* call below.
# (Previously these lived after the UPDATE-ONLY block, so `install.sh --update-only`
# called log_info before it existed and aborted under `set -e`.)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# If update-only mode, skip config creation
if [ "$UPDATE_ONLY" = "1" ]; then
    log_info "Running in UPDATE-ONLY mode - preserving existing configs"
fi

# Defaults
HEARTBEAT="${HEARTBEAT:-60}"

# Validate required variables
validate_vars() {
    # Skip validation in update-only mode
    if [ "$UPDATE_ONLY" = "1" ]; then
        return 0
    fi

    local missing=0
    
    [ -z "$FLEET_URL" ] && { log_error "FLEET_URL is required"; missing=1; }
    [ -z "$AGENT_TOKEN" ] && { log_error "AGENT_TOKEN is required"; missing=1; }
    
    if [ $missing -eq 1 ]; then
        echo ""
        echo "Usage: $0 --fleet-url=https://fleet.example.com --agent-token=xxxxx"
        echo "   or: $0 --update-only   (to update code files only)"
        exit 1
    fi
}

echo ""
echo "========================================="
if [ "$UPDATE_ONLY" = "1" ]; then
    echo "  Fleet Agent - Code Update"
else
    echo "  Fleet Agent Installer"
fi
echo "========================================="
echo ""

# Get script directory (where package was extracted)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

validate_vars

if [ "$UPDATE_ONLY" = "1" ]; then
    log_info "Updating Fleet Agent code (configs preserved)..."
else
    log_info "Installing Fleet Agent..."
    log_info "Fleet Manager: $FLEET_URL"
fi
log_info "Install path: $INSTALL_PATH"

# ============================================
# 1. Create directories
# ============================================
log_info "Creating directories..."

mkdir -p "$INSTALL_PATH"/{var,logs}

# ============================================
# 2. Copy files
# ============================================
log_info "Copying files..."

# Copy agent files (excluding install.sh itself)
for item in "$SCRIPT_DIR/"*; do
    if [ "$(basename "$item")" != "install.sh" ]; then
        cp -r "$item" "$INSTALL_PATH/"
    fi
done

# ============================================
# 3. Create agent config (skip in update-only mode)
# ============================================
if [ "$UPDATE_ONLY" = "1" ] && [ -f "$INSTALL_PATH/config.php" ]; then
    log_info "Preserving existing agent configuration..."
else
    log_info "Creating agent configuration..."

    cat > "$INSTALL_PATH/config.php" << EOF
<?php
return [
    // Fleet Manager connection
    'panel' => [
        'url' => '${FLEET_URL}',
        'agent_token' => '${AGENT_TOKEN}',
    ],

    // Socket configuration
    'socket' => [
        'path' => '${INSTALL_PATH}/var/agent.sock',
        'permissions' => 0660,
        'group' => 'www-data',
    ],

    // Paths
    'paths' => [
        'base' => '${INSTALL_PATH}',
        'token_file' => '${INSTALL_PATH}/var/agent.token',
        'log_file' => '${INSTALL_PATH}/logs/agent.log',
    ],

    // Security
    'security' => [
        'require_auth_token' => true,
    ],

    // Logging
    'logging' => [
        'level' => 'info',
        'max_size' => 10 * 1024 * 1024,
        'max_files' => 5,
    ],

    // Heartbeat settings
    'heartbeat' => [
        'interval' => ${HEARTBEAT},
        'timeout' => 30,
    ],

    // Local references
    'panel_domain' => '${PANEL_DOMAIN}',
    'email_domain' => '${EMAIL_DOMAIN}',

    // Extraction settings
    'extraction' => [
        'max_file_size' => 5 * 1024 * 1024,
        'timeout' => 300,
    ],
];
EOF
fi

# ============================================
# 4. Create systemd service (skip in update-only mode if exists)
# ============================================
if [ "$UPDATE_ONLY" = "1" ] && [ -f "/etc/systemd/system/fleet-agent.service" ]; then
    log_info "Preserving existing systemd service..."
else
    log_info "Creating systemd service..."

    cat > /etc/systemd/system/fleet-agent.service << EOF
[Unit]
Description=Fleet Manager Agent
After=network.target

[Service]
Type=simple
WorkingDirectory=${INSTALL_PATH}
ExecStart=/usr/bin/php ${INSTALL_PATH}/agent.php --foreground
Restart=always
RestartSec=5
User=root

# Environment
Environment=FLEET_URL=${FLEET_URL}
Environment=AGENT_TOKEN=${AGENT_TOKEN}

[Install]
WantedBy=multi-user.target
EOF
fi

# ============================================
# 5. Create heartbeat cron (health + versions + tasks)
# ============================================
# The rich client (heartbeat.php) collects service/cpu/mem/disk health, deployed
# versions and the live SSH port/auth, POSTs them to the Fleet Manager, and runs
# any queued tasks. The systemd unit only runs the socket daemon (agent.php),
# which does NOT send heartbeats - so THIS cron is what actually populates the
# server_health table and the version columns.
#
# (Previously this cron ran a bare `{"status":"alive"}` curl, which updated
# last_heartbeat but left Health permanently empty and never reported versions.)
log_info "Setting up heartbeat cron (health reporter)..."

# Resolve a PHP CLI binary at install time.
PHP_BIN="$(command -v php 2>/dev/null || echo /usr/bin/php)"

# Create heartbeat runner: watchdog for the socket daemon + run the rich client.
cat > "$INSTALL_PATH/heartbeat.sh" << EOF
#!/bin/bash
# Fleet Agent heartbeat runner (health + versions + ssh + tasks).
INSTALL_PATH="${INSTALL_PATH}"
PHP_BIN="${PHP_BIN}"

# Watchdog: restart the socket daemon if it died.
if ! pgrep -f "fleet-agent/agent.php" > /dev/null; then
    systemctl restart fleet-agent 2>/dev/null || true
fi

# Send a full heartbeat (health, versions, ssh) and process any queued tasks.
if [ -f "\$INSTALL_PATH/heartbeat.php" ]; then
    "\$PHP_BIN" "\$INSTALL_PATH/heartbeat.php" > /dev/null 2>&1 || true
fi
EOF

chmod +x "$INSTALL_PATH/heartbeat.sh"

# Run every 30s (cron's finest granularity is 1 min, so add a delayed second run).
(crontab -l 2>/dev/null | grep -v "fleet-agent/heartbeat"; \
 echo "* * * * * $INSTALL_PATH/heartbeat.sh > /dev/null 2>&1"; \
 echo "* * * * * sleep 30; $INSTALL_PATH/heartbeat.sh > /dev/null 2>&1") | crontab -

# ============================================
# 6. Generate auth token
# ============================================
TOKEN_FILE="$INSTALL_PATH/var/agent.token"
if [ ! -f "$TOKEN_FILE" ]; then
    log_info "Generating authentication token..."
    openssl rand -hex 32 > "$TOKEN_FILE"
else
    log_info "Token file already exists, preserving..."
fi

# ============================================
# 7. Create required directories
# ============================================
mkdir -p "$INSTALL_PATH/backups"

# ============================================
# 8. Set permissions
# ============================================
log_info "Setting permissions..."

chown -R root:root "$INSTALL_PATH"
chmod -R 750 "$INSTALL_PATH"
chmod 600 "$INSTALL_PATH/config.php"
chmod 600 "$TOKEN_FILE"

# ============================================
# 9. Enable and start service
# ============================================
log_info "Starting Fleet Agent service..."

systemctl daemon-reload
systemctl enable fleet-agent
systemctl start fleet-agent

# Wait a moment and check status
sleep 2

if systemctl is-active --quiet fleet-agent; then
    log_info "Fleet Agent is running"
else
    log_warn "Fleet Agent may not have started correctly"
    log_warn "Check logs: journalctl -u fleet-agent -f"
fi

# ============================================
# 10. Test connection to Fleet Manager
# ============================================
log_info "Testing connection to Fleet Manager..."

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "${FLEET_URL}/api/agent/heartbeat" \
    -H "X-Agent-Token: ${AGENT_TOKEN}" \
    -H "Content-Type: application/json" \
    -d '{"status":"installed"}' \
    2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    log_info "Successfully connected to Fleet Manager"
elif [ "$HTTP_CODE" = "401" ]; then
    log_warn "Connection failed: Invalid agent token"
else
    log_warn "Connection test returned HTTP $HTTP_CODE"
fi

# ============================================
# Done
# ============================================
echo ""
echo "========================================="
echo -e "${GREEN}  Installation Complete!${NC}"
echo "========================================="
echo ""
echo "  Fleet Manager:  ${FLEET_URL}"
echo "  Install path:   ${INSTALL_PATH}"
echo "  Agent token:    ${AGENT_TOKEN:0:8}..."
echo ""
echo "Commands:"
echo "  Status:   systemctl status fleet-agent"
echo "  Logs:     journalctl -u fleet-agent -f"
echo "  Restart:  systemctl restart fleet-agent"
echo ""

