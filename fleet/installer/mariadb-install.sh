#!/bin/bash
#
# DEVCON Fleet Manager - MariaDB Installation
# Installs and secures MariaDB
#
# Usage: ./mariadb-install.sh <root_password>
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

if [ -z "$1" ]; then
    log_error "Usage: ./mariadb-install.sh <root_password>"
    exit 1
fi

ROOT_PASS="$1"

echo "=========================================="
echo "DEVCON Fleet Manager - MariaDB Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

log_info "Installing MariaDB..."
DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client

log_info "Ensuring MariaDB service is running..."
systemctl enable mariadb >/dev/null 2>&1 || true
systemctl start mariadb 2>/dev/null || true

# Temp client config: keeps the password off the command line and survives
# special characters (#, ;, ", spaces) that would break an inline -p value.
CNF="$(mktemp)"
chmod 600 "$CNF"
printf '[client]\nuser=root\npassword="%s"\n' "${ROOT_PASS//\"/\\\"}" > "$CNF"
trap 'rm -f "$CNF"' EXIT

# Wait for the server to accept connections AND detect how root authenticates.
# Fresh install -> unix_socket (no password). Re-run -> the password is already set.
# This makes the script safe to re-run after a partial/failed provision.
AUTH=""
for i in $(seq 1 30); do
    if mariadb -u root -e "SELECT 1" >/dev/null 2>&1; then AUTH="socket"; break; fi
    if mariadb --defaults-extra-file="$CNF" -e "SELECT 1" >/dev/null 2>&1; then AUTH="password"; break; fi
    sleep 1
done

if [ -z "$AUTH" ]; then
    log_error "MariaDB is not reachable / root cannot authenticate after 30s."
    systemctl status mariadb --no-pager -l 2>/dev/null | tail -n 20 || true
    exit 1
fi
log_info "MariaDB is ready (root auth: ${AUTH})."

log_info "Securing MariaDB installation..."

# Escape single quotes for the SQL string literal
SQL_PASS="${ROOT_PASS//\'/\'\'}"

# Set/affirm the root password (idempotent). Use socket auth on a fresh install,
# otherwise authenticate with the already-set password.
if [ "$AUTH" = "socket" ]; then
    mariadb -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${SQL_PASS}';"
else
    mariadb --defaults-extra-file="$CNF" -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${SQL_PASS}';"
fi

# From here on always authenticate with the password. Cleanup statements are
# idempotent and tolerant (|| true) so a re-run never aborts under `set -e`.
DB="mariadb --defaults-extra-file=$CNF"
$DB -e "DELETE FROM mysql.user WHERE User='';" 2>/dev/null || true
$DB -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');" 2>/dev/null || true
$DB -e "DROP DATABASE IF EXISTS test;" 2>/dev/null || true
$DB -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';" 2>/dev/null || true
$DB -e "FLUSH PRIVILEGES;"

log_info "Enabling MariaDB service..."
systemctl enable mariadb >/dev/null 2>&1 || true
systemctl restart mariadb

log_info "MariaDB installation complete!"
echo ""
echo "Root password has been set."

