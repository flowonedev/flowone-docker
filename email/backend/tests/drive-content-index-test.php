#!/usr/bin/env php
<?php
/**
 * FlowOne Drive Content-Index Test.
 *
 * Regression coverage for the bug where Drive files (OnlyOffice docs, saved
 * email attachments) showed up in universal search by FILENAME only: their
 * body text was never extracted/indexed, so the searched paragraph was never
 * highlighted.
 *
 * The fix centralizes search indexing in DriveService so EVERY content-write
 * path keeps Meilisearch + MySQL search content fresh:
 *   - uploadFileContent()   (mailbox/message/chat "save to Drive")
 *   - saveEmailAttachment() (the "Saved to Drive" badge path)
 *   - updateFileContent()   (OnlyOffice save callback)
 *
 * This script drives those service methods with REAL files and asserts that
 * the body text lands in universal_search_index.content_text (the synchronous
 * source of truth) and is highlighted (<mark>) by UniversalSearchService.
 *
 * Test groups (run all by default; restrict with --only=GROUP[,GROUP]):
 *
 *   preflight   extensions, autoloader, DB, required tables, PhpWord,
 *               DriveService + SearchIndexerService method presence
 *   index       docx  (saveEmailAttachment) -> content indexed
 *               docx table cell (saveEmailAttachment) -> cell text indexed
 *               xlsx  (saveEmailAttachment) -> cell text indexed
 *               pptx  (saveEmailAttachment) -> slide text indexed
 *               plain text (uploadFileContent) -> content indexed
 *               markdown .md (octet-stream by extension) -> content indexed
 *               update (updateFileContent) -> RE-indexed (new in, old out)
 *               xlsx update -> re-indexed via zip-mime detection (OnlyOffice)
 *               no-text binary -> filename fallback
 *               (xlsx/pptx tests self-skip if PhpSpreadsheet/PhpPresentation
 *                are not installed)
 *   highlight   end-to-end UniversalSearchService::search() wraps the
 *               matched token in <mark>
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-content-index-test.php --verbose
 *
 * Flags (handled by the shared runner):
 *   --verbose              extra debug output (stack traces)
 *   --json                 emit results as JSON to stdout
 *   --smoke                preflight only (no business logic)
 *   --skip-send            accepted for rule parity (no external sends here)
 *   --only=GROUP[,GROUP]   run only listed groups
 *   --timeout=N            per-test timeout in seconds (default 30)
 *   --help                 show this message
 *
 * Safety: all data uses flowone_test_*@flowone.pro users and [FLOWONE-TEST]
 * markers. Cleanup deletes the test users' drive rows, index rows, quota rows,
 * Meili docs and physical files, and runs even on failure or SIGINT.
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

$runner = new FlowOneTestRunner('drive-content-index', $argv);

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

$config = null;
$db = null;

// ---------------------------------------------------------------------------
// 1. PREFLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('preflight')) {
    $runner->section('1. PREFLIGHT');

    $runner->test('php extensions loaded (pdo_mysql, mbstring, zip)', function () use ($runner) {
        foreach (['pdo_mysql', 'mbstring', 'zip'] as $ext) {
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
        foreach (['drive_files', 'drive_folders', 'universal_search_index'] as $table) {
            $count = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
            $runner->assertEquals(1, $count, "{$table} table missing");
        }
    });

    $runner->test('PhpWord available (docx fixtures + extraction)', function () use ($runner) {
        $runner->assertTrue(
            class_exists('\PhpOffice\PhpWord\IOFactory'),
            'PhpOffice\\PhpWord not installed - docx extraction cannot work'
        );
    });

    $runner->test('PhpSpreadsheet / PhpPresentation available (xlsx/pptx)', function () use ($runner) {
        $missing = [];
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) $missing[] = 'PhpSpreadsheet (xlsx/xls)';
        if (!class_exists('\PhpOffice\PhpPresentation\IOFactory')) $missing[] = 'PhpPresentation (pptx/ppt)';
        if (!empty($missing)) {
            // Not fatal: those file types just won't be content-indexed. The
            // related tests below self-skip.
            $runner->log('          (warn) missing: ' . implode(', ', $missing));
            return 'warn';
        }
    });

    $runner->test('DriveService write methods exist', function () use ($runner) {
        $runner->assertTrue(class_exists('\Webmail\Services\DriveService'), 'DriveService missing');
        foreach (['uploadFileContent', 'saveEmailAttachment', 'updateFileContent', 'getUserPath'] as $m) {
            $runner->assertTrue(
                method_exists('\Webmail\Services\DriveService', $m),
                "DriveService::{$m}() not defined"
            );
        }
    });

    $runner->test('SearchIndexerService::indexDriveFile exists', function () use ($runner) {
        $runner->assertTrue(class_exists('\Webmail\Services\SearchIndexerService'), 'SearchIndexerService missing');
        $runner->assertTrue(
            method_exists('\Webmail\Services\SearchIndexerService', 'indexDriveFile'),
            'indexDriveFile() not defined'
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

$drive   = new \Webmail\Services\DriveService($config, $owner);
$indexer = new \Webmail\Services\SearchIndexerService($config);

// ---------------------------------------------------------------------------
// Fixtures + signal-safe cleanup
// ---------------------------------------------------------------------------
/** @var array<int,true> drive_files ids created by this run (for Meili removal). */
$createdFileIds = [];
/** @var array<int,string> temp fixture files on the local FS to unlink. */
$tempPaths = [];

