#!/usr/bin/env php
<?php
/**
 * Backup Runner
 * 
 * Executes scheduled backups via cron.
 * Usage: php backup-runner.php --categories=webserver,mysql,mail --retention=7
 */

$config = require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Lib/Logger.php';
require_once __DIR__ . '/Lib/BackupManager.php';
require_once __DIR__ . '/Lib/DiffGenerator.php';
require_once __DIR__ . '/Lib/ActionInterface.php';
require_once __DIR__ . '/Lib/BaseAction.php';
require_once __DIR__ . '/Lib/BackupScheduleManager.php';
require_once __DIR__ . '/Lib/PanelDb.php';
require_once __DIR__ . '/Actions/BackupAction.php';

use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\BackupScheduleManager;
use VpsAdmin\Agent\Lib\PanelDb;
use VpsAdmin\Agent\Actions\BackupAction;

// Parse command line arguments
$options = getopt('', ['categories:', 'retention:', 'sites:', 'components:', 'destination:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
VPS Admin Backup Runner

Usage: php backup-runner.php [options]

Options:
  --categories=cat1,cat2   Comma-separated list of categories to backup
                           Available: webserver, vhosts, php, mysql, mail, dns,
                                      fail2ban, firewall, ssl, modsec, cpguard,
                                      cron, ssh, databases
  
  --sites=domain1,domain2  Comma-separated list of sites to backup
                           Use 'all' to backup all sites
  
  --components=c1,c2       Site backup components (default: all)
                           Available: all, database, plugins, themes, uploads, wpcore
  
  --retention=N            Delete backups older than N days (default: 7)
  
  --destination=DEST       Backup destination: local, nas, or both (default: local)
  
  --help                   Show this help message

Examples:
  # Backup all configs to NAS
  php backup-runner.php --categories=webserver,mysql,mail --retention=7 --destination=nas
  
  # Backup specific sites (full backup) to NAS
  php backup-runner.php --sites=example.com,mysite.hu --retention=14 --destination=nas
  
  # Backup all sites (database only)
  php backup-runner.php --sites=all --components=database --retention=7
  
  # Backup sites with specific components
  php backup-runner.php --sites=all --components=database,plugins,themes --retention=7
  
  # Full backup (configs + all sites) to both local and NAS
  php backup-runner.php --categories=webserver,mysql,mail --sites=all --retention=7 --destination=both

HELP;
    exit(0);
}

$categories = isset($options['categories']) ? explode(',', $options['categories']) : [];
$sites = isset($options['sites']) ? ($options['sites'] === 'all' ? 'all' : explode(',', $options['sites'])) : [];
$components = isset($options['components']) ? explode(',', $options['components']) : ['all'];
$retention = isset($options['retention']) ? (int)$options['retention'] : 7;
$destination = $options['destination'] ?? 'local';

if (empty($categories) && empty($sites)) {
    echo "Error: No categories or sites specified. Use --help for usage.\n";
    exit(1);
}

// Key identifying this schedule's workload in the run-state file. The panel
// reads the same key (derived from the cron command) to show last-run status.
$runStateKey = BackupScheduleManager::runStateKeyFromArgs([
    'sites' => $options['sites'] ?? '',
    'categories' => $options['categories'] ?? '',
    'components' => $options['components'] ?? '',
    'destination' => $options['destination'] ?? 'local',
]);

/**
 * Record the run outcome so the Scheduled tab can show WHY a run produced
 * nothing instead of silently looking broken.
 */
$GLOBALS['runStateFinalized'] = false;

function recordRunState(string $key, string $status, string $message = ''): void
{
    try {
        if ($status !== 'running') {
            $GLOBALS['runStateFinalized'] = true;
        }
        BackupScheduleManager::writeRunState($key, $status, $message);
    } catch (Throwable $e) {
        // State recording must never break the backup itself.
    }
}

recordRunState($runStateKey, 'running', 'Backup run started');

// If the runner dies from a fatal error / OOM / kill before reaching a
// final exit path, don't leave the schedule stuck on "running" forever.
register_shutdown_function(function () use ($runStateKey): void {
    if (empty($GLOBALS['runStateFinalized'])) {
        $err = error_get_last();
        $msg = $err ? "Runner died: {$err['message']}" : 'Runner terminated unexpectedly';
        try {
            BackupScheduleManager::writeRunState($runStateKey, 'failed', $msg);
        } catch (Throwable $e) {
            // Nothing more we can do at shutdown.
        }
    }
});

// Initialize components
$logger = new Logger($config);

// =========================================================================
// NAS AVAILABILITY CHECK - Fallback to local if NAS is unreachable
// =========================================================================
$nasAvailable = false;
$mountPoint = null;
$originalDestination = $destination;

if ($destination === 'nas' || $destination === 'both') {
    try {
        // Panel DB credentials live in the API config; the agent config's
        // database block is only a placeholder and must not be used here.
        $pdo = PanelDb::get();
        if (!$pdo) {
            throw new Exception('Panel DB unavailable: ' . PanelDb::lastError());
        }

        // Get default NAS connection
        $stmt = $pdo->query("SELECT mount_point, nfs_server, nfs_path FROM nas_connections WHERE is_default = 1 LIMIT 1");
        $nasConfig = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($nasConfig && !empty($nasConfig['mount_point'])) {
            $mountPoint = $nasConfig['mount_point'];
            
            // Check if mounted using mountpoint command
            exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>/dev/null", $output, $exitCode);
            $nasAvailable = ($exitCode === 0);
            
            // If not mounted, try auto-remount
            if (!$nasAvailable) {
                $logger->warning("NAS not mounted, attempting auto-remount", ['mount_point' => $mountPoint]);
                echo "NAS not mounted at {$mountPoint}, attempting remount...\n";
                
                // Try mount from fstab first
                exec("mount " . escapeshellarg($mountPoint) . " 2>&1", $mountOutput, $mountExitCode);
                
                // If fstab mount fails and we have NFS details, try explicit mount
                if ($mountExitCode !== 0 && !empty($nasConfig['nfs_server']) && !empty($nasConfig['nfs_path'])) {
                    $nfsSource = $nasConfig['nfs_server'] . ':' . $nasConfig['nfs_path'];
                    exec("mount -t nfs -o rw,soft,timeo=30 " . escapeshellarg($nfsSource) . " " . escapeshellarg($mountPoint) . " 2>&1", $mountOutput2, $mountExitCode2);
                }
                
                // Re-check mount status
                exec("mountpoint -q " . escapeshellarg($mountPoint) . " 2>/dev/null", $output2, $exitCode2);
                $nasAvailable = ($exitCode2 === 0);
                
                if ($nasAvailable) {
                    $logger->info("NAS auto-remount successful", ['mount_point' => $mountPoint]);
                    echo "NAS remounted successfully.\n";
                }
            }
        } else {
            $logger->warning("No default NAS connection configured");
        }
    } catch (Exception $e) {
        $logger->error("Failed to check NAS availability: " . $e->getMessage());
    }
    
    // Handle NAS unavailability: the backup itself must NEVER be skipped
    // because the NAS is down. Both destination=nas and destination=both
    // degrade to local-only - the archive stays on the VPS (visible in the
    // panel with a Server badge) and can be pushed to NAS later with the
    // "Send to NAS" transfer action once the mount is healthy. We still log
    // loudly, alert the operator and exit with the degraded code so cron /
    // monitoring see that the off-site copy is missing.
    $degraded = false;
    if (!$nasAvailable) {
        $destination = 'local';
        $degraded = true;
        $logger->warning('NAS unavailable, degrading to local-only', [
            'original_destination' => $originalDestination,
            'fallback_destination' => $destination,
            'mount_point' => $mountPoint ?? null,
        ]);
        echo "WARNING: NAS not available, this run wrote local-only. Off-site copy is MISSING - backup kept on server.\n";
        notifyBackupAlert(
            'NAS off-site copy missing (degraded)',
            "Scheduled backup ran with destination={$originalDestination} but the NAS was unreachable.\n"
                . "The backup was created locally on the server instead - no data was lost.\n"
                . "Mount point: " . ($mountPoint ?? 'unknown') . "\n"
                . "Action: once the NAS is restored, use 'Send to NAS' in the panel (or re-run with destination=nas) to upload the local archives."
        );
    }
}
$backup = new BackupManager($config, $logger);
$diff = new DiffGenerator();

$logger->info('Scheduled backup started', [
    'categories' => $categories,
    'sites' => $sites,
    'components' => $components,
    'retention' => $retention,
    'destination' => $destination,
]);

$results = [
    'success' => true,
    'categories_backed_up' => [],
    'sites_backed_up' => [],
    'errors' => [],
    'cleaned_up' => 0,
];

// Create BackupAction instance
$backupAction = new BackupAction($config, $backup, $diff, $logger);

// Backup categories (config files)
if (!empty($categories)) {
    $categoryResult = $backupAction->execute('create', [
        'categories' => $categories,
        'destination' => $destination,
    ], 'scheduled');
    
    if ($categoryResult['success']) {
        $results['categories_backed_up'] = $categoryResult['data']['backed_up'] ?? [];
        $results['archive'] = $categoryResult['data']['archive'] ?? null;
        $logger->info('Category backup completed', [
            'backed_up' => count($results['categories_backed_up']),
            'archive' => $results['archive'],
        ]);
    } else {
        $results['errors'][] = 'Category backup failed: ' . ($categoryResult['error'] ?? 'Unknown error');
        $logger->error('Category backup failed', ['error' => $categoryResult['error'] ?? 'Unknown']);
    }
}

// Backup sites (files + database based on components)
if (!empty($sites)) {
    $siteResult = $backupAction->execute('backupSites', [
        'sites' => $sites,
        'components' => $components,
        'destination' => $destination,
    ], 'scheduled');
    
    if ($siteResult['success']) {
        $results['sites_backed_up'] = $siteResult['data']['sites'] ?? [];
        $logger->info('Site backup completed', [
            'sites' => count($results['sites_backed_up']),
            'components' => $components,
        ]);
    } else {
        $results['errors'][] = 'Site backup failed: ' . ($siteResult['error'] ?? 'Unknown error');
        $logger->error('Site backup failed', ['error' => $siteResult['error'] ?? 'Unknown']);
    }
}

// Cleanup old backups
if ($retention > 0) {
    $backupPath = $config['paths']['backups'] ?? '/var/www/vps-admin/backups';
    $cutoff = strtotime("-{$retention} days");
    $deleted = 0;
    
    // Cleanup scheduled backups
    $scheduledPath = $backupPath . '/scheduled';
    if (is_dir($scheduledPath)) {
        $deleted += cleanupOldBackups($scheduledPath, $cutoff);
    }
    
    // Cleanup manual backups
    $manualPath = $backupPath . '/manual';
    if (is_dir($manualPath)) {
        $deleted += cleanupOldBackups($manualPath, $cutoff);
    }
    
    // Cleanup site backups
    $sitesPath = $backupPath . '/sites';
    if (is_dir($sitesPath)) {
        $deleted += cleanupOldBackups($sitesPath, $cutoff);
    }

    // NAS-side retention: prune the same backup folders on the NAS so old
    // off-site archives don't accumulate forever (the old rsync sweeper
    // never cleaned them). Only runs when this schedule targets the NAS
    // and the mount is actually live.
    if (in_array($originalDestination, ['nas', 'both'], true) && $nasAvailable && !empty($mountPoint)) {
        foreach (['scheduled', 'manual', 'sites', 'emails'] as $nasSub) {
            $nasCleanupPath = rtrim($mountPoint, '/') . '/backups/' . $nasSub;
            if (is_dir($nasCleanupPath)) {
                $deleted += cleanupOldBackups($nasCleanupPath, $cutoff);
            }
        }
    }

    $results['cleaned_up'] = $deleted;
    
    if ($deleted > 0) {
        $logger->info('Old backups cleaned up', ['deleted' => $deleted, 'retention_days' => $retention]);
    }
}

// Summary
if (!empty($results['errors'])) {
    $results['success'] = false;
    $logger->warning('Scheduled backup completed with errors', $results);
    echo "Backup completed with errors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - {$error}\n";
    }
    recordRunState($runStateKey, 'failed', implode(' | ', $results['errors']));
    exit(1);
} else {
    $logger->info('Scheduled backup completed successfully', $results + ['degraded' => $degraded ?? false]);
    echo "Backup completed successfully.\n";
    echo "  Destination: {$destination}\n";
    if (!empty($results['categories_backed_up'])) {
        echo "  Categories: " . count($results['categories_backed_up']) . " items\n";
    }
    if (!empty($results['sites_backed_up'])) {
        echo "  Sites: " . count($results['sites_backed_up']) . " sites\n";
    }
    if ($results['cleaned_up'] > 0) {
        echo "  Cleaned up: {$results['cleaned_up']} old backups\n";
    }
    // Exit code 3 = degraded (local OK but NAS off-site copy missing).
    // Cron/monitoring should treat this as a soft failure that needs attention
    // even though the local backup itself succeeded.
    if (!empty($degraded)) {
        echo "  STATUS: DEGRADED -- off-site NAS copy missing, backup kept on server.\n";
        recordRunState($runStateKey, 'degraded', 'NAS unavailable - backup kept on server, send to NAS once it is back online');
        exit(3);
    }
    $summaryBits = [];
    if (!empty($results['categories_backed_up'])) {
        $summaryBits[] = count($results['categories_backed_up']) . ' config items';
    }
    if (!empty($results['sites_backed_up'])) {
        $summaryBits[] = count($results['sites_backed_up']) . ' sites';
    }
    recordRunState($runStateKey, 'success', 'Backed up ' . (implode(', ', $summaryBits) ?: 'nothing') . " (destination: {$destination})");
    exit(0);
}

