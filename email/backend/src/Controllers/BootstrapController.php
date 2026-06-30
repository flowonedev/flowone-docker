<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\AccountService;
use Webmail\Services\LabelService;
use Webmail\Services\FilterService;
use Webmail\Services\AddonService;
use Webmail\Services\DualWriteTelemetry;
use Webmail\Services\RedisCacheService;
use Webmail\Addons\Team\Services\ColleagueService;
use Webmail\Addons\Tasks\Services\TodoService;
use Webmail\Addons\EmailTracking\Services\TrackingService;

class BootstrapController extends BaseController
{
    private const BOOTSTRAP_VERSION = 1;
    private const CACHE_TTL = 60;
    private string $settingsDir = '/var/www/vps-email/data/settings';

    /**
     * Combined bootstrap endpoint -- replaces 12+ individual API calls with one.
     * Only uses DB + file reads (no IMAP), so it's fast.
     *
     * GET /bootstrap
     */
    public function bootstrap(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $email = $this->userEmail;
        $activeEmail = $this->getActiveEmail();

        // Try Redis cache first
        $redis = $this->getRedis();
        $cacheKey = $this->getCacheKey($email);
        if ($redis) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached !== false) {
                    $data = json_decode($cached, true);
                    if (is_array($data)) {
                        // folder_identity_version must NEVER be cached: a
                        // stale value here would let the client think its
                        // folder cache is in sync when it isn't, defeating
                        // the whole rename-invalidation contract. Always
                        // refresh it before returning the cached payload.
                        $data['mail']['folder_identity_version'] = $this->readFolderIdentityVersion($activeEmail);
                        // force_password_change must also stay live, otherwise a
                        // cached payload would either hide the forced-change gate
                        // after it's set, or keep showing it after the user just
                        // changed their password.
                        $data['user']['force_password_change'] = $this->getForcePasswordChange($email);
                        return Response::success($data);
                    }
                }
            } catch (\Exception $e) {
                // Cache miss / failure -- continue to build fresh
            }
        }

        // -- User --
        $displayName = $this->getDisplayName($email);
        $avatarUrl = null;
        $colleagueService = null;
        try {
            $colleagueService = new ColleagueService($this->config);
            $colleague = $colleagueService->ensureColleagueExists($email);
            if ($colleague && !empty($colleague['avatar_path'])) {
                $avatarUrl = '/api/colleagues/avatar/' . basename($colleague['avatar_path']);
            }
        } catch (\Exception $e) {
            // Non-critical
        }

        // -- Settings (file read) --
        $settings = $this->loadSettings($activeEmail);
        $trustedSenders = $settings['trusted_senders'] ?? [];

        // -- Addons (Redis-cached) --
        $addons = [];
        try {
            $addonService = new AddonService($this->config, $email);
            $addons = $addonService->getAll();
        } catch (\Exception $e) {
            error_log('[Bootstrap] Addons failed: ' . $e->getMessage());
        }

        // -- Accounts --
        $accountsData = $this->getAccountsData($email);

        // -- Mail: labels + filters --
        $labels = [];
        $filters = [];
        try {
            $labelService = new LabelService($this->config);
            $filterService = new FilterService($this->config);
            $labels = $labelService->getLabels($activeEmail);
            $filters = $filterService->getFilters($activeEmail);
        } catch (\Exception $e) {
            error_log('[Bootstrap] Labels/Filters failed: ' . $e->getMessage());
        }

        // -- Team (conditional on addon) --
        $teamData = null;
        if (!empty($addons['team']) && $colleagueService) {
            try {
                $colleagues = $colleagueService->getColleagues($email);
                $groups = $colleagueService->getGroups($email);
                $isAdmin = $colleagueService->isAdmin($email);
                $meColleagueId = null;
                if ($colleague && !empty($colleague['id'])) {
                    $meColleagueId = (int) $colleague['id'];
                }
                $groupPerms = $colleagueService->getEffectiveGroupPermissions($email);
                $teamData = [
                    'colleagues' => $colleagues,
                    'groups' => $groups,
                    'is_admin' => $isAdmin,
                    'me_colleague_id' => $meColleagueId,
                    'my_permissions' => $groupPerms,
                ];
            } catch (\Exception $e) {
                error_log('[Bootstrap] Team data failed: ' . $e->getMessage());
            }
        }

        // -- Notifications --
        $notificationsData = null;
        try {
            $trackingService = new TrackingService($this->config);
            $trackingService->consolidateDuplicateNotifications($activeEmail);
            $notifications = $trackingService->getNotifications($activeEmail, false, 50);
            $unreadCount = $trackingService->getUnreadCount($activeEmail);
            $notificationsData = [
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ];
        } catch (\Exception $e) {
            error_log('[Bootstrap] Notifications failed: ' . $e->getMessage());
        }

        // -- Todos (conditional on addon) --
        // Always include completed todos so the redesigned panel's "today's
        // progress" ring has truthful data after a hard refresh. Completed
        // ones are filtered for display by the UI (`showCompleted` toggle),
        // but the store needs the full set to compute the percentage.
        $todosData = null;
        if (!empty($addons['tasks'])) {
            try {
                $todoService = new TodoService($this->config);
                $todos = $todoService->getTodos($activeEmail, true);
                $todosData = [
                    'todos' => $todos,
                ];
            } catch (\Exception $e) {
                error_log('[Bootstrap] Todos failed: ' . $e->getMessage());
            }
        }

        // -- Push config --
        $vapidKey = $this->config['push']['vapid_public_key'] ?? null;

        // -- Folder identity version (Wave 2 P2) --
        // Per-account monotonic counter that bumps on every folder rename /
        // move / delete. The frontend caches this baseline and compares
        // against the value attached to WebSocket `folder.changed` events
        // (and on reconnect via the dedicated identity-version endpoint).
        // Any drift means we missed at least one event and must invalidate
        // folder caches before serving stale data.
        $folderIdentityVersion = $this->readFolderIdentityVersion($activeEmail);

        $payload = [
            'meta' => [
                'bootstrap_version' => self::BOOTSTRAP_VERSION,
            ],

            'user' => [
                'email' => $email,
                'display_name' => $displayName,
                'avatar_url' => $avatarUrl,
                'force_password_change' => $this->getForcePasswordChange($email),
            ],

            'settings' => $settings,

            'accounts' => $accountsData,

            'mail' => [
                'labels' => $labels,
                'label_colors' => LabelService::COLORS,
                'filters' => $filters,
                'trusted_senders' => $trustedSenders,
                'folder_identity_version' => $folderIdentityVersion,
            ],

            'team' => $teamData,

            'notifications' => $notificationsData,

            'todos' => $todosData,

            'addons' => $addons,

            'push' => [
                'vapid_key' => $vapidKey,
            ],
        ];

        // Store in Redis cache
        if ($redis) {
            try {
                $redis->setex($cacheKey, self::CACHE_TTL, json_encode($payload));
            } catch (\Exception $e) {
                error_log('[Bootstrap] Redis cache write failed: ' . $e->getMessage());
            }
        }

        return Response::success($payload);
    }

    /**
     * Invalidate bootstrap cache for a user (call from settings/account/label/filter save).
     */
    public static function invalidateCache(array $config, string $email): void
    {
        try {
            $redisConfig = $config['redis'] ?? [];
            $redis = new \Redis();
            $host = $redisConfig['host'] ?? '127.0.0.1';
            $port = $redisConfig['port'] ?? 6379;
            $timeout = $redisConfig['timeout'] ?? 2.0;
            if (!$redis->connect($host, $port, $timeout)) return;
            $password = $redisConfig['password'] ?? null;
            if ($password) $redis->auth($password);
            $database = $redisConfig['database'] ?? 0;
            if ($database > 0) $redis->select($database);

            $prefix = $redisConfig['prefix'] ?? 'webmail:';
            $hash = md5(strtolower($email));
            $redis->del($prefix . 'bootstrap:' . $hash);
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    private function getRedis(): ?\Redis
    {
        try {
            $redisConfig = $this->config['redis'] ?? [];
            $redis = new \Redis();
            $host = $redisConfig['host'] ?? '127.0.0.1';
            $port = $redisConfig['port'] ?? 6379;
            $timeout = $redisConfig['timeout'] ?? 2.0;
            if (!$redis->connect($host, $port, $timeout)) return null;
            $password = $redisConfig['password'] ?? null;
            if ($password && !$redis->auth($password)) return null;
            $database = $redisConfig['database'] ?? 0;
            if ($database > 0) $redis->select($database);
            return $redis;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getCacheKey(string $email): string
    {
        $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
        $hash = md5(strtolower($email));
        return $prefix . 'bootstrap:' . $hash;
    }

    /**
     * Read the per-account folder_identity_version from Redis.
     * Always read fresh -- this value gates the whole frontend
     * folder-cache invalidation contract, so a stale value here would let
     * a client think its caches are in sync when they aren't. Returns 0
     * when Redis is unavailable; the frontend treats 0 as "unknown" and
     * either falls back to event-driven invalidation or to a full refetch.
     */
    private function readFolderIdentityVersion(string $accountId): int
    {
        try {
            $cacheSvc = new RedisCacheService($this->config);
            $telem = new DualWriteTelemetry($cacheSvc);
            return $telem->getFolderIdentityVersion($accountId);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get display name from settings file (same logic as AuthController::getDisplayName)
     */
    private function getDisplayName(string $email): string
    {
        $hash = md5(strtolower($email));
        $file = $this->settingsDir . '/' . $hash . '.json';

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            if (is_array($data) && !empty($data['display_name'])) {
                return $data['display_name'];
            }
        }

        return explode('@', $email)[0];
    }

    /**
     * Whether the shared mail_accounts row has the "force password change on
     * next login" flag set. Defensive: any error (missing column / table /
     * connection) is treated as "not forced". Computed live (never cached) so
     * the FlowOne forced-change gate flips on/off immediately.
     */
    private function getForcePasswordChange(string $email): bool
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("SELECT force_password_change FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([strtolower($email)]);
            $val = $stmt->fetchColumn();
            return $val !== false && (int) $val === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Load settings from JSON file (same logic as SettingsController::loadSettings)
     */
    private function loadSettings(string $email): array
    {
        $hash = md5(strtolower($email));
        $file = $this->settingsDir . '/' . $hash . '.json';

        $defaults = [
            'display_name' => '',
            'signature' => '',
            'messages_per_page' => 50,
            'theme' => 'system',
            'accent_color' => 'green',
            'layout_mode' => 'columns',
            'display_density' => 'cosy',
            'perspective' => 'operations',
            'setup_completed' => false,
            'auto_mark_read' => true,
            'confirm_delete' => true,
            'folder_order' => [],
            'refresh_interval' => 60,
            'large_attachment_threshold' => 10,
            'block_remote_images' => true,
            'override_email_styling' => true,
            'ooo_enabled' => false,
            'ooo_subject' => '',
            'ooo_message' => '',
            'ooo_start_date' => '',
            'ooo_end_date' => '',
        ];

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }

        return $defaults;
    }

    /**
     * Build accounts data (mirrors AccountController::list logic but reuses existing DB connection)
     */
    private function getAccountsData(string $email): array
    {
        try {
            $accountService = new AccountService($this->config);
            $accounts = $accountService->getAccounts($email);
            $presets = AccountService::getProviderPresets();

            $oauthAccounts = [];
            if ($this->googleOAuthService) {
                $googleAccounts = $this->googleOAuthService->getOAuthAccounts($email);
                $googleAccounts = array_filter($googleAccounts, function ($acc) use ($email) {
                    return strtolower($acc['account_email'] ?? '') !== strtolower($email);
                });
                $googleAccounts = array_map(function ($acc) {
                    $acc['is_oauth'] = true;
                    $acc['provider'] = 'google';
                    return $acc;
                }, $googleAccounts);
                $oauthAccounts = array_merge($oauthAccounts, $googleAccounts);
            }

            if ($this->microsoftOAuthService) {
                $microsoftAccounts = $this->microsoftOAuthService->getOAuthAccounts($email);
                $microsoftAccounts = array_filter($microsoftAccounts, function ($acc) use ($email) {
                    return strtolower($acc['account_email'] ?? '') !== strtolower($email);
                });
                $microsoftAccounts = array_map(function ($acc) {
                    $acc['is_oauth'] = true;
                    $acc['provider'] = 'microsoft';
                    return $acc;
                }, $microsoftAccounts);
                $oauthAccounts = array_merge($oauthAccounts, $microsoftAccounts);
            }

            $accounts = array_map(function ($acc) {
                $acc['is_oauth'] = false;
                return $acc;
            }, $accounts);

            return [
                'accounts' => array_values(array_merge($accounts, $oauthAccounts)),
                'presets' => $presets,
                'google_oauth_enabled' => $this->googleOAuthService !== null,
                'microsoft_oauth_enabled' => $this->microsoftOAuthService !== null,
            ];
        } catch (\Exception $e) {
            error_log('[Bootstrap] Accounts failed: ' . $e->getMessage());
            return [
                'accounts' => [],
                'presets' => [],
                'google_oauth_enabled' => false,
                'microsoft_oauth_enabled' => false,
            ];
        }
    }
}
