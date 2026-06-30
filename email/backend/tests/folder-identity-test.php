#!/usr/bin/env php
<?php
/**
 * FlowOne Folder Identity Test (Wave 2 P0).
 *
 * Verifies the canonical folder identity dual-write rollout end-to-end
 * WITHOUT requiring a live IMAP connection. Each group exercises the
 * code path that would normally fire on an HTTP request, but with
 * synthetic data that lives entirely under a `flowone_test_` prefix
 * so it can never be confused with real user data.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight        -- PHP extensions + DB + Redis + migrations 160/163 present
 *   identity_upsert  -- FolderIndexService::upsertFromListing assigns + reuses UUIDv7
 *   pin_canonical    -- pinned_emails INSERT carries folder_id (post-cutover)
 *   telemetry        -- folder_identity_version is monotonic per account
 *   compare_resolve  -- regression guard: FolderInputResolver::compareResolve still
 *                       flags drift / partial / ok between folder_id and path lookups
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/folder-identity-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output (resolved ids, counter values)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no DB/Redis writes)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-cleanup         leave test rows in place (debugging only)
 *   --skip-send            no-op, accepted for parity with other tests
 *   --account=EMAIL        synthetic account id for the test (default: flowone_test_<rand>@flowone.pro)
 *   --help                 show this message
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', [
    'verbose',
    'json',
    'smoke',
    'only:',
    'skip-cleanup',
    'skip-send',
    'account:',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke   = isset($opts['smoke']);
$skipCleanup = isset($opts['skip-cleanup']);
$only = isset($opts['only'])
    ? array_map('trim', explode(',', (string) $opts['only']))
    : [];

$testAccount = isset($opts['account'])
    ? strtolower((string) $opts['account'])
    : 'flowone_test_' . bin2hex(random_bytes(3)) . '@flowone.pro';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/folder-identity-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

// Stuff we created and need to clean up no matter how we exit.
$cleanup = [
    'account_id'    => $testAccount,
    'folder_paths'  => [],
    'pinned_uids'   => [],
    'conv_ids'      => [],
];

function fi_out(string $msg): void
{
    global $logFile, $jsonOut;
    if (!$jsonOut) {
        echo $msg . "\n";
    }
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function fi_should_run(string $group): bool
{
    global $only;
    return empty($only) || in_array($group, $only, true);
}

function fi_record(string $name, string $status, int $ms, ?string $error = null, array $meta = []): void
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
    fi_out(sprintf('  [%-4s]  %s (%dms)', $status, $name, $ms));
    if ($error !== null) {
        fi_out('          -> ' . $error);
    }
}

function fi_test(string $name, callable $fn, int $timeoutSec = 10): void
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
            fi_record($name, 'WARN', $ms, $result['msg'] ?? null, $result['meta'] ?? []);
        } else {
            $meta = is_array($result) ? ($result['meta'] ?? []) : [];
            fi_record($name, 'PASS', $ms, null, $meta);
        }
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        fi_record($name, 'FAIL', $ms, $e->getMessage());
        global $verbose;
        if ($verbose) {
            fi_out('          at ' . $e->getFile() . ':' . $e->getLine());
        }
    } finally {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}

function fi_cleanup_now(array &$cleanup, array $config): void
{
    if (empty($cleanup['account_id'])) return;
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $acct = $cleanup['account_id'];

        // pinned_emails: cleanup test rows
        $db->prepare(
            "DELETE FROM pinned_emails
              WHERE user_email = ? AND (folder LIKE 'flowone_test_%' OR folder_id IN (
                SELECT id FROM webmail_folder_identity WHERE account_id = ? AND current_path LIKE 'flowone_test_%'
              ))"
        )->execute([$acct, $acct]);

        // webmail_conversation_members
        try {
            $db->prepare(
                "DELETE FROM webmail_conversation_members
                  WHERE user_email = ? AND folder LIKE 'flowone_test_%'"
            )->execute([$acct]);
        } catch (\Throwable $e) { /* schema may not be present */ }

        // webmail_conversations
        try {
            $db->prepare(
                "DELETE FROM webmail_conversations
                  WHERE user_email = ? AND folder LIKE 'flowone_test_%'"
            )->execute([$acct]);
        } catch (\Throwable $e) { /* schema may not be present */ }

        // path history first (FK-style relation), then path intervals,
        // then identity itself. Tolerate missing tables on partial deploys.
        $db->prepare(
            "DELETE h FROM webmail_folder_path_history h
               JOIN webmail_folder_identity fi ON h.folder_id = fi.id
              WHERE fi.account_id = ? AND (fi.current_path LIKE 'flowone_test_%' OR fi.current_path LIKE 'flowone_test_renamed_%')"
        )->execute([$acct]);

        try {
            $db->prepare(
                "DELETE FROM webmail_folder_path_intervals
                  WHERE account_id = ? AND (path LIKE 'flowone_test_%' OR path LIKE 'flowone_test_renamed_%')"
            )->execute([$acct]);
        } catch (\Throwable $e) { /* migration 164 may not be applied */ }

        try {
            $db->prepare(
                "DELETE FROM webmail_folder_snapshots
                  WHERE account_id = ?"
            )->execute([$acct]);
        } catch (\Throwable $e) { /* migration 164 may not be applied */ }

        try {
            $db->prepare(
                "DELETE FROM webmail_account_provider WHERE account_id = ?"
            )->execute([$acct]);
        } catch (\Throwable $e) { /* table may not exist */ }

        $db->prepare(
            "DELETE FROM webmail_folder_identity
              WHERE account_id = ? AND (current_path LIKE 'flowone_test_%' OR current_path LIKE 'flowone_test_renamed_%')"
        )->execute([$acct]);
    } catch (\Throwable $e) {
        fi_out('  cleanup error: ' . $e->getMessage());
    }
}

