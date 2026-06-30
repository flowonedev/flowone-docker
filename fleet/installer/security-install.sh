#!/bin/bash
#
# DEVCON Fleet Manager - Security Tools Installation
# Installs and configures Fail2ban and FirewallD
#
# Usage: ./security-install.sh [ssh_port]
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

SSH_PORT="${1:-22}"

echo "=========================================="
echo "DEVCON Fleet Manager - Security Install"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

log_info "Installing Fail2ban..."
DEBIAN_FRONTEND=noninteractive apt-get install -y fail2ban

log_info "Configuring Fail2ban..."
cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
banaction = firewallcmd-rich-rules[actiontype=<multiport>]
banaction_allports = firewallcmd-rich-rules[actiontype=<allports>]

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
bantime = 86400

[postfix]
enabled = true
port = smtp,465,submission
filter = postfix
logpath = /var/log/mail.log
maxretry = 5

[dovecot]
enabled = true
port = imap,imaps
filter = dovecot
logpath = /var/log/mail.log
maxretry = 5

[postfix-sasl]
enabled = true
port = smtp,465,submission
filter = postfix[mode=auth]
logpath = /var/log/mail.log
maxretry = 3
EOF

log_info "Installing FirewallD..."
DEBIAN_FRONTEND=noninteractive apt-get install -y firewalld

log_info "Configuring FirewallD..."
systemctl enable firewalld
systemctl start firewalld

# Allow SSH
firewall-cmd --permanent --add-port=${SSH_PORT}/tcp

# Allow web
firewall-cmd --permanent --add-port=80/tcp
firewall-cmd --permanent --add-port=443/tcp

# Allow mail
firewall-cmd --permanent --add-port=25/tcp
firewall-cmd --permanent --add-port=587/tcp
firewall-cmd --permanent --add-port=993/tcp

# Allow ManageSieve (for mail filter management)
firewall-cmd --permanent --add-port=4190/tcp

# Allow OLS admin (restrict in production)
firewall-cmd --permanent --add-port=7080/tcp

# Note: Redis (6379), Meilisearch (7700), collab (1234), mailsync (1235)
# are intentionally NOT exposed - they bind to localhost only.

# Reload
firewall-cmd --reload

log_info "Starting Fail2ban..."
systemctl enable fail2ban
systemctl start fail2ban

log_info "Security tools installation complete!"
echo ""
echo "Configured:"
echo "  - Fail2ban with SSH, Postfix, and Dovecot jails"
echo "  - FirewallD with HTTP, HTTPS, mail, and ManageSieve ports"
echo ""
echo "SSH port allowed: $SSH_PORT"
echo ""
echo "Internal-only services (localhost, not exposed):"
echo "  - Redis: 6379"
echo "  - Meilisearch: 7700"
echo "  - Collab WS: 1234"
echo "  - Mailsync WS: 1235"