$rrmdir = function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? $rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

$cleanup = function () use ($db, $drive, $indexer, $owner, &$createdFileIds, &$tempPaths, $rrmdir) {
    // Remove Meili + MySQL index docs for the files we created.
    foreach (array_keys($createdFileIds) as $fid) {
        try {
            $indexer->removeFromIndex($owner, 'drive_file', (string)$fid);
        } catch (\Throwable $e) {
            // best effort
        }
    }

    // DB rows for the test user. Scoped strictly to flowone_test_ users.
    try {
        if (!empty($createdFileIds)) {
            $ids = implode(',', array_map('intval', array_keys($createdFileIds)));
            $db->exec("DELETE FROM drive_file_versions WHERE file_id IN ({$ids})");
        }
    } catch (\Throwable $e) {
        // table may not exist on minimal installs
    }
    $db->exec("DELETE FROM drive_files   WHERE user_email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM drive_folders WHERE user_email LIKE 'flowone_test_%'");
    $db->exec("DELETE FROM universal_search_index WHERE user_email LIKE 'flowone_test_%'");
    try {
        $db->exec("DELETE FROM drive_quotas WHERE user_email LIKE 'flowone_test_%'");
    } catch (\Throwable $e) {
        // best effort
    }

    // Physical bytes for the test user (their storage dir is exclusively theirs).
    try {
        $rrmdir($drive->getUserPath($owner));
    } catch (\Throwable $e) {
        // best effort
    }

    foreach ($tempPaths as $p) {
        @unlink($p);
    }
    $tempPaths = [];
};
$cleanup();
$runner->addCleanup($cleanup);

/** Build a real .docx whose first paragraph contains $phrase; returns its path. */
$makeDocx = function (string $phrase) use (&$tempPaths): string {
    $word = new \PhpOffice\PhpWord\PhpWord();
    $section = $word->addSection();
    $section->addText('[FLOWONE-TEST] ' . $phrase);
    $path = sys_get_temp_dir() . '/flowone_test_' . bin2hex(random_bytes(8)) . '.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
    $tempPaths[] = $path;
    return $path;
};

/**
 * Build a real .docx whose only text lives inside a TABLE CELL (no paragraph
 * outside the table). Guards extractTextFromElement() table traversal:
 * Table->getRows()->getCells() must be walked or this token is dropped.
 */
$makeDocxWithTable = function (string $cellPhrase) use (&$tempPaths): string {
    $word = new \PhpOffice\PhpWord\PhpWord();
    $section = $word->addSection();
    $table = $section->addTable();
    $table->addRow();
    $table->addCell(4000)->addText('[FLOWONE-TEST] ' . $cellPhrase);
    $path = sys_get_temp_dir() . '/flowone_test_' . bin2hex(random_bytes(8)) . '.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
    $tempPaths[] = $path;
    return $path;
};

