#!/usr/bin/env php
<?php
/**
 * Drive Tier State Backfill (Phase 4).
 *
 * Migration 167 added the tier_state column to drive_files and seeded
 * historical rows from storage_location at migration time. New uploads
 * (post-167) get tier_state='hot' from the column default. This cron
 * keeps tier_state in sync with storage_location for as long as
 * DriveService keeps writing only to storage_location — i.e. the
 * Phase 4 -> Phase 5 transition window.
 *
 * Once Phase 5 lands and DriveService writes tier_state directly, this
 * cron becomes a no-op (every reconcile pass reports in_sync=N,
 * updated=0). At that point we can disable it.
 *
 * Safety guards:
 *   - Default mode is --dry-run when run interactively. Use --apply
 *     to actually mutate rows.
 *   - Each pass is bounded by --batch (default 500) to keep the
 *     working set small.
 *   - Locks via flock(/var/lock/flowone-drive-tier-backfill.lock) when
 *     run from cron; non-blocking. If a prior pass is still running
 *     this one exits silently.
 *   - Writes a structured log line per pass to
 *     storage/logs/drive-tier-backfill.log and to the FlowOne
 *     operation journal when available.
 *   - --json prints the result as a structured JSON object for any
 *     monitoring integration.
 *
 * Crontab:
 *   13 * * * * /usr/bin/flock -n /var/lock/flowone-drive-tier-backfill.lock \
 *      /usr/local/lsws/lsphp83/bin/php \
 *      /var/www/vps-email/backend/cron/drive-tier-backfill.php --apply \
 *      >> /var/log/flowone/drive-tier-backfill.log 2>&1
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-backfill must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

$opts = parseOpts($argv);
if ($opts['help']) {
    printHelp();
    exit(0);
}

// Default to dry-run on TTY when neither --apply nor --dry-run is given.
if (!$opts['apply'] && !$opts['dry_run']) {
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDERR, "[safety] no --apply or --dry-run; defaulting to --dry-run\n");
        $opts['dry_run'] = true;
    } else {
        fwrite(STDERR, "must specify --apply or --dry-run\n");
        exit(2);
    }
}

$config = require __DIR__ . '/../src/config.php';
$pdo = Database::getConnection($config);

// Best-effort wire-up to the FlowOne operation journal. The shared
// storage library may not be installed in dev environments — degrade
// gracefully without it. Production server always has it.
$journal = null;
try {
    $storageConfig = StorageConfig::load();
    $signer = HmacSigner::fromKeyFile(
        (string) $storageConfig['state']['hmac_key_path'],
        (int) $storageConfig['state']['hmac_key_mode_max']
    );
    $journal = new OperationJournal(
        (string) $storageConfig['journal']['path'],
        $signer,
        0
    );
} catch (\Throwable $e) {
    if ($opts['verbose']) {
        fwrite(STDERR, "[journal] disabled: " . $e->getMessage() . "\n");
    }
}

$service = new TierStateService(
    $pdo,
    'drive_files',
    'drive_tier_transitions',
    $journal
);

$startUnix = time();
$startMonoMs = (int) (microtime(true) * 1000);
$stats = $service->reconcileLegacyLocation(
    batchLimit: $opts['batch'],
    actor: 'drive-tier-backfill',
    dryRun: $opts['dry_run']
);
$elapsedMs = (int) (microtime(true) * 1000) - $startMonoMs;

$counts = $service->counts();

$result = [
    'mode'        => $opts['dry_run'] ? 'dry-run' : 'apply',
    'batch_limit' => $opts['batch'],
    'started_at'  => date('c', $startUnix),
    'elapsed_ms'  => $elapsedMs,
    'reconcile'   => $stats,
    'tier_counts' => $counts,
];

$journal?->record('drive_tier_backfill_run', $result);

if ($opts['json']) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    renderResult($result, $opts);
}

// Exit 0 even when stats[failed] > 0 so a single bad row doesn't
// take the cron entry to a failed state — but log it loudly.
exit(0);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = [
        'help'    => false,
        'apply'   => false,
        'dry_run' => false,
        'verbose' => false,
        'json'    => false,
        'batch'   => 500,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--apply') { $opts['apply'] = true; continue; }
        if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if ($arg === '--json') { $opts['json'] = true; continue; }
        if (str_starts_with($arg, '--batch=')) {
            $opts['batch'] = max(1, (int) substr($arg, strlen('--batch=')));
            continue;
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
Drive Tier State Backfill (Phase 4)

Usage:
  drive-tier-backfill.php --apply              perform reconciliation
  drive-tier-backfill.php --dry-run            list intended changes
  drive-tier-backfill.php --batch=N            rows per pass (default 500)
  drive-tier-backfill.php --verbose            chatty logging
  drive-tier-backfill.php --json               machine-readable output

Reconciles drive_files.tier_state with the legacy storage_location
column. Idempotent; safe to run hourly. Defaults to --dry-run when
run interactively.

TXT;
}

function renderResult(array $r, array $opts): void
{
    $mode = $r['mode'];
    $st = $r['reconcile'];
    $counts = $r['tier_counts'];
    echo "[DRIVE-TIER-BACKFILL] mode={$mode} elapsed_ms={$r['elapsed_ms']} ";
    echo "scanned={$st['scanned']} in_sync={$st['in_sync']} ";
    echo "updated={$st['updated']} skipped_terminal={$st['skipped_terminal']} ";
    echo "failed={$st['failed']}\n";
    echo "[DRIVE-TIER-BACKFILL] tier_counts: ";
    $parts = [];
    foreach ($counts as $state => $n) {
        $parts[] = "{$state}={$n}";
    }
    echo implode(' ', $parts) . "\n";
}
