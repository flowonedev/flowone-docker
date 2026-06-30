#!/usr/bin/env php
<?php
/**
 * Folder Snapshot Prune (Wave 2 P1).
 *
 * Bounds the size of webmail_folder_snapshots so a chatty client cannot
 * pile up unbounded history. Retention rules per account:
 *
 *   - Always keep the N=2 most recent snapshots (the analyzer needs at
 *     least one prior + one current to diff).
 *   - Of the remainder, keep snapshots <= --retention-days old.
 *   - Delete everything older than the retention window.
 *
 * The prune is bounded per pass with --limit (default 1000). Run hourly
 * to spread the load; on a quiet install the cron exits within a few
 * milliseconds.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/prune-folder-snapshots.php --help
 *
 * Crontab (every hour):
 *   23 * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/prune-folder-snapshots.php
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$args = [
    'help' => false,
    'verbose' => false,
    'json' => false,
    'dry-run' => false,
    'retention-days' => 7,
    'min-keep' => 2,
    'limit' => 1000,
];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--help' || $a === '-h') { $args['help'] = true; continue; }
    if ($a === '--verbose')  { $args['verbose'] = true; continue; }
    if ($a === '--json')     { $args['json']    = true; continue; }
    if ($a === '--dry-run')  { $args['dry-run'] = true; continue; }
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}

if ($args['help']) {
    echo <<<USAGE
prune-folder-snapshots.php -- bound webmail_folder_snapshots size.

Usage:
  php prune-folder-snapshots.php [flags]

Flags:
  --dry-run               Report deletes without executing.
  --verbose               Per-account debug output.
  --json                  Emit JSON summary on stdout.
  --retention-days=N      Older than N days are deleted (default 7).
  --min-keep=N            Minimum snapshots per account (default 2).
  --limit=N               Cap deletes per run (default 1000).
  --help                  Show this message.

USAGE;
    exit(0);
}

$retentionDays = max(1, (int) $args['retention-days']);
$minKeep = max(1, (int) $args['min-keep']);
$limit = max(1, (int) $args['limit']);
$verbose = (bool) $args['verbose'];
$jsonOut = (bool) $args['json'];
$dryRun  = (bool) $args['dry-run'];

$config = require __DIR__ . '/../src/config.php';

$logTag  = '[SNAPSHOT-PRUNE]';
$logFile = __DIR__ . '/../storage/logs/prune-folder-snapshots.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
$logLine = function (string $msg) use ($logFile, $jsonOut): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!$jsonOut) echo $line;
};

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

// Schema check.
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

$summary = [
    'accounts' => 0,
    'deleted' => 0,
    'started_at' => gmdate('c'),
    'finished_at' => null,
    'dry_run' => $dryRun,
];

// Per-account pass: for every account with > min-keep snapshots, find
// the cutoff (older than retention OR rank > min-keep) and delete in
// bounded batches.
try {
    $stmt = $db->query('SELECT account_id, COUNT(*) AS n FROM webmail_folder_snapshots GROUP BY account_id');
    $accounts = $stmt->fetchAll();
} catch (\Throwable $e) {
    $logLine($logTag . ' account list failed: ' . $e->getMessage());
    exit(2);
}

$totalDeleted = 0;
foreach ($accounts as $row) {
    $acct = (string) $row['account_id'];
    $count = (int) $row['n'];
    $summary['accounts']++;
    if ($count <= $minKeep) {
        continue;
    }

    // Find the (count - minKeep)th most recent snapshot. Anything
    // older is eligible IF it also exceeds the retention window OR
    // there are still more than minKeep snapshots after deletion.
    $keepStmt = $db->prepare(
        'SELECT id, captured_at FROM webmail_folder_snapshots
          WHERE account_id = ?
          ORDER BY captured_at DESC
          LIMIT ' . $minKeep
    );
    $keepStmt->execute([$acct]);
    $keepIds = array_map(fn($r) => (int) $r['id'], $keepStmt->fetchAll());

    // Build the WHERE clause excluding the protected ids and applying
    // the retention cutoff.
    $cutoff = date('Y-m-d H:i:s', time() - $retentionDays * 86400);
    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));

    $sql = "DELETE FROM webmail_folder_snapshots
             WHERE account_id = ?
               AND id NOT IN ({$placeholders})
               AND captured_at < ?
             ORDER BY captured_at ASC
             LIMIT {$limit}";
    $params = array_merge([$acct], $keepIds, [$cutoff]);

    if ($dryRun) {
        // Count what would be deleted.
        $cntSql = "SELECT COUNT(*) AS c FROM webmail_folder_snapshots
                    WHERE account_id = ?
                      AND id NOT IN ({$placeholders})
                      AND captured_at < ?";
        $cnt = $db->prepare($cntSql);
        $cnt->execute(array_merge([$acct], $keepIds, [$cutoff]));
        $r = $cnt->fetch();
        $would = (int) ($r['c'] ?? 0);
        if ($verbose && $would > 0) {
            $logLine($logTag . " dry account={$acct} would_delete={$would}");
        }
        $totalDeleted += min($would, $limit);
        continue;
    }

    try {
        $del = $db->prepare($sql);
        $del->execute($params);
        $deleted = $del->rowCount();
        $totalDeleted += $deleted;
        if ($verbose && $deleted > 0) {
            $logLine($logTag . " account={$acct} deleted={$deleted}");
        }
    } catch (\Throwable $e) {
        $logLine($logTag . " delete failed for account={$acct}: " . $e->getMessage());
    }
}

$summary['deleted'] = $totalDeleted;
$summary['finished_at'] = gmdate('c');

$logLine($logTag . sprintf(
    ' summary accounts=%d deleted=%d retention_days=%d min_keep=%d dry_run=%s',
    $summary['accounts'], $totalDeleted, $retentionDays, $minKeep,
    $dryRun ? 'yes' : 'no'
));

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

exit(0);
