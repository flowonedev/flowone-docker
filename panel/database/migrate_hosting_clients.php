<?php
/**
 * Hosting Clients Migration
 * 
 * Separates VPS Panel clients from Email App clients.
 * - Renames tables: client_subscriptions -> hosting_subscriptions
 *                   client_domains -> hosting_domains
 *                   payments -> hosting_payments
 * - Creates hosting_clients table and populates it from existing subscription data.
 * 
 * Run: php database/migrate_hosting_clients.php
 */

echo "===========================================\n";
echo "  Hosting Tables Migration\n";
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
    
    // Step 1: Rename tables
    echo "Step 1: Renaming tables...\n";
    
    $renames = [
        'client_subscriptions' => 'hosting_subscriptions',
        'client_domains' => 'hosting_domains',
        'payments' => 'hosting_payments',
    ];
    
    foreach ($renames as $oldName => $newName) {
        if (tableExists($pdo, $newName)) {
            echo "  - {$newName} already exists, skipping rename\n";
        } elseif (tableExists($pdo, $oldName)) {
            $pdo->exec("RENAME TABLE `{$oldName}` TO `{$newName}`");
            echo "  - Renamed {$oldName} -> {$newName}\n";
        } else {
            echo "  - {$oldName} not found, will create {$newName}\n";
        }
    }
    
    echo "\n";
    
    // Step 2: Create hosting_subscriptions if it doesn't exist
    echo "Step 2: Ensuring hosting_subscriptions table exists...\n";
    
    if (!tableExists($pdo, 'hosting_subscriptions')) {
        $pdo->exec("
            CREATE TABLE hosting_subscriptions (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                client_id INT UNSIGNED NOT NULL,
                plan_name VARCHAR(100) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) DEFAULT 'HUF',
                billing_cycle ENUM('monthly','yearly') DEFAULT 'yearly',
                start_date DATE NOT NULL,
                next_due_date DATE NOT NULL,
                status ENUM('active','cancelled','expired') DEFAULT 'active',
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_status (status),
                INDEX idx_next_due (next_due_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created hosting_subscriptions\n";
    } else {
        echo "[OK] Table exists\n";
    }
    
    echo "\n";
    
    // Step 3: Create hosting_domains if it doesn't exist
    echo "Step 3: Ensuring hosting_domains table exists...\n";
    
    if (!tableExists($pdo, 'hosting_domains')) {
        $pdo->exec("
            CREATE TABLE hosting_domains (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                client_id INT UNSIGNED NOT NULL,
                domain VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_domain (domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created hosting_domains\n";
    } else {
        echo "[OK] Table exists\n";
    }
    
    echo "\n";
    
    // Step 4: Create hosting_payments if it doesn't exist
    echo "Step 4: Ensuring hosting_payments table exists...\n";
    
    if (!tableExists($pdo, 'hosting_payments')) {
        $pdo->exec("
            CREATE TABLE hosting_payments (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                client_id INT UNSIGNED NOT NULL,
                subscription_id INT UNSIGNED,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) DEFAULT 'HUF',
                payment_date DATE NOT NULL,
                payment_method VARCHAR(50),
                transaction_ref VARCHAR(255),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_subscription (subscription_id),
                INDEX idx_date (payment_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Created hosting_payments\n";
    } else {
        echo "[OK] Table exists\n";
    }
    
    echo "\n";
    
    // Step 5: Create hosting_clients table
    echo "Step 5: Creating hosting_clients table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hosting_clients (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            company VARCHAR(255),
            address TEXT,
            notes TEXT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "[OK] Table created/exists\n\n";
    
    // Step 6: Check if we need to migrate data
    $stmt = $pdo->query("SELECT COUNT(*) FROM hosting_clients");
    $existingCount = $stmt->fetchColumn();
    
    if ($existingCount > 0) {
        echo "[INFO] hosting_clients already has {$existingCount} records. Skipping data migration.\n";
        echo "       If you want to re-migrate, truncate the table first.\n\n";
    } else {
        // Step 7: Get all unique client_ids from subscriptions and domains
        echo "Step 6: Finding clients from subscriptions and domains...\n";
        
        $stmt = $pdo->query("
            SELECT DISTINCT client_id 
            FROM (
                SELECT client_id FROM hosting_subscriptions
                UNION
                SELECT client_id FROM hosting_domains
            ) AS combined
            ORDER BY client_id
        ");
        $clientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "[INFO] Found " . count($clientIds) . " unique client IDs\n\n";
        
        // Step 8: For each client_id, try to get data from old clients table or create placeholder
        echo "Step 7: Migrating client data...\n";
        
        $insertStmt = $pdo->prepare("
            INSERT INTO hosting_clients (id, name, email, phone, company, status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        
        foreach ($clientIds as $clientId) {
            // Try to get data from the old clients table (Email App)
            $stmt = $pdo->prepare("
                SELECT id, display_name, user_email, phone, company 
                FROM clients 
                WHERE id = ?
            ");
            $stmt->execute([$clientId]);
            $oldClient = $stmt->fetch();
            
            if ($oldClient) {
                // Use data from old table
                $name = $oldClient['display_name'] ?: "Client #{$clientId}";
                $email = $oldClient['user_email'] ?: "client{$clientId}@example.com";
                $phone = $oldClient['phone'];
                $company = $oldClient['company'];
                
                echo "  - Client #{$clientId}: {$name} ({$email}) - from existing data\n";
            } else {
                // Create placeholder
                $name = "Client #{$clientId}";
                $email = "client{$clientId}@example.com";
                $phone = null;
                $company = null;
                
                echo "  - Client #{$clientId}: {$name} - placeholder (no existing data)\n";
            }
            
            $insertStmt->execute([$clientId, $name, $email, $phone, $company]);
        }
        
        echo "\n[OK] Migrated " . count($clientIds) . " clients\n\n";
    }
    
    // Step 9: Verify
    echo "Step 8: Verification...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM hosting_clients");
    $totalClients = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT hc.id, hc.name, hc.email, 
               (SELECT COUNT(*) FROM hosting_domains cd WHERE cd.client_id = hc.id) as domains,
               (SELECT COUNT(*) FROM hosting_subscriptions cs WHERE cs.client_id = hc.id) as subscriptions
        FROM hosting_clients hc
        ORDER BY hc.id
    ");
    $clients = $stmt->fetchAll();
    
    echo "\nHosting Clients ({$totalClients} total):\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-4s | %-25s | %-30s | %-6s | %-6s\n", "ID", "Name", "Email", "Domains", "Subs");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($clients as $client) {
        printf("%-4s | %-25s | %-30s | %-6s | %-6s\n", 
            $client['id'],
            substr($client['name'], 0, 25),
            substr($client['email'], 0, 30),
            $client['domains'],
            $client['subscriptions']
        );
    }
    
    echo str_repeat("-", 80) . "\n";
    
    // Show table summary
    echo "\nTable Summary:\n";
    $tables = ['hosting_clients', 'hosting_subscriptions', 'hosting_domains', 'hosting_payments'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "  - {$table}: {$count} records\n";
    }
    
    echo "\n===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n";
    echo "\nVPS Panel tables:\n";
    echo "  - hosting_clients\n";
    echo "  - hosting_subscriptions\n";
    echo "  - hosting_domains\n";
    echo "  - hosting_payments\n";
    echo "\nEmail App tables (unchanged):\n";
    echo "  - clients\n";
    echo "\nIMPORTANT: Update placeholder client data (name/email) as needed.\n\n";
    
} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}