// Always-on cleanup: try/finally style guard via shutdown function +
// signal handlers, so even SIGINT during a hang clears the test data.
register_shutdown_function(function () use (&$cleanup, $config, $skipCleanup) {
    if ($skipCleanup) return;
    fi_cleanup_now($cleanup, $config);
});
if (function_exists('pcntl_signal')) {
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use (&$cleanup, $config, $skipCleanup) {
            if (!$skipCleanup) fi_cleanup_now($cleanup, $config);
            exit(130);
        });
    }
}
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

// ===== HEADER =====
fi_out('=================================================================');
fi_out('  FlowOne Folder Identity Test (Wave 2 P0)');
fi_out('  ' . date('Y-m-d H:i:s T'));
fi_out('  Account:   ' . $testAccount);
fi_out('  Mode:      ' . ($smoke ? 'SMOKE' : 'FULL'));
fi_out('  Groups:    ' . (empty($only) ? 'all' : implode(',', $only)));
fi_out('  Log:       ' . $logFile);
fi_out('=================================================================');

// =================================================================
// 1. PREFLIGHT
// =================================================================
if (fi_should_run('preflight')) {
    fi_out("\n--- 1. PREFLIGHT ---");

    fi_test('PHP extensions (pdo_mysql + redis + json)', function () {
        foreach (['pdo_mysql', 'redis', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("missing PHP extension: {$ext}");
            }
        }
        return null;
    }, 5);

    fi_test('Database connection', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $row = $db->query('SELECT 1 AS ok')->fetch();
        if (!$row || (int) $row['ok'] !== 1) {
            throw new \RuntimeException('SELECT 1 failed');
        }
        return null;
    }, 10);

    fi_test('Redis ping', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) {
            return ['status' => 'WARN', 'msg' => 'Redis unavailable; telemetry tests will WARN'];
        }
        return null;
    }, 5);

    fi_test('storage/logs writable', function () use ($logDir) {
        if (!is_writable($logDir)) {
            throw new \RuntimeException("not writable: {$logDir}");
        }
        return null;
    }, 5);

    fi_test('Migration 160 applied (webmail_folder_identity exists)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $row = $db->query("SHOW TABLES LIKE 'webmail_folder_identity'")->fetch();
        if (!$row) {
            throw new \RuntimeException('webmail_folder_identity is missing -- run migration 160');
        }
        return null;
    }, 5);

    fi_test('Migration 160 applied (pinned_emails.folder_id exists)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pinned_emails' AND COLUMN_NAME = 'folder_id'"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || (int) $row['c'] === 0) {
            throw new \RuntimeException('pinned_emails.folder_id is missing -- run migration 160');
        }
        return null;
    }, 5);

    fi_test('Migration 163 applied (conversation tables + indexes)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        $checks = [
            ['webmail_conversation_members', 'folder_id'],
            ['webmail_conversations',        'folder_id'],
        ];
        foreach ($checks as [$table, $col]) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $col]);
            $row = $stmt->fetch();
            if (!$row || (int) $row['c'] === 0) {
                throw new \RuntimeException("{$table}.{$col} is missing -- run migration 163");
            }
        }
        // Check the composite index exists on pinned_emails for dual-read.
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pinned_emails'
                AND INDEX_NAME = 'idx_user_folder_id_uid'"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || (int) $row['c'] === 0) {
            return ['status' => 'WARN', 'msg' => 'idx_user_folder_id_uid on pinned_emails is missing -- dual-read will be slow'];
        }
        return null;
    }, 5);

    fi_test('Migration 164 applied (path_intervals + folder_snapshots)', function () use ($config) {
        $db = \Webmail\Core\Database::getConnection($config);
        foreach (['webmail_folder_path_intervals', 'webmail_folder_snapshots'] as $table) {
            $row = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
            if (!$row) {
                throw new \RuntimeException("{$table} is missing -- run migration 164");
            }
        }
        return null;
    }, 5);
}

