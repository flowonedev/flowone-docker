<?php
/**
 * Statistics Aggregation Cron Job
 * 
 * Run this script hourly via cron:
 * 0 * * * * php /path/to/backend/aggregate-stats.php >> /var/log/webmail-stats.log 2>&1
 * 
 * This script:
 * 1. Aggregates event-based statistics
 * 2. Calculates derived metrics (reply times, etc.)
 * 3. Updates contact statistics
 * 4. Cleans up old event logs
 */

require_once __DIR__ . '/vendor/autoload.php';

use Webmail\Services\StatsAggregator;

// Load configuration
$config = require __DIR__ . '/src/config.php';

// Initialize aggregator
$aggregator = new StatsAggregator($config);

echo "=== Statistics Aggregation Started at " . date('Y-m-d H:i:s') . " ===\n";

// Run aggregation
$result = $aggregator->run();

if ($result['success']) {
    echo "Aggregation completed successfully.\n";
    echo "Users processed: {$result['users_processed']}\n";
    echo "Duration: {$result['duration_seconds']} seconds\n";
    
    if (!empty($result['errors'])) {
        echo "Warnings:\n";
        foreach ($result['errors'] as $error) {
            echo "  - {$error['user']}: {$error['error']}\n";
        }
    }
} else {
    echo "Aggregation failed: {$result['error']}\n";
    exit(1);
}

// Cleanup old events (keep 90 days)
echo "\nCleaning up old events...\n";
$deleted = $aggregator->cleanupOldEvents(90);
echo "Deleted {$deleted} old event records.\n";

echo "\n=== Aggregation Completed at " . date('Y-m-d H:i:s') . " ===\n";

