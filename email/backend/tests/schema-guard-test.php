#!/usr/bin/env php
<?php
/**
 * schema-guard-test.php
 *
 * Verifies \Webmail\Core\SchemaGuard — the helper that gates each service's
 * self-healing DDL (ensureTablesExist/ensureX) so it runs at most once per
 * code version instead of on every request.
 *
 * We verify (without touching IMAP or any external service):
 *   1. First run executes the migrate callable and records a marker.
 *   2. A second call in the SAME request is deduped in-process (no re-run).
 *   3. Across "requests" (in-process state cleared) the DB marker still
 *      suppresses the re-run.
 *   4. Bumping the version re-runs the callable exactly once and updates the
 *      marker.
 *   5. With no shared connection it falls back to running the callable.
 *   6. A throwing callable never escapes and writes no marker.
 *   7. INTEGRATION: constructing a real service (FilterService) registers a
 *      schema_guards marker for its class — proving the wiring is live.
 *
 * Per .cursor/rules/server-side-testing.mdc — CLI only, idempotent, test rows
 * prefixed `flowone_test_`, cleanup on every exit path.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/schema-guard-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;
use Webmail\Core\SchemaGuard;
use Webmail\Services\FilterService;

$runner = new FlowOneTestRunner('schema-guard', $argv);

// --- 0. PREFLIGHT ------------------------------------------------------------

$runner->section('0. PREFLIGHT');

foreach (['pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        $runner->log("missing PHP extension: {$ext}");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    $runner->log('FATAL: cannot connect to DB: ' . $e->getMessage());
    exit(1);
}

$runner->test('db reachable', function () use ($db) {
    return (string) $db->query('SELECT 1')->fetchColumn() === '1';
});

$runner->test('GET_LOCK / RELEASE_LOCK available', function () use ($db) {
    $got = (string) $db->query("SELECT GET_LOCK('flowone_test_preflight', 1)")->fetchColumn();
    $db->query("SELECT RELEASE_LOCK('flowone_test_preflight')");
    if ($got !== '1') {
        throw new \RuntimeException('GET_LOCK did not return 1');
    }
    return true;
});

// schema_guards is created lazily by SchemaGuard; trigger + verify it exists.
SchemaGuard::runFor($db, 'flowone_test_preflight_create', '0', function () {});
$runner->test('schema_guards table exists', function () use ($db) {
    $db->query('SELECT guard_key, version, applied_at FROM schema_guards LIMIT 1');
    return true;
});

// Helper: clear SchemaGuard's in-process memo to simulate a fresh request.
$resetInProcess = function () {
    $r = new \ReflectionClass(SchemaGuard::class);
    $p = $r->getProperty('verified');
    $p->setAccessible(true);
    $p->setValue(null, []);
};

// Helper: read the recorded version for a key (or null).
$markerVersion = function (string $key) use ($db): ?string {
    $stmt = $db->prepare('SELECT version FROM schema_guards WHERE guard_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
};

$wipe = function () use ($db) {
    $db->prepare("DELETE FROM schema_guards WHERE guard_key LIKE 'flowone_test_%'")->execute();
};

$wipe();
$runner->addCleanup(function () use ($wipe, $runner) {
    $wipe();
    $runner->log('post-test cleanup: flowone_test_ markers removed');
});

if ($runner->smoke) {
    exit($runner->finish());
}

// --- 1. GUARD CORE -----------------------------------------------------------

$runner->section('1. GUARD CORE');

if ($runner->shouldRunSection('1. GUARD CORE')) {

    $runner->test('first run executes migrate + writes marker', function () use ($db, $resetInProcess, $markerVersion) {
        $resetInProcess();
        $key = 'flowone_test_guard_a';
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);
        $calls = 0;
        SchemaGuard::runFor($db, $key, '1', function () use (&$calls) { $calls++; });
        if ($calls !== 1) throw new \RuntimeException("expected 1 call, got {$calls}");
        if ($markerVersion($key) !== '1') throw new \RuntimeException('marker version not recorded as 1');
        return true;
    });

    $runner->test('in-process dedupe: same request does not re-run', function () use ($db, $resetInProcess) {
        $resetInProcess();
        $key = 'flowone_test_guard_b';
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; };
        SchemaGuard::runFor($db, $key, '1', $fn);
        SchemaGuard::runFor($db, $key, '1', $fn); // same request, same version
        if ($calls !== 1) throw new \RuntimeException("expected 1 call (deduped), got {$calls}");
        return true;
    });

    $runner->test('db marker suppresses re-run across requests', function () use ($db, $resetInProcess) {
        $key = 'flowone_test_guard_c';
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; };

        $resetInProcess();                       // request 1
        SchemaGuard::runFor($db, $key, '1', $fn);
        $resetInProcess();                       // request 2 (fresh memo)
        SchemaGuard::runFor($db, $key, '1', $fn);

        if ($calls !== 1) throw new \RuntimeException("expected 1 call (marker hit), got {$calls}");
        return true;
    });

    $runner->test('version bump re-runs once + updates marker', function () use ($db, $resetInProcess, $markerVersion) {
        $key = 'flowone_test_guard_d';
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; };

        $resetInProcess();
        SchemaGuard::runFor($db, $key, '1', $fn);   // v1 -> run
        $resetInProcess();
        SchemaGuard::runFor($db, $key, '2', $fn);   // v2 -> re-run
        $resetInProcess();
        SchemaGuard::runFor($db, $key, '2', $fn);   // v2 again -> skip

        if ($calls !== 2) throw new \RuntimeException("expected 2 calls, got {$calls}");
        if ($markerVersion($key) !== '2') throw new \RuntimeException('marker version not bumped to 2');
        return true;
    });

    $runner->test('no connection -> falls back to running migrate', function () use ($resetInProcess) {
        $resetInProcess();
        $calls = 0;
        SchemaGuard::runFor(null, 'flowone_test_guard_e', '1', function () use (&$calls) { $calls++; });
        if ($calls !== 1) throw new \RuntimeException("expected 1 call (fallback), got {$calls}");
        return true;
    });

    $runner->test('throwing migrate does not escape and writes no marker', function () use ($db, $resetInProcess, $markerVersion) {
        $resetInProcess();
        $key = 'flowone_test_guard_f';
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);
        try {
            SchemaGuard::runFor($db, $key, '1', function () {
                throw new \RuntimeException('boom');
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('exception escaped SchemaGuard: ' . $e->getMessage());
        }
        if ($markerVersion($key) !== null) throw new \RuntimeException('marker was written despite failure');
        return true;
    });
}

// --- 2. INTEGRATION (real service) ------------------------------------------

$runner->section('2. INTEGRATION');

if ($runner->shouldRunSection('2. INTEGRATION')) {

    $runner->test('constructing FilterService registers its schema_guards marker', function () use ($config, $db, $resetInProcess, $markerVersion) {
        $key = FilterService::class; // 'Webmail\Services\FilterService'
        // Force a clean slate so we can prove construction (re)creates it.
        $resetInProcess();
        $db->prepare('DELETE FROM schema_guards WHERE guard_key = ?')->execute([$key]);

        new FilterService($config);

        $v = $markerVersion($key);
        if ($v === null) throw new \RuntimeException('FilterService did not register a schema_guards marker');
        // Version is the service file mtime — must be a positive integer string.
        if (!ctype_digit($v) || (int) $v <= 0) throw new \RuntimeException("unexpected marker version: {$v}");
        return true;
    });

    $runner->test('second FilterService construction is a no-op (marker already current)', function () use ($config, $db, $resetInProcess) {
        $key = FilterService::class;
        // Count rows before/after a fresh-request construction: marker count
        // for this key must stay exactly 1 (no duplicate, no churn).
        $resetInProcess();
        new FilterService($config);
        $stmt = $db->prepare('SELECT COUNT(*) FROM schema_guards WHERE guard_key = ?');
        $stmt->execute([$key]);
        $n = (int) $stmt->fetchColumn();
        if ($n !== 1) throw new \RuntimeException("expected exactly 1 marker row, got {$n}");
        return true;
    });
}

exit($runner->finish());
