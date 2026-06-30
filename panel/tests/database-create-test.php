#!/usr/bin/env php
<?php
/**
 * Database Create + Site-Link Test Suite
 *
 * Locks in the "Panel database fixes" work so it cannot regress:
 *
 *   - socket-default: MysqlAdminCredentials::normalize() now defaults the
 *                     admin socket to /var/run/mysqld/mysqld.sock for
 *                     localhost when none is configured (the common
 *                     /root/.my.cnf case). Without it the provisioner
 *                     connected via host=localhost and root auth failed
 *                     with SQLSTATE 1044/1045 -> DatabaseCreateStep failed
 *                     -> the whole site was parked 'degraded' with no DB.
 *   - db-roundtrip:   the resolved admin can actually CREATE/DROP a
 *                     database (the reported "creating a site doesn't
 *                     create a database" bug), and SiteRowBackfiller writes
 *                     a database_links row so the panel can associate the
 *                     provisioned flowone_<domain> schema with the site.
 *   - auth:           token verification must FAIL CLOSED. An RS256 token
 *                     cannot be verified with an empty HS256 secret, and
 *                     AuthService no longer silently falls back to one
 *                     (the random-401 -> logout root cause).
 *   - reset-heal:     db.resetPassword is self-healing - it CREATEs a
 *                     missing user, sets the password, and re-grants the
 *                     linked DB, so the operator can always recover login
 *                     even after a degraded provision (the "can't log in
 *                     to MySQL" report).
 *
 * Non-destructive: all DBs/users/rows use a flowone_test_ prefix or a
 * *.invalid domain and are removed on exit (even on SIGINT/SIGTERM).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/database-create-test.php --verbose
 *
 * Flags: --help --verbose --smoke --json --skip-send --only=group1,group2
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2100));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\DatabaseAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Agent\Provisioner\Support\SiteRowBackfiller;
use VpsAdmin\Tests\Lib\TestHarness;

const DEFAULT_SOCKET = '/var/run/mysqld/mysqld.sock';

$harness = new TestHarness('DatabaseCreate', $opts);

// ── Cleanup tracking ──────────────────────────────────────────────────
// Everything created during the run is registered here so the SIGINT/
// SIGTERM handler (and the normal finish) can reclaim it.
$probeDatabases = [];   // db names to DROP
$probeUsers = [];       // [user, host] to DROP
$probeSiteIds = [];     // sites.id rows to DELETE
$probeLinks = [];       // [domain, db_name] database_links rows to DELETE

/** Lazily build the same admin adapter the worker uses. */
$adminAdapter = static function (): MysqlAdapter {
    static $a = null;
    if ($a === null) {
        $a = new MysqlAdapter(new ProcessCommandRunner(), MysqlAdminCredentials::providerFromDefaultConfigFiles());
    }
    return $a;
};

/** Best-effort panel PDO, or null if unreachable (dev / not installed). */
$panelPdo = static function (): ?\PDO {
    try {
        return PanelDatabase::fromDefaultConfigFiles()->pdo();
    } catch (\Throwable) {
        return null;
    }
};

