#!/usr/bin/env php
<?php
/**
 * Meilisearch Migration Script
 * 
 * Migrates all existing search index data from MySQL to Meilisearch.
 * Safe to run multiple times - uses upsert operations.
 * 
 * Usage:
 *   php migrate_to_meilisearch.php [options]
 * 
 * Options:
 *   --user=email     Migrate only a specific user
 *   --verify         Verify document counts after migration
 *   --clear          Clear Meilisearch index before migration
 *   --configure      Only configure index settings (no data migration)
 *   --status         Show Meilisearch status and statistics
 *   --help           Show this help message
 * 
 * Environment Variables Required:
 *   MEILI_HOST       Meilisearch host (default: http://127.0.0.1:7700)
 *   MEILI_MASTER_KEY Master key for admin operations
 *   MEILI_SEARCH_KEY Search-only key
 * 
 * Example:
 *   MEILI_MASTER_KEY=your-key php migrate_to_meilisearch.php
 *   MEILI_MASTER_KEY=your-key php migrate_to_meilisearch.php --user=admin@example.com
 *   MEILI_MASTER_KEY=your-key php migrate_to_meilisearch.php --clear --verify
 */

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Parse command line arguments
$options = getopt('', ['user:', 'verify', 'clear', 'configure', 'status', 'help']);

// Show help
if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    preg_match('/\/\*\*(.*?)\*\//s', file_get_contents(__FILE__), $matches);
    echo "\n";
    exit(0);
}

// Load configuration
$config = require __DIR__ . '/../src/config.php';

// Override with environment variables if set
if (getenv('MEILI_HOST')) {
    $config['meilisearch']['host'] = getenv('MEILI_HOST');
}
if (getenv('MEILI_MASTER_KEY')) {
    $config['meilisearch']['master_key'] = getenv('MEILI_MASTER_KEY');
}
if (getenv('MEILI_SEARCH_KEY')) {
    $config['meilisearch']['search_key'] = getenv('MEILI_SEARCH_KEY');
}

// Check for master key
if (empty($config['meilisearch']['master_key'])) {
    echo "\033[31mError: MEILI_MASTER_KEY environment variable is required.\033[0m\n";
    echo "Set it with: MEILI_MASTER_KEY=your-key php migrate_to_meilisearch.php\n";
    exit(1);
}

use Webmail\Services\MeilisearchService;
use Webmail\Services\SearchIndexerService;

