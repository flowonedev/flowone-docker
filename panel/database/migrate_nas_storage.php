<?php
/**
 * NAS Storage Migration
 * 
 * Creates tables for NAS connection management:
 * - nas_connections: Store NAS configurations (local, NFS, CIFS)
 * - nas_domain_overrides: Domain-specific storage assignments
 * 
 * Run: php database/migrate_nas_storage.php
 */

echo "===========================================\n";
echo "  NAS Storage Tables Migration\n";
echo "===========================================\n\n";

// Load config
$configPath = __DIR__ . '/../api/config.php';
if (!file_exists($configPath)) {
    die("[ERROR] Config not found at: {$configPath}\n");
}

$config = require $configPath;

// Load local config if exists
$localConfig = __DIR__ . '/../api/config.local.php';
if (file_exists($localConfig)) {
    $localOverrides = require $localConfig;
    $config = array_merge($config, $localOverrides);
}

$dbConfig = $config['database'];

try {
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "[OK] Connected to database: {$dbConfig['name']}\n\n";
    
    // Helper to check if table exists
    function tableExists($pdo, $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->fetch() !== false;
    }
    
    // Step 1: Create nas_connections table
    echo "Step 1: Creating nas_connections table...\n";
    
    if (tableExists($pdo, 'nas_connections')) {
        echo "[OK] Table already exists\n";
    } else {
        $pdo->exec("
            CREATE TABLE nas_connections (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                driver ENUM('local', 'nfs', 'cifs') DEFAULT 'nfs',
                mount_point VARCHAR(500) NOT NULL,
                nfs_server VARCHAR(255),
                nfs_path VARCHAR(500),
                nfs_options VARCHAR(500) DEFAULT 'rw,soft,timeo=10,retrans=3',
                vpn_enabled TINYINT(1) DEFAULT 0,
                vpn_config_path VARCHAR(500),
                is_default TINYINT(1) DEFAULT 0,
                status ENUM('active', 'inactive', 'error') DEFAULT 'active',
                last_check TIMESTAMP NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_default (is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created nas_connections table\n";
    }
    
    echo "\n";
    
    // Step 2: Create nas_domain_overrides table
    echo "Step 2: Creating nas_domain_overrides table...\n";
    
    if (tableExists($pdo, 'nas_domain_overrides')) {
        echo "[OK] Table already exists\n";
    } else {
        $pdo->exec("
            CREATE TABLE nas_domain_overrides (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                nas_connection_id INT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                sub_path VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_domain (domain),
                FOREIGN KEY (nas_connection_id) REFERENCES nas_connections(id) ON DELETE CASCADE,
                INDEX idx_domain (domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created nas_domain_overrides table\n";
    }
    
    echo "\n";
    
    // Step 3: Insert default local storage if not exists
    echo "Step 3: Ensuring default local storage exists...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM nas_connections WHERE is_default = 1");
    $hasDefault = $stmt->fetchColumn() > 0;
    
    if ($hasDefault) {
        echo "[OK] Default storage already configured\n";
    } else {
        // Served to the email app as the Drive storage base path - must be
        // www-data writable, never the mail spool (/var/mail/vhosts).
        $pdo->exec("
            INSERT INTO nas_connections (name, driver, mount_point, is_default, status, notes)
            VALUES ('Local Storage', 'local', '/var/www/vps-email/storage/drive', 1, 'active', 'Default local storage for FlowOne Drive')
        ");
        echo "[OK] Created default local storage entry\n";
    }
    
    echo "\n";
    
    // Step 4: Verification
    echo "Step 4: Verification...\n";
    
    $stmt = $pdo->query("SELECT * FROM nas_connections ORDER BY is_default DESC, name ASC");
    $connections = $stmt->fetchAll();
    
    echo "\nNAS Connections (" . count($connections) . " total):\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-4s | %-20s | %-8s | %-30s | %-8s | %-8s\n", 
        "ID", "Name", "Driver", "Mount Point", "Default", "Status");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($connections as $conn) {
        printf("%-4s | %-20s | %-8s | %-30s | %-8s | %-8s\n", 
            $conn['id'],
            substr($conn['name'], 0, 20),
            $conn['driver'],
            substr($conn['mount_point'], 0, 30),
            $conn['is_default'] ? 'Yes' : 'No',
            $conn['status']
        );
    }
    
    echo str_repeat("-", 100) . "\n";
    
    // Show table summary
    echo "\nTable Summary:\n";
    $tables = ['nas_connections', 'nas_domain_overrides'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "  - {$table}: {$count} records\n";
    }
    
    echo "\n===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n\n";
    
} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}

