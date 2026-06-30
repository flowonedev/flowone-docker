#!/usr/bin/env php
<?php
/**
 * FlowOne Unified Drive Share Test.
 *
 * Exercises the full surface behind the unified share modal (the single,
 * app-wide UnifiedShareModal): public token links (file + folder) and their
 * share-state payload, office guest links, and the new "notify colleagues /
 * groups about a share link" path (ShareNotificationService).
 *
 * The people/group collaborator CRUD itself is covered by
 * drive-file-sharing-test.php; this suite focuses on the link + notify code
 * paths that the unified modal added on top of it.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight    extensions, autoloader, DB, required tables + classes
 *   links        file public link create / state / password / remove
 *   folderlinks  folder public link create / state / remove
 *   guest        office guest link create / list / validate / revoke
 *   notify       recipient resolution (success / empty / cross-domain) + delivery
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-share-test.php --verbose
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

$runner = new FlowOneTestRunner('drive-share', $argv);

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

    $runner->test('required tables exist', function () use ($runner, &$db) {
        foreach (['drive_files', 'drive_folders', 'office_guest_tokens', 'notifications',
                  'organization_colleagues', 'colleague_groups', 'colleague_group_members'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing");
        }
    });

    $runner->test('share services load', function () use ($runner) {
        $runner->assertTrue(class_exists('\Webmail\Services\DriveService'), 'DriveService missing');
        $runner->assertTrue(class_exists('\Webmail\Services\OfficeGuestLinkService'), 'OfficeGuestLinkService missing');
        $runner->assertTrue(class_exists('\Webmail\Services\ShareNotificationService'), 'ShareNotificationService missing');
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

$drive = new \Webmail\Services\DriveService($config, $owner);
$guest = new \Webmail\Services\OfficeGuestLinkService($config);
$notifier = new \Webmail\Services\ShareNotificationService($config);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
$collab = 'flowone_test_collab@flowone.pro';
$collab2 = 'flowone_test_collab2@flowone.pro';
$crossEmail = 'flowone_test_cross@other.test';
$groupName = 'flowone_test_group_' . bin2hex(random_bytes(3));

$cleanup = function () use ($db) {
    $db->exec("DELETE FROM office_guest_tokens WHERE created_by LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM notifications WHERE type = 'drive_share' AND user_email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM colleague_group_members WHERE added_by LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM colleague_groups WHERE name LIKE 'flowone_test_group_%'");
    $db->exec("DELETE FROM organization_colleagues WHERE email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_files WHERE user_email LIKE 'flowone_test_%' AND original_name LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_folders WHERE user_email LIKE 'flowone_test_%' AND name LIKE 'flowone_test_%'");
};
$cleanup();
$runner->addCleanup($cleanup);

/** Insert a bare drive_files row (DB-only fixture; no bytes on disk). */
$makeFile = function (string $name) use ($db, $owner): int {
    $stmt = $db->prepare('
        INSERT INTO drive_files (user_email, filename, original_name, size, mime_type, is_trashed)
        VALUES (?, ?, ?, 2048, ?, 0)
    ');
    $stmt->execute([
        strtolower($owner),
        'flowone_test_' . bin2hex(random_bytes(8)) . '.docx',
        $name,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    return (int)$db->lastInsertId();
};

/** Insert a bare drive_folders row. */
$makeFolder = function (string $name) use ($db, $owner): int {
    $stmt = $db->prepare('INSERT INTO drive_folders (user_email, name, size) VALUES (?, ?, 0)');
    $stmt->execute([strtolower($owner), $name]);
    return (int)$db->lastInsertId();
};

$fileId = $makeFile('flowone_test_doc.docx');
$folderId = $makeFolder('flowone_test_folder');

// ---------------------------------------------------------------------------
// 2. FILE PUBLIC LINKS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('links')) {
    $runner->section('2. FILE PUBLIC LINKS');

    $runner->test('create file share link (password + download limit)', function () use ($runner, $drive, $owner, $fileId) {
        $token = $drive->createShareLink($owner, $fileId, null, false, 5, 'flowone_test_pw');
        $runner->assertTrue(!empty($token), 'no token returned');
    });

    $runner->test('share-state reflects the active link (getFile + getFileShareInfo)', function () use ($runner, $drive, $owner, $fileId) {
        $file = $drive->getFile($owner, $fileId);
        $runner->assertTrue(!empty($file['share_token']), 'share_token not persisted');
        $runner->assertEquals(5, (int)$file['max_downloads'], 'max_downloads mismatch');
        $runner->assertTrue(!empty($file['share_password']), 'password not stored');

        $info = $drive->getFileShareInfo($file['share_token']);
        $runner->assertTrue(is_array($info), 'public share info missing for token');
    });

    $runner->test('correct password validates, wrong password fails', function () use ($runner, $drive, $owner, $fileId) {
        $token = $drive->getFile($owner, $fileId)['share_token'];
        $runner->assertTrue($drive->validateFileSharePassword($token, 'flowone_test_pw'), 'correct password rejected');
        $runner->assertTrue(!$drive->validateFileSharePassword($token, 'wrong'), 'wrong password accepted');
    });

    $runner->test('share-state for a non-existent file is null (404 source)', function () use ($runner, $drive, $owner) {
        $runner->assertTrue($drive->getFile($owner, 999000111) === null, 'phantom file returned a row');
    });

    $runner->test('remove file share link clears the token', function () use ($runner, $drive, $owner, $fileId) {
        $runner->assertTrue($drive->removeShareLink($owner, $fileId), 'remove failed');
        $file = $drive->getFile($owner, $fileId);
        $runner->assertTrue(empty($file['share_token']), 'share_token still present after removal');
    });
}

// ---------------------------------------------------------------------------
// 3. FOLDER PUBLIC LINKS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('folderlinks')) {
    $runner->section('3. FOLDER PUBLIC LINKS');

    $runner->test('create folder share link', function () use ($runner, $drive, $owner, $folderId) {
        $token = $drive->createFolderShareLink($owner, $folderId, null, null, null);
        $runner->assertTrue(!empty($token), 'no folder token returned');
    });

    $runner->test('share-state reflects the folder link (getFolder)', function () use ($runner, $drive, $owner, $folderId) {
        $folder = $drive->getFolder($owner, $folderId);
        $runner->assertTrue(!empty($folder['share_token']), 'folder share_token not persisted');
    });

    $runner->test('remove folder share link clears the token', function () use ($runner, $drive, $owner, $folderId) {
        $runner->assertTrue($drive->removeFolderShareLink($owner, $folderId), 'folder remove failed');
        $folder = $drive->getFolder($owner, $folderId);
        $runner->assertTrue(empty($folder['share_token']), 'folder token still present after removal');
    });
}

// ---------------------------------------------------------------------------
// 4. OFFICE GUEST LINKS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('guest')) {
    $runner->section('4. OFFICE GUEST LINKS');

    $guestToken = null;

    $runner->test('create an editor guest link', function () use ($runner, $guest, $owner, $fileId, &$guestToken) {
        $link = $guest->createLink($fileId, 'editor', $owner, 168, null);
        $runner->assertTrue(!empty($link['token']), 'no guest token');
        $runner->assertEquals('editor', $link['role'], 'role mismatch');
        $guestToken = $link['token'];
    });

    $runner->test('guest link appears in listLinks', function () use ($runner, $guest, $fileId, &$guestToken) {
        $links = $guest->listLinks($fileId);
        $tokens = array_column($links, 'token');
        $runner->assertTrue(in_array($guestToken, $tokens, true), 'created link not listed');
    });

    $runner->test('validateAndConsume accepts an active link', function () use ($runner, $guest, &$guestToken) {
        $row = $guest->validateAndConsume($guestToken);
        $runner->assertTrue(is_array($row), 'active token rejected');
        $runner->assertEquals('editor', $row['role'], 'consumed role mismatch');
    });

    $runner->test('revokeLink revokes and blocks further use', function () use ($runner, $guest, $owner, &$guestToken) {
        $runner->assertTrue($guest->revokeLink($guestToken, $owner), 'revoke failed');
        $runner->assertTrue($guest->validateAndConsume($guestToken) === null, 'revoked token still valid');
    });

    $runner->test('non-owner cannot revoke a guest link', function () use ($runner, $guest, $fileId, $collab) {
        $link = $guest->createLink($fileId, 'viewer', 'flowone_test_owner@flowone.pro', 24, null);
        $runner->assertTrue(!$guest->revokeLink($link['token'], $collab), 'stranger revoked a link');
    });
}

