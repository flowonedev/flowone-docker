#!/bin/bash
#
# panel-front-stack-test.sh — end-to-end verification of a DOCKER-provisioned box
# that runs the HYBRID "native panel front + containerized email" architecture
# produced by DockerProvisioningService::provisionDocker +
# ProvisioningService::deployNativePanelFront.
#
# It proves the FULL chain, not just "can I connect":
#   containers -> shared MariaDB (TCP) -> native OpenLiteSpeed front (:80/:443) ->
#   panel (native lsphp @ /var/www/vps-admin) + email (reverse-proxy -> web:8080) +
#   collab/mailsync WS -> PowerDNS -> host hardening (SSH/firewall/fail2ban) ->
#   host-net mail pod (25/587/993) -> Let's Encrypt certs (panel/email/mail).
#
# RUNS ON THE TARGET BOX (CLI only). It is READ-ONLY and idempotent: it creates no
# DB rows and no files other than its own timestamped log. The only external/
# destructive action (a test email) is OPT-IN (--send-test-mail) and honors
# --skip-send. Any test data it does create is prefixed [FLOWONE-TEST].
#
# Server run command (copy the file to the box first, then):
#   bash /opt/flowone/panel-front-stack-test.sh --verbose
#   bash /opt/flowone/panel-front-stack-test.sh --smoke --json
#
# Exit code: 0 = all pass, 1 = any failure.

set -u -o pipefail

# ---------------------------------------------------------------------------
# Config / CLI
# ---------------------------------------------------------------------------
STACK_DIR="/opt/flowone"
ENV_FILE="$STACK_DIR/.env"
COMPOSE_FILE="$STACK_DIR/docker-compose.yml"
PROJECT="flowone"
PANEL_ROOT="/var/www/vps-admin"
LOG_DIR="$STACK_DIR/test-logs"
TEST_TIMEOUT=30            # per external-call timeout (seconds)
SSH_PORT_EXPECTED=1985     # hardened SSH port (native parity)

VERBOSE=0
SKIP_SEND=0
SEND_TEST_MAIL=0
SMOKE=0
JSON=0
ONLY=""
PANEL_DOMAIN_OVERRIDE=""
EMAIL_DOMAIN_OVERRIDE=""
MAIL_DOMAIN_OVERRIDE=""

usage() {
    cat <<'EOF'
panel-front-stack-test.sh — verify the native-panel-front + docker-email stack.

USAGE:
  bash panel-front-stack-test.sh [flags]

FLAGS:
  --help              Show this help and exit.
  --verbose           Print extra debug (raw command output) for every check.
  --smoke             Quick health check only (preflight + containers + native +
                      DB connectivity). No business-logic / cert / mail depth.
  --json              Emit machine-readable JSON results (for monitoring). Implies
                      quiet human output.
  --only=g1,g2        Run only these groups (comma-separated). Groups:
                      preflight,containers,database,native,panel,email,
                      websockets,dns,security,mail,ssl
  --skip-send         Never perform the optional test-email send (default anyway).
  --send-test-mail    OPT IN to a single [FLOWONE-TEST] loopback SMTP probe.
  --panel-domain=X    Override auto-detected panel domain (default: from .env).
  --email-domain=X    Override auto-detected email domain (default: from .env).
  --mail-domain=X     Override auto-detected mail  domain (default: from .env).
  --timeout=N         Per-check external-call timeout in seconds (default 30).

Reads domains + secrets from /opt/flowone/.env. Exit 0 = all pass, 1 = failures.
EOF
}

for arg in "$@"; do
    case "$arg" in
        --help|-h) usage; exit 0 ;;
        --verbose) VERBOSE=1 ;;
        --smoke) SMOKE=1 ;;
        --json) JSON=1 ;;
        --skip-send) SKIP_SEND=1 ;;
        --send-test-mail) SEND_TEST_MAIL=1 ;;
        --only=*) ONLY="${arg#*=}" ;;
        --panel-domain=*) PANEL_DOMAIN_OVERRIDE="${arg#*=}" ;;
        --email-domain=*) EMAIL_DOMAIN_OVERRIDE="${arg#*=}" ;;
        --mail-domain=*) MAIL_DOMAIN_OVERRIDE="${arg#*=}" ;;
        --timeout=*) TEST_TIMEOUT="${arg#*=}" ;;
        *) echo "Unknown argument: $arg (see --help)"; exit 2 ;;
    esac
done

