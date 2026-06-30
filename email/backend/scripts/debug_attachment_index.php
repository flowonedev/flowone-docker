<?php
/**
 * Debug attachment indexing
 */

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/config.php';

$userEmail = $argv[1] ?? 'robert@pixelranger.hu';

echo "=== Debug Attachment Indexing ===\n";
echo "User: $userEmail\n\n";

// Connect to DB
$dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4";
$db = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// 1. Check if webmail_email_attachments exists and has data
echo "1. Checking webmail_email_attachments table...\n";
try {
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM webmail_email_attachments WHERE user_email = ?");
    $stmt->execute([$userEmail]);
    $result = $stmt->fetch();
    echo "   Rows in webmail_email_attachments: " . $result['cnt'] . "\n";
} catch (PDOException $e) {
    echo "   Table doesn't exist or error: " . $e->getMessage() . "\n";
}

// 2. Check conversation_members with has_attachment
echo "\n2. Checking conversation_members with has_attachment...\n";
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt
    FROM webmail_conversation_members m
    WHERE m.user_email = ? AND m.has_attachment = 1
");
$stmt->execute([$userEmail]);
$result = $stmt->fetch();
echo "   Members with has_attachment=1: " . $result['cnt'] . "\n";

// 3. Check conversations with has_attachment
echo "\n3. Checking conversations with has_attachment...\n";
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt
    FROM webmail_conversations c
    WHERE c.user_email = ? AND c.has_attachment = 1
");
$stmt->execute([$userEmail]);
$result = $stmt->fetch();
echo "   Conversations with has_attachment=1: " . $result['cnt'] . "\n";

// 4. Run the actual query from the indexer
echo "\n4. Running indexer query (first 5 results)...\n";
$skipFolders = ['trash', 'deleted items', 'deleted', 'spam', 'junk', 'drafts'];

$stmt = $db->prepare("
    SELECT
        m.uid,
        fi.current_path AS folder,
        m.subject,
        m.from_email,
        m.from_name,
        m.message_date as date,
        COALESCE(m.has_attachment, c.has_attachment, 0) as has_attachment
    FROM webmail_conversation_members m
    LEFT JOIN webmail_conversations c
        ON c.user_email = m.user_email
        AND c.conversation_id = m.conversation_id
    LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
    WHERE m.user_email = ?
        AND (m.has_attachment = 1 OR c.has_attachment = 1)
    ORDER BY m.message_date DESC
    LIMIT 10
");
$stmt->execute([$userEmail]);
$emails = $stmt->fetchAll();

echo "   Found " . count($emails) . " emails\n\n";

$indexed = 0;
$skipped = 0;
foreach ($emails as $i => $email) {
    $folderLower = strtolower($email['folder'] ?? '');
    $shouldSkip = false;
    foreach ($skipFolders as $skip) {
        if (strpos($folderLower, $skip) !== false) {
            $shouldSkip = true;
            break;
        }
    }
    
    echo "   [$i] UID: {$email['uid']}, Folder: {$email['folder']}, Subject: " . substr($email['subject'] ?? '', 0, 40) . "\n";
    echo "       From: {$email['from_email']}, has_attachment: {$email['has_attachment']}\n";
    echo "       Skip: " . ($shouldSkip ? "YES (folder filter)" : "NO") . "\n";
    
    if ($shouldSkip) {
        $skipped++;
    } else {
        $indexed++;
    }
}

echo "\n   Would index: $indexed, Would skip: $skipped\n";

// 5. Try to actually index one
echo "\n5. Attempting to index one attachment entry...\n";
if (!empty($emails)) {
    $email = $emails[0];
    $sourceId = ($email['folder'] ?? 'INBOX') . ':' . ($email['uid'] ?? '0') . ':' . md5('Attachment(s)');
    
    try {
        $stmt = $db->prepare("
            INSERT INTO universal_search_index 
            (user_email, source_type, source_id, title, content_text, content_snippet, folder_name, source_date)
            VALUES (?, 'email_attachment', ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title)
        ");
        $stmt->execute([
            strtolower($userEmail),
            $sourceId,
            $email['subject'] ?? 'Test',
            "test content attachment",
            "Test snippet",
            $email['folder'],
            $email['date'],
        ]);
        echo "   SUCCESS! Inserted/updated attachment entry.\n";
    } catch (PDOException $e) {
        echo "   FAILED: " . $e->getMessage() . "\n";
    }
}

// 6. Check what's in universal_search_index for attachments
echo "\n6. Checking universal_search_index for email_attachment type...\n";
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM universal_search_index 
    WHERE user_email = ? AND source_type = 'email_attachment'
");
$stmt->execute([$userEmail]);
$result = $stmt->fetch();
echo "   email_attachment entries: " . $result['cnt'] . "\n";

echo "\nDone.\n";

