#!/usr/bin/env php
<?php
/**
 * Scheduled Chat Message Processor
 * 
 * Sends chat messages that were scheduled for a future time.
 * Checks for pending messages whose scheduled_at has passed and sends them.
 * 
 * Run via cron every minute:
 *   * * * * * php /var/www/vps-email/backend/cron/process-scheduled-chat.php
 * 
 * Options:
 *   --verbose     Show detailed progress
 *   --dry-run     Show what would be sent without actually sending
 *   --help        Show this help message
 */

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Load autoloader
require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\Chat\Services\ChatService;

// Parse command line arguments
$options = getopt('', ['verbose', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Scheduled Chat Message Processor\n";
    echo "=================================\n\n";
    echo "Sends chat messages that were scheduled for a future time.\n\n";
    echo "Usage: php process-scheduled-chat.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose     Show detailed progress\n";
    echo "  --dry-run     Show pending messages without sending\n";
    echo "  --help        Show this help message\n\n";
    echo "Cron setup (every minute):\n";
    echo "  * * * * * php /var/www/vps-email/backend/cron/process-scheduled-chat.php\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

// Load config
$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    die("Config file not found: {$configFile}\n");
}
$config = require $configFile;

$timestamp = date('Y-m-d H:i:s');

if ($verbose) {
    echo "[{$timestamp}] Scheduled Chat Processor starting...\n";
}

try {
    $chatService = new ChatService($config);

    if ($dryRun) {
        // Just show pending messages
        $dbConfig = $config['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? 3306,
            $dbConfig['name'] ?? 'webmail'
        );

        $db = new PDO($dsn, $dbConfig['user'] ?? 'root', $dbConfig['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare('
            SELECT sm.id, sm.conversation_id, sm.content, sm.scheduled_at, oc.email
            FROM chat_scheduled_messages sm
            JOIN organization_colleagues oc ON sm.colleague_id = oc.id
            WHERE sm.status = \'pending\' AND sm.scheduled_at <= ?
            ORDER BY sm.scheduled_at ASC
        ');
        $stmt->execute([$now]);
        $pending = $stmt->fetchAll();

        echo "[{$timestamp}] DRY RUN - Found " . count($pending) . " pending scheduled messages\n";
        foreach ($pending as $msg) {
            echo "  #{$msg['id']}: conv={$msg['conversation_id']} from={$msg['email']} at={$msg['scheduled_at']}\n";
            echo "    Content: " . substr($msg['content'], 0, 80) . (strlen($msg['content']) > 80 ? '...' : '') . "\n";
        }
        exit(0);
    }

    // Process scheduled messages
    $result = $chatService->processScheduledMessages();

    if ($verbose || $result['sent'] > 0 || $result['failed'] > 0) {
        echo "[{$timestamp}] Processed: {$result['total_pending']} pending, {$result['sent']} sent, {$result['failed']} failed\n";

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                echo "  ERROR: {$error}\n";
            }
        }
    }
} catch (\Exception $e) {
    $msg = "[{$timestamp}] FATAL: " . $e->getMessage();
    error_log("process-scheduled-chat: " . $e->getMessage());
    echo $msg . "\n";
    exit(1);
}

