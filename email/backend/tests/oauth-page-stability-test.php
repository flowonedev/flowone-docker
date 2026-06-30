#!/usr/bin/env php
<?php
/**
 * FlowOne OAuth Page Stability - Layer 1 Regression Test Suite
 *
 * Verifies the Layer 1 frontend defense against the Gmail-IMAP-replica
 * "page-1 flap" that causes "different months of emails disappear after I
 * move one email" on OAuth accounts.
 *
 * What this test enforces:
 *   1. Source has the OAuth view-stability guard in mailbox.js:
 *      - localPendingRemovals tracker (ref + helpers)
 *      - markLocalRemoval / consumeLocalRemovals / unmarkLocalRemoval
 *      - hasPendingLocalRemovals / isCurrentAccountOAuth helpers
 *      - guard branch in fetchMessages page-1 path
 *      - guard branch in fetchMessagesSince UIDVALIDITY flush
 *   2. Source has the integration-layer hookup in useMailSyncIntegration.js:
 *      - SYNC_OPTIONS passed through debouncedFetchMessages
 *      - SYNC_OPTIONS passed through debouncedIncrementalFetch fallback
 *      - markLocalRemoval called from handleMessageMoved + handleMessageDeleted
 *      - consume / suppress branch in handleConversationUpdated
 *      - consume / skip branch in handleFolderCounts (totalDecreased path)
 *   3. The deployed production bundle in /var/www/vps-email/assets/ contains
 *      the new guards (catches "forgot to npm run build" deploy mistakes).
 *   4. Live IMAP smoke (with --email): connect to the Gmail OAuth account
 *      and run two consecutive page-1 fetches against INBOX, reporting the
 *      overlap. A low overlap proves the bug exists at the IMAP layer and
 *      the frontend defense is doing real work.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php --verbose
 *
 * Smoke check (no live IMAP, no destructive ops):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php --smoke
 *
 * With a live Gmail OAuth account:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php \
 *     --email=USER@gmail.com --verbose
 *
 * Options:
 *   --only=GROUPS    Comma-separated: source,bundle,imap
 *   --smoke          Source + bundle only, no IMAP
 *   --verbose        Stack traces, raw IMAP details
 *   --skip-imap      Skip live IMAP checks
 *   --email=USER     Gmail OAuth account to probe for page-1 stability
 *   --skip-send      No-op (non-destructive by design); accepted for CLI conformance
 *   --json           Output JSON only (for automation)
 *   --help           Show this help
 *
 * Safety / non-destructive guarantee:
 *   - This test NEVER writes to IMAP. It only reads INBOX page-1 twice
 *     to measure overlap. No messages are appended, moved, flagged, or
 *     deleted.
 *   - No DB writes.
 *   - Per-test timeouts (default 30s) prevent a hung IMAP call from
 *     blocking the suite.
 *   - Idempotent: safe to run any number of times.
 *
 * Exit codes:
 *   0 - all tests passed
 *   1 - one or more tests failed
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapService;
use Webmail\Core\Database;

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['only::', 'smoke', 'verbose', 'json', 'help', 'email::', 'skip-imap', 'skip-send']);

if (isset($opts['help'])) {
    echo <<<HELP
FlowOne OAuth Page Stability Test Suite (Layer 1)
==================================================

Usage:
  php oauth-page-stability-test.php [options]

Options:
  --only=GROUPS    Comma-separated: source,bundle,imap
  --smoke          Source + bundle checks only (no live IMAP)
  --verbose        Show detailed failure info + IMAP UID lists
  --skip-imap      Skip live IMAP tests
  --email=USER     Gmail OAuth account for live page-1 stability probe
  --skip-send      No-op (this test is non-destructive)
  --json           Output JSON only
  --help           Show this help

Examples:
  # Smoke check after a deploy (source + bundle)
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php --smoke

  # Full check incl. live page-1 stability probe
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php \\
    --email=cryptorangerhu@gmail.com --verbose

  # Just the production bundle check
  /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/oauth-page-stability-test.php --only=bundle

This test reads files and performs read-only IMAP queries.
No data is modified on the server, the IMAP account, or the database.

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
$logFile = $logDir . '/oauth-page-stability-test-' . date('Ymd-His') . '.log';

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
    if ($smokeOnly && $group === 'imap') return false;
    if ($skipImap && $group === 'imap') return false;
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

/** Read a file into a string; throw if missing. */
function load_file(string $path): string {
    if (!is_file($path)) throw new \RuntimeException("file not found: {$path}");
    $contents = @file_get_contents($path);
    if ($contents === false) throw new \RuntimeException("cannot read: {$path}");
    return $contents;
}

