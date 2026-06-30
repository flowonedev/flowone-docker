#!/usr/bin/env php
<?php
/**
 * FlowOne Drive File Sharing Test.
 *
 * Verifies file-level sharing with people and colleague groups
 * (migration 191: drive_file_collaborators + drive_file_group_access,
 * DriveFileSharingService): CRUD on both share types, ownership and
 * self-share guards, direct access resolution (person vs group, editor
 * beats viewer), and the shared-with-me file listing.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   extensions, autoloader, DB, migration 191 + colleague tables
 *   people      add / duplicate-upsert / update / list / remove collaborators
 *   groups      group access grant / list / replace / remove
 *   access      resolveDirectFileAccess (person, group, editor-wins, required perm)
 *   sharedlist  getFilesSharedWith (dedupe, trash + own-file exclusion)
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-file-sharing-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            accepted for rule parity (no external sends here)
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * All test rows use flowone_test_ prefixes / flowone_test*@flowone.pro
 * users and are removed in cleanup handlers that run even on failure or
 * SIGINT. Idempotent - safe to run repeatedly.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('drive-file-sharing', $argv);

$config = null;
$db = null;

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (pdo_mysql, openssl, mbstring)', function () use ($runner) {
        foreach (['pdo_mysql', 'openssl', 'mbstring'] as $ext) {
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

    $runner->test('file sharing tables exist (migration 191)', function () use ($runner, &$db) {
        foreach (['drive_file_collaborators', 'drive_file_group_access'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing - run migration 191_drive_file_collaborators.sql");
        }
    });

    $runner->test('colleague system tables exist (migration 032)', function () use ($runner, &$db) {
        foreach (['organization_colleagues', 'colleague_groups', 'colleague_group_members'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing");
        }
    });

    $runner->test('DriveFileSharingService class loads', function () use ($runner) {
        $runner->assertTrue(class_exists('\Webmail\Services\DriveFileSharingService'), 'service class missing');
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

$sharing = new \Webmail\Services\DriveFileSharingService($db);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
$owner = 'flowone_test_owner@flowone.pro';
$collab = 'flowone_test_collab@flowone.pro';
$collab2 = 'flowone_test_collab2@flowone.pro';
$stranger = 'flowone_test_stranger@flowone.pro';
$groupName = 'flowone_test_group_' . bin2hex(random_bytes(3));

$cleanup = function () use ($db) {
    // Order matters only for non-cascading rows; FK cascades handle the rest.
    $db->exec("DELETE FROM drive_file_collaborators WHERE invited_by LIKE 'flowone_test_%' OR user_email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_file_group_access WHERE granted_by LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM colleague_groups WHERE name LIKE 'flowone_test_group_%'");
    $db->exec("DELETE FROM organization_colleagues WHERE email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_files WHERE user_email LIKE 'flowone_test_%' AND original_name LIKE 'flowone_test_%'");
};
// Remove leftovers from any previous failed run before starting.
$cleanup();
$runner->addCleanup($cleanup);

/** Insert a bare drive_files row (DB-only fixture; no bytes on disk needed). */
$makeFile = function (string $userEmail, string $name, int $trashed = 0) use ($db): int {
    $stmt = $db->prepare('
        INSERT INTO drive_files (user_email, filename, original_name, size, mime_type, is_trashed)
        VALUES (?, ?, ?, 1024, ?, ?)
    ');
    $stmt->execute([
        strtolower($userEmail),
        'flowone_test_' . bin2hex(random_bytes(8)) . '.xlsx',
        $name,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        $trashed,
    ]);
    return (int)$db->lastInsertId();
};

$fileId = $makeFile($owner, 'flowone_test_budget.xlsx');

// Colleague + group fixtures (for the groups/access/sharedlist sections)
$db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Collab')")
    ->execute([$collab]);
$collabColleagueId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Collab2')")
    ->execute([$collab2]);
$collab2ColleagueId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO colleague_groups (organization_domain, name, created_by) VALUES ('flowone.pro', ?, ?)")
    ->execute([$groupName, $owner]);
$groupId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO colleague_group_members (group_id, colleague_id, added_by) VALUES (?, ?, ?)')
    ->execute([$groupId, $collab2ColleagueId, $owner]);

// ---------------------------------------------------------------------------
// 2. PEOPLE
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('people')) {
    $runner->section('2. PEOPLE');

    $runner->test('owner can add a collaborator (viewer)', function () use ($runner, $sharing, $owner, $collab, $fileId) {
        $result = $sharing->addCollaborator($owner, $fileId, $collab, 'viewer');
        $runner->assertTrue($result['success'], 'add failed: ' . ($result['error'] ?? ''));
        $runner->assertEquals('viewer', $result['collaborator']['permission'], 'permission mismatch');
    });

    $runner->test('re-adding the same person upserts the permission', function () use ($runner, $sharing, $owner, $collab, $fileId) {
        $result = $sharing->addCollaborator($owner, $fileId, $collab, 'editor');
        $runner->assertTrue($result['success'], 're-add failed');
        $list = $sharing->getCollaborators($owner, $fileId);
        $runner->assertEquals(1, count($list), 'duplicate row created instead of upsert');
        $runner->assertEquals('editor', $list[0]['permission'], 'upsert did not update permission');
    });

    $runner->test('cannot share a file with yourself', function () use ($runner, $sharing, $owner, $fileId) {
        $result = $sharing->addCollaborator($owner, $fileId, $owner, 'editor');
        $runner->assertTrue(!$result['success'], 'self-share was accepted');
    });

    $runner->test('non-owner cannot share the file', function () use ($runner, $sharing, $stranger, $collab, $fileId) {
        $result = $sharing->addCollaborator($stranger, $fileId, $collab, 'editor');
        $runner->assertTrue(!$result['success'], 'non-owner could share');
    });

    $runner->test('invalid permission falls back to viewer', function () use ($runner, $sharing, $owner, $collab2, $fileId) {
        $result = $sharing->addCollaborator($owner, $fileId, $collab2, 'superadmin');
        $runner->assertTrue($result['success'], 'add failed');
        $runner->assertEquals('viewer', $result['collaborator']['permission'], 'invalid permission not normalized');
    });

    $runner->test('updateCollaboratorPermission changes the role', function () use ($runner, $sharing, $owner, $collab2, $fileId) {
        $runner->assertTrue($sharing->updateCollaboratorPermission($owner, $fileId, $collab2, 'editor'), 'update failed');
        $list = $sharing->getCollaborators($owner, $fileId);
        $byEmail = array_column($list, 'permission', 'email');
        $runner->assertEquals('editor', $byEmail[$collab2] ?? '', 'permission not updated');
    });

    $runner->test('updateCollaboratorPermission rejects invalid role', function () use ($runner, $sharing, $owner, $collab2, $fileId) {
        $runner->assertTrue(!$sharing->updateCollaboratorPermission($owner, $fileId, $collab2, 'root'), 'invalid role accepted');
    });

    $runner->test('removeCollaborator deletes the row', function () use ($runner, $sharing, $owner, $collab2, $fileId) {
        $runner->assertTrue($sharing->removeCollaborator($owner, $fileId, $collab2), 'remove failed');
        $list = $sharing->getCollaborators($owner, $fileId);
        $runner->assertEquals(1, count($list), 'collaborator not removed');
    });

    $runner->test('non-owner sees an empty collaborator list', function () use ($runner, $sharing, $stranger, $fileId) {
        $runner->assertEquals(0, count($sharing->getCollaborators($stranger, $fileId)), 'non-owner could list collaborators');
    });
}

