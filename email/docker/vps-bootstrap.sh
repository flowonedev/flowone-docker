#!/usr/bin/env bash
#
# vps-bootstrap.sh — stand up the FlowOne BRIDGE-NETWORK stack on a fresh Linux
# VPS for a manual dry-run (Phase E). This is the hand-run analog of Fleet's
# DockerProvisioningService: it installs Docker, logs in to GHCR, generates
# fresh per-host secrets + a .env, seeds the JWT key volume, pulls the published
# images and brings the stack up — then health-checks it.
#
# SCOPE: web + mariadb + redis + meilisearch + collab + mailsync only. The
# host-networking pods (mail, PowerDNS, coTURN/LiveKit, OnlyOffice) are NOT part
# of this compose yet — they are authored + validated in a later Phase E step.
#
# NOT FOR PRODUCTION DATA: this MINTS fresh secrets and a fresh JWT pair. Run it
# only on an empty box. A real migration seeds those from Fleet / the snapshot.
#
# Run ON THE TARGET VPS (as root), with docker-compose.yml next to this script:
#   scp -r email/docker/ root@VPS:/opt/flowone-src/
#   ssh root@VPS
#   cd /opt/flowone-src
#   GHCR_TOKEN=ghp_xxx ./vps-bootstrap.sh --ghcr-user=flowonedev --domain=stg.flowone.pro
#
# Options:
#   --domain=<host>        EMAIL_DOMAIN (default: this box's public IP)
#   --ssl                  render an HTTPS .env (expects certs under /etc/letsencrypt/live/<domain>)
#   --registry=<ref>       image registry/namespace (default: ghcr.io/flowonedev)
#   --tag=<tag>            image tag (default: latest)
#   --ghcr-user=<user>     GHCR username for `docker login` (token via GHCR_TOKEN env)
#   --stack-dir=<path>     where the stack lives on the box (default: /opt/flowone)
#   --compose=<path>       docker-compose.yml source (default: alongside this script)
#   --skip-docker-install  assume Docker Engine + compose plugin already present
#   --skip-login           assume already logged in to the registry (or images public)
#   --wait=<seconds>       health-wait timeout (default: 240)
#   --help
set -euo pipefail

# ---- defaults ----
DOMAIN=""
ENABLE_SSL=0
REGISTRY="${DOCKER_REGISTRY:-ghcr.io/flowonedev}"
TAG="${DOCKER_TAG:-latest}"
GHCR_USER=""
STACK_DIR="/opt/flowone"
COMPOSE_SRC=""
SKIP_DOCKER_INSTALL=0
SKIP_LOGIN=0
WAIT_TIMEOUT=240

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for arg in "$@"; do
    case "$arg" in
        --help|-h)
            awk 'NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"; exit 0 ;;
        --domain=*)            DOMAIN="${arg#*=}" ;;
        --ssl)                 ENABLE_SSL=1 ;;
        --registry=*)          REGISTRY="${arg#*=}" ;;
        --tag=*)               TAG="${arg#*=}" ;;
        --ghcr-user=*)         GHCR_USER="${arg#*=}" ;;
        --stack-dir=*)         STACK_DIR="${arg#*=}" ;;
        --compose=*)           COMPOSE_SRC="${arg#*=}" ;;
        --skip-docker-install) SKIP_DOCKER_INSTALL=1 ;;
        --skip-login)          SKIP_LOGIN=1 ;;
        --wait=*)              WAIT_TIMEOUT="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 1 ;;
    esac
done

log()  { printf '\033[0;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[0;32m[OK]\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m[WARN]\033[0m %s\n' "$*"; }
die()  { printf '\033[0;31m[FAIL]\033[0m %s\n' "$*" >&2; exit 1; }

# Portable random hex (no host openssl dependency): coreutils od + tr are always present.
rand_hex() { od -An -tx1 -N"${1:-32}" /dev/urandom | tr -d ' \n'; }

# ---- pre-flight ----
[ "$(id -u)" -eq 0 ] || die "Run as root (Docker install + /opt writes need it)."
[ "$(uname -s)" = "Linux" ] || die "This script targets Linux (host networking, Docker Engine)."

