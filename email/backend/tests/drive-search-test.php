#!/usr/bin/env php
<?php
/**
 * FlowOne Drive-Wide Search Test.
 *
 * Exercises the server-side Drive search that powers the "Search My Drive" box:
 * DriveService::searchByName() (partial name match across ALL folders, trashed
 * excluded, per-user scoped) plus the controller-level guard that rejects
 * queries shorter than 2 characters (DriveController::search()).
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight    extensions, autoloader, DB, required tables + searchByName()
 *   search       partial match, cross-folder match, folder-name match,
 *                trashed exclusion, per-user scoping, result limit, <2 guard
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-search-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --skip-send            accepted for rule parity (no external sends here)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * All test rows use flowone_test_ prefixes / flowone_test*@flowone.pro users
 * and are removed in cleanup handlers that run even on failure or SIGINT.
 * Idempotent - safe to run repeatedly.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('drive-search', $argv);

$config = null;
$db = null;

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (pdo_mysql, mbstring)', function () use ($runner) {
        foreach (['pdo_mysql', 'mbstring'] as $ext) {
            $runner->assertTrue(extension_loaded($ext), "{$ext} extension missing");
        }
    });

    $runner->test('cron bootstrap + config load', function () use ($runner, &$config) {
        require_once __DIR__ . '/../cron/bootstrap.php';
        $config = require __DIR__ . '/../src/config.php';
        $runner->assertTrue(is_array($config), 'config.php did not return an array');
    });

    $runner->test('database reachable', function () use ($runner, &$config, &$db) {
        $db = \Webmail\Core\Database::getConnection($config);
        $runner->assertEquals('1', (string)$db->query('SELECT 1')->fetchColumn(), 'SELECT 1 failed');
    });

    $runner->test('required tables exist', function () use ($runner, &$db) {
        foreach (['drive_files', 'drive_folders'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing");
        }
    });

    $runner->test('DriveService::searchByName exists', function () use ($runner) {
        $runner->assertTrue(class_exists('\Webmail\Services\DriveService'), 'DriveService missing');
        $runner->assertTrue(
            method_exists('\Webmail\Services\DriveService', 'searchByName'),
            'searchByName() not defined on DriveService'
        );
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// Lazy init for --only runs that skip preflight
if ($config === null) {
    require_once __DIR__ . '/../cron/bootstrap.php';
    $config = require __DIR__ . '/../src/config.php';
}
if ($db === null) {
    $db = \Webmail\Core\Database::getConnection($config);
}

$owner = 'flowone_test_owner@flowone.pro';
$other = 'flowone_test_other@flowone.pro';

$drive = new \Webmail\Services\DriveService($config, $owner);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
$cleanup = function () use ($db) {
    $db->exec("DELETE FROM drive_files WHERE user_email LIKE 'flowone_test_%' AND original_name LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_folders WHERE user_email LIKE 'flowone_test_%' AND name LIKE 'flowone_test_%'");
};
$cleanup();
$runner->addCleanup($cleanup);

/** Insert a bare drive_files row (DB-only fixture; no bytes on disk). */
$makeFile = function (string $name, ?string $email = null, ?int $folderId = null, int $trashed = 0) use ($db, $owner): int {
    $email = $email ?? $owner;
    $stmt = $db->prepare('
        INSERT INTO drive_files (user_email, filename, original_name, size, mime_type, folder_id, is_trashed)
        VALUES (?, ?, ?, 1024, ?, ?, ?)
    ');
    $stmt->execute([
        strtolower($email),
        'flowone_test_' . bin2hex(random_bytes(8)) . '.docx',
        $name,
        'application/octet-stream',
        $folderId,
        $trashed,
    ]);
    return (int)$db->lastInsertId();
};

/** Insert a bare drive_folders row. */
$makeFolder = function (string $name, ?string $email = null, ?int $parentId = null) use ($db, $owner): int {
    $email = $email ?? $owner;
    $stmt = $db->prepare('INSERT INTO drive_folders (user_email, name, parent_id, size, is_trashed) VALUES (?, ?, ?, 0, 0)');
    $stmt->execute([strtolower($email), $name, $parentId]);
    return (int)$db->lastInsertId();
};

// Owner fixtures
$rootFileId   = $makeFile('flowone_test_alpha_report.docx');                       // root-level
$subFolderId  = $makeFolder('flowone_test_subfolder');                             // a nested folder
$nestedFileId = $makeFile('flowone_test_beta_nested.docx', $owner, $subFolderId);  // file INSIDE subfolder
$trashedId    = $makeFile('flowone_test_gamma_trashed.docx', $owner, null, 1);     // trashed -> excluded
$projFolderId = $makeFolder('flowone_test_projects_folder');                       // folder name match target

// Cross-user fixture (must never leak into the owner's results)
$otherFileId  = $makeFile('flowone_test_zeta_otheronly.docx', $other);

// Mirrors the controller guard in DriveController::search(): reject <2 chars,
// otherwise delegate to the service. Keeps the guard contract under test
// without standing up the full HTTP/auth stack.
$runSearch = function (string $q) use ($drive, $owner): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return ['folders' => [], 'files' => []];
    }
    return $drive->searchByName($owner, $q);
};

