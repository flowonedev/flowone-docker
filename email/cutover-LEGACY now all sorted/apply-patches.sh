#!/bin/bash
# ===========================================================================
# apply-patches.sh -- Stage 2 of the canonical-identity cutover.
#
# Copies every cleaned PHP file from cutover/code-patches/ into its
# production location, taking a timestamped backup of each original first.
# Every file is php -l-checked twice (source + applied). If any check
# fails the script aborts with the original tree untouched (because the
# php -l of the source happens BEFORE we write to production).
#
# Run on the VPS, AS root, FROM the backend root:
#
#   cd /var/www/vps-email/backend
#   bash cutover/apply-patches.sh
#
# Reversal: bash cutover/revert-patches.sh
# Verification only (no writes): bash cutover/verify-patches.sh
#
# Excluded from this stage by design:
#   - cutover/code-patches/frontend/**     (deferred deploy)
# ===========================================================================

set -euo pipefail

# --- Where am I? --------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PATCH_ROOT="$SCRIPT_DIR/code-patches"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$SCRIPT_DIR/backups/code-$TIMESTAMP"
LATEST_LINK="$SCRIPT_DIR/backups/latest"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"

# --- Sanity checks ------------------------------------------------------
if [[ ! -d "$PATCH_ROOT" ]]; then
    echo "[apply-patches] FATAL: $PATCH_ROOT not found." >&2
    exit 1
fi

if [[ ! -x "$PHP_BIN" ]]; then
    echo "[apply-patches] FATAL: PHP binary not executable: $PHP_BIN" >&2
    echo "                Set PHP_BIN env var to override." >&2
    exit 1
fi

# --- Build the list of files to ship -----------------------------------
# We mirror code-patches/ -> backend/, EXCLUDING the frontend/ subtree.
mapfile -t PATCH_FILES < <(
    find "$PATCH_ROOT" -type f \
        \( -name '*.php' -o -name '*.sh' \) \
        -not -path "$PATCH_ROOT/frontend/*" \
        | sort
)

if [[ ${#PATCH_FILES[@]} -eq 0 ]]; then
    echo "[apply-patches] FATAL: no patch files found under $PATCH_ROOT" >&2
    exit 1
fi

echo "[apply-patches] $(date) -- canonical-identity Stage 2 (code patches)"
echo "[apply-patches] backend root: $BACKEND_ROOT"
echo "[apply-patches] patch root  : $PATCH_ROOT"
echo "[apply-patches] backup dir  : $BACKUP_DIR"
echo "[apply-patches] file count  : ${#PATCH_FILES[@]}"
echo

# --- Step 1: pre-flight syntax check on EVERY source patch -------------
echo "[apply-patches] STEP 1 -- php -l on every staged patch (source side)"
PRE_FAIL=0
for src in "${PATCH_FILES[@]}"; do
    if [[ "$src" == *.php ]]; then
        if ! "$PHP_BIN" -l "$src" >/dev/null 2>&1; then
            echo "  FAIL  ${src#$SCRIPT_DIR/}"
            "$PHP_BIN" -l "$src" || true
            PRE_FAIL=$((PRE_FAIL + 1))
        else
            echo "  ok    ${src#$SCRIPT_DIR/}"
        fi
    fi
done
if [[ $PRE_FAIL -gt 0 ]]; then
    echo
    echo "[apply-patches] ABORT: $PRE_FAIL source patch(es) failed php -l." >&2
    echo "                Production tree untouched. Fix the patches and rerun." >&2
    exit 1
fi
echo

# --- Step 2: take backups + copy in ------------------------------------
mkdir -p "$BACKUP_DIR"
echo "[apply-patches] STEP 2 -- back up + copy"

APPLIED=()
for src in "${PATCH_FILES[@]}"; do
    rel="${src#$PATCH_ROOT/}"
    target="$BACKEND_ROOT/$rel"
    backup="$BACKUP_DIR/$rel"

    if [[ ! -f "$target" ]]; then
        echo "  skip  $rel (no original to replace)"
        continue
    fi

    mkdir -p "$(dirname "$backup")"
    cp -p "$target" "$backup"

    cp -p "$src" "$target"
    APPLIED+=("$target")
    echo "  copy  $rel"
done

# Convenience symlink: backups/latest always points at the most recent
# stage-2 backup so revert-patches.sh has zero arguments.
ln -sfn "$BACKUP_DIR" "$LATEST_LINK"
echo
echo "[apply-patches] backed up ${#APPLIED[@]} files; latest -> $BACKUP_DIR"
echo

# --- Step 3: post-apply syntax check on the production targets ---------
echo "[apply-patches] STEP 3 -- php -l on every applied target"
POST_FAIL=0
for target in "${APPLIED[@]}"; do
    if [[ "$target" == *.php ]]; then
        if ! "$PHP_BIN" -l "$target" >/dev/null 2>&1; then
            echo "  FAIL  ${target#$BACKEND_ROOT/}"
            "$PHP_BIN" -l "$target" || true
            POST_FAIL=$((POST_FAIL + 1))
        else
            echo "  ok    ${target#$BACKEND_ROOT/}"
        fi
    fi
done

if [[ $POST_FAIL -gt 0 ]]; then
    echo
    echo "[apply-patches] FATAL: $POST_FAIL applied file(s) failed php -l." >&2
    echo "                Auto-reverting from $BACKUP_DIR ..." >&2
    for target in "${APPLIED[@]}"; do
        rel="${target#$BACKEND_ROOT/}"
        backup="$BACKUP_DIR/$rel"
        if [[ -f "$backup" ]]; then
            cp -p "$backup" "$target"
            echo "  revert $rel"
        fi
    done
    rm -f "$LATEST_LINK"
    echo "[apply-patches] revert complete; production tree restored." >&2
    exit 1
fi

echo
echo "[apply-patches] DONE -- all ${#APPLIED[@]} file(s) applied + verified."
echo "                Restart OLS:  sudo /usr/local/lsws/bin/lswsctrl restart"
echo "                Revert with:  bash $SCRIPT_DIR/revert-patches.sh"
