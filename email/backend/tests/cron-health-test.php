#!/usr/bin/env php
<?php
/**
 * FlowOne Cron Health Test.
 *
 * Non-destructive sanity check for every script under
 * email/backend/cron/. Designed to be safe to run any time and from
 * any environment -- it does not execute the cron bodies, it only
 * asserts the static preconditions that historically blew up in
 * production (wrong `require ... config.php` path, missing `.env`
 * bootstrap, missing DB password, missing tables, etc.).
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   -- PHP extensions, autoloader, .env discoverable
 *   config      -- every cron's `require ... config.php` resolves to a real file
 *   bootstrap   -- every cron that touches Database::getConnection sources .env
 *   db          -- DB password is non-empty AND PDO connect + `SELECT 1` works
 *   tables      -- core tables the crons touch exist
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/cron-health-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output (per-file decisions)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight + config groups only (no DB)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            no-op, accepted for parity with other tests
 *   --help                 show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'verbose',
    'json',
    'smoke',
    'only:',
    'skip-send',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke   = isset($opts['smoke']);
$only = isset($opts['only'])
    ? array_map('trim', explode(',', (string) $opts['only']))
    : [];

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cron-health-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

// ---- Crons that touch Database::getConnection ----
// Used by the bootstrap + db groups to know which scripts MUST have .env
// loaded before they reach the config require. Manually curated rather
// than scraped, so newly-added crons that connect to MySQL need an
// explicit entry here -- if you forget, the test stays silent rather
// than yelling about an unrelated cron.
$cronsNeedingDb = [
    'process-automation-hub.php',
    'process-boardpro-automation.php',
    'process-crm-automation.php',
    'process-email-queue.php',
    'process-scheduled-chat.php',
    'process-scheduled-emails.php',
    'process-scope-radar.php',
    // reconcile-mailboxes.php removed in Phase 3 of DB-as-truth refactor:
    // orphan detection now flows through the mailsync worker's QRESYNC
    // expunge events (idleManager.js) instead of a periodic SEARCH ALL.
    'news-refresh.php',
    'run-projecthub-inactivity.php',
    'dual-write-readiness.php',
    'cleanup-drive.php',
    'cleanup-stale-rooms.php',
    'sync-sieve-ooo.php',
    'index-attachments.php',
    'prune-folder-snapshots.php',
    'verify-folder-identity-consistency.php',
    'folder-rename-analyzer.php',
    'backfill-folder-ids.php',
    'all-mail-coverage-report.php',
];

function ch_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) {
        echo $msg . "\n";
    }
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function ch_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function ch_record(string $name, string $status, int $ms, ?string $error = null, array $meta = []): void
{
    global $totalTests, $passed, $failed, $warnings, $results;
    $totalTests++;
    if ($status === 'PASS') {
        $passed++;
    } elseif ($status === 'WARN') {
        $warnings++;
    } else {
        $failed++;
    }
    $results[] = [
        'name' => $name,
        'status' => $status,
        'ms' => $ms,
        'error' => $error,
        'meta' => $meta,
    ];
    ch_out(sprintf('  [%-4s]  %s (%dms)', $status, $name, $ms));
    if ($error !== null) {
        ch_out('          -> ' . $error);
    }
}

function ch_test(string $name, callable $fn, int $timeoutSec = 10): void
{
    $start = microtime(true);
    if (function_exists('pcntl_alarm')) {
        pcntl_signal(SIGALRM, function () {
            throw new \RuntimeException('test exceeded timeout');
        });
        pcntl_alarm($timeoutSec);
    }
    try {
        $result = $fn();
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (is_array($result) && ($result['status'] ?? null) === 'WARN') {
            ch_record($name, 'WARN', $ms, $result['msg'] ?? null, $result['meta'] ?? []);
        } else {
            $meta = is_array($result) ? ($result['meta'] ?? []) : [];
            ch_record($name, 'PASS', $ms, null, $meta);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        ch_record($name, 'FAIL', $ms, $e->getMessage());
        global $verbose;
        if ($verbose) {
            ch_out('          at ' . $e->getFile() . ':' . $e->getLine());
        }
    } finally {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}

/**
 * Read a cron script and extract every `require ... config.php` literal
 * path that uses `__DIR__`. Returns the resolved absolute path(s).
 * Crons that build the path dynamically are skipped (they'd hit the
 * file-exists check at runtime instead, which is enough).
 */
