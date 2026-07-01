<?php
/**
 * Fleet Manager API Configuration
 */

return [
    // Application
    'app' => [
        'name' => 'DEVCON Fleet Manager',
        'debug' => false,
        'url' => 'https://fleet.devcon1.hu',
    ],

    // Database
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'fleet_manager',
        'user' => 'fleet_manager',
        'password' => '', // Set this in config.local.php
        'charset' => 'utf8mb4',
    ],

    // JWT Authentication
    'jwt' => [
        'secret' => '', // Set this in config.local.php
        'algorithm' => 'HS256',
        'expiry' => 86400, // 24 hours
        'refresh_expiry' => 604800, // 7 days
    ],

    // Encryption (for storing sensitive data)
    'encryption' => [
        'key' => '', // Set this in config.local.php (32 bytes, base64 encoded)
        'method' => 'aes-256-gcm',
    ],

    // SSH Defaults
    'ssh' => [
        'default_port' => 22,
        'timeout' => 30,
        // Where the Fleet Manager stores per-server pxr management keys. MUST be
        // writable by the web user (www-data). The app lives at /var/www/vps-fleet
        // and copy-fleet.sh creates + chowns /var/www/vps-fleet/var, so keys belong
        // under it. (The old /var/www/fleet-manager/keys/ was unwritable -> keys
        // were silently lost and hardened boxes became unmanageable.)
        'key_path' => '/var/www/vps-fleet/var/keys/',

        // SSH hardening applied at the end of a full provision.
        'harden' => true,            // set false to skip the harden_ssh step entirely
        'harden_port' => 1985,       // sshd is moved here; root login is then denied

        // Default PUBLIC key authorized for the unprivileged "pxr" user on EVERY
        // cloned server (so you can SSH/SFTP in). Override per-server from the
        // dashboard. The Fleet Manager additionally authorizes its own internal
        // management key so it can keep managing the box after root is denied.
        'pxr_authorized_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICjXaLjLqR8gin2iTxy21uaMv0JihhCLbqm8epukCrMj vps-sftp-access',

        // Fleet-wide MANAGEMENT PRIVATE key: the private half of pxr_authorized_key
        // (e.g. your vps-sftp-access key). When set, the Fleet Manager uses it to
        // connect as pxr on ANY hardened box - so it is never locked out even if a
        // per-server key is lost or the panel record was re-added.
        //
        // PREFERRED: paste/rotate this key from the dashboard (Settings -> Fleet
        // Access). That stores it encrypted in the DB and takes precedence over the
        // values below, so you can swap a compromised key instantly without editing
        // files. The config-file path below remains only as an optional fallback.
        //   'management_key_path'       => '/var/www/vps-fleet/var/keys/operator_key',
        //   'management_key_passphrase' => '...'   // omit/empty if the key has none
        'management_key_path' => '',
        'management_key_passphrase' => '',
    ],

    // Deployment packages
    'packages' => [
        'path' => __DIR__ . '/../packages/',
        'panel' => 'panel/panel-latest.tar.gz',
        'email' => 'email/email-latest.tar.gz',
        'agent' => 'agent/agent-latest.tar.gz',
        // FlowOne shared library (flowone/storage). Build with packages/shared/build.sh
        // on a host that has /var/www/shared; deployment is skipped if absent.
        'shared' => 'shared/shared-latest.tar.gz',
        'max_size' => 104857600, // 100MB
    ],

    // Templates storage
    'templates' => [
        'path' => __DIR__ . '/../templates/',
    ],

    // Docker Compose provisioning (native->docker migration, Phase D).
    // Consumed by DockerProvisioningService: `registry`/`tag` are injected into
    // the per-host .env (compose pulls ${REGISTRY}/flowone-<svc>:${TAG});
    // `compose_path` is the docker-compose.yml uploaded to the target.
    'docker' => [
        // Image registry/namespace. Publish with email/docker/build-and-push.sh.
        // GHCR is free for private images at fleet scale (1-month notice before
        // any metering). Override here or in config.local.php / via env.
        'registry' => getenv('DOCKER_REGISTRY') ?: 'ghcr.io/flowonedev',
        'tag'      => getenv('DOCKER_TAG') ?: 'latest',
        // Canonical compose file. Repo-relative default resolves in this
        // monorepo; on the Fleet server set an absolute path in config.local.php
        // (or bundle the file alongside the deployed Fleet code).
        'compose_path' => __DIR__ . '/../../email/docker/docker-compose.yml',
    ],

    // Provisioning database structure
    // Defines which databases to create on new servers and where to dump schemas from
    'provisioning' => [
        'databases' => [
            // Panel + Email share this database (like devc_vps_dash on main server)
            'shared' => [
                'source_db' => 'devc_vps_dash',
                'target_db' => 'devc_vps_dash',
                'target_user' => 'vpsadmin',
            ],
            // Mail server database (postfix/dovecot)
            // source_user/source_pass: set in config.local.php if the mailserver DB needs specific credentials for dump
            'mail' => [
                'source_db' => 'mailserver',
                'target_db' => 'mailserver',
                'target_user' => 'mailuser',
                'source_user' => '', // e.g. 'mailuser' - set in config.local.php
                'source_pass' => '', // set in config.local.php
            ],
            // Fleet agent database
            // source_db should match the actual DB name on the main server (set in config.local.php)
            // target_db is what gets created on provisioned servers
            'fleet' => [
                'source_db' => 'fleet_manager',
                'target_db' => 'fleet_manager',
                'target_user' => 'fleet_manager',
            ],
        ],
    ],

    // Server agent settings (remote servers reporting to Fleet)
    'server_agent' => [
        'heartbeat_interval' => 60, // seconds
        'health_retention_days' => 30,
        'error_retention_days' => 90,
    ],

    // Local agent daemon (for local server extraction)
    'agent' => [
        'socket' => '/run/fleet-manager/agent.sock',
        'token_file' => '/var/www/vps-fleet/var/agent.token',
        'timeout' => 300, // 5 minutes for extractions
    ],

    // CORS
    'cors' => [
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-Agent-Token'],
        'max_age' => 86400,
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 120,
    ],
];

