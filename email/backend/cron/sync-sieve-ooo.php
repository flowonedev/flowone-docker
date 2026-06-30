#!/usr/bin/env php
<?php
/**
 * OOO Sieve Sync Cron
 * 
 * Lightweight cron job that checks for expired Out-of-Office schedules
 * and regenerates the unified sieve script (removing vacation block).
 * 
 * Dovecot Sieve handles the actual auto-reply. This cron only ensures
 * that expired OOO settings get cleaned up automatically.
 * 
 * Run every 15 minutes (use a star-slash-15 step in the minute field):
 *   STAR/15 STAR STAR STAR STAR  php /var/www/vps-email/backend/cron/sync-sieve-ooo.php
 * 
 * Options:
 *   --verbose   Show detailed output
 *   --help      Show help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\SieveSyncService;

$options = getopt('', ['verbose', 'help']);

if (isset($options['help'])) {
    echo "OOO Sieve Sync - Disables expired vacation sieve scripts\n\n";
    echo "Usage: php sync-sieve-ooo.php [--verbose] [--help]\n\n";
    echo "Cron: */15 * * * * php /var/www/vps-email/backend/cron/sync-sieve-ooo.php\n\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$settingsDir = '/var/www/vps-email/data/settings';

// Load config
$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    die("Config file not found: {$configFile}\n");
}
$config = require $configFile;

if (!is_dir($settingsDir)) {
    if ($verbose) echo "No settings directory found, nothing to do.\n";
    exit(0);
}

$now = new DateTime();
$updated = 0;
$checked = 0;

// Scan all user settings files
foreach (glob("{$settingsDir}/*.json") as $file) {
    $settings = json_decode(file_get_contents($file), true);
    if (!is_array($settings)) continue;
    
    // Only care about users who have OOO enabled
    if (empty($settings['ooo_enabled'])) continue;
    
    $checked++;
    
    // Check if the OOO has expired (has end date in the past)
    if (empty($settings['ooo_end_date'])) continue;
    
    $end = new DateTime($settings['ooo_end_date']);
    if ($now <= $end) {
        if ($verbose) {
            // Find email from settings or filename
            $email = $settings['_email'] ?? basename($file, '.json');
            echo "[{$email}] OOO still active (ends {$settings['ooo_end_date']})\n";
        }
        continue;
    }
    
    // OOO has expired - auto-disable it
    $settings['ooo_enabled'] = false;
    file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    if ($verbose) {
        echo "[expired] Disabled OOO in settings (ended {$settings['ooo_end_date']})\n";
    }
    
    // Now regenerate the sieve script for this user (without vacation)
    // We need the user's email to locate their maildir
    // Try to find it via the settings or the mail accounts DB
    $email = findEmailForSettingsFile($file, $config);
    if (!$email) {
        error_log("[OOO-Cron] Could not determine email for settings file: {$file}");
        continue;
    }
    
    // Regenerate unified sieve script
    regenerateSieveScript($email, $config, $verbose);
    $updated++;
    
    // Update DB status back to active
    updateDbStatus($email, $config);
}

if ($verbose || $updated > 0) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Checked {$checked} active OOO settings, disabled {$updated} expired.\n";
}

// Log if any work was done
if ($updated > 0) {
    $logFile = __DIR__ . '/../storage/logs/ooo-sync.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $entry = sprintf("[%s] Disabled %d expired OOO sieve scripts\n", date('Y-m-d H:i:s'), $updated);
    file_put_contents($logFile, $entry, FILE_APPEND);
}

exit(0);


/**
 * Find user email for a given settings JSON file.
 * Reverse-lookup: scan mail accounts DB for matching hash.
 */
function findEmailForSettingsFile(string $file, array $config): ?string
{
    $hash = basename($file, '.json');
    
    try {
        $dbConfig = $config['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? 3306,
            $dbConfig['name'] ?? 'webmail'
        );
        
        $db = new PDO($dsn, $dbConfig['user'] ?? 'root', $dbConfig['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        
        // Direct lookup via MD5 hash (indexed scan, no row limit)
        $stmt = $db->prepare("SELECT email FROM mail_accounts WHERE MD5(LOWER(email)) = ? LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row['email'];
        }
        
        // Check user_accounts table (for added accounts)
        $stmt = $db->prepare("SELECT account_email FROM user_accounts WHERE MD5(LOWER(account_email)) = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row['account_email'];
        }
    } catch (Exception $e) {
        error_log("[OOO-Cron] DB lookup error: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Regenerate the unified sieve script (filters + blocked/safe senders, no vacation)
 */
function regenerateSieveScript(string $email, array $config, bool $verbose): void
{
    $syncService = new SieveSyncService($config);
    $result = $syncService->syncViaDisk($email);

    if (!$result['success']) {
        error_log("[OOO-Cron] Failed to sync sieve for {$email}: " . ($result['error'] ?? 'unknown'));
    } elseif ($verbose) {
        echo "[{$email}] Regenerated sieve script (vacation removed)\n";
    }
}

/**
 * Update DB status back to active when OOO expires
 */
function updateDbStatus(string $email, array $config): void
{
    try {
        $dbConfig = $config['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? 3306,
            $dbConfig['name'] ?? 'webmail'
        );
        
        $db = new PDO($dsn, $dbConfig['user'] ?? 'root', $dbConfig['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        
        $stmt = $db->prepare("
            UPDATE mail_accounts 
            SET status = 'active', vacation_message = NULL, vacation_subject = NULL
            WHERE email = ? AND status = 'vacation'
        ");
        $stmt->execute([$email]);
    } catch (Exception $e) {
        error_log("[OOO-Cron] Failed to update DB for {$email}: " . $e->getMessage());
    }
}