$harness->onCleanup(function () use (&$probeDatabases, &$probeUsers, &$probeSiteIds, &$probeLinks, $adminAdapter, $panelPdo): void {
    foreach ($probeDatabases as $db) {
        try {
            $adminAdapter()->dropDatabase($db);
        } catch (\Throwable) {
            // best-effort
        }
    }
    foreach ($probeUsers as [$user, $host]) {
        try {
            $adminAdapter()->dropUser($user, $host);
        } catch (\Throwable) {
            // best-effort
        }
    }
    $pdo = $panelPdo();
    if ($pdo !== null) {
        foreach ($probeLinks as [$domain, $dbName]) {
            try {
                $pdo->prepare("DELETE FROM database_links WHERE domain = ? AND db_name = ?")
                    ->execute([$domain, $dbName]);
            } catch (\Throwable) {
                // best-effort
            }
        }
        foreach ($probeSiteIds as $id) {
            try {
                $pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }
});

// ── preflight ─────────────────────────────────────────────────────────

$harness->test('preflight', 'php sapi is cli', function (): array {
    return php_sapi_name() === 'cli'
        ? ['outcome' => TestHarness::PASS]
        : ['outcome' => TestHarness::FAIL, 'message' => 'not cli'];
});

$harness->test('preflight', 'required extensions loaded (pdo_mysql, openssl, json)', function (): array {
    foreach (['pdo_mysql', 'openssl', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "missing extension: {$ext}"];
        }
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('preflight', 'core classes autoloadable', function (): array {
    foreach ([MysqlAdminCredentials::class, SiteRowBackfiller::class, MysqlAdapter::class, PanelDatabase::class] as $c) {
        if (!class_exists($c)) {
            return ['outcome' => TestHarness::FAIL, 'message' => "class not autoloadable: {$c}"];
        }
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('preflight', 'mysqld unix socket present', function (): array {
    return is_readable(DEFAULT_SOCKET)
        ? ['outcome' => TestHarness::PASS, 'message' => DEFAULT_SOCKET]
        : ['outcome' => TestHarness::WARN, 'message' => DEFAULT_SOCKET . ' not present (ok off-server)'];
});

$harness->test('preflight', 'panel DB reachable', function () use ($panelPdo): array {
    $pdo = $panelPdo();
    if ($pdo === null) {
        return ['outcome' => TestHarness::WARN, 'message' => 'panel DB unreachable (ok in dev)'];
    }
    $pdo->query('SELECT 1');
    return ['outcome' => TestHarness::PASS];
});

// ── socket-default: the MysqlAdminCredentials::normalize() fix ─────────

$harness->test('socket-default', 'explicit database_admin.socket is honored verbatim', function (): array {
    $cfg = ['database_admin' => ['user' => 'root', 'password' => 'x', 'socket' => '/custom/path/mysql.sock']];
    $creds = (MysqlAdminCredentials::provider($cfg))();
    if (($creds['socket'] ?? null) !== '/custom/path/mysql.sock') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'explicit socket lost: ' . json_encode($creds)];
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('socket-default', 'non-localhost host gets NO socket default (stays TCP)', function (): array {
    $cfg = ['database_admin' => ['user' => 'root', 'password' => 'x', 'host' => 'db.internal.example']];
    $creds = (MysqlAdminCredentials::provider($cfg))();
    if (($creds['socket'] ?? null) !== null) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'remote host must not get a unix socket: ' . json_encode($creds)];
    }
    if (($creds['host'] ?? null) !== 'db.internal.example') {
        return ['outcome' => TestHarness::FAIL, 'message' => 'host not preserved: ' . json_encode($creds)];
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('socket-default', 'localhost admin with no socket defaults to mysqld.sock when present', function (): array {
    $cfg = ['database_admin' => ['user' => 'root', 'password' => 'x', 'host' => 'localhost']];
    $creds = (MysqlAdminCredentials::provider($cfg))();
    if (!is_readable(DEFAULT_SOCKET)) {
        // Off-server: the default is gated on the socket actually existing,
        // so it correctly stays null here.
        if (($creds['socket'] ?? null) !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'socket should stay null when the file is absent'];
        }
        return ['outcome' => TestHarness::WARN, 'message' => 'socket file absent (ok off-server) - cannot assert default'];
    }
    if (($creds['socket'] ?? null) !== DEFAULT_SOCKET) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'expected default socket, got: ' . json_encode($creds)];
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('socket-default', '127.0.0.1 is treated as localhost for the socket default', function (): array {
    $cfg = ['database_admin' => ['user' => 'root', 'password' => 'x', 'host' => '127.0.0.1']];
    $creds = (MysqlAdminCredentials::provider($cfg))();
    if (!is_readable(DEFAULT_SOCKET)) {
        return ['outcome' => TestHarness::WARN, 'message' => 'socket file absent (ok off-server)'];
    }
    if (($creds['socket'] ?? null) !== DEFAULT_SOCKET) {
        return ['outcome' => TestHarness::FAIL, 'message' => '127.0.0.1 did not get the socket default: ' . json_encode($creds)];
    }
    return ['outcome' => TestHarness::PASS];
});

// ── db-roundtrip: live CREATE/DROP + database_links recording ─────────

$harness->test('db-roundtrip', 'resolved admin can CREATE then DROP a database', function () use ($opts, $adminAdapter, &$probeDatabases): array {
    if (isset($opts['skip-send'])) {
        return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send requested'];
    }
    if (!extension_loaded('pdo_mysql')) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'pdo_mysql not loaded (run on server)'];
    }
    $name = 'flowone_test_dbc_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $adapter = $adminAdapter();
    try {
        $adapter->createDatabase($name);
        $probeDatabases[] = $name;
    } catch (\Throwable $e) {
        $hint = MysqlAdminCredentials::privilegeHint($e);
        return ['outcome' => TestHarness::FAIL, 'message' => 'CREATE failed: ' . $e->getMessage() . ($hint ? ' | ' . $hint : '')];
    }
    if (!$adapter->databaseExists($name)) {
        return ['outcome' => TestHarness::FAIL, 'message' => "created DB {$name} not visible"];
    }
    $adapter->dropDatabase($name);
    $probeDatabases = array_values(array_diff($probeDatabases, [$name]));
    if ($adapter->databaseExists($name)) {
        return ['outcome' => TestHarness::FAIL, 'message' => "DB {$name} still present after drop"];
    }
    return ['outcome' => TestHarness::PASS, 'message' => "probe db: {$name}"];
});

$harness->test('db-roundtrip', 'provisioning backfill writes a database_links row for the site', function () use ($opts, $panelPdo, &$probeSiteIds, &$probeLinks): array {
    if (isset($opts['skip-send'])) {
        return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send requested'];
    }
    $pdo = $panelPdo();
    if ($pdo === null) {
        return ['outcome' => TestHarness::WARN, 'message' => 'panel DB unreachable (ok in dev)'];
    }

    $domain = 'flowone-test-link-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.invalid';
    $dbName = 'flowone_test_link_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $dbUser = 'fo_test_' . substr(bin2hex(random_bytes(3)), 0, 6);

    $pdo->prepare(
        "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
         VALUES (?, 'active', 'provisioning', NOW(), NOW())"
    )->execute([$domain]);
    $siteId = (int) $pdo->lastInsertId();
    $probeSiteIds[] = $siteId;
    $probeLinks[] = [$domain, $dbName];

    $states = [
        StepName::DATABASE_CREATE => StepState::fresh(StepName::DATABASE_CREATE)
            ->mergeData(['db_name' => $dbName]),
        StepName::DATABASE_USER_CREATE => StepState::fresh(StepName::DATABASE_USER_CREATE)
            ->mergeData(['user' => $dbUser]),
    ];

    $bf = new SiteRowBackfiller(PanelDatabase::fromDefaultConfigFiles());
    $bf->backfill($siteId, $states, ['domain' => $domain], $domain);

    $stmt = $pdo->prepare("SELECT db_name, db_user, domain FROM database_links WHERE domain = ? AND db_name = ?");
    $stmt->execute([$domain, $dbName]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return ['outcome' => TestHarness::FAIL, 'message' => "no database_links row written for {$domain} -> {$dbName}"];
    }
    if (($row['db_user'] ?? null) !== $dbUser) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'db_user not recorded on the link: ' . json_encode($row)];
    }
    return ['outcome' => TestHarness::PASS, 'message' => "{$domain} -> {$dbName}"];
});

$harness->test('db-roundtrip', 'backfill link upsert is idempotent (re-run does not duplicate)', function () use ($opts, $panelPdo, &$probeSiteIds, &$probeLinks): array {
    if (isset($opts['skip-send'])) {
        return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send requested'];
    }
    $pdo = $panelPdo();
    if ($pdo === null) {
        return ['outcome' => TestHarness::WARN, 'message' => 'panel DB unreachable (ok in dev)'];
    }

    $domain = 'flowone-test-idem-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.invalid';
    $dbName = 'flowone_test_idem_' . substr(bin2hex(random_bytes(3)), 0, 6);

    $pdo->prepare(
        "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
         VALUES (?, 'active', 'provisioning', NOW(), NOW())"
    )->execute([$domain]);
    $siteId = (int) $pdo->lastInsertId();
    $probeSiteIds[] = $siteId;
    $probeLinks[] = [$domain, $dbName];

    $states = [
        StepName::DATABASE_CREATE => StepState::fresh(StepName::DATABASE_CREATE)->mergeData(['db_name' => $dbName]),
        StepName::DATABASE_USER_CREATE => StepState::fresh(StepName::DATABASE_USER_CREATE)->mergeData(['user' => 'fo_test_idem']),
    ];
    $bf = new SiteRowBackfiller(PanelDatabase::fromDefaultConfigFiles());
    $bf->backfill($siteId, $states, ['domain' => $domain], $domain);
    $bf->backfill($siteId, $states, ['domain' => $domain], $domain); // second run

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM database_links WHERE domain = ? AND db_name = ?");
    $cnt->execute([$domain, $dbName]);
    $n = (int) $cnt->fetchColumn();
    if ($n !== 1) {
        return ['outcome' => TestHarness::FAIL, 'message' => "expected exactly 1 link row, got {$n}"];
    }
    return ['outcome' => TestHarness::PASS];
});

// ── auth: token verification must FAIL CLOSED ─────────────────────────

$harness->test('auth', 'an RS256 token cannot be verified with an empty HS256 secret', function (): array {
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($res === false) {
        return ['outcome' => TestHarness::WARN, 'message' => 'openssl could not generate a keypair here'];
    }
    openssl_pkey_export($res, $priv);
    $pub = openssl_pkey_get_details($res)['key'] ?? '';

    $b64u = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    $header = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = $b64u(json_encode(['sub' => 1, 'exp' => time() + 60]));
    $signingInput = $header . '.' . $payload;

    openssl_sign($signingInput, $sig, $priv, OPENSSL_ALGO_SHA256);

    // The real RS256 signature must verify with the public key...
    if (openssl_verify($signingInput, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'RS256 self-verification failed (test bug)'];
    }

    // ...but the signature an empty-secret HS256 verifier would compute is
    // different, so a silent HS256('') fallback can NEVER validate an
    // already-issued RS256 token. That is the random-401 -> logout bug.
    $rsSig = $b64u($sig);
    $hsSig = $b64u(hash_hmac('sha256', $signingInput, '', true));
    if (hash_equals($rsSig, $hsSig)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'RS256 sig collided with empty-HS256 sig (impossible - test bug)'];
    }
    return ['outcome' => TestHarness::PASS];
});

$harness->test('auth', 'AuthService resolves keys fail-closed (no empty-secret HS256 fallback)', function (): array {
    $candidates = [
        __DIR__ . '/../api/src/Services/AuthService.php',
        '/var/www/vps-admin/api/src/Services/AuthService.php',
    ];
    $path = null;
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $path = $c;
            break;
        }
    }
    if ($path === null) {
        return ['outcome' => TestHarness::WARN, 'message' => 'AuthService.php not found here'];
    }
    $src = (string) file_get_contents($path);

    // The old silent fallback returned the (possibly empty) secret
    // unconditionally. It must be gone.
    if (strpos($src, "return ['key' => \$this->container->getConfig('jwt.secret'), 'algorithm' => 'HS256'];") !== false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'old unconditional empty-secret HS256 fallback still present'];
    }
    // The hardened resolver + fail-closed throw must be present.
    if (strpos($src, 'function resolveKey(') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'resolveKey() not found - key hardening missing'];
    }
    if (!preg_match('/throw new \\\\?RuntimeException/', $src) || strpos($src, 'refusing empty-secret HS256 fallback') === false) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'fail-closed throw for empty secret not found'];
    }
    return ['outcome' => TestHarness::PASS];
});

