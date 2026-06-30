<?php
/**
 * WordPress Installer
 * 
 * Handles WordPress installation using WP-CLI.
 */

namespace VpsAdmin\Agent\Installers;

class WordPressInstaller
{
    private array $config;
    private $logger;

    public function __construct(array $config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Install WordPress on a site
     */
    public function install(array $params, string $actor): array
    {
        $domain = $params['domain'];
        $docRoot = $params['document_root'];
        $siteUser = $params['site_user'];
        $adminEmail = $params['admin_email'];
        $adminUser = $params['admin_user'];
        $adminPass = $params['admin_password'];
        $siteTitle = $params['site_title'];

        // Check WP-CLI is installed
        exec('which wp 2>/dev/null', $wpPath, $wpCode);
        if ($wpCode !== 0) {
            return $this->error('WP-CLI is not installed. Please install it first with: curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp');
        }

        // Check if directory is empty or has only VPS Admin scaffolding files
        // These are auto-created by VhostAction when a site is provisioned
        $scaffolding = ['.', '..', '.well-known', 'error', 'cgi-bin'];
        $allFiles = array_diff(scandir($docRoot), $scaffolding);
        
        // Filter out known safe files: index.html (and its backups), .htaccess, .user.ini, hidden dotfiles
        $realFiles = array_filter($allFiles, function ($f) {
            // Allow index.html and any backup of it (e.g. index.html.backup.2026-02-19_003747)
            if ($f === 'index.html' || str_starts_with($f, 'index.html.')) return false;
            if ($f === '.htaccess' || $f === '.user.ini') return false;
            return true;
        });
        
        if (!empty($realFiles)) {
            return $this->error('Document root is not empty. Please clear it before installing WordPress. Found: ' . implode(', ', array_slice(array_values($realFiles), 0, 10)));
        }

        // Clean up all scaffolding before install
        foreach ($allFiles as $f) {
            $path = $docRoot . '/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        // Remove error page directory (WordPress has its own error handling)
        $errorDir = $docRoot . '/error';
        if (is_dir($errorDir)) {
            array_map('unlink', glob($errorDir . '/*'));
            @rmdir($errorDir);
        }

        // Create or get database
        $dbName = $params['db_name'] ?? $this->generateDbName($domain);
        $dbUser = $params['db_user'] ?? $dbName;
        $dbPass = $params['db_password'] ?? $this->generatePassword();
        
        $dbResult = $this->createDatabase($dbName, $dbUser, $dbPass);
        if (!$dbResult['success']) {
            return $this->error('Failed to create database: ' . ($dbResult['error'] ?? 'Unknown error'));
        }

        // Download WordPress
        $result = $this->execAsUser($siteUser, "cd {$docRoot} && wp core download --locale=en_US");
        if (!$result['success']) {
            $this->cleanupFailedInstall($docRoot, $siteUser);
            return $this->error('Failed to download WordPress: ' . $result['output']);
        }

        // Create wp-config.php (use escapeshellarg for all user-supplied values)
        $escDbName = escapeshellarg($dbName);
        $escDbUser = escapeshellarg($dbUser);
        $escDbPass = escapeshellarg($dbPass);
        
        $result = $this->execAsUser($siteUser, 
            "cd {$docRoot} && wp config create " .
            "--dbname={$escDbName} " .
            "--dbuser={$escDbUser} " .
            "--dbpass={$escDbPass} " .
            "--dbhost='localhost' " .
            "--dbcharset='utf8mb4'"
        );
        if (!$result['success']) {
            $this->cleanupFailedInstall($docRoot, $siteUser);
            return $this->error('Failed to create wp-config.php: ' . $result['output']);
        }

        // Install WordPress
        $escapedTitle = escapeshellarg($siteTitle);
        $escapedPass = escapeshellarg($adminPass);
        $escapedUser = escapeshellarg($adminUser);
        $escapedEmail = escapeshellarg($adminEmail);
        
        $result = $this->execAsUser($siteUser,
            "cd {$docRoot} && wp core install " .
            "--url='https://{$domain}' " .
            "--title={$escapedTitle} " .
            "--admin_user={$escapedUser} " .
            "--admin_password={$escapedPass} " .
            "--admin_email={$escapedEmail} " .
            "--skip-email"
        );
        if (!$result['success']) {
            $this->cleanupFailedInstall($docRoot, $siteUser);
            return $this->error('Failed to install WordPress: ' . $result['output']);
        }

        // Set proper permissions
        $this->setPermissions($docRoot, $siteUser);

        // Get installed version
        $version = 'latest';
        $versionResult = $this->execAsUser($siteUser, "cd {$docRoot} && wp core version");
        if ($versionResult['success']) {
            $version = trim($versionResult['output']);
        }

        // Install/activate some recommended plugins (optional)
        // $this->execAsUser($siteUser, "cd {$docRoot} && wp plugin install wordfence --activate");

        // Set permalinks
        $this->execAsUser($siteUser, "cd {$docRoot} && wp rewrite structure '/%postname%/'");

        // Create .htaccess for pretty permalinks
        $htaccess = <<<HTACCESS
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\\.php\$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTACCESS;
        file_put_contents($docRoot . '/.htaccess', $htaccess);
        chown($docRoot . '/.htaccess', $siteUser);
        chgrp($docRoot . '/.htaccess', $siteUser);
        chmod($docRoot . '/.htaccess', 0644);

        // Reload OLS to apply any config changes
        $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['reload']);

        return $this->success([
            'domain' => $domain,
            'app' => 'wordpress',
            'version' => $version,
            'install_path' => $docRoot,
            'admin_url' => "https://{$domain}/wp-admin/",
            'admin_user' => $adminUser,
            'admin_password' => $adminPass,
            'database' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPass,
        ], "WordPress {$version} installed successfully on {$domain}");
    }

    /**
     * Clean up document root after a failed WordPress install attempt.
     * Removes all WordPress files so the next attempt starts clean.
     */
    private function cleanupFailedInstall(string $docRoot, string $siteUser): void
    {
        $this->logger->warning("Cleaning up failed WordPress install at {$docRoot}");
        
        // Remove all files except .well-known (needed for SSL/ACME)
        $items = @scandir($docRoot);
        if (!$items) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.well-known') continue;
            $path = $docRoot . '/' . $item;
            if (is_dir($path)) {
                $this->execCommand('rm', ['-rf', $path]);
            } else {
                @unlink($path);
            }
        }
        
        // Restore ownership
        if (posix_getpwnam($siteUser)) {
            $this->execCommand('chown', ['-R', "{$siteUser}:{$siteUser}", $docRoot]);
        }
    }

