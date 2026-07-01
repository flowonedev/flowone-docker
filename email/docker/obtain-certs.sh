#!/usr/bin/env bash
# obtain-certs.sh — issue/expand ONE Let's Encrypt SAN cert covering every public
# hostname a FlowOne box serves (webmail email.<domain>, panel, fleet, www, and the
# mail FQDN). All names MUST already resolve to this box (validation is HTTP-01).
#
# The web container publishes :80, so certbot standalone can't bind it while web
# runs — we briefly stop web, run certbot in a throwaway certbot/certbot container,
# then restart web no matter what (trap). Certs land on the HOST /etc/letsencrypt,
# which both the web container (:ro) and the mail pod (/etc/letsencrypt-host:ro)
# already mount, so a `docker compose up -d web` + `restart mail` picks them up.
#
# We keep a single lineage (--cert-name, default = first domain) so the SAN cert
# has ONE path; point SSL_CERT_FILE (web) + TLS_CERT_NAME (mail) at that one name.
#
# Usage:
#   obtain-certs.sh --email=admin@example.com [--cert-name=vps.example.com] \
#       vps.example.com email.example.com panel.example.com fleet.example.com www.example.com
#   obtain-certs.sh --help
set -uo pipefail

ACME_EMAIL=""
CERT_NAME=""
WEB_CONTAINER="${WEB_CONTAINER:-flowone-web-1}"
DOMAINS=()

usage() { sed -n '2,20p' "$0"; exit "${1:-0}"; }

for arg in "$@"; do
    case "$arg" in
        --help|-h)       usage 0 ;;
        --email=*)       ACME_EMAIL="${arg#*=}" ;;
        --cert-name=*)   CERT_NAME="${arg#*=}" ;;
        --web-container=*) WEB_CONTAINER="${arg#*=}" ;;
        --*)             echo "unknown flag: $arg" >&2; usage 1 ;;
        *)               DOMAINS+=("$arg") ;;
    esac
done

[ "${#DOMAINS[@]}" -ge 1 ] || { echo "at least one domain is required" >&2; usage 1; }
[ -n "$ACME_EMAIL" ] || { echo "--email=<addr> is required (LE account + expiry notices)" >&2; usage 1; }
[ -n "$CERT_NAME" ] || CERT_NAME="${DOMAINS[0]}"

log(){ printf '\033[0;36m==>\033[0m %s\n' "$*"; }

# Build the -d flags.
D_ARGS=(); for d in "${DOMAINS[@]}"; do D_ARGS+=(-d "$d"); done

web_was_running=0
if docker ps --format '{{.Names}}' | grep -qx "$WEB_CONTAINER"; then web_was_running=1; fi
restore_web(){ [ "$web_was_running" = 1 ] && { log "Restarting ${WEB_CONTAINER}..."; docker start "$WEB_CONTAINER" >/dev/null 2>&1 || true; }; }
trap restore_web EXIT

if [ "$web_was_running" = 1 ]; then log "Stopping ${WEB_CONTAINER} to free port 80..."; docker stop "$WEB_CONTAINER" >/dev/null; fi

log "Requesting cert '${CERT_NAME}' for: ${DOMAINS[*]}"
docker run --rm -p 80:80 \
    -v /etc/letsencrypt:/etc/letsencrypt \
    -v /var/lib/letsencrypt:/var/lib/letsencrypt \
    certbot/certbot certonly --standalone \
    --cert-name "$CERT_NAME" "${D_ARGS[@]}" \
    --non-interactive --agree-tos --no-eff-email -m "$ACME_EMAIL" \
    --expand --keep-until-expiring
rc=$?

if [ "$rc" -eq 0 ] && [ -s "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]; then
    log "Cert ready at /etc/letsencrypt/live/${CERT_NAME}/ — SANs:"
    openssl x509 -in "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" -noout -text \
        | sed -n '/Subject Alternative Name/{n;p}' | tr -d ' '
    echo "CERT_OK ${CERT_NAME}"
else
    echo "CERT_FAIL rc=${rc}" >&2
    exit 1
fi
