#!/usr/bin/env php
<?php
/**
 * FlowOne Guest Call Attachments Test.
 *
 * Covers the in-call chat attachment chain added 2026-06-11:
 *
 *   GuestCallAttachmentService (upload/download/quota/blocklist/cleanup)
 *   + the transcript email integration (attachments + CID images).
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight    extensions, autoloader, DB, table, storage dir, SMTP config
 *   upload       token create + upload roundtrip + checksum + listForRoom
 *   errors       invalid token, expired token, oversize, blocked ext, cross-room
 *   transcript   transcript send with file/image messages (skipped with --skip-send)
 *   cleanup      retention purge of backdated rows + files
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/guest-call-attachments-test.php --verbose --skip-send
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            skip the live transcript email send
 *   --email=ADDR           transcript recipient (required for live send test)
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * All test data uses the flowone_test_ prefix and is removed in cleanup
 * handlers that run even on failure or SIGINT. Idempotent.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('guest-call-attachments', $argv);

$transcriptEmail = '';
foreach ($runner->extra as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $transcriptEmail = trim(substr($arg, 8));
    }
}

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
$config = null;
$db = null;
$service = null;

if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php pdo_mysql extension loaded', function () use ($runner) {
        $runner->assertTrue(extension_loaded('pdo_mysql'), 'pdo_mysql extension missing');
    });

    $runner->test('php fileinfo extension loaded', function () use ($runner) {
        $runner->assertTrue(extension_loaded('fileinfo'), 'fileinfo extension missing (MIME detection)');
    });

    $runner->test('cron bootstrap + config load', function () use ($runner, &$config) {
        require_once __DIR__ . '/../cron/bootstrap.php';
        $config = require __DIR__ . '/../src/config.php';
        $runner->assertTrue(is_array($config), 'config.php did not return an array');
    });

    $runner->test('database reachable', function () use ($runner, &$config, &$db) {
        $runner->assertTrue(is_array($config), 'config not loaded');
        $db = \Webmail\Core\Database::getConnection($config);
        $runner->assertEquals('1', (string)$db->query('SELECT 1')->fetchColumn(), 'SELECT 1 failed');
    });

    $runner->test('guest_call_attachments table exists (service auto-creates)', function () use ($runner, &$config, &$db, &$service) {
        $service = new \Webmail\Services\GuestCallAttachmentService($config);
        $count = $db->query("SHOW TABLES LIKE 'guest_call_attachments'")->rowCount();
        $runner->assertEquals(1, $count, 'guest_call_attachments table missing after service init');
    });

    $runner->test('guest_call_tokens table exists', function () use ($runner, &$db) {
        $count = $db->query("SHOW TABLES LIKE 'guest_call_tokens'")->rowCount();
        $runner->assertEquals(1, $count, 'guest_call_tokens table missing');
    });

    $runner->test('attachment storage dir writable', function () use ($runner, &$service) {
        $base = $service->baseDir();
        if (!is_dir($base)) {
            $runner->assertTrue(@mkdir($base, 0755, true), "cannot create $base");
        }
        $probe = $base . '/flowone_test_probe_' . getmypid();
        $runner->assertTrue(@file_put_contents($probe, 'x') !== false, "$base not writable");
        @unlink($probe);
    });

    $runner->test('storage disk space > 200MB', function () use ($runner, &$service) {
        $free = @disk_free_space($service->baseDir()) ?: @disk_free_space(sys_get_temp_dir());
        $runner->assertTrue($free !== false && $free > 200 * 1024 * 1024, 'less than 200MB free');
    });

    $runner->test('smtp config present', function () use ($runner, &$config) {
        $smtp = $config['smtp'] ?? [];
        $runner->assertTrue(!empty($smtp['host'] ?? ''), 'smtp.host missing from config');
        if (empty($smtp['username']) || empty($smtp['password'])) {
            return 'warn'; // noreply fallback unusable; user-credential path may still work
        }
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
if ($service === null) {
    $service = new \Webmail\Services\GuestCallAttachmentService($config);
}

// ---------------------------------------------------------------------------
// Test fixtures (flowone_test_ prefix everywhere) + signal-safe cleanup
// ---------------------------------------------------------------------------
$suffix = bin2hex(random_bytes(6));
$testRoom = 'flowone_test_room_' . $suffix;
$otherRoom = 'flowone_test_room2_' . $suffix;
$testToken = 'flowone_test_tok_' . $suffix;
$otherToken = 'flowone_test_tok2_' . $suffix;
$expiredToken = 'flowone_test_exp_' . $suffix;
$tempFiles = [];

$runner->addCleanup(function () use ($db, $service, $testRoom, $otherRoom, &$tempFiles) {
    foreach ([$testRoom, $otherRoom] as $room) {
        $stmt = $db->prepare('SELECT id FROM guest_call_attachments WHERE room_name = ?');
        $stmt->execute([$room]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $service->deleteAttachment((int)$id);
        }
    }
    $db->prepare("DELETE FROM guest_call_tokens WHERE token LIKE 'flowone_test_%'")->execute();
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
});

/** Insert a guest_call_tokens row for testing. */
$insertToken = function (string $token, string $room, string $expiresAt) use ($db) {
    $stmt = $db->prepare("
        INSERT INTO guest_call_tokens (token, room_name, created_by, expires_at, status)
        VALUES (?, ?, 'flowone_test@flowone.pro', ?, 'active')
        ON DUPLICATE KEY UPDATE room_name = VALUES(room_name), expires_at = VALUES(expires_at), status = 'active'
    ");
    $stmt->execute([$token, $room, $expiresAt]);
};

/** Create a temp file with given content; returns the $_FILES-style array. */
$makeUpload = function (string $name, string $content, ?int $declaredSize = null) use (&$tempFiles): array {
    $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_');
    file_put_contents($tmp, $content);
    $tempFiles[] = $tmp;
    return [
        'name' => $name,
        'tmp_name' => $tmp,
        'size' => $declaredSize ?? strlen($content),
        'error' => 0,
        'type' => 'application/octet-stream',
    ];
};

// 1x1 transparent PNG (valid image for MIME detection + CID embed)
$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
);

