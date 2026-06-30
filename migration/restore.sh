#!/usr/bin/env bash
#
# restore.sh — restore a snapshot.sh bundle onto a NEW FlowOne Docker box.
#
# Runs on the new server, after `docker compose up -d` has created the named
# volumes. It verifies the manifest checksums first, then:
#   - DB      : pipes db-all-databases.sql.gz into the `mariadb` container.
#   - drive   : extracts drive.tar.gz INTO the storage volume (vps_email_storage).
#   - meili   : extracts meili.tar.gz INTO the meili volume.
#   - jwt     : places the RS256 PEM pair INTO the jwt_keys volume.
#   - flowone : restores /etc/flowone/{master,state}.key + storage.local.php to the host.
#   - vmail/homes/certs/dkim : extract to host paths (for the Phase E mail pod);
#                              these default to the native locations and warn if
#                              the target is the bridge-only box.
#   - env     : extracts the source .env / fleet config.local.php to <bundle>/_restored-secrets/
#               for you to MERGE by hand (never blindly overwrites a live .env).
#
# Safe by default: --dry-run prints the plan and touches nothing; the DB restore
# refuses to run unless you pass --yes (it overwrites databases).
#
# Usage:
#   ./restore.sh --bundle=DIR --dry-run
#   ./restore.sh --bundle=DIR --db-only --yes
#   ./restore.sh --bundle=DIR --yes
#
# Flags:
#   --bundle=DIR             snapshot bundle to restore (required)
#   --yes                    actually perform writes (required for non-dry-run DB/vol writes)
#   --db-only                restore only the database
#   --no-db                  restore everything EXCEPT the database
#   --skip=a,b,c             skip artifacts (drive,meili,jwt,flowone,vmail,homes,certs,dkim,env)
#   --dry-run                print plan, verify checksums, write nothing
#   --verbose / --help
#
#   --project=NAME           compose project name (default: flowone) -> volume prefix
#   --mariadb-container=NAME (default: <project>-mariadb-1)
#   --db-root-pass=PASS      MariaDB root pass (or env MYSQL_ROOT_PASSWORD)
#   --vol-storage=NAME       (default: <project>_vps_email_storage)
#   --vol-meili=NAME         (default: <project>_meili_data)
#   --vol-jwt=NAME           (default: <project>_jwt_keys)
#   --vmail=PATH (/home/vmail)  --homes=PATH (/home)
#   --certs=PATH (/etc/letsencrypt)  --dkim=PATH (/etc/opendkim)
#   --helper-image=IMG       throwaway image for volume writes (default: alpine:3.20)
#
set -euo pipefail

BUNDLE=""
ASSUME_YES=0
DB_ONLY=0
NO_DB=0
DRY_RUN=0
VERBOSE=0
SKIP_CSV=""

PROJECT="flowone"
MARIADB_CONTAINER=""
DB_ROOT_PASS="${MYSQL_ROOT_PASSWORD:-}"
VOL_STORAGE=""
VOL_MEILI=""
VOL_JWT=""
VMAIL_DIR="/home/vmail"
HOMES_DIR="/home"
CERTS_DIR="/etc/letsencrypt"
DKIM_DIR="/etc/opendkim"
HELPER_IMAGE="alpine:3.20"

C_GREEN=$'\033[0;32m'; C_RED=$'\033[0;31m'; C_YEL=$'\033[0;33m'; C_RST=$'\033[0m'
log()  { echo "${C_GREEN}[restore]${C_RST} $*"; }
warn() { echo "${C_YEL}[restore] WARN:${C_RST} $*" >&2; }
die()  { echo "${C_RED}[restore] ERROR:${C_RST} $*" >&2; exit 1; }
vlog() { [ "$VERBOSE" -eq 1 ] && echo "[restore] $*" || true; }
print_help() { sed -n '2,/^set -euo/p' "$0" | sed 's/^# \{0,1\}//; s/^#//' | sed '$d'; }

