<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

/**
 * Dovecot IMAP/POP3 server configuration management
 */
class DovecotAction extends BaseAction
{
    protected array $allowedActions = ['status', 'settings', 'updateSettings', 'restart', 'connections'];

    public function getNamespace(): string
    {
        return 'dovecot';
    }

    public function getMethods(): array
    {
        return ['status', 'settings', 'updateSettings', 'restart', 'connections', 'rawConfig', 'saveRawConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updateSettings']);
    }

    /**
     * Get Dovecot status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        // Check if running
        exec('systemctl is-active dovecot 2>&1', $activeOutput, $activeCode);
        $running = ($activeCode === 0);
        
        // Get version
        $version = null;
        exec('dovecot --version 2>&1', $versionOutput, $versionCode);
        if ($versionCode === 0 && !empty($versionOutput)) {
            if (preg_match('/(\d+\.\d+\.\d+)/', $versionOutput[0], $matches)) {
                $version = $matches[1];
            }
        }
        
        return $this->success([
            'running' => $running,
            'version' => $version,
        ]);
    }

    /**
     * Get Dovecot settings
     */
    protected function actionSettings(array $params, string $actor): array
    {
        $settings = [];
        
        $settingKeys = [
            // General
            'protocols', 'listen', 'base_dir', 'login_greeting',
            // Mail location
            'mail_location', 'mail_home', 'mail_uid', 'mail_gid',
            'mail_privileged_group', 'first_valid_uid', 'last_valid_uid',
            // SSL/TLS
            'ssl', 'ssl_cert', 'ssl_key', 'ssl_min_protocol', 'ssl_cipher_list',
            'ssl_prefer_server_ciphers', 'ssl_dh',
            // Authentication
            'auth_mechanisms', 'auth_username_format', 'auth_verbose',
            'auth_verbose_passwords', 'auth_debug', 'auth_debug_passwords',
            'disable_plaintext_auth', 'auth_ssl_require_client_cert',
            'auth_ssl_username_from_cert',
            // Logging
            'log_path', 'info_log_path', 'debug_log_path', 'log_timestamp',
            'mail_debug', 'verbose_ssl',
            // Limits
            'mail_max_userip_connections', 'default_process_limit',
            'default_client_limit', 'default_vsz_limit',
            // Plugins
            'mail_plugins',
            // LMTP
            'lmtp_save_to_detail_mailbox', 'recipient_delimiter',
            'postmaster_address',
        ];
        
        foreach ($settingKeys as $key) {
            exec("doveconf -h {$key} 2>&1", $output, $code);
            if ($code === 0 && !empty($output)) {
                $value = trim($output[0]);
                // Handle Dovecot's special < prefix for file paths
                if (strpos($value, '<') === 0) {
                    $value = substr($value, 1);
                }
                $settings[$key] = $value;
            }
            $output = [];
        }
        
        // Get protocol-specific plugins
        $protocolPlugins = ['imap', 'pop3', 'lmtp'];
        foreach ($protocolPlugins as $proto) {
            exec("doveconf -h 'protocol {$proto} { mail_plugins }' 2>&1", $output, $code);
            if ($code === 0 && !empty($output)) {
                $settings["protocol_{$proto}_mail_plugins"] = trim($output[0]);
            }
            $output = [];
        }
        
        // Get plugin settings
        $pluginSettings = [
            'quota', 'quota_rule', 'quota_rule2', 'quota_warning', 'quota_grace',
            'sieve', 'sieve_global_dir', 'sieve_before', 'sieve_after',
            'zlib_save', 'zlib_save_level',
        ];
        foreach ($pluginSettings as $key) {
            exec("doveconf -h 'plugin { {$key} }' 2>&1", $output, $code);
            if ($code === 0 && !empty($output) && trim($output[0]) !== '') {
                $settings["plugin_{$key}"] = trim($output[0]);
            }
            $output = [];
        }
        
        // Get protocols as array
        $protocols = [];
        if (!empty($settings['protocols'])) {
            $protocols = preg_split('/\s+/', $settings['protocols']);
        }
        
        // Get connections
        $connections = $this->getConnections();
        
        return $this->success([
            'settings' => $settings,
            'protocols' => $protocols,
            'connections' => $connections,
        ]);
    }

    /**
     * Get active connections
     */
    private function getConnections(): array
    {
        $connections = [];
        exec('doveadm who 2>&1', $output, $code);
        
        if ($code === 0) {
            foreach ($output as $i => $line) {
                if ($i === 0) continue; // Skip header
                
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 4) {
                    $connections[] = [
                        'user' => $parts[0],
                        'protocol' => strtoupper($parts[2] ?? 'imap'),
                        'ip' => $parts[1] ?? '',
                        'connected' => $parts[3] ?? '',
                        'id' => md5($line),
                    ];
                }
            }
        }
        
        return $connections;
    }

    /**
     * Update Dovecot settings
     */
    protected function actionUpdateSettings(array $params, string $actor): array
    {
        if (!isset($params['settings']) || !is_array($params['settings'])) {
            return $this->error('Settings array is required');
        }
        
        // Dovecot config locations
        $configFiles = [
            '/etc/dovecot/dovecot.conf',
            '/etc/dovecot/conf.d/10-mail.conf',
            '/etc/dovecot/conf.d/10-master.conf',
            '/etc/dovecot/conf.d/10-auth.conf',
            '/etc/dovecot/conf.d/10-ssl.conf',
            '/etc/dovecot/conf.d/10-logging.conf',
        ];
        
        // Find main config
        $mainConfig = '/etc/dovecot/dovecot.conf';
        if (!file_exists($mainConfig)) {
            return $this->error('Dovecot configuration file not found');
        }
        
        // Backup
        $backupPath = $this->backupFile($mainConfig, 'dovecot-settings', $actor);
        
        $newSettings = $params['settings'];
        
        // Map settings to config files (or main if conf.d doesn't exist)
        $settingToFile = [
            // General - main config
            'protocols' => '/etc/dovecot/dovecot.conf',
            'listen' => '/etc/dovecot/dovecot.conf',
            'login_greeting' => '/etc/dovecot/dovecot.conf',
            // Mail settings
            'mail_location' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_home' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_uid' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_gid' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_privileged_group' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_plugins' => '/etc/dovecot/conf.d/10-mail.conf',
            'mail_max_userip_connections' => '/etc/dovecot/conf.d/10-mail.conf',
            // Master settings
            'default_process_limit' => '/etc/dovecot/conf.d/10-master.conf',
            'default_client_limit' => '/etc/dovecot/conf.d/10-master.conf',
            'default_vsz_limit' => '/etc/dovecot/conf.d/10-master.conf',
            // Auth settings
            'auth_mechanisms' => '/etc/dovecot/conf.d/10-auth.conf',
            'auth_username_format' => '/etc/dovecot/conf.d/10-auth.conf',
            'auth_verbose' => '/etc/dovecot/conf.d/10-auth.conf',
            'auth_verbose_passwords' => '/etc/dovecot/conf.d/10-auth.conf',
            'auth_debug' => '/etc/dovecot/conf.d/10-auth.conf',
            // SSL settings
            'ssl' => '/etc/dovecot/conf.d/10-ssl.conf',
            'ssl_cert' => '/etc/dovecot/conf.d/10-ssl.conf',
            'ssl_key' => '/etc/dovecot/conf.d/10-ssl.conf',
            'ssl_min_protocol' => '/etc/dovecot/conf.d/10-ssl.conf',
            'ssl_cipher_list' => '/etc/dovecot/conf.d/10-ssl.conf',
            'ssl_prefer_server_ciphers' => '/etc/dovecot/conf.d/10-ssl.conf',
            'verbose_ssl' => '/etc/dovecot/conf.d/10-ssl.conf',
            // Logging settings
            'log_path' => '/etc/dovecot/conf.d/10-logging.conf',
            'info_log_path' => '/etc/dovecot/conf.d/10-logging.conf',
            'debug_log_path' => '/etc/dovecot/conf.d/10-logging.conf',
            'log_timestamp' => '/etc/dovecot/conf.d/10-logging.conf',
            'mail_debug' => '/etc/dovecot/conf.d/10-logging.conf',
            // LMTP
            'lmtp_save_to_detail_mailbox' => '/etc/dovecot/conf.d/20-lmtp.conf',
            'recipient_delimiter' => '/etc/dovecot/conf.d/20-lmtp.conf',
            'postmaster_address' => '/etc/dovecot/conf.d/20-lmtp.conf',
        ];
        
        $allowedSettings = array_keys($settingToFile);
        
        // For this server, everything is in main config file
        $mainConfig = '/etc/dovecot/dovecot.conf';
        
        foreach ($newSettings as $key => $value) {
            if (!in_array($key, $allowedSettings)) {
                continue;
            }
            
            // Try specific config file first, fall back to main config
            $configFile = $settingToFile[$key] ?? $mainConfig;
            if (!file_exists($configFile)) {
                $configFile = $mainConfig;
            }
            
            $content = file_get_contents($configFile);
            $value = trim($value);
            
            // Handle ssl_cert and ssl_key which need < prefix
            if (in_array($key, ['ssl_cert', 'ssl_key']) && strpos($value, '<') !== 0) {
                $value = '<' . $value;
            }
            
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/m', $content)) {
                $content = preg_replace(
                    '/^\s*#?\s*' . preg_quote($key, '/') . '\s*=.*$/m',
                    $key . ' = ' . $value,
                    $content
                );
            } else {
                $content .= "\n{$key} = {$value}";
            }
            
            file_put_contents($configFile, $content);
        }
        
        return $this->success([
            'backup' => $backupPath,
            'message' => 'Dovecot settings updated. Restart Dovecot to apply.',
        ], "Dovecot settings updated by {$actor}");
    }

    /**
     * Restart Dovecot
     */
    protected function actionRestart(array $params, string $actor): array
    {
        exec('systemctl restart dovecot 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            return $this->error('Failed to restart Dovecot: ' . implode("\n", $output));
        }
        
        return $this->success([
            'message' => 'Dovecot restarted successfully',
        ], "Dovecot restarted by {$actor}");
    }

    /**
     * Get active connections
     */
    protected function actionConnections(array $params, string $actor): array
    {
        return $this->success([
            'connections' => $this->getConnections(),
        ]);
    }

    /**
     * Allowed Dovecot config files
     */
    private function getAllowedDovecotFiles(): array
    {
        return [
            '/etc/dovecot/dovecot.conf',
            '/etc/dovecot/dovecot-sql.conf.ext',
            '/etc/dovecot/dovecot-ldap.conf.ext',
            '/etc/dovecot/conf.d/10-auth.conf',
            '/etc/dovecot/conf.d/10-director.conf',
            '/etc/dovecot/conf.d/10-logging.conf',
            '/etc/dovecot/conf.d/10-mail.conf',
            '/etc/dovecot/conf.d/10-master.conf',
            '/etc/dovecot/conf.d/10-ssl.conf',
            '/etc/dovecot/conf.d/15-lda.conf',
            '/etc/dovecot/conf.d/15-mailboxes.conf',
            '/etc/dovecot/conf.d/20-imap.conf',
            '/etc/dovecot/conf.d/20-lmtp.conf',
            '/etc/dovecot/conf.d/20-managesieve.conf',
            '/etc/dovecot/conf.d/20-pop3.conf',
            '/etc/dovecot/conf.d/90-acl.conf',
            '/etc/dovecot/conf.d/90-plugin.conf',
            '/etc/dovecot/conf.d/90-quota.conf',
            '/etc/dovecot/conf.d/90-sieve.conf',
            '/etc/dovecot/conf.d/90-sieve-extprograms.conf',
            '/etc/dovecot/conf.d/auth-sql.conf.ext',
            '/etc/dovecot/conf.d/auth-ldap.conf.ext',
            '/etc/dovecot/conf.d/auth-passwdfile.conf.ext',
            '/etc/dovecot/conf.d/auth-system.conf.ext',
        ];
    }

    /**
     * Validate the config file path
     */
    private function validateDovecotFile(string $file): bool
    {
        // Must be in /etc/dovecot/
        if (strpos($file, '/etc/dovecot/') !== 0) {
            return false;
        }
        
        // No path traversal
        if (str_contains($file, '..')) {
            return false;
        }
        
        // Check against allowed list
        $allowed = $this->getAllowedDovecotFiles();
        if (in_array($file, $allowed)) {
            return true;
        }
        
        // Allow files in /etc/dovecot/ or /etc/dovecot/conf.d/
        $dir = dirname($file);
        if ($dir === '/etc/dovecot' || $dir === '/etc/dovecot/conf.d') {
            return true;
        }
        
        return false;
    }

    /**
     * Get raw Dovecot config file
     */
    protected function actionRawConfig(array $params, string $actor): array
    {
        $configPath = $params['file'] ?? '/etc/dovecot/dovecot.conf';
        
        // Validate file path
        if (!$this->validateDovecotFile($configPath)) {
            return $this->error('Invalid config file path');
        }
        
        if (!file_exists($configPath)) {
            // For optional files, return empty content if file doesn't exist
            if ($configPath !== '/etc/dovecot/dovecot.conf') {
                return $this->success([
                    'path' => $configPath,
                    'content' => '',
                    'exists' => false,
                ]);
            }
            return $this->error('Dovecot config file not found');
        }
        
        return $this->success([
            'path' => $configPath,
            'content' => file_get_contents($configPath),
            'exists' => true,
        ]);
    }

    /**
     * Save raw Dovecot config file
     */
    protected function actionSaveRawConfig(array $params, string $actor): array
    {
        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }
        
        $configPath = $params['file'] ?? '/etc/dovecot/dovecot.conf';
        
        // Validate file path
        if (!$this->validateDovecotFile($configPath)) {
            return $this->error('Invalid config file path');
        }
        
        // Create backup directory
        $backupDir = '/var/www/vps-admin/backups/dovecot';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Create a safe backup filename
        $filename = str_replace('/', '_', ltrim($configPath, '/'));
        $backupPath = $backupDir . '/' . $filename . '.' . date('Y-m-d_H-i-s') . '.bak';
        
        // Backup existing file if it exists
        if (file_exists($configPath)) {
            copy($configPath, $backupPath);
            
            // Get current file info to preserve permissions
            $fileOwner = fileowner($configPath);
            $fileGroup = filegroup($configPath);
            $filePerms = fileperms($configPath) & 0777;
        } else {
            // Ensure parent directory exists
            $dir = dirname($configPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // Default permissions for new files
            $fileOwner = 0; // root
            $fileGroup = 0; // root
            $filePerms = 0644;
        }
        
        // Clean up Windows line endings
        $content = str_replace("\r\n", "\n", $params['content']);
        $content = str_replace("\r", "", $content);
        
        // Write the config
        if (file_put_contents($configPath, $content) === false) {
            return $this->error('Failed to write configuration file');
        }
        
        // Set ownership and permissions
        chown($configPath, $fileOwner);
        chgrp($configPath, $fileGroup);
        chmod($configPath, $filePerms);
        
        return $this->success([
            'path' => $configPath,
            'backup' => file_exists($backupPath) ? $backupPath : null,
            'message' => 'Dovecot configuration saved',
        ], "Dovecot config " . basename($configPath) . " saved by {$actor}");
    }
}
