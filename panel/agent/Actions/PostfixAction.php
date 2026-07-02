<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\MailPodBridge;

/**
 * Postfix SMTP server configuration management
 */
class PostfixAction extends BaseAction
{
    protected array $allowedActions = ['status', 'settings', 'updateSettings', 'restart', 'flush', 'queue'];

    private ?MailPodBridge $mailPod = null;

    public function getNamespace(): string
    {
        return 'postfix';
    }

    public function getMethods(): array
    {
        return ['status', 'settings', 'updateSettings', 'restart', 'flush', 'queue', 'rawConfig', 'saveRawConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updateSettings']);
    }

    /**
     * On Docker boxes Postfix runs inside the flowone mail pod; read
     * status/config through docker exec instead of native tooling.
     */
    private function mailPod(): MailPodBridge
    {
        if ($this->mailPod === null) {
            $this->mailPod = new MailPodBridge(
                fn (string $cmd, array $args, int $timeout = 0) => $this->execCommand($cmd, $args, $timeout)
            );
        }
        return $this->mailPod;
    }

    /**
     * Get Postfix status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $pod = $this->mailPod();

        if ($pod->active()) {
            $running = $pod->programRunning('postfix');
            $versionOut = $pod->exec(['postconf', '-d', 'mail_version'], 20)['output'];
        } else {
            exec('systemctl is-active postfix 2>&1', $activeOutput, $activeCode);
            $running = ($activeCode === 0);
            exec('postconf -d mail_version 2>&1', $versionOutput);
            $versionOut = implode("\n", $versionOutput ?? []);
        }

        $version = null;
        if (preg_match('/mail_version\s*=\s*(.+)/', $versionOut, $matches)) {
            $version = trim($matches[1]);
        }

        return $this->success([
            'running' => $running,
            'version' => $version,
            'runtime' => $pod->active() ? 'docker' : 'native',
        ]);
    }

    /**
     * Get Postfix settings
     */
    protected function actionSettings(array $params, string $actor): array
    {
        $settings = [];
        
        $settingKeys = [
            // General
            'myhostname', 'mydomain', 'myorigin', 'inet_interfaces', 'inet_protocols',
            'smtpd_banner',
            // Queue & Limits
            'message_size_limit', 'mailbox_size_limit', 'smtpd_recipient_limit',
            'maximal_queue_lifetime', 'bounce_queue_lifetime',
            // Virtual domains
            'virtual_mailbox_domains', 'virtual_mailbox_maps', 'virtual_alias_maps',
            'virtual_transport', 'virtual_mailbox_base', 'virtual_uid_maps', 'virtual_gid_maps',
            // TLS/SSL
            'smtp_tls_security_level', 'smtpd_tls_security_level', 'smtpd_use_tls',
            'smtpd_tls_cert_file', 'smtpd_tls_key_file', 'smtpd_tls_protocols',
            // SASL Auth
            'smtpd_sasl_auth_enable', 'smtpd_sasl_type', 'smtpd_sasl_path',
            'smtpd_sasl_security_options', 'smtpd_sasl_local_domain',
            // Spam Prevention
            'smtpd_helo_required', 'strict_rfc821_envelopes', 'disable_vrfy_command',
            'smtpd_delay_reject',
            // Restrictions
            'smtpd_helo_restrictions', 'smtpd_sender_restrictions', 'smtpd_recipient_restrictions',
            // DKIM/Milter
            'milter_default_action', 'milter_protocol', 'smtpd_milters', 'non_smtpd_milters',
            // Rate limiting
            'smtpd_client_connection_count_limit', 'smtpd_client_connection_rate_limit',
            'smtpd_client_message_rate_limit',
        ];
        
        if ($this->mailPod()->active()) {
            // One docker exec fetches every key at once.
            $settings = $this->mailPod()->keyValues('postconf', $settingKeys, 30);
        } else {
            foreach ($settingKeys as $key) {
                exec("postconf {$key} 2>&1", $output, $code);
                if ($code === 0 && !empty($output)) {
                    if (preg_match('/' . preg_quote($key, '/') . '\s*=\s*(.*)/', $output[0], $matches)) {
                        $settings[$key] = trim($matches[1]);
                    }
                }
                $output = [];
            }
        }
        
        // Get mail queue
        $queue = $this->getQueue();
        
        return $this->success([
            'settings' => $settings,
            'queue' => $queue,
            'runtime' => $this->mailPod()->active() ? 'docker' : 'native',
            'read_only' => $this->mailPod()->active(),
        ]);
    }

    /**
     * Get mail queue
     */
    private function getQueue(): array
    {
        $queue = [];
        if ($this->mailPod()->active()) {
            $res = $this->mailPod()->exec(['postqueue', '-j'], 30);
            $output = $res['success'] ? explode("\n", $res['output']) : [];
            $code = $res['success'] ? 0 : 1;
        } else {
            exec('postqueue -j 2>&1', $output, $code);
        }
        
        if ($code === 0) {
            foreach ($output as $line) {
                $msg = json_decode($line, true);
                if ($msg) {
                    $queue[] = [
                        'id' => $msg['queue_id'] ?? '',
                        'from' => $msg['sender'] ?? '',
                        'to' => implode(', ', array_column($msg['recipients'] ?? [], 'address')),
                        'size' => $msg['message_size'] ?? 0,
                        'status' => $msg['queue_name'] ?? 'unknown',
                    ];
                }
            }
        }
        
        return $queue;
    }

    /**
     * Update Postfix settings
     */
    protected function actionUpdateSettings(array $params, string $actor): array
    {
        if ($this->mailPod()->active()) {
            return $this->error($this->mailPod()->readOnlyError());
        }

        if (!isset($params['settings']) || !is_array($params['settings'])) {
            return $this->error('Settings array is required');
        }
        
        $configFile = '/etc/postfix/main.cf';
        if (!file_exists($configFile)) {
            return $this->error('Postfix configuration file not found');
        }
        
        // Backup
        $backupPath = $this->backupFile($configFile, 'postfix-settings', $actor);
        
        $newSettings = $params['settings'];
        
        $allowedSettings = [
            // General
            'myhostname', 'mydomain', 'myorigin', 'inet_interfaces', 'inet_protocols',
            'smtpd_banner',
            // Queue & Limits
            'message_size_limit', 'mailbox_size_limit', 'smtpd_recipient_limit',
            'maximal_queue_lifetime', 'bounce_queue_lifetime',
            // Virtual domains
            'virtual_mailbox_domains', 'virtual_mailbox_maps', 'virtual_alias_maps',
            'virtual_transport', 'virtual_mailbox_base', 'virtual_uid_maps', 'virtual_gid_maps',
            // TLS/SSL
            'smtp_tls_security_level', 'smtpd_tls_security_level', 'smtpd_use_tls',
            'smtpd_tls_cert_file', 'smtpd_tls_key_file', 'smtpd_tls_protocols',
            // SASL Auth
            'smtpd_sasl_auth_enable', 'smtpd_sasl_type', 'smtpd_sasl_path',
            'smtpd_sasl_security_options', 'smtpd_sasl_local_domain',
            // Spam Prevention
            'smtpd_helo_required', 'strict_rfc821_envelopes', 'disable_vrfy_command',
            'smtpd_delay_reject',
            // Restrictions
            'smtpd_helo_restrictions', 'smtpd_sender_restrictions', 'smtpd_recipient_restrictions',
            // DKIM/Milter
            'milter_default_action', 'milter_protocol', 'smtpd_milters', 'non_smtpd_milters',
            // Rate limiting
            'smtpd_client_connection_count_limit', 'smtpd_client_connection_rate_limit',
            'smtpd_client_message_rate_limit',
        ];
        
        foreach ($newSettings as $key => $value) {
            if (!in_array($key, $allowedSettings)) {
                continue;
            }
            
            $value = escapeshellarg(trim($value));
            exec("postconf -e \"{$key}={$value}\" 2>&1", $output, $code);
            
            if ($code !== 0) {
                return $this->error("Failed to set {$key}: " . implode("\n", $output));
            }
        }
        
        return $this->success([
            'backup' => $backupPath,
            'message' => 'Postfix settings updated. Restart Postfix to apply.',
        ], "Postfix settings updated by {$actor}");
    }

    /**
     * Restart Postfix
     */
    protected function actionRestart(array $params, string $actor): array
    {
        if ($this->mailPod()->active()) {
            $res = $this->mailPod()->restartProgram('postfix');
            if (!$res['success']) {
                return $this->error('Failed to restart Postfix in mail pod: ' . $res['output']);
            }
        } else {
            exec('systemctl restart postfix 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return $this->error('Failed to restart Postfix: ' . implode("\n", $output));
            }
        }
        
        return $this->success([
            'message' => 'Postfix restarted successfully',
        ], "Postfix restarted by {$actor}");
    }

    /**
     * Flush mail queue
     */
    protected function actionFlush(array $params, string $actor): array
    {
        if ($this->mailPod()->active()) {
            $res = $this->mailPod()->exec(['postqueue', '-f'], 30);
            if (!$res['success']) {
                return $this->error('Failed to flush queue: ' . $res['output']);
            }
        } else {
            exec('postqueue -f 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return $this->error('Failed to flush queue: ' . implode("\n", $output));
            }
        }
        
        return $this->success([
            'message' => 'Mail queue flushed',
        ], "Mail queue flushed by {$actor}");
    }

    /**
     * Get queue status
     */
    protected function actionQueue(array $params, string $actor): array
    {
        return $this->success([
            'queue' => $this->getQueue(),
        ]);
    }

    /**
     * Allowed Postfix config files (only essential .cf files)
     */
    private function getAllowedPostfixFiles(): array
    {
        return [
            '/etc/postfix/main.cf',
            '/etc/postfix/master.cf',
        ];
    }

    /**
     * Validate the config file path
     */
    private function validatePostfixFile(string $file): bool
    {
        // Normalize path
        $realPath = realpath(dirname($file)) . '/' . basename($file);
        
        // Must be in /etc/postfix/
        if (strpos($file, '/etc/postfix/') !== 0) {
            return false;
        }
        
        // Check against allowed list or ensure it's a regular file in /etc/postfix/
        $allowed = $this->getAllowedPostfixFiles();
        if (in_array($file, $allowed)) {
            return true;
        }
        
        // Allow any file directly in /etc/postfix/ (no subdirectories for security)
        if (dirname($file) === '/etc/postfix' && !str_contains(basename($file), '..')) {
            return true;
        }
        
        return false;
    }

    /**
     * Get raw Postfix config file
     */
    protected function actionRawConfig(array $params, string $actor): array
    {
        $configPath = $params['file'] ?? '/etc/postfix/main.cf';
        
        // Validate file path
        if (!$this->validatePostfixFile($configPath)) {
            return $this->error('Invalid config file path');
        }

        // Docker box: read the file from inside the mail pod (read-only view).
        if ($this->mailPod()->active()) {
            $content = $this->mailPod()->readFile($configPath);
            return $this->success([
                'path' => $configPath,
                'content' => $content ?? '',
                'exists' => $content !== null,
                'read_only' => true,
                'runtime' => 'docker',
            ]);
        }
        
        if (!file_exists($configPath)) {
            // For non-main.cf files, return empty content if file doesn't exist
            if ($configPath !== '/etc/postfix/main.cf') {
                return $this->success([
                    'path' => $configPath,
                    'content' => '',
                    'exists' => false,
                ]);
            }
            return $this->error('Postfix config file not found');
        }
        
        return $this->success([
            'path' => $configPath,
            'content' => file_get_contents($configPath),
            'exists' => true,
        ]);
    }

    /**
     * Save raw Postfix config file
     */
    protected function actionSaveRawConfig(array $params, string $actor): array
    {
        if ($this->mailPod()->active()) {
            return $this->error($this->mailPod()->readOnlyError());
        }

        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }
        
        $configPath = $params['file'] ?? '/etc/postfix/main.cf';
        
        // Validate file path
        if (!$this->validatePostfixFile($configPath)) {
            return $this->error('Invalid config file path');
        }
        
        // Create backup directory
        $backupDir = '/var/www/vps-admin/backups/postfix';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = basename($configPath);
        $backupPath = $backupDir . '/' . $filename . '.' . date('Y-m-d_H-i-s') . '.bak';
        
        // Backup existing file if it exists
        if (file_exists($configPath)) {
            copy($configPath, $backupPath);
            
            // Get current file info to preserve permissions
            $fileOwner = fileowner($configPath);
            $fileGroup = filegroup($configPath);
            $filePerms = fileperms($configPath) & 0777;
        } else {
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
            'message' => 'Postfix configuration saved',
        ], "Postfix config {$filename} saved by {$actor}");
    }
}