# ---------------------------------------------------------------------------
# Colors + counters + logging
# ---------------------------------------------------------------------------
if [ -t 1 ] && [ "$JSON" = "0" ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; DIM='\033[2m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; DIM=''; NC=''
fi

PASSED=0; FAILED=0; WARNED=0
FAIL_LINES=""
JSON_ITEMS=""
LOG_FILE=""

init_log() {
    mkdir -p "$LOG_DIR" 2>/dev/null || LOG_DIR="/tmp"
    LOG_FILE="$LOG_DIR/panel-front-test-$(date +%Y%m%d-%H%M%S).log"
    : > "$LOG_FILE" 2>/dev/null || LOG_FILE="/dev/null"
    {
        echo "panel-front-stack-test — $(date -Iseconds)"
        echo "host=$(hostname 2>/dev/null) panel=$PANEL_DOMAIN email=$EMAIL_DOMAIN mail=$MAIL_DOMAIN"
        echo "----------------------------------------------------------------------"
    } >> "$LOG_FILE" 2>/dev/null
}

logf() { echo "$1" >> "$LOG_FILE" 2>/dev/null; }
vecho() { [ "$VERBOSE" = "1" ] && [ "$JSON" = "0" ] && echo -e "    ${DIM}$1${NC}"; logf "    $1"; }

# ---------------------------------------------------------------------------
# Test-outcome plumbing. Each test fn sets T_STATUS + T_MSG via pass/warn/fail.
# ---------------------------------------------------------------------------
T_STATUS="PASS"; T_MSG=""
pass() { T_STATUS="PASS"; T_MSG="$1"; }
warn() { T_STATUS="WARN"; T_MSG="$1"; }
fail() { T_STATUS="FAIL"; T_MSG="$1"; }

now_ms() { date +%s%3N 2>/dev/null || echo $(( $(date +%s) * 1000 )); }
to() { timeout "${TEST_TIMEOUT}s" "$@"; }   # bound external calls so nothing hangs the suite

json_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n\r'; }

record() {
    local grp="$1" name="$2" status="$3" ms="$4" msg="$5"
    local ts; ts="$(date +%H:%M:%S)"
    logf "[$ts] [$status] $grp/$name (${ms}ms) — $msg"
    case "$status" in
        PASS) PASSED=$((PASSED+1)); [ "$JSON" = "0" ] && echo -e "  ${GREEN}✓ PASS${NC} ${grp}/${name} ${DIM}(${ms}ms)${NC} — $msg" ;;
        WARN) WARNED=$((WARNED+1)); [ "$JSON" = "0" ] && echo -e "  ${YELLOW}! WARN${NC} ${grp}/${name} ${DIM}(${ms}ms)${NC} — $msg" ;;
        FAIL) FAILED=$((FAILED+1)); FAIL_LINES="${FAIL_LINES}\n  - ${grp}/${name}: ${msg}"; [ "$JSON" = "0" ] && echo -e "  ${RED}✗ FAIL${NC} ${grp}/${name} ${DIM}(${ms}ms)${NC} — $msg" ;;
    esac
    local item; item="{\"group\":\"$(json_escape "$grp")\",\"name\":\"$(json_escape "$name")\",\"status\":\"$status\",\"ms\":$ms,\"message\":\"$(json_escape "$msg")\"}"
    JSON_ITEMS="${JSON_ITEMS:+$JSON_ITEMS,}$item"
}

run() {
    local grp="$1" name="$2" fn="$3"
    local s; s="$(now_ms)"
    T_STATUS="PASS"; T_MSG="ok"
    "$fn"
    local ms=$(( $(now_ms) - s ))
    record "$grp" "$name" "$T_STATUS" "$ms" "$T_MSG"
}

group_enabled() {
    local g="$1"
    if [ -n "$ONLY" ]; then case ",$ONLY," in *",$g,"*) return 0 ;; *) return 1 ;; esac; fi
    if [ "$SMOKE" = "1" ]; then case "$g" in preflight|containers|database|native) return 0 ;; *) return 1 ;; esac; fi
    return 0
}

# ---------------------------------------------------------------------------
# .env-driven configuration
# ---------------------------------------------------------------------------
get_env() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r'; }

EMAIL_DOMAIN=""; MAIL_DOMAIN=""; PANEL_DOMAIN=""
DB_ROOT_PASS=""; EMAIL_DB_NAME=""; EMAIL_DB_USER=""; EMAIL_DB_PASS=""
PANEL_DB_NAME="devc_vps_dash"; PANEL_DB_USER="vpsadmin"

load_env() {
    [ -f "$ENV_FILE" ] || return 1
    EMAIL_DOMAIN="$(get_env EMAIL_DOMAIN)"
    MAIL_DOMAIN="$(get_env MAIL_DOMAIN)"
    local papi; papi="$(get_env PANEL_API_URL)"
    PANEL_DOMAIN="$(printf '%s' "$papi" | sed -E 's#^https?://##; s#/api/?$##')"
    DB_ROOT_PASS="$(get_env MYSQL_ROOT_PASSWORD)"
    EMAIL_DB_NAME="$(get_env DB_NAME)"
    EMAIL_DB_USER="$(get_env DB_USER)"
    EMAIL_DB_PASS="$(get_env DB_PASS)"
    [ -n "$PANEL_DOMAIN_OVERRIDE" ] && PANEL_DOMAIN="$PANEL_DOMAIN_OVERRIDE"
    [ -n "$EMAIL_DOMAIN_OVERRIDE" ] && EMAIL_DOMAIN="$EMAIL_DOMAIN_OVERRIDE"
    [ -n "$MAIL_DOMAIN_OVERRIDE" ] && MAIL_DOMAIN="$MAIL_DOMAIN_OVERRIDE"
    return 0
}

