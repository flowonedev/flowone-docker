<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class AgentController extends BaseController
{
    /**
     * Get comprehensive agent diagnostics
     */
    public function diagnostics(Request $request): Response
    {
        $diagnostics = [
            'agent' => $this->checkAgentService(),
            'socket' => $this->checkSocket(),
            'token' => $this->checkToken(),
            'php' => $this->checkPhp(),
            'mysql' => $this->checkMysql(),
            'permissions' => $this->checkPermissions(),
            'security' => $this->checkSecurity(),
            'subsystems' => $this->getSubsystems(),
            'logs' => $this->getRecentLogs(),
            'errors' => $this->getRecentErrors(),
            'timestamp' => date('c'),
        ];

        return Response::success($diagnostics);
    }

    /**
     * Restart agent service
     */
    public function restart(Request $request): Response
    {
        exec('sudo systemctl restart vpsadmin-agent 2>&1', $output, $exitCode);
        
        if ($exitCode !== 0) {
            $this->logAction('agent.restart', 'vpsadmin-agent', 'failed', ['output' => implode("\n", $output)]);
            return Response::error('Failed to restart agent: ' . implode("\n", $output));
        }
        
        $this->logAction('agent.restart', 'vpsadmin-agent', 'success');
        return Response::success(['message' => 'Agent restart initiated'], 'Agent restarting...');
    }

    /**
     * Check agent service status
     */
    private function checkAgentService(): array
    {
        $result = [
            'running' => false,
            'enabled' => false,
            'pid' => null,
            'uptime' => null,
            'uptime_human' => null,
            'memory' => null,
            'memory_human' => null,
            'cpu_time' => null,
            'started_at' => null,
            'error' => null,
        ];

        // Check if running
        exec('systemctl is-active vpsadmin-agent 2>&1', $activeOutput, $activeCode);
        $result['running'] = ($activeCode === 0);

        // Check if enabled
        exec('systemctl is-enabled vpsadmin-agent 2>&1', $enabledOutput, $enabledCode);
        $result['enabled'] = ($enabledCode === 0);

        // Get PID from systemd (more reliable)
        exec('systemctl show vpsadmin-agent --property=MainPID --value 2>/dev/null', $mainPidOutput, $mainPidCode);
        if ($mainPidCode === 0 && !empty($mainPidOutput) && (int)trim($mainPidOutput[0]) > 0) {
            $result['pid'] = (int)trim($mainPidOutput[0]);
        } else {
            // Fallback to pgrep
            exec('pgrep -f "agent.php" | head -1', $pidOutput, $pidCode);
            if ($pidCode === 0 && !empty($pidOutput)) {
                $result['pid'] = (int)trim($pidOutput[0]);
            }
        }
        
        if ($result['pid']) {
            $pid = $result['pid'];
            
            // Get all stats in one ps call for efficiency
            exec("ps -p {$pid} -o rss=,time=,etimes=,lstart= 2>/dev/null", $psOutput);
            if (!empty($psOutput)) {
                $line = trim($psOutput[0]);
                $parts = preg_split('/\s+/', $line, 4);
                
                if (count($parts) >= 1) {
                    // Memory (RSS in KB)
                    $memKb = (int)$parts[0];
                    $result['memory'] = $memKb * 1024;
                    $result['memory_human'] = $this->formatBytes($memKb * 1024);
                }
                
                if (count($parts) >= 2) {
                    // CPU time
                    $result['cpu_time'] = $parts[1];
                }
                
                if (count($parts) >= 3) {
                    // Uptime in seconds
                    $uptimeSeconds = (int)$parts[2];
                    $result['uptime'] = $uptimeSeconds;
                    $result['uptime_human'] = $this->formatUptime($uptimeSeconds);
                }
                
                if (count($parts) >= 4) {
                    // Start time
                    $result['started_at'] = $parts[3];
                }
            }
        }

        return $result;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Format uptime seconds to human readable
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $mins = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($days > 0) return "{$days}d {$hours}h {$mins}m";
        if ($hours > 0) return "{$hours}h {$mins}m {$secs}s";
        if ($mins > 0) return "{$mins}m {$secs}s";
        return "{$secs}s";
    }

    /**
     * Check socket file
     */
    private function checkSocket(): array
    {
        $socketPath = $this->container->getConfig('agent.socket');
        
        $result = [
            'exists' => false,
            'path' => $socketPath,
            'permissions' => null,
            'permissions_ok' => false,
            'owner' => null,
            'group' => null,
        ];

        if (file_exists($socketPath)) {
            $result['exists'] = true;
            
            $perms = fileperms($socketPath);
            $result['permissions'] = substr(sprintf('%o', $perms), -4);
            
            $owner = posix_getpwuid(fileowner($socketPath));
            $group = posix_getgrgid(filegroup($socketPath));
            
            $result['owner'] = $owner['name'] ?? 'unknown';
            $result['group'] = $group['name'] ?? 'unknown';
            
            // Check if permissions are correct (should be 0770 or 0660, owned by root:www-data)
            $result['permissions_ok'] = (
                $result['owner'] === 'root' && 
                $result['group'] === 'www-data' &&
                in_array($result['permissions'], ['0770', '0660', '770', '660'])
            );
        }

        return $result;
    }

    /**
     * Check token file
     */
    private function checkToken(): array
    {
        $tokenFile = $this->container->getConfig('agent.token_file');
        
        $result = [
            'exists' => false,
            'path' => $tokenFile,
            'length' => 0,
            'permissions' => null,
            'permissions_ok' => false,
        ];

        if (file_exists($tokenFile)) {
            $result['exists'] = true;
            $result['length'] = strlen(trim(file_get_contents($tokenFile)));
            
            $perms = fileperms($tokenFile);
            $result['permissions'] = substr(sprintf('%o', $perms), -4);
            
            $owner = posix_getpwuid(fileowner($tokenFile));
            $group = posix_getgrgid(filegroup($tokenFile));
            
            // Check if permissions are correct (should be 0640, owned by root:www-data)
            $result['permissions_ok'] = (
                ($owner['name'] ?? '') === 'root' && 
                ($group['name'] ?? '') === 'www-data' &&
                in_array($result['permissions'], ['0640', '640', '0600', '600'])
            );
        }

        return $result;
    }

    /**
     * Check PHP extensions
     */
    private function checkPhp(): array
    {
        $required = ['sockets', 'pcntl', 'posix', 'json', 'pdo_mysql', 'openssl', 'mbstring', 'curl'];
        $loaded = [];
        
        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                $loaded[] = $ext;
            }
        }

        return [
            'version' => PHP_VERSION,
            'extensions' => $loaded,
            'missing' => array_diff($required, $loaded),
        ];
    }

    /**
     * Check MySQL connection
     */
    private function checkMysql(): array
    {
        $result = [
            'connected' => false,
            'version' => null,
            'error' => null,
        ];

        try {
            $config = [
                'host' => $this->container->getConfig('database.host'),
                'port' => $this->container->getConfig('database.port'),
                'dbname' => $this->container->getConfig('database.name'),
                'user' => $this->container->getConfig('database.user'),
                'password' => $this->container->getConfig('database.password'),
            ];

            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
            $pdo = new \PDO($dsn, $config['user'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            $result['connected'] = true;
            $result['version'] = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Check file permissions
     */
    private function checkPermissions(): array
    {
        $result = [
            'correct' => true,
            'issues' => [],
        ];

        $checks = [
            '/var/www/vps-admin/var' => ['owner' => 'www-data', 'group' => 'www-data', 'perms' => '750'],
            '/var/www/vps-admin/agent' => ['owner' => 'www-data', 'group' => 'www-data', 'perms' => '755'],
            '/var/www/vps-admin/backups' => ['owner' => 'www-data', 'group' => 'www-data', 'perms' => '750'],
        ];

        foreach ($checks as $path => $expected) {
            if (!file_exists($path)) {
                $result['issues'][] = "Path not found: {$path}";
                $result['correct'] = false;
                continue;
            }

            $owner = posix_getpwuid(fileowner($path));
            $group = posix_getgrgid(filegroup($path));
            $perms = substr(sprintf('%o', fileperms($path)), -3);

            if (($owner['name'] ?? '') !== $expected['owner']) {
                $result['issues'][] = "{$path}: Wrong owner (expected {$expected['owner']}, got {$owner['name']})";
                $result['correct'] = false;
            }

            if (($group['name'] ?? '') !== $expected['group']) {
                $result['issues'][] = "{$path}: Wrong group (expected {$expected['group']}, got {$group['name']})";
                $result['correct'] = false;
            }
        }

        return $result;
    }

    /**
     * Check security status (CPGuard, ModSec, etc.)
     */
    private function checkSecurity(): array
    {
        $result = [
            'cpguard' => null,
            'cpguard_blocking' => false,
            'modsec' => null,
            'modsec_blocking' => false,
            'fail2ban' => null,
        ];

        // Check CPGuard
        exec('systemctl is-active cpguard 2>&1', $cpguardOutput, $cpguardCode);
        if ($cpguardCode === 0) {
            $result['cpguard'] = 'active';
            
            // Check if CPGuard has blocked anything recently
            if (file_exists('/var/log/cpguard/blocked.log')) {
                $recentBlocks = shell_exec('tail -n 100 /var/log/cpguard/blocked.log 2>/dev/null | grep -c "$(date +%Y-%m-%d)"');
                if ((int)trim($recentBlocks) > 0) {
                    $result['cpguard_blocking'] = true;
                    $result['cpguard'] = 'blocking';
                }
            }
        } else {
            $result['cpguard'] = 'inactive';
        }

        // Check ModSecurity
        exec('systemctl is-active modsecurity 2>&1', $modsecOutput, $modsecCode);
        if ($modsecCode === 0) {
            $result['modsec'] = 'active';
        } else {
            // Check if it's running as part of OpenLiteSpeed
            if (file_exists('/usr/local/lsws/conf/modsec.conf')) {
                $result['modsec'] = 'configured';
            }
        }

        // Check Fail2ban
        exec('systemctl is-active fail2ban 2>&1', $fail2banOutput, $fail2banCode);
        $result['fail2ban'] = ($fail2banCode === 0) ? 'active' : 'inactive';

        return $result;
    }

    /**
     * Get recent agent logs
     */
    private function getRecentLogs(int $lines = 20): array
    {
        $logs = [];
        
        exec("journalctl -u vpsadmin-agent -n {$lines} --no-pager -o json 2>/dev/null", $output);
        
        foreach ($output as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $logs[] = [
                    'timestamp' => date('Y-m-d H:i:s', (int)($entry['__REALTIME_TIMESTAMP'] / 1000000)),
                    'level' => $entry['PRIORITY'] <= 3 ? 'error' : ($entry['PRIORITY'] <= 4 ? 'warning' : 'info'),
                    'message' => $entry['MESSAGE'] ?? '',
                ];
            }
        }

        return array_reverse($logs);
    }

    /**
     * Get recent errors
     */
    private function getRecentErrors(): array
    {
        $errors = [];
        
        exec('journalctl -u vpsadmin-agent -p err -n 10 --no-pager 2>/dev/null', $output);
        
        foreach ($output as $line) {
            if (!empty(trim($line)) && strpos($line, '--') !== 0) {
                $errors[] = trim($line);
            }
        }

        return $errors;
    }

    /**
     * Get all agent subsystems/handlers
     */
    private function getSubsystems(): array
    {
        $subsystems = [];
        $agentPath = '/var/www/vps-admin/agent/Actions';
        
        // Check if agent is running - if it is, handlers must exist
        exec('systemctl is-active vpsadmin-agent 2>&1', $agentActiveOutput, $agentActiveCode);
        $agentRunning = ($agentActiveCode === 0);
        
        // Define all subsystems with their details
        $handlers = [
            'DatabaseAction' => [
                'name' => 'Database',
                'namespace' => 'db',
                'description' => 'MySQL/MariaDB database management',
                'icon' => 'database',
                'service' => 'mariadb',
                'config_paths' => ['/etc/mysql/my.cnf', '/root/.my.cnf'],
            ],
            'VhostAction' => [
                'name' => 'Virtual Hosts',
                'namespace' => 'vhost',
                'description' => 'Website/domain management',
                'icon' => 'language',
                'service' => 'lsws',
                'config_paths' => ['/usr/local/lsws/conf/httpd_config.conf'],
            ],
            'SslAction' => [
                'name' => 'SSL Certificates',
                'namespace' => 'ssl',
                'description' => 'Let\'s Encrypt SSL management',
                'icon' => 'verified_user',
                'service' => null,
                'config_paths' => ['/root/.acme.sh'],
            ],
            'MailAction' => [
                'name' => 'Mail Accounts',
                'namespace' => 'mail',
                'description' => 'Email accounts and forwards',
                'icon' => 'mail',
                'service' => null,
                'config_paths' => ['/etc/postfix/virtual'],
            ],
            'PostfixAction' => [
                'name' => 'Postfix SMTP',
                'namespace' => 'postfix',
                'description' => 'Outgoing mail server',
                'icon' => 'forward_to_inbox',
                'service' => 'postfix',
                'config_paths' => ['/etc/postfix/main.cf'],
            ],
            'DovecotAction' => [
                'name' => 'Dovecot IMAP',
                'namespace' => 'dovecot',
                'description' => 'Incoming mail server',
                'icon' => 'inbox',
                'service' => 'dovecot',
                'config_paths' => ['/etc/dovecot/dovecot.conf'],
            ],
            'DnsAction' => [
                'name' => 'DNS',
                'namespace' => 'dns',
                'description' => 'PowerDNS zone management',
                'icon' => 'dns',
                'service' => 'pdns',
                'config_paths' => ['/etc/powerdns/pdns.conf'],
            ],
            'PhpAction' => [
                'name' => 'PHP',
                'namespace' => 'php',
                'description' => 'PHP version management',
                'icon' => 'code',
                'service' => null,
                'config_paths' => ['/usr/local/lsws/lsphp81/etc/php/8.1/litespeed/php.ini'],
            ],
            'MysqlAction' => [
                'name' => 'MySQL Config',
                'namespace' => 'mysql',
                'description' => 'MySQL server configuration',
                'icon' => 'storage',
                'service' => 'mariadb',
                'config_paths' => ['/etc/mysql/mariadb.conf.d/50-server.cnf'],
            ],
            'ServiceAction' => [
                'name' => 'Services',
                'namespace' => 'service',
                'description' => 'System service control',
                'icon' => 'tune',
                'service' => null,
                'config_paths' => [],
            ],
            'Fail2banAction' => [
                'name' => 'Fail2ban',
                'namespace' => 'fail2ban',
                'description' => 'Intrusion prevention',
                'icon' => 'block',
                'service' => 'fail2ban',
                'config_paths' => ['/etc/fail2ban/jail.local'],
            ],
            'FirewallAction' => [
                'name' => 'Firewall',
                'namespace' => 'firewall',
                'description' => 'Firewalld management',
                'icon' => 'local_fire_department',
                'service' => 'firewalld',
                'config_paths' => ['/etc/firewalld'],
            ],
            'ModsecAction' => [
                'name' => 'ModSecurity',
                'namespace' => 'modsec',
                'description' => 'Web application firewall',
                'icon' => 'security',
                'service' => null,
                'config_paths' => ['/usr/local/lsws/conf/modsec.conf'],
            ],
        ];

        // One Docker-aware snapshot of every allowed service from the agent.
        // The agent (root) routes containerized services (mariadb, postfix,
        // dovecot, ... on Docker boxes) through docker/supervisorctl, so this
        // never reports a false "stopped" the way a raw `systemctl is-active`
        // from www-data does. Falls back to systemctl if the agent is down.
        $serviceMap = $this->getAgentServiceMap($agentRunning);

        foreach ($handlers as $className => $info) {
            $filePath = "{$agentPath}/{$className}.php";
            
            // If agent is running, the handler files must exist (agent wouldn't start otherwise)
            // We can't reliably check files from www-data due to permissions
            $fileExists = $agentRunning;
            
            // Check service status if applicable
            $serviceStatus = null;
            $serviceRunning = null;
            $serviceRuntime = null;
            if ($info['service']) {
                $svc = $serviceMap[$info['service']] ?? null;
                if ($svc !== null) {
                    $serviceRunning = !empty($svc['active']);
                    $serviceStatus = (string) ($svc['status'] ?? ($serviceRunning ? 'running' : 'stopped'));
                    $serviceRuntime = (string) ($svc['runtime'] ?? 'systemd');
                } else {
                    exec("systemctl is-active {$info['service']} 2>&1", $svcOutput, $code);
                    $serviceRunning = ($code === 0);
                    $serviceStatus = $serviceRunning ? 'running' : 'stopped';
                    unset($svcOutput);
                }
            }
            
            // Check config paths - use readable paths only
            $configExists = [];
            foreach ($info['config_paths'] as $configPath) {
                if ($serviceRuntime === 'docker') {
                    // Containerized service: its config lives inside the
                    // container image/volume, not on the host filesystem.
                    // The container being up is the proof the config exists.
                    $configExists[$configPath] = $serviceRunning === true;
                } elseif (is_readable($configPath)) {
                    $configExists[$configPath] = true;
                } elseif (strpos($configPath, '/etc/') === 0) {
                    // /etc paths should be readable, if not it's missing
                    $configExists[$configPath] = file_exists($configPath);
                } else {
                    // For root-only paths like /root/.my.cnf, assume exists if agent running
                    $configExists[$configPath] = $agentRunning;
                }
            }
            
            // Determine status based on file existence and service state
            $status = 'ok';
            if (!$fileExists) {
                $status = 'error';
            } elseif ($info['service'] && $serviceRunning === false) {
                $status = 'warning';
            }
            
            $subsystems[] = [
                'class' => $className,
                'name' => $info['name'],
                'namespace' => $info['namespace'],
                'description' => $info['description'],
                'icon' => $info['icon'],
                'file_path' => $filePath,
                'file_exists' => $fileExists,
                'service' => $info['service'],
                'service_status' => $serviceStatus,
                'service_running' => $serviceRunning,
                'service_runtime' => $serviceRuntime,
                'config_paths' => $configExists,
                'status' => $status,
            ];
        }

        return $subsystems;
    }

    /**
     * Docker-aware service status snapshot, keyed by service name. Sourced
     * from the agent's service.list (root + DockerServiceBridge); empty when
     * the agent is not running so callers fall back to plain systemctl.
     */
    private function getAgentServiceMap(bool $agentRunning): array
    {
        if (!$agentRunning) {
            return [];
        }
        try {
            $result = $this->callAgent('service.list');
            if (empty($result['success']) || empty($result['data']['services'])) {
                return [];
            }
            $map = [];
            foreach ((array) $result['data']['services'] as $svc) {
                if (!empty($svc['name'])) {
                    $map[(string) $svc['name']] = $svc;
                }
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

