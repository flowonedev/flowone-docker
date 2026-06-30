<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class DashboardController extends BaseController
{
    /**
     * Get dashboard overview - with caching
     */
    public function index(Request $request): Response
    {
        // Cache the entire dashboard response for 30 seconds
        $data = $this->cache->remember('dashboard:overview', 30, function() {
            // Get services status
            $services = $this->agent->execute('service.list');
            
            // Get vhosts count
            $vhosts = $this->agent->execute('vhost.list');
            
            // Get databases count
            $databases = $this->agent->execute('db.list');
            
            // Get SSL certificates
            $ssl = $this->agent->execute('ssl.list');

            // Get system info
            $systemInfo = $this->getSystemInfo();

            return [
                'services' => $services['data']['services'] ?? [],
                'sites_count' => count($vhosts['data']['vhosts'] ?? []),
                'databases_count' => count($databases['data']['databases'] ?? []),
                'certificates_count' => count($ssl['data']['certificates'] ?? []),
                'system' => $systemInfo,
            ];
        });

        // Get recent audit logs (don't cache - should be fresh)
        $recentLogs = $this->audit->getLogs([], 1, 10);
        $data['recent_activity'] = $recentLogs['data'];

        return Response::success($data);
    }

    /**
     * Get detailed stats - with short cache
     */
    public function stats(Request $request): Response
    {
        // Cache stats for 10 seconds (system info changes frequently)
        $stats = $this->cache->remember('dashboard:stats', 10, function() {
            return [
                'cpu' => $this->getCpuUsage(),
                'memory' => $this->getMemoryUsage(),
                'disk' => $this->getDiskUsage(),
                'load' => $this->getLoadAverage(),
                'uptime' => $this->getUptime(),
                'network' => $this->getNetworkStats(),
            ];
        });

        return Response::success($stats);
    }

    /**
     * Get system information
     */
    private function getSystemInfo(): array
    {
        $info = [
            'hostname' => gethostname(),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
        ];

        // Add uptime
        if (file_exists('/proc/uptime')) {
            $uptime = (float)file_get_contents('/proc/uptime');
            $info['uptime_seconds'] = $uptime;
            $info['uptime_human'] = $this->formatUptime($uptime);
        }

        return $info;
    }

    /**
     * Get CPU usage
     */
    private function getCpuUsage(): array
    {
        $load = sys_getloadavg();
        
        // Get CPU count
        $cpuCount = 1;
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $cpuCount = substr_count($cpuinfo, 'processor');
        }

        return [
            'load_1' => round($load[0], 2),
            'load_5' => round($load[1], 2),
            'load_15' => round($load[2], 2),
            'cores' => $cpuCount,
            'usage_percent' => round(($load[0] / $cpuCount) * 100, 1),
        ];
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(): array
    {
        $memory = [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'percent' => 0,
        ];

        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
                $memory['total'] = (int)$m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) {
                $memory['free'] = (int)$m[1] * 1024;
            }
            
            $memory['used'] = $memory['total'] - $memory['free'];
            $memory['percent'] = $memory['total'] > 0 
                ? round(($memory['used'] / $memory['total']) * 100, 1) 
                : 0;
        }

        $memory['total_human'] = $this->formatBytes($memory['total']);
        $memory['used_human'] = $this->formatBytes($memory['used']);
        $memory['free_human'] = $this->formatBytes($memory['free']);

        return $memory;
    }

    /**
     * Get disk usage
     */
    private function getDiskUsage(): array
    {
        $disks = [];
        
        // Get main partitions
        $paths = ['/', '/var', '/home'];
        
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $total = disk_total_space($path);
                $free = disk_free_space($path);
                $used = $total - $free;
                
                $disks[] = [
                    'path' => $path,
                    'total' => $total,
                    'used' => $used,
                    'free' => $free,
                    'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
                    'total_human' => $this->formatBytes($total),
                    'used_human' => $this->formatBytes($used),
                    'free_human' => $this->formatBytes($free),
                ];
            }
        }

        return $disks;
    }

    /**
     * Get load average
     */
    private function getLoadAverage(): array
    {
        $load = sys_getloadavg();
        return [
            '1min' => round($load[0], 2),
            '5min' => round($load[1], 2),
            '15min' => round($load[2], 2),
        ];
    }

    /**
     * Get uptime
     */
    private function getUptime(): ?array
    {
        if (!file_exists('/proc/uptime')) {
            return null;
        }

        $uptime = (float)file_get_contents('/proc/uptime');
        
        return [
            'seconds' => $uptime,
            'human' => $this->formatUptime($uptime),
        ];
    }

    /**
     * Get network stats
     */
    private function getNetworkStats(): array
    {
        $stats = [];

        if (file_exists('/proc/net/dev')) {
            $content = file_get_contents('/proc/net/dev');
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                    $interface = $m[1];
                    if ($interface !== 'lo') {
                        $stats[] = [
                            'interface' => $interface,
                            'rx_bytes' => (int)$m[2],
                            'tx_bytes' => (int)$m[3],
                            'rx_human' => $this->formatBytes((int)$m[2]),
                            'tx_human' => $this->formatBytes((int)$m[3]),
                        ];
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format uptime to human readable
     */
    private function formatUptime(float $seconds): string
    {
        $totalSeconds = (int) $seconds;
        $days = (int) floor($totalSeconds / 86400);
        $hours = (int) floor(($totalSeconds % 86400) / 3600);
        $minutes = (int) floor(($totalSeconds % 3600) / 60);

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";

        return implode(' ', $parts) ?: '0m';
    }
}

