#!/bin/bash
#
# master-update.sh — ONE command that brings the Fleet master (devcon2) fully
# up to date from git. This is the "we push to git, we pull on the master" step
# of the workflow:
#
#   local dev (Windows) --push--> GitHub --this script--> devcon2:
#     1. git pull the repo checkout (/opt/flowone-repo)
#     2. sync fleet API/agent/templates/migrations into /var/www/vps-fleet
#     3. sync the email Docker stack files into /var/www/email/docker
#        (docker.compose_path — what provisionDocker ships to targets)
#     4. rebuild deploy packages from repo source:
#          panel  (npm-builds panel/dashboard only when it changed)
#          shared (panel/shared library)
#          agent  (fleet/agent)
#     5. restart PHP + fleet-agent
#
# Run ON the master as root:
#   bash /opt/flowone-repo/fleet/master-update.sh
#   bash /opt/flowone-repo/fleet/master-update.sh --skip-pull        # local test
#   bash /opt/flowone-repo/fleet/master-update.sh --skip-packages   # code only
#   bash /opt/flowone-repo/fleet/master-update.sh --with-fleet-dashboard
#
# Idempotent: safe to run after every push. Never touches config.local.php,
# var/ (tokens), or any per-server state.
set -uo pipefail

REPO="/opt/flowone-repo"
BRANCH="main"
PROD="/var/www/vps-fleet"
EMAIL_DST="/var/www/email/docker"
SKIP_PULL=0
SKIP_PACKAGES=0
FORCE_FLEET_DASH=0

for arg in "$@"; do
    case "$arg" in
        --repo=*) REPO="${arg#*=}" ;;
        --branch=*) BRANCH="${arg#*=}" ;;
        --skip-pull) SKIP_PULL=1 ;;
        --skip-packages) SKIP_PACKAGES=1 ;;
        --with-fleet-dashboard) FORCE_FLEET_DASH=1 ;;
        --help|-h)
            grep '^#' "$0" | sed 's/^# \{0,1\}//' | head -30; exit 0 ;;
        *) echo "Unknown arg: $arg (see --help)"; exit 2 ;;
    esac
done

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
die()  { echo -e "${RED}[FAIL]${NC} $1"; exit 1; }

[ "$(id -u)" = "0" ] || die "run as root"
[ -d "$REPO/.git" ] || die "no git checkout at $REPO (clone the repo there first)"
[ -d "$PROD" ] || die "no fleet install at $PROD"

# ---------------------------------------------------------------------------
# 1. Pull
# ---------------------------------------------------------------------------
if [ "$SKIP_PULL" = "0" ]; then
    git -C "$REPO" fetch origin "$BRANCH" || die "git fetch failed (check network/token)"
    git -C "$REPO" reset --hard "origin/$BRANCH" >/dev/null || die "git reset failed"
fi
HEAD_SHA="$(git -C "$REPO" rev-parse --short HEAD)"
ok "repo at $HEAD_SHA ($(git -C "$REPO" log -1 --format=%s | cut -c1-70))"

# tree-hash marker helpers: rebuild only what actually changed
mkdir -p "$PROD/var"
tree_hash() { git -C "$REPO" rev-parse "HEAD:$1" 2>/dev/null || echo "missing"; }
marker_get() { cat "$PROD/var/$1.tree" 2>/dev/null || echo "none"; }
marker_set() { echo "$2" > "$PROD/var/$1.tree"; }

# ---------------------------------------------------------------------------
# 2. Fleet code -> /var/www/vps-fleet
# ---------------------------------------------------------------------------
F="$REPO/fleet"

