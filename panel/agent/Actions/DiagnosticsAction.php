<?php
/**
 * Diagnostics Action Handler
 * 
 * Runs comprehensive connectivity checks for NAS/VPN troubleshooting.
 * Checks are context-aware: if VPN is already connected, DNS/port
 * checks are downgraded to informational since they don't affect
 * the active tunnel. Log analysis only considers entries since the
 * last successful VPN initialization.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class DiagnosticsAction extends BaseAction
{
    private const CONFIG_DIR = '/etc/openvpn/client';

    public function getNamespace(): string
    {
        return 'diagnostics';
    }

    public function getMethods(): array
    {
        return [
            'nasFull',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return false;
    }

    /**
     * Full NAS connectivity diagnostic.
     * Runs VPN state checks first, then uses that context to
     * adjust severity of downstream checks.
     */
    public function actionNasFull(array $params, string $actor): array
    {
        $vpnName = $this->normalizeVpnName($params['vpn_name'] ?? '');
        $nasIp = $params['nas_ip'] ?? '';
        $nasPath = $params['nas_path'] ?? '';
        $mountPoint = $params['mount_point'] ?? '';
        $nfsPort = (int)($params['nfs_port'] ?? 2049);
        $driver = $params['driver'] ?? 'nfs';

        $results = [];
        $overallStatus = 'ok';

        // 1. VPN Config
        $results['vpn_config'] = $this->checkVpnConfig($vpnName);

        // 2-3. VPN Service + TUN interface (run first to determine live state)
        $results['vpn_service'] = $this->checkVpnService($vpnName);
        $results['tun_interface'] = $this->checkTunInterface();

        $vpnConnected = (
            ($results['vpn_service']['status'] ?? '') === 'ok' &&
            ($results['tun_interface']['status'] ?? '') === 'ok'
        );

        // 4. VPN routes
        $results['vpn_routes'] = $this->checkVpnRoutes();

        // 5. Mount point status (run early -- a working mount proves entire chain works)
        $mountOk = false;
        if (!empty($mountPoint)) {
            $results['mount_status'] = $this->checkMountStatus($mountPoint);
            $mountOk = ($results['mount_status']['status'] === 'ok');
        }

        $systemWorking = $vpnConnected && $mountOk;

        // 6. DNS Resolution (OK if system is working, warning if VPN up but no mount)
        $results['dns_resolution'] = $this->checkDnsResolution($vpnName, $vpnConnected, $systemWorking);

        // 7. VPN Port (skip when VPN is already connected)
        $results['vpn_port'] = $this->checkVpnPort($vpnName, $vpnConnected);

        // 8. NAS reachability (if mounted, proven reachable)
        if (!empty($nasIp)) {
            $results['nas_reachable'] = $this->checkNasReachable($nasIp, $nfsPort, $driver, $mountOk);
        }

        // 9. Storage client prerequisites (NFS or CIFS based on driver)
        $results['storage_client'] = $this->checkStorageClient($driver);

        // 10. Storage port on NAS (if mounted, proven accessible)
        if (!empty($nasIp)) {
            $results['storage_port'] = $this->checkStoragePort($nasIp, $nfsPort, $driver, $mountOk);
        }

        // 11. Firewall (OK if system is working -- traffic clearly flows)
        $results['firewall'] = $this->checkFirewall($systemWorking);

        // 12. VPN log analysis (only errors after last successful init)
        if (!empty($vpnName)) {
            $results['vpn_logs'] = $this->analyzeVpnLogs($vpnName, $vpnConnected);
        }

        foreach ($results as $check) {
            if ($check['status'] === 'error') {
                $overallStatus = 'error';
                break;
            }
            if ($check['status'] === 'warning') {
                $overallStatus = 'warning';
            }
        }

        return $this->success([
            'overall_status' => $overallStatus,
            'vpn_connected' => $vpnConnected,
            'checks' => $results,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Normalize VPN name: strip directory and .conf extension.
     * "/etc/openvpn/client/synology.conf" -> "synology"
     */
    private function normalizeVpnName(string $input): string
    {
        if (empty($input)) {
            return '';
        }
        $name = basename($input);
        if (substr($name, -5) === '.conf') {
            $name = substr($name, 0, -5);
        }
        return $name;
    }

    private function checkVpnConfig(string $vpnName): array
    {
        if (empty($vpnName)) {
            return [
                'status' => 'skipped',
                'label' => 'VPN Config',
                'message' => 'No VPN name provided',
                'icon' => 'description',
            ];
        }

        $configFile = self::CONFIG_DIR . "/{$vpnName}.conf";
        if (!file_exists($configFile)) {
            return [
                'status' => 'error',
                'label' => 'VPN Config',
                'message' => "Config file not found: {$configFile}",
                'icon' => 'description',
                'fix' => 'Create a VPN connection in the VPN Connections tab',
            ];
        }

        $content = @file_get_contents($configFile);
        $remotes = [];
        if (preg_match_all('/^remote\s+(\S+)\s+(\d+)/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $remotes[] = $m[1] . ':' . $m[2];
            }
        }

        $protocol = 'unknown';
        if (preg_match('/^proto\s+(\S+)/m', $content, $m)) {
            $protocol = $m[1];
        }

        return [
            'status' => 'ok',
            'label' => 'VPN Config',
            'message' => "Config found: {$configFile}",
            'icon' => 'description',
            'details' => [
                'remotes' => $remotes,
                'protocol' => $protocol,
            ],
        ];
    }

    private function checkVpnService(string $vpnName): array
    {
        if (empty($vpnName)) {
            return [
                'status' => 'skipped',
                'label' => 'VPN Service',
                'message' => 'No VPN name provided',
                'icon' => 'settings_ethernet',
            ];
        }

        $serviceName = "openvpn-client@{$vpnName}";

        $activeResult = $this->execCommand('systemctl', ['is-active', $serviceName], 5);
        $activeStatus = trim($activeResult['output']);

        $enabledResult = $this->execCommand('systemctl', ['is-enabled', $serviceName], 5);
        $enabled = trim($enabledResult['output']) === 'enabled';

        if ($activeStatus === 'active') {
            return [
                'status' => 'ok',
                'label' => 'VPN Service',
                'message' => "Service is active and running" . ($enabled ? ' (auto-start enabled)' : ' (auto-start disabled)'),
                'icon' => 'settings_ethernet',
                'details' => ['service' => $serviceName, 'active' => true, 'enabled' => $enabled],
            ];
        }

        if ($activeStatus === 'activating') {
            return [
                'status' => 'warning',
                'label' => 'VPN Service',
                'message' => 'Service is currently starting/connecting...',
                'icon' => 'settings_ethernet',
                'details' => ['service' => $serviceName, 'active' => false, 'enabled' => $enabled],
            ];
        }

        $fix = $activeStatus === 'failed'
            ? 'Check VPN logs for errors. Try: systemctl restart ' . $serviceName
            : 'Start the VPN: systemctl start ' . $serviceName;

        return [
            'status' => 'error',
            'label' => 'VPN Service',
            'message' => "Service is {$activeStatus}" . ($enabled ? ' (auto-start enabled)' : ''),
            'icon' => 'settings_ethernet',
            'fix' => $fix,
            'details' => ['service' => $serviceName, 'active' => false, 'enabled' => $enabled, 'state' => $activeStatus],
        ];
    }

    private function checkDnsResolution(string $vpnName, bool $vpnConnected, bool $systemWorking): array
    {
        if (empty($vpnName)) {
            return [
                'status' => 'skipped',
                'label' => 'DNS Resolution',
                'message' => 'No VPN name provided',
                'icon' => 'dns',
            ];
        }

        $configFile = self::CONFIG_DIR . "/{$vpnName}.conf";
        if (!file_exists($configFile)) {
            return [
                'status' => 'skipped',
                'label' => 'DNS Resolution',
                'message' => 'VPN config not found',
                'icon' => 'dns',
            ];
        }

        $content = @file_get_contents($configFile);
        $hostnames = [];

        if (preg_match_all('/^remote\s+(\S+)\s+/m', $content, $matches)) {
            $hostnames = array_unique($matches[1]);
        }

        if (empty($hostnames)) {
            return [
                'status' => 'warning',
                'label' => 'DNS Resolution',
                'message' => 'No remote hostname found in VPN config',
                'icon' => 'dns',
            ];
        }

        $resolutions = [];
        $allResolved = true;

        foreach ($hostnames as $host) {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $resolutions[$host] = ['type' => 'ip', 'resolved' => $host];
                continue;
            }

            $result = $this->execCommand('dig', ['+short', $host], 10);
            $resolved = trim($result['output']);

            if (empty($resolved) || !$result['success']) {
                $resolutions[$host] = ['type' => 'hostname', 'resolved' => null, 'error' => 'Failed to resolve'];
                $allResolved = false;
            } else {
                $ips = array_filter(explode("\n", $resolved));
                $resolutions[$host] = ['type' => 'hostname', 'resolved' => $ips];
            }
        }

        if (!$allResolved) {
            if ($systemWorking) {
                return [
                    'status' => 'ok',
                    'label' => 'DNS Resolution',
                    'message' => 'DDNS hostname stale, but VPN + NAS fully working (no impact)',
                    'icon' => 'dns',
                    'details' => ['resolutions' => $resolutions, 'note' => 'DDNS should be refreshed to prevent issues on VPN reconnect'],
                ];
            }

            if ($vpnConnected) {
                return [
                    'status' => 'warning',
                    'label' => 'DNS Resolution',
                    'message' => 'DDNS hostname not resolving, but VPN is connected (tunnel still active)',
                    'icon' => 'dns',
                    'fix' => 'Check DDNS settings on your NAS. The tunnel works now but may fail on reconnect.',
                    'details' => ['resolutions' => $resolutions, 'vpn_connected' => true],
                ];
            }

            return [
                'status' => 'error',
                'label' => 'DNS Resolution',
                'message' => 'One or more hostnames failed to resolve. DDNS may be stale or DNS misconfigured.',
                'icon' => 'dns',
                'fix' => 'Check DDNS settings on your NAS. Verify the hostname resolves to your current public IP.',
                'details' => ['resolutions' => $resolutions],
            ];
        }

        $summary = [];
        foreach ($resolutions as $host => $info) {
            $ip = is_array($info['resolved']) ? implode(', ', $info['resolved']) : $info['resolved'];
            $summary[] = "{$host} -> {$ip}";
        }

        return [
            'status' => 'ok',
            'label' => 'DNS Resolution',
            'message' => implode(' | ', $summary),
            'icon' => 'dns',
            'details' => ['resolutions' => $resolutions],
        ];
    }

    private function checkVpnPort(string $vpnName, bool $vpnConnected): array
    {
        if (empty($vpnName)) {
            return [
                'status' => 'skipped',
                'label' => 'VPN Port Reachable',
                'message' => 'No VPN name provided',
                'icon' => 'swap_horiz',
            ];
        }

        if ($vpnConnected) {
            return [
                'status' => 'ok',
                'label' => 'VPN Port Reachable',
                'message' => 'VPN tunnel is active (port check not needed)',
                'icon' => 'swap_horiz',
                'details' => ['skipped_reason' => 'vpn_connected'],
            ];
        }

        $configFile = self::CONFIG_DIR . "/{$vpnName}.conf";
        if (!file_exists($configFile)) {
            return [
                'status' => 'skipped',
                'label' => 'VPN Port Reachable',
                'message' => 'VPN config not found',
                'icon' => 'swap_horiz',
            ];
        }

        $content = @file_get_contents($configFile);

        if (!preg_match('/^remote\s+(\S+)\s+(\d+)/m', $content, $match)) {
            return [
                'status' => 'warning',
                'label' => 'VPN Port Reachable',
                'message' => 'Could not parse remote address from config',
                'icon' => 'swap_horiz',
            ];
        }

        $host = $match[1];
        $port = $match[2];

        $resolvedHost = $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $digResult = $this->execCommand('dig', ['+short', $host, 'A'], 10);
            $resolved = trim($digResult['output']);
            $ips = array_filter(explode("\n", $resolved));
            if (!empty($ips)) {
                $resolvedHost = $ips[0];
            }
        }

        $result = $this->execCommand('nc', ['-z', '-w', '5', $resolvedHost, $port], 10);

        if ($result['success']) {
            return [
                'status' => 'ok',
                'label' => 'VPN Port Reachable',
                'message' => "Port {$port} on {$host} ({$resolvedHost}) is reachable",
                'icon' => 'swap_horiz',
                'details' => ['host' => $host, 'resolved' => $resolvedHost, 'port' => $port],
            ];
        }

        return [
            'status' => 'error',
            'label' => 'VPN Port Reachable',
            'message' => "Cannot reach {$host}:{$port} -- connection timed out or refused",
            'icon' => 'swap_horiz',
            'fix' => 'Check router port forwarding (WAN port ' . $port . ' -> NAS local IP). Verify the public IP has not changed.',
            'details' => ['host' => $host, 'resolved' => $resolvedHost, 'port' => $port, 'output' => $result['output'] ?? ''],
        ];
    }

    private function checkTunInterface(): array
    {
        $result = $this->execCommand('ip', ['addr', 'show'], 5);
        $output = $result['output'];

        if (preg_match('/\d+:\s+(tun\d+).*state\s+(\w+)/m', $output, $m)) {
            $tunName = $m[1];
            $tunState = $m[2];
            $tunIp = null;

            if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)/', substr($output, strpos($output, $tunName)), $ipMatch)) {
                $tunIp = $ipMatch[1];
            }

            if (strtoupper($tunState) === 'UP' || strtoupper($tunState) === 'UNKNOWN') {
                return [
                    'status' => 'ok',
                    'label' => 'TUN Interface',
                    'message' => "{$tunName} is up" . ($tunIp ? " with IP {$tunIp}" : ''),
                    'icon' => 'lan',
                    'details' => ['interface' => $tunName, 'ip' => $tunIp, 'state' => $tunState],
                ];
            }

            return [
                'status' => 'warning',
                'label' => 'TUN Interface',
                'message' => "{$tunName} exists but state is {$tunState}",
                'icon' => 'lan',
                'details' => ['interface' => $tunName, 'ip' => $tunIp, 'state' => $tunState],
            ];
        }

        return [
            'status' => 'error',
            'label' => 'TUN Interface',
            'message' => 'No TUN interface found. VPN tunnel is not established.',
            'icon' => 'lan',
            'fix' => 'Start the VPN service. If already started, check VPN logs for connection errors.',
        ];
    }

    private function checkVpnRoutes(): array
    {
        $result = $this->execCommand('ip', ['route'], 5);
        $output = $result['output'];

        $tunRoutes = [];
        foreach (explode("\n", $output) as $line) {
            if (strpos($line, 'tun') !== false) {
                $tunRoutes[] = trim($line);
            }
        }

        if (empty($tunRoutes)) {
            return [
                'status' => 'warning',
                'label' => 'VPN Routes',
                'message' => 'No routes going through VPN tunnel. Traffic to NAS may not use the VPN.',
                'icon' => 'route',
                'fix' => 'Add custom routes in the VPN config or up-script for your NAS subnet (e.g. 192.168.1.0/24).',
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'VPN Routes',
            'message' => count($tunRoutes) . ' route(s) through tunnel',
            'icon' => 'route',
            'details' => ['routes' => $tunRoutes],
        ];
    }

    /**
     * Try TCP connection with multiple methods (nc, bash /dev/tcp/, PHP fsockopen).
     * Returns true if any method succeeds.
     */
    private function tcpPortOpen(string $host, int $port): bool
    {
        $result = $this->execCommand('nc', ['-z', '-w', '5', $host, (string)$port], 10);
        if ($result['success']) {
            return true;
        }

        $result = $this->execCommand('bash', ['-c', "timeout 5 bash -c 'echo > /dev/tcp/" . escapeshellarg($host) . "/{$port}' 2>&1"], 10);
        if ($result['success']) {
            return true;
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($fp) {
            fclose($fp);
            return true;
        }

        return false;
    }

    /**
     * Check NAS reachability. If the mount point is already working,
     * NAS is proven reachable. Otherwise test via TCP and ICMP.
     */
    private function checkNasReachable(string $nasIp, int $servicePort, string $driver, bool $mountOk): array
    {
        if ($mountOk) {
            return [
                'status' => 'ok',
                'label' => 'NAS Reachable',
                'message' => "NAS at {$nasIp} is reachable (storage is mounted and working)",
                'icon' => 'cell_tower',
                'details' => ['ip' => $nasIp, 'method' => 'mount_verified'],
            ];
        }

        $port = ($driver === 'cifs' || $driver === 'smb') ? 445 : $servicePort;

        $tcpReachable = $this->tcpPortOpen($nasIp, $port);

        $pingResult = $this->execCommand('ping', ['-c', '3', '-W', '10', $nasIp], 15);
        $pingReachable = $pingResult['success'];

        $rtt = '';
        if ($pingReachable && preg_match('/rtt.*=\s*([\d.]+)\/([\d.]+)/', $pingResult['output'], $m)) {
            $rtt = " (avg {$m[2]}ms)";
        }

        if ($tcpReachable && $pingReachable) {
            return [
                'status' => 'ok',
                'label' => 'NAS Reachable',
                'message' => "NAS at {$nasIp} is reachable (port {$port} open, ping OK{$rtt})",
                'icon' => 'cell_tower',
                'details' => ['ip' => $nasIp, 'tcp_port' => $port, 'tcp' => true, 'ping' => true],
            ];
        }

        if ($tcpReachable && !$pingReachable) {
            return [
                'status' => 'ok',
                'label' => 'NAS Reachable',
                'message' => "NAS at {$nasIp} is reachable (port {$port} open, ICMP ping blocked -- this is normal)",
                'icon' => 'cell_tower',
                'details' => ['ip' => $nasIp, 'tcp_port' => $port, 'tcp' => true, 'ping' => false],
            ];
        }

        if (!$tcpReachable && $pingReachable) {
            return [
                'status' => 'warning',
                'label' => 'NAS Reachable',
                'message' => "NAS at {$nasIp} responds to ping{$rtt} but port {$port} is closed",
                'icon' => 'cell_tower',
                'fix' => "NAS is reachable but port {$port} is not open. Enable the service on NAS and check its firewall.",
                'details' => ['ip' => $nasIp, 'tcp_port' => $port, 'tcp' => false, 'ping' => true],
            ];
        }

        return [
            'status' => 'error',
            'label' => 'NAS Reachable',
            'message' => "Cannot reach NAS at {$nasIp} (port {$port} and ping both failed)",
            'icon' => 'cell_tower',
            'fix' => 'Verify VPN tunnel is connected and routes to NAS subnet exist. Check NAS firewall.',
            'details' => [
                'ip' => $nasIp,
                'tcp_port' => $port,
                'tcp' => false,
                'ping' => false,
                'ping_output' => $pingResult['output'] ?? '',
            ],
        ];
    }

    /**
     * Check if a binary exists in PATH or common sbin locations.
     */
    private function binaryExists(string $name): bool
    {
        $whichResult = $this->execCommand('which', [$name], 5);
        if ($whichResult['success']) {
            return true;
        }

        foreach (['/sbin/', '/usr/sbin/', '/usr/local/sbin/'] as $dir) {
            if (file_exists($dir . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check storage client prerequisites based on driver type (NFS or CIFS).
     * Searches both PATH and /sbin/ locations for mount helpers.
     */
    private function checkStorageClient(string $driver): array
    {
        if ($driver === 'cifs' || $driver === 'smb') {
            $hasCifs = $this->binaryExists('mount.cifs');

            if ($hasCifs) {
                return [
                    'status' => 'ok',
                    'label' => 'Storage Client',
                    'message' => 'CIFS client (mount.cifs) installed',
                    'icon' => 'inventory_2',
                ];
            }

            return [
                'status' => 'error',
                'label' => 'Storage Client',
                'message' => 'Missing: mount.cifs not found',
                'icon' => 'inventory_2',
                'fix' => 'yum install cifs-utils',
                'details' => ['driver' => $driver, 'cifs_installed' => false],
            ];
        }

        $hasNfs = $this->binaryExists('mount.nfs');

        $rpcCheck = $this->execCommand('systemctl', ['is-active', 'rpcbind'], 5);
        $rpcActive = trim($rpcCheck['output']) === 'active';

        if ($hasNfs && $rpcActive) {
            return [
                'status' => 'ok',
                'label' => 'Storage Client',
                'message' => 'NFS client installed, rpcbind active',
                'icon' => 'inventory_2',
            ];
        }

        $issues = [];
        $fixes = [];
        if (!$hasNfs) {
            $issues[] = 'mount.nfs not found';
            $fixes[] = 'yum install nfs-utils';
        }
        if (!$rpcActive) {
            $issues[] = 'rpcbind not running';
            $fixes[] = 'systemctl start rpcbind && systemctl enable rpcbind';
        }

        return [
            'status' => 'error',
            'label' => 'Storage Client',
            'message' => 'Missing: ' . implode(', ', $issues),
            'icon' => 'inventory_2',
            'fix' => implode(' && ', $fixes),
            'details' => ['driver' => $driver, 'nfs_installed' => $hasNfs, 'rpcbind_active' => $rpcActive],
        ];
    }

    /**
     * Check storage port on NAS. If mount is working, port is proven accessible.
     * Otherwise tries nc, bash /dev/tcp/, and PHP fsockopen as fallbacks.
     */
    private function checkStoragePort(string $nasIp, int $nfsPort, string $driver, bool $mountOk): array
    {
        $port = ($driver === 'cifs' || $driver === 'smb') ? 445 : $nfsPort;
        $label = ($driver === 'cifs' || $driver === 'smb') ? 'CIFS' : 'NFS';

        if ($mountOk) {
            return [
                'status' => 'ok',
                'label' => "{$label} Port",
                'message' => "{$label} port {$port} on {$nasIp} is accessible (storage is mounted)",
                'icon' => 'lock_open',
                'details' => ['ip' => $nasIp, 'port' => $port, 'driver' => $driver, 'method' => 'mount_verified'],
            ];
        }

        $reachable = $this->tcpPortOpen($nasIp, $port);

        if ($reachable) {
            return [
                'status' => 'ok',
                'label' => "{$label} Port",
                'message' => "{$label} port {$port} on {$nasIp} is open",
                'icon' => 'lock_open',
                'details' => ['ip' => $nasIp, 'port' => $port, 'driver' => $driver],
            ];
        }

        return [
            'status' => 'error',
            'label' => "{$label} Port",
            'message' => "{$label} port {$port} on {$nasIp} is not reachable",
            'icon' => 'lock_open',
            'fix' => "Enable {$label} on the NAS and ensure its firewall allows port {$port} from the VPN subnet.",
            'details' => ['ip' => $nasIp, 'port' => $port, 'driver' => $driver],
        ];
    }

    /**
     * Detect mount status by actual functionality tests rather than
     * relying on mountpoint/proc_mounts detection which can fail
     * due to proc_open exit code bugs or namespace issues.
     *
     * Strategy: test if the directory works (writable, has disk space
     * different from root), read /proc/mounts for type info.
     */
    private function checkMountStatus(string $mountPoint): array
    {
        if (!is_dir($mountPoint)) {
            return [
                'status' => 'error',
                'label' => 'Mount Point',
                'message' => "Directory does not exist: {$mountPoint}",
                'icon' => 'folder',
                'fix' => "Create the mount point: mkdir -p {$mountPoint}",
            ];
        }

        $freeBytes = @disk_free_space($mountPoint);
        $totalBytes = @disk_total_space($mountPoint);

        $testFile = rtrim($mountPoint, '/') . '/.diag_test_' . time();
        $writable = @file_put_contents($testFile, 'test');
        if ($writable !== false) {
            @unlink($testFile);
        }

        $mountType = '';
        $mountSource = '';
        $mounts = @file_get_contents('/proc/mounts');
        if ($mounts !== false) {
            foreach (explode("\n", $mounts) as $line) {
                $parts = preg_split('/\s+/', $line);
                if (isset($parts[1]) && $parts[1] === $mountPoint) {
                    $mountSource = $parts[0] ?? '';
                    $mountType = $parts[2] ?? '';
                    break;
                }
            }
        }

        $rootTotal = @disk_total_space('/');
        $isDifferentFs = ($totalBytes !== false && $rootTotal !== false && $totalBytes !== $rootTotal);

        $isMounted = !empty($mountType) || $isDifferentFs;

        if (!$isMounted) {
            $stat = @stat($mountPoint);
            $parentStat = @stat(dirname($mountPoint));
            if ($stat && $parentStat && $stat['dev'] !== $parentStat['dev']) {
                $isMounted = true;
            }
        }

        if (!$isMounted && $writable !== false && $totalBytes !== false && $totalBytes > 0) {
            $isMounted = true;
        }

        if (!$isMounted) {
            return [
                'status' => 'error',
                'label' => 'Mount Point',
                'message' => "Not mounted: {$mountPoint}",
                'icon' => 'folder',
                'fix' => 'Mount the NFS share using the Mount button, or fix the VPN tunnel first if NAS is unreachable.',
                'details' => [
                    'dir_exists' => true,
                    'writable' => $writable !== false,
                    'total_bytes' => $totalBytes,
                    'root_total_bytes' => $rootTotal,
                    'mount_type' => $mountType,
                    'mount_source' => $mountSource,
                ],
            ];
        }

        $spaceInfo = '';
        if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
            $usedPct = round(($totalBytes - $freeBytes) / $totalBytes * 100, 1);
            $freeHuman = $this->formatDiagBytes($totalBytes - ($totalBytes - $freeBytes));
            $totalHuman = $this->formatDiagBytes($totalBytes);
            $spaceInfo = " | {$usedPct}% used ({$freeHuman} free of {$totalHuman})";
        }

        $typeLabel = $mountType ?: 'unknown';

        return [
            'status' => $writable !== false ? 'ok' : 'warning',
            'label' => 'Mount Point',
            'message' => "Mounted ({$typeLabel}) and " . ($writable !== false ? 'writable' : 'read-only') . $spaceInfo,
            'icon' => 'folder',
            'details' => [
                'mount_point' => $mountPoint,
                'mount_type' => $mountType,
                'mount_source' => $mountSource,
                'mounted' => true,
                'writable' => $writable !== false,
                'free_bytes' => $freeBytes,
                'total_bytes' => $totalBytes,
            ],
        ];
    }

    private function formatDiagBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    private function checkFirewall(bool $systemWorking): array
    {
        $activeResult = $this->execCommand('systemctl', ['is-active', 'firewalld'], 5);
        if (trim($activeResult['output']) !== 'active') {
            return [
                'status' => 'ok',
                'label' => 'Firewall',
                'message' => 'FirewallD is not active (no firewall blocking)',
                'icon' => 'shield',
            ];
        }

        $zonesResult = $this->execCommand('firewall-cmd', ['--get-active-zones'], 5);
        $zones = $zonesResult['output'];

        $tunInZone = strpos($zones, 'tun') !== false;

        $trustedCheck = $this->execCommand('firewall-cmd', ['--zone=trusted', '--list-interfaces'], 5);
        $trustedIfaces = trim($trustedCheck['output'] ?? '');
        $tunInTrusted = strpos($trustedIfaces, 'tun') !== false;

        if ($tunInZone || $tunInTrusted) {
            return [
                'status' => 'ok',
                'label' => 'Firewall',
                'message' => 'FirewallD active, TUN interface is in a trusted zone',
                'icon' => 'shield',
                'details' => ['zones' => $zones, 'trusted_interfaces' => $trustedIfaces],
            ];
        }

        if ($systemWorking) {
            return [
                'status' => 'ok',
                'label' => 'Firewall',
                'message' => 'FirewallD active, VPN traffic flowing correctly (firewall not blocking)',
                'icon' => 'shield',
                'details' => ['zones' => $zones, 'note' => 'TUN not in trusted zone but traffic works via other rules'],
            ];
        }

        $result = $this->execCommand('firewall-cmd', ['--list-all'], 5);
        $firewallInfo = $result['output'];

        return [
            'status' => 'warning',
            'label' => 'Firewall',
            'message' => 'FirewallD is active but TUN interface may not be in trusted zone',
            'icon' => 'shield',
            'fix' => 'firewall-cmd --zone=trusted --add-interface=tun0 --permanent && firewall-cmd --reload',
            'details' => ['zones' => $zones, 'firewall_info' => $firewallInfo],
        ];
    }

    /**
     * Analyze VPN logs intelligently:
     * - Only considers logs since last successful initialization
     * - If VPN is connected and "Initialization Sequence Completed" is
     *   the last significant entry, earlier errors are ignored
     */
    private function analyzeVpnLogs(string $vpnName, bool $vpnConnected): array
    {
        $serviceName = "openvpn-client@{$vpnName}";
        $result = $this->execCommand('journalctl', [
            '-u', $serviceName, '-n', '50', '--no-pager', '-o', 'short-iso',
        ], 10);

        $logs = $result['output'];

        if (empty(trim($logs))) {
            return [
                'status' => 'warning',
                'label' => 'VPN Logs',
                'message' => 'No recent VPN log entries found',
                'icon' => 'article',
            ];
        }

        $lastInitPos = strrpos($logs, 'Initialization Sequence Completed');
        $initCompleted = ($lastInitPos !== false);

        $logsToCheck = $logs;
        if ($initCompleted) {
            $logsAfterInit = substr($logs, $lastInitPos);
            $logsToCheck = $logsAfterInit;
        }

        $issues = [];

        if (stripos($logsToCheck, 'Connection timed out') !== false) {
            $issues[] = 'Connection timed out -- VPN server unreachable (check port forward / public IP)';
        }
        if (stripos($logsToCheck, 'Network is unreachable') !== false) {
            $issues[] = 'Network unreachable -- likely IPv6 or routing issue';
        }
        if (stripos($logsToCheck, 'TLS handshake failed') !== false || stripos($logsToCheck, 'TLS Error') !== false) {
            $issues[] = 'TLS handshake failed -- certificate mismatch or expired';
        }
        if (stripos($logsToCheck, 'AUTH_FAILED') !== false) {
            $issues[] = 'Authentication failed -- wrong credentials or certificates';
        }
        if (stripos($logsToCheck, 'Cannot resolve host') !== false) {
            $issues[] = 'DNS resolution failed -- DDNS hostname not resolving';
        }
        if (stripos($logsToCheck, 'process exiting') !== false && stripos($logsToCheck, 'SIGTERM') !== false) {
            $issues[] = 'VPN was terminated (SIGTERM received)';
        }

        $deprecatedWarnings = (stripos($logs, 'DEPRECATED OPTION') !== false);

        if ($initCompleted && empty($issues)) {
            $msg = 'VPN initialized successfully (no errors since last connection)';
            if ($deprecatedWarnings) {
                $msg .= ' -- deprecated cipher warnings present';
            }
            return [
                'status' => 'ok',
                'label' => 'VPN Logs',
                'message' => $msg,
                'icon' => 'article',
                'details' => [
                    'log_snippet' => substr($logs, -500),
                    'deprecated_warnings' => $deprecatedWarnings,
                ],
            ];
        }

        if ($vpnConnected && $initCompleted && !empty($issues)) {
            return [
                'status' => 'warning',
                'label' => 'VPN Logs',
                'message' => 'VPN is connected but ' . count($issues) . ' warning(s) after last init',
                'icon' => 'article',
                'fix' => implode("\n", $issues),
                'details' => ['issues' => $issues, 'log_snippet' => substr($logs, -800)],
            ];
        }

        if (!empty($issues)) {
            return [
                'status' => 'error',
                'label' => 'VPN Logs',
                'message' => count($issues) . ' issue(s) detected in logs',
                'icon' => 'article',
                'fix' => implode("\n", $issues),
                'details' => ['issues' => $issues, 'log_snippet' => substr($logs, -800)],
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'VPN Logs',
            'message' => 'No error patterns detected in recent logs',
            'icon' => 'article',
            'details' => ['log_snippet' => substr($logs, -500)],
        ];
    }
}
