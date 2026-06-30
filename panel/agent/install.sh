#!/bin/bash
#
# VPS Admin Agent Installation Script
#
# This script installs the agent as a systemd service.
# Must be run as root.
#

set -e

INSTALL_PATH="/var/www/vps-admin"
AGENT_USER="root"
SOCKET_GROUP="www-data"

echo "Installing VPS Admin Agent..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Error: Please run as root"
    exit 1
fi

# Create directories
mkdir -p "$INSTALL_PATH"/{agent,var,backups,logs}
mkdir -p "$INSTALL_PATH"/backups/{configs,databases,deleted_vhosts,deleted_sites,deleted_mail}

# Copy agent files
cp -r agent/* "$INSTALL_PATH/agent/"

# Generate auth token
if [ ! -f "$INSTALL_PATH/var/agent.token" ]; then
    openssl rand -hex 32 > "$INSTALL_PATH/var/agent.token"
    chmod 640 "$INSTALL_PATH/var/agent.token"
    chown root:$SOCKET_GROUP "$INSTALL_PATH/var/agent.token"
    echo "Generated new auth token"
fi

# Set permissions
chown -R root:root "$INSTALL_PATH/agent"
chmod -R 750 "$INSTALL_PATH/agent"
chown root:$SOCKET_GROUP "$INSTALL_PATH/var"
chmod 750 "$INSTALL_PATH/var"
chown root:$SOCKET_GROUP "$INSTALL_PATH/backups"
chmod 750 "$INSTALL_PATH/backups"
chown root:$SOCKET_GROUP "$INSTALL_PATH/logs"
chmod 750 "$INSTALL_PATH/logs"

# Create systemd service
cat > /etc/systemd/system/vpsadmin-agent.service << 'EOF'
[Unit]
Description=VPS Admin Agent
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/vps-admin/agent
ExecStart=/usr/bin/php /var/www/vps-admin/agent/agent.php --foreground
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Reload systemd
systemctl daemon-reload

# Enable and start service
systemctl enable vpsadmin-agent
systemctl start vpsadmin-agent

echo ""
echo "VPS Admin Agent installed successfully!"
echo ""
echo "Auth token saved to: $INSTALL_PATH/var/agent.token"
echo "Socket path: $INSTALL_PATH/var/agent.sock"
echo ""
echo "Commands:"
echo "  systemctl status vpsadmin-agent"
echo "  systemctl restart vpsadmin-agent"
echo "  journalctl -u vpsadmin-agent -f"
echo ""

