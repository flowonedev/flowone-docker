#!/usr/bin/env bash
# Run all Project Hub CLI tests with JSON aggregation.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
SCRIPTS=(
  "$ROOT/project-hub-structure-test.php"
  "$ROOT/project-hub-cards-test.php"
  "$ROOT/project-hub-time-test.php"
  "$ROOT/project-hub-views-test.php"
  "$ROOT/project-hub-permissions-test.php"
  "$ROOT/project-hub-notifications-test.php"
)
EXTRA=("$@")
SUM_PASS=0
SUM_FAIL=0
SUM_WARN=0
for s in "${SCRIPTS[@]}"; do
  echo "=== $(basename "$s") ==="
  OUT="$("$PHP" "$s" --json "${EXTRA[@]}" 2>&1)" || true
  echo "$OUT"
  PASS="$(echo "$OUT" | sed -n 's/.*"passed":\([0-9]*\).*/\1/p' | tail -1)"
  FAIL="$(echo "$OUT" | sed -n 's/.*"failed":\([0-9]*\).*/\1/p' | tail -1)"
  WARN="$(echo "$OUT" | sed -n 's/.*"warnings":\([0-9]*\).*/\1/p' | tail -1)"
  SUM_PASS=$((SUM_PASS + ${PASS:-0}))
  SUM_FAIL=$((SUM_FAIL + ${FAIL:-0}))
  SUM_WARN=$((SUM_WARN + ${WARN:-0}))
done
echo "--- AGGREGATE --- passed=$SUM_PASS failed=$SUM_FAIL warnings=$SUM_WARN"
EXIT_CODE=$(( SUM_FAIL > 0 ? 1 : 0 ))
echo "=== projecthub-coverage-check.php ==="
"$PHP" "$ROOT/projecthub-coverage-check.php" || EXIT_CODE=1
echo "=== projecthub-cleanup-check.php (purge) ==="
"$PHP" "$ROOT/projecthub-cleanup-check.php" --purge || EXIT_CODE=1
exit "$EXIT_CODE"
