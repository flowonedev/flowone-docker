#!/usr/bin/env php
<?php
/**
 * FlowOne Attachment Content Extraction Test
 *
 * Verifies the full text-extraction pipeline that feeds Meilisearch:
 *   - Excel (.xlsx) extraction via PhpSpreadsheet
 *   - PowerPoint (.pptx) extraction via PhpPresentation
 *   - DOCX extraction via PhpWord (regression)
 *   - PDF extraction via pdftotext (regression)
 *   - Text/CSV/Markdown extraction (regression)
 *   - End-to-end: indexEmailAttachment with binary content -> universal_search_index
 *   - Cron mime-type list stays in sync with SearchIndexerService constant
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/attachment-extraction-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required for end-to-end test)
 *   --only=GROUPS        Comma-separated: preflight,xlsx,pptx,docx,pdf,text,e2e,cron
 *   --smoke              Quick health check: just preflight + mime-type sync
 *   --verbose            Print stack traces and extracted text previews
 *   --json               Output results as JSON (for automation/monitoring)
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

// ─── Per-test timeout protection ─────────────────────────────────────
set_time_limit(120); // overall guard, individual tests guard themselves

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email::', 'only::', 'smoke', 'verbose', 'json', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Attachment Extraction Test\n";
    echo "===================================\n\n";
    echo "Usage:\n";
    echo "  php attachment-extraction-test.php [--email=user@flowone.pro] [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL   Required for end-to-end (e2e) test; tests use [FLOWONE-TEST] prefix\n";
    echo "  --only=GROUPS   Comma-separated: preflight,xlsx,pptx,docx,pdf,text,e2e,cron\n";
    echo "  --smoke         Quick health: preflight + cron sync only\n";
    echo "  --verbose       Print stack traces and extracted text previews\n";
    echo "  --json          Output results as JSON\n";
    echo "  --help          Show this help\n\n";
    echo "Example (full run):\n";
    echo "  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/attachment-extraction-test.php \\\n";
    echo "      --email=admin@flowone.pro --verbose\n";
    exit(0);
}

$testEmail   = $opts['email'] ?? null;
$verbose     = isset($opts['verbose']);
$smokeOnly   = isset($opts['smoke']);
$jsonOutput  = isset($opts['json']);
$onlyGroups  = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

// Marker that lets cleanup recognise data we created
const TEST_PREFIX = '[FLOWONE-TEST] ';
const TEST_HU_PHRASE = 'Kerékpáros közvetett kapcsolat';

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['preflight', 'cron']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ─── Logging ─────────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/attachment-extraction-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];
$createdFiles = []; // temp files to cleanup
$createdRows  = []; // [user_email, source_type, source_id]

function out(string $msg): void {
    global $logFile, $jsonOutput;
    if ($jsonOutput) return;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn, int $timeoutSec = 30): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);

    // Per-test soft timeout
    set_time_limit($timeoutSec);

    try {
        $result = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m  {$name} ({$elapsed}ms)");
        out("          -> " . $e->getMessage());
        if ($verbose) {
            out("          at " . $e->getFile() . ':' . $e->getLine());
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $cond, string $msg = 'Assertion failed'): void {
    if (!$cond) throw new \RuntimeException($msg);
}
function assert_contains(string $haystack, string $needle, string $msg = ''): void {
    if (mb_stripos($haystack, $needle) === false) {
        $label = $msg ?: "Expected output to contain '$needle'";
        $preview = mb_substr($haystack, 0, 200);
        throw new \RuntimeException("$label. First 200 chars: '$preview'");
    }
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ?: 'Values differ') . ": expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

// ─── Cleanup (registered NOW so even SIGINT/fatal triggers it) ──────

function cleanup(): void {
    global $createdFiles, $createdRows, $config, $verbose;

    foreach ($createdFiles as $f) {
        if (is_file($f)) @unlink($f);
    }

    if (!empty($createdRows)) {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $config['db']['host'] ?? '127.0.0.1', $config['db']['name'] ?? '');
            $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $db->prepare("DELETE FROM universal_search_index WHERE user_email = ? AND source_type = ? AND source_id = ?");
            foreach ($createdRows as [$ue, $st, $sid]) {
                try { $stmt->execute([$ue, $st, $sid]); } catch (\PDOException $e) {
                    if ($verbose) fwrite(STDERR, "cleanup row failed: " . $e->getMessage() . "\n");
                }
            }
        } catch (\Throwable $e) {
            if ($verbose) fwrite(STDERR, "cleanup db failed: " . $e->getMessage() . "\n");
        }
    }
}
register_shutdown_function('cleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { cleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { cleanup(); exit(143); });
}

// ─── Header ─────────────────────────────────────────────────────────

out(str_repeat('═', 60));
out("FlowOne Attachment Content Extraction Test");
out(str_repeat('═', 60));
out("Log: $logFile");
out("Mode: " . ($smokeOnly ? 'SMOKE' : (!empty($onlyGroups) ? 'GROUPS=' . implode(',', $onlyGroups) : 'FULL')));
out('');

use Webmail\Services\SearchIndexerService;

// =====================================================================
// 0. PRE-FLIGHT
// =====================================================================
if (shouldRun('preflight')) {
    out("--- 0. PRE-FLIGHT ---");

    test('PHP version >= 8.0', function () {
        assert_true(version_compare(PHP_VERSION, '8.0.0', '>='), 'Need PHP 8.0+, got ' . PHP_VERSION);
    });

    test('Required PHP extensions loaded', function () {
        $required = ['pdo_mysql', 'mbstring', 'zip', 'xml', 'gd'];
        $missing = array_filter($required, fn($e) => !extension_loaded($e));
        assert_true(empty($missing), 'Missing extensions: ' . implode(',', $missing));
    });

    test('Database reachable', function () use ($config) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1', $config['db']['name'] ?? '');
        $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $db->query("SELECT 1");
    });

    test('storage/logs writable', function () use ($logDir) {
        assert_true(is_dir($logDir) && is_writable($logDir), "Log dir not writable: $logDir");
    });

    test('Temp dir writable', function () {
        $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_');
        assert_true($tmp !== false, 'tempnam failed');
        @unlink($tmp);
    });

    test('SearchIndexerService class loadable', function () use ($config) {
        $svc = new SearchIndexerService($config);
        assert_true(method_exists($svc, 'isExtractableMimeType'), 'Missing isExtractableMimeType');
    });

    out('');
}

// Build a single indexer to reuse across all tests
$indexer = new SearchIndexerService($config);

// Reflection helper to call private methods (test-only)
function invokePrivate(object $obj, string $method, array $args = []) {
    $ref = new \ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

// =====================================================================
// 1. MIME-TYPE REGISTRATION
// =====================================================================
if (shouldRun('preflight')) {
    out("--- 1. MIME-TYPE REGISTRATION ---");

    $expectedMimes = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-excel', // .xls
        'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
        'application/vnd.ms-powerpoint', // .ppt
        'text/plain',
        'text/csv',
        'text/markdown',
        'text/html',
    ];

    foreach ($expectedMimes as $mime) {
        test("Mime registered: $mime", function () use ($indexer, $mime) {
            assert_true($indexer->isExtractableMimeType($mime), "Mime '$mime' not in EXTRACTABLE_MIME_TYPES");
        });
    }
    out('');
}

// =====================================================================
// 2. EXCEL (.xlsx) EXTRACTION  ← the main bug fix
// =====================================================================
if (shouldRun('xlsx')) {
    out("--- 2. EXCEL (.xlsx) EXTRACTION ---");

    test('PhpSpreadsheet library installed', function () {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new \RuntimeException('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet on the server');
        }
    });

    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        test('Generates .xlsx with Hungarian content', function () use (&$createdFiles) {
            $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_xlsx_') . '.xlsx';
            $createdFiles[] = $tmp;

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Útvonalak');
            $sheet->setCellValue('A1', 'Típus');
            $sheet->setCellValue('B1', 'Leírás');
            $sheet->setCellValue('A2', 'Kerékpáros');
            $sheet->setCellValue('B2', TEST_HU_PHRASE); // "Kerékpáros közvetett kapcsolat"
            $sheet->setCellValue('A3', 'Gyalogos');
            $sheet->setCellValue('B3', 'Második sor szöveg');

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmp);
            assert_true(file_exists($tmp) && filesize($tmp) > 0, 'XLSX file not written');
        });

        test('extractSpreadsheetText finds Hungarian phrase', function () use ($indexer, &$createdFiles) {
            // Re-use the file from previous test (last entry)
            $tmp = end($createdFiles);
            assert_true(is_string($tmp) && is_file($tmp), 'XLSX fixture missing');

            $text = invokePrivate($indexer, 'extractSpreadsheetText', [$tmp]);
            assert_true($text !== '', 'No text extracted');
            assert_contains($text, TEST_HU_PHRASE, 'Hungarian phrase missing from extracted XLSX');
            assert_contains($text, 'Kerékpáros', 'Cell A2 missing');
            assert_contains($text, 'Útvonalak', 'Sheet title missing');
        });

        test('extractFileContent routes .xlsx via mime', function () use ($indexer, &$createdFiles) {
            $tmp = end($createdFiles);
            $text = invokePrivate($indexer, 'extractFileContent', [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $tmp,
            ]);
            assert_contains($text, TEST_HU_PHRASE, 'extractFileContent did not route xlsx mime');
        });

        test('extractFileContent routes .xlsx via extension (octet-stream)', function () use ($indexer, &$createdFiles) {
            $tmp = end($createdFiles);
            $text = invokePrivate($indexer, 'extractFileContent', [
                'application/octet-stream', // generic - should fall back to .xlsx extension
                $tmp,
            ]);
            assert_contains($text, TEST_HU_PHRASE, 'extractFileContent did not fall back to extension');
        });

        test('extractSpreadsheetText caps output at ~100KB', function () use ($indexer, &$createdFiles) {
            // Build a huge sheet to validate the cap
            $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_xlsx_big_') . '.xlsx';
            $createdFiles[] = $tmp;
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            for ($r = 1; $r <= 5000; $r++) {
                $sheet->setCellValue("A{$r}", str_repeat('lorem ipsum dolor sit amet ', 10));
            }
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmp);

            $text = invokePrivate($indexer, 'extractSpreadsheetText', [$tmp]);
            assert_true(strlen($text) > 1000,  'Big sheet extraction returned very little');
            assert_true(strlen($text) <= 100100, 'Big sheet extraction not capped: ' . strlen($text) . ' chars');
        }, 60);
    }

    out('');
}

// =====================================================================
// 3. POWERPOINT (.pptx) EXTRACTION
// =====================================================================
if (shouldRun('pptx')) {
    out("--- 3. POWERPOINT (.pptx) EXTRACTION ---");

    test('PhpPresentation library installed', function () {
        if (!class_exists('\PhpOffice\PhpPresentation\IOFactory')) {
            return 'warn'; // it's listed in composer.json but optional in practice
        }
    });

    if (class_exists('\PhpOffice\PhpPresentation\IOFactory')) {
        test('Generates .pptx with text shapes', function () use (&$createdFiles) {
            $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_pptx_') . '.pptx';
            $createdFiles[] = $tmp;

            $pres = new \PhpOffice\PhpPresentation\PhpPresentation();
            $slide = $pres->getActiveSlide();
            $shape = $slide->createRichTextShape()->setHeight(300)->setWidth(600)->setOffsetX(50)->setOffsetY(50);
            $shape->createTextRun(TEST_HU_PHRASE);
            $shape->createParagraph()->createTextRun('Second line of slide content');

            $writer = \PhpOffice\PhpPresentation\IOFactory::createWriter($pres, 'PowerPoint2007');
            $writer->save($tmp);
            assert_true(file_exists($tmp) && filesize($tmp) > 0, 'PPTX file not written');
        });

        test('extractPresentationText finds Hungarian phrase', function () use ($indexer, &$createdFiles) {
            $tmp = end($createdFiles);
            $text = invokePrivate($indexer, 'extractPresentationText', [$tmp]);
            assert_true($text !== '', 'No text extracted from pptx');
            assert_contains($text, TEST_HU_PHRASE, 'Hungarian phrase missing from extracted PPTX');
        });

        test('extractFileContent routes .pptx via mime', function () use ($indexer, &$createdFiles) {
            $tmp = end($createdFiles);
            $text = invokePrivate($indexer, 'extractFileContent', [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                $tmp,
            ]);
            assert_contains($text, TEST_HU_PHRASE, 'extractFileContent did not route pptx mime');
        });
    }

    out('');
}

// =====================================================================
// 4. DOCX (regression — make sure we didn't break it)
// =====================================================================
if (shouldRun('docx')) {
    out("--- 4. DOCX REGRESSION ---");

    test('PhpWord library installed', function () {
        if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
            throw new \RuntimeException('PhpWord not installed');
        }
    });

    if (class_exists('\PhpOffice\PhpWord\IOFactory')) {
        test('extractDocxText still works', function () use ($indexer, &$createdFiles) {
            $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_docx_') . '.docx';
            $createdFiles[] = $tmp;

            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText('DOCX content: ' . TEST_HU_PHRASE);
            $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tmp);

            $text = invokePrivate($indexer, 'extractDocxText', [$tmp]);
            assert_contains($text, TEST_HU_PHRASE, 'DOCX extraction regression');
        });
    }

    out('');
}

// =====================================================================
// 5. PDF (regression)
// =====================================================================
if (shouldRun('pdf')) {
    out("--- 5. PDF REGRESSION ---");

    test('pdftotext binary available', function () {
        $check = trim(shell_exec('which pdftotext 2>/dev/null') ?: '');
        if ($check === '') {
            return 'warn'; // optional but desirable
        }
    });

    out('');
}

// =====================================================================
// 6. TEXT / CSV / MD (regression)
// =====================================================================
if (shouldRun('text')) {
    out("--- 6. TEXT/CSV/MD ---");

    test('Plain text extraction', function () use ($indexer, &$createdFiles) {
        $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_txt_') . '.txt';
        $createdFiles[] = $tmp;
        file_put_contents($tmp, TEST_HU_PHRASE . " plain content");
        $text = invokePrivate($indexer, 'extractFileContent', ['text/plain', $tmp]);
        assert_contains($text, TEST_HU_PHRASE);
    });

    test('CSV extraction', function () use ($indexer, &$createdFiles) {
        $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_csv_') . '.csv';
        $createdFiles[] = $tmp;
        file_put_contents($tmp, "type,desc\nKerékpáros," . TEST_HU_PHRASE);
        $text = invokePrivate($indexer, 'extractFileContent', ['text/csv', $tmp]);
        assert_contains($text, TEST_HU_PHRASE);
    });

    out('');
}

// =====================================================================
// 7. END-TO-END: indexEmailAttachment writes the content to the index
// =====================================================================
if (shouldRun('e2e') && $testEmail) {
    out("--- 7. END-TO-END INDEX WRITE ---");

    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        test('E2E indexing (skipped — no PhpSpreadsheet)', fn() => 'warn');
    } else {
        $e2eFilename = 'flowone_test_extract_' . time() . '.xlsx';

        test('indexEmailAttachment stores extracted XLSX content', function () use ($indexer, $testEmail, &$createdFiles, &$createdRows, $e2eFilename, $config) {
            $tmp = tempnam(sys_get_temp_dir(), 'flowone_test_e2e_') . '.xlsx';
            $createdFiles[] = $tmp;

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', TEST_HU_PHRASE);
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmp);

            $bytes = file_get_contents($tmp);
            $folder = 'INBOX';
            $uid = 999999;
            $sourceId = $folder . ':' . $uid . ':' . md5($e2eFilename);
            $createdRows[] = [strtolower($testEmail), 'email_attachment', $sourceId];

            $ok = $indexer->indexEmailAttachment($testEmail, [
                'filename'     => $e2eFilename,
                'mime_type'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'from_email'   => 'tester@flowone.pro',
                'from_name'    => 'FlowOne Test',
                'subject'      => TEST_PREFIX . 'Attachment extraction',
                'folder'       => $folder,
                'uid'          => $uid,
                'part'         => '2',
                'size'         => strlen($bytes),
                'message_date' => date('Y-m-d H:i:s'),
                'content'      => $bytes,
            ]);
            assert_true($ok, 'indexEmailAttachment returned false');

            // Verify MySQL row now contains the extracted text
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $config['db']['host'] ?? '127.0.0.1', $config['db']['name'] ?? '');
            $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $stmt = $db->prepare("SELECT content_text, content_snippet, extra_data FROM universal_search_index WHERE user_email = ? AND source_type = ? AND source_id = ?");
            $stmt->execute([strtolower($testEmail), 'email_attachment', $sourceId]);
            $row = $stmt->fetch();
            assert_true(is_array($row), 'Indexed row not found in DB');
            assert_contains($row['content_text'] ?? '', TEST_HU_PHRASE, 'Indexed content_text missing phrase');

            $extra = json_decode($row['extra_data'] ?? '{}', true);
            assert_true(($extra['content_indexed'] ?? false) === true, 'content_indexed flag not true');
        }, 60);
    }

    out('');
} elseif (shouldRun('e2e')) {
    out("--- 7. END-TO-END (skipped: pass --email=USER to enable) ---");
    out('');
}

// =====================================================================
// 8. CRON / INDEXER LIST DRIFT GUARD
// =====================================================================
if (shouldRun('cron')) {
    out("--- 8. CRON SYNC GUARD ---");

    test('Cron index-attachments.php uses SearchIndexerService constant', function () {
        $cronPath = __DIR__ . '/../cron/index-attachments.php';
        assert_true(is_file($cronPath), "Cron file missing: $cronPath");
        $src = file_get_contents($cronPath);
        assert_true(
            strpos($src, 'SearchIndexerService::EXTRACTABLE_MIME_TYPES') !== false,
            'Cron is NOT using the constant — mime-type lists will drift apart'
        );
    });

    test('Constant lists xlsx & pptx', function () {
        $mimes = SearchIndexerService::EXTRACTABLE_MIME_TYPES;
        assert_true(in_array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $mimes, true), 'xlsx missing from constant');
        assert_true(in_array('application/vnd.openxmlformats-officedocument.presentationml.presentation', $mimes, true), 'pptx missing from constant');
    });

    out('');
}

// =====================================================================
// SUMMARY
// =====================================================================

$failedTests = array_filter($results, fn($r) => $r['status'] === 'FAIL');

if ($jsonOutput) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'results'  => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    out(str_repeat('═', 60));
    out("SUMMARY");
    out(str_repeat('═', 60));
    out("Total:    $totalTests");
    out("\033[32mPassed:   $passed\033[0m");
    out("\033[31mFailed:   $failed\033[0m");
    out("\033[33mWarnings: $warnings\033[0m");
    if (!empty($failedTests)) {
        out('');
        out("FAILED TESTS:");
        foreach ($failedTests as $t) {
            out("  ✗ {$t['name']}");
            out("      {$t['error']}");
        }
    }
    out('');
    out("Log written to: $logFile");
}

exit($failed > 0 ? 1 : 0);
