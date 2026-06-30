#!/usr/bin/env bash
#
# db-parity-check.sh — per-table row-count parity between two FlowOne databases.
#
# The migration safety gate: after a restore, prove the new DB has exactly the
# rows the old one did. Two modes:
#
#   1. COMPARE (Phase E/F, old vs new):
#        ./db-parity-check.sh --source=devc_vps_dash --target=devc_vps_dash \
#            --src-host=OLD --tgt-host=NEW [creds...]
#      For every BASE TABLE in --source, compares COUNT(*) to --target and
#      reports missing tables and row-count drift.
#
#   2. SELF-TEST (locally testable round-trip fidelity):
#        ./db-parity-check.sh --self-test --db=devc_vps_dash [creds...]
#      Dumps --db, restores it into a throwaway scratch DB on the SAME server,
#      compares the two, then drops the scratch DB. Proves dump+restore loses
#      no rows — exactly what snapshot.sh/restore.sh rely on. Idempotent;
#      scratch DB is `<db>_flowone_test_parity` and is always dropped.
#
# Designed to run where a MariaDB client is available and both DBs are
# reachable — e.g. inside the `mariadb` container:
#   docker cp migration/db-parity-check.sh flowone-mariadb-1:/tmp/
#   docker exec -e SRC_PASS="$ROOT" flowone-mariadb-1 \
#       bash /tmp/db-parity-check.sh --self-test --db=devc_vps_dash --src-user=root --verbose
#
# Flags:
#   --self-test            round-trip fidelity mode (needs --db)
#   --db=NAME              database for --self-test
#   --source=NAME --target=NAME   databases for COMPARE mode
#   --src-host/--src-port/--src-user/--src-pass   (or env SRC_HOST/SRC_PORT/SRC_USER/SRC_PASS)
#   --tgt-host/--tgt-port/--tgt-user/--tgt-pass   (default to SRC_* when omitted)
#   --json                 emit a JSON summary
#   --verbose / --help
#
# Exit 0 = parity holds; 1 = drift/missing tables or error.
#
set -euo pipefail

SELF_TEST=0
JSON=0
VERBOSE=0
DB=""
SOURCE=""
TARGET=""

SRC_HOST="${SRC_HOST:-127.0.0.1}"; SRC_PORT="${SRC_PORT:-3306}"; SRC_USER="${SRC_USER:-root}"; SRC_PASS="${SRC_PASS:-}"
TGT_HOST=""; TGT_PORT=""; TGT_USER=""; TGT_PASS=""

print_help() { sed -n '2,/^set -euo/p' "$0" | sed 's/^# \{0,1\}//; s/^#//' | sed '$d'; }
C_GREEN=$'\033[0;32m'; C_RED=$'\033[0;31m'; C_YEL=$'\033[0;33m'; C_RST=$'\033[0m'
log()  { echo "${C_GREEN}[parity]${C_RST} $*"; }
warn() { echo "${C_YEL}[parity] WARN:${C_RST} $*" >&2; }
die()  { echo "${C_RED}[parity] ERROR:${C_RST} $*" >&2; exit 1; }
vlog() { [ "$VERBOSE" -eq 1 ] && echo "[parity] $*" || true; }

for arg in "$@"; do
  case "$arg" in
    --help|-h) print_help; exit 0 ;;
    --self-test) SELF_TEST=1 ;;
    --json) JSON=1 ;;
    --verbose) VERBOSE=1 ;;
    --db=*) DB="${arg#*=}" ;;
    --source=*) SOURCE="${arg#*=}" ;;
    --target=*) TARGET="${arg#*=}" ;;
    --src-host=*) SRC_HOST="${arg#*=}" ;;
    --src-port=*) SRC_PORT="${arg#*=}" ;;
    --src-user=*) SRC_USER="${arg#*=}" ;;
    --src-pass=*) SRC_PASS="${arg#*=}" ;;
    --tgt-host=*) TGT_HOST="${arg#*=}" ;;
    --tgt-port=*) TGT_PORT="${arg#*=}" ;;
    --tgt-user=*) TGT_USER="${arg#*=}" ;;
    --tgt-pass=*) TGT_PASS="${arg#*=}" ;;
    *) die "unknown arg: $arg (try --help)" ;;
  esac
done

# Target connection defaults to the source connection (same server).
: "${TGT_HOST:=$SRC_HOST}"; : "${TGT_PORT:=$SRC_PORT}"; : "${TGT_USER:=$SRC_USER}"; : "${TGT_PASS:=$SRC_PASS}"

MYSQL_BIN="$(command -v mariadb || command -v mysql || true)"
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
[ -n "$MYSQL_BIN" ] || die "no mariadb/mysql client in PATH"

src_q() { MYSQL_PWD="$SRC_PASS" "$MYSQL_BIN" --host="$SRC_HOST" --port="$SRC_PORT" --user="$SRC_USER" --batch --skip-column-names -e "$1"; }
tgt_q() { MYSQL_PWD="$TGT_PASS" "$MYSQL_BIN" --host="$TGT_HOST" --port="$TGT_PORT" --user="$TGT_USER" --batch --skip-column-names -e "$1"; }

