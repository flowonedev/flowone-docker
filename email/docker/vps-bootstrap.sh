#!/usr/bin/env bash
#
# vps-bootstrap.sh — stand up the FlowOne BRIDGE-NETWORK stack on a fresh Linux
# VPS for a manual dry-run (Phase E). This is the hand-run analog of Fleet's
# DockerProvisioningService: it installs Docker, logs in to GHCR, generates
# fresh per-host secrets + a .env, seeds the JWT key volume, pulls the published
# images and brings the stack up — then health-checks it.
#
# SCOPE: web + mariadb + redis + meilisearch + collab + mailsync + the full
# host-networked MAIL pod (Postfix/Dovecot/OpenDKIM/OpenDMARC/Rspamd/ClamAV/
# SpamAssassin/Unbound). Still NOT included: PowerDNS, coTURN/LiveKit, OnlyOffice.
#
# NOT FOR PRODUCTION DATA: this MINTS fresh secrets and a fresh JWT pair. Run it
# only on an empty box. A real migration seeds those from Fleet / the snapshot.
#
# Run ON THE TARGET VPS (as root), with the docker/ tree next to this script:
#   scp -r email/docker/ root@VPS:/opt/flowone-src/
#   ssh root@VPS
#   cd /opt/flowone-src
#   GHCR_TOKEN=ghp_xxx ./vps-bootstrap.sh --ghcr-user=flowonedev --domain=stg.flowone.pro \
#       --mail-domain=stg.flowone.pro
#
# Options:
#   --base-domain=<domain> registrable domain (e.g. example.com). Derives the
#                          standard FlowOne host layout: webmail=email.<d>,
#                          mail FQDN=vps.<d>, mail/DKIM domain=<d>, and a SAN cert
#                          over vps/email/panel/fleet/www.<d>. Individual overrides
#                          (--domain/--mail-domain/--cert-domains) still win.
#   --domain=<host>        EMAIL_DOMAIN = the webmail host (default: email.<base> or this box's IP)
#   --mail-domain=<domain> primary mail/DKIM domain (default: <base> or --domain). The mail
#                          pod signs *@<mail-domain> and serves it as a virtual domain.
#   --cert-domains=<csv>   SAN hostnames for the TLS cert (default: derived from --base-domain)
#   --ssl                  obtain a real LE SAN cert (certbot standalone) + render an HTTPS .env.
#                          Ordering is handled: the stack comes up on HTTP, the cert is issued,
#                          then SSL is flipped on and web/mail recreated.
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
BASE_DOMAIN=""
DOMAIN=""
MAIL_DOMAIN=""
CERT_DOMAINS=""
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
        --base-domain=*)       BASE_DOMAIN="${arg#*=}" ;;
        --domain=*)            DOMAIN="${arg#*=}" ;;
        --mail-domain=*)       MAIL_DOMAIN="${arg#*=}" ;;
        --cert-domains=*)      CERT_DOMAINS="${arg#*=}" ;;
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

# --base-domain expands to the standard FlowOne host layout (matches the DNS a
# real box gets: email/panel/fleet/www/vps all A-record the box). Explicit flags
# still override each derived value.
if [ -n "$BASE_DOMAIN" ]; then
    [ -n "$DOMAIN" ]       || DOMAIN="email.${BASE_DOMAIN}"       # webmail host
    [ -n "$MAIL_DOMAIN" ]  || MAIL_DOMAIN="$BASE_DOMAIN"          # mailboxes @<base>
    [ -n "$CERT_DOMAINS" ] || CERT_DOMAINS="vps.${BASE_DOMAIN},email.${BASE_DOMAIN},panel.${BASE_DOMAIN},fleet.${BASE_DOMAIN},www.${BASE_DOMAIN}"
    SERVER_FQDN="vps.${BASE_DOMAIN}"                              # mail HELO/myhostname + cert lineage
fi

