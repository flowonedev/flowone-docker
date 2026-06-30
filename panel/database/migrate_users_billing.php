<?php
/**
 * Migration: User Management & Client Billing System
 * 
 * Adds:
 * - Role, email, status columns to admin_users
 * - user_sites table for user-site associations
 * - clients table for hosting clients
 * - client_domains table for client-domain associations
 * - client_subscriptions table for billing
 * - payments table for payment history
 * - billing_emails table for email tracking
 * - billing_settings table for configuration
 * 
 * Usage: php migrate_users_billing.php [--user=root] [--password=xxx]
 */

// Parse command line arguments
$options = getopt('', ['user::', 'password::', 'host::', 'database::']);
$dbUser = $options['user'] ?? 'root';
$dbPass = $options['password'] ?? '';
$dbHost = $options['host'] ?? 'localhost';
$dbName = $options['database'] ?? 'vpsadmin';

// Try to read password from .my.cnf if not provided
if (empty($dbPass) && file_exists('/root/.my.cnf')) {
    $mycnf = file_get_contents('/root/.my.cnf');
    if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $mycnf, $matches)) {
        $dbPass = trim($matches[1]);
    }
}

echo "===========================================\n";
echo "  User Management & Billing Migration\n";
echo "===========================================\n\n";

try {
    // Connect to database
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "[OK] Connected to database: {$dbName}\n\n";

    // Track executed migrations
    $migrations = [];

    // =====================================================
    // Migration 1: Extend admin_users table
    // =====================================================
    echo "1. Extending admin_users table...\n";
    
    // Check if role column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'role'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN role ENUM('super_admin', 'user') DEFAULT 'user' AFTER password_hash");
        echo "   - Added 'role' column\n";
        $migrations[] = 'admin_users.role';
    } else {
        echo "   - 'role' column already exists\n";
    }
    
    // Check if email column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'email'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) AFTER role");
        echo "   - Added 'email' column\n";
        $migrations[] = 'admin_users.email';
    } else {
        echo "   - 'email' column already exists\n";
    }
    
    // Check if status column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'status'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN status ENUM('active', 'suspended') DEFAULT 'active' AFTER email");
        echo "   - Added 'status' column\n";
        $migrations[] = 'admin_users.status';
    } else {
        echo "   - 'status' column already exists\n";
    }
    
    // Update existing admin user to super_admin
    $pdo->exec("UPDATE admin_users SET role = 'super_admin' WHERE username = 'admin' AND role = 'user'");
    echo "   - Ensured 'admin' user has super_admin role\n";
    
    echo "[OK] admin_users table updated\n\n";

    // =====================================================
    // Migration 2: Create user_sites table
    // =====================================================
    echo "2. Creating user_sites table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sites (
            user_id INT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, domain),
            FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] user_sites table created\n\n";
    $migrations[] = 'user_sites';

    // =====================================================
    // Migration 3: Create clients table
    // =====================================================
    echo "3. Creating clients table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
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
    echo "[OK] clients table created\n\n";
    $migrations[] = 'clients';

    // =====================================================
    // Migration 4: Create client_domains table
    // =====================================================
    echo "4. Creating client_domains table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_domains (
            client_id INT UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            PRIMARY KEY (client_id, domain),
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] client_domains table created\n\n";
    $migrations[] = 'client_domains';

    // =====================================================
    // Migration 5: Create client_subscriptions table
    // =====================================================
    echo "5. Creating client_subscriptions table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_subscriptions (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            plan_name VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) DEFAULT 'HUF',
            billing_cycle ENUM('monthly', 'yearly') DEFAULT 'yearly',
            start_date DATE NOT NULL,
            next_due_date DATE NOT NULL,
            status ENUM('active', 'cancelled', 'expired') DEFAULT 'active',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            INDEX idx_next_due (next_due_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] client_subscriptions table created\n\n";
    $migrations[] = 'client_subscriptions';

    // =====================================================
    // Migration 6: Create payments table
    // =====================================================
    echo "6. Creating payments table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payments (
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
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES client_subscriptions(id) ON DELETE SET NULL,
            INDEX idx_client (client_id),
            INDEX idx_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] payments table created\n\n";
    $migrations[] = 'payments';

    // =====================================================
    // Migration 7: Create billing_emails table
    // =====================================================
    echo "7. Creating billing_emails table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_emails (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            client_id INT UNSIGNED NOT NULL,
            subscription_id INT UNSIGNED,
            email_type ENUM('reminder_30', 'reminder_7', 'overdue', 'receipt') NOT NULL,
            sent_to VARCHAR(255) NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] billing_emails table created\n\n";
    $migrations[] = 'billing_emails';

    // =====================================================
    // Migration 8: Create billing_settings table
    // =====================================================
    echo "8. Creating billing_settings table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS billing_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Insert default settings
    $defaults = [
        ['reminder_days', '30'],
        ['admin_email', ''],
        ['email_from_name', 'VPS Admin'],
        ['email_from_address', ''],
        ['currency_default', 'HUF'],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO billing_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $setting) {
        $stmt->execute($setting);
    }
    
    echo "[OK] billing_settings table created with defaults\n\n";
    $migrations[] = 'billing_settings';

    // =====================================================
    // Summary
    // =====================================================
    echo "===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n\n";
    echo "Tables/columns created or updated:\n";
    foreach ($migrations as $item) {
        echo "  - {$item}\n";
    }
    echo "\n";

} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}

