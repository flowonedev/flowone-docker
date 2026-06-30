#!/usr/bin/env php
<?php
/**
 * FlowOne Chat System - Comprehensive Test Suite
 *
 * Tests DM conversations, group chats, channels, messages, reactions,
 * threads, pins, read receipts, typing indicators, search, bookmarks,
 * scheduled messages, meeting conversations, call history, huddles,
 * WebSocket/Redis pub/sub, and settings.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/chat-system-test.php \
 *       --email=user@flowone.pro --verbose
 *
 * Options:
 *   --email=EMAIL        Test account email (required)
 *   --only=GROUPS        Comma-separated: dm,message,reaction,thread,pin,read,typing,search,group,channel,meeting,call,huddle,schedule,bookmark,settings,mute,ws
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
    echo "FlowOne Chat System Test Suite\n";
    echo "==============================\n\n";
    echo "Usage:\n";
    echo "  php chat-system-test.php --email=user@flowone.pro [options]\n\n";
    echo "Options:\n";
    echo "  --email=EMAIL        Test account email (required)\n";
    echo "  --only=GROUPS        Comma-separated: dm,message,reaction,thread,pin,read,typing,search,group,channel,meeting,call,huddle,schedule,bookmark,settings,mute,ws\n";
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
    if ($smokeOnly) return in_array($group, ['dm', 'message', 'group', 'channel']);
    if (empty($onlyGroups)) return true;
    return in_array($group, $onlyGroups);
}

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/chat-system-test-' . date('Ymd-His') . '.log';
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

$TEST_TAG = '[CHATTEST]';
$runId    = date('His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

$cleanupConversationIds = [];
$cleanupChannelIds      = [];
$cleanupHuddleIds       = [];

function doCleanup(): void {
    global $config, $testEmail, $cleanupConversationIds, $cleanupChannelIds, $cleanupHuddleIds;

    out("\n--- CLEANUP ---");

    $db = \Webmail\Core\Database::getConnection($config);

    foreach ($cleanupConversationIds as $cid) {
        try {
            $msgSubquery = "SELECT id FROM chat_messages WHERE conversation_id = $cid";
            try { $db->exec("DELETE FROM chat_message_reactions WHERE message_id IN ($msgSubquery)"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_read_receipts WHERE message_id IN ($msgSubquery)"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_bookmarks WHERE message_id IN ($msgSubquery)"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_mentions WHERE conversation_id = $cid"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_attachments WHERE conversation_id = $cid"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_scheduled_messages WHERE conversation_id = $cid"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM call_history WHERE conversation_id = $cid"); } catch (\Exception $e) {}

            $db->exec("DELETE FROM chat_messages WHERE conversation_id = $cid");
            $db->exec("DELETE FROM chat_typing_status WHERE conversation_id = $cid");
            $db->exec("DELETE FROM chat_participants WHERE conversation_id = $cid");
            try { $db->exec("DELETE FROM chat_dm_lookup WHERE conversation_id = $cid"); } catch (\Exception $e) {}

            $db->exec("DELETE FROM chat_conversations WHERE id = $cid");
            out("          [v] Deleted conversation ID $cid");
        } catch (\Exception $e) {
            out("          [!] Cleanup error for conv $cid: " . $e->getMessage());
        }
    }

    foreach ($cleanupChannelIds as $cid) {
        try {
            $msgSubquery = "SELECT id FROM chat_messages WHERE conversation_id = $cid";
            try { $db->exec("DELETE FROM chat_message_reactions WHERE message_id IN ($msgSubquery)"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_read_receipts WHERE message_id IN ($msgSubquery)"); } catch (\Exception $e) {}
            try { $db->exec("DELETE FROM chat_mentions WHERE conversation_id = $cid"); } catch (\Exception $e) {}

            $db->exec("DELETE FROM chat_messages WHERE conversation_id = $cid");
            $db->exec("DELETE FROM chat_typing_status WHERE conversation_id = $cid");
            $db->exec("DELETE FROM chat_participants WHERE conversation_id = $cid");
            $db->exec("DELETE FROM chat_conversations WHERE id = $cid");
            out("          [v] Deleted channel ID $cid");
        } catch (\Exception $e) {
            out("          [!] Cleanup error for channel $cid: " . $e->getMessage());
        }
    }

    foreach ($cleanupHuddleIds as $hid) {
        try {
            $db->prepare("DELETE FROM chat_huddle_participants WHERE huddle_id = ?")->execute([$hid]);
            $db->prepare("DELETE FROM chat_huddles WHERE id = ?")->execute([$hid]);
            out("          [v] Deleted huddle ID $hid");
        } catch (\Exception $e) {
            out("          [!] Cleanup error for huddle $hid: " . $e->getMessage());
        }
    }

    // Clean up call history test rows
    try {
        $db->prepare("DELETE FROM call_history WHERE conversation_id IN (" . implode(',', array_merge($cleanupConversationIds, [0])) . ") AND caller_id = 0")->execute();
    } catch (\Exception $e) {}

    out("  Cleanup complete.");
}

register_shutdown_function('doCleanup');
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  function () { doCleanup(); exit(130); });
    pcntl_signal(SIGTERM, function () { doCleanup(); exit(143); });
}

// ── Header ──────────────────────────────────────────────────────

$mode = $smokeOnly ? 'SMOKE' : (empty($onlyGroups) ? 'FULL' : 'Groups: ' . implode(', ', $onlyGroups));
out("=============================================================");
out("  FlowOne Chat System Test Suite");
out("  Account : $testEmail");
out("  Run ID  : $runId");
out("  Mode    : $mode");
out("=============================================================");

// ── Pre-flight ──────────────────────────────────────────────────

out("\n=== Pre-flight checks ===");
$startTime = microtime(true);

$db = null;
test('Database connection', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    assert_true($db instanceof \PDO, 'PDO instance');
});

test('chat_conversations table exists', function () use (&$db) {
    $r = $db->query("SHOW TABLES LIKE 'chat_conversations'");
    assert_true($r->rowCount() > 0, 'Table missing');
});

test('chat_messages table exists', function () use (&$db) {
    $r = $db->query("SHOW TABLES LIKE 'chat_messages'");
    assert_true($r->rowCount() > 0, 'Table missing');
});

test('call_history table exists', function () use (&$db) {
    $r = $db->query("SHOW TABLES LIKE 'call_history'");
    assert_true($r->rowCount() > 0, 'Table missing');
});

$chatService = null;
test('ChatService instantiates', function () use ($config, &$chatService) {
    $chatService = new \Webmail\Addons\Chat\Services\ChatService($config);
    assert_true($chatService !== null, 'ChatService is null');
});

$colleague = null;
$colleagueId = null;
test('Test user exists as colleague', function () use ($chatService, $testEmail, &$colleague, &$colleagueId) {
    $colleague = $chatService->getColleagueByEmail($testEmail);
    assert_true($colleague !== null, "Colleague not found for $testEmail");
    assert_not_empty($colleague['id'], 'Colleague ID');
    $colleagueId = (int)$colleague['id'];
    vlog("Colleague ID: {$colleague['id']}, name: " . ($colleague['display_name'] ?? $colleague['email']));
});

// Find a second colleague for DM tests
$colleague2 = null;
test('Second colleague available for DM tests', function () use (&$db, $testEmail, &$colleague2) {
    $stmt = $db->prepare("SELECT * FROM organization_colleagues WHERE email != ? AND organization_domain = (SELECT organization_domain FROM organization_colleagues WHERE email = ?) LIMIT 1");
    $stmt->execute([strtolower($testEmail), strtolower($testEmail)]);
    $colleague2 = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$colleague2) {
        vlog("No second colleague found -- DM tests will be limited");
        return 'warn';
    }
    vlog("Second colleague: ID {$colleague2['id']}, email: {$colleague2['email']}");
});

// ── Shared state across test groups ─────────────────────────────

$dmConvId    = null;
$groupConvId = null;
$channelId   = null;
$messageId   = null;
$threadMsgId = null;

// ========================================
// DM CONVERSATIONS
// ========================================

if (shouldRun('dm')) {
    out("\n=== DM Conversations ===");

    test('getOrCreateDMConversation creates new DM', function () use ($chatService, $testEmail, $colleague2, &$dmConvId, &$cleanupConversationIds) {
        if (!$colleague2) return 'warn';

        $result = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        assert_true($result['success'], 'Create DM: ' . ($result['error'] ?? 'unknown error'));
        assert_not_empty($result['conversation']['id'], 'Conversation ID');
        $dmConvId = (int)$result['conversation']['id'];
        $cleanupConversationIds[] = $dmConvId;
        assert_equals('direct', $result['conversation']['type'], 'Type is direct');
        vlog("DM conversation ID: $dmConvId");
    });

    test('getOrCreateDMConversation returns existing DM on retry', function () use ($chatService, $testEmail, $colleague2, &$dmConvId) {
        if (!$colleague2 || !$dmConvId) return 'warn';

        $result = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        assert_true($result['success'], 'Re-fetch DM');
        assert_equals($dmConvId, (int)$result['conversation']['id'], 'Same conversation returned');
    });

    test('getConversation retrieves DM details', function () use ($chatService, $testEmail, &$dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->getConversation($dmConvId, $testEmail);
        assert_true($result['success'], 'Get conversation');
        assert_equals('direct', $result['conversation']['type'], 'Type');
        assert_true(isset($result['conversation']['participants']), 'Participants present');
        vlog("Participants: " . count($result['conversation']['participants']));
    });

    test('getConversations lists DM in user conversation list', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->getConversations($testEmail, 100);
        assert_true($result['success'], 'List conversations');
        $ids = array_column($result['conversations'], 'id');
        assert_true(in_array($dmConvId, $ids), 'DM in list');
        vlog("Total conversations: " . count($result['conversations']));
    });

    test('getConversation for wrong user returns error', function () use ($chatService, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->getConversation($dmConvId, 'nonexistent@example.com');
        assert_false($result['success'], 'Should fail for wrong user');
    });
}

// ========================================
// MESSAGES
// ========================================

if (shouldRun('message')) {
    out("\n=== Messages ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) {
            $dmConvId = (int)$r['conversation']['id'];
            $cleanupConversationIds[] = $dmConvId;
        }
    }

    test('sendMessage sends a text message', function () use ($chatService, $testEmail, $dmConvId, &$messageId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->sendMessage($dmConvId, $testEmail, "Test message $GLOBALS[runId]");
        assert_true($result['success'], 'Send message: ' . ($result['error'] ?? ''));
        assert_not_empty($result['message']['id'], 'Message ID');
        assert_equals("Test message $GLOBALS[runId]", $result['message']['content'], 'Content');
        $messageId = (int)$result['message']['id'];
        vlog("Message ID: $messageId");
    });

    test('getMessages retrieves messages for conversation', function () use ($chatService, $testEmail, $dmConvId, $messageId) {
        if (!$dmConvId || !$messageId) return 'warn';

        $result = $chatService->getMessages($dmConvId, $testEmail);
        assert_true($result['success'], 'Get messages');
        assert_true(is_array($result['messages']), 'Messages array');
        $ids = array_column($result['messages'], 'id');
        assert_true(in_array($messageId, $ids), 'Our message in list');
        vlog("Messages count: " . count($result['messages']));
    });

    test('sendMessage with reply_to creates threaded reply', function () use ($chatService, $testEmail, $dmConvId, $messageId, &$threadMsgId) {
        if (!$dmConvId || !$messageId) return 'warn';

        $result = $chatService->sendMessage($dmConvId, $testEmail, "Reply to parent", $messageId);
        assert_true($result['success'], 'Send reply');
        assert_equals($messageId, (int)$result['message']['reply_to_id'], 'reply_to_id');
        $threadMsgId = (int)$result['message']['id'];
        vlog("Thread reply ID: $threadMsgId, parent: $messageId");
    });

    test('editMessage changes content', function () use ($chatService, $testEmail, $messageId, $dmConvId) {
        if (!$messageId) return 'warn';

        $result = $chatService->editMessage($messageId, $testEmail, "Edited content $GLOBALS[runId]");
        assert_true($result['success'], 'Edit message');

        $msgs = $chatService->getMessages($dmConvId, $testEmail);
        $edited = null;
        foreach ($msgs['messages'] as $m) {
            if ((int)$m['id'] === $messageId) { $edited = $m; break; }
        }
        assert_true($edited !== null, 'Find edited message');
        assert_equals("Edited content $GLOBALS[runId]", $edited['content'], 'Edited content');
        vlog("Edited message verified, is_edited: " . ($edited['is_edited'] ?? 'n/a'));
    });

    test('editMessage by wrong user fails', function () use ($chatService, $messageId) {
        if (!$messageId) return 'warn';

        $result = $chatService->editMessage($messageId, 'wrong@example.com', 'Should fail');
        assert_false($result['success'], 'Should reject wrong user edit');
    });

    $deleteTestMsgId = null;
    test('deleteMessage soft-deletes a message', function () use ($chatService, $testEmail, $dmConvId, &$deleteTestMsgId) {
        if (!$dmConvId) return 'warn';

        $send = $chatService->sendMessage($dmConvId, $testEmail, "To be deleted");
        assert_true($send['success'], 'Send disposable message');
        $deleteTestMsgId = (int)$send['message']['id'];

        $result = $chatService->deleteMessage($deleteTestMsgId, $testEmail);
        assert_true($result['success'], 'Delete message');
    });

    test('Multiple messages maintain order', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $r = $chatService->sendMessage($dmConvId, $testEmail, "Order test $i");
            assert_true($r['success'], "Send msg $i");
            $ids[] = (int)$r['message']['id'];
        }

        $msgs = $chatService->getMessages($dmConvId, $testEmail, 50);
        assert_true($msgs['success'], 'Get messages');
        $fetchedIds = array_column($msgs['messages'], 'id');
        foreach ($ids as $id) {
            assert_true(in_array($id, $fetchedIds), "Message $id in results");
        }
        vlog("Order IDs: " . implode(', ', $ids));
    });
}

// ========================================
// REACTIONS
// ========================================

if (shouldRun('reaction')) {
    out("\n=== Reactions ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }
    if (!$messageId && $dmConvId) {
        $r = $chatService->sendMessage($dmConvId, $testEmail, "Reaction target $runId");
        if ($r['success']) $messageId = (int)$r['message']['id'];
    }

    test('addReaction adds emoji to message', function () use ($chatService, $testEmail, $messageId) {
        if (!$messageId) return 'warn';

        $result = $chatService->addReaction($messageId, $testEmail, '👍');
        assert_true($result['success'], 'Add reaction: ' . ($result['error'] ?? ''));
        vlog("Reaction added to message $messageId");
    });

    test('addReaction with different emoji adds second reaction', function () use ($chatService, $testEmail, $messageId) {
        if (!$messageId) return 'warn';

        $result = $chatService->addReaction($messageId, $testEmail, '❤️');
        assert_true($result['success'], 'Add second reaction');
    });

    test('addReaction duplicate is idempotent', function () use ($chatService, $testEmail, $messageId) {
        if (!$messageId) return 'warn';

        $result = $chatService->addReaction($messageId, $testEmail, '👍');
        assert_true($result['success'], 'Duplicate reaction');
    });

    test('removeReaction removes emoji', function () use ($chatService, $testEmail, $messageId) {
        if (!$messageId) return 'warn';

        $result = $chatService->removeReaction($messageId, $testEmail, '❤️');
        assert_true($result['success'], 'Remove reaction');
    });
}

// ========================================
// THREADS
// ========================================

if (shouldRun('thread')) {
    out("\n=== Threads ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    $threadParentId = null;
    test('Create parent message for thread', function () use ($chatService, $testEmail, $dmConvId, &$threadParentId) {
        if (!$dmConvId) return 'warn';

        $r = $chatService->sendMessage($dmConvId, $testEmail, "Thread parent $GLOBALS[runId]");
        assert_true($r['success'], 'Send parent');
        $threadParentId = (int)$r['message']['id'];
        vlog("Thread parent: $threadParentId");
    });

    test('Send replies to create a thread', function () use ($chatService, $testEmail, $dmConvId, $threadParentId) {
        if (!$threadParentId) return 'warn';

        for ($i = 1; $i <= 3; $i++) {
            $r = $chatService->sendMessage($dmConvId, $testEmail, "Thread reply $i", $threadParentId);
            assert_true($r['success'], "Reply $i");
        }
        vlog("Sent 3 thread replies");
    });

    test('getThread returns parent and replies', function () use ($chatService, $testEmail, $threadParentId) {
        if (!$threadParentId) return 'warn';

        $result = $chatService->getThread($threadParentId, $testEmail);
        assert_true($result['success'], 'Get thread');
        assert_true(isset($result['messages']) && count($result['messages']) > 0, 'Has messages');
        assert_equals($threadParentId, (int)$result['messages'][0]['id'], 'First message is parent');
        $replyCount = count($result['messages']) - 1;
        assert_greater_than(0, $replyCount, 'Has replies');
        vlog("Thread messages: " . count($result['messages']) . " (1 parent + $replyCount replies)");
    });

    test('getActiveThreads includes our thread', function () use ($chatService, $testEmail, $threadParentId) {
        if (!$threadParentId) return 'warn';

        $result = $chatService->getActiveThreads($testEmail);
        assert_true($result['success'], 'Active threads');
        $parentIds = array_column($result['threads'], 'id');
        assert_true(in_array($threadParentId, $parentIds), 'Our thread in active list');
        vlog("Active threads: " . count($result['threads']));
    });

    test('deleteThread removes entire thread', function () use ($chatService, $testEmail, $threadParentId) {
        if (!$threadParentId) return 'warn';

        $result = $chatService->deleteThread($threadParentId, $testEmail);
        assert_true($result['success'], 'Delete thread');
    });
}

// ========================================
// PINS
// ========================================

if (shouldRun('pin')) {
    out("\n=== Pins ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    $pinMsgId = null;
    test('Pin a message', function () use ($chatService, $testEmail, $dmConvId, &$pinMsgId) {
        if (!$dmConvId) return 'warn';

        $send = $chatService->sendMessage($dmConvId, $testEmail, "Pin me $GLOBALS[runId]");
        assert_true($send['success'], 'Send message to pin');
        $pinMsgId = (int)$send['message']['id'];

        $result = $chatService->togglePinMessage($pinMsgId, $testEmail);
        assert_true($result['success'], 'Pin message');
        vlog("Pinned message: $pinMsgId");
    });

    test('getPinnedMessages lists pinned message', function () use ($chatService, $testEmail, $dmConvId, $pinMsgId) {
        if (!$pinMsgId) return 'warn';

        $result = $chatService->getPinnedMessages($dmConvId, $testEmail);
        assert_true($result['success'], 'Get pinned');
        $ids = array_column($result['messages'], 'id');
        assert_true(in_array($pinMsgId, $ids), 'Pinned message in list');
        vlog("Pinned messages: " . count($result['messages']));
    });

    test('Unpin message (toggle)', function () use ($chatService, $testEmail, $pinMsgId) {
        if (!$pinMsgId) return 'warn';

        $result = $chatService->togglePinMessage($pinMsgId, $testEmail);
        assert_true($result['success'], 'Unpin message');
    });

    test('togglePin on conversation (sticky)', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->togglePin($dmConvId, $testEmail);
        assert_true($result['success'], 'Pin conversation');

        $result2 = $chatService->togglePin($dmConvId, $testEmail);
        assert_true($result2['success'], 'Unpin conversation');
    });
}

// ========================================
// READ RECEIPTS
// ========================================

if (shouldRun('read')) {
    out("\n=== Read receipts ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    test('markAsRead marks conversation read', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $chatService->sendMessage($dmConvId, $testEmail, "Read test $GLOBALS[runId]");
        $result = $chatService->markAsRead($dmConvId, $testEmail);
        assert_true($result['success'], 'Mark as read');
        vlog("Marked conversation $dmConvId as read");
    });

    test('getUnreadCounts returns zero after marking read', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->getUnreadCounts($testEmail);
        assert_true($result['success'], 'Get unread');
        $byConv = $result['by_conversation'] ?? [];
        $count = $byConv[$dmConvId] ?? 0;
        assert_equals(0, (int)$count, "Unread count for conv $dmConvId");
        vlog("Total unread: " . ($result['total'] ?? 0) . ", by_conversation sample: " . json_encode(array_slice($byConv, 0, 5, true)));
    });
}

// ========================================
// TYPING INDICATORS
// ========================================

if (shouldRun('typing')) {
    out("\n=== Typing indicators ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    test('updateTypingStatus sets typing=true', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->updateTypingStatus($dmConvId, $testEmail, true);
        assert_true($result['success'], 'Start typing');
    });

    test('updateTypingStatus sets typing=false', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->updateTypingStatus($dmConvId, $testEmail, false);
        assert_true($result['success'], 'Stop typing');
    });
}

// ========================================
// SEARCH
// ========================================

if (shouldRun('search')) {
    out("\n=== Search ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    test('Send searchable message', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $r = $chatService->sendMessage($dmConvId, $testEmail, "UniqueSearchToken_{$GLOBALS['runId']}");
        assert_true($r['success'], 'Send searchable message');
    });

    test('searchMessages finds message by content', function () use ($chatService, $testEmail) {
        $result = $chatService->searchMessages($testEmail, "UniqueSearchToken_{$GLOBALS['runId']}");
        assert_true($result['success'], 'Search');
        assert_greater_than(0, count($result['messages']), 'Found results');
        vlog("Search results: " . count($result['messages']));
    });

    test('searchMessages within specific conversation', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->searchMessages($testEmail, "UniqueSearchToken_{$GLOBALS['runId']}", $dmConvId);
        assert_true($result['success'], 'Scoped search');
        assert_greater_than(0, count($result['messages']), 'Found in conversation');
    });

    test('searchMessages for non-existent term returns empty', function () use ($chatService, $testEmail) {
        $result = $chatService->searchMessages($testEmail, 'zzz_nonexistent_' . bin2hex(random_bytes(8)));
        assert_true($result['success'], 'Search for gibberish');
        assert_equals(0, count($result['messages']), 'No results');
    });
}

// ========================================
// GROUP CHAT
// ========================================

if (shouldRun('group')) {
    out("\n=== Group chat ===");

    test('createGroup creates a group conversation', function () use ($chatService, $testEmail, $colleague, $colleague2, &$groupConvId, &$cleanupConversationIds, $runId) {
        if (!$colleague2) return 'warn';

        $memberIds = [(int)$colleague2['id']];
        $result = $chatService->createGroup($testEmail, $memberIds, "Test Group $runId", "Test description");
        assert_true($result['success'], 'Create group: ' . ($result['error'] ?? ''));
        assert_not_empty($result['conversation']['id'], 'Group ID');
        assert_equals('group', $result['conversation']['type'], 'Type is group');
        $groupConvId = (int)$result['conversation']['id'];
        $cleanupConversationIds[] = $groupConvId;
        vlog("Group conversation ID: $groupConvId");
    });

    test('sendMessage in group works', function () use ($chatService, $testEmail, $groupConvId) {
        if (!$groupConvId) return 'warn';

        $result = $chatService->sendMessage($groupConvId, $testEmail, "Group message test");
        assert_true($result['success'], 'Send group message');
        vlog("Group message ID: " . $result['message']['id']);
    });

    test('getGroupMembers lists all members', function () use ($chatService, $testEmail, $groupConvId) {
        if (!$groupConvId) return 'warn';

        $result = $chatService->getGroupMembers($groupConvId, $testEmail);
        assert_true($result['success'], 'Get members');
        assert_greater_than(1, count($result['members']), 'At least 2 members');
        vlog("Group members: " . count($result['members']));
    });

    test('updateGroup changes name', function () use ($chatService, $testEmail, $groupConvId) {
        if (!$groupConvId) return 'warn';

        $result = $chatService->updateGroup($groupConvId, $testEmail, ['name' => "Renamed Group"]);
        assert_true($result['success'], 'Update group');
    });

    test('toggleMute mutes/unmutes conversation', function () use ($chatService, $testEmail, $groupConvId) {
        if (!$groupConvId) return 'warn';

        $r1 = $chatService->toggleMute($groupConvId, $testEmail);
        assert_true($r1['success'], 'Mute');
        $r2 = $chatService->toggleMute($groupConvId, $testEmail);
        assert_true($r2['success'], 'Unmute');
    });

    test('archiveConversation archives and unarchives', function () use ($chatService, $testEmail, $groupConvId) {
        if (!$groupConvId) return 'warn';

        $r1 = $chatService->archiveConversation($groupConvId, $testEmail, true);
        assert_true($r1['success'], 'Archive');
        $r2 = $chatService->archiveConversation($groupConvId, $testEmail, false);
        assert_true($r2['success'], 'Unarchive');
    });
}

// ========================================
// CHANNELS
// ========================================

if (shouldRun('channel')) {
    out("\n=== Channels ===");

    $channelService = new \Webmail\Addons\Chat\Services\ChannelService($config);

    test('createChannel creates a public channel', function () use ($channelService, $testEmail, &$channelId, &$cleanupChannelIds, $runId) {
        $result = $channelService->createChannel($testEmail, "test-channel-$runId", true, 'Test topic', 'Test purpose');
        assert_true($result['success'], 'Create channel: ' . ($result['error'] ?? ''));
        assert_not_empty($result['channel']['id'] ?? null, 'Channel ID');
        $channelId = (int)$result['channel']['id'];
        $cleanupChannelIds[] = $channelId;
        vlog("Channel ID: $channelId");
    });

    test('browseChannels lists the new channel', function () use ($channelService, $testEmail, $channelId, $runId) {
        if (!$channelId) return 'warn';

        $result = $channelService->browseChannels($testEmail);
        assert_true($result['success'], 'Browse channels');
        $ids = array_column($result['channels'], 'id');
        assert_true(in_array($channelId, $ids), 'Channel in browse list');
        vlog("Browseable channels: " . count($result['channels']));
    });

    test('getChannelInfo returns channel details', function () use ($channelService, $channelId) {
        if (!$channelId) return 'warn';

        $info = $channelService->getChannelInfo($channelId);
        assert_true($info !== null, 'Channel info');
        assert_equals('Test topic', $info['topic'], 'Topic');
        assert_equals('Test purpose', $info['purpose'], 'Purpose');
    });

    test('setTopic changes channel topic', function () use ($channelService, $testEmail, $channelId) {
        if (!$channelId) return 'warn';

        $result = $channelService->setTopic($channelId, $testEmail, 'Updated topic');
        assert_true($result['success'], 'Set topic');
    });

    test('setPurpose changes channel purpose', function () use ($channelService, $testEmail, $channelId) {
        if (!$channelId) return 'warn';

        $result = $channelService->setPurpose($channelId, $testEmail, 'Updated purpose');
        assert_true($result['success'], 'Set purpose');
    });

    test('Send message in channel', function () use ($chatService, $testEmail, $channelId) {
        if (!$channelId) return 'warn';

        $result = $chatService->sendMessage($channelId, $testEmail, "Channel message test", null, null, null, false, true);
        assert_true($result['success'], 'Send channel message');
    });

    test('leaveChannel removes user from channel', function () use ($channelService, $testEmail, $channelId) {
        if (!$channelId) return 'warn';

        $result = $channelService->leaveChannel($channelId, $testEmail);
        assert_true($result['success'], 'Leave channel');
    });

    test('joinChannel re-adds user to channel', function () use ($channelService, $testEmail, $channelId) {
        if (!$channelId) return 'warn';

        $result = $channelService->joinChannel($channelId, $testEmail);
        assert_true($result['success'], 'Rejoin channel');
    });
}

// ========================================
// MEETING CONVERSATIONS
// ========================================

if (shouldRun('meeting')) {
    out("\n=== Meeting conversations ===");

    $meetingConvId = null;
    test('createMeetingConversation creates a meeting chat', function () use ($chatService, $testEmail, $colleague2, &$meetingConvId, &$cleanupConversationIds, $runId) {
        $participants = $colleague2 ? [$colleague2['email']] : [];
        $result = $chatService->createMeetingConversation($testEmail, "Test Meeting $runId", $participants);
        assert_true($result['success'], 'Create meeting conv: ' . ($result['error'] ?? ''));
        assert_not_empty($result['conversation_id'] ?? null, 'Meeting conv ID');
        $meetingConvId = (int)$result['conversation_id'];
        $cleanupConversationIds[] = $meetingConvId;
        vlog("Meeting conversation ID: $meetingConvId");
    });

    test('sendMessage in meeting conversation', function () use ($chatService, $testEmail, &$meetingConvId) {
        if (!$meetingConvId) return 'warn';

        $result = $chatService->sendMessage($meetingConvId, $testEmail, "Meeting chat message");
        assert_true($result['success'], 'Send meeting message');
    });

    test('getConversation returns meeting type (stored as group)', function () use ($chatService, $testEmail, &$meetingConvId) {
        if (!$meetingConvId) return 'warn';

        $result = $chatService->getConversation($meetingConvId, $testEmail);
        assert_true($result['success'], 'Get meeting conv');
        assert_equals('group', $result['conversation']['type'], 'Type is group (meeting stored as group)');
        assert_true(str_starts_with($result['conversation']['name'], 'Meeting:'), 'Name prefixed with Meeting:');
    });
}

// ========================================
// CALL HISTORY
// ========================================

if (shouldRun('call')) {
    out("\n=== Call history & ICE ===");

    $callService = new \Webmail\Services\CallService($config);

    test('getTurnCredentials returns ICE servers', function () use ($callService, $testEmail) {
        $result = $callService->getTurnCredentials($testEmail);
        assert_true($result['success'], 'Get TURN credentials');
        assert_true(is_array($result['iceServers']), 'iceServers is array');
        assert_greater_than(0, count($result['iceServers']), 'At least STUN server');
        vlog("ICE servers: " . count($result['iceServers']));
    });

    test('getLiveKitToken generates JWT', function () use ($callService, $testEmail) {
        $roomName = 'test-room-' . $GLOBALS['runId'];
        try {
            $result = $callService->getLiveKitToken($roomName, $testEmail, 'Test User');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not configured')) {
                vlog("LiveKit not configured -- skipping");
                return 'warn';
            }
            throw $e;
        }
        assert_not_empty($result['token'] ?? null, 'JWT token');
        vlog("LiveKit token length: " . strlen($result['token']));
    });

    test('saveCallHistory records a call', function () use ($callService, $dmConvId, $colleagueId) {
        if (!$dmConvId) return 'warn';

        $callId = 'test_call_' . $GLOBALS['runId'];
        $result = $callService->saveCallHistory([
            'call_id'          => $callId,
            'conversation_id'  => $dmConvId,
            'initiated_by'     => $colleagueId,
            'call_type'        => 'video',
            'status'           => 'completed',
            'started_at'       => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            'ended_at'         => date('Y-m-d H:i:s'),
            'duration_seconds' => 120,
            'participants'     => [$colleagueId],
            'had_screen_share' => 1,
        ]);
        assert_true($result['success'], 'Save call: ' . ($result['error'] ?? ''));
        assert_not_empty($result['id'] ?? null, 'Call history ID');
        vlog("Call history ID: " . ($result['id'] ?? 'n/a'));
    });

    test('getCallHistory retrieves records', function () use ($callService, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $calls = $callService->getCallHistory($dmConvId);
        assert_true(is_array($calls), 'Returns array');
        assert_greater_than(0, count($calls), 'Has call records');
        $latest = $calls[0];
        assert_equals('video', $latest['call_type'], 'Call type');
        vlog("Call records: " . count($calls));
    });
}

// ========================================
// HUDDLES
// ========================================

if (shouldRun('huddle')) {
    out("\n=== Huddles ===");

    $huddleService = new \Webmail\Services\HuddleService($db);

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    $huddleId = null;
    test('startHuddle creates or joins a huddle', function () use ($huddleService, $testEmail, $dmConvId, &$huddleId, &$cleanupHuddleIds) {
        if (!$dmConvId) return 'warn';

        $result = $huddleService->startHuddle($dmConvId, $testEmail);
        assert_true($result['success'], 'Start huddle: ' . ($result['error'] ?? ''));
        assert_not_empty($result['huddle']['id'], 'Huddle ID');
        $huddleId = (int)$result['huddle']['id'];
        $cleanupHuddleIds[] = $huddleId;
        vlog("Huddle ID: $huddleId");
    });

    test('getActiveHuddle returns the running huddle', function () use ($huddleService, $testEmail, $dmConvId, $huddleId) {
        if (!$huddleId) return 'warn';

        $result = $huddleService->getActiveHuddle($dmConvId, $testEmail);
        assert_true($result['success'], 'Get active huddle');
        assert_equals($huddleId, (int)$result['huddle']['id'], 'Huddle ID matches');
        vlog("Active huddle participants: " . count($result['huddle']['participants'] ?? []));
    });

    test('getAllActiveHuddles includes our huddle', function () use ($huddleService, $testEmail, $huddleId) {
        if (!$huddleId) return 'warn';

        $result = $huddleService->getAllActiveHuddles($testEmail);
        assert_true($result['success'], 'Get all huddles');
        $ids = array_column($result['huddles'], 'id');
        assert_true(in_array($huddleId, $ids), 'Our huddle in list');
        vlog("Active huddles total: " . count($result['huddles']));
    });

    test('leaveHuddle removes user from huddle', function () use ($huddleService, $testEmail, $huddleId) {
        if (!$huddleId) return 'warn';

        $result = $huddleService->leaveHuddle($huddleId, $testEmail);
        assert_true($result['success'], 'Leave huddle');
    });
}

// ========================================
// SCHEDULED MESSAGES
// ========================================

if (shouldRun('schedule')) {
    out("\n=== Scheduled messages ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    $scheduledId = null;
    test('scheduleMessage creates a scheduled message', function () use ($chatService, $testEmail, $dmConvId, &$scheduledId) {
        if (!$dmConvId) return 'warn';

        $futureTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $result = $chatService->scheduleMessage($dmConvId, $testEmail, "Scheduled: $GLOBALS[runId]", $futureTime);
        assert_true($result['success'], 'Schedule message: ' . ($result['error'] ?? ''));
        assert_not_empty($result['scheduled_message']['id'] ?? null, 'Scheduled ID');
        $scheduledId = (int)$result['scheduled_message']['id'];
        vlog("Scheduled message ID: $scheduledId");
    });

    test('getScheduledMessages lists the scheduled message', function () use ($chatService, $testEmail, $scheduledId) {
        if (!$scheduledId) return 'warn';

        $result = $chatService->getScheduledMessages($testEmail);
        assert_true($result['success'], 'Get scheduled');
        $messages = $result['messages'] ?? [];
        $ids = array_map(fn($s) => (int)$s['id'], $messages);
        assert_true(in_array($scheduledId, $ids), 'Scheduled in list');
        vlog("Scheduled messages: " . count($messages));
    });

    test('updateScheduledMessage changes content', function () use ($chatService, $testEmail, $scheduledId) {
        if (!$scheduledId) return 'warn';

        $result = $chatService->updateScheduledMessage($scheduledId, $testEmail, ['content' => 'Updated scheduled']);
        assert_true($result['success'], 'Update scheduled');
    });

    test('deleteScheduledMessage removes it', function () use ($chatService, $testEmail, $scheduledId) {
        if (!$scheduledId) return 'warn';

        $result = $chatService->deleteScheduledMessage($scheduledId, $testEmail);
        assert_true($result['success'], 'Delete scheduled');
    });
}

// ========================================
// BOOKMARKS
// ========================================

if (shouldRun('bookmark')) {
    out("\n=== Bookmarks ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    $bookmarkMsgId = null;
    test('toggleBookmark adds bookmark', function () use ($chatService, $testEmail, $dmConvId, &$bookmarkMsgId) {
        if (!$dmConvId) return 'warn';

        $send = $chatService->sendMessage($dmConvId, $testEmail, "Bookmark me $GLOBALS[runId]");
        assert_true($send['success'], 'Send message');
        $bookmarkMsgId = (int)$send['message']['id'];

        $result = $chatService->toggleBookmark($bookmarkMsgId, $testEmail);
        assert_true($result['success'], 'Add bookmark');
        vlog("Bookmarked message: $bookmarkMsgId");
    });

    test('getBookmarks lists bookmarked message', function () use ($chatService, $testEmail, $bookmarkMsgId) {
        if (!$bookmarkMsgId) return 'warn';

        $result = $chatService->getBookmarks($testEmail);
        assert_true($result['success'], 'Get bookmarks');
        $msgIds = array_map(fn($b) => (int)$b['id'], $result['bookmarks'] ?? []);
        assert_true(in_array($bookmarkMsgId, $msgIds), 'Bookmark in list');
        vlog("Bookmarks: " . count($result['bookmarks']));
    });

    test('toggleBookmark removes bookmark', function () use ($chatService, $testEmail, $bookmarkMsgId) {
        if (!$bookmarkMsgId) return 'warn';

        $result = $chatService->toggleBookmark($bookmarkMsgId, $testEmail);
        assert_true($result['success'], 'Remove bookmark');
    });
}

// ========================================
// CONVERSATION SETTINGS
// ========================================

if (shouldRun('settings')) {
    out("\n=== Conversation settings ===");

    if (!$dmConvId && $colleague2) {
        $r = $chatService->getOrCreateDMConversation($testEmail, (int)$colleague2['id']);
        if ($r['success']) { $dmConvId = (int)$r['conversation']['id']; $cleanupConversationIds[] = $dmConvId; }
    }

    test('getConversationSettings returns settings', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->getConversationSettings($dmConvId, $testEmail);
        assert_true($result['success'], 'Get settings');
        assert_true(is_array($result['settings']), 'Settings is array');
        vlog("Settings keys: " . implode(', ', array_keys($result['settings'])));
    });

    test('updateConversationSettings changes settings', function () use ($chatService, $testEmail, $dmConvId) {
        if (!$dmConvId) return 'warn';

        $result = $chatService->updateConversationSettings($dmConvId, $testEmail, [
            'notifications' => 'mentions_only',
        ]);
        assert_true($result['success'], 'Update settings');

        $verify = $chatService->getConversationSettings($dmConvId, $testEmail);
        assert_equals('mentions_only', $verify['settings']['notifications'] ?? null, 'Setting persisted');
    });
}

// ========================================
// WEBSOCKET / REDIS PUB/SUB
// ========================================

if (shouldRun('ws')) {
    out("\n=== WebSocket / Redis pub/sub ===");

    $redis = null;
    test('RedisCacheService instantiates', function () use ($config, &$redis) {
        $redis = new \Webmail\Services\RedisCacheService($config);
        assert_true($redis !== null, 'Redis instance');
    });

    test('publishEvent sends CHAT_MESSAGE_NEW event', function () use ($redis, $testEmail) {
        if (!$redis) return 'warn';

        $published = $redis->publishEvent($testEmail, 'CHAT_MESSAGE_NEW', [
            'conversation_id' => 0,
            'message' => ['id' => 0, 'content' => 'Test WS event', 'test' => true],
        ]);
        assert_true($published, 'Publish event');
        vlog("Published CHAT_MESSAGE_NEW to $testEmail channel");
    });

    test('publishEvent sends CHAT_TYPING_START event', function () use ($redis, $testEmail) {
        if (!$redis) return 'warn';

        $published = $redis->publishEvent($testEmail, 'CHAT_TYPING_START', [
            'conversation_id' => 0,
            'colleague_id' => 0,
        ]);
        assert_true($published, 'Publish typing event');
        vlog("Published CHAT_TYPING_START");
    });

    test('publishEvent sends CALL_INITIATE event', function () use ($redis, $testEmail) {
        if (!$redis) return 'warn';

        $published = $redis->publishEvent($testEmail, 'CALL_INITIATE', [
            'call_id' => 'test-call-' . $GLOBALS['runId'],
            'conversation_id' => 0,
            'caller_id' => 0,
            'call_type' => 'video',
        ]);
        assert_true($published, 'Publish call event');
        vlog("Published CALL_INITIATE");
    });

    test('publishEvent sends CALL_SCREEN_SHARE_START event', function () use ($redis, $testEmail) {
        if (!$redis) return 'warn';

        $published = $redis->publishEvent($testEmail, 'CALL_SCREEN_SHARE_START', [
            'call_id' => 'test-call-' . $GLOBALS['runId'],
            'user_id' => 0,
        ]);
        assert_true($published, 'Publish screen share event');
        vlog("Published CALL_SCREEN_SHARE_START");
    });
}

// ========================================
// CHAT MUTE (notification suppression)
// ========================================

if (shouldRun('mute')) {
    out("\n=== Chat mute (notification suppression) ===");

    // A capturing stand-in for RedisCacheService so we can inspect the exact
    // per-recipient payloads broadcastToConversation() publishes, without
    // touching the live Redis bus. It subclasses RedisCacheService to satisfy
    // ChatService's typed $redis property; the empty constructor skips the real
    // connection (publishEvent only records into memory).
    $makeCapturingRedis = function () {
        return new class extends \Webmail\Services\RedisCacheService {
            public array $events = [];
            public function __construct() { /* no live connection for capture */ }
            public function publishEvent(string $userEmail, string $eventType, array $payload): bool {
                $this->events[] = ['email' => strtolower($userEmail), 'type' => $eventType, 'payload' => $payload];
                return true;
            }
        };
    };

    // Reflection handles for the private redis property and private broadcast method.
    $rcRedisProp = new \ReflectionProperty(\Webmail\Addons\Chat\Services\ChatService::class, 'redis');
    $rcRedisProp->setAccessible(true);
    $rcBroadcast = new \ReflectionMethod(\Webmail\Addons\Chat\Services\ChatService::class, 'broadcastToConversation');
    $rcBroadcast->setAccessible(true);
    $origRedis = $rcRedisProp->getValue($chatService);

    $muteConvId = null;

    test('Setup: create group for mute test', function () use ($chatService, $testEmail, $colleague2, &$muteConvId, &$cleanupConversationIds, $runId) {
        if (!$colleague2) return 'warn';
        $r = $chatService->createGroup($testEmail, [(int)$colleague2['id']], "Mute Test $runId", 'mute test');
        assert_true($r['success'], 'Create group: ' . ($r['error'] ?? ''));
        $muteConvId = (int)$r['conversation']['id'];
        $cleanupConversationIds[] = $muteConvId;
        vlog("Mute test conversation ID: $muteConvId");
    });

    test('toggleMute sets is_muted=1 for the muting user only', function () use ($chatService, $testEmail, $colleague2, &$muteConvId, &$db) {
        if (!$muteConvId || !$colleague2) return 'warn';
        $r = $chatService->toggleMute($muteConvId, $testEmail);
        assert_true($r['success'], 'Mute: ' . ($r['error'] ?? ''));

        $stmt = $db->prepare("
            SELECT LOWER(c.email) AS email, p.is_muted
            FROM chat_participants p
            JOIN organization_colleagues c ON p.colleague_id = c.id
            WHERE p.conversation_id = ?
        ");
        $stmt->execute([$muteConvId]);
        $byEmail = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $byEmail[$row['email']] = (int)$row['is_muted'];
        assert_equals(1, $byEmail[strtolower($testEmail)] ?? -1, 'Muting user is_muted');
        assert_equals(0, $byEmail[strtolower($colleague2['email'])] ?? -1, 'Other participant not muted');
        vlog('Participant mute state: ' . json_encode($byEmail));
    });

    test('New-message broadcast flags recipient_muted per recipient', function () use ($chatService, $testEmail, $colleague2, &$muteConvId, $makeCapturingRedis, $rcRedisProp, $rcBroadcast) {
        if (!$muteConvId || !$colleague2) return 'warn';
        $fake = $makeCapturingRedis();
        $rcRedisProp->setValue($chatService, $fake);
        $rcBroadcast->invoke($chatService, $muteConvId, \Webmail\Addons\Chat\Services\ChatService::EVENT_MESSAGE_NEW, [
            'conversation_id' => $muteConvId,
            'message' => ['id' => 0, 'content' => '[CHATTEST] mute payload', 'sender_email' => $testEmail],
        ]);

        $byEmail = [];
        foreach ($fake->events as $ev) {
            assert_equals(\Webmail\Addons\Chat\Services\ChatService::EVENT_MESSAGE_NEW, $ev['type'], 'Event type');
            $byEmail[$ev['email']] = $ev['payload'];
        }
        assert_true(isset($byEmail[strtolower($testEmail)]), 'Broadcast reached muting user (message still delivered)');
        assert_true(isset($byEmail[strtolower($colleague2['email'])]), 'Broadcast reached other participant');
        assert_true(($byEmail[strtolower($testEmail)]['recipient_muted'] ?? null) === true, 'Muted user payload recipient_muted=true');
        assert_true(($byEmail[strtolower($colleague2['email'])]['recipient_muted'] ?? null) === false, 'Other participant recipient_muted=false');
        vlog('Captured ' . count($fake->events) . ' message broadcasts with recipient_muted flags');
    });

    test('Non-message events do not carry recipient_muted', function () use ($chatService, &$muteConvId, $makeCapturingRedis, $rcRedisProp, $rcBroadcast) {
        if (!$muteConvId) return 'warn';
        $fake = $makeCapturingRedis();
        $rcRedisProp->setValue($chatService, $fake);
        $rcBroadcast->invoke($chatService, $muteConvId, \Webmail\Addons\Chat\Services\ChatService::EVENT_TYPING_START, [
            'conversation_id' => $muteConvId, 'colleague_id' => 0,
        ]);
        foreach ($fake->events as $ev) {
            assert_false(array_key_exists('recipient_muted', $ev['payload']), 'Typing event must not carry recipient_muted');
        }
    });

    test('Restore live Redis transport on ChatService', function () use ($chatService, $rcRedisProp, $origRedis) {
        $rcRedisProp->setValue($chatService, $origRedis);
        assert_true(true);
    });

    test('toggleMute unmutes (state cleanup)', function () use ($chatService, $testEmail, &$muteConvId) {
        if (!$muteConvId) return 'warn';
        $r = $chatService->toggleMute($muteConvId, $testEmail);
        assert_true($r['success'], 'Unmute');
    });
}