// ---------------------------------------------------------------------------
// 5. NOTIFY COLLEAGUES / GROUPS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('notify')) {
    $runner->section('5. NOTIFY COLLEAGUES / GROUPS');

    // Colleague + group fixtures (same-domain + one cross-domain).
    $db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Collab')")
        ->execute([$collab]);
    $collabId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Collab2')")
        ->execute([$collab2]);
    $collab2Id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('flowone.pro', ?, 'FlowOne Test Owner')")
        ->execute([$owner]);
    $ownerId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO organization_colleagues (organization_domain, email, display_name) VALUES ('other.test', ?, 'Cross Domain')")
        ->execute([$crossEmail]);
    $crossId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO colleague_groups (organization_domain, name, created_by) VALUES ('flowone.pro', ?, ?)")
        ->execute([$groupName, $owner]);
    $groupId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO colleague_group_members (group_id, colleague_id, added_by) VALUES (?, ?, ?)')
        ->execute([$groupId, $collab2Id, $owner]);

    $runner->test('resolveRecipientEmails resolves colleagues + group members', function () use ($runner, $notifier, $owner, $collabId, $groupId, $collab, $collab2) {
        $emails = $notifier->resolveRecipientEmails($owner, [$collabId], [$groupId]);
        sort($emails);
        $runner->assertTrue(in_array($collab, $emails, true), 'direct colleague missing');
        $runner->assertTrue(in_array($collab2, $emails, true), 'group member missing');
    });

    $runner->test('resolveRecipientEmails excludes the sharer themselves', function () use ($runner, $notifier, $owner, $ownerId, $collabId, $collab) {
        $emails = $notifier->resolveRecipientEmails($owner, [$ownerId, $collabId], []);
        $runner->assertTrue(!in_array($owner, $emails, true), 'sharer was notified about their own share');
        $runner->assertTrue(in_array($collab, $emails, true), 'other colleague dropped');
    });

    $runner->test('empty recipient set resolves to no emails (400 source)', function () use ($runner, $notifier, $owner) {
        $runner->assertEquals(0, count($notifier->resolveRecipientEmails($owner, [], [])), 'empty set produced recipients');
    });

    $runner->test('cross-domain ids are filtered out (no mail relay)', function () use ($runner, $notifier, $owner, $crossId) {
        $runner->assertEquals(0, count($notifier->resolveRecipientEmails($owner, [$crossId], [])), 'cross-domain id leaked through');
    });

    $runner->test('notify() creates a drive_share notification per recipient', function () use ($runner, $notifier, $owner, $collab, $collab2, $db) {
        $sent = $notifier->notify($owner, 'flowone_test_doc.docx', 'https://flowone.pro/api/drive/share/tok', [$collab, $collab2], 'file');
        $runner->assertEquals(2, $sent, 'expected 2 notifications created');

        $count = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE type = 'drive_share' AND user_email LIKE 'flowone_test_%'")->fetchColumn();
        $runner->assertEquals(2, $count, 'notification rows not persisted');
    });

    $runner->test('notify() with no recipients is a no-op', function () use ($runner, $notifier, $owner) {
        $runner->assertEquals(0, $notifier->notify($owner, 'flowone_test_doc.docx', 'https://flowone.pro/x', [], 'file'), 'empty notify created rows');
    });
}

exit($runner->finish());
