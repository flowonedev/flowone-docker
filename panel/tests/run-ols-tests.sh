#!/bin/bash
#
# Step 2 Test Runner (OLS parser/mutator/writer + Step contracts)
#
# Runs the four Step 2 suites that exercise the OLS AST/parser/mutator/writer
# and the StepInterface contract layer. Foundation suites are run separately
# via run-foundation-tests.sh.
#
# Usage:
#   ./run-ols-tests.sh              # full suite, verbose terminal output
#   ./run-ols-tests.sh --smoke      # connectivity + schema only
#   ./run-ols-tests.sh --json       # machine-readable JSON
#
# Server invocation:
#   bash /var/www/vps-admin/tests/run-ols-tests.sh --verbose
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
    "ols-parser-test.php"
    "ols-mutator-test.php"
    "ols-writer-test.php"
    "step-contract-test.php"
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
echo " Step 2 suite summary"
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
