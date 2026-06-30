#!/usr/bin/env php
<?php
/**
 * Drive Quota Backfill (versioning overhaul, one-time).
 *
 * Historic quota drift: office/collab saves (updateFileContent) kept the
 * old bytes on disk as a version but only charged the size DELTA, so
 * version bytes from that path were never counted. Restores also skipped
 * the ledger entirely. This script recomputes every user's used_bytes
 * from the rows that actually own bytes:
 *
 *   used_bytes = SUM(drive_files.size WHERE NOT trashed... all rows count;
 *                trash still occupies bytes until permanently deleted)
 *              + SUM(drive_file_versions.size for the user's files)
 *
 * Dry-run by default: prints per-user current vs computed usage and the
 * delta. Pass --apply to write the corrected values.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-version-quota-backfill.php --help
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-version-quota-backfill.php            (dry-run)
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-version-quota-backfill.php --apply
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-version-quota-backfill must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;

$opts = [
    'help' => false,
    'apply' => false,
    'verbose' => false,
    'json' => false,
    'user' => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
    if ($arg === '--apply') { $opts['apply'] = true; continue; }
    if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
    if ($arg === '--json') { $opts['json'] = true; continue; }
    if (str_starts_with($arg, '--user=')) { $opts['user'] = strtolower(substr($arg, strlen('--user='))); continue; }
}

if ($opts['help']) {
    echo <<<TXT
Drive Quota Backfill (one-time, versioning overhaul)

Recomputes drive_quotas.used_bytes per user as
  SUM(drive_files.size) + SUM(drive_file_versions.size)
fixing the historic undercount of version bytes from office saves.

Usage:
  drive-version-quota-backfill.php             dry-run report (default)
  drive-version-quota-backfill.php --apply     write corrected values
  drive-version-quota-backfill.php --user=a@b  limit to one user
  drive-version-quota-backfill.php --verbose   show users with zero delta too
  drive-version-quota-backfill.php --json      machine-readable output

TXT;
    exit(0);
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$fmt = function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) abs($bytes);
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return ($bytes < 0 ? '-' : '') . round($v, 2) . ' ' . $units[$i];
};

// Computed usage per user. Trashed files still own bytes until the
// permanent delete runs (which refunds them), so every row counts.
$sql = "
    SELECT u.user_email,
           COALESCE(f.bytes, 0) + COALESCE(v.bytes, 0) AS computed,
           COALESCE(q.used_bytes, 0) AS current_used
      FROM (
            SELECT user_email FROM drive_files
            UNION
            SELECT user_email FROM drive_quotas
           ) u
      LEFT JOIN (
            SELECT user_email, SUM(size) AS bytes
              FROM drive_files
             GROUP BY user_email
           ) f ON f.user_email = u.user_email
      LEFT JOIN (
            SELECT df.user_email, SUM(dv.size) AS bytes
              FROM drive_file_versions dv
              JOIN drive_files df ON df.id = dv.file_id
             GROUP BY df.user_email
           ) v ON v.user_email = u.user_email
      LEFT JOIN drive_quotas q ON q.user_email = u.user_email
";
$params = [];
if ($opts['user'] !== null) {
    $sql .= " WHERE u.user_email = ?";
    $params[] = $opts['user'];
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$summary = [
    'mode' => $opts['apply'] ? 'apply' : 'dry-run',
    'users_checked' => 0,
    'users_drifted' => 0,
    'users_updated' => 0,
    'total_drift_bytes' => 0,
    'users' => [],
];

foreach ($rows as $row) {
    $email = (string) $row['user_email'];
    $computed = (int) $row['computed'];
    $current = (int) $row['current_used'];
    $delta = $computed - $current;

    $summary['users_checked']++;

    if ($delta === 0) {
        if ($opts['verbose'] && !$opts['json']) {
            echo "  OK    {$email}  used={$fmt($current)}\n";
        }
        continue;
    }

    $summary['users_drifted']++;
    $summary['total_drift_bytes'] += $delta;
    $summary['users'][] = ['email' => $email, 'current' => $current, 'computed' => $computed, 'delta' => $delta];

    if (!$opts['json']) {
        printf(
            "  %s %s  current=%s  computed=%s  delta=%s\n",
            $opts['apply'] ? 'FIX  ' : 'DRIFT',
            $email,
            $fmt($current),
            $fmt($computed),
            $fmt($delta)
        );
    }

    if ($opts['apply']) {
        $db->prepare('
            INSERT INTO drive_quotas (user_email, quota_bytes, used_bytes)
            VALUES (?, -1, ?)
            ON DUPLICATE KEY UPDATE used_bytes = VALUES(used_bytes)
        ')->execute([$email, max(0, $computed)]);
        $summary['users_updated']++;
    }
}

if ($opts['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
} else {
    printf(
        "\n[QUOTA-BACKFILL] mode=%s users=%d drifted=%d updated=%d total_drift=%s\n",
        $summary['mode'],
        $summary['users_checked'],
        $summary['users_drifted'],
        $summary['users_updated'],
        $fmt($summary['total_drift_bytes'])
    );
    if (!$opts['apply'] && $summary['users_drifted'] > 0) {
        echo "Re-run with --apply to write the corrected values.\n";
    }
}

exit(0);