    /**
     * Update WordPress installation
     */
    public function update(string $domain, string $docRoot, string $siteUser): array
    {
        // Backup current installation
        $backupDir = $this->config['paths']['backups'] . '/wordpress/' . $domain . '_' . date('Y-m-d_H-i-s');
        if (!is_dir(dirname($backupDir))) {
            mkdir(dirname($backupDir), 0755, true);
        }
        
        // Export database
        $this->execAsUser($siteUser, "cd {$docRoot} && wp db export {$backupDir}.sql");
        
        // Update WordPress core
        $result = $this->execAsUser($siteUser, "cd {$docRoot} && wp core update");
        if (!$result['success']) {
            return $this->error('Failed to update WordPress core: ' . $result['output']);
        }

        // Update database
        $this->execAsUser($siteUser, "cd {$docRoot} && wp core update-db");

        // Update plugins
        $this->execAsUser($siteUser, "cd {$docRoot} && wp plugin update --all");

        // Update themes
        $this->execAsUser($siteUser, "cd {$docRoot} && wp theme update --all");

        // Get new version
        $version = 'latest';
        $versionResult = $this->execAsUser($siteUser, "cd {$docRoot} && wp core version");
        if ($versionResult['success']) {
            $version = trim($versionResult['output']);
        }

        // Clear caches
        $this->execAsUser($siteUser, "cd {$docRoot} && wp cache flush");

        return $this->success([
            'domain' => $domain,
            'version' => $version,
            'backup' => $backupDir . '.sql',
        ], "WordPress updated to version {$version}");
    }

