<?php
/**
 * DEVCON Fleet Manager - Config Parameterizer
 * 
 * Converts extracted configs into templates by replacing
 * hardcoded values with {{VARIABLES}}
 * 
 * Usage: php parameterize.php <extracted_dir> <output_dir> [--server-ip=X.X.X.X] [--hostname=xxx]
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line');
}

// Default replacements (will be detected from server_info.json)
$replacements = [];

// Parse command line arguments
$extractedDir = $argv[1] ?? null;
$outputDir = $argv[2] ?? null;

if (!$extractedDir || !$outputDir) {
    echo "Usage: php parameterize.php <extracted_dir> <output_dir> [options]\n";
    echo "Options:\n";
    echo "  --server-ip=X.X.X.X    Override server IP detection\n";
    echo "  --hostname=xxx         Override hostname detection\n";
    echo "  --panel-domain=xxx     Panel domain\n";
    echo "  --email-domain=xxx     Email app domain\n";
    echo "  --mail-domain=xxx      Mail domain\n";
    exit(1);
}

// Parse additional options
for ($i = 3; $i < count($argv); $i++) {
    if (preg_match('/^--([^=]+)=(.+)$/', $argv[$i], $matches)) {
        $key = str_replace('-', '_', $matches[1]);
        $replacements[$key] = $matches[2];
    }
}

// Load server info
$serverInfoPath = $extractedDir . '/server_info.json';
if (file_exists($serverInfoPath)) {
    $serverInfo = json_decode(file_get_contents($serverInfoPath), true);
    
    if (!isset($replacements['server_ip']) && isset($serverInfo['ip_address'])) {
        $replacements['server_ip'] = $serverInfo['ip_address'];
    }
    
    if (!isset($replacements['hostname']) && isset($serverInfo['hostname'])) {
        $replacements['hostname'] = $serverInfo['hostname'];
    }
}

// Define variable mappings
$variableMap = [
    // IP addresses
    $replacements['server_ip'] ?? '' => '{{SERVER_IP}}',
    
    // Hostnames
    $replacements['hostname'] ?? '' => '{{SERVER_HOSTNAME}}',
    
    // Domains (if provided)
    $replacements['panel_domain'] ?? '' => '{{PANEL_DOMAIN}}',
    $replacements['email_domain'] ?? '' => '{{EMAIL_DOMAIN}}',
    $replacements['mail_domain'] ?? '' => '{{MAIL_DOMAIN}}',
];

// Remove empty mappings
$variableMap = array_filter($variableMap, function($value, $key) {
    return !empty($key);
}, ARRAY_FILTER_USE_BOTH);

// Additional patterns to detect and replace
$patterns = [
    // Database passwords (common patterns)
    '/password\s*=\s*[\'"]([^\'"]+)[\'"]/i' => 'password = \'{{DB_PASSWORD}}\'',
    '/dbpasswd\s*=\s*[\'"]([^\'"]+)[\'"]/i' => 'dbpasswd = \'{{DB_PASSWORD}}\'',
    
    // MySQL connection strings
    '/mysql:\/\/([^:]+):([^@]+)@/' => 'mysql://{{DB_USER}}:{{DB_PASSWORD}}@',
    
    // API keys/secrets (generic patterns)
    '/api_key\s*=\s*[\'"]([a-zA-Z0-9]{32,})[\'"]/i' => 'api_key = \'{{API_KEY}}\'',
    '/secret\s*=\s*[\'"]([a-zA-Z0-9]{32,})[\'"]/i' => 'secret = \'{{SECRET_KEY}}\'',
    '/jwt_secret\s*=\s*[\'"]([a-zA-Z0-9]{32,})[\'"]/i' => 'jwt_secret = \'{{JWT_SECRET}}\'',
];

/**
 * Process a single file
 */
function processFile(string $inputPath, string $outputPath, array $variableMap, array $patterns): array
{
    $content = file_get_contents($inputPath);
    $originalContent = $content;
    $replacementsMade = [];
    
    // Apply direct string replacements
    foreach ($variableMap as $search => $replace) {
        if (empty($search)) continue;
        
        $count = 0;
        $content = str_replace($search, $replace, $content, $count);
        
        if ($count > 0) {
            $replacementsMade[] = [
                'type' => 'direct',
                'original' => $search,
                'replacement' => $replace,
                'count' => $count
            ];
        }
    }
    
    // Apply pattern replacements
    foreach ($patterns as $pattern => $replacement) {
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content, -1, $count);
            
            if ($count > 0) {
                $replacementsMade[] = [
                    'type' => 'pattern',
                    'pattern' => $pattern,
                    'replacement' => $replacement,
                    'count' => $count
                ];
            }
        }
    }
    
    // Create output directory if needed
    $outputDir = dirname($outputPath);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Write output
    file_put_contents($outputPath, $content);
    
    return [
        'modified' => $content !== $originalContent,
        'replacements' => $replacementsMade
    ];
}