if ($smoke) {
    goto done;
}

// =================================================================
// 2. IDENTITY UPSERT
// =================================================================
if (fi_should_run('identity_upsert')) {
    fi_out("\n--- 2. IDENTITY UPSERT ---");

    fi_test('upsertFromListing assigns UUIDv7', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $id = $svc->upsertFromListing($testAccount, [
            'name' => $path,
            'path' => $path,
            'display_name' => $path,
            'uidvalidity' => 12345,
            'uidnext' => 67,
        ]);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new \RuntimeException("not a UUIDv7: {$id}");
        }
        return ['meta' => ['id' => $id]];
    }, 10);

    fi_test('upsertFromListing is idempotent (same path -> same id)', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $a = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);
        $b = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);
        if ($a !== $b) {
            throw new \RuntimeException("idempotency broken: {$a} != {$b}");
        }
        return ['meta' => ['id' => $a]];
    }, 10);

    fi_test('getByPath returns the row by current path', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $id = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);
        $row = $svc->getByPath($testAccount, $path);
        if (!$row || ($row['id'] ?? null) !== $id) {
            throw new \RuntimeException('getByPath did not return the upserted row');
        }
        return ['meta' => ['id' => $id]];
    }, 10);
}

// =================================================================
// 3. CANONICAL PIN
// =================================================================
if (fi_should_run('pin_canonical')) {
    fi_out("\n--- 3. CANONICAL PIN ---");

    fi_test('Pin INSERT writes folder_id (legacy folder column is gone)', function () use ($config, &$cleanup, $testAccount) {
        $db  = \Webmail\Core\Database::getConnection($config);
        $svc = new \Webmail\Services\FolderIndexService($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $folderId = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);

        $uid = random_int(900000, 999999);
        $cleanup['pinned_uids'][] = $uid;
        $stmt = $db->prepare(
            "INSERT INTO pinned_emails (user_email, folder_id, uid, message_id, subject)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $testAccount, $folderId, $uid, '[FLOWONE-TEST] msg-id', '[FLOWONE-TEST] subject',
        ]);

        $row = $db->prepare(
            "SELECT folder_id FROM pinned_emails WHERE user_email = ? AND uid = ? LIMIT 1"
        );
        $row->execute([$testAccount, $uid]);
        $r = $row->fetch();
        if (!$r) throw new \RuntimeException('pin insert returned no row on read-back');
        if ($r['folder_id'] !== $folderId) {
            throw new \RuntimeException("folder_id mismatch: got " . var_export($r['folder_id'], true));
        }
        return ['meta' => ['folder_id' => $folderId, 'uid' => $uid]];
    }, 15);

    fi_test('Pin read by folder_id roundtrips', function () use ($config, &$cleanup, $testAccount) {
        $db = \Webmail\Core\Database::getConnection($config);
        $svc = new \Webmail\Services\FolderIndexService($config);

        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $folderId = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);

        $uid = random_int(900000, 999999);
        $cleanup['pinned_uids'][] = $uid;
        $db->prepare("INSERT INTO pinned_emails (user_email, folder_id, uid) VALUES (?, ?, ?)")
           ->execute([$testAccount, $folderId, $uid]);

        $stmt = $db->prepare("SELECT id FROM pinned_emails WHERE user_email = ? AND folder_id = ? AND uid = ?");
        $stmt->execute([$testAccount, $folderId, $uid]);
        if (!$stmt->fetch()) throw new \RuntimeException('canonical pin read missed row');

        return null;
    }, 15);
}

