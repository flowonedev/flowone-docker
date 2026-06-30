#!/bin/bash
# =============================================================================
# FlowOne - install/update the presence plugin in a RUNNING Document Server
# =============================================================================
# Copies plugins/flowone-presence into the flowone-office container without
# rebuilding the image (the Dockerfile also bakes it in for future rebuilds).
#
# Run on the server from this directory:
#   cd /var/www/vps-email/office
#   bash install-presence-plugin.sh
#
# Options:
#   --container=flowone-office   Container name (default: flowone-office)
#   --help                       Show this help
# =============================================================================

set -euo pipefail

CONTAINER="flowone-office"

for arg in "$@"; do
    case "$arg" in
        --container=*) CONTAINER="${arg#*=}" ;;
        --help)
            grep '^#' "$0" | head -16 | sed 's/^# \?//'
            exit 0
            ;;
        *) echo "Unknown option: $arg (see --help)"; exit 1 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="${SCRIPT_DIR}/plugins/flowone-presence"
DEST="/var/www/onlyoffice/documentserver/sdkjs-plugins"

log()  { echo -e "\033[0;32m[presence]\033[0m $1"; }
fail() { echo -e "\033[0;31m[presence]\033[0m $1"; exit 1; }

[ -d "$SRC" ] || fail "Plugin source not found: $SRC"
command -v docker >/dev/null 2>&1 || fail "Docker is not installed"
docker ps --format '{{.Names}}' | grep -qx "$CONTAINER" || fail "Container not running: $CONTAINER"

# The plugin runtime ships with the DS; warn if this build lacks it.
if ! docker exec "$CONTAINER" test -f "${DEST}/v1/plugins.js"; then
    fail "${DEST}/v1/plugins.js missing in container - unexpected DS build, plugin would not start"
fi

log "Copying plugin into ${CONTAINER}:${DEST}/flowone-presence ..."
docker exec "$CONTAINER" rm -rf "${DEST}/flowone-presence"
docker cp "$SRC" "${CONTAINER}:${DEST}/flowone-presence"
docker exec "$CONTAINER" chown -R ds:ds "${DEST}/flowone-presence"

# Verify it is served over HTTP (any 2xx/3xx is fine)
if docker exec "$CONTAINER" curl -fsS "http://127.0.0.1/sdkjs-plugins/flowone-presence/config.json" >/dev/null 2>&1; then
    log "Plugin is being served: /sdkjs-plugins/flowone-presence/config.json"
else
    log "NOTE: could not verify via container-local curl; check https://DOMAIN:8443/sdkjs-plugins/flowone-presence/config.json"
fi

log "Done. Reload any open editors to pick up the plugin."
