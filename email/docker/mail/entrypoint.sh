#!/usr/bin/env bash
# FlowOne mail pod entrypoint. Renders the native Fleet mail templates from env,
# provisions the vmail store + DKIM key + TLS cert, wires the Rspamd milter
# (native panel-agent parity), then hands off to supervisord for the full stack.
# Idempotent: safe to re-run (volumes persist mail, DKIM key and certs).
set -euo pipefail

log()  { echo "[mail-entrypoint] $*"; }
die()  { echo "[mail-entrypoint][FATAL] $*" >&2; exit 1; }

# --- required + defaulted env ---------------------------------------------
: "${MAIL_DOMAIN:?MAIL_DOMAIN required (e.g. mail.example.com)}"
: "${MAIL_DB_PASS:?MAIL_DB_PASS required}"
SERVER_FQDN="${SERVER_FQDN:-$MAIL_DOMAIN}"
SERVER_IP="${SERVER_IP:-127.0.0.1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-postmaster@${MAIL_DOMAIN}}"
# The TLS cert name = the server's own FQDN by default (that's the hostname MUAs
# connect to for IMAP/SMTP and must match the cert). Overridable if the cert is
# issued under a different name.
TLS_CERT_NAME="${MAIL_TLS_NAME:-$SERVER_FQDN}"
MAIL_DB_HOST="${MAIL_DB_HOST:-127.0.0.1}"
MAIL_DB_PORT="${MAIL_DB_PORT:-3306}"
MAIL_DB_NAME="${MAIL_DB_NAME:-mailserver}"
MAIL_DB_USER="${MAIL_DB_USER:-mailuser}"

# Optional (heavy) security services. Default ON for production parity; set to 0
# on RAM-constrained boxes (ClamAV alone loads ~1.2GB). Disabling them is safe:
# milter_default_action=accept means mail still flows, and DKIM/DMARC/Rspamd —
# which do the signing/auth we actually test — stay enabled independently.
ENABLE_CLAMAV="${MAIL_ENABLE_CLAMAV:-1}"
ENABLE_SPAMASSASSIN="${MAIL_ENABLE_SPAMASSASSIN:-1}"
ENABLE_RSPAMD="${MAIL_ENABLE_RSPAMD:-1}"

TPL=/opt/mail-templates

render() {  # render <template> <dest>
    sed -e "s|{{SERVER_FQDN}}|${SERVER_FQDN}|g" \
        -e "s|{{MAIL_DOMAIN}}|${MAIL_DOMAIN}|g" \
        -e "s|{{TLS_CERT_NAME}}|${TLS_CERT_NAME}|g" \
        -e "s|{{SERVER_IP}}|${SERVER_IP}|g" \
        -e "s|{{ADMIN_EMAIL}}|${ADMIN_EMAIL}|g" \
        -e "s|{{MAIL_DB_HOST}}|${MAIL_DB_HOST}|g" \
        -e "s|{{MAIL_DB_PORT}}|${MAIL_DB_PORT}|g" \
        -e "s|{{MAIL_DB_NAME}}|${MAIL_DB_NAME}|g" \
        -e "s|{{MAIL_DB_USER}}|${MAIL_DB_USER}|g" \
        -e "s|{{MAIL_DB_PASS}}|${MAIL_DB_PASS}|g" \
        "$1" > "$2"
}

log "Configuring mail pod: MAIL_DOMAIN=${MAIL_DOMAIN} FQDN=${SERVER_FQDN} DB=${MAIL_DB_USER}@${MAIL_DB_HOST}:${MAIL_DB_PORT}/${MAIL_DB_NAME}"

# --- vmail user + storage --------------------------------------------------
getent group vmail  >/dev/null 2>&1 || groupadd -g 5000 vmail
getent passwd vmail >/dev/null 2>&1 || useradd -u 5000 -g 5000 -d /home/vmail -s /usr/sbin/nologin vmail
mkdir -p /home/vmail
[ -f /home/vmail/global.sieve ] || : > /home/vmail/global.sieve
chown -R vmail:vmail /home/vmail
chmod 2775 /home/vmail

echo "${MAIL_DOMAIN}" > /etc/mailname

