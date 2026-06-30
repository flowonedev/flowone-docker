#!/usr/bin/env php
<?php
/**
 * SFTP Sessions :: journal -> sftp_sessions ingestion (parser + store)
 *
 * Verifies the per-session activity tracking for the additional restricted
 * SFTP users:
 *   - parser   : SftpSessionParser turns each sshd / internal-sftp log line
 *                into the correct typed event (login / logout / xfer / null).
 *   - apply    : SftpSessionIngestor::apply() correlates a full session
 *                lifecycle (login -> transfers -> logout) by PID against an
 *                in-memory store double, summing bytes/files and closing the
 *                session with the right duration. Also covers the skip paths
 *                (unknown user, orphan transfer/logout). No DB or journald.
 *   - sshd     : the managed Match block requests `internal-sftp -l INFO`,
 *                without which transfer bytes are never logged.
 *   - roundtrip: SftpSessionStore against the real panel DB using only
 *                `flowone_test_`-prefixed rows (exercises the MySQL SQL:
 *                FROM_UNIXTIME, counter increments, duration). Skipped under
 *                --skip-send or when the panel DB is unavailable (e.g. local).
 *
 * All DB rows created use a `flowone_test_` session_key prefix and are
 * removed on success, failure, or signal.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/sftp-sessions-test.php --verbose
 *
 * Flags:
 *   --help       -- this header
 *   --verbose    -- include stack traces on failures
 *   --skip-send  -- skip the DB roundtrip (parser/apply only)
 *   --smoke      -- run pre-flight only
 *   --only=g     -- run only group g (preflight always runs)
 *   --json       -- machine-readable summary
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Sftp\SftpSessionIngestor;
use VpsAdmin\Agent\Sftp\SftpSessionParser;
use VpsAdmin\Agent\Sftp\SftpSessionStore;
use VpsAdmin\Agent\Sftp\SshdSftpConfigurator;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SFTP Sessions', $opts);
$skipSend = isset($opts['skip-send']);

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) { throw new \RuntimeException($msg); }
};

// In-memory store double: models sftp_sessions rows without a DB so the
// ingestor's PID-correlation logic can be tested deterministically.
$fakeStoreClass = new class () extends SftpSessionStore {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    /** @var array<int,int> userId => login count */
    public array $aggregates = [];
    private int $auto = 0;

    public function __construct() {} // no PDO

    public function openSession(string $sessionKey, string $username, ?int $userId, ?string $domain, ?string $clientIp, int $pid, float $loginTs): void
    {
        foreach ($this->rows as $r) {
            if ($r['session_key'] === $sessionKey) { return; } // idempotent upsert
        }
        $id = ++$this->auto;
        $this->rows[$id] = [
            'id' => $id, 'session_key' => $sessionKey, 'username' => $username,
            'pid' => $pid, 'ip' => $clientIp, 'login' => (int) $loginTs, 'status' => 'open',
            'up' => 0, 'down' => 0, 'fu' => 0, 'fd' => 0, 'logout' => null, 'duration' => null,
        ];
    }

    public function findOpenIdByPid(int $pid): ?int
    {
        $found = null;
        foreach ($this->rows as $r) {
            if ($r['pid'] === $pid && $r['status'] === 'open') { $found = $r['id']; }
        }
        return $found;
    }

    public function addBytes(int $id, int $bytesRead, int $bytesWritten): void
    {
        $this->rows[$id]['down'] += $bytesRead;
        $this->rows[$id]['up'] += $bytesWritten;
        if ($bytesRead > 0) { $this->rows[$id]['fd']++; }
        if ($bytesWritten > 0) { $this->rows[$id]['fu']++; }
    }

    public function closeSession(int $id, float $logoutTs): void
    {
        if (($this->rows[$id]['status'] ?? '') !== 'open') { return; }
        $this->rows[$id]['status'] = 'closed';
        $this->rows[$id]['logout'] = (int) $logoutTs;
        $this->rows[$id]['duration'] = max(0, (int) $logoutTs - (int) $this->rows[$id]['login']);
    }

    public function touchAggregate(int $userId, ?string $clientIp, float $loginTs): void
    {
        $this->aggregates[$userId] = ($this->aggregates[$userId] ?? 0) + 1;
    }
};

// ─── preflight ──────────────────────────────────────────────────

