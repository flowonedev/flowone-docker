# Canonical Identity Cutover -- Operator Runbook

This runbook describes the **staged** cutover. The plan splits the work
into three phases of increasing irreversibility. You can pause or abort
between any two steps.

| Stage | What                              | Reversible?            |
| ----- | --------------------------------- | ---------------------- |
| 1     | `ALTER COLUMN folder ... NULL`    | yes (re-add NOT NULL)  |
| 2     | `apply-patches.sh` (cp + php -l)  | yes (`revert-patches.sh`) |
| 3     | `DROP COLUMN folder`              | **NO** (restore from DB backup) |

A clean run takes about 30 minutes wall-clock, including the two
observability checkpoints.

## 0. Prerequisites

Run from a shell on the VPS (`/var/www/vps-email/backend/`). All paths
below are relative to that directory unless noted.

- PHP CLI: `/usr/local/lsws/lsphp83/bin/php`
- DB credentials: `vpsadmin / 7bcf619af819e4e274e5cfdfba022274 / devc_vps_dash`
- LiteSpeed control: `sudo /usr/local/lsws/bin/lswsctrl restart`
- A second SSH session for tailing `logs/php_errors.log`.

Set up a shell alias so the migration commands are short:

```bash
alias mysql_dev='mysql -u vpsadmin -p"7bcf619af819e4e274e5cfdfba022274" devc_vps_dash'
PHP_BIN=/usr/local/lsws/lsphp83/bin/php
```

## 1. Pre-flight

```bash
# 1a. Upload cutover/ from the dev box (manual scp or git pull).
ls cutover/code-patches/src/Services/ConversationService.php

# 1b. Verify every staged PHP patch parses (read-only check, no changes).
bash cutover/verify-patches.sh

# 1c. Exercise the app for ~30 sec from a browser so compare-mode
#     samples are non-zero, then refresh the readiness file.
$PHP_BIN cron/dual-write-readiness.php

# 1d. Run preflight. If samples=0, use --warmup to seed counters.
$PHP_BIN cutover/preflight.php
$PHP_BIN cutover/preflight.php --warmup --require-samples=5
```

`preflight.php` must print `RESULT: SAFE TO PROCEED` before continuing.
Investigate any FAIL line and rerun.

## 2. Backup the three target tables

The Stage 3 column drop is irreversible; the only rollback path is a
restore from the backup taken here.

```bash
mkdir -p cutover/backups/db
mysqldump -u vpsadmin -p"7bcf619af819e4e274e5cfdfba022274" devc_vps_dash \
    pinned_emails webmail_conversation_members webmail_conversations \
    > "cutover/backups/db/pre-cutover-$(date +%Y%m%d-%H%M%S).sql"

# Sanity: file exists and is non-trivial in size (~MB).
ls -lh cutover/backups/db/
```

Keep this file until you have at least 24h of clean post-cutover
operation. Move it off-box for long-term archival.

## 3. Stage 1 -- make the legacy `folder` column nullable

This is reversible: if you change your mind, run
`ALTER TABLE ... MODIFY folder VARCHAR(255) NOT NULL` to restore the
constraint. Stage 1 lets the new code's INSERTs (which omit the
`folder` column) succeed before the column is fully dropped.

```bash
mysql -u vpsadmin -p"7bcf619af819e4e274e5cfdfba022274" devc_vps_dash \
    < cutover/165_make_folder_columns_nullable.sql
```

Confirm the migrations table records it:

```bash
mysql_dev -e "SELECT * FROM migrations WHERE name = '165_make_folder_columns_nullable';"
```

## 4. Stage 2 -- apply code patches

```bash
bash cutover/apply-patches.sh
```

The script:

1. Runs `php -l` on every staged file in `cutover/code-patches/`.
2. Backs up every original to `cutover/backups/code-<timestamp>/`.
3. Copies the cleaned versions into place.
4. Re-runs `php -l` on every applied target.
5. If any post-apply lint fails, **auto-reverts** from the backup.

The script symlinks `cutover/backups/latest -> code-<timestamp>` so
`revert-patches.sh` works with no arguments.

## 5. Restart LiteSpeed

```bash
sudo /usr/local/lsws/bin/lswsctrl restart
```

Wait ~5 sec for the workers to come up.

## 6. Checkpoint A -- 5-minute observability window

In the second SSH session:

