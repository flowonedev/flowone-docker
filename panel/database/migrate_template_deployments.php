<?php
/**
 * Template Deployments Migration
 * 
 * Creates a table to track which templates are deployed to which sites.
 * This allows the system to know what template type (placeholder, coming soon, maintenance)
 * is active on each site.
 * 
 * Run: php database/migrate_template_deployments.php
 */

echo "===========================================\n";
echo "  Template Deployments Migration\n";
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
    
    // Step 1: Create template_deployments table
    echo "Step 1: Creating template_deployments table...\n";
    
    if (tableExists($pdo, 'template_deployments')) {
        echo "[OK] Table already exists\n\n";
    } else {
        $pdo->exec("
            CREATE TABLE template_deployments (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                domain VARCHAR(255) NOT NULL,
                template_type ENUM('site_placeholder', 'site_coming_soon', 'site_maintenance') NOT NULL,
                deployed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                deployed_by VARCHAR(50),
                backup_file VARCHAR(255),
                INDEX idx_domain (domain),
                INDEX idx_template_type (template_type),
                UNIQUE KEY unique_domain (domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "[OK] Table created\n\n";
    }
    
    // Step 2: Scan existing sites for template backups and populate table
    echo "Step 2: Scanning for existing template deployments...\n";
    
    $homeDirs = glob('/home/*', GLOB_ONLYDIR);
    $detected = 0;
    $skipped = 0;
    
    foreach ($homeDirs as $homeDir) {
        $publicHtml = $homeDir . '/public_html';
        if (is_dir($publicHtml)) {
            $domain = basename($homeDir);
            
            // Check for template backup
            $backupPattern = $publicHtml . '/index.html.backup.*';
            $backupFiles = glob($backupPattern);
            
            if (!empty($backupFiles)) {
                // Check if already in database
                $stmt = $pdo->prepare("SELECT id FROM template_deployments WHERE domain = ?");
                $stmt->execute([$domain]);
                
                if ($stmt->fetch()) {
                    $skipped++;
                    continue;
                }
                
                // Get the most recent backup
                usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                $latestBackup = basename($backupFiles[0]);
                
                // Try to detect template type from current index.html content
                $indexFile = $publicHtml . '/index.html';
                $templateType = 'site_placeholder'; // default
                
                if (file_exists($indexFile)) {
                    $content = file_get_contents($indexFile);
                    $contentLower = strtolower($content);
                    
                    if (strpos($contentLower, 'maintenance') !== false || strpos($contentLower, 'under maintenance') !== false) {
                        $templateType = 'site_maintenance';
                    } elseif (strpos($contentLower, 'coming soon') !== false || strpos($contentLower, 'under construction') !== false) {
                        $templateType = 'site_coming_soon';
                    }
                }
                
                // Insert into database
                $stmt = $pdo->prepare("
                    INSERT INTO template_deployments (domain, template_type, backup_file, deployed_by)
                    VALUES (?, ?, ?, 'migration')
                ");
                $stmt->execute([$domain, $templateType, $latestBackup]);
                
                echo "  - {$domain}: detected as {$templateType}\n";
                $detected++;
            }
        }
    }
    
    echo "\n[OK] Detected {$detected} existing deployments";
    if ($skipped > 0) {
        echo " (skipped {$skipped} already tracked)";
    }
    echo "\n\n";
    
    // Step 3: Verify
    echo "Step 3: Verification...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM template_deployments");
    $total = $stmt->fetchColumn();
    
    if ($total > 0) {
        $stmt = $pdo->query("
            SELECT domain, template_type, deployed_at, deployed_by 
            FROM template_deployments 
            ORDER BY deployed_at DESC 
            LIMIT 20
        ");
        $deployments = $stmt->fetchAll();
        
        echo "\nTemplate Deployments ({$total} total):\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-30s | %-20s | %-20s | %-10s\n", "Domain", "Template", "Deployed At", "By");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($deployments as $d) {
            $templateName = match($d['template_type']) {
                'site_placeholder' => 'Placeholder',
                'site_coming_soon' => 'Coming Soon',
                'site_maintenance' => 'Maintenance',
                default => $d['template_type']
            };
            printf("%-30s | %-20s | %-20s | %-10s\n", 
                substr($d['domain'], 0, 30),
                $templateName,
                $d['deployed_at'],
                $d['deployed_by'] ?? 'system'
            );
        }
        
        echo str_repeat("-", 80) . "\n";
        
        // Summary by type
        echo "\nSummary by Template Type:\n";
        $stmt = $pdo->query("
            SELECT template_type, COUNT(*) as count 
            FROM template_deployments 
            GROUP BY template_type
        ");
        foreach ($stmt->fetchAll() as $row) {
            $name = match($row['template_type']) {
                'site_placeholder' => 'Placeholder',
                'site_coming_soon' => 'Coming Soon',
                'site_maintenance' => 'Maintenance',
                default => $row['template_type']
            };
            echo "  - {$name}: {$row['count']} site(s)\n";
        }
    } else {
        echo "\n[INFO] No template deployments found. Table is empty.\n";
        echo "       Deployments will be tracked as you apply templates.\n";
    }
    
    echo "\n===========================================\n";
    echo "  Migration Complete!\n";
    echo "===========================================\n\n";
    
} catch (PDOException $e) {
    echo "[ERROR] Database error: " . $e->getMessage() . "\n";
    exit(1);
}

