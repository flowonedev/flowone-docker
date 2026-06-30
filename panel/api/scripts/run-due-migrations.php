<?php
/**
 * Delta-sync scheduler dispatcher
 * ---------------------------------------------------------------
 * Finds imap_migrations rows whose next scheduled run is due and kicks off the
 * canonical runner (run-imap-migration.php) for each. Two kinds of due work:
 *
 *   - Periodic delta : schedule_enabled = 1 AND next_run_at <= NOW()
 *                      -> runs mode='delta', re-arms next_run_at = NOW()+interval
 *   - Post-cutover    : sweep_at IS NOT NULL AND sweep_at <= NOW()
 *     sweep             -> runs mode='sweep' ONCE, then disarms (sweep_at=NULL,
 *                          schedule_enabled=0)
 *
 * Every run is the same non-destructive imapsync (delta-safe), so re-runs only
 * copy new messages. A flock keeps overlapping timer ticks from stacking, and
 * rows already 'running'/'pending' are skipped so we never double-dispatch.
 *
 * Meant to be invoked by a systemd timer (vpsadmin-migration-scheduler.timer)
 * every few minutes. Safe to run by hand.
 *
 * Usage:
 *   php run-due-migrations.php            # dispatch due migrations
 *   php run-due-migrations.php --dry-run  # report due migrations, dispatch nothing
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

$dryRun = in_array('--dry-run', $argv, true);

// ---- Config bootstrap (identical to run-imap-migration.php) -------------
$configFile = dirname(__DIR__) . '/config.php';
$localConfigFile = dirname(__DIR__) . '/config.local.php';

if (!file_exists($configFile)) {
    die("Config file not found\n");
}
$config = require $configFile;
if (file_exists($localConfigFile)) {
    $config = array_replace_recursive($config, require $localConfigFile);
}

// ---- Single-instance lock ----------------------------------------------
// Dry-run is read-only (it never arms a row or launches a runner), so it skips
// the lock entirely — that keeps `--dry-run` (e.g. the self-test) from being
// blocked by, or interfering with, the live systemd timer tick.
$lock = null;
if (!$dryRun) {
    $lockPath = sys_get_temp_dir() . '/flowone-migration-scheduler.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        // Another dispatcher tick is still running — nothing to do.
        echo "[" . date('Y-m-d H:i:s') . "] Another dispatcher run holds the lock; exiting.\n";
        exit(0);
    }
}

// ---- DB connection ------------------------------------------------------
try {
    $dbConfig = $config['database'];
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

$runnerScript = __DIR__ . '/run-imap-migration.php';
$logDir = '/var/log/imapsync';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$runnerLog = is_dir($logDir) ? $logDir . '/runner.log' : sys_get_temp_dir() . '/imapsync-runner.log';
$phpBin = PHP_BINARY ?: 'php';

$now = date('Y-m-d H:i:s');

/**
 * Find migrations that are due for a delta or a sweep, skipping any that are
 * already in flight. Selection is ordered so the longest-waiting runs first.
 *
 * Real runs never touch 'selftest.invalid' rows (the self-test's throwaway),
 * so a leftover/--keep test row can never trigger a real imapsync. Dry-run
 * still sees them so the self-test can verify selection + classification.
 */
$selftestFilter = $dryRun ? '' : " AND source_host <> 'selftest.invalid' ";
$stmt = $pdo->query("
    SELECT id, status, schedule_enabled, delta_interval_minutes, next_run_at, sweep_at,
           (sweep_at IS NOT NULL AND sweep_at <= NOW()) AS sweep_due
    FROM imap_migrations
    WHERE status NOT IN ('running', 'pending', 'cancelled')
      {$selftestFilter}
      AND (
            (sweep_at IS NOT NULL AND sweep_at <= NOW())
         OR (schedule_enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= NOW())
      )
    ORDER BY COALESCE(sweep_at, next_run_at) ASC
");
$due = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$due) {
    echo "[{$now}] No delta/sweep migrations due.\n";
}

$dispatched = 0;

