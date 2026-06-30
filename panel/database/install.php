#!/usr/bin/env php
<?php
/**
 * VPS Admin Database Installation Script
 * 
 * Safe installation with:
 * - Dry-run mode (--dry-run)
 * - Pre-flight checks
 * - Conflict detection
 * - No destructive actions without confirmation
 * 
 * Usage: php install.php [--host=localhost] [--port=3306] [--user=root] [--password=xxx] [--database=vpsadmin]
 */

// Parse command line arguments
$options = getopt('', [
    'host::',
    'port::',
    'user::',
    'password::',
    'database::',
    'admin-user::',
    'admin-pass::',
    'dry-run',
    'check',
    'force',
    'help'
]);

// Colors
define('RED', "\033[0;31m");
define('GREEN', "\033[0;32m");
define('YELLOW', "\033[1;33m");
define('BLUE', "\033[0;34m");
define('CYAN', "\033[0;36m");
define('NC', "\033[0m");

function logInfo(string $msg): void {
    echo BLUE . "[INFO]" . NC . " {$msg}\n";
}

function logSuccess(string $msg): void {
    echo GREEN . "[OK]" . NC . " {$msg}\n";
}

function logWarn(string $msg): void {
    echo YELLOW . "[WARN]" . NC . " {$msg}\n";
}

function logError(string $msg): void {
    echo RED . "[ERROR]" . NC . " {$msg}\n";
}

function logDry(string $msg): void {
    echo CYAN . "[DRY-RUN]" . NC . " Would: {$msg}\n";
}

if (isset($options['help'])) {
    echo <<<HELP
VPS Admin Database Installation

Usage: php install.php [options]

Options:
  --host        MySQL host (default: localhost)
  --port        MySQL port (default: 3306)
  --user        MySQL admin user (default: root)
  --password    MySQL admin password
  --database    Database name (default: vpsadmin)
  --admin-user  Panel admin username (default: admin)
  --admin-pass  Panel admin password (will prompt if not provided)
  --dry-run     Show what would be done without making changes
  --check       Only check for conflicts, don't install
  --force       Skip confirmation prompts
  --help        Show this help message

Examples:
  php install.php --check --password=root123
  php install.php --dry-run --password=root123
  php install.php --password=root123
  php install.php --password=root123 --admin-pass=secure123

HELP;
    exit(0);
}

$host = $options['host'] ?? 'localhost';
$port = $options['port'] ?? 3306;
$user = $options['user'] ?? 'devc_vps_dash';
$password = $options['password'] ?? 'd3Logir6Siege//';
$database = $options['database'] ?? 'devc_vps_dash';
$adminUser = $options['admin-user'] ?? 'pxradmin';
$adminPass = $options['admin-pass'] ?? null;
$dryRun = isset($options['dry-run']);
$checkOnly = isset($options['check']);
$force = isset($options['force']);

echo "\n";
echo GREEN . "========================================" . NC . "\n";
echo GREEN . "   VPS Admin Database Installation" . NC . "\n";
if ($dryRun) {
    echo CYAN . "   (DRY RUN MODE)" . NC . "\n";
} elseif ($checkOnly) {
    echo CYAN . "   (CHECK MODE)" . NC . "\n";
}
echo GREEN . "========================================" . NC . "\n";
echo "\n";

// =====================================================
// Connection Test
// =====================================================

logInfo("Testing MySQL connection...");

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port}",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    logSuccess("Connected to MySQL at {$host}:{$port}");
} catch (PDOException $e) {
    logError("Cannot connect to MySQL: " . $e->getMessage());
    exit(1);
}

// =====================================================
// Conflict Detection
// =====================================================

echo "\n";
logInfo("Checking for conflicts...");

$conflicts = [];
$warnings = [];

