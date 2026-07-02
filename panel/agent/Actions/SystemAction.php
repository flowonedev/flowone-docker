<?php
/**
 * System Action Handler
 * 
 * Manages system-level configurations:
 * - Hostname
 * - Timezone
 * - SSH settings
 * - Swap management
 * - System info
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\MailPodBridge;

class SystemAction extends BaseAction
{
    private ?\PDO $pdo = null;

    private ?MailPodBridge $mailPod = null;

    /** Services whose config lives inside the Docker mail pod on hybrid boxes. */
    private const MAIL_POD_SERVICES = ['postfix', 'dovecot'];

    private function mailPod(): MailPodBridge
    {
        if ($this->mailPod === null) {
            $this->mailPod = new MailPodBridge(
                fn (string $cmd, array $args, int $timeout = 0) => $this->execCommand($cmd, $args, $timeout)
            );
        }
        return $this->mailPod;
    }

    public function getNamespace(): string
    {
        return 'system';
    }

    public function getMethods(): array
    {
        return [
            'info',           // Get system information
            'hostname',       // Get/set hostname
            'timezone',       // Get/set timezone
            'timezones',      // List available timezones
            'ssh',            // Get SSH settings
            'updateSsh',      // Update SSH settings
            'sshRaw',         // Get raw SSH config
            'swap',           // Get swap info
            'createSwap',     // Create swap file
            'swappiness',     // Get/set swappiness
            'reboot',         // Reboot server
            'uptime',         // Get uptime info
            'pdns',           // Get PowerDNS config
            'updatePdns',     // Update PowerDNS config
            'pdnsStatus',     // Get PowerDNS service status
            'motd',           // Get MOTD
            'updateMotd',     // Update MOTD
            'templates',      // List HTML templates
            'getTemplate',    // Get a specific template
            'updateTemplate', // Update/create a template
            'listSitesForTemplate',   // List sites for template deployment
            'applyTemplateToSite',    // Apply template to a specific site
            'deployTemplateToAllSites', // Deploy template to multiple sites
            'listTemplateBackups',    // List template backups for a site
            'revertTemplate',         // Revert template to original
            'getTemplateDeployments', // Get all active template deployments
            'olsRestart',     // Restart OpenLiteSpeed
            'olsReload',      // Reload OpenLiteSpeed (graceful)
            'olsReadConfig',  // Read OpenLiteSpeed config (runs as root)
            'olsSaveConfig',  // Save OpenLiteSpeed config
            'checkPermissions', // Check config file permissions
            'fixPermissions',   // Fix config file permissions
            'syntaxCheck',      // Check config file syntax
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['updateSsh', 'hostname', 'timezone', 'createSwap', 'swappiness', 'updatePdns']);
    }

    /**
     * Get database connection for template tracking
     * Includes connection health check to handle stale connections in long-running daemon
     */
    private function getConnection(): ?\PDO
    {
        // Check if existing connection is still alive
        if ($this->pdo !== null) {
            try {
                $this->pdo->query('SELECT 1');
                return $this->pdo;
            } catch (\PDOException $e) {
                $this->pdo = null;
                $this->logger->warning('Database connection was stale, reconnecting', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Read panel config
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';
        
        if (!file_exists($configFile)) {
            $this->logger->warning('Panel config file not found');
            return null;
        }

        try {
            $config = require $configFile;
            if (file_exists($localConfigFile)) {
                $localConfig = require $localConfigFile;
                $config = array_replace_recursive($config, $localConfig);
            }

            $dbConfig = $config['database'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['name'] ?? '',
                $dbConfig['charset'] ?? 'utf8mb4'
            );

            $this->pdo = new \PDO(
                $dsn,
                $dbConfig['user'] ?? '',
                $dbConfig['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );

            return $this->pdo;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to connect to database: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record a template deployment in the database
     */
    private function recordTemplateDeployment(string $domain, string $templateType, string $backupFile, string $actor): bool
    {
        $pdo = $this->getConnection();
        if (!$pdo) {
            return false;
        }

        try {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle re-deployments
            $stmt = $pdo->prepare("
                INSERT INTO template_deployments (domain, template_type, backup_file, deployed_by, deployed_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    template_type = VALUES(template_type),
                    backup_file = VALUES(backup_file),
                    deployed_by = VALUES(deployed_by),
                    deployed_at = NOW()
            ");
            $stmt->execute([$domain, $templateType, $backupFile, $actor]);
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to record template deployment: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate the default placeholder page for a domain.
     * This is the same page VhostAction creates when a site is first provisioned.
     */
    private function generateDefaultPlaceholder(string $domain): string
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
        <p>This site is ready. Upload your files to get started.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Remove a template deployment record from the database
     */
    private function removeTemplateDeployment(string $domain): bool
    {
        $pdo = $this->getConnection();
        if (!$pdo) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM template_deployments WHERE domain = ?");
            $stmt->execute([$domain]);
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to remove template deployment: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get template deployment info for a domain from the database
     */
    private function getTemplateDeploymentInfo(string $domain): ?array
    {
        $pdo = $this->getConnection();
        if (!$pdo) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT template_type, deployed_at, deployed_by, backup_file 
                FROM template_deployments 
                WHERE domain = ?
            ");
            $stmt->execute([$domain]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get comprehensive system information
     */
    protected function actionInfo(array $params, string $actor): array
    {
        $info = [
            'hostname' => gethostname(),
            'os' => $this->getOsInfo(),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'uptime' => $this->getUptime(),
            'load' => sys_getloadavg(),
            'memory' => $this->getMemoryInfo(),
            'swap' => $this->getSwapInfo(),
            'disk' => $this->getDiskInfo(),
            'cpu' => $this->getCpuInfo(),
            'timezone' => date_default_timezone_get(),
            'time' => date('Y-m-d H:i:s T'),
        ];

        return $this->success($info);
    }

    /**
     * Get or set hostname
     */
    protected function actionHostname(array $params, string $actor): array
    {
        // Get current hostname
        if (!isset($params['hostname'])) {
            $hostname = gethostname();
            $fqdn = $this->getFqdn();
            
            return $this->success([
                'hostname' => $hostname,
                'fqdn' => $fqdn,
            ]);
        }

        // Set new hostname
        $newHostname = trim($params['hostname']);
        
        // Validate hostname format
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $newHostname)) {
            return $this->error('Invalid hostname format');
        }

        // Backup current hostname files
        $this->backupFile('/etc/hostname', 'hostname', $actor);
        $this->backupFile('/etc/hosts', 'hostname', $actor);

        $oldHostname = gethostname();

        // Update /etc/hostname
        file_put_contents('/etc/hostname', $newHostname . "\n");

        // Update /etc/hosts
        $hosts = file_get_contents('/etc/hosts');
        $hosts = preg_replace('/\b' . preg_quote($oldHostname, '/') . '\b/', $newHostname, $hosts);
        file_put_contents('/etc/hosts', $hosts);

        // Apply hostname immediately
        $this->execCommand('hostnamectl', ['set-hostname', $newHostname]);

        return $this->success([
            'hostname' => $newHostname,
            'old_hostname' => $oldHostname,
        ], "Hostname changed to {$newHostname}");
    }

    /**
     * Get or set timezone
     */
    protected function actionTimezone(array $params, string $actor): array
    {
        // Get current timezone
        if (!isset($params['timezone'])) {
            $current = @readlink('/etc/localtime');
            if ($current) {
                $current = str_replace('/usr/share/zoneinfo/', '', $current);
            } else {
                // Fallback to timedatectl
                $result = $this->execCommand('timedatectl', ['show', '--property=Timezone', '--value']);
                $current = trim($result['output']);
            }
            
            return $this->success([
                'timezone' => $current,
                'time' => date('Y-m-d H:i:s'),
                'utc_offset' => date('P'),
            ]);
        }

        // Set new timezone
        $newTimezone = trim($params['timezone']);
        $zonefile = '/usr/share/zoneinfo/' . $newTimezone;
        
        if (!file_exists($zonefile)) {
            return $this->error("Invalid timezone: {$newTimezone}");
        }

        // Set timezone using timedatectl
        $result = $this->execCommand('timedatectl', ['set-timezone', $newTimezone]);
        
        if (!$result['success']) {
            return $this->error("Failed to set timezone: " . $result['output']);
        }

        return $this->success([
            'timezone' => $newTimezone,
            'time' => date('Y-m-d H:i:s'),
            'utc_offset' => date('P'),
        ], "Timezone changed to {$newTimezone}");
    }

    /**
     * List available timezones
     */
    protected function actionTimezones(array $params, string $actor): array
    {
        $result = $this->execCommand('timedatectl', ['list-timezones']);
        
        if (!$result['success']) {
            return $this->error('Failed to list timezones');
        }

        $timezones = array_filter(explode("\n", $result['output']));
        
        // Group by region
        $grouped = [];
        foreach ($timezones as $tz) {
            $parts = explode('/', $tz, 2);
            $region = $parts[0];
            $city = $parts[1] ?? $tz;
            
            if (!isset($grouped[$region])) {
                $grouped[$region] = [];
            }
            $grouped[$region][] = $tz;
        }

        return $this->success([
            'timezones' => $timezones,
            'grouped' => $grouped,
            'count' => count($timezones),
        ]);
    }

    /**
     * Get SSH configuration settings
     */
    protected function actionSsh(array $params, string $actor): array
    {
        $sshdConfig = '/etc/ssh/sshd_config';
        
        if (!file_exists($sshdConfig)) {
            return $this->error('SSH config file not found');
        }

        $content = file_get_contents($sshdConfig);
        
        $settings = [
            // Connection
            'port' => $this->parseSSHSetting($content, 'Port', 22),
            'listen_address' => $this->parseSSHSetting($content, 'ListenAddress', ''),
            'login_grace_time' => $this->parseSSHSetting($content, 'LoginGraceTime', 120),
            'max_startups' => $this->parseSSHSetting($content, 'MaxStartups', '10:30:100'),
            // Authentication
            'permit_root_login' => $this->parseSSHSetting($content, 'PermitRootLogin', 'prohibit-password'),
            'password_authentication' => $this->parseSSHSetting($content, 'PasswordAuthentication', 'yes'),
            'pubkey_authentication' => $this->parseSSHSetting($content, 'PubkeyAuthentication', 'yes'),
            'permit_empty_passwords' => $this->parseSSHSetting($content, 'PermitEmptyPasswords', 'no'),
            'max_auth_tries' => $this->parseSSHSetting($content, 'MaxAuthTries', 6),
            'max_sessions' => $this->parseSSHSetting($content, 'MaxSessions', 10),
            // Access Control
            'allow_users' => $this->parseSSHSetting($content, 'AllowUsers', ''),
            'deny_users' => $this->parseSSHSetting($content, 'DenyUsers', ''),
            'allow_groups' => $this->parseSSHSetting($content, 'AllowGroups', ''),
            'deny_groups' => $this->parseSSHSetting($content, 'DenyGroups', ''),
            // Security
            'use_dns' => $this->parseSSHSetting($content, 'UseDNS', 'no'),
            'tcp_keep_alive' => $this->parseSSHSetting($content, 'TCPKeepAlive', 'yes'),
            'client_alive_interval' => $this->parseSSHSetting($content, 'ClientAliveInterval', 0),
            'client_alive_count_max' => $this->parseSSHSetting($content, 'ClientAliveCountMax', 3),
            // Features
            'x11_forwarding' => $this->parseSSHSetting($content, 'X11Forwarding', 'no'),
            'allow_tcp_forwarding' => $this->parseSSHSetting($content, 'AllowTcpForwarding', 'yes'),
            'allow_agent_forwarding' => $this->parseSSHSetting($content, 'AllowAgentForwarding', 'yes'),
            'gateway_ports' => $this->parseSSHSetting($content, 'GatewayPorts', 'no'),
            'banner' => $this->parseSSHSetting($content, 'Banner', ''),
        ];

        // Get SSH service status
        $result = $this->execCommand('systemctl', ['is-active', 'sshd']);
        $settings['service_status'] = trim($result['output']) === 'active' ? 'active' : 'inactive';

        return $this->success($settings);
    }

    /**
     * Update SSH configuration
     */
    protected function actionUpdateSsh(array $params, string $actor): array
    {
        $sshdConfig = '/etc/ssh/sshd_config';
        
        if (!file_exists($sshdConfig)) {
            return $this->error('SSH config file not found');
        }

        // Backup before modification
        $this->backupFile($sshdConfig, 'updateSsh', $actor);

        $content = file_get_contents($sshdConfig);
        $originalContent = $content;

        // Allowed settings to update (SSH directive => param key)
        $allowedSettings = [
            // Connection
            'Port' => 'port',
            'ListenAddress' => 'listen_address',
            'LoginGraceTime' => 'login_grace_time',
            'MaxStartups' => 'max_startups',
            // Authentication
            'PermitRootLogin' => 'permit_root_login',
            'PasswordAuthentication' => 'password_authentication',
            'PubkeyAuthentication' => 'pubkey_authentication',
            'PermitEmptyPasswords' => 'permit_empty_passwords',
            'MaxAuthTries' => 'max_auth_tries',
            'MaxSessions' => 'max_sessions',
            // Access Control
            'AllowUsers' => 'allow_users',
            'DenyUsers' => 'deny_users',
            'AllowGroups' => 'allow_groups',
            'DenyGroups' => 'deny_groups',
            // Security
            'UseDNS' => 'use_dns',
            'TCPKeepAlive' => 'tcp_keep_alive',
            'ClientAliveInterval' => 'client_alive_interval',
            'ClientAliveCountMax' => 'client_alive_count_max',
            // Features
            'X11Forwarding' => 'x11_forwarding',
            'AllowTcpForwarding' => 'allow_tcp_forwarding',
            'AllowAgentForwarding' => 'allow_agent_forwarding',
            'GatewayPorts' => 'gateway_ports',
            'Banner' => 'banner',
        ];

        // Toggle settings (yes/no values)
        $toggleSettings = [
            'PasswordAuthentication', 'PubkeyAuthentication', 'PermitEmptyPasswords',
            'UseDNS', 'TCPKeepAlive', 'X11Forwarding', 'AllowTcpForwarding',
            'AllowAgentForwarding', 'GatewayPorts'
        ];

        $updated = [];

        foreach ($allowedSettings as $sshKey => $paramKey) {
            if (isset($params[$paramKey])) {
                $value = $params[$paramKey];
                
                // Validate specific settings
                if ($sshKey === 'Port' && (!is_numeric($value) || $value < 1 || $value > 65535)) {
                    return $this->error('Invalid port number');
                }
                
                if ($sshKey === 'PermitRootLogin' && !in_array($value, ['yes', 'no', 'prohibit-password', 'forced-commands-only'])) {
                    return $this->error('Invalid PermitRootLogin value');
                }
                
                if (in_array($sshKey, $toggleSettings) && !in_array($value, ['yes', 'no'])) {
                    return $this->error("Invalid {$sshKey} value");
                }

                // Skip empty values for optional settings
                if (empty($value) && in_array($sshKey, ['ListenAddress', 'AllowUsers', 'DenyUsers', 'AllowGroups', 'DenyGroups', 'Banner'])) {
                    continue;
                }

                $content = $this->updateSSHSetting($content, $sshKey, $value);
                $updated[$sshKey] = $value;
            }
        }

        if (empty($updated)) {
            return $this->error('No valid settings to update');
        }

        // Write updated config
        file_put_contents($sshdConfig, $content);

        // Test configuration
        $testResult = $this->execCommand('sshd', ['-t']);
        if (!$testResult['success']) {
            // Restore original config
            file_put_contents($sshdConfig, $originalContent);
            return $this->error('SSH configuration test failed: ' . $testResult['output']);
        }

        // Reload SSH service
        $this->execCommand('systemctl', ['reload', 'sshd']);

        return $this->success([
            'updated' => $updated,
        ], 'SSH configuration updated. Service reloaded.');
    }

    /**
     * Get raw SSH config file
     */
    protected function actionSshRaw(array $params, string $actor): array
    {
        $sshdConfig = '/etc/ssh/sshd_config';
        
        if (!file_exists($sshdConfig)) {
            return $this->error('SSH config file not found');
        }

        return $this->success([
            'path' => $sshdConfig,
            'content' => file_get_contents($sshdConfig),
        ]);
    }

    /**
     * Get swap information
     */
    protected function actionSwap(array $params, string $actor): array
    {
        return $this->success($this->getSwapInfo());
    }

    /**
     * Create swap file
     */
    protected function actionCreateSwap(array $params, string $actor): array
    {
        $size = $params['size'] ?? '2G';
        $path = $params['path'] ?? '/swapfile';
        
        // Validate size format
        if (!preg_match('/^(\d+)(M|G)$/', $size, $matches)) {
            return $this->error('Invalid size format. Use format like 2G or 1024M');
        }

        // Check if swap file already exists
        if (file_exists($path)) {
            return $this->error("Swap file already exists at {$path}");
        }

        // Create swap file
        $result = $this->execCommand('fallocate', ['-l', $size, $path]);
        if (!$result['success']) {
            // Fallback to dd for older systems
            $sizeBytes = $matches[1] * ($matches[2] === 'G' ? 1024 : 1);
            $result = $this->execCommand('dd', ['if=/dev/zero', "of={$path}", 'bs=1M', "count={$sizeBytes}"]);
            if (!$result['success']) {
                return $this->error('Failed to create swap file: ' . $result['output']);
            }
        }

        // Set permissions
        chmod($path, 0600);

        // Make swap
        $result = $this->execCommand('mkswap', [$path]);
        if (!$result['success']) {
            unlink($path);
            return $this->error('Failed to format swap: ' . $result['output']);
        }

        // Enable swap
        $result = $this->execCommand('swapon', [$path]);
        if (!$result['success']) {
            unlink($path);
            return $this->error('Failed to enable swap: ' . $result['output']);
        }

        // Add to fstab for persistence
        $fstabEntry = "{$path} none swap sw 0 0\n";
        $fstab = file_get_contents('/etc/fstab');
        if (strpos($fstab, $path) === false) {
            file_put_contents('/etc/fstab', $fstab . $fstabEntry);
        }

        return $this->success([
            'path' => $path,
            'size' => $size,
            'swap_info' => $this->getSwapInfo(),
        ], "Swap file created at {$path}");
    }

    /**
     * Get or set swappiness
     */
    protected function actionSwappiness(array $params, string $actor): array
    {
        // Get current swappiness
        if (!isset($params['value'])) {
            $current = (int) trim(file_get_contents('/proc/sys/vm/swappiness'));
            return $this->success(['swappiness' => $current]);
        }

        // Set swappiness
        $value = (int) $params['value'];
        if ($value < 0 || $value > 100) {
            return $this->error('Swappiness must be between 0 and 100');
        }

        // Set immediately
        file_put_contents('/proc/sys/vm/swappiness', $value);

        // Make persistent
        $sysctlConf = '/etc/sysctl.d/99-swappiness.conf';
        file_put_contents($sysctlConf, "vm.swappiness = {$value}\n");

        return $this->success([
            'swappiness' => $value,
        ], "Swappiness set to {$value}");
    }

    /**
     * Get uptime information
     */
    protected function actionUptime(array $params, string $actor): array
    {
        return $this->success($this->getUptime());
    }

    /**
     * Reboot the server
     */
    protected function actionReboot(array $params, string $actor): array
    {
        $delay = $params['delay'] ?? 0;
        
        if ($delay > 0) {
            $this->execCommand('shutdown', ['-r', "+{$delay}"]);
            return $this->success([
                'scheduled' => true,
                'delay_minutes' => $delay,
            ], "Reboot scheduled in {$delay} minutes");
        }

        // Immediate reboot in background
        exec('nohup shutdown -r now > /dev/null 2>&1 &');
        
        return $this->success([
            'scheduled' => true,
            'delay_minutes' => 0,
        ], 'Server rebooting now');
    }

    // ========================================
    // Helper methods
    // ========================================

    private function getOsInfo(): array
    {
        $osRelease = [];
        if (file_exists('/etc/os-release')) {
            $lines = file('/etc/os-release', FILE_IGNORE_NEW_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $osRelease[strtolower($key)] = trim($value, '"');
                }
            }
        }

        return [
            'name' => $osRelease['name'] ?? 'Linux',
            'version' => $osRelease['version'] ?? '',
            'id' => $osRelease['id'] ?? 'linux',
            'pretty_name' => $osRelease['pretty_name'] ?? 'Linux',
        ];
    }

    private function getUptime(): array
    {
        $uptime = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
        
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = floor($uptime % 60);

        return [
            'seconds' => $uptime,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'formatted' => "{$days}d {$hours}h {$minutes}m",
            'since' => date('Y-m-d H:i:s', time() - (int)$uptime),
        ];
    }

    private function getMemoryInfo(): array
    {
        $meminfo = [];
        $lines = file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $meminfo[$matches[1]] = (int) $matches[2] * 1024; // Convert to bytes
            }
        }

        $total = $meminfo['MemTotal'] ?? 0;
        $available = $meminfo['MemAvailable'] ?? $meminfo['MemFree'] ?? 0;
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'percent_used' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            'total_human' => $this->humanSize($total),
            'used_human' => $this->humanSize($used),
            'available_human' => $this->humanSize($available),
        ];
    }

    private function getSwapInfo(): array
    {
        $meminfo = [];
        $lines = file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $meminfo[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $meminfo['SwapTotal'] ?? 0;
        $free = $meminfo['SwapFree'] ?? 0;
        $used = $total - $free;

        // Get swap files
        $swapFiles = [];
        if (file_exists('/proc/swaps')) {
            $lines = file('/proc/swaps', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            array_shift($lines); // Remove header
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 5) {
                    $swapFiles[] = [
                        'path' => $parts[0],
                        'type' => $parts[1],
                        'size' => (int)$parts[2] * 1024,
                        'used' => (int)$parts[3] * 1024,
                        'priority' => (int)$parts[4],
                    ];
                }
            }
        }

        // Get swappiness
        $swappiness = (int) trim(@file_get_contents('/proc/sys/vm/swappiness') ?: '60');

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent_used' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            'total_human' => $this->humanSize($total),
            'used_human' => $this->humanSize($used),
            'free_human' => $this->humanSize($free),
            'swappiness' => $swappiness,
            'swap_files' => $swapFiles,
            'has_swap' => $total > 0,
        ];
    }

    private function getDiskInfo(): array
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent_used' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            'total_human' => $this->humanSize($total),
            'used_human' => $this->humanSize($used),
            'free_human' => $this->humanSize($free),
        ];
    }

    private function getCpuInfo(): array
    {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        
        $cores = substr_count($cpuinfo, 'processor');
        
        $model = 'Unknown';
        if (preg_match('/model name\s*:\s*(.+)$/m', $cpuinfo, $matches)) {
            $model = trim($matches[1]);
        }

        $mhz = null;
        if (preg_match('/cpu MHz\s*:\s*([\d.]+)/m', $cpuinfo, $matches)) {
            $mhz = round((float)$matches[1]);
        }

        return [
            'model' => $model,
            'cores' => $cores,
            'mhz' => $mhz,
            'load' => sys_getloadavg(),
        ];
    }

    private function getFqdn(): string
    {
        $result = $this->execCommand('hostname', ['-f']);
        return trim($result['output']) ?: gethostname();
    }

    private function parseSSHSetting(string $content, string $key, $default)
    {
        // Match both uncommented and commented lines, prefer uncommented
        if (preg_match('/^' . preg_quote($key, '/') . '\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        // Check for commented default
        if (preg_match('/^#' . preg_quote($key, '/') . '\s+(.+)$/m', $content, $matches)) {
            return $default; // Return default if only commented version exists
        }
        return $default;
    }

    private function updateSSHSetting(string $content, string $key, $value): string
    {
        $pattern = '/^#?' . preg_quote($key, '/') . '\s+.+$/m';
        $replacement = "{$key} {$value}";
        
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $replacement, $content);
        }
        
        // Add setting at the end if not found
        return $content . "\n{$replacement}\n";
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // ============================================
    // PowerDNS Configuration
    // ============================================

    private string $pdnsConfigPath = '/etc/powerdns/pdns.conf';

    /**
     * Get PowerDNS configuration
     */
    protected function actionPdns(array $params, string $actor): array
    {
        if (!file_exists($this->pdnsConfigPath)) {
            return $this->error('PowerDNS configuration file not found at ' . $this->pdnsConfigPath);
        }

        $content = file_get_contents($this->pdnsConfigPath);
        $parsed = $this->parsePdnsConfig($content);

        return $this->success([
            'config_path' => $this->pdnsConfigPath,
            'raw' => $content,
            'parsed' => $parsed,
        ]);
    }

    /**
     * Update PowerDNS configuration
     */
    protected function actionUpdatePdns(array $params, string $actor): array
    {
        if (!isset($params['config'])) {
            return $this->error('Configuration content is required');
        }

        $newConfig = $params['config'];

        // Validate the configuration syntax
        $validation = $this->validatePdnsConfig($newConfig);
        if (!$validation['valid']) {
            return $this->error('Invalid configuration: ' . $validation['error']);
        }

        // Backup current config
        $this->backupFile($this->pdnsConfigPath, 'pdns_config', $actor);

        // Write new config to temp file first
        $tempFile = '/tmp/pdns.conf.test.' . time();
        file_put_contents($tempFile, $newConfig);

        // Test the configuration with pdns_server --config-check (if available)
        $testResult = $this->testPdnsConfig($tempFile);
        if (!$testResult['success']) {
            unlink($tempFile);
            return $this->error('Configuration test failed: ' . $testResult['error']);
        }

        // Apply the new configuration
        if (!copy($tempFile, $this->pdnsConfigPath)) {
            unlink($tempFile);
            return $this->error('Failed to write configuration file');
        }
        unlink($tempFile);

        // Restart PowerDNS to apply changes
        $restartResult = $this->execCommand('systemctl', ['restart', 'pdns']);
        
        // Wait a moment and check if it's running
        sleep(1);
        $statusResult = $this->execCommand('systemctl', ['is-active', 'pdns']);
        $isRunning = (strpos($statusResult['output'], 'active') !== false);

        if (!$isRunning) {
            // Restore backup if restart failed
            $backupDir = $this->config['paths']['backups'] ?? '/var/www/vps-admin/backups';
            $latestBackup = glob($backupDir . '/pdns_config_*.bak');
            if (!empty($latestBackup)) {
                rsort($latestBackup);
                copy($latestBackup[0], $this->pdnsConfigPath);
                $this->execCommand('systemctl', ['restart', 'pdns']);
            }
            return $this->error('PowerDNS failed to start with new configuration. Previous config restored.');
        }

        return $this->success([
            'message' => 'PowerDNS configuration updated successfully',
            'service_status' => 'running',
        ]);
    }

    /**
     * Get PowerDNS service status
     */
    protected function actionPdnsStatus(array $params, string $actor): array
    {
        // Check if service is running
        $statusResult = $this->execCommand('systemctl', ['status', 'pdns', '--no-pager']);
        $isActive = $this->execCommand('systemctl', ['is-active', 'pdns']);
        $running = (strpos($isActive['output'], 'active') !== false);

        // Get version
        $versionResult = $this->execCommand('pdns_server', ['--version']);
        $version = null;
        if (preg_match('/PowerDNS.*?(\d+\.\d+(?:\.\d+)?)/i', $versionResult['output'], $matches)) {
            $version = $matches[1];
        }

        // Get PID
        $pid = null;
        if (file_exists('/run/pdns/pdns.pid')) {
            $pid = (int) trim(file_get_contents('/run/pdns/pdns.pid'));
        }

        // Test DNS - check if PowerDNS responds to queries at all
        // We use 'version.pdns' CH TXT which always works on any PowerDNS server
        $dnsWorking = false;
        $testResult = $this->execCommand('dig', ['@127.0.0.1', 'version.pdns', 'CH', 'TXT', '+short', '+time=2']);
        // Consider it working if dig exits cleanly (even with empty response means server is responding)
        if ($testResult['code'] === 0) {
            $dnsWorking = true;
        } else {
            // Fallback: try pdns_control which talks directly to the daemon
            $pdnsControl = $this->execCommand('pdns_control', ['ping']);
            $dnsWorking = ($pdnsControl['code'] === 0 && strpos($pdnsControl['output'], 'PONG') !== false);
        }

        return $this->success([
            'running' => $running,
            'version' => $version,
            'pid' => $pid,
            'dns_responding' => $dnsWorking,
            'status_output' => $statusResult['output'],
        ]);
    }

    /**
     * Parse PowerDNS config into structured format
     */
    private function parsePdnsConfig(string $content): array
    {
        $settings = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Parse key=value format
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $settings[trim($key)] = trim($value);
            }
        }

        return $settings;
    }

    /**
     * Validate PowerDNS config syntax
     */
    private function validatePdnsConfig(string $content): array
    {
        $lines = explode("\n", $content);
        $requiredSettings = ['launch']; // At minimum, launch must be set
        $foundSettings = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Check for valid format (key=value or just key for some settings)
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $foundSettings[] = $key;

                // Validate specific settings
                if ($key === 'gmysql-port' && !is_numeric(trim($value))) {
                    return ['valid' => false, 'error' => "Line " . ($lineNum + 1) . ": gmysql-port must be numeric"];
                }
            } else {
                // Some settings might be just key (like launch=gmysql without equals sometimes)
                $foundSettings[] = $line;
            }
        }

        // Check required settings
        foreach ($requiredSettings as $required) {
            if (!in_array($required, $foundSettings)) {
                return ['valid' => false, 'error' => "Missing required setting: {$required}"];
            }
        }

        return ['valid' => true];
    }

    /**
     * Test PowerDNS config file
     */
    private function testPdnsConfig(string $configPath): array
    {
        // Unfortunately pdns_server doesn't have a --config-check like nginx
        // We can try to parse it and check for obvious errors
        $content = file_get_contents($configPath);
        $validation = $this->validatePdnsConfig($content);
        
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        return ['success' => true];
    }

    // ============================================
    // MOTD (Message of the Day) Management
    // ============================================

    private string $motdPath = '/etc/motd';
    private string $motdScriptsPath = '/etc/update-motd.d';

    /**
     * Get current MOTD content
     */
    protected function actionMotd(array $params, string $actor): array
    {
        // Check for dynamic MOTD scripts (CyberPanel uses this)
        $scripts = [];
        if (is_dir($this->motdScriptsPath)) {
            $files = scandir($this->motdScriptsPath);
            foreach ($files as $file) {
                if ($file[0] === '.' || !is_file($this->motdScriptsPath . '/' . $file)) {
                    continue;
                }
                $scripts[] = [
                    'name' => $file,
                    'executable' => is_executable($this->motdScriptsPath . '/' . $file),
                    'content' => file_get_contents($this->motdScriptsPath . '/' . $file),
                ];
            }
            // Sort by name (they typically have numeric prefixes like 00-header, 10-help)
            usort($scripts, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        // Get static MOTD
        $staticMotd = '';
        if (file_exists($this->motdPath)) {
            $staticMotd = file_get_contents($this->motdPath);
        }

        // Generate current MOTD output by running the scripts
        $currentOutput = '';
        if (!empty($scripts)) {
            $result = $this->execCommand('run-parts', ['--lsbsysinit', $this->motdScriptsPath]);
            $currentOutput = $result['output'] ?? '';
        } elseif (!empty($staticMotd)) {
            $currentOutput = $staticMotd;
        }

        return $this->success([
            'static_motd' => $staticMotd,
            'static_path' => $this->motdPath,
            'scripts' => $scripts,
            'scripts_path' => $this->motdScriptsPath,
            'current_output' => $currentOutput,
            'has_dynamic' => !empty($scripts),
        ]);
    }

    /**
     * Update MOTD content
     */
    protected function actionUpdateMotd(array $params, string $actor): array
    {
        // Type: 'static' or 'script'
        $type = $params['type'] ?? 'static';
        
        if ($type === 'static') {
            // Update static MOTD
            if (!isset($params['content'])) {
                return $this->error('Content is required for static MOTD');
            }
            
            // Backup current MOTD
            $this->backupFile($this->motdPath, 'motd', $actor);
            
            file_put_contents($this->motdPath, $params['content']);
            
            return $this->success([
                'type' => 'static',
                'path' => $this->motdPath,
            ], 'Static MOTD updated successfully');
        }
        
        if ($type === 'script') {
            // Update a dynamic MOTD script
            if (!isset($params['name']) || !isset($params['content'])) {
                return $this->error('Script name and content are required');
            }
            
            $name = basename($params['name']); // Sanitize
            $scriptPath = $this->motdScriptsPath . '/' . $name;
            
            // Backup if exists
            if (file_exists($scriptPath)) {
                $this->backupFile($scriptPath, 'motd_script', $actor);
            }
            
            // Ensure directory exists
            if (!is_dir($this->motdScriptsPath)) {
                mkdir($this->motdScriptsPath, 0755, true);
            }
            
            file_put_contents($scriptPath, $params['content']);
            chmod($scriptPath, 0755); // Make executable
            
            return $this->success([
                'type' => 'script',
                'name' => $name,
                'path' => $scriptPath,
            ], "MOTD script '{$name}' updated successfully");
        }
        
        if ($type === 'disable_cyberpanel') {
            // Disable CyberPanel's dynamic MOTD
            $cyberScript = $this->motdScriptsPath . '/00-cyberpanel';
            if (file_exists($cyberScript)) {
                $this->backupFile($cyberScript, 'motd_script', $actor);
                chmod($cyberScript, 0644); // Remove execute permission
                return $this->success([
                    'disabled' => '00-cyberpanel',
                ], 'CyberPanel MOTD disabled');
            }
            return $this->error('CyberPanel MOTD script not found');
        }
        
        return $this->error('Invalid type. Use "static", "script", or "disable_cyberpanel"');
    }

    // ============================================
    // HTML Templates Management
    // ============================================

    private string $templatesPath = '/var/www/vps-admin/templates';
    
    // Standard template types
    private array $templateTypes = [
        'error_404' => [
            'name' => '404 Not Found',
            'description' => 'Displayed when a page is not found',
            'filename' => '404.html',
        ],
        'error_403' => [
            'name' => '403 Forbidden',
            'description' => 'Displayed when access is denied',
            'filename' => '403.html',
        ],
        'error_500' => [
            'name' => '500 Server Error',
            'description' => 'Displayed when server encounters an error',
            'filename' => '500.html',
        ],
        'error_503' => [
            'name' => '503 Service Unavailable',
            'description' => 'Displayed during maintenance',
            'filename' => '503.html',
        ],
        'site_placeholder' => [
            'name' => 'New Site Placeholder',
            'description' => 'Default page for newly created sites',
            'filename' => 'placeholder.html',
        ],
        'site_coming_soon' => [
            'name' => 'Coming Soon',
            'description' => 'Coming soon / under construction page',
            'filename' => 'coming-soon.html',
        ],
        'site_maintenance' => [
            'name' => 'Maintenance Mode',
            'description' => 'Displayed when site is in maintenance mode',
            'filename' => 'maintenance.html',
        ],
    ];

    /**
     * List all available templates
     */
    protected function actionTemplates(array $params, string $actor): array
    {
        // Ensure templates directory exists
        if (!is_dir($this->templatesPath)) {
            mkdir($this->templatesPath, 0755, true);
        }

        $templates = [];
        foreach ($this->templateTypes as $id => $info) {
            $filePath = $this->templatesPath . '/' . $info['filename'];
            $templates[$id] = [
                'id' => $id,
                'name' => $info['name'],
                'description' => $info['description'],
                'filename' => $info['filename'],
                'exists' => file_exists($filePath),
                'size' => file_exists($filePath) ? filesize($filePath) : 0,
                'modified' => file_exists($filePath) ? date('Y-m-d H:i:s', filemtime($filePath)) : null,
            ];
        }

        // Also list any custom templates
        $customTemplates = [];
        $files = glob($this->templatesPath . '/*.html');
        foreach ($files as $file) {
            $filename = basename($file);
            // Check if it's not a standard template
            $isStandard = false;
            foreach ($this->templateTypes as $info) {
                if ($info['filename'] === $filename) {
                    $isStandard = true;
                    break;
                }
            }
            if (!$isStandard) {
                $customTemplates[] = [
                    'filename' => $filename,
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }

        return $this->success([
            'templates' => $templates,
            'custom' => $customTemplates,
            'path' => $this->templatesPath,
        ]);
    }

    /**
     * Get a specific template content
     */
    protected function actionGetTemplate(array $params, string $actor): array
    {
        if (!isset($params['id']) && !isset($params['filename'])) {
            return $this->error('Template ID or filename is required');
        }

        $filename = null;
        $templateInfo = null;

        if (isset($params['id'])) {
            if (!isset($this->templateTypes[$params['id']])) {
                return $this->error('Invalid template ID');
            }
            $templateInfo = $this->templateTypes[$params['id']];
            $filename = $templateInfo['filename'];
        } else {
            $filename = basename($params['filename']); // Sanitize
        }

        $filePath = $this->templatesPath . '/' . $filename;
        $content = '';
        
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
        } else {
            // Return default template content
            $content = $this->getDefaultTemplateContent($params['id'] ?? $filename);
        }

        return $this->success([
            'id' => $params['id'] ?? null,
            'filename' => $filename,
            'info' => $templateInfo,
            'content' => $content,
            'exists' => file_exists($filePath),
            'path' => $filePath,
        ]);
    }

    /**
     * Update/create a template
     */
    protected function actionUpdateTemplate(array $params, string $actor): array
    {
        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }

        if (!isset($params['id']) && !isset($params['filename'])) {
            return $this->error('Template ID or filename is required');
        }

        $filename = null;
        if (isset($params['id'])) {
            if (!isset($this->templateTypes[$params['id']])) {
                return $this->error('Invalid template ID');
            }
            $filename = $this->templateTypes[$params['id']]['filename'];
        } else {
            $filename = basename($params['filename']); // Sanitize
            if (!str_ends_with($filename, '.html')) {
                $filename .= '.html';
            }
        }

        // Ensure templates directory exists
        if (!is_dir($this->templatesPath)) {
            mkdir($this->templatesPath, 0755, true);
        }

        $filePath = $this->templatesPath . '/' . $filename;

        // Backup if exists
        if (file_exists($filePath)) {
            $this->backupFile($filePath, 'template', $actor);
        }

        // Write content
        file_put_contents($filePath, $params['content']);

        // Also copy to OpenLiteSpeed error pages directory if it's an error template
        $this->deployErrorTemplate($params['id'] ?? null, $filename, $params['content']);

        return $this->success([
            'filename' => $filename,
            'path' => $filePath,
            'size' => strlen($params['content']),
        ], "Template '{$filename}' saved successfully");
    }

    /**
     * Deploy error template to all sites
     * OLS has hardcoded error pages in binary, so we must deploy to each site's /error/ folder
     * and configure errorPage blocks in vhost.conf
     */
    private function deployErrorTemplate(?string $id, string $filename, string $content): void
    {
        if ($id === null) return;

        $errorCode = null;
        switch ($id) {
            case 'error_404': $errorCode = '404'; break;
            case 'error_403': $errorCode = '403'; break;
            case 'error_500': $errorCode = '500'; break;
            case 'error_503': $errorCode = '503'; break;
            default: return;
        }

        // Deploy to all sites
        $this->deployErrorTemplateToAllSites($errorCode, $content);
        
        // Graceful reload OLS to apply changes
        $olsBin = '/usr/local/lsws/bin/lswsctrl';
        if (file_exists($olsBin)) {
            exec("{$olsBin} reload 2>&1", $output, $exitCode);
            $this->logger->info("OLS reloaded after error template deploy", [
                'exitCode' => $exitCode,
            ]);
        }
    }

    /**
     * Deploy error template to all sites (HTML files only, no vhost.conf changes)
     * vhost.conf errorPage blocks are set once during site creation
     */
    private function deployErrorTemplateToAllSites(string $errorCode, string $content): void
    {
        $homeDirs = glob('/home/*', GLOB_ONLYDIR);
        
        foreach ($homeDirs as $homeDir) {
            $publicHtml = $homeDir . '/public_html';
            if (!is_dir($publicHtml)) continue;
            
            $domain = basename($homeDir);
            
            // Create error directory
            $errorDir = "{$publicHtml}/error";
            if (!is_dir($errorDir)) {
                mkdir($errorDir, 0755, true);
            }
            
            // Write error page
            $errorFile = "{$errorDir}/{$errorCode}.html";
            if (file_put_contents($errorFile, $content) !== false) {
                // Set ownership
                if (posix_getpwnam($domain)) {
                    @chown($errorDir, $domain);
                    @chgrp($errorDir, $domain);
                    @chown($errorFile, $domain);
                    @chgrp($errorFile, $domain);
                }
                
                $this->logger->info("Deployed {$errorCode} error page to site", [
                    'domain' => $domain,
                    'path' => $errorFile,
                ]);
            }
        }
    }

    /**
     * Get default template content for a type
     */
    private function getDefaultTemplateContent(string $id): string
    {
        $defaults = [
            'error_404' => $this->getDefault404Template(),
            'error_403' => $this->getDefault403Template(),
            'error_500' => $this->getDefaultErrorTemplate('500', 'Internal Server Error', 'The server encountered an unexpected condition.'),
            'error_503' => $this->getDefaultErrorTemplate('503', 'Service Unavailable', 'The server is temporarily unable to handle your request.'),
            'site_placeholder' => $this->getDefaultPlaceholderTemplate(),
            'site_coming_soon' => $this->getDefaultComingSoonTemplate(),
            'site_maintenance' => $this->getDefaultMaintenanceTemplate(),
        ];

        return $defaults[$id] ?? $this->getDefaultPlaceholderTemplate();
    }

    private function getDefault404Template(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e8e8e8;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 1.5rem;
            color: #a0aec0;
            margin-bottom: 1rem;
        }
        .error-message {
            color: #718096;
            margin-bottom: 2rem;
            max-width: 400px;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.4);
        }
        .footer {
            position: fixed;
            bottom: 1.5rem;
            left: 0;
            right: 0;
            text-align: center;
            color: #4a5568;
            font-size: 0.75rem;
        }
        .footer .heart { color: #e53e3e; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-message">The page you're looking for doesn't exist or has been moved.</p>
        <a href="/" class="btn">Back to Home</a>
    </div>
    <div class="footer">Server powered by DEVCON Panel. A product of <span class="heart">&#10084;</span> Pixel Ranger Studio.</div>
</body>
</html>
HTML;
    }

    private function getDefault403Template(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Forbidden</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e8e8e8;
        }
        .container {
            text-align: center;
            padding: 2rem;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 1.5rem;
            color: #a0aec0;
            margin-bottom: 1rem;
        }
        .error-message {
            color: #718096;
            margin-bottom: 2rem;
            max-width: 400px;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(245, 87, 108, 0.4);
        }
        .footer {
            position: fixed;
            bottom: 1.5rem;
            left: 0;
            right: 0;
            text-align: center;
            color: #4a5568;
            font-size: 0.75rem;
        }
        .footer .heart { color: #e53e3e; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-code">403</div>
        <h1 class="error-title">Access Forbidden</h1>
        <p class="error-message">You don't have permission to access this resource.</p>
        <a href="/" class="btn">Back to Home</a>
    </div>
    <div class="footer">Server powered by DEVCON Panel. A product of <span class="heart">&#10084;</span> Pixel Ranger Studio.</div>
</body>
</html>
HTML;
    }

    private function getDefaultErrorTemplate(string $code, string $title, string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$code} - {$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e8e8e8;
        }
        .container { text-align: center; padding: 2rem; }
        .error-code {
            font-size: 8rem;
            font-weight: 800;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 1rem;
        }
        .error-title { font-size: 1.5rem; color: #a0aec0; margin-bottom: 1rem; }
        .error-message { color: #718096; margin-bottom: 2rem; max-width: 400px; }
        .btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: #1a1a2e;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 40px rgba(250, 112, 154, 0.4); }
        .footer {
            position: fixed;
            bottom: 1.5rem;
            left: 0;
            right: 0;
            text-align: center;
            color: #4a5568;
            font-size: 0.75rem;
        }
        .footer .heart { color: #e53e3e; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-code">{$code}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-message">{$message}</p>
        <a href="/" class="btn">Back to Home</a>
    </div>
    <div class="footer">Server powered by DEVCON Panel. A product of <span class="heart">&#10084;</span> Pixel Ranger Studio.</div>
</body>
</html>
HTML;
    }

    private function getDefaultPlaceholderTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0c0c0c 0%, #1a1a2e 50%, #16213e 100%);
            color: #e8e8e8;
        }
        .container { text-align: center; padding: 2rem; }
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #00d9ff 0%, #7c3aed 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
        }
        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #00d9ff 0%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }
        .subtitle {
            font-size: 1.25rem;
            color: #a0aec0;
            margin-bottom: 2rem;
        }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 50px;
            color: #10b981;
            font-size: 0.875rem;
        }
        .status::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">S</div>
        <h1>Site Ready</h1>
        <p class="subtitle">Your website has been successfully configured.</p>
        <div class="status">Server is running</div>
    </div>
</body>
</html>
HTML;
    }

    private function getDefaultComingSoonTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coming Soon</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            color: #e8e8e8;
        }
        .container { text-align: center; padding: 2rem; max-width: 600px; }
        .icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        h1 {
            font-size: 3rem;
            font-weight: 700;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 50%, #4facfe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }
        .subtitle {
            font-size: 1.25rem;
            color: #a0aec0;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .notify-form {
            display: flex;
            gap: 0.5rem;
            max-width: 400px;
            margin: 0 auto;
        }
        .notify-form input {
            flex: 1;
            padding: 0.875rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50px;
            color: white;
            font-size: 1rem;
        }
        .notify-form input::placeholder { color: rgba(255, 255, 255, 0.5); }
        .notify-form button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            border-radius: 50px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .notify-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(245, 87, 108, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">&#128640;</div>
        <h1>Coming Soon</h1>
        <p class="subtitle">We're working hard to bring you something amazing. Stay tuned!</p>
        <form class="notify-form" onsubmit="return false;">
            <input type="email" placeholder="Enter your email">
            <button type="submit">Notify Me</button>
        </form>
    </div>
</body>
</html>
HTML;
    }

    private function getDefaultMaintenanceTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e8e8e8;
        }
        .container { text-align: center; padding: 2rem; }
        .icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 2rem;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            animation: spin 4s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }
        .subtitle {
            font-size: 1.125rem;
            color: #a0aec0;
            margin-bottom: 2rem;
            max-width: 400px;
        }
        .eta {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: rgba(250, 112, 154, 0.1);
            border: 1px solid rgba(250, 112, 154, 0.3);
            border-radius: 10px;
            color: #fa709a;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">&#9881;</div>
        <h1>Under Maintenance</h1>
        <p class="subtitle">We're performing scheduled maintenance. We'll be back online shortly.</p>
        <div class="eta">Estimated time: ~30 minutes</div>
    </div>
</body>
</html>
HTML;
    }

    // ============================================
    // Template Deployment Actions
    // ============================================

    /**
     * Apply a template to a specific site
     */
    protected function actionApplyTemplateToSite(array $params, string $actor): array
    {
        if (!isset($params['template_id']) || !isset($params['domain'])) {
            return $this->error('Template ID and domain are required');
        }

        $templateId = $params['template_id'];
        $domain = $params['domain'];
        $filename = $params['filename'] ?? 'index.html';

        // Get template content
        $templateContent = null;
        
        // Check if it's a standard template
        if (isset($this->templateTypes[$templateId])) {
            $templateFile = $this->templatesPath . '/' . $this->templateTypes[$templateId]['filename'];
            if (file_exists($templateFile)) {
                $templateContent = file_get_contents($templateFile);
            } else {
                // Use default template
                $templateContent = $this->getDefaultTemplateContent($templateId);
            }
        } else {
            // Try custom template file
            $templateFile = $this->templatesPath . '/' . $templateId;
            if (file_exists($templateFile)) {
                $templateContent = file_get_contents($templateFile);
            }
        }

        if ($templateContent === null) {
            return $this->error("Template '{$templateId}' not found");
        }

        // Find site document root
        $siteRoot = "/home/{$domain}/public_html";
        if (!is_dir($siteRoot)) {
            return $this->error("Site directory not found: {$siteRoot}");
        }

        // Replace placeholders in template
        $templateContent = str_replace(
            ['{{domain}}', '{{DOMAIN}}', '{domain}', '{DOMAIN}'],
            $domain,
            $templateContent
        );

        // Backup existing file if it exists
        $targetFile = "{$siteRoot}/{$filename}";
        $backupFilename = null;
        if (file_exists($targetFile)) {
            $backupFilename = $filename . '.backup.' . date('Y-m-d_His');
            $backupFile = "{$siteRoot}/{$backupFilename}";
            copy($targetFile, $backupFile);
        }

        // Write template
        file_put_contents($targetFile, $templateContent);

        // Set correct ownership
        $siteUser = $domain;
        if (posix_getpwnam($siteUser)) {
            chown($targetFile, $siteUser);
            chgrp($targetFile, $siteUser);
        }

        // Record deployment in database (only for site templates)
        if (in_array($templateId, ['site_placeholder', 'site_coming_soon', 'site_maintenance'])) {
            $this->recordTemplateDeployment($domain, $templateId, $backupFilename ?? '', $actor);
        }

        $this->logger->info("Applied template to site", [
            'template' => $templateId,
            'domain' => $domain,
            'file' => $targetFile,
            'actor' => $actor,
        ]);

        return $this->success([
            'domain' => $domain,
            'template' => $templateId,
            'file' => $targetFile,
            'backup_file' => $backupFilename,
        ], "Template '{$templateId}' applied to {$domain}");
    }

    /**
     * Deploy a template to all sites
     */
    protected function actionDeployTemplateToAllSites(array $params, string $actor): array
    {
        if (!isset($params['template_id'])) {
            return $this->error('Template ID is required');
        }

        $templateId = $params['template_id'];
        $filename = $params['filename'] ?? 'index.html';
        $skipExisting = $params['skip_existing'] ?? true;

        // Get template content
        $templateContent = null;
        
        if (isset($this->templateTypes[$templateId])) {
            $templateFile = $this->templatesPath . '/' . $this->templateTypes[$templateId]['filename'];
            if (file_exists($templateFile)) {
                $templateContent = file_get_contents($templateFile);
            } else {
                $templateContent = $this->getDefaultTemplateContent($templateId);
            }
        } else {
            $templateFile = $this->templatesPath . '/' . $templateId;
            if (file_exists($templateFile)) {
                $templateContent = file_get_contents($templateFile);
            }
        }

        if ($templateContent === null) {
            return $this->error("Template '{$templateId}' not found");
        }

        // Find all sites in /home
        $sites = [];
        $deployed = [];
        $skipped = [];
        $failed = [];

        $homeDirs = glob('/home/*', GLOB_ONLYDIR);
        foreach ($homeDirs as $homeDir) {
            $publicHtml = $homeDir . '/public_html';
            if (is_dir($publicHtml)) {
                $domain = basename($homeDir);
                $sites[] = $domain;

                $targetFile = "{$publicHtml}/{$filename}";
                
                // Skip if file exists and skipExisting is true
                if ($skipExisting && file_exists($targetFile)) {
                    $skipped[] = $domain;
                    continue;
                }

                // Replace domain placeholder
                $siteContent = str_replace(
                    ['{{domain}}', '{{DOMAIN}}', '{domain}', '{DOMAIN}'],
                    $domain,
                    $templateContent
                );

                // Backup if overwriting
                $backupFilename = null;
                if (file_exists($targetFile)) {
                    $backupFilename = $filename . '.backup.' . date('Y-m-d_His');
                    $backupFile = "{$publicHtml}/{$backupFilename}";
                    copy($targetFile, $backupFile);
                }

                // Write template
                if (file_put_contents($targetFile, $siteContent) !== false) {
                    // Set ownership
                    if (posix_getpwnam($domain)) {
                        @chown($targetFile, $domain);
                        @chgrp($targetFile, $domain);
                    }
                    $deployed[] = $domain;
                    
                    // Record deployment in database (only for site templates)
                    if (in_array($templateId, ['site_placeholder', 'site_coming_soon', 'site_maintenance'])) {
                        $this->recordTemplateDeployment($domain, $templateId, $backupFilename ?? '', $actor);
                    }
                } else {
                    $failed[] = $domain;
                }
            }
        }

        $this->logger->info("Deployed template to all sites", [
            'template' => $templateId,
            'deployed' => count($deployed),
            'skipped' => count($skipped),
            'failed' => count($failed),
            'actor' => $actor,
        ]);

        return $this->success([
            'template' => $templateId,
            'filename' => $filename,
            'total_sites' => count($sites),
            'deployed' => $deployed,
            'skipped' => $skipped,
            'failed' => $failed,
        ], sprintf(
            "Template deployed to %d sites (%d skipped, %d failed)",
            count($deployed),
            count($skipped),
            count($failed)
        ));
    }

    /**
     * List all sites for template deployment
     */
    protected function actionListSitesForTemplate(array $params, string $actor): array
    {
        $sites = [];
        $homeDirs = glob('/home/*', GLOB_ONLYDIR);
        
        // Get all deployment records from database
        $deployments = [];
        $pdo = $this->getConnection();
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT domain, template_type, deployed_at, deployed_by FROM template_deployments");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $deployments[$row['domain']] = $row;
                }
            } catch (\Exception $e) {
                // Continue without database info
            }
        }
        
        foreach ($homeDirs as $homeDir) {
            $publicHtml = $homeDir . '/public_html';
            if (is_dir($publicHtml)) {
                $domain = basename($homeDir);
                $indexExists = file_exists($publicHtml . '/index.html') || file_exists($publicHtml . '/index.php');
                
                // Check for template backups
                $backupPattern = $publicHtml . '/index.html.backup.*';
                $backupFiles = glob($backupPattern);
                $hasTemplateBackup = !empty($backupFiles);
                $backupCount = count($backupFiles);
                $latestBackup = null;
                
                if ($hasTemplateBackup) {
                    // Get the most recent backup
                    usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                    $latestBackup = [
                        'filename' => basename($backupFiles[0]),
                        'timestamp' => filemtime($backupFiles[0]),
                        'date' => date('Y-m-d H:i:s', filemtime($backupFiles[0])),
                    ];
                }
                
                // Get template type from database
                $deployment = $deployments[$domain] ?? null;
                $templateType = $deployment['template_type'] ?? null;
                $deployedAt = $deployment['deployed_at'] ?? null;
                $deployedBy = $deployment['deployed_by'] ?? null;
                
                $sites[] = [
                    'domain' => $domain,
                    'path' => $publicHtml,
                    'has_index' => $indexExists,
                    'has_template_backup' => $hasTemplateBackup,
                    'backup_count' => $backupCount,
                    'latest_backup' => $latestBackup,
                    'template_type' => $templateType,
                    'deployed_at' => $deployedAt,
                    'deployed_by' => $deployedBy,
                ];
            }
        }

        return $this->success([
            'sites' => $sites,
            'count' => count($sites),
        ]);
    }

    /**
     * List template backups for a site
     */
    protected function actionListTemplateBackups(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $filename = $params['filename'] ?? 'index.html';
        $siteRoot = "/home/{$domain}/public_html";

        if (!is_dir($siteRoot)) {
            return $this->error("Site directory not found: {$siteRoot}");
        }

        // Find all backup files for this filename
        $backups = [];
        $pattern = "{$siteRoot}/{$filename}.backup.*";
        $backupFiles = glob($pattern);

        foreach ($backupFiles as $backupFile) {
            $backups[] = [
                'path' => $backupFile,
                'filename' => basename($backupFile),
                'size' => filesize($backupFile),
                'created' => date('Y-m-d H:i:s', filemtime($backupFile)),
                'timestamp' => filemtime($backupFile),
            ];
        }

        // Sort by timestamp descending (newest first)
        usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);

        // Check if current file exists
        $currentFile = "{$siteRoot}/{$filename}";
        $hasCurrentFile = file_exists($currentFile);

        return $this->success([
            'domain' => $domain,
            'filename' => $filename,
            'backups' => $backups,
            'has_current_file' => $hasCurrentFile,
            'current_file_size' => $hasCurrentFile ? filesize($currentFile) : 0,
        ]);
    }

    /**
     * Revert template - restore backup and remove current file
     */
    protected function actionRevertTemplate(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $filename = $params['filename'] ?? 'index.html';
        $backupFile = $params['backup_file'] ?? null; // Specific backup to restore
        $siteRoot = "/home/{$domain}/public_html";

        if (!is_dir($siteRoot)) {
            return $this->error("Site directory not found: {$siteRoot}");
        }

        $targetFile = "{$siteRoot}/{$filename}";
        $restoredFrom = null;

        // Find the backup to restore
        if ($backupFile) {
            // Use specific backup file
            $restoreFrom = "{$siteRoot}/{$backupFile}";
            if (!file_exists($restoreFrom)) {
                return $this->error("Backup file not found: {$backupFile}");
            }
            if (!copy($restoreFrom, $targetFile)) {
                return $this->error("Failed to restore backup");
            }
            $restoredFrom = basename($restoreFrom);
        } else {
            // Find the most recent backup
            $pattern = "{$siteRoot}/{$filename}.backup.*";
            $backupFiles = glob($pattern);

            if (!empty($backupFiles)) {
                // Restore from backup
                usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
                $restoreFrom = $backupFiles[0];
                if (!copy($restoreFrom, $targetFile)) {
                    return $this->error("Failed to restore backup");
                }
                $restoredFrom = basename($restoreFrom);
            } else {
                // No backup exists — regenerate the default placeholder page
                $defaultContent = $this->generateDefaultPlaceholder($domain);
                if (file_put_contents($targetFile, $defaultContent) === false) {
                    return $this->error("Failed to write default placeholder page");
                }
                $restoredFrom = 'default-placeholder';
            }
        }

        // Set correct ownership
        if (posix_getpwnam($domain)) {
            chown($targetFile, $domain);
            chgrp($targetFile, $domain);
        }

        // Remove all backup files for this domain (clean up)
        $removeBackup = $params['remove_backup'] ?? true;
        if ($removeBackup) {
            $allBackups = glob("{$siteRoot}/{$filename}.backup.*");
            foreach ($allBackups as $oldBackup) {
                @unlink($oldBackup);
            }
        }

        // Remove deployment record from database
        $this->removeTemplateDeployment($domain);

        $this->logger->info("Reverted template for site", [
            'domain' => $domain,
            'file' => $targetFile,
            'restored_from' => $restoredFrom,
            'actor' => $actor,
        ]);

        $message = $restoredFrom === 'default-placeholder'
            ? "Template removed — restored default placeholder page"
            : "Template reverted — restored from {$restoredFrom}";

        return $this->success([
            'domain' => $domain,
            'filename' => $filename,
            'restored_from' => $restoredFrom,
            'backup_removed' => $removeBackup,
        ], $message);
    }

    /**
     * Get all active template deployments
     */
    protected function actionGetTemplateDeployments(array $params, string $actor): array
    {
        $pdo = $this->getConnection();
        if (!$pdo) {
            return $this->error('Database connection not available');
        }

        try {
            $templateType = $params['template_type'] ?? null;
            
            if ($templateType) {
                $stmt = $pdo->prepare("
                    SELECT domain, template_type, deployed_at, deployed_by, backup_file 
                    FROM template_deployments 
                    WHERE template_type = ?
                    ORDER BY deployed_at DESC
                ");
                $stmt->execute([$templateType]);
            } else {
                $stmt = $pdo->query("
                    SELECT domain, template_type, deployed_at, deployed_by, backup_file 
                    FROM template_deployments 
                    ORDER BY deployed_at DESC
                ");
            }
            
            $deployments = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Group by template type for summary
            $summary = [
                'site_placeholder' => 0,
                'site_coming_soon' => 0,
                'site_maintenance' => 0,
            ];
            
            foreach ($deployments as $d) {
                if (isset($summary[$d['template_type']])) {
                    $summary[$d['template_type']]++;
                }
            }
            
            return $this->success([
                'deployments' => $deployments,
                'total' => count($deployments),
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to fetch deployments: ' . $e->getMessage());
        }
    }

    // ============================================
    // OpenLiteSpeed Management
    // ============================================

    /**
     * Restart OpenLiteSpeed
     */
    protected function actionOlsRestart(array $params, string $actor): array
    {
        $output = [];
        $exitCode = 0;
        
        exec('systemctl restart lsws 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            return $this->error('Failed to restart OpenLiteSpeed: ' . implode("\n", $output));
        }
        
        return $this->success([
            'message' => 'OpenLiteSpeed restarted successfully',
            'output' => implode("\n", $output),
        ], 'OpenLiteSpeed restarted');
    }

    /**
     * Reload OpenLiteSpeed (graceful)
     */
    protected function actionOlsReload(array $params, string $actor): array
    {
        $olsBin = '/usr/local/lsws/bin/lswsctrl';
        $output = [];
        $exitCode = 0;
        
        exec("{$olsBin} reload 2>&1", $output, $exitCode);
        
        if ($exitCode !== 0) {
            return $this->error('Failed to reload OpenLiteSpeed: ' . implode("\n", $output));
        }
        
        return $this->success([
            'message' => 'OpenLiteSpeed reloaded successfully',
            'output' => implode("\n", $output),
        ], 'OpenLiteSpeed reloaded');
    }

    /**
     * Read OpenLiteSpeed configuration file
     * Runs as root so it can read lsadm-owned files with restrictive permissions
     */
    protected function actionOlsReadConfig(array $params, string $actor): array
    {
        $configPath = '/usr/local/lsws/conf/httpd_config.conf';
        
        if (!file_exists($configPath)) {
            return $this->error('OpenLiteSpeed configuration file not found');
        }
        
        $content = file_get_contents($configPath);
        if ($content === false) {
            return $this->error('Failed to read OpenLiteSpeed configuration file');
        }
        
        // Clean up any Windows line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "", $content);
        
        // Get file info
        $stat = stat($configPath);
        $ownerInfo = posix_getpwuid($stat['uid']);
        $groupInfo = posix_getgrgid($stat['gid']);
        
        return $this->success([
            'content' => $content,
            'path' => $configPath,
            'owner' => $ownerInfo['name'] ?? $stat['uid'],
            'group' => $groupInfo['name'] ?? $stat['gid'],
            'permissions' => substr(sprintf('%o', $stat['mode']), -4),
            'size' => $stat['size'],
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
        ]);
    }

    /**
     * Save OpenLiteSpeed configuration file
     * Handles permission changes for lsadm-owned file
     */
    protected function actionOlsSaveConfig(array $params, string $actor): array
    {
        $content = $params['content'] ?? null;
        
        if ($content === null) {
            return $this->error('Content is required');
        }
        
        $configPath = '/usr/local/lsws/conf/httpd_config.conf';
        
        if (!file_exists($configPath)) {
            return $this->error('OpenLiteSpeed configuration file not found');
        }
        
        // Create backup with timestamp
        $backupDir = '/var/www/vps-admin/backups/ols';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $backupPath = $backupDir . '/httpd_config.conf.' . date('Y-m-d_H-i-s') . '.bak';
        
        // Backup current config
        if (!copy($configPath, $backupPath)) {
            return $this->error('Failed to create backup');
        }
        
        // Also keep a rolling backup at the original location
        copy($configPath, $configPath . '.bak');
        
        // Clean up Windows line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "", $content);
        
        // Get current file info for restoring permissions
        $fileOwner = fileowner($configPath);
        $fileGroup = filegroup($configPath);
        $filePerms = fileperms($configPath) & 0777;
        
        // Write the new config
        if (file_put_contents($configPath, $content) === false) {
            return $this->error('Failed to write configuration file');
        }
        
        // Restore original ownership and permissions
        chown($configPath, $fileOwner);
        chgrp($configPath, $fileGroup);
        chmod($configPath, $filePerms);
        
        return $this->success([
            'message' => 'Configuration saved successfully',
            'backup' => $backupPath,
        ], 'OpenLiteSpeed configuration saved');
    }

    // ============================================
    // Config File Permissions Management
    // ============================================

    /**
     * Service configuration files and their expected permissions
     */
    private array $serviceConfigs = [
        'ols' => [
            'name' => 'OpenLiteSpeed',
            'configs' => [
                [
                    'path' => '/usr/local/lsws/conf/httpd_config.conf',
                    'owner' => 'lsadm',
                    'group' => 'nogroup',
                    'perms' => '0750', // OLS uses 0750 for security
                    'acceptable_perms' => ['0750', '0644', '0640'], // Accept OLS default and common alternatives
                    'dir_path' => '/usr/local/lsws/conf',
                    'dir_perms' => '0750', // OLS uses 0750 for security
                    'acceptable_dir_perms' => ['0750', '0755'], // Accept OLS default and standard
                ],
            ],
        ],
        'php' => [
            'name' => 'PHP',
            'configs' => [
                [
                    'path' => '/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/usr/local/lsws/lsphp82/etc/php/8.2/litespeed/php.ini',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/usr/local/lsws/lsphp81/etc/php/8.1/litespeed/php.ini',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
            ],
        ],
        'mysql' => [
            'name' => 'MySQL/MariaDB',
            'configs' => [
                [
                    'path' => '/etc/mysql/my.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                // MySQL-specific (optional - may not exist on MariaDB)
                [
                    'path' => '/etc/mysql/mysql.conf.d/mysqld.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
                [
                    'path' => '/etc/mysql/mysql.conf.d/mysql.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
                // conf.d directory files
                [
                    'path' => '/etc/mysql/conf.d/mysql.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
                [
                    'path' => '/etc/mysql/conf.d/mysqldump.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
                // MariaDB-specific (optional - may not exist on MySQL)
                [
                    'path' => '/etc/mysql/mariadb.conf.d/50-server.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
                [
                    'path' => '/etc/mysql/mariadb.conf.d/50-client.cnf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                    'optional' => true,
                ],
            ],
        ],
        'postfix' => [
            'name' => 'Postfix',
            'configs' => [
                [
                    'path' => '/etc/postfix/main.cf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/postfix/master.cf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
            ],
        ],
        'dovecot' => [
            'name' => 'Dovecot',
            'configs' => [
                [
                    'path' => '/etc/dovecot/dovecot.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0755',
                    'is_dir' => true,
                ],
                [
                    'path' => '/etc/dovecot/conf.d/10-auth.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/10-logging.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/10-mail.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/10-master.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/10-ssl.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/15-lda.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/15-mailboxes.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/20-imap.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/20-pop3.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
                [
                    'path' => '/etc/dovecot/conf.d/90-quota.conf',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
            ],
        ],
        'pdns' => [
            'name' => 'PowerDNS',
            'configs' => [
                [
                    'path' => '/etc/powerdns/pdns.conf',
                    'owner' => 'root',
                    'group' => 'pdns',
                    'perms' => '0640',
                ],
            ],
        ],
        'ssh' => [
            'name' => 'SSH',
            'configs' => [
                [
                    'path' => '/etc/ssh/sshd_config',
                    'owner' => 'root',
                    'group' => 'root',
                    'perms' => '0644',
                ],
            ],
        ],
    ];

    /**
     * Check config file permissions for a service
     */
    protected function actionCheckPermissions(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        
        // If no service specified, check all
        if ($service === null) {
            $results = [];
            foreach ($this->serviceConfigs as $svcId => $svcInfo) {
                $results[$svcId] = $this->checkServicePermissions($svcId);
            }
            return $this->success(['services' => $results]);
        }
        
        // Check specific service
        if (!isset($this->serviceConfigs[$service])) {
            return $this->error("Unknown service: {$service}");
        }
        
        return $this->success($this->checkServicePermissions($service));
    }

    /**
     * Fix config file permissions for a service
     */
    protected function actionFixPermissions(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        
        if ($service === null) {
            return $this->error('Service parameter is required');
        }

        if (!isset($this->serviceConfigs[$service])) {
            return $this->error("Unknown service: {$service}");
        }

        if (in_array($service, self::MAIL_POD_SERVICES, true) && $this->mailPod()->active()) {
            return $this->error($this->mailPod()->readOnlyError());
        }

        $fixed = [];
        $errors = [];
        $svcInfo = $this->serviceConfigs[$service];
        
        foreach ($svcInfo['configs'] as $config) {
            $path = $config['path'];
            
            // Skip non-existent files
            if (!file_exists($path)) {
                continue;
            }
            
            try {
                // Fix directory permissions if specified
                if (isset($config['dir_path']) && is_dir($config['dir_path'])) {
                    $dirPerms = octdec($config['dir_perms']);
                    chmod($config['dir_path'], $dirPerms);
                    $fixed[] = "Directory {$config['dir_path']} permissions set to {$config['dir_perms']}";
                }
                
                // Fix file/dir permissions
                $perms = octdec($config['perms']);
                if (!@chmod($path, $perms)) {
                    $errors[] = "Failed to set permissions on {$path}";
                    continue;
                }
                
                // Fix ownership
                $owner = $config['owner'];
                $group = $config['group'];
                
                // Get user/group IDs
                $userInfo = posix_getpwnam($owner);
                $groupInfo = posix_getgrnam($group);
                
                if ($userInfo && $groupInfo) {
                    if (!@chown($path, $userInfo['uid'])) {
                        $errors[] = "Failed to set owner on {$path}";
                    }
                    if (!@chgrp($path, $groupInfo['gid'])) {
                        $errors[] = "Failed to set group on {$path}";
                    }
                }
                
                $fixed[] = "{$path}: {$config['perms']} {$owner}:{$group}";
                
            } catch (\Exception $e) {
                $errors[] = "{$path}: " . $e->getMessage();
            }
        }
        
        // Re-check after fixing
        $status = $this->checkServicePermissions($service);
        
        return $this->success([
            'service' => $service,
            'fixed' => $fixed,
            'errors' => $errors,
            'status' => $status,
        ], empty($errors) ? 'Permissions fixed successfully' : 'Some permissions could not be fixed');
    }

    /**
     * Check permissions for a single service
     */
    private function checkServicePermissions(string $service): array
    {
        $svcInfo = $this->serviceConfigs[$service];
        $configs = [];
        $allOk = true;

        // Docker box: postfix/dovecot config lives inside the mail pod, not
        // on the host. The files are baked by the container image with
        // correct ownership, so report them as managed-and-OK instead of
        // "File does not exist" noise.
        if (in_array($service, self::MAIL_POD_SERVICES, true) && $this->mailPod()->active()) {
            foreach ($svcInfo['configs'] as $config) {
                $exists = $this->mailPod()->fileExists($config['path']);
                $configs[] = [
                    'path' => $config['path'],
                    'exists' => $exists,
                    'is_dir' => isset($config['is_dir']) && $config['is_dir'],
                    'expected_owner' => $config['owner'],
                    'expected_group' => $config['group'],
                    'expected_perms' => $config['perms'],
                    'ok' => $exists,
                    'issues' => $exists
                        ? ['Managed inside the ' . MailPodBridge::POD . ' container']
                        : ['File not found inside the ' . MailPodBridge::POD . ' container'],
                ];
                if (!$exists) {
                    $allOk = false;
                }
            }
            return [
                'service' => $service,
                'name' => $svcInfo['name'],
                'ok' => $allOk,
                'configs' => $configs,
                'runtime' => 'docker',
            ];
        }

        foreach ($svcInfo['configs'] as $config) {
            $path = $config['path'];
            $exists = file_exists($path);
            $isDir = isset($config['is_dir']) && $config['is_dir'];
            
            $result = [
                'path' => $path,
                'exists' => $exists,
                'is_dir' => $isDir,
                'expected_owner' => $config['owner'],
                'expected_group' => $config['group'],
                'expected_perms' => $config['perms'],
                'ok' => false,
                'issues' => [],
            ];
            
            if (!$exists) {
                // If file is optional and doesn't exist, that's OK
                if (isset($config['optional']) && $config['optional']) {
                    $result['ok'] = true;
                    $result['optional'] = true;
                    $result['issues'][] = 'File not present (optional)';
                } else {
                    $result['issues'][] = 'File does not exist';
                    $allOk = false;
                }
                $configs[] = $result;
                continue;
            }
            
            // Get actual permissions
            $stat = stat($path);
            $actualPerms = substr(sprintf('%o', $stat['mode']), -4);
            $ownerInfo = posix_getpwuid($stat['uid']);
            $groupInfo = posix_getgrgid($stat['gid']);
            
            $result['actual_owner'] = $ownerInfo['name'] ?? $stat['uid'];
            $result['actual_group'] = $groupInfo['name'] ?? $stat['gid'];
            $result['actual_perms'] = $actualPerms;
            
            // Check owner
            if ($result['actual_owner'] !== $config['owner']) {
                $result['issues'][] = "Owner should be {$config['owner']}, is {$result['actual_owner']}";
            }
            
            // Check group
            if ($result['actual_group'] !== $config['group']) {
                $result['issues'][] = "Group should be {$config['group']}, is {$result['actual_group']}";
            }
            
            // Check permissions - support multiple acceptable values
            $acceptablePerms = $config['acceptable_perms'] ?? [$config['perms']];
            if (!in_array($actualPerms, $acceptablePerms)) {
                $result['issues'][] = "Permissions should be {$config['perms']}, are {$actualPerms}";
            }
            
            // Check directory permissions if specified
            if (isset($config['dir_path']) && is_dir($config['dir_path'])) {
                $dirStat = stat($config['dir_path']);
                $dirPerms = substr(sprintf('%o', $dirStat['mode']), -4);
                $result['dir_path'] = $config['dir_path'];
                $result['dir_perms'] = $dirPerms;
                $result['expected_dir_perms'] = $config['dir_perms'];
                
                // Support multiple acceptable dir permissions
                $acceptableDirPerms = $config['acceptable_dir_perms'] ?? [$config['dir_perms']];
                if (!in_array($dirPerms, $acceptableDirPerms)) {
                    $result['issues'][] = "Directory perms should be {$config['dir_perms']}, are {$dirPerms}";
                }
            }
            
            // Check if www-data can read (for panel access)
            $wwwDataInfo = posix_getpwnam('www-data');
            if ($wwwDataInfo) {
                $readable = is_readable($path);
                $result['readable_by_panel'] = $readable;
                if (!$readable) {
                    $result['issues'][] = 'Not readable by panel (www-data)';
                }
            }
            
            $result['ok'] = empty($result['issues']);
            if (!$result['ok']) {
                $allOk = false;
            }
            
            $configs[] = $result;
        }
        
        return [
            'service' => $service,
            'name' => $svcInfo['name'],
            'ok' => $allOk,
            'configs' => $configs,
        ];
    }

    // ============================================
    // Config Syntax Check
    // ============================================

    /**
     * Check syntax/validity of a config file
     */
    protected function actionSyntaxCheck(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        $content = $params['content'] ?? null;
        
        if (!$service) {
            return $this->error('Service parameter is required');
        }

        $result = match ($service) {
            'ssh' => $this->checkSshSyntax($content),
            'ols' => $this->checkOlsSyntax($content),
            'php' => $this->checkPhpIniSyntax($content),
            'mysql' => $this->checkMysqlSyntax($content),
            'postfix' => $this->checkPostfixSyntax($content),
            'dovecot' => $this->checkDovecotSyntax($content),
            'pdns' => $this->checkPdnsSyntax($content),
            'nginx' => $this->checkNginxSyntax($content),
            default => ['valid' => false, 'error' => "Unknown service: {$service}"],
        };

        return $this->success([
            'service' => $service,
            'valid' => $result['valid'],
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'message' => $result['message'] ?? ($result['valid'] ? 'Syntax OK' : 'Syntax errors found'),
        ]);
    }

    /**
     * Check SSH config syntax
     */
    private function checkSshSyntax(?string $content): array
    {
        // If content provided, write to temp file
        if ($content !== null) {
            $tempFile = '/tmp/sshd_config_test_' . time();
            file_put_contents($tempFile, $content);
            $configPath = $tempFile;
        } else {
            $configPath = '/etc/ssh/sshd_config';
        }

        $output = [];
        $exitCode = 0;
        exec("sshd -t -f {$configPath} 2>&1", $output, $exitCode);

        if (isset($tempFile)) {
            unlink($tempFile);
        }

        return [
            'valid' => $exitCode === 0,
            'errors' => $exitCode !== 0 ? $output : [],
            'message' => $exitCode === 0 ? 'SSH configuration syntax is valid' : implode("\n", $output),
        ];
    }

    /**
     * Check OpenLiteSpeed config syntax
     */
    private function checkOlsSyntax(?string $content): array
    {
        $errors = [];
        $warnings = [];
        
        // If content provided, parse it; otherwise read from file
        $configContent = $content ?? @file_get_contents('/usr/local/lsws/conf/httpd_config.conf');
        
        if (!$configContent) {
            return ['valid' => false, 'errors' => ['Could not read configuration file']];
        }

        $lines = explode("\n", $configContent);
        $braceCount = 0;
        $lineNum = 0;
        $inBlock = [];

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }

            // Count braces
            $openBraces = substr_count($trimmed, '{');
            $closeBraces = substr_count($trimmed, '}');
            
            $braceCount += $openBraces - $closeBraces;

            // Track block types
            if ($openBraces > 0) {
                if (preg_match('/^(\w+)\s/', $trimmed, $matches)) {
                    $inBlock[] = $matches[1];
                }
            }
            if ($closeBraces > 0 && !empty($inBlock)) {
                array_pop($inBlock);
            }

            // Check for common errors
            if ($braceCount < 0) {
                $errors[] = "Line {$lineNum}: Unexpected closing brace '}'";
                $braceCount = 0;
            }

            // Check for duplicate keys in same block (warning)
            // Check for required directives
        }

        if ($braceCount !== 0) {
            $errors[] = "Unbalanced braces: {$braceCount} unclosed block(s)";
        }

        // Try to do a graceful reload test if no content changes
        if ($content === null) {
            $output = [];
            exec('/usr/local/lsws/bin/lswsctrl status 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                $warnings[] = 'OpenLiteSpeed service may not be running';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'OpenLiteSpeed configuration syntax appears valid' : 'Configuration has errors',
        ];
    }

    /**
     * Check PHP.ini syntax
     */
    private function checkPhpIniSyntax(?string $content): array
    {
        $errors = [];
        $warnings = [];
        
        $configContent = $content ?? '';
        if (empty($configContent)) {
            return ['valid' => true, 'message' => 'No content to check'];
        }

        $lines = explode("\n", $configContent);
        $lineNum = 0;
        $inSection = false;

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === ';' || $trimmed[0] === '#') {
                continue;
            }

            // Section headers
            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $matches)) {
                $inSection = $matches[1];
                continue;
            }

            // Key = value pairs
            if (!preg_match('/^[\w.]+\s*=/', $trimmed)) {
                // Could be a continuation or error
                if (!preg_match('/^".*"$/', $trimmed) && !preg_match('/^[\'"]/', $trimmed)) {
                    $warnings[] = "Line {$lineNum}: Possible syntax issue - '{$trimmed}'";
                }
            }

            // Check for common mistakes
            if (preg_match('/^(memory_limit|upload_max_filesize|post_max_size)\s*=\s*(\d+)$/', $trimmed, $matches)) {
                $warnings[] = "Line {$lineNum}: {$matches[1]} value '{$matches[2]}' missing unit (M, G)";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'PHP configuration syntax appears valid' : 'Configuration has errors',
        ];
    }

    /**
     * Check MySQL config syntax
     */
    private function checkMysqlSyntax(?string $content): array
    {
        $errors = [];
        $warnings = [];
        
        $configContent = $content ?? '';
        if (empty($configContent)) {
            return ['valid' => true, 'message' => 'No content to check'];
        }

        $lines = explode("\n", $configContent);
        $lineNum = 0;
        $currentSection = null;
        $validSections = ['mysqld', 'mysql', 'client', 'mysqldump', 'mysqld_safe', 'server'];

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            // Section headers
            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $matches)) {
                $currentSection = $matches[1];
                if (!in_array($currentSection, $validSections)) {
                    $warnings[] = "Line {$lineNum}: Unknown section [{$currentSection}]";
                }
                continue;
            }

            // Key = value or just key
            if (!preg_match('/^[\w_-]+(\s*=\s*.+)?$/', $trimmed)) {
                $errors[] = "Line {$lineNum}: Invalid syntax - '{$trimmed}'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'MySQL configuration syntax appears valid' : 'Configuration has errors',
        ];
    }

    /**
     * Check Postfix config syntax
     */
    private function checkPostfixSyntax(?string $content): array
    {
        if ($content !== null) {
            $tempFile = '/tmp/postfix_main_cf_test_' . time();
            file_put_contents($tempFile, $content);
        }

        $output = [];
        $exitCode = 0;
        exec('postfix check 2>&1', $output, $exitCode);

        if (isset($tempFile)) {
            unlink($tempFile);
        }

        // postfix check returns 0 even with warnings
        $errors = array_filter($output, fn($line) => stripos($line, 'fatal') !== false || stripos($line, 'error') !== false);
        $warnings = array_filter($output, fn($line) => stripos($line, 'warning') !== false);

        return [
            'valid' => empty($errors),
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'message' => empty($errors) ? 'Postfix configuration syntax is valid' : implode("\n", $errors),
        ];
    }

    /**
     * Check Dovecot config syntax
     */
    private function checkDovecotSyntax(?string $content): array
    {
        $output = [];
        $exitCode = 0;
        exec('doveconf -n 2>&1', $output, $exitCode);

        $errors = [];
        $warnings = [];

        foreach ($output as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                $errors[] = $line;
            } elseif (stripos($line, 'warning') !== false || stripos($line, 'deprecated') !== false) {
                $warnings[] = $line;
            }
        }

        return [
            'valid' => $exitCode === 0 && empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'Dovecot configuration syntax is valid' : implode("\n", $errors),
        ];
    }

    /**
     * Check PowerDNS config syntax (basic validation)
     */
    private function checkPdnsSyntax(?string $content): array
    {
        $errors = [];
        $warnings = [];
        
        $configContent = $content ?? @file_get_contents('/etc/powerdns/pdns.conf');
        if (!$configContent) {
            return ['valid' => false, 'errors' => ['Could not read configuration']];
        }

        $lines = explode("\n", $configContent);
        $lineNum = 0;
        $hasLaunch = false;

        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }

            // Check for key=value format
            if (strpos($trimmed, '=') === false) {
                $errors[] = "Line {$lineNum}: Invalid format (missing '=') - '{$trimmed}'";
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            
            if ($key === 'launch') {
                $hasLaunch = true;
            }

            // Check for empty values on required fields
            if (in_array($key, ['launch', 'gmysql-host', 'gmysql-user', 'gmysql-dbname']) && empty(trim($value))) {
                $errors[] = "Line {$lineNum}: Required value for '{$key}' is empty";
            }
        }

        if (!$hasLaunch) {
            $errors[] = "Missing required 'launch' directive";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => empty($errors) ? 'PowerDNS configuration appears valid' : 'Configuration has errors',
        ];
    }

    /**
     * Check Nginx config syntax
     */
    private function checkNginxSyntax(?string $content): array
    {
        $output = [];
        $exitCode = 0;
        exec('nginx -t 2>&1', $output, $exitCode);

        $errors = [];
        $warnings = [];

        foreach ($output as $line) {
            if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                $errors[] = $line;
            } elseif (stripos($line, 'warn') !== false) {
                $warnings[] = $line;
            }
        }

        return [
            'valid' => $exitCode === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => $exitCode === 0 ? 'Nginx configuration syntax is valid' : implode("\n", $output),
        ];
    }
}

