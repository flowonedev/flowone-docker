<?php
/**
 * AI Helper Database Migration Script
 * 
 * Run this script to create AI Helper tables in the database.
 * Usage: php migrate_ai_helper.php [--user=root] [--password=xxx] [--host=localhost] [--database=dbname]
 */

// Parse command line arguments
$options = getopt('', ['user::', 'password::', 'host::', 'database::']);

// Try to load from config file first
$dbUser = $options['user'] ?? null;
$dbPass = $options['password'] ?? null;
$dbHost = $options['host'] ?? null;
$dbName = $options['database'] ?? null;

// Load configuration if not provided via CLI
if ($dbUser === null || $dbPass === null || $dbHost === null || $dbName === null) {
    $configPath = __DIR__ . '/../api/config.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
        
        // Load local config if exists
        $localConfigPath = __DIR__ . '/../api/config.local.php';
        if (file_exists($localConfigPath)) {
            $localConfig = require $localConfigPath;
            $config = array_replace_recursive($config, $localConfig);
        }
        
        if (isset($config['database'])) {
            $dbConfig = $config['database'];
            $dbHost = $dbHost ?? $dbConfig['host'] ?? 'localhost';
            $dbUser = $dbUser ?? $dbConfig['user'] ?? 'root';
            $dbPass = $dbPass ?? $dbConfig['password'] ?? '';
            $dbName = $dbName ?? $dbConfig['name'] ?? 'vpsadmin';
        }
    }
}

// Fallback defaults
$dbHost = $dbHost ?? 'localhost';
$dbUser = $dbUser ?? 'root';
$dbPass = $dbPass ?? '';
$dbName = $dbName ?? 'vpsadmin';

// Try to read password from .my.cnf if not provided
if (empty($dbPass) && file_exists('/root/.my.cnf')) {
    $mycnf = file_get_contents('/root/.my.cnf');
    if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $mycnf, $matches)) {
        $dbPass = trim($matches[1]);
    }
}

echo "===========================================\n";
echo "  AI Helper Database Migration\n";
echo "===========================================\n\n";

try {
    // Connect to database (without charset in DSN to avoid issues)
    $dsn = "mysql:host={$dbHost};dbname={$dbName}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Set charset after connection
    $pdo->exec("SET NAMES utf8mb4");
    
    echo "[OK] Connected to database: {$dbName}\n\n";

    echo "===========================================\n";
    echo "  AI Helper Database Migration\n";
    echo "===========================================\n\n";

    // Check if tables already exist
    $tables = ['ai_conversations', 'ai_messages', 'ai_cached_issues', 'ai_helper_settings'];
    $existingTables = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
        }
    }

    if (count($existingTables) === count($tables)) {
        echo "All AI Helper tables already exist.\n";
        echo "Tables: " . implode(', ', $existingTables) . "\n\n";
        exit(0);
    }

    echo "Creating AI Helper tables...\n\n";

    // Create ai_conversations table
    if (!in_array('ai_conversations', $existingTables)) {
        echo "1. Creating ai_conversations table...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_conversations (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] ai_conversations table created\n\n";
    } else {
        echo "[SKIP] ai_conversations table already exists\n\n";
    }

    // Create ai_messages table
    if (!in_array('ai_messages', $existingTables)) {
        echo "2. Creating ai_messages table...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_messages (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                conversation_id INT UNSIGNED NOT NULL,
                role ENUM('user', 'assistant', 'system') NOT NULL,
                content TEXT NOT NULL,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] ai_messages table created\n\n";
    } else {
        echo "[SKIP] ai_messages table already exists\n\n";
    }

    // Create ai_cached_issues table
    if (!in_array('ai_cached_issues', $existingTables)) {
        echo "3. Creating ai_cached_issues table...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_cached_issues (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                issue_type VARCHAR(100) NOT NULL,
                service VARCHAR(50),
                issue_key VARCHAR(255) NOT NULL,
                severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                description TEXT,
                detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                metadata JSON,
                UNIQUE KEY unique_issue (issue_type, service, issue_key),
                INDEX idx_service (service),
                INDEX idx_severity (severity),
                INDEX idx_resolved (resolved_at),
                INDEX idx_detected_at (detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] ai_cached_issues table created\n\n";
    } else {
        echo "[SKIP] ai_cached_issues table already exists\n\n";
    }

    // Create ai_helper_settings table
    if (!in_array('ai_helper_settings', $existingTables)) {
        echo "4. Creating ai_helper_settings table...\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_helper_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert default settings
        $defaults = [
            ['openai_api_key', ''],
            ['openai_model', 'gpt-4o'],
            ['max_tokens', '2000'],
            ['temperature', '0.3'],
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO ai_helper_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $setting) {
            $stmt->execute($setting);
        }
        echo "[OK] ai_helper_settings table created with defaults\n\n";
    } else {
        echo "[SKIP] ai_helper_settings table already exists\n\n";
    }

    echo "===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n\n";
    echo "All AI Helper tables have been created.\n";
    echo "You can now use the AI Helper feature.\n\n";

} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