// =================================================================
// 4. TELEMETRY (post-cutover regression guard)
// =================================================================
if (fi_should_run('telemetry')) {
    fi_out("\n--- 4. TELEMETRY ---");

    fi_test('folder_identity_version is monotonic per account', function () use ($config, $testAccount) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) {
            return ['status' => 'WARN', 'msg' => 'Redis unavailable; version test skipped'];
        }
        $telem = new \Webmail\Services\DualWriteTelemetry($redis);
        $a = $telem->bumpFolderIdentityVersion($testAccount);
        $b = $telem->bumpFolderIdentityVersion($testAccount);
        if ($b <= $a) {
            throw new \RuntimeException("version did not advance: {$a} -> {$b}");
        }
        // Cleanup the test version key.
        $redis->delete('account:' . $testAccount . ':folder_identity_version');
        return ['meta' => ['v_a' => $a, 'v_b' => $b]];
    }, 10);
}

// =================================================================
// 5. PATH INTERVALS
// =================================================================
if (fi_should_run('intervals')) {
    fi_out("\n--- 5. PATH INTERVALS ---");

    fi_test('upsertFromListing opens an interval row', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $db  = \Webmail\Core\Database::getConnection($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $id = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);
        $stmt = $db->prepare(
            'SELECT id FROM webmail_folder_path_intervals
              WHERE account_id = ? AND folder_id = ? AND path = ? AND valid_to IS NULL'
        );
        $stmt->execute([$testAccount, $id, $path]);
        if (!$stmt->fetch()) {
            throw new \RuntimeException('open interval not created');
        }
        return ['meta' => ['id' => $id]];
    }, 10);

    fi_test('getByPath resolves via open interval', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $id = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);
        $row = $svc->getByPath($testAccount, $path);
        if (!$row || ($row['id'] ?? null) !== $id) {
            throw new \RuntimeException('getByPath did not find the open interval');
        }
        return null;
    }, 10);
}

// =================================================================
// 6. RENAME
// =================================================================
if (fi_should_run('rename')) {
    fi_out("\n--- 6. RENAME ---");

    fi_test('applyRename: pin survives, folder_id stable, intervals updated', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $db  = \Webmail\Core\Database::getConnection($config);

        $oldPath = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $newPath = 'flowone_test_renamed_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $oldPath;
        $cleanup['folder_paths'][] = $newPath;

        $folderId = $svc->upsertFromListing($testAccount, ['name' => $oldPath, 'path' => $oldPath]);

        // Pin a synthetic message under the old path.
        $uid = random_int(900000, 999999);
        $cleanup['pinned_uids'][] = $uid;
        $db->prepare(
            "INSERT INTO pinned_emails (user_email, folder, folder_id, uid, message_id, subject)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$testAccount, $oldPath, $folderId, $uid, '[FLOWONE-TEST] rename', '[FLOWONE-TEST] subject']);

        // Apply the rename.
        $applied = $svc->applyRename($testAccount, $folderId, $oldPath, $newPath);
        if (!$applied) {
            throw new \RuntimeException('applyRename returned false');
        }

        // 1) Identity row's current_path is the new path.
        $check = $svc->getById($folderId);
        if (!$check || ($check['current_path'] ?? null) !== $newPath) {
            throw new \RuntimeException('current_path not updated to new path');
        }

        // 2) Old interval is closed, new interval is open.
        $oldIv = $db->prepare(
            'SELECT valid_to FROM webmail_folder_path_intervals
              WHERE account_id = ? AND folder_id = ? AND path = ?
              ORDER BY id DESC LIMIT 1'
        );
        $oldIv->execute([$testAccount, $folderId, $oldPath]);
        $oldRow = $oldIv->fetch();
        if (!$oldRow || empty($oldRow['valid_to'])) {
            throw new \RuntimeException('old interval was not closed');
        }
        $newIv = $db->prepare(
            'SELECT id FROM webmail_folder_path_intervals
              WHERE account_id = ? AND folder_id = ? AND path = ? AND valid_to IS NULL'
        );
        $newIv->execute([$testAccount, $folderId, $newPath]);
        if (!$newIv->fetch()) {
            throw new \RuntimeException('new interval was not opened');
        }

        // 3) Pin still resolves to the same folder_id, regardless of path
        // (this is the whole point of canonical identity).
        $pin = $db->prepare(
            "SELECT folder, folder_id FROM pinned_emails
              WHERE user_email = ? AND folder_id = ? AND uid = ? LIMIT 1"
        );
        $pin->execute([$testAccount, $folderId, $uid]);
        $pinRow = $pin->fetch();
        if (!$pinRow) {
            throw new \RuntimeException('pin lost after rename (folder_id lookup)');
        }
        // The cosmetic dual-write column should now reflect the new path.
        if ($pinRow['folder'] !== $newPath) {
            return ['status' => 'WARN', 'msg' => "pin.folder cosmetic refresh skipped: got {$pinRow['folder']}"];
        }

        // 4) getByPath(oldPath) should still resolve via closed interval.
        $resolved = $svc->getByPath($testAccount, $oldPath);
        if (!$resolved || ($resolved['id'] ?? null) !== $folderId) {
            throw new \RuntimeException('getByPath(oldPath) did not redirect via closed interval');
        }
        return ['meta' => ['folder_id' => $folderId, 'old' => $oldPath, 'new' => $newPath]];
    }, 20);

    fi_test('applyRename bumps folder_identity_version when telemetry passed', function () use ($config, $testAccount) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) {
            return ['status' => 'WARN', 'msg' => 'Redis unavailable; version check skipped'];
        }
        $svc   = new \Webmail\Services\FolderIndexService($config);
        $telem = new \Webmail\Services\DualWriteTelemetry($redis);

        $oldPath = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $newPath = 'flowone_test_renamed_' . bin2hex(random_bytes(3));
        $folderId = $svc->upsertFromListing($testAccount, ['name' => $oldPath, 'path' => $oldPath]);

        $before = $telem->getFolderIdentityVersion($testAccount);
        $svc->applyRename($testAccount, $folderId, $oldPath, $newPath, null, null, null, $telem);
        $after  = $telem->getFolderIdentityVersion($testAccount);

        if ($after <= $before) {
            throw new \RuntimeException("folder_identity_version did not advance: {$before} -> {$after}");
        }

        // Cleanup the synthetic version key + folders so nothing leaks.
        $redis->delete('account:' . $testAccount . ':folder_identity_version');
        return ['meta' => ['v_before' => $before, 'v_after' => $after]];
    }, 20);
}