// Check if database exists
$stmt = $pdo->query("SHOW DATABASES LIKE '{$database}'");
if ($stmt->rowCount() > 0) {
    $warnings[] = "Database '{$database}' already exists";
    
    // Check if tables exist
    $pdo->exec("USE `{$database}`");
    
    $tables = ['admin_users', 'sessions', 'audit_logs'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            $warnings[] = "Table '{$table}' already exists";
        }
    }
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
    try {
        $stmt->execute([$adminUser]);
        if ($stmt->fetchColumn() > 0) {
            $warnings[] = "Admin user '{$adminUser}' already exists (password will be updated)";
        }
    } catch (PDOException $e) {
        // Table doesn't exist yet
    }
}

// Check if vpsadmin MySQL user exists
$stmt = $pdo->query("SELECT COUNT(*) FROM mysql.user WHERE User = 'vpsadmin' AND Host = 'localhost'");
if ($stmt->fetchColumn() > 0) {
    $warnings[] = "MySQL user 'vpsadmin'@'localhost' already exists";
}

// Display results
if (empty($warnings)) {
    logSuccess("No conflicts detected - fresh installation");
} else {
    echo "\n";
    echo YELLOW . "Warnings detected:" . NC . "\n";
    foreach ($warnings as $warning) {
        echo "  - {$warning}\n";
    }
    echo "\n";
}

// Check mode - exit here
if ($checkOnly) {
    echo "\n";
    if (empty($warnings)) {
        echo GREEN . "System ready for installation." . NC . "\n";
    } else {
        echo YELLOW . count($warnings) . " warning(s) found." . NC . "\n";
        echo "The installation will update existing items where possible.\n";
    }
    echo "\nTo proceed, run without --check flag.\n\n";
    exit(0);
}

// =====================================================
// Dry Run Mode
// =====================================================

if ($dryRun) {
    echo "\n";
    logInfo("Installation plan:");
    echo "\n";
    
    logDry("Create database '{$database}' (if not exists)");
    logDry("Create table 'admin_users' (if not exists)");
    logDry("Create table 'sessions' (if not exists)");
    logDry("Create table 'audit_logs' (if not exists)");
    logDry("Create/update admin user '{$adminUser}'");
    logDry("Create MySQL user 'vpsadmin'@'localhost' (if not exists)");
    logDry("Grant privileges on '{$database}' to 'vpsadmin'@'localhost'");
    logDry("Generate application credentials file");
    
    echo "\n";
    echo YELLOW . "To proceed with actual installation, run without --dry-run" . NC . "\n";
    echo "\n";
    exit(0);
}

// =====================================================
// Confirmation
// =====================================================

if (!$force && !empty($warnings)) {
    echo "Continue with installation? (y/N): ";
    $confirm = trim(fgets(STDIN));
    if ($confirm !== 'y' && $confirm !== 'Y') {
        logInfo("Installation cancelled.");
        exit(0);
    }
    echo "\n";
}

// =====================================================
// Prompt for admin password
// =====================================================

if ($adminPass === null) {
    echo "Enter admin panel password: ";
    
    // Hide password input
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows - can't easily hide input
        $adminPass = trim(fgets(STDIN));
    } else {
        system('stty -echo');
        $adminPass = trim(fgets(STDIN));
        system('stty echo');
    }
    echo "\n";
    
    if (strlen($adminPass) < 8) {
        logError("Password must be at least 8 characters.");
        exit(1);
    }
}

// =====================================================
// Installation
// =====================================================

