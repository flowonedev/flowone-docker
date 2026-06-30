<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

/**
 * PHP configuration management
 */
class PhpAction extends BaseAction
{
    protected array $allowedActions = ['versions', 'settings', 'updateSettings', 'restart', 'rawConfig', 'saveRawConfig'];

    public function getNamespace(): string
    {
        return 'php';
    }

    public function getMethods(): array
    {
        return ['versions', 'settings', 'updateSettings', 'restart', 'rawConfig', 'saveRawConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updateSettings']);
    }

    /**
     * Get installed PHP versions
     */
    protected function actionVersions(array $params, string $actor): array
    {
        $versions = [];
        $lswsPath = '/usr/local/lsws';
        
        // Find all lsphp installations
        $dirs = glob($lswsPath . '/lsphp*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $handler = basename($dir);
            if (preg_match('/lsphp(\d)(\d)/', $handler, $matches)) {
                $version = $matches[1] . '.' . $matches[2];
                $phpBin = $dir . '/bin/php';
                $iniPath = $dir . '/etc/php/' . $version . '/litespeed/php.ini';
                
                $versions[] = [
                    'version' => $version,
                    'handler' => $handler,
                    'path' => $dir,
                    'binary' => $phpBin,
                    'active' => file_exists($phpBin) && file_exists($iniPath),
                    'ini_path' => $iniPath,
                ];
            }
        }
        
        // Sort by version
        usort($versions, fn($a, $b) => version_compare($b['version'], $a['version']));
        
        return $this->success(['versions' => $versions]);
    }

    /**
     * Get PHP settings for a specific version
     */
    protected function actionSettings(array $params, string $actor): array
    {
        if (!isset($params['version'])) {
            return $this->error('PHP version is required');
        }
        
        $version = $params['version'];
        $handler = 'lsphp' . str_replace('.', '', $version);
        $phpBin = '/usr/local/lsws/' . $handler . '/bin/php';
        $iniPath = '/usr/local/lsws/' . $handler . '/etc/php/' . $version . '/litespeed/php.ini';
        
        if (!file_exists($iniPath)) {
            return $this->error("PHP {$version} ini file not found at {$iniPath}");
        }
        
        $iniContent = file_get_contents($iniPath);
        $settings = [];
        
        // Parse common settings
        $settingKeys = [
            // Core settings
            'memory_limit', 'max_execution_time', 'max_input_time',
            'upload_max_filesize', 'post_max_size', 'max_input_vars',
            'max_file_uploads', 'date.timezone',
            // Error handling
            'display_errors', 'display_startup_errors', 'log_errors',
            'error_reporting', 'error_log',
            // Security settings
            'expose_php', 'allow_url_fopen', 'allow_url_include',
            'open_basedir', 'disable_functions',
            // OPCache settings
            'opcache.enable', 'opcache.enable_cli', 'opcache.memory_consumption',
            'opcache.interned_strings_buffer', 'opcache.max_accelerated_files',
            'opcache.revalidate_freq', 'opcache.validate_timestamps',
            'opcache.save_comments', 'opcache.fast_shutdown',
            // Performance
            'realpath_cache_size', 'realpath_cache_ttl', 'output_buffering',
            // Session settings
            'session.gc_maxlifetime', 'session.cookie_lifetime',
            'session.save_handler', 'session.save_path',
            'session.cookie_secure', 'session.cookie_httponly', 'session.cookie_samesite',
            // Redis session settings
            'redis.session.locking_enabled',
        ];
        
        foreach ($settingKeys as $key) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.+)$/m', $iniContent, $matches)) {
                $settings[$key] = trim($matches[1]);
            }
        }
        
        // Get loaded extensions using the PHP binary
        $loadedExtensions = [];
        if (file_exists($phpBin)) {
            exec($phpBin . ' -m 2>/dev/null', $extOutput, $extExitCode);
            if ($extExitCode === 0) {
                $loadedExtensions = array_map('strtolower', array_filter($extOutput, fn($line) => !empty(trim($line)) && !str_starts_with($line, '[')));
            }
        }
        
        // Check for common extensions
        $extensionsToCheck = ['redis', 'memcached', 'memcache', 'imagick', 'gd', 'curl', 'zip', 'mbstring', 'xml', 'json', 'mysqli', 'pdo_mysql', 'openssl', 'intl', 'bcmath', 'soap', 'gmp', 'exif', 'fileinfo', 'iconv', 'sodium'];
        
        foreach ($extensionsToCheck as $ext) {
            $settings['extension_' . $ext] = in_array(strtolower($ext), $loadedExtensions);
        }
        
        return $this->success([
            'version' => $version,
            'ini_path' => $iniPath,
            'settings' => $settings,
            'loaded_extensions' => $loadedExtensions,
        ]);
    }

    /**
     * Update PHP settings for a specific version
     */
    protected function actionUpdateSettings(array $params, string $actor): array
    {
        if (!isset($params['version'])) {
            return $this->error('PHP version is required');
        }
        
        if (!isset($params['settings']) || !is_array($params['settings'])) {
            return $this->error('Settings array is required');
        }
        
        $version = $params['version'];
        $handler = 'lsphp' . str_replace('.', '', $version);
        $iniPath = '/usr/local/lsws/' . $handler . '/etc/php/' . $version . '/litespeed/php.ini';
        
        if (!file_exists($iniPath)) {
            return $this->error("PHP {$version} ini file not found");
        }
        
        // Backup current file
        $backupPath = $this->backupFile($iniPath, 'php-settings', $actor);
        
        $iniContent = file_get_contents($iniPath);
        $newSettings = $params['settings'];
        
        // Allowed settings to modify
        $allowedSettings = [
            // Core settings
            'memory_limit', 'max_execution_time', 'max_input_time',
            'upload_max_filesize', 'post_max_size', 'max_input_vars',
            'max_file_uploads', 'date.timezone',
            // Error handling
            'display_errors', 'display_startup_errors', 'log_errors',
            'error_reporting', 'error_log',
            // Security settings
            'expose_php', 'allow_url_fopen', 'allow_url_include',
            'open_basedir', 'disable_functions',
            // OPCache settings
            'opcache.enable', 'opcache.enable_cli', 'opcache.memory_consumption',
            'opcache.interned_strings_buffer', 'opcache.max_accelerated_files',
            'opcache.revalidate_freq', 'opcache.validate_timestamps',
            'opcache.save_comments', 'opcache.fast_shutdown',
            // Performance
            'realpath_cache_size', 'realpath_cache_ttl', 'output_buffering',
            // Session settings
            'session.gc_maxlifetime', 'session.cookie_lifetime',
            'session.save_handler', 'session.save_path',
            'session.cookie_secure', 'session.cookie_httponly', 'session.cookie_samesite',
            'redis.session.locking_enabled',
        ];
        
        foreach ($newSettings as $key => $value) {
            if (!in_array($key, $allowedSettings)) {
                continue;
            }
            
            // Sanitize value
            $value = trim($value);
            
            // Update or add setting
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/m', $iniContent)) {
                // Update existing
                $iniContent = preg_replace(
                    '/^\s*' . preg_quote($key, '/') . '\s*=.*$/m',
                    $key . ' = ' . $value,
                    $iniContent
                );
            } else {
                // Add new
                $iniContent .= "\n{$key} = {$value}";
            }
        }
        
        file_put_contents($iniPath, $iniContent);
        
        return $this->success([
            'version' => $version,
            'backup' => $backupPath,
            'message' => "PHP {$version} settings updated",
        ], "PHP {$version} settings updated by {$actor}");
    }

    /**
     * Restart PHP for a specific version (restart lsws)
     */
    protected function actionRestart(array $params, string $actor): array
    {
        // LiteSpeed manages PHP processes, so we restart lsws
        exec('systemctl restart lsws 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            return $this->error('Failed to restart LiteSpeed: ' . implode("\n", $output));
        }
        
        return $this->success([
            'message' => 'LiteSpeed restarted successfully',
        ], "LiteSpeed restarted by {$actor}");
    }

    /**
     * Get raw PHP config file content
     */
    protected function actionRawConfig(array $params, string $actor): array
    {
        if (!isset($params['version'])) {
            return $this->error('PHP version is required');
        }
        
        $version = $params['version'];
        $handler = 'lsphp' . str_replace('.', '', $version);
        
        // Use custom file path if provided, otherwise default to litespeed/php.ini
        if (!empty($params['file'])) {
            $iniPath = $params['file'];
            // Validate the path is within the PHP directory for security
            $allowedBase = '/usr/local/lsws/' . $handler . '/etc/php/';
            // Simple string check - path must start with allowed base
            if (strpos($iniPath, $allowedBase) !== 0) {
                return $this->error('Invalid file path - must be within PHP configuration directory');
            }
            // Prevent directory traversal
            if (strpos($iniPath, '..') !== false) {
                return $this->error('Invalid file path - directory traversal not allowed');
            }
        } else {
            $iniPath = '/usr/local/lsws/' . $handler . '/etc/php/' . $version . '/litespeed/php.ini';
        }
        
        if (!file_exists($iniPath)) {
            return $this->error("PHP config file not found at {$iniPath}");
        }
        
        return $this->success([
            'version' => $version,
            'path' => $iniPath,
            'content' => file_get_contents($iniPath),
        ]);
    }

    /**
     * Save raw PHP config file content
     */
    protected function actionSaveRawConfig(array $params, string $actor): array
    {
        if (!isset($params['version'])) {
            return $this->error('PHP version is required');
        }
        
        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }
        
        $version = $params['version'];
        $content = $params['content'];
        $handler = 'lsphp' . str_replace('.', '', $version);
        
        // Use custom file path if provided, otherwise default to litespeed/php.ini
        if (!empty($params['file'])) {
            $iniPath = $params['file'];
            // Validate the path is within the PHP directory for security
            $allowedBase = '/usr/local/lsws/' . $handler . '/etc/php/';
            // Simple string check - path must start with allowed base
            if (strpos($iniPath, $allowedBase) !== 0) {
                return $this->error('Invalid file path - must be within PHP configuration directory');
            }
            // Prevent directory traversal
            if (strpos($iniPath, '..') !== false) {
                return $this->error('Invalid file path - directory traversal not allowed');
            }
        } else {
            $iniPath = '/usr/local/lsws/' . $handler . '/etc/php/' . $version . '/litespeed/php.ini';
        }
        
        if (!file_exists($iniPath)) {
            return $this->error("PHP config file not found at {$iniPath}");
        }
        
        // Create backup
        $backupDir = '/var/www/vps-admin/backups/php';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $fileName = basename($iniPath);
        $backupPath = $backupDir . '/' . $fileName . '.' . date('Y-m-d_H-i-s') . '.bak';
        copy($iniPath, $backupPath);
        
        // Get current file info
        $fileOwner = fileowner($iniPath);
        $fileGroup = filegroup($iniPath);
        $filePerms = fileperms($iniPath) & 0777;
        
        // Clean up Windows line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "", $content);
        
        // Write the new config
        if (file_put_contents($iniPath, $content) === false) {
            return $this->error('Failed to write configuration file');
        }
        
        // Restore original ownership and permissions
        chown($iniPath, $fileOwner);
        chgrp($iniPath, $fileGroup);
        chmod($iniPath, $filePerms);
        
        return $this->success([
            'version' => $version,
            'path' => $iniPath,
            'backup' => $backupPath,
            'message' => "PHP configuration saved ({$fileName})",
        ], "PHP {$version} configuration ({$fileName}) saved by {$actor}");
    }
}