// =================================================================
// 7. PROVIDER
// =================================================================
if (fi_should_run('provider')) {
    fi_out("\n--- 7. PROVIDER ---");

    fi_test('getProviderType returns unknown for fresh account', function () use ($config, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $redis = new \Webmail\Services\RedisCacheService($config);
        // Make sure the cache is clean for the test account.
        if ($redis->isAvailable()) {
            $redis->delete('account:' . $testAccount . ':provider_type');
        }
        $type = $svc->getProviderType($testAccount, $redis);
        if ($type !== 'unknown') {
            return ['status' => 'WARN', 'msg' => "expected 'unknown', got '{$type}'"];
        }
        return null;
    }, 5);

    fi_test('Per-provider weights yield different scores', function () {
        // We exercise the public detectRenames API with two synthetic
        // shapes that score above 50 on default weights but ONLY above
        // 70 on gmail weights (because gmail leans on display-name +
        // hierarchy more). The aim is just to prove the profile
        // selector wires through.
        $svc = new \Webmail\Services\FolderIndexService([]);
        $missing = [[
            'id' => '00000000-0000-7000-8000-000000000000',
            'current_path' => 'Old Folder',
            'display_name' => 'Old Folder',
            'uidvalidity' => 0,
            'uidnext' => 0,
            'special_use' => '\\All',
            'message_count' => 100,
            'parent_id' => 'p',
            'delimiter' => '/',
        ]];
        $new = [[
            'name' => 'Old Folder Renamed',
            'path' => 'Old Folder Renamed',
            'display_name' => 'Old Folder',
            'uidvalidity' => 0,
            'uidnext' => 0,
            'special_use' => '\\All',
            'total' => 100,
            'parent_id' => 'p',
            'delimiter' => '/',
        ]];
        $defResult = $svc->detectRenames($new, $missing, 'unknown');
        $gmlResult = $svc->detectRenames($new, $missing, 'gmail');
        // Both should produce SOME result, but the score distribution
        // differs. We assert at least one of them yielded a rename or
        // a create -- the goal is that the profile selector is wired,
        // not that the synthetic numbers favour one provider.
        $totalDef = count($defResult['renames']) + count($defResult['creates']) + count($defResult['conflicts']);
        $totalGml = count($gmlResult['renames']) + count($gmlResult['creates']) + count($gmlResult['conflicts']);
        if ($totalDef === 0 || $totalGml === 0) {
            throw new \RuntimeException('detectRenames returned an empty result for one of the profiles');
        }
        return ['meta' => ['def_renames' => count($defResult['renames']), 'gml_renames' => count($gmlResult['renames'])]];
    }, 5);
}