COMPOSE=""   # resolved in preflight
db_query() {  # run SQL in the container DB as root; prints raw rows (tab-sep, no header)
    printf '%s' "$1" | to $COMPOSE exec -T -e MYSQL_PWD="$DB_ROOT_PASS" mariadb mariadb -uroot -N 2>/dev/null
}

# ---------------------------------------------------------------------------
# PRE-FLIGHT (abort early if the environment is unusable)
# ---------------------------------------------------------------------------
preflight() {
    local abort=0
    if [ "$(id -u)" != "0" ]; then
        echo -e "${YELLOW}WARN: not running as root — service/SSH/cert checks may be incomplete.${NC}"
    fi
    for c in docker curl ss openssl grep sed awk; do
        if ! command -v "$c" >/dev/null 2>&1; then
            echo -e "${RED}PRE-FLIGHT FAIL: required command '$c' not found.${NC}"; abort=1
        fi
    done
    if docker compose version >/dev/null 2>&1; then
        COMPOSE="docker compose -p $PROJECT -f $COMPOSE_FILE"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE="docker-compose -p $PROJECT -f $COMPOSE_FILE"
    else
        echo -e "${RED}PRE-FLIGHT FAIL: docker compose plugin not available.${NC}"; abort=1
    fi
    [ -f "$COMPOSE_FILE" ] || { echo -e "${RED}PRE-FLIGHT FAIL: $COMPOSE_FILE missing (stack not shipped).${NC}"; abort=1; }
    [ -f "$ENV_FILE" ] || { echo -e "${RED}PRE-FLIGHT FAIL: $ENV_FILE missing (stack not rendered).${NC}"; abort=1; }
    if [ -n "$EMAIL_DOMAIN" ]; then
        # disk space in the log dir (need a few MB for logs)
        local avail; avail="$(df -Pm "$LOG_DIR" 2>/dev/null | awk 'NR==2{print $4}')"
        [ -n "$avail" ] && [ "$avail" -lt 20 ] && echo -e "${YELLOW}WARN: <20MB free in $LOG_DIR.${NC}"
    fi
    [ -z "$PANEL_DOMAIN" ] && echo -e "${YELLOW}WARN: could not derive PANEL_DOMAIN from .env (PANEL_API_URL).${NC}"
    [ -z "$DB_ROOT_PASS" ] && echo -e "${YELLOW}WARN: MYSQL_ROOT_PASSWORD not in .env — DB checks may fail.${NC}"
    return $abort
}

# ---------------------------------------------------------------------------
# GROUP: preflight (as recorded checks)
# ---------------------------------------------------------------------------
t_env_loaded() {
    [ -f "$ENV_FILE" ] || { fail ".env missing at $ENV_FILE"; return; }
    [ -n "$EMAIL_DOMAIN" ] || { fail "EMAIL_DOMAIN empty in .env"; return; }
    vecho "panel=$PANEL_DOMAIN email=$EMAIL_DOMAIN mail=$MAIL_DOMAIN"
    pass "env loaded: panel=$PANEL_DOMAIN email=$EMAIL_DOMAIN mail=$MAIL_DOMAIN"
}
t_compose_file() {
    to $COMPOSE config >/dev/null 2>&1 && pass "compose config valid" || fail "compose config invalid (check $COMPOSE_FILE / .env)"
}

# ---------------------------------------------------------------------------
# GROUP: containers
# ---------------------------------------------------------------------------
CORE_SERVICES="mariadb redis meilisearch web collab mailsync mail"
t_containers_running() {
    local ps; ps="$(to $COMPOSE ps 2>/dev/null)"; vecho "$ps"
    local missing=""
    for s in $CORE_SERVICES; do
        echo "$ps" | grep -qE "(^|[[:space:]/])${s}([[:space:]]|-|$)" || missing="$missing $s"
    done
    [ -z "$missing" ] && pass "all core services present ($CORE_SERVICES)" || fail "services not found:$missing"
}
t_containers_health() {
    local up; up="$(to $COMPOSE ps --status running 2>/dev/null | grep -cE 'running|Up' 2>/dev/null)"
    local unhealthy; unhealthy="$(to $COMPOSE ps 2>/dev/null | grep -i 'unhealthy' | awk '{print $1}' | tr '\n' ' ')"
    [ -n "$unhealthy" ] && { fail "unhealthy containers: $unhealthy"; return; }
    [ "${up:-0}" -ge 5 ] && pass "$up containers running, none unhealthy" || warn "only ${up:-0} containers running"
}