# List BASE TABLE names of a schema (on the source connection).
list_tables() { # schema  -> connfn
  local schema="$1" connfn="$2"
  $connfn "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;"
}

# Build a single UNION ALL that returns "<table>\t<count>" for every table.
counts_for() { # schema  connfn  "tab1 tab2 ..."
  local schema="$1" connfn="$2"; shift 2
  local tables="$*"
  [ -z "$tables" ] && return 0
  local sql="" first=1
  for t in $tables; do
    local sel="SELECT '$t' AS t, COUNT(*) AS c FROM \`$schema\`.\`$t\`"
    if [ $first -eq 1 ]; then sql="$sel"; first=0; else sql="$sql UNION ALL $sel"; fi
  done
  $connfn "$sql"
}

# Compare two schemas; sets globals MISMATCH, MISSING, NTAB. Prints a table.
compare_schemas() { # srcschema  tgtschema
  local s="$1" t="$2"
  local stabs; stabs=$(list_tables "$s" src_q | tr '\n' ' ')
  [ -n "$stabs" ] || die "source schema '$s' has no tables (or unreachable)"

  declare -A SC TC
  while IFS=$'\t' read -r name cnt; do [ -n "$name" ] && SC["$name"]="$cnt"; done < <(counts_for "$s" src_q $stabs)

  local ttabs; ttabs=$(list_tables "$t" tgt_q | tr '\n' ' ')
  if [ -n "$ttabs" ]; then
    while IFS=$'\t' read -r name cnt; do [ -n "$name" ] && TC["$name"]="$cnt"; done < <(counts_for "$t" tgt_q $ttabs)
  fi

  MISMATCH=0; MISSING=0; NTAB=0
  local json_rows=""
  for name in $(printf '%s\n' "${!SC[@]}" | sort); do
    NTAB=$((NTAB+1))
    local sc="${SC[$name]}" tc="${TC[$name]-__MISSING__}"
    if [ "$tc" = "__MISSING__" ]; then
      MISSING=$((MISSING+1)); warn "MISSING in target: $name (source rows=$sc)"
      json_rows="$json_rows{\"table\":\"$name\",\"source\":$sc,\"target\":null,\"ok\":false},"
    elif [ "$sc" != "$tc" ]; then
      MISMATCH=$((MISMATCH+1)); warn "DRIFT $name: source=$sc target=$tc"
      json_rows="$json_rows{\"table\":\"$name\",\"source\":$sc,\"target\":$tc,\"ok\":false},"
    else
      vlog "ok $name=$sc"
      json_rows="$json_rows{\"table\":\"$name\",\"source\":$sc,\"target\":$tc,\"ok\":true},"
    fi
  done

  if [ "$JSON" -eq 1 ]; then
    echo "{\"source\":\"$s\",\"target\":\"$t\",\"tables\":$NTAB,\"mismatch\":$MISMATCH,\"missing\":$MISSING,\"rows\":[${json_rows%,}]}"
  fi
}

# ---- run --------------------------------------------------------------------
if [ "$SELF_TEST" -eq 1 ]; then
  [ -n "$DB" ] || die "--self-test requires --db=NAME"
  [ -n "$DUMP_BIN" ] || die "no mariadb-dump/mysqldump in PATH for --self-test"
  SCRATCH="${DB}_flowone_test_parity"
  TMP="$(mktemp -d)"; DUMP="$TMP/dump.sql.gz"
  cleanup() { src_q "DROP DATABASE IF EXISTS \`$SCRATCH\`;" >/dev/null 2>&1 || true; rm -rf "$TMP"; }
  trap cleanup EXIT INT TERM

  log "self-test: dump '$DB' -> restore into scratch '$SCRATCH' -> compare"
  MYSQL_PWD="$SRC_PASS" "$DUMP_BIN" --host="$SRC_HOST" --port="$SRC_PORT" --user="$SRC_USER" \
    --single-transaction --quick --routines --triggers "$DB" | gzip > "$DUMP"
  vlog "dump size: $(stat -c %s "$DUMP" 2>/dev/null || echo '?') bytes"

  src_q "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\`;"
  gunzip -c "$DUMP" | MYSQL_PWD="$SRC_PASS" "$MYSQL_BIN" --host="$SRC_HOST" --port="$SRC_PORT" --user="$SRC_USER" "$SCRATCH"

  SOURCE="$DB"; TARGET="$SCRATCH"
  compare_schemas "$SOURCE" "$TARGET"
else
  [ -n "$SOURCE" ] && [ -n "$TARGET" ] || die "COMPARE mode needs --source and --target (or use --self-test)"
  compare_schemas "$SOURCE" "$TARGET"
fi

echo
if [ "${MISMATCH:-1}" -eq 0 ] && [ "${MISSING:-1}" -eq 0 ]; then
  log "PARITY OK — ${NTAB} tables, all row counts match ($SOURCE == $TARGET)"
  exit 0
else
  die "PARITY FAILED — tables=$NTAB drift=$MISMATCH missing=$MISSING"
fi
