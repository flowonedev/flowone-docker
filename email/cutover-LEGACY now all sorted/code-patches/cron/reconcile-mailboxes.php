<?php
/**
 * Mailbox Reconciliation Cron Job
 * 
 * Run this script hourly via cron:
 *   0 * * * * php /var/www/vps-email/backend/cron/reconcile-mailboxes.php >> /var/log/mailbox-reconcile.log 2>&1
 * 
 * Purpose:
 *   - Detects orphan conversation_members records (UIDs that no longer exist in IMAP)
 *   - Handles changes made by external clients (Thunderbird, mobile apps, server rules)
 *   - Updates last_verified_at timestamp for tracking data confidence
 *   - Cleans up stale data to prevent ghost emails
 * 
 * Process:
 *   1. Get users with recent activity (avoid scanning inactive accounts)
 *   2. For each user's indexed folders:
 *      a. IMAP SEARCH ALL to get current UIDs
 *      b. Compare with database UIDs
 *      c. Delete orphan records (UIDs not in IMAP)
 *      d. Update last_verified_at for valid records
 */

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\ImapService;
use Webmail\Services\RedisCacheService;

// Load config
$config = require __DIR__ . '/../src/config.php';

// Database connection
$db = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Redis cache
$cache = new RedisCacheService($config);

echo "[" . date('Y-m-d H:i:s') . "] Starting mailbox reconciliation...\n";