# --- render configs --------------------------------------------------------
mkdir -p /etc/postfix /etc/dovecot /etc/opendkim
render "$TPL/postfix/main.cf.template"                     /etc/postfix/main.cf
render "$TPL/postfix/master.cf.template"                   /etc/postfix/master.cf
render "$TPL/postfix/mysql-virtual-domains.cf.template"    /etc/postfix/mysql-virtual-domains.cf
render "$TPL/postfix/mysql-virtual-mailboxes.cf.template"  /etc/postfix/mysql-virtual-mailboxes.cf
render "$TPL/postfix/mysql-virtual-aliases.cf.template"    /etc/postfix/mysql-virtual-aliases.cf
render "$TPL/dovecot/dovecot.conf.template"                /etc/dovecot/dovecot.conf
render "$TPL/dovecot/dovecot-sql.conf.ext.template"        /etc/dovecot/dovecot-sql.conf.ext
render "$TPL/opendkim/opendkim.conf.template"              /etc/opendkim.conf
render "$TPL/opendkim/KeyTable.template"                   /etc/opendkim/KeyTable
render "$TPL/opendkim/SigningTable.template"               /etc/opendkim/SigningTable
render "$TPL/opendkim/TrustedHosts.template"               /etc/opendkim/TrustedHosts
render "$TPL/opendmarc/opendmarc.conf.template"            /etc/opendmarc.conf
render "$TPL/spamassassin/local.cf.template"               /etc/spamassassin/local.cf

# Lock down secret-bearing maps (they carry MAIL_DB_PASS).
chown root:postfix /etc/postfix/mysql-virtual-*.cf   && chmod 640 /etc/postfix/mysql-virtual-*.cf
chown root:dovecot /etc/dovecot/dovecot-sql.conf.ext && chmod 640 /etc/dovecot/dovecot-sql.conf.ext

# --- runtime dirs / sockets ------------------------------------------------
mkdir -p /var/run/opendkim  && chown opendkim:opendkim   /var/run/opendkim
mkdir -p /var/run/opendmarc && chown opendmarc:opendmarc /var/run/opendmarc
mkdir -p /var/run/dovecot
mkdir -p /var/run/clamav    && chown clamav:clamav       /var/run/clamav  2>/dev/null || true
mkdir -p /var/lib/clamav    && chown clamav:clamav       /var/lib/clamav  2>/dev/null || true
mkdir -p /var/spool/postfix/spamass
mkdir -p /var/lib/redis-rspamd && chown redis:redis      /var/lib/redis-rspamd 2>/dev/null || true
mkdir -p /var/lib/spamassassin

# --- DKIM key (persisted; DNS record must stay stable) ---------------------
KEYDIR="/etc/opendkim/keys/${MAIL_DOMAIN}"
if [ ! -s "${KEYDIR}/mail.private" ]; then
    log "Generating 2048-bit DKIM key for ${MAIL_DOMAIN} (selector: mail)..."
    mkdir -p "$KEYDIR"
    opendkim-genkey -b 2048 -d "${MAIL_DOMAIN}" -D "$KEYDIR" -s mail -v || die "opendkim-genkey failed"
fi
chown -R opendkim:opendkim /etc/opendkim/keys
chmod 600 "${KEYDIR}/mail.private"
log "==================== DKIM DNS record (add as TXT) ===================="
log "Name: mail._domainkey.${MAIL_DOMAIN}"
sed -n 's/.*(\s*//; s/\s*).*//; p' "${KEYDIR}/mail.txt" 2>/dev/null | tr -d '"\t ' | tr -d '\n'; echo ""
cat "${KEYDIR}/mail.txt" 2>/dev/null || true
log "======================================================================"

# --- TLS cert: real host cert if mounted, else self-signed fallback --------
# The pod keeps its cert in a WRITABLE volume at /etc/letsencrypt. If the host's
# certbot tree is bind-mounted read-only at /etc/letsencrypt-host, copy the real
# cert for TLS_CERT_NAME in (so certbot on the host is the source of truth; a
# `docker compose restart mail` after renewal re-copies it). Otherwise fall back
# to a self-signed cert so the pod still serves TLS on a box without a real cert.
LE_DIR="/etc/letsencrypt/live/${TLS_CERT_NAME}"
HOST_LE="/etc/letsencrypt-host/live/${TLS_CERT_NAME}"
if [ -s "${HOST_LE}/fullchain.pem" ] && [ -s "${HOST_LE}/privkey.pem" ]; then
    log "Using host LE cert for ${TLS_CERT_NAME} (from ${HOST_LE})."
    mkdir -p "$LE_DIR"
    # -L follows the archive/ symlinks certbot uses, copying the real files.
    cp -Lf "${HOST_LE}/fullchain.pem" "${LE_DIR}/fullchain.pem"
    cp -Lf "${HOST_LE}/privkey.pem"   "${LE_DIR}/privkey.pem"
