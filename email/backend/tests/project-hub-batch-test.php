#!/usr/bin/env php
<?php
/**
 * project-hub-batch-test.php — verifies the Project Hub batch endpoints
 * that replaced per-id N+1 loops in EnhancedComments, CardAssigneesPanel,
 * and FolderTaskView.
 *
 * Tested batch surfaces:
 *   - ProjectHubWorkTrackingService::getCommentReactionsBatch (single
 *     GROUP BY query for many comments)
 *   - ProjectHubWorkTrackingService::addAssigneesBatch (multi-row
 *     INSERT IGNORE for many emails)
 *   - BoardController::batchFetch route registration
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/project-hub-batch-test.php \
 *       --email=user@flowone.pro --password=PASS --verbose
 *
 * Options:
 *   --email=EMAIL       Test account email (required for DB groups)
 *   --password=PASS     Test account password (required for DB groups)
 *   --only=GROUPS       Comma-separated: structural,reactions,assignees
 *   --smoke             Pre-flight only
 *   --json              Output JSON
 *   --verbose           Extra debug output
 *   --help              Show this help
 *
 * Exit 0 on all pass, 1 on any failure.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'password:', 'only:', 'smoke', 'json', 'verbose', 'help']);

if (isset($opts['help'])) {
    echo "FlowOne Project Hub Batch Test Suite\n";
    echo "=====================================\n\n";
    echo "Usage:\n";
    echo "  php project-hub-batch-test.php --email=user@flowone.pro --password=PASS [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL       Test account email\n";
    echo "  --password=PASS     Test account password\n";
    echo "  --only=GROUPS       structural,reactions,assignees\n";
    echo "  --smoke             Pre-flight only\n";
    echo "  --json              Output JSON\n";
    echo "  --verbose           Extra debug output\n";
    echo "  --help              Show this help\n";
    exit(0);
}

if (empty($opts['email']) || empty($opts['password'])) {
    fwrite(STDERR, "ERROR: --email and --password are required. Use --help.\n");
    exit(1);
}

$testEmail    = strtolower($opts['email']);
$testPassword = $opts['password'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$jsonOutput   = isset($opts['json']);
$onlyGroups   = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return false;
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups, true);
}

$logFile = __DIR__ . '/../storage/logs/project-hub-batch-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$totalTests = 0;
$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

function out(string $msg): void {
    global $logFile, $jsonOutput;
    $line = $msg . "\n";
    if (!$jsonOutput) echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}
function vlog(string $m): void { global $verbose; if ($verbose) out("          [v] $m"); }
function assert_true(bool $c, string $msg = 'Assertion failed'): void {
    if (!$c) throw new \RuntimeException($msg);
}
function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ?: 'Values differ') . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $r = $fn();
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        if ($r === 'warn') {
            $warnings++;
            out("  \033[33m[WARN]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'WARN', 'ms' => $elapsed];
        } elseif ($r === 'skip') {
            out("  \033[36m[SKIP]\033[0m  {$name} ({$elapsed}ms)");
            $results[] = ['name' => $name, 'status' => 'SKIP', 'ms' => $elapsed];
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
        if ($verbose) out("          at " . $e->getFile() . ':' . $e->getLine());
        $results[] = ['name' => $name, 'status' => 'FAIL', 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

// Cleanup tracking ----------------------------------------------------

$TEST_TAG = '[FLOWONE-PHBATCH]';
$seededCardId = null;
$seededCommentIds = [];

function doCleanup(): void {
    global $config, $seededCardId, $seededCommentIds, $testEmail;
    out("\n--- CLEANUP ---");
    try {
        $db = \Webmail\Core\Database::getConnection($config);

        if (!empty($seededCommentIds)) {
            $ph = implode(',', array_fill(0, count($seededCommentIds), '?'));
            $stmt = $db->prepare("DELETE FROM projecthub_comment_reactions WHERE comment_id IN ({$ph})");
            $stmt->execute($seededCommentIds);
            $stmt = $db->prepare("DELETE FROM webmail_card_comments WHERE id IN ({$ph})");
            $stmt->execute($seededCommentIds);
        }
        if ($seededCardId) {
            $stmt = $db->prepare("DELETE FROM projecthub_card_assignees WHERE card_id = ?");
            $stmt->execute([$seededCardId]);
        }
    } catch (\Throwable $e) {
        out("  [WARN] cleanup: " . $e->getMessage());
    }
    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// =====================================================================

out("=================================================================");
out("  FlowOne Project Hub Batch Test Suite");
out("  " . date('Y-m-d H:i:s T'));
out("  Account: {$testEmail}");
out("  Log:     {$logFile}");
out("=================================================================\n");

// =====================================================================
// PRE-FLIGHT
// =====================================================================

out("--- PRE-FLIGHT ---");

test('PHP extension: pdo_mysql', function () {
    assert_true(extension_loaded('pdo_mysql'), 'pdo_mysql not loaded');
});

$db = null;
test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'Not a PDO instance');
});

test('Required tables present', function () use (&$db) {
    foreach (['webmail_card_comments', 'projecthub_comment_reactions', 'projecthub_card_assignees', 'webmail_board_cards', 'webmail_board_lists', 'webmail_boards'] as $tbl) {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
        assert_true($stmt->fetch() !== false, "Missing table: $tbl");
    }
});

test('ProjectHubWorkTrackingService class exists', function () {
    assert_true(class_exists('\\Webmail\\Addons\\ProjectHub\\Services\\ProjectHubWorkTrackingService'), 'missing');
});

if ($smokeOnly) {
    out("\n--- SMOKE MODE complete ---");
    out("Result: passed={$passed} failed={$failed}");
    exit($failed > 0 ? 1 : 0);
}

// =====================================================================
// 1. STRUCTURAL
// =====================================================================

if (shouldRun('structural')) {
    out("\n--- 1. STRUCTURAL ---");

    test('Service has getCommentReactionsBatch', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\ProjectHub\\Services\\ProjectHubWorkTrackingService');
        assert_true($rc->hasMethod('getCommentReactionsBatch'), 'method missing');
    });

    test('Service has addAssigneesBatch', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\ProjectHub\\Services\\ProjectHubWorkTrackingService');
        assert_true($rc->hasMethod('addAssigneesBatch'), 'method missing');
    });

    test('Controller has getReactionsBatch + addAssigneesBatch', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\ProjectHub\\Controllers\\ProjectHubController');
        assert_true($rc->hasMethod('getReactionsBatch'), 'getReactionsBatch missing');
        assert_true($rc->hasMethod('addAssigneesBatch'), 'addAssigneesBatch missing');
    });

    test('BoardController has batchFetch', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\KanbanBoards\\Controllers\\BoardController');
        assert_true($rc->hasMethod('batchFetch'), 'batchFetch missing');
    });

    test('Routes registered for all 3 batch endpoints', function () {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        assert_true(str_contains($routes, '/project-hub/comments/reactions/batch'), 'reactions batch route missing');
        assert_true(str_contains($routes, '/project-hub/cards/{id}/assignees/batch'), 'assignees batch route missing');
        assert_true(str_contains($routes, '/boards/batch-fetch'), 'boards batch-fetch route missing');
    });

    test('Newer Phase F/G batch routes registered', function () {
        $routes = file_get_contents(__DIR__ . '/../routes.php');
        assert_true(str_contains($routes, '/project-hub/cards/assignees/batch-fetch'), 'card assignees batch-fetch route missing');
        assert_true(str_contains($routes, '/project-hub/card-assignees/batch'), 'card-assignees batch-delete route missing');
        assert_true(str_contains($routes, '/boards/cards/{id}/subtasks/batch'), 'subtasks batch route missing');
        assert_true(str_contains($routes, '/boards/{id}/members/batch'), 'board members batch route missing');
    });

    test('ProjectHubWorkTrackingService has F7/F8 batch methods', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\ProjectHub\\Services\\ProjectHubWorkTrackingService');
        assert_true($rc->hasMethod('getCardAssigneesBatch'), 'getCardAssigneesBatch missing');
        assert_true($rc->hasMethod('removeAssigneesBatch'), 'removeAssigneesBatch missing');
    });

    test('BoardService has G1/G6 batch methods', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\KanbanBoards\\Services\\BoardService');
        assert_true($rc->hasMethod('enrichCardsBatch'), 'enrichCardsBatch missing');
        assert_true($rc->hasMethod('getMilestoneProgressBatch'), 'getMilestoneProgressBatch missing');
        assert_true($rc->hasMethod('createSubtasksBatch'), 'createSubtasksBatch missing');
    });

    test('ProjectHubService still exposes overview/hierarchy paths', function () {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\ProjectHub\\Services\\ProjectHubService');
        assert_true($rc->hasMethod('getFullHierarchy'), 'getFullHierarchy missing');
        assert_true($rc->hasMethod('getSpaceOverview'), 'getSpaceOverview missing');
        assert_true($rc->hasMethod('getFolderBoardAttachments'), 'getFolderBoardAttachments missing');
        assert_true($rc->hasMethod('getFolderTrackedUrls'), 'getFolderTrackedUrls missing');
        assert_true($rc->hasMethod('checkAndUnblockDependents'), 'checkAndUnblockDependents missing');
    });
}

// =====================================================================
// 2. REACTIONS BATCH (DB-level)
// =====================================================================

$service = null;
if (shouldRun('reactions') || shouldRun('assignees')) {
    test('Bootstrap ProjectHubWorkTrackingService', function () use ($config, &$service) {
        $service = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);
        assert_true($service !== null, 'not constructed');
    });
}

if (shouldRun('reactions') && $service) {
    out("\n--- 2. REACTIONS BATCH ---");

    test('Seed 10 comments with reactions', function () use (&$db, $testEmail, &$seededCardId, &$seededCommentIds, $TEST_TAG) {
        // Find ANY card the user owns to attach test comments to. If
        // none exists we'll create an orphan card row anchored by a
        // placeholder list (also created).
        $stmt = $db->prepare("SELECT id FROM webmail_board_cards LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            // Without a card, comments would violate FK; abandon the test.
            return 'skip';
        }
        $seededCardId = (int)$row['id'];

        $ins = $db->prepare("
            INSERT INTO webmail_card_comments (card_id, user_email, content, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        for ($i = 0; $i < 10; $i++) {
            $ins->execute([$seededCardId, $testEmail, $TEST_TAG . " comment #{$i}"]);
            $seededCommentIds[] = (int)$db->lastInsertId();
        }

        // Each comment gets 2 reactions ('thumbs_up' from testEmail + 'heart' from another).
        $rIns = $db->prepare("
            INSERT INTO projecthub_comment_reactions (comment_id, user_email, emoji, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($seededCommentIds as $cid) {
            $rIns->execute([$cid, $testEmail, '👍']);
            $rIns->execute([$cid, 'flowone_test_other@example.com', '❤️']);
        }
        vlog("Seeded card={$seededCardId}, comments=" . count($seededCommentIds));
    });

    test('getCommentReactionsBatch returns one row group per comment', function () use ($service, &$seededCommentIds) {
        if (empty($seededCommentIds)) return 'skip';
        $start = microtime(true);
        $out = $service->getCommentReactionsBatch($seededCommentIds);
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        vlog("Batched fetch for " . count($seededCommentIds) . " comments: {$elapsed}ms");
        assert_equals(count($seededCommentIds), count($out), 'should return key per comment');
        foreach ($seededCommentIds as $cid) {
            assert_true(isset($out[$cid]), "key for comment {$cid} missing");
            assert_equals(2, count($out[$cid]), "comment {$cid} should have 2 emoji groups");
        }
        assert_true($elapsed < 500, "Batch took {$elapsed}ms (>500ms is suspicious)");
    });

    test('Batched call is faster than N individual calls (sanity)', function () use ($service, &$seededCommentIds) {
        if (empty($seededCommentIds)) return 'skip';
        // Per-id loop (the old path)
        $loopStart = microtime(true);
        foreach ($seededCommentIds as $cid) {
            $service->getCommentReactions($cid);
        }
        $loopMs = (int)round((microtime(true) - $loopStart) * 1000);

        // Batched
        $batchStart = microtime(true);
        $service->getCommentReactionsBatch($seededCommentIds);
        $batchMs = (int)round((microtime(true) - $batchStart) * 1000);

        vlog("loop={$loopMs}ms batch={$batchMs}ms speedup~" . ($batchMs > 0 ? round($loopMs / max($batchMs, 1), 2) : 'inf') . 'x');
        // On a healthy DB the batch should be at least as fast; we
        // tolerate 1.5x slack to avoid flakes on noisy shared hosts.
        assert_true($batchMs <= max($loopMs, 50) * 1.5, "Batch ({$batchMs}ms) should be no slower than loop ({$loopMs}ms)");
    });

    test('Empty input returns empty array', function () use ($service) {
        $r = $service->getCommentReactionsBatch([]);
        assert_equals([], $r, 'empty input should return []');
    });

    test('Unknown comment ids return empty arrays (not nulls)', function () use ($service) {
        $r = $service->getCommentReactionsBatch([999999998, 999999999]);
        assert_equals(2, count($r), 'should return key per requested id');
        foreach ($r as $list) {
            assert_equals([], $list, 'unknown id should map to empty list');
        }
    });
}

// =====================================================================
// 3. ASSIGNEES BATCH (DB-level)
// =====================================================================

if (shouldRun('assignees') && $service) {
    out("\n--- 3. ASSIGNEES BATCH ---");

    test('Find a card for assignee batch test', function () use (&$db, &$seededCardId) {
        if ($seededCardId) return;
        $stmt = $db->prepare("SELECT id FROM webmail_board_cards LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) return 'skip';
        $seededCardId = (int)$row['id'];
    });

    test('addAssigneesBatch inserts multiple rows in one statement', function () use ($service, &$seededCardId) {
        if (!$seededCardId) return 'skip';
        $emails = [
            'flowone_test_a@example.com',
            'flowone_test_b@example.com',
            'flowone_test_c@example.com',
        ];
        $start = microtime(true);
        $r = $service->addAssigneesBatch($seededCardId, $emails, 'assignee');
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        vlog("Batched assign took {$elapsed}ms, result=" . json_encode($r));
        assert_true(isset($r['assignees']), 'response should include assignees');
        $foundEmails = array_column($r['assignees'], 'user_email');
        foreach ($emails as $e) {
            assert_true(in_array(strtolower($e), $foundEmails, true), "expected {$e} in assignees list");
        }
        assert_true($elapsed < 1000, "Batch took {$elapsed}ms (>1s is suspicious)");
    });

    test('Idempotent: re-running with same emails does not duplicate', function () use ($service, &$seededCardId, &$db) {
        if (!$seededCardId) return 'skip';
        $emails = [
            'flowone_test_a@example.com',
            'flowone_test_b@example.com',
        ];
        $service->addAssigneesBatch($seededCardId, $emails, 'assignee');
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM projecthub_card_assignees WHERE card_id = ? AND user_email IN ('flowone_test_a@example.com','flowone_test_b@example.com')");
        $stmt->execute([$seededCardId]);
        $row = $stmt->fetch();
        assert_equals(2, (int)$row['c'], 'should still be 2 rows after re-run (ON DUPLICATE KEY UPDATE)');
    });

    test('Empty emails returns no-op with assignees list', function () use ($service, &$seededCardId) {
        if (!$seededCardId) return 'skip';
        $r = $service->addAssigneesBatch($seededCardId, [], 'assignee');
        assert_equals(0, $r['added'] ?? -1, 'added should be 0');
        assert_true(is_array($r['assignees'] ?? null), 'assignees list should still come back');
    });
}

// =====================================================================
// 4. PHASE F/G BATCH PATHS (correctness + perf)
// =====================================================================

if (shouldRun('fgbatch') || (empty($onlyGroups) && !$smokeOnly)) {
    out("\n--- 4. PHASE F/G BATCH PATHS ---");

    $hubService = null;
    $phService  = null;
    $boardService = null;
    test('Bootstrap Phase F/G services', function () use ($config, &$hubService, &$phService, &$boardService) {
        $hubService   = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);
        $phService    = new \Webmail\Addons\ProjectHub\Services\ProjectHubService($config);
        $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($config);
        assert_true($hubService && $phService && $boardService, 'one or more services failed to construct');
    });

    test('getCardAssigneesBatch returns map shape', function () use (&$hubService, &$db) {
        if (!$hubService) return 'skip';
        $stmt = $db->prepare("SELECT id FROM webmail_board_cards WHERE archived = 0 LIMIT 5");
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (empty($ids)) return 'skip';

        $start = microtime(true);
        $map = $hubService->getCardAssigneesBatch($ids);
        $ms  = (int)round((microtime(true) - $start) * 1000);
        vlog("getCardAssigneesBatch for " . count($ids) . " cards: {$ms}ms");
        assert_true(is_array($map), 'must return array');
        foreach ($ids as $cid) {
            assert_true(array_key_exists($cid, $map), "missing key for card {$cid}");
            assert_true(is_array($map[$cid]), "value for card {$cid} must be array");
        }
        assert_true($ms < 1000, "batch took {$ms}ms (>1s suspicious)");
    });

    test('Batched assignee fetch beats per-id loop', function () use (&$hubService, &$db) {
        if (!$hubService) return 'skip';
        $stmt = $db->prepare("SELECT id FROM webmail_board_cards WHERE archived = 0 LIMIT 10");
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (count($ids) < 2) return 'skip';

        $loopStart = microtime(true);
        foreach ($ids as $cid) {
            $hubService->getCardAssignees($cid);
        }
        $loopMs = (int)round((microtime(true) - $loopStart) * 1000);

        $batchStart = microtime(true);
        $hubService->getCardAssigneesBatch($ids);
        $batchMs = (int)round((microtime(true) - $batchStart) * 1000);

        vlog("loop={$loopMs}ms batch={$batchMs}ms");
        assert_true($batchMs <= max($loopMs, 50) * 1.5, "batch ({$batchMs}ms) should be no slower than loop ({$loopMs}ms)");
    });

    test('removeAssigneesBatch is a no-op for empty input', function () use (&$hubService) {
        if (!$hubService) return 'skip';
        $r = $hubService->removeAssigneesBatch([]);
        assert_true(is_array($r), 'returns array');
        assert_equals(0, count($r), 'no rows for empty input');
    });

    test('BoardService::enrichCardsBatch returns array, preserves order', function () use (&$boardService, &$db) {
        if (!$boardService) return 'skip';
        $stmt = $db->prepare("SELECT * FROM webmail_board_cards WHERE archived = 0 LIMIT 5");
        $stmt->execute();
        $cards = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (empty($cards)) return 'skip';
        $ids = array_column($cards, 'id');

        $start = microtime(true);
        $enriched = $boardService->enrichCardsBatch($cards);
        $ms = (int)round((microtime(true) - $start) * 1000);
        vlog("enrichCardsBatch on " . count($cards) . " cards: {$ms}ms");
        assert_equals(count($cards), count($enriched), 'enriched count should match');
        foreach ($enriched as $i => $row) {
            assert_equals((int)$ids[$i], (int)$row['id'], "order mismatch at index {$i}");
        }
    });

    test('getMilestoneProgressBatch returns key per list', function () use (&$boardService, &$db) {
        if (!$boardService) return 'skip';
        $stmt = $db->prepare("SELECT id FROM webmail_board_lists WHERE archived = 0 LIMIT 5");
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (empty($ids)) return 'skip';

        $start = microtime(true);
        $map = $boardService->getMilestoneProgressBatch($ids);
        $ms = (int)round((microtime(true) - $start) * 1000);
        vlog("getMilestoneProgressBatch on " . count($ids) . " lists: {$ms}ms");
        foreach ($ids as $lid) {
            assert_true(array_key_exists($lid, $map), "missing key for list {$lid}");
            assert_true(isset($map[$lid]['progress_percent']), "missing progress_percent for {$lid}");
        }
        assert_true($ms < 1000, "batch took {$ms}ms (>1s suspicious)");
    });

    test('checkAndUnblockDependents safe on isolated id', function () use (&$phService) {
        if (!$phService) return 'skip';
        $r = $phService->checkAndUnblockDependents(999999991);
        assert_true(is_array($r), 'returns array');
    });

    test('getFullHierarchy completes for current user', function () use (&$phService, $testEmail) {
        if (!$phService) return 'skip';
        $start = microtime(true);
        $h = $phService->getFullHierarchy($testEmail);
        $ms = (int)round((microtime(true) - $start) * 1000);
        vlog("getFullHierarchy: {$ms}ms");
        assert_true(is_array($h) && isset($h['spaces']), 'response shape unexpected');
        assert_true($ms < 5000, "hierarchy took {$ms}ms (>5s is a regression)");
    });
}

// =====================================================================
// SUMMARY
// =====================================================================

out("\n=================================================================");
out("  SUMMARY");
out("=================================================================");
out("  Total:    {$totalTests}");
out("  \033[32mPassed:   {$passed}\033[0m");
out("  \033[31mFailed:   {$failed}\033[0m");
out("  \033[33mWarnings: {$warnings}\033[0m");

if ($failed > 0) {
    out("\n--- FAILURES ---");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("  \033[31m[FAIL]\033[0m {$r['name']}");
            out("        -> " . ($r['error'] ?? ''));
        }
    }
}

if ($jsonOutput) {
    echo json_encode([
        'total' => $totalTests,
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'log' => $logFile,
        'results' => $results,
    ], JSON_PRETTY_PRINT) . "\n";
}

exit($failed > 0 ? 1 : 0);