if [ -z "$COMPOSE_SRC" ]; then COMPOSE_SRC="${SCRIPT_DIR}/docker-compose.yml"; fi
[ -f "$COMPOSE_SRC" ] || die "docker-compose.yml not found at '$COMPOSE_SRC' (pass --compose=)."

if [ -z "$DOMAIN" ]; then
    DOMAIN="$(curl -fsS https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
    warn "No --domain given; using detected address '$DOMAIN'."
fi

SCHEME="http"; WS_SCHEME="ws"
if [ "$ENABLE_SSL" = "1" ]; then SCHEME="https"; WS_SCHEME="wss"; fi

# ---- 1. Docker Engine + compose plugin ----
if [ "$SKIP_DOCKER_INSTALL" = "0" ]; then
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        ok "Docker + compose plugin already present ($(docker --version | awk '{print $3}' | tr -d ,))."
    else
        log "Installing Docker Engine + compose plugin (get.docker.com)..."
        curl -fsSL https://get.docker.com | sh
        systemctl enable --now docker
        ok "Docker installed."
    fi
else
    command -v docker >/dev/null 2>&1 || die "--skip-docker-install set but docker not found."
fi
docker compose version >/dev/null 2>&1 || die "docker compose plugin missing."

# ---- 2. Registry login (private GHCR) ----
if [ "$SKIP_LOGIN" = "0" ]; then
    reg_host="${REGISTRY%%/*}"
    if [ -n "${GHCR_TOKEN:-}" ] && [ -n "$GHCR_USER" ]; then
        log "Logging in to ${reg_host} as ${GHCR_USER}..."
        echo "$GHCR_TOKEN" | docker login "$reg_host" -u "$GHCR_USER" --password-stdin
        ok "Registry login succeeded."
    else
        warn "No GHCR_TOKEN + --ghcr-user given; assuming already logged in or images are public."
    fi
fi

# ---- 3. Stack dir + compose file ----
log "Preparing stack dir: ${STACK_DIR}"
mkdir -p "$STACK_DIR"
cp "$COMPOSE_SRC" "${STACK_DIR}/docker-compose.yml"

# ---- 4. Generate .env (fresh secrets) — only if absent, so re-runs are stable ----
ENV_FILE="${STACK_DIR}/.env"
if [ -f "$ENV_FILE" ]; then
    warn ".env already exists at ${ENV_FILE} — leaving it (delete to regenerate)."
else
    log "Generating fresh .env (new secrets — dry-run box only)..."
    DB_PASS="$(rand_hex 24)"
    MAIL_DB_PASS="$(rand_hex 24)"
    MYSQL_ROOT_PASSWORD="$(rand_hex 24)"
    MEILI_MASTER_KEY="$(rand_hex 32)"
    IMAP_ENCRYPTION_KEY="$(rand_hex 32)"
    AI_ENCRYPTION_KEY="$(rand_hex 32)"
    SSO_SERVER_KEY="$(rand_hex 32)"
    cat > "$ENV_FILE" <<EOF
# GENERATED by vps-bootstrap.sh for a manual dry-run. Fresh secrets — NOT prod.
EMAIL_DOMAIN=${DOMAIN}
FRONTEND_URL=${SCHEME}://${DOMAIN}
API_URL=${SCHEME}://${DOMAIN}/api
APP_ENV=prod
APP_DEBUG=false
ENABLE_SSL=${ENABLE_SSL}
SSL_CERT_FILE=/etc/letsencrypt/live/${DOMAIN}/fullchain.pem
SSL_KEY_FILE=/etc/letsencrypt/live/${DOMAIN}/privkey.pem

DB_HOST=mariadb
DB_PORT=3306
DB_NAME=devc_vps_dash
DB_USER=vpsadmin
DB_PASS=${DB_PASS}

MAIL_DB_HOST=mariadb
MAIL_DB_NAME=mailserver
MAIL_DB_USER=mailuser
MAIL_DB_PASS=${MAIL_DB_PASS}

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

MEILI_HOST=http://meilisearch:7700
MEILI_MASTER_KEY=${MEILI_MASTER_KEY}
MEILI_SEARCH_KEY=${MEILI_MASTER_KEY}

