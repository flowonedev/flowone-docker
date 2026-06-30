#!/usr/bin/env php
<?php
/**
 * Refresh subscribed RSS/Atom feeds (curl_multi batches) and prune old items.
 *
 * Crontab (every 15 minutes) — example:
 *   0,15,30,45 * * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/news-refresh.php >> /var/www/vps-email/storage/logs/news-refresh.log 2>&1
 *
 * Env:
 *   NEWS_RETENTION_DAYS — default 30
 *
 * Flags:
 *   --verbose         More output (per-feed + retention)
 *   --purge-shorts    Re-check every existing video item against
 *                     /shorts/{id} and DELETE the rows that turn out to
 *                     be YouTube Shorts. Used as a one-shot cleanup after
 *                     deploying the is_short migration. Skips the normal
 *                     refresh pass so it can run concurrently with the
 *                     regular cron without fighting the lock. Optionally
 *                     scope with --purge-limit=N (default: all).
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if (in_array('--help', $argv, true)) {
    echo "Usage: news-refresh.php [--verbose] [--purge-shorts [--purge-limit=N]]\n";
    exit(0);
}

$verbose = in_array('--verbose', $argv, true);
$purgeShorts = in_array('--purge-shorts', $argv, true);
$purgeLimit = null;
foreach ($argv as $a) {
    if (strncmp($a, '--purge-limit=', 14) === 0) {
        $purgeLimit = (int) substr($a, 14);
    }
}

require_once __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

use Webmail\Addons\NewsReader\Services\NewsReaderService;
use Webmail\Addons\NewsReader\Services\RssFetcherService;

$svc = new NewsReaderService($config);

if ($purgeShorts) {
    // Stand-alone cleanup mode — does not contend on the cron lock so an
    // operator can run it ad-hoc post-deploy without blocking the next
    // scheduled refresh.
    $deletedShorts = $svc->purgeYouTubeShorts($purgeLimit, $verbose);
    echo "[news-refresh] Purged shorts: {$deletedShorts}\n";
    exit(0);
}

if (!$svc->tryCronLock(840)) {
    if ($verbose) {
        echo "[news-refresh] Skipped: lock held\n";
    }
    exit(0);
}

$fetcher = new RssFetcherService();
$due = $svc->feedsDueForCron(15, 5);
if ($verbose) {
    echo '[news-refresh] Feeds due: ' . count($due) . "\n";
}

$batches = array_chunk($due, 12);
foreach ($batches as $chunk) {
    $jobs = [];
    foreach ($chunk as $row) {
        $jobs[] = [
            'id' => $row['id'],
            'url' => $row['url'],
            'etag' => $row['etag'],
            'modified' => $row['modified'],
        ];
    }
    $results = $fetcher->fetchMulti($jobs, 12);
    foreach ($results as $res) {
        $fid = (int) $res['job_id'];
        try {
            $svc->processCronFetchResult($fid, $res);
        } catch (\Throwable $e) {
            error_log('[news-refresh] feed ' . $fid . ': ' . $e->getMessage());
        }
    }
}

$deleted = $svc->runRetention(30);
if ($verbose) {
    echo "[news-refresh] Retention deleted rows: {$deleted}\n";
}

exit(0);
