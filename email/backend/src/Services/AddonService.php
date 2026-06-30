<?php

namespace Webmail\Services;

/**
 * AddonService - Checks addon status from the VPS Admin Panel
 * 
 * Fetches addon toggle states via Panel API and caches in Redis.
 * Used to gate CRM Pro and Client Portal features.
 */
class AddonService
{
    private array $config;
    private ?\Redis $redis = null;
    private ?string $userEmail = null;
    private const CACHE_KEY = 'addon_status';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(array $config, ?string $userEmail = null)
    {
        $this->config = $config;
        $this->userEmail = $userEmail;
        $this->initRedis();
    }

    /**
     * Check if a specific addon is enabled (respects per-user overrides)
     */
    public function isEnabled(string $slug): bool
    {
        $statuses = $this->getAll();
        return $statuses[$slug] ?? false;
    }

    /**
     * Convenience method: is CRM Pro enabled?
     */
    public function isCrmProEnabled(): bool
    {
        return $this->isEnabled('crm_pro');
    }

    /**
     * Convenience method: is Mood Boards enabled?
     */
    public function isMoodboardsEnabled(): bool
    {
        return $this->isEnabled('moodboards');
    }

    public function isKanbanBoardsEnabled(): bool
    {
        return $this->isEnabled('kanban_boards');
    }

    /**
     * Convenience method: is Chat & Calls enabled?
     */
    public function isChatEnabled(): bool
    {
        return $this->isEnabled('chat');
    }

    /**
     * Convenience method: is Email Marketing enabled?
     */
    public function isEmailMarketingEnabled(): bool
    {
        return $this->isEnabled('email_marketing');
    }

    /**
     * Convenience method: is Team Management enabled?
     */
    public function isTeamEnabled(): bool
    {
        return $this->isEnabled('team');
    }

    /**
     * Convenience method: is Calendar enabled?
     */
    public function isCalendarEnabled(): bool
    {
        return $this->isEnabled('calendar');
    }

    /**
     * Convenience method: is Tasks enabled?
     */
    public function isTasksEnabled(): bool
    {
        return $this->isEnabled('tasks');
    }

    /**
     * Convenience method: is Email Tracking enabled?
     */
    public function isEmailTrackingEnabled(): bool
    {
        return $this->isEnabled('email_tracking');
    }

    /**
     * Convenience method: is Time Tracker enabled?
     */
    public function isTimeTrackerEnabled(): bool
    {
        return $this->isEnabled('time_tracker');
    }

    /**
     * Convenience method: is Reactions enabled?
     */
    public function isReactionsEnabled(): bool
    {
        return $this->isEnabled('reactions');
    }

    /**
     * Convenience method: is AI Assistant enabled?
     */
    public function isAIAssistantEnabled(): bool
    {
        return $this->isEnabled('ai_assistant');
    }

    /**
     * Convenience method: is Board Pro enabled?
     */
    public function isBoardProEnabled(): bool
    {
        return $this->isEnabled('board_pro');
    }

    public function isProjectHubEnabled(): bool
    {
        return $this->isEnabled('project_hub');
    }

    /**
     * Convenience method: is Automation Hub enabled?
     */
    public function isAutomationHubEnabled(): bool
    {
        return $this->isEnabled('automation_hub');
    }

    /**
     * Convenience method: is Universal Search enabled?
     */
    public function isUniversalSearchEnabled(): bool
    {
        return $this->isEnabled('universal_search');
    }

    public function isNewsReaderEnabled(): bool
    {
        return $this->isEnabled('news_reader');
    }

    /**
     * Get all addon statuses as associative array.
     * If $userEmail was provided to the constructor, returns per-user resolved
     * statuses (user override > group override > global).
     * Returns: ['crm_pro' => true/false, ...]
     */
    public function getAll(): array
    {
        // Try Redis cache first (per-user or global key)
        $cached = $this->getFromCache();
        if ($cached !== null) {
            return $cached;
        }

        // Fetch from Panel API (passes email if set)
        $statuses = $this->fetchFromPanel();

        // Cache the result
        if ($statuses !== null) {
            $this->setCache($statuses);
            return $statuses;
        }

        // Panel unreachable - try stale cache
        $stale = $this->getFromCache(true);
        if ($stale !== null) {
            return $stale;
        }

        // No cache, no Panel - safe default: all disabled
        return [];
    }

    /**
     * Push per-user addon overrides to the Panel via the onboarding-assign endpoint.
     * Returns true on success, false on failure.
     */
    public function setUserAddons(string $email, array $addons): bool
    {
        $panelUrl = $this->config['panel']['api_url'] ?? '';
        $apiKey = $this->config['panel']['api_key'] ?? '';

        if (empty($panelUrl) || empty($apiKey)) {
            error_log("AddonService::setUserAddons: Panel API not configured");
            return false;
        }

        $url = rtrim($panelUrl, '/') . '/addons/onboarding-assign';
        $payload = json_encode(['email' => $email, 'addons' => $addons]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("AddonService::setUserAddons: Panel API failed (HTTP {$httpCode}): {$error}");
            return false;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['success']) || !$data['success']) {
            error_log("AddonService::setUserAddons: Invalid response: " . substr($response, 0, 200));
            return false;
        }

