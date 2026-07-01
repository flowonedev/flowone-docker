#!/usr/bin/env bash
#
# build-and-push.sh — build the FlowOne app-tier images and (optionally) push
# them to a registry. Part of the native->docker migration (Phase D): servers
# provisioned by Fleet run `docker compose pull ${REGISTRY}/flowone-<svc>:${TAG}`,
# so those images must exist in the registry first.
#
# Builds three images from the email/ build context:
#   flowone-web       (docker/web/Dockerfile)      OLS + lsphp83 + baked SPA
#   flowone-collab    (docker/collab/Dockerfile)   Hocuspocus WS
#   flowone-mailsync  (docker/mailsync/Dockerfile) IMAP-IDLE WS
#
# Default is BUILD ONLY (safe). Pass --push to also push (requires a prior
# `docker login` to the registry).
#
# Usage:
#   ./build-and-push.sh                          # build all 3 locally, tag, print sizes
#   ./build-and-push.sh --push                   # build + push all 3
#   ./build-and-push.sh --service=web --push     # single service
#   ./build-and-push.sh --registry=ghcr.io/acme --tag=v1 --push
#   ./build-and-push.sh --help
#
# Registry/tag resolution (highest first): flag > env (DOCKER_REGISTRY/DOCKER_TAG)
# > default (ghcr.io/flowonedev : latest). Keep this in sync with the Fleet
# `docker` config block (fleet/api/config.php).
#
# GHCR login (once, before --push):
#   echo "$GHCR_TOKEN" | docker login ghcr.io -u <github-user> --password-stdin
#   (token = a GitHub Personal Access Token (classic) with write:packages)
set -euo pipefail

REGISTRY="${DOCKER_REGISTRY:-ghcr.io/flowonedev}"
TAG="${DOCKER_TAG:-latest}"
PUSH=0
ONLY_SERVICE=""

for arg in "$@"; do
    case "$arg" in
        --help|-h)
            # Print the leading comment block (line 2 until the first non-# line).
            awk 'NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"
            exit 0 ;;
        --push)            PUSH=1 ;;
        --registry=*)      REGISTRY="${arg#*=}" ;;
        --tag=*)           TAG="${arg#*=}" ;;
        --service=*)       ONLY_SERVICE="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 1 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"   # email/docker
EMAIL_DIR="$(dirname "$SCRIPT_DIR")"                          # email (build context)

# service name -> Dockerfile (relative to EMAIL_DIR)
SERVICES=("web" "collab" "mailsync")
declare -A DOCKERFILES=(
    [web]="docker/web/Dockerfile"
    [collab]="docker/collab/Dockerfile"
    [mailsync]="docker/mailsync/Dockerfile"
)

if [ -n "$ONLY_SERVICE" ]; then
    if [ -z "${DOCKERFILES[$ONLY_SERVICE]:-}" ]; then
        echo "Unknown service '$ONLY_SERVICE' (expected: ${SERVICES[*]})" >&2
        exit 1
    fi
    SERVICES=("$ONLY_SERVICE")
fi

echo "==> Registry: ${REGISTRY}   Tag: ${TAG}   Push: $([ "$PUSH" = 1 ] && echo yes || echo no)"
echo "==> Build context: ${EMAIL_DIR}"

build_one() {
    local svc="$1"
    local dockerfile="${EMAIL_DIR}/${DOCKERFILES[$svc]}"
    local image="${REGISTRY}/flowone-${svc}:${TAG}"

    echo ""
    echo "==> Building ${image}"
    echo "    dockerfile: ${dockerfile}"
    docker build -f "$dockerfile" -t "$image" "$EMAIL_DIR"
}

push_one() {
    local svc="$1"
    local image="${REGISTRY}/flowone-${svc}:${TAG}"
    echo "==> Pushing ${image}"
    docker push "$image"
}

for svc in "${SERVICES[@]}"; do
    build_one "$svc"
done

echo ""
echo "==> Built images:"
docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}' | grep -E "flowone-(web|collab|mailsync):" || true

if [ "$PUSH" = 1 ]; then
    # Fail early with a clear message if not logged in to the registry host.
    reg_host="${REGISTRY%%/*}"
    if ! docker system info 2>/dev/null | grep -qi "${reg_host}"; then
        : # login state isn't always visible via `docker system info`; push will surface auth errors.
    fi
    echo ""
    for svc in "${SERVICES[@]}"; do
        push_one "$svc"
    done
    echo ""
    echo "==> Push complete. Servers can now: docker compose pull"
else
    echo ""
    echo "==> Build only (no push). Re-run with --push once logged in:"
    echo "    echo \"\$GHCR_TOKEN\" | docker login ${REGISTRY%%/*} -u <github-user> --password-stdin"
    echo "    ${BASH_SOURCE[0]} --registry=${REGISTRY} --tag=${TAG} --push"
fi
