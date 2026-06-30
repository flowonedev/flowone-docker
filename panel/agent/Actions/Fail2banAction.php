<?php
/**
 * Fail2ban Action Handler
 * 
 * Manages Fail2ban jails and bans.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class Fail2banAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'fail2ban';
    }

    public function getMethods(): array
    {
        return ['status', 'jails', 'jail', 'banned', 'ban', 'unban', 'createJail', 'updateJail', 'deleteJail', 'enableJail', 'disableJail'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['createJail', 'updateJail', 'deleteJail', 'enableJail', 'disableJail']);
    }

    /**
     * Get Fail2ban status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $result = $this->execCommand('fail2ban-client', ['status']);
        
        if (!$result['success']) {
            return $this->error('Failed to get Fail2ban status: ' . $result['output']);
        }

        $jailCount = 0;
        $jails = [];
        
        if (preg_match('/Number of jail:\s+(\d+)/', $result['output'], $matches)) {
            $jailCount = (int)$matches[1];
        }
        
        if (preg_match('/Jail list:\s+(.+)$/m', $result['output'], $matches)) {
            $jails = array_map('trim', explode(',', $matches[1]));
        }

        return $this->success([
            'running' => true,
            'jail_count' => $jailCount,
            'jails' => $jails,
        ]);
    }

    /**
     * List all jails with details
     */
    protected function actionJails(array $params, string $actor): array
    {
        $status = $this->actionStatus($params, $actor);
        
        if (!$status['success']) {
            return $status;
        }

        $jails = [];
        
        foreach ($status['data']['jails'] as $jailName) {
            $jailDetails = $this->getJailDetails($jailName);
            if ($jailDetails) {
                $jails[] = $jailDetails;
            }
        }

        return $this->success(['jails' => $jails]);
    }

    /**
     * Get specific jail details
     */
    protected function actionJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        $details = $this->getJailDetails($name);
        
        if (!$details) {
            return $this->error("Jail not found: {$name}");
        }

        return $this->success(['jail' => $details]);
    }

    /**
     * Get banned IPs for a jail
     */
    protected function actionBanned(array $params, string $actor): array
    {
        $name = $params['name'] ?? null;
        $banned = [];

        if ($name) {
            if (!Validator::jailName($name)) {
                return $this->error('Invalid jail name');
            }
            
            $result = $this->execCommand('fail2ban-client', ['status', $name]);
            
            if ($result['success'] && preg_match('/Banned IP list:\s+(.+)$/m', $result['output'], $matches)) {
                $ips = array_filter(array_map('trim', explode(' ', $matches[1])));
                $banned[$name] = $ips;
            }
        } else {
            // Get banned from all jails
            $status = $this->actionStatus($params, $actor);
            
            if ($status['success']) {
                foreach ($status['data']['jails'] as $jailName) {
                    $result = $this->execCommand('fail2ban-client', ['status', $jailName]);
                    
                    if ($result['success'] && preg_match('/Banned IP list:\s+(.+)$/m', $result['output'], $matches)) {
                        $ips = array_filter(array_map('trim', explode(' ', $matches[1])));
                        if (!empty($ips)) {
                            $banned[$jailName] = $ips;
                        }
                    }
                }
            }
        }

        return $this->success(['banned' => $banned]);
    }

    /**
     * Ban an IP
     */
    protected function actionBan(array $params, string $actor): array
    {
        if (!isset($params['jail']) || !isset($params['ip'])) {
            return $this->error('Jail name and IP are required');
        }

        $jail = $params['jail'];
        $ip = $params['ip'];
        
        if (!Validator::jailName($jail)) {
            return $this->error('Invalid jail name');
        }
        
        if (!Validator::ipAddress($ip)) {
            return $this->error('Invalid IP address');
        }

        $result = $this->execCommand('fail2ban-client', ['set', $jail, 'banip', $ip]);
        
        if ($result['success']) {
            return $this->success([
                'jail' => $jail,
                'ip' => $ip,
                'action' => 'ban',
            ], "IP {$ip} banned in jail {$jail}");
        }

        return $this->error("Failed to ban IP: " . $result['output']);
    }

    /**
     * Unban an IP
     */
    protected function actionUnban(array $params, string $actor): array
    {
        if (!isset($params['jail']) || !isset($params['ip'])) {
            return $this->error('Jail name and IP are required');
        }

        $jail = $params['jail'];
        $ip = $params['ip'];
        
        if (!Validator::jailName($jail)) {
            return $this->error('Invalid jail name');
        }
        
        if (!Validator::ipAddress($ip)) {
            return $this->error('Invalid IP address');
        }

        $result = $this->execCommand('fail2ban-client', ['set', $jail, 'unbanip', $ip]);
        
        if ($result['success']) {
            return $this->success([
                'jail' => $jail,
                'ip' => $ip,
                'action' => 'unban',
            ], "IP {$ip} unbanned from jail {$jail}");
        }

        return $this->error("Failed to unban IP: " . $result['output']);
    }

    /**
     * Create a new jail
     */
    protected function actionCreateJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        $jailFile = '/etc/fail2ban/jail.d/' . $name . '.local';
        
        if (file_exists($jailFile)) {
            return $this->error("Jail already exists: {$name}");
        }

        $config = $this->generateJailConfig($name, $params);
        
        file_put_contents($jailFile, $config);
        chmod($jailFile, 0644);

        // Reload fail2ban
        $this->execCommand('fail2ban-client', ['reload']);

        return $this->success([
            'name' => $name,
            'config_path' => $jailFile,
        ], "Jail {$name} created");
    }

    /**
     * Update an existing jail
     */
    protected function actionUpdateJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        $jailFile = $this->findJailConfig($name);
        
        if (!$jailFile) {
            return $this->error("Jail config not found: {$name}");
        }

        // Backup before modification
        $this->backupFile($jailFile, 'updateJail', $actor);

        // Read current config
        $currentConfig = $this->parseJailConfig($jailFile, $name);
        
        // Merge with new params
        $newConfig = array_merge($currentConfig, array_filter([
            'enabled' => $params['enabled'] ?? null,
            'port' => $params['port'] ?? null,
            'filter' => $params['filter'] ?? null,
            'logpath' => $params['logpath'] ?? null,
            'maxretry' => $params['maxretry'] ?? null,
            'bantime' => $params['bantime'] ?? null,
            'findtime' => $params['findtime'] ?? null,
        ], fn($v) => $v !== null));

        // Generate new config
        $config = $this->generateJailConfig($name, $newConfig);
        
        file_put_contents($jailFile, $config);
        chmod($jailFile, 0644);

        // Reload fail2ban
        $this->execCommand('fail2ban-client', ['reload']);

        return $this->success([
            'name' => $name,
            'config' => $newConfig,
        ], "Jail {$name} updated");
    }

    /**
     * Enable a jail
     */
    protected function actionEnableJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        $jailFile = $this->findJailConfig($name);
        
        if (!$jailFile) {
            return $this->error("Jail config not found: {$name}");
        }

        // Backup
        $this->backupFile($jailFile, 'enableJail', $actor);

        // Read and update config
        $content = file_get_contents($jailFile);
        $content = preg_replace('/^enabled\s*=\s*\S+/mi', 'enabled = true', $content);
        file_put_contents($jailFile, $content);

        // Reload and start
        $this->execCommand('fail2ban-client', ['reload']);
        $this->execCommand('fail2ban-client', ['start', $name]);

        return $this->success([
            'name' => $name,
            'enabled' => true,
        ], "Jail {$name} enabled");
    }

    /**
     * Disable a jail
     */
    protected function actionDisableJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        // Don't allow disabling critical jails
        $protectedJails = ['sshd', 'ssh'];
        if (in_array($name, $protectedJails)) {
            return $this->error('Cannot disable protected jail');
        }

        $jailFile = $this->findJailConfig($name);
        
        if (!$jailFile) {
            return $this->error("Jail config not found: {$name}");
        }

        // Backup
        $this->backupFile($jailFile, 'disableJail', $actor);

        // Read and update config
        $content = file_get_contents($jailFile);
        $content = preg_replace('/^enabled\s*=\s*\S+/mi', 'enabled = false', $content);
        file_put_contents($jailFile, $content);

        // Stop and reload
        $this->execCommand('fail2ban-client', ['stop', $name]);
        $this->execCommand('fail2ban-client', ['reload']);

        return $this->success([
            'name' => $name,
            'enabled' => false,
        ], "Jail {$name} disabled");
    }

    /**
     * Delete a jail
     */
    protected function actionDeleteJail(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Jail name is required');
        }

        $name = $params['name'];
        
        if (!Validator::jailName($name)) {
            return $this->error('Invalid jail name');
        }

        // Don't allow deleting default jails
        $protectedJails = ['sshd', 'ssh'];
        if (in_array($name, $protectedJails)) {
            return $this->error('Cannot delete protected jail');
        }

        $jailFile = $this->findJailConfig($name);
        
        if (!$jailFile) {
            return $this->error("Jail config not found: {$name}");
        }

        // Backup before deletion
        $this->backupFile($jailFile, 'deleteJail', $actor);

        // Stop the jail first
        $this->execCommand('fail2ban-client', ['stop', $name]);

        // Remove config
        unlink($jailFile);

        // Reload
        $this->execCommand('fail2ban-client', ['reload']);

        return $this->success([
            'name' => $name,
        ], "Jail {$name} deleted");
    }

    /**
     * Get jail details
     */
    private function getJailDetails(string $name): ?array
    {
        $result = $this->execCommand('fail2ban-client', ['status', $name]);
        
        if (!$result['success']) {
            return null;
        }

        $details = [
            'name' => $name,
            'enabled' => true,
            'filter' => $name,
            'logpath' => '',
            'port' => '',
            'maxretry' => 5,
            'bantime' => '600',
            'findtime' => '600',
            'currently_failed' => 0,
            'total_failed' => 0,
            'currently_banned' => 0,
            'total_banned' => 0,
            'banned_ips' => [],
        ];

        // Parse status output
        if (preg_match('/Currently failed:\s+(\d+)/', $result['output'], $m)) {
            $details['currently_failed'] = (int)$m[1];
        }
        if (preg_match('/Total failed:\s+(\d+)/', $result['output'], $m)) {
            $details['total_failed'] = (int)$m[1];
        }
        if (preg_match('/Currently banned:\s+(\d+)/', $result['output'], $m)) {
            $details['currently_banned'] = (int)$m[1];
        }
        if (preg_match('/Total banned:\s+(\d+)/', $result['output'], $m)) {
            $details['total_banned'] = (int)$m[1];
        }
        if (preg_match('/Banned IP list:\s+(.+)$/m', $result['output'], $m)) {
            $details['banned_ips'] = array_filter(array_map('trim', explode(' ', $m[1])));
        }

        // First, try to get config from file (more reliable)
        $jailFile = $this->findJailConfig($name);
        if ($jailFile) {
            $fileConfig = $this->parseJailConfig($jailFile, $name);
            
            // Use file config values
            if (!empty($fileConfig['port'])) {
                $details['port'] = $fileConfig['port'];
            }
            if (!empty($fileConfig['filter'])) {
                $details['filter'] = $fileConfig['filter'];
            }
            if (!empty($fileConfig['logpath'])) {
                $details['logpath'] = $fileConfig['logpath'];
            }
            if (!empty($fileConfig['maxretry'])) {
                $details['maxretry'] = $fileConfig['maxretry'];
            }
            if (!empty($fileConfig['bantime'])) {
                $details['bantime'] = $fileConfig['bantime'];
            }
            if (!empty($fileConfig['findtime'])) {
                $details['findtime'] = $fileConfig['findtime'];
            }
            if (isset($fileConfig['enabled'])) {
                $details['enabled'] = in_array(strtolower($fileConfig['enabled']), ['true', 'yes', '1', 'on']);
            }
        }

        // Fallback: Get runtime values from fail2ban-client (these are numeric)
        if (empty($details['bantime']) || $details['bantime'] === '600') {
            $bantimeResult = $this->execCommand('fail2ban-client', ['get', $name, 'bantime']);
            if ($bantimeResult['success'] && !empty(trim($bantimeResult['output']))) {
                $val = trim($bantimeResult['output']);
                if (is_numeric($val)) {
                    $details['bantime'] = $this->formatTime((int)$val);
                }
            }
        }
        
        if (empty($details['findtime']) || $details['findtime'] === '600') {
            $findtimeResult = $this->execCommand('fail2ban-client', ['get', $name, 'findtime']);
            if ($findtimeResult['success'] && !empty(trim($findtimeResult['output']))) {
                $val = trim($findtimeResult['output']);
                if (is_numeric($val)) {
                    $details['findtime'] = $this->formatTime((int)$val);
                }
            }
        }
        
        if (empty($details['maxretry']) || $details['maxretry'] === 5) {
            $maxretryResult = $this->execCommand('fail2ban-client', ['get', $name, 'maxretry']);
            if ($maxretryResult['success'] && !empty(trim($maxretryResult['output']))) {
                $details['maxretry'] = (int)trim($maxretryResult['output']);
            }
        }

        return $details;
    }
    
    /**
     * Format seconds to human readable time
     */
    private function formatTime(int $seconds): string
    {
        if ($seconds >= 86400 && $seconds % 86400 === 0) {
            return ($seconds / 86400) . 'd';
        }
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return ($seconds / 3600) . 'h';
        }
        if ($seconds >= 60 && $seconds % 60 === 0) {
            return ($seconds / 60) . 'm';
        }
        return $seconds . 's';
    }

    /**
     * Find jail config file
     */
    private function findJailConfig(string $name): ?string
    {
        // Check jail.d directory first (highest priority)
        $jailFile = '/etc/fail2ban/jail.d/' . $name . '.local';
        if (file_exists($jailFile)) {
            return $jailFile;
        }
        
        $jailFile = '/etc/fail2ban/jail.d/' . $name . '.conf';
        if (file_exists($jailFile)) {
            return $jailFile;
        }

        // Check cpguard jail config
        $cpguardJail = '/etc/fail2ban/jail.d/cpg-' . $name . '.conf';
        if (file_exists($cpguardJail)) {
            return $cpguardJail;
        }

        // Check if defined in jail.local
        $jailLocal = '/etc/fail2ban/jail.local';
        if (file_exists($jailLocal)) {
            $content = file_get_contents($jailLocal);
            if (preg_match('/\[' . preg_quote($name, '/') . '\]/i', $content)) {
                return $jailLocal;
            }
        }

        // Check if defined in jail.conf (default jails)
        $jailConf = '/etc/fail2ban/jail.conf';
        if (file_exists($jailConf)) {
            $content = file_get_contents($jailConf);
            if (preg_match('/\[' . preg_quote($name, '/') . '\]/i', $content)) {
                return $jailConf;
            }
        }

        return null;
    }

    /**
     * Parse jail config from file
     */
    private function parseJailConfig(string $file, string $name): array
    {
        $config = [];

        if (!file_exists($file)) {
            return $config;
        }

        $content = file_get_contents($file);
        
        // Find the jail section (case-insensitive)
        if (preg_match('/\[\s*' . preg_quote($name, '/') . '\s*\](.*?)(?=\[|$)/si', $content, $matches)) {
            $section = $matches[1];
            
            // Parse each key-value pair
            foreach (['enabled', 'port', 'filter', 'logpath', 'maxretry', 'bantime', 'findtime', 'backend', 'action'] as $key) {
                if (preg_match('/^\s*' . $key . '\s*=\s*(.+)$/mi', $section, $m)) {
                    $value = trim($m[1]);
                    // Remove inline comments
                    if (($pos = strpos($value, '#')) !== false) {
                        $value = trim(substr($value, 0, $pos));
                    }
                    if (!empty($value)) {
                        $config[$key] = $value;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * Generate jail configuration
     */
    private function generateJailConfig(string $name, array $params): string
    {
        $enabled = $params['enabled'] ?? 'true';
        $port = $params['port'] ?? 'http,https';
        $filter = $params['filter'] ?? $name;
        $logpath = $params['logpath'] ?? '/var/log/' . $name . '.log';
        $maxretry = $params['maxretry'] ?? 5;
        $bantime = $params['bantime'] ?? '10m';
        $findtime = $params['findtime'] ?? '10m';

        return <<<CONFIG
[{$name}]
enabled = {$enabled}
port = {$port}
filter = {$filter}
logpath = {$logpath}
maxretry = {$maxretry}
bantime = {$bantime}
findtime = {$findtime}
CONFIG;
    }
}