/** Build a real .xlsx with $phrase in cell A1; returns its path. */
$makeXlsx = function (string $phrase) use (&$tempPaths): string {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getActiveSheet()->setCellValue('A1', '[FLOWONE-TEST] ' . $phrase);
    $path = sys_get_temp_dir() . '/flowone_test_' . bin2hex(random_bytes(8)) . '.xlsx';
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save($path);
    $ss->disconnectWorksheets();
    $tempPaths[] = $path;
    return $path;
};

/** Build a real .pptx with $phrase on slide one; returns its path. */
$makePptx = function (string $phrase) use (&$tempPaths): string {
    $pres = new \PhpOffice\PhpPresentation\PhpPresentation();
    $shape = $pres->getActiveSlide()->createRichTextShape();
    $shape->setHeight(300)->setWidth(600)->setOffsetX(20)->setOffsetY(20);
    $shape->createTextRun('[FLOWONE-TEST] ' . $phrase);
    $path = sys_get_temp_dir() . '/flowone_test_' . bin2hex(random_bytes(8)) . '.pptx';
    \PhpOffice\PhpPresentation\IOFactory::createWriter($pres, 'PowerPoint2007')->save($path);
    $tempPaths[] = $path;
    return $path;
};

/** A unique, fulltext-safe single-word token (>3 chars, no stopword). */
$makeToken = function (string $stem): string {
    return 'flowone' . $stem . bin2hex(random_bytes(4));
};

