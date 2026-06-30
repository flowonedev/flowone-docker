#!/usr/bin/env bash
#
# snapshot.sh — capture a FlowOne *native* server's MIGRATE bucket into one
# portable, checksummed bundle, ready for restore.sh on a new Docker box.
#
# Runs on the OLD native server (the live one being migrated). It is read-only
# with respect to live data: it only dumps/copies, never deletes or mutates.
#
# Captures (see PLAN.md "three migration buckets"):
#   - DB      : ONE `mysqldump --all-databases` (devc_vps_dash + every per-site
#               WordPress DB + the PowerDNS gmysql tables + the mail DB), gzipped.
#               Single dump, not selective — keeps pdns/WP consistent with the rest.
#   - vmail   : /home/vmail (maildirs)
#   - homes   : /home/<domain> site roots (excludes vmail)
#   - drive   : <install>/storage (drive files, portal docs, mood uploads)
#   - certs   : /etc/letsencrypt
#   - dkim    : /etc/opendkim (keys + KeyTable/SigningTable/TrustedHosts)
#   - meili   : Meilisearch data dir (optional; re-indexable, captured to skip a reindex)
#   - secrets : the NON-REGENERABLE keys — losing any of these bricks encrypted
#               data or logs everyone out:
#                 /etc/flowone/master.key      (SecretVault)
#                 /etc/flowone/state.key       (storage HMAC)
#                 /etc/flowone/storage.local.php
#                 <install>/backend/storage/config/jwt-private.pem + jwt-public.pem
#                 <install>/backend/.env       (IMAP_ENCRYPTION_KEY + OAUTH_KEYS live here)
#                 <fleet>/api/config.local.php (Fleet AES-256-GCM encryption.key)  [if present]
#   - manifest: sha256 + byte size of every artifact, plus host/version metadata.
#
# Usage:
#   sudo ./snapshot.sh --out=/mnt/vps-backup/snap-$(date +%F)            # full
#   sudo ./snapshot.sh --out=DIR --db-only                              # just the DB
#   sudo ./snapshot.sh --out=DIR --dry-run                              # plan only
#
# Flags:
#   --out=DIR            destination bundle dir (required unless --dry-run)
#   --db-only            capture only the database dump
#   --no-db              capture everything EXCEPT the database
#   --skip=a,b,c         skip named artifacts (vmail,homes,drive,certs,dkim,meili,secrets)
#   --dry-run            print what would be captured (+ sizes) and exit; touch nothing
#   --verbose            extra output
#   --help               this banner
#
# DB connection (env or flag):
#   --db-host=  (DB_HOST, default 127.0.0.1)   --db-user= (DB_USER, default root)
#   --db-pass=  (DB_PASS)                       --db-port= (DB_PORT, default 3306)
# Paths (env or flag), with native defaults:
#   --install=  (INSTALL_PATH, /var/www/vps-email)
#   --vmail=    (VMAIL_DIR,    /home/vmail)
#   --homes=    (HOMES_DIR,    /home)
#   --meili=    (MEILI_DATA,   /var/lib/meilisearch)
#   --fleet=    (FLEET_PATH,   /var/www/vps-fleet)
#
set -euo pipefail

# ---- defaults ---------------------------------------------------------------
OUT_DIR=""
DB_ONLY=0
NO_DB=0
DRY_RUN=0
VERBOSE=0
SKIP_CSV=""

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

INSTALL_PATH="${INSTALL_PATH:-/var/www/vps-email}"
VMAIL_DIR="${VMAIL_DIR:-/home/vmail}"
HOMES_DIR="${HOMES_DIR:-/home}"
MEILI_DATA="${MEILI_DATA:-/var/lib/meilisearch}"
FLEET_PATH="${FLEET_PATH:-/var/www/vps-fleet}"

C_GREEN=$'\033[0;32m'; C_RED=$'\033[0;31m'; C_YEL=$'\033[0;33m'; C_RST=$'\033[0m'
log()  { echo "${C_GREEN}[snapshot]${C_RST} $*"; }
warn() { echo "${C_YEL}[snapshot] WARN:${C_RST} $*" >&2; }
die()  { echo "${C_RED}[snapshot] ERROR:${C_RST} $*" >&2; exit 1; }
vlog() { [ "$VERBOSE" -eq 1 ] && echo "[snapshot] $*" || true; }

