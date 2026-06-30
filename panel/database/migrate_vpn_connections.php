<?php
/**
 * VPN Connections Migration
 * 
 * Creates table for OpenVPN client connection management.
 * 
 * Run: php database/migrate_vpn_connections.php
 */

echo "===========================================\n";
echo "  VPN Connections Table Migration\n";
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
    
    // Create vpn_connections table
    echo "Creating vpn_connections table...\n";
    
    if (tableExists($pdo, 'vpn_connections')) {
        echo "[OK] Table already exists\n";
    } else {
        $pdo->exec("
            CREATE TABLE vpn_connections (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL UNIQUE,
                config_name VARCHAR(100) NOT NULL,
                description VARCHAR(255),
                server_address VARCHAR(255),
                server_port INT DEFAULT 1194,
                protocol ENUM('udp', 'tcp') DEFAULT 'udp',
                status ENUM('connected', 'disconnected', 'connecting', 'error') DEFAULT 'disconnected',
                local_ip VARCHAR(45),
                remote_ip VARCHAR(45),
                connected_at TIMESTAMP NULL,
                last_error TEXT,
                auto_start TINYINT(1) DEFAULT 1,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_status (status),
                INDEX idx_config_name (config_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created vpn_connections table\n";
    }
    
    echo "\n";
    
    // Verification
    echo "Verification...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM vpn_connections");
    $count = $stmt->fetchColumn();
    echo "  - vpn_connections: {$count} records\n";
    
    echo "\n===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n\n";
    
} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}

