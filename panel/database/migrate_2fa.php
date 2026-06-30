#!/usr/bin/env php
<?php
/**
 * VPS Admin - 2FA Migration Script
 * 
 * Adds two-factor authentication support to the database.
 * 
 * Usage: php migrate_2fa.php [--dry-run]
 */

// Load config
$config = [];
$configPath = dirname(__DIR__) . '/api/config.local.php';
if (file_exists($configPath)) {
    $config = require $configPath;
}

// Fallback to default config
$configDefault = require dirname(__DIR__) . '/api/config.php';
$config = array_replace_recursive($configDefault, $config);

$dryRun = in_array('--dry-run', $argv);

echo "\n";
echo "\033[0;32m========================================\033[0m\n";
echo "\033[0;32m   VPS Admin 2FA Migration\033[0m\n";
if ($dryRun) {
    echo "\033[0;36m   (DRY RUN MODE)\033[0m\n";
}
echo "\033[0;32m========================================\033[0m\n";
echo "\n";

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['name']
    );
    
    $pdo = new PDO($dsn, $config['database']['user'], $config['database']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "\033[0;34m[INFO]\033[0m Connected to database\n";
    
    // Check if columns exist
    $stmt = $pdo->query("DESCRIBE admin_users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Migration 1: Add 2FA columns to admin_users
    if (!in_array('totp_secret', $columns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'totp_secret' column to admin_users\n";
        } else {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_hash");
            echo "\033[0;32m[OK]\033[0m Added 'totp_secret' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'totp_secret' column already exists\n";
    }
    
    if (!in_array('totp_enabled', $columns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'totp_enabled' column to admin_users\n";
        } else {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0 AFTER totp_secret");
            echo "\033[0;32m[OK]\033[0m Added 'totp_enabled' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'totp_enabled' column already exists\n";
    }
    
    if (!in_array('totp_backup_codes', $columns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'totp_backup_codes' column to admin_users\n";
        } else {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN totp_backup_codes TEXT NULL AFTER totp_enabled");
            echo "\033[0;32m[OK]\033[0m Added 'totp_backup_codes' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'totp_backup_codes' column already exists\n";
    }
    
    // Migration 2: Enhance sessions table with more info
    $stmt = $pdo->query("DESCRIBE sessions");
    $sessionColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('last_activity', $sessionColumns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'last_activity' column to sessions\n";
        } else {
            $pdo->exec("ALTER TABLE sessions ADD COLUMN last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER expires_at");
            echo "\033[0;32m[OK]\033[0m Added 'last_activity' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'last_activity' column already exists\n";
    }
    
    if (!in_array('location', $sessionColumns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'location' column to sessions\n";
        } else {
            $pdo->exec("ALTER TABLE sessions ADD COLUMN location VARCHAR(100) NULL AFTER user_agent");
            echo "\033[0;32m[OK]\033[0m Added 'location' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'location' column already exists\n";
    }
    
    if (!in_array('device_name', $sessionColumns)) {
        if ($dryRun) {
            echo "\033[0;36m[DRY-RUN]\033[0m Would add 'device_name' column to sessions\n";
        } else {
            $pdo->exec("ALTER TABLE sessions ADD COLUMN device_name VARCHAR(100) NULL AFTER location");
            echo "\033[0;32m[OK]\033[0m Added 'device_name' column\n";
        }
    } else {
        echo "\033[0;33m[SKIP]\033[0m 'device_name' column already exists\n";
    }
    
    echo "\n";
    echo "\033[0;32m========================================\033[0m\n";
    echo "\033[0;32m   Migration Complete!\033[0m\n";
    echo "\033[0;32m========================================\033[0m\n";
    echo "\n";
    
} catch (PDOException $e) {
    echo "\033[0;31m[ERROR]\033[0m Database error: " . $e->getMessage() . "\n";
    exit(1);
}

