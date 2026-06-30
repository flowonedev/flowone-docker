<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Preflight Check Service
 *
 * Runs a series of validation checks on the target server and local environment
 * before a full provision deployment begins. Each check returns a structured
 * result with status (pass/warn/fail), message, and optional details.
 */
class PreflightService
{
    private Container $container;
    private SSHService $ssh;
    private EncryptionService $encryption;
    private \PDO $db;

    private const CHECK_TIMEOUT = 10;

    private const REQUIRED_PORTS = [
        22   => 'SSH',
        25   => 'SMTP',
        80   => 'HTTP',
        443  => 'HTTPS',
        587  => 'SMTP Submission',
        993  => 'IMAPS',
        4190 => 'ManageSieve',
        7080 => 'OLS Admin',
    ];

    private const MIN_DISK_MB = 5120; // 5 GB
    private const MIN_RAM_MB  = 768;

    private const REPO_URLS = [
        'archive.ubuntu.com' => 'http://archive.ubuntu.com/ubuntu/dists/',
        'repo.litespeed.sh'  => 'https://repo.litespeed.sh',
    ];

    private const KNOWN_SERVICES = [
        'mariadb', 'mysql', 'lshttpd', 'openlitespeed',
        'postfix', 'dovecot', 'redis-server', 'meilisearch',
        'fail2ban', 'firewalld',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->db = $container->getDatabase();
    }

