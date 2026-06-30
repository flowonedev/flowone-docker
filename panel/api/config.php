<?php
/**
 * API Configuration
 */

return [
    // Application
    'app' => [
        'name' => 'PXR VPS Admin',
        'debug' => false,
        'url' => 'https://vps.devcon1.hu',
    ],

    // Database
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'devc_vps_dash',
        'user' => 'devc_vps_dash',
        'password' => '', // MUST be set in config.local.php
        'charset' => 'utf8mb4',
    ],

    // JWT Authentication
    'jwt' => [
        'secret' => '', // HS256 fallback – set in config.local.php
        'algorithm' => 'RS256', // RS256 preferred; falls back to HS256 if keys missing
        'private_key' => '/var/www/vps-admin/var/jwt-private.pem', // openssl genrsa -out jwt-private.pem 2048
        'public_key'  => '/var/www/vps-admin/var/jwt-public.pem',  // openssl rsa -in jwt-private.pem -pubout -out jwt-public.pem
        'expiry' => 7200, // 2 hours (access token)
        'refresh_expiry' => 86400, // 24 hours (refresh token)
    ],

    // Agent communication
    'agent' => [
        'socket' => '/run/vps-admin/agent.sock',
        'token_file' => '/var/www/vps-admin/var/agent.token',
        'timeout' => 30,
    ],

    // Redis cache
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null, // Set in config.local.php if needed
        'database' => 0,
        'timeout' => 2.0,
        'prefix' => 'vps:',
        'ttls' => [
            'sites' => 300,      // 5 minutes
            'site' => 300,       // 5 minutes
            'dns' => 300,        // 5 minutes
            'mail' => 300,       // 5 minutes
            'db' => 300,         // 5 minutes
            'backups' => 600,    // 10 minutes
            'files' => 30,       // 30 seconds (files change often)
            'stats' => 10,       // 10 seconds (needs freshness)
            'ssl' => 3600,       // 1 hour (rarely changes)
            'default' => 300,    // 5 minutes default
        ],
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 200,
    ],

    // CORS
    'cors' => [
        'allowed_origins' => ['https://vps.devcon1.hu'],  // Set in config.local.php
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'max_age' => 86400,
    ],

    // Session
    'session' => [
        'lifetime' => 86400, // 24 hours
        'secure' => true,
    ],

    // AI Helper
    'ai_helper' => [
        'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
        'openai_model' => getenv('OPENAI_MODEL') ?: 'gpt-4o', // Default model
        'max_tokens' => 2000,
        'temperature' => 0.3, // Lower for more deterministic responses
        'system_prompt' => 'You are a VPS server diagnostic assistant...',
        // Available OpenAI chat models:
        // 'gpt-4o' - Best overall performance, fast and cost-effective (default)
        // 'gpt-4o-mini' - Fast and cost-effective for simple tasks
        // 'gpt-4-turbo' - Complex reasoning and difficult problems
        // 'gpt-4' - Standard GPT-4 model
        // 'gpt-3.5-turbo' - Fast and economical for basic tasks
    ],

    // Email App integration (for addon cache invalidation webhook)
    'email_app' => [
        'api_url' => '', // e.g. 'https://mail.devcon1.hu/api' — set in config.local.php
        'api_key' => '', // Same shared key as PANEL_API_KEY in the Email App — set in config.local.php
    ],

    // External API Keys (for service-to-service communication)
    // Set actual keys in config.local.php
    'external_api' => [
        'keys' => [
             'email_app' => '', // MUST be set in config.local.php
        ],
    ],
];