try {
    // Create database
    logInfo("Creating database...");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");
    logSuccess("Database '{$database}' ready");

    // Create tables
    logInfo("Creating tables...");

    // Admin users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    logSuccess("Table 'admin_users' ready");

    // Sessions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(64) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_expires_at (expires_at),
            FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    logSuccess("Table 'sessions' ready");

    // Audit logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            action VARCHAR(100) NOT NULL,
            actor VARCHAR(50) NOT NULL,
            target VARCHAR(255),
            details JSON,
            backup_path VARCHAR(500),
            diff MEDIUMTEXT,
            outcome ENUM('success', 'failed', 'rollback') NOT NULL DEFAULT 'success',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action (action),
            INDEX idx_actor (actor),
            INDEX idx_target (target),
            INDEX idx_outcome (outcome),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    logSuccess("Table 'audit_logs' ready");

    // Create or update admin user
    logInfo("Setting up admin user...");
    
    $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO admin_users (username, password_hash) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
    ");
    $stmt->execute([$adminUser, $passwordHash]);
    logSuccess("Admin user '{$adminUser}' ready");

    // Create database user for the application
    logInfo("Creating application database user...");
    
    $appPassword = bin2hex(random_bytes(16));
    
    // Check if user exists and update password, or create new
    $stmt = $pdo->query("SELECT COUNT(*) FROM mysql.user WHERE User = 'vpsadmin' AND Host = 'localhost'");
    if ($stmt->fetchColumn() > 0) {
        $pdo->exec("ALTER USER 'vpsadmin'@'localhost' IDENTIFIED BY '{$appPassword}'");
        logSuccess("Updated password for 'vpsadmin'@'localhost'");
    } else {
        $pdo->exec("CREATE USER 'vpsadmin'@'localhost' IDENTIFIED BY '{$appPassword}'");
        logSuccess("Created user 'vpsadmin'@'localhost'");
    }
    
    $pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO 'vpsadmin'@'localhost'");
    $pdo->exec("FLUSH PRIVILEGES");
    logSuccess("Privileges granted");

    // Generate config file content
    $jwtSecret = bin2hex(random_bytes(32));
    $configContent = <<<PHP
<?php
/**
 * VPS Admin - Local Configuration
 * Generated: {$date}
 * 
 * This file contains sensitive credentials - keep it secure!
 */

return [
    'database' => [
        'host' => '{$host}',
        'port' => {$port},
        'name' => '{$database}',
        'user' => 'vpsadmin',
        'password' => '{$appPassword}',
    ],
    'jwt' => [
        'secret' => '{$jwtSecret}',
    ],
];

PHP;

    $date = date('Y-m-d H:i:s');
    $configContent = <<<PHP
<?php
/**
 * VPS Admin - Local Configuration
 * Generated: {$date}
 * 
 * This file contains sensitive credentials - keep it secure!
 */

return [
    'database' => [
        'host' => '{$host}',
        'port' => {$port},
        'name' => '{$database}',
        'user' => 'vpsadmin',
        'password' => '{$appPassword}',
    ],
    'jwt' => [
        'secret' => '{$jwtSecret}',
    ],
];

PHP;

    // Try to write config file
    $configPath = dirname(__DIR__) . '/api/config.local.php';
    if (file_put_contents($configPath, $configContent)) {
        chmod($configPath, 0640);
        logSuccess("Configuration saved to: {$configPath}");
    } else {
        logWarn("Could not write config file. Save this manually:");
    }

    // Output summary
    echo "\n";
    echo GREEN . "========================================" . NC . "\n";
    echo GREEN . "   Installation Complete!" . NC . "\n";
    echo GREEN . "========================================" . NC . "\n";
    echo "\n";
    
    echo "Database: {$database}\n";
    echo "Admin Username: {$adminUser}\n";
    echo "Admin Password: (as entered)\n";
    echo "\n";
    
    echo "Application credentials:\n";
    echo "  MySQL User: vpsadmin@localhost\n";
    echo "  MySQL Password: {$appPassword}\n";
    echo "  JWT Secret: {$jwtSecret}\n";
    echo "\n";
    
    if (!file_exists($configPath)) {
        echo YELLOW . "Manual config required - add to api/config.local.php:" . NC . "\n";
        echo "----------------------------------------\n";
        echo $configContent;
        echo "----------------------------------------\n";
    }
    
    echo "\n";
    echo YELLOW . "IMPORTANT:" . NC . "\n";
    echo "  1. Keep the above credentials secure\n";
    echo "  2. Change the default admin password on first login\n";
    echo "  3. Ensure api/config.local.php has restricted permissions\n";
    echo "\n";

} catch (PDOException $e) {
    logError("Database error: " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    logError("Error: " . $e->getMessage());
    exit(1);
}