# ---------------------------------------------------------------------------
# GROUP: database (shared containerized MariaDB, TCP on 127.0.0.1:3306)
# ---------------------------------------------------------------------------
t_db_loopback_publish() {
    to bash -c "echo > /dev/tcp/127.0.0.1/3306" 2>/dev/null \
        && pass "MariaDB reachable on host loopback 127.0.0.1:3306" \
        || fail "127.0.0.1:3306 not reachable — panel/PowerDNS cannot use the DB"
}
t_db_root_ok() {
    local r; r="$(db_query 'SELECT 1;')"; vecho "root SELECT 1 -> '$r'"
    [ "$r" = "1" ] && pass "container DB root auth OK (MYSQL_ROOT_PASSWORD)" || fail "root auth to container DB failed"
}
t_db_root_wildcard_grant() {
    local n; n="$(db_query "SELECT COUNT(*) FROM mysql.user WHERE User='root' AND Host='%';")"
    vecho "root@'%' count -> '$n'"
    [ "${n:-0}" -ge 1 ] && pass "root@'%' exists (native tooling can reach DB over TCP)" \
        || fail "root@'%' missing — panel schema import over TCP will fail"
}
t_db_panel_schema() {
    local n; n="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$PANEL_DB_NAME';")"
    vecho "$PANEL_DB_NAME table count -> '$n'"
    [ "${n:-0}" -ge 5 ] && pass "$PANEL_DB_NAME has ${n} tables (panel installed)" \
        || fail "$PANEL_DB_NAME has ${n:-0} tables — panel schema not imported"
}
t_db_panel_user() {
    local n; n="$(db_query "SELECT COUNT(*) FROM mysql.user WHERE User='$PANEL_DB_USER';")"
    [ "${n:-0}" -ge 1 ] && pass "panel DB user '$PANEL_DB_USER' present" || fail "panel DB user '$PANEL_DB_USER' missing"
}
t_db_email_schema() {
    [ -n "$EMAIL_DB_NAME" ] || { warn "EMAIL DB_NAME not in .env"; return; }
    local n; n="$(db_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$EMAIL_DB_NAME';")"
    [ "${n:-0}" -ge 5 ] && pass "email DB '$EMAIL_DB_NAME' has ${n} tables" || warn "email DB '$EMAIL_DB_NAME' has ${n:-0} tables"
}

# ---------------------------------------------------------------------------
# GROUP: native OpenLiteSpeed front tier
# ---------------------------------------------------------------------------
t_ols_running() {
    if systemctl is-active --quiet lshttpd 2>/dev/null || systemctl is-active --quiet lsws 2>/dev/null; then
        pass "native OpenLiteSpeed service active"
    elif to /usr/local/lsws/bin/lswsctrl status 2>/dev/null | grep -qi 'running'; then
        pass "native OpenLiteSpeed running (lswsctrl)"
    else
        fail "native OpenLiteSpeed not running — nothing terminates TLS on :443"
    fi
}
t_ols_owns_ports() {
    local l; l="$(to ss -ltnp 2>/dev/null)"; vecho "$l"
    local p80 p443
    p80="$(echo "$l" | grep -E ':80[[:space:]]' | grep -ci 'lshttpd\|litespeed\|openlite')"
    p443="$(echo "$l" | grep -E ':443[[:space:]]' | grep -ci 'lshttpd\|litespeed\|openlite')"
    if echo "$l" | grep -qE ':80[[:space:]]' && echo "$l" | grep -qE ':443[[:space:]]'; then
        if [ "${p80:-0}" -ge 1 ] || [ "${p443:-0}" -ge 1 ]; then
            pass "OpenLiteSpeed owns public :80 and :443"
        else
            warn ":80/:443 are bound but not obviously by litespeed (check ss output)"
        fi
    else
        fail ":80 and/or :443 not listening — native front not serving"
    fi
}
t_web_loopback_only() {
    local l; l="$(to ss -ltn 2>/dev/null)"
    if echo "$l" | grep -qE '127\.0\.0\.1:8080'; then
        # must NOT be published on a public interface
        if echo "$l" | grep -E ':8080[[:space:]]' | grep -qvE '127\.0\.0\.1:8080'; then
            warn "web container :8080 also bound beyond loopback"
        else
            pass "web container on 127.0.0.1:8080 (loopback only, fronted by OLS)"
        fi
    else
        fail "web container not on 127.0.0.1:8080 — reverse-proxy target missing"
    fi
}
t_vhosts_registered() {
    local hc="/usr/local/lsws/conf/httpd_config.conf"
    [ -f "$hc" ] || { fail "httpd_config.conf missing"; return; }
    local havep havee
    havep="$(grep -c "virtualHost ${PANEL_DOMAIN} " "$hc" 2>/dev/null)"
    havee="$(grep -c "virtualHost ${EMAIL_DOMAIN} " "$hc" 2>/dev/null)"
    if [ "${havep:-0}" -ge 1 ] && [ "${havee:-0}" -ge 1 ]; then
        pass "panel + email vhosts registered in httpd_config.conf"
    else
        fail "vhost missing (panel=$havep email=$havee) in httpd_config.conf"
    fi
}
t_email_vhost_is_proxy() {
    local vh="/usr/local/lsws/conf/vhosts/${EMAIL_DOMAIN}/vhconf.conf"
    [ -f "$vh" ] || { fail "email vhost conf missing at $vh"; return; }
    if grep -q 'email_backend' "$vh" && grep -qE 'address[[:space:]]+127\.0\.0\.1:8080' "$vh"; then
        pass "email vhost is a reverse-proxy -> 127.0.0.1:8080 (container web)"
    else
        fail "email vhost is not the docker reverse-proxy template"
    fi
}