// ========================================
// BATCH MEMBER REMOVAL (F4)
// ========================================

out("\n=== Batch member removal (F4) ===");

test('ChatService has removeGroupMembersBatch', function () {
    $rc = new \ReflectionClass('\\Webmail\\Addons\\Chat\\Services\\ChatService');
    assert_true($rc->hasMethod('removeGroupMembersBatch'), 'removeGroupMembersBatch missing');
});

test('ChatController has removeGroupMembersBatch', function () {
    $rc = new \ReflectionClass('\\Webmail\\Addons\\Chat\\Controllers\\ChatController');
    assert_true($rc->hasMethod('removeGroupMembersBatch'), 'controller removeGroupMembersBatch missing');
});

test('Route registered: DELETE /chat/groups/{id}/members (batch body)', function () {
    $routes = file_get_contents(__DIR__ . '/../routes.php');
    // Look for batch route registration matching the new DELETE-with-body pattern.
    assert_true(
        preg_match('#delete\(.*?/chat/groups/\{[^}]+\}/members(?!/)#i', $routes) === 1,
        'DELETE /chat/groups/{id}/members batch route missing'
    );
});

// ========================================
// RESULTS
// ========================================

$elapsed = round((microtime(true) - $startTime) * 1000);

out("\n=============================================================");
out("  RESULTS");
out("=============================================================");
out("  Total:    $totalTests");
out("  Passed:   $passed");
if ($failed > 0)   out("  Failed:   $failed");
if ($warnings > 0) out("  Warnings: $warnings");
out("  Duration: {$elapsed}ms");
out("  Log:      $logFile");

if ($failed > 0) {
    out("\n  FAILURES:");
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            out("    - {$r['name']}: {$r['error']}");
        }
    }
}
out("=============================================================");
