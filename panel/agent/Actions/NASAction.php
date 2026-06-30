<?php
/**
 * NAS Action Handler
 * 
 * Manages NAS storage operations:
 * - Test mount point accessibility
 * - Get storage stats (disk usage)
 * - Mount/unmount NFS shares (optional)
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class NASAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'nas';
    }

    public function getMethods(): array
    {
        return [
            'test',      // Test if mount point is accessible
            'stats',     // Get disk usage stats for a mount point
            'mount',     // Mount an NFS share
            'unmount',   // Unmount an NFS share
            'checkNfs',  // Check if NFS client is available
            'preflight', // Pre-mount checks: NFS client (auto-install), CPGuard port, mount dir
            'persist',   // Write idempotent fstab entry so the mount survives reboots
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return $method === 'persist'; // persist modifies /etc/fstab
    }

    /**
     * Test if a mount point is accessible and writable
     * Auto-attempts remount if NFS is not mounted and server info is provided
     */
    public function actionTest(array $params, string $actor): array
    {
        $driver = $params['driver'] ?? 'local';
        $mountPoint = $params['mount_point'] ?? '';
        $nfsServer = $params['nfs_server'] ?? '';
        $nfsPath = $params['nfs_path'] ?? '';
        $autoRemount = $params['auto_remount'] ?? true;

        if (empty($mountPoint)) {
            return $this->error('Mount point is required');
        }

        $checks = [
            'mount_exists' => false,
            'is_mounted' => false,
            'readable' => false,
            'writable' => false,
            'space_available' => null,
            'auto_remounted' => false,
        ];

        // Create the mount point directory if missing - a missing dir is a
        // setup gap, not a test failure (nas.mount already does the same).
        if (!is_dir($mountPoint) && !@mkdir($mountPoint, 0755, true)) {
            return $this->error("Mount point does not exist and could not be created: {$mountPoint}", [
                'checks' => $checks,
            ]);
        }
        $checks['mount_exists'] = true;

        // For NFS, check if it's actually mounted
        if ($driver === 'nfs') {
            $mountCheck = $this->execCommand('mountpoint', ['-q', $mountPoint], 10);
            $checks['is_mounted'] = $mountCheck['success'];
            
            if (!$checks['is_mounted']) {
                $mounts = @file_get_contents('/proc/mounts');
                if ($mounts !== false && strpos($mounts, $mountPoint) !== false) {
                    $checks['is_mounted'] = true;
                }
            }
            
            if (!$checks['is_mounted'] && $autoRemount && !empty($nfsServer) && !empty($nfsPath)) {
                $this->logger->info("NFS not mounted, attempting auto-remount", [
                    'mount_point' => $mountPoint,
                    'nfs_server' => $nfsServer,
                    'nfs_path' => $nfsPath,
                ]);
                
                $this->execCommand('mount', [$mountPoint], 30);
                $mountCheck = $this->execCommand('mountpoint', ['-q', $mountPoint], 10);
                
                if (!$mountCheck['success']) {
                    $source = "{$nfsServer}:{$nfsPath}";
                    $mountResult = $this->execCommand('mount', [
                        '-t', 'nfs',
                        '-o', 'rw,soft,intr,timeo=30',
                        $source,
                        $mountPoint
                    ], 60);
                    
                    if ($mountResult['success']) {
                        $checks['is_mounted'] = true;
                        $checks['auto_remounted'] = true;
                        $this->logger->info("Auto-remount successful", ['mount_point' => $mountPoint]);
                    } else {
                        $this->logger->warning("Auto-remount failed", [
                            'mount_point' => $mountPoint,
                            'output' => $mountResult['output'] ?? '',
                            'timed_out' => $mountResult['timed_out'] ?? false,
                        ]);
                    }
                } else {
                    $checks['is_mounted'] = true;
                    $checks['auto_remounted'] = true;
                    $this->logger->info("Auto-remount via fstab successful", ['mount_point' => $mountPoint]);
                }
            }
            
            if (!$checks['is_mounted']) {
                return $this->error("NFS share is not mounted at: {$mountPoint}", [
                    'checks' => $checks,
                    'suggestion' => "Try mounting with: mount -t nfs {$nfsServer}:{$nfsPath} {$mountPoint}",
                ]);
            }
        } else {
            // Local storage is always "mounted"
            $checks['is_mounted'] = true;
        }

        // Check read access
        $checks['readable'] = is_readable($mountPoint);
        if (!$checks['readable']) {
            return $this->error("Mount point is not readable: {$mountPoint}", [
                'checks' => $checks,
            ]);
        }

        // Check write access by creating a test file
        $testFile = rtrim($mountPoint, '/') . '/.nas_test_' . time();
        $testContent = 'NAS write test: ' . date('Y-m-d H:i:s');
        
        if (@file_put_contents($testFile, $testContent) !== false) {
            $checks['writable'] = true;
            @unlink($testFile);
        } else {
            return $this->error("Mount point is not writable: {$mountPoint}", [
                'checks' => $checks,
            ]);
        }

        // Get available space
        $freeBytes = @disk_free_space($mountPoint);
        $totalBytes = @disk_total_space($mountPoint);
        
        if ($freeBytes !== false && $totalBytes !== false) {
            $checks['space_available'] = [
                'free' => $freeBytes,
                'total' => $totalBytes,
                'used' => $totalBytes - $freeBytes,
                'free_human' => $this->formatBytes($freeBytes),
                'total_human' => $this->formatBytes($totalBytes),
                'used_percent' => round(($totalBytes - $freeBytes) / $totalBytes * 100, 1),
            ];
        }

        return $this->success([
            'mount_point' => $mountPoint,
            'driver' => $driver,
            'checks' => $checks,
        ], 'Storage connection test successful');
    }

    /**
     * Get storage stats for a mount point
     */
    public function actionStats(array $params, string $actor): array
    {
        $mountPoint = $params['mount_point'] ?? '';

        if (empty($mountPoint)) {
            return $this->error('Mount point is required');
        }

        if (!is_dir($mountPoint)) {
            return $this->error("Mount point does not exist: {$mountPoint}");
        }

        $freeBytes = @disk_free_space($mountPoint);
        $totalBytes = @disk_total_space($mountPoint);

        if ($freeBytes === false || $totalBytes === false) {
            return $this->error("Unable to get disk stats for: {$mountPoint}");
        }

        $usedBytes = $totalBytes - $freeBytes;
        $usedPercent = $totalBytes > 0 ? round($usedBytes / $totalBytes * 100, 1) : 0;

        $inodeStats = null;
        $dfResult = $this->execCommand('df', ['-i', $mountPoint], 15);
        if ($dfResult['success']) {
            $lines = explode("\n", trim($dfResult['output']));
            if (count($lines) >= 2) {
                $parts = preg_split('/\s+/', $lines[1]);
                if (count($parts) >= 5) {
                    $inodeStats = [
                        'total' => (int)$parts[1],
                        'used' => (int)$parts[2],
                        'free' => (int)$parts[3],
                        'used_percent' => trim($parts[4], '%'),
                    ];
                }
            }
        }

        $mountInfo = null;
        $mountResult = $this->execCommand('findmnt', ['-n', '-o', 'SOURCE,FSTYPE,OPTIONS', $mountPoint], 10);
        if ($mountResult['success']) {
            $parts = preg_split('/\s+/', trim($mountResult['output']), 3);
            if (count($parts) >= 2) {
                $mountInfo = [
                    'source' => $parts[0],
                    'fstype' => $parts[1],
                    'options' => $parts[2] ?? '',
                ];
            }
        }

        return $this->success([
            'mount_point' => $mountPoint,
            'bytes' => [
                'total' => $totalBytes,
                'used' => $usedBytes,
                'free' => $freeBytes,
            ],
            'human' => [
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'free' => $this->formatBytes($freeBytes),
            ],
            'used_percent' => $usedPercent,
            'inodes' => $inodeStats,
            'mount' => $mountInfo,
        ]);
    }

    /**
     * Mount an NFS share
     */
    public function actionMount(array $params, string $actor): array
    {
        $nfsServer = $params['nfs_server'] ?? '';
        $nfsPath = $params['nfs_path'] ?? '';
        $mountPoint = $params['mount_point'] ?? '';
        $options = $params['nfs_options'] ?? 'rw,soft,timeo=10,retrans=3';

        if (empty($nfsServer) || empty($nfsPath) || empty($mountPoint)) {
            return $this->error('NFS server, path, and mount point are required');
        }

        $mountCheck = $this->execCommand('mountpoint', ['-q', $mountPoint], 10);
        if ($mountCheck['success']) {
            return $this->success([
                'mount_point' => $mountPoint,
                'already_mounted' => true,
            ], 'Mount point is already mounted');
        }

        if (!is_dir($mountPoint)) {
            if (!@mkdir($mountPoint, 0755, true)) {
                return $this->error("Failed to create mount point directory: {$mountPoint}");
            }
        }

        $source = "{$nfsServer}:{$nfsPath}";
        $mountResult = $this->execCommand('mount', [
            '-t', 'nfs',
            '-o', $options,
            $source,
            $mountPoint
        ], 60);

        if (!$mountResult['success']) {
            $msg = ($mountResult['timed_out'] ?? false)
                ? "NFS mount timed out after 60s. Check VPN connectivity and NFS server availability."
                : "Failed to mount NFS share: " . $mountResult['output'];
            return $this->error($msg);
        }

        $this->logger->info("Mounted NFS share", [
            'source' => $source,
            'mount_point' => $mountPoint,
            'actor' => $actor,
        ]);

        return $this->success([
            'mount_point' => $mountPoint,
            'source' => $source,
        ], 'NFS share mounted successfully');
    }

    /**
     * Unmount an NFS share
     */
    public function actionUnmount(array $params, string $actor): array
    {
        $mountPoint = $params['mount_point'] ?? '';

        if (empty($mountPoint)) {
            return $this->error('Mount point is required');
        }

        $mountCheck = $this->execCommand('mountpoint', ['-q', $mountPoint], 10);
        if (!$mountCheck['success']) {
            return $this->success([
                'mount_point' => $mountPoint,
                'was_mounted' => false,
            ], 'Mount point is not mounted');
        }

        $unmountResult = $this->execCommand('umount', [$mountPoint], 30);

        if (!$unmountResult['success']) {
            $lazyResult = $this->execCommand('umount', ['-l', $mountPoint], 15);
            if (!$lazyResult['success']) {
                return $this->error("Failed to unmount: " . $unmountResult['output']);
            }
        }

        $this->logger->info("Unmounted NFS share", [
            'mount_point' => $mountPoint,
            'actor' => $actor,
        ]);

        return $this->success([
            'mount_point' => $mountPoint,
        ], 'Mount point unmounted successfully');
    }

    /**
     * Check if NFS client utilities are available
     */
    public function actionCheckNfs(array $params, string $actor): array
    {
        $checks = [
            'nfs_client' => false,
            'mount_command' => false,
            'rpcbind' => false,
        ];

        $nfsCheck = $this->execCommand('which', ['mount.nfs'], 5);
        $checks['nfs_client'] = $nfsCheck['success'];

        $mountCheck = $this->execCommand('which', ['mount'], 5);
        $checks['mount_command'] = $mountCheck['success'];

        $rpcCheck = $this->execCommand('systemctl', ['is-active', 'rpcbind'], 5);
        $checks['rpcbind'] = trim($rpcCheck['output']) === 'active';

        $allPassed = $checks['nfs_client'] && $checks['mount_command'];

        if (!$allPassed) {
            $missing = [];
            if (!$checks['nfs_client']) {
                $missing[] = 'NFS client (install: yum install nfs-utils)';
            }
            if (!$checks['mount_command']) {
                $missing[] = 'mount command';
            }
            
            return $this->error('NFS client requirements not met', [
                'checks' => $checks,
                'missing' => $missing,
            ]);
        }

        return $this->success([
            'checks' => $checks,
        ], 'NFS client is available');
    }

    /**
     * Pre-mount checks for the NAS setup wizard.
     *
     * Verifies (and where possible auto-fixes) everything a mount needs:
     *  - NFS client tools present (auto-installs nfs-common / nfs-utils)
     *  - rpcbind service state (informational - NFSv4 works without it)
     *  - CPGuard outbound port for the VPN (warning only; filtering may be off)
     *  - mount point directory exists (created if missing)
     */
    public function actionPreflight(array $params, string $actor): array
    {
        $mountPoint = $params['mount_point'] ?? '';
        $vpnPort = (int)($params['vpn_port'] ?? 1194);
        $checkVpnPort = !empty($params['vpn_enabled']);

        $checks = [];
        $fatal = [];

        // -- NFS client tools (auto-install when missing) ---------------------
        $hasNfs = $this->execCommand('which', ['mount.nfs'], 5)['success'];
        $installed = false;
        if (!$hasNfs) {
            if (file_exists('/usr/bin/apt-get')) {
                $this->execCommand('/usr/bin/apt-get', ['install', '-y', 'nfs-common'], 180);
            } elseif (file_exists('/usr/bin/yum')) {
                $this->execCommand('/usr/bin/yum', ['install', '-y', 'nfs-utils'], 180);
            } elseif (file_exists('/usr/bin/dnf')) {
                $this->execCommand('/usr/bin/dnf', ['install', '-y', 'nfs-utils'], 180);
            }
            $hasNfs = $this->execCommand('which', ['mount.nfs'], 5)['success'];
            $installed = $hasNfs;
        }
        $checks['nfs_client'] = [
            'status'  => $hasNfs ? 'ok' : 'error',
            'label'   => 'NFS client tools',
            'message' => $hasNfs
                ? ($installed ? 'Installed automatically' : 'Already installed')
                : 'mount.nfs missing and automatic install failed - install nfs-common (apt) or nfs-utils (yum) manually',
        ];
        if (!$hasNfs) {
            $fatal[] = 'NFS client tools missing';
        }

        // -- rpcbind (informational; NFSv4 does not require it) ----------------
        $rpc = trim($this->execCommand('systemctl', ['is-active', 'rpcbind'], 5)['output']);
        $checks['rpcbind'] = [
            'status'  => $rpc === 'active' ? 'ok' : 'warning',
            'label'   => 'rpcbind service',
            'message' => $rpc === 'active' ? 'Active' : "Not active ({$rpc}) - fine for NFSv4, needed for NFSv3",
        ];

        // -- CPGuard outbound port for the VPN tunnel --------------------------
        if ($checkVpnPort) {
            $nft = $this->execCommand($this->which('nft'), ['list', 'set', 'inet', 'cpguard_fw', 'tcp_out'], 10);
            $portOpen = !$nft['success'] || preg_match('/\b' . $vpnPort . '\b/', $nft['output']);
            $checks['cpguard_port'] = [
                'status'  => $portOpen ? 'ok' : 'error',
                'label'   => "CPGuard outbound port {$vpnPort}",
                'message' => $portOpen
                    ? ($nft['success'] ? "Port {$vpnPort} allowed in CPGuard TCP OUT" : 'CPGuard filtering not detected')
                    : "Port {$vpnPort} MISSING from CPGuard TCP OUT - VPN cannot connect. Fix: nft add element inet cpguard_fw tcp_out { {$vpnPort} }",
            ];
            if (!$portOpen) {
                $fatal[] = "CPGuard blocks outbound port {$vpnPort}";
            }
        }

        // -- Mount point directory ---------------------------------------------
        $dirOk = is_dir($mountPoint);
        if (!$dirOk && $mountPoint !== '') {
            $dirOk = @mkdir($mountPoint, 0755, true);
        }
        $checks['mount_dir'] = [
            'status'  => $dirOk ? 'ok' : 'error',
            'label'   => 'Mount point directory',
            'message' => $dirOk ? "{$mountPoint} ready" : "Could not create {$mountPoint}",
        ];
        if (!$dirOk) {
            $fatal[] = "Mount point directory {$mountPoint} could not be created";
        }

        if (!empty($fatal)) {
            return $this->error(implode('; ', $fatal), ['checks' => $checks]);
        }

        return $this->success(['checks' => $checks], 'Preflight checks passed');
    }

    /**
     * Persist an NFS mount in /etc/fstab so it survives reboots.
     *
     * Idempotent: any existing fstab line for the same mount point is replaced.
     * Uses soft-mount + systemd automount options so a dead NAS can never hang
     * the server at boot or wedge processes in uninterruptible sleep.
     */
    public function actionPersist(array $params, string $actor): array
    {
        $nfsServer = $params['nfs_server'] ?? '';
        $nfsPath = $params['nfs_path'] ?? '';
        $mountPoint = $params['mount_point'] ?? '';
        $options = $params['nfs_options'] ?? 'rw,soft,timeo=10,retrans=3,_netdev,nofail,x-systemd.automount';

        if (empty($nfsServer) || empty($nfsPath) || empty($mountPoint)) {
            return $this->error('NFS server, path, and mount point are required');
        }

        // fstab fields are whitespace-separated: refuse values that would
        // corrupt the table or smuggle in extra entries.
        foreach (['nfs_server' => $nfsServer, 'nfs_path' => $nfsPath, 'mount_point' => $mountPoint, 'nfs_options' => $options] as $field => $value) {
            if (preg_match('/[\s]/', $value)) {
                return $this->error("Invalid {$field}: must not contain whitespace");
            }
        }

        $options = self::normalizeFstabOptions($options);

        $fstabPath = '/etc/fstab';
        $fstab = @file_get_contents($fstabPath);
        if ($fstab === false) {
            return $this->error('Could not read /etc/fstab');
        }

        $this->backupFile($fstabPath, 'persist', $actor);

        $newLine = "{$nfsServer}:{$nfsPath} {$mountPoint} nfs {$options} 0 0";
        ['content' => $content, 'replaced' => $replaced] = self::rewriteFstab($fstab, $newLine, $mountPoint);

        if (@file_put_contents($fstabPath, $content) === false) {
            return $this->error('Could not write /etc/fstab');
        }

        // Let systemd pick up the new automount unit.
        $this->execCommand('systemctl', ['daemon-reload'], 30);

        $this->logger->info('Persisted NFS mount in fstab', [
            'entry' => $newLine,
            'replaced_existing' => $replaced,
            'actor' => $actor,
        ]);

        return $this->success([
            'fstab_entry' => $newLine,
            'replaced_existing' => $replaced,
        ], 'Mount persisted in /etc/fstab');
    }

    /**
     * Ensure boot-safety options are present: _netdev + nofail are
     * non-negotiable for network mounts (a dead NAS must never block boot).
     * Static + pure so the test suite can verify without touching /etc/fstab.
     */
    public static function normalizeFstabOptions(string $options): string
    {
        $optList = array_filter(array_map('trim', explode(',', $options)));
        foreach (['_netdev', 'nofail'] as $required) {
            if (!in_array($required, $optList, true)) {
                $optList[] = $required;
            }
        }
        return implode(',', $optList);
    }

    /**
     * Rewrite fstab content, replacing any active entry for the given mount
     * point with $newLine (or appending it). Pure function for testability.
     *
     * @return array{content: string, replaced: bool}
     */
    public static function rewriteFstab(string $fstab, string $newLine, string $mountPoint): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $fstab);
        $kept = [];
        $replaced = false;
        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));
            // Field 2 is the mount point - replace any existing entry for it.
            if (count($fields) >= 2 && $fields[1] === $mountPoint && $line !== '' && $line[0] !== '#') {
                if (!$replaced) {
                    $kept[] = $newLine;
                    $replaced = true;
                }
                continue;
            }
            $kept[] = $line;
        }
        // Normalize the tail to exactly one trailing newline; without this the
        // replace path would grow a blank line on every run (not idempotent).
        while (!empty($kept) && trim((string)end($kept)) === '') {
            array_pop($kept);
        }
        if (!$replaced) {
            $kept[] = $newLine;
        }

        return ['content' => implode("\n", $kept) . "\n", 'replaced' => $replaced];
    }

    /**
     * Resolve a command to an absolute path (sbin dirs are often not in the
     * agent's PATH).
     */
    private function which(string $cmd): string
    {
        foreach (['/usr/sbin', '/usr/bin', '/sbin', '/bin', '/usr/local/bin', '/usr/local/sbin'] as $dir) {
            if (file_exists("{$dir}/{$cmd}")) {
                return "{$dir}/{$cmd}";
            }
        }
        return $cmd;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

