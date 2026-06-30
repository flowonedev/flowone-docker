#!/bin/bash
# =============================================================================
# FlowOne - OnlyOffice Document Server installer
# =============================================================================
# Builds the whitelabeled flowone-onlyoffice image, runs the container with
# HTTPS (reusing the host's Let's Encrypt cert), and writes the backend
# integration config (backend/storage/office-config.json).
#
# Run on the server from this directory:
#   cd /var/www/vps-email/office
#   bash install-onlyoffice.sh
#
# Options:
#   --domain=flowone.pro          Public domain (default: flowone.pro)
#   --https-port=8443             Public HTTPS port for the Document Server
#   --tag=9.4.0                   onlyoffice/documentserver image tag
#   --backend=/var/www/vps-email/backend   Backend path (for office-config.json)
#   --cert-dir=/etc/letsencrypt/live/DOMAIN  Cert dir (fullchain.pem/privkey.pem)
#   --secret=...                  JWT secret (default: generated, reused on re-run)
#   --refresh-certs               Only re-copy SSL certs + restart container
#   --skip-build                  Use existing flowone-onlyoffice image
#   --help                        Show this help
#
# Re-running is safe (idempotent): the container is recreated, the existing
# JWT secret is reused so already-issued links keep working.
# =============================================================================

set -euo pipefail

DOMAIN="flowone.pro"
HTTPS_PORT=8443
HTTP_PORT=8090
TAG="9.4.0"
BACKEND="/var/www/vps-email/backend"
CERT_DIR=""
SECRET=""
REFRESH_CERTS=0
SKIP_BUILD=0
DATA_ROOT="/var/www/onlyoffice-data"
CONTAINER="flowone-office"
IMAGE="flowone-onlyoffice"

for arg in "$@"; do
    case "$arg" in
        --domain=*) DOMAIN="${arg#*=}" ;;
        --https-port=*) HTTPS_PORT="${arg#*=}" ;;
        --tag=*) TAG="${arg#*=}" ;;
        --backend=*) BACKEND="${arg#*=}" ;;
        --cert-dir=*) CERT_DIR="${arg#*=}" ;;
        --secret=*) SECRET="${arg#*=}" ;;
        --refresh-certs) REFRESH_CERTS=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
        --help)
            grep '^#' "$0" | head -30 | sed 's/^# \?//'
            exit 0
            ;;
        *) echo "Unknown option: $arg (see --help)"; exit 1 ;;
    esac
done

[ -z "$CERT_DIR" ] && CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_JSON="${BACKEND}/storage/office-config.json"

log()  { echo -e "\033[0;32m[office]\033[0m $1"; }
warn() { echo -e "\033[1;33m[office]\033[0m $1"; }
fail() { echo -e "\033[0;31m[office]\033[0m $1"; exit 1; }

# -----------------------------------------------------------------------------
# Pre-flight
# -----------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker info >/dev/null 2>&1 || fail "Docker daemon is not running (or no permission)"
[ -d "$BACKEND" ] || fail "Backend path not found: $BACKEND"
[ -f "${CERT_DIR}/fullchain.pem" ] || fail "SSL cert not found: ${CERT_DIR}/fullchain.pem"
[ -f "${CERT_DIR}/privkey.pem" ] || fail "SSL key not found: ${CERT_DIR}/privkey.pem"

mkdir -p "${DATA_ROOT}/logs" "${DATA_ROOT}/data/certs" "${DATA_ROOT}/lib" "${DATA_ROOT}/db"

copy_certs() {
    cp -L "${CERT_DIR}/fullchain.pem" "${DATA_ROOT}/data/certs/onlyoffice.crt"
    cp -L "${CERT_DIR}/privkey.pem"   "${DATA_ROOT}/data/certs/onlyoffice.key"
    chmod 400 "${DATA_ROOT}/data/certs/onlyoffice.key"
    log "SSL certs copied from ${CERT_DIR}"
}

if [ "$REFRESH_CERTS" = "1" ]; then
    copy_certs
    docker restart "$CONTAINER" >/dev/null
    log "Container restarted with refreshed certs. Done."
    exit 0
fi

# -----------------------------------------------------------------------------
# JWT secret: reuse from existing office-config.json unless explicitly given
# -----------------------------------------------------------------------------
if [ -z "$SECRET" ] && [ -f "$CONFIG_JSON" ]; then
    SECRET=$(php -r '$c=json_decode(file_get_contents($argv[1]),true); echo $c["jwt_secret"]??"";' "$CONFIG_JSON" 2>/dev/null || true)
    [ -n "$SECRET" ] && log "Reusing existing JWT secret from office-config.json"
