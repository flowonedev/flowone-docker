#!/usr/bin/env php
<?php
/**
 * Drive Tier-Down Worker (Phase 5a — shadow tier-down; Phase 5c —
 * destructive sweep). One cron, two passes:
 *
 *   PASS 1 (always on):  TIER-DOWN
 *     Selects the N oldest `hot` drive_files rows, streams them to
 *     the email-drive NAS tenant subpath, verifies checksum, and
 *     transitions the row to `cold`. The VPS copy is LEFT IN PLACE
 *     by this pass (shadow). Phase 5c sweep handles deletion later.
 *
 *   PASS 2 (gated by phase5_tier_down_destructive=true): SWEEP
 *     Selects `cold` rows whose tier_changed_at is older than the
 *     configurable grace window (default 24h), re-checksums the NAS
 *     copy, optionally re-checksums the VPS copy, and only then
 *     unlinks the VPS shadow. Per-file MountLock shared with recall
 *     so an in-flight recall can never race with a destructive
 *     unlink. The row stays `cold` (canonical bytes are on NAS;
 *     the shadow just stops existing).
 *
 * Refuses to run unless:
 *   - phase5_tier_down_shadow is true (kill switch)
 *   - storage-ctl status reports a WRITABLE state (HEALTHY / DEGRADED)
 *   - the email-drive tenant root exists (TenantBootstrap completed)
 *
 * Safety guards (apply to both passes):
 *   - --dry-run / --apply explicit; defaults to dry-run on TTY
 *   - --batch=N caps work per pass (default 25)
 *   - Per-file MountLock at /var/lib/flowone/tier-{file_id}.lock
 *   - Wall-clock cap of --max-seconds=N (default 300 = 5 minutes)
 *   - Hard refuse when tier_state column not present (migration 167)
 *
 * Pass-specific:
 *   - --age-days=N        tier-down age threshold (default 30)
 *   - --min-bytes=N       tier-down floor (default 1 MiB)
 *   - --grace-hours=N     sweep grace period (default config value, 24h)
 *   - --sweep-batch=N     sweep cap per pass (default 25)
 *   - --no-tier           skip tier-down pass
 *   - --no-sweep          skip sweep pass (force shadow regardless of flag)
 *
 * Crontab:
 *   23 * * * * /usr/bin/flock -n /var/lock/flowone-drive-tier-down.lock \
 *      /usr/local/lsws/lsphp83/bin/php \
 *      /var/www/vps-email/backend/cron/drive-tier-down.php --apply \
 *      >> /var/log/flowone/drive-tier-down.log 2>&1
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-tier-down must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\HealthState;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\MountLock;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\StorageHealth;
use FlowOne\Storage\TenantResolver;
use FlowOne\Storage\TierBytesMover;
use FlowOne\Storage\TierDestructiveSweeper;
use FlowOne\Storage\TierState;
use FlowOne\Storage\TierStateService;

const TIER_DOWN_TENANT = 'email-drive';

$opts = parseOpts($argv);
if ($opts['help']) {
    printHelp();
    exit(0);
}

// Default to dry-run on TTY to make accidental interactive runs safe.
if (!$opts['apply'] && !$opts['dry_run']) {
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        fwrite(STDERR, "[safety] no --apply or --dry-run; defaulting to --dry-run\n");
        $opts['dry_run'] = true;
    } else {
        fwrite(STDERR, "must specify --apply or --dry-run\n");
        exit(2);
    }
}

$storageConfig = StorageConfig::load();
if (!($storageConfig['phases']['phase5_tier_down_shadow'] ?? false)) {
    fwrite(STDERR, "phase5_tier_down_shadow is OFF — refusing to run tier-down worker\n");
    exit(2);
}
$destructive = (bool) ($storageConfig['phases']['phase5_tier_down_destructive'] ?? false);
// Phase 6d: if LRU selection is on, the tier-down candidate selector
// orders by COALESCE(last_read_at, tier_changed_at) ASC instead of
// the pure-age ordering. CLI --order can override the config.
$orderBy = $opts['order'] !== ''
    ? $opts['order']
    : (($storageConfig['phases']['phase6d_lru_selection'] ?? false) ? 'lru' : 'age');

$signer = HmacSigner::fromKeyFile(
    (string) $storageConfig['state']['hmac_key_path'],
    (int) $storageConfig['state']['hmac_key_mode_max']
);
$journal = new OperationJournal(
    (string) $storageConfig['journal']['path'],
    $signer,
    0
);

// Preflight: NAS health.
$health = StorageHealth::fromConfig(null);
$status = $health->getStatus();
if (!HealthState::isWritable($status->status)) {
    fwrite(STDERR, "[preflight] storage status is {$status->status} (not writable); aborting\n");
    $journal->record('tier_down_aborted_unwritable', [
        'status' => $status->status,
        'root_cause' => $status->rootCause,
    ]);
    exit(3);
}

// Preflight: tenant root exists.
$resolver = TenantResolver::fromConfig($storageConfig);
$tenantRoot = $resolver->rootFor(TIER_DOWN_TENANT);
if (!is_dir($tenantRoot)) {
    fwrite(STDERR, "[preflight] tenant root {$tenantRoot} missing; run `storage-ctl tenants ensure`\n");
    exit(3);
}

$appConfig = require __DIR__ . '/../src/config.php';
$pdo = Database::getConnection($appConfig);

// Sanity: drive_files.tier_state column present?
try {
    $check = $pdo->query("SHOW COLUMNS FROM drive_files LIKE 'tier_state'");
    if ($check->fetch() === false) {
        fwrite(STDERR, "[preflight] drive_files.tier_state missing; run migration 167 first\n");
        exit(3);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[preflight] schema check failed: " . $e->getMessage() . "\n");
    exit(3);
}

$tierService = new TierStateService($pdo, 'drive_files', 'drive_tier_transitions', $journal);
$mover = new TierBytesMover($resolver, new FlowOne\Storage\Invariants($journal, strict: false), $journal);

$vpsBase = rtrim((string) ($appConfig['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive'), '/');
$lockDir = rtrim((string) $storageConfig['state']['dir'], '/');

$startUnix = time();
$startMs = (int) (microtime(true) * 1000);
$deadlineMs = $startMs + $opts['max_seconds'] * 1000;

$summary = [
    'mode'           => $opts['dry_run'] ? 'dry-run' : 'apply',
    'destructive'    => $destructive,
    'tier_pass'      => !$opts['no_tier'],
    'sweep_pass'     => !$opts['no_sweep'] && $destructive,
    'order_by'       => $orderBy,
    'candidates'     => 0,
    'attempted'      => 0,
    'tiered'         => 0,
    'skipped_small'  => 0,
    'skipped_locked' => 0,
    'skipped_missing'=> 0,
    'failed'         => 0,
    'bytes_total'    => 0,
    'sweep'          => null,
    'elapsed_ms'     => 0,
];

$candidates = $opts['no_tier']
    ? []
    : $tierService->findTierDownCandidates($opts['age_days'], $opts['batch'], $orderBy);
$summary['candidates'] = count($candidates);

foreach ($candidates as $cand) {
    if ((int) (microtime(true) * 1000) > $deadlineMs) {
        fwrite(STDERR, "[budget] wall-clock cap reached; stopping\n");
        break;
    }
    $fileId = (int) $cand['id'];
    $size = (int) $cand['size'];

    if ($size < $opts['min_bytes']) {
        $summary['skipped_small']++;
        if ($opts['verbose']) fwrite(STDOUT, "[skip] file_id={$fileId} size={$size} < min_bytes\n");
        continue;
    }
    $summary['attempted']++;

    // Per-file mutex so two parallel workers can't race.
    $lock = new MountLock($lockDir . '/tier-' . $fileId . '.lock', waitTimeoutSec: 1);
    if (!$lock->tryAcquire()) {
        $summary['skipped_locked']++;
        if ($opts['verbose']) fwrite(STDOUT, "[skip] file_id={$fileId} locked by another worker\n");
        continue;
    }

    try {
        $row = fetchDriveRow($pdo, $fileId);
        if ($row === null) {
            $summary['skipped_missing']++;
            continue;
        }
        $userHash = md5(strtolower($row['user_email']));
        $vpsPath = "{$vpsBase}/{$userHash}/{$row['filename']}";
        if (!is_file($vpsPath)) {
            $summary['skipped_missing']++;
            $journal->record('tier_down_vps_missing', [
                'file_id' => $fileId, 'path' => $vpsPath,
            ]);
            continue;
        }
        $checksum = (string) ($row['checksum'] ?? '');
        if ($checksum === '') {
            $checksum = md5_file($vpsPath) ?: '';
            if ($checksum === '') {
                $summary['failed']++;
                $journal->record('tier_down_checksum_unreadable', [
                    'file_id' => $fileId, 'path' => $vpsPath,
                ]);
                continue;
            }
            $upd = $pdo->prepare("UPDATE drive_files SET checksum = :c WHERE id = :id");
            $upd->execute([':c' => $checksum, ':id' => $fileId]);
        }

        // Destination relative to the email-drive tenant subpath:
        //   /mnt/nas-drive/drive/{user_hash}/{filename}
        $relUnderTenant = "{$userHash}/{$row['filename']}";
        $relUnderMount  = "drive/{$relUnderTenant}";

        if ($opts['dry_run']) {
            if ($opts['verbose']) fwrite(STDOUT, "[would-tier] file_id={$fileId} size={$size} -> {$relUnderMount}\n");
            $summary['tiered']++;
            continue;
        }

        // hot -> tiering (rollback later if mover fails)
        $tierService->transitionTo($fileId, TierState::TIERING, 'drive-tier-down', 'phase5a worker');

        $move = $mover->tierDown($vpsPath, TIER_DOWN_TENANT, $relUnderTenant, $checksum);
        if (!$move['ok']) {
            // Rollback: tiering -> hot
            try {
                $tierService->transitionTo($fileId, TierState::HOT, 'drive-tier-down', 'rollback: ' . ($move['error'] ?? 'unknown'));
            } catch (\Throwable $e) {
                $journal->record('tier_down_rollback_failed', [
                    'file_id' => $fileId, 'error' => $e->getMessage(),
                ]);
            }
            $summary['failed']++;
            fwrite(STDERR, "[fail] file_id={$fileId}: " . ($move['error'] ?? 'unknown') . "\n");
            continue;
        }

        // Update nas_relative_path to point at the new tenant subpath.
        $upd = $pdo->prepare(
            "UPDATE drive_files
             SET nas_relative_path = :nrp
             WHERE id = :id"
        );
        $upd->execute([':nrp' => $relUnderMount, ':id' => $fileId]);

        // tiering -> cold (commits the tier-down)
        $tierService->transitionTo(
            $fileId,
            TierState::COLD,
            'drive-tier-down',
            'tier-down committed',
            null,
            $move['bytes'],
            $move['duration_ms']
        );

        // Phase 5c: VPS deletion is no longer done inline. The
        // destructive sweep pass below picks up cold rows whose
        // tier_changed_at has aged past the grace window, re-verifies
        // the NAS checksum, and only THEN unlinks. This protects
        // against a checksum bug in the mover destroying bytes in
        // the same call that produced a (potentially broken) NAS copy.

        $summary['tiered']++;
        $summary['bytes_total'] += $move['bytes'];
        if ($opts['verbose']) {
            fwrite(STDOUT, "[ok] file_id={$fileId} bytes={$move['bytes']} dur={$move['duration_ms']}ms -> {$relUnderMount}\n");
        }
    } catch (\Throwable $e) {
        $summary['failed']++;
        $journal->record('tier_down_exception', [
            'file_id' => $fileId, 'error' => $e->getMessage(),
        ]);
        fwrite(STDERR, "[exception] file_id={$fileId}: {$e->getMessage()}\n");
        // Try rollback to hot.
        try {
            if ($tierService->getState($fileId) === TierState::TIERING) {
                $tierService->transitionTo($fileId, TierState::HOT, 'drive-tier-down', 'rollback: exception');
            }
        } catch (\Throwable) {
            // swallow — already journalled
        }
    } finally {
        $lock->release();
    }
}

// ─── Phase 5c sweep pass ────────────────────────────────────────────────
// Only runs when both flags align:
//   - phase5_tier_down_destructive=true in storage config
//   - --no-sweep NOT passed on the CLI
// Uses the SAME per-file MountLock as tier-down + recall, so an in-flight
// recall can never race with a destructive unlink.
if (!$opts['no_sweep'] && $destructive) {
    $graceHours = $opts['grace_hours']
        ?? (int) ($storageConfig['tier']['destructive_grace_hours'] ?? TierDestructiveSweeper::DEFAULT_GRACE_HOURS);
    $sweepBatch = $opts['sweep_batch'];

    // Respect the remaining wall-clock budget; never let sweep eat all of it.
    $remainingSec = max(10, $opts['max_seconds'] - (int) ((microtime(true) * 1000 - $startMs) / 1000));

    $sweeper = new TierDestructiveSweeper(
        pdo:          $pdo,
        tierService:  $tierService,
        mover:        $mover,
        resolver:     $resolver,
        tenant:       TIER_DOWN_TENANT,
        vpsBasePath:  $vpsBase,
        lockDir:      $lockDir,
        journal:      $journal,
        tableName:    'drive_files',
        strict:       true,
        lockWaitSec:  1,
    );
    $sweepResult = $sweeper->sweep($graceHours, $sweepBatch, $opts['dry_run'], $remainingSec);
    $summary['sweep'] = $sweepResult;

    if ($opts['verbose']) {
        foreach ($sweepResult['entries'] as $e) {
            fwrite(STDOUT, "[sweep:{$e['action']}] file_id={$e['file_id']} bytes={$e['bytes']}\n");
        }
    }
}

$summary['elapsed_ms'] = (int) (microtime(true) * 1000) - $startMs;
$journal->record('tier_down_run', $summary);

if ($opts['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    renderSummary($summary);
}
exit($summary['failed'] > 0 ? 1 : 0);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = [
        'help'        => false,
        'apply'       => false,
        'dry_run'     => false,
        'verbose'     => false,
        'json'        => false,
        'batch'       => 25,
        'age_days'    => 30,
        'min_bytes'   => 1048576,
        'max_seconds' => 300,
        'no_tier'     => false,
        'no_sweep'    => false,
        'sweep_batch' => 25,
        'grace_hours' => null,
        'order'       => '', // '' = inherit from config (phase6d_lru_selection); 'age' | 'lru' override
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--apply') { $opts['apply'] = true; continue; }
        if ($arg === '--dry-run') { $opts['dry_run'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if ($arg === '--json') { $opts['json'] = true; continue; }
        if ($arg === '--no-tier') { $opts['no_tier'] = true; continue; }
        if ($arg === '--no-sweep') { $opts['no_sweep'] = true; continue; }
        if (str_starts_with($arg, '--batch=')) {
            $opts['batch'] = max(1, (int) substr($arg, strlen('--batch=')));
            continue;
        }
        if (str_starts_with($arg, '--age-days=')) {
            $opts['age_days'] = max(0, (int) substr($arg, strlen('--age-days=')));
            continue;
        }
        if (str_starts_with($arg, '--min-bytes=')) {
            $opts['min_bytes'] = max(0, (int) substr($arg, strlen('--min-bytes=')));
            continue;
        }
        if (str_starts_with($arg, '--max-seconds=')) {
            $opts['max_seconds'] = max(10, (int) substr($arg, strlen('--max-seconds=')));
            continue;
        }
        if (str_starts_with($arg, '--sweep-batch=')) {
            $opts['sweep_batch'] = max(1, (int) substr($arg, strlen('--sweep-batch=')));
            continue;
        }
        if (str_starts_with($arg, '--grace-hours=')) {
            $opts['grace_hours'] = max(0, (int) substr($arg, strlen('--grace-hours=')));
            continue;
        }
        if (str_starts_with($arg, '--order=')) {
            $val = strtolower(substr($arg, strlen('--order=')));
            $opts['order'] = ($val === 'lru' || $val === 'age') ? $val : '';
            continue;
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
Drive Tier-Down Worker (Phase 5a tier + Phase 5c destructive sweep)

Usage:
  drive-tier-down.php --apply            run both passes
  drive-tier-down.php --dry-run          list what would happen, no I/O
  drive-tier-down.php --no-sweep         tier-down only (ignore Phase 5c flag)
  drive-tier-down.php --no-tier          sweep only (assumes destructive on)

Tier-down pass options:
  --batch=N          tier-down rows per pass (default 25)
  --age-days=N       candidate age threshold (default 30)
  --min-bytes=N      skip tiny files (default 1 MiB)
  --order=age|lru    candidate ordering. Defaults from config
                     (phase6d_lru_selection: lru=on -> 'lru', else 'age').

Sweep pass options (requires phase5_tier_down_destructive = true):
  --sweep-batch=N    sweep rows per pass (default 25)
  --grace-hours=N    cold-window grace before VPS unlink (default 24)

Shared:
  --max-seconds=N    wall-clock cap (default 300)
  --verbose / -v     chatty per-file output
  --json             machine-readable summary

Default shadow behaviour (phase5_tier_down_destructive=false):
  PASS 1 runs and copies bytes to NAS; PASS 2 is skipped; VPS bytes remain.

Destructive behaviour (phase5_tier_down_destructive=true):
  PASS 1 runs as above. PASS 2 picks up cold rows whose tier_changed_at
  is older than --grace-hours, re-verifies the NAS checksum (and VPS
  checksum in strict mode), and unlinks the VPS shadow. Per-file lock
  is shared with recall so in-flight reads can never race.

TXT;
}

function fetchDriveRow(\PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, user_email, filename, size, checksum, nas_relative_path, tier_state
         FROM drive_files WHERE id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function renderSummary(array $s): void
{
    $mode = $s['mode'];
    $dest = $s['destructive'] ? 'destructive' : 'shadow';
    $order = $s['order_by'] ?? 'age';
    echo "[DRIVE-TIER-DOWN] mode={$mode} layer={$dest} order={$order} ";
    echo "tier_pass=" . ($s['tier_pass'] ? 'on' : 'off') . " ";
    echo "sweep_pass=" . ($s['sweep_pass'] ? 'on' : 'off') . "\n";

    echo "  tier:  candidates={$s['candidates']} attempted={$s['attempted']} ";
    echo "tiered={$s['tiered']} failed={$s['failed']} ";
    echo "skipped(small={$s['skipped_small']},locked={$s['skipped_locked']},missing={$s['skipped_missing']}) ";
    echo "bytes={$s['bytes_total']}\n";

    if ($s['sweep'] !== null) {
        $sw = $s['sweep'];
        echo "  sweep: candidates={$sw['candidates']} attempted={$sw['attempted']} ";
        echo "swept={$sw['swept']} failed={$sw['failed']} ";
        echo "skipped(locked={$sw['skipped_locked']},drift={$sw['skipped_state_drift']},";
        echo "nas={$sw['skipped_nas_missing']},checksum={$sw['skipped_checksum_drift']},";
        echo "vps={$sw['skipped_vps_missing']}) bytes={$sw['bytes_total']}\n";
    }

    echo "  elapsed_ms={$s['elapsed_ms']}\n";
}