function ch_extract_config_paths(string $cronFile): array
{
    $src = @file_get_contents($cronFile);
    if ($src === false) {
        return [];
    }
    $paths = [];
    // Matches:  require[_once] (__DIR__ . '/../something/config.php')
    //           $x = require __DIR__ . '/../something/config.php';
    $pattern = '/require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]*config\.php)[\'"]/i';
    if (preg_match_all($pattern, $src, $m)) {
        foreach ($m[1] as $rel) {
            $paths[] = realpath(dirname($cronFile) . $rel) ?: (dirname($cronFile) . $rel);
        }
    }
    return $paths;
}

/**
 * Returns true if the cron sources .env before connecting to the DB:
 * either by requiring cron/bootstrap.php, or by manually parsing the
 * .env file.
 */
function ch_loads_env(string $cronFile): bool
{
    $src = @file_get_contents($cronFile);
    if ($src === false) {
        return false;
    }
    if (preg_match('/require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]\/bootstrap\.php[\'"]/i', $src)) {
        return true;
    }
    if (preg_match('/[\'"]\.env[\'"]/', $src) && preg_match('/\bputenv\s*\(/', $src)) {
        return true;
    }
    return false;
}

// ===== HEADER =====
ch_out('=================================================================');
ch_out('  FlowOne Cron Health Test');
ch_out('  ' . date('Y-m-d H:i:s T'));
ch_out('  Mode:      ' . ($smoke ? 'SMOKE' : 'FULL'));
ch_out('  Groups:    ' . (empty($only) ? 'all' : implode(',', $only)));
ch_out('  Log:       ' . $logFile);
ch_out('=================================================================');

$cronDir = realpath(__DIR__ . '/../cron');
if ($cronDir === false || !is_dir($cronDir)) {
    ch_out('FATAL: cron directory missing at ' . __DIR__ . '/../cron');
    exit(1);
}

// =================================================================
// 1. PREFLIGHT
// =================================================================
if (ch_should_run('preflight')) {
    ch_out("\n--- 1. PREFLIGHT ---");

    ch_test('PHP extensions (pdo_mysql + json)', function () {
        foreach (['pdo_mysql', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("missing PHP extension: {$ext}");
            }
        }
        return null;
    }, 5);

    ch_test('Autoloader loaded (\\Webmail\\Core\\Database resolvable)', function () {
        if (!class_exists('\\Webmail\\Core\\Database')) {
            throw new \RuntimeException('\\Webmail\\Core\\Database not found -- composer autoload broken');
        }
        return null;
    }, 5);

    ch_test('.env file present at backend/.env', function () {
        $envFile = realpath(__DIR__ . '/../.env');
        if ($envFile === false || !is_file($envFile)) {
            return ['status' => 'WARN', 'msg' => 'backend/.env not found -- crons will rely on real env vars'];
        }
        if (!is_readable($envFile)) {
            throw new \RuntimeException("not readable: {$envFile}");
        }
        return null;
    }, 5);

    ch_test('storage/logs writable', function () use ($logDir) {
        if (!is_writable($logDir)) {
            throw new \RuntimeException("not writable: {$logDir}");
        }
        return null;
    }, 5);

    ch_test('cron/bootstrap.php present', function () use ($cronDir) {
        $bs = $cronDir . DIRECTORY_SEPARATOR . 'bootstrap.php';
        if (!is_file($bs)) {
            throw new \RuntimeException("missing: {$bs}");
        }
        return null;
    }, 5);
}