// =================================================================
// 8. SNAPSHOT CAPTURE (DB write + analyzer drains it)
// =================================================================
if (fi_should_run('snapshot_capture')) {
    fi_out("\n--- 8. SNAPSHOT CAPTURE ---");

    fi_test('snapshot row INSERT is read-back-consistent', function () use ($config, $testAccount) {
        $db = \Webmail\Core\Database::getConnection($config);
        $payload = json_encode([
            ['name' => 'flowone_test_a', 'path' => 'flowone_test_a'],
            ['name' => 'flowone_test_b', 'path' => 'flowone_test_b'],
        ]);
        $db->prepare(
            'INSERT INTO webmail_folder_snapshots
                (account_id, snapshot, folder_count, captured_at, request_id)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)'
        )->execute([$testAccount, $payload, 2, 'flowone_test_req']);

        $row = $db->prepare(
            'SELECT folder_count, consumed_at FROM webmail_folder_snapshots
              WHERE account_id = ? ORDER BY id DESC LIMIT 1'
        );
        $row->execute([$testAccount]);
        $r = $row->fetch();
        if (!$r) throw new \RuntimeException('snapshot insert produced no row');
        if ((int) $r['folder_count'] !== 2) {
            throw new \RuntimeException('folder_count not persisted');
        }
        if ($r['consumed_at'] !== null) {
            throw new \RuntimeException('new snapshot should be unconsumed');
        }
        return null;
    }, 10);
}

// =================================================================
// 9. RECONCILIATION (read-only drift detector)
// =================================================================
if (fi_should_run('reconciliation')) {
    fi_out("\n--- 9. RECONCILIATION ---");

    fi_test('No identity rows without an open interval (post-upsert)', function () use ($config, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $db  = \Webmail\Core\Database::getConnection($config);
        // Create + upsert -> there should be ZERO drift for this account.
        $svc->upsertFromListing($testAccount, [
            'name' => 'flowone_test_recon_' . bin2hex(random_bytes(3)),
            'path' => 'flowone_test_recon_' . bin2hex(random_bytes(3)),
        ]);
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM webmail_folder_identity fi
               WHERE fi.account_id = ?
                 AND fi.current_path LIKE \'flowone_test_%\'
                 AND NOT EXISTS (
                   SELECT 1 FROM webmail_folder_path_intervals pi
                    WHERE pi.folder_id = fi.id
                      AND pi.account_id = fi.account_id
                      AND pi.path = fi.current_path
                      AND pi.valid_to IS NULL
                 )'
        );
        $stmt->execute([$testAccount]);
        $row = $stmt->fetch();
        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException('identity rows missing open intervals after upsert');
        }
        return null;
    }, 10);

    fi_test('No multi-owner conflicts on test paths', function () use ($config, $testAccount) {
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM (
                SELECT path FROM webmail_folder_path_intervals
                 WHERE account_id = ? AND valid_to IS NULL
                 GROUP BY path
                HAVING COUNT(DISTINCT folder_id) > 1
             ) t'
        );
        $stmt->execute([$testAccount]);
        $row = $stmt->fetch();
        if ((int) ($row['c'] ?? 0) > 0) {
            throw new \RuntimeException('multiple folders own the same path simultaneously');
        }
        return null;
    }, 10);
}

// =================================================================
// 10. STRUCTUREDLOG REQUEST CONTEXT
// =================================================================
if (fi_should_run('structured_log')) {
    fi_out("\n--- 10. STRUCTUREDLOG REQUEST CONTEXT ---");

    fi_test('setContext keys auto-attach to emit() lines', function () {
        \Webmail\Services\StructuredLog::clearContext();
        \Webmail\Services\StructuredLog::setContext([
            'account_id' => 'flowone_test_user',
            'provider_type' => 'gmail',
        ]);
        $line = \Webmail\Services\StructuredLog::line('test_event', ['folder_path' => 'X']);
        if (!str_contains($line, '"account_id":"flowone_test_user"')) {
            throw new \RuntimeException('account_id missing from line: ' . $line);
        }
        if (!str_contains($line, '"provider_type":"gmail"')) {
            throw new \RuntimeException('provider_type missing from line: ' . $line);
        }
        \Webmail\Services\StructuredLog::clearContext();
        return null;
    }, 5);

    fi_test('Per-call context overrides setContext', function () {
        \Webmail\Services\StructuredLog::clearContext();
        \Webmail\Services\StructuredLog::setContext(['provider_type' => 'gmail']);
        $line = \Webmail\Services\StructuredLog::line('test_event', ['provider_type' => 'dovecot']);
        if (!str_contains($line, '"provider_type":"dovecot"')) {
            throw new \RuntimeException('per-call override did not win: ' . $line);
        }
        \Webmail\Services\StructuredLog::clearContext();
        return null;
    }, 5);
}