// ---------------------------------------------------------------------------
// 3. GROUPS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('groups')) {
    $runner->section('3. GROUPS');

    $runner->test('owner can grant group access', function () use ($runner, $sharing, $owner, $fileId, $groupId) {
        $result = $sharing->addGroupAccess($owner, $fileId, $groupId, 'viewer');
        $runner->assertTrue($result['success'], 'grant failed: ' . ($result['error'] ?? ''));
    });

    $runner->test('group access list includes member count + name', function () use ($runner, $sharing, $owner, $fileId, $groupId, $groupName) {
        $list = $sharing->getGroupAccess($owner, $fileId);
        $runner->assertEquals(1, count($list), 'unexpected group access count');
        $runner->assertEquals($groupName, $list[0]['group_name'], 'group name mismatch');
        $runner->assertEquals($groupId, (int)$list[0]['group_id'], 'group id mismatch');
        $runner->assertEquals(1, (int)$list[0]['member_count'], 'member count mismatch');
    });

    $runner->test('re-granting upserts the permission', function () use ($runner, $sharing, $owner, $fileId, $groupId) {
        $result = $sharing->addGroupAccess($owner, $fileId, $groupId, 'editor');
        $runner->assertTrue($result['success'], 're-grant failed');
        $list = $sharing->getGroupAccess($owner, $fileId);
        $runner->assertEquals(1, count($list), 'duplicate group access row');
        $runner->assertEquals('editor', $list[0]['permission'], 'permission not upserted');
    });

    $runner->test('grant rejects unknown group', function () use ($runner, $sharing, $owner, $fileId) {
        $result = $sharing->addGroupAccess($owner, $fileId, 999999999, 'viewer');
        $runner->assertTrue(!$result['success'], 'unknown group accepted');
    });

    $runner->test('non-owner cannot grant group access', function () use ($runner, $sharing, $stranger, $fileId, $groupId) {
        $result = $sharing->addGroupAccess($stranger, $fileId, $groupId, 'viewer');
        $runner->assertTrue(!$result['success'], 'non-owner could grant');
    });

    $runner->test('removeGroupAccess deletes the grant', function () use ($runner, $sharing, $owner, $fileId, $groupId) {
        $result = $sharing->removeGroupAccess($owner, $fileId, $groupId);
        $runner->assertTrue($result['success'], 'remove failed');
        $runner->assertEquals(0, count($sharing->getGroupAccess($owner, $fileId)), 'grant not removed');
    });
}

