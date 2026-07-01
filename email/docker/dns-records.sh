#!/usr/bin/env bash
# dns-records.sh — print every DNS record the mail domain needs for deliverability
# (MX, SPF, DKIM, DMARC) plus the PTR/client-config that must be set out-of-band.
# DKIM is read LIVE from the running mail pod's OpenDKIM key, so the value always
# matches what the server actually signs with.
#
# Usage: dns-records.sh [--stack-dir=/opt/flowone] [--mail-container=flowone-mail-1]
set -uo pipefail

STACK_DIR="${STACK_DIR:-/opt/flowone}"; MAIL_CONTAINER="${MAIL_CONTAINER:-flowone-mail-1}"
for a in "$@"; do case "$a" in
    --help|-h) sed -n '2,9p' "$0"; exit 0 ;;
    --stack-dir=*) STACK_DIR="${a#*=}" ;;
    --mail-container=*) MAIL_CONTAINER="${a#*=}" ;;
    *) echo "unknown arg: $a" >&2; exit 1 ;;
esac; done

ENVF="${STACK_DIR}/.env"
[ -f "$ENVF" ] || { echo "no .env at ${ENVF}" >&2; exit 1; }
val(){ grep -E "^$1=" "$ENVF" | cut -d= -f2-; }
DOMAIN="$(val MAIL_DOMAIN)"; FQDN="$(val SERVER_FQDN)"; IP="$(val SERVER_IP)"
[ -n "$DOMAIN" ] || { echo "MAIL_DOMAIN missing in .env" >&2; exit 1; }
FQDN="${FQDN:-$DOMAIN}"

# DKIM value straight from the pod (selector 'mail'), all whitespace/quotes stripped
# so the base64 p= is contiguous (valid regardless of provider chunking).
DKIM_RAW="$(docker exec "$MAIL_CONTAINER" cat "/etc/opendkim/keys/${DOMAIN}/mail.txt" 2>/dev/null || true)"
DKIM_VAL="$(printf '%s' "$DKIM_RAW" | tr -d '\n' | sed -e 's/.*(\s*//' -e 's/\s*).*//' | tr -d '"\t ')"

echo    "==================== DNS records for ${DOMAIN} ===================="
echo    "(host/name shown relative; use the fully-qualified form your panel expects)"
echo
echo    "# 1) MX  — routes inbound mail to this box"
printf  "   %-28s %-6s %s\n" "${DOMAIN}." "MX" "10 ${FQDN}."
echo
echo    "# 2) A   — the mail host (should already exist)"
printf  "   %-28s %-6s %s\n" "${FQDN}." "A" "${IP}"
echo
echo    "# 3) SPF — authorize this box to send for the domain (TXT on the apex)"
printf  "   %-28s %-6s %s\n" "${DOMAIN}." "TXT" "\"v=spf1 a mx ip4:${IP} ~all\""
echo
echo    "# 4) DKIM — public key for selector 'mail' (TXT). If your panel limits a"
echo    "#    TXT value to 255 chars, split into 255-char quoted chunks; most auto-split."
printf  "   %-28s %-6s\n" "mail._domainkey.${DOMAIN}." "TXT"
if [ -n "$DKIM_VAL" ]; then echo "   \"${DKIM_VAL}\""; else echo "   (!! could not read DKIM key from ${MAIL_CONTAINER})"; fi
echo
echo    "# 5) DMARC — policy + aggregate reports (TXT)"
printf  "   %-28s %-6s %s\n" "_dmarc.${DOMAIN}." "TXT" "\"v=DMARC1; p=quarantine; rua=mailto:postmaster@${DOMAIN}; ruf=mailto:postmaster@${DOMAIN}; fo=1; adkim=s; aspf=s\""
echo
echo    "# 6) PTR (reverse DNS) — set at your VPS/IP provider, NOT in this zone:"
echo    "       ${IP}  ->  ${FQDN}"
echo
echo    "# Mail-client settings for this domain:"
echo    "   IMAP: ${FQDN}  port 993  SSL/TLS"
echo    "   SMTP: ${FQDN}  port 587  STARTTLS  (auth = full email + password)"
echo    "===================================================================="
