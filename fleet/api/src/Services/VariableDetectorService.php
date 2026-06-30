<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Service to detect sensitive values in config content
 * and convert them to template variables
 */
class VariableDetectorService
{
    private Container $container;

    // Patterns for detecting different types of sensitive data
    // Each pattern captures the value to be replaced
    private const DETECTION_PATTERNS = [
        // Database credentials - various formats
        'db_password' => [
            '/(?:password|passwd|pass)\s*[=:]\s*["\']?([^"\'\s\r\n]+)["\']?/i',
            '/connect_info\s*=.*password=([^&\s"\']+)/i',
            '/-p([^\s"\']+)/i', // MySQL CLI password
        ],
        'db_user' => [
            '/(?:user|username|db_user)\s*[=:]\s*["\']?([^"\'\s\r\n@]+)["\']?/i',
            '/connect_info\s*=.*user=([^&\s"\']+)/i',
        ],
        'db_name' => [
            '/(?:dbname|database|db_name)\s*[=:]\s*["\']?([^"\'\s\r\n]+)["\']?/i',
            '/connect_info\s*=.*dbname=([^&\s"\']+)/i',
        ],
        'db_host' => [
            '/(?:hosts?|server|db_host)\s*[=:]\s*["\']?([a-zA-Z0-9._-]+)["\']?/i',
            '/connect_info\s*=.*host=([^&\s"\']+)/i',
        ],
        
        // Network identifiers
        'ip_address' => [
            '/\b((?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?))\b/',
        ],
        'domain' => [
            '/(?:mydomain|myhostname|mail_name|servername|server_name)\s*[=:]\s*["\']?([a-z0-9][a-z0-9.-]*\.[a-z]{2,})["\']?/i',
            '/(?:relay_domains|virtual_mailbox_domains)\s*[=:]\s*["\']?([a-z0-9][a-z0-9.-]*\.[a-z]{2,})["\']?/i',
        ],
        
        // SSL/TLS paths
        'ssl_cert' => [
            '/(?:ssl_cert(?:ificate)?|smtpd_tls_cert_file|ssl_cert_file)\s*[=:<]\s*["\']?([^\s"\'>\r\n]+\.(?:pem|crt))["\']?/i',
            '/(?:SSLCertificateFile|ssl-cert)\s+["\']?([^\s"\'>\r\n]+)["\']?/i',
        ],
        'ssl_key' => [
            '/(?:ssl_key|smtpd_tls_key_file|ssl_key_file)\s*[=:<]\s*["\']?([^\s"\'>\r\n]+\.(?:pem|key))["\']?/i',
            '/(?:SSLCertificateKeyFile|ssl-key)\s+["\']?([^\s"\'>\r\n]+)["\']?/i',
        ],
        
        // DKIM specific
        'dkim_selector' => [
            '/(?:Selector|selector)\s+["\']?([a-z0-9_-]+)["\']?/i',
            '/KeyTable.*?([a-z0-9_-]+)\._domainkey/i',
        ],
        
        // Email addresses
        'email' => [
            '/(?:root_email|admin_email|postmaster)\s*[=:]\s*["\']?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})["\']?/i',
        ],
    ];

    // Variable groupings with context-aware naming
    private const VARIABLE_CONTEXTS = [
        'postfix' => [
            'db_password' => 'MAIL_DB_PASSWORD',
            'db_user' => 'MAIL_DB_USER',
            'db_name' => 'MAIL_DB_NAME',
            'db_host' => 'MAIL_DB_HOST',
        ],
        'dovecot' => [
            'db_password' => 'MAIL_DB_PASSWORD',
            'db_user' => 'MAIL_DB_USER',
            'db_name' => 'MAIL_DB_NAME',
            'db_host' => 'MAIL_DB_HOST',
        ],
        'panel' => [
            'db_password' => 'PANEL_DB_PASSWORD',
            'db_user' => 'PANEL_DB_USER',
            'db_name' => 'PANEL_DB_NAME',
            'db_host' => 'PANEL_DB_HOST',
        ],
        'email' => [
            'db_password' => 'EMAIL_DB_PASSWORD',
            'db_user' => 'EMAIL_DB_USER',
            'db_name' => 'EMAIL_DB_NAME',
            'db_host' => 'EMAIL_DB_HOST',
        ],
        '_default' => [
            'db_password' => 'DB_PASSWORD',
            'db_user' => 'DB_USER',
            'db_name' => 'DB_NAME',
            'db_host' => 'DB_HOST',
            'ip_address' => 'SERVER_IP',
            'domain' => 'MAIL_DOMAIN',
            'ssl_cert' => 'SSL_CERT_PATH',
            'ssl_key' => 'SSL_KEY_PATH',
            'dkim_selector' => 'DKIM_SELECTOR',
            'email' => 'ADMIN_EMAIL',
        ],
    ];

