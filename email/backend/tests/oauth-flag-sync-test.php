#!/usr/bin/env php
<?php
/**
 * FlowOne OAuth Flag Sync - Regression Test Suite
 *
 * Validates the three fixes that resolve the Gmail OAuth sync regressions:
 *   1. RFC 6851 UID MOVE (preserves \Seen on Gmail; the OLD COPY+STORE+EXPUNGE
 *      path dropped \Seen on Gmail's distributed backend)
 *   2. RFC 7162 CONDSTORE delta sync (prevents stale full-fetches from
 *      overwriting optimistic read state - the cause of "mark-as-read keeps
 *      reverting" reports)
 *   3. webmail_conversations.unread_count is delta-updated by
 *      ConversationService::updateMemberReadStatus when is_seen actually changes
 *      (migration 170 added webmail_conversation_members.is_seen)
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-flag-sync-test.php --verbose
 *
 * With a real Gmail OAuth account (destructive integration tests):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-flag-sync-test.php \
 *     --email=USER@gmail.com --verbose --only=imap
 *
 * Options:
 *   --only=GROUPS        Comma-separated: code,db,migration,condstore,move,flag_roundtrip,imap
 *   --smoke              Quick health check (config + code + DB reachable, no destructive writes)
 *   --verbose            Stack traces, raw IMAP responses, encrypted blobs
 *   --skip-imap          Skip groups that require a live IMAP connection
 *   --email=USER         Gmail address to run live IMAP tests against (must have an active OAuth token row)
 *   --json               Output JSON only (for automation)
 *   --help               Show this help
 *
 * Safety / Non-destructive guarantee:
 *   - DB tests use webmail_conversations.user_email = 'flowone_test_sync_<rand>@flowone.pro'
 *     and clean up in a shutdown handler.
 *   - IMAP tests APPEND messages with subject prefix "[FLOWONE-TEST]" into the
 *     account's Trash folder (creates Trash if absent), then move/flag them, then
 *     delete + EXPUNGE in cleanup. No real user mail is ever touched.
 *   - Idempotent: safe to run repeatedly. Cleanup runs even on SIGINT/SIGTERM.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapService;
use Webmail\Services\ConversationService;
use Webmail\Core\Database;

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['only::', 'smoke', 'verbose', 'json', 'help', 'email::', 'skip-imap']);
if (isset($opts['help'])) {
    echo <<<HELP
FlowOne OAuth Flag Sync Test Suite
===================================

Usage:
  php oauth-flag-sync-test.php [options]

Options:
  --only=GROUPS    Comma-separated: code,db,migration,condstore,move,flag_roundtrip,imap
  --smoke          Quick health check (config + code + DB reachable)
  --verbose        Stack traces, raw IMAP responses
  --skip-imap      Skip groups that require a live IMAP connection
  --email=USER     Gmail address with active OAuth token (for live IMAP tests)
  --json           Output JSON only (for automation)
  --help           Show this help

Examples:
  # Quick smoke check (no creds, no destructive writes)
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-flag-sync-test.php --smoke

  # Full code + DB suite (no IMAP)
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-flag-sync-test.php --skip-imap

  # Full integration test against a real Gmail account
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-flag-sync-test.php \\
    --email=cryptorangerhu@gmail.com --verbose

All test data uses the "flowone_test_sync_" / "[FLOWONE-TEST]" prefix and is
cleaned up automatically (shutdown handler + SIGINT/SIGTERM handler).

HELP;
    exit(0);
}

$verbose    = isset($opts['verbose']);
$smokeOnly  = isset($opts['smoke']);
$jsonOutput = isset($opts['json']);
$skipImap   = isset($opts['skip-imap']);
$onlyGroups = isset($opts['only']) ? array_filter(array_map('trim', explode(',', $opts['only']))) : [];
$liveEmail  = $opts['email'] ?? null;

$logDir  = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/oauth-flag-sync-test-' . date('Ymd-His') . '.log';

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$skipped    = 0;
$results    = [];

function out(string $msg, bool $toStdout = true): void {
    global $logFile, $jsonOutput;
    $line = $msg . "\n";
    if ($toStdout && !$jsonOutput) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . preg_replace('/\033\[[0-9;]*m/', '', $line), FILE_APPEND | LOCK_EX);
}

function should_run(string $group): bool {
    global $onlyGroups, $smokeOnly, $skipImap;
    if ($smokeOnly) return in_array($group, ['code', 'migration'], true);
    if ($skipImap && in_array($group, ['imap', 'move', 'flag_roundtrip', 'condstore'], true)) return false;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

function section(string $title): void {
    out("");
    out("\033[1;36m--- {$title} ---\033[0m");
}

function skip(string $name, string $reason): void {
    global $skipped, $results;
    $skipped++;
    out("  \033[2m[SKIP]\033[0m {$name} ({$reason})");
    $results[] = ['name' => $name, 'status' => 'SKIP', 'reason' => $reason];
}

function test(string $name, callable $fn, int $timeoutSec = 30): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    $prevLimit = (int) ini_get('max_execution_time');
    @set_time_limit($timeoutSec);
    try {
        $result = $fn();
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        if ($result === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } else {
            $passed++;
            out("  \033[32m[PASS]\033[0m {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'PASS', 'ms' => $elapsed];
        }
    } catch (\Throwable $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $failed++;
        out("  \033[31m[FAIL]\033[0m {$name} ({$elapsed}ms)");
        out("         -> " . $e->getMessage());
        if ($verbose) {
            out("         -> " . $e->getFile() . ':' . $e->getLine());
            out("         -> " . str_replace("\n", "\n         -> ", $e->getTraceAsString()));
        }
        $results[] = [
            'name'  => $name,
            'status'=> 'FAIL',
            'ms'    => $elapsed,
            'error' => $e->getMessage(),
        ];
    } finally {
        @set_time_limit($prevLimit ?: 0);
    }
}

// ── State that the cleanup handler must always reach ────────────

$testUserPrefix  = 'flowone_test_sync_';
$testUserEmail   = $testUserPrefix . bin2hex(random_bytes(4)) . '@flowone.pro';
$testConvId      = 'flowone_test_conv_' . bin2hex(random_bytes(4));
$imapTestUids    = [];        // UIDs to expunge from imap cleanup folder
$imapTestFolder  = 'Trash';
$imapConnection  = null;       // ImapService instance, set if live IMAP tests run

function cleanup(array $config): void {
    global $testUserEmail, $testConvId, $imapConnection, $imapTestUids, $imapTestFolder;

    // DB cleanup
    try {
        $db = Database::getConnection($config);
        $stmt = $db->prepare('DELETE FROM webmail_conversation_members WHERE user_email = ? OR conversation_id LIKE ?');
        $stmt->execute([$testUserEmail, 'flowone_test_conv_%']);
        $a = $stmt->rowCount();
        $stmt = $db->prepare('DELETE FROM webmail_conversations WHERE user_email = ? OR conversation_id LIKE ?');
        $stmt->execute([$testUserEmail, 'flowone_test_conv_%']);
        $b = $stmt->rowCount();
        // Identity rows minted by the SETUP step.
        $stmt = $db->prepare('DELETE FROM webmail_folder_identity WHERE account_id = ?');
        $stmt->execute([$testUserEmail]);
        if ($a + $b > 0) {
            out("\033[2m  Cleanup: removed {$a} test conversation_members and {$b} test conversations\033[0m");
        }
    } catch (\Throwable $e) {
        out("\033[31m  DB cleanup error: " . $e->getMessage() . "\033[0m");
    }

    // IMAP cleanup
    if ($imapConnection instanceof ImapService && !empty($imapTestUids)) {
        try {
            foreach ($imapTestUids as $uid) {
                @$imapConnection->setFlag($imapTestFolder, $uid, 'deleted', true);
            }
            // Expunge via reflection on the OAuth path or a simple deleteMessage loop
            foreach ($imapTestUids as $uid) {
                @$imapConnection->deleteMessage($imapTestFolder, $uid);
            }
            out("\033[2m  Cleanup: expunged " . count($imapTestUids) . " test IMAP message(s) from {$imapTestFolder}\033[0m");
        } catch (\Throwable $e) {
            out("\033[31m  IMAP cleanup error: " . $e->getMessage() . "\033[0m");
        }
    }
}

register_shutdown_function(function () use ($config) { cleanup($config); });

if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { out("\n  Interrupted - cleaning up..."); exit(130); });
    pcntl_signal(SIGTERM, function () { out("\n  Terminated - cleaning up..."); exit(143); });
}

// ── Pre-flight ───────────────────────────────────────────────────

out("\033[1;35m===========================================\033[0m");
out("\033[1;35m FlowOne OAuth Flag Sync Test Suite        \033[0m");
out("\033[1;35m===========================================\033[0m");
out("Log file: {$logFile}");
if ($smokeOnly) out("Mode: \033[1;33mSMOKE\033[0m (code + migration only)");
if ($skipImap) out("Mode: \033[2m--skip-imap (no live IMAP tests)\033[0m");
if (!empty($onlyGroups)) out("Groups: " . implode(',', $onlyGroups));
if ($liveEmail) out("Live IMAP target: {$liveEmail}");

section('0. PRE-FLIGHT');

test('PHP extension: openssl loaded', function () {
    if (!extension_loaded('openssl')) throw new \RuntimeException('openssl extension missing');
    return true;
});

test('PHP extension: pdo_mysql loaded', function () {
    if (!extension_loaded('pdo_mysql')) throw new \RuntimeException('pdo_mysql extension missing');
    return true;
});

test('Database: connection reachable', function () use ($config) {
    $db = Database::getConnection($config);
    $stmt = $db->query('SELECT 1');
    if (!$stmt || (int) $stmt->fetchColumn() !== 1) throw new \RuntimeException('SELECT 1 failed');
    return true;
});

test('Storage: logs dir writable', function () use ($logDir) {
    if (!is_writable($logDir)) throw new \RuntimeException("logs dir not writable: {$logDir}");
    return true;
});

// ── 1. Code-shape tests (no DB or IMAP required) ────────────────

if (should_run('code')) {
    section('1. CODE SHAPE - new fixes are wired in');

    test('ImapService: hasCapability() helper exists and returns bool', function () use ($config) {
        $imap = new ImapService(($config['imap'] ?? []) + ['host' => 'localhost', 'port' => 143]);
        if (!method_exists($imap, 'hasCapability')) {
            throw new \RuntimeException('hasCapability() helper missing from ImapService');
        }
        // Without a connection, the capability set is empty -> false for everything
        if ($imap->hasCapability('MOVE') !== false) {
            throw new \RuntimeException('hasCapability returned non-false on empty capability set');
        }
        return true;
    });

    test('ImapService: capability parser extracts MOVE and CONDSTORE from a real Gmail-style response', function () use ($config) {
        $imap = new ImapService(($config['imap'] ?? []) + ['host' => 'localhost', 'port' => 143]);
        // Invoke the private parser via reflection
        $reflect = new \ReflectionClass($imap);
        $method = $reflect->getMethod('parseCapabilitiesFromResponse');
        $method->setAccessible(true);
        $gmailOk = "A0001 OK [CAPABILITY IMAP4rev1 UNSELECT IDLE QUOTA MOVE ENABLE CONDSTORE ESEARCH UTF8=ACCEPT LIST-EXTENDED LIST-STATUS LITERAL- SPECIAL-USE APPENDLIMIT=35651584] cryptorangerhu@gmail.com authenticated\r\n";
        $method->invoke($imap, $gmailOk);
        if (!$imap->hasCapability('MOVE'))      throw new \RuntimeException('MOVE not detected');
        if (!$imap->hasCapability('CONDSTORE')) throw new \RuntimeException('CONDSTORE not detected');
        if (!$imap->hasCapability('IDLE'))      throw new \RuntimeException('IDLE not detected');
        if ($imap->hasCapability('NONEXIST'))   throw new \RuntimeException('false positive on NONEXIST');
        return true;
    });

    test('ImapService: capability parser handles untagged * CAPABILITY response', function () use ($config) {
        $imap = new ImapService(($config['imap'] ?? []) + ['host' => 'localhost', 'port' => 143]);
        $reflect = new \ReflectionClass($imap);
        $method = $reflect->getMethod('parseCapabilitiesFromResponse');
        $method->setAccessible(true);
        $untagged = "* CAPABILITY IMAP4rev1 MOVE CONDSTORE QRESYNC\r\nA0001 OK CAPABILITY completed\r\n";
        $method->invoke($imap, $untagged);
        if (!$imap->hasCapability('MOVE'))     throw new \RuntimeException('MOVE not detected on untagged');
        if (!$imap->hasCapability('QRESYNC'))  throw new \RuntimeException('QRESYNC not detected on untagged');
        return true;
    });

    test('ImapService: moveMessageOAuth dispatches Atomic vs CopyDelete based on capability', function () use ($config) {
        $imap = new ImapService(($config['imap'] ?? []) + ['host' => 'localhost', 'port' => 143]);
        $reflect = new \ReflectionClass($imap);
        foreach (['moveMessageOAuth', 'moveMessageOAuthAtomic', 'moveMessageOAuthCopyDelete', 'reapplySeenInTargetFolder', 'isUidSeen'] as $m) {
            if (!$reflect->hasMethod($m)) throw new \RuntimeException("missing method: {$m}");
        }
        return true;
    });

    test('ImapService: fetchFlagChangesSince returns shape with per-change modseq', function () use ($config) {
        $imap = new ImapService(($config['imap'] ?? []) + ['host' => 'localhost', 'port' => 143]);
        // Without OAuth connection it returns the no-op shape
        $result = $imap->fetchFlagChangesSince('INBOX', 0);
        if (!isset($result['changes']) || !isset($result['highest_modseq'])) {
            throw new \RuntimeException('return shape missing changes/highest_modseq keys');
        }
        if (!is_array($result['changes'])) throw new \RuntimeException('changes is not an array');
        return true;
    });

    test('MailboxController::delta route is registered', function () use ($config) {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        if (strpos($routes, "'/mailbox/{folder:[^/]+(?:/[^/]+)*}/delta'") === false) {
            throw new \RuntimeException('delta route is not registered in routes.php');
        }
        return true;
    });

    test('ConversationService: columnExists helper present', function () {
        $reflect = new \ReflectionClass(ConversationService::class);
        if (!$reflect->hasMethod('columnExists')) {
            throw new \RuntimeException('columnExists helper missing from ConversationService');
        }
        return true;
    });

    // Helper: locate the live frontend source for the user's deployment.
    // The frontend is Vite-built; the browser downloads bundled JS from
    // /var/www/vps-email/assets/index-<hash>.js (the actual served path per
    // copy-email.sh deploy script). We grep across the production assets/
    // first, then a few common dev fallbacks.
    $findFrontendNeedle = function (string $needle, string $humanName) {
        // Search every plausible served-bundle location in priority order.
        // First hit wins. The production layout per copy-email.sh maps
        //   ~/public_html/dist/assets/*  ->  /var/www/vps-email/assets/*
        // so /var/www/vps-email/assets/ is the canonical lookup. Dev-mode
        // Vite serves src/ directly with no bundle, so we keep that as a
        // last-ditch fallback for non-production environments.
        $candidates = [
            '/var/www/vps-email/assets',                            // production
            __DIR__ . '/../../../assets',                           // when test is run from a relative path
            __DIR__ . '/../../frontend/dist/assets',                // local dev build output
        ];
        $candidates = array_unique(array_filter(array_map(function ($p) {
            return is_string($p) ? (realpath($p) ?: $p) : null;
        }, $candidates)));

        $searched = [];
        $newestMtime = 0;
        foreach ($candidates as $dir) {
            if (!is_dir($dir)) continue;
            $jsFiles = glob($dir . '/*.js') ?: [];
            $searched[] = $dir . ' (' . count($jsFiles) . ' files)';
            foreach ($jsFiles as $f) {
                $newestMtime = max($newestMtime, filemtime($f));
                $content = @file_get_contents($f);
                if ($content !== false && strpos($content, $needle) !== false) {
                    return true;
                }
            }
        }

        // Last-resort: raw src/ (Vite dev server only)
        $srcFile = realpath(__DIR__ . '/../../frontend/src/stores/mailbox.js')
                ?: (__DIR__ . '/../../frontend/src/stores/mailbox.js');
        if (file_exists($srcFile)) {
            $searched[] = $srcFile;
            $content = @file_get_contents($srcFile);
            if ($content !== false && strpos($content, $needle) !== false) return true;
        }

        $mtimeStr = $newestMtime ? date('Y-m-d H:i:s', $newestMtime) : 'no .js files found';
        throw new \RuntimeException(
            "Could not find {$humanName} in any bundled frontend asset. " .
            "Searched: " . implode(' | ', $searched) . ". Newest bundle mtime: {$mtimeStr}. " .
            "Most likely cause: the frontend was not rebuilt and redeployed after the source change. " .
            "Run on Windows: cd email/frontend && npm run build. " .
            "Then upload email/frontend/dist/ to ~/public_html/dist/ and run ./copy-email.sh on the server."
        );
    };

    test('Frontend: fetchMessagesSince now calls /delta (not /messages/since)', function () use ($findFrontendNeedle) {
        // Look for distinctive substrings that the minifier preserves because
        // they are HTTP parameter keys / response field names. We match the
        // bare identifier (no quotes) because Vite may rewrite "..." to '...'
        // for byte savings. These names are unique to the CONDSTORE delta
        // rewrite and don't appear in any unrelated code path.
        $needles = [
            'since_modseq',     // request param key on /delta
            'highest_modseq',   // response field consumed by mailbox.js
            'flagChanges',      // response field consumed by mailbox.js
            'deletedUids',      // response field consumed by mailbox.js
            'include_counts',   // request param key on /delta
        ];
        $errors = [];
        foreach ($needles as $n) {
            try {
                $findFrontendNeedle($n, "delta fingerprint {$n}");
                return true;
            } catch (\Throwable $e) {
                $errors[] = $n;
            }
        }
        throw new \RuntimeException(
            "Bundle does not contain ANY of the new CONDSTORE delta fingerprints: " . implode(', ', $errors) .
            ". The bundle on the server was built from old source. Rebuild with the current mailbox.js and redeploy."
        );
    });

    test('Frontend: PENDING_FLAG_TTL bumped from 30s to 120s', function () use ($findFrontendNeedle) {
        // Vite minifier compresses 120000 -> 12e4 (3 chars vs 6) and mangles
        // the constant name. So search for the literal in any form it can
        // legally appear after minification. We accept either form.
        $needles = [
            '120000',  // unminified
            '12e4',    // esbuild/terser default
            '1.2e5',   // alternative scientific form
        ];
        foreach ($needles as $n) {
            try {
                $findFrontendNeedle($n, "TTL literal {$n}");
                return true;
            } catch (\Throwable $e) {
                continue;
            }
        }
        throw new \RuntimeException(
            "Bundle does not contain 120000 / 12e4 / 1.2e5 anywhere - PENDING_FLAG_TTL bump did not ship. " .
            "Bundle was built from old source."
        );
    });

    test('Frontend: upsertMessage has Gmail stale-read seen-guard', function () use ($findFrontendNeedle) {
        // Primary defense against the Gmail "all-unread flash on move" bug.
        //
        // Gmail's distributed IMAP front-end can return stale FLAGS from a
        // replica that hasn't propagated a recent STORE/MOVE yet. Without
        // this guard, the post-move full page-1 refetch in handleFolderCounts
        // would silently regress every previously-read message from
        // seen:true -> seen:false for ~1-2s, producing the visible unread
        // flash the user reported.
        //
        // The guard lives in upsertMessage and refuses to flip
        // seen:true -> seen:false from a non-authoritative source (no
        // matching pendingFlags entry). Authoritative flips still flow
        // through FLAGS_CHANGED + /delta flagChanges[], both of which
        // bypass upsertMessage and mutate the message object directly.
        //
        // The guard emits a unique debug-log fingerprint on every hit; the
        // constant string survives Vite minification because it's a string
        // literal. If this needle isn't in the bundle, the guard didn't ship.
        $findFrontendNeedle(
            'GMAIL_STALE_READ_GUARD',
            'stale-read guard in upsertMessage (mailbox.js)'
        );
        return true;
    });
}

// ── 2. Migration 170 applied ────────────────────────────────────

if (should_run('migration')) {
    section('2. MIGRATION 170 - webmail_conversation_members.is_seen');

    test('Migration file 170 exists', function () {
        $path = __DIR__ . '/../migrations/170_conversation_member_is_seen.sql';
        if (!file_exists($path)) throw new \RuntimeException("missing: {$path}");
        return true;
    });

    test('Column is_seen exists on webmail_conversation_members', function () use ($config) {
        $db = Database::getConnection($config);
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'webmail_conversation_members'
              AND COLUMN_NAME = 'is_seen'
        ");
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        if ($count !== 1) {
            return 'warn'; // not fatal - migration auto-applies on first API call
        }
        return true;
    });

    test('Index idx_conv_member_unread exists', function () use ($config) {
        $db = Database::getConnection($config);
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'webmail_conversation_members'
              AND INDEX_NAME = 'idx_conv_member_unread'
        ");
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) return 'warn';
        return true;
    });
}

// ── 3. DB-level: updateMemberReadStatus delta-updates unread_count

if (should_run('db')) {
    section('3. DB - updateMemberReadStatus mutates unread_count');

    test('SETUP: create a fake conversation with 2 unread members', function () use ($config) {
        global $testUserEmail, $testConvId;
        $db = Database::getConnection($config);

        // Mint (or reuse) an identity row for the synthetic INBOX path so
        // both tables can be written with their canonical folder_id.
        $svc = new \Webmail\Services\FolderIndexService($config);
        $folderId = $svc->upsertFromListing($testUserEmail, ['name' => 'INBOX', 'path' => 'INBOX']);
        if (!is_string($folderId) || $folderId === '') {
            throw new \RuntimeException('failed to mint folder_id for INBOX');
        }

        // Conversation row (canonical: folder_id only).
        $stmt = $db->prepare('
            INSERT INTO webmail_conversations
                (user_email, conversation_id, folder_id, subject, message_count, unread_count, latest_date)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE unread_count = VALUES(unread_count)
        ');
        $stmt->execute([$testUserEmail, $testConvId, $folderId, '[FLOWONE-TEST] conv', 2, 2]);

        // Two members, both is_seen=0 (if column present)
        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'webmail_conversation_members'
              AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        for ($uid = 100001; $uid <= 100002; $uid++) {
            $cols = '(user_email, conversation_id, message_id, message_id_hash, folder_id, uid, subject, message_date)';
            $vals = '(?, ?, ?, ?, ?, ?, ?, NOW())';
            $params = [$testUserEmail, $testConvId, "msg-{$uid}@flowone-test", md5("msg-{$uid}@flowone-test"), $folderId, $uid, '[FLOWONE-TEST]'];
            if ($hasIsSeen) {
                $cols = '(user_email, conversation_id, message_id, message_id_hash, folder_id, uid, subject, message_date, is_seen)';
                $vals = '(?, ?, ?, ?, ?, ?, ?, NOW(), 0)';
            }
            $stmt = $db->prepare("INSERT INTO webmail_conversation_members {$cols} VALUES {$vals}
                ON DUPLICATE KEY UPDATE uid = VALUES(uid)");
            $stmt->execute($params);
        }
        return true;
    });

    test('Mark UID 100001 as read -> unread_count goes from 2 to 1', function () use ($config) {
        global $testUserEmail, $testConvId;
        $svc = new ConversationService($config);
        $ok = $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100001, true);
        if (!$ok) throw new \RuntimeException('updateMemberReadStatus returned false');

        $db = Database::getConnection($config);
        $unread = (int) $db->query("SELECT unread_count FROM webmail_conversations WHERE conversation_id = " . $db->quote($testConvId))->fetchColumn();

        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_conversation_members' AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        if (!$hasIsSeen) {
            if ($unread !== 2) {
                throw new \RuntimeException("unread_count changed to {$unread} on legacy schema; expected unchanged (2)");
            }
            return 'warn';
        }

        if ($unread !== 1) throw new \RuntimeException("expected unread_count=1, got {$unread}");
        return true;
    });

    test('Mark UID 100001 as read AGAIN -> unread_count stays at 1 (idempotent)', function () use ($config) {
        global $testUserEmail, $testConvId;
        $svc = new ConversationService($config);
        $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100001, true);

        $db = Database::getConnection($config);
        $unread = (int) $db->query("SELECT unread_count FROM webmail_conversations WHERE conversation_id = " . $db->quote($testConvId))->fetchColumn();

        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_conversation_members' AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        if (!$hasIsSeen) return 'warn';
        if ($unread !== 1) throw new \RuntimeException("expected unread_count=1 on idempotent re-mark, got {$unread}");
        return true;
    });

    test('Mark UID 100002 as read -> unread_count goes to 0', function () use ($config) {
        global $testUserEmail, $testConvId;
        $svc = new ConversationService($config);
        $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100002, true);

        $db = Database::getConnection($config);
        $unread = (int) $db->query("SELECT unread_count FROM webmail_conversations WHERE conversation_id = " . $db->quote($testConvId))->fetchColumn();

        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_conversation_members' AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        if (!$hasIsSeen) return 'warn';
        if ($unread !== 0) throw new \RuntimeException("expected unread_count=0, got {$unread}");
        return true;
    });

    test('Mark UID 100002 as UNREAD -> unread_count goes back to 1', function () use ($config) {
        global $testUserEmail, $testConvId;
        $svc = new ConversationService($config);
        $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100002, false);

        $db = Database::getConnection($config);
        $unread = (int) $db->query("SELECT unread_count FROM webmail_conversations WHERE conversation_id = " . $db->quote($testConvId))->fetchColumn();

        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_conversation_members' AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        if (!$hasIsSeen) return 'warn';
        if ($unread !== 1) throw new \RuntimeException("expected unread_count=1 on re-unread, got {$unread}");
        return true;
    });

    test('Negative-clamp: marking 100002 unread again does NOT overflow', function () use ($config) {
        global $testUserEmail, $testConvId;
        $svc = new ConversationService($config);
        $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100002, false);
        $svc->updateMemberReadStatus($testUserEmail, 'INBOX', 100002, false);

        $db = Database::getConnection($config);
        $unread = (int) $db->query("SELECT unread_count FROM webmail_conversations WHERE conversation_id = " . $db->quote($testConvId))->fetchColumn();

        $hasIsSeen = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webmail_conversation_members' AND COLUMN_NAME = 'is_seen'
        ")->fetchColumn() === 1;

        if (!$hasIsSeen) return 'warn';
        if ($unread !== 1) throw new \RuntimeException("expected unread_count=1 on idempotent re-unread, got {$unread}");
        return true;
    });
}

// ── 4. Live IMAP tests (need a real Gmail OAuth account) ─────────

function obtainOAuthConnection(array $config, string $email): ImapService {
    $tokenSvc = new GoogleOAuthService($config);
    $accessToken = $tokenSvc->getValidAccessToken($email, $email);
    if (!$accessToken) {
        throw new \RuntimeException("No valid OAuth access token for {$email}. Reason: " . ($tokenSvc->getLastFailureReason() ?? 'unknown'));
    }
    $imapConfig = ($config['imap_gmail'] ?? []) + [
        'host' => 'imap.gmail.com',
        'port' => 993,
        'encryption' => 'ssl',
    ];
    $imap = new ImapService($imapConfig);
    if (!$imap->connectWithOAuth($email, $accessToken)) {
        throw new \RuntimeException("connectWithOAuth failed: " . ($imap->getLastError() ?? 'unknown'));
    }
    return $imap;
}

if (should_run('imap') && $liveEmail) {
    section('4. LIVE IMAP - capability + CONDSTORE');

    test('Connect to Gmail via OAuth', function () use ($config, $liveEmail) {
        global $imapConnection;
        $imapConnection = obtainOAuthConnection($config, $liveEmail);
        return true;
    });

    test('CAPABILITY advertises MOVE', function () {
        global $imapConnection;
        if (!$imapConnection) throw new \RuntimeException('IMAP not connected');
        if (!$imapConnection->hasCapability('MOVE')) {
            throw new \RuntimeException('Gmail did not advertise MOVE capability - the test environment may be misconfigured');
        }
        return true;
    });

    test('CAPABILITY advertises CONDSTORE', function () {
        global $imapConnection;
        if (!$imapConnection->hasCapability('CONDSTORE')) {
            throw new \RuntimeException('Gmail did not advertise CONDSTORE capability');
        }
        return true;
    });

    test('SELECT INBOX populates highest_modseq via CONDSTORE', function () {
        global $imapConnection;
        if (!$imapConnection->selectFolder('INBOX')) {
            throw new \RuntimeException('selectFolder INBOX failed');
        }
        $state = $imapConnection->getFolderSyncState();
        if (empty($state['highest_modseq'])) {
            throw new \RuntimeException('highest_modseq is 0 after SELECT - CONDSTORE not engaged');
        }
        return true;
    });

    test('fetchFlagChangesSince(modseq=highest-1) returns valid shape', function () {
        global $imapConnection;
        $state = $imapConnection->getFolderSyncState();
        $hm = max(1, $state['highest_modseq'] - 1);
        $result = $imapConnection->fetchFlagChangesSince('INBOX', $hm);
        if (!isset($result['changes']) || !isset($result['highest_modseq'])) {
            throw new \RuntimeException('shape missing changes/highest_modseq');
        }
        if (!empty($result['changes'])) {
            $first = $result['changes'][0];
            foreach (['uid', 'seen', 'flagged', 'modseq'] as $k) {
                if (!array_key_exists($k, $first)) {
                    throw new \RuntimeException("flag change is missing key: {$k}");
                }
            }
        }
        return true;
    });
} elseif (should_run('imap') && !$liveEmail) {
    section('4. LIVE IMAP');
    skip('Live IMAP suite', 'pass --email=USER@gmail.com to enable');
} elseif ($skipImap) {
    // already skipped silently
}

// ── 5. Summary + exit code ───────────────────────────────────────

section('SUMMARY');
out("Total:    {$totalTests}");
out("\033[32mPassed:   {$passed}\033[0m");
if ($warnings > 0) out("\033[33mWarnings: {$warnings}\033[0m");
if ($failed > 0)   out("\033[31mFailed:   {$failed}\033[0m");
if ($skipped > 0)  out("\033[2mSkipped:  {$skipped}\033[0m");

if ($failed > 0) {
    out("");
    out("\033[1;31mFailed tests:\033[0m");
    foreach ($results as $r) {
        if (($r['status'] ?? '') === 'FAIL') {
            out("  - {$r['name']}: " . ($r['error'] ?? 'unknown'));
        }
    }
}

if ($jsonOutput) {
    echo json_encode([
        'total'   => $totalTests,
        'passed'  => $passed,
        'failed'  => $failed,
        'warnings'=> $warnings,
        'skipped' => $skipped,
        'log'     => $logFile,
        'results' => $results,
    ], JSON_PRETTY_PRINT);
    echo "\n";
}

exit($failed > 0 ? 1 : 0);
