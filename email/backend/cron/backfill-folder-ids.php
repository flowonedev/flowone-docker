#!/usr/bin/env php
<?php
/**
 * Folder-ID Backfill (Wave 2 P0).
 *
 * Walks pinned_emails, webmail_conversation_members and webmail_conversations
 * looking for rows where folder_id IS NULL and assigns each one the canonical
 * UUIDv7 from webmail_folder_identity (looked up by current_path or path
 * history). The backfill is idempotent: subsequent runs only see rows that
 * are still NULL.
 *
 * Three safety properties:
 *
 *   1. Concurrency: We acquire a per-user advisory lock so two parallel
 *      backfill runs never touch the same user. On MariaDB 10.6+ the
 *      `SELECT ... FOR UPDATE SKIP LOCKED` form lets multiple batches
 *      progress through different rows of the same user concurrently
 *      without blocking; on older versions we fall back to a Redis
 *      advisory lock plus plain SELECT.
 *
 *   2. Bounded scope: We process at most --batch-size rows per loop, and
 *      stop after --max-batches (or total rows == 0). Each loop sleeps
 *      --sleep-ms milliseconds to give read traffic room to breathe.
 *
 *   3. No destructive writes: We only UPDATE folder_id from NULL ->
 *      <uuid>. If the folder is unknown (path not in webmail_folder_identity
 *      AND not in webmail_folder_path_history) we leave the row alone and
 *      report it as `unresolved`; the next /mailbox/folders refresh will
 *      register the folder, then the next run picks it up.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/backfill-folder-ids.php --help
 *
 * Crontab (every 6 hours, gentle):
 *   17 *\/6 * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/backfill-folder-ids.php --batch-size=500
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

// ---- Args ----
$args = [
    'dry-run' => false,
    'verbose' => false,
    'json' => false,
    'help' => false,
    'batch-size' => 500,
    'max-batches' => 0,        // 0 = unlimited
    'sleep-ms' => 50,
    'account' => null,         // restrict to a single user_email
    'tables' => 'all',         // pinned|conv-members|conv|all
    'synthesize-missing' => false, // mint placeholder identity rows when needed
    'repair-intervals' => false,   // open path-intervals for identity rows that lack them
];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--dry-run') { $args['dry-run'] = true; continue; }
    if ($a === '--verbose') { $args['verbose'] = true; continue; }
    if ($a === '--json')    { $args['json'] = true; continue; }
    if ($a === '--synthesize-missing') { $args['synthesize-missing'] = true; continue; }
    if ($a === '--repair-intervals')   { $args['repair-intervals'] = true; continue; }
    if ($a === '--help' || $a === '-h') { $args['help'] = true; continue; }
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}

if ($args['help']) {
    echo <<<USAGE
backfill-folder-ids.php -- backfill folder_id on legacy dual-write rows.

Usage:
  php backfill-folder-ids.php [flags]

Flags:
  --dry-run           Report counts; don't UPDATE.
  --verbose           Per-row debug output.
  --json              Emit a JSON summary on stdout (for monitoring).
  --batch-size=N      Rows per pass (default 500, max 5000).
  --max-batches=N     Stop after N passes (0 = unlimited).
  --sleep-ms=N        Sleep this many ms between batches (default 50).
  --account=EMAIL     Restrict to a single user_email.
  --tables=NAME       all|pinned|conv-members|conv (default all).
  --synthesize-missing
                      For (account, path) tuples with no identity row,
                      mint a minimal placeholder identity (state=degraded)
                      AND open its canonical path-interval. The next
                      /mailbox/folders refresh upgrades it to 'healthy' and
                      fills in uidvalidity / special_use.
  --repair-intervals  One-shot: open a missing path-interval for any
                      identity row that lacks one. Idempotent.
  --help              Show this message.

Tables touched (in order):
  pinned_emails (folder_id NULL)
  webmail_conversation_members (folder_id NULL)
  webmail_conversations (folder_id NULL)

Identity is resolved in three stages: (1) match current_path
case-insensitively, (2) walk webmail_folder_path_history.former_path,
(3) optionally synthesize a placeholder identity row if
--synthesize-missing was passed. Otherwise, unresolved rows are reported
and skipped, and the next backfill run will retry them.

USAGE;
    exit(0);
}

// ---- Clamp batch size ----
$batchSize = max(1, min(5000, (int) $args['batch-size']));
$maxBatches = max(0, (int) $args['max-batches']);
$sleepMs    = max(0, (int) $args['sleep-ms']);
$tables     = strtolower((string) $args['tables']);
if (!in_array($tables, ['all', 'pinned', 'conv-members', 'conv'], true)) {
    fwrite(STDERR, "Invalid --tables value: {$tables}\n");
    exit(2);
}

$config = require __DIR__ . '/../src/config.php';

// ---- Helpers ----
$logTag  = '[BACKFILL]';
$logFile = __DIR__ . '/../storage/logs/backfill-folder-ids.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
$logLine = function (string $msg) use (&$args, $logFile): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!$args['json']) {
        echo $line;
    }
};

// ---- Connect DB / Redis ----
try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$redis = null;
try {
    $redis = new \Webmail\Services\RedisCacheService($config);
} catch (\Throwable $e) {
    $redis = null;
}

// ---- Detect SKIP LOCKED capability (MariaDB 10.6+ / MySQL 8.0+) ----
$skipLockedSupported = false;
try {
    $row = $db->query('SELECT VERSION() AS v')->fetch();
    $version = (string) ($row['v'] ?? '');
    if (preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
        $major = (int) $m[1];
        $minor = (int) $m[2];
        if (stripos($version, 'MariaDB') !== false) {
            // MariaDB 10.6+ supports SKIP LOCKED.
            $skipLockedSupported = ($major > 10) || ($major === 10 && $minor >= 6);
        } else {
            // MySQL 8.0+
            $skipLockedSupported = ($major >= 8);
        }
    }
} catch (\Throwable $e) {
    $skipLockedSupported = false;
}

// ---- Per-user advisory lock (Redis fallback when SKIP LOCKED unsupported) ----
$lockKey = function (string $userEmail): string {
    return 'backfill:folder_ids:' . md5(strtolower($userEmail));
};
$tryLock = function (string $userEmail) use ($redis, $lockKey, $skipLockedSupported): bool {
    if ($skipLockedSupported) {
        return true; // DB row locks are sufficient
    }
    if ($redis === null || !$redis->isAvailable()) {
        return true; // best effort: proceed without a lock
    }
    return $redis->setIfNotExists($lockKey($userEmail), '1', 600);
};
$releaseLock = function (string $userEmail) use ($redis, $lockKey, $skipLockedSupported): void {
    if ($skipLockedSupported) return;
    if ($redis === null || !$redis->isAvailable()) return;
    $redis->delete($lockKey($userEmail));
};

// ---- Resolve folder identity by current_path -> path_history fallback ----
//
// Legacy webmail_conversations / webmail_conversation_members rows often store
// the folder column lowercased (ConversationService::normalizeFolder() did
// strtolower()), while webmail_folder_identity.current_path is the IMAP-shape
// path (typically mixed case). We therefore compare both columns by
// LOWER(...) so the backfill is collation-independent and survives any
// historical normalisation drift.
$identityCache = []; // (account_id|lc_path) -> folder_id|null
$synthesizedCount = 0;

// FolderIndexService instance for synthesize calls. Built lazily because we
// only need it when --synthesize-missing is on.
$folderIndex = null;

// Derive a sensible display_name from a folder path using the most common
// IMAP delimiters. Used only when synthesizing minimal identity rows; the
// natural /mailbox/folders refresh will overwrite this with the real name.
$deriveDisplayName = function (string $path): string {
    $path = trim($path);
    if ($path === '') return $path;
    foreach (['/', '.', '\\'] as $sep) {
        if (strpos($path, $sep) !== false) {
            $parts = explode($sep, $path);
            $last = end($parts);
            if (is_string($last) && $last !== '') return $last;
        }
    }
    return $path;
};

$resolveIdentity = function (string $accountId, string $path) use (
    $db, $config, &$identityCache, &$folderIndex, &$synthesizedCount,
    $deriveDisplayName, &$args, $logTag, $logLine
) {
    $accountIdRaw = $accountId;
    $accountIdLc  = strtolower($accountId);
    $pathRaw      = $path;
    $lcPath       = mb_strtolower(trim($path));
    if ($lcPath === '') {
        return null;
    }
    $key = $accountIdLc . '|' . $lcPath;
    if (array_key_exists($key, $identityCache)) {
        return $identityCache[$key];
    }
    try {
        // 1) Exact-or-case-insensitive match on current_path.
        $stmt = $db->prepare(
            'SELECT id FROM webmail_folder_identity
              WHERE LOWER(account_id) = ? AND LOWER(current_path) = ?
              LIMIT 1'
        );
        $stmt->execute([$accountIdLc, $lcPath]);
        $row = $stmt->fetch();
        if ($row && !empty($row['id'])) {
            return $identityCache[$key] = (string) $row['id'];
        }

        // 2) Closed-interval lookup (rename history) -- catches paths that
        //    were renamed AFTER the legacy row was written but before the
        //    backfill ran.
        $stmt2 = $db->prepare(
            'SELECT fi.id FROM webmail_folder_identity fi
              JOIN webmail_folder_path_history h ON h.folder_id = fi.id
             WHERE LOWER(fi.account_id) = ? AND LOWER(h.former_path) = ?
             ORDER BY h.recorded_at DESC LIMIT 1'
        );
        $stmt2->execute([$accountIdLc, $lcPath]);
        $row2 = $stmt2->fetch();
        if ($row2 && !empty($row2['id'])) {
            return $identityCache[$key] = (string) $row2['id'];
        }

        // 3) Optional: synthesize a minimal placeholder identity row.
        //    Delegates to FolderIndexService::synthesizePlaceholder() so the
        //    insert + open-interval pair is atomic. Used when the user has
        //    never visited /mailbox/folders since the Wave-2 schema landed,
        //    so legacy dual-write rows reference paths that simply don't
        //    exist in webmail_folder_identity yet.
        if (!empty($args['synthesize-missing']) && !$args['dry-run']) {
            if ($folderIndex === null) {
                $folderIndex = new \Webmail\Services\FolderIndexService($config);
            }
            $synthId = $folderIndex->synthesizePlaceholder(
                $accountIdRaw,
                $pathRaw,
                $deriveDisplayName($pathRaw)
            );
            if ($synthId !== null) {
                $synthesizedCount++;
                if (!empty($args['verbose'])) {
                    $logLine($logTag . " synthesized identity id={$synthId} user={$accountIdRaw} path={$pathRaw} state=degraded");
                }
                return $identityCache[$key] = $synthId;
            }
        }
    } catch (\Throwable $e) {
        error_log('[BACKFILL] resolveIdentity error: ' . $e->getMessage());
    }
    return $identityCache[$key] = null;
};

// ---- Optional one-shot: open missing path-intervals ----
//
// Earlier versions of --synthesize-missing inserted into webmail_folder_identity
// without opening the corresponding row in webmail_folder_path_intervals,
// causing the reconciliation cron to flag every synthesized row as
// `no_open_interval`. This flag repairs that drift in one pass; safe to
// run any time because ensureOpenInterval is idempotent.
if (!empty($args['repair-intervals']) && !$args['dry-run']) {
    try {
        $folderIndex = new \Webmail\Services\FolderIndexService($config);
        $opened = $folderIndex->repairMissingIntervals(
            !empty($args['account']) ? (string) $args['account'] : null
        );
        $logLine($logTag . " repair-intervals: opened {$opened} missing path-interval(s)");
    } catch (\Throwable $e) {
        $logLine($logTag . ' repair-intervals failed: ' . $e->getMessage());
    }
}

// ---- Per-table batch loop ----
$summary = [
    'tables' => [],
    'started_at' => gmdate('c'),
    'finished_at' => null,
    'dry_run' => $args['dry-run'],
    'skip_locked' => $skipLockedSupported,
];

$tableSpecs = [
    'pinned' => [
        'name' => 'pinned_emails',
        'enabled' => in_array($tables, ['all', 'pinned'], true),
    ],
    'conv-members' => [
        'name' => 'webmail_conversation_members',
        'enabled' => in_array($tables, ['all', 'conv-members'], true),
    ],
    'conv' => [
        'name' => 'webmail_conversations',
        'enabled' => in_array($tables, ['all', 'conv'], true),
    ],
];

foreach ($tableSpecs as $alias => $spec) {
    if (!$spec['enabled']) continue;
    $table = $spec['name'];

    $tableSummary = [
        'table' => $table,
        'scanned' => 0,
        'updated' => 0,
        'unresolved' => 0,
        'batches' => 0,
    ];

    // Schema check: skip table if folder_id column doesn't exist yet.
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'folder_id'"
        );
        $stmt->execute([$table]);
        $colRow = $stmt->fetch();
        if (!$colRow || (int) ($colRow['c'] ?? 0) === 0) {
            $logLine($logTag . " skipping {$table}: folder_id column missing (run migration 163 first)");
            $summary['tables'][$alias] = $tableSummary + ['skipped' => 'no_folder_id_column'];
            continue;
        }
    } catch (\Throwable $e) {
        $logLine($logTag . " schema check failed for {$table}: " . $e->getMessage());
        continue;
    }

    $batchNum = 0;
    // Cursor across batches: we always pick rows with id > $lastSeenId so an
    // unresolvable row (folder_id stays NULL because no identity exists yet)
    // can never re-appear in the next batch and create an infinite loop.
    // The next backfill run starts fresh from id=0 and re-tries the
    // unresolved rows once more (now hopefully resolvable because the user
    // has visited the folder in the meantime and an identity row exists).
    $lastSeenId = 0;
    while (true) {
        if ($maxBatches > 0 && $batchNum >= $maxBatches) {
            break;
        }

        $params = [];
        $where = 'folder_id IS NULL AND folder IS NOT NULL AND id > ?';
        $params[] = $lastSeenId;
        if (!empty($args['account'])) {
            $where .= ' AND user_email = ?';
            $params[] = (string) $args['account'];
        }

        $sql = "SELECT id, user_email, folder FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT {$batchSize}";
        if ($skipLockedSupported && !$args['dry-run']) {
            $sql .= ' FOR UPDATE SKIP LOCKED';
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) {
                $db->commit();
                break;
            }

            // Advance the cursor PAST the last row in this batch BEFORE we
            // process them, so even if processing fails partway we still
            // make forward progress on the next iteration.
            $batchMaxId = 0;
            foreach ($rows as $r) {
                $rid = (int) $r['id'];
                if ($rid > $batchMaxId) $batchMaxId = $rid;
            }
            if ($batchMaxId > $lastSeenId) {
                $lastSeenId = $batchMaxId;
            }

            $tableSummary['scanned'] += count($rows);
            $tableSummary['batches']++;
            $batchNum++;

            // Group by user so we acquire one Redis advisory lock per user
            // (only used when SKIP LOCKED is unavailable).
            $byUser = [];
            foreach ($rows as $row) {
                $byUser[$row['user_email']][] = $row;
            }

            $updateStmt = $db->prepare("UPDATE {$table} SET folder_id = ? WHERE id = ? AND folder_id IS NULL");

            foreach ($byUser as $userEmail => $userRows) {
                if (!$tryLock($userEmail)) {
                    if ($args['verbose']) {
                        $logLine($logTag . " lock-skip user={$userEmail} table={$table}");
                    }
                    continue;
                }
                try {
                    foreach ($userRows as $row) {
                        $folderId = $resolveIdentity($userEmail, (string) $row['folder']);
                        if ($folderId === null) {
                            $tableSummary['unresolved']++;
                            if ($args['verbose']) {
                                $logLine($logTag . " unresolved id={$row['id']} table={$table} user={$userEmail} folder={$row['folder']}");
                            }
                            continue;
                        }
                        if ($args['dry-run']) {
                            $tableSummary['updated']++;
                            continue;
                        }
                        $updateStmt->execute([$folderId, $row['id']]);
                        $tableSummary['updated'] += $updateStmt->rowCount();
                    }
                } finally {
                    $releaseLock($userEmail);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $logLine($logTag . " batch error on {$table}: " . $e->getMessage());
            break;
        }

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    $summary['tables'][$alias] = $tableSummary;
    $logLine($logTag . sprintf(
        ' %s scanned=%d updated=%d unresolved=%d batches=%d',
        $table,
        $tableSummary['scanned'],
        $tableSummary['updated'],
        $tableSummary['unresolved'],
        $tableSummary['batches']
    ));
}

if ($synthesizedCount > 0) {
    $summary['synthesized_identities'] = $synthesizedCount;
    $logLine($logTag . sprintf(' synthesized %d placeholder identity row(s) (state=degraded)', $synthesizedCount));
}

$summary['finished_at'] = gmdate('c');

if ($args['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

exit(0);
