#!/bin/bash
# ===========================================================================
# revert-patches.sh -- Stage 2 rollback for the canonical-identity cutover.
#
# Restores every file in the most recent backup directory back into the
# backend tree. Each restored file is php -l-checked. Use this BEFORE
# running the column-drop migration (Stage 3) -- after Stage 3 the legacy
# columns are gone and reverting code without a database restore will not
# bring the system back to a working state.
#
# Run on the VPS, AS root, FROM the backend root:
#
#   cd /var/www/vps-email/backend
#   bash cutover/revert-patches.sh                # most recent backup
#   bash cutover/revert-patches.sh code-20260522-103022  # specific backup
#
# After revert: sudo /usr/local/lsws/bin/lswsctrl restart
# ===========================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_ROOT="$SCRIPT_DIR/backups"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"

if [[ ! -d "$BACKUP_ROOT" ]]; then
    echo "[revert-patches] FATAL: $BACKUP_ROOT not found; nothing to revert from." >&2
    exit 1
fi

# --- Resolve the backup dir --------------------------------------------
if [[ $# -ge 1 ]]; then
    BACKUP_DIR="$BACKUP_ROOT/$1"
else
    if [[ ! -L "$BACKUP_ROOT/latest" && ! -d "$BACKUP_ROOT/latest" ]]; then
        echo "[revert-patches] FATAL: no 'latest' symlink in $BACKUP_ROOT; specify a backup explicitly." >&2
        echo "                 Available backups:" >&2
        ls -1 "$BACKUP_ROOT" | grep -v '^latest$' | sort >&2 || true
        exit 1
    fi
    BACKUP_DIR="$(readlink -f "$BACKUP_ROOT/latest")"
fi

if [[ ! -d "$BACKUP_DIR" ]]; then
    echo "[revert-patches] FATAL: backup dir not found: $BACKUP_DIR" >&2
    exit 1
fi

echo "[revert-patches] $(date) -- restoring from $BACKUP_DIR"

mapfile -t BACKUP_FILES < <(find "$BACKUP_DIR" -type f | sort)
if [[ ${#BACKUP_FILES[@]} -eq 0 ]]; then
    echo "[revert-patches] FATAL: backup dir is empty: $BACKUP_DIR" >&2
    exit 1
fi
echo "[revert-patches] file count: ${#BACKUP_FILES[@]}"

# --- Restore + php -l --------------------------------------------------
RESTORED=0
FAIL=0
for backup in "${BACKUP_FILES[@]}"; do
    rel="${backup#$BACKUP_DIR/}"
    target="$BACKEND_ROOT/$rel"

    mkdir -p "$(dirname "$target")"
    cp -p "$backup" "$target"
    RESTORED=$((RESTORED + 1))
    echo "  restore $rel"

    if [[ "$target" == *.php ]]; then
        if ! "$PHP_BIN" -l "$target" >/dev/null 2>&1; then
            echo "          [FAIL] php -l failed after restore"
            "$PHP_BIN" -l "$target" || true
            FAIL=$((FAIL + 1))
        fi
    fi
done

echo
echo "[revert-patches] restored $RESTORED file(s)"
if [[ $FAIL -gt 0 ]]; then
    echo "[revert-patches] WARNING: $FAIL file(s) failed php -l after restore." >&2
    echo "                 The backup may be corrupted; investigate before restarting OLS." >&2
    exit 1
fi
echo "                 Restart OLS:  sudo /usr/local/lsws/bin/lswsctrl restart"