/**
 * Send an operator alert about a backup problem.
 *
 * Writes to a dedicated alert log (so a sysadmin watching the file sees it
 * immediately) and attempts an email via the system mailer. Failures are
 * swallowed - this is a best-effort notifier; the non-zero exit code is the
 * primary signal to cron/monitoring.
 */
function notifyBackupAlert(string $subject, string $body): void
{
    $logDir = '/var/www/vps-admin/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $alertLog = $logDir . '/backup-alerts.log';
    $line = '[' . date('Y-m-d H:i:s') . "] {$subject} | " . str_replace("\n", ' / ', $body) . "\n";
    @file_put_contents($alertLog, $line, FILE_APPEND);

    $to = 'admin@flowone.pro';
    $headers = implode("\r\n", [
        'From: FlowOne Backup <monitor@flowone.pro>',
        'Reply-To: monitor@flowone.pro',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: FlowOne Backup Runner',
    ]);
    @mail($to, "[FlowOne Backup] {$subject}", $body, $headers);
}

/**
 * Cleanup old backups in a directory
 */
function cleanupOldBackups(string $path, int $cutoff): int
{
    $deleted = 0;
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = $file->getExtension();
                // Only delete backup files
                if (in_array($ext, ['gz', 'bak', 'sql', 'json']) || 
                    str_ends_with($file->getFilename(), '.tar.gz')) {
                    if ($file->getMTime() < $cutoff) {
                        if (unlink($file->getPathname())) {
                            $deleted++;
                        }
                    }
                }
            }
        }
        
        // Remove empty directories
        $dirIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($dirIterator as $item) {
            if ($item->isDir()) {
                $dirPath = $item->getPathname();
                if (count(scandir($dirPath)) === 2) { // Only . and ..
                    rmdir($dirPath);
                }
            }
        }
    } catch (Exception $e) {
        // Ignore errors during cleanup
    }
    
    return $deleted;
}