// Initialize services
try {
    $meilisearch = new MeilisearchService($config);
    $indexer = new SearchIndexerService($config);
} catch (Exception $e) {
    echo "\033[31mError initializing services: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}

// Check Meilisearch availability
if (!$meilisearch->isEnabled()) {
    echo "\033[31mError: Meilisearch is not enabled. Check your configuration.\033[0m\n";
    exit(1);
}

if (!$meilisearch->isHealthy()) {
    echo "\033[31mError: Meilisearch is not healthy. Is it running?\033[0m\n";
    echo "Host: " . ($config['meilisearch']['host'] ?? 'http://127.0.0.1:7700') . "\n";
    exit(1);
}

echo "\033[32mMeilisearch connection successful!\033[0m\n";
echo "Host: " . ($config['meilisearch']['host'] ?? 'http://127.0.0.1:7700') . "\n\n";

// Show status only
if (isset($options['status'])) {
    $stats = $meilisearch->getStats();
    echo "\033[36m=== Meilisearch Status ===\033[0m\n";
    echo "Enabled: " . ($stats['enabled'] ? 'Yes' : 'No') . "\n";
    echo "Healthy: " . ($stats['healthy'] ? 'Yes' : 'No') . "\n";
    if (isset($stats['numberOfDocuments'])) {
        echo "Documents: " . number_format($stats['numberOfDocuments']) . "\n";
    }
    if (isset($stats['isIndexing'])) {
        echo "Indexing: " . ($stats['isIndexing'] ? 'Yes' : 'No') . "\n";
    }
    exit(0);
}

// Configure index only
if (isset($options['configure'])) {
    echo "\033[36mConfiguring Meilisearch index settings...\033[0m\n";
    if ($meilisearch->configureIndex()) {
        echo "\033[32mIndex configured successfully!\033[0m\n";
    } else {
        echo "\033[31mFailed to configure index.\033[0m\n";
        exit(1);
    }
    exit(0);
}

// Clear index if requested
if (isset($options['clear'])) {
    echo "\033[33mClearing Meilisearch index...\033[0m\n";
    if ($meilisearch->clearIndex()) {
        echo "Index cleared.\n";
        // Wait for task to complete
        $meilisearch->waitForTasks(30);
    } else {
        echo "\033[31mFailed to clear index.\033[0m\n";
    }
}

// Progress callback
$progressCallback = function($current, $total, $message) {
    static $lastPercent = -1;
    $percent = $total > 0 ? round(($current / $total) * 100) : 0;
    
    if ($percent !== $lastPercent) {
        $bar = str_repeat('=', intval($percent / 2)) . str_repeat(' ', 50 - intval($percent / 2));
        echo "\r\033[K[$bar] $percent% - $message";
        $lastPercent = $percent;
    }
    
    if ($current >= $total && $total > 0) {
        echo "\n";
    }
};

// Migrate specific user or all users
if (isset($options['user'])) {
    $userEmail = $options['user'];
    echo "\033[36mMigrating user: $userEmail\033[0m\n";
    
    $result = $indexer->syncUserToMeilisearch($userEmail, $progressCallback);
    
    if ($result['success']) {
        echo "\033[32mMigration complete!\033[0m\n";
        echo "  Synced: " . number_format($result['synced']) . " documents\n";
        if ($result['errors'] > 0) {
            echo "  \033[33mErrors: " . number_format($result['errors']) . "\033[0m\n";
        }
    } else {
        echo "\033[31mMigration failed: " . ($result['error'] ?? 'Unknown error') . "\033[0m\n";
        exit(1);
    }
} else {
    echo "\033[36mMigrating all users...\033[0m\n";
    
    $result = $indexer->syncAllToMeilisearch($progressCallback);
    
    if ($result['success']) {
        echo "\n\033[32mMigration complete!\033[0m\n";
        echo "  Users: " . number_format($result['users']) . "\n";
        echo "  Documents synced: " . number_format($result['synced']) . "\n";
        if ($result['errors'] > 0) {
            echo "  \033[33mErrors: " . number_format($result['errors']) . "\033[0m\n";
        }
    } else {
        echo "\n\033[31mMigration failed: " . ($result['error'] ?? 'Unknown error') . "\033[0m\n";
        exit(1);
    }
}

// Verify if requested
if (isset($options['verify'])) {
    echo "\n\033[36mVerifying migration...\033[0m\n";
    
    // Wait for indexing to complete
    echo "Waiting for Meilisearch indexing to complete...\n";
    $meilisearch->waitForTasks(60);
    
    // Get MySQL count
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1',
            $config['db']['name'] ?? 'devc_vps_dash'
        );
        $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '');
        
        $stmt = $db->query("SELECT COUNT(*) FROM universal_search_index");
        $mysqlCount = (int)$stmt->fetchColumn();
        
        // Get Meilisearch count
        $stats = $meilisearch->getStats();
        $meiliCount = $stats['numberOfDocuments'] ?? 0;
        
        echo "  MySQL documents: " . number_format($mysqlCount) . "\n";
        echo "  Meilisearch documents: " . number_format($meiliCount) . "\n";
        
        if ($mysqlCount === $meiliCount) {
            echo "\033[32mVerification passed! Document counts match.\033[0m\n";
        } else {
            $diff = abs($mysqlCount - $meiliCount);
            echo "\033[33mWarning: Document count mismatch ($diff difference)\033[0m\n";
            echo "This may be normal if some documents failed to index or are still being processed.\n";
        }
    } catch (Exception $e) {
        echo "\033[31mVerification failed: " . $e->getMessage() . "\033[0m\n";
    }
}

echo "\n\033[32mDone!\033[0m\n";

