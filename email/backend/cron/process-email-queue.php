#!/usr/bin/env php
<?php
/**
 * Email Queue Processor
 * 
 * Background worker that processes the email queue respecting rate limits:
 * - 100 emails per hour per user
 * - 500 emails per day per user
 * 
 * Run via cron every minute:
 *   * * * * * php /var/www/vps-email/backend/cron/process-email-queue.php
 * 
 * Options:
 *   --batch=N     Process N emails per run (default: 10)
 *   --dry-run     Show what would be processed without sending
 *   --verbose     Show detailed progress
 *   --help        Show this help message
 * 
 * The worker will:
 * 1. Find campaigns with pending emails
 * 2. Check rate limits per user
 * 3. Send emails up to the batch limit
 * 4. Update campaign progress
 * 5. Broadcast real-time updates via Redis/WebSocket
 */

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Load autoloader
require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\EmailMarketing\Services\EmailQueueService;

// Parse command line arguments
$options = getopt('', ['batch:', 'dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Email Queue Processor\n";
    echo "=====================\n\n";
    echo "Processes queued emails respecting rate limits (100/hour, 500/day per user).\n\n";
    echo "Usage: php process-email-queue.php [options]\n\n";
    echo "Options:\n";
    echo "  --batch=N     Process N emails per run (default: 10)\n";
    echo "  --dry-run     Show what would be processed without sending\n";
    echo "  --verbose     Show detailed progress\n";
    echo "  --help        Show this help message\n\n";
    echo "Cron setup (every minute):\n";
    echo "  * * * * * php /var/www/vps-email/backend/cron/process-email-queue.php\n\n";
    exit(0);
}

$batchSize = (int)($options['batch'] ?? 10);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Load config
$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    die("Config file not found: {$configFile}\n");
}
$config = require $configFile;

// Timestamp for logging
$timestamp = date('Y-m-d H:i:s');

if ($verbose) {
    echo "[{$timestamp}] Email Queue Processor starting...\n";
    echo "  Batch size: {$batchSize}\n";
    echo "  Dry run: " . ($dryRun ? 'Yes' : 'No') . "\n\n";
}

try {
    $queueService = new EmailQueueService($config);
    
    if ($dryRun) {
        // Dry run - just show what would be processed
        echo "[{$timestamp}] DRY RUN - No emails will be sent\n\n";
        
        // Connect to database to check queue
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
        
        // Get pending campaigns
        $stmt = $db->prepare("
            SELECT c.campaign_id, c.user_email, c.subject, c.total_recipients, c.sent_count, c.status,
                   COUNT(q.id) as pending_count
            FROM email_campaigns c
            LEFT JOIN email_queue q ON q.campaign_id = c.campaign_id AND q.status IN ('pending', 'rate_limited')
            WHERE c.status IN ('pending', 'processing')
            GROUP BY c.campaign_id
            ORDER BY c.created_at ASC
        ");
        $stmt->execute();
        $campaigns = $stmt->fetchAll();
        
        if (empty($campaigns)) {
            echo "No campaigns to process.\n";
        } else {
            echo "Campaigns to process:\n";
            echo str_repeat('-', 80) . "\n";
            
            foreach ($campaigns as $campaign) {
                $limits = $queueService->checkRateLimits($campaign['user_email']);
                
                echo sprintf(
                    "Campaign: %s\n  User: %s\n  Subject: %s\n  Progress: %d/%d sent\n  Pending: %d\n  Rate limits: %d hourly, %d daily available\n  Can send now: %d\n\n",
                    $campaign['campaign_id'],
                    $campaign['user_email'],
                    substr($campaign['subject'], 0, 50) . (strlen($campaign['subject']) > 50 ? '...' : ''),
                    $campaign['sent_count'],
                    $campaign['total_recipients'],
                    $campaign['pending_count'],
                    $limits['hourly_available'],
                    $limits['daily_available'],
                    min($limits['can_send'], $batchSize)
                );
            }
        }
        
        exit(0);
    }
    
    // Process the queue
    $result = $queueService->processQueue($batchSize);
    
    if ($verbose) {
        echo "[{$timestamp}] Processing complete:\n";
        echo "  Processed: {$result['processed']}\n";
        echo "  Sent: {$result['sent']}\n";
        echo "  Failed: {$result['failed']}\n";
        echo "  Rate limited: {$result['rate_limited']}\n";
    }
    
    // Log to file
    $logFile = __DIR__ . '/../storage/logs/email-queue.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = sprintf(
        "[%s] processed=%d sent=%d failed=%d rate_limited=%d\n",
        $timestamp,
        $result['processed'],
        $result['sent'],
        $result['failed'],
        $result['rate_limited']
    );
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // Exit with appropriate code
    if ($result['failed'] > 0 && $result['sent'] === 0) {
        exit(1); // All failed
    }
    
    exit(0);
    
} catch (Exception $e) {
    $error = "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    
    if ($verbose) {
        echo $error;
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    
    // Log error
    $logFile = __DIR__ . '/../storage/logs/email-queue.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, $error, FILE_APPEND);
    
    exit(1);
}