# ---------------------------------------------------------------------------
# GROUP: panel (native lsphp app) — the original bug was the email login showing here
# ---------------------------------------------------------------------------
t_panel_docroot() {
    [ -d "$PANEL_ROOT" ] && [ -d "$PANEL_ROOT/api" ] \
        && pass "panel docroot $PANEL_ROOT with api/ present" \
        || fail "panel not installed at $PANEL_ROOT (missing docroot/api)"
}
t_panel_http() {
    [ -n "$PANEL_DOMAIN" ] || { warn "no panel domain"; return; }
    local code; code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time "$TEST_TIMEOUT" --resolve "${PANEL_DOMAIN}:443:127.0.0.1" "https://${PANEL_DOMAIN}/" 2>/dev/null)"
    vecho "panel https code=$code"
    case "$code" in
        200|301|302|401) pass "panel https reachable (HTTP $code)" ;;
        000) fail "panel https not reachable (curl failed / TLS error)" ;;
        *) warn "panel https returned HTTP $code" ;;
    esac
}
t_panel_not_email_app() {
    [ -n "$PANEL_DOMAIN" ] && [ -n "$EMAIL_DOMAIN" ] || { warn "domains missing for discriminator"; return; }
    local ph eh
    ph="$(curl -sk --max-time "$TEST_TIMEOUT" --resolve "${PANEL_DOMAIN}:443:127.0.0.1" "https://${PANEL_DOMAIN}/" 2>/dev/null)"
    eh="$(curl -sk --max-time "$TEST_TIMEOUT" --resolve "${EMAIL_DOMAIN}:443:127.0.0.1" "https://${EMAIL_DOMAIN}/" 2>/dev/null)"
    if [ -z "$ph" ]; then warn "panel returned empty body"; return; fi
    # The original regression: panel.<domain> served the FlowOne Email SPA login.
    if printf '%s' "$ph" | grep -qiE 'FlowOne Email|Sign in to your account|email access powered'; then
        fail "panel domain is serving the EMAIL app login (catch-all regression)"
    elif [ -n "$eh" ] && [ "$ph" = "$eh" ]; then
        fail "panel and email serve BYTE-IDENTICAL content (catch-all regression)"
    else
        pass "panel serves its own app (distinct from the email SPA)"
    fi
}

# ---------------------------------------------------------------------------
# GROUP: email (reverse-proxy -> container web)
# ---------------------------------------------------------------------------
t_email_http() {
    [ -n "$EMAIL_DOMAIN" ] || { warn "no email domain"; return; }
    local code; code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time "$TEST_TIMEOUT" --resolve "${EMAIL_DOMAIN}:443:127.0.0.1" "https://${EMAIL_DOMAIN}/" 2>/dev/null)"
    vecho "email https code=$code"
    case "$code" in
        200|301|302) pass "email https reachable via native proxy (HTTP $code)" ;;
        000) fail "email https not reachable (proxy/TLS error)" ;;
        *) warn "email https returned HTTP $code" ;;
    esac
}
t_email_api_health() {
    [ -n "$EMAIL_DOMAIN" ] || { warn "no email domain"; return; }
    local code; code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time "$TEST_TIMEOUT" --resolve "${EMAIL_DOMAIN}:443:127.0.0.1" "https://${EMAIL_DOMAIN}/api/health" 2>/dev/null)"
    vecho "email /api/health code=$code"
    case "$code" in
        200|204) pass "email backend /api/health OK (HTTP $code)" ;;
        404) warn "email /api/health -> 404 (endpoint may differ; proxy still routes /api)" ;;
        000) fail "email /api unreachable through the proxy" ;;
        *) warn "email /api/health -> HTTP $code" ;;
    esac
}

