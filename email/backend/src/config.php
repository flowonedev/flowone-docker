<?php

// OAuth encryption key support (versioned)
// OAUTH_KEYS format: v1:<keySource>,v2:<keySource>,...
// OAUTH_CURRENT_VERSION format: integer (matching a v{N} in OAUTH_KEYS)
$oauthKeysRaw = getenv('OAUTH_KEYS') ?: '';
$oauthCurrentVersion = (int)(getenv('OAUTH_CURRENT_VERSION') ?: 0);
$oauthKeys = [];

if (trim($oauthKeysRaw) !== '') {
    $pairs = array_filter(array_map('trim', explode(',', $oauthKeysRaw)));
    foreach ($pairs as $pair) {
        if (!preg_match('/^v(\d+):(.*)$/i', $pair, $m)) {
            continue;
        }
        $ver = (int)$m[1];
        $keySource = trim($m[2]);
        if ($ver > 0 && $keySource !== '') {
            $oauthKeys[$ver] = $keySource;
        }
    }
}

// Practical default: if OAUTH_KEYS isn't set but IMAP_ENCRYPTION_KEY is, allow
// decrypting legacy rows and encrypt new ones under v1 (until you run the
// key-rotation migration).
$legacyImapKey = getenv('IMAP_ENCRYPTION_KEY') ?: '';
if (empty($oauthKeys) && $legacyImapKey !== '') {
    $oauthKeys = [1 => $legacyImapKey];
    if ($oauthCurrentVersion === 0) {
        $oauthCurrentVersion = 1;
    }
}

// Per-deployment public URLs. Each server runs on its own domain
// (email.<domain>); these drive OAuth redirects, in-app links, and CSP. Set
// API_URL / FRONTEND_URL in the server's .env. The flowone.pro fallbacks keep
// the canonical deployment working when they are unset.
$frontendUrl = rtrim(getenv('FRONTEND_URL') ?: 'https://flowone.pro', '/');
$apiUrl = rtrim(getenv('API_URL') ?: ($frontendUrl . '/api'), '/');

