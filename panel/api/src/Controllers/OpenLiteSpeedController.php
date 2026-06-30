<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class OpenLiteSpeedController extends BaseController
{
    private string $configPath = '/usr/local/lsws/conf/httpd_config.conf';
    private string $olsBin = '/usr/local/lsws/bin/lswsctrl';

    /**
     * Get OpenLiteSpeed status
     */
    public function status(Request $request): Response
    {
        $running = false;
        $version = null;
        $uptime = null;
        $pid = null;
        
        // Check if lsws is running
        exec('systemctl is-active lsws 2>/dev/null', $output, $exitCode);
        $running = ($exitCode === 0 && isset($output[0]) && trim($output[0]) === 'active');
        
        // Get version
        exec('/usr/local/lsws/bin/lshttpd -v 2>&1', $versionOutput);
        if (!empty($versionOutput)) {
            foreach ($versionOutput as $line) {
                if (preg_match('/LiteSpeed\/(\d+\.\d+(?:\.\d+)?)/i', $line, $matches)) {
                    $version = $matches[1];
                    break;
                }
            }
        }
        
        // Get PID and uptime
        if ($running) {
            $pidFile = '/tmp/lshttpd/lshttpd.pid';
            if (file_exists($pidFile)) {
                $pid = (int)trim(file_get_contents($pidFile));
                
                // Get process start time for uptime
                if ($pid > 0) {
                    exec("ps -o etimes= -p {$pid} 2>/dev/null", $uptimeOutput);
                    if (!empty($uptimeOutput)) {
                        $seconds = (int)trim($uptimeOutput[0]);
                        $uptime = $this->formatUptime($seconds);
                    }
                }
            }
        }
        
        // Get listener info
        $listeners = $this->getListeners();
        
        return Response::success([
            'running' => $running,
            'version' => $version,
            'uptime' => $uptime,
            'pid' => $pid,
            'listeners' => $listeners,
        ]);
    }

    /**
     * Get OpenLiteSpeed settings
     * Uses agent to read config (runs as root, can read lsadm-owned files)
     */
    public function settings(Request $request): Response
    {
        try {
            // Use agent to read config (runs as root, can read 0750 permissions)
            $result = $this->agent->execute('system.olsReadConfig', [], $this->getActor());
            
            if (!$result['success']) {
                return Response::error($result['error'] ?? 'Failed to read OpenLiteSpeed configuration');
            }
            
            $content = $result['data']['content'] ?? '';
            $settings = $this->parseConfig($content);
            
            return Response::success([
                'settings' => $settings,
                'config_path' => $this->configPath,
            ]);
        } catch (\Exception $e) {
            return Response::error('Failed to parse OpenLiteSpeed configuration: ' . $e->getMessage());
        }
    }

    /**
     * Update OpenLiteSpeed settings
     */
    public function updateSettings(Request $request): Response
    {
        $settings = $request->input('settings', []);
        
        if (empty($settings)) {
            return Response::error('No settings provided');
        }
        
        // Use agent to read config (runs as root, can read 0750 permissions)
        $readResult = $this->agent->execute('system.olsReadConfig', [], $this->getActor());
        if (!$readResult['success']) {
            return Response::error($readResult['error'] ?? 'Failed to read OpenLiteSpeed configuration');
        }

        $content = $readResult['data']['content'] ?? '';
        
        foreach ($settings as $key => $value) {
            $content = $this->updateConfigValue($content, $key, $value);
        }
        
        // Use agent to save config (runs as root, can write to lsadm-owned files)
        $result = $this->agent->execute('system.olsSaveConfig', [
            'content' => $content,
        ], $this->getActor());
        
        if (!$result['success']) {
            $this->logAction('ols.settings', 'httpd_config.conf', 'failed', ['error' => $result['error'] ?? 'Unknown error']);
            return Response::error($result['error'] ?? 'Failed to write configuration file');
        }
        
        $this->logAction('ols.settings', 'httpd_config.conf', 'success', ['settings' => array_keys($settings)]);
        
        return Response::success([
            'settings' => $this->parseConfig($content),
            'backup' => $result['data']['backup'] ?? null,
        ], 'Settings updated successfully. Restart OpenLiteSpeed to apply changes.');
    }

    /**
     * Restart OpenLiteSpeed
     */
    public function restart(Request $request): Response
    {
        $result = $this->agent->execute('system.olsRestart', [], $this->getActor());
        
        if (!$result['success']) {
            $this->logAction('ols.restart', 'lsws', 'failed', ['error' => $result['error'] ?? 'Unknown error']);
            return Response::error($result['error'] ?? 'Failed to restart OpenLiteSpeed');
        }
        
        $this->logAction('ols.restart', 'lsws', 'success');
        
        return Response::success([
            'message' => 'OpenLiteSpeed restarted successfully',
        ]);
    }

    /**
     * Reload OpenLiteSpeed (graceful)
     */
    public function reload(Request $request): Response
    {
        $result = $this->agent->execute('system.olsReload', [], $this->getActor());
        
        if (!$result['success']) {
            $this->logAction('ols.reload', 'lsws', 'failed', ['error' => $result['error'] ?? 'Unknown error']);
            return Response::error($result['error'] ?? 'Failed to reload OpenLiteSpeed');
        }
        
        $this->logAction('ols.reload', 'lsws', 'success');
        
        return Response::success([
            'message' => 'OpenLiteSpeed reloaded successfully',
        ]);
    }

    /**
     * Get virtual hosts list
     */
    public function vhosts(Request $request): Response
    {
        $vhostsPath = '/usr/local/lsws/conf/vhosts';
        $vhosts = [];
        
        if (is_dir($vhostsPath)) {
            $dirs = scandir($vhostsPath);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                
                $confPath = "{$vhostsPath}/{$dir}/vhconf.conf";
                if (file_exists($confPath)) {
                    $vhosts[] = [
                        'name' => $dir,
                        'config_path' => $confPath,
                    ];
                }
            }
        }
        
        return Response::success([
            'vhosts' => $vhosts,
            'total' => count($vhosts),
        ]);
    }

    /**
     * Get raw OpenLiteSpeed config file
     * Uses agent to read config (runs as root, can read lsadm-owned files)
     */
    public function rawConfig(Request $request): Response
    {
        // Use agent to read config (runs as root, can read 0750 permissions)
        $result = $this->agent->execute('system.olsReadConfig', [], $this->getActor());
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to read OpenLiteSpeed configuration');
        }
        
        return Response::success([
            'path' => $this->configPath,
            'content' => $result['data']['content'] ?? '',
        ]);
    }

    /**
     * Save raw OpenLiteSpeed config file
     */
    public function saveRawConfig(Request $request): Response
    {
        $content = $request->input('content');
        
        if (empty($content)) {
            return Response::error('No content provided');
        }
        
        // Use agent to save config (runs as root, can write to lsadm-owned files)
        $result = $this->agent->execute('system.olsSaveConfig', [
            'content' => $content,
        ], $this->getActor());
        
        if (!$result['success']) {
            $this->logAction('ols.rawConfig', 'httpd_config.conf', 'failed', ['error' => $result['error'] ?? 'Unknown error']);
            return Response::error($result['error'] ?? 'Failed to write configuration file');
        }
        
        $this->logAction('ols.rawConfig', 'httpd_config.conf', 'success');
        
        return Response::success([
            'message' => 'Configuration saved successfully',
            'backup' => $result['data']['backup'] ?? null,
        ], 'Configuration saved. Restart OpenLiteSpeed to apply changes.');
    }

    /**
     * Parse OpenLiteSpeed configuration
     */
    private function parseConfig(string $content): array
    {
        $settings = [];
        
        // Extract server-level settings
        $serverSettings = [
            'serverName' => '/serverName\s+(.+)/m',
            'user' => '/user\s+(.+)/m',
            'group' => '/group\s+(.+)/m',
            'priority' => '/priority\s+(\d+)/m',
            'autoRestart' => '/autoRestart\s+(\d+)/m',
            'chrootPath' => '/chrootPath\s+(.+)/m',
            'enableChroot' => '/enableChroot\s+(\d+)/m',
            'inMemBufSize' => '/inMemBufSize\s+(\d+[KMG]?)/m',
            'swappingDir' => '/swappingDir\s+(.+)/m',
            'autoFix503' => '/autoFix503\s+(\d+)/m',
            'gracefulRestartTimeout' => '/gracefulRestartTimeout\s+(\d+)/m',
            'mime' => '/mime\s+(.+)/m',
            'useIpInProxyHeader' => '/useIpInProxyHeader\s+(\d+)/m',
            'adminEmails' => '/adminEmails\s+(.+)/m',
        ];
        
        foreach ($serverSettings as $key => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $settings[$key] = trim($matches[1]);
            }
        }
        
        // Extract tuning settings (handle nested braces)
        if (preg_match('/tuning\s*\{((?:[^{}]|\{[^{}]*\})*)\}/s', $content, $tuningBlock)) {
            $tuningContent = $tuningBlock[1];
            $tuningSettings = [
                'maxConnections' => '/maxConnections\s+(\d+)/m',
                'maxSSLConnections' => '/maxSSLConnections\s+(\d+)/m',
                'connTimeout' => '/connTimeout\s+(\d+)/m',
                'maxKeepAliveReq' => '/maxKeepAliveReq\s+(\d+)/m',
                'keepAliveTimeout' => '/keepAliveTimeout\s+(\d+)/m',
                'sndBufSize' => '/sndBufSize\s+(\d+)/m',
                'rcvBufSize' => '/rcvBufSize\s+(\d+)/m',
                'maxReqURLLen' => '/maxReqURLLen\s+(\d+)/m',
                'maxReqHeaderSize' => '/maxReqHeaderSize\s+(\d+)/m',
                'maxReqBodySize' => '/maxReqBodySize\s+(\d+[KMG]?)/m',
                'maxDynRespHeaderSize' => '/maxDynRespHeaderSize\s+(\d+)/m',
                'maxDynRespSize' => '/maxDynRespSize\s+(\d+[KMG]?)/m',
                'maxCachedFileSize' => '/maxCachedFileSize\s+(\d+)/m',
                'totalInMemCacheSize' => '/totalInMemCacheSize\s+(\d+[KMG]?)/m',
                'maxMMapFileSize' => '/maxMMapFileSize\s+(\d+)/m',
                'totalMMapCacheSize' => '/totalMMapCacheSize\s+(\d+[KMG]?)/m',
                'useSendfile' => '/useSendfile\s+(\d+)/m',
                'fileETag' => '/fileETag\s+(\d+)/m',
                'SSLStrongDhKey' => '/SSLStrongDhKey\s+(\d+)/m',
                // Compression settings
                'enableGzipCompress' => '/enableGzipCompress\s+(\d+)/m',
                'gzipCompressLevel' => '/gzipCompressLevel\s+(\d+)/m',
                'enableBrCompress' => '/enableBrCompress\s+(\d+)/m',
                'brStaticCompressLevel' => '/brStaticCompressLevel\s+(\d+)/m',
                'gzipAutoUpdateStatic' => '/gzipAutoUpdateStatic\s+(\d+)/m',
                'gzipStaticCompressLevel' => '/gzipStaticCompressLevel\s+(\d+)/m',
                'compressibleTypes' => '/compressibleTypes\s+(.+)/m',
                // Cache settings
                'enableCache' => '/enableCache\s+(\d+)/m',
                'expiresByType' => '/expiresByType\s+(.+)/m',
            ];
            
            foreach ($tuningSettings as $key => $pattern) {
                if (preg_match($pattern, $tuningContent, $matches)) {
                    $settings['tuning_' . $key] = trim($matches[1]);
                }
            }
        }
        
        // Extract security settings
        if (preg_match('/security\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s', $content, $securityBlock)) {
            $securityContent = $securityBlock[1];
            
            if (preg_match('/fileAccessControl\s*\{([^}]+)\}/s', $securityContent, $facBlock)) {
                $facContent = $facBlock[1];
                if (preg_match('/followSymbolLink\s+(\d+)/m', $facContent, $matches)) {
                    $settings['security_followSymbolLink'] = trim($matches[1]);
                }
                if (preg_match('/checkSymbolLink\s+(\d+)/m', $facContent, $matches)) {
                    $settings['security_checkSymbolLink'] = trim($matches[1]);
                }
            }
            
            if (preg_match('/CGIRLimit\s*\{([^}]+)\}/s', $securityContent, $cgiBlock)) {
                $cgiContent = $cgiBlock[1];
                if (preg_match('/maxCGIInstances\s+(\d+)/m', $cgiContent, $matches)) {
                    $settings['security_maxCGIInstances'] = trim($matches[1]);
                }
            }
        }
        
        return $settings;
    }

    /**
     * Update a configuration value
     */
    private function updateConfigValue(string $content, string $key, string $value): string
    {
        // Handle tuning settings
        if (str_starts_with($key, 'tuning_')) {
            $settingKey = substr($key, 7);
            $pattern = '/(' . preg_quote($settingKey, '/') . '\s+)\S+/m';
            
            // Find tuning block and update within it
            if (preg_match('/tuning\s*\{([^}]+)\}/s', $content, $matches, \PREG_OFFSET_CAPTURE)) {
                $tuningBlock = $matches[0][0];
                $tuningOffset = $matches[0][1];
                
                if (preg_match($pattern, $tuningBlock)) {
                    $newTuningBlock = preg_replace($pattern, '${1}' . $value, $tuningBlock);
                    $content = substr_replace($content, $newTuningBlock, $tuningOffset, strlen($tuningBlock));
                }
            }
        }
        // Handle security settings
        elseif (str_starts_with($key, 'security_')) {
            $settingKey = substr($key, 9);
            $pattern = '/(' . preg_quote($settingKey, '/') . '\s+)\S+/m';
            $content = preg_replace($pattern, '${1}' . $value, $content);
        }
        // Handle server-level settings
        else {
            $pattern = '/(' . preg_quote($key, '/') . '\s+)\S+/m';
            $content = preg_replace($pattern, '${1}' . $value, $content);
        }
        
        return $content;
    }

    /**
     * Get listeners from config
     * Uses agent to read config (runs as root, can read lsadm-owned files)
     */
    private function getListeners(): array
    {
        $listeners = [];
        
        // Use agent to read config (runs as root, can read 0750 permissions)
        $result = $this->agent->execute('system.olsReadConfig', [], 'system');
        if (!$result['success']) {
            return $listeners;
        }
        
        $content = $result['data']['content'] ?? '';
        
        // Find all listener blocks
        if (preg_match_all('/listener\s+(\S+)\s*\{([^}]+)\}/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $block = $match[2];
                
                $listener = ['name' => $name];
                
                if (preg_match('/address\s+([^\s]+)/m', $block, $addrMatch)) {
                    $listener['address'] = trim($addrMatch[1]);
                }
                if (preg_match('/secure\s+(\d+)/m', $block, $secMatch)) {
                    $listener['secure'] = (bool)$secMatch[1];
                }
                
                $listeners[] = $listener;
            }
        }
        
        return $listeners;
    }

    /**
     * Format uptime from seconds
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        
        return implode(' ', $parts) ?: '< 1m';
    }

    /**
     * Get extprocessor calculator data
     * Returns system specs, vhost count, current settings, and recommended configuration values
     */
    public function calculator(Request $request): Response
    {
        // Check for custom/simulation parameters
        $customCores = $request->getQuery('custom_cores');
        $customRamGB = $request->getQuery('custom_ram_gb');
        $customVhosts = $request->getQuery('custom_vhosts');
        $isSimulation = $customCores || $customRamGB || $customVhosts;

        // Get actual system values
        $actualCpuCores = 1;
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $actualCpuCores = substr_count($cpuinfo, 'processor');
        }

        $actualTotalRamMB = 0;
        $availableRamMB = 0;
        if (file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
                $actualTotalRamMB = (int)($m[1] / 1024);
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) {
                $availableRamMB = (int)($m[1] / 1024);
            }
        }

        $actualVhostCount = 0;
        $vhostResult = $this->agent->execute('vhost.list', [], 'system');
        if ($vhostResult['success'] && isset($vhostResult['data']['vhosts'])) {
            $actualVhostCount = count($vhostResult['data']['vhosts']);
        }

        // Use custom values if provided, otherwise use actual
        $cpuCores = $customCores ? (int)$customCores : $actualCpuCores;
        $totalRamMB = $customRamGB ? (int)((float)$customRamGB * 1024) : $actualTotalRamMB;
        $vhostCount = $customVhosts !== null ? (int)$customVhosts : $actualVhostCount;

        // Get current extprocessor settings from config
        $currentSettings = $this->parseCurrentExtprocessor();

        // Calculate recommended values based on system resources
        // Goal: balance throughput with resource usage based on actual needs
        
        // Reserve memory for system/MySQL/buffers (2GB minimum, or 25% of RAM)
        $systemReserve = max(2048, (int)($totalRamMB * 0.25));
        $ramForPhp = $totalRamMB - $systemReserve;
        
        // Each PHP process typically uses 100-200MB depending on the app
        $memPerProcess = 150;
        $maxByMemory = (int)floor($ramForPhp / $memPerProcess);
        
        // CPU-based limit: PHP is I/O bound, so 4x cores is reasonable max
        $maxByCpu = $cpuCores * 4;
        
        // Site-based calculation depends on how many sites share resources
        if ($vhostCount <= 3) {
            // Few sites (1-3): Let them use full CPU capacity
            // A single busy site should be able to handle traffic spikes
            $siteBasedWorkers = $maxByCpu;
        } else {
            // Many sites (4+): Scale based on site count (3 workers per site)
            // but still capped by CPU
            $siteBasedWorkers = max(10, $vhostCount * 3);
        }
        
        // Take the MINIMUM of all limits (safety first)
        $recommendedMaxConns = min($maxByMemory, $maxByCpu, $siteBasedWorkers);
        
        // Ensure absolute minimum of 5 workers
        $recommendedMaxConns = max(5, $recommendedMaxConns);
        
        // Hard cap based on RAM to prevent OOM under load (RAM / 200MB)
        $absoluteMax = (int)floor($totalRamMB / 200);
        $recommendedMaxConns = min($recommendedMaxConns, $absoluteMax);
        
        // PHP_LSAPI_CHILDREN should match maxConns
        $recommendedChildren = $recommendedMaxConns;
        
        // LSAPI_AVOID_FORK threshold - prevents excessive forking
        $avoidFork = $totalRamMB >= 8192 ? '300M' : ($totalRamMB >= 4096 ? '200M' : '150M');
        
        // Memory limits per process - allow headroom for complex pages
        // These are per-process limits, not total - OLS will kill processes exceeding these
        if ($totalRamMB >= 8192) {
            $memSoftLimit = '1024M';
            $memHardLimit = '1536M';
        } elseif ($totalRamMB >= 4096) {
            $memSoftLimit = '768M';
            $memHardLimit = '1024M';
        } else {
            $memSoftLimit = '512M';
            $memHardLimit = '768M';
        }
        
        // Process limits - scale with worker count
        $procSoftLimit = max(50, $recommendedMaxConns * 3);
        $procHardLimit = $procSoftLimit + 30;
        
        // Backlog - queue size for waiting connections (scale with workers)
        $backlog = max(25, $recommendedMaxConns * 2);
        
        // initTimeout - longer for servers with many sites (cold starts take longer)
        $initTimeout = $vhostCount > 20 ? 90 : 60;

        return Response::success([
            'is_simulation' => $isSimulation,
            'system' => [
                'cpu_cores' => $cpuCores,
                'total_ram_mb' => $totalRamMB,
                'available_ram_mb' => $isSimulation ? 0 : $availableRamMB,
                'total_ram_human' => $this->formatMemory($totalRamMB),
                'available_ram_human' => $isSimulation ? 'N/A (simulated)' : $this->formatMemory($availableRamMB),
                'actual_cpu_cores' => $actualCpuCores,
                'actual_ram_mb' => $actualTotalRamMB,
            ],
            'vhosts' => [
                'count' => $vhostCount,
                'actual_count' => $actualVhostCount,
            ],
            'current' => $currentSettings,
            'recommended' => [
                'maxConns' => $recommendedMaxConns,
                'PHP_LSAPI_CHILDREN' => $recommendedChildren,
                'LSAPI_AVOID_FORK' => $avoidFork,
                'initTimeout' => $initTimeout,
                'retryTimeout' => 0,
                'persistConn' => 1,
                'respBuffer' => 0,
                'autoStart' => 2,
                'backlog' => (int)$backlog,
                'instances' => 1,
                'priority' => 0,
                'memSoftLimit' => $memSoftLimit,
                'memHardLimit' => $memHardLimit,
                'procSoftLimit' => $procSoftLimit,
                'procHardLimit' => $procHardLimit,
            ],
            'config_template' => $this->generateExtprocessorConfig(
                $recommendedMaxConns,
                $recommendedChildren,
                $avoidFork,
                $initTimeout,
                (int)$backlog,
                $memSoftLimit,
                $memHardLimit,
                $procSoftLimit,
                $procHardLimit
            ),
        ]);
    }

    /**
     * Parse current extprocessor lsphp settings from config
     */
    private function parseCurrentExtprocessor(): array
    {
        $settings = [
            'maxConns' => null,
            'PHP_LSAPI_CHILDREN' => null,
            'LSAPI_AVOID_FORK' => null,
            'initTimeout' => null,
            'retryTimeout' => null,
            'persistConn' => null,
            'respBuffer' => null,
            'autoStart' => null,
            'backlog' => null,
            'instances' => null,
            'priority' => null,
            'memSoftLimit' => null,
            'memHardLimit' => null,
            'procSoftLimit' => null,
            'procHardLimit' => null,
        ];

        // Use agent to read config (runs as root, can read lsadm-owned files)
        $result = $this->agent->execute('system.olsReadConfig', [], 'system');
        if (!$result['success']) {
            return $settings;
        }

        $content = $result['data']['content'] ?? '';
        
        // Find extprocessor lsphp block
        if (preg_match('/extprocessor\s+lsphp\s*\{([^}]+)\}/s', $content, $match)) {
            $block = $match[1];
            
            // Parse simple key-value pairs
            $patterns = [
                'maxConns' => '/maxConns\s+(\d+)/i',
                'initTimeout' => '/initTimeout\s+(\d+)/i',
                'retryTimeout' => '/retryTimeout\s+(\d+)/i',
                'persistConn' => '/persistConn\s+(\d+)/i',
                'respBuffer' => '/respBuffer\s+(\d+)/i',
                'autoStart' => '/autoStart\s+(\d+)/i',
                'backlog' => '/backlog\s+(\d+)/i',
                'instances' => '/instances\s+(\d+)/i',
                'priority' => '/priority\s+(\d+)/i',
                'memSoftLimit' => '/memSoftLimit\s+(\S+)/i',
                'memHardLimit' => '/memHardLimit\s+(\S+)/i',
                'procSoftLimit' => '/procSoftLimit\s+(\d+)/i',
                'procHardLimit' => '/procHardLimit\s+(\d+)/i',
            ];
            
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $block, $m)) {
                    $settings[$key] = is_numeric($m[1]) ? (int)$m[1] : $m[1];
                }
            }
            
            // Parse env variables
            if (preg_match('/env\s+PHP_LSAPI_CHILDREN=(\d+)/i', $block, $m)) {
                $settings['PHP_LSAPI_CHILDREN'] = (int)$m[1];
            }
            if (preg_match('/env\s+LSAPI_AVOID_FORK=(\S+)/i', $block, $m)) {
                $settings['LSAPI_AVOID_FORK'] = $m[1];
            }
        }
        
        return $settings;
    }

    /**
     * Format memory in MB to human readable
     */
    private function formatMemory(int $mb): string
    {
        if ($mb >= 1024) {
            return round($mb / 1024, 1) . ' GB';
        }
        return $mb . ' MB';
    }

    /**
     * Generate extprocessor configuration block
     */
    private function generateExtprocessorConfig(
        int $maxConns,
        int $children,
        string $avoidFork,
        int $initTimeout,
        int $backlog,
        string $memSoftLimit,
        string $memHardLimit,
        int $procSoftLimit,
        int $procHardLimit
    ): string {
        return "extprocessor lsphp {
  type                    lsapi
  address                 uds://tmp/lshttpd/lsphp.sock
  maxConns                {$maxConns}
  env                     PHP_LSAPI_CHILDREN={$children}
  env                     LSAPI_AVOID_FORK={$avoidFork}
  initTimeout             {$initTimeout}
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               2
  path                    lsphp83/bin/lsphp
  backlog                 {$backlog}
  instances               1
  priority                0
  memSoftLimit            {$memSoftLimit}
  memHardLimit            {$memHardLimit}
  procSoftLimit           {$procSoftLimit}
  procHardLimit           {$procHardLimit}
}";
    }
}

