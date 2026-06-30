#!/bin/bash
#
# Step 3 Test Runner (infrastructure adapters)
#
# Runs the six Step 3 suites that exercise the CommandRunner +
# FilesystemAdapter + OlsAdapter + MysqlAdapter + SftpAdapter +
# NasAdapter layer.
#
# Some suites require root or specific binaries; they SKIP gracefully
# rather than fail. Read the per-suite output to see what's covered.
#
# Usage:
#   ./run-adapter-tests.sh              # full suite, verbose terminal output
#   ./run-adapter-tests.sh --smoke      # connectivity + schema only
#   ./run-adapter-tests.sh --json       # machine-readable JSON
#
# Server invocation:
#   sudo bash /var/www/vps-admin/tests/run-adapter-tests.sh --verbose
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
    "command-runner-test.php"
    "filesystem-adapter-test.php"
    "ols-adapter-test.php"
    "mysql-adapter-test.php"
    "sftp-adapter-test.php"
    "nas-adapter-test.php"
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
echo " Step 3 suite summary"
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