/** Read the indexed body text for a drive file straight from the source of truth. */
$indexedContent = function (int $fileId) use ($db, $owner): ?string {
    $stmt = $db->prepare(
        "SELECT content_text FROM universal_search_index
         WHERE user_email = ? AND source_type = 'drive_file' AND source_id = ?"
    );
    $stmt->execute([strtolower($owner), (string)$fileId]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
};

// ---------------------------------------------------------------------------
// 2. CONTENT INDEXING ON EVERY WRITE PATH
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('index')) {
    $runner->section('2. CONTENT INDEXING');

    $runner->test('saveEmailAttachment indexes docx body text (not just filename)', function () use ($runner, $drive, $owner, $makeDocx, $makeToken, $indexedContent, &$createdFileIds) {
        $token = $makeToken('docx');
        $docx = $makeDocx("The unique marker is {$token} in the first paragraph.");
        $bytes = file_get_contents($docx);

        $result = $drive->saveEmailAttachment(
            $owner,
            'flowone_test_proposal.docx',
            $bytes,
            DOCX_MIME,
            'flowone_test_subject'
        );
        $runner->assertTrue(is_array($result) && !empty($result['file']['id']), 'saveEmailAttachment did not return a file');
        $fileId = (int)$result['file']['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue($content !== null, 'no universal_search_index row created for saved attachment');
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'indexed content_text is missing the docx body token (filename-only bug)'
        );
    });

    $runner->test('saveEmailAttachment indexes docx TABLE CELL text', function () use ($runner, $drive, $owner, $makeDocxWithTable, $makeToken, $indexedContent, &$createdFileIds) {
        // Table-only doc: the token lives exclusively in a table cell, so this
        // fails unless extractTextFromElement() walks Table->getRows()->getCells().
        $token = $makeToken('tbl');
        $docx = $makeDocxWithTable("Pricing row marker {$token} in a table cell.");
        $result = $drive->saveEmailAttachment(
            $owner,
            'flowone_test_pricing.docx',
            file_get_contents($docx),
            DOCX_MIME,
            'flowone_test_subject'
        );
        $runner->assertTrue(is_array($result) && !empty($result['file']['id']), 'table docx attachment not saved');
        $fileId = (int)$result['file']['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'docx table-cell text was not indexed (table traversal missing)'
        );
    });

    $runner->test('saveEmailAttachment indexes xlsx cell text', function () use ($runner, $drive, $owner, $makeXlsx, $makeToken, $indexedContent, &$createdFileIds) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return 'skip';
        }
        $token = $makeToken('xlsx');
        $xlsx = $makeXlsx("Spreadsheet marker {$token} in A1.");
        $result = $drive->saveEmailAttachment(
            $owner,
            'flowone_test_budget.xlsx',
            file_get_contents($xlsx),
            XLSX_MIME,
            'flowone_test_subject'
        );
        $runner->assertTrue(is_array($result) && !empty($result['file']['id']), 'xlsx attachment not saved');
        $fileId = (int)$result['file']['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'xlsx cell text was not indexed'
        );
    });

    $runner->test('saveEmailAttachment indexes pptx slide text', function () use ($runner, $drive, $owner, $makePptx, $makeToken, $indexedContent, &$createdFileIds) {
        if (!class_exists('\PhpOffice\PhpPresentation\IOFactory')) {
            return 'skip';
        }
        $token = $makeToken('pptx');
        $pptx = $makePptx("Slide marker {$token} on slide one.");
        $result = $drive->saveEmailAttachment(
            $owner,
            'flowone_test_deck.pptx',
            file_get_contents($pptx),
            PPTX_MIME,
            'flowone_test_subject'
        );
        $runner->assertTrue(is_array($result) && !empty($result['file']['id']), 'pptx attachment not saved');
        $fileId = (int)$result['file']['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'pptx slide text was not indexed'
        );
    });

    $runner->test('updateFileContent re-indexes xlsx via zip-mime detection (OnlyOffice save)', function () use ($runner, $drive, $owner, $makeXlsx, $makeToken, $indexedContent, &$createdFileIds) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            return 'skip';
        }
        // Seed an xlsx, then replace its bytes the way the OnlyOffice callback
        // does. updateFileContent() recomputes the mime via mime_content_type(),
        // which for a zip-container .xlsx often yields 'application/zip'; the
        // extractor must still detect it by extension and pull cell text.
        $tokenA = $makeToken('xla');
        $tokenB = $makeToken('xlb');
        $file = $drive->uploadFileContent(
            $owner,
            'flowone_test_sheet.xlsx',
            file_get_contents($makeXlsx("Original {$tokenA}.")),
            XLSX_MIME
        );
        $runner->assertTrue(is_array($file) && !empty($file['id']), 'xlsx seed failed');
        $fileId = (int)$file['id'];
        $createdFileIds[$fileId] = true;

        $updated = $drive->updateFileContent($owner, $fileId, $makeXlsx("Edited {$tokenB}."), false);
        $runner->assertTrue(is_array($updated), 'xlsx updateFileContent returned null');

        $after = $indexedContent($fileId);
        $runner->assertTrue($after !== null && str_contains($after, $tokenB), 'edited xlsx cell not re-indexed (zip-mime extraction failed)');
        $runner->assertTrue($after !== null && !str_contains($after, $tokenA), 'stale xlsx cell survived re-index');
    });

    $runner->test('uploadFileContent indexes plain-text body', function () use ($runner, $drive, $owner, $makeToken, $indexedContent, &$createdFileIds) {
        $token = $makeToken('txt');
        $file = $drive->uploadFileContent(
            $owner,
            'flowone_test_notes.txt',
            "[FLOWONE-TEST] plain text marker {$token} on line one.",
            'text/plain'
        );
        $runner->assertTrue(is_array($file) && !empty($file['id']), 'uploadFileContent returned null');
        $fileId = (int)$file['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'plain-text content was not indexed'
        );
    });

    $runner->test('uploadFileContent indexes markdown (.md) by extension', function () use ($runner, $drive, $owner, $makeToken, $indexedContent, &$createdFileIds) {
        $token = $makeToken('md');
        // Browsers commonly send application/octet-stream for .md; the indexer
        // must still detect it as text by extension and index the body.
        $file = $drive->uploadFileContent(
            $owner,
            'flowone_test_readme.md',
            "# [FLOWONE-TEST] heading\n\nMarkdown marker {$token} in the body.\n",
            'application/octet-stream'
        );
        $runner->assertTrue(is_array($file) && !empty($file['id']), 'md uploadFileContent returned null');
        $fileId = (int)$file['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertTrue(
            $content !== null && str_contains($content, $token),
            'markdown content was not indexed'
        );
    });

    $runner->test('updateFileContent RE-indexes (new text in, old text out)', function () use ($runner, $drive, $owner, $makeDocx, $makeToken, $indexedContent, &$createdFileIds) {
        $tokenA = $makeToken('vera');
        $tokenB = $makeToken('verb');

        // Seed via uploadFileContent (docx bytes, correct mime).
        $docxA = $makeDocx("Original revision marker {$tokenA}.");
        $file = $drive->uploadFileContent(
            $owner,
            'flowone_test_living.docx',
            file_get_contents($docxA),
            DOCX_MIME
        );
        $runner->assertTrue(is_array($file) && !empty($file['id']), 'seed uploadFileContent failed');
        $fileId = (int)$file['id'];
        $createdFileIds[$fileId] = true;

        $seeded = $indexedContent($fileId);
        $runner->assertTrue($seeded !== null && str_contains($seeded, $tokenA), 'seed content not indexed');

        // Simulate an OnlyOffice save replacing the bytes (no version row).
        $docxB = $makeDocx("Edited revision marker {$tokenB}.");
        $updated = $drive->updateFileContent($owner, $fileId, $docxB, false);
        $runner->assertTrue(is_array($updated), 'updateFileContent returned null');

        $after = $indexedContent($fileId);
        $runner->assertTrue($after !== null && str_contains($after, $tokenB), 'edited body token not re-indexed');
        $runner->assertTrue($after !== null && !str_contains($after, $tokenA), 'stale body token survived re-index');
    });

    $runner->test('no-text binary falls back to filename in the index', function () use ($runner, $drive, $owner, &$createdFileIds, $indexedContent) {
        $name = 'flowone_test_blob_' . bin2hex(random_bytes(3)) . '.bin';
        $file = $drive->uploadFileContent(
            $owner,
            $name,
            random_bytes(2048), // non-text, unextractable
            'application/octet-stream'
        );
        $runner->assertTrue(is_array($file) && !empty($file['id']), 'binary uploadFileContent failed');
        $fileId = (int)$file['id'];
        $createdFileIds[$fileId] = true;

        $content = $indexedContent($fileId);
        $runner->assertEquals($name, $content, 'binary file should fall back to filename as content');
    });
}