        $this->clearCache();
        return true;
    }

    /**
     * Force refresh addon status (clears cache and re-fetches)
     */
    public function refreshStatus(): array
    {
        $this->clearCache();
        $statuses = $this->fetchFromPanel();
        if ($statuses !== null) {
            $this->setCache($statuses);
            return $statuses;
        }
        // Panel unreachable after cache clear - return empty (fail-closed)
        return [];
    }

    /**
     * Fetch addon status from Panel API.
     * Passes ?email= if $userEmail is set so the Panel resolves per-user overrides.
     */
    private function fetchFromPanel(): ?array
    {
        $panelUrl = $this->config['panel']['api_url'] ?? '';
        $apiKey = $this->config['panel']['api_key'] ?? '';

        if (empty($panelUrl) || empty($apiKey)) {
            // Local dev fallback: Panel API not configured, so read per-user
            // addon selections from the onboarding settings file. Lets local
            // development work without a running Panel instance.
            return $this->fetchFromUserSettingsFile();
        }

        $url = rtrim($panelUrl, '/') . '/addons/status';

        // Append user email for per-user resolution
        if (!empty($this->userEmail)) {
            $url .= '?email=' . urlencode($this->userEmail);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
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

        if ($error || $httpCode !== 200) {
            error_log("AddonService: Panel API failed (HTTP {$httpCode}): {$error}");
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['success']) || !$data['success']) {
            error_log("AddonService: Invalid Panel API response");
            return null;
        }

        return $data['data'] ?? [];
    }

    /**
     * Local-dev fallback: read addon selections from the user's onboarding
     * settings file when the Panel API isn't configured. Returns null if no
     * settings file exists yet (will fall through to "all disabled" default).
     */
    private function fetchFromUserSettingsFile(): ?array
    {
        if (empty($this->userEmail)) {
            return null;
        }

        $settingsDir = '/var/www/vps-email/data/settings';
        $hash = md5(strtolower($this->userEmail));
        $file = $settingsDir . '/' . $hash . '.json';

        if (!file_exists($file)) {
            return null;
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $settings = json_decode($contents, true);
        $addons = $settings['onboarding_profile']['addons'] ?? null;

        return is_array($addons) ? $addons : null;
    }

    /**
     * Get the Redis cache key (per-user if email is set, otherwise global)
     */
    private function getCacheKey(): string
    {
        $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
        $base = $prefix . self::CACHE_KEY;
        if (!empty($this->userEmail)) {
            return $base . ':' . md5($this->userEmail);
        }
        return $base;
    }

    /**
     * Get from Redis cache
     */
    private function getFromCache(bool $allowExpired = false): ?array
    {
        if (!$this->redis) return null;

        try {
            $key = $this->getCacheKey();

            if ($allowExpired) {
                // For stale fallback, we store a separate non-expiring backup
                $backup = $this->redis->get($key . ':backup');
                if ($backup !== false) {
                    return json_decode($backup, true);
                }
                return null;
            }

            $cached = $this->redis->get($key);
            if ($cached !== false) {
                return json_decode($cached, true);
            }
        } catch (\Throwable $e) {
            error_log("AddonService: Redis read error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Store in Redis cache
     */
    private function setCache(array $statuses): void
    {
        if (!$this->redis) return;

        try {
            $key = $this->getCacheKey();
            $json = json_encode($statuses);

            $this->redis->setex($key, self::CACHE_TTL, $json);
            // Also store a non-expiring backup for stale fallback
            $this->redis->set($key . ':backup', $json);
        } catch (\Throwable $e) {
            error_log("AddonService: Redis write error: " . $e->getMessage());
        }
    }

    /**
     * Clear cache (both primary TTL key and non-expiring backup)
     */
    private function clearCache(): void
    {
        if (!$this->redis) return;

        try {
            $key = $this->getCacheKey();
            $this->redis->del($key);
            $this->redis->del($key . ':backup');
        } catch (\Throwable $e) {
            error_log("AddonService: Redis clear error: " . $e->getMessage());
        }
    }

    /**
     * Initialize Redis connection
     */
    private function initRedis(): void
    {
        if (!extension_loaded('redis')) return;

        try {
            $this->redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $timeout = $this->config['redis']['timeout'] ?? 2.0;

            $this->redis->connect($host, $port, $timeout);

            $password = $this->config['redis']['password'] ?? null;
            if ($password) {
                $this->redis->auth($password);
            }

            $database = $this->config['redis']['database'] ?? 0;
            if ($database > 0) {
                $this->redis->select($database);
            }
        } catch (\Throwable $e) {
            error_log("AddonService: Redis connection failed: " . $e->getMessage());
            $this->redis = null;
        }
    }
}