# ---------------------------------------------------------------------------
# GROUP: websockets (collab 1234 / mailsync 1235 on loopback + proxied paths)
# ---------------------------------------------------------------------------
t_ws_ports() {
    local l; l="$(to ss -ltn 2>/dev/null)"
    local c m
    echo "$l" | grep -qE '127\.0\.0\.1:1234' && c=1 || c=0
    echo "$l" | grep -qE '127\.0\.0\.1:1235' && m=1 || m=0
    if [ "$c" = 1 ] && [ "$m" = 1 ]; then pass "collab(1234) + mailsync(1235) on loopback"
    else fail "WS loopback ports missing (collab=$c mailsync=$m)"; fi
}
t_ws_upgrade() {
    [ -n "$EMAIL_DOMAIN" ] || { warn "no email domain"; return; }
    local code; code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time "$TEST_TIMEOUT" \
        --resolve "${EMAIL_DOMAIN}:443:127.0.0.1" \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        "https://${EMAIL_DOMAIN}/collab-ws" 2>/dev/null)"
    vecho "collab-ws upgrade code=$code"
    case "$code" in
        101) pass "/collab-ws upgrades to WebSocket (HTTP 101)" ;;
        400|401|426|200) warn "/collab-ws reachable (HTTP $code) — auth/handshake gate, proxy routes it" ;;
        000) fail "/collab-ws not reachable through the proxy" ;;
        *) warn "/collab-ws -> HTTP $code" ;;
    esac
}