JWT_ALGORITHM=RS256
JWT_PRIVATE_KEY_PATH=/etc/flowone/jwt/jwt-private.pem
JWT_PUBLIC_KEY_PATH=/etc/flowone/jwt/jwt-public.pem
IMAP_ENCRYPTION_KEY=${IMAP_ENCRYPTION_KEY}
AI_ENCRYPTION_KEY=${AI_ENCRYPTION_KEY}
OAUTH_KEYS=
OAUTH_CURRENT_VERSION=1
SSO_SERVER_KEY=${SSO_SERVER_KEY}

COLLAB_ADDR=collab:1234
MAILSYNC_ADDR=mailsync:1235
COLLAB_WS_URL=${WS_SCHEME}://${DOMAIN}/collab-ws

# Realtime/mail/calls pods are NOT in this compose yet (Phase E). Left blank so
# the app boots; features that need them stay dark until those pods are authored.
STUN_URL=
TURN_URL=
TURN_SECRET=
TURN_TTL=86400
LIVEKIT_API_KEY=
LIVEKIT_API_SECRET=
LIVEKIT_WS_URL=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@${DOMAIN}
IMAP_HOST=${DOMAIN}
IMAP_PORT=993
IMAP_TLS=true
IMAP_VERIFY_CERT=true
FCM_ENABLED=false
APNS_VOIP_ENABLED=false
PANEL_API_URL=
PANEL_API_KEY=

REGISTRY=${REGISTRY}
TAG=${TAG}

GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
MICROSOFT_OAUTH_CLIENT_ID=
MICROSOFT_OAUTH_CLIENT_SECRET=

MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
EOF
    chmod 600 "$ENV_FILE"
    ok "Wrote ${ENV_FILE} (chmod 600)."
fi

# ---- 5. Seed the JWT key volume (fresh RS256 pair) ----
JWT_VOLUME="flowone_jwt_keys"
log "Seeding JWT key volume '${JWT_VOLUME}' (idempotent)..."
docker volume create "$JWT_VOLUME" >/dev/null
docker run --rm -v "${JWT_VOLUME}":/jwt node:20-bookworm-slim bash -c '
    set -e
    if [ -s /jwt/jwt-private.pem ] && [ -s /jwt/jwt-public.pem ]; then
        echo "  keys already present — leaving as-is."; exit 0
    fi
    openssl genrsa -out /jwt/jwt-private.pem 2048
    openssl rsa -in /jwt/jwt-private.pem -pubout -out /jwt/jwt-public.pem
    chmod 600 /jwt/jwt-private.pem; chmod 644 /jwt/jwt-public.pem
    echo "  generated RS256 pair."
'
ok "JWT volume ready."

# ---- 6. Pull + up ----
cd "$STACK_DIR"
log "Pulling images (${REGISTRY}/flowone-*:${TAG})..."
docker compose pull web collab mailsync
log "Bringing the stack up..."
docker compose up -d

# ---- 7. Health wait ----
log "Waiting up to ${WAIT_TIMEOUT}s for the web service to become healthy..."
deadline=$(( $(date +%s) + WAIT_TIMEOUT ))
healthy=0
while [ "$(date +%s)" -lt "$deadline" ]; do
    state="$(docker inspect -f '{{.State.Health.Status}}' flowone-web-1 2>/dev/null || echo missing)"
    if [ "$state" = "healthy" ]; then healthy=1; break; fi
    if [ "$state" = "unhealthy" ]; then break; fi
    sleep 5
done

echo ""
docker compose ps
echo ""
if [ "$healthy" = "1" ]; then
    code="$(docker exec flowone-web-1 sh -lc "curl -s -o /dev/null -w '%{http_code}' http://localhost/api/auth/me" 2>/dev/null || echo 000)"
    ok "Stack healthy. GET /api/auth/me -> ${code} (401/200 = app booted + DB/Redis answered)."
    echo ""
    echo "Next: point DNS at this box and, for TLS, obtain certs then re-run with --ssl."
    echo "Browse: ${SCHEME}://${DOMAIN}/"
    exit 0
else
    die "Web service did not become healthy in ${WAIT_TIMEOUT}s. Inspect: docker compose -f ${STACK_DIR}/docker-compose.yml logs web"
fi
