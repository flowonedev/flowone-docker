#!/usr/bin/env php
<?php
/**
 * MysqlAdapter Test Suite
 *
 * Verifies CRUD against a real MariaDB using a sandboxed test database
 * and test user that are created + destroyed by each run. Production
 * data is never touched.
 *
 * All test artifacts use the `flowone_test_` prefix so an aborted run
 * leaves recognizable orphans that can be cleaned by hand.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/mysql-adapter-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'verbose', 'skip-send', 'only:', 'smoke', 'json', 'help',
    'admin-user:', 'admin-pass:', 'admin-host:', 'admin-port:', 'admin-socket:',
]);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('MysqlAdapter', $opts);

/**
 * Credentials selection (in priority order)
 * -----------------------------------------
 * 1. Explicit --admin-user / --admin-pass / --admin-socket flags. If
 *    ALL three of host/user/pass are provided we use them verbatim.
 *    This is the path CI runs on a pre-baked container.
 *
 * 2. MysqlAdminCredentials resolver. This is the same resolver the
 *    saga uses in production: it looks at `database_admin` in panel
 *    config.local.php, then `/root/.my.cnf`, then falls back to the
 *    panel user. By default the test follows the same lookup so a
 *    flag-free run on a properly-configured server "just works".
 *
 * If neither yields admin privileges, destructive tests SKIP rather
 * than FAIL - that's the correct outcome on a server where the
 * effective account really is narrowly scoped.
 *
 * Override examples:
 *   # use a specific root password explicitly
 *   --admin-user=root --admin-pass='<root-pass>'
 *   # use the unix socket (passwordless auth_socket plugin)
 *   --admin-user=root --admin-socket=/var/run/mysqld/mysqld.sock --admin-pass=''
 */
$flagsOverride = isset($opts['admin-user']) && isset($opts['admin-pass']);
if ($flagsOverride) {
    $panelCfg = PanelDatabase::fromDefaultConfigFiles()->config();
    $cfg = [
        'host' => (string) ($opts['admin-host'] ?? $panelCfg['host'] ?? '127.0.0.1'),
        'port' => (int) ($opts['admin-port'] ?? $panelCfg['port'] ?? 3306),
        'user' => (string) $opts['admin-user'],
        'password' => (string) $opts['admin-pass'],
        'socket' => isset($opts['admin-socket']) && $opts['admin-socket'] !== ''
            ? (string) $opts['admin-socket']
            : ($panelCfg['socket'] ?? null),
    ];
    $credentials = static fn(): array => $cfg;
    $credSource = "explicit flags (user={$cfg['user']})";
} else {
    // Resolver path - this also picks up /root/.my.cnf so the test
    // succeeds out of the box on the same server where the saga
    // succeeds.
    $credentials = MysqlAdminCredentials::providerFromDefaultConfigFiles();
    $resolved = ($credentials)();
    $cfg = $resolved;
    $credSource = "MysqlAdminCredentials resolver (user={$resolved['user']})";
}

$adapter = new MysqlAdapter(new ProcessCommandRunner(), \Closure::fromCallable($credentials));

/**
 * Probe whether the configured user has the privileges required by the
 * destructive tests. We try to create + drop a uniquely-named scratch
 * database. Anything other than SUCCESS means we should SKIP rather
 * than FAIL the dependent tests.
 */
function probeDestructivePrivileges(MysqlAdapter $adapter): array
{
    $probeDb = 'flowone_test_probe_' . substr(bin2hex(random_bytes(4)), 0, 8);
    try {
        $adapter->createDatabase($probeDb);
    } catch (\Throwable $e) {
        return ['allowed' => false, 'reason' => 'CREATE DATABASE denied: ' . $e->getMessage()];
    }
    try {
        $adapter->dropDatabase($probeDb);
    } catch (\Throwable $e) {
        // Already created but cant drop - that means cleanup is impossible
        // and we still must report the probe as failed.
        return ['allowed' => false, 'reason' => 'DROP DATABASE denied: ' . $e->getMessage()];
    }
    return ['allowed' => true, 'reason' => ''];
}