// ---------------------------------------------------------------------------
// 3. END-TO-END HIGHLIGHT
// ---------------------------------------------------------------------------
if ($runner->shouldRunSection('highlight')) {
    $runner->section('3. HIGHLIGHT');

    $runner->test('UniversalSearchService highlights the matched body token', function () use ($runner, $config, $drive, $indexer, $owner, $makeDocx, $makeToken, &$createdFileIds) {
        $token = $makeToken('hl');
        $docx = $makeDocx("Highlight target {$token} lives in paragraph one.");
        $result = $drive->saveEmailAttachment(
            $owner,
            'flowone_test_highlight.docx',
            file_get_contents($docx),
            DOCX_MIME,
            'flowone_test_subject'
        );
        $runner->assertTrue(is_array($result) && !empty($result['file']['id']), 'highlight fixture not saved');
        $fileId = (int)$result['file']['id'];
        $createdFileIds[$fileId] = true;

        $svc = new \Webmail\Services\UniversalSearchService($config);

        // Meilisearch indexing is async; poll briefly. The MySQL fallback is
        // synchronous, so search() finds the row regardless once Meili is
        // either caught up or returns empty (triggering fallback).
        $found = null;
        $deadline = microtime(true) + 4.0;
        do {
            $res = $svc->search($owner, $token, ['types' => ['drive_file'], 'limit' => 25]);
            foreach (($res['results'] ?? []) as $r) {
                if (($r['source_type'] ?? '') === 'drive_file' && (string)($r['source_id'] ?? '') === (string)$fileId) {
                    $found = $r;
                    break;
                }
            }
            if ($found !== null) {
                break;
            }
            usleep(300000);
        } while (microtime(true) < $deadline);

        if ($found === null) {
            // Should not happen: content_text is in MySQL synchronously. Treat a
            // miss as a soft warning only if Meili is enabled (async lag), else fail.
            if ($indexer->isMeilisearchEnabled()) {
                return 'warn';
            }
            $runner->assertTrue(false, 'file did not appear in search results');
        }

        $snippet = (string)($found['highlighted_snippet'] ?? '');
        $runner->assertTrue(
            str_contains($snippet, '<mark>') && str_contains($snippet, $token),
            'matched token was not wrapped in <mark> in the snippet'
        );
    });
}

exit($runner->finish());
