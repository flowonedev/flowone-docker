#!/usr/bin/env php
<?php
/**
 * Folder Rename Analyzer (Wave 2 P1).
 *
 * Asynchronous worker that consumes folder snapshots written by
 * MailboxController::captureFolderSnapshot() and decides which
 * paths represent renames vs new folders. Invokes
 * FolderIndexService::applyRename() for confirmed renames so
 * folder_id stays stable across delimiter / namespace / display-name
 * changes.
 *
 * Per-account loop:
 *
 *   1) Find accounts with pending work (Redis SET key
 *      `folder_rename_analysis:pending:<md5(account)>` exists OR
 *      DB has any unconsumed snapshot rows). Redis is the fast path.
 *
 *   2) For each account, take the latest unconsumed snapshot. Pick
 *      the prior consumed snapshot for the same account as the diff
 *      base. Renames need at least two snapshots; the very first
 *      snapshot per account is just consumed without analysis.
 *
 *   3) Diff the two snapshots:
 *        - Folders present in BOTH (by current_path) are unchanged.
 *        - Folders only in PRIOR are "missing" (rename source candidates).
 *        - Folders only in LATEST are "new" (rename target candidates
 *          OR genuinely new folders).
 *      Run FolderIndexService::detectRenames() over (new, missing).
 *
 *   4) For each confirmed rename, call applyRename() which closes
 *      the old path interval, opens a new one, updates current_path,
 *      cascades the legacy `folder` column, and bumps the per-account
 *      folder_identity_version.
 *
 *   5) Mark BOTH the latest and any older unconsumed snapshots as
 *      consumed so the same diff is never replayed. Replay is fine
 *      forensically (all intervals are timestamped) but operationally
 *      wasteful.
 *
 *   6) Clear the per-account pending Redis flag.
 *
 * Concurrency: a Redis advisory lock (SETNX, 600s TTL) is held per
 * account for the duration of the run, so two cron invocations never
 * race on the same account. Different accounts run sequentially in
 * one process; horizontal sharding (multiple cron entries with
 * different `--shard=` filters) is reserved for future scale.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/folder-rename-analyzer.php --help
 *
 * Crontab (every minute, lightweight when there's no work):
 *   * * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/folder-rename-analyzer.php
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

// ---- Args ----
$args = [
    'help' => false,
    'verbose' => false,
    'json' => false,
    'dry-run' => false,
    'account' => null,           // restrict to a single account
    'max-accounts' => 50,        // cap per cron tick
    'min-score' => 70,           // detectRenames threshold
    'help' => false,
];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--help' || $a === '-h') { $args['help'] = true; continue; }
    if ($a === '--verbose')  { $args['verbose']  = true; continue; }
    if ($a === '--json')     { $args['json']     = true; continue; }
    if ($a === '--dry-run')  { $args['dry-run']  = true; continue; }
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}

if ($args['help']) {
    echo <<<USAGE
folder-rename-analyzer.php -- async rename detector.

Usage:
  php folder-rename-analyzer.php [flags]

Flags:
  --dry-run             Detect renames but don't applyRename or mark consumed.
  --verbose             Per-account debug output.
  --json                Emit JSON summary on stdout.
  --account=EMAIL       Process a single account (skip Redis discovery).
  --max-accounts=N      Cap accounts processed per run (default 50).
  --min-score=N         Score threshold for confirmed renames (default 70).
  --help                Show this message.

USAGE;
    exit(0);
}

$verbose = (bool) $args['verbose'];
$jsonOut = (bool) $args['json'];
$dryRun  = (bool) $args['dry-run'];
$maxAccounts = max(1, (int) $args['max-accounts']);
$minScore = max(1, min(100, (int) $args['min-score']));

// ---- Config / DB / Redis ----
$config = require __DIR__ . '/../src/config.php';

$logTag  = '[RENAME-ANALYZER]';
$logFile = __DIR__ . '/../storage/logs/folder-rename-analyzer.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
$logLine = function (string $msg) use ($logFile, $jsonOut): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!$jsonOut) {
        echo $line;
    }
};

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

$folderIndex = new \Webmail\Services\FolderIndexService($config);
$telemetry = ($redis !== null) ? new \Webmail\Services\DualWriteTelemetry($redis) : null;

// ---- Schema check: bail with a clear message if migration 164 is absent ----
try {
    $row = $db->query("SHOW TABLES LIKE 'webmail_folder_snapshots'")->fetch();
    if (!$row) {
        $logLine($logTag . ' webmail_folder_snapshots missing -- run migration 164 first');
        exit(0);
    }
} catch (\Throwable $e) {
    $logLine($logTag . ' schema check failed: ' . $e->getMessage());
    exit(2);
}

// ---- Discover accounts with pending work ----
$accounts = [];
if (!empty($args['account'])) {
    $accounts[] = strtolower((string) $args['account']);
} else {
    // Pull from DB (single source of truth). Redis pending key is just a
    // signal that prevents redundant DB scans on quiet clusters; if Redis
    // is down we fall through to the DB scan anyway.
    try {
        $stmt = $db->prepare(
            'SELECT account_id, COUNT(*) AS n
               FROM webmail_folder_snapshots
              WHERE consumed_at IS NULL
              GROUP BY account_id
              ORDER BY MAX(captured_at) ASC
              LIMIT ' . $maxAccounts
        );
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $accounts[] = (string) $row['account_id'];
        }
    } catch (\Throwable $e) {
        $logLine($logTag . ' account scan failed: ' . $e->getMessage());
        exit(2);
    }
}

if (empty($accounts)) {
    if ($verbose) {
        $logLine($logTag . ' no pending work');
    }
    if ($jsonOut) {
        echo json_encode(['accounts' => 0, 'renames' => 0]) . "\n";
    }
    exit(0);
}

// ---- Helpers ----
$lockKey = fn(string $acct) => 'rename_analyzer:lock:' . md5(strtolower($acct));
$pendingKey = fn(string $acct) => 'folder_rename_analysis:pending:' . md5(strtolower($acct));

$tryLock = function (string $acct) use ($redis, $lockKey): bool {
    if ($redis === null || !$redis->isAvailable()) {
        return true; // best effort
    }
    return $redis->setIfNotExists($lockKey($acct), '1', 600);
};
$releaseLock = function (string $acct) use ($redis, $lockKey): void {
    if ($redis === null || !$redis->isAvailable()) return;
    $redis->delete($lockKey($acct));
};

$indexFoldersByPath = function (array $list): array {
    $out = [];
    foreach ($list as $row) {
        $p = (string) ($row['path'] ?? $row['name'] ?? '');
        if ($p === '') continue;
        $out[$p] = $row;
    }
    return $out;
};

// ---- Process each account ----
$summary = [
    'accounts' => 0,
    'analyzed' => 0,
    'renames' => 0,
    'creates' => 0,
    'conflicts' => 0,
    'started_at' => gmdate('c'),
    'finished_at' => null,
    'dry_run' => $dryRun,
];

foreach ($accounts as $accountId) {
    if (!$tryLock($accountId)) {
        if ($verbose) $logLine($logTag . " skip locked account={$accountId}");
        continue;
    }
    $summary['accounts']++;
    try {
        // Latest unconsumed snapshot is the diff target.
        $stmt = $db->prepare(
            'SELECT id, snapshot, captured_at
               FROM webmail_folder_snapshots
              WHERE account_id = ? AND consumed_at IS NULL
              ORDER BY captured_at DESC LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $latest = $stmt->fetch();
        if (!$latest) {
            if ($verbose) $logLine($logTag . " no_latest account={$accountId}");
            continue;
        }

        // Prior snapshot (any state) is the diff base.
        $stmt = $db->prepare(
            'SELECT id, snapshot, captured_at
               FROM webmail_folder_snapshots
              WHERE account_id = ? AND id <> ? AND captured_at <= ?
              ORDER BY captured_at DESC LIMIT 1'
        );
        $stmt->execute([$accountId, (int) $latest['id'], $latest['captured_at']]);
        $prior = $stmt->fetch();

        // Decode payloads.
        $latestList = json_decode((string) $latest['snapshot'], true);
        $priorList  = $prior ? json_decode((string) $prior['snapshot'], true) : null;
        if (!is_array($latestList)) {
            $logLine($logTag . " bad_snapshot id={$latest['id']} account={$accountId}");
            $latestList = [];
        }
        if ($priorList !== null && !is_array($priorList)) {
            $priorList = null;
        }

        if ($priorList === null) {
            // First snapshot for this account -- nothing to diff against.
            if (!$dryRun) {
                $upd = $db->prepare(
                    'UPDATE webmail_folder_snapshots
                        SET consumed_at = CURRENT_TIMESTAMP
                      WHERE account_id = ? AND id <= ? AND consumed_at IS NULL'
                );
                $upd->execute([$accountId, (int) $latest['id']]);
            }
            if ($redis !== null && $redis->isAvailable()) {
                $redis->delete($pendingKey($accountId));
            }
            if ($verbose) $logLine($logTag . " first_snapshot account={$accountId}");
            continue;
        }

        $latestByPath = $indexFoldersByPath($latestList);
        $priorByPath  = $indexFoldersByPath($priorList);

        $missingPaths = array_diff(array_keys($priorByPath), array_keys($latestByPath));
        $newPaths     = array_diff(array_keys($latestByPath), array_keys($priorByPath));

        // Map missing paths -> identity rows so detectRenames has a
        // useful "from" set. We use FolderIndexService::getByPath
        // because the path may have been reassigned in path history
        // already.
        $missingFolders = [];
        foreach ($missingPaths as $mp) {
            $row = $folderIndex->getByPath($accountId, $mp);
            if (!$row) continue;
            // Augment the identity row with the snapshot view so the
            // weighted scorer can use uidvalidity / total / etc.
            if (isset($priorByPath[$mp])) {
                $row['uidvalidity']  = $priorByPath[$mp]['uidvalidity']  ?? $row['uidvalidity'];
                $row['uidnext']      = $priorByPath[$mp]['uidnext']      ?? $row['uidnext'];
                $row['display_name'] = $priorByPath[$mp]['display_name'] ?? ($row['display_name'] ?? $mp);
                $row['delimiter']    = $priorByPath[$mp]['delimiter']    ?? $row['delimiter'];
                $row['message_count'] = $priorByPath[$mp]['total']
                    ?? ($priorByPath[$mp]['message_count'] ?? $row['message_count']);
                $row['special_use']  = $priorByPath[$mp]['special_use']  ?? $row['special_use'];
            }
            $missingFolders[] = $row;
        }

        $newFolders = [];
        foreach ($newPaths as $np) {
            $newFolders[] = $latestByPath[$np];
        }

        // Skip the work if nothing to diff.
        if (empty($missingFolders) || empty($newFolders)) {
            if (!$dryRun) {
                $upd = $db->prepare(
                    'UPDATE webmail_folder_snapshots
                        SET consumed_at = CURRENT_TIMESTAMP
                      WHERE account_id = ? AND id <= ? AND consumed_at IS NULL'
                );
                $upd->execute([$accountId, (int) $latest['id']]);
            }
            if ($redis !== null && $redis->isAvailable()) {
                $redis->delete($pendingKey($accountId));
            }
            if ($verbose) {
                $logLine($logTag . sprintf(
                    " no_diff account=%s missing=%d new=%d",
                    $accountId, count($missingFolders), count($newFolders)
                ));
            }
            $summary['analyzed']++;
            continue;
        }

        // Per-provider weight profile for the rename scorer. Falls back
        // to 'unknown' when the account has not been fingerprinted yet.
        $providerType = $folderIndex->getProviderType($accountId, $redis);
        \Webmail\Services\StructuredLog::setContext([
            'account_id'    => $accountId,
            'provider_type' => $providerType,
        ]);

        $detection = $folderIndex->detectRenames($newFolders, $missingFolders, $providerType);
        $summary['analyzed']++;
        $summary['conflicts'] += count($detection['conflicts'] ?? []);
        $summary['creates']   += count($detection['creates'] ?? []);

        foreach ($detection['renames'] as $rename) {
            if ($rename['score'] < $minScore) {
                continue;
            }
            // Look up old identity row and oldPath.
            $oldRow = null;
            foreach ($missingFolders as $mf) {
                if ((string) $mf['id'] === (string) $rename['from_id']) {
                    $oldRow = $mf;
                    break;
                }
            }
            if (!$oldRow) continue;

            $newPath = (string) $rename['new_path'];
            $oldPath = (string) ($oldRow['current_path'] ?? '');
            if ($oldPath === '' || $newPath === '' || $oldPath === $newPath) {
                continue;
            }

            $newRow = $latestByPath[$newPath] ?? [];
            $newDisplay = $newRow['display_name'] ?? $newRow['name'] ?? null;
            $newPrefix  = $newRow['namespace_prefix'] ?? null;
            $newDelim   = $newRow['delimiter'] ?? null;

            if ($dryRun) {
                $logLine($logTag . sprintf(
                    " dry_rename account=%s from=%s to=%s score=%d",
                    $accountId, $oldPath, $newPath, (int) $rename['score']
                ));
                $summary['renames']++;
                continue;
            }
            try {
                $applied = $folderIndex->applyRename(
                    $accountId,
                    (string) $rename['from_id'],
                    $oldPath,
                    $newPath,
                    is_string($newDisplay) ? $newDisplay : null,
                    is_string($newPrefix) ? $newPrefix : null,
                    is_string($newDelim) ? $newDelim : null,
                    $telemetry
                );
                if ($applied) {
                    $summary['renames']++;
                    $logLine($logTag . sprintf(
                        " rename_applied account=%s from=%s to=%s score=%d folder_id=%s",
                        $accountId, $oldPath, $newPath,
                        (int) $rename['score'], (string) $rename['from_id']
                    ));

                    // Publish a real-time WebSocket event so any connected
                    // tabs of this user invalidate their folder caches
                    // immediately. Without this, externally-detected
                    // renames (Roundcube, Outlook, etc.) would only
                    // surface when the tab next reconnects.
                    if ($redis !== null && $redis->isAvailable()) {
                        try {
                            $redis->publishFolderChanged($accountId, 'renamed', $oldPath, $newPath);
                        } catch (\Throwable $pubErr) {
                            $logLine($logTag . " publish_failed account={$accountId} err=" . $pubErr->getMessage());
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logLine($logTag . " rename_failed account={$accountId} from={$oldPath} to={$newPath} err=" . $e->getMessage());
            }
        }

        // Mark consumed: latest + any older still-pending rows
        // (operational hygiene: don't replay storms after a backlog).
        if (!$dryRun) {
            $upd = $db->prepare(
                'UPDATE webmail_folder_snapshots
                    SET consumed_at = CURRENT_TIMESTAMP
                  WHERE account_id = ? AND id <= ? AND consumed_at IS NULL'
            );
            $upd->execute([$accountId, (int) $latest['id']]);
        }
        if ($redis !== null && $redis->isAvailable()) {
            $redis->delete($pendingKey($accountId));
        }
    } finally {
        $releaseLock($accountId);
    }
}

$summary['finished_at'] = gmdate('c');
$logLine($logTag . sprintf(
    ' summary accounts=%d analyzed=%d renames=%d creates=%d conflicts=%d dry_run=%s',
    $summary['accounts'], $summary['analyzed'], $summary['renames'],
    $summary['creates'], $summary['conflicts'],
    $summary['dry_run'] ? 'yes' : 'no'
));

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

exit(0);
