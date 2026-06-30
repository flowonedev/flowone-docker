#!/bin/bash
# ===========================================================================
# verify-patches.sh -- Read-only pre-flight for the staged code patches.
#
# Runs `php -l` on every file under cutover/code-patches/ (excluding
# frontend/) so the operator can catch syntax errors BEFORE running
# apply-patches.sh. Touches nothing in the production tree.
#
# Usage:
#   cd /var/www/vps-email/backend
#   bash cutover/verify-patches.sh
#
# Exit code: 0 on all-pass, 1 on any failure.
# ===========================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PATCH_ROOT="$SCRIPT_DIR/code-patches"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"

if [[ ! -d "$PATCH_ROOT" ]]; then
    echo "[verify-patches] FATAL: $PATCH_ROOT not found." >&2
    exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
    echo "[verify-patches] FATAL: PHP binary not executable: $PHP_BIN" >&2
    exit 1
fi

mapfile -t PHP_FILES < <(
    find "$PATCH_ROOT" -type f -name '*.php' \
        -not -path "$PATCH_ROOT/frontend/*" \
        | sort
)

echo "[verify-patches] $(date) -- php -l on ${#PHP_FILES[@]} staged file(s)"

FAIL=0
for f in "${PHP_FILES[@]}"; do
    rel="${f#$SCRIPT_DIR/}"
    if "$PHP_BIN" -l "$f" >/dev/null 2>&1; then
        echo "  ok    $rel"
    else
        echo "  FAIL  $rel"
        "$PHP_BIN" -l "$f" || true
        FAIL=$((FAIL + 1))
    fi
done

echo
if [[ $FAIL -gt 0 ]]; then
    echo "[verify-patches] $FAIL file(s) failed php -l."
    exit 1
fi
echo "[verify-patches] all ${#PHP_FILES[@]} file(s) pass."