$harness->test('preflight', 'CLI + PHP 8.x', function () {
    return ['outcome' => version_compare(PHP_VERSION, '8.0', '>=') ? TestHarness::PASS : TestHarness::FAIL,
            'message' => PHP_VERSION];
});

$harness->test('preflight', 'Session classes autoload', function () use ($assert) {
    $assert(class_exists(SftpSessionParser::class), 'parser missing');
    $assert(class_exists(SftpSessionStore::class), 'store missing');
    $assert(class_exists(SftpSessionIngestor::class), 'ingestor missing');
    return [];
});

// ─── parser (pure) ──────────────────────────────────────────────

$harness->test('parser', 'Login line -> login event', function () use ($assert) {
    $p = new SftpSessionParser();
    $e = $p->classify('Accepted password for sftp_440_abc from 203.0.113.7 port 51514 ssh2', 4242, 1000.0);
    $assert($e !== null && $e['type'] === 'login', 'not classified as login');
    $assert($e['user'] === 'sftp_440_abc', 'wrong user');
    $assert($e['ip'] === '203.0.113.7', 'wrong ip');
    $assert($e['pid'] === 4242, 'wrong pid');
    // publickey + keyboard-interactive variants also match.
    $assert($p->classify('Accepted publickey for u1 from 10.0.0.1 port 2 ssh2', 1, 1.0) !== null, 'publickey not matched');
    $assert($p->classify('Accepted keyboard-interactive/pam for u1 from 10.0.0.1 port 2 ssh2', 1, 1.0) !== null, 'kbd-int not matched');
    return [];
});

$harness->test('parser', 'Logout lines (both spellings) -> logout', function () use ($assert) {
    $p = new SftpSessionParser();
    $a = $p->classify('Disconnected from user sftp_440_abc 203.0.113.7 port 51514', 4242, 1100.0);
    $assert($a !== null && $a['type'] === 'logout' && $a['user'] === 'sftp_440_abc', 'Disconnected not matched');
    $b = $p->classify('pam_unix(sshd:session): session closed for user sftp_440_abc', 4242, 1100.0);
    $assert($b !== null && $b['type'] === 'logout' && $b['user'] === 'sftp_440_abc', 'session closed not matched');
    return [];
});

$harness->test('parser', 'Transfer close line -> xfer w/ bytes', function () use ($assert) {
    $p = new SftpSessionParser();
    $e = $p->classify('close "/uploads/big.zip" bytes read 0 written 10485760', 4242, 1050.0);
    $assert($e !== null && $e['type'] === 'xfer', 'not classified as xfer');
    $assert($e['read'] === 0 && $e['written'] === 10485760, 'bytes parsed wrong');
    $e2 = $p->classify('close "/d/report.pdf" bytes read 2048 written 0', 4242, 1051.0);
    $assert($e2['read'] === 2048 && $e2['written'] === 0, 'download bytes parsed wrong');
    return [];
});

$harness->test('parser', 'Irrelevant lines -> null', function () use ($assert) {
    $p = new SftpSessionParser();
    $assert($p->classify('Server listening on 0.0.0.0 port 22.', 1, 1.0) === null, 'listening line not ignored');
    $assert($p->classify('Failed password for invalid user bob from 1.2.3.4 port 9', 1, 1.0) === null, 'failed login not ignored');
    $assert($p->classify('', 1, 1.0) === null, 'empty not ignored');
    $assert($p->classify('Accepted password for u from 1.2.3.4 port 1 ssh2', 0, 1.0) === null, 'pid 0 not ignored');
    return [];
});

$harness->test('parser', 'sessionKey binds user+pid+login-second', function () use ($assert) {
    $assert(SftpSessionParser::sessionKey('u1', 99, 1000.9) === 'u1:99:1000', 'unexpected key');
    return [];
});

// ─── apply (correlation via in-memory store) ────────────────────

