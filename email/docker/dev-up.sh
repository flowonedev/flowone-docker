#!/usr/bin/env bash
#
# dev-up.sh -- LOCAL dev bring-up for the FlowOne stack. Builds the app tier from
# your working copy (docker-compose.override.yml forces pull_policy: build, so it
# NEVER pulls GHCR) and starts the bridge services. The host-networked `mail` pod
# is skipped by default because it needs Linux host networking, which Docker
# Desktop on Windows/macOS can't provide.
#
# This is the LOCAL end of the loop:  edit code -> ./dev-up.sh (test here)
#                                      -> git push -> CI publishes images
#                                      -> Fleet one-click deploy to a server.
#
# Usage:
#   ./dev-up.sh                # build + up the bridge tier (no mail)
#   ./dev-up.sh --with-mail    # also start the mail pod (LINUX hosts only)
#   ./dev-up.sh --down         # stop + remove the local stack
#   ./dev-up.sh --logs         # follow the web container logs
#   ./dev-up.sh --help
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

PROJECT=flowone
WITH_MAIL=0

case "${1:-}" in
    --help|-h)  sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --down)     docker compose -p "$PROJECT" down; exit 0 ;;
    --logs)     docker compose -p "$PROJECT" logs -f web; exit 0 ;;
    --with-mail) WITH_MAIL=1 ;;
    "")         ;;
    *)          echo "Unknown argument: $1 (try --help)" >&2; exit 1 ;;
esac

# 1. .env -- created from the example on first run; you must fill it in.
if [ ! -f .env ]; then
    cp .env.example .env
    echo "Created .env from .env.example. Edit the change-me values, then re-run ./dev-up.sh."
    exit 0
fi

# 2. JWT keys -- seed the shared volume (idempotent; prod is seeded by Fleet).
./gen-jwt-keys.sh

# 3. Build the app tier from local source + bring the stack up.
if [ "$WITH_MAIL" = 1 ]; then
    echo "Building + starting the FULL stack (including mail -- Linux host only)..."
    docker compose -p "$PROJECT" up -d --build
else
    echo "Building + starting the bridge tier (mail skipped -- use --with-mail on Linux)..."
    docker compose -p "$PROJECT" up -d --build --scale mail=0
fi

echo ""
docker compose -p "$PROJECT" ps
echo ""
echo "Web:   http://localhost"
echo "Logs:  ./dev-up.sh --logs"
echo "Stop:  ./dev-up.sh --down"
