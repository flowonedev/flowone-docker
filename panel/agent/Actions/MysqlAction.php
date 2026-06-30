<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

/**
 * MySQL/MariaDB configuration management
 */
class MysqlAction extends BaseAction
{
    protected array $allowedActions = ['status', 'settings', 'updateSettings', 'restart', 'variables'];

    public function getNamespace(): string
    {
        return 'mysql';
    }

    public function getMethods(): array
    {
        return ['status', 'settings', 'updateSettings', 'restart', 'variables', 'rawConfig', 'saveRawConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updateSettings']);
    }

    /**
     * Get MySQL status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        // Check if running
        exec('systemctl is-active mariadb 2>&1 || systemctl is-active mysql 2>&1', $activeOutput, $activeCode);
        $running = ($activeCode === 0);
        
        // Get version
        $version = null;
        if ($running) {
            exec('mysql --defaults-file=/root/.my.cnf -V 2>&1', $versionOutput, $versionCode);
            if ($versionCode === 0 && !empty($versionOutput)) {
                if (preg_match('/(\d+\.\d+\.\d+)/', $versionOutput[0], $matches)) {
                    $version = $matches[1];
                }
            }
        }
        
        // Get uptime
        $uptime = null;
        if ($running) {
            exec("mysql --defaults-file=/root/.my.cnf -e \"SHOW GLOBAL STATUS LIKE 'Uptime';\" 2>&1", $uptimeOutput, $uptimeCode);
            if ($uptimeCode === 0 && count($uptimeOutput) > 1) {
                $parts = preg_split('/\s+/', trim($uptimeOutput[1]));
                if (isset($parts[1])) {
                    $seconds = (int)$parts[1];
                    $days = floor($seconds / 86400);
                    $hours = floor(($seconds % 86400) / 3600);
                    $minutes = floor(($seconds % 3600) / 60);
                    $uptime = "{$days}d {$hours}h {$minutes}m";
                }
            }
        }
        
        return $this->success([
            'running' => $running,
            'version' => $version,
            'uptime' => $uptime,
        ]);
    }

    /**
     * Get MySQL settings
     */
    protected function actionSettings(array $params, string $actor): array
    {
        // Get common settings
        $settings = [];
        $variables = [];
        
        $settingKeys = [
            // Connection settings
            'max_connections', 'max_allowed_packet', 'wait_timeout', 'interactive_timeout',
            'connect_timeout', 'net_read_timeout', 'net_write_timeout',
            // InnoDB settings
            'innodb_buffer_pool_size', 'innodb_log_file_size', 'innodb_flush_log_at_trx_commit',
            'innodb_file_per_table', 'innodb_flush_method', 'innodb_io_capacity',
            // Performance settings
            'tmp_table_size', 'max_heap_table_size', 'table_open_cache', 'thread_cache_size',
            'sort_buffer_size', 'read_buffer_size', 'join_buffer_size',
            // Query cache (deprecated in MySQL 8 but still used in MariaDB)
            'query_cache_size', 'query_cache_type',
            // Logging
            'slow_query_log', 'long_query_time', 'log_bin', 'expire_logs_days',
            'binlog_format', 'sync_binlog',
            // Character set
            'character_set_server', 'collation_server',
            // Security
            'skip_name_resolve', 'bind_address',
        ];
        
        // Query MySQL for current values
        foreach ($settingKeys as $key) {
            exec("mysql --defaults-file=/root/.my.cnf -N -s -e \"SHOW VARIABLES LIKE '{$key}';\" 2>&1", $output, $code);
            if ($code === 0 && !empty($output)) {
                $parts = preg_split('/\s+/', trim($output[0]));
                if (count($parts) >= 2) {
                    $settings[$key] = $parts[1];
                }
            }
            $output = [];
        }
        
        // Get all variables for the table
        exec("mysql --defaults-file=/root/.my.cnf -N -s -e \"SHOW VARIABLES;\" 2>&1", $allVars, $code);
        if ($code === 0) {
            foreach ($allVars as $line) {
                $parts = preg_split('/\t+/', trim($line), 2);
                if (count($parts) >= 2) {
                    $variables[] = [
                        'name' => $parts[0],
                        'value' => $parts[1],
                    ];
                }
            }
        }
        
        return $this->success([
            'settings' => $settings,
            'variables' => $variables,
        ]);
    }

    /**
     * Update MySQL settings
     */
    protected function actionUpdateSettings(array $params, string $actor): array
    {
        if (!isset($params['settings']) || !is_array($params['settings'])) {
            return $this->error('Settings array is required');
        }
        
        $configFile = '/etc/mysql/mariadb.conf.d/50-server.cnf';
        if (!file_exists($configFile)) {
            $configFile = '/etc/mysql/mysql.conf.d/mysqld.cnf';
        }
        if (!file_exists($configFile)) {
            $configFile = '/etc/my.cnf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error('MySQL configuration file not found');
        }
        
        // Backup
        $backupPath = $this->backupFile($configFile, 'mysql-settings', $actor);
        
        $content = file_get_contents($configFile);
        $newSettings = $params['settings'];
        
        $allowedSettings = [
            // Connection settings
            'max_connections', 'max_allowed_packet', 'wait_timeout', 'interactive_timeout',
            'connect_timeout', 'net_read_timeout', 'net_write_timeout',
            // InnoDB settings
            'innodb_buffer_pool_size', 'innodb_log_file_size', 'innodb_flush_log_at_trx_commit',
            'innodb_file_per_table', 'innodb_flush_method', 'innodb_io_capacity',
            // Performance settings
            'tmp_table_size', 'max_heap_table_size', 'table_open_cache', 'thread_cache_size',
            'sort_buffer_size', 'read_buffer_size', 'join_buffer_size',
            // Query cache
            'query_cache_size', 'query_cache_type',
            // Logging
            'slow_query_log', 'long_query_time', 'expire_logs_days', 'sync_binlog',
            // Character set
            'character_set_server', 'collation_server',
        ];
        
        foreach ($newSettings as $key => $value) {
            if (!in_array($key, $allowedSettings)) {
                continue;
            }
            
            $key = str_replace('_', '-', $key); // MySQL uses dashes in config
            $value = trim($value);
            
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/m', $content)) {
                $content = preg_replace(
                    '/^\s*' . preg_quote($key, '/') . '\s*=.*$/m',
                    $key . ' = ' . $value,
                    $content
                );
            } else {
                // Add under [mysqld] section
                $content = preg_replace(
                    '/(\[mysqld\].*?)((?=\[)|$)/s',
                    "$1{$key} = {$value}\n",
                    $content,
                    1
                );
            }
        }
        
        file_put_contents($configFile, $content);
        
        return $this->success([
            'backup' => $backupPath,
            'message' => 'MySQL settings updated. Restart MySQL to apply.',
        ], "MySQL settings updated by {$actor}");
    }

    /**
     * Restart MySQL
     */
    protected function actionRestart(array $params, string $actor): array
    {
        exec('systemctl restart mariadb 2>&1 || systemctl restart mysql 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            return $this->error('Failed to restart MySQL: ' . implode("\n", $output));
        }
        
        return $this->success([
            'message' => 'MySQL restarted successfully',
        ], "MySQL restarted by {$actor}");
    }

    /**
     * Get all MySQL variables
     */
    protected function actionVariables(array $params, string $actor): array
    {
        $variables = [];
        
        exec("mysql --defaults-file=/root/.my.cnf -N -s -e \"SHOW VARIABLES;\" 2>&1", $allVars, $code);
        if ($code === 0) {
            foreach ($allVars as $line) {
                $parts = preg_split('/\t+/', trim($line), 2);
                if (count($parts) >= 2) {
                    $variables[] = [
                        'name' => $parts[0],
                        'value' => $parts[1],
                    ];
                }
            }
        }
        
        return $this->success([
            'variables' => $variables,
        ]);
    }

    /**
     * Allowed MySQL config files
     */
    private function getAllowedMysqlFiles(): array
    {
        return [
            '/etc/mysql/my.cnf',
            '/etc/mysql/mysql.conf.d/mysqld.cnf',
            '/etc/mysql/mysql.conf.d/mysql.cnf',
            '/etc/mysql/conf.d/mysql.cnf',
            '/etc/mysql/conf.d/mysqldump.cnf',
            '/etc/mysql/mariadb.conf.d/50-server.cnf',
            '/etc/mysql/mariadb.conf.d/50-client.cnf',
        ];
    }

    /**
     * Validate the config file path
     */
    private function validateMysqlFilePath(?string $file): ?string
    {
        // Default to main server config
        if (empty($file)) {
            // Try MariaDB first, then MySQL
            if (file_exists('/etc/mysql/mariadb.conf.d/50-server.cnf')) {
                return '/etc/mysql/mariadb.conf.d/50-server.cnf';
            }
            if (file_exists('/etc/mysql/mysql.conf.d/mysqld.cnf')) {
                return '/etc/mysql/mysql.conf.d/mysqld.cnf';
            }
            return '/etc/mysql/my.cnf';
        }
        
        // Check if file is in allowed list
        if (!in_array($file, $this->getAllowedMysqlFiles(), true)) {
            return null;
        }
        
        return $file;
    }

    /**
     * Get raw MySQL config file
     */
    protected function actionRawConfig(array $params, string $actor): array
    {
        $file = $params['file'] ?? null;
        $configPath = $this->validateMysqlFilePath($file);
        
        if ($configPath === null) {
            return $this->error('Invalid or unauthorized config file path');
        }
        
        if (!file_exists($configPath)) {
            return $this->error("Config file not found: {$configPath}");
        }
        
        return $this->success([
            'path' => $configPath,
            'content' => file_get_contents($configPath),
        ]);
    }

    /**
     * Save raw MySQL config file
     */
    protected function actionSaveRawConfig(array $params, string $actor): array
    {
        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }
        
        $file = $params['file'] ?? null;
        $configPath = $this->validateMysqlFilePath($file);
        
        if ($configPath === null) {
            return $this->error('Invalid or unauthorized config file path');
        }
        
        if (!file_exists($configPath)) {
            return $this->error("Config file not found: {$configPath}");
        }
        
        // Create backup with filename in backup name
        $backupDir = '/var/www/vps-admin/backups/mysql';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $backupName = basename($configPath) . '.' . date('Y-m-d_H-i-s') . '.bak';
        $backupPath = $backupDir . '/' . $backupName;
        copy($configPath, $backupPath);
        
        // Get current file info
        $fileOwner = fileowner($configPath);
        $fileGroup = filegroup($configPath);
        $filePerms = fileperms($configPath) & 0777;
        
        // Clean up Windows line endings
        $content = str_replace("\r\n", "\n", $params['content']);
        $content = str_replace("\r", "", $content);
        
        // Write the new config
        if (file_put_contents($configPath, $content) === false) {
            return $this->error('Failed to write configuration file');
        }
        
        // Restore original ownership and permissions
        chown($configPath, $fileOwner);
        chgrp($configPath, $fileGroup);
        chmod($configPath, $filePerms);
        
        return $this->success([
            'path' => $configPath,
            'backup' => $backupPath,
            'message' => 'MySQL configuration saved',
        ], "MySQL configuration saved by {$actor}");
    }
}
