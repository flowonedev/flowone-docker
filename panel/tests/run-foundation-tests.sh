#!/bin/bash
#
# Foundation Test Runner
# Runs all 6 foundation suites in order. Exits non-zero on any failure.
#
# Usage:
#   ./run-foundation-tests.sh              # full suite, verbose terminal output
#   ./run-foundation-tests.sh --smoke      # connectivity + schema only
#   ./run-foundation-tests.sh --json       # machine-readable JSON
#
# Server invocation:
#   bash /var/www/vps-admin/tests/run-foundation-tests.sh --verbose
#

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php || echo php)"
fi

FLAGS=()
for arg in "$@"; do FLAGS+=("$arg"); done

SUITES=(
    "capabilities-test.php"
    "audit-log-test.php"
    "state-machine-test.php"
    "site-lock-test.php"
    "secret-vault-test.php"
    "secret-leak-test.php"
)

PASS=0
FAIL=0
FAILED_SUITES=()

for suite in "${SUITES[@]}"; do
    echo
    echo "========================================================"
    echo " Running ${suite}"
    echo "========================================================"
    if "$PHP_BIN" "$SCRIPT_DIR/$suite" "${FLAGS[@]+"${FLAGS[@]}"}"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        FAILED_SUITES+=("$suite")
    fi
done

echo
echo "========================================================"
echo " Foundation suite summary"
echo "========================================================"
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
if [ ${#FAILED_SUITES[@]} -gt 0 ]; then
    echo
    echo "Failed suites:"
    for s in "${FAILED_SUITES[@]}"; do
        echo "  - $s"
    done
fi

[ "$FAIL" -eq 0 ]