// Get users with indexed folders AND active sessions (have cached credentials)
$stmt = $db->prepare("
    SELECT DISTINCT fi.user_email 
    FROM webmail_folder_index fi
    WHERE fi.is_indexed = 1 
    AND fi.updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($users) . " users with indexed folders\n";

$totalOrphansDeleted = 0;
$totalVerified = 0;
$usersProcessed = 0;
$errors = [];

foreach ($users as $userEmail) {
    echo "\n[User: {$userEmail}]\n";
    
    try {
        // Try to get cached session with IMAP credentials from Redis
        $sessionKey = "webmail:session:{$userEmail}";
        $sessionJson = null;
        
        // Try Redis raw get
        try {
            $redis = new \Redis();
            $redis->connect($config['redis']['host'], $config['redis']['port']);
            if (!empty($config['redis']['password'])) {
                $redis->auth($config['redis']['password']);
            }
            $redis->select($config['redis']['database'] ?? 0);
            $sessionJson = $redis->get($sessionKey);
            $redis->close();
        } catch (\Exception $e) {
            echo "  - Redis error: " . $e->getMessage() . "\n";
            continue;
        }
        
        if (!$sessionJson) {
            echo "  - Skipped: No active session in Redis\n";
            continue;
        }
        
        $sessionData = json_decode($sessionJson, true);
        
        if (!$sessionData || empty($sessionData['imap_password'])) {
            echo "  - Skipped: No IMAP credentials in session\n";
            continue;
        }
        
        // Connect to IMAP
        $imapConfig = [
            'host' => $sessionData['imap_host'] ?? $config['imap']['host'] ?? 'localhost',
            'port' => $sessionData['imap_port'] ?? $config['imap']['port'] ?? 993,
            'encryption' => $sessionData['imap_encryption'] ?? $config['imap']['encryption'] ?? 'ssl',
        ];
        
        $imap = new ImapService($imapConfig);
        
        if (!$imap->connect($userEmail, $sessionData['imap_password'])) {
            echo "  - Skipped: IMAP connection failed\n";
            continue;
        }
        
        $usersProcessed++;
        
        // Get indexed folders for this user, joined to identity so we
        // can scope the conversation-member queries by stable folder_id.
        // Folders without an identity row are skipped: the
        // FolderIndexService is the writer for both tables and no
        // production code path leaves an indexed folder unidentified.
        $stmt = $db->prepare("
            SELECT idx.folder, fi.id AS folder_id
            FROM webmail_folder_index idx
            JOIN webmail_folder_identity fi
              ON fi.account_id = idx.user_email AND fi.current_path = idx.folder
            WHERE idx.user_email = ? AND idx.is_indexed = 1
        ");
        $stmt->execute([$userEmail]);
        $folders = $stmt->fetchAll();

        echo "  - Reconciling " . count($folders) . " folders\n";

        foreach ($folders as $row) {
            $folder   = $row['folder'];
            $folderId = $row['folder_id'];
            try {
                // Get all UIDs from IMAP (SEARCH ALL)
                $imapUids = $imap->searchAllUids($folder);

                if ($imapUids === false) {
                    echo "    [{$folder}] SEARCH failed, skipping\n";
                    continue;
                }

                $imapUidSet = array_flip($imapUids); // For O(1) lookup

                // Get UIDs from database via the canonical folder_id.
                $stmt = $db->prepare("
                    SELECT id, uid FROM webmail_conversation_members
                    WHERE user_email = ? AND folder_id = ?
                ");
                $stmt->execute([$userEmail, $folderId]);
                $dbRecords = $stmt->fetchAll();
                
                $orphanIds = [];
                $validIds = [];
                
                foreach ($dbRecords as $record) {
                    if ($record['uid'] <= 0 || !isset($imapUidSet[$record['uid']])) {
                        // UID doesn't exist in IMAP or is invalid - orphan!
                        $orphanIds[] = $record['id'];
                    } else {
                        $validIds[] = $record['id'];
                    }
                }
                
                // Delete orphans
                if (!empty($orphanIds)) {
                    $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
                    $stmt = $db->prepare("DELETE FROM webmail_conversation_members WHERE id IN ({$placeholders})");
                    $stmt->execute($orphanIds);
                    $deleted = $stmt->rowCount();
                    $totalOrphansDeleted += $deleted;
                    echo "    [{$folder}] Deleted {$deleted} orphan records\n";
                    
                    // Invalidate Redis cache for this folder
                    $cache->invalidateFolder($userEmail, $folder);
                    $cache->invalidateConversations($userEmail, $folder);
                }
                
                // Update last_verified_at for valid records
                if (!empty($validIds)) {
                    // Batch update in chunks to avoid huge queries
                    $chunks = array_chunk($validIds, 500);
                    foreach ($chunks as $chunk) {
                        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                        $stmt = $db->prepare("
                            UPDATE webmail_conversation_members 
                            SET last_verified_at = NOW() 
                            WHERE id IN ({$placeholders})
                        ");
                        $stmt->execute($chunk);
                        $totalVerified += $stmt->rowCount();
                    }
                }
                
            } catch (\Exception $e) {
                $errors[] = "[{$userEmail}/{$folder}] " . $e->getMessage();
                echo "    [{$folder}] Error: " . $e->getMessage() . "\n";
            }
        }
        
        // Close IMAP connection
        $imap->disconnect();
        
    } catch (\Exception $e) {
        $errors[] = "[{$userEmail}] " . $e->getMessage();
        echo "  - Error: " . $e->getMessage() . "\n";
    }
}

// Also clean up orphan conversations (where all members are deleted)
echo "\n[Cleanup] Removing orphan conversations...\n";
try {
    $stmt = $db->prepare("
        DELETE c FROM webmail_conversations c
        LEFT JOIN webmail_conversation_members m 
            ON c.user_email = m.user_email AND c.conversation_id = m.conversation_id
        WHERE m.id IS NULL
    ");
    $stmt->execute();
    $orphanConversations = $stmt->rowCount();
    echo "Deleted {$orphanConversations} orphan conversation records\n";
} catch (\Exception $e) {
    echo "Error cleaning orphan conversations: " . $e->getMessage() . "\n";
}

// Summary
echo "\n========================================\n";
echo "Reconciliation complete!\n";
echo "  - Users processed: {$usersProcessed}\n";
echo "  - Orphans deleted: {$totalOrphansDeleted}\n";
echo "  - Records verified: {$totalVerified}\n";
echo "  - Errors: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