/** Source/bundle file paths. Resolve repo path relative to this script so the
 *  test works in both the in-repo workspace and the deployed server tree.    */
function repo_path(string $relativeFromBackend): string {
    return realpath(__DIR__ . '/../' . $relativeFromBackend) ?: (__DIR__ . '/../' . $relativeFromBackend);
}

function frontend_src_path(string $relativeFromFrontendSrc): string {
    // Source on the server lives at /var/www/vps-email/frontend/src/...
    // The backend test is at /var/www/vps-email/backend/tests/, so we
    // walk up two and into frontend/src/. Falls back gracefully if the
    // server layout differs.
    $candidates = [
        __DIR__ . '/../../frontend/src/' . $relativeFromFrontendSrc,
        '/var/www/vps-email/frontend/src/' . $relativeFromFrontendSrc,
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) return $c;
    }
    return $candidates[0];
}

/**
 * Production deployments ship only the compiled bundle (frontend/dist
 * -> /var/www/vps-email/assets/) so frontend/src is normally absent.
 * The "source shape" group is meaningful in dev workspaces and CI, but
 * cannot run on a production server. Detect once at startup so the
 * whole group skips with a clear reason rather than reporting 11 file-
 * not-found failures.
 */
function frontend_source_available(): bool {
    return is_file(frontend_src_path('stores/mailbox.js'))
        && is_file(frontend_src_path('composables/useMailSyncIntegration.js'));
}

function bundle_root(): string {
    // Production deployed bundle path.
    return '/var/www/vps-email/assets';
}

/**
 * Authoritative resolver for the "active" main bundle.
 *
 * Vite emits multiple `index-<hash>.js` files in dist/assets per build:
 * the big main app bundle and several smaller dynamic-import chunks (also
 * confusingly named `index-<hash>.js` when their source module is named
 * `index.js`). When the user runs a `cp -r dist/assets/` everything lands
 * with the same mtime, so picking "the newest" by stat is undefined.
 *
 * The browser reads dist/index.html to find which hash to load -- that is
 * the single source of truth, and the file we must scan. We try a few
 * common locations because the deployed layout varies between dev/prod.
 *
 * Returns the absolute path to the active bundle, or null if it cannot be
 * resolved (caller should fall back to a best-effort glob).
 */
function active_main_bundle_path(string $bundleRoot): ?string {
    $htmlCandidates = [
        // Webserver document roots that commonly host the SPA index.html.
        dirname($bundleRoot) . '/index.html',  // /var/www/vps-email/index.html
        $bundleRoot . '/index.html',           // /var/www/vps-email/assets/index.html (rare)
    ];
    foreach ($htmlCandidates as $html) {
        if (!is_file($html)) continue;
        $contents = @file_get_contents($html);
        if (!$contents) continue;
        // Match the first <script ... src="...index-HASH.js"> reference.
        // This is the entry chunk the browser actually loads on boot.
        if (preg_match('#["\']\s*\.?\/?(?:assets\/)?(index-[A-Za-z0-9_-]+\.js)["\']#', $contents, $m)) {
            $candidate = $bundleRoot . '/' . $m[1];
            if (is_file($candidate)) return $candidate;
        }
    }
    return null;
}

// ── Pre-flight ───────────────────────────────────────────────────

out("\033[1;35m===========================================\033[0m");
out("\033[1;35m FlowOne OAuth Page Stability Test Suite   \033[0m");
out("\033[1;35m Layer 1 (frontend defense)                \033[0m");
out("\033[1;35m===========================================\033[0m");
out("Log file: {$logFile}");
if ($smokeOnly) out("Mode: \033[1;33mSMOKE\033[0m (source + bundle only)");
if ($skipImap)  out("Mode: \033[2m--skip-imap (no live IMAP)\033[0m");
if (!empty($onlyGroups)) out("Groups: " . implode(',', $onlyGroups));
if ($liveEmail) out("Live IMAP target: {$liveEmail}");

section('0. PRE-FLIGHT');

