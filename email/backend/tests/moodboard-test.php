#!/usr/bin/env php
<?php
/**
 * FlowOne Moodboard - Comprehensive Test Suite
 *
 * Tests board CRUD, all element types, single/batch add/update/delete,
 * move items, color/border/gradient (style_data), connections, snapshots,
 * share links, duplicate board, trash/restore, comments, Redis pub/sub,
 * and security hardening (upload validation, IDOR, traversal, rate limits).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/moodboard-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --only=GROUPS        Comma-separated: board,item,batch,move,style,connection,snapshot,share,duplicate,trash,comment,ws,export,security
 *   --smoke              Run a minimal subset of tests
 *   --verbose            Show extra debug info
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', ['email:', 'only:', 'smoke', 'verbose', 'help']);
if (isset($opts['help']) || empty($opts['email'])) {
    echo "FlowOne Moodboard Test Suite\n";
    echo "=============================\n\n";
    echo "Usage:\n";
    echo "  php moodboard-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --only=GROUPS        Comma-separated: board,item,batch,move,style,connection,snapshot,share,duplicate,trash,comment,ws,export,security\n";
    echo "  --smoke              Run minimal smoke tests only\n";
    echo "  --verbose            Show extra debug info\n";
    echo "  --help               Show this help\n\n";
    exit(1);
}

$testEmail    = $opts['email'];
$verbose      = isset($opts['verbose']);
$smokeOnly    = isset($opts['smoke']);
$onlyGroups   = isset($opts['only']) ? explode(',', $opts['only']) : [];

function shouldRun(string $group): bool {
    global $onlyGroups, $smokeOnly;
    if ($smokeOnly) return in_array($group, ['board', 'item', 'batch', 'style']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/moodboard-test-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$totalTests = 0;
$passed     = 0;
$failed     = 0;
$warnings   = 0;
$results    = [];

function out(string $msg): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function test(string $name, callable $fn): void {
    global $totalTests, $passed, $failed, $warnings, $results, $verbose;
    $totalTests++;
    $start = microtime(true);
    try {
        $result = $fn();
        $elapsed = round((microtime(true) - $start) * 1000);
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
        $elapsed = round((microtime(true) - $start) * 1000);
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
function assert_false(bool $cond, string $msg = 'Expected false'): void {
    if ($cond) throw new \RuntimeException($msg);
}
function assert_equals($exp, $act, string $msg = ''): void {
    if ($exp !== $act) {
        $label = $msg ?: 'Values differ';
        throw new \RuntimeException("$label: expected " . var_export($exp, true) . ", got " . var_export($act, true));
    }
}
function assert_not_empty($val, string $msg = 'Value is empty'): void {
    if (empty($val)) throw new \RuntimeException($msg);
}
function assert_null($val, string $msg = 'Expected null'): void {
    if ($val !== null) throw new \RuntimeException($msg . ': got ' . var_export($val, true));
}
function assert_greater_than(int $t, int $a, string $msg = ''): void {
    if ($a <= $t) throw new \RuntimeException(($msg ?: 'Value not greater') . ": expected > $t, got $a");
}
function vlog(string $msg): void {
    global $verbose;
    if ($verbose) out("          [v] $msg");
}

// ── Cleanup tracking ─────────────────────────────────────────────

$TEST_TAG = '[MBTEST]';
$runId    = date('His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$cleanupBoardIds = [];

function doCleanup(): void {
    global $config, $testEmail, $cleanupBoardIds;

    out("\n--- CLEANUP ---");

    $db = \Webmail\Core\Database::getConnection($config);
    $userLower = strtolower($testEmail);

    foreach ($cleanupBoardIds as $bid) {
        try {
            $db->prepare('DELETE FROM mood_board_comments WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_board_snapshots WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_board_connections WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            // Delete todos for items in this board
            $db->prepare('DELETE t FROM mood_board_todos t JOIN mood_board_items i ON t.item_id = i.id WHERE i.board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_board_items WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_board_members WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_board_shares WHERE board_id = ?')->execute([$bid]);
        } catch (\Throwable $e) { /* ignore */ }
        try {
            $db->prepare('DELETE FROM mood_boards WHERE id = ? AND owner_email = ?')->execute([$bid, $userLower]);
            vlog("Deleted board ID $bid");
        } catch (\Throwable $e) { /* ignore */ }
    }

    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Banner ───────────────────────────────────────────────────────

out("=============================================================");
out("  FlowOne Moodboard Test Suite");
out("  Account : $testEmail");
out("  Run ID  : $runId");
out("  Mode    : " . ($smokeOnly ? 'SMOKE' : (empty($onlyGroups) ? 'FULL' : 'Groups: ' . implode(', ', $onlyGroups))));
out("=============================================================\n");

// ══════════════════════════════════════════════════════════════════
// PRE-FLIGHT CHECKS
// ══════════════════════════════════════════════════════════════════

out("=== Pre-flight checks ===");

test('Database connection', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    $row = $db->query('SELECT 1 AS ok')->fetch();
    assert_equals(1, (int)$row['ok'], 'DB ping');
});

test('mood_boards table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db->query("SHOW TABLES LIKE 'mood_boards'")->rowCount() > 0, 'Table missing');
});

test('mood_board_items table exists', function () use ($config) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db->query("SHOW TABLES LIKE 'mood_board_items'")->rowCount() > 0, 'Table missing');
});

test('MoodBoardService instantiates', function () use ($config) {
    $ms = new \Webmail\Addons\Moodboards\Services\MoodBoardService($config);
    assert_true($ms instanceof \Webmail\Addons\Moodboards\Services\MoodBoardService);
});

// Shared service instance
$mbService = new \Webmail\Addons\Moodboards\Services\MoodBoardService($config);

// ══════════════════════════════════════════════════════════════════
// GROUP: board -- Board CRUD
// ══════════════════════════════════════════════════════════════════

$boardId = null;

