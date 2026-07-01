#!/usr/bin/env bash
# create-mail-account.sh — provision a mailbox in the mailserver DB so a user can
# log in to the webmail app and send/receive. Webmail login is a live IMAP auth
# against the mail pod (AuthController::login -> ImapService::connect), so a
# mail_accounts row (bcrypt password) + an active mail_domains row is all that's
# needed — no app-DB user; the colleague record self-creates on first /auth/me.
#
# Password hashing uses the web container's PHP (password_hash BCRYPT = Dovecot
# BLF-CRYPT). Writes go through the mariadb container as root (mailuser is
# SELECT-only). Idempotent: re-running updates the password + reactivates.
#
# Usage:
#   create-mail-account.sh --email=demo@example.com [--password=secret] \
#       [--quota-mb=512] [--stack-dir=/opt/flowone]
#   (omit --password to auto-generate one; it is printed at the end)
set -uo pipefail

EMAIL=""; PASSWORD=""; QUOTA_MB=512; STACK_DIR="${STACK_DIR:-/opt/flowone}"
WEB_CONTAINER="${WEB_CONTAINER:-flowone-web-1}"; DB_CONTAINER="${DB_CONTAINER:-flowone-mariadb-1}"

usage(){ sed -n '2,18p' "$0"; exit "${1:-0}"; }
for a in "$@"; do case "$a" in
    --help|-h) usage 0 ;;
    --email=*) EMAIL="${a#*=}" ;;
    --password=*) PASSWORD="${a#*=}" ;;
    --quota-mb=*) QUOTA_MB="${a#*=}" ;;
    --stack-dir=*) STACK_DIR="${a#*=}" ;;
    --web-container=*) WEB_CONTAINER="${a#*=}" ;;
    --db-container=*) DB_CONTAINER="${a#*=}" ;;
    *) echo "unknown arg: $a" >&2; usage 1 ;;
esac; done

log(){ printf '\033[0;36m==>\033[0m %s\n' "$*"; }
[ -n "$EMAIL" ] || { echo "--email=<addr> required" >&2; usage 1; }
case "$EMAIL" in *@*.*) ;; *) echo "invalid email: $EMAIL" >&2; exit 1 ;; esac

ENVF="${STACK_DIR}/.env"
[ -f "$ENVF" ] || { echo "no .env at ${ENVF}" >&2; exit 1; }
ROOT="$(grep -E '^MYSQL_ROOT_PASSWORD=' "$ENVF" | cut -d= -f2-)"
DBNAME="$(grep -E '^MAIL_DB_NAME=' "$ENVF" | cut -d= -f2-)"; DBNAME="${DBNAME:-mailserver}"
[ -n "$ROOT" ] || { echo "MYSQL_ROOT_PASSWORD not found in ${ENVF}" >&2; exit 1; }

LOCAL="${EMAIL%@*}"; DOMAIN="${EMAIL#*@}"; MAILDIR="${DOMAIN}/${LOCAL}/"
[ -n "$PASSWORD" ] || { PASSWORD="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16)"; GENERATED=1; }

log "Hashing password (bcrypt) via ${WEB_CONTAINER}..."
HASH="$(docker exec -e _PW="$PASSWORD" "$WEB_CONTAINER" /usr/local/lsws/lsphp83/bin/php -r 'echo password_hash(getenv("_PW"), PASSWORD_BCRYPT);')"
case "$HASH" in \$2*) ;; *) echo "bcrypt hash generation failed: $HASH" >&2; exit 1 ;; esac

log "Upserting ${EMAIL} (domain=${DOMAIN}, quota=${QUOTA_MB}MB, maildir=${MAILDIR}) into ${DBNAME}..."
# Here-doc delimiter is UNQUOTED so bash expands our vars; the expanded bcrypt hash
# (contains $ but no quotes/backslashes) is inserted literally inside SQL quotes.
docker exec -i "$DB_CONTAINER" mysql -uroot -p"$ROOT" "$DBNAME" <<SQL
INSERT INTO mail_domains (domain, status, max_accounts, max_quota_mb)
  VALUES ('${DOMAIN}', 'active', 100, 5120)
  ON DUPLICATE KEY UPDATE status='active';
INSERT INTO mail_accounts (email, domain, username, password_hash, quota_mb, maildir_path, status, login_suspended)
  VALUES ('${EMAIL}', '${DOMAIN}', '${LOCAL}', '${HASH}', ${QUOTA_MB}, '${MAILDIR}', 'active', 0)
  ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), status='active', login_suspended=0, quota_mb=VALUES(quota_mb);
SQL
rc=$?
[ "$rc" -eq 0 ] || { echo "DB upsert failed (rc=$rc)" >&2; exit 1; }

echo
log "Mailbox ready."
echo "  email:    ${EMAIL}"
echo "  password: ${PASSWORD}${GENERATED:+   (auto-generated)}"
echo "  login at: your webmail URL (FRONTEND_URL) — use the full email + this password"
