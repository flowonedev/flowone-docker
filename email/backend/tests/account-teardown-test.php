#!/usr/bin/env php
<?php
/**
 * account-teardown-test.php
 *
 * Verifies AccountTeardownService::purge() fully removes the residual state a
 * removed linked account leaves behind (folder sync queue, folder identity,
 * cached OAuth token) AND never touches an unrelated account's rows.
 *
 * Per .cursor/rules/server-side-testing.mdc - CLI only, idempotent, all data
 * prefixed `flowone_test_` so it can never collide with production rows, and
 * cleanup runs on every exit path (including SIGINT/SIGTERM).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/account-teardown-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Services\AccountTeardownService;
use Webmail\Services\MailboxSyncService;
use Webmail\Services\OAuthTokenCache;
use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('account-teardown', $argv);

// -- Pre-flight ----------------------------------------------------------

foreach (['pdo_mysql', 'redis'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    $runner->log('DB connectivity failed: ' . $e->getMessage());
    exit(1);
}

$redisOk = false;
try {
    $redis = new RedisCacheService($config);
    $redisOk = $redis->isAvailable();
} catch (\Throwable $e) {
    $runner->log('Redis connectivity failed: ' . $e->getMessage());
}

// -- Synthetic fixtures (prefixed so they never collide with prod) -------

$PROVIDER  = 'google';
$PRIMARY   = 'flowone_test_primary@example.invalid';
$ACCOUNT   = 'flowone_test_acct@example.invalid';
$KEEP_ACCT = 'flowone_test_keep@example.invalid';   // collateral guard
$FID_A     = uuid4();
$FID_B     = uuid4();
$FID_KEEP  = uuid4();
$IDENT_ID  = uuid4();

$teardown = new AccountTeardownService($config, $db);
$sync     = new MailboxSyncService($config, $db);
$cache    = new OAuthTokenCache($config);

$cleanup = function () use ($db, $cache, $PROVIDER, $PRIMARY, $ACCOUNT, $KEEP_ACCT) {
    foreach (
        [
            ['webmail_folder_sync_state', 'account_email', [$ACCOUNT, $KEEP_ACCT]],
            ['webmail_folder_identity',   'account_id',    [$ACCOUNT, $KEEP_ACCT]],
        ] as [$table, $col, $vals]
    ) {
        foreach ($vals as $v) {
            try {
                $db->prepare("DELETE FROM {$table} WHERE LOWER({$col}) = ?")->execute([strtolower($v)]);
            } catch (\Throwable $e) {
                // table may not exist in a stripped env; ignore
            }
        }
    }
    try {
        $cache->invalidateToken($PROVIDER, $PRIMARY, $ACCOUNT);
        $cache->clearRevoked($PROVIDER, $PRIMARY, $ACCOUNT);
    } catch (\Throwable $e) {
    }
};
$runner->addCleanup($cleanup);
// Start from a clean slate in case a previous run died mid-way.
$cleanup();

if ($runner->smoke) {
    $runner->section('1. SMOKE');
    $runner->test('db reachable', fn() => true);
    $runner->test('redis reachable', fn() => $redisOk ? true : 'warn');
    exit($runner->finish());
}

// -- Seeding helpers -----------------------------------------------------

$seedSyncState = function (string $user, string $account, string $fid, string $path) use ($db) {
    $db->prepare(
        'INSERT INTO webmail_folder_sync_state (user_email, folder_id, account_email, folder_path, status)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([strtolower($user), $fid, strtolower($account), $path, 'synced']);
};

// -- 1. Full teardown ----------------------------------------------------

if ($runner->shouldRunSection('1. teardown')) {
    $runner->section('1. TEARDOWN');

    $runner->test('seed: two sync-state rows + one identity row for the target account', function () use ($seedSyncState, $db, $PRIMARY, $ACCOUNT, $FID_A, $FID_B, $IDENT_ID, $runner) {
        $seedSyncState($PRIMARY, $ACCOUNT, $FID_A, 'flowone_test_INBOX');
        $seedSyncState($PRIMARY, $ACCOUNT, $FID_B, 'flowone_test_Sent');

        try {
            $db->prepare(
                'INSERT INTO webmail_folder_identity (id, account_id, current_path, display_name)
                 VALUES (?, ?, ?, ?)'
            )->execute([$IDENT_ID, strtolower($ACCOUNT), 'flowone_test_INBOX', 'flowone_test INBOX']);
        } catch (\Throwable $e) {
            // identity table optional in stripped envs; assertions below tolerate 0
        }

        $n = (int)$db->query(
            "SELECT COUNT(*) FROM webmail_folder_sync_state WHERE LOWER(account_email)='" . strtolower($ACCOUNT) . "'"
        )->fetchColumn();
        $runner->assertEquals(2, $n, 'seed sync-state row count');
    });

    $runner->test('seed: an UNRELATED account that must survive teardown', function () use ($seedSyncState, $PRIMARY, $KEEP_ACCT, $FID_KEEP, $runner, $db) {
        $seedSyncState($PRIMARY, $KEEP_ACCT, $FID_KEEP, 'flowone_test_KEEP');
        $n = (int)$db->query(
            "SELECT COUNT(*) FROM webmail_folder_sync_state WHERE LOWER(account_email)='" . strtolower($KEEP_ACCT) . "'"
        )->fetchColumn();
        $runner->assertEquals(1, $n, 'collateral row seeded');
    });

    $runner->test('seed: cached OAuth access token', function () use ($cache, $PROVIDER, $PRIMARY, $ACCOUNT, $redisOk) {
        if (!$redisOk) return 'warn';
        $cache->putToken($PROVIDER, $PRIMARY, $ACCOUNT, 'ya29.flowone_test_token', 300);
        $got = $cache->getToken($PROVIDER, $PRIMARY, $ACCOUNT);
        if ($got !== 'ya29.flowone_test_token') {
            throw new \RuntimeException('token did not seed; got ' . var_export($got, true));
        }
    });

    $summary = null;
    $runner->test('purge() runs and reports the rows it removed', function () use ($teardown, $PRIMARY, $ACCOUNT, $PROVIDER, &$summary, $runner) {
        $summary = $teardown->purge($PRIMARY, $ACCOUNT, $PROVIDER);
        $runner->assertEquals(2, (int)$summary['sync_state_rows'], 'purge should report 2 sync-state rows removed');
    });

    $runner->test('all sync-state rows for the target account are gone', function () use ($db, $ACCOUNT, $runner) {
        $n = (int)$db->query(
            "SELECT COUNT(*) FROM webmail_folder_sync_state WHERE LOWER(account_email)='" . strtolower($ACCOUNT) . "'"
        )->fetchColumn();
        $runner->assertEquals(0, $n, 'sync-state rows must be 0 after purge');
    });

    $runner->test('folder identity rows for the target account are gone', function () use ($db, $ACCOUNT, $runner) {
        try {
            $n = (int)$db->query(
                "SELECT COUNT(*) FROM webmail_folder_identity WHERE LOWER(account_id)='" . strtolower($ACCOUNT) . "'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 'warn';
        }
        $runner->assertEquals(0, $n, 'identity rows must be 0 after purge');
    });

    $runner->test('cached OAuth token is cleared', function () use ($cache, $PROVIDER, $PRIMARY, $ACCOUNT, $redisOk, $runner) {
        if (!$redisOk) return 'warn';
        $got = $cache->getToken($PROVIDER, $PRIMARY, $ACCOUNT);
        $runner->assertTrue($got === null, 'token cache must be empty after purge, got ' . var_export($got, true));
    });

    $runner->test('UNRELATED account is untouched (no collateral damage)', function () use ($db, $KEEP_ACCT, $runner) {
        $n = (int)$db->query(
            "SELECT COUNT(*) FROM webmail_folder_sync_state WHERE LOWER(account_email)='" . strtolower($KEEP_ACCT) . "'"
        )->fetchColumn();
        $runner->assertEquals(1, $n, 'collateral account row must still exist');
    });
}

// -- 2. Idempotency ------------------------------------------------------

if ($runner->shouldRunSection('2. idempotent')) {
    $runner->section('2. IDEMPOTENT');

    $runner->test('purge() on an already-clean account is a safe no-op', function () use ($teardown, $PRIMARY, $ACCOUNT, $PROVIDER, $runner) {
        $r = $teardown->purge($PRIMARY, $ACCOUNT, $PROVIDER);
        $runner->assertEquals(0, (int)$r['sync_state_rows'], 'second purge should remove 0 sync-state rows');
        $runner->assertEquals(0, (int)$r['identity_rows'], 'second purge should remove 0 identity rows');
    });

    $runner->test('empty inputs are rejected without touching anything', function () use ($teardown, $runner) {
        $r = $teardown->purge('', '', null);
        $runner->assertEquals(0, (int)$r['sync_state_rows'], 'empty input must remove nothing');
    });
}

exit($runner->finish());

// ==========================================================================

function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