for arg in "$@"; do
  case "$arg" in
    --help|-h) print_help; exit 0 ;;
    --yes) ASSUME_YES=1 ;;
    --db-only) DB_ONLY=1 ;;
    --no-db) NO_DB=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --verbose) VERBOSE=1 ;;
    --bundle=*) BUNDLE="${arg#*=}" ;;
    --skip=*) SKIP_CSV="${arg#*=}" ;;
    --project=*) PROJECT="${arg#*=}" ;;
    --mariadb-container=*) MARIADB_CONTAINER="${arg#*=}" ;;
    --db-root-pass=*) DB_ROOT_PASS="${arg#*=}" ;;
    --vol-storage=*) VOL_STORAGE="${arg#*=}" ;;
    --vol-meili=*) VOL_MEILI="${arg#*=}" ;;
    --vol-jwt=*) VOL_JWT="${arg#*=}" ;;
    --vmail=*) VMAIL_DIR="${arg#*=}" ;;
    --homes=*) HOMES_DIR="${arg#*=}" ;;
    --certs=*) CERTS_DIR="${arg#*=}" ;;
    --dkim=*) DKIM_DIR="${arg#*=}" ;;
    --helper-image=*) HELPER_IMAGE="${arg#*=}" ;;
    *) die "unknown arg: $arg (try --help)" ;;
  esac
done

[ -n "$BUNDLE" ] || die "--bundle=DIR is required"
[ -d "$BUNDLE" ] || die "bundle dir not found: $BUNDLE"
: "${MARIADB_CONTAINER:=${PROJECT}-mariadb-1}"
: "${VOL_STORAGE:=${PROJECT}_vps_email_storage}"
: "${VOL_MEILI:=${PROJECT}_meili_data}"
: "${VOL_JWT:=${PROJECT}_jwt_keys}"

is_skipped() { case ",$SKIP_CSV," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }
guard_write() {
  if [ "$DRY_RUN" -eq 1 ]; then return 1; fi
  if [ "$ASSUME_YES" -ne 1 ]; then die "refusing to write without --yes (or use --dry-run)"; fi
  return 0
}

# ---- manifest verification --------------------------------------------------
verify_manifest() {
  local mf="$BUNDLE/manifest.txt"
  [ -f "$mf" ] || { warn "no manifest.txt in bundle — cannot verify integrity"; return 0; }
  command -v sha256sum >/dev/null || { warn "sha256sum unavailable — skipping verify"; return 0; }
  local bad=0 n=0
  while read -r sum size file; do
    case "$sum" in \#*|"") continue ;; esac
    [ "$sum" = "-" ] && continue
    n=$((n+1))
    local f="$BUNDLE/$file"
    [ -f "$f" ] || { warn "manifest lists missing file: $file"; bad=$((bad+1)); continue; }
    local actual; actual=$(sha256sum "$f" | awk '{print $1}')
    if [ "$actual" != "$sum" ]; then warn "checksum MISMATCH: $file"; bad=$((bad+1)); else vlog "ok: $file"; fi
  done < "$mf"
  [ "$bad" -eq 0 ] || die "manifest verification failed ($bad bad of $n)"
  log "manifest verified ($n artifacts)"
}

# ---- helpers ----------------------------------------------------------------
# Extract bundle/<name>.tar.gz into a named docker volume (strip the top dir
# so contents land at the volume root). The snapshot tars as "<basename>/...".
restore_into_volume() { # name  volume
  local name="$1" vol="$2" f="$BUNDLE/$1.tar.gz"
  if is_skipped "$name"; then log "skip $name (--skip)"; return 0; fi
  [ -f "$f" ] || { warn "$name: $f not in bundle, skipping"; return 0; }
  if [ "$DRY_RUN" -eq 1 ]; then log "would restore $name -> volume $vol (strip top dir)"; return 0; fi
  guard_write || return 0
  log "restoring $name -> volume $vol"
  docker run --rm -v "$vol":/dst -v "$BUNDLE":/src:ro "$HELPER_IMAGE" \
    sh -c "tar xzf /src/$name.tar.gz -C /dst --strip-components=1"
}

restore_into_path() { # name  path
  local name="$1" dst="$2" f="$BUNDLE/$1.tar.gz"
  if is_skipped "$name"; then log "skip $name (--skip)"; return 0; fi
  [ -f "$f" ] || { warn "$name: $f not in bundle, skipping"; return 0; }
  if [ "$DRY_RUN" -eq 1 ]; then log "would restore $name -> $dst"; return 0; fi
  guard_write || return 0
  log "restoring $name -> $dst"
  mkdir -p "$(dirname "$dst")"
  tar xzf "$f" -C "$(dirname "$dst")"
}