$config = [
    // IMAP Settings - Using SSL on port 993
    'imap' => [
        'host' => 'localhost',
        'port' => 993,
        'encryption' => 'ssl',
        // Only skip cert validation for localhost; external hosts must validate
        'validate_cert' => (getenv('IMAP_HOST') ?: 'localhost') !== 'localhost',
        // Sieve/ManageSieve settings
        'sieve_host' => 'localhost',
        'sieve_port' => 4190,
        'sieve_tls' => false, // Dovecot ManageSieve typically doesn't require TLS on localhost
    ],

    // SMTP Settings - Using STARTTLS on port 587
    'smtp' => [
        'host' => 'localhost',
        'port' => 587,
        'encryption' => 'tls',
        'auth' => true,
        // Only skip peer verification for localhost; external hosts must verify
        'verify_peer' => (getenv('SMTP_HOST') ?: 'localhost') !== 'localhost',
        // System notification email (for sending share notifications, etc.)
        'username' => 'noreply@devcon1.hu',
        'password' => getenv('SMTP_NOTIFICATION_PASSWORD') ?: '',
    ],
    
    // Contacts / address-book reconciliation
    'contacts' => [
        // After this many sends to the same address, a "seen" recipient is
        // auto-collected into the non-synced "Other contacts" pool (never the
        // synced book, so phones stay clean). Set 0/negative to keep default.
        'auto_add_threshold' => (int)(getenv('CONTACTS_AUTO_ADD_THRESHOLD') ?: 3),
    ],

    // Email open-tracking (read receipts)
    'email_tracking' => [
        // Mail providers (Gmail's GoogleImageProxy, Apple Mail Privacy
        // Protection, Outlook) fetch the tracking pixel automatically within
        // seconds of delivery, BEFORE a human opens the message. Any "open"
        // recorded within this many seconds of the send is treated as that
        // automated prefetch and ignored, so it never produces a false read
        // receipt or a phantom mobile push. Set to 0 to disable the guard.
        'prefetch_window_seconds' => (int)(getenv('EMAIL_TRACKING_PREFETCH_WINDOW') ?: 15),
    ],

    // JWT Settings
    // RS256 (asymmetric): private key signs, public key verifies
    // HS256 (symmetric): shared secret — kept as fallback during migration
    'jwt' => [
        'secret' => getenv('JWT_SECRET') ?: '', // DEPRECATED: Only kept for decrypting legacy IMAP passwords. Remove once all sessions have rotated.
        'algorithm' => getenv('JWT_ALGORITHM') ?: 'RS256',
        'private_key_path' => getenv('JWT_PRIVATE_KEY_PATH') ?: __DIR__ . '/../storage/config/jwt-private.pem',
        'public_key_path' => getenv('JWT_PUBLIC_KEY_PATH') ?: __DIR__ . '/../storage/config/jwt-public.pem',
        'expiry' => 3600 * 12, // 12 hours
        'refresh_expiry' => 3600 * 24 * 7, // 7 days (2FA remember device)
    ],

    // OAuth token encryption settings (versioned)
    'oauth_encryption' => [
        'keys' => $oauthKeys,
        'current_version' => $oauthCurrentVersion,
        // Used for legacy AES-256-CBC decrypt compatibility
        'legacy_imap_key' => $legacyImapKey,
    ],
    
    // Session/Cookie Settings
    'session' => [
        'cookie_name' => 'webmail_session',
        'secure' => true,
        'httponly' => true,
        // IP binding: reject sessions from different IPs than the one that created the session
        // Disabled by default as it can cause issues with mobile users and VPNs
        // Set SESSION_ENFORCE_IP_BINDING=true in .env to enable
        'enforce_ip_binding' => getenv('SESSION_ENFORCE_IP_BINDING') === 'true',
        'samesite' => 'Strict',
    ],
    
    // Upload Settings
    'upload' => [
        'max_size' => 25 * 1024 * 1024, // 25MB
        'temp_dir' => '/tmp/webmail_attachments',
        'allowed_types' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', 'text/plain', 'text/csv',
        ],
    ],
    
    // User preferences defaults
    'defaults' => [
        'messages_per_page' => 50,
        'theme' => 'system', // 'light', 'dark', 'system'
        'signature' => '',
        'display_name' => '',
    ],
    
    // Database Settings
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'devc_vps_dash',
        'user' => getenv('DB_USER') ?: 'vpsadmin',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    
    // Mail Server Database (Dovecot/Postfix) - for colleague sync
    // Configure this to sync colleagues from your mail server
    'mail_db' => [
        'host' => getenv('MAIL_DB_HOST') ?: '127.0.0.1',
        'name' => getenv('MAIL_DB_NAME') ?: 'vmail',      // Your Dovecot database name
        'user' => getenv('MAIL_DB_USER') ?: 'vmail',      // Read-only user recommended
        'pass' => getenv('MAIL_DB_PASS') ?: '',           // Set via environment variable
    ],
    
    // Panel Integration (storage configuration comes from Panel)
    'panel' => [
        'api_url' => getenv('PANEL_API_URL') ?: 'https://panel.devcon1.hu/api',
        'api_key' => getenv('PANEL_API_KEY') ?: '',
        'storage_cache_ttl' => 300, // Cache Panel storage config for 5 minutes
    ],
    
    // General storage path (portal files, documents, etc.)
    'storage_path' => getenv('STORAGE_PATH') ?: (
        (strpos(__DIR__, '/var/www/vps-email') === 0)
            ? '/var/www/vps-email/storage'
            : __DIR__ . '/../storage'
    ),
    
    // Drive Storage Settings (fallback when Panel is unavailable)
    'drive' => [
        'storage_path' => (strpos(__DIR__, '/var/www/vps-email') === 0)
            ? '/var/www/vps-email/storage/drive'
            : __DIR__ . '/../storage/drive',
    ],
    
    // Google OAuth Settings
    // IMPORTANT: Both redirect URIs below must be registered in Google Cloud Console:
    //   1. For adding secondary accounts: https://flowone.pro/api/auth/google/callback
    //   2. For "Sign in with Google" login: https://flowone.pro/api/auth/google/login/callback
    'google_oauth' => [
        'client_id' => getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: ($apiUrl . '/auth/google/callback'),
        // The login redirect URI is constructed in AuthController as: {api_url}/auth/google/login/callback
        // Login requests the FULL Gmail scope so a single consent grants both
        // identity (openid/email/profile) and mailbox access (mail.google.com).
        // This avoids the silent re-consent popup that used to fire after login.
        // The "Google hasn't verified this app" warning appears once per user
        // until Google completes restricted-scope verification, then never again
        // (Google remembers consent via the stored refresh token).
        'login_scopes' => [
            'openid',
            'email',
            'profile',
            'https://mail.google.com/',
        ],
        // Used for adding a SECONDARY Gmail account via Settings -> Accounts.
        // Same set as login so the consent screen is identical.
        'scopes' => [
            'https://mail.google.com/',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            //'https://www.googleapis.com/auth/calendar',
            //'https://www.googleapis.com/auth/calendar.events',
        ],
    ],
    
    // Microsoft OAuth Settings
    // IMPORTANT: Both redirect URIs below must be registered in Azure AD App Registration:
    //   1. For adding secondary accounts: https://flowone.pro/api/auth/microsoft/callback
    //   2. For "Sign in with Microsoft" login: https://flowone.pro/api/auth/microsoft/login/callback
    'microsoft_oauth' => [
        'client_id' => getenv('MICROSOFT_OAUTH_CLIENT_ID') ?: '',
        'client_secret' => getenv('MICROSOFT_OAUTH_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('MICROSOFT_OAUTH_REDIRECT_URI') ?: ($apiUrl . '/auth/microsoft/callback'),
        // The login redirect URI is constructed in AuthController as: {api_url}/auth/microsoft/login/callback
        'scopes' => [
            'https://outlook.office.com/IMAP.AccessAsUser.All',
            'https://outlook.office.com/SMTP.Send',
            'https://graph.microsoft.com/User.Read',
            'https://graph.microsoft.com/Calendars.ReadWrite',
            'offline_access',
        ],
    ],
    
    // App Settings
    'app' => [
        'env' => getenv('APP_ENV') ?: 'prod',
        'api_url' => $apiUrl,
        'frontend_url' => $frontendUrl,
    ],
    
    // IMAP password encryption key (separate from JWT secret for defense-in-depth)
    // (SessionService still historically had a JWT fallback; we are removing it
    // as part of the OAuth encryption hardening.)
    'imap_encryption_key' => getenv('IMAP_ENCRYPTION_KEY') ?: '',
    
    // AI Settings
    'encryption_key' => getenv('AI_ENCRYPTION_KEY') ?: '',
    
    // Redis Cache Settings
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
        'prefix' => 'webmail:',
        'timeout' => 2.0, // Connection timeout in seconds
        'ttl' => [
            'message' => 3600,        // 1 hour - full message content
            'message_list' => 600,    // 10 minutes - message list/envelope (was 5 min)
            'conversation' => 1800,   // 30 minutes - conversation data (was 5 min)
            'folder_status' => 300,   // 5 minutes - folder counts (was 2 min)
            'folder_list' => 600,     // 10 minutes - folder list (was 5 min)
            'thumbnail' => 86400,     // 24 hours - drive thumbnails
            'session' => 43200,       // 12 hours - session data
        ],
    ],
    
    // Collaborative Editing Settings
    'collab' => [
        'prefix' => 'collab_',                        // Database table prefix
        'ws_port' => (int)(getenv('COLLAB_WS_PORT') ?: 1234),
        'ws_host' => getenv('COLLAB_WS_HOST') ?: 'localhost',
        'ws_url' => getenv('COLLAB_WS_URL') ?: 'wss://flowone.pro/collab_ws',
    ],
    
    // Web Push Notification Settings (VAPID)
    // Generate keys with: cd mailsync/server && node ../../scripts/generate-vapid-keys.js
    'push' => [
        'vapid_public_key' => getenv('VAPID_PUBLIC_KEY') ?: '',
        'vapid_private_key' => getenv('VAPID_PRIVATE_KEY') ?: '',
        'vapid_subject' => getenv('VAPID_SUBJECT') ?: 'mailto:admin@devcon1.hu',
    ],
    
    // WebRTC / TURN Server Settings (Coturn) — LEGACY, kept for backward compat
    'webrtc' => [
        'stun_url' => getenv('STUN_URL') ?: 'stun:stun.devcon1.hu:3478',
        'turn_url' => getenv('TURN_URL') ?: 'turn:turn.devcon1.hu:3478',
        'turn_secret' => getenv('TURN_SECRET') ?: '',
        'turn_ttl' => (int)(getenv('TURN_TTL') ?: 86400), // 24h credential lifetime
    ],
    
    // LiveKit SFU Server (replaces mesh WebRTC + Coturn)
    // SECURITY: api_key / api_secret are required secrets and MUST come from the
    // environment. No source-code defaults — a missing value makes
    // CallService::getLiveKitToken throw ("LiveKit API credentials not
    // configured") rather than silently signing tokens with a leaked secret.
    // ws_url is not a secret, so it keeps a canonical-deployment fallback.
    'livekit' => [
        'api_key' => getenv('LIVEKIT_API_KEY') ?: '',
        'api_secret' => getenv('LIVEKIT_API_SECRET') ?: '',
        'ws_url' => getenv('LIVEKIT_WS_URL') ?: 'wss://devcon1.hu:7443',
    ],
    
    // SSO Settings (desktop app cross-authentication)
    'sso' => [
        'server_key' => getenv('SSO_SERVER_KEY') ?: 'flowone-sso-default-key-change-in-production',
        'seed_ttl' => 7 * 24 * 3600, // 7 days
        'code_ttl' => 120, // 2 minutes
    ],
    
    // Meilisearch Settings (Universal Search Engine)
    // Binds to 127.0.0.1 only for security - no external access
    'meilisearch' => [
        'host' => getenv('MEILI_HOST') ?: 'http://127.0.0.1:7700',
        'master_key' => getenv('MEILI_MASTER_KEY') ?: '',
        'search_key' => getenv('MEILI_SEARCH_KEY') ?: '',
        'index_name' => 'documents',
        'batch_size' => 1000,  // Documents per batch for bulk indexing
    ],

    // Markets data provider for the News dashboard panel.
    // Stocks/indices/forex/commodities are fetched from Twelve Data
    // (https://twelvedata.com/) which requires a free API key. Crypto
    // is still pulled key-less from CoinGecko, so leaving this empty
    // simply disables the stocks card — the crypto card keeps working.
    'markets' => [
        'twelvedata_api_key' => getenv('TWELVEDATA_API_KEY') ?: '',
        // How long to consider a cached overview "fresh" before
        // hitting the upstreams again. Defaulting to 1 hour keeps us
        // well under Twelve Data's 800-calls/day free quota even when
        // every active user has a custom basket.
        'cache_ttl_fresh' => (int) (getenv('MARKETS_CACHE_TTL') ?: 3600),
    ],
];

// Centralized local override merge.
//
// config.local.php is a DEV-ONLY file (git-ignored) that lets developers
// override settings like smtp.host or imap.host to point at remote servers.
// It MUST NEVER exist on production: on the VPS, mail.devcon1.hu resolves
// to the server's own public IP, so overriding smtp.host to it routes
// outbound mail out the public interface and back through Postfix, which
// then trips OpenDMARC validation and rejects authenticated submissions
// with "550 5.7.1 rejected by DMARC policy".
//
// Previously this merge only happened in public/index.php, which meant
// the web request path and the cron path (process-scheduled-emails.php,
// bootstrap.php, etc.) loaded different effective configs. That
// inconsistency let a delayed-send queue mask the bug while every
// immediate send broke. Performing the merge here guarantees ALL
// callers - web, cron, tests, scripts - see identical settings.
$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

return $config;

