<?php
/**
 * Migration scheduler self-test (live, non-destructive)
 * ---------------------------------------------------------------
 * Verifies the delta-sync scheduler end-to-end against the REAL panel DB
 * without touching any real mailbox or running imapsync:
 *
 *   1. Schema       - the schedule columns exist and migration_mode includes
 *                     the 'sweep' phase (controller self-heal worked).
 *   2. Tooling      - imapsync + the runner script are present.
 *   3. Delta pickup - a throwaway "due" row is selected by the dispatcher
 *                     (--dry-run, so nothing is launched).
 *   4. Sweep pickup - the same row, armed as a post-cutover sweep, is picked
 *                     up as a 'sweep'.
 *
 * The throwaway row uses source_host 'selftest.invalid' and is ALWAYS deleted
 * at the end (pass or fail) unless --keep is passed. Safe to run on production.
 *
 * Usage:
 *   php migration-selftest.php          # run the checks, clean up after
 *   php migration-selftest.php --heal   # add any missing columns first (CLI
 *                                       #   self-heal — use when the API host's
 *                                       #   OPcache hasn't picked up the new
 *                                       #   controller yet), then run checks
 *   php migration-selftest.php --keep   # leave the throwaway row in place
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line\n");
}

$keep = in_array('--keep', $argv, true);
$heal = in_array('--heal', $argv, true);

/**
 * Idempotently bring imap_migrations up to the current schema. Mirrors
 * ImapMigrationController::ensureColumnsExist() so a headless box (no API hit
 * yet, or stale OPcache) can be made test-ready from the CLI. Returns the list
 * of columns it actually added.
 */
function healSchema(PDO $pdo): array
{
    $added = [];
    $columns = [
        'total_messages'         => "ADD COLUMN total_messages INT DEFAULT 0",
        'transferred_messages'   => "ADD COLUMN transferred_messages INT DEFAULT 0",
        'transferred_bytes'      => "ADD COLUMN transferred_bytes BIGINT DEFAULT 0",
        'verified'               => "ADD COLUMN verified TINYINT(1) DEFAULT 0",
        'migration_mode'         => "ADD COLUMN migration_mode ENUM('initial','delta','final','sweep') NOT NULL DEFAULT 'initial'",
        'schedule_enabled'       => "ADD COLUMN schedule_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'delta_interval_minutes' => "ADD COLUMN delta_interval_minutes INT NOT NULL DEFAULT 360",
        'next_run_at'            => "ADD COLUMN next_run_at DATETIME NULL",
        'last_delta_at'          => "ADD COLUMN last_delta_at DATETIME NULL",
        'sweep_at'               => "ADD COLUMN sweep_at DATETIME NULL",
    ];
    foreach ($columns as $name => $ddl) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM imap_migrations LIKE ?");
        $stmt->execute([$name]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE imap_migrations {$ddl}");
            $added[] = $name;
        }
    }
    $col = $pdo->query("SHOW COLUMNS FROM imap_migrations LIKE 'migration_mode'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'] ?? '', "'sweep'") === false) {
        $pdo->exec("ALTER TABLE imap_migrations MODIFY COLUMN migration_mode ENUM('initial','delta','final','sweep') NOT NULL DEFAULT 'initial'");
        $added[] = "migration_mode(+sweep)";
    }
    return $added;
}

// ---- Config bootstrap (same as the runner/dispatcher) -------------------
$configFile = dirname(__DIR__) . '/config.php';
$localConfigFile = dirname(__DIR__) . '/config.local.php';
if (!file_exists($configFile)) {
    die("Config file not found at {$configFile}\n");
}
$config = require $configFile;
if (file_exists($localConfigFile)) {
    $config = array_replace_recursive($config, require $localConfigFile);
}

try {
    $db = $config['database'];
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
};

echo "=== FlowOne migration scheduler self-test ===\n\n";

// ---- 0. Optional CLI schema heal ----------------------------------------
if ($heal) {
    echo "[0] Schema heal (--heal)\n";
    try {
        $added = healSchema($pdo);
        echo $added
            ? "  added: " . implode(', ', $added) . "\n\n"
            : "  nothing to add — schema already current\n\n";
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        echo "  (does the DB user have ALTER privilege on imap_migrations?)\n\n";
    }
}