/**
 * Run the canonical runner for one migration id and WAIT for it to finish.
 *
 * Synchronous on purpose: launching a detached background job from a systemd
 * oneshot (or the LiteSpeed web SAPI) is unreliable — the child is reaped when
 * the parent process exits. Running it in the foreground means the dispatcher
 * (kept alive by systemd for the duration) reliably drives each job to
 * completion. The single-instance flock keeps overlapping timer ticks from
 * stacking, so long-running jobs simply hold the tick until they finish.
 */
$runNow = function (int $id) use ($phpBin, $runnerScript, $runnerLog): void {
    $cmd = sprintf(
        '%s %s %d >> %s 2>&1',
        escapeshellarg($phpBin),
        escapeshellarg($runnerScript),
        $id,
        escapeshellarg($runnerLog)
    );
    exec($cmd);
};

foreach ($due as $m) {
    $id = (int) $m['id'];
    // Sweep takes precedence over a periodic delta when both are due. The
    // "is this a due sweep?" decision is made in SQL (sweep_due) so it uses
    // MySQL's NOW() against the stored datetime — comparing in PHP would be
    // wrong whenever the DB timezone differs from PHP's default timezone.
    $isSweep = (bool) ((int) ($m['sweep_due'] ?? 0));
    $mode = $isSweep ? 'sweep' : 'delta';
    $interval = max(30, (int) ($m['delta_interval_minutes'] ?: 360));

    echo "[{$now}] Migration #{$id} due -> {$mode}" . ($dryRun ? ' (dry run)' : '') . "\n";
    if ($dryRun) {
        continue;
    }

    try {
        if ($isSweep) {
            // One-off sweep: arm 'pending' then disarm the schedule entirely.
            $pdo->prepare("
                UPDATE imap_migrations
                SET status = 'pending', migration_mode = 'sweep', progress = 0,
                    current_account = NULL, error_message = NULL, pid = NULL,
                    last_delta_at = NOW(), sweep_at = NULL, schedule_enabled = 0, next_run_at = NULL
                WHERE id = ? AND status NOT IN ('running', 'pending')
            ")->execute([$id]);
        } else {
            // Periodic delta: arm 'pending' and re-schedule the next tick.
            $pdo->prepare("
                UPDATE imap_migrations
                SET status = 'pending', migration_mode = 'delta', progress = 0,
                    current_account = NULL, error_message = NULL, pid = NULL,
                    last_delta_at = NOW(), next_run_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE id = ? AND status NOT IN ('running', 'pending')
            ")->execute([$interval, $id]);
        }

        // Hand off to the canonical runner and wait for it.
        $runNow($id);
        $dispatched++;
    } catch (Exception $e) {
        echo "[{$now}] Migration #{$id} dispatch failed: " . $e->getMessage() . "\n";
    }
}

// ---- Cold-start pass ----------------------------------------------------
// Pick up any 'pending' migration that no runner has claimed yet — e.g. the web
// UI could not spawn the background runner under LiteSpeed, or a manual
// "Run delta now" / "Final cutover" re-armed a finished row to 'pending'.
//
// A row only stays 'pending' while NO runner is processing it: the runner's
// first action is an atomic claim (UPDATE ... SET status='running' WHERE
// status='pending'), so the instant a runner takes a job the row leaves this
// result set. That atomic claim also means it's safe to hand a still-booting
// web-spawned job to the runner here — both processes race for the same claim
// and exactly one wins, so imapsync never double-runs. No time-grace needed.
$pendingStmt = $pdo->query("
    SELECT id
    FROM imap_migrations
    WHERE status = 'pending'
      {$selftestFilter}
    ORDER BY created_at ASC
");
$pendingRows = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingRows as $p) {
    $id = (int) $p['id'];
    echo "[{$now}] Migration #{$id} pending (unclaimed) -> starting" . ($dryRun ? ' (dry run)' : '') . "\n";
    if ($dryRun) {
        continue;
    }
    try {
        $runNow($id);
        $dispatched++;
    } catch (Exception $e) {
        echo "[{$now}] Migration #{$id} cold-start failed: " . $e->getMessage() . "\n";
    }
}

echo "[{$now}] Dispatched {$dispatched} migration(s).\n";

if ($lock) {
    flock($lock, LOCK_UN);
}
exit(0);