if (shouldRun('board')) {
    out("\n=== Board CRUD ===");

    test('createBoard creates a new board', function () use (
        $mbService, $testEmail, $runId, $TEST_TAG, &$boardId, &$cleanupBoardIds
    ) {
        $board = $mbService->createBoard($testEmail, [
            'name' => "$TEST_TAG Test Board $runId",
            'description' => 'Automated test board',
            'background_color' => '#1a1a2e',
        ]);
        assert_true($board !== null, 'createBoard returned null');
        assert_not_empty($board['id'], 'Board ID');
        assert_equals("$TEST_TAG Test Board $runId", $board['name'], 'Name');
        assert_equals('#1a1a2e', $board['background_color'], 'Background color');
        $boardId = (int)$board['id'];
        $cleanupBoardIds[] = $boardId;
        vlog("Created board ID: $boardId");
    });

    test('getBoard returns correct board with items array', function () use ($mbService, $testEmail, $boardId) {
        $board = $mbService->getBoard($testEmail, $boardId);
        assert_true($board !== null, 'getBoard null');
        assert_equals($boardId, (int)$board['id'], 'ID match');
        assert_true(isset($board['items']), 'items array exists');
        assert_true(isset($board['connections']), 'connections array exists');
    });

    test('getBoards lists the test board', function () use ($mbService, $testEmail, $boardId) {
        $boards = $mbService->getBoards($testEmail);
        $ids = array_map(fn($b) => (int)$b['id'], $boards);
        assert_true(in_array($boardId, $ids), 'Board in list');
    });

    test('updateBoard changes name and background', function () use ($mbService, $testEmail, $boardId, $runId, $TEST_TAG) {
        $updated = $mbService->updateBoard($testEmail, $boardId, [
            'name' => "$TEST_TAG Updated Board $runId",
            'background_color' => '#16213e',
        ]);
        assert_true($updated !== null, 'updateBoard null');
        assert_equals("$TEST_TAG Updated Board $runId", $updated['name'], 'Name updated');
        assert_equals('#16213e', $updated['background_color'], 'BG color updated');
    });

    test('getBoard for wrong user returns null', function () use ($mbService, $boardId) {
        $board = $mbService->getBoard('wrong@nobody.com', $boardId);
        assert_null($board, 'Should not see other user board');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: item -- Add all element types, single update, delete
// ══════════════════════════════════════════════════════════════════

$itemIds = [];

if (shouldRun('item')) {
    out("\n=== Item CRUD (all element types) ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Items $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $elementTypes = [
        'text'         => ['title' => 'Test text', 'content' => 'Hello world', 'color' => '#ffffff'],
        'note'         => ['title' => 'Sticky note', 'content' => 'Remember this', 'color' => '#fef08a'],
        'image'        => ['title' => 'Photo', 'image_url' => 'https://example.com/photo.jpg'],
        'shape'        => ['title' => 'Circle', 'style_data' => ['shapeType' => 'circle', 'fill' => '#3b82f6']],
        'frame'        => ['title' => 'Section frame', 'width' => 800, 'height' => 600],
        'link'         => ['title' => 'Web link', 'url' => 'https://flowone.pro'],
        'color_swatch' => ['title' => 'Brand blue', 'color' => '#2563eb'],
        'drawing'      => ['title' => 'Sketch', 'content' => '{"paths":[]}'],
        'line'         => ['title' => 'Divider'],
        'video'        => ['title' => 'Demo video', 'url' => 'https://example.com/video.mp4'],
    ];

    foreach ($elementTypes as $type => $extra) {
        test("addItem type=$type", function () use (
            $mbService, $testEmail, $boardId, $type, $extra, $runId, $TEST_TAG,
            &$itemIds, &$cleanupBoardIds
        ) {
            $data = array_merge([
                'type' => $type,
                'pos_x' => rand(0, 500),
                'pos_y' => rand(0, 500),
                'width' => $extra['width'] ?? 240,
                'height' => $extra['height'] ?? 180,
                'rotation' => 0,
            ], $extra);

            $item = $mbService->addItem($testEmail, $boardId, $data);
            assert_true($item !== null, "addItem($type) returned null");
            assert_equals($type, $item['type'], 'Type stored');
            assert_not_empty($item['id'], 'Item ID');
            $itemIds[$type] = (int)$item['id'];
            vlog("$type item ID: {$item['id']}, z_index: {$item['z_index']}");
        });
    }

    test('getItem returns correct item', function () use ($mbService, $itemIds) {
        $textId = $itemIds['text'] ?? null;
        if (!$textId) return 'warn';
        $item = $mbService->getItem($textId);
        assert_true($item !== null, 'getItem null');
        assert_equals('text', $item['type'], 'Type match');
        assert_equals('Test text', $item['title'], 'Title match');
    });

    test('updateItem changes title and position', function () use ($mbService, $testEmail, $boardId, $itemIds) {
        $noteId = $itemIds['note'] ?? null;
        if (!$noteId) return 'warn';

        $updated = $mbService->updateItem($testEmail, $boardId, $noteId, [
            'title' => 'Updated sticky note',
            'pos_x' => 100,
            'pos_y' => 200,
        ]);
        assert_true($updated !== null, 'updateItem null');
        assert_equals('Updated sticky note', $updated['title'], 'Title updated');
        assert_equals(100, (int)$updated['pos_x'], 'pos_x updated');
        assert_equals(200, (int)$updated['pos_y'], 'pos_y updated');
    });

    test('deleteItem soft-deletes', function () use ($mbService, $testEmail, $boardId, $itemIds) {
        $lineId = $itemIds['line'] ?? null;
        if (!$lineId) return 'warn';

        $result = $mbService->deleteItem($testEmail, $boardId, $lineId);
        assert_true($result, 'deleteItem should succeed');

        $gone = $mbService->getItem($lineId);
        assert_null($gone, 'Soft-deleted item should not appear via getItem');
    });

    test('Z-index auto-increments per item', function () use ($mbService, $itemIds) {
        $zIndexes = [];
        foreach ($itemIds as $type => $id) {
            if ($type === 'line') continue;
            $item = $mbService->getItem($id);
            if ($item) $zIndexes[$type] = (int)$item['z_index'];
        }
        $values = array_values($zIndexes);
        $unique = array_unique($values);
        assert_equals(count($values), count($unique), 'All z_indexes should be unique');
        vlog("Z-indexes: " . json_encode($zIndexes));
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: batch -- Batch add, batch update, batch delete
// ══════════════════════════════════════════════════════════════════

if (shouldRun('batch')) {
    out("\n=== Batch operations ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Batch $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $batchItemIds = [];

    test('batchAddItems adds 5 items in one call', function () use (
        $mbService, $testEmail, $boardId, &$batchItemIds
    ) {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = [
                'type' => 'note',
                'pos_x' => $i * 100,
                'pos_y' => 0,
                'width' => 80,
                'height' => 80,
                'title' => "Batch note $i",
                'color' => '#' . str_pad(dechex(rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT),
            ];
        }

        $result = $mbService->batchAddItems($testEmail, $boardId, $items);
        assert_true(is_array($result), 'Should return array');
        assert_equals(5, count($result), 'Should add 5 items');

        foreach ($result as $item) {
            $batchItemIds[] = (int)$item['id'];
        }
        vlog("Batch added IDs: " . implode(', ', $batchItemIds));
    });

    test('batchUpdateItems moves all 5 items', function () use (
        $mbService, $testEmail, $boardId, $batchItemIds
    ) {
        $updates = [];
        foreach ($batchItemIds as $i => $id) {
            $updates[] = [
                'id' => $id,
                'pos_x' => 500 + ($i * 50),
                'pos_y' => 300,
            ];
        }

        $result = $mbService->batchUpdateItems($testEmail, $boardId, $updates);
        assert_true($result, 'batchUpdateItems should return true');

        // Verify positions changed
        $item = $mbService->getItem($batchItemIds[0]);
        assert_equals(500, (int)$item['pos_x'], 'First item pos_x moved');
        assert_equals(300, (int)$item['pos_y'], 'First item pos_y moved');
    });

    test('batchDeleteItems soft-deletes 3 of 5', function () use (
        $mbService, $testEmail, $boardId, $batchItemIds
    ) {
        $toDelete = array_slice($batchItemIds, 0, 3);
        $deleted = $mbService->batchDeleteItems($testEmail, $boardId, $toDelete);
        assert_equals(3, $deleted, 'Should soft-delete 3');

        foreach ($toDelete as $id) {
            $item = $mbService->getItem($id);
            assert_null($item, "Item $id should be soft-deleted");
        }

        // Remaining 2 should still be visible
        $remaining = array_slice($batchItemIds, 3);
        foreach ($remaining as $id) {
            $item = $mbService->getItem($id);
            assert_true($item !== null, "Item $id should still exist");
        }
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: move -- Move items (position change, parent change)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('move')) {
    out("\n=== Move items ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Move $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $moveItemId = null;
    $frameItemId = null;

    test('Setup: create item and frame for move tests', function () use (
        $mbService, $testEmail, $boardId, &$moveItemId, &$frameItemId
    ) {
        $item = $mbService->addItem($testEmail, $boardId, [
            'type' => 'note', 'pos_x' => 50, 'pos_y' => 50, 'width' => 100, 'height' => 100,
            'title' => 'Moveable note',
        ]);
        $frame = $mbService->addItem($testEmail, $boardId, [
            'type' => 'frame', 'pos_x' => 300, 'pos_y' => 300, 'width' => 500, 'height' => 400,
            'title' => 'Target frame',
        ]);
        assert_true($item !== null && $frame !== null, 'Both created');
        $moveItemId = (int)$item['id'];
        $frameItemId = (int)$frame['id'];
    });

    test('Move item to new position', function () use ($mbService, $testEmail, $boardId, $moveItemId) {
        $updated = $mbService->updateItem($testEmail, $boardId, $moveItemId, [
            'pos_x' => 999,
            'pos_y' => 888,
        ]);
        assert_true($updated !== null, 'Move item');
        assert_equals(999, (int)$updated['pos_x'], 'New X');
        assert_equals(888, (int)$updated['pos_y'], 'New Y');
    });

    test('Move item into a frame (set parent_id)', function () use (
        $mbService, $testEmail, $boardId, $moveItemId, $frameItemId
    ) {
        $updated = $mbService->updateItem($testEmail, $boardId, $moveItemId, [
            'parent_id' => $frameItemId,
        ]);
        assert_true($updated !== null, 'Set parent');
        assert_equals($frameItemId, (int)$updated['parent_id'], 'parent_id set');
    });

    test('Move item out of frame (clear parent_id)', function () use (
        $mbService, $testEmail, $boardId, $moveItemId
    ) {
        $updated = $mbService->updateItem($testEmail, $boardId, $moveItemId, [
            'parent_id' => null,
        ]);
        assert_true($updated !== null, 'Clear parent');
        assert_true($updated['parent_id'] === null || $updated['parent_id'] === '0' || $updated['parent_id'] === 0, 'parent_id cleared');
    });

    test('Batch move multiple items', function () use ($mbService, $testEmail, $boardId, $moveItemId, $frameItemId) {
        $result = $mbService->batchUpdateItems($testEmail, $boardId, [
            ['id' => $moveItemId, 'pos_x' => 111, 'pos_y' => 222],
            ['id' => $frameItemId, 'pos_x' => 333, 'pos_y' => 444],
        ]);
        assert_true($result, 'Batch move');

        $item1 = $mbService->getItem($moveItemId);
        $item2 = $mbService->getItem($frameItemId);
        assert_equals(111, (int)$item1['pos_x'], 'Item 1 X');
        assert_equals(333, (int)$item2['pos_x'], 'Item 2 X');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: style -- Colors, borders, gradients (style_data + color_data)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('style')) {
    out("\n=== Style: colors, borders, gradients ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Style $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $styleItemId = null;

    test('Create item with style_data (border, shadow, opacity)', function () use (
        $mbService, $testEmail, $boardId, &$styleItemId
    ) {
        $item = $mbService->addItem($testEmail, $boardId, [
            'type' => 'shape',
            'pos_x' => 100, 'pos_y' => 100, 'width' => 200, 'height' => 200,
            'title' => 'Styled shape',
            'color' => '#3b82f6',
            'style_data' => [
                'shapeType' => 'rectangle',
                'fill' => '#3b82f6',
                'borderWidth' => 3,
                'borderColor' => '#1e40af',
                'borderStyle' => 'solid',
                'borderRadius' => 12,
                'opacity' => 0.85,
                'shadow' => [
                    'enabled' => true,
                    'x' => 4, 'y' => 4, 'blur' => 10,
                    'color' => 'rgba(0,0,0,0.25)',
                ],
            ],
        ]);
        assert_true($item !== null, 'Styled item created');
        $styleItemId = (int)$item['id'];

        $sd = $item['style_data'];
        assert_true(is_array($sd), 'style_data decoded to array');
        assert_equals('rectangle', $sd['shapeType'], 'shapeType');
        assert_equals('#3b82f6', $sd['fill'], 'fill color');
        assert_equals(3, $sd['borderWidth'], 'borderWidth');
        assert_equals('#1e40af', $sd['borderColor'], 'borderColor');
        assert_equals(12, $sd['borderRadius'], 'borderRadius');
        vlog("style_data keys: " . implode(', ', array_keys($sd)));
    });

    test('Update style_data (change gradient)', function () use (
        $mbService, $testEmail, $boardId, $styleItemId
    ) {
        $updated = $mbService->updateItem($testEmail, $boardId, $styleItemId, [
            'style_data' => [
                'shapeType' => 'rectangle',
                'fill' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                'borderWidth' => 0,
                'borderRadius' => 20,
                'opacity' => 1.0,
            ],
        ]);
        assert_true($updated !== null, 'Update style');
        $sd = $updated['style_data'];
        assert_true(str_contains($sd['fill'], 'linear-gradient'), 'Gradient fill stored');
        assert_equals(20, $sd['borderRadius'], 'Updated radius');
        assert_equals(0, $sd['borderWidth'], 'Border removed');
    });

    test('Update color_data (multi-stop gradient)', function () use (
        $mbService, $testEmail, $boardId, $styleItemId
    ) {
        $colorData = [
            'type' => 'gradient',
            'gradient' => [
                'type' => 'linear',
                'angle' => 90,
                'stops' => [
                    ['color' => '#ff6b6b', 'position' => 0],
                    ['color' => '#feca57', 'position' => 50],
                    ['color' => '#48dbfb', 'position' => 100],
                ],
            ],
        ];
        $updated = $mbService->updateItem($testEmail, $boardId, $styleItemId, [
            'color_data' => $colorData,
        ]);
        assert_true($updated !== null, 'color_data update');
        $cd = $updated['color_data'];
        assert_true(is_array($cd), 'color_data decoded');
        assert_equals('gradient', $cd['type'], 'color_data type');
        assert_equals(3, count($cd['gradient']['stops']), '3 gradient stops');
        vlog("color_data gradient angle: {$cd['gradient']['angle']}");
    });

    test('Batch update styles on multiple items', function () use ($mbService, $testEmail, $boardId, $styleItemId) {
        $item2 = $mbService->addItem($testEmail, $boardId, [
            'type' => 'note', 'pos_x' => 400, 'pos_y' => 100, 'width' => 150, 'height' => 150,
            'title' => 'Note to style',
        ]);
        assert_true($item2 !== null, 'Second item for batch');

        $result = $mbService->batchUpdateItems($testEmail, $boardId, [
            [
                'id' => $styleItemId,
                'style_data' => ['fill' => '#ef4444', 'borderWidth' => 5, 'borderColor' => '#991b1b'],
            ],
            [
                'id' => (int)$item2['id'],
                'color' => '#10b981',
                'style_data' => ['fill' => '#10b981', 'borderWidth' => 2, 'borderColor' => '#065f46'],
            ],
        ]);
        assert_true($result, 'Batch style update');

        $check = $mbService->getItem($styleItemId);
        assert_equals('#ef4444', $check['style_data']['fill'], 'Item 1 fill updated');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: connection -- Connections between items
// ══════════════════════════════════════════════════════════════════

if (shouldRun('connection')) {
    out("\n=== Connections ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Conn $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $connItemA = null;
    $connItemB = null;
    $connItemC = null;
    $connId = null;

    test('Setup: create 3 items for connections', function () use (
        $mbService, $testEmail, $boardId, &$connItemA, &$connItemB, &$connItemC
    ) {
        $a = $mbService->addItem($testEmail, $boardId, ['type' => 'note', 'pos_x' => 0, 'pos_y' => 0, 'title' => 'A']);
        $b = $mbService->addItem($testEmail, $boardId, ['type' => 'note', 'pos_x' => 300, 'pos_y' => 0, 'title' => 'B']);
        $c = $mbService->addItem($testEmail, $boardId, ['type' => 'note', 'pos_x' => 150, 'pos_y' => 300, 'title' => 'C']);
        assert_true($a !== null && $b !== null && $c !== null, 'Items created');
        $connItemA = (int)$a['id'];
        $connItemB = (int)$b['id'];
        $connItemC = (int)$c['id'];
    });

    test('addConnection creates A -> B', function () use (
        $mbService, $testEmail, $boardId, $connItemA, $connItemB, &$connId
    ) {
        $conn = $mbService->addConnection($testEmail, $boardId, [
            'from_item_id' => $connItemA,
            'to_item_id' => $connItemB,
            'line_style' => 'dashed',
            'line_color' => '#ef4444',
            'line_width' => 3,
            'arrow_end' => 1,
            'label' => 'depends on',
        ]);
        assert_true($conn !== null, 'Connection created');
        assert_equals($connItemA, (int)$conn['from_item_id'], 'from_item_id');
        assert_equals($connItemB, (int)$conn['to_item_id'], 'to_item_id');
        assert_equals('dashed', $conn['line_style'], 'line_style');
        assert_equals('#ef4444', $conn['line_color'], 'line_color');
        $connId = (int)$conn['id'];
        vlog("Connection ID: $connId");
    });

    test('updateConnection changes style', function () use ($mbService, $testEmail, $boardId, $connId) {
        $updated = $mbService->updateConnection($testEmail, $boardId, $connId, [
            'line_style' => 'solid',
            'line_color' => '#22c55e',
            'line_width' => 5,
        ]);
        assert_true($updated !== null, 'Update connection');
        assert_equals('solid', $updated['line_style'], 'Style updated');
        assert_equals('#22c55e', $updated['line_color'], 'Color updated');
    });

    test('batchAddConnections creates B->C and A->C', function () use (
        $mbService, $testEmail, $boardId, $connItemA, $connItemB, $connItemC
    ) {
        $result = $mbService->batchAddConnections($testEmail, $boardId, [
            ['from_item_id' => $connItemB, 'to_item_id' => $connItemC, 'line_color' => '#3b82f6'],
            ['from_item_id' => $connItemA, 'to_item_id' => $connItemC, 'line_color' => '#f59e0b'],
        ]);
        assert_equals(2, count($result), 'Batch added 2 connections');
    });

    test('deleteConnection removes one', function () use ($mbService, $testEmail, $boardId, $connId) {
        $result = $mbService->deleteConnection($testEmail, $boardId, $connId);
        assert_true($result, 'Delete connection');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: snapshot -- Snapshots (save/restore board state)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('snapshot')) {
    out("\n=== Snapshots ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Snap $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $snapId = null;

    test('createSnapshot saves current state', function () use ($mbService, $testEmail, $boardId, &$snapId) {
        $snapId = $mbService->createSnapshot($boardId, $testEmail, 'manual', 'Test snapshot');
        assert_true($snapId !== null && $snapId > 0, 'Snapshot created');
        vlog("Snapshot ID: $snapId");
    });

    test('getSnapshots lists the snapshot', function () use ($mbService, $testEmail, $boardId, $snapId) {
        $snaps = $mbService->getSnapshots($testEmail, $boardId);
        assert_true(is_array($snaps), 'Array returned');
        $ids = array_map(fn($s) => (int)$s['id'], $snaps);
        assert_true(in_array($snapId, $ids), 'Snapshot in list');

        $snap = null;
        foreach ($snaps as $s) {
            if ((int)$s['id'] === $snapId) { $snap = $s; break; }
        }
        assert_equals('manual', $snap['trigger_type'], 'Trigger type');
        assert_equals('Test snapshot', $snap['label'], 'Label');
    });

    test('restoreSnapshot restores board state', function () use ($mbService, $testEmail, $boardId, $snapId) {
        $result = $mbService->restoreSnapshot($testEmail, $boardId, $snapId);
        assert_true($result, 'Restore should succeed');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: share -- Public share links
// ══════════════════════════════════════════════════════════════════

if (shouldRun('share')) {
    out("\n=== Share links ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Share $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $shareToken = null;

    test('createShareLink generates token', function () use ($mbService, $testEmail, $boardId, &$shareToken) {
        $share = $mbService->createShareLink($testEmail, $boardId, 'view', null, 24);
        assert_true($share !== null, 'Share created');
        assert_not_empty($share['token'], 'Token');
        assert_equals('view', $share['mode'], 'Mode');
        assert_not_empty($share['expires_at'], 'Expiry set');
        $shareToken = $share['token'];
        vlog("Share token: $shareToken");
    });

    test('getShareInfo returns metadata by token', function () use ($mbService, $shareToken) {
        $info = $mbService->getShareInfo($shareToken);
        assert_true($info !== null, 'Share info');
        assert_equals('view', $info['mode'] ?? $info['share_mode'] ?? '', 'Mode from info');
    });

    test('getBoardByShareToken returns board data', function () use ($mbService, $shareToken) {
        $board = $mbService->getBoardByShareToken($shareToken);
        assert_true($board !== null, 'Board by share token');
        assert_not_empty($board['name'], 'Board name');
    });

    test('updateShareLink changes mode to edit', function () use ($mbService, $testEmail, $boardId) {
        $updated = $mbService->updateShareLink($testEmail, $boardId, ['mode' => 'edit']);
        assert_true($updated !== null, 'Update share');
        assert_equals('edit', $updated['mode'], 'Mode changed');
    });

    test('createShareLink with password', function () use ($mbService, $testEmail, $boardId) {
        $share = $mbService->createShareLink($testEmail, $boardId, 'view', 'secret123', null);
        assert_true($share !== null, 'Password share');
        assert_true($share['has_password'], 'has_password flag');
    });

    test('validateSharePassword correct/incorrect', function () use ($mbService, $shareToken, $config) {
        // Re-fetch token after password was set
        $db = \Webmail\Core\Database::getConnection($config);
        $stmt = $db->prepare('SELECT share_token FROM mood_boards WHERE share_token IS NOT NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) return 'warn';
        $tok = $row['share_token'];

        $valid = $mbService->validateSharePassword($tok, 'secret123');
        assert_true($valid, 'Correct password should validate');

        $invalid = $mbService->validateSharePassword($tok, 'wrongpass');
        assert_false($invalid, 'Wrong password should fail');
    });

    test('removeShareLink disables sharing', function () use ($mbService, $testEmail, $boardId) {
        $result = $mbService->removeShareLink($testEmail, $boardId);
        assert_true($result, 'Remove share');

        // Token should be null now
        $db = $mbService->getDb();
        $stmt = $db->prepare('SELECT share_token FROM mood_boards WHERE id = ?');
        $stmt->execute([$boardId]);
        $row = $stmt->fetch();
        assert_true($row['share_token'] === null, 'Token should be null after removal');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: duplicate -- Duplicate board
// ══════════════════════════════════════════════════════════════════

if (shouldRun('duplicate')) {
    out("\n=== Duplicate board ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Dup $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    // Add items to source board for duplication
    $mbService->addItem($testEmail, $boardId, ['type' => 'note', 'pos_x' => 10, 'pos_y' => 10, 'title' => 'Dup item 1']);
    $mbService->addItem($testEmail, $boardId, ['type' => 'text', 'pos_x' => 200, 'pos_y' => 10, 'title' => 'Dup item 2', 'content' => 'Text content']);

    test('duplicateBoard creates copy with all items', function () use (
        $mbService, $testEmail, $boardId, $runId, $TEST_TAG, &$cleanupBoardIds
    ) {
        $copy = $mbService->duplicateBoard($testEmail, $boardId, "$TEST_TAG Copy $runId");
        assert_true($copy !== null, 'Duplicate created');
        assert_true((int)$copy['id'] !== $boardId, 'Different ID');
        assert_equals("$TEST_TAG Copy $runId", $copy['name'], 'Custom name');
        $cleanupBoardIds[] = (int)$copy['id'];

        // Check items were copied
        $srcBoard = $mbService->getBoard($testEmail, $boardId);
        $srcCount = count(array_filter($srcBoard['items'] ?? [], fn($i) => empty($i['deleted_at'])));
        $dstCount = count($copy['items'] ?? []);
        assert_true($dstCount >= $srcCount, "Copy should have >= $srcCount items, got $dstCount");
        vlog("Source items: $srcCount, Copy items: $dstCount, Copy ID: {$copy['id']}");
    });

    test('Duplicate board items have different IDs from source', function () use (
        $mbService, $testEmail, $boardId, $cleanupBoardIds
    ) {
        $copyId = end($cleanupBoardIds);
        $src = $mbService->getBoard($testEmail, $boardId);
        $dst = $mbService->getBoard($testEmail, $copyId);

        $srcIds = array_map(fn($i) => (int)$i['id'], $src['items'] ?? []);
        $dstIds = array_map(fn($i) => (int)$i['id'], $dst['items'] ?? []);

        $overlap = array_intersect($srcIds, $dstIds);
        assert_equals(0, count($overlap), 'No shared item IDs between src and copy');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: trash -- Soft delete, restore, trash view
// ══════════════════════════════════════════════════════════════════

if (shouldRun('trash')) {
    out("\n=== Trash / restore ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Trash $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $trashIds = [];

    test('Setup: add 3 items then delete all', function () use (
        $mbService, $testEmail, $boardId, &$trashIds
    ) {
        for ($i = 0; $i < 3; $i++) {
            $item = $mbService->addItem($testEmail, $boardId, [
                'type' => 'note', 'pos_x' => $i * 100, 'pos_y' => 0, 'title' => "Trash item $i",
            ]);
            $trashIds[] = (int)$item['id'];
            $mbService->deleteItem($testEmail, $boardId, (int)$item['id']);
        }
    });

    test('getDeletedItems shows all 3 in trash', function () use ($mbService, $testEmail, $boardId, $trashIds) {
        $deleted = $mbService->getDeletedItems($testEmail, $boardId);
        $delIds = array_map(fn($i) => (int)$i['id'], $deleted);
        foreach ($trashIds as $id) {
            assert_true(in_array($id, $delIds), "Item $id should be in trash");
        }
    });

    test('restoreItem brings one back', function () use ($mbService, $testEmail, $boardId, $trashIds) {
        $result = $mbService->restoreItem($testEmail, $boardId, $trashIds[0]);
        assert_true($result, 'Restore single');

        $item = $mbService->getItem($trashIds[0]);
        assert_true($item !== null, 'Restored item should be visible');
    });

    test('restoreAllItems brings remaining back', function () use ($mbService, $testEmail, $boardId) {
        $count = $mbService->restoreAllItems($testEmail, $boardId);
        assert_greater_than(0, $count, 'Should restore at least 1');

        $deleted = $mbService->getDeletedItems($testEmail, $boardId);
        assert_equals(0, count($deleted), 'Trash should be empty');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: comment -- Comments on board items
// ══════════════════════════════════════════════════════════════════

if (shouldRun('comment')) {
    out("\n=== Comments ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Comment $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    $commentId = null;
    $threadId = 'thread_' . $runId;

    test('addComment creates a comment', function () use (
        $mbService, $boardId, $testEmail, $threadId, &$commentId
    ) {
        $comment = $mbService->addComment($boardId, [
            'thread_id' => $threadId,
            'user_email' => $testEmail,
            'user_name' => 'Test User',
            'content' => 'This looks great!',
            'pos_x' => 150,
            'pos_y' => 250,
        ]);
        assert_true($comment !== null, 'Comment created');
        assert_not_empty($comment['id'], 'Comment ID');
        $commentId = (int)$comment['id'];
        vlog("Comment ID: $commentId, thread: $threadId");
    });

    test('getComments lists the comment', function () use ($mbService, $boardId, $commentId, $threadId) {
        $threads = $mbService->getComments($boardId);
        assert_true(is_array($threads), 'Array returned');
        assert_greater_than(0, count($threads), 'At least one thread');

        $found = false;
        foreach ($threads as $thread) {
            assert_equals($threadId, $thread['thread_id'], 'Thread ID matches');
            foreach ($thread['comments'] as $c) {
                if ((int)$c['id'] === $commentId) { $found = true; break 2; }
            }
        }
        assert_true($found, 'Comment found in thread');
        vlog("Threads: " . count($threads) . ", comment $commentId found in thread $threadId");
    });

    test('updateComment changes content', function () use ($mbService, $commentId) {
        $updated = $mbService->updateComment($commentId, 'Updated: even better!');
        assert_true($updated !== null, 'Update comment');
        assert_equals('Updated: even better!', $updated['content'], 'Content updated');
    });

    test('resolveThread marks thread resolved', function () use ($mbService, $boardId, $threadId, $testEmail) {
        $result = $mbService->resolveThread($boardId, $threadId, $testEmail);
        assert_true($result, 'Resolve thread');
    });

    test('unresolveThread reopens thread', function () use ($mbService, $boardId, $threadId) {
        $result = $mbService->unresolveThread($boardId, $threadId);
        assert_true($result, 'Unresolve thread');
    });

    test('deleteComment removes it', function () use ($mbService, $commentId) {
        $result = $mbService->deleteComment($commentId);
        assert_true($result, 'Delete comment');

        $gone = $mbService->getComment($commentId);
        assert_null($gone, 'Deleted comment should be null');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: ws -- WebSocket / Redis pub/sub
// ══════════════════════════════════════════════════════════════════

if (shouldRun('ws')) {
    out("\n=== WebSocket / Redis pub/sub ===");

    test('RedisCacheService instantiates and is available', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        $available = $redis->isAvailable();
        if (!$available) {
            vlog("Redis not available -- skipping WS tests");
            return 'warn';
        }
        assert_true($available, 'Redis available');
    });

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG WS $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    test('publishMoodBoardRoomEvent publishes without error', function () use ($config, $boardId) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) return 'warn';

        $result = $redis->publishMoodBoardRoomEvent($boardId, 'MOOD_BOARD_ITEM_ADDED', [
            'item_id' => 999,
            'type' => 'note',
            'test' => true,
        ]);
        assert_true($result, 'Publish should return true');
        vlog("Published test event to mood_board:$boardId channel");
    });

    test('Redis publish to arbitrary channel works', function () use ($config) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        if (!$redis->isAvailable()) return 'warn';

        $result = $redis->publish('test_channel_' . time(), json_encode(['ping' => true]));
        assert_true($result, 'Publish to channel');
    });
}

// ══════════════════════════════════════════════════════════════════
// GROUP: export -- Export verification (data, not actual file generation)
// ══════════════════════════════════════════════════════════════════

if (shouldRun('export')) {
    out("\n=== Export (PPTX, PDF, CSV) ===");

    if (!$boardId) {
        $b = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG Export $runId"]);
        $boardId = (int)$b['id'];
        $cleanupBoardIds[] = $boardId;
    }

    // Ensure board has varied items for export
    $mbService->addItem($testEmail, $boardId, [
        'type' => 'text', 'pos_x' => 50, 'pos_y' => 50, 'width' => 300, 'height' => 60,
        'title' => 'Export heading', 'content' => 'Exported text content',
        'style_data' => ['fontSize' => 24, 'fontWeight' => 'bold'],
    ]);
    $mbService->addItem($testEmail, $boardId, [
        'type' => 'note', 'pos_x' => 50, 'pos_y' => 150, 'width' => 200, 'height' => 200,
        'title' => 'Export note', 'content' => 'Note body for export', 'color' => '#fef08a',
    ]);
    $mbService->addItem($testEmail, $boardId, [
        'type' => 'shape', 'pos_x' => 400, 'pos_y' => 50, 'width' => 150, 'height' => 150,
        'title' => 'Blue circle',
        'style_data' => ['shapeType' => 'circle', 'fill' => '#3b82f6'],
    ]);

    $exportData = null;
    $exportBoard = null;

    test('getBoardForExport returns full board data', function () use ($mbService, $testEmail, $boardId, &$exportData, &$exportBoard) {
        $exportData = $mbService->getBoardForExport($testEmail, $boardId);
        assert_true($exportData !== null, 'Export data');
        assert_true(isset($exportData['board']), 'Board key present');
        assert_true(isset($exportData['assets']), 'Assets key present');

        $exportBoard = $exportData['board'];
        assert_not_empty($exportBoard['name'], 'Board name');
        assert_true(isset($exportBoard['items']), 'Items present');
        assert_true(isset($exportBoard['connections']), 'Connections present');
        assert_greater_than(0, count($exportBoard['items']), 'At least 1 item');
        vlog("Export: " . count($exportBoard['items']) . " items, " . count($exportBoard['connections']) . " connections, assets: " . count($exportData['assets']));
    });

    test('PPTX export generates valid .pptx file', function () use (&$exportBoard, &$exportData) {
        if (!$exportBoard) return 'warn';

        if (!class_exists('\\PhpOffice\\PhpPresentation\\PhpPresentation')) {
            vlog("PhpPresentation library not available -- skipping PPTX test");
            return 'warn';
        }

        $pptxService = new \Webmail\Addons\Moodboards\Services\MoodBoardPptxService();
        $assetMap = $exportData['assets'] ?? [];
        $filePathMap = $exportData['filePaths'] ?? [];
        $outputPath = $pptxService->generate($exportBoard, $assetMap, $filePathMap);

        assert_true(file_exists($outputPath), 'PPTX file should exist');
        $size = filesize($outputPath);
        assert_greater_than(0, $size, 'PPTX file should have content');
        vlog("PPTX size: $size bytes, path: $outputPath");

        $header = file_get_contents($outputPath, false, null, 0, 4);
        assert_equals("PK\x03\x04", $header, 'PPTX should have ZIP magic bytes');

        @unlink($outputPath);
        $dir = dirname($outputPath);
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    });

    test('PDF export generates valid .pdf file', function () use (&$exportBoard, &$exportData) {
        if (!$exportBoard) return 'warn';

        if (!class_exists('\\TCPDF')) {
            vlog("TCPDF library not available -- skipping PDF test");
            return 'warn';
        }

        $pdfService = new \Webmail\Addons\Moodboards\Services\MoodBoardPdfService();
        $assetMap = $exportData['assets'] ?? [];
        $filePathMap = $exportData['filePaths'] ?? [];
        $outputPath = $pdfService->generate($exportBoard, $assetMap, $filePathMap);

        assert_true(file_exists($outputPath), 'PDF file should exist');
        $size = filesize($outputPath);
        assert_greater_than(0, $size, 'PDF file should have content');
        vlog("PDF size: $size bytes, path: $outputPath");

        $header = file_get_contents($outputPath, false, null, 0, 5);
        assert_equals('%PDF-', $header, 'PDF should have %PDF- header');

        $pdfService->cleanup();
    });

    test('exportTexts returns CSV content', function () use ($mbService, $testEmail, $boardId) {
        $csv = $mbService->exportTexts($testEmail, $boardId);
        if ($csv === null) {
            vlog("exportTexts returned null -- may have no text items");
            return 'warn';
        }
        assert_true(strlen($csv) > 0, 'CSV not empty');
        assert_true(str_contains($csv, 'Export'), 'CSV contains our text');
        vlog("CSV length: " . strlen($csv) . " bytes");
    });

    test('importTexts round-trips CSV', function () use ($mbService, $testEmail, $boardId) {
        $csv = $mbService->exportTexts($testEmail, $boardId);
        if (!$csv) return 'warn';

        $result = $mbService->importTexts($testEmail, $boardId, $csv);
        assert_true(is_array($result), 'Import returns array');
        vlog("Import result: " . json_encode($result));
    });
}

// ══════════════════════════════════════════════════════════════════
// Final: delete test board
// ══════════════════════════════════════════════════════════════════

if (shouldRun('board') && $boardId) {
    out("\n=== Board deletion ===");

    test('deleteBoard removes the board', function () use ($mbService, $testEmail, $boardId, &$cleanupBoardIds) {
        $result = $mbService->deleteBoard($testEmail, $boardId);
        assert_true($result, 'deleteBoard');

        $gone = $mbService->getBoard($testEmail, $boardId);
        assert_null($gone, 'Deleted board should be null');

        $cleanupBoardIds = array_filter($cleanupBoardIds, fn($id) => $id !== $boardId);
    });
}

// ══════════════════════════════════════════════════════════════════
// BATCH IMAGE-SET INSERT (F3)
// ══════════════════════════════════════════════════════════════════

out("\n=== Batch image-set insert (F3) ===");

test('MoodBoardService has addImagesToSetBatch', function () {
    $rc = new \ReflectionClass('\\Webmail\\Addons\\Moodboards\\Services\\MoodBoardService');
    assert_true($rc->hasMethod('addImagesToSetBatch'), 'addImagesToSetBatch missing');
});

test('MoodBoardController has addImagesToSetBatch', function () {
    $rc = new \ReflectionClass('\\Webmail\\Addons\\Moodboards\\Controllers\\MoodBoardController');
    assert_true($rc->hasMethod('addImagesToSetBatch'), 'controller addImagesToSetBatch missing');
});

test('Route registered: /mood-boards/{id}/items/{itemId}/images/batch', function () {
    $routes = file_get_contents(__DIR__ . '/../routes.php');
    assert_true(
        str_contains($routes, '/items/{itemId}/images/batch')
        || str_contains($routes, '/items/{item_id}/images/batch'),
        'image-set batch route missing'
    );
});

// ══════════════════════════════════════════════════════════════════
// GROUP: security -- Security hardening regression tests
// ══════════════════════════════════════════════════════════════════

if (shouldRun('security')) {
    out("\n=== Security hardening ===");

    $secTmpFiles = [];
    $mkTmp = function (string $ext, string $content) use (&$secTmpFiles): string {
        $path = sys_get_temp_dir() . '/flowone_test_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($path, $content);
        $secTmpFiles[] = $path;
        return $path;
    };
    $invokeValidate = function (array $fileInfo) use ($mbService) {
        $rm = new \ReflectionMethod($mbService, 'validateUploadedFile');
        $rm->setAccessible(true);
        return $rm->invoke($mbService, $fileInfo);
    };

    $controllerSrc = file_get_contents(__DIR__ . '/../src/Addons/Moodboards/Controllers/MoodBoardController.php');
    $serviceSrc    = file_get_contents(__DIR__ . '/../src/Addons/Moodboards/Services/MoodBoardService.php');

    // ── Upload validation (finding #1) ──────────────────────────────

    test('Upload validation rejects .php files', function () use ($mkTmp, $invokeValidate) {
        $tmp = $mkTmp('php', '<?php echo "owned"; ');
        $err = $invokeValidate(['name' => 'evil.php', 'size' => 20, 'tmp_name' => $tmp]);
        assert_true($err !== null, 'PHP upload must be rejected');
        vlog("Rejection reason: $err");
    });

    test('Upload validation rejects .html files', function () use ($mkTmp, $invokeValidate) {
        $tmp = $mkTmp('html', '<html><script>alert(1)</script></html>');
        $err = $invokeValidate(['name' => 'xss.html', 'size' => 40, 'tmp_name' => $tmp]);
        assert_true($err !== null, 'HTML upload must be rejected');
    });

    test('Upload validation rejects extension/content mismatch', function () use ($mkTmp, $invokeValidate) {
        $tmp = $mkTmp('png', '<?php system($_GET["c"]); ');
        $err = $invokeValidate(['name' => 'fake.png', 'size' => 30, 'tmp_name' => $tmp]);
        assert_true($err !== null, 'PHP content disguised as PNG must be rejected');
        vlog("Rejection reason: $err");
    });

    test('Upload validation rejects SVG with <script>', function () use ($mkTmp, $invokeValidate) {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';
        $tmp = $mkTmp('svg', $svg);
        $err = $invokeValidate(['name' => 'evil.svg', 'size' => strlen($svg), 'tmp_name' => $tmp]);
        assert_true($err !== null, 'SVG with script must be rejected');
    });

    test('Upload validation rejects SVG with event handlers', function () use ($mkTmp, $invokeValidate) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle onload="fetch(\'//evil.tld\')" r="10"/></svg>';
        $tmp = $mkTmp('svg', $svg);
        $err = $invokeValidate(['name' => 'evil2.svg', 'size' => strlen($svg), 'tmp_name' => $tmp]);
        assert_true($err !== null, 'SVG with on* handler must be rejected');
    });

    test('Upload validation accepts a clean PNG', function () use ($mkTmp, $invokeValidate) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $tmp = $mkTmp('png', $png);
        $err = $invokeValidate(['name' => 'ok.png', 'size' => strlen($png), 'tmp_name' => $tmp]);
        assert_null($err, 'Clean PNG should pass validation');
    });

    test('Upload validation rejects oversized files', function () use ($mkTmp, $invokeValidate) {
        $tmp = $mkTmp('png', 'x');
        $err = $invokeValidate(['name' => 'big.png', 'size' => 200 * 1024 * 1024, 'tmp_name' => $tmp]);
        assert_true($err !== null, 'Files over the size cap must be rejected');
    });

    test('Stored filenames use CSPRNG (random_bytes)', function () use ($serviceSrc) {
        assert_true(
            str_contains($serviceSrc, "bin2hex(random_bytes(16))"),
            'uploadFileLocal must use bin2hex(random_bytes(16)) for stored names'
        );
        assert_false(
            (bool)preg_match("/uniqid\\('mood_'\\)/", $serviceSrc),
            'uniqid-based filenames must be gone'
        );
    });

    // ── Path traversal (finding #3) ─────────────────────────────────

    test('All four serve methods sanitize filename with basename()', function () use ($controllerSrc) {
        $count = substr_count($controllerSrc, "basename((string)\$request->getParam('filename'))");
        assert_true($count >= 4, "Expected >=4 basename() sanitizations, found $count");
    });

    // ── Todo IDOR (finding #4) ──────────────────────────────────────

    test('updateTodo/deleteTodo cannot cross board boundaries', function () use (
        $mbService, $testEmail, $runId, $TEST_TAG, &$cleanupBoardIds
    ) {
        $boardA = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG IDOR-A $runId"]);
        $boardB = $mbService->createBoard($testEmail, ['name' => "$TEST_TAG IDOR-B $runId"]);
        $cleanupBoardIds[] = (int)$boardA['id'];
        $cleanupBoardIds[] = (int)$boardB['id'];

        $item = $mbService->addItem($testEmail, (int)$boardA['id'], [
            'type' => 'todo_list', 'pos_x' => 0, 'pos_y' => 0, 'title' => 'IDOR todos',
        ]);
        assert_true($item !== null, 'todo_list item created');

        $todo = $mbService->addTodo($testEmail, (int)$boardA['id'], (int)$item['id'], ['text' => 'secret todo']);
        assert_true($todo !== null, 'todo created');
        $todoId = (int)$todo['id'];

        // Attack: user has access to board B, tries to mutate board A's todo via board B
        $updated = $mbService->updateTodo($testEmail, (int)$boardB['id'], $todoId, ['text' => 'hijacked']);
        assert_null($updated, 'Cross-board updateTodo must fail');

        $deleted = $mbService->deleteTodo($testEmail, (int)$boardB['id'], $todoId);
        assert_false($deleted, 'Cross-board deleteTodo must fail');

        // Legitimate access through the owning board still works
        $legit = $mbService->updateTodo($testEmail, (int)$boardA['id'], $todoId, ['text' => 'updated legit']);
        assert_true($legit !== null, 'Same-board updateTodo must succeed');
        assert_equals('updated legit', $legit['text'], 'Todo text updated');
    });

    // ── Comment impersonation (finding #5) ──────────────────────────

    test('publicAddComment whitelists fields (no author_email passthrough)', function () use ($controllerSrc) {
        // Extract the publicAddComment method body
        $start = strpos($controllerSrc, 'function publicAddComment');
        assert_true($start !== false, 'publicAddComment found');
        $body = substr($controllerSrc, $start, 3500);
        assert_false(
            str_contains($body, "\$data = \$request->input();"),
            'publicAddComment must not pass raw request body to addComment'
        );
        assert_false(
            (bool)preg_match("/'author_email'\s*=>\s*\\\$input/", $body),
            'publicAddComment must never accept author_email from guests'
        );
    });

    // ── Heartbeat token validation (finding #11) ────────────────────

    test('publicHeartbeat validates the share token', function () use ($controllerSrc) {
        $start = strpos($controllerSrc, 'function publicHeartbeat');
        assert_true($start !== false, 'publicHeartbeat found');
        $body = substr($controllerSrc, $start, 1500);
        assert_true(str_contains($body, 'getShareInfo'), 'publicHeartbeat must call getShareInfo before recording');
    });

    // ── Batch caps (findings #6, #10) ───────────────────────────────

    test('MAX_BATCH_ITEMS cap exists and is enforced', function () use ($controllerSrc) {
        $rc = new \ReflectionClass('\\Webmail\\Addons\\Moodboards\\Controllers\\MoodBoardController');
        $cap = $rc->getConstant('MAX_BATCH_ITEMS');
        assert_true(is_int($cap) && $cap > 0 && $cap <= 500, 'MAX_BATCH_ITEMS sane value');
        $enforcements = substr_count($controllerSrc, 'self::MAX_BATCH_ITEMS)');
        assert_greater_than(2, $enforcements, 'Cap enforced in multiple endpoints');
    });

    // ── Hardcoded secret removal (finding #7) ───────────────────────

    test('No hardcoded encryption-key fallback remains', function () use ($controllerSrc) {
        assert_false(
            str_contains($controllerSrc, 'webmail-ai-secret-key-change-me'),
            'Hardcoded fallback secret must be removed'
        );
    });

    // ── Share password handling (findings #8, #9) ───────────────────

    test('Share password never read from GET params', function () use ($controllerSrc) {
        assert_false(str_contains($controllerSrc, "\$_GET['password']"), '$_GET[password] removed');
        assert_false(str_contains($controllerSrc, "\$_GET['p']"), '$_GET[p] removed');
    });

    test('Share password attempts are rate limited', function () use ($controllerSrc) {
        assert_true(
            str_contains($controllerSrc, 'checkSharePasswordRateLimit'),
            'Rate-limit helper must exist and be wired into password validation'
        );
    });

    test('RateLimiter blocks after limit exceeded (Redis)', function () use ($config) {
        $limiter = new \Webmail\Services\RateLimiter($config);
        if (!$limiter->isAvailable()) {
            vlog('Redis unavailable -- skipping live rate-limit test');
            return 'warn';
        }
        $key = 'rl:flowone-test:' . bin2hex(random_bytes(6));
        $blocked = false;
        for ($i = 0; $i < 6; $i++) {
            $res = $limiter->allow($key, 5, 60);
            if (!$res['allowed']) { $blocked = true; break; }
        }
        assert_true($blocked, '6th attempt with limit=5 must be blocked');
    });

    // ── AI endpoints rate limited (finding #6) ──────────────────────

    test('AI endpoints call checkAiRateLimit', function () use ($controllerSrc) {
        $count = substr_count($controllerSrc, '$this->checkAiRateLimit()');
        assert_true($count >= 3, "All 3 AI endpoints must be rate limited, found $count");
    });

    // ── Error message leakage (finding #12) ─────────────────────────

    test('No exception messages leak to API clients', function () use ($controllerSrc) {
        assert_false(
            (bool)preg_match("/'message'\s*=>\s*'[^']*'\s*\.\s*\\\$e->getMessage\(\)/", $controllerSrc),
            'Client responses must not contain $e->getMessage()'
        );
    });

    // ── SVG serving hardened (finding #2) ───────────────────────────

    test('SVG responses carry a restrictive CSP', function () use ($controllerSrc) {
        assert_true(
            str_contains($controllerSrc, "Content-Security-Policy: default-src 'none'"),
            'streamFileWithCache must add CSP for svg/html/xml'
        );
        $fastPath = file_get_contents(__DIR__ . '/../public/index.php');
        assert_true(
            str_contains($fastPath, "Content-Security-Policy: default-src 'none'"),
            'public/index.php fast-path must add CSP for svg/html/xml'
        );
    });

    // Cleanup temp files created by this group
    foreach ($secTmpFiles as $f) { @unlink($f); }
}

// ══════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════

out("\n=============================================================");
out("  RESULTS");
out("=============================================================");

$totalDuration = array_sum(array_column($results, 'ms'));

out("  Total:    $totalTests");
out("  Passed:   \033[32m$passed\033[0m");
if ($warnings > 0) out("  Warnings: \033[33m$warnings\033[0m");
if ($failed > 0)   out("  Failed:   \033[31m$failed\033[0m");
out("  Duration: {$totalDuration}ms");
out("  Log:      $logFile");

if ($failed > 0) {
    out("\n  FAILURES:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    - {$r['name']}: {$r['error']}");
        }
    }
}

out("=============================================================\n");

exit($failed > 0 ? 1 : 0);
