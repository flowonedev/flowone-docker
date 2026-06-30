#!/bin/bash
#
# DEVCON Fleet Manager - Mail Server Installation
# Installs Postfix, Dovecot, OpenDKIM, OpenDMARC
# Sets up virtual mailbox with MariaDB backend
#
# Usage: ./mail-install.sh <hostname> <mail_domain> [mail_db_pass]
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
    log_error "Usage: ./mail-install.sh <hostname> <mail_domain> [mail_db_pass]"
    exit 1
fi

HOSTNAME="$1"
MAIL_DOMAIN="$2"
MAIL_DB_PASS="${3:-$(openssl rand -hex 16)}"
MAIL_DB_NAME="mailserver"
MAIL_DB_USER="mailuser"

echo "=========================================="
echo "DEVCON Fleet Manager - Mail Server Install"
echo "=========================================="
echo ""
log_info "Hostname: $HOSTNAME"
log_info "Mail domain: $MAIL_DOMAIN"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root"
    exit 1
fi

# Preconfigure Postfix
log_info "Preconfiguring Postfix..."
debconf-set-selections <<< "postfix postfix/mailname string $HOSTNAME"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"

log_info "Installing Postfix..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    postfix \
    postfix-mysql \
    postfix-policyd-spf-python

log_info "Installing Dovecot..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    dovecot-core \
    dovecot-imapd \
    dovecot-lmtpd \
    dovecot-mysql \
    dovecot-sieve \
    dovecot-managesieved

log_info "Installing mail utilities..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    opendkim \
    opendkim-tools \
    opendmarc \
    mailutils

# ============================================
# imapsync (mailbox migration engine)
# ============================================
# Used by the Panel IMAP migration tool to copy mailboxes from a previous
# provider. Non-fatal: if the distro repo doesn't carry it, log a warning
# and continue — migrations just stay unavailable until it's installed.
log_info "Installing imapsync (mailbox migration tool)..."
if DEBIAN_FRONTEND=noninteractive apt-get install -y imapsync; then
    log_info "imapsync installed: $(imapsync --version 2>/dev/null | head -n1 || echo present)"
else
    log_warn "imapsync not available via apt on this distro."
    log_warn "Mail migration needs it — install manually: https://imapsync.lamiral.info/"
fi
# Log directory the Panel migration runner writes per-job logs into.
mkdir -p /var/log/imapsync && chmod 755 /var/log/imapsync

# ============================================
# Create mail user and directories
# ============================================
# Mail lives under /home/vmail/<domain>/<user> - the layout the panel agent,
# webmail Sieve sync and Dovecot config (mail_location) all expect.
log_info "Creating mail user and directories..."
groupadd -g 5000 vmail 2>/dev/null || true
useradd -g vmail -u 5000 vmail -d /home/vmail -s /usr/sbin/nologin 2>/dev/null || true
mkdir -p /home/vmail/${MAIL_DOMAIN#mail.}
# Global after-script referenced by dovecot.conf (sieve_after); empty is valid.
touch /home/vmail/global.sieve
chown -R vmail:vmail /home/vmail

# ============================================
# Setup OpenDKIM
# ============================================
log_info "Setting up OpenDKIM..."
mkdir -p /etc/opendkim/keys/${MAIL_DOMAIN}

# Generate DKIM keys
log_info "Generating DKIM keys for ${MAIL_DOMAIN}..."
opendkim-genkey -s mail -d ${MAIL_DOMAIN} -D /etc/opendkim/keys/${MAIL_DOMAIN}/ -b 2048
chown -R opendkim:opendkim /etc/opendkim
chmod 600 /etc/opendkim/keys/${MAIL_DOMAIN}/mail.private

# Create OpenDKIM config files
cat > /etc/opendkim.conf << DKIMEOF
AutoRestart             Yes
AutoRestartRate         10/1h
UMask                   002
Syslog                  yes
SyslogSuccess           Yes
LogWhy                  Yes
Canonicalization        relaxed/simple
ExternalIgnoreList      refile:/etc/opendkim/TrustedHosts
InternalHosts           refile:/etc/opendkim/TrustedHosts
KeyTable                refile:/etc/opendkim/KeyTable
SigningTable             refile:/etc/opendkim/SigningTable
Mode                    sv
PidFile                 /var/run/opendkim/opendkim.pid
SignatureAlgorithm      rsa-sha256
UserID                  opendkim:opendkim
Socket                  inet:12301@localhost
DKIMEOF

cat > /etc/opendkim/TrustedHosts << THEOF
127.0.0.1
localhost
${MAIL_DOMAIN}
*.${MAIL_DOMAIN}
THEOF

cat > /etc/opendkim/KeyTable << KTEOF
mail._domainkey.${MAIL_DOMAIN} ${MAIL_DOMAIN}:mail:/etc/opendkim/keys/${MAIL_DOMAIN}/mail.private
KTEOF

cat > /etc/opendkim/SigningTable << STEOF
*@${MAIL_DOMAIN} mail._domainkey.${MAIL_DOMAIN}
STEOF

# ============================================
# Setup OpenDMARC
# ============================================
log_info "Setting up OpenDMARC..."
mkdir -p /var/run/opendmarc

cat > /etc/opendmarc.conf << DMARCEOF
AuthservID              ${MAIL_DOMAIN}
TrustedAuthservIDs      ${MAIL_DOMAIN}
RejectFailures          false
IgnoreMailFrom          ${MAIL_DOMAIN}
IgnoreAuthenticatedClients true
RequiredHeaders         true
SPFSelfValidate         true
Socket                  inet:54321@localhost
UMask                   0002
UserID                  opendmarc:opendmarc
PidFile                 /var/run/opendmarc/opendmarc.pid
DMARCEOF

# ============================================
# Enable services
# ============================================
log_info "Enabling services..."
systemctl enable postfix
systemctl enable dovecot
systemctl enable opendkim
systemctl enable opendmarc

# Start DKIM/DMARC services
systemctl restart opendkim 2>/dev/null || true
systemctl restart opendmarc 2>/dev/null || true

# ============================================
# Print DKIM DNS record
# ============================================
echo ""
log_info "Mail server installation complete!"
echo ""
echo "========================================="
echo "  DKIM DNS Record"
echo "========================================="
echo ""
if [ -f /etc/opendkim/keys/${MAIL_DOMAIN}/mail.txt ]; then
    echo "Add this TXT record to your DNS:"
    cat /etc/opendkim/keys/${MAIL_DOMAIN}/mail.txt
fi
echo ""
echo "========================================="
echo "  Additional DNS Records Required"
echo "========================================="
echo ""
echo "  MX Record:     ${MAIL_DOMAIN} -> ${HOSTNAME} (priority 10)"
echo "  SPF Record:    v=spf1 mx a ip4:<YOUR_SERVER_IP> ~all"
echo "  DMARC Record:  v=DMARC1; p=quarantine; rua=mailto:postmaster@${MAIL_DOMAIN}"
echo ""
echo "========================================="
echo "  Config Notes"
echo "========================================="
echo ""
echo "  Postfix/Dovecot configs need to be deployed from Fleet Manager templates."
echo "  DKIM key generated at: /etc/opendkim/keys/${MAIL_DOMAIN}/mail.private"
echo ""