```bash
tail -F logs/php_errors.log | grep --line-buffered -Ei "Unknown column 'folder|invariant"
```

Open the app in a browser and exercise:

- Open INBOX, scroll, mark a message read
- Pin / unpin a message
- Open a folder under a deeper path (e.g. `INBOX.Archive.2026`)
- Run a `is:pinned` search
- Move a message between two folders

If the tail surfaces ANY `Unknown column 'folder'` line: **abort and
revert**.

```bash
bash cutover/revert-patches.sh
sudo /usr/local/lsws/bin/lswsctrl restart
```

The Stage 1 nullable ALTER is harmless and can stay in place.
Investigate the missed query, regenerate the patch, retry from §4.

If the tail is clean for 5 minutes, continue.

## 7. Stage 3 -- drop the legacy columns (IRREVERSIBLE)

```bash
mysql -u vpsadmin -p"7bcf619af819e4e274e5cfdfba022274" devc_vps_dash \
    < cutover/166_canonical_identity_cutover.sql
```

The migration:

- Drops every legacy index that includes the `folder` column.
- Drops the `folder` column on all three tables.
- Promotes `folder_id` to `NOT NULL`.
- Adds the canonical `folder_id`-shaped unique keys / indexes.
- Records the cutover in `migrations`.

Confirm:

```bash
mysql_dev -e "SELECT * FROM migrations WHERE name = '166_canonical_identity_cutover';"
```

## 8. Post-health check

```bash
$PHP_BIN cutover/post-health.php
```

Must print `RESULT: HEALTHY`. The script verifies:

- The `folder` column is gone from all three tables
- `folder_id` is `NOT NULL` on all three
- Every new index created by 166 exists (including `idx_norm_subject_id`)
- A spot-check `SELECT` works on each table
- No `Unknown column 'folder'` (any alias variant) in the last 5
  minutes of `php_errors.log`
- No stale references to dropped indexes (`idx_user_folder*`,
  `idx_folder_conv`, `idx_norm_subject`)

`--since=15` widens the log scan window if you took a coffee break
between Stage 3 and post-health.

## 9. Checkpoint B -- 10-minute browser smoke test

Final exercise. If anything looks wrong here:

- The legacy column is gone, so `revert-patches.sh` alone will not fix
  things. You need to:
  1. `bash cutover/revert-patches.sh && sudo /usr/local/lsws/bin/lswsctrl restart`
  2. Restore the three tables from the backup taken in §2:
     ```bash
     mysql_dev < cutover/backups/db/pre-cutover-<timestamp>.sql
     ```
  3. Restart LiteSpeed again to flush prepared-statement caches.

Recovery time: ~5 minutes.

## 10. Cleanup (after a clean Checkpoint B)

```bash
# Move the cutover artifacts out of the active path.
mv cutover "cutover/../cutover/archived-$(date +%Y-%m-%d)"

# (Optional) Disable the readiness cron now that legacy_* counters are
# dead. Repurposed: the slim post-cutover script reports compare-mode
# regression and invariant_violations; weekly is plenty.
crontab -e
# Change the schedule from `5 2 * * *` to `5 2 * * 0` and re-comment as
# "post-cutover regression telemetry".
```

Update the project todo list: P2 cutover is done. P3 (folder groups,
feature flag rollout, frontend `mailRouteService.js` cleanup) remains
deferred.

## Quick-reference -- where everything lives

| Artifact                                   | Path                                                       |
| ------------------------------------------ | ---------------------------------------------------------- |
| Audit (every site, old SQL -> new SQL)     | `cutover/AUDIT.md`                                          |
| Stage 1 nullable migration                 | `cutover/165_make_folder_columns_nullable.sql`              |
| Stage 3 column-drop migration              | `cutover/166_canonical_identity_cutover.sql`                |
| Cleaned PHP / JS files                     | `cutover/code-patches/**`                                   |
| Apply script                               | `cutover/apply-patches.sh`                                  |
| Revert script                              | `cutover/revert-patches.sh`                                 |
| Verify-only script                         | `cutover/verify-patches.sh`                                 |
| Pre-flight gate                            | `cutover/preflight.php` (`--warmup`, `--require-samples=N`) |
| Post-health gate                           | `cutover/post-health.php` (`--since=N`)                     |
| Slim post-cutover readiness telemetry      | `cron/dual-write-readiness.php`                             |
