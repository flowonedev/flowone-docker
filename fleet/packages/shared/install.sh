#!/bin/bash
#
# FlowOne Shared Library (flowone/storage) - Installer
# Deploys the privilege-separated storage platform to /var/www/shared and wires
# its dedicated user, HMAC state key, runtime dirs, systemd daemons and crons.
# Mirrors INSTALL.md. Safe to re-run (idempotent).
#
# Usage: bash install.sh [--install-path=/var/www/shared] [--php-bin=PATH] [--update-only]
#
set -e

# Colors + logging (defined before first use)
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

INSTALL_PATH="/var/www/shared"
UPDATE_ONLY=0
PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="php"
STORAGE_USER="flowone-storage"

for arg in "$@"; do
    case $arg in
        --install-path=*) INSTALL_PATH="${arg#*=}" ;;
        --php-bin=*) PHP_BIN="${arg#*=}" ;;
        --update-only) UPDATE_ONLY=1 ;;
    esac
done

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log_info "Installing FlowOne shared library to ${INSTALL_PATH}"

# 1) Dedicated single-purpose unprivileged user (no home, no login). The monitor +
#    reclaim daemons run as this user; the helper (root) gates the socket by its UID.
if ! id -u "$STORAGE_USER" >/dev/null 2>&1; then
    log_info "Creating system user ${STORAGE_USER}"
    useradd --system --no-create-home --shell /usr/sbin/nologin "$STORAGE_USER" 2>/dev/null || true
fi

# 2) Code sync (does not touch runtime data dirs)
mkdir -p "$INSTALL_PATH"
for item in bin config cron docs src systemd tests composer.json composer.lock INSTALL.md VERSION BUILD_DATE; do
    [ -e "$SRC_DIR/$item" ] && cp -r "$SRC_DIR/$item" "$INSTALL_PATH/"
