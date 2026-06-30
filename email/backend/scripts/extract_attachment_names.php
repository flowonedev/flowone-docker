<?php
/**
 * Extract actual attachment filenames for emails already flagged with has_attachment
 * 
 * Usage: php extract_attachment_names.php <user_email> <jwt_token>
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/config.php';

if ($argc < 3) {
    echo "Usage: php extract_attachment_names.php <user_email> <jwt_token>\n";
    exit(1);
}

$userEmail = $argv[1];
$token = $argv[2];
$apiBase = 'https://email.devcon1.hu/api';

echo "=== Extract Attachment Filenames ===\n";
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
function callApi(string $endpoint, string $token): ?array {
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
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    return $data['data'] ?? $data;
}

// Test API
echo "Testing API...\n";
$test = callApi('/mailbox/folders', $token);
if (!$test) {
    echo "API connection failed. Get a fresh token.\n";
    exit(1);
}
echo "API connected.\n\n";

// Get messages that have has_attachment=1 but no entry in webmail_email_attachments
$stmt = $db->prepare("
    SELECT m.folder, m.uid, m.subject, m.from_email, m.from_name, m.message_date
    FROM webmail_conversation_members m
    WHERE m.user_email = ?
      AND m.has_attachment = 1
      AND NOT EXISTS (
          SELECT 1 FROM webmail_email_attachments a 
          WHERE a.user_email = m.user_email 
            AND a.folder = m.folder 
            AND a.uid = m.uid
      )
    ORDER BY m.message_date DESC
    LIMIT 500
");
$stmt->execute([$userEmail]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($messages) . " messages with attachments to process.\n\n";

$totalAttachments = 0;
$processed = 0;

foreach ($messages as $msg) {
    $processed++;
    echo "[$processed/" . count($messages) . "] {$msg['folder']} UID:{$msg['uid']} - ";
    
    // Skip trash/deleted/spam
    $folderLower = strtolower($msg['folder']);
    if (strpos($folderLower, 'trash') !== false || 
        strpos($folderLower, 'deleted') !== false ||
        strpos($folderLower, 'spam') !== false ||
        strpos($folderLower, 'junk') !== false) {
        echo "SKIP (trash/spam)\n";
        continue;
    }
    
    // Get message details from API
    $encodedFolder = urlencode($msg['folder']);
    $msgData = callApi("/mailbox/{$encodedFolder}/messages/{$msg['uid']}", $token);
    
    if (!$msgData || empty($msgData['attachments'])) {
        echo "No attachments found\n";
        continue;
    }
    
    $attCount = count($msgData['attachments']);
    echo "$attCount attachment(s): ";
    
    foreach ($msgData['attachments'] as $att) {
        $filename = $att['filename'] ?? 'Unknown';
        $mimeType = $att['type'] ?? 'application/octet-stream';
        $size = $att['size'] ?? 0;
        
        echo "$filename, ";
        
        try {
            $insertAtt = $db->prepare("
                INSERT INTO webmail_email_attachments 
                (user_email, folder, uid, filename, mime_type, size, from_email, from_name, subject, message_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    mime_type = VALUES(mime_type),
                    size = VALUES(size)
            ");
            $insertAtt->execute([
                $userEmail,
                $msg['folder'],
                $msg['uid'],
                $filename,
                $mimeType,
                $size,
                $msg['from_email'] ?? '',
                $msg['from_name'] ?? '',
                $msg['subject'] ?? '',
                $msg['message_date'] ?? null,
            ]);
            $totalAttachments++;
        } catch (PDOException $e) {
            echo "(DB error) ";
        }
    }
    echo "\n";
    
    // Small delay
    usleep(100000);
}

echo "\n=== Summary ===\n";
echo "Messages processed: $processed\n";
echo "Attachments stored: $totalAttachments\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