$privProbe = probeDestructivePrivileges($adapter);
$skipDestructive = !$privProbe['allowed'];
$skipReason = $skipDestructive
    ? "credentials from {$credSource} lack admin privileges ({$privProbe['reason']}). "
      . "Add a `database_admin` block to /var/www/vps-admin/api/config.local.php "
      . "OR put valid root creds in /root/.my.cnf, then re-run flag-free. "
      . "Or override explicitly: --admin-user=root --admin-pass='<root-pass>'."
    : '';

$testDb = 'flowone_test_' . substr(bin2hex(random_bytes(4)), 0, 8);
$testUser = 'fwt_' . substr(bin2hex(random_bytes(3)), 0, 6);
$testPass = 'tmp_' . bin2hex(random_bytes(8));
$testHost = '127.0.0.1';

$harness->onCleanup(function () use ($adapter, $testDb, $testUser, $testHost): void {
    try { $adapter->dropDatabase($testDb); } catch (\Throwable) {}
    try { $adapter->dropUser($testUser, $testHost); } catch (\Throwable) {}
    try { $adapter->dropUser($testUser, 'localhost'); } catch (\Throwable) {}
});

// ── preflight: report effective privileges up front ──────────
$harness->test('preflight', 'admin privileges available',
    function () use ($cfg, $privProbe, $skipReason) {
        if (!$privProbe['allowed']) {
            return [
                'outcome' => TestHarness::WARN,
                'message' => "destructive tests will SKIP. {$skipReason}",
            ];
        }
        return null; // PASS - admin user has CREATE DATABASE
    });

// ── safe-name validation ──────────────────────────────────────
$harness->test('safe_name', 'rejects DB names with semicolons',
    function () use ($adapter) {
        try {
            $adapter->databaseExists('foo;drop table x');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('safe_name', 'rejects DB names with backticks',
    function () use ($adapter) {
        try {
            $adapter->databaseExists('foo`bar`');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

// ── database CRUD ─────────────────────────────────────────────
$harness->test('db', 'create + exists + drop round-trip',
    function () use ($adapter, $testDb, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        $created = $adapter->createDatabase($testDb);
        if (!$created) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'created=false on fresh'];
        }
        if (!$adapter->databaseExists($testDb)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exists=false after create'];
        }
        // Idempotent: second create is a no-op
        $again = $adapter->createDatabase($testDb);
        if ($again) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'created=true on repeat'];
        }
        $dropped = $adapter->dropDatabase($testDb);
        if (!$dropped) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dropped=false'];
        }
        if ($adapter->databaseExists($testDb)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'still exists after drop'];
        }
        $dropAgain = $adapter->dropDatabase($testDb);
        if ($dropAgain) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dropped=true on missing'];
        }
    });

$harness->test('db', 'listDatabases includes test DB while present',
    function () use ($adapter, $testDb, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        $adapter->createDatabase($testDb);
        try {
            $names = $adapter->listDatabases();
            if (!in_array($testDb, $names, true)) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'test db missing from list'];
            }
        } finally {
            $adapter->dropDatabase($testDb);
        }
    });

// ── user CRUD ─────────────────────────────────────────────────
$harness->test('user', 'create + exists + drop round-trip',
    function () use ($adapter, $testUser, $testPass, $testHost, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        $created = $adapter->createUser($testUser, $testPass, $testHost);
        if (!$created) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'created=false on fresh'];
        }
        if (!$adapter->userExists($testUser, $testHost)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'exists=false after create'];
        }
        $again = $adapter->createUser($testUser, $testPass, $testHost);
        if ($again) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'created=true on repeat'];
        }
        $dropped = $adapter->dropUser($testUser, $testHost);
        if (!$dropped) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dropped=false'];
        }
        if ($adapter->userExists($testUser, $testHost)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'still exists after drop'];
        }
    });