// =================================================================
// 2. CONFIG PATH RESOLUTION
// =================================================================
if (ch_should_run('config')) {
    ch_out("\n--- 2. CONFIG ---");

    $cronFiles = glob($cronDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($cronFiles);
    if (empty($cronFiles)) {
        ch_test('cron scripts present', function () {
            throw new \RuntimeException('no cron scripts found under email/backend/cron/');
        }, 1);
    }

    foreach ($cronFiles as $cronFile) {
        $base = basename($cronFile);
        if ($base === 'bootstrap.php') {
            continue;
        }
        ch_test("config path resolves: {$base}", function () use ($cronFile, $verbose) {
            $paths = ch_extract_config_paths($cronFile);
            if (empty($paths)) {
                return ['status' => 'WARN', 'msg' => 'no static config.php require found (cron may build path dynamically)'];
            }
            foreach ($paths as $p) {
                if (!is_file($p)) {
                    throw new \RuntimeException("require target does not exist: {$p}");
                }
            }
            if ($verbose) {
                ch_out('          paths: ' . implode(', ', $paths));
            }
            return null;
        }, 5);
    }
}

if ($smoke) {
    goto done;
}

// =================================================================
// 3. BOOTSTRAP (env loading) for DB-touching crons
// =================================================================
if (ch_should_run('bootstrap')) {
    ch_out("\n--- 3. BOOTSTRAP ---");

    foreach ($cronsNeedingDb as $name) {
        $path = $cronDir . DIRECTORY_SEPARATOR . $name;
        ch_test("env-loaded before DB use: {$name}", function () use ($path) {
            if (!is_file($path)) {
                return ['status' => 'WARN', 'msg' => "{$path} not present on this checkout"];
            }
            if (!ch_loads_env($path)) {
                throw new \RuntimeException(
                    "cron does not require cron/bootstrap.php and does not parse .env -- "
                    . 'getenv(DB_PASS) will be empty when run via crontab'
                );
            }
            return null;
        }, 5);
    }
}

// =================================================================
// 4. DB CONNECTION (via the same path a cron would take)
// =================================================================
if (ch_should_run('db')) {
    ch_out("\n--- 4. DB ---");

    ch_test('config[db][pass] non-empty after bootstrap', function () use ($config) {
        $pass = $config['db']['pass'] ?? '';
        if ($pass === '') {
            throw new \RuntimeException(
                'config[db][pass] is empty -- .env DB_PASS not loaded, or env var unset'
            );
        }
        return null;
    }, 5);

    ch_test('Database::getConnection + SELECT 1', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $row = $db->query('SELECT 1 AS ok')->fetch();
        if (!$row || (int) $row['ok'] !== 1) {
            throw new \RuntimeException('SELECT 1 failed');
        }
        return null;
    }, 10);

    ch_test('current MySQL user matches config[db][user]', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $row = $db->query('SELECT CURRENT_USER() AS u')->fetch();
        $current = (string) ($row['u'] ?? '');
        $expected = (string) ($config['db']['user'] ?? '');
        if ($expected === '') {
            return ['status' => 'WARN', 'msg' => 'config[db][user] empty -- cannot compare'];
        }
        if (strpos($current, $expected) !== 0) {
            throw new \RuntimeException(
                "CURRENT_USER()='{$current}' does not start with config[db][user]='{$expected}'"
            );
        }
        return null;
    }, 5);
}

// =================================================================
// 5. CORE TABLES the crons touch
// =================================================================
if (ch_should_run('tables')) {
    ch_out("\n--- 5. TABLES ---");

    $requiredTables = [
        'automation_hub_workflows',
        'automation_hub_nodes',
        'automation_hub_executions',
        'automation_hub_delayed_executions',
        'news_reader_feeds',
        'news_reader_items',
        'news_reader_subscriptions',
    ];
    $optionalTables = [
        'crm_automation_rules',
        'boardpro_automation_rules',
        'scope_radar_runs',
    ];

    foreach ($requiredTables as $table) {
        ch_test("table exists: {$table}", function () use ($config, $table) {
            $db = \Webmail\Core\Database::getConnection($config);
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            $row = $stmt->fetch();
            if (!$row || (int) $row['c'] === 0) {
                throw new \RuntimeException("{$table} is missing");
            }
            return null;
        }, 5);
    }

    foreach ($optionalTables as $table) {
        ch_test("table exists (optional): {$table}", function () use ($config, $table) {
            $db = \Webmail\Core\Database::getConnection($config);
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            $row = $stmt->fetch();
            if (!$row || (int) $row['c'] === 0) {
                return ['status' => 'WARN', 'msg' => "{$table} missing -- migration may not have been applied"];
            }
            return null;
        }, 5);
    }

    ch_test('news_reader_feeds.uniq_canonical_hash UNIQUE index present', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            "SELECT NON_UNIQUE FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_reader_feeds'
                AND INDEX_NAME = 'uniq_canonical_hash' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('uniq_canonical_hash index missing on news_reader_feeds');
        }
        if ((int) $row['NON_UNIQUE'] !== 0) {
            throw new \RuntimeException('uniq_canonical_hash exists but is not UNIQUE');
        }
        return null;
    }, 5);
}

done:

// ===== SUMMARY =====
ch_out("\n=================================================================");
ch_out(sprintf(
    '  Summary: %d total | PASS=%d  WARN=%d  FAIL=%d',
    $totalTests, $passed, $warnings, $failed
));
if ($failed > 0) {
    ch_out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            ch_out('   - ' . $r['name'] . ': ' . ($r['error'] ?? ''));
        }
    }
}
ch_out("=================================================================\n");

if ($jsonOut) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'warnings' => $warnings,
        'failed' => $failed,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
