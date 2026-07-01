#!/usr/bin/env bash
#
# gen-jwt-keys.sh — DEV/LOCAL ONLY. Generate an RS256 JWT key pair into the
# stack's `jwt_keys` named volume so a plain `docker compose up` (with no Fleet
# to seed it) can sign/verify tokens.
#
#   PRODUCTION seeds this volume from Fleet (DockerProvisioningService::
#   seedJwtVolume) or from the migration snapshot — NEVER use this script on a
#   real box: minting a NEW pair rotates the signing key and logs everyone out.
#
# Idempotent: if the volume already holds a key pair, it is left untouched.
# Uses the node:20 image (Debian, ships openssl) so no host openssl is needed —
# and that image is already pulled for the app builds.
#
# Usage:
#   ./gen-jwt-keys.sh                 # volume: flowone_jwt_keys (compose project 'flowone')
#   ./gen-jwt-keys.sh <volume_name>   # override the target volume
#   ./gen-jwt-keys.sh --help
set -euo pipefail

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
fi

VOLUME="${1:-flowone_jwt_keys}"

echo "Ensuring JWT key pair in Docker volume: ${VOLUME}"
docker volume create "${VOLUME}" >/dev/null

docker run --rm -v "${VOLUME}":/jwt node:20-bookworm-slim bash -c '
    set -e
    if [ -s /jwt/jwt-private.pem ] && [ -s /jwt/jwt-public.pem ]; then
        echo "  keys already present — leaving as-is (safe/idempotent)."
        exit 0
    fi
    openssl genrsa -out /jwt/jwt-private.pem 2048
    openssl rsa -in /jwt/jwt-private.pem -pubout -out /jwt/jwt-public.pem
    chmod 600 /jwt/jwt-private.pem
    chmod 644 /jwt/jwt-public.pem
    echo "  generated RS256 key pair (jwt-private.pem / jwt-public.pem)."
'

echo "Done. Bring the stack up with: docker compose -f docker/docker-compose.yml up -d"
