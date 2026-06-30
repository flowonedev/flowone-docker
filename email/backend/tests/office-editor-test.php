#!/usr/bin/env php
<?php
/**
 * FlowOne OnlyOffice Integration Test.
 *
 * Verifies the full OnlyOffice editor chain: Document Server availability,
 * settings resolution (office-config.json), editor config signing (JWT),
 * doc key lifecycle (create / stable / rotate), signed file tokens,
 * simulated save callback writing back to Drive, blank file creation, and
 * guest share link lifecycle.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   extensions, autoloader, DB, tables, office settings, DS healthcheck
 *   tokens      signed file token mint/verify/expiry/scope, JWT config signing
 *   dockey      doc key create + stability + staleness rotation
 *   files       blank docx/xlsx/pptx creation into Drive + config build
 *   rename      filename normalization (extension preserved) + live title push
 *   callback    simulated DS save callback (download + updateFileContent + key rotation)
 *   guest       guest link create / validate / expire / revoke
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/office-editor-test.php --verbose
 *
 * Flags:
 *   --verbose              extra debug output
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --skip-send            skip the live Document Server healthcheck + callback
 *                          group (use when DS is not installed yet)
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * All test rows use flowone_test_ prefixes / the flowone_test@flowone.pro
 * user and are removed in cleanup handlers that run even on failure or
 * SIGINT. Idempotent.
 *
 * Exit code: 0 on all PASS / WARN, 1 on any FAIL.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/lib/test-runner.php';

$runner = new FlowOneTestRunner('office-editor', $argv);

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
$config = null;
$db = null;
$office = null;
$settings = null;

if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (pdo_mysql, curl, openssl, zip)', function () use ($runner) {
        foreach (['pdo_mysql', 'curl', 'openssl', 'zip'] as $ext) {
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

    $runner->test('office tables exist (migration 189)', function () use ($runner, &$db) {
        foreach (['office_editor_keys', 'office_guest_tokens'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing - run migration 189_office_editor.sql");
        }
    });

    $runner->test('phpoffice libraries available', function () use ($runner) {
        $runner->assertTrue(class_exists('\PhpOffice\PhpWord\PhpWord'), 'phpoffice/phpword missing');
        $runner->assertTrue(class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet'), 'phpoffice/phpspreadsheet missing');
        $runner->assertTrue(class_exists('\PhpOffice\PhpPresentation\PhpPresentation'), 'phpoffice/phppresentation missing');
        $runner->assertTrue(class_exists('\Firebase\JWT\JWT'), 'firebase/php-jwt missing');
    });

    $runner->test('office settings resolved (office-config.json or config.php)', function () use ($runner, &$config, &$office, &$settings) {
        $office = new \Webmail\Services\OfficeEditorService($config);
        $settings = $office->getSettings();
        $runner->log('          server_url=' . ($settings['server_url'] ?: '(empty)')
            . ' internal_url=' . $settings['internal_url']
            . ' enabled=' . ($settings['enabled'] ? 'yes' : 'no'));
        if (!$office->isEnabled()) {
            return 'warn'; // Installer not run yet - integration code still testable
        }
    });

    $runner->test('document server healthcheck', function () use ($runner, &$office, &$settings) {
        if ($runner->skipSend) {
            $runner->log('          skipped (--skip-send)');
            return 'warn';
        }
        if (!$office->isEnabled()) {
            $runner->log('          office not configured - skipping');
            return 'warn';
        }
        $ch = curl_init($settings['internal_url'] . '/healthcheck');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $runner->assertTrue($code === 200 && stripos((string)$body, 'true') !== false,
            "healthcheck failed (http={$code}, body=" . substr((string)$body, 0, 100) . ')');
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
if ($office === null) {
    $office = new \Webmail\Services\OfficeEditorService($config);
    $settings = $office->getSettings();
}

$testUser = 'flowone_test@flowone.pro';
$driveService = new \Webmail\Services\DriveService($config, $testUser);
$guestLinks = new \Webmail\Services\OfficeGuestLinkService($config);

// Fake file ids far outside real ranges to avoid collisions; doc-key tests
// use rows keyed by these ids and cleanup removes them.
$fakeFileIdBase = 900000000 + random_int(0, 99999);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
$createdDriveFileIds = [];

$runner->addCleanup(function () use ($db, $testUser, &$createdDriveFileIds, $fakeFileIdBase, $config) {
    // Guest tokens + editor keys from this (and any previous failed) run
    $db->prepare('DELETE FROM office_guest_tokens WHERE created_by = ?')->execute([$testUser]);
    $db->prepare('DELETE FROM office_editor_keys WHERE file_id >= 900000000')->execute();

    // Drive rows + bytes created by the files/callback groups
    try {
        $driveService = new \Webmail\Services\DriveService($config, $testUser);
        $stmt = $db->prepare("SELECT id FROM drive_files WHERE user_email = ? AND original_name LIKE 'flowone_test_%'");
        $stmt->execute([$testUser]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $fid) {
            $driveService->permanentlyDeleteFile($testUser, (int)$fid);
        }
    } catch (\Throwable $e) {
        // Fallback: at least remove the DB rows
        $db->prepare("DELETE FROM drive_files WHERE user_email = ? AND original_name LIKE 'flowone_test_%'")
            ->execute([$testUser]);
    }
    $db->prepare('DELETE FROM office_editor_keys WHERE file_id IN (SELECT id FROM drive_files WHERE user_email = ?)')
        ->execute([$testUser]);
});

// ---------------------------------------------------------------------------
// 2. TOKENS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('tokens')) {
    $runner->section('2. TOKENS');

    $runner->test('file token mint + verify roundtrip', function () use ($runner, $office, $fakeFileIdBase) {
        $token = $office->mintFileToken($fakeFileIdBase, 'content', 300);
        $runner->assertTrue($office->verifyFileToken($token, $fakeFileIdBase, 'content'), 'valid token rejected');
    });

    $runner->test('file token rejects wrong file id', function () use ($runner, $office, $fakeFileIdBase) {
        $token = $office->mintFileToken($fakeFileIdBase, 'content', 300);
        $runner->assertTrue(!$office->verifyFileToken($token, $fakeFileIdBase + 1, 'content'), 'token accepted for wrong file');
    });

    $runner->test('file token rejects wrong scope', function () use ($runner, $office, $fakeFileIdBase) {
        $token = $office->mintFileToken($fakeFileIdBase, 'content', 300);
        $runner->assertTrue(!$office->verifyFileToken($token, $fakeFileIdBase, 'callback'), 'content token accepted as callback');
    });

    $runner->test('file token rejects tampering', function () use ($runner, $office, $fakeFileIdBase) {
        $token = $office->mintFileToken($fakeFileIdBase, 'content', 300);
        $tampered = substr($token, 0, -4) . 'AAAA';
        $runner->assertTrue(!$office->verifyFileToken($tampered, $fakeFileIdBase, 'content'), 'tampered token accepted');
    });

    $runner->test('editor config JWT signs and decodes with shared secret', function () use ($runner, $office, $settings, $fakeFileIdBase) {
        if (($settings['jwt_secret'] ?? '') === '') {
            $runner->log('          office jwt_secret not configured - skipping');
            return 'warn';
        }
        $file = [
            'id' => $fakeFileIdBase,
            'original_name' => 'flowone_test_doc.docx',
            'folder_id' => null,
            'current_version' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $cfg = $office->buildEditorConfig($file, 'editor', ['id' => 'flowone_test@flowone.pro', 'name' => 'Test'], 'en');
        $runner->assertTrue(!empty($cfg['token']), 'config token missing');
        $decoded = json_decode((string)json_encode(
            \Firebase\JWT\JWT::decode($cfg['token'], new \Firebase\JWT\Key($settings['jwt_secret'], 'HS256'))
        ), true);
        $runner->assertEquals('docx', $decoded['document']['fileType'] ?? '', 'decoded fileType mismatch');
        $runner->assertEquals('edit', $decoded['editorConfig']['mode'] ?? '', 'decoded mode mismatch');
        $runner->assertTrue(str_contains($decoded['document']['url'] ?? '', '/office/files/'), 'document url malformed');
    });

    $runner->test('viewer role builds view-mode config without edit permission', function () use ($runner, $office, $settings, $fakeFileIdBase) {
        if (($settings['jwt_secret'] ?? '') === '') {
            return 'warn';
        }
        $file = ['id' => $fakeFileIdBase, 'original_name' => 'flowone_test_doc.xlsx', 'folder_id' => null, 'current_version' => 1, 'updated_at' => date('Y-m-d H:i:s')];
        $cfg = $office->buildEditorConfig($file, 'viewer', ['id' => 'g', 'name' => 'Guest'], 'en');
        $runner->assertEquals('view', $cfg['editorConfig']['mode'], 'viewer did not get view mode');
        $runner->assertEquals(false, $cfg['document']['permissions']['edit'], 'viewer got edit permission');
        $runner->assertEquals('cell', $cfg['documentType'], 'xlsx should map to cell editor');
    });
}

// ---------------------------------------------------------------------------
// 3. DOC KEYS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('dockey')) {
    $runner->section('3. DOC KEYS');

    $fileRow = fn(int $id, int $version, string $updated) => [
        'id' => $id, 'original_name' => 'flowone_test_key.docx',
        'current_version' => $version, 'updated_at' => $updated, 'folder_id' => null,
    ];
    $keyFileId = $fakeFileIdBase + 10;
    $now = date('Y-m-d H:i:s');

    $runner->test('doc key created and stable across repeat opens', function () use ($runner, $office, $fileRow, $keyFileId, $now) {
        $k1 = $office->getOrCreateDocKey($fileRow($keyFileId, 1, $now));
        $k2 = $office->getOrCreateDocKey($fileRow($keyFileId, 1, $now));
        $runner->assertTrue($k1 !== '', 'empty key');
        $runner->assertEquals($k1, $k2, 'key changed between identical opens (would split co-editing sessions)');
        $runner->assertTrue($office->keyMatchesFile($k1, $keyFileId), 'key prefix does not match file');
    });

    $runner->test('doc key rotates when file version changes externally', function () use ($runner, $office, $fileRow, $keyFileId, $now) {
        $k1 = $office->getOrCreateDocKey($fileRow($keyFileId, 1, $now));
        $k2 = $office->getOrCreateDocKey($fileRow($keyFileId, 2, $now));
        $runner->assertTrue($k1 !== $k2, 'key not rotated after external version bump (DS would serve stale cache)');
    });

    $runner->test('rotateDocKey issues a fresh key', function () use ($runner, $office, $fileRow, $keyFileId, $now) {
        $before = $office->getOrCreateDocKey($fileRow($keyFileId, 2, $now));
        $office->rotateDocKey($keyFileId, $fileRow($keyFileId, 3, $now));
        $after = $office->getOrCreateDocKey($fileRow($keyFileId, 3, $now));
        $runner->assertTrue($before !== $after, 'rotateDocKey did not change the key');
    });
}

// ---------------------------------------------------------------------------
// 4. FILES (blank creation into Drive)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('files')) {
    $runner->section('4. FILES');

    foreach (['docx', 'xlsx', 'pptx'] as $type) {
        $runner->test("create blank {$type} in Drive", function () use ($runner, $office, $driveService, $testUser, $type, &$createdDriveFileIds) {
            $file = $office->createBlankFile($driveService, $testUser, $type, 'flowone_test_blank_' . $type, null);
            $runner->assertTrue(is_array($file) && !empty($file['id']), "createBlankFile({$type}) returned null");
            $createdDriveFileIds[] = (int)$file['id'];
            $runner->assertTrue((int)$file['size'] > 0, 'created file is empty');
            $withPath = $driveService->getFileByIdWithPath((int)$file['id']);
            $runner->assertTrue($withPath !== null && file_exists($withPath['storage_path']), 'file bytes missing on disk');
            // OOXML files are ZIP containers - verify magic bytes
            $head = (string)file_get_contents($withPath['storage_path'], false, null, 0, 2);
            $runner->assertEquals('PK', $head, "{$type} is not a valid OOXML/zip container");
        }, 60);
    }

    $runner->test('editor config builds for a real Drive file', function () use ($runner, $office, $driveService, $settings, &$createdDriveFileIds) {
        if (($settings['jwt_secret'] ?? '') === '') {
            return 'warn';
        }
        $runner->assertTrue(count($createdDriveFileIds) > 0, 'no created files to test against');
        $file = $driveService->getFileByIdWithPath($createdDriveFileIds[0]);
        $cfg = $office->buildEditorConfig($file, 'editor', ['id' => 'flowone_test@flowone.pro', 'name' => 'Test'], 'en');
        $runner->assertTrue(!empty($cfg['document']['key']), 'no doc key in config');
        $runner->assertTrue(!empty($cfg['token']), 'config not signed');
    });
}

// ---------------------------------------------------------------------------
// 5. RENAME (filename normalization + live title push)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('rename')) {
    $runner->section('5. RENAME');

    $docx = ['id' => $fakeFileIdBase + 30, 'original_name' => 'flowone_test_report.docx'];

    $runner->test('rename strips a wrong extension and re-applies the real one', function () use ($runner, $docx) {
        $out = \Webmail\Services\OfficeEditorService::normalizeRenameTarget('Budget.txt', $docx);
        $runner->assertEquals('Budget.docx', $out, 'extension not preserved');
    });

    $runner->test('rename adds the extension when none is supplied', function () use ($runner, $docx) {
        $out = \Webmail\Services\OfficeEditorService::normalizeRenameTarget('Budget', $docx);
        $runner->assertEquals('Budget.docx', $out, 'missing extension not added');
    });

    $runner->test('rename keeps internal dots, replaces only the last segment', function () use ($runner, $docx) {
        $out = \Webmail\Services\OfficeEditorService::normalizeRenameTarget('Q3.final.pdf', $docx);
        $runner->assertEquals('Q3.final.docx', $out, 'internal dots mishandled');
    });

    $runner->test('rename strips path separators and null bytes (no directory escape)', function () use ($runner, $docx) {
        $out = \Webmail\Services\OfficeEditorService::normalizeRenameTarget("../etc/pa\0sswd", $docx);
        $runner->assertTrue(strpos($out, '/') === false && strpos($out, '\\') === false && strpos($out, "\0") === false,
            "path separators survived: {$out}");
        $runner->assertEquals('..etcpasswd.docx', $out, 'unexpected sanitized result');
    });

    $runner->test('rename rejects an empty / whitespace name', function () use ($runner, $docx) {
        $runner->assertEquals('', \Webmail\Services\OfficeEditorService::normalizeRenameTarget('   ', $docx), 'blank name accepted');
        $runner->assertEquals('', \Webmail\Services\OfficeEditorService::normalizeRenameTarget('.docx', $docx), 'extension-only name accepted');
    });

    $runner->test('updateDocumentTitle is a safe no-op without a live session key', function () use ($runner, $office, $db, $fakeFileIdBase) {
        $noKeyId = $fakeFileIdBase + 31;
        // Ensure there is no key row for this file.
        $db->prepare('DELETE FROM office_editor_keys WHERE file_id = ?')->execute([$noKeyId]);
        $ok = $office->updateDocumentTitle(['id' => $noKeyId, 'original_name' => 'flowone_test_nokey.docx']);
        $runner->assertTrue($ok === false, 'expected false (no session) without throwing');
    });

    $runner->test('updateDocumentTitle reaches the command service for a known key', function () use ($runner, $office, $db, $fakeFileIdBase, $settings) {
        if ($runner->skipSend) {
            $runner->log('          skipped (--skip-send)');
            return 'warn';
        }
        if (!$office->isEnabled()) {
            $runner->log('          office not configured - skipping');
            return 'warn';
        }
        // Seed a key row so the method has a key to send; there is no live
        // editing session, so the DS replies "key not found" (error 1) and the
        // method returns false. The point is that the call signs + reaches DS
        // without throwing - it must never crash a rename.
        $seedId = $fakeFileIdBase + 32;
        $office->getOrCreateDocKey(['id' => $seedId, 'original_name' => 'flowone_test_seed.docx', 'current_version' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $ok = $office->updateDocumentTitle(['id' => $seedId, 'original_name' => 'flowone_test_renamed.docx']);
        $db->prepare('DELETE FROM office_editor_keys WHERE file_id = ?')->execute([$seedId]);
        $runner->assertTrue(is_bool($ok), 'updateDocumentTitle should return a bool');
        $runner->log('          command service returned ' . ($ok ? 'success' : 'no-live-session (expected)'));
    });
}

// ---------------------------------------------------------------------------
// 6. CALLBACK (simulated DS save)
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('callback')) {
    $runner->section('6. CALLBACK');

    if ($runner->skipSend) {
        $runner->test('simulated save callback', function () use ($runner) {
            $runner->log('          skipped (--skip-send)');
            return 'warn';
        });
    } else {
        $runner->test('save callback downloads + writes back to Drive + rotates key', function () use ($runner, $office, $driveService, $testUser, &$createdDriveFileIds) {
            // Create a source file in Drive
            $file = $office->createBlankFile($driveService, $testUser, 'docx', 'flowone_test_callback', null);
            $runner->assertTrue(is_array($file), 'fixture file creation failed');
            $fileId = (int)$file['id'];
            $createdDriveFileIds[] = $fileId;
            $keyBefore = $office->getOrCreateDocKey($file);

            // Build a fake "edited" docx and serve it over a throwaway local
            // HTTP server (the service only accepts http/https URLs, exactly
            // like the real Document Server cache URLs).
            $serveDir = sys_get_temp_dir() . '/flowone_test_office_' . bin2hex(random_bytes(4));
            mkdir($serveDir, 0700, true);
            $edited = $serveDir . '/edited.docx';
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText('FLOWONE-TEST edited content ' . date('c'));
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($edited);

            $port = random_int(18900, 18999);
            $proc = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $serveDir],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
            $runner->assertTrue(is_resource($proc), 'could not start local fixture HTTP server');

            try {
                // Wait for the built-in server to accept connections
                $up = false;
                for ($i = 0; $i < 20; $i++) {
                    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
                    if ($sock) { fclose($sock); $up = true; break; }
                    usleep(150000);
                }
                $runner->assertTrue($up, 'fixture HTTP server did not come up');

                $payload = [
                    'key' => $keyBefore,
                    'status' => 2,
                    'url' => "http://127.0.0.1:{$port}/edited.docx",
                    'users' => [$testUser],
                ];
                $result = $office->handleCallback($fileId, $payload, $driveService);
                $runner->assertEquals(0, $result['error'] ?? -1, 'callback returned error');

                $fresh = $driveService->getFileByIdWithPath($fileId);
                $runner->assertEquals(2, (int)$fresh['current_version'], 'file version did not bump after save');
                $runner->assertTrue((int)$fresh['size'] !== (int)$file['size'], 'file size unchanged - content not replaced');

                $keyAfter = $office->getOrCreateDocKey($fresh);
                $runner->assertTrue($keyAfter !== $keyBefore, 'doc key not rotated after final save');
            } finally {
                proc_terminate($proc);
                proc_close($proc);
                @unlink($edited);
                @rmdir($serveDir);
            }
        }, 60);

        $runner->test('callback rejects key from a different file', function () use ($runner, $office, $driveService, $fakeFileIdBase) {
            $result = $office->handleCallback($fakeFileIdBase + 50, [
                'key' => 'f12345-deadbeef', 'status' => 2, 'url' => 'http://127.0.0.1/none',
            ], $driveService);
            $runner->assertEquals(1, $result['error'] ?? 0, 'mismatched key accepted');
        });

        $runner->test('callback status 1 (editing) is a no-op success', function () use ($runner, $office, $driveService, &$createdDriveFileIds) {
            $runner->assertTrue(count($createdDriveFileIds) > 0, 'no fixture file');
            $fileId = $createdDriveFileIds[count($createdDriveFileIds) - 1];
            $result = $office->handleCallback($fileId, ['key' => 'f' . $fileId . '-abc', 'status' => 1], $driveService);
            $runner->assertEquals(0, $result['error'] ?? -1, 'editing status not accepted');
        });
    }
}

// ---------------------------------------------------------------------------
// 7. GUEST LINKS
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('guest')) {
    $runner->section('7. GUEST LINKS');

    $guestFileId = $fakeFileIdBase + 20;

    $runner->test('create + validate guest link (editor role)', function () use ($runner, $guestLinks, $guestFileId, $testUser) {
        $link = $guestLinks->createLink($guestFileId, 'editor', $testUser, 24, 'flowone_test_link');
        $runner->assertTrue(!empty($link['token']), 'no token returned');
        $row = $guestLinks->validateAndConsume($link['token']);
        $runner->assertTrue($row !== null, 'fresh link failed validation');
        $runner->assertEquals('editor', $row['role'], 'role mismatch');
        $runner->assertEquals($guestFileId, (int)$row['file_id'], 'file id mismatch');
    });

    $runner->test('validation bumps use_count', function () use ($runner, $guestLinks, $guestFileId, $testUser, $db) {
        $link = $guestLinks->createLink($guestFileId, 'viewer', $testUser, 24, 'flowone_test_count');
        $guestLinks->validateAndConsume($link['token']);
        $guestLinks->validateAndConsume($link['token']);
        $stmt = $db->prepare('SELECT use_count FROM office_guest_tokens WHERE token = ?');
        $stmt->execute([$link['token']]);
        $runner->assertEquals(2, (int)$stmt->fetchColumn(), 'use_count not incremented');
    });

    $runner->test('expired link rejected', function () use ($runner, $guestLinks, $guestFileId, $testUser, $db) {
        $link = $guestLinks->createLink($guestFileId, 'viewer', $testUser, 24, 'flowone_test_expired');
        $db->prepare('UPDATE office_guest_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE token = ?')
            ->execute([$link['token']]);
        $runner->assertTrue($guestLinks->validateAndConsume($link['token']) === null, 'expired link accepted');
    });

    $runner->test('revoked link rejected + revoke requires creator', function () use ($runner, $guestLinks, $guestFileId, $testUser) {
        $link = $guestLinks->createLink($guestFileId, 'viewer', $testUser, 24, 'flowone_test_revoke');
        $runner->assertTrue(!$guestLinks->revokeLink($link['token'], 'someone-else@flowone.pro'), 'non-creator could revoke');
        $runner->assertTrue($guestLinks->revokeLink($link['token'], $testUser), 'creator revoke failed');
        $runner->assertTrue($guestLinks->validateAndConsume($link['token']) === null, 'revoked link accepted');
    });

    $runner->test('listLinks returns only active links for the file', function () use ($runner, $guestLinks, $guestFileId, $testUser) {
        $guestLinks->createLink($guestFileId + 1, 'viewer', $testUser, 24, 'flowone_test_list');
        $links = $guestLinks->listLinks($guestFileId + 1);
        $runner->assertEquals(1, count($links), 'unexpected link count');
        $runner->assertEquals('flowone_test_list', $links[0]['label'], 'label mismatch');
    });
}

exit($runner->finish());