# ---------------------------------------------------------------------------
# GROUP: dns (PowerDNS on the shared container DB)
# ---------------------------------------------------------------------------
t_pdns_running() {
    if systemctl is-active --quiet pdns 2>/dev/null || systemctl is-active --quiet pdns-server 2>/dev/null; then
        pass "PowerDNS service active"
    elif to ss -lun 2>/dev/null | grep -qE ':53[[:space:]]'; then
        pass "something is serving DNS on :53 (assume PowerDNS)"
    else
        warn "PowerDNS not active / :53 not listening"
    fi
}
t_pdns_zone() {
    [ -n "$MAIL_DOMAIN" ] || { warn "no base domain to check zone"; return; }
    # PowerDNS gmysql backend stores its zones in the PANEL DB (devc_vps_dash),
    # not a dedicated 'powerdns' schema (see installPowerDNS gmysql-dbname).
    local n; n="$(db_query "SELECT COUNT(*) FROM \`$PANEL_DB_NAME\`.domains WHERE name='$MAIL_DOMAIN';" 2>/dev/null)"
    if [ -z "$n" ]; then
        # domains table may not exist yet; fall back to pdnsutil on the host.
        if to pdnsutil list-all-zones 2>/dev/null | grep -qx "$MAIL_DOMAIN"; then
            pass "PowerDNS zone for $MAIL_DOMAIN present (pdnsutil)"
        else
            warn "could not confirm PowerDNS zone for $MAIL_DOMAIN (no domains table / pdnsutil)"
        fi
        return
    fi
    [ "${n:-0}" -ge 1 ] && pass "PowerDNS zone for $MAIL_DOMAIN present in $PANEL_DB_NAME.domains" \
        || warn "no PowerDNS zone row for $MAIL_DOMAIN in $PANEL_DB_NAME.domains"
}

# ---------------------------------------------------------------------------
# GROUP: security (host hardening — native parity)
# ---------------------------------------------------------------------------
t_fail2ban() {
    systemctl is-active --quiet fail2ban 2>/dev/null && pass "fail2ban active" || fail "fail2ban not active"
}
t_firewall() {
    if systemctl is-active --quiet firewalld 2>/dev/null; then pass "firewalld active"
    elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi active; then pass "ufw active"
    else fail "no active host firewall (firewalld/ufw)"; fi
}
t_ssh_port() {
    local l; l="$(to ss -ltn 2>/dev/null)"
    if echo "$l" | grep -qE ":${SSH_PORT_EXPECTED}[[:space:]]"; then
        pass "sshd listening on hardened port ${SSH_PORT_EXPECTED}"
    elif echo "$l" | grep -qE ':22[[:space:]]'; then
        warn "sshd on :22 (hardening to ${SSH_PORT_EXPECTED} may not have run yet)"
    else
        warn "could not confirm sshd port"
    fi
}
t_ssh_root_denied() {
    local cfg; cfg="$(sshd -T 2>/dev/null | grep -i '^permitrootlogin')"
    [ -z "$cfg" ] && cfg="$(grep -iE '^\s*PermitRootLogin' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null | tail -1)"
    vecho "PermitRootLogin -> '$cfg'"
    if echo "$cfg" | grep -qiE 'no|prohibit-password'; then pass "SSH root login restricted ($cfg)"
    else warn "SSH root login not clearly restricted ($cfg)"; fi
}
t_pxr_user() {
    id pxr >/dev/null 2>&1 && pass "hardened admin user 'pxr' exists" || warn "'pxr' user not found (hardening may be pending)"
}
t_docker_after_firewall() {
    # published container ports must survive firewalld (Docker restarted after harden)
    to bash -c "echo > /dev/tcp/127.0.0.1/8080" 2>/dev/null \
        && pass "web loopback :8080 still reachable after firewall (docker rules intact)" \
        || warn ":8080 not reachable — firewalld may have dropped docker's rules (needs docker restart)"
}

# ---------------------------------------------------------------------------
# GROUP: mail (host-net pod: 25/587/465/143/993) + cert
# ---------------------------------------------------------------------------
t_mail_ports() {
    local l; l="$(to ss -ltn 2>/dev/null)"
    local miss=""
    for p in 25 587 993; do echo "$l" | grep -qE ":${p}[[:space:]]" || miss="$miss $p"; done
    [ -z "$miss" ] && pass "mail ports listening (25/587/993)" || fail "mail ports not listening:$miss"
}
t_mail_smtp_banner() {
    local banner; banner="$(to bash -c 'exec 3<>/dev/tcp/127.0.0.1/25; head -1 <&3' 2>/dev/null)"
    vecho "SMTP banner: $banner"
    echo "$banner" | grep -qE '^220 ' && pass "SMTP 220 banner on :25 (${banner:0:40}...)" || warn "no SMTP 220 banner on :25"
}
t_mail_cert_in_pod() {
    [ -n "$MAIL_DOMAIN" ] || { warn "no mail domain"; return; }
    local out; out="$(to $COMPOSE exec -T mail sh -c 'ls -1 /etc/letsencrypt/live 2>/dev/null' 2>/dev/null)"
    vecho "mail pod /etc/letsencrypt/live -> $out"
    if printf '%s' "$out" | grep -qx "$MAIL_DOMAIN"; then pass "mail pod has cert dir for $MAIL_DOMAIN"
    else warn "mail pod cert for $MAIL_DOMAIN not found (self-signed fallback until DNS+certbot)"; fi
}
t_mail_test_send() {
    if [ "$SKIP_SEND" = "1" ] || [ "$SEND_TEST_MAIL" = "0" ]; then
        warn "test-mail send skipped (opt in with --send-test-mail)"; return
    fi
    local rcpt="postmaster@${MAIL_DOMAIN}"
    local r; r="$(to bash -c "printf 'EHLO test\r\nMAIL FROM:<flowone_test@${MAIL_DOMAIN}>\r\nRCPT TO:<${rcpt}>\r\nDATA\r\nSubject: [FLOWONE-TEST] loopback probe\r\n\r\ntest\r\n.\r\nQUIT\r\n' | nc -w 5 127.0.0.1 25" 2>/dev/null)"
    vecho "$r"
    echo "$r" | grep -qE '250 ' && pass "[FLOWONE-TEST] SMTP accepted (250)" || warn "SMTP did not accept test message"
}

# ---------------------------------------------------------------------------
# GROUP: ssl (Let's Encrypt certs for panel/email/mail)
# ---------------------------------------------------------------------------
cert_is_le() {
    local d="$1" dir="/etc/letsencrypt/live/$1"
    [ -f "$dir/fullchain.pem" ] || { echo "missing"; return; }
    local issuer; issuer="$(to openssl x509 -in "$dir/fullchain.pem" -noout -issuer 2>/dev/null)"
    if echo "$issuer" | grep -qiE "Let's Encrypt|\b(R3|R10|R11|E[5-9])\b"; then echo "le"; else echo "selfsigned"; fi
}
cert_days_left() {
    local dir="/etc/letsencrypt/live/$1"
    [ -f "$dir/fullchain.pem" ] || { echo "-1"; return; }
    local end; end="$(to openssl x509 -in "$dir/fullchain.pem" -noout -enddate 2>/dev/null | cut -d= -f2)"
    [ -z "$end" ] && { echo "-1"; return; }
    local e n; e="$(date -d "$end" +%s 2>/dev/null)"; n="$(date +%s)"
    [ -z "$e" ] && { echo "-1"; return; }
    echo $(( (e - n) / 86400 ))
}
check_cert() {
    local label="$1" d="$2"
    [ -n "$d" ] || { warn "$label domain empty"; return; }
    local kind; kind="$(cert_is_le "$d")"
    case "$kind" in
        le) local days; days="$(cert_days_left "$d")"
            if [ "$days" -lt 0 ]; then warn "$label cert ($d) LE but expiry unknown"
            elif [ "$days" -lt 7 ]; then warn "$label cert ($d) expires in ${days}d — renew"
            else pass "$label cert ($d) is Let's Encrypt, ${days}d left"; fi ;;
        selfsigned) fail "$label cert ($d) is self-signed/placeholder (DNS not pointing here or certbot failed)" ;;
        *) fail "$label cert ($d) missing" ;;
    esac
}
t_ssl_panel() { check_cert "panel" "$PANEL_DOMAIN"; }
t_ssl_email() { check_cert "email" "$EMAIL_DOMAIN"; }
t_ssl_mail()  { check_cert "mail"  "$MAIL_DOMAIN"; }

# ---------------------------------------------------------------------------
# Runner
# ---------------------------------------------------------------------------
main() {
    load_env
    init_log

    if ! preflight; then
        echo -e "${RED}Pre-flight failed — aborting before running tests.${NC}"
        exit 1
    fi

    [ "$JSON" = "0" ] && {
        echo -e "${BLUE}=== FlowOne panel-front stack test ===${NC}"
        echo -e "  ${DIM}panel=$PANEL_DOMAIN  email=$EMAIL_DOMAIN  mail=$MAIL_DOMAIN${NC}"
        echo -e "  ${DIM}log=$LOG_FILE  mode=$([ "$SMOKE" = 1 ] && echo smoke || echo full)${NC}\n"
    }

    if group_enabled preflight; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 1. PRE-FLIGHT ---${NC}"
        run preflight env_loaded t_env_loaded
        run preflight compose_config t_compose_file
    fi
    if group_enabled containers; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 2. CONTAINERS ---${NC}"
        run containers running t_containers_running
        run containers health t_containers_health
    fi
    if group_enabled database; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 3. DATABASE (shared MariaDB) ---${NC}"
        run database loopback_publish t_db_loopback_publish
        run database root_auth t_db_root_ok
        run database root_wildcard t_db_root_wildcard_grant
        run database panel_schema t_db_panel_schema
        run database panel_user t_db_panel_user
        run database email_schema t_db_email_schema
    fi
    if group_enabled native; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 4. NATIVE OPENLITESPEED FRONT ---${NC}"
        run native ols_running t_ols_running
        run native owns_ports t_ols_owns_ports
        run native web_loopback t_web_loopback_only
        run native vhosts_registered t_vhosts_registered
        run native email_is_proxy t_email_vhost_is_proxy
    fi
    if group_enabled panel; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 5. PANEL (native) ---${NC}"
        run panel docroot t_panel_docroot
        run panel http t_panel_http
        run panel not_email_app t_panel_not_email_app
    fi
    if group_enabled email; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 6. EMAIL (reverse-proxy) ---${NC}"
        run email http t_email_http
        run email api_health t_email_api_health
    fi
    if group_enabled websockets; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 7. WEBSOCKETS ---${NC}"
        run websockets loopback_ports t_ws_ports
        run websockets collab_upgrade t_ws_upgrade
    fi
    if group_enabled dns; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 8. DNS (PowerDNS) ---${NC}"
        run dns pdns_running t_pdns_running
        run dns zone t_pdns_zone
    fi
    if group_enabled security; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 9. SECURITY (hardening) ---${NC}"
        run security fail2ban t_fail2ban
        run security firewall t_firewall
        run security ssh_port t_ssh_port
        run security ssh_root_denied t_ssh_root_denied
        run security pxr_user t_pxr_user
        run security docker_ports_survive t_docker_after_firewall
    fi
    if group_enabled mail; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 10. MAIL (host-net pod) ---${NC}"
        run mail ports t_mail_ports
        run mail smtp_banner t_mail_smtp_banner
        run mail cert_in_pod t_mail_cert_in_pod
        run mail test_send t_mail_test_send
    fi
    if group_enabled ssl; then
        [ "$JSON" = "0" ] && echo -e "${BLUE}--- 11. SSL (Let's Encrypt) ---${NC}"
        run ssl panel_cert t_ssl_panel
        run ssl email_cert t_ssl_email
        run ssl mail_cert t_ssl_mail
    fi

    local total=$((PASSED + FAILED + WARNED))
    logf "----------------------------------------------------------------------"
    logf "SUMMARY: $PASSED passed, $FAILED failed, $WARNED warnings ($total total)"

    if [ "$JSON" = "1" ]; then
        printf '{"passed":%d,"failed":%d,"warnings":%d,"total":%d,"log":"%s","results":[%s]}\n' \
            "$PASSED" "$FAILED" "$WARNED" "$total" "$(json_escape "$LOG_FILE")" "$JSON_ITEMS"
    else
        echo ""
        echo -e "${BLUE}=== SUMMARY ===${NC}"
        echo -e "  ${GREEN}$PASSED passed${NC}, ${RED}$FAILED failed${NC}, ${YELLOW}$WARNED warnings${NC}  (${total} checks)"
        echo -e "  ${DIM}log: $LOG_FILE${NC}"
        if [ "$FAILED" -gt 0 ]; then
            echo -e "${RED}Failures:${NC}$(echo -e "$FAIL_LINES")"
        fi
    fi

    [ "$FAILED" -eq 0 ] && exit 0 || exit 1
}

main