    // Values to ignore (common defaults, not server-specific)
    private const IGNORE_VALUES = [
        // IPs
        '127.0.0.1',
        'localhost',
        '0.0.0.0',
        '::1',
        // Common usernames that shouldn't be replaced
        'root',
        'www-data',
        'nobody',
        'mysql',
        'postfix',
        'dovecot',
        'vmail',
        'opendkim',
        // Common paths
        '/dev/null',
        '/var/log',
        '/tmp',
        // Common DB names that are generic
        'mysql',
        'information_schema',
        'performance_schema',
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Detect all variables in extracted config data
     * Returns detected values organized by variable name
     */
    public function detectVariables(array $extractedData): array
    {
        $serverInfo = $extractedData['server_info'] ?? [];
        $extracted = $extractedData['extracted'] ?? [];

        // Initialize with server info
        $detected = [
            'SERVER_IP' => $serverInfo['ip'] ?? null,
            'SERVER_HOSTNAME' => $serverInfo['hostname'] ?? null,
        ];

        // Track where each value was found (for UI feedback)
        $foundIn = [];

        // Scan each category's files
        foreach ($extracted as $category => $data) {
            foreach ($data['files'] ?? [] as $file) {
                if (empty($file['content']) || ($file['dry_run'] ?? false)) {
                    continue;
                }

                $context = $this->determineContext($category, $file['path'] ?? '');
                $fileDetections = $this->scanContent($file['content'], $context);

                foreach ($fileDetections as $varName => $value) {
                    if (!isset($detected[$varName]) || $detected[$varName] === null) {
                        $detected[$varName] = $value;
                    }
                    
                    // Track which files contain this variable
                    $foundIn[$varName][] = [
                        'category' => $category,
                        'file' => $file['path'] ?? $file['filename'] ?? 'unknown',
                    ];
                }
            }
        }

        // Build variable definitions with metadata
        $definitions = $this->buildVariableDefinitions($detected, $foundIn);

        return [
            'detected' => $detected,
            'definitions' => $definitions,
            'found_in' => $foundIn,
        ];
    }

    /**
     * Scan content for sensitive values
     */
    private function scanContent(string $content, string $context): array
    {
        $detected = [];

        foreach (self::DETECTION_PATTERNS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[1] as $value) {
                        // Skip ignored values
                        if ($this->shouldIgnore($value)) {
                            error_log("VariableDetector: Ignoring value '{$value}' for type '{$type}'");
                            continue;
                        }

                        // Determine variable name based on context
                        $varName = $this->getVariableName($type, $context);
                        
                        // For IPs, only capture non-private ranges as SERVER_IP
                        if ($type === 'ip_address') {
                            if ($this->isPublicIP($value)) {
                                $detected[$varName] = $value;
                                error_log("VariableDetector: Detected IP '{$value}' as {$varName}");
                            }
                        } else {
                            $detected[$varName] = $value;
                            error_log("VariableDetector: Detected '{$value}' as {$varName} (type: {$type}, context: {$context})");
                        }
                    }
                }
            }
        }

        return $detected;
    }

    /**
     * Determine context from category and file path
     */
    private function determineContext(string $category, string $filePath): string
    {
        // Check file path for specific contexts
        $pathLower = strtolower($filePath);
        
        if (str_contains($pathLower, 'postfix') || str_contains($pathLower, '/etc/postfix')) {
            return 'postfix';
        }
        if (str_contains($pathLower, 'dovecot')) {
            return 'dovecot';
        }
        if (str_contains($pathLower, 'panel') || str_contains($pathLower, 'vps-panel')) {
            return 'panel';
        }
        if (str_contains($pathLower, 'email-app') || str_contains($pathLower, 'vps-email') || str_contains($pathLower, 'mailflow')) {
            return 'email';
        }

        // Fall back to category
        return match($category) {
            'postfix' => 'postfix',
            'dovecot' => 'dovecot',
            default => '_default',
        };
    }

    /**
     * Get variable name based on type and context
     */
    private function getVariableName(string $type, string $context): string
    {
        // Check context-specific mapping first
        if (isset(self::VARIABLE_CONTEXTS[$context][$type])) {
            return self::VARIABLE_CONTEXTS[$context][$type];
        }

        // Fall back to default mapping
        return self::VARIABLE_CONTEXTS['_default'][$type] ?? strtoupper($type);
    }

    /**
     * Check if value should be ignored
     */
    private function shouldIgnore(string $value): bool
    {
        $valueLower = strtolower(trim($value));
        
        foreach (self::IGNORE_VALUES as $ignore) {
            if ($valueLower === strtolower($ignore)) {
                return true;
            }
        }

        // Ignore very short values (likely not real credentials)
        if (strlen($value) < 3) {
            return true;
        }

        // Ignore common boolean/placeholder values
        if (in_array($valueLower, ['yes', 'no', 'true', 'false', 'on', 'off', 'none', 'null', 'empty'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if IP is public (not private/local)
     */
    private function isPublicIP(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        // Private ranges
        $privateRanges = [
            [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
            [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
            [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
        ];

        foreach ($privateRanges as [$start, $end]) {
            if ($long >= $start && $long <= $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build variable definitions with type and metadata
     */
    private function buildVariableDefinitions(array $detected, array $foundIn): array
    {
        $definitions = [
            // Core server variables
            ['name' => 'SERVER_IP', 'label' => 'Server IP Address', 'type' => 'text', 'required' => true, 'category' => 'server'],
            ['name' => 'SERVER_HOSTNAME', 'label' => 'Server Hostname', 'type' => 'text', 'required' => true, 'category' => 'server'],
            
            // Domain variables
            ['name' => 'PANEL_DOMAIN', 'label' => 'Panel Domain', 'type' => 'text', 'required' => true, 'category' => 'domains', 'placeholder' => 'panel.example.com'],
            ['name' => 'EMAIL_DOMAIN', 'label' => 'Email App Domain', 'type' => 'text', 'required' => true, 'category' => 'domains', 'placeholder' => 'email.example.com'],
            ['name' => 'MAIL_DOMAIN', 'label' => 'Mail Domain', 'type' => 'text', 'required' => true, 'category' => 'domains', 'placeholder' => 'example.com'],
            
            // Mail server database
            ['name' => 'MAIL_DB_HOST', 'label' => 'Mail DB Host', 'type' => 'text', 'required' => true, 'category' => 'mail_database', 'default' => 'localhost'],
            ['name' => 'MAIL_DB_NAME', 'label' => 'Mail DB Name', 'type' => 'text', 'required' => true, 'category' => 'mail_database', 'default' => 'mailserver'],
            ['name' => 'MAIL_DB_USER', 'label' => 'Mail DB User', 'type' => 'text', 'required' => true, 'category' => 'mail_database', 'default' => 'mailuser'],
            ['name' => 'MAIL_DB_PASSWORD', 'label' => 'Mail DB Password', 'type' => 'password', 'required' => true, 'category' => 'mail_database', 'generate' => true],
            
            // Shared database (Panel + Email use the same DB)
            ['name' => 'PANEL_DB_HOST', 'label' => 'App DB Host', 'type' => 'text', 'required' => false, 'category' => 'app_database', 'default' => 'localhost'],
            ['name' => 'PANEL_DB_NAME', 'label' => 'App DB Name', 'type' => 'text', 'required' => false, 'category' => 'app_database', 'default' => 'devc_vps_dash'],
            ['name' => 'PANEL_DB_USER', 'label' => 'App DB User', 'type' => 'text', 'required' => false, 'category' => 'app_database', 'default' => 'vpsadmin'],
            ['name' => 'PANEL_DB_PASSWORD', 'label' => 'App DB Password', 'type' => 'password', 'required' => false, 'category' => 'app_database', 'generate' => true],
            
            // SSL
            ['name' => 'SSL_CERT_PATH', 'label' => 'SSL Certificate Path', 'type' => 'text', 'required' => false, 'category' => 'ssl', 'placeholder' => '/etc/letsencrypt/live/example.com/fullchain.pem'],
            ['name' => 'SSL_KEY_PATH', 'label' => 'SSL Key Path', 'type' => 'text', 'required' => false, 'category' => 'ssl', 'placeholder' => '/etc/letsencrypt/live/example.com/privkey.pem'],
            
            // DKIM
            ['name' => 'DKIM_SELECTOR', 'label' => 'DKIM Selector', 'type' => 'text', 'required' => false, 'category' => 'dkim', 'default' => 'mail'],
            
            // Admin
            ['name' => 'ADMIN_EMAIL', 'label' => 'Admin Email', 'type' => 'email', 'required' => false, 'category' => 'admin'],

            // Email App login mailbox — a real IMAP account is auto-created at the
            // deployed base domain (e.g. robert@devcon2.hu) so the Email app has a
            // working login. Local part only; the domain is derived from MAIL_DOMAIN.
            ['name' => 'MAIL_LOGIN_USER', 'label' => 'Email Login Mailbox (local part)', 'type' => 'text', 'required' => false, 'category' => 'admin', 'default' => 'robert', 'placeholder' => 'robert'],
            ['name' => 'MAIL_LOGIN_PASS', 'label' => 'Email Login Password (blank = panel admin password)', 'type' => 'password', 'required' => false, 'category' => 'admin'],
            
            // Root DB password (for initial setup)
            ['name' => 'DB_ROOT_PASSWORD', 'label' => 'Database Root Password', 'type' => 'password', 'required' => true, 'category' => 'database', 'generate' => true],

            // Redis
            ['name' => 'REDIS_PASS', 'label' => 'Redis Password', 'type' => 'password', 'required' => false, 'category' => 'redis', 'generate' => true],
            ['name' => 'REDIS_MAXMEM', 'label' => 'Redis Max Memory', 'type' => 'text', 'required' => false, 'category' => 'redis', 'default' => '256mb'],

            // Meilisearch
            ['name' => 'MEILI_MASTER_KEY', 'label' => 'Meilisearch Master Key', 'type' => 'password', 'required' => false, 'category' => 'meilisearch', 'generate' => true],
            ['name' => 'MEILI_SEARCH_KEY', 'label' => 'Meilisearch Search Key', 'type' => 'text', 'required' => false, 'category' => 'meilisearch'],
        ];

        // Add detected values and found_in info
        foreach ($definitions as &$def) {
            $def['detected_value'] = $detected[$def['name']] ?? null;
            $def['found_in'] = $foundIn[$def['name']] ?? [];
            $def['has_value'] = !empty($detected[$def['name']]);
        }

        return $definitions;
    }

    /**
     * Replace detected values with variables in content
     */
    public function replaceWithVariables(string $content, array $variableMap): string
    {
        // Sort by value length (longest first) to avoid partial replacements
        uasort($variableMap, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($variableMap as $varName => $value) {
            if (!empty($value) && is_string($value) && strlen($value) >= 3) {
                // Literal substitution. Previously this used preg_replace(), which returns
                // NULL on a regex error (invalid UTF-8, backtrack/recursion limit) and would
                // silently null out the whole config - corrupting the blueprint. str_replace
                // does the same literal replacement with no null/error path.
                $content = str_replace($value, '{{' . $varName . '}}', $content);
            }
        }

        return $content;
    }

    /**
     * Build variable map from detected values and user overrides
     */
    public function buildVariableMap(array $detected, array $userOverrides = []): array
    {
        $map = [];

        // Start with detected values
        foreach ($detected as $varName => $value) {
            if (!empty($value)) {
                $map[$varName] = $value;
            }
        }

        // Apply user overrides
        foreach ($userOverrides as $varName => $value) {
            if (!empty($value)) {
                $map[$varName] = $value;
            }
        }

        return $map;
    }

    /**
     * Get variable categories for UI grouping
     */
    public function getVariableCategories(): array
    {
        return [
            'server' => ['label' => 'Server', 'icon' => 'dns'],
            'domains' => ['label' => 'Domains', 'icon' => 'language'],
            'mail_database' => ['label' => 'Mail Database', 'icon' => 'mail'],
            'panel_database' => ['label' => 'Panel Database', 'icon' => 'dashboard'],
            'email_database' => ['label' => 'Email App Database', 'icon' => 'inbox'],
            'ssl' => ['label' => 'SSL/TLS', 'icon' => 'lock'],
            'dkim' => ['label' => 'DKIM', 'icon' => 'verified_user'],
            'admin' => ['label' => 'Admin', 'icon' => 'admin_panel_settings'],
            'database' => ['label' => 'Database', 'icon' => 'storage'],
        ];
    }
}