test('PHP extension: openssl loaded', function () {
    if (!extension_loaded('openssl')) throw new \RuntimeException('openssl extension missing');
    return true;
});
test('Storage: logs dir writable', function () use ($logDir) {
    if (!is_writable($logDir)) throw new \RuntimeException("logs dir not writable: {$logDir}");
    return true;
});

// ── 1. Source shape ────────────────────────────────────────────────

if (should_run('source') && !frontend_source_available()) {
    section('1. SOURCE SHAPE - Layer 1 guards wired into frontend src');
    $skipReason = 'frontend/src not deployed -- production servers ship only the compiled bundle; run this group from your dev workspace';
    skip('mailbox.js: localPendingRemovals tracker defined',                                                  $skipReason);
    skip('mailbox.js: markLocalRemoval / consumeLocalRemovals / unmarkLocalRemoval all present',              $skipReason);
    skip('mailbox.js: isCurrentAccountOAuth helper exists with Gmail folder fallback',                        $skipReason);
    skip('mailbox.js: useAccountsStore imported (no runtime require())',                                      $skipReason);
    skip('mailbox.js: fetchMessages page-1 guard is always-on for OAuth + pending removals',                  $skipReason);
    skip('mailbox.js: fetchMessages caller-trace log present (debug aid)',                                    $skipReason);
    skip('mailbox.js: inline UIDVALIDITY flush in fetchMessages respects pending local removals',             $skipReason);
    skip('mailbox.js: fetchMessagesSince UIDVALIDITY branch respects pending local removals',                 $skipReason);
    skip('mailbox.js: helpers exported from the Pinia store return block',                                    $skipReason);
    skip('useMailSyncIntegration.js: SYNC_OPTIONS plumbed through debouncedFetchMessages',                    $skipReason);
    skip('useMailSyncIntegration.js: handleConversationUpdated has OAuth+pending suppression branch',         $skipReason);
    skip('useMailSyncIntegration.js: handleFolderCounts consumes local removals before refresh',              $skipReason);
    skip('useMailSyncIntegration.js: handleFolderCounts fast-path skips when only unread changed',            $skipReason);
    skip('useMailSyncIntegration.js: handleFlagsChanged skip path does NOT re-enqueue folder fetch',          $skipReason);
    skip('useMailSyncIntegration.js: handleMessageMoved and handleMessageDeleted bump the counter',           $skipReason);
    skip('FolderTree.vue: selectFolder has freshness gate (FOLDER_CLICK_FRESH_WINDOW_MS)',                    $skipReason);
    out("\033[2m  Tip: bundle group (group 2) covers the production-side equivalent assertion.\033[0m");
} elseif (should_run('source')) {
    section('1. SOURCE SHAPE - Layer 1 guards wired into frontend src');

    test('mailbox.js: localPendingRemovals tracker defined', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (!preg_match('/localPendingRemovals\s*=\s*ref\(/', $src)) {
            throw new \RuntimeException('localPendingRemovals ref not found in mailbox.js');
        }
        if (strpos($src, 'REMOVAL_TTL') === false) {
            throw new \RuntimeException('REMOVAL_TTL constant missing -- counter could leak forever');
        }
        return true;
    });

    test('mailbox.js: markLocalRemoval / consumeLocalRemovals / unmarkLocalRemoval all present', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        foreach (['markLocalRemoval', 'consumeLocalRemovals', 'unmarkLocalRemoval', 'hasPendingLocalRemovals'] as $sym) {
            if (!preg_match('/function\s+' . preg_quote($sym, '/') . '\s*\(/', $src)) {
                throw new \RuntimeException("function {$sym} missing from mailbox.js");
            }
        }
        return true;
    });

    test('mailbox.js: isCurrentAccountOAuth helper exists with Gmail folder fallback', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (!preg_match('/function\s+isCurrentAccountOAuth\s*\(/', $src)) {
            throw new \RuntimeException('isCurrentAccountOAuth helper missing');
        }
        if (strpos($src, "'[Gmail]/'") === false && strpos($src, '"[Gmail]/"') === false) {
            throw new \RuntimeException('Gmail folder-namespace fallback missing -- primary OAuth login would be misdetected');
        }
        if (strpos($src, 'useAccountsStore') === false) {
            throw new \RuntimeException('accounts store not consulted -- secondary OAuth detection would fail');
        }
        return true;
    });

    test('mailbox.js: useAccountsStore imported (no runtime require())', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (!preg_match('/import\s*\{[^}]*useAccountsStore[^}]*\}\s*from\s*[\'"]@\/stores\/accounts[\'"]/', $src)) {
            throw new \RuntimeException('useAccountsStore not imported -- Vite would crash on dynamic require()');
        }
        return true;
    });

    test('mailbox.js: fetchMessages page-1 guard is always-on for OAuth + pending removals', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (strpos($src, 'Suppressed page-1 replace') === false) {
            throw new \RuntimeException('expected log line "Suppressed page-1 replace" missing');
        }
        // Make sure the gate is NOT options.suppressIfLocallyConsistent anymore.
        // Earlier versions required `options.suppressIfLocallyConsistent === true`
        // to enter the guard, which let every direct fetchMessages(folder, 1)
        // call from revalidateActiveFolder / route watchers / refresh button
        // bypass the protection. The new always-on variant gates on OAuth +
        // pendingRemovals only.
        if (preg_match('/page\s*===\s*1\s*&&\s*options\.suppressIfLocallyConsistent\s*===\s*true\s*&&/', $src)) {
            throw new \RuntimeException('page-1 guard still gated on options.suppressIfLocallyConsistent -- direct callers will bypass it');
        }
        if (!preg_match('/page\s*===\s*1\s*&&\s*isCurrentAccountOAuth\(\)/', $src)) {
            throw new \RuntimeException('page-1 guard no longer entered via isCurrentAccountOAuth -- did the always-on variant ship?');
        }
        // The 50% overlap threshold gate was removed in the no-threshold
        // variant. Live testing showed Gmail's replica flap often returns
        // 60-80% overlap with the existing view (mostly-the-same top-25
        // with a few swapped UIDs), which is still visually disruptive
        // but did NOT trip the < 50% gate. The pendingRemovals TTL (30s)
        // is the right safety valve. Overlap is now computed for the log
        // only, never used as a condition.
        if (preg_match('/OAUTH_FLAP_OVERLAP_THRESHOLD/', $src)) {
            throw new \RuntimeException('OAUTH_FLAP_OVERLAP_THRESHOLD constant still present -- guard is gated on overlap, which lets Gmail flap through when 60-80% similar');
        }
        if (!preg_match('/pending-removal TTL active, view trusted/', $src)) {
            throw new \RuntimeException('expected log substring "pending-removal TTL active, view trusted" missing -- did the no-threshold variant ship?');
        }
        return true;
    });

    test('mailbox.js: fetchMessages caller-trace log present (debug aid)', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (strpos($src, 'fetchMessages page-1 call') === false) {
            throw new \RuntimeException('caller-trace log missing -- cannot diagnose rogue page-1 callers');
        }
        return true;
    });

    test('mailbox.js: inline UIDVALIDITY flush in fetchMessages respects pending local removals', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (strpos($src, 'Suppressed inline UIDVALIDITY flush') === false) {
            throw new \RuntimeException('inline UIDVALIDITY-flap suppression branch missing from fetchMessages');
        }
        return true;
    });

    test('mailbox.js: fetchMessagesSince UIDVALIDITY branch respects pending local removals', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        if (strpos($src, 'Suppressed UIDVALIDITY flush') === false) {
            throw new \RuntimeException('UIDVALIDITY-flap suppression branch not found in fetchMessagesSince');
        }
        return true;
    });

    test('mailbox.js: helpers exported from the Pinia store return block', function () {
        $src = load_file(frontend_src_path('stores/mailbox.js'));
        foreach (['markLocalRemoval', 'consumeLocalRemovals', 'unmarkLocalRemoval', 'hasPendingLocalRemovals', 'isCurrentAccountOAuth'] as $sym) {
            // Exported entries appear as bare identifiers in the return object
            if (!preg_match('/^\s*' . preg_quote($sym, '/') . '\s*,?\s*$/m', $src)) {
                throw new \RuntimeException("{$sym} not exported from mailbox store -- integration layer cannot call it");
            }
        }
        return true;
    });

    test('useMailSyncIntegration.js: SYNC_OPTIONS plumbed through debouncedFetchMessages', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        if (strpos($src, 'SYNC_OPTIONS') === false) {
            throw new \RuntimeException('SYNC_OPTIONS constant missing -- sync-triggered fetches not flagged');
        }
        if (!preg_match('/suppressIfLocallyConsistent\s*:\s*true/', $src)) {
            throw new \RuntimeException('SYNC_OPTIONS payload does not set suppressIfLocallyConsistent: true');
        }
        if (!preg_match('/fetchMessages\([^)]*SYNC_OPTIONS\)/', $src)) {
            throw new \RuntimeException('fetchMessages call inside debouncedFetchMessages does not pass SYNC_OPTIONS');
        }
        return true;
    });

    test('useMailSyncIntegration.js: handleConversationUpdated has OAuth+pending suppression branch', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        if (strpos($src, 'Suppressed CONVERSATION_UPDATED') === false) {
            throw new \RuntimeException('CONVERSATION_UPDATED suppression branch missing');
        }
        if (strpos($src, 'isCurrentAccountOAuth') === false) {
            throw new \RuntimeException('OAuth gate not checked in CONVERSATION_UPDATED handler');
        }
        return true;
    });

    test('useMailSyncIntegration.js: handleFolderCounts consumes local removals before refresh', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        if (strpos($src, 'consumeLocalRemovals') === false) {
            throw new \RuntimeException('consumeLocalRemovals not invoked in handleFolderCounts');
        }
        if (strpos($src, 'fully consumed by local removals') === false) {
            throw new \RuntimeException('skip-refresh log line missing -- earlier fix may have regressed');
        }
        return true;
    });

    test('useMailSyncIntegration.js: handleMessageMoved and handleMessageDeleted bump the counter', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        // Both handlers should call markLocalRemoval after the local removeMessageFromList.
        $matches = preg_match_all('/markLocalRemoval/', $src);
        if ($matches < 2) {
            throw new \RuntimeException("markLocalRemoval found only {$matches} times -- expected >= 2 (move + delete handlers)");
        }
        return true;
    });

    test('useMailSyncIntegration.js: handleFolderCounts fast-path skips when only unread changed', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        // The fast-path branch returns early when folderChanged is false
        // (no uidnext advance, no UIDVALIDITY change, no total decrease).
        // Without this, Gmail-side bulk-read storms (50+ events) trash the
        // UI even though no message-list change actually happened.
        if (strpos($src, 'Fast-path: only the unread counter changed') === false) {
            throw new \RuntimeException('handleFolderCounts fast-path branch missing -- unread-only storms will lag the UI');
        }
        return true;
    });

    test('useMailSyncIntegration.js: handleFlagsChanged skip path does NOT re-enqueue folder fetch', function () {
        $src = load_file(frontend_src_path('composables/useMailSyncIntegration.js'));
        // The "Skipping FLAGS_CHANGED for pending flags" branch used to
        // call debouncedFetchFolders before returning. That call has been
        // removed; FOLDER_COUNTS already covers the count refresh path.
        if (!preg_match('/Skipping FLAGS_CHANGED for pending flags[\s\S]{0,400}return/', $src)) {
            throw new \RuntimeException('handleFlagsChanged skip branch is missing or no longer returns immediately');
        }
        if (preg_match('/Skipping FLAGS_CHANGED for pending flags[\s\S]{0,200}debouncedFetchFolders/', $src)) {
            throw new \RuntimeException('handleFlagsChanged skip branch still calls debouncedFetchFolders -- 50+ event storms will redundantly poll /folders');
        }
        return true;
    });

    test('FolderTree.vue: selectFolder has freshness gate (FOLDER_CLICK_FRESH_WINDOW_MS)', function () {
        $src = load_file(frontend_src_path('components/FolderTree.vue'));
        if (strpos($src, 'FOLDER_CLICK_FRESH_WINDOW_MS') === false) {
            throw new \RuntimeException('selectFolder freshness gate constant missing -- repeated Inbox clicks will refetch every time');
        }
        if (strpos($src, 'getLastRefreshed') === false) {
            throw new \RuntimeException('selectFolder does not consult mailbox.getLastRefreshed -- freshness gate inert');
        }
        if (strpos($src, 'Skipping refetch for') === false) {
            throw new \RuntimeException('expected debug log "Skipping refetch for" missing from selectFolder');
        }
        return true;
    });
}