# API (never config.local.php — it holds this box's DB creds + registry token)
cp -r "$F/api/src"    "$PROD/api/" 2>/dev/null || die "api/src copy failed"
cp -r "$F/api/public" "$PROD/api/"
cp "$F/api/routes.php" "$F/api/config.php" "$F/api/composer.json" "$PROD/api/"
mkdir -p "$PROD/api/cli" "$PROD/api/tests"
cp -r "$F/api/cli/."   "$PROD/api/cli/"
cp -r "$F/api/tests/." "$PROD/api/tests/"
chmod +x "$PROD/api/tests/"*.sh 2>/dev/null || true

# Packages build/install scripts (NOT built tarballs — those are rebuilt below)
mkdir -p "$PROD/packages"
cp -r "$F/packages/." "$PROD/packages/"

# Templates, migrations, agent source, installer, extractor
cp -r "$F/templates/." "$PROD/templates/"
mkdir -p "$PROD/database"
cp -r "$F/database/." "$PROD/database/"
mkdir -p "$PROD/agent"
cp -r "$F/agent/." "$PROD/agent/"
[ -d "$F/installer" ] && { mkdir -p "$PROD/installer"; cp -r "$F/installer/." "$PROD/installer/"; chmod +x "$PROD/installer/"*.sh 2>/dev/null || true; }
[ -d "$F/extractor" ] && { mkdir -p "$PROD/extractor"; cp -r "$F/extractor/." "$PROD/extractor/"; chmod +x "$PROD/extractor/"*.sh 2>/dev/null || true; }
ok "fleet code synced"

# Fleet dashboard: the live assets under $PROD were built at deploy time. Only
# rebuild when fleet/dashboard actually changed (or when forced) — node is
# required for this. First run just records the baseline.
FD_HASH="$(tree_hash fleet/dashboard)"
FD_MARK="$(marker_get fleet-dashboard)"
if [ "$FORCE_FLEET_DASH" = "1" ] || { [ "$FD_MARK" != "none" ] && [ "$FD_MARK" != "$FD_HASH" ]; }; then
    if command -v npm >/dev/null 2>&1; then
        echo "fleet dashboard changed — building..."
        (cd "$F/dashboard" && npm ci --no-audit --no-fund >/dev/null 2>&1 && npm run build >/dev/null 2>&1) \
            && { find "$PROD/assets" -maxdepth 1 -type f \( -name '*.js' -o -name '*.css' \) -delete 2>/dev/null || true
                 cp -r "$F/dashboard/dist/." "$PROD/"
                 marker_set fleet-dashboard "$FD_HASH"
                 ok "fleet dashboard rebuilt + deployed"; } \
            || warn "fleet dashboard build FAILED — kept the currently deployed assets"
    else
        warn "npm not found — cannot rebuild fleet dashboard"
    fi
else
    marker_set fleet-dashboard "$FD_HASH"
    ok "fleet dashboard unchanged (skipped)"
fi

# ---------------------------------------------------------------------------
# 3. Email Docker stack -> /var/www/email/docker (compose_path for provisioning)
# ---------------------------------------------------------------------------
mkdir -p "$EMAIL_DST"
cp -r "$REPO/email/docker/." "$EMAIL_DST/"
rm -f "$EMAIL_DST/.env" 2>/dev/null || true   # never keep a local-dev .env here
chmod +x "$EMAIL_DST/"*.sh "$EMAIL_DST/mariadb-init/"*.sh 2>/dev/null || true
ok "email stack files synced ($EMAIL_DST)"

