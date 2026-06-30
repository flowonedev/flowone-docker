<?php

namespace Webmail\Services;

/**
 * PushNotificationService - Web Push notification subscription management
 * 
 * Stores push subscriptions in MySQL and syncs to Redis for the
 * Node.js mailsync server to read when sending push notifications.
 * 
 * The actual push sending is done by the Node.js mailsync server
 * using the web-push npm package, as it already handles real-time
 * events and knows when users are offline.
 */
class PushNotificationService
{
    private \PDO $db;
    private array $config;
    private ?RedisCacheService $redis = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
        
        // Redis for syncing subscriptions to Node.js server
        try {
            $this->redis = new RedisCacheService($config);
        } catch (\Throwable $e) {
            error_log("PushNotificationService: Redis unavailable: " . $e->getMessage());
        }
    }
    
    /**
     * Create push_subscriptions table if it doesn't exist
     */
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS push_subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    endpoint TEXT NOT NULL,
                    p256dh VARCHAR(512) NOT NULL,
                    auth VARCHAR(255) NOT NULL,
                    user_agent VARCHAR(500) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    UNIQUE KEY unique_endpoint (endpoint(500))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("PushNotificationService: Table creation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get the VAPID public key for the frontend
     */
    public function getVapidPublicKey(): string
    {
        return $this->config['push']['vapid_public_key'] ?? '';
    }
    
    /**
     * Subscribe: store push subscription for a user
     */
    public function subscribe(string $userEmail, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): array
    {
        try {
            // Upsert: insert or update if endpoint already exists
            $stmt = $this->db->prepare("
                INSERT INTO push_subscriptions (user_email, endpoint, p256dh, auth, user_agent)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    user_email = VALUES(user_email),
                    p256dh = VALUES(p256dh),
                    auth = VALUES(auth),
                    user_agent = VALUES(user_agent),
                    updated_at = NOW()
            ");
            $stmt->execute([$userEmail, $endpoint, $p256dh, $auth, $userAgent]);
            
            // Sync all subscriptions for this user to Redis
            $this->syncSubscriptionsToRedis($userEmail);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: Subscribe failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save subscription'];
        }
    }
    
    /**
     * Unsubscribe: remove a push subscription
     */
    public function unsubscribe(string $userEmail, string $endpoint): array
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM push_subscriptions 
                WHERE user_email = ? AND endpoint = ?
            ");
            $stmt->execute([$userEmail, $endpoint]);
            
            // Sync to Redis
            $this->syncSubscriptionsToRedis($userEmail);
            
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: Unsubscribe failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to remove subscription'];
        }
    }
    
    /**
     * Sync all push subscriptions for a user to Redis
     * 
     * The Node.js mailsync server reads these when it needs to send
     * push notifications to offline users.
     * 
     * Redis key: push_subs:{email}  (prefix is added by RedisCacheService)
     * Value: JSON array of { endpoint, keys: { p256dh, auth } }
     */
    private function syncSubscriptionsToRedis(string $userEmail): void
    {
        if (!$this->redis) return;
        
        try {
            $stmt = $this->db->prepare("
                SELECT endpoint, p256dh, auth 
                FROM push_subscriptions 
                WHERE user_email = ?
            ");
            $stmt->execute([$userEmail]);
            $subs = $stmt->fetchAll();
            
            $redisKey = 'push_subs:' . strtolower($userEmail);
            
            if (empty($subs)) {
                $this->redis->delete($redisKey);
            } else {
                // Format as web-push compatible subscription objects
                $formatted = array_map(function($sub) {
                    return [
                        'endpoint' => $sub['endpoint'],
                        'keys' => [
                            'p256dh' => $sub['p256dh'],
                            'auth' => $sub['auth']
                        ]
                    ];
                }, $subs);
                
                // Store as JSON string with 30-day TTL
                $this->redis->set($redisKey, json_encode($formatted), 86400 * 30);
            }
        } catch (\Throwable $e) {
            error_log("PushNotificationService: Redis sync failed: " . $e->getMessage());
        }
    }
    
    // ============================================================
    // Native (FCM) push tokens for the Capacitor iOS/Android apps
    //
    // MySQL (native_push_tokens) is the source of truth. After every
    // change we rebuild the derived Redis cache fcm_tokens:{email} that
    // the Node mailsync fcmService reads. One row per (user, app, device);
    // the token rotates in place so devices never accumulate stale tokens.
    // ============================================================

    private function ensureNativeTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS native_push_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    platform VARCHAR(20) NOT NULL DEFAULT 'ios',
                    app_id VARCHAR(100) NOT NULL DEFAULT 'com.flowone.pro',
                    device_id VARCHAR(191) NOT NULL,
                    device_name VARCHAR(255) DEFAULT NULL,
                    token VARCHAR(512) NOT NULL,
                    token_kind VARCHAR(16) NOT NULL DEFAULT 'fcm',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_device_kind (user_email, app_id, device_id, token_kind),
                    INDEX idx_user_email (user_email),
                    INDEX idx_user_kind (user_email, token_kind),
                    INDEX idx_token (token(191)),
                    INDEX idx_last_seen (last_seen_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("PushNotificationService: native table creation failed: " . $e->getMessage());
        }
    }

    /**
     * Register (or refresh) a native FCM token for a user's device.
     *
     * Dedupe strategy: keyed on (user_email, app_id, device_id) so the token
     * rotates in place. A given FCM token belongs to exactly one install, so
     * any rows holding the same token on a different device/user are removed
     * first (covers reinstall / account switch on the same handset).
     */
    public function registerNativeToken(
        string $userEmail,
        string $platform,
        string $appId,
        string $deviceId,
        ?string $deviceName,
        string $token,
        string $tokenKind = 'fcm'
    ): array {
        $this->ensureNativeTableExists();

        // Only two kinds are valid; anything else falls back to 'fcm'.
        $tokenKind = ($tokenKind === 'voip') ? 'voip' : 'fcm';

        try {
            $affected = [strtolower($userEmail) => true];

            // Reassign the token to this device: drop it wherever else it lives.
            // Scoped to the same kind so an fcm token never clobbers a voip row
            // (and vice versa); the two are different opaque strings anyway.
            $sel = $this->db->prepare(
                "SELECT DISTINCT user_email FROM native_push_tokens
                 WHERE token = ? AND token_kind = ? AND NOT (user_email = ? AND app_id = ? AND device_id = ?)"
            );
            $sel->execute([$token, $tokenKind, $userEmail, $appId, $deviceId]);
            foreach ($sel->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $affected[strtolower((string)$em)] = true;
            }
            $del = $this->db->prepare(
                "DELETE FROM native_push_tokens
                 WHERE token = ? AND token_kind = ? AND NOT (user_email = ? AND app_id = ? AND device_id = ?)"
            );
            $del->execute([$token, $tokenKind, $userEmail, $appId, $deviceId]);

            // Upsert by device identity (now incl. token_kind); token + last_seen_at
            // refresh in place. fcm and voip rows for one device coexist.
            $stmt = $this->db->prepare("
                INSERT INTO native_push_tokens
                    (user_email, platform, app_id, device_id, device_name, token, token_kind, last_seen_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    platform = VALUES(platform),
                    device_name = VALUES(device_name),
                    token = VALUES(token),
                    last_seen_at = NOW()
            ");
            $stmt->execute([$userEmail, $platform, $appId, $deviceId, $deviceName, $token, $tokenKind]);

            foreach (array_keys($affected) as $em) {
                $this->syncNativeTokensToRedis($em);
            }

            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: registerNativeToken failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save device token'];
        }
    }

    /**
     * Remove a native token (logout / device removal). Match by token (preferred)
     * or by device_id (optionally scoped to an app_id).
     */
    public function removeNativeToken(
        string $userEmail,
        ?string $token = null,
        ?string $deviceId = null,
        ?string $appId = null
    ): array {
        try {
            if ($token) {
                $stmt = $this->db->prepare(
                    "DELETE FROM native_push_tokens WHERE user_email = ? AND token = ?"
                );
                $stmt->execute([$userEmail, $token]);
            } elseif ($deviceId && $appId) {
                $stmt = $this->db->prepare(
                    "DELETE FROM native_push_tokens WHERE user_email = ? AND device_id = ? AND app_id = ?"
                );
                $stmt->execute([$userEmail, $deviceId, $appId]);
            } elseif ($deviceId) {
                $stmt = $this->db->prepare(
                    "DELETE FROM native_push_tokens WHERE user_email = ? AND device_id = ?"
                );
                $stmt->execute([$userEmail, $deviceId]);
            } else {
                return ['success' => false, 'error' => 'token or device_id required'];
            }

            $this->syncNativeTokensToRedis($userEmail);
            return ['success' => true];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: removeNativeToken failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to remove device token'];
        }
    }

    /**
     * Delete native tokens not seen for $days (60-90d window). Returns rows removed.
     * Resyncs the Redis cache for every affected user.
     */
    public function cleanupStaleNativeTokens(int $days = 75): int
    {
        $days = max(1, $days);
        try {
            $sel = $this->db->prepare(
                "SELECT DISTINCT user_email FROM native_push_tokens
                 WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)"
            );
            $sel->execute();
            $affected = $sel->fetchAll(\PDO::FETCH_COLUMN);

            $stmt = $this->db->prepare(
                "DELETE FROM native_push_tokens
                 WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL " . (int)$days . " DAY)"
            );
            $stmt->execute();
            $count = $stmt->rowCount();

            foreach ($affected as $em) {
                $this->syncNativeTokensToRedis(strtolower((string)$em));
            }
            return $count;
        } catch (\PDOException $e) {
            error_log("PushNotificationService: cleanupStaleNativeTokens failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Drain the fcm_prune_queue: dead tokens reported by the Node mailsync
     * server (FCM returned not-registered/invalid). Node never edits the token
     * cache directly — it only enqueues here; PHP owns MySQL and rebuilds Redis.
     */
    public function drainFcmPruneQueue(int $max = 1000): int
    {
        if (!$this->redis) {
            return 0;
        }

        $processed = 0;
        $affected = [];

        try {
            for ($i = 0; $i < $max; $i++) {
                $raw = $this->redis->listPop('fcm_prune_queue');
                if ($raw === null) {
                    break;
                }

                $item = json_decode($raw, true);
                $token = is_array($item) ? ($item['token'] ?? null) : null;
                if (!$token) {
                    continue;
                }

                $sel = $this->db->prepare("SELECT DISTINCT user_email FROM native_push_tokens WHERE token = ?");
                $sel->execute([$token]);
                foreach ($sel->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                    $affected[strtolower((string)$em)] = true;
                }

                $del = $this->db->prepare("DELETE FROM native_push_tokens WHERE token = ?");
                $del->execute([$token]);
                $processed++;
            }

            foreach (array_keys($affected) as $em) {
                $this->syncNativeTokensToRedis($em);
            }
        } catch (\Throwable $e) {
            error_log("PushNotificationService: drainFcmPruneQueue failed: " . $e->getMessage());
        }

        return $processed;
    }

    /**
     * Count native tokens for a user (test/diagnostics).
     */
    public function getNativeTokenCount(string $userEmail): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM native_push_tokens WHERE user_email = ?");
            $stmt->execute([$userEmail]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    // ============================================================
    // Notification preferences (user-wide push gating)
    //
    // Flat columns in MySQL; mirrored into the generic Redis map
    // notif_prefs:{email} so the Node sender can gate by type without a
    // DB hit and without knowing the column layout.
    // ============================================================

    private const PREF_TYPES = ['email', 'chat', 'calls', 'calendar', 'boards'];

    private function ensurePrefsTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS notification_preferences (
                    user_email VARCHAR(255) NOT NULL PRIMARY KEY,
                    push_email TINYINT(1) NOT NULL DEFAULT 1,
                    push_chat TINYINT(1) NOT NULL DEFAULT 1,
                    push_calls TINYINT(1) NOT NULL DEFAULT 1,
                    push_calendar TINYINT(1) NOT NULL DEFAULT 1,
                    push_boards TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("PushNotificationService: prefs table creation failed: " . $e->getMessage());
        }
    }

    /**
     * Get a user's push preferences as a {type: bool} map. Defaults all-on.
     */
    public function getPreferences(string $userEmail): array
    {
        $defaults = array_fill_keys(self::PREF_TYPES, true);

        try {
            $stmt = $this->db->prepare("SELECT * FROM notification_preferences WHERE user_email = ?");
            $stmt->execute([strtolower($userEmail)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return $defaults;
            }

            return [
                'email' => (bool)$row['push_email'],
                'chat' => (bool)$row['push_chat'],
                'calls' => (bool)$row['push_calls'],
                'calendar' => (bool)$row['push_calendar'],
                'boards' => (bool)$row['push_boards'],
            ];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: getPreferences failed: " . $e->getMessage());
            return $defaults;
        }
    }

    /**
     * Update a user's push preferences (partial map allowed). Returns the saved
     * map and mirrors it to Redis (notif_prefs:{email}).
     */
    public function updatePreferences(string $userEmail, array $prefs): array
    {
        $this->ensurePrefsTableExists();

        $current = $this->getPreferences($userEmail);
        foreach (self::PREF_TYPES as $type) {
            if (array_key_exists($type, $prefs)) {
                $current[$type] = (bool)$prefs[$type];
            }
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO notification_preferences
                    (user_email, push_email, push_chat, push_calls, push_calendar, push_boards)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    push_email = VALUES(push_email),
                    push_chat = VALUES(push_chat),
                    push_calls = VALUES(push_calls),
                    push_calendar = VALUES(push_calendar),
                    push_boards = VALUES(push_boards)
            ");
            $stmt->execute([
                strtolower($userEmail),
                $current['email'] ? 1 : 0,
                $current['chat'] ? 1 : 0,
                $current['calls'] ? 1 : 0,
                $current['calendar'] ? 1 : 0,
                $current['boards'] ? 1 : 0,
            ]);

            $this->syncPrefsToRedis($userEmail, $current);

            return ['success' => true, 'preferences' => $current];
        } catch (\PDOException $e) {
            error_log("PushNotificationService: updatePreferences failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save preferences'];
        }
    }

    /**
     * Store the user's current unread badge total in Redis (badge:{email}).
     *
     * The Node mailsync server reads + atomically increments this to set the
     * iOS aps.badge / Android notificationCount on each push. The PWA reports
     * its authoritative unread total here (email + chat + missed calls) so the
     * app-icon badge stays accurate as the user reads/receives items.
     *
     * Stored as a bare integer string so Node's Redis INCR works directly.
     */
    public function setBadgeCount(string $userEmail, int $count): array
    {
        if (!$this->redis) {
            return ['success' => false, 'error' => 'Redis unavailable'];
        }

        try {
            $this->redis->set('badge:' . strtolower($userEmail), (string)max(0, $count), 86400 * 30);
            return ['success' => true];
        } catch (\Throwable $e) {
            error_log("PushNotificationService: setBadgeCount failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to store badge count'];
        }
    }

    /**
     * Mirror preferences to Redis as a generic {type: 1|0} map the Node sender reads.
     */
    private function syncPrefsToRedis(string $userEmail, ?array $prefs = null): void
    {
        if (!$this->redis) {
            return;
        }

        $prefs = $prefs ?? $this->getPreferences($userEmail);
        $map = [];
        foreach (self::PREF_TYPES as $type) {
            $map[$type] = !empty($prefs[$type]) ? 1 : 0;
        }

        try {
            $this->redis->set('notif_prefs:' . strtolower($userEmail), json_encode($map), 86400 * 90);
        } catch (\Throwable $e) {
            error_log("PushNotificationService: prefs Redis sync failed: " . $e->getMessage());
        }
    }

    /**
     * Rebuild the derived Redis caches for a user's native device tokens.
     *
     *   fcm_tokens:{email}  -> JSON [{token, app_id, platform}]  (token_kind 'fcm')
     *   voip_tokens:{email} -> JSON [{token, app_id, platform}]  (token_kind 'voip')
     *
     * Kept in SEPARATE keys because a VoIP/PushKit token is APNs-only and would
     * be rejected by FCM. app_id lets the Node fcmService route by app (a phone
     * with both Pro and Chat installed only rings the right one); platform lets
     * the call path pick the VoIP (iOS) vs full-screen-intent (Android) channel.
     * The Node reader still accepts the legacy bare-string shape for backward
     * compat on the fcm cache.
     */
    private function syncNativeTokensToRedis(string $userEmail): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT token, app_id, platform, token_kind FROM native_push_tokens WHERE user_email = ?"
            );
            $stmt->execute([$userEmail]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $fcmKey = 'fcm_tokens:' . strtolower($userEmail);
            $voipKey = 'voip_tokens:' . strtolower($userEmail);

            $fcm = [];
            $voip = [];
            foreach ($rows as $row) {
                $entry = [
                    'token' => $row['token'],
                    'app_id' => $row['app_id'] ?: 'com.flowone.pro',
                    'platform' => $row['platform'] ?: 'ios',
                ];
                if (($row['token_kind'] ?? 'fcm') === 'voip') {
                    $voip[] = $entry;
                } else {
                    $fcm[] = $entry;
                }
            }

            // 90-day TTL; re-register on app start keeps it fresh.
            if (empty($fcm)) {
                $this->redis->delete($fcmKey);
            } else {
                $this->redis->set($fcmKey, json_encode(array_values($fcm)), 86400 * 90);
            }

            if (empty($voip)) {
                $this->redis->delete($voipKey);
            } else {
                $this->redis->set($voipKey, json_encode(array_values($voip)), 86400 * 90);
            }
        } catch (\Throwable $e) {
            error_log("PushNotificationService: native Redis sync failed: " . $e->getMessage());
        }
    }
}