$harness->test('apply', 'Full lifecycle: login -> 2 xfers -> logout', function () use ($assert, $fakeStoreClass) {
    $store = clone $fakeStoreClass;
    $ing = new SftpSessionIngestor($store, new SftpSessionParser());
    $known = ['sftp_440_abc' => ['id' => 7, 'domain' => 'example.com']];

    $entries = [
        ['ts' => 2000.0, 'pid' => 555, 'message' => 'Accepted password for sftp_440_abc from 198.51.100.9 port 40000 ssh2'],
        ['ts' => 2003.0, 'pid' => 555, 'message' => 'close "/up/a.bin" bytes read 0 written 1000'],
        ['ts' => 2005.0, 'pid' => 555, 'message' => 'close "/down/b.txt" bytes read 250 written 0'],
        ['ts' => 2030.0, 'pid' => 555, 'message' => 'Disconnected from user sftp_440_abc 198.51.100.9 port 40000'],
    ];
    $stats = $ing->apply($entries, $known);

    $assert($stats['logins'] === 1, 'login not counted');
    $assert($stats['transfers'] === 2, 'transfers not counted');
    $assert($stats['logouts'] === 1, 'logout not counted');
    $assert(count($store->rows) === 1, 'expected exactly one session row');

    $row = array_values($store->rows)[0];
    $assert($row['session_key'] === 'sftp_440_abc:555:2000', 'wrong session key');
    $assert($row['up'] === 1000 && $row['down'] === 250, 'byte totals wrong: ' . json_encode($row));
    $assert($row['fu'] === 1 && $row['fd'] === 1, 'file counts wrong');
    $assert($row['status'] === 'closed', 'session not closed');
    $assert($row['duration'] === 30, 'duration wrong: ' . var_export($row['duration'], true));
    $assert(($store->aggregates[7] ?? 0) === 1, 'aggregate login count not bumped');
    return [];
});

$harness->test('apply', 'Idempotent re-apply of same batch', function () use ($assert, $fakeStoreClass) {
    $store = clone $fakeStoreClass;
    $ing = new SftpSessionIngestor($store, new SftpSessionParser());
    $known = ['u1' => ['id' => 1, 'domain' => null]];
    $entries = [
        ['ts' => 10.0, 'pid' => 1, 'message' => 'Accepted password for u1 from 10.0.0.2 port 5 ssh2'],
        ['ts' => 12.0, 'pid' => 1, 'message' => 'close "/x" bytes read 0 written 500'],
    ];
    $ing->apply($entries, $known);
    $ing->apply($entries, $known); // same window again

    $assert(count($store->rows) === 1, 'duplicate session row on re-apply');
    $row = array_values($store->rows)[0];
    // openSession is idempotent; addBytes is additive (real ingest never
    // re-reads a window thanks to the cursor, so double-count here is OK to
    // document: the row is unique, bytes reflect each apply).
    $assert($row['up'] === 1000, 'expected additive bytes across two applies: ' . $row['up']);
    return [];
});

$harness->test('apply', 'Untracked user is skipped', function () use ($assert, $fakeStoreClass) {
    $store = clone $fakeStoreClass;
    $ing = new SftpSessionIngestor($store, new SftpSessionParser());
    $stats = $ing->apply([
        ['ts' => 1.0, 'pid' => 9, 'message' => 'Accepted password for root from 10.0.0.1 port 5 ssh2'],
    ], ['u1' => ['id' => 1, 'domain' => null]]);
    $assert($stats['logins'] === 0 && $stats['skipped'] === 1, 'untracked login not skipped');
    $assert(count($store->rows) === 0, 'row created for untracked user');
    return [];
});

$harness->test('apply', 'Orphan transfer/logout (no open session) skipped', function () use ($assert, $fakeStoreClass) {
    $store = clone $fakeStoreClass;
    $ing = new SftpSessionIngestor($store, new SftpSessionParser());
    $stats = $ing->apply([
        ['ts' => 5.0, 'pid' => 77, 'message' => 'close "/x" bytes read 10 written 0'],
        ['ts' => 6.0, 'pid' => 77, 'message' => 'Disconnected from user u1 10.0.0.1 port 5'],
    ], ['u1' => ['id' => 1, 'domain' => null]]);
    $assert($stats['transfers'] === 0 && $stats['logouts'] === 0, 'orphan events were applied');
    $assert($stats['skipped'] === 2, 'orphan events not skipped');
    return [];
});

$harness->test('apply', 'Out-of-order entries sorted by ts', function () use ($assert, $fakeStoreClass) {
    $store = clone $fakeStoreClass;
    $ing = new SftpSessionIngestor($store, new SftpSessionParser());
    $known = ['u1' => ['id' => 1, 'domain' => null]];
    // logout listed before login; apply() must sort so the login opens first.
    $ing->apply([
        ['ts' => 120.0, 'pid' => 3, 'message' => 'Disconnected from user u1 10.0.0.1 port 5'],
        ['ts' => 100.0, 'pid' => 3, 'message' => 'Accepted password for u1 from 10.0.0.1 port 5 ssh2'],
    ], $known);
    $row = array_values($store->rows)[0] ?? null;
    $assert($row !== null && $row['status'] === 'closed' && $row['duration'] === 20, 'reorder/duration failed');
    return [];
});

