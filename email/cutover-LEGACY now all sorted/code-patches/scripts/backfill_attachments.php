<?php
/**
 * Backfill has_attachment flag for existing emails
 * 
 * Usage: php backfill_attachments.php <user_email> <jwt_token>
 * 
 * This script uses the webmail API to check each message for attachments,
 * then updates the webmail_conversation_members and webmail_conversations tables.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/config.php';

if ($argc < 3) {
    echo "Usage: php backfill_attachments.php <user_email> <jwt_token>\n";
    echo "Example: php backfill_attachments.php robert@pixelranger.hu 'eyJ...'\n";
    echo "\nGet your JWT token from browser dev tools (Authorization header)\n";
    exit(1);
}

$userEmail = $argv[1];
$token = $argv[2];
$apiBase = 'https://email.devcon1.hu/api';

echo "=== Attachment Backfill Script ===\n";
echo "User: $userEmail\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Connect to database
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db']['host'] ?? '127.0.0.1',
        $config['db']['name'] ?? 'devc_vps_dash'
    );
    $db = new PDO($dsn, $config['db']['user'] ?? 'vpsadmin', $config['db']['pass'] ?? '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected.\n";
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Helper function to call API
function callApi(string $endpoint, string $token, bool $debug = false): ?array {
    global $apiBase;
    
    $url = $apiBase . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($debug) {
        echo "  URL: $url\n";
        echo "  HTTP Code: $httpCode\n";
        if ($error) echo "  cURL Error: $error\n";
        if ($response) echo "  Response: " . substr($response, 0, 200) . "\n";
    }
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['data'] ?? $data;
}

// Test API connection
echo "Testing API connection...\n";
$test = callApi('/mailbox/folders', $token, true);
if (!$test) {
    echo "\nAPI connection failed. You may need a fresh token.\n";
    echo "Get one from browser: F12 -> Network -> any API call -> Headers -> Authorization\n";
    exit(1);
}
echo "API connected.\n\n";

// Get list of (folder_id, folder path) pairs by joining the conversation
// table to the identity table. Post-cutover the conversation table is
// keyed by stable folder_id; we still need the path to call the canonical
// /folders/{folder_id}/... API and to log which folder we are scanning.
$stmt = $db->prepare("
    SELECT DISTINCT m.folder_id, fi.current_path AS folder
    FROM webmail_conversation_members m
    LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
    WHERE m.user_email = ?
");
$stmt->execute([$userEmail]);
$folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($folders) . " folders to process.\n\n";

$totalUpdated = 0;
$totalWithAttachments = 0;

foreach ($folders as $folderRow) {
    $folderId   = $folderRow['folder_id'];
    $folderName = $folderRow['folder'];
    if (empty($folderName)) {
        echo "Skipping orphaned folder_id (no identity row)\n";
        continue;
    }

    // Skip trash/deleted folders
    if (stripos($folderName, 'trash') !== false ||
        stripos($folderName, 'deleted') !== false ||
        stripos($folderName, 'spam') !== false ||
        stripos($folderName, 'junk') !== false) {
        echo "Skipping folder: $folderName\n";
        continue;
    }

    echo "Processing folder: $folderName\n";

    // Get UIDs from database for this folder
    $stmt = $db->prepare("
        SELECT uid FROM webmail_conversation_members
        WHERE user_email = ? AND folder_id = ? AND has_attachment = 0
    ");
    $stmt->execute([$userEmail, $folderId]);
    $uids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($uids)) {
        echo "  No messages to check.\n";
        continue;
    }

    echo "  Checking " . count($uids) . " messages...\n";

    $folderUpdated = 0;
    $folderAttachments = 0;
    $processed = 0;

    foreach ($uids as $uid) {
        $processed++;
        if ($processed % 5 == 0) {
            echo "  Progress: $processed/" . count($uids) . " ($folderAttachments with attachments)    \r";
        }

        // Canonical folder-id-shaped API.
        $msg = callApi("/folders/{$folderId}/messages/{$uid}", $token);

        if ($msg && !empty($msg['has_attachment'])) {
            $folderAttachments++;

            // Update webmail_conversation_members (canonical: folder_id)
            $updateStmt = $db->prepare("
                UPDATE webmail_conversation_members
                SET has_attachment = 1
                WHERE user_email = ? AND folder_id = ? AND uid = ?
            ");
            $updateStmt->execute([$userEmail, $folderId, $uid]);

            if ($updateStmt->rowCount() > 0) {
                $folderUpdated++;
            }

            // Store actual attachment details in webmail_email_attachments table.
            // The attachments cache keeps its own `folder` string column so we
            // continue to write the path here (this table is unaffected by the
            // canonical-identity cutover).
            if (!empty($msg['attachments']) && is_array($msg['attachments'])) {
                foreach ($msg['attachments'] as $att) {
                    $filename = $att['filename'] ?? 'Unknown';
                    $mimeType = $att['type'] ?? 'application/octet-stream';
                    $size = $att['size'] ?? 0;
                    $part = $att['part'] ?? null;  // MIME part identifier for fetching

                    try {
                        $insertAtt = $db->prepare("
                            INSERT INTO webmail_email_attachments
                            (user_email, folder, uid, filename, part, mime_type, size, from_email, from_name, subject, message_date)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                part = VALUES(part),
                                mime_type = VALUES(mime_type),
                                size = VALUES(size)
                        ");
                        $insertAtt->execute([
                            $userEmail,
                            $folderName,
                            $uid,
                            $filename,
                            $part,
                            $mimeType,
                            $size,
                            $msg['from'][0]['email'] ?? $msg['from_email'] ?? '',
                            $msg['from'][0]['name'] ?? $msg['from_name'] ?? '',
                            $msg['subject'] ?? '',
                            $msg['date'] ?? null,
                        ]);
                    } catch (PDOException $e) {
                        // Ignore duplicate errors
                    }
                }
            }
        }

        // Small delay to not hammer the API
        usleep(100000); // 100ms
    }

    echo "  Messages with attachments: $folderAttachments\n";
    echo "  Records updated: $folderUpdated\n";

    $totalUpdated += $folderUpdated;
    $totalWithAttachments += $folderAttachments;
}

// Now update webmail_conversations based on members
echo "\nUpdating conversations...\n";

$stmt = $db->prepare("
    UPDATE webmail_conversations c
    SET has_attachment = 1
    WHERE c.user_email = ?
    AND EXISTS (
        SELECT 1 FROM webmail_conversation_members m
        WHERE m.user_email = c.user_email
        AND m.conversation_id = c.conversation_id
        AND m.has_attachment = 1
    )
");
$stmt->execute([$userEmail]);
$conversationsUpdated = $stmt->rowCount();

echo "Conversations updated: $conversationsUpdated\n";

echo "\n=== Summary ===\n";
echo "Total messages with attachments: $totalWithAttachments\n";
echo "Total member records updated: $totalUpdated\n";
echo "Total conversations updated: $conversationsUpdated\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