    /**
     * Execute command as site user (or directly if user is nobody/invalid)
     */
    private function execAsUser(string $user, string $command): array
    {
        // If user is 'nobody' or doesn't exist, run directly as root
        if ($user === 'nobody' || $user === 'root') {
            // Add --allow-root flag for wp-cli commands when running as root
            $rootCommand = $this->addAllowRootFlag($command);
            exec($rootCommand . ' 2>&1', $output, $exitCode);
            return [
                'success' => $exitCode === 0,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ];
        }
        
        // Check if user exists
        exec("id {$user} 2>/dev/null", $checkOutput, $checkCode);
        if ($checkCode !== 0) {
            // User doesn't exist, run as root with --allow-root
            $rootCommand = $this->addAllowRootFlag($command);
            exec($rootCommand . ' 2>&1', $output, $exitCode);
            return [
                'success' => $exitCode === 0,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ];
        }
        
        // Run as the specified user
        $escapedCommand = escapeshellarg($command);
        $fullCommand = "su - {$user} -s /bin/bash -c {$escapedCommand} 2>&1";
        
        exec($fullCommand, $output, $exitCode);
        
        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Add --allow-root flag to wp-cli commands
     */
    private function addAllowRootFlag(string $command): string
    {
        // Check if this is a wp command
        if (preg_match('/\bwp\s+(core|config|db|plugin|theme|rewrite|cache|option|user)\b/', $command)) {
            // Add --allow-root if not already present
            if (strpos($command, '--allow-root') === false) {
                // Insert --allow-root after the wp subcommand
                $command = preg_replace('/(\bwp\s+\w+)/', '$1 --allow-root', $command);
            }
        }
        return $command;
    }

    /**
     * Execute command
     */
    private function execCommand(string $cmd, array $args = []): array
    {
        $command = $cmd;
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        
        exec($command . ' 2>&1', $output, $exitCode);
        
        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Set WordPress directory permissions
     */
    private function setPermissions(string $docRoot, string $user): void
    {
        // Set ownership
        exec("chown -R {$user}:{$user} " . escapeshellarg($docRoot));
        
        // Set directory permissions
        exec("find " . escapeshellarg($docRoot) . " -type d -exec chmod 755 {} \\;");
        
        // Set file permissions
        exec("find " . escapeshellarg($docRoot) . " -type f -exec chmod 644 {} \\;");
        
        // Make wp-content writable
        $wpContent = $docRoot . '/wp-content';
        if (is_dir($wpContent)) {
            exec("chmod -R 755 " . escapeshellarg($wpContent));
        }
    }

    /**
     * Create database (or use existing one)
     */
    private function createDatabase(string $dbName, string $dbUser, string $dbPass): array
    {
        try {
            $password = $this->getMySqlPassword();
            $pdo = new \PDO(
                "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                'root',
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            
            // Check if database exists
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$dbName]);
            $dbExists = (bool) $stmt->fetch();
            
            if (!$dbExists) {
                // Create database if it doesn't exist
                $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            
            // Check if user exists
            $stmt = $pdo->prepare("SELECT User FROM mysql.user WHERE User = ? AND Host = 'localhost'");
            $stmt->execute([$dbUser]);
            $userExists = (bool) $stmt->fetch();
            
            $safeDbUser = preg_replace('/[^a-zA-Z0-9_]/', '', $dbUser);
            $quotedDbPass = $pdo->quote($dbPass);
            
            if (!$userExists) {
                $pdo->exec("CREATE USER '{$safeDbUser}'@'localhost' IDENTIFIED BY {$quotedDbPass}");
            } else {
                // User already exists (e.g., created during site setup) — update password
                // so it matches what we'll put in wp-config.php
                $pdo->exec("ALTER USER '{$safeDbUser}'@'localhost' IDENTIFIED BY {$quotedDbPass}");
            }
            
            // Always grant privileges (idempotent)
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$safeDbUser}'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            
            return ['success' => true, 'created' => !$dbExists];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
     * Generate database name from domain
     */
    private function generateDbName(string $domain): string
    {
        $name = preg_replace('/[^a-z0-9]/', '_', strtolower(str_replace('.', '_', $domain)));
        return 'wp_' . substr($name, 0, 57);
    }

    /**
     * Generate a secure password
     */
    private function generatePassword(int $length = 20): string
    {
        // Avoid shell-problematic chars (!$#&* etc.) — these cause issues in
        // nested shell escaping (su -c, wp-cli subcommands). Stick to safe specials.
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-+=@';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * Return success response
     */
    private function success(array $data, string $message = ''): array
    {
        return [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];
    }

    /**
     * Return error response
     */
    private function error(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }
}