print_help() { sed -n '2,/^set -euo/p' "$0" | sed 's/^# \{0,1\}//; s/^#//' | sed '$d'; }

# ---- args -------------------------------------------------------------------
for arg in "$@"; do
  case "$arg" in
    --help|-h) print_help; exit 0 ;;
    --db-only) DB_ONLY=1 ;;
    --no-db)   NO_DB=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --verbose) VERBOSE=1 ;;
    --out=*)     OUT_DIR="${arg#*=}" ;;
    --skip=*)    SKIP_CSV="${arg#*=}" ;;
    --db-host=*) DB_HOST="${arg#*=}" ;;
    --db-port=*) DB_PORT="${arg#*=}" ;;
    --db-user=*) DB_USER="${arg#*=}" ;;
    --db-pass=*) DB_PASS="${arg#*=}" ;;
    --install=*) INSTALL_PATH="${arg#*=}" ;;
    --vmail=*)   VMAIL_DIR="${arg#*=}" ;;
    --homes=*)   HOMES_DIR="${arg#*=}" ;;
    --meili=*)   MEILI_DATA="${arg#*=}" ;;
    --fleet=*)   FLEET_PATH="${arg#*=}" ;;
    *) die "unknown arg: $arg (try --help)" ;;
  esac
done

is_skipped() { case ",$SKIP_CSV," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

# pick mysqldump binary (mariadb-dump on newer MariaDB)
DUMP_BIN="$(command -v mysqldump || command -v mariadb-dump || true)"
[ "$NO_DB" -eq 1 ] || [ -n "$DUMP_BIN" ] || die "neither mysqldump nor mariadb-dump found in PATH"

if [ "$DRY_RUN" -eq 0 ]; then
  [ -n "$OUT_DIR" ] || die "--out=DIR is required (or use --dry-run)"
  mkdir -p "$OUT_DIR"
fi

MANIFEST="${OUT_DIR:-/dev/stdout}/manifest.txt"
SHA="$(command -v sha256sum || true)"

# Record one artifact's checksum + size into the manifest.
record() {
  local f="$1"
  [ "$DRY_RUN" -eq 1 ] && return 0
  local size; size=$(stat -c '%s' "$f" 2>/dev/null || echo 0)
  local sum="-"; [ -n "$SHA" ] && sum=$("$SHA" "$f" | awk '{print $1}')
  printf '%s  %s  %s\n' "$sum" "$size" "$(basename "$f")" >> "$MANIFEST"
  vlog "recorded $(basename "$f") (${size} bytes)"
}

# tar a directory into the bundle if it exists.
tar_dir() {  # name  src  [extra tar args...]
  local name="$1"; local src="$2"; shift 2
  if is_skipped "$name"; then log "skip $name (--skip)"; return 0; fi
  if [ ! -e "$src" ]; then warn "$name: source not found, skipping: $src"; return 0; fi
  local dst="${OUT_DIR}/${name}.tar.gz"
  if [ "$DRY_RUN" -eq 1 ]; then
    local approx; approx=$(du -sh "$src" 2>/dev/null | awk '{print $1}')
    log "would capture $name <- $src (${approx:-?})"
    return 0
  fi
  log "capturing $name <- $src"
  tar czf "$dst" "$@" -C "$(dirname "$src")" "$(basename "$src")"
  record "$dst"
}

# ---- preflight --------------------------------------------------------------
log "FlowOne native snapshot — $(date '+%F %T %Z') on $(hostname)"
[ "$(id -u)" -eq 0 ] || warn "not running as root — some paths (vmail, /etc/*) may be unreadable"

if [ "$DRY_RUN" -eq 0 ]; then
  : > "$MANIFEST"
  {
    echo "# FlowOne snapshot manifest"
    echo "# created: $(date '+%F %T %Z')"
    echo "# host:    $(hostname)"
    echo "# install: $INSTALL_PATH"
    [ -f "$INSTALL_PATH/VERSION" ] && echo "# version: $(cat "$INSTALL_PATH/VERSION")"
    echo "# format:  <sha256>  <bytes>  <file>"
  } >> "$MANIFEST"
fi

# ---- 1. database ------------------------------------------------------------
if [ "$NO_DB" -eq 0 ]; then
  DB_OUT="${OUT_DIR}/db-all-databases.sql.gz"
  if [ "$DRY_RUN" -eq 1 ]; then
    log "would mysqldump --all-databases from ${DB_USER}@${DB_HOST}:${DB_PORT} -> db-all-databases.sql.gz"
  else
    log "dumping ALL databases (single consistent dump)"
    # --single-transaction: consistent snapshot w/o locking InnoDB tables.
    # --routines/--triggers/--events: stored programs. --all-databases: WP + pdns + mail too.
    MYSQL_PWD="$DB_PASS" "$DUMP_BIN" \
      --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
      --single-transaction --quick --routines --triggers --events \
      --all-databases | gzip > "$DB_OUT"
    record "$DB_OUT"
  fi
fi
[ "$DB_ONLY" -eq 1 ] && { log "done (--db-only)"; exit 0; }

# ---- 2. filesystem trees ----------------------------------------------------
if [ "$NO_DB" -eq 0 ] || [ "$DB_ONLY" -eq 0 ]; then
  tar_dir vmail "$VMAIL_DIR"
  # homes: capture /home but EXCLUDE vmail (captured separately) to avoid dup.
  if ! is_skipped homes; then
    if [ -d "$HOMES_DIR" ]; then
      if [ "$DRY_RUN" -eq 1 ]; then
        log "would capture homes <- $HOMES_DIR (excluding $(basename "$VMAIL_DIR"))"
      else
        log "capturing homes <- $HOMES_DIR (excluding $(basename "$VMAIL_DIR"))"
        tar czf "${OUT_DIR}/homes.tar.gz" --exclude="$(basename "$VMAIL_DIR")" \
          -C "$(dirname "$HOMES_DIR")" "$(basename "$HOMES_DIR")"
        record "${OUT_DIR}/homes.tar.gz"
      fi
    else warn "homes: $HOMES_DIR not found, skipping"; fi
  fi
  tar_dir drive "$INSTALL_PATH/storage"
  tar_dir certs "/etc/letsencrypt"
  tar_dir dkim  "/etc/opendkim"
  tar_dir meili "$MEILI_DATA"
fi

# ---- 3. non-regenerable secrets --------------------------------------------
if ! is_skipped secrets; then
  if [ "$DRY_RUN" -eq 1 ]; then
    log "would capture secrets: /etc/flowone/{master.key,state.key,storage.local.php}, jwt PEMs, backend/.env, fleet config.local.php"
  else
    SECRETS_TMP="$(mktemp -d)"
    trap 'rm -rf "$SECRETS_TMP"' EXIT
    copy_secret() { [ -e "$1" ] && { mkdir -p "$SECRETS_TMP/$(dirname "$2")"; cp -a "$1" "$SECRETS_TMP/$2"; vlog "secret: $1"; } || warn "secret missing: $1"; }
    copy_secret "/etc/flowone/master.key"            "etc-flowone/master.key"
    copy_secret "/etc/flowone/state.key"             "etc-flowone/state.key"
    copy_secret "/etc/flowone/storage.local.php"     "etc-flowone/storage.local.php"
    copy_secret "$INSTALL_PATH/backend/storage/config/jwt-private.pem" "jwt/jwt-private.pem"
    copy_secret "$INSTALL_PATH/backend/storage/config/jwt-public.pem"  "jwt/jwt-public.pem"
    copy_secret "$INSTALL_PATH/backend/.env"         "env/email.env"
    copy_secret "$FLEET_PATH/api/config.local.php"   "fleet/config.local.php"
    log "capturing secrets bundle (0600)"
    tar czf "${OUT_DIR}/secrets.tar.gz" -C "$SECRETS_TMP" .
    chmod 600 "${OUT_DIR}/secrets.tar.gz"
    record "${OUT_DIR}/secrets.tar.gz"
    rm -rf "$SECRETS_TMP"; trap - EXIT
  fi
fi

if [ "$DRY_RUN" -eq 1 ]; then
  log "dry-run complete — nothing written."
else
  log "snapshot complete -> $OUT_DIR"
  log "manifest:"
  sed 's/^/    /' "$MANIFEST"
fi