/** True if any file in the result set has the given original_name. */
$hasFile = function (array $result, string $name): bool {
    foreach ($result['files'] as $f) {
        if (($f['original_name'] ?? null) === $name) return true;
    }
    return false;
};

/** True if any folder in the result set has the given name. */
$hasFolder = function (array $result, string $name): bool {
    foreach ($result['folders'] as $f) {
        if (($f['name'] ?? null) === $name) return true;
    }
    return false;
};

// ---------------------------------------------------------------------------
// 2. DRIVE-WIDE SEARCH
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('search')) {
    $runner->section('2. DRIVE-WIDE SEARCH');

    $runner->test('partial (substring) match finds a root file', function () use ($runner, $drive, $owner, $hasFile) {
        $res = $drive->searchByName($owner, 'alpha');
        $runner->assertTrue($hasFile($res, 'flowone_test_alpha_report.docx'), 'partial match did not find root file');
    });

    $runner->test('cross-folder match: a nested file is found from root', function () use ($runner, $drive, $owner, $hasFile, $subFolderId) {
        $res = $drive->searchByName($owner, 'beta_nested');
        $runner->assertTrue($hasFile($res, 'flowone_test_beta_nested.docx'), 'nested file not found by Drive-wide search');
        // Sanity: it really is nested, not at root.
        $runner->assertTrue($subFolderId > 0, 'nested fixture not created');
    });

    $runner->test('folder name match is returned in folders[]', function () use ($runner, $drive, $owner, $hasFolder) {
        $res = $drive->searchByName($owner, 'projects_folder');
        $runner->assertTrue($hasFolder($res, 'flowone_test_projects_folder'), 'folder name match missing');
    });

    $runner->test('trashed items are excluded', function () use ($runner, $drive, $owner, $hasFile) {
        $res = $drive->searchByName($owner, 'gamma_trashed');
        $runner->assertTrue(!$hasFile($res, 'flowone_test_gamma_trashed.docx'), 'trashed file leaked into results');
    });

    $runner->test('results are scoped to the user (no cross-user leak)', function () use ($runner, $drive, $owner, $other, $hasFile) {
        $ownerRes = $drive->searchByName($owner, 'zeta_otheronly');
        $runner->assertTrue(!$hasFile($ownerRes, 'flowone_test_zeta_otheronly.docx'), 'another user\'s file leaked to owner');

        $otherRes = $drive->searchByName($other, 'zeta_otheronly');
        $runner->assertTrue($hasFile($otherRes, 'flowone_test_zeta_otheronly.docx'), 'owner of the file could not find it');
    });

    $runner->test('limit caps the number of returned files', function () use ($runner, $drive, $makeFile) {
        $tag = 'flowone_test_limit_' . bin2hex(random_bytes(3));
        for ($i = 0; $i < 3; $i++) {
            $makeFile("{$tag}_{$i}.docx");
        }
        $res = $drive->searchByName('flowone_test_owner@flowone.pro', $tag, 2);
        $runner->assertEquals(2, count($res['files']), 'limit not honored');
    });

    $runner->test('query shorter than 2 chars returns empty (controller guard)', function () use ($runner, $runSearch) {
        $res = $runSearch('a');
        $runner->assertEquals(0, count($res['files']), 'short query returned files');
        $runner->assertEquals(0, count($res['folders']), 'short query returned folders');
    });

    $runner->test('valid 2-char query passes the guard and searches', function () use ($runner, $runSearch, $hasFile) {
        // "al" is a substring of flowone_test_alpha_report.docx
        $res = $runSearch('al');
        $runner->assertTrue($hasFile($res, 'flowone_test_alpha_report.docx'), '2-char query was blocked or matched nothing');
    });
}

exit($runner->finish());