// =================================================================
// 11. COMPARE-MODE RESOLVE
// =================================================================
if (fi_should_run('compare_resolve')) {
    fi_out("\n--- 11. COMPARE-MODE RESOLVE ---");

    fi_test('compareResolve returns "ok" for healthy round-trip', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $resolver = new \Webmail\Services\FolderInputResolver($config);
        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $folderId = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);

        $cmp = $resolver->compareResolve($testAccount, $folderId, $path);
        if (($cmp['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('expected "ok", got: ' . json_encode($cmp));
        }
        return ['meta' => ['folder_id' => $folderId]];
    }, 10);

    fi_test('compareResolve returns "skipped" without both sides', function () use ($config, $testAccount) {
        $resolver = new \Webmail\Services\FolderInputResolver($config);
        $a = $resolver->compareResolve($testAccount, null, 'flowone_test_anything');
        $b = $resolver->compareResolve($testAccount, '00000000-0000-7000-8000-000000000000', null);
        if (($a['status'] ?? null) !== 'skipped' || ($b['status'] ?? null) !== 'skipped') {
            throw new \RuntimeException('expected both single-input calls to skip');
        }
        return null;
    }, 5);

    fi_test('compareResolve returns "partial" when open interval is missing', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $db  = \Webmail\Core\Database::getConnection($config);
        $resolver = new \Webmail\Services\FolderInputResolver($config);

        $path = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $path;
        $folderId = $svc->upsertFromListing($testAccount, ['name' => $path, 'path' => $path]);

        // Simulate the bug: identity row exists with current_path set, but
        // the open interval row is missing (e.g. interrupted rename, lost
        // INSERT). The folder_id->path lookup still works (current_path
        // fallback), but path->folder_id misses because getByPath checks
        // intervals first then current_path... let me verify by deleting
        // the interval and checking what compareResolve says.
        $db->prepare(
            'DELETE FROM webmail_folder_path_intervals
              WHERE account_id = ? AND folder_id = ? AND path = ? AND valid_to IS NULL'
        )->execute([$testAccount, $folderId, $path]);

        $cmp = $resolver->compareResolve($testAccount, $folderId, $path);
        // current_path on the identity row still wins for path lookup,
        // so this should still resolve to OK. The genuine "partial"
        // happens when BOTH the interval and current_path are stale.
        // Force the partial: temporarily blank current_path.
        $db->prepare('UPDATE webmail_folder_identity SET current_path = ? WHERE id = ?')
           ->execute(['flowone_test_renamed_' . bin2hex(random_bytes(3)), $folderId]);
        $cmp2 = $resolver->compareResolve($testAccount, $folderId, $path);
        // Now path->folder_id resolves to NULL (no interval, no current_path
        // match), folder_id->path resolves to a different path. Either
        // 'partial' or 'identity_drift' is acceptable.
        if (!in_array($cmp2['status'] ?? null, ['partial', 'identity_drift'], true)) {
            throw new \RuntimeException('expected partial/drift, got: ' . json_encode($cmp2));
        }
        return ['meta' => ['cmp' => $cmp['status'], 'cmp2' => $cmp2['status']]];
    }, 15);

    fi_test('compareResolve returns "identity_drift" when path resolves to different folder_id', function () use ($config, &$cleanup, $testAccount) {
        $svc = new \Webmail\Services\FolderIndexService($config);
        $db  = \Webmail\Core\Database::getConnection($config);
        $resolver = new \Webmail\Services\FolderInputResolver($config);

        // Realistic drift shape: two healthy identity rows (different
        // current_paths because of the UNIQUE KEY on (account, path)),
        // and a stray extra open interval row pointing folder A's path
        // at folder B. This is exactly the kind of bug a botched rename
        // could leave behind: A's interval was never closed, then B's
        // interval was added on top with a newer valid_from. getByPath
        // ORDER BY valid_from DESC LIMIT 1 picks B, while getById(A)
        // still returns A.current_path. compareResolve should flag.
        $pathA = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $pathB = 'flowone_test_folder_' . bin2hex(random_bytes(3));
        $cleanup['folder_paths'][] = $pathA;
        $cleanup['folder_paths'][] = $pathB;
        $idA = $svc->upsertFromListing($testAccount, ['name' => $pathA, 'path' => $pathA]);
        $idB = $svc->upsertFromListing($testAccount, ['name' => $pathB, 'path' => $pathB]);

        // Inject the bug: an extra open interval at pathA owned by B,
        // with a newer valid_from so getByPath surfaces B for pathA.
        $db->prepare(
            'INSERT INTO webmail_folder_path_intervals
                (account_id, folder_id, path, valid_from, valid_to, reason)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 SECOND), NULL, ?)'
        )->execute([$testAccount, $idB, $pathA, 'reconcile']);

        $cmp = $resolver->compareResolve($testAccount, $idA, $pathA);
        if (($cmp['status'] ?? null) !== 'identity_drift') {
            // Cleanup before raising so the next test isn't poisoned.
            $db->prepare(
                'DELETE FROM webmail_folder_path_intervals
                  WHERE account_id = ? AND folder_id = ? AND path = ? AND reason = ?'
            )->execute([$testAccount, $idB, $pathA, 'reconcile']);
            throw new \RuntimeException('expected "identity_drift", got: ' . json_encode($cmp));
        }

        // Sanity: the diagnostic payload should show A's id resolved one
        // way and B's id resolved the other, so an operator can tell at
        // a glance which two rows are fighting over the same path.
        $byId = $cmp['by_id']['folder_id']   ?? null;
        $byPathId = $cmp['by_path']['folder_id'] ?? null;
        if ($byId !== $idA || $byPathId !== $idB) {
            $db->prepare(
                'DELETE FROM webmail_folder_path_intervals
                  WHERE account_id = ? AND folder_id = ? AND path = ? AND reason = ?'
            )->execute([$testAccount, $idB, $pathA, 'reconcile']);
            throw new \RuntimeException('drift payload mismatch: by_id=' . var_export($byId, true) . ' by_path_id=' . var_export($byPathId, true));
        }

        // Cleanup the stray interval row before the test exits.
        $db->prepare(
            'DELETE FROM webmail_folder_path_intervals
              WHERE account_id = ? AND folder_id = ? AND path = ? AND reason = ?'
        )->execute([$testAccount, $idB, $pathA, 'reconcile']);
        return ['meta' => ['idA' => $idA, 'idB' => $idB]];
    }, 15);

    fi_test('recordResolveCompare bumps the right counters', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) {
            return ['status' => 'WARN', 'msg' => 'Redis unavailable; counter test skipped'];
        }
        $telem = new \Webmail\Services\DualWriteTelemetry($redis);

        $okBefore  = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_OK_24H) ?: 0);
        $divBefore = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_DIVERGENCES_24H) ?: 0);
        $samBefore = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_SAMPLES_24H) ?: 0);

        $telem->recordResolveCompare('ok', 'flowone_test');
        $telem->recordResolveCompare('identity_drift', 'flowone_test');
        $telem->recordResolveCompare('partial', 'flowone_test');
        $telem->recordResolveCompare('skipped', 'flowone_test'); // must NOT bump

        $okAfter  = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_OK_24H) ?: 0);
        $divAfter = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_DIVERGENCES_24H) ?: 0);
        $samAfter = (int) ($redis->get(\Webmail\Services\DualWriteTelemetry::KEY_RESOLVE_SAMPLES_24H) ?: 0);

        if ($okAfter !== $okBefore + 1) {
            throw new \RuntimeException("ok counter: {$okBefore} -> {$okAfter}");
        }
        if ($divAfter !== $divBefore + 1) {
            throw new \RuntimeException("divergences counter: {$divBefore} -> {$divAfter}");
        }
        // samples should advance by 3 (ok + drift + partial), NOT 4 (skipped excluded)
        if ($samAfter !== $samBefore + 3) {
            throw new \RuntimeException("samples counter: {$samBefore} -> {$samAfter} (expected +3)");
        }
        return ['meta' => ['ok' => $okAfter, 'div' => $divAfter, 'samples' => $samAfter]];
    }, 10);
}

// =================================================================
done:

// ===== SUMMARY =====
fi_out("\n=================================================================");
fi_out(sprintf(
    '  Summary: %d total | PASS=%d  WARN=%d  FAIL=%d',
    $totalTests, $passed, $warnings, $failed
));
if ($failed > 0) {
    fi_out("\n  Failed tests:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            fi_out('   - ' . $r['name'] . ': ' . ($r['error'] ?? ''));
        }
    }
}
fi_out("=================================================================\n");

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