// ── 2. Production bundle shape ───────────────────────────────────

if (should_run('bundle')) {
    section('2. DEPLOYED BUNDLE - Layer 1 guards compiled into production assets');

    $bundleRoot = bundle_root();

    test('Production assets directory exists', function () use ($bundleRoot) {
        if (!is_dir($bundleRoot)) {
            throw new \RuntimeException("bundle dir missing: {$bundleRoot} (this is fine in dev, but means nothing is deployed)");
        }
        return true;
    });

    test('Active production bundle contains the Layer 1 markers', function () use ($bundleRoot) {
        if (!is_dir($bundleRoot)) {
            return 'warn'; // dev environment, no deployed bundle to check
        }

        // Vite code-splits aggressively: any component imported only by a
        // lazy-loaded route (e.g. FolderTree.vue, which is only used by
        // MailboxView, a route chunk) ends up in that route's chunk, NOT
        // in the main index-*.js bundle. Likewise, store-level helpers
        // imported eagerly from main land in index-*.js. So a single
        // "find the main bundle and scan it" strategy will inevitably
        // miss markers that legitimately shipped to a different chunk.
        //
        // We scan ALL JS files under the bundle root and ask "does any
        // shipped chunk contain this marker?". That matches what the
        // browser ultimately loads -- if the marker is anywhere in
        // dist/assets, the code WILL be evaluated at runtime once the
        // owning chunk is imported.
        $jsFiles = glob($bundleRoot . '/*.js') ?: [];
        if (empty($jsFiles)) {
            throw new \RuntimeException("no JS bundles found under {$bundleRoot}");
        }

        $required = [
            'Suppressed page-1 replace',
            'Suppressed UIDVALIDITY flush',
            'Suppressed inline UIDVALIDITY flush',
            'Suppressed CONVERSATION_UPDATED',
            'fully consumed by local removals',
            'fetchMessages page-1 call',
            'pending-removal TTL active, view trusted',
            'Skipping refetch for',
        ];
        $forbidden = [
            // The old overlap-threshold gate was removed. If this constant
            // resurfaces in any chunk, someone shipped a stale variant.
            'OAUTH_FLAP_OVERLAP_THRESHOLD',
        ];

        $missing = array_fill_keys($required, true);  // marker => still-missing?
        $foundIn = [];                                 // marker => chunk file
        $forbiddenHits = [];                           // forbidden token => chunk file

        foreach ($jsFiles as $jsFile) {
            $contents = @file_get_contents($jsFile);
            if ($contents === false || strlen($contents) === 0) continue;

            foreach ($required as $needle) {
                if ($missing[$needle] && strpos($contents, $needle) !== false) {
                    $missing[$needle] = false;
                    $foundIn[$needle] = basename($jsFile);
                }
            }
            foreach ($forbidden as $token) {
                if (strpos($contents, $token) !== false) {
                    $forbiddenHits[$token] = basename($jsFile);
                }
            }
        }

        $stillMissing = array_keys(array_filter($missing));
        if (!empty($stillMissing)) {
            throw new \RuntimeException(
                "no shipped chunk under " . $bundleRoot . " contains these Layer 1 markers: "
                . implode(', ', $stillMissing)
                . " -- did `npm run build` run after the source edit, and did the new dist/ get uploaded?"
            );
        }
        if (!empty($forbiddenHits)) {
            $details = [];
            foreach ($forbiddenHits as $tok => $chunk) {
                $details[] = "{$tok} in {$chunk}";
            }
            throw new \RuntimeException(
                "forbidden tokens still present in shipped chunks: " . implode(', ', $details)
                . " -- the obsolete variant is deployed; rebuild + redeploy after pulling the latest fix"
            );
        }
        return true;
    });
}