$uploadedTextId = 0;
$uploadedImageId = 0;

// ---------------------------------------------------------------------------
// 2. UPLOAD
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('upload')) {
    $runner->section('2. UPLOAD');

    $runner->test('create test tokens', function () use ($runner, $insertToken, $testToken, $otherToken, $testRoom, $otherRoom, $db) {
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $insertToken($testToken, $testRoom, $future);
        $insertToken($otherToken, $otherRoom, $future);
        $stmt = $db->prepare('SELECT COUNT(*) FROM guest_call_tokens WHERE token IN (?, ?)');
        $stmt->execute([$testToken, $otherToken]);
        $runner->assertEquals(2, (int)$stmt->fetchColumn(), 'test tokens not inserted');
    });

    $runner->test('validateToken accepts active token', function () use ($runner, $service, $testToken, $testRoom) {
        $row = $service->validateToken($testToken);
        $runner->assertTrue($row !== null, 'active token rejected');
        $runner->assertEquals($testRoom, $row['room_name'], 'wrong room resolved');
    });

    $runner->test('upload text file roundtrip', function () use ($runner, $service, $testToken, $makeUpload, &$uploadedTextId) {
        $content = "[FLOWONE-TEST] attachment body " . str_repeat('x', 2048);
        $res = $service->upload($testToken, $makeUpload('flowone_test_notes.txt', $content), 'FlowOne Test');
        $runner->assertTrue(!empty($res['success']), 'upload failed: ' . ($res['error'] ?? '?'));
        $uploadedTextId = (int)$res['attachment']['id'];
        $runner->assertTrue($uploadedTextId > 0, 'no attachment id returned');
        $runner->assertEquals('flowone_test_notes.txt', $res['attachment']['name'], 'name mangled');
        $runner->assertEquals(false, $res['attachment']['is_image'], 'txt flagged as image');
    });

    $runner->test('db row matches upload', function () use ($runner, $service, &$uploadedTextId, $testRoom) {
        $row = $service->getAttachmentRow($uploadedTextId);
        $runner->assertTrue($row !== null, 'row missing');
        $runner->assertEquals($testRoom, $row['room_name'], 'room mismatch');
        $runner->assertTrue((int)$row['size_bytes'] > 2000, 'size not recorded');
        $runner->assertEquals('FlowOne Test', $row['uploaded_by'], 'uploaded_by not recorded');
    });

    $runner->test('download checksum matches uploaded content', function () use ($runner, $service, $testToken, &$uploadedTextId) {
        $content = "[FLOWONE-TEST] attachment body " . str_repeat('x', 2048);
        $res = $service->resolveForDownload($testToken, $uploadedTextId);
        $runner->assertTrue(!empty($res['success']), 'resolve failed: ' . ($res['error'] ?? '?'));
        $runner->assertEquals(hash('sha256', $content), hash_file('sha256', $res['path']), 'checksum mismatch');
        $runner->assertEquals('flowone_test_notes.txt', $res['name'], 'download name mismatch');
    });

    $runner->test('upload png detected as image', function () use ($runner, $service, $testToken, $makeUpload, $pngBytes, &$uploadedImageId) {
        $res = $service->upload($testToken, $makeUpload('flowone_test_pixel.png', $pngBytes), 'FlowOne Test');
        $runner->assertTrue(!empty($res['success']), 'png upload failed: ' . ($res['error'] ?? '?'));
        $runner->assertEquals(true, $res['attachment']['is_image'], 'png not detected as image');
        $runner->assertEquals('image/png', $res['attachment']['mime'], 'server-side MIME detection failed');
        $uploadedImageId = (int)$res['attachment']['id'];
    });

    $runner->test('listForRoom returns both uploads with live paths', function () use ($runner, $service, $testRoom) {
        $list = $service->listForRoom($testRoom);
        $runner->assertEquals(2, count($list), 'expected 2 attachments in room');
        foreach ($list as $att) {
            $runner->assertTrue(is_file($att['path']), 'listed path missing: ' . $att['path']);
        }
    });
}