if [ -z "$DOMAIN" ]; then
    DOMAIN="$(curl -fsS https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
    warn "No --domain/--base-domain given; using detected address '$DOMAIN'."
fi

# Primary mail/DKIM domain. Defaults to --domain; the mail pod signs *@MAIL_DOMAIN
# and serves it as a virtual domain, so the seeded test account must live here.
[ -n "$MAIL_DOMAIN" ] || MAIL_DOMAIN="$DOMAIN"
# The mail server's own hostname (HELO / myhostname). Derived from --base-domain
# above; otherwise mail.<domain> unless the domain is already an IP (no PTR story
# on a bare-IP dry-run box, so just reuse it).
if [ -z "${SERVER_FQDN:-}" ]; then
    if printf '%s' "$MAIL_DOMAIN" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
        SERVER_FQDN="$MAIL_DOMAIN"
    else
        SERVER_FQDN="mail.${MAIL_DOMAIN}"
    fi
fi
SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

# The TLS cert lives under ONE lineage = the mail FQDN, and the SAN list covers the
# webmail host + panel/fleet/www too. Keeping the lineage = SERVER_FQDN means the
# mail pod's default TLS_CERT_NAME (=SERVER_FQDN) resolves to the same cert, and the
# web tier serves email.<base> off it via SAN. Fall back to the single host if no
# SAN list was derived (bare --domain / IP case).
CERT_NAME="$SERVER_FQDN"
[ -n "$CERT_DOMAINS" ] || CERT_DOMAINS="$DOMAIN"
case ",${CERT_DOMAINS}," in *",${CERT_NAME},"*) ;; *) CERT_DOMAINS="${CERT_NAME},${CERT_DOMAINS}" ;; esac

# Auto-size the heavy mail services to the box. ClamAV alone resident-loads
# ~1.2GB; on anything under ~3GB total RAM it (and SpamAssassin) would OOM the
# stack, so default them OFF there. Override by exporting MAIL_ENABLE_* first.
_mem_mb="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)"
if [ "${_mem_mb:-0}" -lt 3000 ]; then _heavy_default=0; else _heavy_default=1; fi
MAIL_ENABLE_CLAMAV="${MAIL_ENABLE_CLAMAV:-$_heavy_default}"
MAIL_ENABLE_SPAMASSASSIN="${MAIL_ENABLE_SPAMASSASSIN:-$_heavy_default}"
if [ "$_heavy_default" = "0" ]; then
    warn "Detected ${_mem_mb}MB RAM (<3GB): defaulting ClamAV + SpamAssassin OFF (mail + DKIM still work)."
fi

SCHEME="http"; WS_SCHEME="ws"
if [ "$ENABLE_SSL" = "1" ]; then SCHEME="https"; WS_SCHEME="wss"; fi

