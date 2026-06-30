<?php

namespace Webmail\Addons\AutomationHub\Services;

class ServerMonitorBridge
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get system stats from VPS ADMIN (this server).
     */
    public function getSystemStats(): array
    {
        $url = $this->getVpsAdminUrl('/api/dashboard/stats');
        if (!$url) return ['error' => 'VPS ADMIN not configured'];

        return $this->apiCall($url, $this->getVpsAdminApiKey());
    }

    /**
     * Get service statuses from VPS ADMIN.
     */
    public function getServiceStatuses(): array
    {
        $url = $this->getVpsAdminUrl('/api/services/status');
        if (!$url) return ['error' => 'VPS ADMIN not configured'];

        return $this->apiCall($url, $this->getVpsAdminApiKey());
    }

    /**
     * Get fleet server health from Fleet Manager.
     */
    public function getFleetHealth(int $serverId): array
    {
        $url = $this->getFleetManagerUrl("/api/servers/{$serverId}/health");
        if (!$url) return ['error' => 'Fleet Manager not configured'];

        return $this->apiCall($url, $this->getFleetManagerApiKey());
    }

    /**
     * Get fleet server issues from Fleet Manager.
     */
    public function getFleetIssues(int $serverId): array
    {
        $url = $this->getFleetManagerUrl("/api/servers/{$serverId}/issues");
        if (!$url) return ['error' => 'Fleet Manager not configured'];

        return $this->apiCall($url, $this->getFleetManagerApiKey());
    }

    /**
     * Check a specific metric against a threshold.
     * Returns: ['triggered' => bool, 'metric' => string, 'value' => mixed, 'threshold' => mixed]
     */
    public function checkMetric(string $metric, string $condition, float $threshold, ?string $service = null): array
    {
        $stats = $this->getSystemStats();
        $data = $stats['data'] ?? $stats;

        $cpu = $data['cpu'] ?? [];
        $memory = $data['memory'] ?? [];
        $disk = $data['disk'] ?? [];
        $rootDisk = is_array($disk) && isset($disk[0]) ? $disk[0] : (is_array($disk) ? $disk : []);

        $value = match ($metric) {
            'cpu_load' => (float)($cpu['usage_percent'] ?? $cpu['load_1'] ?? 0),
            'memory_usage' => (float)($memory['percent'] ?? $memory['used_percent'] ?? 0),
            'disk_usage' => (float)($rootDisk['percent'] ?? $rootDisk['used_percent'] ?? 0),
            default => 0,
        };

        if ($metric === 'service_status') {
            $services = $this->getServiceStatuses();
            $serviceData = $services['data'] ?? $services;
            $serviceStatus = $serviceData[$service] ?? 'unknown';

            $triggered = match ($condition) {
                'stopped' => $serviceStatus !== 'running',
                'running' => $serviceStatus === 'running',
                default => false,
            };

            return [
                'triggered' => $triggered,
                'metric' => 'service_status',
                'service' => $service,
                'value' => $serviceStatus,
                'condition' => $condition,
            ];
        }

        $triggered = match ($condition) {
            'above' => $value > $threshold,
            'below' => $value < $threshold,
            default => false,
        };

        return [
            'triggered' => $triggered,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'condition' => $condition,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function getVpsAdminUrl(string $path): ?string
    {
        $base = $this->config['automation_hub']['vps_admin_url']
            ?? $this->config['panel']['api_url']
            ?? '';
        return $base ? rtrim($base, '/') . $path : null;
    }

    private function getVpsAdminApiKey(): string
    {
        return $this->config['automation_hub']['vps_admin_api_key']
            ?? $this->config['panel']['api_key']
            ?? '';
    }

    private function getFleetManagerUrl(string $path): ?string
    {
        $base = $this->config['automation_hub']['fleet_manager_url'] ?? '';
        return $base ? rtrim($base, '/') . $path : null;
    }

    private function getFleetManagerApiKey(): string
    {
        return $this->config['automation_hub']['fleet_manager_api_key'] ?? '';
    }

    private function apiCall(string $url, string $apiKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("ServerMonitorBridge API error: {$error}");
            return ['error' => $error];
        }

        if ($httpCode !== 200) {
            return ['error' => "HTTP {$httpCode}", 'status_code' => $httpCode];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid JSON response'];
    }
}