# ---------------------------------------------------------------------------
# 4. Deploy packages (from repo source — no external folders involved)
# ---------------------------------------------------------------------------
if [ "$SKIP_PACKAGES" = "0" ]; then
    PKG_VER="$(date +%Y.%m.%d)-${HEAD_SHA}"

    # --- panel dashboard: npm build only when panel/dashboard changed ---
    PD_HASH="$(tree_hash panel/dashboard)"
    PD_MARK="$(marker_get panel-dashboard)"
    PANEL_DIST="$REPO/panel/dashboard/dist"
    if [ ! -d "$PANEL_DIST" ] || [ "$PD_MARK" != "$PD_HASH" ]; then
        command -v npm >/dev/null 2>&1 || die "npm required to build the panel dashboard"
        echo "building panel dashboard (npm ci + vite build)..."
        (cd "$REPO/panel/dashboard" && npm ci --no-audit --no-fund >/dev/null 2>&1) || die "panel npm ci failed"
        (cd "$REPO/panel/dashboard" && npm run build >/dev/null 2>&1) || die "panel dashboard build failed"
        marker_set panel-dashboard "$PD_HASH"
        ok "panel dashboard built"
    else
        ok "panel dashboard unchanged (reusing dist)"
    fi
    [ -f "$PANEL_DIST/index.html" ] || die "panel dist missing index.html"

    # --- stage the panel source in the /var/www/vps-admin layout ---
    STAGE="/tmp/panel-stage-$$"
    rm -rf "$STAGE"; mkdir -p "$STAGE"
    cp -r "$PANEL_DIST/." "$STAGE/"                       # index.html + assets/
    cp -r "$REPO/panel/api" "$STAGE/api"
    rm -rf "$STAGE/api/vendor" "$STAGE/api/logs" "$STAGE/api/config.local.php"
    cp -r "$REPO/panel/agent"    "$STAGE/agent"
    cp -r "$REPO/panel/database" "$STAGE/database"
    # Self-hosted fonts (icons render as raw ligature text without them).
    # The repo keeps ONE copy under fleet/dashboard/public/fonts.
    cp -r "$REPO/fleet/dashboard/public/fonts" "$STAGE/fonts"

    bash "$PROD/packages/panel/build.sh" "$PKG_VER" --source="$STAGE" --output="$PROD/packages/panel" >/dev/null \
        && ok "panel package built (panel-v$PKG_VER.tar.gz)" \
        || die "panel package build failed"
    rm -rf "$STAGE"

    # --- shared library package (panel/shared) ---
    if [ -d "$REPO/panel/shared" ]; then
        bash "$PROD/packages/shared/build.sh" "$PKG_VER" --source="$REPO/panel/shared" --output="$PROD/packages/shared" >/dev/null \
            && ok "shared package built" || warn "shared package build failed"
    fi

    # --- fleet agent package (fleet/agent, synced into $PROD/agent above) ---
    bash "$PROD/packages/agent/build.sh" "$PKG_VER" >/dev/null \
        && ok "agent package built" || warn "agent package build failed"
fi

# ---------------------------------------------------------------------------
# 5. Ownership + services
# ---------------------------------------------------------------------------
chown -R www-data:www-data "$PROD"
[ -d "$PROD/agent" ] && chown -R root:root "$PROD/agent"
chmod 600 "$PROD/api/config.local.php" 2>/dev/null || true

systemctl restart lsphp83 2>/dev/null || systemctl restart lsws 2>/dev/null || warn "could not restart PHP"

# Live agent on this box runs from /opt/fleet-agent (code only, never config)
if [ -d /opt/fleet-agent ]; then
    cp "$F/agent/agent.php" "$F/agent/heartbeat.php" /opt/fleet-agent/ 2>/dev/null || true
    mkdir -p /opt/fleet-agent/Actions /opt/fleet-agent/Lib
    cp -r "$F/agent/Actions/." /opt/fleet-agent/Actions/
    cp -r "$F/agent/Lib/."     /opt/fleet-agent/Lib/
    systemctl is-active --quiet fleet-agent && systemctl restart fleet-agent
    ok "live fleet-agent synced"
fi

echo ""
ok "master updated to $HEAD_SHA — packages:"
ls -la "$PROD/packages/panel/" | grep -E 'panel-(v|latest)' || true
ls -la "$PROD/packages/shared/" 2>/dev/null | grep -E 'shared-(v|latest)' || true
ls -la "$PROD/packages/agent/" 2>/dev/null | grep -E 'agent-(v|latest)' || true