fi
if [ ! -s "${LE_DIR}/fullchain.pem" ] || [ ! -s "${LE_DIR}/privkey.pem" ]; then
    log "No cert at ${LE_DIR}; generating self-signed fallback (replace with LE later)."
    mkdir -p "$LE_DIR"
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
        -keyout "${LE_DIR}/privkey.pem" -out "${LE_DIR}/fullchain.pem" \
        -subj "/CN=${TLS_CERT_NAME}" >/dev/null 2>&1 || die "self-signed cert generation failed"
fi

# --- Unbound DNSSEC trust anchor -------------------------------------------
# Unbound's default config validates with an auto-trust-anchor-file
# (/var/lib/unbound/root.key). In a fresh container that file is absent, so
# unbound aborts ("failed to setup modules") and supervisor crash-loops it,
# breaking Rspamd's DNS (RBL/SPF/DKIM-verify). Seed it from the builtin root
# anchor (unbound-anchor exits 1 on the initial bootstrap but still writes the
# file, hence the `|| true`).
if [ ! -s /var/lib/unbound/root.key ]; then
    log "Seeding Unbound DNSSEC root trust anchor..."
    mkdir -p /var/lib/unbound
    unbound-anchor -a /var/lib/unbound/root.key || true
fi
chown -R unbound:unbound /var/lib/unbound 2>/dev/null || true

# --- wait (best-effort) for the mail DB ------------------------------------
log "Waiting up to 60s for DB ${MAIL_DB_HOST}:${MAIL_DB_PORT}..."
for _ in $(seq 1 60); do
    if (exec 3<>"/dev/tcp/${MAIL_DB_HOST}/${MAIL_DB_PORT}") 2>/dev/null; then
        exec 3>&- 2>/dev/null || true; log "DB reachable."; break
    fi
    sleep 1
done

# --- Postfix: docker logging + milter chain (respecting the enable toggles) --
# Rebuild smtpd_milters from scratch so disabled services are never referenced
# (a dangling milter socket only "works" because of milter_default_action=accept,
# but it spams the log with connect failures — cleaner to omit it entirely).
# Order mirrors the native chain: SpamAssassin -> OpenDKIM(8891) -> OpenDMARC(8893)
# -> Rspamd(11332). OpenDKIM/OpenDMARC are always on (they do the signing + auth
# results this pod is actually validated on).
postconf -e "maillog_file=/dev/stdout"
smtpd_milters=""
non_smtpd_milters=""
if [ "$ENABLE_SPAMASSASSIN" = "1" ]; then
    smtpd_milters="${smtpd_milters} unix:/var/spool/postfix/spamass/spamass.sock"
    non_smtpd_milters="${non_smtpd_milters} unix:/var/spool/postfix/spamass/spamass.sock"
fi
smtpd_milters="${smtpd_milters} inet:127.0.0.1:8891 inet:127.0.0.1:8893"
non_smtpd_milters="${non_smtpd_milters} inet:127.0.0.1:8891"
if [ "$ENABLE_RSPAMD" = "1" ]; then
    smtpd_milters="${smtpd_milters} inet:localhost:11332"
fi
postconf -e "smtpd_milters=$(echo "$smtpd_milters" | sed 's/^ *//')"
postconf -e "non_smtpd_milters=$(echo "$non_smtpd_milters" | sed 's/^ *//')"
newaliases 2>/dev/null || true
postfix set-permissions 2>/dev/null || true

# --- Build the runtime supervisord config, dropping disabled program blocks ---
DROP=""
[ "$ENABLE_CLAMAV" = "1" ]       || DROP="${DROP} clamd freshclam"
[ "$ENABLE_SPAMASSASSIN" = "1" ] || DROP="${DROP} spamd spamass-milter"
[ "$ENABLE_RSPAMD" = "1" ]       || DROP="${DROP} rspamd redis-rspamd"
RUNTIME_SUP=/run/supervisor-mail.conf
awk -v drop="$DROP" '
    BEGIN { n = split(drop, d, " ") }
    /^\[program:/ {
        name = $0; sub(/^\[program:/, "", name); sub(/\].*/, "", name)
        skip = 0
        for (i = 1; i <= n; i++) if (d[i] == name) skip = 1
    }
    { if (!skip) print }
' /etc/supervisor/conf.d/mail.conf > "$RUNTIME_SUP"

log "Starting supervisord — always: postfix dovecot opendkim opendmarc unbound; toggles: rspamd=${ENABLE_RSPAMD} clamav=${ENABLE_CLAMAV} spamassassin=${ENABLE_SPAMASSASSIN}"
exec /usr/bin/supervisord -c "$RUNTIME_SUP"