// ---------------------------------------------------------------------------
// 4. ACCESS RESOLUTION
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('access')) {
    $runner->section('4. ACCESS RESOLUTION');

    $runner->test('person share resolves with via=user', function () use ($runner, $sharing, $owner, $collab, $fileId) {
        // people section left $collab as editor on $fileId; re-assert to be --only-safe
        $sharing->addCollaborator($owner, $fileId, $collab, 'editor');
        $access = $sharing->resolveDirectFileAccess($collab, $fileId);
        $runner->assertTrue($access !== false, 'no access resolved');
        $runner->assertEquals('user', $access['via'], 'expected person share');
        $runner->assertEquals('editor', $access['permission'], 'permission mismatch');
        $runner->assertEquals($owner, $access['owner_email'], 'owner mismatch');
    });

    $runner->test('group share resolves with via=group', function () use ($runner, $sharing, $owner, $collab2, $fileId, $groupId) {
        $sharing->addGroupAccess($owner, $fileId, $groupId, 'viewer');
        $access = $sharing->resolveDirectFileAccess($collab2, $fileId);
        $runner->assertTrue($access !== false, 'group member got no access');
        $runner->assertEquals('group', $access['via'], 'expected group share');
        $runner->assertEquals('viewer', $access['permission'], 'permission mismatch');
    });

    $runner->test('editor beats viewer when both person and group share exist', function () use ($runner, $sharing, $owner, $collab2, $fileId) {
        // collab2: viewer via group (above) + editor via direct share
        $sharing->addCollaborator($owner, $fileId, $collab2, 'editor');
        $access = $sharing->resolveDirectFileAccess($collab2, $fileId);
        $runner->assertEquals('editor', $access['permission'], 'highest permission did not win');
        $sharing->removeCollaborator($owner, $fileId, $collab2);
    });

    $runner->test('requiredPermission=editor filters out viewers', function () use ($runner, $sharing, $collab2, $fileId) {
        // collab2 is back to viewer-via-group only
        $access = $sharing->resolveDirectFileAccess($collab2, $fileId, 'editor');
        $runner->assertTrue($access === false, 'viewer passed an editor requirement');
    });

    $runner->test('stranger has no access', function () use ($runner, $sharing, $stranger, $fileId) {
        $runner->assertTrue($sharing->resolveDirectFileAccess($stranger, $fileId) === false, 'stranger got access');
    });
}