$harness->test('user', 'setUserPassword changes auth',
    function () use ($adapter, $testUser, $testPass, $testHost, $cfg, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        $adapter->createUser($testUser, $testPass, $testHost);
        try {
            $newPass = 'tmp_' . bin2hex(random_bytes(8));
            $adapter->setUserPassword($testUser, $newPass, $testHost);
            // Use the IP literal (matching $testHost='127.0.0.1') instead
            // of $cfg['host']: the resolver typically returns 'localhost',
            // which PDO routes through the unix socket so the server
            // resolves the connection as '@localhost' - but we created
            // the test user at '@127.0.0.1'. Forcing the IP literal here
            // forces TCP and matches the grant.
            $dsn = sprintf(
                'mysql:host=127.0.0.1;port=%d;charset=utf8mb4',
                (int) ($cfg['port'] ?? 3306)
            );
            try {
                $pdo = new \PDO($dsn, $testUser, $newPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->query('SELECT 1')->fetchColumn();
            } catch (\PDOException $e) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'new password rejected: ' . $e->getMessage()];
            }
        } finally {
            $adapter->dropUser($testUser, $testHost);
        }
    });

// ── grants ────────────────────────────────────────────────────
$harness->test('grants', 'grantAll then revoke + drop',
    function () use ($adapter, $testDb, $testUser, $testPass, $testHost, $cfg, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        $adapter->createDatabase($testDb);
        $adapter->createUser($testUser, $testPass, $testHost);
        try {
            $adapter->grantAllOnDatabase($testDb, $testUser, $testHost);
            // Connect-back via IP literal forces TCP, matching the
            // user's @127.0.0.1 grant. See setUserPassword test for
            // the unix-socket vs TCP mismatch this works around.
            $dsn = sprintf(
                'mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4',
                (int) ($cfg['port'] ?? 3306),
                $testDb
            );
            try {
                $pdo = new \PDO($dsn, $testUser, $testPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            } catch (\PDOException $e) {
                // Capture grants snapshot to make the failure debuggable
                // (1044 here usually means GRANT was recorded against a
                // different host than we're connecting from, or hasn't
                // propagated yet).
                $insp = $adapter->grantInspection($testDb, $testUser, $testHost);
                $rawDump = implode("\n    | ", $insp['raw'] ?: ['<no rows>']);
                return ['outcome' => TestHarness::FAIL, 'message' => $e->getMessage()
                    . "\n  immediately-after-GRANT dump for {$testUser}@{$testHost}:\n    | "
                    . $rawDump
                    . "\n  inspection_error: " . ($insp['error'] ?? '<none>')];
            }
            $pdo->exec('CREATE TABLE flowone_test_t (id INT PRIMARY KEY)');
            $pdo->exec('DROP TABLE flowone_test_t');

            // Revoke
            $adapter->revokeAllOnDatabase($testDb, $testUser, $testHost);
            // Re-connect WITHOUT dbname in the DSN. With dbname,
            // MariaDB performs an implicit USE at connect time which
            // 1044s post-revoke - that's actually proof the revoke
            // worked, but it short-circuits the CREATE TABLE check
            // we want to perform (and means we'd never see exec()
            // throw, only the constructor). Connect bare, then USE
            // the db explicitly: USAGE survives revoke so that part
            // succeeds, and we can then assert CREATE TABLE fails
            // because the schema-level grant is gone.
            $bareDsn = sprintf(
                'mysql:host=127.0.0.1;port=%d;charset=utf8mb4',
                (int) ($cfg['port'] ?? 3306)
            );
            $pdo2 = new \PDO($bareDsn, $testUser, $testPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            try {
                $pdo2->exec(sprintf('USE `%s`', $testDb));
                $pdo2->exec('CREATE TABLE flowone_test_should_fail (id INT)');
                return ['outcome' => TestHarness::FAIL, 'message' => 'revoke did not prevent USE+CREATE'];
            } catch (\PDOException) {
                // expected: 1044 on USE or CREATE - either is proof
                // the revoke was effective.
            }
        } finally {
            try { $adapter->dropUser($testUser, $testHost); } catch (\Throwable) {}
            try { $adapter->dropDatabase($testDb); } catch (\Throwable) {}
        }
    });

$harness->test('grants', 'grantCustom rejects non-GRANT/REVOKE statements',
    function () use ($adapter) {
        try {
            $adapter->grantCustom('DROP DATABASE devc_vps_dash');
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected exception'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

// Regression: hasAllPrivilegesOn must see the grant even when the
// database name contains an underscore. MariaDB's SHOW GRANTS escapes
// `_` (and `%`) in identifiers because both are wildcards in the
// GRANT-pattern grammar; the verifier must un-escape before matching
// or DatabaseGrantStep.verify() fails for every site (Job #553-style
// regression: 'execute() succeeded but verify() FAILED').
$harness->test('grants', 'hasAllPrivilegesOn matches DB name with underscore',
    function () use ($adapter, $testDb, $testUser, $testPass, $testHost, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        // testDb already contains underscores from the `flowone_test_` prefix,
        // so this exercises the production-path scenario faithfully.
        $adapter->createDatabase($testDb);
        $adapter->createUser($testUser, $testPass, $testHost);
        try {
            $adapter->grantAllOnDatabase($testDb, $testUser, $testHost);
            $inspection = $adapter->grantInspection($testDb, $testUser, $testHost);
            if (!$inspection['has_all']) {
                $rawDump = implode("\n    | ", $inspection['raw'] ?: ['<no rows>']);
                $normDump = implode("\n    | ", $inspection['normalised'] ?: ['<no rows>']);
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "hasAllPrivilegesOn returned false right after "
                        . "grantAllOnDatabase succeeded.\n  testDb={$testDb} testUser={$testUser} testHost={$testHost}\n"
                        . "  show_grants_raw:\n    | {$rawDump}\n"
                        . "  show_grants_normalised:\n    | {$normDump}\n"
                        . "  inspection_error: " . ($inspection['error'] ?? '<none>'),
                ];
            }
            $adapter->revokeAllOnDatabase($testDb, $testUser, $testHost);
            $afterRevoke = $adapter->grantInspection($testDb, $testUser, $testHost);
            if ($afterRevoke['has_all']) {
                $rawDump = implode("\n    | ", $afterRevoke['raw'] ?: ['<no rows>']);
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "hasAllPrivilegesOn still returns true after revoke.\n"
                        . "  show_grants_raw:\n    | {$rawDump}",
                ];
            }
        } finally {
            try { $adapter->dropUser($testUser, $testHost); } catch (\Throwable) {}
            try { $adapter->dropDatabase($testDb); } catch (\Throwable) {}
        }
    });

// ── dumpDatabase ──────────────────────────────────────────────
$harness->test('dump', 'dumpDatabase writes a non-empty file',
    function () use ($adapter, $testDb, $cfg, $skipDestructive, $skipReason) {
        if ($skipDestructive) {
            return ['outcome' => TestHarness::SKIP, 'message' => $skipReason];
        }
        if (!is_executable('/usr/bin/mysqldump') && !is_executable('/usr/local/bin/mysqldump')) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'mysqldump not installed'];
        }
        $adapter->createDatabase($testDb);
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $cfg['host'] ?? '127.0.0.1', $testDb);
            $pdo = new \PDO($dsn, $cfg['user'] ?? '', $cfg['password'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE flowone_test_dump (id INT PRIMARY KEY, name VARCHAR(50))');
            $pdo->exec("INSERT INTO flowone_test_dump VALUES (1, 'flowone-test-row')");

            $outPath = sys_get_temp_dir() . '/flowone_test_dump_' . bin2hex(random_bytes(4)) . '.sql';
            try {
                $bytes = $adapter->dumpDatabase($testDb, $outPath);
                if ($bytes < 100) {
                    return ['outcome' => TestHarness::FAIL, 'message' => "dump too small: {$bytes}"];
                }
                $contents = file_get_contents($outPath);
                if (strpos($contents, 'flowone-test-row') === false) {
                    return ['outcome' => TestHarness::FAIL, 'message' => 'dump missing test row'];
                }
            } finally {
                @unlink($outPath);
            }
        } finally {
            $adapter->dropDatabase($testDb);
        }
    });

exit($harness->run());