# SSL ordering: the web container refuses to start with ENABLE_SSL=1 if the cert
# file is missing (OLS can't load it), but certbot's HTTP-01 needs the box up on
# :80 first — chicken/egg. So the INITIAL .env boots HTTP when the cert isn't there
# yet; step 8b obtains it and flips ENABLE_SSL on. If the cert already exists
# (re-run), start straight on HTTPS.
INITIAL_SSL="$ENABLE_SSL"
if [ "$ENABLE_SSL" = "1" ] && [ ! -s "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]; then
    INITIAL_SSL=0
fi

# Idempotent .env key setter (used by the post-up SSL flip; the initial file is
# written by the heredoc below only when absent).
set_env() { # set_env KEY VALUE FILE
    local k="$1" v="$2" f="$3"
    if grep -qE "^${k}=" "$f"; then sed -i "s|^${k}=.*|${k}=${v}|" "$f"; else echo "${k}=${v}" >> "$f"; fi
}

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

# The mariadb service bind-mounts ./mariadb-init as its docker-entrypoint-initdb.d
# (creates the mailserver DB + mailuser + schema on a FRESH volume). It MUST sit
# next to the compose file on the box, or the mail DB never gets provisioned.
COMPOSE_DIR="$(dirname "$COMPOSE_SRC")"
if [ -d "${COMPOSE_DIR}/mariadb-init" ]; then
    cp -r "${COMPOSE_DIR}/mariadb-init" "${STACK_DIR}/mariadb-init"
    ok "Copied mariadb-init/ (mail DB bootstrap) next to compose."
else
    warn "mariadb-init/ not found beside compose — mail DB won't be auto-provisioned."
fi

# Day-2 admin helpers live with the stack so operators can run them post-boot.
for _h in obtain-certs.sh create-mail-account.sh dns-records.sh; do
    [ -f "${COMPOSE_DIR}/${_h}" ] && install -m755 "${COMPOSE_DIR}/${_h}" "${STACK_DIR}/${_h}"
done

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
ENABLE_SSL=${INITIAL_SSL}
SSL_CERT_FILE=/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem
SSL_KEY_FILE=/etc/letsencrypt/live/${CERT_NAME}/privkey.pem

DB_HOST=mariadb
DB_PORT=3306
DB_NAME=devc_vps_dash
DB_USER=vpsadmin
DB_PASS=${DB_PASS}

# App-tier view of the mail DB (bridge: reaches mariadb by service name). The
# mail POD reaches the same DB via 127.0.0.1:3306 (set in compose, host net).
MAIL_DB_HOST=mariadb
MAIL_DB_PORT=3306
MAIL_DB_NAME=mailserver
MAIL_DB_USER=mailuser
MAIL_DB_PASS=${MAIL_DB_PASS}

# Mail pod identity (consumed by the mail service + its config templates).
MAIL_DOMAIN=${MAIL_DOMAIN}
SERVER_FQDN=${SERVER_FQDN}
SERVER_IP=${SERVER_IP}
ADMIN_EMAIL=postmaster@${MAIL_DOMAIN}

# Heavy security services. ClamAV needs ~1.2GB RAM; on a small dry-run box leave
# these OFF (mail still flows + DKIM/DMARC/Rspamd stay on). Flip to 1 in prod.
MAIL_ENABLE_CLAMAV=${MAIL_ENABLE_CLAMAV}
MAIL_ENABLE_SPAMASSASSIN=${MAIL_ENABLE_SPAMASSASSIN}
MAIL_ENABLE_RSPAMD=1

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
# The web tier reaches the host-net mail pod via the host gateway
# (host.docker.internal, mapped in compose). Self-signed cert on the dry-run box,
# so cert verification is off here; flip to true + a real name for production.
IMAP_HOST=host.docker.internal
IMAP_PORT=993
IMAP_TLS=true
IMAP_VERIFY_CERT=false
SMTP_HOST=host.docker.internal
SMTP_PORT=587
SIEVE_HOST=host.docker.internal
SIEVE_PORT=4190
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
# alpine + `apk add openssl`: tiny and reliably has the openssl CLI. (node:20
# -slim strips openssl, so it cannot mint the pair on a fresh box.)
docker run --rm -v "${JWT_VOLUME}":/jwt alpine:3 sh -c '
    set -e
    if [ -s /jwt/jwt-private.pem ] && [ -s /jwt/jwt-public.pem ]; then
        echo "  keys already present — leaving as-is."; exit 0
    fi
    apk add --no-cache openssl >/dev/null
    openssl genrsa -out /jwt/jwt-private.pem 2048
    openssl rsa -in /jwt/jwt-private.pem -pubout -out /jwt/jwt-public.pem
    chmod 600 /jwt/jwt-private.pem; chmod 644 /jwt/jwt-public.pem
    echo "  generated RS256 pair."
'
ok "JWT volume ready."

# ---- 6. Pull + up ----
cd "$STACK_DIR"
log "Pulling images (${REGISTRY}/flowone-*:${TAG})..."
docker compose pull web collab mailsync mail
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

# ---- 8b. TLS: obtain a real SAN cert, then flip SSL on ----
# Only when --ssl was requested AND the cert lineage isn't a bare IP (LE won't
# issue for IPs). The stack is already up on HTTP here, so obtain-certs.sh can
# briefly stop web to free :80, issue over HTTP-01, and restart it.
if [ "$ENABLE_SSL" = "1" ]; then
    if printf '%s' "$CERT_NAME" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
        warn "--ssl requested but the host is a bare IP (${CERT_NAME}); skipping cert (LE needs a domain)."
    else
        if [ ! -s "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]; then
            log "Obtaining LE SAN cert '${CERT_NAME}' for: ${CERT_DOMAINS}"
            _cert_args=""; IFS=','; for d in $CERT_DOMAINS; do _cert_args="$_cert_args $d"; done; unset IFS
            # shellcheck disable=SC2086
            bash "${SCRIPT_DIR}/obtain-certs.sh" --email="postmaster@${MAIL_DOMAIN}" \
                --cert-name="${CERT_NAME}" $_cert_args || warn "cert issuance failed; leaving stack on HTTP."
        fi
        if [ -s "/etc/letsencrypt/live/${CERT_NAME}/fullchain.pem" ]; then
            log "Enabling HTTPS in .env and recreating web + mail..."
            set_env ENABLE_SSL 1 "$ENV_FILE"
            docker compose up -d web
            docker compose restart mail
        fi
    fi
    echo ""
fi

# ---- 8. Mail pod smoke (host-networked: probe the ports on the host itself) ----
# We don't fail the whole run if mail is slow — ClamAV/freshclam can take a while
# to warm up — but we surface the port state so the operator knows where it stands.
log "Probing mail pod ports on the host (25/587/993/4190)..."
mail_state="$(docker inspect -f '{{.State.Health.Status}}' flowone-mail-1 2>/dev/null || echo missing)"
probe_port() {
    # $1=port $2=label  — 3s connect timeout via bash /dev/tcp (no nc dependency)
    if timeout 3 bash -c ": >/dev/tcp/127.0.0.1/$1" 2>/dev/null; then
        ok "  mail :$1 ($2) is accepting connections."
    else
        warn "  mail :$1 ($2) not answering yet."
    fi
}
probe_port 25  "smtp"
probe_port 587 "submission"
probe_port 993 "imaps"
probe_port 4190 "sieve"
echo "  mail container health: ${mail_state}"
echo ""

if [ "$healthy" = "1" ]; then
    code="$(docker exec flowone-web-1 sh -lc "curl -s -o /dev/null -w '%{http_code}' http://localhost/api/auth/me" 2>/dev/null || echo 000)"
    ok "Stack healthy. GET /api/auth/me -> ${code} (401/200 = app booted + DB/Redis answered)."
    echo ""
    echo "Create a login account:   ./create-mail-account.sh --email=you@${MAIL_DOMAIN}"
    echo "Print DNS records to add:  ./dns-records.sh   (MX/SPF/DKIM/DMARC/PTR)"
    echo "Verify the whole mail pod (seed + auth + send/receive + DKIM):"
    echo "  docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \\"
    echo "    /var/www/vps-email/backend/tests/mail-system-test.php \\"
    echo "    --mail-domain=${MAIL_DOMAIN} --db-admin-pass=<MYSQL_ROOT_PASSWORD> --verbose"
    echo ""
    if [ "$ENABLE_SSL" != "1" ]; then
        echo "Next: point DNS at this box, then re-run with --ssl (or --base-domain=<d> --ssl) for TLS."
    fi
    echo "Browse: ${SCHEME}://${DOMAIN}/"
    exit 0
else
    die "Web service did not become healthy in ${WAIT_TIMEOUT}s. Inspect: docker compose -f ${STACK_DIR}/docker-compose.yml logs web"
fi
