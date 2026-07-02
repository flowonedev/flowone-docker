<?php
/**
 * VPS Admin Agent Configuration
 * 
 * This file contains all configuration for the privileged agent daemon.
 * The agent runs as root and listens on a UNIX socket only.
 */

return [
    // Socket configuration
    'socket' => [
        'path' => '/run/vps-admin/agent.sock',
        'permissions' => 0660,
        'group' => 'www-data',
    ],

    // Paths
    'paths' => [
        'base' => '/var/www/vps-admin',
        'backups' => '/var/www/vps-admin/backups',
        'logs' => '/var/www/vps-admin/logs',
        'ols_vhosts' => '/usr/local/lsws/conf/vhosts',
        'ols_config' => '/usr/local/lsws/conf/httpd_config.conf',
        'ols_bin' => '/usr/local/lsws/bin',
        'ssl_certs' => '/etc/letsencrypt/live',
        'webroot' => '/home',
    ],

    // Allowed services for management
    'allowed_services' => [
        // Core infrastructure
        'lsws',           // OpenLiteSpeed
        'mysql',          // MySQL/MariaDB
        'mariadb',        // MariaDB alternative
        'redis',          // Cache & pub/sub
        'postfix',        // Mail transfer agent
        'dovecot',        // IMAP/POP3 server
        'vpsadmin-agent', // VPS Admin Agent (self-monitoring)
        'fail2ban',       // Intrusion prevention
        'firewalld',      // Firewall
        'pdns',           // PowerDNS
        // Email App services
        'mailsync-server', // Real-time email sync WebSocket (IMAP IDLE, Redis pub/sub) - port 1235
        'collab-server',   // Collaborative document editing WebSocket (Hocuspocus) - port 1234
        'meilisearch',     // Full-text search engine
        'spamd',           // SpamAssassin daemon (native name)
        'spamassassin',    // SpamAssassin daemon (alt name / mail-pod program)
    ],

    // Backup retention
    'backup' => [
        'max_age_days' => 30,
        'max_count' => 100,
    ],

    // Logging
    'logging' => [
        'level' => 'info', // debug, info, warning, error
        'file' => '/var/www/vps-admin/logs/agent.log',
    ],

    // Security
    'security' => [
        'allowed_clients' => ['www-data', 'vpsadmin'],
        'require_auth_token' => true,
    ],

    // Server
    'server' => [
        'ip' => '185.208.227.207',
    ],

    // Database (for app installer tracking)
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'devc_vps_dash',
        'user' => 'devc_vps_dash',
        'pass' => '', // Set via agent config.local.php or env
    ],
];