done
# Code owned by root; bin scripts executable
chown -R root:root "$INSTALL_PATH"
[ -d "$INSTALL_PATH/bin" ] && chmod +x "$INSTALL_PATH"/bin/* 2>/dev/null || true

# 3) /etc/flowone + HMAC state key (signs/verifies all authoritative state payloads).
#    Generate a FRESH per-host key if absent; never overwrite an existing one.
install -d -m 0750 -g "$STORAGE_USER" /etc/flowone 2>/dev/null || mkdir -p /etc/flowone
chgrp "$STORAGE_USER" /etc/flowone 2>/dev/null || true
chmod 0750 /etc/flowone
if [ ! -f /etc/flowone/state.key ]; then
    log_info "Generating /etc/flowone/state.key (fresh per-host HMAC key)"
    install -m 0640 -o root -g "$STORAGE_USER" /dev/null /etc/flowone/state.key 2>/dev/null || { :; }
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32 > /etc/flowone/state.key
    else
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > /etc/flowone/state.key
    fi
    chown root:"$STORAGE_USER" /etc/flowone/state.key 2>/dev/null || true
    chmod 0640 /etc/flowone/state.key
else
    log_info "/etc/flowone/state.key already present - keeping it"
fi
# Canonical config is read in-place from ${INSTALL_PATH}/config/storage.php.
# Per-host overrides (optional) live in /etc/flowone/storage.local.php - not created here.

# 4) Runtime directories with the exact ownership/modes the daemons expect.
#    /run/flowone is also provided by RuntimeDirectory= in the helper unit.
install -d -m 0775 -o root -g "$STORAGE_USER"            /var/lib/flowone 2>/dev/null || { mkdir -p /var/lib/flowone; chown root:"$STORAGE_USER" /var/lib/flowone 2>/dev/null || true; chmod 0775 /var/lib/flowone; }
install -d -m 0755 -o "$STORAGE_USER" -g "$STORAGE_USER" /var/log/flowone 2>/dev/null || { mkdir -p /var/log/flowone; chown "$STORAGE_USER":"$STORAGE_USER" /var/log/flowone 2>/dev/null || true; }
install -d -m 0755 -o "$STORAGE_USER" -g "$STORAGE_USER" /var/log/flowone/chaos 2>/dev/null || mkdir -p /var/log/flowone/chaos
install -d -m 0755 -o root -g root                       /run/flowone 2>/dev/null || mkdir -p /run/flowone
# NAS mountpoint (real mount is set up separately when NAS/VPN are enabled)
mkdir -p /mnt/nas-drive

# 5) No composer install: the library ships no vendor/ and has no external deps;
#    panel/email autoload FlowOne\Storage via their own composer psr-4 mapping
#    (../../shared/src/Storage/, resolved by the sibling /var/www layout).

# 6) systemd units (helper [root], monitord + reclaim-daemon [flowone-storage])
if [ -d "$INSTALL_PATH/systemd" ]; then
    log_info "Installing systemd units..."
    shopt -s nullglob
    units=("$INSTALL_PATH"/systemd/*.service "$INSTALL_PATH"/systemd/*.timer)
    for unit in "${units[@]}"; do
        install -m 0644 "$unit" "/etc/systemd/system/$(basename "$unit")"
        log_info "  -> $(basename "$unit")"
    done
    systemctl daemon-reload 2>/dev/null || true
    # Helper first, then the unprivileged daemons (After=/Wants= also orders them).
    # Never fail the install if a unit needs the NAS/VPN that a clone lacks yet.
    for unit in flowone-storage-helper.service flowone-storage-monitord.service flowone-reclaim-daemon.service; do
        [ -f "/etc/systemd/system/$unit" ] || continue
        systemctl enable "$unit" 2>/dev/null || true
        systemctl restart "$unit" 2>/dev/null || log_warn "  (${unit} did not start - likely needs NAS/VPN; harmless on clones without NAS)"
    done
    shopt -u nullglob
fi

# 7) Cron jobs (match the source server). Skipped in --update-only mode.
if [ "$UPDATE_ONLY" != "1" ]; then
    log_info "Installing cron jobs..."

    # Storage request dispatcher - every minute (processes queued helper RPCs)
    cat > /etc/cron.d/flowone-storage <<CRON
# Managed by Fleet Manager - FlowOne storage request dispatcher
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${STORAGE_USER} ${PHP_BIN} ${INSTALL_PATH}/bin/storage-request-dispatcher.php >> /var/log/flowone/dispatcher.log 2>&1
CRON
    chmod 644 /etc/cron.d/flowone-storage

    # NAS backup - nightly snapshot + retain, weekly restore drill
    cat > /etc/cron.d/flowone-nas-backup <<CRON
# Managed by Fleet Manager - FlowOne NAS backup (no-op without NAS/kill switch)
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0  4 * * * ${STORAGE_USER} ${PHP_BIN} ${INSTALL_PATH}/bin/nas-backup.php snapshot --apply >> /var/log/flowone/backup.log 2>&1
0  6 * * * ${STORAGE_USER} ${PHP_BIN} ${INSTALL_PATH}/bin/nas-backup.php retain   --apply >> /var/log/flowone/backup.log 2>&1
30 7 * * 1 ${STORAGE_USER} ${PHP_BIN} ${INSTALL_PATH}/bin/nas-backup.php drill            >> /var/log/flowone/backup.log 2>&1
CRON
    chmod 644 /etc/cron.d/flowone-nas-backup

    # Tenant retention sweep - hourly (root, flock-guarded against overlap)
    cat > /etc/cron.d/flowone-tenant-retention <<CRON
# Managed by Fleet Manager - FlowOne tenant retention sweep
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 * * * * root /usr/bin/flock -n /var/lock/flowone-tenant-retention.lock ${PHP_BIN} ${INSTALL_PATH}/cron/tenant-retention.php --apply >> /var/log/flowone/tenant-retention.log 2>&1
CRON
    chmod 644 /etc/cron.d/flowone-tenant-retention
fi

log_info "FlowOne shared library installed at ${INSTALL_PATH}"
echo "OK"