fi
if [ -z "$SECRET" ]; then
    SECRET=$(openssl rand -hex 32)
    log "Generated new JWT secret"
fi

# -----------------------------------------------------------------------------
# Build whitelabeled image
# -----------------------------------------------------------------------------
if [ "$SKIP_BUILD" = "1" ]; then
    log "Skipping image build (--skip-build)"
else
    log "Building ${IMAGE}:${TAG} (base onlyoffice/documentserver:${TAG})..."
    docker build --build-arg "ONLYOFFICE_TAG=${TAG}" -t "${IMAGE}:${TAG}" "$SCRIPT_DIR"
fi

# -----------------------------------------------------------------------------
# (Re)create container
# -----------------------------------------------------------------------------
copy_certs

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    log "Removing existing container ${CONTAINER}..."
    docker rm -f "$CONTAINER" >/dev/null
fi

log "Starting ${CONTAINER} (HTTPS :${HTTPS_PORT}, internal HTTP 127.0.0.1:${HTTP_PORT})..."
docker run -d --name "$CONTAINER" --restart=always \
    -p "127.0.0.1:${HTTP_PORT}:80" \
    -p "${HTTPS_PORT}:443" \
    -e JWT_ENABLED=true \
    -e "JWT_SECRET=${SECRET}" \
    -e JWT_HEADER=Authorization \
    -v "${DATA_ROOT}/logs:/var/log/onlyoffice" \
    -v "${DATA_ROOT}/data:/var/www/onlyoffice/Data" \
    -v "${DATA_ROOT}/lib:/var/lib/onlyoffice" \
    -v "${DATA_ROOT}/db:/var/lib/postgresql" \
    "${IMAGE}:${TAG}" >/dev/null

# -----------------------------------------------------------------------------
# Firewall
# -----------------------------------------------------------------------------
if command -v firewall-cmd >/dev/null 2>&1; then
    if ! firewall-cmd --list-ports 2>/dev/null | grep -q "${HTTPS_PORT}/tcp"; then
        firewall-cmd --permanent --add-port="${HTTPS_PORT}/tcp" >/dev/null && firewall-cmd --reload >/dev/null \
            && log "Opened firewall port ${HTTPS_PORT}/tcp" \
            || warn "Could not open firewall port ${HTTPS_PORT}/tcp - open it manually"
    fi
else
    warn "firewall-cmd not found - make sure port ${HTTPS_PORT}/tcp is reachable"
fi

# -----------------------------------------------------------------------------
# Health check (Document Server can take 1-2 minutes on first boot)
# -----------------------------------------------------------------------------
log "Waiting for Document Server to come up (max 240s)..."
HEALTH_OK=0
for i in $(seq 1 48); do
    if curl -fsS "http://127.0.0.1:${HTTP_PORT}/healthcheck" 2>/dev/null | grep -q "true"; then
        HEALTH_OK=1
        break
    fi
    sleep 5
done
[ "$HEALTH_OK" = "1" ] && log "Document Server is healthy" || warn "Healthcheck not green yet - check: docker logs ${CONTAINER}"

# -----------------------------------------------------------------------------
# Backend integration config
# -----------------------------------------------------------------------------
mkdir -p "${BACKEND}/storage"
cat > "$CONFIG_JSON" <<EOF
{
    "enabled": true,
    "server_url": "https://${DOMAIN}:${HTTPS_PORT}",
    "internal_url": "http://127.0.0.1:${HTTP_PORT}",
    "jwt_secret": "${SECRET}"
}
EOF
# Match ownership of the backend dir so PHP can read it
BACKEND_OWNER=$(stat -c '%U:%G' "$BACKEND" 2>/dev/null || echo "")
[ -n "$BACKEND_OWNER" ] && chown "$BACKEND_OWNER" "$CONFIG_JSON" || true
chmod 640 "$CONFIG_JSON"
log "Wrote ${CONFIG_JSON}"

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo ""
echo "============================================================"
echo "  OnlyOffice Document Server is installed"
echo "============================================================"
echo "  Editor URL:    https://${DOMAIN}:${HTTPS_PORT}"
echo "  Healthcheck:   https://${DOMAIN}:${HTTPS_PORT}/healthcheck"
echo "  Container:     ${CONTAINER} (image ${IMAGE}:${TAG})"
echo "  Backend cfg:   ${CONFIG_JSON}"
echo ""
echo "  NEXT STEPS:"
echo "  1. Run the DB migration (see deploy notes)"
echo "  2. Upload the new backend + frontend code, rebuild frontend"
echo "  3. Cert renewals: add a Let's Encrypt deploy hook that runs"
echo "     bash $SCRIPT_DIR/install-onlyoffice.sh --refresh-certs"
echo "============================================================"