// ---------------------------------------------------------------------------
// 3. ERRORS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('errors')) {
    $runner->section('3. ERRORS');

    $runner->test('invalid token rejected', function () use ($runner, $service, $makeUpload) {
        $res = $service->upload('flowone_test_does_not_exist', $makeUpload('flowone_test_a.txt', 'x'), '');
        $runner->assertEquals('invalid_token', $res['code'] ?? '', 'invalid token accepted');
    });

    $runner->test('expired token rejected', function () use ($runner, $service, $insertToken, $expiredToken, $makeUpload) {
        $insertToken($expiredToken, 'flowone_test_room_expired', gmdate('Y-m-d H:i:s', time() - 3600));
        $res = $service->upload($expiredToken, $makeUpload('flowone_test_b.txt', 'x'), '');
        $runner->assertEquals('invalid_token', $res['code'] ?? '', 'expired token accepted');
    });

    $runner->test('oversize file rejected (declared size)', function () use ($runner, $service, $testToken, $insertToken, $testRoom, $makeUpload) {
        $insertToken($testToken, $testRoom, gmdate('Y-m-d H:i:s', time() + 3600));
        $res = $service->upload($testToken, $makeUpload('flowone_test_big.bin', 'x', 26 * 1024 * 1024), '');
        $runner->assertEquals('too_large', $res['code'] ?? '', 'oversize file accepted');
    });

    $runner->test('blocked extension rejected (.php)', function () use ($runner, $service, $testToken, $makeUpload) {
        $res = $service->upload($testToken, $makeUpload('flowone_test_shell.php', '<?php echo 1;'), '');
        $runner->assertEquals('blocked_type', $res['code'] ?? '', '.php accepted');
    });

    $runner->test('double extension rejected (.php.jpg)', function () use ($runner, $service, $testToken, $makeUpload) {
        $res = $service->upload($testToken, $makeUpload('flowone_test_shell.php.jpg', 'GIF89a'), '');
        $runner->assertEquals('blocked_type', $res['code'] ?? '', '.php.jpg accepted');
    });

    $runner->test('cross-room download denied', function () use ($runner, $service, $otherToken, $testToken, $makeUpload) {
        $up = $service->upload($testToken, $makeUpload('flowone_test_priv.txt', 'secret'), '');
        $runner->assertTrue(!empty($up['success']), 'setup upload failed');
        $res = $service->resolveForDownload($otherToken, (int)$up['attachment']['id']);
        $runner->assertEquals('not_found', $res['code'] ?? '', 'cross-room download allowed');
    });

    $runner->test('download with invalid token denied', function () use ($runner, $service, &$uploadedTextId) {
        $res = $service->resolveForDownload('flowone_test_nope', max(1, $uploadedTextId));
        $runner->assertEquals('invalid_token', $res['code'] ?? '', 'invalid token download allowed');
    });
}

