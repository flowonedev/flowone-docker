#!/usr/bin/env bash
#
# build-and-push.sh — build the FlowOne app-tier images and (optionally) push
# them to a registry. Part of the native->docker migration (Phase D): servers
# provisioned by Fleet run `docker compose pull ${REGISTRY}/flowone-<svc>:${TAG}`,
# so those images must exist in the registry first.
#
# Builds these images:
#   flowone-web       (docker/web/Dockerfile,   ctx email/)     OLS + lsphp83 + SPA
#   flowone-collab    (docker/collab/Dockerfile, ctx email/)    Hocuspocus WS
#   flowone-mailsync  (docker/mailsync/Dockerfile, ctx email/)  IMAP-IDLE WS
#   flowone-mail      (docker/mail/Dockerfile,  ctx docker/mail) full mail stack
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

# service name -> Dockerfile + build context. The app tier shares the email/
# context; the mail pod is self-contained under docker/mail.
SERVICES=("web" "collab" "mailsync" "mail")
declare -A DOCKERFILES=(
    [web]="${EMAIL_DIR}/docker/web/Dockerfile"
    [collab]="${EMAIL_DIR}/docker/collab/Dockerfile"
    [mailsync]="${EMAIL_DIR}/docker/mailsync/Dockerfile"
    [mail]="${SCRIPT_DIR}/mail/Dockerfile"
)
declare -A CONTEXTS=(
    [web]="${EMAIL_DIR}"
    [collab]="${EMAIL_DIR}"
    [mailsync]="${EMAIL_DIR}"
    [mail]="${SCRIPT_DIR}/mail"
)

if [ -n "$ONLY_SERVICE" ]; then
    if [ -z "${DOCKERFILES[$ONLY_SERVICE]:-}" ]; then
        echo "Unknown service '$ONLY_SERVICE' (expected: ${SERVICES[*]})" >&2
        exit 1
    fi
    SERVICES=("$ONLY_SERVICE")
fi

echo "==> Registry: ${REGISTRY}   Tag: ${TAG}   Push: $([ "$PUSH" = 1 ] && echo yes || echo no)"

build_one() {
    local svc="$1"
    local dockerfile="${DOCKERFILES[$svc]}"
    local context="${CONTEXTS[$svc]}"
    local image="${REGISTRY}/flowone-${svc}:${TAG}"

    echo ""
    echo "==> Building ${image}"
    echo "    dockerfile: ${dockerfile}"
    echo "    context:    ${context}"
    docker build -f "$dockerfile" -t "$image" "$context"
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
docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}' | grep -E "flowone-(web|collab|mailsync|mail):" || true

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
