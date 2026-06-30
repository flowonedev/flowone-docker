<?php
/**
 * Application Installer Action Handler
 * 
 * Manages application installations (WordPress, Laravel, etc.)
 * Handles install, uninstall, update, and listing operations.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;
use VpsAdmin\Agent\Installers\WordPressInstaller;

class AppAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'app';
    }

    public function getMethods(): array
    {
        return ['templates', 'list', 'install', 'uninstall', 'status'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['install', 'uninstall']);
    }

    /**
     * Get available application templates
     */
    protected function actionTemplates(array $params, string $actor): array
    {
        $templates = $this->getTemplates();
        
        // Check PHP requirements for each template
        foreach ($templates as &$template) {
            $template['requirements_met'] = $this->checkRequirements($template['requirements'] ?? []);
        }
        
        return $this->success(['templates' => $templates]);
    }

    /**
     * List installed applications (from DB + filesystem scan)
     */
    protected function actionList(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;
        $scanFilesystem = $params['scan'] ?? true;
        
        try {
            $pdo = $this->getDatabase();
            
            // Get tracked apps from database
            if ($domain) {
                $stmt = $pdo->prepare("
                    SELECT sa.*, at.name as app_name, at.icon as app_icon
                    FROM site_applications sa
                    LEFT JOIN app_templates at ON sa.app_slug = at.slug
                    WHERE sa.domain = ?
                    ORDER BY sa.installed_at DESC
                ");
                $stmt->execute([$domain]);
            } else {
                $stmt = $pdo->query("
                    SELECT sa.*, at.name as app_name, at.icon as app_icon
                    FROM site_applications sa
                    LEFT JOIN app_templates at ON sa.app_slug = at.slug
                    ORDER BY sa.installed_at DESC
                ");
            }
            
            $apps = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $trackedDomains = array_column($apps, 'domain');
            
            // Scan filesystem for untracked WordPress installations
            if ($scanFilesystem) {
                $discovered = $this->scanForWordPress($domain);
                
                foreach ($discovered as $wp) {
                    // Skip if already tracked
                    if (in_array($wp['domain'], $trackedDomains)) {
                        continue;
                    }
                    
                    // Add as untracked (discovered) app
                    $apps[] = [
                        'id' => null,
                        'domain' => $wp['domain'],
                        'app_slug' => 'wordpress',
                        'app_name' => 'WordPress',
                        'app_icon' => 'web',
                        'app_version' => $wp['version'],
                        'install_path' => $wp['path'],
                        'admin_url' => 'https://' . $wp['domain'] . '/wp-admin/',
                        'admin_user' => null,
                        'database_name' => $wp['db_name'],
                        'installed_at' => null,
                        'installed_by' => null,
                        'status' => 'discovered',
                        'notes' => 'Auto-discovered WordPress installation',
                    ];
                }
            }
            
            return $this->success(['applications' => $apps]);
        } catch (\Exception $e) {
            return $this->error('Failed to list applications: ' . $e->getMessage());
        }
    }
    
    /**
     * Scan for WordPress installations in /home directories
     */
    private function scanForWordPress(?string $filterDomain = null): array
    {
        $found = [];
        $homeDirs = glob('/home/*/public_html');
        
        foreach ($homeDirs as $publicHtml) {
            $wpConfig = $publicHtml . '/wp-config.php';
            
            if (!file_exists($wpConfig)) {
                continue;
            }
            
            // Extract domain from path
            preg_match('#/home/([^/]+)/public_html#', $publicHtml, $matches);
            $domain = $matches[1] ?? null;
            
            if (!$domain) {
                continue;
            }
            
            // Filter by domain if specified
            if ($filterDomain && $domain !== $filterDomain) {
                continue;
            }
            
            // Get WordPress version
            $version = null;
            $versionFile = $publicHtml . '/wp-includes/version.php';
            if (file_exists($versionFile)) {
                $content = file_get_contents($versionFile);
                if (preg_match("/\\\$wp_version\s*=\s*'([^']+)'/", $content, $matches)) {
                    $version = $matches[1];
                }
            }
            
            // Get database name from wp-config.php
            $dbName = null;
            $configContent = file_get_contents($wpConfig);
            if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configContent, $matches)) {
                $dbName = $matches[1];
            }
            
            $found[] = [
                'domain' => $domain,
                'path' => $publicHtml,
                'version' => $version,
                'db_name' => $dbName,
            ];
        }
        
        return $found;
    }

    /**
     * Install an application
     */
    protected function actionInstall(array $params, string $actor): array
    {
        $required = ['domain', 'app_slug'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return $this->error("Missing required field: {$field}");
            }
        }

        $domain = $params['domain'];
        $appSlug = $params['app_slug'];

        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Get template
        $template = $this->getTemplate($appSlug);
        if (!$template) {
            return $this->error("Unknown application: {$appSlug}");
        }

        // Check requirements
        $requirements = $this->checkRequirements($template['requirements'] ?? []);
        if (!$requirements['met']) {
            return $this->error('Requirements not met: ' . implode(', ', $requirements['missing']));
        }

        // Check if site exists
        $homeDir = "/home/{$domain}";
        $docRoot = $homeDir . '/public_html';
        
        if (!is_dir($docRoot)) {
            return $this->error("Site document root not found: {$docRoot}");
        }

        // Get site user
        $siteUser = $this->getSiteUser($domain);
        if (!$siteUser) {
            return $this->error("Could not determine site user for {$domain}");
        }

        // Prepare installation parameters
        $installParams = [
            'domain' => $domain,
            'document_root' => $docRoot,
            'site_user' => $siteUser,
            'admin_email' => $params['admin_email'] ?? "admin@{$domain}",
            'admin_user' => $params['admin_user'] ?? 'admin',
            'admin_password' => $params['admin_password'] ?? $this->generatePassword(),
            'site_title' => $params['site_title'] ?? $domain,
            'db_name' => $params['db_name'] ?? null,
            'db_user' => $params['db_user'] ?? null,
            'db_password' => $params['db_password'] ?? null,
        ];

        // Install based on app type
        $result = match ($appSlug) {
            'wordpress' => $this->installWordPress($installParams, $actor),
            'laravel' => $this->installLaravel($installParams, $actor),
            default => $this->error("Installer not implemented for: {$appSlug}"),
        };

        if (!$result['success']) {
            return $result;
        }

        // Record installation in database
        try {
            $pdo = $this->getDatabase();
            $stmt = $pdo->prepare("
                INSERT INTO site_applications 
                (domain, app_slug, app_version, install_path, admin_url, admin_user, database_name, installed_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, (SELECT id FROM admin_users WHERE username = ? LIMIT 1), 'active')
            ");
            $stmt->execute([
                $domain,
                $appSlug,
                $result['data']['version'] ?? 'latest',
                $docRoot,
                $result['data']['admin_url'] ?? null,
                $installParams['admin_user'],
                $result['data']['database'] ?? null,
                $actor,
            ]);
            
            $result['data']['app_id'] = $pdo->lastInsertId();
        } catch (\Exception $e) {
            // Installation succeeded but record failed - log warning
            $this->logger->warning("Failed to record app installation: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Install WordPress using WP-CLI
     */
    private function installWordPress(array $params, string $actor): array
    {
        $installer = new WordPressInstaller($this->config, $this->logger);
        return $installer->install($params, $actor);
    }

    /**
     * Install Laravel using Composer
     */
    private function installLaravel(array $params, string $actor): array
    {
        $domain = $params['domain'];
        $docRoot = $params['document_root'];
        $siteUser = $params['site_user'];

        // Check if directory is empty or has only VPS Admin scaffolding files
        $scaffolding = ['.', '..', '.well-known', 'error', 'cgi-bin'];
        $allFiles = array_diff(scandir($docRoot), $scaffolding);
        
        $realFiles = array_filter($allFiles, function ($f) {
            if ($f === 'index.html' || str_starts_with($f, 'index.html.')) return false;
            if ($f === '.htaccess' || $f === '.user.ini') return false;
            return true;
        });
        
        if (!empty($realFiles)) {
            return $this->error('Document root is not empty. Please clear it before installing Laravel. Found: ' . implode(', ', array_slice(array_values($realFiles), 0, 10)));
        }

        // Clean up all scaffolding before install
        foreach ($allFiles as $f) {
            $path = $docRoot . '/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $errorDir = $docRoot . '/error';
        if (is_dir($errorDir)) {
            array_map('unlink', glob($errorDir . '/*'));
            @rmdir($errorDir);
        }

        // Create Laravel project
        $tempDir = "/tmp/laravel_" . uniqid();
        
        $result = $this->execCommand('composer', [
            'create-project',
            '--prefer-dist',
            'laravel/laravel',
            $tempDir,
            '--no-interaction',
        ]);

        if (!$result['success']) {
            return $this->error('Failed to create Laravel project: ' . $result['output']);
        }

        // Move files to document root
        $this->execCommand('bash', ['-c', "mv {$tempDir}/* {$tempDir}/.[!.]* {$docRoot}/ 2>/dev/null"]);
        
        // Remove temp directory
        @rmdir($tempDir);

        // Update document root to point to public folder in vhost config
        // Laravel serves from /public subdirectory
        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
        $configFile = $vhostPath . '/vhconf.conf';
        if (file_exists($configFile)) {
            $config = file_get_contents($configFile);
            $config = preg_replace(
                '/docRoot\s+.+$/m',
                'docRoot                   ' . $docRoot . '/public',
                $config
            );
            file_put_contents($configFile, $config);
        }

        // Create database if needed
        $dbInfo = null;
        if (!empty($params['db_name']) || true) {  // Always create for Laravel
            $dbName = $params['db_name'] ?? $this->generateDbName($domain);
            $dbUser = $params['db_user'] ?? $dbName;
            $dbPass = $params['db_password'] ?? $this->generatePassword();
            
            $dbResult = $this->createDatabase($dbName, $dbUser, $dbPass);
            if ($dbResult['success']) {
                $dbInfo = [
                    'name' => $dbName,
                    'user' => $dbUser,
                    'password' => $dbPass,
                ];
                
                // Update .env file
                $envFile = $docRoot . '/.env';
                if (file_exists($envFile)) {
                    $env = file_get_contents($envFile);
                    $env = preg_replace('/^DB_DATABASE=.*/m', "DB_DATABASE={$dbName}", $env);
                    $env = preg_replace('/^DB_USERNAME=.*/m', "DB_USERNAME={$dbUser}", $env);
                    $env = preg_replace('/^DB_PASSWORD=.*/m', "DB_PASSWORD={$dbPass}", $env);
                    $env = preg_replace('/^APP_URL=.*/m', "APP_URL=https://{$domain}", $env);
                    file_put_contents($envFile, $env);
                }
            }
        }

        // Set permissions
        $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $docRoot]);
        $this->execCommand('chmod', ['-R', '755', $docRoot]);
        $this->execCommand('chmod', ['-R', '775', "{$docRoot}/storage", "{$docRoot}/bootstrap/cache"]);

        // Generate application key
        $this->execCommand('su', ['-', $siteUser, '-c', "cd {$docRoot} && php artisan key:generate --force"]);

        // Reload OLS
        $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['restart']);

        return $this->success([
            'domain' => $domain,
            'app' => 'laravel',
            'version' => 'latest',
            'install_path' => $docRoot,
            'public_path' => $docRoot . '/public',
            'database' => $dbInfo['name'] ?? null,
            'db_info' => $dbInfo,
        ], "Laravel installed successfully on {$domain}");
    }

    /**
     * Uninstall an application
     */
    protected function actionUninstall(array $params, string $actor): array
    {
        if (empty($params['app_id']) && (empty($params['domain']) || empty($params['app_slug']))) {
            return $this->error('Either app_id or domain+app_slug is required');
        }

        try {
            $pdo = $this->getDatabase();
            
            // Find the application
            if (!empty($params['app_id'])) {
                $stmt = $pdo->prepare("SELECT * FROM site_applications WHERE id = ?");
                $stmt->execute([$params['app_id']]);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM site_applications WHERE domain = ? AND app_slug = ?");
                $stmt->execute([$params['domain'], $params['app_slug']]);
            }
            
            $app = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$app) {
                return $this->error('Application not found');
            }

            $domain = $app['domain'];
            $docRoot = $app['install_path'];
            $dbName = $app['database_name'];
            $keepFiles = $params['keep_files'] ?? false;
            $keepDatabase = $params['keep_database'] ?? false;

            $results = [
                'domain' => $domain,
                'app_slug' => $app['app_slug'],
                'files_removed' => false,
                'database_removed' => false,
            ];

            // Backup files before removal
            if (!$keepFiles && is_dir($docRoot)) {
                $backupDir = $this->config['paths']['backups'] . '/apps/' . $domain . '_' . date('Y-m-d_H-i-s');
                if (!is_dir(dirname($backupDir))) {
                    mkdir(dirname($backupDir), 0755, true);
                }
                
                // Create backup
                $this->execCommand('tar', ['-czf', $backupDir . '.tar.gz', '-C', dirname($docRoot), basename($docRoot)]);
                $results['backup_path'] = $backupDir . '.tar.gz';

                // Clear directory contents but keep directory
                $this->execCommand('rm', ['-rf', $docRoot . '/*', $docRoot . '/.[!.]*']);
                
                // Create placeholder
                $siteUser = $this->getSiteUser($domain);
                file_put_contents($docRoot . '/index.html', $this->generatePlaceholder($domain));
                if ($siteUser) {
                    chown($docRoot . '/index.html', $siteUser);
                    chgrp($docRoot . '/index.html', $siteUser);
                }
                
                $results['files_removed'] = true;
            }

            // Drop database if exists and not keeping
            if (!$keepDatabase && $dbName) {
                try {
                    $rootPdo = $this->getMySqlConnection();
                    
                    // Backup database first
                    $dbBackup = $this->config['paths']['backups'] . '/databases/' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql';
                    if (!is_dir(dirname($dbBackup))) {
                        mkdir(dirname($dbBackup), 0755, true);
                    }
                    $this->execCommand('mysqldump', [$dbName, '--result-file=' . $dbBackup]);
                    $results['database_backup'] = $dbBackup;
                    
                    // Drop database (safe – validated identifier)
                    $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
                    $rootPdo->exec("DROP DATABASE IF EXISTS `{$safeDbName}`");
                    $results['database_removed'] = true;
                } catch (\Exception $e) {
                    $results['database_error'] = $e->getMessage();
                }
            }

            // Remove from site_applications table
            $stmt = $pdo->prepare("DELETE FROM site_applications WHERE id = ?");
            $stmt->execute([$app['id']]);

            // Reload OLS
            $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['reload']);

            return $this->success($results, "Application {$app['app_slug']} uninstalled from {$domain}");

        } catch (\Exception $e) {
            return $this->error('Failed to uninstall: ' . $e->getMessage());
        }
    }

    /**
     * Get application status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        if (empty($params['app_id']) && (empty($params['domain']) || empty($params['app_slug']))) {
            return $this->error('Either app_id or domain+app_slug is required');
        }

        try {
            $pdo = $this->getDatabase();
            
            if (!empty($params['app_id'])) {
                $stmt = $pdo->prepare("
                    SELECT sa.*, at.name as app_name, at.version as template_version
                    FROM site_applications sa
                    LEFT JOIN app_templates at ON sa.app_slug = at.slug
                    WHERE sa.id = ?
                ");
                $stmt->execute([$params['app_id']]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT sa.*, at.name as app_name, at.version as template_version
                    FROM site_applications sa
                    LEFT JOIN app_templates at ON sa.app_slug = at.slug
                    WHERE sa.domain = ? AND sa.app_slug = ?
                ");
                $stmt->execute([$params['domain'], $params['app_slug']]);
            }
            
            $app = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$app) {
                return $this->error('Application not found');
            }

            // Check if files exist
            $app['files_exist'] = is_dir($app['install_path']);
            
            // Check database exists
            if ($app['database_name']) {
                try {
                    $rootPdo = $this->getMySqlConnection();
                    $stmt = $rootPdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
                    $stmt->execute([$app['database_name']]);
                    $app['database_exists'] = (bool) $stmt->fetch();
                } catch (\Exception $e) {
                    $app['database_exists'] = null;
                }
            }

            // For WordPress, check version
            if ($app['app_slug'] === 'wordpress' && $app['files_exist']) {
                $versionFile = $app['install_path'] . '/wp-includes/version.php';
                if (file_exists($versionFile)) {
                    $content = file_get_contents($versionFile);
                    if (preg_match("/\\\$wp_version\s*=\s*'([^']+)'/", $content, $matches)) {
                        $app['installed_version'] = $matches[1];
                    }
                }
            }

            return $this->success(['application' => $app]);

        } catch (\Exception $e) {
            return $this->error('Failed to get status: ' . $e->getMessage());
        }
    }

    /**
     * Get application templates
     */
    private function getTemplates(): array
    {
        try {
            $pdo = $this->getDatabase();
            $stmt = $pdo->query("SELECT * FROM app_templates WHERE status = 'active' ORDER BY name");
            $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Decode JSON requirements
            foreach ($templates as &$template) {
                if ($template['requirements']) {
                    $template['requirements'] = json_decode($template['requirements'], true);
                }
            }
            
            return $templates;
        } catch (\Exception $e) {
            // Return hardcoded templates as fallback
            return [
                [
                    'slug' => 'wordpress',
                    'name' => 'WordPress',
                    'description' => 'The world\'s most popular content management system.',
                    'version' => 'latest',
                    'category' => 'cms',
                    'icon' => 'wordpress',
                    'requirements' => ['php' => '>=7.4', 'mysql' => true],
                ],
                [
                    'slug' => 'laravel',
                    'name' => 'Laravel',
                    'description' => 'The PHP Framework for Web Artisans.',
                    'version' => 'latest',
                    'category' => 'framework',
                    'icon' => 'code',
                    'requirements' => ['php' => '>=8.1', 'mysql' => true],
                ],
            ];
        }
    }

    /**
     * Get a single template by slug
     */
    private function getTemplate(string $slug): ?array
    {
        $templates = $this->getTemplates();
        foreach ($templates as $template) {
            if ($template['slug'] === $slug) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Check if requirements are met
     */
    private function checkRequirements(array $requirements): array
    {
        $result = [
            'met' => true,
            'missing' => [],
            'details' => [],
        ];

        // Check PHP version
        if (!empty($requirements['php'])) {
            $required = $requirements['php'];
            $current = PHP_VERSION;
            $operator = '>=';
            
            if (preg_match('/^([<>=!]+)?(.+)$/', $required, $matches)) {
                $operator = $matches[1] ?: '>=';
                $required = $matches[2];
            }
            
            if (!version_compare($current, $required, $operator)) {
                $result['met'] = false;
                $result['missing'][] = "PHP {$requirements['php']} (current: {$current})";
            }
            $result['details']['php'] = ['required' => $requirements['php'], 'current' => $current];
        }

        // Check PHP extensions
        if (!empty($requirements['extensions'])) {
            foreach ($requirements['extensions'] as $ext) {
                if (!extension_loaded($ext)) {
                    $result['met'] = false;
                    $result['missing'][] = "PHP extension: {$ext}";
                }
            }
            $result['details']['extensions'] = $requirements['extensions'];
        }

        // Check MySQL
        if (!empty($requirements['mysql'])) {
            // MySQL is always available in our setup
            $result['details']['mysql'] = true;
        }

        // Check WP-CLI for WordPress
        if (isset($requirements['wp_cli'])) {
            exec('which wp 2>/dev/null', $output, $code);
            if ($code !== 0) {
                $result['met'] = false;
                $result['missing'][] = 'WP-CLI';
            }
        }

        return $result;
    }

    /**
     * Get site user from multiple sources
     */
    private function getSiteUser(string $domain): ?string
    {
        $homeDir = "/home/{$domain}";
        $sshDir = $homeDir . '/.ssh';
        $publicHtml = $homeDir . '/public_html';
        
        // 1. Check vhost config for extUser (most authoritative)
        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
        foreach (['vhost.conf', 'vhconf.conf'] as $configName) {
            $configFile = $vhostPath . '/' . $configName;
            if (file_exists($configFile)) {
                $config = file_get_contents($configFile);
                if (preg_match('/extUser\s+(\S+)/m', $config, $matches)) {
                    return $matches[1];
                }
            }
        }
        
        // 2. Check .ssh directory ownership (if exists, likely correct user)
        if (is_dir($sshDir)) {
            $sshStat = stat($sshDir);
            if ($sshStat) {
                $userInfo = posix_getpwuid($sshStat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                    return $userInfo['name'];
                }
            }
        }
        
        // 3. Check public_html ownership
        if (is_dir($publicHtml)) {
            $publicStat = stat($publicHtml);
            if ($publicStat) {
                $userInfo = posix_getpwuid($publicStat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                    return $userInfo['name'];
                }
            }
        }

        // 4. Check home directory ownership
        if (is_dir($homeDir)) {
            $stat = stat($homeDir);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                    return $userInfo['name'];
                }
            }
        }
        
        // 5. Try to find user by domain name patterns
        $domainClean = str_replace(['.', '-'], ['_', ''], $domain);
        $possibleUsers = [
            $domainClean,
            str_replace('_', '', $domainClean),
            preg_replace('/[^a-z0-9]/i', '', $domain),
            substr($domainClean, 0, 32),
        ];
        
        foreach ($possibleUsers as $possibleUser) {
            $userInfo = @posix_getpwnam($possibleUser);
            if ($userInfo && $userInfo['dir'] === $homeDir) {
                return $possibleUser;
            }
        }
        
        // 6. Look for any user whose home directory matches
        exec("getent passwd | grep ':$homeDir:'", $output);
        if (!empty($output)) {
            $parts = explode(':', $output[0]);
            if (!empty($parts[0]) && $parts[0] !== 'root' && $parts[0] !== 'nobody') {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Generate a secure password
     */
    private function generatePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * Generate database name from domain
     */
    private function generateDbName(string $domain): string
    {
        $name = preg_replace('/[^a-z0-9]/', '_', strtolower(str_replace('.', '_', $domain)));
        return substr($name, 0, 60);
    }

    /**
     * Create database and user
     */
    private function createDatabase(string $dbName, string $dbUser, string $dbPass): array
    {
        try {
            $pdo = $this->getMySqlConnection();
            
            // Check if exists
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$dbName]);
            if ($stmt->fetch()) {
                return ['success' => true, 'exists' => true];
            }
            
            // Create database (sanitize identifiers for DDL)
            $safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
            $safeDbUser = preg_replace('/[^a-zA-Z0-9_]/', '', $dbUser);
            $quotedDbPass = $pdo->quote($dbPass);
            
            $pdo->exec("CREATE DATABASE `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("CREATE USER IF NOT EXISTS '{$safeDbUser}'@'localhost' IDENTIFIED BY {$quotedDbPass}");
            // Ensure password matches even if user already existed (IF NOT EXISTS won't update it)
            $pdo->exec("ALTER USER '{$safeDbUser}'@'localhost' IDENTIFIED BY {$quotedDbPass}");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$safeDbName}`.* TO '{$safeDbUser}'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            
            return ['success' => true, 'created' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get MySQL root connection
     */
    private function getMySqlConnection(): \PDO
    {
        $password = $this->getMySqlPassword();
        return new \PDO(
            "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
            'root',
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Get MySQL root password
     */
    private function getMySqlPassword(): string
    {
        $mycnf = '/root/.my.cnf';
        if (file_exists($mycnf)) {
            $content = file_get_contents($mycnf);
            if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        return '';
    }

    /**
     * Cached panel PDO connection
     */
    private ?\PDO $panelPdo = null;

    /**
     * Get VPS Admin database connection
     * Uses root via unix socket (agent runs as root) - same as getMySqlConnection()
     * but with the panel database pre-selected.
     */
    private function getDatabase(): \PDO
    {
        if ($this->panelPdo !== null) {
            try {
                // Revalidate: MariaDB drops idle connections (wait_timeout)
                // and the agent daemon lives for days.
                $this->panelPdo->query('SELECT 1');
                return $this->panelPdo;
            } catch (\PDOException $e) {
                $this->panelPdo = null;
            }
        }

        // Get database name from agent config or fallback
        $dbName = $this->config['database']['name'] ?? 'devc_vps_dash';

        // Connect as root via unix socket (reliable - agent always runs as root)
        $password = $this->getMySqlPassword();
        $this->panelPdo = new \PDO(
            "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname={$dbName};charset=utf8mb4",
            'root',
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        return $this->panelPdo;
    }

    /**
     * Generate placeholder page
     */
    private function generatePlaceholder(string $domain): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$domain}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #1a1a2e; color: #eee; }
        .container { text-align: center; padding: 2rem; }
        h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        p { color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>{$domain}</h1>
        <p>Application has been uninstalled. Upload your files to get started.</p>
    </div>
</body>
</html>
HTML;
    }
}

