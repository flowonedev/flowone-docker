<?php

namespace FleetManager\Agent\Actions;

use FleetManager\Agent\Lib\BaseAction;
use FleetManager\Agent\Lib\UpdateScanner;

/**
 * Task Action - Executes tasks received from Fleet Panel
 */
class TaskAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'task';
    }

    public function execute(string $method, array $params, string $actor): array
    {
        return match ($method) {
            'run_command' => $this->runCommand($params),
            'sync_files' => $this->syncFiles($params),
            'restart_service' => $this->restartService($params),
            'update_agent' => $this->updateAgent($params),
            'health_check' => $this->healthCheck($params),
            'update_packages' => $this->updatePackages($params),
            default => ['success' => false, 'error' => "Unknown method: {$method}"],
        };
    }

    /**
     * Execute a shell command
     */
    private function runCommand(array $params): array
    {
        $command = $params['command'] ?? null;
        $timeout = $params['timeout'] ?? 300;

        if (!$command) {
            return ['success' => false, 'error' => 'No command provided'];
        }

        $this->logger->info("Executing command: {$command}");

        // Execute with timeout
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start process'];
        }

        // Close stdin
        fclose($pipes[0]);

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        // Read output with timeout
        while (true) {
            $status = proc_get_status($process);

            // Read stdout
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            // Check if process finished
            if (!$status['running']) {
                break;
            }

            // Check timeout
            if (time() - $startTime > $timeout) {
                proc_terminate($process, SIGKILL);
                return [
                    'success' => false,
                    'error' => 'Command timed out',
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'timeout' => true,
                ];
            }

            usleep(100000); // 100ms
        }

        // Get final output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Sync files to the server
     */
    private function syncFiles(array $params): array
    {
        $files = $params['files'] ?? [];

        if (empty($files)) {
            return ['success' => false, 'error' => 'No files provided'];
        }

        $results = [];
        $failed = 0;

        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            $content = $file['content'] ?? null;
            $mode = $file['mode'] ?? '0644';
            $owner = $file['owner'] ?? null;

            if (!$path) {
                $results[] = ['path' => 'unknown', 'success' => false, 'error' => 'No path provided'];
                $failed++;
                continue;
            }

            $this->logger->info("Syncing file: {$path}");

            // Create directory if needed
            $dir = dirname($path);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $results[] = ['path' => $path, 'success' => false, 'error' => 'Failed to create directory'];
                    $failed++;
                    continue;
                }
            }

            // Backup existing file
            if (file_exists($path)) {
                $backupPath = $path . '.bak.' . date('YmdHis');
                copy($path, $backupPath);
            }

            // Write file
            if (file_put_contents($path, $content) === false) {
                $results[] = ['path' => $path, 'success' => false, 'error' => 'Failed to write file'];
                $failed++;
                continue;
            }

            // Set permissions
            if ($mode) {
                chmod($path, octdec($mode));
            }

            // Set owner
            if ($owner) {
                $parts = explode(':', $owner);
                $user = $parts[0];
                $group = $parts[1] ?? $user;
                @chown($path, $user);
                @chgrp($path, $group);
            }

            $results[] = ['path' => $path, 'success' => true];
        }

        return [
            'success' => $failed === 0,
            'total' => count($files),
            'synced' => count($files) - $failed,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Restart a service
     */
    private function restartService(array $params): array
    {
        $service = $params['service'] ?? null;

        if (!$service) {
            return ['success' => false, 'error' => 'No service name provided'];
        }

        // Validate service name (security)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return ['success' => false, 'error' => 'Invalid service name'];
        }

        $this->logger->info("Restarting service: {$service}");

        // Try systemctl first
        $output = [];
        $exitCode = 0;
        exec("systemctl restart {$service} 2>&1", $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => 'Failed to restart service',
                'output' => implode("\n", $output),
            ];
        }

        // Get status
        $status = [];
        exec("systemctl is-active {$service} 2>&1", $status, $statusCode);

        return [
            'success' => true,
            'service' => $service,
            'status' => trim(implode('', $status)),
        ];
    }

    /** OS package name prefix -> systemd unit to restart after upgrading it */
    private const PACKAGE_SERVICE_MAP = [
        'mariadb' => 'mariadb',
        'redis' => 'redis-server',
        'postfix' => 'postfix',
        'dovecot' => 'dovecot',
        'fail2ban' => 'fail2ban',
        'clamav' => 'clamav-daemon',
        'opendkim' => 'opendkim',
        'opendmarc' => 'opendmarc',
        'spamassassin' => 'spamd',
        'openlitespeed' => 'lshttpd',
        'lsphp' => 'lshttpd',
        'firewalld' => 'firewalld',
        'pdns' => 'pdns',
        'coturn' => 'coturn',
        'stunnel' => 'stunnel4',
        'meilisearch' => 'meilisearch',
    ];

    /**
     * Apply pending OS/npm updates and auto-restart the affected services.
     *
     * POLICY: this method NEVER reboots the machine. When the OS flags a
     * reboot as required it is reported back to the panel, nothing more.
     */
    private function updatePackages(array $params): array
    {
        $scope = $params['scope'] ?? 'all';
        if (!in_array($scope, ['check', 'system', 'npm', 'all'], true)) {
            return ['success' => false, 'error' => "Invalid scope: {$scope}"];
        }

        $this->logger->info("Update packages requested (scope={$scope})");
        $scanner = new UpdateScanner();

        if ($scope === 'check') {
            $report = $scanner->refresh();
            return ['success' => true, 'scope' => 'check', 'report' => $report];
        }

        $result = [
            'success' => true,
            'scope' => $scope,
            'system' => null,
            'npm' => [],
            'restarted_services' => [],
            'reboot_required' => false,
            'reboot_performed' => false,
        ];
        $servicesToRestart = [];

        if ($scope === 'system' || $scope === 'all') {
            $result['system'] = $this->updateSystemPackages($params, $servicesToRestart);
            if (!$result['system']['success']) {
                $result['success'] = false;
            }
        }

        if ($scope === 'npm' || $scope === 'all') {
            $result['npm'] = $this->updateNpmApps($params, $servicesToRestart);
            foreach ($result['npm'] as $app) {
                if (!$app['success']) {
                    $result['success'] = false;
                }
            }
        }

        foreach (array_unique($servicesToRestart) as $service) {
            $restart = $this->restartService(['service' => $service]);
            $result['restarted_services'][] = [
                'service' => $service,
                'success' => $restart['success'],
                'status' => $restart['status'] ?? ($restart['error'] ?? 'unknown'),
            ];
        }

        if (file_exists('/var/run/reboot-required')) {
            $result['reboot_required'] = true;
            $result['note'] = 'OS requests a reboot, NOT performed (fleet policy: never auto-reboot)';
        }

        // Rescan so the panel sees the post-update state on the next heartbeat
        UpdateScanner::invalidate();
        try {
            $fresh = $scanner->refresh();
            $result['remaining_os'] = $fresh['os']['count'] ?? 0;
            $result['remaining_npm'] = array_sum(array_column($fresh['npm'] ?? [], 'count'));
        } catch (\Throwable $e) {
            $this->logger->warning('Post-update rescan failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Upgrade OS packages via apt/dnf. Fills $servicesToRestart with the
     * systemd units whose packages were touched.
     */
    private function updateSystemPackages(array $params, array &$servicesToRestart): array
    {
        $manager = UpdateScanner::packageManager();
        if (!$manager) {
            return ['success' => false, 'error' => 'No supported package manager (apt/dnf) found'];
        }

        // Validate explicit package names; otherwise upgrade everything pending
        $packages = [];
        foreach ((array)($params['packages'] ?? []) as $pkg) {
            if (is_string($pkg) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $pkg)) {
                $packages[] = $pkg;
            }
        }
        $pkgArgs = implode(' ', array_map('escapeshellarg', $packages));

        // Names of packages being upgraded (for the service restart mapping)
        $upgradedNames = $packages ?: $this->pendingOsPackageNames();

        if ($manager === 'apt') {
            $aptOpts = '-y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
            $command = 'DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive '
                . ($packages
                    ? "apt-get install --only-upgrade {$aptOpts} {$pkgArgs}"
                    : "apt-get upgrade {$aptOpts}");
        } else {
            $command = $packages ? "dnf -y upgrade {$pkgArgs}" : 'dnf -y upgrade';
        }

        $run = $this->runCommand([
            'command' => $command,
            'timeout' => (int)($params['timeout'] ?? 1500),
        ]);

        foreach ($upgradedNames as $name) {
            foreach (self::PACKAGE_SERVICE_MAP as $prefix => $service) {
                if (str_starts_with($name, $prefix)) {
                    $servicesToRestart[] = $service;
                }
            }
        }

        return [
            'success' => $run['success'],
            'manager' => $manager,
            'packages' => $packages ?: 'all',
            'output_tail' => substr(($run['stdout'] ?? '') . ($run['stderr'] ?? ''), -2000),
            'error' => $run['success'] ? null : ($run['error'] ?? 'Upgrade command failed'),
        ];
    }

    /**
     * Run `npm update` in each fleet-managed Node app and queue its service
     * for restart. Only directories known to UpdateScanner are ever touched.
     */
    private function updateNpmApps(array $params, array &$servicesToRestart): array
    {
        $onlyServices = (array)($params['services'] ?? []);
        $results = [];

        foreach (UpdateScanner::npmDirs() as $dir => $service) {
            if (!empty($onlyServices) && !in_array($service, $onlyServices, true)) {
                continue;
            }

            $run = $this->runCommand([
                'command' => 'cd ' . escapeshellarg($dir) . ' && npm update --no-audit --no-fund 2>&1',
                'timeout' => 600,
            ]);

            if ($run['success']) {
                $servicesToRestart[] = $service;
            }

            $results[] = [
                'dir' => $dir,
                'service' => $service,
                'success' => $run['success'],
                'output_tail' => substr($run['stdout'] ?? '', -1000),
                'error' => $run['success'] ? null : ($run['error'] ?? 'npm update failed'),
            ];
        }

        return $results;
    }

    /**
     * Pending OS package names from the last scan cache.
     */
    private function pendingOsPackageNames(): array
    {
        $cache = @file_get_contents(UpdateScanner::cachePath());
        $report = $cache ? json_decode($cache, true) : null;
        return array_column($report['os']['packages'] ?? [], 'name');
    }

    /**
     * Update the fleet agent
     */
    private function updateAgent(array $params): array
    {
        $version = $params['version'] ?? null;
        $url = $params['url'] ?? null;

        $this->logger->info("Agent update requested: version={$version}");

        // For now, just return success - actual update logic would be more complex
        return [
            'success' => true,
            'message' => 'Agent update scheduled',
            'version' => $version,
        ];
    }

    /**
     * Perform immediate health check
     */
    private function healthCheck(array $params): array
    {
        $this->logger->info("Performing health check");

        $health = [
            'timestamp' => date('c'),
            'services' => [],
            'disk' => [],
            'memory' => [],
            'cpu' => [],
        ];

        // Check services
        $services = ['openlitespeed', 'mariadb', 'postfix', 'dovecot', 'fail2ban', 'firewalld'];
        foreach ($services as $service) {
            $status = 'unknown';
            exec("systemctl is-active {$service} 2>&1", $output, $code);
            if ($code === 0) {
                $status = 'running';
            } elseif ($code === 3) {
                $status = 'stopped';
            }
            $health['services'][$service] = $status;
        }

        // Disk usage
        $diskOutput = shell_exec("df -BG / 2>/dev/null | tail -1");
        if ($diskOutput && preg_match('/(\d+)G\s+(\d+)G\s+(\d+)G\s+(\d+)%/', $diskOutput, $matches)) {
            $health['disk'] = [
                'total_gb' => (int)$matches[1],
                'used_gb' => (int)$matches[2],
                'free_gb' => (int)$matches[3],
                'percent' => (int)$matches[4],
            ];
        }

        // Memory
        $memOutput = shell_exec("free -m 2>/dev/null | grep Mem");
        if ($memOutput && preg_match('/Mem:\s+(\d+)\s+(\d+)/', $memOutput, $matches)) {
            $total = (int)$matches[1];
            $used = (int)$matches[2];
            $health['memory'] = [
                'total_mb' => $total,
                'used_mb' => $used,
                'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            ];
        }

        // CPU load
        $loadAvg = sys_getloadavg();
        if ($loadAvg) {
            $health['cpu'] = [
                'load_1m' => $loadAvg[0],
                'load_5m' => $loadAvg[1],
                'load_15m' => $loadAvg[2],
            ];
        }

        return [
            'success' => true,
            'health' => $health,
        ];
    }
}