// ─── sshd config contract ───────────────────────────────────────

$harness->test('sshd', 'Match block enables internal-sftp -l INFO', function () use ($assert) {
    $cfg = (new SshdSftpConfigurator())->desiredConfig();
    $assert(str_contains($cfg, 'ForceCommand internal-sftp'), 'ForceCommand missing');
    $assert(str_contains($cfg, '-l INFO'), 'transfer-byte logging (-l INFO) not enabled');
    return [];
});

// ─── roundtrip (real panel DB; flowone_test_ rows only) ─────────

$panelDb = static function (): ?PDO {
    foreach ([
        ['/var/www/vps-admin/api/config.php', '/var/www/vps-admin/api/config.local.php'],
        [__DIR__ . '/../api/config.php', __DIR__ . '/../api/config.local.php'],
    ] as [$main, $local]) {
        if (!file_exists($main)) { continue; }
        $config = require $main;
        if (file_exists($local)) { $config = array_replace_recursive($config, require $local); }
        $db = $config['database'] ?? [];
        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['port'] ?? 3306, $db['name'] ?? 'devc_vps_dash'),
                $db['user'] ?? '', $db['password'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable) {
            return null;
        }
    }
    return null;
};

$pdo = $skipSend ? null : $panelDb();
$testKey = 'flowone_test_sess_' . substr(bin2hex(random_bytes(4)), 0, 8);

$harness->onCleanup(function () use ($pdo) {
    if ($pdo instanceof PDO) {
        try { $pdo->exec("DELETE FROM sftp_sessions WHERE session_key LIKE 'flowone_test_%'"); } catch (\Throwable) {}
    }
});

$harness->test('roundtrip', 'Store lifecycle against MySQL (test rows only)', function () use ($assert, $pdo, $skipSend, $testKey) {
    if ($skipSend) { return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send']; }
    if (!$pdo instanceof PDO) { return ['outcome' => TestHarness::SKIP, 'message' => 'panel DB unavailable']; }

    $store = new SftpSessionStore($pdo);
    $store->ensureSchema();

    $login = time() - 60;
    $store->openSession($testKey, 'flowone_test_user', null, null, '203.0.113.250', 4000001, (float) $login);

    $id = (int) $pdo->query("SELECT id FROM sftp_sessions WHERE session_key = " . $pdo->quote($testKey))->fetchColumn();
    $assert($id > 0, 'open session row not inserted');

    $store->addBytes($id, 2048, 0);          // a download
    $store->addBytes($id, 0, 1048576);       // an upload
    $store->closeSession($id, (float) ($login + 45));

    $row = $pdo->query("SELECT * FROM sftp_sessions WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    $assert((int) $row['bytes_downloaded'] === 2048, 'downloaded wrong: ' . $row['bytes_downloaded']);
    $assert((int) $row['bytes_uploaded'] === 1048576, 'uploaded wrong: ' . $row['bytes_uploaded']);
    $assert((int) $row['files_downloaded'] === 1 && (int) $row['files_uploaded'] === 1, 'file counts wrong');
    $assert($row['status'] === 'closed', 'status not closed');
    $assert((int) $row['duration_seconds'] === 45, 'duration wrong: ' . $row['duration_seconds']);
    return ['message' => "id={$id}"];
});

$harness->test('roundtrip', 'Cursor get/set round-trip', function () use ($assert, $pdo, $skipSend) {
    if ($skipSend) { return ['outcome' => TestHarness::SKIP, 'message' => '--skip-send']; }
    if (!$pdo instanceof PDO) { return ['outcome' => TestHarness::SKIP, 'message' => 'panel DB unavailable']; }
    $store = new SftpSessionStore($pdo);
    $store->ensureSchema();
    $before = $store->getCursor();
    $store->setCursor('s=flowonetestcursor;i=1');
    $assert($store->getCursor() === 's=flowonetestcursor;i=1', 'cursor not persisted');
    // restore prior cursor so we never disturb the live ingestor.
    $store->setCursor($before ?? '');
    return [];
});

exit($harness->run());
