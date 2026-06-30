<?php
/**
 * Billing Reminders Command
 * 
 * Sends automated payment reminder emails to clients.
 * Should be run daily via cron job.
 * 
 * Usage:
 *   php BillingReminders.php
 *   php BillingReminders.php --dry-run
 *   php BillingReminders.php --admin-summary-only
 * 
 * Cron example (run daily at 9am):
 *   0 9 * * * php /opt/vps-admin/api/src/Commands/BillingReminders.php >> /var/log/vps-admin/billing-reminders.log 2>&1
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use VpsAdmin\Api\Core\Container;
use VpsAdmin\Api\Services\EmailService;

// Parse arguments
$options = getopt('', ['dry-run', 'admin-summary-only', 'help']);

if (isset($options['help'])) {
    echo "Billing Reminders - Send payment reminder emails\n\n";
    echo "Usage: php BillingReminders.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run            Show what would be sent without actually sending\n";
    echo "  --admin-summary-only Only send admin summary, skip client reminders\n";
    echo "  --help               Show this help\n\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$adminOnly = isset($options['admin-summary-only']);

echo "===========================================\n";
echo "  Billing Reminders\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
if ($dryRun) echo "  [DRY RUN MODE]\n";
echo "===========================================\n\n";

try {
    // Initialize container
    $configPath = __DIR__ . '/../../config.php';
    if (!file_exists($configPath)) {
        throw new Exception("Config file not found: {$configPath}");
    }
    
    $config = require $configPath;
    
    // Load local config if exists
    $localConfig = __DIR__ . '/../../config.local.php';
    if (file_exists($localConfig)) {
        $config = array_merge($config, require $localConfig);
    }
    
    $container = new Container($config);
    $db = $container->getDatabase();
    $emailService = new EmailService($container);
    
    // Get billing settings
    $stmt = $db->query("SELECT setting_value FROM billing_settings WHERE setting_key = 'reminder_days'");
    $reminderDays = (int)($stmt->fetchColumn() ?: 30);
    
    $stmt = $db->query("SELECT setting_value FROM billing_settings WHERE setting_key = 'admin_email'");
    $adminEmail = $stmt->fetchColumn();
    
    echo "Settings:\n";
    echo "  - Reminder days: {$reminderDays}\n";
    echo "  - Admin email: " . ($adminEmail ?: '(not set)') . "\n\n";
    
    // ==========================================
    // Get subscriptions due in X days (for reminders)
    // ==========================================
    
    if (!$adminOnly) {
        echo "Checking for subscriptions due in {$reminderDays} days...\n";
        
        $stmt = $db->prepare("
            SELECT cs.*, c.name as client_name, c.email as client_email
            FROM hosting_subscriptions cs
            JOIN hosting_clients c ON cs.client_id = c.id
            WHERE cs.status = 'active'
            AND cs.next_due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$reminderDays]);
        $dueInXDays = $stmt->fetchAll();
        
        echo "  Found: " . count($dueInXDays) . " subscription(s)\n";
        
        foreach ($dueInXDays as $sub) {
            echo "  - {$sub['client_name']} ({$sub['client_email']}): {$sub['plan_name']} - {$sub['amount']} {$sub['currency']}\n";
            
            if (!$dryRun) {
                $sent = $emailService->sendPaymentReminder($sub, 'reminder_30');
                echo "    -> " . ($sent ? "Email sent" : "Skipped (already sent)") . "\n";
            } else {
                echo "    -> [DRY RUN] Would send reminder email\n";
            }
        }
        
        echo "\n";
        
        // ==========================================
        // Get subscriptions due in 7 days (urgent reminder)
        // ==========================================
        
        echo "Checking for subscriptions due in 7 days...\n";
        
        $stmt = $db->prepare("
            SELECT cs.*, c.name as client_name, c.email as client_email
            FROM hosting_subscriptions cs
            JOIN hosting_clients c ON cs.client_id = c.id
            WHERE cs.status = 'active'
            AND cs.next_due_date = DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $dueIn7Days = $stmt->fetchAll();
        
        echo "  Found: " . count($dueIn7Days) . " subscription(s)\n";
        
        foreach ($dueIn7Days as $sub) {
            echo "  - {$sub['client_name']} ({$sub['client_email']}): {$sub['plan_name']}\n";
            
            if (!$dryRun) {
                $sent = $emailService->sendPaymentReminder($sub, 'reminder_7');
                echo "    -> " . ($sent ? "Email sent" : "Skipped (already sent)") . "\n";
            } else {
                echo "    -> [DRY RUN] Would send urgent reminder email\n";
            }
        }
        
        echo "\n";
        
        // ==========================================
        // Get overdue subscriptions
        // ==========================================
        
        echo "Checking for overdue subscriptions...\n";
        
        $stmt = $db->query("
            SELECT cs.*, c.name as client_name, c.email as client_email,
            DATEDIFF(CURDATE(), cs.next_due_date) as days_overdue
            FROM hosting_subscriptions cs
            JOIN hosting_clients c ON cs.client_id = c.id
            WHERE cs.status = 'active'
            AND cs.next_due_date < CURDATE()
            AND DATEDIFF(CURDATE(), cs.next_due_date) IN (1, 7, 14, 30)
        ");
        $overdueForReminder = $stmt->fetchAll();
        
        echo "  Found: " . count($overdueForReminder) . " subscription(s) needing overdue notice\n";
        
        foreach ($overdueForReminder as $sub) {
            echo "  - {$sub['client_name']}: {$sub['plan_name']} ({$sub['days_overdue']} days overdue)\n";
            
            if (!$dryRun) {
                $sent = $emailService->sendPaymentReminder($sub, 'overdue');
                echo "    -> " . ($sent ? "Email sent" : "Skipped (already sent)") . "\n";
            } else {
                echo "    -> [DRY RUN] Would send overdue notice\n";
            }
        }
        
        echo "\n";
    }
    
    // ==========================================
    // Send admin summary
    // ==========================================
    
    if ($adminEmail) {
        echo "Sending admin summary...\n";
        
        // Get all upcoming (30 days)
        $stmt = $db->query("
            SELECT cs.*, c.name as client_name, c.email as client_email
            FROM hosting_subscriptions cs
            JOIN hosting_clients c ON cs.client_id = c.id
            WHERE cs.status = 'active'
            AND cs.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY cs.next_due_date ASC
        ");
        $upcoming = $stmt->fetchAll();
        
        // Get all overdue
        $stmt = $db->query("
            SELECT cs.*, c.name as client_name, c.email as client_email,
            DATEDIFF(CURDATE(), cs.next_due_date) as days_overdue
            FROM hosting_subscriptions cs
            JOIN hosting_clients c ON cs.client_id = c.id
            WHERE cs.status = 'active'
            AND cs.next_due_date < CURDATE()
            ORDER BY cs.next_due_date ASC
        ");
        $overdue = $stmt->fetchAll();
        
        echo "  Upcoming (30 days): " . count($upcoming) . "\n";
        echo "  Overdue: " . count($overdue) . "\n";
        
        if (!$dryRun) {
            $sent = $emailService->sendAdminSummary($upcoming, $overdue);
            echo "  -> " . ($sent ? "Admin summary sent to {$adminEmail}" : "Failed to send admin summary") . "\n";
        } else {
            echo "  -> [DRY RUN] Would send admin summary to {$adminEmail}\n";
        }
    } else {
        echo "Admin email not configured, skipping summary.\n";
    }
    
    echo "\n";
    echo "===========================================\n";
    echo "  Complete!\n";
    echo "===========================================\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

