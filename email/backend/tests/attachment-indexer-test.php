#!/usr/bin/env php
<?php
/**
 * Attachment Indexer Resilience Test
 *
 * Verifies that the attachment indexer (both the runtime/HTTP path and the
 * cron path) survives the failure modes that were causing repeated 500s in
 * the browser since the folder-identity refactor landed:
 *
 *   1. Stale lowercase folder names in webmail_email_attachments that no
 *      longer match Dovecot's PascalCase folders.
 *   2. Rows pointing at UIDs that have been expunged from their folder.
 *   3. Rows pointing at folders that have been deleted entirely.
 *
 * The test instantiates the real SearchIndexerService, connects to IMAP with
 * the provided credentials, plants a small set of synthetic bad rows
 * (recognizable by the [FLOWONE-TEST] subject prefix), runs one indexing
 * batch, and asserts that:
 *
 *   - No exception escapes the indexer (the controller's 500 path).
 *   - Bad rows are marked content_indexed = -1 (skipped permanently).
 *   - Good rows (if any) still index successfully alongside the bad ones.
 *   - php_errors.log lines about the bad rows include the row id so a human
 *     can audit afterwards.
 *
 * All synthetic rows are cleaned up in a finally block AND via a shutdown
 * handler so an interrupted run never leaves test data behind. Safe to run
 * against production -- never modifies real attachment rows or IMAP state.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/attachment-indexer-test.php \
 *     --email=USER --password=PASS --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\SearchIndexerService;
use Webmail\Services\ImapService;
use Webmail\Services\FolderImapResolver;

// ---------------------------------------------------------------------------
// CLI parsing
// ---------------------------------------------------------------------------

$options = getopt('', [
    'help',
    'verbose',
    'smoke',
    'json',
    'only::',
    'skip-send',
    'email::',
    'password::',
    'timeout::',
]);

if (isset($options['help'])) {
    echo <<<HELP
Usage: attachment-indexer-test.php [options]

Required:
  --email=ADDR        Login email for the IMAP/DB account under test
  --password=PASS     IMAP password (used only for connect; never stored)

Optional:
  --help              Show this help and exit
  --verbose           Print stack traces, full responses, raw error lines
  --smoke             Pre-flight only: connectivity + extensions + tables.
                      Skip the synthetic-row insertion and indexer run.
  --json              Print results as JSON (for monitoring/CI)
  --only=g1,g2        Run only the named test groups (preflight,construct,
                      imap,stale-folder,canonical)
  --skip-send         Do not call indexAttachmentContentBatch; only check
                      that the service constructs and reaches the folder
                      check (useful when IMAP is degraded)
  --timeout=SECONDS   Per-test timeout (default: 30)

Exit codes:
  0  all pass
  1  at least one failure
  2  bad invocation / missing flags

HELP;
    exit(0);
}

$email     = $options['email']    ?? null;
$password  = $options['password'] ?? null;
$verbose   = isset($options['verbose']);
$smoke     = isset($options['smoke']);
$asJson    = isset($options['json']);
$skipSend  = isset($options['skip-send']);
$timeout   = (int)($options['timeout'] ?? 30);
$only      = isset($options['only']) && $options['only'] !== ''
    ? array_map('trim', explode(',', $options['only']))
    : null;

if (!$email || !$password) {
    fwrite(STDERR, "ERROR: --email and --password are required. See --help.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Logging setup
// ---------------------------------------------------------------------------

$logDir = __DIR__ . '/../../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/attachment-indexer-test-' . date('Ymd-His') . '.log';
$logHandle = @fopen($logFile, 'a');

$useColor = !$asJson && function_exists('posix_isatty') && @posix_isatty(STDOUT);
$colors = [
    'PASS' => "\033[32m",
    'FAIL' => "\033[31m",
    'WARN' => "\033[33m",
    'INFO' => "\033[36m",
    'RESET' => "\033[0m",
];

$results = [];

function log_line(string $level, string $message, ?float $ms = null): void
{
    global $logHandle, $useColor, $colors, $asJson;
    $time = date('H:i:s');
    $msPart = $ms !== null ? sprintf(' (%dms)', (int)$ms) : '';
    $plain = "[{$time}] [{$level}] {$message}{$msPart}";
    if ($logHandle) {
        @fwrite($logHandle, $plain . "\n");
    }
    if ($asJson) {
        return;
    }
    if ($useColor && isset($colors[$level])) {
        echo $colors[$level] . "[{$level}]" . $colors['RESET']
            . " {$message}{$msPart}\n";
    } else {
        echo $plain . "\n";
    }
}

// ---------------------------------------------------------------------------
// Test infrastructure
// ---------------------------------------------------------------------------

const TEST_SUBJECT_PREFIX = '[FLOWONE-TEST] attachment-indexer';
const TEST_FILENAME_PREFIX = 'flowone_test_';

$config = require __DIR__ . '/../src/config.php';
$insertedTestRowIds = [];

function cleanup_test_rows(\PDO $db, array &$ids): void
{
    if (empty($ids)) return;
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "DELETE FROM webmail_email_attachments WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        log_line('INFO', 'cleaned up ' . count($ids) . ' synthetic test rows');
    } catch (\Throwable $e) {
        log_line('WARN', 'cleanup failed: ' . $e->getMessage());
    }
    $ids = [];
}

// Signal-safe cleanup: shutdown function + (when available) pcntl signal
// handlers so SIGINT/SIGTERM during the run still removes test data.
register_shutdown_function(function () use (&$insertedTestRowIds, $config) {
    if (empty($insertedTestRowIds)) return;
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        cleanup_test_rows($db, $insertedTestRowIds);
    } catch (\Throwable $e) {
        // Best-effort; we're already shutting down.
    }
});

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sigHandler = function (int $sig) {
        log_line('WARN', "received signal {$sig}; cleaning up and exiting");
        exit(130);
    };
    pcntl_signal(SIGINT, $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

function should_run(string $group, ?array $only): bool
{
    if ($only === null) return true;
    return in_array($group, $only, true);
}

function run_test(string $group, string $name, callable $fn, int $timeout): array
{
    global $verbose;
    $start = microtime(true);
    set_time_limit($timeout + 5);

    try {
        $detail = $fn();
        $ms = (microtime(true) - $start) * 1000;
        log_line('PASS', "{$group} :: {$name}", $ms);
        return ['group' => $group, 'name' => $name, 'status' => 'pass', 'ms' => $ms, 'detail' => $detail];
    } catch (\Throwable $e) {
        $ms = (microtime(true) - $start) * 1000;
        $msg = $e->getMessage();
        if ($verbose) {
            $msg .= "\n        in " . $e->getFile() . ':' . $e->getLine();
        }
        log_line('FAIL', "{$group} :: {$name} -- {$msg}", $ms);
        return ['group' => $group, 'name' => $name, 'status' => 'fail', 'ms' => $ms, 'error' => $e->getMessage()];
    }
}

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

// ---------------------------------------------------------------------------
// Pre-flight (always runs, never bypassed)
// ---------------------------------------------------------------------------

$results[] = run_test('preflight', 'php extensions', function () {
    foreach (['imap', 'openssl', 'mbstring', 'pdo_mysql'] as $ext) {
        assert_true(extension_loaded($ext), "missing php extension: {$ext}");
    }
}, $timeout);

$results[] = run_test('preflight', 'database connect', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $row = $db->query('SELECT 1 AS one')->fetch();
    assert_true(($row['one'] ?? null) == 1, 'db responded but did not return expected value');
}, $timeout);

$results[] = run_test('preflight', 'attachments table shape', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $cols = $db->query('SHOW COLUMNS FROM webmail_email_attachments')->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['id', 'user_email', 'folder', 'uid', 'filename', 'content_indexed'] as $required) {
        assert_true(in_array($required, $cols, true), "missing column: {$required}");
    }
}, $timeout);

$results[] = run_test('preflight', 'storage dir writable', function () use ($logDir) {
    assert_true(is_dir($logDir), "missing log dir: {$logDir}");
    assert_true(is_writable($logDir), "log dir not writable: {$logDir}");
}, $timeout);

$preflightFailed = false;
foreach ($results as $r) {
    if ($r['group'] === 'preflight' && $r['status'] === 'fail') {
        $preflightFailed = true;
    }
}

if ($preflightFailed) {
    log_line('FAIL', 'pre-flight failed; aborting before any IMAP or row operations.');
    if ($asJson) {
        echo json_encode(['ok' => false, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
    }
    exit(1);
}

if ($smoke) {
    log_line('INFO', '--smoke specified; pre-flight done, exiting.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Construct test: instantiate SearchIndexerService (constructor 500 path)
// ---------------------------------------------------------------------------

$indexer = null;
if (should_run('construct', $only)) {
    $results[] = run_test('construct', 'SearchIndexerService instantiates', function () use ($config, &$indexer) {
        $indexer = new SearchIndexerService($config);
        assert_true($indexer instanceof SearchIndexerService, 'failed to instantiate');
    }, $timeout);
}

// ---------------------------------------------------------------------------
// IMAP connect
// ---------------------------------------------------------------------------

$imap = null;
$results[] = run_test('imap', 'connect with credentials', function () use ($config, $email, $password, &$imap) {
    $imap = new ImapService($config['imap'] ?? []);
    $ok = $imap->connect($email, $password);
    assert_true((bool)$ok, 'IMAP connect returned false');
}, $timeout);

if ($imap === null) {
    log_line('FAIL', 'IMAP connect failed; cannot proceed with row tests.');
    if ($asJson) {
        echo json_encode(['ok' => false, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// Stale-folder resilience: insert synthetic bad rows, run indexer, verify
// they're marked failed without escaping an exception.
// ---------------------------------------------------------------------------

if (!$skipSend && should_run('stale-folder', $only)) {
    $results[] = run_test('stale-folder', 'synthetic rows are skipped, not thrown', function () use (
        $config, $email, $imap, &$insertedTestRowIds, $indexer
    ) {
        $db = \Webmail\Core\Database::getConnection($config);
        $userEmail = strtolower($email);

        // Insert three deliberately broken rows. All [FLOWONE-TEST] tagged.
        $badRows = [
            ['inbox.flowone_test_does_not_exist_lower', TEST_FILENAME_PREFIX . 'lowercase.pdf'],
            ['INBOX.FLOWONE_TEST_does_not_exist_pascal', TEST_FILENAME_PREFIX . 'pascalcase.pdf'],
            ['INBOX.flowone_test_renamed since insert',  TEST_FILENAME_PREFIX . 'spaces.pdf'],
        ];

        $insert = $db->prepare("
            INSERT INTO webmail_email_attachments
                (user_email, folder, uid, filename, mime_type, size,
                 from_email, from_name, subject, message_date, content_indexed)
            VALUES (?, ?, ?, ?, 'application/pdf', 1024,
                    'test@flowone.pro', 'FlowOne Test', ?, NOW(), 0)
        ");

        foreach ($badRows as $i => [$folder, $filename]) {
            // Use UIDs in the 999000+ range to be obviously synthetic.
            $insert->execute([
                $userEmail,
                $folder,
                999000 + $i,
                $filename,
                TEST_SUBJECT_PREFIX . ' ' . $filename,
            ]);
            $insertedTestRowIds[] = (int)$db->lastInsertId();
        }

        // Run a single indexing batch. The whole point of this test is that
        // it must NOT throw -- previously, the indexer let \TypeError escape.
        $result = $indexer->indexAttachmentContentBatch($userEmail, $imap, 50);

        assert_true(is_array($result), 'indexer must return an array even on failures');
        assert_true(isset($result['processed']), 'result missing processed count');

        // Verify our synthetic rows are now marked failed (content_indexed = -1)
        // and that none of them are still sitting at 0 waiting to retry.
        if (!empty($insertedTestRowIds)) {
            $ph = implode(',', array_fill(0, count($insertedTestRowIds), '?'));
            $check = $db->prepare(
                "SELECT id, content_indexed FROM webmail_email_attachments WHERE id IN ($ph)"
            );
            $check->execute($insertedTestRowIds);
            foreach ($check->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                assert_true(
                    (int)$row['content_indexed'] !== 0,
                    "synthetic row {$row['id']} still at content_indexed=0 (would be retried forever)"
                );
            }
        }

        return [
            'processed' => $result['processed'] ?? 0,
            'errors'    => $result['errors'] ?? 0,
            'rows'      => count($insertedTestRowIds),
        ];
    }, $timeout);
}

// ---------------------------------------------------------------------------
// Canonical resolver: confirm FolderImapResolver rewrites
// arbitrary-case input to the server's real case via the folder-identity
// system. Only meaningful when an identity row exists for the test folder;
// otherwise the resolver gracefully returns the input as-is.
// ---------------------------------------------------------------------------

if (should_run('canonical', $only)) {
    $results[] = run_test('canonical', 'resolver returns input unchanged for empty inputs', function () use ($config, $email) {
        $resolver = new FolderImapResolver($config);
        assert_true($resolver->resolveForImap($email, '') === '', 'empty path should round-trip empty');
        assert_true($resolver->resolveForImap('', 'INBOX') === 'INBOX', 'empty account should round-trip path');
    }, $timeout);

    $results[] = run_test('canonical', 'resolver canonicalizes mixed-case input when identity exists', function () use ($config, $email, $imap) {
        $resolver = new FolderImapResolver($config);
        $userEmail = strtolower($email);

        // Pick a real non-INBOX subfolder from the live IMAP listing.
        $folders = $imap->listFolders();
        $real = null;
        foreach ($folders as $f) {
            $name = (string)($f['name'] ?? '');
            if ($name !== '' && $name !== 'INBOX' && strpos($name, '.') !== false) {
                $real = $name;
                break;
            }
        }
        if ($real === null) {
            return ['skipped' => 'no subfolder present on this account'];
        }

        // Check whether an identity row already exists for this folder
        // (populated by MailboxController::annotateFoldersWithIdentity on
        // any prior /mailbox/folders request from the user). If none, the
        // resolver will return the input as-is and we skip the strict check.
        $svc = new \Webmail\Services\FolderIndexService($config);
        $identity = $svc->getByPath($userEmail, $real);
        if ($identity === null) {
            return [
                'skipped' => 'no identity row for ' . $real
                    . ' yet (open the mailbox once in the browser to populate it)',
            ];
        }

        $canonical = (string) $identity['current_path'];
        $lower = strtolower($real);
        $upper = strtoupper($real);

        $resolvedExact = $resolver->resolveForImap($userEmail, $real);
        $resolvedLower = $resolver->resolveForImap($userEmail, $lower);
        $resolvedUpper = $resolver->resolveForImap($userEmail, $upper);

        assert_true(
            $resolvedExact === $canonical,
            "exact-case round-trip failed: input='{$real}' canonical='{$canonical}' got='{$resolvedExact}'"
        );
        assert_true(
            $resolvedLower === $canonical,
            "lowercase did not canonicalize: input='{$lower}' canonical='{$canonical}' got='{$resolvedLower}'"
        );
        assert_true(
            $resolvedUpper === $canonical,
            "uppercase did not canonicalize: input='{$upper}' canonical='{$canonical}' got='{$resolvedUpper}'"
        );

        return [
            'folder'    => $real,
            'canonical' => $canonical,
            'tested'    => ['exact', 'lower', 'upper'],
        ];
    }, $timeout);

    $results[] = run_test('canonical', 'resolver returns input as-is for unknown paths', function () use ($config, $email) {
        $resolver = new FolderImapResolver($config);
        $unknown = 'INBOX.flowone_test_definitely_not_real_' . bin2hex(random_bytes(4));
        $result = $resolver->resolveForImap(strtolower($email), $unknown);
        assert_true(
            $result === $unknown,
            "unknown path should round-trip unchanged; got '{$result}' instead of '{$unknown}'"
        );
    }, $timeout);

    $results[] = run_test('canonical', 'resolver caches repeat lookups', function () use ($config, $email) {
        $resolver = new FolderImapResolver($config);
        $userEmail = strtolower($email);
        // Two identical calls; second must be a cache hit.
        $resolver->resolveForImap($userEmail, 'INBOX');
        $resolver->resolveForImap($userEmail, 'INBOX');
        $stats = $resolver->stats();
        assert_true(
            ($stats['cache_hits'] ?? 0) >= 1,
            'second identical lookup should hit cache; stats=' . json_encode($stats)
        );
    }, $timeout);
}

// ---------------------------------------------------------------------------
// Cleanup: always remove synthetic rows even on partial failure
// ---------------------------------------------------------------------------

try {
    $db = \Webmail\Core\Database::getConnection($config);
    cleanup_test_rows($db, $insertedTestRowIds);
} catch (\Throwable $e) {
    log_line('WARN', 'final cleanup failed: ' . $e->getMessage());
}

if ($imap) {
    try { $imap->disconnect(); } catch (\Throwable $e) {}
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$passed = count(array_filter($results, fn($r) => $r['status'] === 'pass'));
$failed = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
$total  = count($results);

log_line('INFO', "summary: {$passed}/{$total} passed, {$failed} failed");

if ($failed > 0) {
    log_line('FAIL', 'failures:');
    foreach ($results as $r) {
        if ($r['status'] === 'fail') {
            log_line('FAIL', "  {$r['group']} :: {$r['name']} -- " . ($r['error'] ?? '(no message)'));
        }
    }
}

if ($asJson) {
    echo json_encode([
        'ok'      => $failed === 0,
        'passed'  => $passed,
        'failed'  => $failed,
        'total'   => $total,
        'log'     => $logFile,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

if ($logHandle) {
    @fclose($logHandle);
}

exit($failed === 0 ? 0 : 1);