// ---------------------------------------------------------------------------
// 5. SHARED LIST (shared-with-me)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('sharedlist')) {
    $runner->section('5. SHARED LIST');

    $runner->test('person share appears in getFilesSharedWith', function () use ($runner, $sharing, $owner, $collab, $fileId) {
        $sharing->addCollaborator($owner, $fileId, $collab, 'editor');
        $files = $sharing->getFilesSharedWith($collab);
        $ids = array_map(fn($f) => (int)$f['id'], $files);
        $runner->assertTrue(in_array($fileId, $ids, true), 'shared file missing from list');
    });

    $runner->test('group share appears once with highest permission', function () use ($runner, $sharing, $owner, $collab2, $fileId, $groupId) {
        $sharing->addGroupAccess($owner, $fileId, $groupId, 'viewer');
        $sharing->addCollaborator($owner, $fileId, $collab2, 'editor');
        $files = $sharing->getFilesSharedWith($collab2);
        $matches = array_values(array_filter($files, fn($f) => (int)$f['id'] === $fileId));
        $runner->assertEquals(1, count($matches), 'file duplicated in shared list');
        $runner->assertEquals('editor', $matches[0]['permission'], 'editor permission did not win');
        $sharing->removeCollaborator($owner, $fileId, $collab2);
    });

    $runner->test('trashed files are excluded', function () use ($runner, $sharing, $owner, $collab, $makeFile, $db) {
        $trashedId = $makeFile($owner, 'flowone_test_trashed.xlsx');
        $sharing->addCollaborator($owner, $trashedId, $collab, 'viewer');
        $db->prepare('UPDATE drive_files SET is_trashed = 1 WHERE id = ?')->execute([$trashedId]);
        $files = $sharing->getFilesSharedWith($collab);
        $ids = array_map(fn($f) => (int)$f['id'], $files);
        $runner->assertTrue(!in_array($trashedId, $ids, true), 'trashed file listed');
    });

    $runner->test('files I own never appear in my shared list', function () use ($runner, $sharing, $owner, $fileId, $groupId, $db, $collabColleagueId) {
        // Put the owner into the shared group, then verify the owner's own
        // file does not leak into their shared-with-me list.
        $db->prepare("INSERT IGNORE INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Owner')")
            ->execute([$owner]);
        $ownerColleagueId = (int)$db->query("SELECT id FROM organization_colleagues WHERE email = '{$owner}'")->fetchColumn();
        $db->prepare('INSERT IGNORE INTO colleague_group_members (group_id, colleague_id, added_by) VALUES (?, ?, ?)')
            ->execute([$groupId, $ownerColleagueId, $owner]);
        $sharing->addGroupAccess($owner, $fileId, $groupId, 'editor');

        $files = $sharing->getFilesSharedWith($owner);
        $ids = array_map(fn($f) => (int)$f['id'], $files);
        $runner->assertTrue(!in_array($fileId, $ids, true), 'own file leaked into shared-with-me');
    });
}

exit($runner->finish());