// ── reset-heal: db.resetPassword is self-healing ──────────────────────
// The reported "can't log in to MySQL" case: a degraded provision left
// the user missing / without grants, so a plain ALTER USER reset would
// error. actionResetPassword now CREATE-IF-NOT-EXISTS + ALTER + (re)GRANT.

$dbActionSandbox = realpath(sys_get_temp_dir()) . '/flowone_test_dbaction_' . bin2hex(random_bytes(3));
@mkdir($dbActionSandbox, 0755, true);
$harness->onCleanup(static function () use ($dbActionSandbox): void {
    foreach (glob($dbActionSandbox . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dbActionSandbox);
});

/** Build the agent DatabaseAction (connects as root via the unix socket). */
$dbAction = static function () use ($dbActionSandbox): DatabaseAction {
    static $a = null;
    if ($a === null) {
        $cfg = [
            'paths' => ['backups' => $dbActionSandbox . '/backups'],
            'logging' => ['file' => $dbActionSandbox . '/agent.log', 'level' => 'error'],
        ];
        $a = new DatabaseAction($cfg, new BackupManager($cfg), new DiffGenerator(), new Logger($cfg));
    }
    return $a;
};

/** Invoke the protected actionResetPassword directly. */
$callReset = static function (DatabaseAction $action, array $params) {
    $ref = new ReflectionMethod(DatabaseAction::class, 'actionResetPassword');
    $ref->setAccessible(true);
    return $ref->invoke($action, $params, 'flowone_test');
};

/** Can $user log in (over the socket) with $pass? */
$canLogin = static function (string $user, string $pass): bool {
    try {
        $pdo = new \PDO('mysql:unix_socket=' . DEFAULT_SOCKET, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        return true;
    } catch (\Throwable) {
        return false;
    }
};

$resetGuard = static function () use ($opts): ?array {
    if (isset($opts['skip-send'])) {
        return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send requested'];
    }
    if (!extension_loaded('pdo_mysql') || !is_readable(DEFAULT_SOCKET)) {
        return ['outcome' => TestHarness::SKIP, 'message' => 'no pdo_mysql/socket (run on server)'];
    }
    return null;
};

$harness->test('reset-heal', 'reset CREATEs a missing user, sets password, and grants the DB', function () use ($resetGuard, $dbAction, $callReset, $canLogin, $adminAdapter, &$probeDatabases, &$probeUsers): array {
    if ($skip = $resetGuard()) {
        return $skip;
    }
    $user = 'flowone_test_rp_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $db = 'flowone_test_rpdb_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $pass = 'Pw_' . bin2hex(random_bytes(6));

    $adapter = $adminAdapter();
    // Target DB must exist for the GRANT; the user must NOT (that's the bug).
    $adapter->createDatabase($db);
    $probeDatabases[] = $db;
    if ($adapter->userExists($user, 'localhost')) {
        $adapter->dropUser($user, 'localhost');
    }
    $probeUsers[] = [$user, 'localhost'];

    $r = $callReset($dbAction(), ['user' => $user, 'password' => $pass, 'host' => 'localhost', 'database' => $db]);
    if (empty($r['success'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'reset failed: ' . json_encode($r)];
    }
    if (!$adapter->userExists($user, 'localhost')) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'user was not created by reset'];
    }
    if (($r['data']['granted_database'] ?? null) !== $db) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'grant not reported: ' . json_encode($r['data'] ?? null)];
    }
    if (!$canLogin($user, $pass)) {
        return ['outcome' => TestHarness::FAIL, 'message' => "user cannot log in with the reset password"];
    }
    return ['outcome' => TestHarness::PASS, 'message' => "{$user} -> {$db}"];
});

$harness->test('reset-heal', 'reset is idempotent for an existing user (rotates password)', function () use ($resetGuard, $dbAction, $callReset, $canLogin, $adminAdapter, &$probeUsers): array {
    if ($skip = $resetGuard()) {
        return $skip;
    }
    $user = 'flowone_test_rp2_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $pass1 = 'Pw1_' . bin2hex(random_bytes(6));
    $pass2 = 'Pw2_' . bin2hex(random_bytes(6));
    $probeUsers[] = [$user, 'localhost'];

    $action = $dbAction();
    $callReset($action, ['user' => $user, 'password' => $pass1, 'host' => 'localhost']);
    if (!$canLogin($user, $pass1)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'first reset did not enable login'];
    }
    $r = $callReset($action, ['user' => $user, 'password' => $pass2, 'host' => 'localhost']);
    if (empty($r['success'])) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'second reset failed: ' . json_encode($r)];
    }
    if ($canLogin($user, $pass1)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'old password still works after rotation'];
    }
    if (!$canLogin($user, $pass2)) {
        return ['outcome' => TestHarness::FAIL, 'message' => 'new password does not work after rotation'];
    }
    return ['outcome' => TestHarness::PASS];
});

exit($harness->run());