// ---- 1. Schema ----------------------------------------------------------
echo "[1] Schema\n";
$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM imap_migrations")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[$c['Field']] = $c['Type'];
}
foreach (['schedule_enabled', 'delta_interval_minutes', 'next_run_at', 'last_delta_at', 'sweep_at'] as $col) {
    $check("column {$col}", isset($cols[$col]), $cols[$col] ?? 'MISSING');
}
$check(
    "migration_mode includes 'sweep'",
    isset($cols['migration_mode']) && stripos($cols['migration_mode'], "'sweep'") !== false,
    $cols['migration_mode'] ?? 'MISSING'
);
if (!isset($cols['schedule_enabled'])) {
    echo "\n  HINT: open the Panel → Migration tab once (or hit /api/imap-migration)\n";
    echo "        to trigger the column self-heal, then re-run this test.\n";
}

// ---- 2. Tooling ---------------------------------------------------------
echo "\n[2] Tooling\n";
$runner = __DIR__ . '/run-imap-migration.php';
$dispatcher = __DIR__ . '/run-due-migrations.php';
$check('runner script present', file_exists($runner), $runner);
$check('dispatcher script present', file_exists($dispatcher), $dispatcher);
$imapsync = trim((string) shell_exec('which imapsync 2>/dev/null'));
$check('imapsync installed', $imapsync !== '', $imapsync ?: 'not found (apt install imapsync)');

// ---- 3 & 4. Dispatch pickup (dry-run) -----------------------------------
echo "\n[3] Delta + sweep pickup (dry-run, no imapsync launched)\n";
$testId = null;
try {
    $hostname = gethostname() ?: 'localhost';
    $pdo->prepare("
        INSERT INTO imap_migrations
            (type, source_host, source_port, source_ssl, dest_host, dest_port, dest_ssl,
             accounts, total_accounts, status, log_file, migration_mode,
             schedule_enabled, delta_interval_minutes, next_run_at)
        VALUES ('single', 'selftest.invalid', 993, 1, ?, 993, 1,
                '[]', 0, 'completed', '/tmp/flowone-selftest.log', 'initial',
                1, 360, DATE_SUB(NOW(), INTERVAL 1 MINUTE))
    ")->execute([$hostname]);
    $testId = (int) $pdo->lastInsertId();
    echo "  (throwaway migration #{$testId} created)\n";

    $phpBin = PHP_BINARY ?: 'php';
    $runDispatch = function () use ($phpBin, $dispatcher): string {
        return (string) shell_exec(sprintf('%s %s --dry-run 2>&1', escapeshellarg($phpBin), escapeshellarg($dispatcher)));
    };

    $out1 = $runDispatch();
    $check(
        'due row picked up as delta',
        (bool) preg_match('/#' . $testId . '\s+due\s+->\s+delta/i', $out1),
        'dispatcher dry-run output'
    );

    // Re-arm the same row as a post-cutover sweep and re-check.
    $pdo->prepare("
        UPDATE imap_migrations
        SET schedule_enabled = 0, next_run_at = NULL, sweep_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        WHERE id = ?
    ")->execute([$testId]);

    $out2 = $runDispatch();
    $check(
        'due row picked up as sweep (precedence over delta)',
        (bool) preg_match('/#' . $testId . '\s+due\s+->\s+sweep/i', $out2),
        'dispatcher dry-run output'
    );
} catch (Exception $e) {
    $check('dispatch pickup', false, $e->getMessage());
} finally {
    if ($testId !== null && !$keep) {
        try {
            $pdo->prepare("DELETE FROM imap_migrations WHERE id = ? AND source_host = 'selftest.invalid'")
                ->execute([$testId]);
            echo "  (throwaway migration #{$testId} cleaned up)\n";
        } catch (Exception $e) {
            echo "  WARNING: failed to clean up throwaway row #{$testId}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== Result: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