// ---------------------------------------------------------------------------
// 4. TRANSCRIPT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('transcript')) {
    $runner->section('4. TRANSCRIPT');

    $runner->test('transcript send with file + image messages', function () use ($runner, $config, $db, $service, $testToken, $testRoom, &$uploadedTextId, &$uploadedImageId, $transcriptEmail) {
        if ($runner->skipSend) {
            $runner->log('          (skipped: --skip-send)');
            return 'skip';
        }
        if ($transcriptEmail === '') {
            $runner->log('          (skipped: pass --email=you@flowone.pro to test the live send)');
            return 'skip';
        }
        if (!$uploadedTextId || !$uploadedImageId) {
            throw new \RuntimeException('upload section must run first (no test attachments)');
        }
        // Point the test token at the recipient, then exercise the real path
        $db->prepare('UPDATE guest_call_tokens SET created_by = ? WHERE token = ?')
            ->execute([$transcriptEmail, $testToken]);

        $gcs = new \Webmail\Services\GuestCallService($config);
        $messages = [
            ['sender' => 'FlowOne Test', 'identity' => 'flowone_test_id', 'message' => '[FLOWONE-TEST] transcript with attachments', 'ts' => time() * 1000],
            ['sender' => 'FlowOne Test', 'identity' => 'flowone_test_id', 'message' => '', 'ts' => time() * 1000,
             'isFile' => true, 'isImage' => false, 'attachmentId' => $uploadedTextId, 'name' => 'flowone_test_notes.txt', 'mime' => 'text/plain', 'size' => 2080],
            ['sender' => 'FlowOne Test', 'identity' => 'flowone_test_id', 'message' => '', 'ts' => time() * 1000,
             'isFile' => true, 'isImage' => true, 'attachmentId' => $uploadedImageId, 'name' => 'flowone_test_pixel.png', 'mime' => 'image/png', 'size' => 68],
        ];
        $res = $gcs->sendTranscript($testToken, $messages, 65);
        $runner->assertTrue(!empty($res['success']), 'transcript send failed: ' . ($res['error'] ?? '?'));
    }, 60);

    $runner->test('sendTranscript rejects unknown token (no email sent)', function () use ($runner, $config) {
        $gcs = new \Webmail\Services\GuestCallService($config);
        $res = $gcs->sendTranscript(
            'flowone_test_unknown_token',
            [['sender' => 'FlowOne Test', 'message' => '[FLOWONE-TEST]', 'ts' => time() * 1000]],
            5
        );
        $runner->assertTrue(isset($res['error']), 'unknown token did not error');
    });
}

// ---------------------------------------------------------------------------
// 5. CLEANUP / RETENTION
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('cleanup')) {
    $runner->section('5. CLEANUP / RETENTION');

    $runner->test('cleanupOlderThan purges backdated rows + files', function () use ($runner, $service, $db, $testToken, $insertToken, $testRoom, $makeUpload) {
        $insertToken($testToken, $testRoom, gmdate('Y-m-d H:i:s', time() + 3600));
        $up = $service->upload($testToken, $makeUpload('flowone_test_old.txt', 'old data'), '');
        $runner->assertTrue(!empty($up['success']), 'setup upload failed');
        $id = (int)$up['attachment']['id'];

        $resolved = $service->resolveForDownload($testToken, $id);
        $path = $resolved['path'] ?? '';
        $runner->assertTrue($path !== '' && is_file($path), 'file missing before purge');

        $db->prepare('UPDATE guest_call_attachments SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE id = ?')
            ->execute([$id]);

        $deleted = $service->cleanupOlderThan(7);
        $runner->assertTrue($deleted >= 1, 'cleanup deleted nothing');
        $runner->assertTrue(!is_file($path), 'file survived purge');
        $runner->assertTrue($service->getAttachmentRow($id) === null, 'row survived purge');
    });

    $runner->test('cleanupOlderThan is idempotent', function () use ($runner, $service) {
        $first = $service->cleanupOlderThan(7);
        $second = $service->cleanupOlderThan(7);
        $runner->assertEquals(0, $second, "second run deleted $second rows (first: $first)");
    });
}

exit($runner->finish());