/**
 * Recursively process directory
 */
function processDirectory(string $inputDir, string $outputDir, array $variableMap, array $patterns): array
{
    $results = [];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inputDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $relativePath = str_replace($inputDir, '', $item->getPathname());
        $outputPath = $outputDir . $relativePath;
        
        if ($item->isDir()) {
            if (!is_dir($outputPath)) {
                mkdir($outputPath, 0755, true);
            }
        } else {
            // Skip binary files and certain extensions
            $ext = strtolower($item->getExtension());
            $skipExtensions = ['gz', 'tar', 'zip', 'db', 'sqlite', 'pem', 'key', 'crt'];
            
            if (in_array($ext, $skipExtensions)) {
                // Just copy without modification
                copy($item->getPathname(), $outputPath);
                $results[$relativePath] = ['modified' => false, 'skipped' => true];
                continue;
            }
            
            $result = processFile($item->getPathname(), $outputPath, $variableMap, $patterns);
            $results[$relativePath] = $result;
        }
    }
    
    return $results;
}

// Main execution
echo "DEVCON Fleet Manager - Config Parameterizer\n";
echo "==========================================\n\n";

echo "Input directory: {$extractedDir}\n";
echo "Output directory: {$outputDir}\n\n";

echo "Variable mappings:\n";
foreach ($variableMap as $value => $variable) {
    echo "  {$value} => {$variable}\n";
}
echo "\n";

// Process all files
echo "Processing files...\n\n";

$results = processDirectory($extractedDir, $outputDir, $variableMap, $patterns);

// Summary
$modifiedCount = 0;
$skippedCount = 0;
$unchangedCount = 0;

foreach ($results as $path => $result) {
    if ($result['skipped'] ?? false) {
        $skippedCount++;
    } elseif ($result['modified']) {
        $modifiedCount++;
        echo "MODIFIED: {$path}\n";
        foreach ($result['replacements'] as $r) {
            echo "  - {$r['replacement']} ({$r['count']} occurrences)\n";
        }
    } else {
        $unchangedCount++;
    }
}

echo "\n==========================================\n";
echo "Summary:\n";
echo "  Modified: {$modifiedCount}\n";
echo "  Unchanged: {$unchangedCount}\n";
echo "  Skipped (binary): {$skippedCount}\n";
echo "\nOutput written to: {$outputDir}\n";

// Generate variables.json
$detectedVariables = [];
foreach ($results as $result) {
    if (!empty($result['replacements'])) {
        foreach ($result['replacements'] as $r) {
            preg_match_all('/\{\{([A-Z_]+)\}\}/', $r['replacement'], $matches);
            foreach ($matches[1] as $var) {
                $detectedVariables[$var] = true;
            }
        }
    }
}

$variablesJson = [
    'variables' => []
];

$variableDefinitions = [
    'SERVER_IP' => ['label' => 'Server IP Address', 'type' => 'text', 'required' => true, 'source' => 'auto-detect'],
    'SERVER_HOSTNAME' => ['label' => 'Server Hostname', 'type' => 'text', 'required' => true, 'source' => 'auto-detect'],
    'PANEL_DOMAIN' => ['label' => 'Panel Domain', 'type' => 'text', 'required' => true, 'placeholder' => 'panel.example.com'],
    'EMAIL_DOMAIN' => ['label' => 'Email App Domain', 'type' => 'text', 'required' => true, 'placeholder' => 'email.example.com'],
    'MAIL_DOMAIN' => ['label' => 'Mail Domain', 'type' => 'text', 'required' => true, 'placeholder' => 'example.com'],
    'DB_PASSWORD' => ['label' => 'Database Password', 'type' => 'password', 'required' => true, 'generate' => true],
    'DB_USER' => ['label' => 'Database User', 'type' => 'text', 'required' => true, 'default' => 'mailflow'],
    'API_KEY' => ['label' => 'API Key', 'type' => 'password', 'required' => false, 'generate' => true],
    'SECRET_KEY' => ['label' => 'Secret Key', 'type' => 'password', 'required' => false, 'generate' => true],
    'JWT_SECRET' => ['label' => 'JWT Secret', 'type' => 'password', 'required' => true, 'generate' => true],
];

foreach (array_keys($detectedVariables) as $var) {
    $def = $variableDefinitions[$var] ?? ['label' => $var, 'type' => 'text', 'required' => false];
    $def['name'] = $var;
    $variablesJson['variables'][] = $def;
}

file_put_contents($outputDir . '/variables.json', json_encode($variablesJson, JSON_PRETTY_PRINT));
echo "\nVariables definition written to: {$outputDir}/variables.json\n";

