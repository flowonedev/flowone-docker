<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Template Service for processing config templates with variable substitution
 */
class TemplateService
{
    private Container $container;
    private EncryptionService $encryption;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->encryption = $container->get(EncryptionService::class);
    }

    /**
     * Process a template string with variables
     */
    public function process(string $template, array $variables): string
    {
        // Templates edited/checked out on Windows can carry CRLF line endings.
        // Every target is a Linux config file where a stray \r corrupts values
        // (OLS extUser, systemd ExecStart, cron lines), so normalize to LF first.
        $processed = str_replace(["\r\n", "\r"], "\n", $template);

        foreach ($variables as $key => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $value = (string) $value;

            $pattern = '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/';

            // preg_replace_callback avoids $0/$1/\1 in $value being treated as backreferences
            $processed = preg_replace_callback($pattern, fn() => $value, $processed);
        }

        return $processed;
    }

    /**
     * Get variables used in a template
     */
    public function extractVariables(string $template): array
    {
        preg_match_all('/\{\{\s*([A-Z_][A-Z0-9_]*)\s*\}\}/', $template, $matches);
        return array_unique($matches[1]);
    }

    /**
     * Find any unresolved {{VAR}} placeholders left in a processed string.
     * Used as a pre-deploy guard so we never write a config that still contains
     * literal "{{VAR}}" (which would break the target service).
     *
     * @return string[] unique placeholder names (without braces)
     */
    public function findUnresolvedPlaceholders(string $processed): array
    {
        preg_match_all('/\{\{\s*([A-Z_][A-Z0-9_]*)\s*\}\}/', $processed, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Validate that all required variables are provided
     */
    public function validateVariables(string $template, array $variables): array
    {
        $required = $this->extractVariables($template);
        $missing = [];

        foreach ($required as $var) {
            if (!isset($variables[$var]) || $variables[$var] === '') {
                $missing[] = $var;
            }
        }

        return $missing;
    }

    /**
     * Generate server variables from database record
     */
    public function generateServerVariables(array $server): array
    {
        // Generate safe username from email domain (e.g., email.devcon1.hu -> email_devcon1)
        $emailUser = $this->generateSafeUsername($server['email_domain']);

        $serverHostname = $this->generateHostname($server['panel_domain']);
        // Mail server FQDN: mirror the production server, which uses the BARE base
        // domain as Postfix myhostname (e.g. devcon1.hu) - NOT a subdomain. Using the
        // panel label ("panel.<base>") produced a HELO name with no matching PTR. The
        // base domain has the A record + reverse DNS the operator controls.
        $baseDomain = preg_replace('/^[^.]+\./', '', $server['panel_domain']);
        if ($baseDomain === '' || strpos($baseDomain, '.') === false) {
            // panel_domain was already a bare base domain (e.g. weddingcards.hu).
            $baseDomain = $server['panel_domain'];
        }
        $serverFqdn = $baseDomain;

        $variables = [
            'SERVER_IP' => $server['ip_address'],
            'SERVER_HOSTNAME' => $serverHostname,
            'SERVER_FQDN' => $serverFqdn,
            'PANEL_DOMAIN' => $server['panel_domain'],
            'EMAIL_DOMAIN' => $server['email_domain'],
            'MAIL_DOMAIN' => $server['mail_domain'] ?? $server['email_domain'],
            'EMAIL_USER' => $emailUser,
            'SSH_PORT' => (string)($server['ssh_port'] ?? 22),
            'ADMIN_EMAIL' => $server['panel_admin_email'] ?? "admin@{$server['mail_domain']}",
        ];

        // Load provisioning DB config (shared panel+email, mail, fleet)
        $schemaService = $this->container->get(SchemaService::class);
        $dbConfig = $schemaService->getDbConfig();

        // Decrypt and add passwords
        if (!empty($server['db_root_password_encrypted'])) {
            $variables['DB_ROOT_PASS'] = $this->encryption->decrypt($server['db_root_password_encrypted']);
        } else {
            $variables['DB_ROOT_PASS'] = $this->encryption->generatePassword(24);
        }

        // Shared DB password for Panel + Email (one DB, one user, one password)
        if (!empty($server['panel_db_password_encrypted'])) {
            $variables['PANEL_DB_PASS'] = $this->encryption->decrypt($server['panel_db_password_encrypted']);
        } else {
            $variables['PANEL_DB_PASS'] = $this->encryption->generatePassword(24);
        }
        // Email uses the same shared DB credentials
        $variables['EMAIL_DB_PASS'] = $variables['PANEL_DB_PASS'];

        // Mail server DB password (separate)
        if (!empty($server['mail_db_password_encrypted'])) {
            $variables['MAIL_DB_PASS'] = $this->encryption->decrypt($server['mail_db_password_encrypted']);
        } else {
            $variables['MAIL_DB_PASS'] = $this->encryption->generatePassword(24);
        }

        // Fleet agent DB password (separate). Persist it here so setupDatabases() and any
        // consumer use the SAME value within a deploy - previously it was generated ad-hoc
        // inside setupDatabases() and never shared, so the created user was unusable.
        if (!empty($server['fleet_db_password_encrypted'] ?? null)) {
            $variables['FLEET_DB_PASS'] = $this->encryption->decrypt($server['fleet_db_password_encrypted']);
        } else {
            $variables['FLEET_DB_PASS'] = $this->encryption->generatePassword(24);
        }

        // Database names and users from provisioning config
        // Panel + Email share the same database (like devc_vps_dash / vpsadmin on main server)
        $sharedDbName = $dbConfig['shared']['target_db'] ?? 'devc_vps_dash';
        $sharedDbUser = $dbConfig['shared']['target_user'] ?? 'vpsadmin';

        $variables['PANEL_DB_NAME'] = $sharedDbName;
        $variables['PANEL_DB_USER'] = $sharedDbUser;
        $variables['EMAIL_DB_NAME'] = $sharedDbName;
        $variables['EMAIL_DB_USER'] = $sharedDbUser;
        $variables['MAIL_DB_NAME'] = $dbConfig['mail']['target_db'] ?? 'mailserver';
        $variables['MAIL_DB_USER'] = $dbConfig['mail']['target_user'] ?? 'mailuser';
        $variables['FLEET_DB_NAME'] = $dbConfig['fleet']['target_db'] ?? 'fleet_agent';
        $variables['FLEET_DB_USER'] = $dbConfig['fleet']['target_user'] ?? 'fleetagent';

        // Generate admin password for panel
        if (!empty($server['panel_admin_password_encrypted'])) {
            $variables['ADMIN_PASS'] = $this->encryption->decrypt($server['panel_admin_password_encrypted']);
        } else {
            $variables['ADMIN_PASS'] = $this->encryption->generatePassword(32);
        }

        // CPGuard license key (optional). Empty string = the install_cpguard
        // provisioning step is a no-op; it can be installed later from the
        // server detail page once the operator has a key for this IP.
        if (!empty($server['cpguard_license_key_encrypted'])) {
            try {
                $variables['CPGUARD_LICENSE_KEY'] = $this->encryption->decrypt($server['cpguard_license_key_encrypted']);
            } catch (\Exception $e) {
                $variables['CPGUARD_LICENSE_KEY'] = '';
            }
        } else {
            $variables['CPGUARD_LICENSE_KEY'] = '';
        }

        // Generate other secrets
        $variables['JWT_SECRET'] = bin2hex(random_bytes(32));
        $variables['ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
        $variables['AGENT_TOKEN'] = $server['agent_token'] ?? $this->encryption->generateToken(32);

        // Shared key: Panel external_api <-> Email App panel.api_key. MUST be
        // stable per server — the email .env render and the panel install run
        // in separate service instances, so a per-call random value hands the
        // two sides different keys and every email->panel API call 401s.
        if (!empty($server['email_api_key_encrypted'] ?? null)) {
            $variables['EMAIL_API_KEY'] = $this->encryption->decrypt($server['email_api_key_encrypted']);
        } else {
            $variables['EMAIL_API_KEY'] = bin2hex(random_bytes(32));
        }

        // Redis password
        if (!empty($server['redis_password_encrypted'])) {
            $variables['REDIS_PASS'] = $this->encryption->decrypt($server['redis_password_encrypted']);
        } else {
            $variables['REDIS_PASS'] = $this->encryption->generatePassword(32);
        }
        $variables['REDIS_MAXMEM'] = '256mb';

        // Meilisearch keys
        if (!empty($server['meili_master_key_encrypted'])) {
            $variables['MEILI_MASTER_KEY'] = $this->encryption->decrypt($server['meili_master_key_encrypted']);
        } else {
            $variables['MEILI_MASTER_KEY'] = bin2hex(random_bytes(16));
        }
        $variables['MEILI_SEARCH_KEY'] = $server['meili_search_key'] ?? ''; // Retrieved after Meilisearch starts

        // LiveKit credentials (for voice/video calling in Email App).
        // Reuse stored keys on re-deploy; otherwise auto-generate so every clone
        // has working calling. Keys persist via storeGeneratedPasswords().
        if (!empty($server['livekit_api_key_encrypted'])) {
            $variables['LIVEKIT_API_KEY'] = $this->encryption->decrypt($server['livekit_api_key_encrypted']);
        } else {
            // LiveKit key convention: "API" + alphanumeric identifier
            $variables['LIVEKIT_API_KEY'] = 'API' . $this->encryption->generatePassword(12);
        }
        if (!empty($server['livekit_api_secret_encrypted'])) {
            $variables['LIVEKIT_API_SECRET'] = $this->encryption->decrypt($server['livekit_api_secret_encrypted']);
        } else {
            $variables['LIVEKIT_API_SECRET'] = $this->encryption->generatePassword(48);
        }
        $variables['LIVEKIT_WS_URL'] = $server['livekit_ws_url'] ?? '';

        // Per-server authoritative nameservers. Empty by default: no NS records
        // are seeded and the box derives display defaults from its own domain —
        // the operator's nameservers are never pushed onto a client server.
        $variables['NS1_DOMAIN'] = trim((string) ($server['ns1_domain'] ?? ''));
        $variables['NS2_DOMAIN'] = trim((string) ($server['ns2_domain'] ?? ''));

        // NAS/VPN variables if enabled
        if (!empty($server['nas_enabled'])) {
            $variables['NAS_ENABLED'] = 'true';
            $variables['NAS_IP'] = $server['nas_ip'] ?? '';
            $variables['NAS_PATH'] = $server['nas_path'] ?? '';
            $variables['NAS_MOUNT'] = $server['nas_mount'] ?? '/mnt/nas-drive';
        } else {
            $variables['NAS_ENABLED'] = 'false';
        }

        if (!empty($server['vpn_enabled'])) {
            $variables['VPN_ENABLED'] = 'true';
        } else {
            $variables['VPN_ENABLED'] = 'false';
        }

        // SSL certificate paths (Let's Encrypt convention)
        $sslDomain = $server['panel_domain'];
        $variables['SSL_CERT_PATH'] = "/etc/letsencrypt/live/{$sslDomain}/fullchain.pem";
        $variables['SSL_KEY_PATH'] = "/etc/letsencrypt/live/{$sslDomain}/privkey.pem";

        // Database host (always localhost for local MariaDB)
        $variables['DB_HOST'] = 'localhost';
        $variables['MAIL_DB_HOST'] = 'localhost';
        $variables['PANEL_DB_HOST'] = 'localhost';
        $variables['DB_BIND_ADDRESS'] = '127.0.0.1';

        // PHP defaults (used by php.ini template)
        $variables['PHP_MEMORY_LIMIT'] = '512M';
        $variables['PHP_MAX_UPLOAD'] = '50M';
        $variables['PHP_TIMEZONE'] = 'UTC';

        // DKIM selector: standard 'mail' (matches setupDKIM). Required by templates
        // such as /etc/opendkim.conf - without it the config deploy is blocked.
        $variables['DKIM_SELECTOR'] = $server['dkim_selector'] ?? 'mail';

        // Generic DB_* aliases. The variable detector emits these (its "_default"
        // context) for configs it can't tie to a specific app - e.g. cron jobs like
        // /etc/cron.daily/aide. Point them at the shared panel DB so those templates
        // resolve instead of blocking the whole deploy on an unresolved {{DB_NAME}}.
        $variables['DB_NAME'] = $sharedDbName;
        $variables['DB_USER'] = $sharedDbUser;
        $variables['DB_PASS'] = $variables['PANEL_DB_PASS'];

        // Common aliases (some templates use _PASSWORD instead of _PASS)
        $variables['MAIL_DB_PASSWORD'] = $variables['MAIL_DB_PASS'];
        $variables['PANEL_DB_PASSWORD'] = $variables['PANEL_DB_PASS'];
        $variables['EMAIL_DB_PASSWORD'] = $variables['EMAIL_DB_PASS'] ?? $variables['PANEL_DB_PASS'];
        $variables['DB_ROOT_PASSWORD'] = $variables['DB_ROOT_PASS'];
        $variables['DB_PASSWORD'] = $variables['PANEL_DB_PASS'];
        $variables['REDIS_PASSWORD'] = $variables['REDIS_PASS'];
        $variables['ADMIN_PASSWORD'] = $variables['ADMIN_PASS'];

        // --- Docker deploy crypto (non-regenerable) ---
        // Loaded here only if already persisted (migration 026). On a FRESH box the
        // Docker provisioner mints + persists these via ServerSecretGenerator +
        // persistDockerSecrets(); on a MIGRATED box restore.sh writes the real keys.
        // The NATIVE path ignores these entirely (install.sh mints its own on-box),
        // so we do NOT generate here -- that would burn an RSA keygen on every call.
        if (!empty($server['imap_encryption_key_encrypted'] ?? null)) {
            $variables['IMAP_ENCRYPTION_KEY'] = $this->encryption->decrypt($server['imap_encryption_key_encrypted']);
        }
        if (!empty($server['ai_encryption_key_encrypted'] ?? null)) {
            $variables['AI_ENCRYPTION_KEY'] = $this->encryption->decrypt($server['ai_encryption_key_encrypted']);
        }
        if (!empty($server['sso_server_key_encrypted'] ?? null)) {
            $variables['SSO_SERVER_KEY'] = $this->encryption->decrypt($server['sso_server_key_encrypted']);
        }
        if (!empty($server['jwt_private_key_encrypted'] ?? null)) {
            $variables['JWT_PRIVATE_KEY_PEM'] = $this->encryption->decrypt($server['jwt_private_key_encrypted']);
        }
        if (!empty($server['jwt_public_key'] ?? null)) {
            $variables['JWT_PUBLIC_KEY_PEM'] = $server['jwt_public_key'];
        }
        // VAPID (web push) — subscriptions are bound to the public key, so it
        // must never rotate across re-provisions (migration 031).
        if (!empty($server['vapid_public_key'] ?? null)) {
            $variables['VAPID_PUBLIC_KEY'] = $server['vapid_public_key'];
        }
        if (!empty($server['vapid_private_key_encrypted'] ?? null)) {
            $variables['VAPID_PRIVATE_KEY'] = $this->encryption->decrypt($server['vapid_private_key_encrypted']);
        }

        return $variables;
    }

    /**
     * Persist the Docker-deploy crypto for a server (encrypted, except the JWT
     * public key). COALESCE means we only fill columns that are still NULL, so
     * this is idempotent and never rotates a key that already exists -- reusing
     * IMAP_ENCRYPTION_KEY / the JWT pair across re-provisions is mandatory
     * (rotating them bricks stored passwords / logs everyone out).
     */
    public function persistDockerSecrets(int $serverId, array $variables): void
    {
        $db = $this->container->getDatabase();
        // COALESCE(existing, new): persist a value only on the FIRST provision, then
        // reuse it forever. This covers BOTH the non-regenerable docker crypto
        // (IMAP/AI/SSO/JWT) AND the "regenerable but must stay stable" credentials
        // (DB/Redis/Meili/admin passwords). The latter are baked into persistent
        // volumes on first `up` — mariadb-init only runs on an empty data volume —
        // so if generateServerVariables() minted fresh ones on a re-provision or
        // Docker Update, the new .env would no longer match the on-disk databases
        // and every DB/auth would break. Parity with the native path's
        // storeGeneratedPasswords().
        $stmt = $db->prepare(
            "UPDATE servers SET
                imap_encryption_key_encrypted = COALESCE(imap_encryption_key_encrypted, ?),
                ai_encryption_key_encrypted   = COALESCE(ai_encryption_key_encrypted, ?),
                sso_server_key_encrypted      = COALESCE(sso_server_key_encrypted, ?),
                email_api_key_encrypted       = COALESCE(email_api_key_encrypted, ?),
                jwt_private_key_encrypted     = COALESCE(jwt_private_key_encrypted, ?),
                jwt_public_key                = COALESCE(jwt_public_key, ?),
                vapid_public_key              = COALESCE(vapid_public_key, ?),
                vapid_private_key_encrypted   = COALESCE(vapid_private_key_encrypted, ?),
                db_root_password_encrypted    = COALESCE(db_root_password_encrypted, ?),
                panel_db_password_encrypted   = COALESCE(panel_db_password_encrypted, ?),
                email_db_password_encrypted   = COALESCE(email_db_password_encrypted, ?),
                mail_db_password_encrypted    = COALESCE(mail_db_password_encrypted, ?),
                redis_password_encrypted      = COALESCE(redis_password_encrypted, ?),
                meili_master_key_encrypted    = COALESCE(meili_master_key_encrypted, ?),
                panel_admin_password_encrypted = COALESCE(panel_admin_password_encrypted, ?)
             WHERE id = ?"
        );
        $enc = fn($v) => !empty($v) ? $this->encryption->encrypt($v) : null;
        $stmt->execute([
            $enc($variables['IMAP_ENCRYPTION_KEY'] ?? null),
            $enc($variables['AI_ENCRYPTION_KEY'] ?? null),
            $enc($variables['SSO_SERVER_KEY'] ?? null),
            $enc($variables['EMAIL_API_KEY'] ?? null),
            $enc($variables['JWT_PRIVATE_KEY_PEM'] ?? null),
            !empty($variables['JWT_PUBLIC_KEY_PEM']) ? $variables['JWT_PUBLIC_KEY_PEM'] : null,
            !empty($variables['VAPID_PUBLIC_KEY']) ? $variables['VAPID_PUBLIC_KEY'] : null,
            $enc($variables['VAPID_PRIVATE_KEY'] ?? null),
            $enc($variables['DB_ROOT_PASS'] ?? null),
            $enc($variables['PANEL_DB_PASS'] ?? null),
            $enc($variables['EMAIL_DB_PASS'] ?? null),
            $enc($variables['MAIL_DB_PASS'] ?? null),
            $enc($variables['REDIS_PASS'] ?? null),
            $enc($variables['MEILI_MASTER_KEY'] ?? null),
            $enc($variables['ADMIN_PASS'] ?? null),
            $serverId,
        ]);
    }

    /**
     * Generate hostname from domain
     */
    private function generateHostname(string $domain): string
    {
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            // Return first part as hostname (e.g., "panel" from "panel.example.com")
            return $parts[0];
        }
        return $domain;
    }

    /**
     * Generate a safe Linux username from a domain
     * e.g., email.devcon1.hu -> email_devcon1
     */
    private function generateSafeUsername(string $domain): string
    {
        // Remove www. prefix if present
        $domain = preg_replace('/^www\./', '', $domain);
        
        // Split into parts
        $parts = explode('.', $domain);
        
        // Take first two parts (subdomain + domain name without TLD)
        if (count($parts) >= 3) {
            // email.devcon1.hu -> email_devcon1
            $username = $parts[0] . '_' . $parts[1];
        } elseif (count($parts) >= 2) {
            // devcon1.hu -> devcon1
            $username = $parts[0];
        } else {
            $username = $domain;
        }
        
        // Make safe for Linux: lowercase, replace non-alphanumeric with underscore
        $username = strtolower($username);
        $username = preg_replace('/[^a-z0-9_]/', '_', $username);
        $username = preg_replace('/_+/', '_', $username); // Collapse multiple underscores
        $username = trim($username, '_');
        
        // Ensure it starts with a letter (Linux username requirement)
        if (!preg_match('/^[a-z]/', $username)) {
            $username = 'u_' . $username;
        }
        
        // Limit length (Linux max is 32, but keep shorter for readability)
        $username = substr($username, 0, 24);
        
        return $username;
    }

    /**
     * App config files that must NEVER be deployed from a blueprint: they carry the
     * SOURCE server's JWT secrets, encryption keys and DB credentials. The panel/email/
     * fleet install.sh scripts regenerate these with fresh, per-server secrets, so the
     * extracted copies are both redundant and a cross-server secret leak.
     */
    private const SECRET_APP_CONFIG_PATHS = [
        '/var/www/vps-admin/api/config.local.php',
        '/var/www/vps-admin/api/config.php',
        '/var/www/vps-email/backend/src/config.php',
        '/var/www/vps-fleet/api/config.local.php',
        '/var/www/vps-fleet/api/config.php',
    ];

    /**
     * Process all templates for a blueprint
     */
    public function processBlueprintTemplates(int $blueprintId, array $variables): array
    {
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT * FROM blueprint_templates WHERE blueprint_id = ? ORDER BY category");
        $stmt->execute([$blueprintId]);
        $templates = $stmt->fetchAll();

        $processed = [];

        foreach ($templates as $template) {
            // Skip optional templates if module not enabled
            if ($template['is_optional'] && !empty($template['requires_module'])) {
                $moduleVar = strtoupper($template['requires_module']) . '_ENABLED';
                if (($variables[$moduleVar] ?? 'false') !== 'true') {
                    continue;
                }
            }

            $content = $this->process($template['content'], $variables);
            $targetPath = $this->process($template['target_path'], $variables);

            // Command-output captures (target_path like "[command: apt list ...]")
            // and any other non-file entries are reference snapshots from extraction,
            // NOT deployable configs. They must never be written to disk and must not
            // reach the unresolved-placeholder guard. Real configs are absolute paths.
            if ($targetPath === '' || $targetPath[0] !== '/') {
                continue;
            }

            // Never deploy app secret configs from a blueprint - the app installers
            // regenerate them with fresh per-server secrets. Deploying the extracted
            // source copy would leak the source server's JWT/encryption/DB secrets.
            if (in_array($targetPath, self::SECRET_APP_CONFIG_PATHS, true)) {
                error_log("TemplateService: skipping secret app config from blueprint (regenerated by installer): {$targetPath}");
                continue;
            }

            // Strip tenant-specific entries out of cloned configs. The new server has
            // its own sites, so the source server's per-site backup schedules (and any
            // other site-scoped cron) must not be cloned - only config backups + the
            // generic cron framework survive. See stripTenantCronEntries().
            $content = $this->stripTenantCronEntries($targetPath, $content);

            $processed[] = [
                'id' => $template['id'],
                'category' => $template['category'],
                'filename' => $template['filename'],
                'target_path' => $targetPath,
                'content' => $content,
                'permissions' => $template['permissions'],
                'owner' => $template['owner'],
                'group' => $template['group_name'],
            ];
        }

        return $processed;
    }

    /**
     * Remove tenant-scoped entries from cloned config files before they are
     * deployed to a fresh server.
     *
     * The fleet clones cron (intentionally), but the source server's backup
     * cron (/etc/cron.d/vps-admin-backups) contains per-SITE backup schedules
     * (backup-runner.php --sites=<domain> ...) for the source's own tenants.
     * Those domains do not exist on the new box, so the schedules are noise in
     * the panel's Backups page and would fail every run. We keep:
     *   - the cron framework lines (SHELL=, PATH=, MAILTO=, comments, blanks)
     *   - CONFIG backup schedules (backup-runner.php --categories=...)
     * and drop any line that runs a SITE backup (--sites=).
     *
     * Returns the (possibly) filtered content unchanged for every other path.
     */
    private function stripTenantCronEntries(string $targetPath, string $content): string
    {
        if ($targetPath !== '/etc/cron.d/vps-admin-backups') {
            return $content;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return $content;
        }

        $kept = [];
        $dropped = 0;
        foreach ($lines as $line) {
            // A site backup schedule is a cron line invoking the backup runner
            // with --sites=. Drop it; keep everything else (incl. --categories=).
            if (preg_match('/backup-runner\.php.*--sites=/', $line)) {
                $dropped++;
                continue;
            }
            $kept[] = $line;
        }

        if ($dropped > 0) {
            error_log("TemplateService: stripped {$dropped} cloned per-site backup schedule(s) from {$targetPath}");
        }

        return implode("\n", $kept);
    }

    /**
     * Preview template with variables
     */
    public function previewTemplate(string $template, array $variables): array
    {
        $processed = $this->process($template, $variables);
        $usedVariables = $this->extractVariables($template);
        $missingVariables = $this->validateVariables($template, $variables);

        return [
            'original' => $template,
            'processed' => $processed,
            'variables_used' => $usedVariables,
            'variables_missing' => $missingVariables,
            'is_complete' => empty($missingVariables),
        ];
    }

    /**
     * Create default variables definition
     */
    public function getDefaultVariableDefinitions(): array
    {
        return [
            [
                'name' => 'SERVER_IP',
                'label' => 'Server IP Address',
                'type' => 'text',
                'required' => true,
                'source' => 'auto-detect',
                'description' => 'Public IP address of the server',
            ],
            [
                'name' => 'SERVER_HOSTNAME',
                'label' => 'Server Hostname',
                'type' => 'text',
                'required' => true,
                'source' => 'auto-detect',
                'description' => 'Hostname for the server',
            ],
            [
                'name' => 'PANEL_DOMAIN',
                'label' => 'Panel Domain',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'panel.example.com',
                'description' => 'Domain for the VPS Admin Panel',
            ],
            [
                'name' => 'EMAIL_DOMAIN',
                'label' => 'Email App Domain',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'email.example.com',
                'description' => 'Domain for the MailFlow webmail',
            ],
            [
                'name' => 'MAIL_DOMAIN',
                'label' => 'Mail Domain',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'example.com',
                'description' => 'Domain for email addresses (@example.com)',
            ],
            [
                'name' => 'ADMIN_EMAIL',
                'label' => 'Admin Email',
                'type' => 'email',
                'required' => true,
                'description' => 'Email for the admin account',
            ],
            [
                'name' => 'DB_ROOT_PASS',
                'label' => 'Database Root Password',
                'type' => 'password',
                'required' => true,
                'generate' => true,
                'description' => 'MariaDB root password',
            ],
            [
                'name' => 'PANEL_DB_PASS',
                'label' => 'Panel Database Password',
                'type' => 'password',
                'required' => true,
                'generate' => true,
                'description' => 'Password for panel database user',
            ],
            [
                'name' => 'EMAIL_DB_PASS',
                'label' => 'Email App Database Password',
                'type' => 'password',
                'required' => true,
                'generate' => true,
                'description' => 'Password for email app database user',
            ],
            [
                'name' => 'MAIL_DB_PASS',
                'label' => 'Mail Server Database Password',
                'type' => 'password',
                'required' => true,
                'generate' => true,
                'description' => 'Password for Postfix/Dovecot database user',
            ],
            [
                'name' => 'JWT_SECRET',
                'label' => 'JWT Secret',
                'type' => 'password',
                'required' => true,
                'generate' => true,
                'description' => 'Secret key for JWT tokens',
            ],
            [
                'name' => 'NAS_ENABLED',
                'label' => 'Enable NAS Storage',
                'type' => 'toggle',
                'default' => false,
                'description' => 'Enable NAS integration for mail storage',
            ],
            [
                'name' => 'NAS_IP',
                'label' => 'NAS IP Address',
                'type' => 'text',
                'required_if' => 'NAS_ENABLED',
                'placeholder' => '10.8.0.1',
                'description' => 'NAS IP address (VPN tunnel IP)',
            ],
            [
                'name' => 'NAS_PATH',
                'label' => 'NAS Export Path',
                'type' => 'text',
                'required_if' => 'NAS_ENABLED',
                'placeholder' => '/volume1/mailflow',
                'description' => 'NFS export path on NAS',
            ],
            [
                'name' => 'VPN_ENABLED',
                'label' => 'Enable OpenVPN',
                'type' => 'toggle',
                'default' => false,
                'description' => 'Enable OpenVPN for secure NAS connection',
            ],
            [
                'name' => 'NS1_DOMAIN',
                'label' => 'Primary Nameserver',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'ns1.example.com',
                'description' => 'Primary NS hostname (leave empty to skip NS records)',
            ],
            [
                'name' => 'NS2_DOMAIN',
                'label' => 'Secondary Nameserver',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'ns2.example.com',
                'description' => 'Secondary NS hostname',
            ],
            [
                'name' => 'LIVEKIT_API_KEY',
                'label' => 'LiveKit API Key',
                'type' => 'text',
                'required' => false,
                'description' => 'LiveKit API key for voice/video calling (leave empty to disable)',
            ],
            [
                'name' => 'LIVEKIT_API_SECRET',
                'label' => 'LiveKit API Secret',
                'type' => 'password',
                'required_if' => 'LIVEKIT_API_KEY',
                'description' => 'LiveKit API secret',
            ],
            [
                'name' => 'LIVEKIT_WS_URL',
                'label' => 'LiveKit WebSocket URL',
                'type' => 'text',
                'required_if' => 'LIVEKIT_API_KEY',
                'placeholder' => 'wss://livekit.example.com',
                'description' => 'LiveKit server WebSocket URL',
            ],
        ];
    }
}