    /**
     * Run all preflight checks for a server.
     * Returns structured results with per-check status and an overall summary.
     */
    public function run(int $serverId, ?int $blueprintId = null): array
    {
        $totalStart = microtime(true);

        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        $checks = [];

        // -- 1. SSH connectivity (critical, must run first) --
        $checks[] = $sshResult = $this->checkSSH($server);

        $sshConnected = $sshResult['status'] === 'pass';

        // -- 2. Package files on Fleet Manager server (critical, local check) --
        $checks[] = $this->checkPackageFiles();

        // -- 3. Install scripts exist (critical, local check) --
        $checks[] = $this->checkInstallScripts();

        // Remote checks only if SSH succeeded
        if ($sshConnected) {
            $checks[] = $this->checkDiskSpace();
            $checks[] = $this->checkAPTState();
            $checks[] = $this->checkDNS($server);
            $checks[] = $this->checkPorts();
            $checks[] = $this->checkInternetAccess();
            $checks[] = $this->checkSystemResources();
            $checks[] = $this->checkExistingServices();
            $checks[] = $this->checkOSVersion();

            $this->ssh->disconnect();
        }

        $totalMs = round((microtime(true) - $totalStart) * 1000);

        $passed   = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
        $warnings = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
        $failed   = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));

        $criticalFailed = count(array_filter(
            $checks,
            fn($c) => $c['status'] === 'fail' && $c['category'] === 'critical'
        ));

        return [
            'success' => true,
            'checks'  => $checks,
            'summary' => [
                'total'       => count($checks),
                'passed'      => $passed,
                'warnings'    => $warnings,
                'failed'      => $failed,
                'can_proceed' => $criticalFailed === 0,
                'duration_ms' => $totalMs,
            ],
        ];
    }

    // =========================================================================
    // Individual checks
    // =========================================================================

    private function checkSSH(array $server): array
    {
        $start = microtime(true);
        try {
            $connected = $this->ssh->connectToServer($server);
            $ms = $this->elapsed($start);

            if (!$connected) {
                return $this->result('ssh_connectivity', 'SSH Connection', 'critical', 'fail',
                    'Cannot connect to server -- check credentials and firewall', [], $ms);
            }

            $info = $this->ssh->getSystemInfo();

            return $this->result('ssh_connectivity', 'SSH Connection', 'critical', 'pass',
                'Connected successfully', [
                    'hostname' => $info['hostname'] ?? '',
                    'os'       => $info['os'] ?? '',
                    'uptime'   => $info['uptime'] ?? '',
                ], $ms);
        } catch (\Throwable $e) {
            return $this->result('ssh_connectivity', 'SSH Connection', 'critical', 'fail',
                'SSH error: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkDiskSpace(): array
    {
        $start = microtime(true);
        try {
            $output = $this->execRemote("df -BM --output=target,avail / /var /tmp 2>/dev/null | tail -n +2");
            $ms = $this->elapsed($start);

            if (!$output['success']) {
                return $this->result('disk_space', 'Disk Space', 'critical', 'fail',
                    'Could not check disk space', [], $ms);
            }

            $partitions = [];
            $lowSpace = false;
            foreach (explode("\n", trim($output['output'])) as $line) {
                $line = trim($line);
                if (!$line) continue;
                if (preg_match('/^(\S+)\s+(\d+)M/', $line, $m)) {
                    $mount = $m[1];
                    $availMB = (int)$m[2];
                    $partitions[$mount] = $availMB;
                    if ($availMB < self::MIN_DISK_MB) {
                        $lowSpace = true;
                    }
                }
            }

            if (empty($partitions)) {
                return $this->result('disk_space', 'Disk Space', 'critical', 'warn',
                    'Could not parse disk space output', ['raw' => $output['output']], $ms);
            }

            if ($lowSpace) {
                return $this->result('disk_space', 'Disk Space', 'critical', 'fail',
                    'Insufficient disk space (need at least ' . round(self::MIN_DISK_MB / 1024, 1) . ' GB free)',
                    ['partitions_mb' => $partitions], $ms);
            }

            return $this->result('disk_space', 'Disk Space', 'critical', 'pass',
                'Sufficient disk space available',
                ['partitions_mb' => $partitions], $ms);
        } catch (\Throwable $e) {
            return $this->result('disk_space', 'Disk Space', 'critical', 'fail',
                'Error checking disk: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkAPTState(): array
    {
        $start = microtime(true);
        try {
            $lockCheck = $this->execRemote(
                "fuser /var/lib/dpkg/lock-frontend /var/lib/apt/lists/lock /var/cache/apt/archives/lock 2>/dev/null"
            );
            $ms = $this->elapsed($start);

            $hasLocks = !empty(trim($lockCheck['output'] ?? ''));

            if ($hasLocks) {
                $procs = $this->execRemote("ps aux | grep -E 'apt|dpkg|unattended' | grep -v grep 2>/dev/null");
                return $this->result('apt_state', 'APT Package State', 'critical', 'fail',
                    'APT/dpkg locks are held -- another package operation is running',
                    ['lock_pids' => trim($lockCheck['output']), 'processes' => trim($procs['output'] ?? '')],
                    $this->elapsed($start));
            }

            $brokenCheck = $this->execRemote("dpkg --audit 2>/dev/null | head -5");
            $hasBroken = !empty(trim($brokenCheck['output'] ?? ''));

            if ($hasBroken) {
                return $this->result('apt_state', 'APT Package State', 'critical', 'warn',
                    'Broken packages detected -- provisioning will attempt repair',
                    ['audit' => trim($brokenCheck['output'])], $this->elapsed($start));
            }

            return $this->result('apt_state', 'APT Package State', 'critical', 'pass',
                'No locks, no broken packages', [], $this->elapsed($start));
        } catch (\Throwable $e) {
            return $this->result('apt_state', 'APT Package State', 'critical', 'fail',
                'Error checking APT: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkDNS(array $server): array
    {
        $start = microtime(true);
        try {
            $serverIp = $server['ip_address'];
            $domains = array_filter([
                'panel' => $server['panel_domain'] ?? null,
                'email' => $server['email_domain'] ?? null,
                'mail'  => $server['mail_domain'] ?? null,
            ]);

            if (empty($domains)) {
                return $this->result('dns_resolution', 'DNS Resolution', 'important', 'warn',
                    'No domains configured on this server', [], $this->elapsed($start));
            }

            $results = [];
            $allMatch = true;
            $anyFail = false;

            foreach ($domains as $label => $domain) {
                // Try multiple DNS lookup methods since dig may not be installed
                $dig = $this->execRemote(
                    "IP=$(dig +short A {$domain} 2>/dev/null | head -1); " .
                    "[ -z \"\$IP\" ] && IP=$(getent hosts {$domain} 2>/dev/null | awk '{print \$1}' | head -1); " .
                    "[ -z \"\$IP\" ] && IP=$(host -t A {$domain} 2>/dev/null | grep 'has address' | awk '{print \$NF}' | head -1); " .
                    "echo \"\$IP\""
                );
                $resolved = trim($dig['output'] ?? '');
                $match = $resolved === $serverIp;
                $results[$domain] = [
                    'type'     => $label,
                    'resolved' => $resolved ?: '(no record)',
                    'expected' => $serverIp,
                    'match'    => $match,
                ];
                if (!$match) $allMatch = false;
                if (empty($resolved)) $anyFail = true;
            }

            $ms = $this->elapsed($start);

            if ($allMatch) {
                return $this->result('dns_resolution', 'DNS Resolution', 'important', 'pass',
                    'All domains resolve to server IP', ['domains' => $results], $ms);
            }

            $status = $anyFail ? 'warn' : 'warn';
            return $this->result('dns_resolution', 'DNS Resolution', 'important', $status,
                'Some domains do not resolve to server IP -- SSL cert generation may fail',
                ['domains' => $results], $ms);
        } catch (\Throwable $e) {
            return $this->result('dns_resolution', 'DNS Resolution', 'important', 'warn',
                'Error checking DNS: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkPorts(): array
    {
        $start = microtime(true);
        try {
            $output = $this->execRemote("ss -tlnp 2>/dev/null");
            $ms = $this->elapsed($start);

            if (!$output['success']) {
                return $this->result('port_availability', 'Port Availability', 'important', 'warn',
                    'Could not check ports', [], $ms);
            }

            $lines = $output['output'] ?? '';
            $occupied = [];

            foreach (self::REQUIRED_PORTS as $port => $label) {
                if (preg_match("/:{$port}\s/", $lines)) {
                    // Port is in use -- find what process holds it
                    if (preg_match("/:{$port}\s.*users:\(\(\"([^\"]+)\"/", $lines, $m)) {
                        $occupied[$port] = ['label' => $label, 'process' => $m[1]];
                    } else {
                        $occupied[$port] = ['label' => $label, 'process' => 'unknown'];
                    }
                }
            }

            $ms = $this->elapsed($start);

            if (empty($occupied)) {
                return $this->result('port_availability', 'Port Availability', 'important', 'pass',
                    'All required ports are available', ['checked' => array_keys(self::REQUIRED_PORTS)], $ms);
            }

            // SSH being occupied is expected, filter it for the warning
            $nonSsh = array_filter($occupied, fn($_, $p) => $p !== 22, ARRAY_FILTER_USE_BOTH);

            if (empty($nonSsh)) {
                return $this->result('port_availability', 'Port Availability', 'important', 'pass',
                    'All required ports available (SSH already listening, expected)',
                    ['occupied' => $occupied], $ms);
            }

            return $this->result('port_availability', 'Port Availability', 'important', 'warn',
                count($nonSsh) . ' port(s) already in use -- existing services may conflict',
                ['occupied' => $occupied], $ms);
        } catch (\Throwable $e) {
            return $this->result('port_availability', 'Port Availability', 'important', 'warn',
                'Error checking ports: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkInternetAccess(): array
    {
        $start = microtime(true);
        try {
            $reachable = [];
            $unreachable = [];

            foreach (self::REPO_URLS as $name => $url) {
                $result = $this->execRemote(
                    "curl -s --connect-timeout 5 -o /dev/null -w '%{http_code}' '{$url}' 2>/dev/null"
                );
                $code = trim($result['output'] ?? '');
                $ok = $code && $code !== '000' && (int)$code < 500;

                if ($ok) {
                    $reachable[$name] = (int)$code;
                } else {
                    $unreachable[$name] = $code ?: 'timeout';
                }
            }

            $ms = $this->elapsed($start);

            if (empty($unreachable)) {
                return $this->result('internet_access', 'Internet Access', 'important', 'pass',
                    'Server can reach package repositories', ['reachable' => $reachable], $ms);
            }

            return $this->result('internet_access', 'Internet Access', 'important', 'warn',
                'Cannot reach some repositories -- package installation may fail',
                ['reachable' => $reachable, 'unreachable' => $unreachable], $ms);
        } catch (\Throwable $e) {
            return $this->result('internet_access', 'Internet Access', 'important', 'warn',
                'Error checking internet: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkSystemResources(): array
    {
        $start = microtime(true);
        try {
            $memOut = $this->execRemote("free -m | grep Mem");
            $cpuOut = $this->execRemote("nproc");

            $ms = $this->elapsed($start);

            $totalRam = 0;
            $freeRam = 0;
            if (preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)\s+(\d+)/', $memOut['output'] ?? '', $m)) {
                $totalRam = (int)$m[1];
                $freeRam = (int)$m[5]; // available column
            }

            $cpuCores = (int)trim($cpuOut['output'] ?? '0');

            $details = [
                'total_ram_mb'     => $totalRam,
                'available_ram_mb' => $freeRam,
                'cpu_cores'        => $cpuCores,
            ];

            if ($freeRam > 0 && $freeRam < self::MIN_RAM_MB) {
                return $this->result('system_resources', 'System Resources', 'important', 'warn',
                    "Low available RAM ({$freeRam} MB) -- provisioning may be slow or fail",
                    $details, $ms);
            }

            return $this->result('system_resources', 'System Resources', 'important', 'pass',
                "Resources OK ({$cpuCores} cores, {$totalRam} MB RAM, {$freeRam} MB available)",
                $details, $ms);
        } catch (\Throwable $e) {
            return $this->result('system_resources', 'System Resources', 'important', 'warn',
                'Error checking resources: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkPackageFiles(): array
    {
        $start = microtime(true);
        try {
            $packageService = $this->container->get(PackageService::class);
            $missing = [];
            $found = [];

            foreach (PackageService::TYPES as $type) {
                $path = $packageService->getLatestPath($type);
                if ($path && file_exists($path)) {
                    $version = $packageService->getLatestVersion($type);
                    $sizeMB = round(filesize($path) / 1048576, 1);
                    $found[$type] = [
                        'version' => $version ?? 'unknown',
                        'size_mb' => $sizeMB,
                    ];
                } else {
                    $missing[] = $type;
                }
            }

            $ms = $this->elapsed($start);

            if (!empty($missing)) {
                return $this->result('package_files', 'Package Files', 'critical', 'fail',
                    'Missing packages: ' . implode(', ', $missing) . ' -- build or upload them first',
                    ['found' => $found, 'missing' => $missing], $ms);
            }

            return $this->result('package_files', 'Package Files', 'critical', 'pass',
                'All deployment packages available',
                ['packages' => $found], $ms);
        } catch (\Throwable $e) {
            return $this->result('package_files', 'Package Files', 'critical', 'fail',
                'Error checking packages: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkInstallScripts(): array
    {
        $start = microtime(true);
        try {
            $missing = [];
            $found = [];

            foreach (PackageService::INSTALL_SCRIPT_PATHS as $type => $path) {
                if (file_exists($path)) {
                    $found[] = $type;
                } else {
                    $missing[] = $type;
                }
            }

            $ms = $this->elapsed($start);

            if (!empty($missing)) {
                return $this->result('install_scripts', 'Install Scripts', 'critical', 'fail',
                    'Missing install scripts for: ' . implode(', ', $missing),
                    ['found' => $found, 'missing' => $missing], $ms);
            }

            return $this->result('install_scripts', 'Install Scripts', 'critical', 'pass',
                'All install scripts present',
                ['scripts' => array_keys(PackageService::INSTALL_SCRIPT_PATHS)], $ms);
        } catch (\Throwable $e) {
            return $this->result('install_scripts', 'Install Scripts', 'critical', 'fail',
                'Error checking install scripts: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkExistingServices(): array
    {
        $start = microtime(true);
        try {
            $cmd = "for svc in " . implode(' ', self::KNOWN_SERVICES) .
                   "; do echo \"$svc:$(systemctl is-active $svc 2>/dev/null)\"; done";
            $output = $this->execRemote($cmd);
            $ms = $this->elapsed($start);

            $active = [];
            $inactive = [];

            foreach (explode("\n", trim($output['output'] ?? '')) as $line) {
                if (strpos($line, ':') === false) continue;
                [$svc, $state] = explode(':', $line, 2);
                $state = trim($state);
                if ($state === 'active') {
                    $active[] = $svc;
                } else {
                    $inactive[] = $svc;
                }
            }

            $msg = empty($active)
                ? 'No pre-existing services detected (clean server)'
                : count($active) . ' service(s) already running: ' . implode(', ', $active);

            return $this->result('existing_services', 'Existing Services', 'info', 'pass',
                $msg, ['active' => $active, 'inactive' => $inactive], $ms);
        } catch (\Throwable $e) {
            return $this->result('existing_services', 'Existing Services', 'info', 'pass',
                'Could not check services: ' . $e->getMessage(), [], $this->elapsed($start));
        }
    }

    private function checkOSVersion(): array
    {
        $start = microtime(true);
        try {
            $output = $this->execRemote("cat /etc/os-release 2>/dev/null | grep -E '^(PRETTY_NAME|VERSION_ID)=' | head -2");
            $ms = $this->elapsed($start);

            $lines = trim($output['output'] ?? '');
            $os = '';
            $versionId = '';

            foreach (explode("\n", $lines) as $line) {
                if (strpos($line, 'PRETTY_NAME') !== false) {
                    $os = trim(str_replace(['PRETTY_NAME=', '"'], '', $line));
                }
                if (strpos($line, 'VERSION_ID') !== false) {
                    $versionId = trim(str_replace(['VERSION_ID=', '"'], '', $line));
                }
            }

            $supported = false;
            if (preg_match('/ubuntu/i', $os)) {
                $supported = version_compare($versionId, '20.04', '>=');
            } elseif (preg_match('/debian/i', $os)) {
                $supported = version_compare($versionId, '11', '>=');
            }

            $status = $supported ? 'pass' : 'warn';
            $msg = $os ?: 'Unknown OS';
            if (!$supported && $os) {
                $msg .= ' -- may not be fully compatible';
            }

            return $this->result('os_version', 'OS Version', 'info', $status,
                $msg, ['os' => $os, 'version_id' => $versionId], $ms);
        } catch (\Throwable $e) {
            return $this->result('os_version', 'OS Version', 'info', 'pass',
                'Could not detect OS', [], $this->elapsed($start));
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function execRemote(string $command): array
    {
        return $this->ssh->exec($command);
    }

    private function elapsed(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }

    private function result(
        string $key,
        string $name,
        string $category,
        string $status,
        string $message,
        array $details = [],
        int $durationMs = 0
    ): array {
        return [
            'key'         => $key,
            'name'        => $name,
            'category'    => $category,
            'status'      => $status,
            'message'     => $message,
            'details'     => $details,
            'duration_ms' => $durationMs,
        ];
    }
}