# ---- preflight --------------------------------------------------------------
command -v docker >/dev/null || die "docker not found in PATH"
log "FlowOne restore — bundle: $BUNDLE  project: $PROJECT"
verify_manifest

# ---- 1. database ------------------------------------------------------------
if [ "$NO_DB" -eq 0 ]; then
  DBF="$BUNDLE/db-all-databases.sql.gz"
  if [ ! -f "$DBF" ]; then warn "no db dump in bundle"; else
    if [ "$DRY_RUN" -eq 1 ]; then
      log "would restore DB: gunzip db-all-databases.sql.gz | $MARIADB_CONTAINER mariadb"
    else
      guard_write && {
        docker inspect "$MARIADB_CONTAINER" >/dev/null 2>&1 || die "container not found: $MARIADB_CONTAINER"
        [ -n "$DB_ROOT_PASS" ] || die "DB root password required (--db-root-pass or MYSQL_ROOT_PASSWORD)"
        log "restoring DB into $MARIADB_CONTAINER (this overwrites existing databases)"
        gunzip -c "$DBF" | docker exec -i -e MYSQL_PWD="$DB_ROOT_PASS" "$MARIADB_CONTAINER" mariadb -uroot
        log "DB restore complete"
      }
    fi
  fi
fi
[ "$DB_ONLY" -eq 1 ] && { log "done (--db-only)"; exit 0; }

# ---- 2. volumes -------------------------------------------------------------
restore_into_volume drive "$VOL_STORAGE"
restore_into_volume meili "$VOL_MEILI"

# jwt: PEMs live under jwt/ inside secrets.tar.gz, handled in section 4.

# ---- 3. host paths (for the Phase E mail pod) ------------------------------
restore_into_path vmail "$VMAIL_DIR"
restore_into_path homes "$HOMES_DIR"
restore_into_path certs "$CERTS_DIR"
restore_into_path dkim  "$DKIM_DIR"

# ---- 4. secrets -------------------------------------------------------------
if ! is_skipped flowone && [ -f "$BUNDLE/secrets.tar.gz" ]; then
  if [ "$DRY_RUN" -eq 1 ]; then
    log "would restore secrets: jwt PEMs -> volume $VOL_JWT; /etc/flowone keys -> host; .env/fleet -> _restored-secrets/ for manual merge"
  else
    guard_write && {
      TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
      tar xzf "$BUNDLE/secrets.tar.gz" -C "$TMP"

      # JWT PEMs into the jwt_keys volume (backend signs; collab/mailsync verify).
      if [ -f "$TMP/jwt/jwt-private.pem" ]; then
        log "placing JWT PEM pair into volume $VOL_JWT"
        docker run --rm -v "$VOL_JWT":/dst -v "$TMP/jwt":/src:ro "$HELPER_IMAGE" \
          sh -c 'cp /src/jwt-*.pem /dst/ && chmod 600 /dst/jwt-private.pem && chmod 644 /dst/jwt-public.pem'
      fi

      # /etc/flowone keys onto the host (SecretVault master.key + storage state.key).
      if [ -d "$TMP/etc-flowone" ]; then
        log "placing /etc/flowone keys on host"
        mkdir -p /etc/flowone
        cp -a "$TMP/etc-flowone/." /etc/flowone/ 2>/dev/null || warn "could not write /etc/flowone (need root?)"
      fi

      # .env + fleet config: never auto-overwrite a live .env. Stage for manual merge.
      mkdir -p "$BUNDLE/_restored-secrets"
      [ -f "$TMP/env/email.env" ]      && cp "$TMP/env/email.env" "$BUNDLE/_restored-secrets/email.env"
      [ -f "$TMP/fleet/config.local.php" ] && cp "$TMP/fleet/config.local.php" "$BUNDLE/_restored-secrets/fleet-config.local.php"
      warn "Source .env / fleet config staged in $BUNDLE/_restored-secrets/ — MERGE IMAP_ENCRYPTION_KEY + OAUTH_KEYS into the new .env by hand, then recreate the stack."
      rm -rf "$TMP"; trap - EXIT
    }
  fi
fi

if [ "$DRY_RUN" -eq 1 ]; then log "dry-run complete — nothing written."; else log "restore complete."; fi
