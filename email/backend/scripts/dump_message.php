<?php
/**
 * Dump DB rows and IMAP message for a given UID to help diagnose encoding issues.
 * Usage: php backend/scripts/dump_message.php <uid> [folder]
 */

if ($argc < 2) {
    echo "Usage: php backend/scripts/dump_message.php <uid> [folder]\n";
    exit(1);
}

$uid = (int)$argv[1];
$folder = $argv[2] ?? 'INBOX';

$configPath = __DIR__ . '/../src/config.php';
if (!file_exists($configPath)) {
    echo "Config not found at {$configPath}\n";
    exit(1);
}
$config = require $configPath;

// Print DB rows
echo "=== DB rows for UID {$uid} ===\n";
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $stmt = $pdo->prepare("SELECT * FROM webmail_conversation_members WHERE uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        foreach ($row as $k => $v) {
            echo "{$k}: " . (is_null($v) ? 'NULL' : $v) . "\n";
        }
    } else {
        echo "No row in webmail_conversation_members for uid {$uid}\n";
    }

    if ($row) {
        $convId = $row['conversation_id'];
        $stmt2 = $pdo->prepare("SELECT * FROM webmail_conversations WHERE conversation_id = ? AND user_email = ? LIMIT 1");
        $stmt2->execute([$convId, $row['user_email']]);
        $crow = $stmt2->fetch(PDO::FETCH_ASSOC);
        echo "\n=== Conversation row ===\n";
        if ($crow) {
            foreach ($crow as $k => $v) {
                echo "{$k}: " . (is_null($v) ? 'NULL' : $v) . "\n";
            }
        } else {
            echo "No conversation row found for conversation_id {$convId}\n";
        }
    }
} catch (Exception $e) {
    echo "DB error: " . $e->getMessage() . "\n";
}

// Try to fetch IMAP parsed message using ImapService
echo "\n=== IMAP fetch for UID {$uid} in folder {$folder} ===\n";
require_once __DIR__ . '/../vendor/autoload.php';
try {
    $imap = new Webmail\Services\ImapService($config);
    $msg = $imap->getMessage($folder, $uid);
    if (!$msg) {
        echo "getMessage returned null\n";
    } else {
        echo "subject: " . ($msg['subject'] ?? '(null)') . "\n";
        echo "from_name: " . ($msg['from_name'] ?? '') . "\n";
        echo "snippet: " . ($msg['snippet'] ?? '(null)') . "\n";
        echo "body_preview (first 300 chars):\n";
        $preview = $msg['body_preview'] ?? ($msg['snippet'] ?? '');
        echo mb_substr($preview, 0, 300) . "\n";
    }

    // Raw source if available
    if (method_exists($imap, 'getRawMessage')) {
        $raw = $imap->getRawMessage($uid);
        echo "\n=== Raw message (first 2000 chars) ===\n";
        if ($raw) {
            echo mb_substr($raw, 0, 2000) . "\n";
        } else {
            echo "(no raw message returned)\n";
        }
    }
} catch (Exception $e) {
    echo "IMAP error: " . $e->getMessage() . "\n";
}


