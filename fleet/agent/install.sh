#!/bin/bash
#
# Fleet Manager Agent Installation Script
#
# This script installs and configures the Fleet Manager agent daemon.
# Must be run as root.
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
echo_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
echo_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo_error "This script must be run as root"
    exit 1
fi

# Configuration
INSTALL_DIR="/var/www/vps-fleet"
AGENT_DIR="${INSTALL_DIR}/agent"
VAR_DIR="${INSTALL_DIR}/var"
LOG_DIR="/var/log/fleet-manager"
RUN_DIR="/run/fleet-manager"
SERVICE_FILE="/etc/systemd/system/fleet-agent.service"

echo_info "Installing Fleet Manager Agent..."

# Create directories
echo_info "Creating directories..."
mkdir -p "${VAR_DIR}"
mkdir -p "${LOG_DIR}"
mkdir -p "${RUN_DIR}"

# Set permissions
chown -R root:root "${AGENT_DIR}"
chmod -R 755 "${AGENT_DIR}"
chown www-data:www-data "${VAR_DIR}"
chmod 750 "${VAR_DIR}"
chown root:root "${LOG_DIR}"
chmod 755 "${LOG_DIR}"
chown root:www-data "${RUN_DIR}"
chmod 750 "${RUN_DIR}"

# Generate auth token if it doesn't exist
TOKEN_FILE="${VAR_DIR}/agent.token"
if [ ! -f "${TOKEN_FILE}" ]; then
    echo_info "Generating authentication token..."
    openssl rand -hex 32 > "${TOKEN_FILE}"
    chown www-data:www-data "${TOKEN_FILE}"
    chmod 640 "${TOKEN_FILE}"
    echo_info "Token generated at ${TOKEN_FILE}"
else
    echo_info "Token file already exists"
fi

# Copy systemd service file
echo_info "Installing systemd service..."
cp "${AGENT_DIR}/fleet-agent.service" "${SERVICE_FILE}"
chmod 644 "${SERVICE_FILE}"

# Copy tmpfiles.d config for socket permissions
echo_info "Installing tmpfiles.d config..."
cp "${AGENT_DIR}/fleet-manager.conf" "/etc/tmpfiles.d/fleet-manager.conf"
chmod 644 "/etc/tmpfiles.d/fleet-manager.conf"
systemd-tmpfiles --create /etc/tmpfiles.d/fleet-manager.conf 2>/dev/null || true

# Reload systemd
echo_info "Reloading systemd daemon..."
systemctl daemon-reload

# Enable and start service
echo_info "Enabling fleet-agent service..."
systemctl enable fleet-agent

echo_info "Starting fleet-agent service..."
systemctl start fleet-agent

# Check status
sleep 2
if systemctl is-active --quiet fleet-agent; then
    echo_info "Fleet Manager Agent is running!"
    echo ""
    echo_info "Service status:"
    systemctl status fleet-agent --no-pager -l | head -20
else
    echo_error "Fleet Manager Agent failed to start!"
    echo ""
    echo_error "Service status:"
    systemctl status fleet-agent --no-pager -l
    exit 1
fi

# Verify socket
if [ -S "${RUN_DIR}/agent.sock" ]; then
    echo_info "Socket created at ${RUN_DIR}/agent.sock"
else
    echo_warn "Socket not found - agent may still be starting"
fi

echo ""
echo_info "Installation complete!"
echo ""
echo "Commands:"
echo "  systemctl status fleet-agent   - Check status"
echo "  systemctl restart fleet-agent  - Restart agent"
echo "  journalctl -u fleet-agent -f   - View logs"
echo "  tail -f ${LOG_DIR}/agent.log   - View agent log"
echo ""