// ── 3. Live OAuth page-1 stability probe ──────────────────────────

if (should_run('imap')) {
    section('3. LIVE IMAP - page-1 stability probe against Gmail OAuth');

    if (!$liveEmail) {
        skip('Live page-1 stability probe', 'requires --email=USER (Gmail OAuth account)');
    } else {
        test('OAuth token row exists for ' . $liveEmail, function () use ($config, $liveEmail) {
            $db = Database::getConnection($config);
            $stmt = $db->prepare(
                'SELECT 1 FROM webmail_oauth_tokens
                 WHERE LOWER(TRIM(oauth_email)) = LOWER(TRIM(?))
                   AND provider = "google"
                 LIMIT 1'
            );
            $stmt->execute([$liveEmail]);
            if ($stmt->fetchColumn() === false) {
                throw new \RuntimeException("no Google OAuth token row for {$liveEmail}");
            }
            return true;
        }, 10);

        $imap = null;
        $accessToken = null;

        test('Obtain access token for ' . $liveEmail, function () use ($config, $liveEmail, &$accessToken) {
            $oauth = new GoogleOAuthService($config);
            // Token owner = oauth_email for primary OAuth, the primary_email otherwise.
            // We try oauth_email first (most common case for these tests).
            $accessToken = $oauth->getValidAccessToken($liveEmail, $liveEmail);
            if (!$accessToken) {
                $reason = method_exists($oauth, 'getLastFailureReason') ? ($oauth->getLastFailureReason() ?? 'unknown') : 'unknown';
                throw new \RuntimeException("getValidAccessToken returned null (reason: {$reason})");
            }
            return true;
        }, 15);

        test('XOAUTH2 IMAP authenticate to imap.gmail.com', function () use ($config, $liveEmail, &$accessToken, &$imap) {
            if (!$accessToken) {
                throw new \RuntimeException('no access token (previous test failed)');
            }
            $imap = new ImapService([
                'host'          => 'imap.gmail.com',
                'port'          => 993,
                'encryption'    => 'ssl',
                'validate_cert' => false,
            ]);
            if (!$imap->connectWithOAuth($liveEmail, $accessToken)) {
                $err = method_exists($imap, 'getLastError') ? $imap->getLastError() : 'unknown';
                throw new \RuntimeException("XOAUTH2 IMAP failed: {$err}");
            }
            return true;
        }, 15);

        test('Page-1 stability: two consecutive INBOX fetches', function () use (&$imap, $verbose) {
            if (!$imap) {
                throw new \RuntimeException('no IMAP connection (previous test failed)');
            }

            $r1 = $imap->getMessages('INBOX', 1, 25);
            $r2 = $imap->getMessages('INBOX', 1, 25);

            if (empty($r1['messages']) || empty($r2['messages'])) {
                throw new \RuntimeException('one of the page-1 fetches returned no messages');
            }

            $uids1 = array_map(fn($m) => (int) ($m['uid'] ?? 0), $r1['messages']);
            $uids2 = array_map(fn($m) => (int) ($m['uid'] ?? 0), $r2['messages']);
            $set1  = array_flip($uids1);
            $set2  = array_flip($uids2);

            $overlap = 0;
            foreach ($uids1 as $u) {
                if (isset($set2[$u])) $overlap++;
            }
            $overlapRatio = $overlap / max(count($uids1), count($uids2));

            if ($verbose) {
                out("         -> fetch 1 uids: " . implode(',', $uids1));
                out("         -> fetch 2 uids: " . implode(',', $uids2));
                out("         -> overlap: {$overlap}/" . max(count($uids1), count($uids2)) . " = " . round($overlapRatio * 100) . '%');
            }

            if ($overlapRatio < 0.5) {
                // This is informational, not a failure: a flap proves the bug
                // the Layer 1 defense is built to handle. Return 'warn' so
                // operators see it.
                out("         -> Gmail replica flap observed (overlap " . round($overlapRatio * 100) . "%) -- Layer 1 defense is doing real work");
                return 'warn';
            }
            return true;
        }, 30);
    }
}

// ── Summary ──────────────────────────────────────────────────────

out('');
out("\033[1;35m===========================================\033[0m");
if ($failed === 0) {
    out("\033[1;32m ALL PASSED \033[0m: {$passed} passed, {$warnings} warnings, {$skipped} skipped / {$totalTests} total");
} else {
    out("\033[1;31m FAILED \033[0m: {$failed} failed, {$warnings} warnings, {$skipped} skipped / {$totalTests} total");
    out('');
    out('Failed tests:');
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  - {$r['name']}: {$r['error']}");
        }
    }
}
out("Log: {$logFile}");
out("\033[1;35m===========================================\033[0m");

if ($jsonOutput) {
    echo json_encode([
        'total'    => $totalTests,
        'passed'   => $passed,
        'failed'   => $failed,
        'warnings' => $warnings,
        'skipped'  => $skipped,
        'log'      => $logFile,
        'results'  => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
