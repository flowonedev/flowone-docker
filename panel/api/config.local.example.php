<?php
/**
 * Local Configuration Override
 * 
 * Copy this file to config.local.php and update with your settings.
 * This file should NOT be committed to version control.
 */

return [
    // Enable debug mode for development
    'app' => [
        'debug' => true,  // Set to false in production
        'url' => 'https://backup.devcon1.hu',  // Your actual domain
    ],

    // Database credentials
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'devc_vps_dash',
        'user' => 'devc_vps_dash',
        'password' => 'YOUR_DATABASE_PASSWORD_HERE',  // Update this
        'charset' => 'utf8mb4',
    ],

    // MariaDB administrative credentials
    //
    // Used by the provisioning saga (DatabaseCreateStep,
    // DatabaseUserCreateStep, DatabaseGrantStep, DatabaseDropStep, ...)
    // to run CREATE/DROP DATABASE, CREATE/DROP USER, and GRANT/REVOKE.
    //
    // The 'database' user above is intentionally narrow (only
    // devc_vps_dash.*) and CANNOT do these operations. If this block is
    // omitted, the agent falls back to /root/.my.cnf, then to the panel
    // user (which will fail with SQLSTATE 1044 on the first CREATE).
    //
    // Required privileges on the admin user:
    //   GRANT ALL PRIVILEGES ON *.* TO 'flowone_admin'@'localhost'
    //     WITH GRANT OPTION;
    //
    // Or, more conservatively (still works for the saga):
    //   GRANT CREATE, DROP, GRANT OPTION,
    //         CREATE USER, RELOAD ON *.* TO 'flowone_admin'@'localhost';
    //   (then GRANT ALL on the per-site DBs as they are created.)
    //
    // Connect over a unix socket if MariaDB is local for an extra
    // hardening boost (no TCP exposure of admin creds).
    'database_admin' => [
        'host' => 'localhost',
        'port' => 3306,
        'user' => 'flowone_admin',
        'password' => 'YOUR_MARIADB_ADMIN_PASSWORD_HERE',
        'socket' => '/var/run/mysqld/mysqld.sock',  // optional, omit for TCP
    ],

    // JWT – RS256 keys take priority; secret is HS256 fallback during migration
    // Generate RS256 keys:
    //   openssl genrsa -out /var/www/vps-admin/var/jwt-private.pem 2048
    //   openssl rsa -in /var/www/vps-admin/var/jwt-private.pem -pubout -out /var/www/vps-admin/var/jwt-public.pem
    'jwt' => [
        'secret' => 'GENERATE_AND_PASTE_SECRET_HERE',   // HS256 fallback
        'algorithm' => 'RS256',
        'private_key' => '/var/www/vps-admin/var/jwt-private.pem',
        'public_key'  => '/var/www/vps-admin/var/jwt-public.pem',
        'expiry' => 7200,   // 2 hours
        'refresh_expiry' => 86400, // 24 hours
    ],

    // Agent socket (usually doesn't need changing)
    'agent' => [
        'socket' => '/run/vps-admin/agent.sock',
        'token_file' => '/var/www/vps-admin/var/agent.token',
        'timeout' => 30,
    ],

    // CORS (set to your actual panel domain)
    'cors' => [
        'allowed_origins' => ['https://vps.devcon1.hu'],
    ],

    // AI Helper Configuration
    'ai_helper' => [
        'openai_api_key' => 'YOUR_OPENAI_API_KEY_HERE',  // Get from https://platform.openai.com/api-keys
        'openai_model' => 'gpt-5.2',  // Options: gpt-5.2, gpt-5.2-pro, gpt-5.1-codex-max, gpt-5-mini, gpt-5-nano
        'max_tokens' => 2000,
        'temperature' => 0.3,
    ],

    // Email App integration (addon cache invalidation after toggle)
    // api_url = Email App backend base URL (no trailing slash)
    // api_key = Must match PANEL_API_KEY in the Email App's .env / config
    'email_app' => [
        'api_url' => 'https://mail.devcon1.hu/api',
        'api_key' => 'SAME_AS_PANEL_API_KEY_IN_EMAIL_APP',
    ],

    // External API Keys (for Email App, etc.)
    // Generate with: openssl rand -hex 32
    'external_api' => [
        'keys' => [
            'email_app' => 'GENERATE_WITH_openssl_rand_hex_32',
        ],
    ],
];

