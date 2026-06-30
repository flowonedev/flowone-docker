#!/bin/bash

# Shared FlowOne\Storage library deployment script
# Copies files from SFTP staging to production
#
# Production layout (sibling directories under /var/www/):
#   /var/www/vps-email/   (email frontend + backend, deployed by copy-email.sh)
#   /var/www/vps-admin/   (panel dashboard + api + agent, deployed by copy-panel.sh)
#   /var/www/shared/      (this library)
#
# The composer autoloaders in vps-email/backend/composer.json and
# vps-admin/api/composer.json use "../../shared/src/Storage/", which only
# resolves correctly because of this sibling layout. Do NOT move shared/.
#
# Expected upload structure (SFTP):
#   /home/email.devcon1.hu/public_html/shared/   (full shared/ tree)
#
# Run as root on the server:
#   sudo bash /home/email.devcon1.hu/public_html/shared/copy-shared.sh
#
# Optional environment overrides:
#   STAGING_SHARED=/some/other/path/shared bash copy-shared.sh

set -e

# Configuration
STAGING_SHARED="${STAGING_SHARED:-/home/email.devcon1.hu/public_html/shared}"
PRODUCTION_SHARED="/var/www/shared"
EMAIL_BACKEND_DIR="/var/www/vps-email/backend"
PANEL_API_DIR="/var/www/vps-admin/api"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}  FlowOne Shared Library Deploy${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Must be root for chown, systemctl, install -o root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: this script must run as root (use sudo).${NC}"
    exit 1
fi

# Pre-flight: staging dir must exist with the expected layout
if [ ! -d "$STAGING_SHARED" ]; then
    echo -e "${RED}Error: staging directory $STAGING_SHARED does not exist!${NC}"
    echo -e "${YELLOW}Upload your local shared/ folder to that location first,${NC}"
    echo -e "${YELLOW}or override with STAGING_SHARED=/path bash copy-shared.sh${NC}"
    exit 1
fi
for required in bin/storage-helper.php bin/storage-monitord.php bin/storage-ctl.php \
                src/Storage/StorageHealth.php config/storage.php composer.json \
                systemd/flowone-storage-helper.service systemd/flowone-storage-monitord.service; do
    if [ ! -f "$STAGING_SHARED/$required" ]; then
        echo -e "${RED}Error: $STAGING_SHARED/$required is missing — upload looks incomplete${NC}"
        exit 1
    fi
done

# Pre-flight: dedicated daemon user must exist (created in INSTALL.md step 2)
if ! id -u flowone-storage >/dev/null 2>&1; then
    echo -e "${RED}Error: user 'flowone-storage' does not exist.${NC}"
    echo -e "${YELLOW}Create it first (see shared/INSTALL.md step 2):${NC}"
    echo -e "${YELLOW}  sudo useradd --system --no-create-home --shell /usr/sbin/nologin flowone-storage${NC}"
    exit 1
fi

# Pre-flight: HMAC key must exist (created in INSTALL.md step 3)
if [ ! -f /etc/flowone/state.key ]; then
    echo -e "${RED}Error: HMAC key /etc/flowone/state.key does not exist.${NC}"
    echo -e "${YELLOW}Generate it first (see shared/INSTALL.md step 3).${NC}"
    exit 1
fi

# [1/8] Mirror the tree to production
echo -e "${GREEN}[1/8]${NC} Mirroring $STAGING_SHARED -> $PRODUCTION_SHARED ..."
mkdir -p "$PRODUCTION_SHARED"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
          --exclude='.git' \
          --exclude='vendor' \
          --exclude='copy-shared.sh' \
          "$STAGING_SHARED/" "$PRODUCTION_SHARED/"
else
    # Fallback when rsync isn't installed: cp + clean
    rm -rf "$PRODUCTION_SHARED"/{bin,config,docs,src,systemd,tests,composer.json,INSTALL.md} 2>/dev/null || true
    cp -a "$STAGING_SHARED"/. "$PRODUCTION_SHARED"/
    rm -f "$PRODUCTION_SHARED/copy-shared.sh"
fi
echo "Tree mirrored."

# [2/8] Fix CRLF on every .php file (Windows uploads break PHP parsing)
echo -e "${GREEN}[2/8]${NC} Converting PHP line endings to Unix format..."
find "$PRODUCTION_SHARED" -name "*.php" -type f -exec sed -i 's/\r$//' {} \;

# [3/8] Set ownership and permissions
# Everything is owned root:root. Files are world-readable so flowone-storage
# (which runs the monitor daemon) can read PHP modules. Helper runs as root.
echo -e "${GREEN}[3/8]${NC} Setting ownership and permissions..."
chown -R root:root "$PRODUCTION_SHARED"
find "$PRODUCTION_SHARED" -type d -exec chmod 0755 {} \;
find "$PRODUCTION_SHARED" -type f -exec chmod 0644 {} \;
# CLI entrypoints are executable for convenience (PHP doesn't need this, but
# operators expect `./storage-ctl.php` to work).
chmod 0755 "$PRODUCTION_SHARED"/bin/*.php
chmod 0755 "$PRODUCTION_SHARED"/tests/foundation-test.php 2>/dev/null || true
chmod 0755 "$PRODUCTION_SHARED"/tests/chaos/scenario_*.php 2>/dev/null || true

# [4/8] Refresh systemd units if they already exist on disk (install or update)
# The user installs them once via INSTALL.md step 5; here we only update.
echo -e "${GREEN}[4/8]${NC} Refreshing systemd units (if previously installed)..."
SYSTEMD_RELOAD=false
for unit in flowone-storage-helper.service flowone-storage-monitord.service; do
    if [ -f "/etc/systemd/system/$unit" ]; then
        install -m 0644 "$PRODUCTION_SHARED/systemd/$unit" "/etc/systemd/system/$unit"
        echo "  Updated /etc/systemd/system/$unit"
        SYSTEMD_RELOAD=true
    else
        echo -e "  ${YELLOW}$unit not yet installed (run INSTALL.md step 5 first)${NC}"
    fi
done
if $SYSTEMD_RELOAD; then
    systemctl daemon-reload
fi

# [5/8] Regenerate email backend autoloader to pick up FlowOne\Storage\
echo -e "${GREEN}[5/8]${NC} Regenerating email backend autoloader..."
if [ -d "$EMAIL_BACKEND_DIR" ] && [ -f "$EMAIL_BACKEND_DIR/composer.json" ]; then
    (cd "$EMAIL_BACKEND_DIR" && composer dump-autoload -o --no-interaction 2>/dev/null) \
        && echo "  Email backend autoloader regenerated." \
        || echo -e "  ${YELLOW}composer dump-autoload failed in $EMAIL_BACKEND_DIR (skipping)${NC}"
else
    echo -e "  ${YELLOW}$EMAIL_BACKEND_DIR not present, skipping${NC}"
fi

# [6/8] Regenerate panel API autoloader to pick up FlowOne\Storage\
echo -e "${GREEN}[6/8]${NC} Regenerating panel API autoloader..."
if [ -d "$PANEL_API_DIR" ] && [ -f "$PANEL_API_DIR/composer.json" ]; then
    (cd "$PANEL_API_DIR" && composer dump-autoload -o --no-interaction 2>/dev/null) \
        && echo "  Panel API autoloader regenerated." \
        || echo -e "  ${YELLOW}composer dump-autoload failed in $PANEL_API_DIR (skipping)${NC}"
else
    echo -e "  ${YELLOW}$PANEL_API_DIR not present, skipping${NC}"
fi

# Panel agent uses spl_autoload_register, no composer step needed.

# [7/8] Restart daemons if running, restart panel agent service if running
echo -e "${GREEN}[7/8]${NC} Restarting services..."

# Storage daemons (created in INSTALL.md steps 5-7)
for unit in flowone-storage-helper.service flowone-storage-monitord.service; do
    if systemctl is-enabled --quiet "$unit" 2>/dev/null; then
        echo "  Restarting $unit..."
        systemctl restart "$unit"
        sleep 1
        if systemctl is-active --quiet "$unit"; then
            echo -e "  ${GREEN}$unit OK${NC}"
        else
            echo -e "  ${RED}$unit FAILED — journalctl -u $unit -n 50${NC}"
        fi
    else
        echo -e "  ${YELLOW}$unit not yet enabled (run INSTALL.md step 7)${NC}"
    fi
done

# Panel agent re-reads spl_autoload_register on restart
if systemctl is-active --quiet vpsadmin-agent 2>/dev/null; then
    systemctl restart vpsadmin-agent
    echo "  Restarted vpsadmin-agent."
fi

# Restart PHP workers so the email/panel API pick up the new autoload map.
# OpenLiteSpeed graceful restart is enough; no need to flush opcache by hand.
if command -v /usr/local/lsws/bin/lswsctrl >/dev/null 2>&1; then
    /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
    echo "  Restarted OpenLiteSpeed."
fi

# [8/8] Health check
echo -e "${GREEN}[8/8]${NC} Health check..."
HEALTH_OK=true
[ ! -d "$PRODUCTION_SHARED/src/Storage" ] && echo -e "  ${RED}MISSING: src/Storage${NC}" && HEALTH_OK=false
[ ! -f "$PRODUCTION_SHARED/bin/storage-ctl.php" ] && echo -e "  ${RED}MISSING: bin/storage-ctl.php${NC}" && HEALTH_OK=false
[ ! -f "$PRODUCTION_SHARED/config/storage.php" ] && echo -e "  ${RED}MISSING: config/storage.php${NC}" && HEALTH_OK=false

# Best-effort: ask the operator CLI for status. Will only work after daemon
# is up; tolerated failure here so first-time installers don't see red.
if [ -f "$PRODUCTION_SHARED/bin/storage-ctl.php" ]; then
    if /usr/local/lsws/lsphp83/bin/php "$PRODUCTION_SHARED/bin/storage-ctl.php" status >/dev/null 2>&1; then
        echo -e "  ${GREEN}storage-ctl status OK${NC}"
    else
        echo -e "  ${YELLOW}storage-ctl status not OK yet (daemon may not be enabled — see INSTALL.md)${NC}"
    fi
fi

echo ""
if $HEALTH_OK; then
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}  Shared library deployed!${NC}"
    echo -e "${GREEN}========================================${NC}"
else
    echo -e "${RED}========================================${NC}"
    echo -e "${RED}  Deployment finished with errors${NC}"
    echo -e "${RED}========================================${NC}"
    exit 1
fi

echo ""
echo "Next steps (first-time installs only):"
echo "  1. Follow steps 2-7 in /var/www/shared/INSTALL.md"
echo "  2. Run /var/www/shared/tests/foundation-test.php --verbose"
echo ""
