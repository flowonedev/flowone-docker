<?php
/**
 * Virtual Host Action Handler
 * 
 * Manages OpenLiteSpeed virtual hosts.
 * Handles site creation, deletion, and configuration.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class VhostAction extends BaseAction
{
    private ?\PDO $panelPdo = null;

    /**
     * Path to NS configuration file (shared with DnsAction)
     */
    private const NS_CONFIG_FILE = '/var/www/vps-admin/.dns_ns_config.json';

    /**
     * Get nameserver configuration (shared with DnsAction)
     */
    private function getNsConfiguration(): array
    {
        $defaults = [
            'enabled' => true,
            'ns1' => 'ns1.devcon1.hu',
            'ns2' => 'ns2.devcon1.hu',
        ];

        if (file_exists(self::NS_CONFIG_FILE)) {
            $content = file_get_contents(self::NS_CONFIG_FILE);
            $config = json_decode($content, true);
            if (is_array($config)) {
                return array_merge($defaults, $config);
            }
        }

        return $defaults;
    }

    public function getNamespace(): string
    {
        return 'vhost';
    }

    /**
     * Get panel database connection
     * Includes connection health check to handle stale connections in long-running daemon
     */
    private function getPanelDb(): ?\PDO
    {
        if ($this->panelPdo !== null) {
            try {
                $this->panelPdo->query('SELECT 1');
                return $this->panelPdo;
            } catch (\PDOException $e) {
                $this->panelPdo = null;
                $this->logger->warning('Panel database connection was stale, reconnecting: ' . $e->getMessage());
            }
        }

        // Read panel config
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';
        
        if (!file_exists($configFile)) {
            $this->logger->warning('Panel config file not found');
            return null;
        }

        try {
            $config = require $configFile;
            if (file_exists($localConfigFile)) {
                $localConfig = require $localConfigFile;
                $config = array_replace_recursive($config, $localConfig);
            }

            $dbConfig = $config['database'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['name'] ?? '',
                $dbConfig['charset'] ?? 'utf8mb4'
            );

            $this->panelPdo = new \PDO(
                $dsn,
                $dbConfig['user'] ?? '',
                $dbConfig['password'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            return $this->panelPdo;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to connect to panel database: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up OLS config content - remove CRLF, excessive blank lines
     */
    private function cleanOlsConfig(string $content): string
    {
        // Remove Windows carriage returns (^M / \r)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        
        // Remove excessive blank lines (more than 2 consecutive)
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        
        // Ensure file ends with single newline
        $content = rtrim($content) . "\n";
        
        return $content;
    }

    public function getMethods(): array
    {
        // Phase 5 of V2 consolidation removed 'create' and 'delete' from
        // this whitelist. Provisioning and teardown are now exclusively
        // handled by the saga orchestrator
        // (panel/agent/Provisioner/...). The remaining methods are the
        // OLS/filesystem read + management surface that the
        // SiteManageV2View tabs depend on.
        return ['list', 'get', 'update', 'config', 'saveConfig', 'updateConfigValues', 'logs', 'ftpStatus', 'sshKeys', 'addSshKey', 'updateSshKey', 'removeSshKey', 'fixSshPermissions', 'getDatabases', 'validateSite', 'fixSite', 'fixSiteIssue', 'validateDeletion', 'fixDeletion'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['update', 'saveConfig', 'updateConfigValues']);
    }

    /**
     * List all virtual hosts
     */
    protected function actionList(array $params, string $actor): array
    {
        $vhostsPath = $this->config['paths']['ols_vhosts'];
        $vhosts = [];

        if (!is_dir($vhostsPath)) {
            return $this->success(['vhosts' => []]);
        }

        $dirs = glob($vhostsPath . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $domain = basename($dir);
            
            // Try both config file names (CyberPanel uses vhost.conf, standard OLS uses vhconf.conf)
            $configFile = null;
            if (file_exists($dir . '/vhost.conf')) {
                $configFile = $dir . '/vhost.conf';
            } elseif (file_exists($dir . '/vhconf.conf')) {
                $configFile = $dir . '/vhconf.conf';
            }
            
            if ($configFile) {
                $vhosts[] = $this->parseVhostConfig($domain, $configFile);
            }
        }

        // Sort by domain name
        usort($vhosts, fn($a, $b) => strcmp($a['domain'], $b['domain']));

        return $this->success(['vhosts' => $vhosts]);
    }

    /**
     * Get a specific virtual host
     */
    protected function actionGet(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }

        // Try both config file names (CyberPanel uses vhost.conf, standard OLS uses vhconf.conf)
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Virtual host not found: {$domain}");
        }

        $vhost = $this->parseVhostConfig($domain, $configFile);
        $vhost['config_raw'] = file_get_contents($configFile);

        return $this->success(['vhost' => $vhost]);
    }


    // ------------------------------------------------------------------
    // Phase 5 of V2 consolidation removed actionCreate / actionDelete,
    // doCreate / doDelete and their exclusive helpers from this file.
    // Provisioning + teardown live in panel/agent/Provisioner/ now.
    // Shared helpers (getMySqlPassword, getServerIp, generateDkimKeys,
    // parseDkimRecord, findParentZone, updateDnsSerial, syncDnsZone,
    // domainToUsername, linkDatabaseToSite) intentionally remain because
    // MailAction, DnsAction, DatabaseController, and the remaining
    // management actions in this class still call them.
    // ------------------------------------------------------------------
    /**
     * Find parent zone for a subdomain
     * e.g., for "email.devcon1.hu" returns the zone info for "devcon1.hu" if it exists
     */
    private function findParentZone(\PDO $pdo, string $domain): ?array
    {
        $parts = explode('.', $domain);
        
        // Need at least 3 parts to be a subdomain (sub.domain.tld)
        if (count($parts) < 3) {
            return null;
        }
        
        // Try progressively shorter parent domains
        // e.g., for "a.b.c.devcon1.hu", try "b.c.devcon1.hu", then "c.devcon1.hu", then "devcon1.hu"
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $parentDomain = implode('.', array_slice($parts, $i));
            
            $stmt = $pdo->prepare("SELECT id, name FROM dns_domains WHERE name = ?");
            $stmt->execute([$parentDomain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($zone) {
                // Calculate the subdomain prefix (what comes before the parent domain)
                $subdomain = implode('.', array_slice($parts, 0, $i));
                return [
                    'zone_id' => $zone['id'],
                    'zone_name' => $zone['name'],
                    'subdomain_prefix' => $subdomain,
                ];
            }
        }
        
        return null;
    }

    /**
     * Update DNS zone SOA serial
     */
    private function updateDnsSerial(\PDO $pdo, int $zoneId, string $zoneName): void
    {
        $stmt = $pdo->prepare("SELECT id, content FROM dns_records WHERE domain_id = ? AND type = 'SOA'");
        $stmt->execute([$zoneId]);
        $soa = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($soa) {
            // Parse SOA: ns1.devcon1.hu. admin.domain.com. 2025123001 10800 3600 604800 3600
            $parts = preg_split('/\s+/', $soa['content']);
            if (count($parts) >= 3) {
                $currentSerial = $parts[2];
                $today = date('Ymd');
                
                // Generate new serial
                if (substr($currentSerial, 0, 8) === $today) {
                    // Same day - increment sequence
                    $newSerial = $currentSerial + 1;
                } else {
                    // New day - start at 01
                    $newSerial = $today . '01';
                }
                
                $parts[2] = $newSerial;
                $newSoa = implode(' ', $parts);
                
                $stmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
                $stmt->execute([$newSoa, $soa['id']]);
            }
        }
    }

    /**
     * Convert domain to expected Linux username
     * Takes first part of domain and sanitizes it for use as username
     */
    private function domainToUsername(string $domain): string
    {
        // Get first part of domain (before first dot)
        $parts = explode('.', $domain);
        $base = $parts[0];
        
        // Sanitize: only alphanumeric and underscore, max 32 chars
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower($base));
        
        // Ensure it starts with a letter
        if (!preg_match('/^[a-z]/', $username)) {
            $username = 'u' . $username;
        }
        
        // Max 32 chars for Linux username
        return substr($username, 0, 32);
    }

    /**
     * Link database to website in our database_links table
     */
    private function linkDatabaseToSite(string $domain, string $dbName, string $dbUser): bool
    {
        try {
            $pdo = $this->getPanelDb();
            if (!$pdo) {
                $this->logger->error("Cannot connect to panel database for linking {$dbName} to {$domain}");
                return false;
            }

            // Ensure the table exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS database_links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    db_name VARCHAR(64) NOT NULL,
                    db_user VARCHAR(64),
                    domain VARCHAR(255) NOT NULL,
                    db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
                    created_by INT,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_db_domain (db_name, domain),
                    INDEX idx_domain (domain),
                    INDEX idx_db_name (db_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Check if database link already exists
            $stmt = $pdo->prepare("SELECT id FROM database_links WHERE db_name = ?");
            $stmt->execute([$dbName]);
            if ($stmt->fetch()) {
                // Update the link to point to this site
                $stmt = $pdo->prepare("UPDATE database_links SET domain = ?, db_user = ? WHERE db_name = ?");
                $stmt->execute([$domain, $dbUser, $dbName]);
                $this->logger->info("Updated database link: {$dbName} -> {$domain}");
                return true;
            }

            // Insert new database link
            $stmt = $pdo->prepare("
                INSERT INTO database_links (db_name, db_user, domain, db_host)
                VALUES (?, ?, ?, 'localhost')
            ");
            $stmt->execute([$dbName, $dbUser, $domain]);
            $this->logger->info("Created database link: {$dbName} -> {$domain}");

            return true;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to link database to site: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get MySQL password from config
     */
    private function getMySqlPassword(): string
    {
        $mycnf = '/root/.my.cnf';
        if (file_exists($mycnf)) {
            $content = file_get_contents($mycnf);
            if (preg_match('/password\s*=\s*["\']?([^"\'\\n]+)["\']?/i', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        return '';
    }

    /**
     * Sync DNS zone to all nameservers
     * Bumps serial and sends NOTIFY to slaves
     */
    private function syncDnsZone(string $domain): void
    {
        // Find the actual zone (might be a subdomain)
        $pdo = $this->getPanelDb();
        if ($pdo) {
            // Check if domain is in a parent zone
            $parts = explode('.', $domain);
            $targetZone = $domain;
            
            while (count($parts) > 1) {
                $checkDomain = implode('.', $parts);
                $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
                $stmt->execute([$checkDomain]);
                if ($stmt->fetch()) {
                    $targetZone = $checkDomain;
                    break;
                }
                array_shift($parts);
            }
            
            // Bump serial
            exec("pdnsutil increase-serial " . escapeshellarg($targetZone) . " 2>/dev/null");
            
            // Notify all slaves
            $this->execCommand('pdns_control', ['notify', $targetZone]);
        } else {
            // Fallback: just notify
            $this->execCommand('pdns_control', ['notify', $domain]);
        }
    }

    /**
     * Get server's public IP address
     */
    private function getServerIp(): ?string
    {
        // Use configured IP (most reliable - never changes)
        if (!empty($this->config['server']['ip'])) {
            return $this->config['server']['ip'];
        }

        // Fallback: Try hostname resolution
        $hostname = gethostname();
        $ip = gethostbyname($hostname);
        if ($ip !== $hostname && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }

        // Last resort: External service (unreliable)
        $result = $this->execCommand('curl', ['-s', '-m', '5', 'https://api.ipify.org']);
        if ($result['success'] && filter_var(trim($result['output']), FILTER_VALIDATE_IP)) {
            return trim($result['output']);
        }

        return null;
    }

    /**
     * Generate DKIM keys for a domain
     */
    private function generateDkimKeys(string $domain): array
    {
        $selector = 'default';
        $bits = 2048;
        
        // Create directory
        $dkimPath = "/etc/opendkim/keys/{$domain}";
        if (!is_dir($dkimPath)) {
            mkdir($dkimPath, 0700, true);
        }

        $privateKeyPath = "{$dkimPath}/{$selector}.private";
        
        // Check if already exists
        if (file_exists($privateKeyPath)) {
            // Read existing public key
            $publicKeyPath = "{$dkimPath}/{$selector}.txt";
            if (file_exists($publicKeyPath)) {
                $content = file_get_contents($publicKeyPath);
                $record = $this->parseDkimRecord($content);
                if ($record) {
                    return ['success' => true, 'existing' => true, 'record' => $record];
                }
            }
            return ['success' => true, 'existing' => true, 'record' => null];
        }

        // Generate keys using opendkim-genkey
        $result = $this->execCommand('opendkim-genkey', [
            '-b', (string)$bits,
            '-d', $domain,
            '-D', $dkimPath,
            '-s', $selector,
            '-v'
        ]);

        if (!$result['success']) {
            $this->logger->warning("Failed to generate DKIM keys: " . $result['output']);
            return ['success' => false, 'error' => $result['output']];
        }

        // Set permissions
        $this->execCommand('chown', ['opendkim:opendkim', $privateKeyPath]);
        $this->execCommand('chmod', ['600', $privateKeyPath]);

        // Add to OpenDKIM signing table
        $signingTablePath = '/etc/opendkim/SigningTable';
        $signingEntry = "*@{$domain} {$selector}._domainkey.{$domain}\n";
        
        $signingTable = file_exists($signingTablePath) ? file_get_contents($signingTablePath) : '';
        if (strpos($signingTable, $domain) === false) {
            file_put_contents($signingTablePath, $signingTable . $signingEntry);
        }

        // Add to OpenDKIM key table
        $keyTablePath = '/etc/opendkim/KeyTable';
        $keyEntry = "{$selector}._domainkey.{$domain} {$domain}:{$selector}:{$privateKeyPath}\n";
        
        $keyTable = file_exists($keyTablePath) ? file_get_contents($keyTablePath) : '';
        if (strpos($keyTable, $domain) === false) {
            file_put_contents($keyTablePath, $keyTable . $keyEntry);
        }

        // Reload opendkim
        $this->execCommand('systemctl', ['reload', 'opendkim']);

        // Read the generated public key
        $publicKeyPath = "{$dkimPath}/{$selector}.txt";
        $dnsRecord = null;
        if (file_exists($publicKeyPath)) {
            $content = file_get_contents($publicKeyPath);
            $dnsRecord = $this->parseDkimRecord($content);
        }

        return [
            'success' => true,
            'record' => $dnsRecord,
        ];
    }

    /**
     * Parse DKIM record from opendkim-genkey output
     * Returns properly quoted TXT record content for PowerDNS
     */
    private function parseDkimRecord(string $content): ?string
    {
        // opendkim-genkey outputs format like:
        // default._domainkey IN TXT ( "v=DKIM1; h=sha256; k=rsa; "
        //           "p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA..."
        //           "...rest..." ) ; ----- DKIM key default for domain
        
        // Extract all quoted strings from the TXT record
        if (preg_match_all('/"([^"]+)"/', $content, $matches)) {
            if (!empty($matches[1])) {
                // Rebuild with proper quoting for PowerDNS
                // Format: "part1" "part2" "part3"
                $parts = [];
                foreach ($matches[1] as $part) {
                    $parts[] = '"' . trim($part) . '"';
                }
                return implode(' ', $parts);
            }
        }
        
        // Fallback: try to extract raw v=DKIM1 content
        if (preg_match('/v=DKIM1[^;]*;[^"]+p=[A-Za-z0-9+\/=]+/', $content, $matches)) {
            return '"' . trim($matches[0]) . '"';
        }
        
        return null;
    }

    /**
     * Update virtual host configuration
     */
    protected function actionUpdate(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }

        // Try both config file names (CyberPanel uses vhost.conf, default is vhconf.conf)
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Virtual host not found: {$domain}");
        }

        // Get original file ownership and permissions before making changes
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;

        // Backup current config
        $backupPath = $this->backupFile($configFile, 'update', $actor);

        // Read current config
        $currentConfig = file_get_contents($configFile);

        // Update PHP version in existing config if specified
        $newConfig = $currentConfig;
        
        if (!empty($params['php_lsapi'])) {
            $phpVersion = $params['php_lsapi']; // e.g., 'lsphp83'
            
            // Validate PHP version format
            if (!preg_match('/^lsphp\d{2,3}$/', $phpVersion)) {
                return $this->error("Invalid PHP version format: {$phpVersion}. Expected format: lsphp81, lsphp83, etc.");
            }
            
            // SAFETY: Only update the 'path' directive for PHP binary
            // This is the ONLY thing that determines which PHP version runs
            // DO NOT touch: extprocessor names, socket paths, handler names (they're site-specific in CyberPanel)
            
            // Count how many path lines exist for PHP
            $pathMatches = preg_match_all('/^(\s*)path\s+\/usr\/local\/lsws\/lsphp\d+\/bin\/lsphp\s*$/m', $currentConfig, $matches);
            
            if ($pathMatches === 0) {
                // No PHP path found - this config might not have PHP configured
                return $this->error("No PHP path directive found in vhost config. Cannot update PHP version.");
            }
            
            // Update PHP binary path ONLY - preserve exact whitespace/formatting
            // e.g., "  path  /usr/local/lsws/lsphp81/bin/lsphp" -> "  path  /usr/local/lsws/lsphp83/bin/lsphp"
            $newConfig = preg_replace(
                '/^(\s*path\s+)\/usr\/local\/lsws\/lsphp\d+\/bin\/lsphp(\s*)$/m',
                '${1}/usr/local/lsws/' . $phpVersion . '/bin/lsphp${2}',
                $currentConfig
            );
            
            // Verify the replacement actually happened
            if ($newConfig === $currentConfig && !str_contains($currentConfig, $phpVersion)) {
                return $this->error("Failed to update PHP version in config. Please check the vhost configuration manually.");
            }
            
            // SAFETY CHECK: Ensure config still has all critical sections
            $criticalPatterns = [
                'docRoot' => '/docRoot\s+/',
                'extprocessor' => '/extprocessor\s+\w+\s*\{/',
                'scripthandler' => '/scripthandler\s*\{/',
            ];
            
            foreach ($criticalPatterns as $section => $pattern) {
                if (preg_match($pattern, $currentConfig) && !preg_match($pattern, $newConfig)) {
                    // Critical section was removed - abort!
                    return $this->error("Safety check failed: {$section} section would be removed. Aborting update.");
                }
            }
        }

        // Generate diff
        $diff = $this->diff->fromContent($currentConfig, $newConfig, $domain);

        // Only write and restart if something changed
        if ($newConfig !== $currentConfig) {
            // Write new config
            file_put_contents($configFile, $newConfig);
            
            // Restore original ownership and permissions (lsadm:nogroup for OLS)
            chown($configFile, $origOwner);
            chgrp($configFile, $origGroup);
            chmod($configFile, $origPerms);

            // Reload OLS to apply changes (use graceful reload, not restart)
            $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['reload']);
        }

        return $this->success([
            'domain' => $domain,
            'backup' => $backupPath,
            'diff' => $diff,
            'php_version' => $params['php_lsapi'] ?? null,
            'changed' => $newConfig !== $currentConfig,
        ], "Virtual host {$domain} updated. OpenLiteSpeed reloaded.");
    }

    /**
     * Parse vhost configuration file
     */
    private function parseVhostConfig(string $domain, string $configFile): array
    {
        $content = file_get_contents($configFile);
        
        $vhost = [
            'domain' => $domain,
            'enabled' => true,
            'document_root' => null,
            'php_version' => null,
            'php_handler' => null,
            'ext_user' => null,
            'ssl' => false,
            'ssl_expires' => null,
        ];

        // Parse docRoot - handle $VH_ROOT variable
        if (preg_match('/docRoot\s+(.+)$/m', $content, $matches)) {
            $docRoot = trim($matches[1]);
            // Replace $VH_ROOT with actual path
            if (strpos($docRoot, '$VH_ROOT') !== false) {
                $docRoot = str_replace('$VH_ROOT', '/home/' . $domain, $docRoot);
            }
            $vhost['document_root'] = $docRoot;
        }

        // Check for SSL certificate
        // Only mark as SSL if certificate exists AND is actually configured for this domain
        
        $certFile = null;
        $certInfo = null;
        
        // Priority 1: Check exact domain match (most common case)
        $sslPath = '/etc/letsencrypt/live/' . $domain;
        if (file_exists($sslPath . '/fullchain.pem')) {
            $certFile = $sslPath . '/fullchain.pem';
            $certInfo = openssl_x509_parse(file_get_contents($certFile));
        } else {
            // Priority 2: Check if vhssl block is configured and cert exists
            if (preg_match('/vhssl\s*\{[^}]*certFile\s+([^\n]+)/is', $content, $matches)) {
                $configuredCertPath = trim($matches[1]);
                // Replace $VH_NAME variable if present
                if (strpos($configuredCertPath, '$VH_NAME') !== false) {
                    $configuredCertPath = str_replace('$VH_NAME', $domain, $configuredCertPath);
                }
                // Replace $VH_ROOT variable if present
                if (strpos($configuredCertPath, '$VH_ROOT') !== false) {
                    $configuredCertPath = str_replace('$VH_ROOT', '/home/' . $domain, $configuredCertPath);
                }
                
                if (file_exists($configuredCertPath)) {
                    $certFile = $configuredCertPath;
                    $certInfo = openssl_x509_parse(file_get_contents($certFile));
                }
            }
            
            // Priority 3: For mail subdomains only, check global mail.devcon1.hu cert
            // This is a special case for mail subdomains that use the global cert
            if (!$certFile && strpos($domain, 'mail.') === 0) {
                $globalMailCert = '/etc/letsencrypt/live/mail.devcon1.hu/fullchain.pem';
                if (file_exists($globalMailCert)) {
                    $parsed = openssl_x509_parse(file_get_contents($globalMailCert));
                    if ($parsed) {
                        // Check if this mail subdomain is in the global cert's SANs
                        if (isset($parsed['extensions']['subjectAltName'])) {
                            $sans = explode(', ', $parsed['extensions']['subjectAltName']);
                            foreach ($sans as $san) {
                                $sanDomain = str_replace('DNS:', '', trim($san));
                                if ($sanDomain === $domain) {
                                    $certFile = $globalMailCert;
                                    $certInfo = $parsed;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        if ($certFile && $certInfo) {
            $vhost['ssl'] = true;
            if (isset($certInfo['validTo_time_t'])) {
                $vhost['ssl_expires'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
            }
        }

        // Parse PHP version from extprocessor path directive
        // CyberPanel format: scripthandler uses site user (e.g., lsapi:akade3882)
        // but the PHP version is in the path directive (e.g., /usr/local/lsws/lsphp83/bin/lsphp)
        if (preg_match('/path\s+\/usr\/local\/lsws\/lsphp(\d)(\d)\/bin\/lsphp/m', $content, $matches)) {
            $vhost['php_version'] = $matches[1] . '.' . $matches[2];
            $vhost['php_handler'] = 'lsphp' . $matches[1] . $matches[2];
        }
        
        // Fallback: try standard OLS format (extprocessor lsphp81)
        if (empty($vhost['php_handler']) && preg_match('/extprocessor\s+(lsphp(\d)(\d))/m', $content, $matches)) {
            $vhost['php_handler'] = $matches[1];
            $vhost['php_version'] = $matches[2] . '.' . $matches[3];
        }

        // Get extUser (site owner for SFTP)
        if (preg_match('/extUser\s+(\S+)/m', $content, $matches)) {
            $vhost['ext_user'] = $matches[1];
        }

        // Get process limits from vhost config
        $vhost['limits'] = [
            'mem_soft_limit' => null,
            'mem_hard_limit' => null,
            'proc_soft_limit' => null,
            'proc_hard_limit' => null,
            'max_conns' => null,
        ];
        
        if (preg_match('/memSoftLimit\s+(\S+)/m', $content, $matches)) {
            $vhost['limits']['mem_soft_limit'] = $matches[1];
        }
        if (preg_match('/memHardLimit\s+(\S+)/m', $content, $matches)) {
            $vhost['limits']['mem_hard_limit'] = $matches[1];
        }
        if (preg_match('/procSoftLimit\s+(\S+)/m', $content, $matches)) {
            $vhost['limits']['proc_soft_limit'] = $matches[1];
        }
        if (preg_match('/procHardLimit\s+(\S+)/m', $content, $matches)) {
            $vhost['limits']['proc_hard_limit'] = $matches[1];
        }
        if (preg_match('/maxConns\s+(\S+)/m', $content, $matches)) {
            $vhost['limits']['max_conns'] = $matches[1];
        }

        // Get PHP.ini limits if PHP version is known
        if (!empty($vhost['php_version'])) {
            // CyberPanel/OLS path: /usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini
            $phpVersion = $vhost['php_version']; // e.g., "8.3"
            $handler = $vhost['php_handler']; // e.g., "lsphp83"
            $phpIniPath = '/usr/local/lsws/' . $handler . '/etc/php/' . $phpVersion . '/litespeed/php.ini';
            
            if (file_exists($phpIniPath)) {
                $phpIni = file_get_contents($phpIniPath);
                $vhost['php_limits'] = [];
                
                $iniSettings = [
                    'memory_limit', 'max_execution_time', 'max_input_time',
                    'upload_max_filesize', 'post_max_size', 'max_input_vars'
                ];
                
                foreach ($iniSettings as $setting) {
                    if (preg_match('/^\s*' . $setting . '\s*=\s*(.+)$/m', $phpIni, $matches)) {
                        $vhost['php_limits'][$setting] = trim($matches[1]);
                    }
                }
            }
        }

        // Get site size
        if ($vhost['document_root'] && is_dir($vhost['document_root'])) {
            $vhost['size'] = $this->getDirectorySize($vhost['document_root']);
            $vhost['size_human'] = $this->humanFileSize($vhost['size']);
        }

        // Check for template backup (indicates a template has been applied)
        // Use actual document root rather than hardcoded path
        $docRoot = $vhost['document_root'] ?? "/home/{$domain}/public_html";
        $backupPattern = "{$docRoot}/index.html.backup.*";
        $backupFiles = glob($backupPattern);
        $vhost['has_template_backup'] = !empty($backupFiles);
        if ($vhost['has_template_backup']) {
            usort($backupFiles, fn($a, $b) => filemtime($b) - filemtime($a));
            $vhost['template_backup_date'] = date('Y-m-d H:i:s', filemtime($backupFiles[0]));
        }

        // Get template type from database
        $vhost['template_type'] = null;
        $vhost['deployed_at'] = null;
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                $stmt = $panelDb->prepare("
                    SELECT template_type, deployed_at, deployed_by 
                    FROM template_deployments 
                    WHERE domain = ?
                ");
                $stmt->execute([$domain]);
                $deployment = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($deployment) {
                    $vhost['template_type'] = $deployment['template_type'];
                    $vhost['deployed_at'] = $deployment['deployed_at'];
                    $vhost['deployed_by'] = $deployment['deployed_by'];
                    // If we have a database record, mark as having template even without backup file
                    if (!$vhost['has_template_backup']) {
                        $vhost['has_template_backup'] = true;
                    }
                }
            } catch (\Exception $e) {
                // Silently ignore database errors
            }
        }
        
        // Additional detection: Check index.html content for template markers
        // This catches templates applied manually or through other means
        if (!$vhost['has_template_backup'] && !$vhost['template_type']) {
            $indexFile = "{$docRoot}/index.html";
            if (file_exists($indexFile)) {
                $content = @file_get_contents($indexFile, false, null, 0, 2000);
                if ($content) {
                    // Check for common template markers
                    if (stripos($content, 'maintenance') !== false && 
                        (stripos($content, 'under maintenance') !== false || 
                         stripos($content, 'scheduled maintenance') !== false ||
                         stripos($content, 'maintenance mode') !== false)) {
                        $vhost['has_template_backup'] = true;
                        $vhost['template_type'] = 'site_maintenance';
                    } elseif (stripos($content, 'coming soon') !== false || 
                              stripos($content, 'launching soon') !== false) {
                        $vhost['has_template_backup'] = true;
                        $vhost['template_type'] = 'site_coming_soon';
                    } elseif (stripos($content, 'under construction') !== false || 
                              stripos($content, 'placeholder') !== false ||
                              stripos($content, 'site is being built') !== false) {
                        $vhost['has_template_backup'] = true;
                        $vhost['template_type'] = 'site_placeholder';
                    }
                }
            }
        }

        return $vhost;
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Permission denied or other error
        }
        return $size;
    }

    /**
     * Convert bytes to human readable
     */
    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Generate vhost configuration with user-specific settings
     * Uses OLS variables ($VH_ROOT, $VH_NAME) for portability like CyberPanel
     * Includes security headers and optimal SSL config when certificate exists
     */
    private function generateVhostConfig(string $domain, array $params): string
    {
        $phpLsapi = $params['php_lsapi'] ?? 'lsphp83';
        $siteUser = $params['site_user'] ?? 'www-data';
        $adminEmail = $params['admin_email'] ?? 'pixelrangerstudio@gmail.com';
        $includeMail = !empty($params['create_mail_domain']);

        // Extract PHP version for path (e.g., lsphp83 -> 8.3)
        $phpVersion = '8.3';
        if (preg_match('/lsphp(\d)(\d)/', $phpLsapi, $matches)) {
            $phpVersion = $matches[1] . '.' . $matches[2];
        }

        // Build vhAliases - include mail if mail domain is being created
        $vhAliases = $includeMail ? 'www.$VH_NAME, mail.$VH_NAME' : 'www.$VH_NAME';

        // Check if SSL certificate exists before including SSL block and security headers
        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        $sslCertExists = file_exists($certPath . '/fullchain.pem') && file_exists($certPath . '/privkey.pem');

        $contextBlock = <<<'CONTEXT'
context / {
  location                $DOC_ROOT/
  allowBrowse             1
  rewrite  {
    enable                1
    rules                 <<<END_RULES
RewriteRule .* - [E=XFO:SAMEORIGIN]
RewriteRule .* - [E=XCTO:nosniff]
RewriteRule .* - [E=RP:strict-origin-when-cross-origin]
END_RULES
  }
  extraHeaders            <<<END_HEADERS
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: %{XFO}e
X-Content-Type-Options: %{XCTO}e
Referrer-Policy: %{RP}e
END_HEADERS
}
CONTEXT;

        // Build SSL block only if certificate exists
        $sslBlock = '';
        if ($sslCertExists) {
            $sslBlock = <<<'SSL'

vhssl  {
  keyFile                 /etc/letsencrypt/live/$VH_NAME/privkey.pem
  certFile                /etc/letsencrypt/live/$VH_NAME/fullchain.pem
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableStapling          1
  ocspRespMaxAge          86400
  ciphers                 ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES256-GCM-SHA384
  sslSessionTickets       1
  enableQuic              1
}
SSL;
        }

        // Use OLS variables for portability (like CyberPanel)
        // enableGzip 0 for BREACH mitigation
        
        return <<<CONFIG
docRoot                   \$VH_ROOT/public_html
vhDomain                  \$VH_NAME
vhAliases                 {$vhAliases}
adminEmails               {$adminEmail}
enableGzip                0
enableIpGeo               1

index  {
  useServer               0
  indexFiles              index.php, index.html
}

errorlog \$VH_ROOT/logs/\$VH_NAME.error_log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog \$VH_ROOT/logs/\$VH_NAME.access_log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\""
  logHeaders              5
  rollingSize             10M
  keepDays                10
  compressArchive         1
}

scripthandler  {
  add                     lsapi:{$siteUser} php
}

extprocessor {$siteUser} {
  type                    lsapi
  address                 UDS://tmp/lshttpd/{$siteUser}.sock
  maxConns                10
  env                     LSAPI_CHILDREN=10
  initTimeout             600
  retryTimeout            0
  persistConn             1
  pcKeepAliveTimeout      1
  respBuffer              0
  autoStart               1
  path                    /usr/local/lsws/{$phpLsapi}/bin/lsphp
  extUser                 {$siteUser}
  extGroup                {$siteUser}
  memSoftLimit            1024M
  memHardLimit            1024M
  procSoftLimit           400
  procHardLimit           500
}

phpIniOverride  {

}

module cache {
  storagePath /usr/local/lsws/cachedata/\$VH_NAME
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
}

context /.well-known/acme-challenge {
  location                \$DOC_ROOT/.well-known/acme-challenge
  allowBrowse             1

  rewrite  {
    enable                0
  }
  addDefaultCharset       off

  phpIniOverride  {

  }
}

{$contextBlock}{$sslBlock}

errorPage 404 {
  url /error/404.html
}
errorPage 403 {
  url /error/403.html
}
errorPage 500 {
  url /error/500.html
}
errorPage 503 {
  url /error/503.html
}
CONFIG;
    }

    /**
     * Generate placeholder page
     */
    private function generatePlaceholderPage(string $domain): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to {$domain}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #1a1a2e; color: #eee; }
        .container { text-align: center; padding: 2rem; }
        h1 { font-size: 2.5rem; margin-bottom: 1rem; }
        p { color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>{$domain}</h1>
        <p>This site is ready. Upload your files to get started.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Add SSL block and security headers to vhost config after certificate is issued
     * Uses the golden standard configuration with OLS heredoc syntax for headers
     */
    private function addSslToVhostConfig(string $domain): bool
    {
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            $this->logger->warning("Vhost config not found for {$domain}, cannot add SSL block");
            return false;
        }

        // Check if SSL block already exists
        $config = file_get_contents($configFile);
        if (preg_match('/vhssl\s*\{/i', $config)) {
            // SSL block already exists
            return true;
        }

        // Check if certificate exists
        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        if (!file_exists($certPath . '/fullchain.pem') || !file_exists($certPath . '/privkey.pem')) {
            $this->logger->warning("SSL certificate not found for {$domain}, cannot add SSL block");
            return false;
        }

        // Get original file ownership and permissions
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;

        // 1. Disable gzip for BREACH mitigation
        if (preg_match('/enableGzip\s+1/i', $config)) {
            $config = preg_replace('/enableGzip\s+1/i', 'enableGzip                0', $config);
        }

        // 2. Replace/update the context block with security headers (using OLS heredoc syntax)
        // Note: CSP removed as it's site-specific and can break resources
        $secureContextBlock = <<<'CONTEXT'
context / {
  location                $DOC_ROOT/
  allowBrowse             1
  rewrite  {
    enable                1
    rules                 <<<END_RULES
RewriteRule .* - [E=XFO:SAMEORIGIN]
RewriteRule .* - [E=XCTO:nosniff]
RewriteRule .* - [E=RP:strict-origin-when-cross-origin]
END_RULES
  }
  extraHeaders            <<<END_HEADERS
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: %{XFO}e
X-Content-Type-Options: %{XCTO}e
Referrer-Policy: %{RP}e
END_HEADERS
}
CONTEXT;

        // Remove existing context / block if present (handles nested braces properly)
        $config = $this->replaceContextBlock($config, '/', $secureContextBlock);

        // 3. Add SSL block with optimal settings (golden standard)
        $sslBlock = <<<'SSL'

vhssl  {
  keyFile                 /etc/letsencrypt/live/$VH_NAME/privkey.pem
  certFile                /etc/letsencrypt/live/$VH_NAME/fullchain.pem
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableStapling          1
  ocspRespMaxAge          86400
  ciphers                 ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES256-GCM-SHA384
  sslSessionTickets       1
  enableQuic              1
}
SSL;

        // Append SSL block to config
        $newConfig = rtrim($config) . "\n" . $sslBlock . "\n";

        // Write updated config
        if (file_put_contents($configFile, $newConfig) === false) {
            $this->logger->error("Failed to add SSL block to vhost config for {$domain}");
            return false;
        }

        // Restore original ownership and permissions
        chown($configFile, $origOwner);
        chgrp($configFile, $origGroup);
        chmod($configFile, $origPerms);

        $this->logger->info("Added SSL block and security headers to vhost config for {$domain}");
        return true;
    }

    /**
     * Replace a context block in OLS config, handling nested braces correctly
     * 
     * @param string $config The full config content
     * @param string $contextPath The context path to match (e.g., '/' or '/.well-known/acme-challenge')
     * @param string $replacement The replacement block (or empty to just remove)
     * @return string The modified config
     */
    private function replaceContextBlock(string $config, string $contextPath, string $replacement): string
    {
        
        // Escape the context path for regex
        $escapedPath = preg_quote($contextPath, '/');
        
        // Find the start of the context block
        $pattern = '/context\s+' . $escapedPath . '\s*\{/';
        if (!preg_match($pattern, $config, $matches, PREG_OFFSET_CAPTURE)) {
            // No existing context block - append replacement if provided
            if (!empty($replacement)) {
                return rtrim($config) . "\n\n" . $replacement;
            }
            return $config;
        }
        
        $startPos = $matches[0][1];
        $braceStart = $startPos + strlen($matches[0][0]) - 1; // Position of opening {
        
        // Find matching closing brace by counting brace depth
        $depth = 1;
        $pos = $braceStart + 1;
        $len = strlen($config);
        
        while ($pos < $len && $depth > 0) {
            $char = $config[$pos];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            $pos++;
        }
        
        if ($depth !== 0) {
            // Unbalanced braces - log warning and return unchanged
            $this->logger->warning("Unbalanced braces in config for context {$contextPath}");
            return $config;
        }
        
        $endPos = $pos; // Position after closing }
        
        // Replace the block
        $before = substr($config, 0, $startPos);
        $after = substr($config, $endPos);
        
        // Clean up whitespace
        $before = rtrim($before);
        $after = ltrim($after);
        
        if (!empty($replacement)) {
            return $before . "\n\n" . $replacement . "\n\n" . $after;
        }
        
        return $before . "\n\n" . $after;
    }

    /**
     * Remove SSL block from vhost config when certificate is deleted
     */
    private function removeSslFromVhostConfig(string $domain): bool
    {
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            $this->logger->warning("Vhost config not found for {$domain}, cannot remove SSL block");
            return false;
        }

        // Get original file ownership and permissions
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;

        $config = file_get_contents($configFile);
        
        // Check if SSL block exists
        if (!preg_match('/vhssl\s*\{/i', $config)) {
            // SSL block doesn't exist, nothing to remove
            return true;
        }

        // Remove SSL block (vhssl { ... } including nested braces)
        // Match vhssl block with proper brace counting
        $newConfig = preg_replace_callback(
            '/vhssl\s*\{[^}]*((?:\{[^}]*\}[^}]*)*)\}/is',
            function($matches) {
                // Count braces to ensure we match the complete block
                $content = $matches[0];
                $braceCount = 1;
                $pos = strpos($content, '{') + 1;
                $endPos = $pos;
                
                while ($endPos < strlen($content) && $braceCount > 0) {
                    if ($content[$endPos] === '{') {
                        $braceCount++;
                    } elseif ($content[$endPos] === '}') {
                        $braceCount--;
                    }
                    $endPos++;
                }
                
                // Return empty string to remove the block
                return '';
            },
            $config
        );

        // Alternative simpler approach: remove lines between vhssl { and matching }
        $lines = explode("\n", $config);
        $newLines = [];
        $inSslBlock = false;
        $braceCount = 0;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Detect start of SSL block
            if (preg_match('/^vhssl\s*\{/i', $trimmed)) {
                $inSslBlock = true;
                $braceCount = substr_count($line, '{') - substr_count($line, '}');
                continue; // Skip this line
            }
            
            // If we're in SSL block, count braces
            if ($inSslBlock) {
                $braceCount += substr_count($line, '{');
                $braceCount -= substr_count($line, '}');
                
                // If braces are balanced, we've reached the end
                if ($braceCount <= 0) {
                    $inSslBlock = false;
                    $braceCount = 0;
                }
                continue; // Skip lines in SSL block
            }
            
            // Keep lines outside SSL block
            $newLines[] = $line;
        }
        
        $newConfig = implode("\n", $newLines);
        
        // Clean up multiple consecutive empty lines
        $newConfig = preg_replace('/\n{3,}/', "\n\n", $newConfig);

        // Write updated config
        if (file_put_contents($configFile, $newConfig) === false) {
            $this->logger->error("Failed to remove SSL block from vhost config for {$domain}");
            return false;
        }

        // Restore original ownership and permissions
        chown($configFile, $origOwner);
        chgrp($configFile, $origGroup);
        chmod($configFile, $origPerms);

        $this->logger->info("Removed SSL block from vhost config for {$domain}");
        return true;
    }

    /**
     * Remove mail DNS records (MX, SPF, DMARC, DKIM, mail A) for a domain
     */
    private function removeMailDnsRecords(string $domain): void
    {
        try {
            $pdo = $this->getPanelDb();
            if (!$pdo) {
                $this->logger->warning("Cannot connect to panel database to remove mail DNS records for {$domain}");
                return;
            }

            // Find DNS zone for the domain
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                $this->logger->info("DNS zone not found for {$domain}, skipping mail DNS record removal");
                return;
            }

            $zoneId = $zone['id'];
            $recordsRemoved = 0;

            // Remove MX record
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'MX'");
            $stmt->execute([$zoneId, $domain]);
            $recordsRemoved += $stmt->rowCount();

            // Remove mail A record
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'A'");
            $stmt->execute([$zoneId, 'mail.' . $domain]);
            $recordsRemoved += $stmt->rowCount();

            // Remove SPF record (TXT record for domain with SPF content)
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT' AND content LIKE 'v=spf1%'");
            $stmt->execute([$zoneId, $domain]);
            $recordsRemoved += $stmt->rowCount();

            // Remove DMARC record
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT' AND content LIKE 'v=DMARC1%'");
            $stmt->execute([$zoneId, '_dmarc.' . $domain]);
            $recordsRemoved += $stmt->rowCount();

            // Remove DKIM records (all selectors including default._domainkey and legacy _domainkey)
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name LIKE ? AND type = 'TXT'");
            $stmt->execute([$zoneId, '%._domainkey.' . $domain]);
            $recordsRemoved += $stmt->rowCount();
            
            // Also remove legacy _domainkey.domain record (without selector prefix)
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT'");
            $stmt->execute([$zoneId, '_domainkey.' . $domain]);
            $recordsRemoved += $stmt->rowCount();

            if ($recordsRemoved > 0) {
                // Update SOA serial
                $this->updateDnsSerial($pdo, $zoneId, $domain);
                
                // Sync DNS to all nameservers
                $this->syncDnsZone($domain);
                
                $this->logger->info("Removed {$recordsRemoved} mail DNS records for {$domain}");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to remove mail DNS records for {$domain}: " . $e->getMessage());
        }
    }

    /**
     * Validate that all required mail DNS records exist for a domain
     */
    private function validateMailDnsRecords(string $domain): array
    {
        $required = [
            'MX' => ['name' => $domain, 'type' => 'MX'],
            'mail_A' => ['name' => 'mail.' . $domain, 'type' => 'A'],
            'SPF' => ['name' => $domain, 'type' => 'TXT', 'content_pattern' => 'v=spf1'],
            'DMARC' => ['name' => '_dmarc.' . $domain, 'type' => 'TXT', 'content_pattern' => 'v=DMARC1'],
            'DKIM' => ['name_pattern' => '%._domainkey.' . $domain, 'type' => 'TXT'],
        ];

        $results = [
            'all_present' => true,
            'present' => [],
            'missing' => [],
            'details' => [],
        ];

        try {
            $pdo = $this->getPanelDb();
            if (!$pdo) {
                $results['all_present'] = false;
                $results['missing'] = array_keys($required);
                return $results;
            }

            // Get DNS zone
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                $results['all_present'] = false;
                $results['missing'] = array_keys($required);
                $results['error'] = 'DNS zone not found';
                return $results;
            }

            $zoneId = $zone['id'];

            // Check each required record
            foreach ($required as $key => $spec) {
                $found = false;
                
                if (isset($spec['name_pattern'])) {
                    // Pattern match (for DKIM)
                    $stmt = $pdo->prepare("SELECT id, name, type, content FROM dns_records WHERE domain_id = ? AND name LIKE ? AND type = ?");
                    $stmt->execute([$zoneId, $spec['name_pattern'], $spec['type']]);
                } elseif (isset($spec['content_pattern'])) {
                    // Content pattern match (for SPF, DMARC)
                    $stmt = $pdo->prepare("SELECT id, name, type, content FROM dns_records WHERE domain_id = ? AND name = ? AND type = ? AND content LIKE ?");
                    $stmt->execute([$zoneId, $spec['name'], $spec['type'], '%' . $spec['content_pattern'] . '%']);
                } else {
                    // Exact match
                    $stmt = $pdo->prepare("SELECT id, name, type, content FROM dns_records WHERE domain_id = ? AND name = ? AND type = ?");
                    $stmt->execute([$zoneId, $spec['name'], $spec['type']]);
                }
                
                $record = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($record) {
                    $results['present'][] = $key;
                    $results['details'][$key] = [
                        'name' => $record['name'],
                        'type' => $record['type'],
                        'exists' => true,
                    ];
                } else {
                    $results['all_present'] = false;
                    $results['missing'][] = $key;
                    $results['details'][$key] = [
                        'name' => $spec['name'] ?? $spec['name_pattern'] ?? 'unknown',
                        'type' => $spec['type'],
                        'exists' => false,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to validate mail DNS records for {$domain}: " . $e->getMessage());
            $results['all_present'] = false;
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Set proper permissions on document root
     */
    private function setPermissions(string $path, string $user): void
    {
        $this->execCommand('chown', ['-R', $user . ':' . $user, $path]);
        $this->execCommand('chmod', ['-R', '755', $path]);
    }

    /**
     * Add vhost to main OLS config
     */
    private function addVhostToMainConfig(string $domain, ?string $homeDir = null, bool $includeMail = false): void
    {
        $mainConfig = $this->config['paths']['ols_config'];
        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
        $vhRoot = $homeDir ?? "/home/{$domain}";

        // Validate config file exists
        if (!file_exists($mainConfig)) {
            throw new \Exception("OLS config file not found: {$mainConfig}");
        }

        $lines = file($mainConfig, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \Exception("Failed to read OLS config file: {$mainConfig}");
        }
        
        $content = implode("\n", $lines);
        
        // PRE-VALIDATION: Check if config is already corrupted before modifying
        $existingBalance = substr_count($content, '{') - substr_count($content, '}');
        if ($existingBalance !== 0) {
            throw new \Exception("OLS config has unbalanced braces (balance: {$existingBalance}). Fix httpd_config.conf manually before creating sites.");
        }

        // Create timestamped backup before modifying
        $backupPath = $mainConfig . '.bak';
        $timestampedBackup = $mainConfig . '.backup.' . date('Y-m-d_H-i-s');
        copy($mainConfig, $backupPath);
        copy($mainConfig, $timestampedBackup);
        
        $originalLineCount = count($lines);
        $newLines = [];
        
        // 1. Add virtualhost definition if not exists (check both cases for compatibility)
        $hasVhost = stripos($content, "virtualhost {$domain} {") !== false || stripos($content, "virtualHost {$domain} {") !== false;
        
        // Track which listeners we've added mappings to
        $addedToDefault = false;
        $addedToSSL = false;
        
        // Check if mapping already exists
        $mapEntry = "  map                     {$domain} {$domain}";
        $hasMapping = strpos($content, $mapEntry) !== false;
        
        // Mail subdomain mapping â€” routes to the main domain's vhost
        $mailDomain = 'mail.' . $domain;
        $mailMapEntry = "  map                     {$domain} {$mailDomain}";
        
        $i = 0;
        $totalLines = count($lines);
        
        while ($i < $totalLines) {
            $line = $lines[$i];
            $trimmed = trim($line);
            
            // Detect listener blocks and add mapping before the closing brace
            if (!$hasMapping) {
                // Check for listener Default (HTTP)
                if (!$addedToDefault && preg_match('/^listener\s+Default\s*\{/i', $trimmed)) {
                    $newLines[] = $line;
                    $i++;
                    // Process this listener block
                    $result = $this->processListenerBlock($lines, $i, $totalLines, $domain, $includeMail);
                    $newLines = array_merge($newLines, $result['lines']);
                    $i = $result['nextIndex'];
                    $addedToDefault = true;
                    continue;
                }
                
                // Check for listener SSL (HTTPS) - but NOT "SSL IPv6"
                if (!$addedToSSL && preg_match('/^listener\s+SSL\s*\{/i', $trimmed) && !preg_match('/IPv6/i', $trimmed)) {
                    $newLines[] = $line;
                    $i++;
                    // Process this listener block
                    $result = $this->processListenerBlock($lines, $i, $totalLines, $domain, $includeMail);
                    $newLines = array_merge($newLines, $result['lines']);
                    $i = $result['nextIndex'];
                    $addedToSSL = true;
                    continue;
                }
                
            }
            
            $newLines[] = $line;
            $i++;
        }
        
        // Add virtualhost definition at end if not exists
        if (!$hasVhost) {
            $newLines[] = "";
            $newLines[] = "virtualHost {$domain} {";
            $newLines[] = '  vhRoot                  /home/$VH_NAME';
            $newLines[] = '  configFile              $SERVER_ROOT/conf/vhosts/$VH_NAME/vhost.conf';
            $newLines[] = "  allowSymbolLink         1";
            $newLines[] = "  enableScript            1";
            $newLines[] = "  restrained              1";
            $newLines[] = "}";
        }
        
        // Ensure file ends with newline
        $finalContent = implode("\n", $newLines);
        if (substr($finalContent, -1) !== "\n") {
            $finalContent .= "\n";
        }
        
        // Validation: new content should have at least as many lines as original
        $newLineCount = count($newLines);
        if ($newLineCount < $originalLineCount) {
            throw new \Exception("OLS config validation failed: new content has fewer lines ({$newLineCount}) than original ({$originalLineCount})");
        }
        
        // Validation: check that all listener blocks are properly closed
        $braceBalance = substr_count($finalContent, '{') - substr_count($finalContent, '}');
        if ($braceBalance !== 0) {
            throw new \Exception("OLS config validation failed: unbalanced braces (balance: {$braceBalance}) after modification");
        }
        
        // Clean and write the config
        $finalContent = $this->cleanOlsConfig($finalContent);
        if (file_put_contents($mainConfig, $finalContent) === false) {
            throw new \Exception("Failed to write OLS config file: {$mainConfig}");
        }
        
        // Restore correct ownership and permissions (lsadm:nogroup, 0644)
        $this->execCommand('chown', ['lsadm:nogroup', $mainConfig]);
        $this->execCommand('chmod', ['0644', $mainConfig]);
        
        $this->logger->info("Updated OLS config for domain: {$domain}");
    }
    
    /**
     * Process a listener block and add domain mapping before the closing brace
     * Properly handles nested braces
     */
    private function processListenerBlock(array $lines, int $startIndex, int $totalLines, string $domain, bool $includeMail = false): array
    {
        $result = [];
        $braceCount = 1; // We already saw the opening brace
        $i = $startIndex;
        $mapEntry = "  map                     {$domain} {$domain}";
        // Mail mapping - route mail subdomain to the main domain's vhost
        $mailMapEntry = "  map                     {$domain} mail.{$domain}";
        $lastMapLineIndex = -1;
        
        while ($i < $totalLines && $braceCount > 0) {
            $line = $lines[$i];
            $trimmed = trim($line);
            
            // Count braces
            $braceCount += substr_count($line, '{');
            $braceCount -= substr_count($line, '}');
            
            // Track the last map line (only at brace level 1, not inside nested blocks)
            if ($braceCount === 1 && preg_match('/^\s*map\s+/', $line)) {
                $lastMapLineIndex = count($result);
            }
            
            // If this is the closing brace of the listener block (braceCount becomes 0)
            if ($braceCount === 0) {
                // Insert the new mappings before the closing brace
                // Find proper insertion point - after the last map entry or before closing brace
                $newMappings = [$mapEntry];
                if ($includeMail) {
                    $newMappings[] = $mailMapEntry;
                }
                
                if ($lastMapLineIndex >= 0) {
                    // Insert after the last map entry
                    array_splice($result, $lastMapLineIndex + 1, 0, $newMappings);
                } else {
                    // No map entries found, insert before closing brace
                    foreach ($newMappings as $mapping) {
                        $result[] = $mapping;
                    }
                }
                $result[] = $line;
            } else {
                $result[] = $line;
            }
            
            $i++;
        }
        
        return [
            'lines' => $result,
            'nextIndex' => $i
        ];
    }

    /**
     * Remove vhost from main OLS config
     * Properly handles nested braces in virtualhost blocks
     */
    private function removeVhostFromMainConfig(string $domain): void
    {
        $mainConfig = $this->config['paths']['ols_config'];
        
        // Validate config file exists
        if (!file_exists($mainConfig)) {
            $this->logger->error("OLS config file not found: {$mainConfig}");
            return;
        }
        
        // Create timestamped backup before modifying
        $backupPath = $mainConfig . '.bak';
        $timestampedBackup = $mainConfig . '.backup.' . date('Y-m-d_H-i-s');
        copy($mainConfig, $backupPath);
        copy($mainConfig, $timestampedBackup);
        
        $lines = file($mainConfig, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $this->logger->error("Failed to read OLS config file: {$mainConfig}");
            return;
        }
        
        $newLines = [];
        $i = 0;
        $totalLines = count($lines);
        
        while ($i < $totalLines) {
            $line = $lines[$i];
            $trimmed = trim($line);
            
            // Check if this is the virtualhost block we want to remove
            if (preg_match('/^virtualhost\s+' . preg_quote($domain, '/') . '\s*\{/i', $trimmed)) {
                // Skip this entire block (properly counting nested braces)
                $braceCount = 1;
                $i++; // Move past the opening line
                
                while ($i < $totalLines && $braceCount > 0) {
                    $braceCount += substr_count($lines[$i], '{');
                    $braceCount -= substr_count($lines[$i], '}');
                    $i++;
                }
                
                // Remove any trailing empty line after the block
                if ($i < $totalLines && trim($lines[$i]) === '') {
                    $i++;
                }
                continue;
            }
            
            // Check if this is a map entry for the domain we're removing
            // Format: map <vhost_name> <domain_mapped> - remove if vhost matches our domain
            if (preg_match('/^\s*map\s+' . preg_quote($domain, '/') . '\s+/i', $trimmed)) {
                // Skip this line (removes main domain and any subdomain mappings like mail.domain)
                $i++;
                continue;
            }
            
            $newLines[] = $line;
            $i++;
        }
        
        // Clean up multiple consecutive empty lines
        $cleanedLines = [];
        $prevEmpty = false;
        foreach ($newLines as $line) {
            $isEmpty = trim($line) === '';
            if ($isEmpty && $prevEmpty) {
                continue; // Skip consecutive empty lines
            }
            $cleanedLines[] = $line;
            $prevEmpty = $isEmpty;
        }
        
        // Ensure file ends with newline
        $finalContent = implode("\n", $cleanedLines);
        if (substr($finalContent, -1) !== "\n") {
            $finalContent .= "\n";
        }
        
        // Validation: check that all blocks are properly closed
        $braceBalance = substr_count($finalContent, '{') - substr_count($finalContent, '}');
        
        if ($braceBalance !== 0) {
            $this->logger->error("Config validation failed during removal: unbalanced braces (balance: {$braceBalance}). Aborting write.");
            return;
        }
        
        // Validation: new content should not be empty
        if (strlen(trim($finalContent)) < 100) {
            $this->logger->error("Config validation failed during removal: content too short. Aborting write.");
            return;
        }
        
        // Clean and write the config
        $finalContent = $this->cleanOlsConfig($finalContent);
        if (file_put_contents($mainConfig, $finalContent) === false) {
            $this->logger->error("Failed to write OLS config file: {$mainConfig}");
            return;
        }
        
        // Restore correct ownership and permissions (lsadm:nogroup, 0644)
        $this->execCommand('chown', ['lsadm:nogroup', $mainConfig]);
        $this->execCommand('chmod', ['0644', $mainConfig]);
        
        $this->logger->info("Removed domain from OLS config: {$domain}");
    }

    /**
     * Get vhost configuration file content with structured parsing
     */
    protected function actionConfig(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }

        // Find config file
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Configuration file not found for {$domain}");
        }

        $config = file_get_contents($configFile);
        
        // Parse into structured format for cell editing
        $parsed = $this->parseConfigStructured($config);
        
        return $this->success([
            'domain' => $domain,
            'config' => $config,
            'path' => $configFile,
            'parsed' => $parsed,
        ]);
    }
    
    /**
     * Parse vhost config into structured sections for cell editing
     */
    private function parseConfigStructured(string $config): array
    {
        $sections = [];
        $lines = explode("\n", $config);
        $currentSection = 'General';
        $sectionStack = [];
        $sectionId = 0;
        
        // Track which section we're in
        $inBlock = false;
        $blockName = '';
        $blockPath = '';
        
        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmed) || $trimmed[0] === '#') {
                continue;
            }
            
            // Check for block start: "directive value {" or "directive {"
            if (preg_match('/^(\w+)\s*(.+?)?\s*\{$/', $trimmed, $match)) {
                $blockName = $match[1];
                $blockPath = $match[2] ?? '';
                $currentSection = $blockName . ($blockPath ? " ({$blockPath})" : '');
                $sectionStack[] = $currentSection;
                $inBlock = true;
                
                // Add section header
                if (!isset($sections[$currentSection])) {
                    $sections[$currentSection] = [
                        'id' => $sectionId++,
                        'name' => $blockName,
                        'path' => trim($blockPath),
                        'items' => [],
                    ];
                }
                
                // If block has a path value, add it as first item
                if (!empty($blockPath)) {
                    $sections[$currentSection]['items'][] = [
                        'key' => $blockName,
                        'value' => trim($blockPath),
                        'line' => $lineNum,
                        'editable' => true,
                        'type' => 'path',
                    ];
                }
                continue;
            }
            
            // Check for block end
            if ($trimmed === '}') {
                if (!empty($sectionStack)) {
                    array_pop($sectionStack);
                }
                $currentSection = !empty($sectionStack) ? end($sectionStack) : 'General';
                continue;
            }
            
            // Parse key-value pairs
            // Handle: key value OR key  value (multiple spaces)
            if (preg_match('/^(\w+)\s+(.+)$/', $trimmed, $match)) {
                $key = $match[1];
                $value = trim($match[2]);
                
                // Ensure section exists
                if (!isset($sections[$currentSection])) {
                    $sections[$currentSection] = [
                        'id' => $sectionId++,
                        'name' => $currentSection,
                        'path' => '',
                        'items' => [],
                    ];
                }
                
                // Determine value type and editability
                $type = $this->detectValueType($key, $value);
                $editable = $this->isValueEditable($key);
                
                $sections[$currentSection]['items'][] = [
                    'key' => $key,
                    'value' => $value,
                    'line' => $lineNum,
                    'editable' => $editable,
                    'type' => $type,
                ];
            }
        }
        
        return array_values($sections);
    }
    
    /**
     * Detect the type of a config value
     */
    private function detectValueType(string $key, string $value): string
    {
        // Boolean types (0/1)
        if (in_array($key, ['enableGzip', 'enableIpGeo', 'useServer', 'enableECDHE', 
            'renegProtection', 'sslSessionCache', 'enableStapling', 'enableSpdy',
            'allowBrowse', 'autoStart', 'persistConn', 'respBuffer', 'autoLoadHtaccess'])) {
            return 'boolean';
        }
        
        // Number types
        if (in_array($key, ['maxConns', 'initTimeout', 'retryTimeout', 'pcKeepAliveTimeout',
            'rollingSize', 'keepDays', 'logHeaders', 'ocspRespMaxAge', 'enableSpdy',
            'sslProtocol', 'certChain', 'compressArchive'])) {
            return 'number';
        }
        
        // Select types
        if ($key === 'logLevel') {
            return 'select:DEBUG,INFO,NOTICE,WARN,ERROR';
        }
        if ($key === 'type') {
            return 'select:lsapi,fcgi,servlet,proxy';
        }
        
        // Path types
        if (in_array($key, ['docRoot', 'path', 'location', 'keyFile', 'certFile', 
            'errorlog', 'accesslog', 'address'])) {
            return 'path';
        }
        
        // Email type
        if ($key === 'adminEmails') {
            return 'email';
        }
        
        return 'text';
    }
    
    /**
     * Check if a config value should be editable
     */
    private function isValueEditable(string $key): bool
    {
        // These are system-managed or dangerous to edit
        $nonEditable = [
            'vhDomain', // Should use proper domain management
            'vhAliases', // Tied to vhDomain
        ];
        
        return !in_array($key, $nonEditable);
    }
    
    /**
     * Update specific config values (targeted replacement - safe)
     */
    protected function actionUpdateConfigValues(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['changes'])) {
            return $this->error('Domain and changes are required');
        }

        $domain = $params['domain'];
        $changes = $params['changes']; // Array of { section, key, value, oldValue }
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }
        
        if (!is_array($changes) || empty($changes)) {
            return $this->error('No changes provided');
        }

        // Find config file
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Configuration file not found for {$domain}");
        }

        // Get original file stats
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;
        
        // Backup current config
        $backupPath = $this->backupFile($configFile, 'updateValues', $actor);
        
        // Read current config
        $config = file_get_contents($configFile);
        $originalConfig = $config;
        
        $applied = [];
        $failed = [];
        
        foreach ($changes as $change) {
            $key = $change['key'] ?? null;
            $newValue = $change['value'] ?? null;
            $oldValue = $change['oldValue'] ?? null;
            $section = $change['section'] ?? null;
            
            if (!$key || $newValue === null) {
                $failed[] = ['key' => $key, 'reason' => 'Missing key or value'];
                continue;
            }
            
            // Validate the value based on type
            $validationError = $this->validateConfigValue($key, $newValue);
            if ($validationError) {
                $failed[] = ['key' => $key, 'reason' => $validationError];
                continue;
            }
            
            // Build regex pattern for this key
            // Match: key    oldValue (with flexible whitespace)
            // Replace with: key    newValue (preserving original whitespace)
            
            if ($oldValue !== null) {
                // If we have old value, be more specific
                $pattern = '/^(\s*' . preg_quote($key, '/') . '\s+)' . preg_quote($oldValue, '/') . '(\s*)$/m';
                $replacement = '${1}' . $newValue . '${2}';
            } else {
                // Match any value for this key
                $pattern = '/^(\s*' . preg_quote($key, '/') . '\s+)\S.*?(\s*)$/m';
                $replacement = '${1}' . $newValue . '${2}';
            }
            
            $newConfig = preg_replace($pattern, $replacement, $config, 1, $count);
            
            if ($count > 0) {
                $config = $newConfig;
                $applied[] = ['key' => $key, 'oldValue' => $oldValue, 'newValue' => $newValue];
            } else {
                $failed[] = ['key' => $key, 'reason' => 'Pattern not found in config'];
            }
        }
        
        // Only write if something changed
        if (!empty($applied)) {
            // Generate diff
            $diff = $this->diff->fromContent($originalConfig, $config, $domain);
            
            // Write new config
            file_put_contents($configFile, $config);
            
            // Restore ownership and permissions
            chown($configFile, $origOwner);
            chgrp($configFile, $origGroup);
            chmod($configFile, $origPerms);
            
            // Reload OLS
            $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['reload']);
            
            return $this->success([
                'domain' => $domain,
                'applied' => $applied,
                'failed' => $failed,
                'backup' => $backupPath,
                'diff' => $diff,
            ], count($applied) . ' value(s) updated successfully. OpenLiteSpeed reloaded.');
        }
        
        return $this->error('No changes were applied. ' . json_encode($failed));
    }
    
    /**
     * Validate a config value before applying
     */
    private function validateConfigValue(string $key, string $value): ?string
    {
        // Boolean fields must be 0 or 1
        $booleanKeys = ['enableGzip', 'enableIpGeo', 'useServer', 'enableECDHE', 
            'renegProtection', 'sslSessionCache', 'enableStapling', 'allowBrowse', 
            'autoStart', 'persistConn', 'respBuffer', 'autoLoadHtaccess', 'certChain',
            'compressArchive'];
        if (in_array($key, $booleanKeys)) {
            if (!in_array($value, ['0', '1'])) {
                return "Value must be 0 or 1";
            }
        }
        
        // Numeric fields
        $numericKeys = ['maxConns', 'initTimeout', 'retryTimeout', 'pcKeepAliveTimeout',
            'keepDays', 'logHeaders', 'ocspRespMaxAge', 'enableSpdy', 'sslProtocol'];
        if (in_array($key, $numericKeys)) {
            if (!is_numeric($value)) {
                return "Value must be numeric";
            }
        }
        
        // Log level
        if ($key === 'logLevel') {
            $validLevels = ['DEBUG', 'INFO', 'NOTICE', 'WARN', 'ERROR'];
            if (!in_array(strtoupper($value), $validLevels)) {
                return "Invalid log level. Use: " . implode(', ', $validLevels);
            }
        }
        
        // Rolling size must end with M or G
        if ($key === 'rollingSize') {
            if (!preg_match('/^\d+[MG]$/', $value)) {
                return "Value must be like 10M or 1G";
            }
        }
        
        // Email validation
        if ($key === 'adminEmails') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return "Invalid email address";
            }
        }
        
        // Paths should not contain dangerous characters
        $pathKeys = ['docRoot', 'path', 'location', 'keyFile', 'certFile'];
        if (in_array($key, $pathKeys)) {
            if (preg_match('/[;&|`$]/', $value)) {
                return "Path contains invalid characters";
            }
        }
        
        return null; // Valid
    }

    /**
     * Save vhost configuration file
     */
    protected function actionSaveConfig(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['config'])) {
            return $this->error('Domain and config are required');
        }

        $domain = $params['domain'];
        $newConfig = $params['config'];
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }

        // Find config file
        $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Configuration file not found for {$domain}");
        }

        // SAFETY: Validate config has required sections before saving
        $requiredPatterns = [
            'docRoot' => '/docRoot\s+\S+/',
            'extprocessor block' => '/extprocessor\s+\w+\s*\{[^}]*\}/s',
            'scripthandler' => '/scripthandler\s*\{[^}]*\}/s',
        ];
        
        $errors = [];
        foreach ($requiredPatterns as $name => $pattern) {
            if (!preg_match($pattern, $newConfig)) {
                $errors[] = "Missing or malformed: {$name}";
            }
        }
        
        // Check for balanced braces
        $openBraces = substr_count($newConfig, '{');
        $closeBraces = substr_count($newConfig, '}');
        if ($openBraces !== $closeBraces) {
            $errors[] = "Unbalanced braces: {$openBraces} open, {$closeBraces} close";
        }
        
        // Check for common syntax errors
        if (preg_match('/^\s*\{/m', $newConfig)) {
            $errors[] = "Orphan opening brace found (brace on line by itself without directive)";
        }
        
        if (!empty($errors)) {
            return $this->error("Config validation failed:\n- " . implode("\n- ", $errors));
        }

        // Get original file ownership and permissions before making changes
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;
        
        // Backup current config
        $backupPath = $this->backupFile($configFile, 'saveConfig', $actor);

        // Read current config for diff
        $currentConfig = file_get_contents($configFile);

        // Generate diff
        $diff = $this->diff->fromContent($currentConfig, $newConfig, basename($configFile));

        // Write new config
        file_put_contents($configFile, $newConfig);
        
        // Restore original ownership and permissions (lsadm:nogroup for OLS)
        chown($configFile, $origOwner);
        chgrp($configFile, $origGroup);
        chmod($configFile, $origPerms);

        // Reload OLS to apply changes (use reload instead of restart for faster apply)
        $this->execCommand($this->config['paths']['ols_bin'] . '/lswsctrl', ['reload']);

        return $this->success([
            'domain' => $domain,
            'backup' => $backupPath,
            'diff' => $diff,
        ], "Configuration for {$domain} saved. OpenLiteSpeed reloaded.");
    }

    /**
     * Get vhost logs
     */
    protected function actionLogs(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $type = $params['type'] ?? 'error';
        $lines = (int) ($params['lines'] ?? 100);
        
        if (!Validator::hostname($domain)) {
            return $this->error('Invalid domain format');
        }

        // Determine log file path
        $logsPath = $this->config['paths']['ols_logs'] ?? '/usr/local/lsws/logs';
        
        if ($type === 'error') {
            // Try domain-specific error log first, then general error log
            $logFile = $logsPath . '/' . $domain . '.error_log';
            if (!file_exists($logFile)) {
                $logFile = $logsPath . '/error.log';
            }
        } else {
            // Access log
            $logFile = $logsPath . '/' . $domain . '.access_log';
            if (!file_exists($logFile)) {
                $logFile = $logsPath . '/access.log';
            }
        }

        if (!file_exists($logFile)) {
            return $this->success([
                'domain' => $domain,
                'type' => $type,
                'lines' => [],
                'path' => $logFile,
            ], 'Log file not found');
        }

        // Read last N lines using tail
        $output = [];
        exec("tail -n {$lines} " . escapeshellarg($logFile), $output);

        return $this->success([
            'domain' => $domain,
            'type' => $type,
            'lines' => $output,
            'path' => $logFile,
            'count' => count($output),
        ]);
    }

    /**
     * Get FTP/SFTP status
     */
    protected function actionFtpStatus(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        // Check if FTP (vsftpd or proftpd) is running
        $ftpActive = false;
        exec('systemctl is-active vsftpd 2>/dev/null', $vsftpd, $vsftpdCode);
        exec('systemctl is-active proftpd 2>/dev/null', $proftpd, $proftpdCode);
        exec('systemctl is-active pure-ftpd 2>/dev/null', $pureftpd, $pureftpdCode);
        
        if ($vsftpdCode === 0 || $proftpdCode === 0 || $pureftpdCode === 0) {
            $ftpActive = true;
        }

        // SFTP is always available via SSH
        $sftpActive = true;
        exec('systemctl is-active sshd 2>/dev/null', $sshdOutput, $sshdCode);
        exec('systemctl is-active ssh 2>/dev/null', $sshOutput, $sshCode);
        if ($sshdCode !== 0 && $sshCode !== 0) {
            $sftpActive = false;
        }

        // Get SSH port from sshd_config
        $sshPort = 22;
        if (file_exists('/etc/ssh/sshd_config')) {
            $sshdConfig = file_get_contents('/etc/ssh/sshd_config');
            // Match Port directive (not commented out)
            if (preg_match('/^Port\s+(\d+)/m', $sshdConfig, $matches)) {
                $sshPort = (int) $matches[1];
            }
        }

        // Get the site user - check multiple sources
        $homeDir = '/home/' . $domain;
        $siteUser = null;
        $siteUid = null;
        $siteGid = null;
        
        // Method 1: Get from public_html ownership (most reliable - always has correct owner)
        $publicHtml = $homeDir . '/public_html';
        if (is_dir($publicHtml)) {
            $stat = stat($publicHtml);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                    $siteUser = $userInfo['name'];
                    $siteUid = $stat['uid'];
                    $siteGid = $stat['gid'];
                }
            }
        }
        
        // Method 2: Get from .ssh directory ownership (if exists)
        if (!$siteUser) {
            $sshDir = $homeDir . '/.ssh';
            if (is_dir($sshDir)) {
                $stat = stat($sshDir);
                if ($stat) {
                    $userInfo = posix_getpwuid($stat['uid']);
                    if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                        $siteUser = $userInfo['name'];
                        $siteUid = $stat['uid'];
                        $siteGid = $stat['gid'];
                    }
                }
            }
        }
        
        // Method 3: Get from home directory ownership
        if (!$siteUser && is_dir($homeDir)) {
            $stat = stat($homeDir);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                    $siteUser = $userInfo['name'];
                    $siteUid = $stat['uid'];
                    $siteGid = $stat['gid'];
                }
            }
        }
        
        // Method 4: Try vhost config's extUser directive as last fallback
        if (!$siteUser) {
            $configFile = $this->config['paths']['ols_vhosts'] . '/' . $domain . '/vhost.conf';
            if (file_exists($configFile)) {
                $config = file_get_contents($configFile);
                if (preg_match('/extUser\s+(\S+)/m', $config, $matches)) {
                    $potentialUser = $matches[1];
                    // Only use if it's not www-data/nobody
                    if ($potentialUser !== 'www-data' && $potentialUser !== 'nobody') {
                        $siteUser = $potentialUser;
                    }
                }
            }
        }

        // Get SSH keys for the site user
        $sshKeys = [];
        $sshDir = $homeDir . '/.ssh';
        $authorizedKeysFile = $sshDir . '/authorized_keys';
        
        if (file_exists($authorizedKeysFile)) {
            $keys = file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($keys as $key) {
                $key = trim($key);
                if (!empty($key) && !str_starts_with($key, '#')) {
                    $sshKeys[] = $key;
                }
            }
        }

        // Check SSH permissions
        $sshPermissions = $this->checkSshPermissions($domain, $homeDir, $siteUser);

        return $this->success([
            'domain' => $domain,
            'ftp' => $ftpActive,
            'sftp' => $sftpActive,
            'sshPort' => $sshPort,
            'siteUser' => $siteUser,
            'ssh_keys' => $sshKeys,
            'ssh_permissions' => $sshPermissions,
        ]);
    }
    
    /**
     * Check SSH directory and file permissions
     */
    private function checkSshPermissions(string $domain, string $homeDir, ?string $expectedUser): array
    {
        $result = [
            'home_dir' => [
                'path' => $homeDir,
                'exists' => false,
                'owner' => null,
                'group' => null,
                'perms' => null,
                'perms_octal' => null,
                'correct' => false,
            ],
            'ssh_dir' => [
                'path' => $homeDir . '/.ssh',
                'exists' => false,
                'owner' => null,
                'group' => null,
                'perms' => null,
                'perms_octal' => null,
                'correct' => false,
                'expected_perms' => '0700',
            ],
            'authorized_keys' => [
                'path' => $homeDir . '/.ssh/authorized_keys',
                'exists' => false,
                'owner' => null,
                'group' => null,
                'perms' => null,
                'perms_octal' => null,
                'correct' => false,
                'expected_perms' => '0600',
            ],
            'expected_owner' => $expectedUser,
            'all_correct' => false,
        ];
        
        // Check home directory
        if (is_dir($homeDir)) {
            $result['home_dir']['exists'] = true;
            $stat = stat($homeDir);
            if ($stat) {
                $ownerInfo = posix_getpwuid($stat['uid']);
                $groupInfo = posix_getgrgid($stat['gid']);
                $result['home_dir']['owner'] = $ownerInfo ? $ownerInfo['name'] : (string)$stat['uid'];
                $result['home_dir']['group'] = $groupInfo ? $groupInfo['name'] : (string)$stat['gid'];
                $result['home_dir']['perms_octal'] = sprintf('%04o', $stat['mode'] & 0777);
                $result['home_dir']['perms'] = $this->formatPermissions($stat['mode']);
                // Home dir should be 755 or 750 - root ownership is OK (CyberPanel style)
                $homePermsOk = in_array($result['home_dir']['perms_octal'], ['0755', '0750', '0700']);
                // Root ownership is acceptable for home directory in CyberPanel setups
                $homeOwnerOk = $result['home_dir']['owner'] === 'root' || ($expectedUser && $result['home_dir']['owner'] === $expectedUser);
                $result['home_dir']['correct'] = $homePermsOk && $homeOwnerOk;
            }
        }
        
        // Check .ssh directory
        $sshDir = $homeDir . '/.ssh';
        if (is_dir($sshDir)) {
            $result['ssh_dir']['exists'] = true;
            $stat = stat($sshDir);
            if ($stat) {
                $ownerInfo = posix_getpwuid($stat['uid']);
                $groupInfo = posix_getgrgid($stat['gid']);
                $result['ssh_dir']['owner'] = $ownerInfo ? $ownerInfo['name'] : (string)$stat['uid'];
                $result['ssh_dir']['group'] = $groupInfo ? $groupInfo['name'] : (string)$stat['gid'];
                $result['ssh_dir']['perms_octal'] = sprintf('%04o', $stat['mode'] & 0777);
                $result['ssh_dir']['perms'] = $this->formatPermissions($stat['mode']);
                // .ssh dir must be 700 and owned by site user
                $sshDirPermsOk = $result['ssh_dir']['perms_octal'] === '0700';
                $sshDirOwnerOk = $expectedUser && $result['ssh_dir']['owner'] === $expectedUser;
                $result['ssh_dir']['correct'] = $sshDirPermsOk && $sshDirOwnerOk;
            }
        }
        
        // Check authorized_keys file
        $authorizedKeysFile = $sshDir . '/authorized_keys';
        if (file_exists($authorizedKeysFile)) {
            $result['authorized_keys']['exists'] = true;
            $stat = stat($authorizedKeysFile);
            if ($stat) {
                $ownerInfo = posix_getpwuid($stat['uid']);
                $groupInfo = posix_getgrgid($stat['gid']);
                $result['authorized_keys']['owner'] = $ownerInfo ? $ownerInfo['name'] : (string)$stat['uid'];
                $result['authorized_keys']['group'] = $groupInfo ? $groupInfo['name'] : (string)$stat['gid'];
                $result['authorized_keys']['perms_octal'] = sprintf('%04o', $stat['mode'] & 0777);
                $result['authorized_keys']['perms'] = $this->formatPermissions($stat['mode']);
                // authorized_keys must be 600 and owned by site user
                $keyPermsOk = $result['authorized_keys']['perms_octal'] === '0600';
                $keyOwnerOk = $expectedUser && $result['authorized_keys']['owner'] === $expectedUser;
                $result['authorized_keys']['correct'] = $keyPermsOk && $keyOwnerOk;
            }
        }
        
        // Overall status
        $result['all_correct'] = 
            $result['home_dir']['correct'] && 
            (!$result['ssh_dir']['exists'] || $result['ssh_dir']['correct']) &&
            (!$result['authorized_keys']['exists'] || $result['authorized_keys']['correct']);
        
        return $result;
    }
    
    /**
     * Format file permissions as string
     */
    private function formatPermissions(int $mode): string
    {
        $perms = '';
        $perms .= ($mode & 0x0100) ? 'r' : '-';
        $perms .= ($mode & 0x0080) ? 'w' : '-';
        $perms .= ($mode & 0x0040) ? 'x' : '-';
        $perms .= ($mode & 0x0020) ? 'r' : '-';
        $perms .= ($mode & 0x0010) ? 'w' : '-';
        $perms .= ($mode & 0x0008) ? 'x' : '-';
        $perms .= ($mode & 0x0004) ? 'r' : '-';
        $perms .= ($mode & 0x0002) ? 'w' : '-';
        $perms .= ($mode & 0x0001) ? 'x' : '-';
        return $perms;
    }
    
    /**
     * Fix SSH permissions for a domain
     * Sets correct ownership and permissions for home, .ssh, and authorized_keys
     */
    protected function actionFixSshPermissions(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $homeDir = '/home/' . $domain;
        
        if (!is_dir($homeDir)) {
            return $this->error("Home directory not found: {$homeDir}");
        }
        
        // Get the site user - try multiple sources since home dir might be owned by root
        $siteUser = null;
        $siteUid = null;
        $siteGid = null;
        
        // Try public_html first (most reliable)
        $publicHtml = $homeDir . '/public_html';
        if (is_dir($publicHtml)) {
            $stat = stat($publicHtml);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                    $siteUser = $userInfo['name'];
                    $siteUid = $stat['uid'];
                    $siteGid = $stat['gid'];
                }
            }
        }
        
        // Try .ssh directory
        if (!$siteUser) {
            $sshDirCheck = $homeDir . '/.ssh';
            if (is_dir($sshDirCheck)) {
                $stat = stat($sshDirCheck);
                if ($stat) {
                    $userInfo = posix_getpwuid($stat['uid']);
                    if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                        $siteUser = $userInfo['name'];
                        $siteUid = $stat['uid'];
                        $siteGid = $stat['gid'];
                    }
                }
            }
        }
        
        // Try home directory itself
        if (!$siteUser) {
            $stat = stat($homeDir);
            if ($stat) {
                $userInfo = posix_getpwuid($stat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody' && $userInfo['name'] !== 'www-data') {
                    $siteUser = $userInfo['name'];
                    $siteUid = $stat['uid'];
                    $siteGid = $stat['gid'];
                }
            }
        }
        
        if (!$siteUser) {
            return $this->error("Cannot determine site user. Check public_html or .ssh directory ownership.");
        }
        
        $fixed = [];
        $errors = [];
        
        // Home directory ownership by root is OK in CyberPanel setups - don't change it
        // Only fix permissions if needed
        $homeStat = stat($homeDir);
        if ($homeStat) {
            $homePerms = $homeStat['mode'] & 0777;
            if ($homePerms !== 0755) {
                if (chmod($homeDir, 0755)) {
                    $fixed[] = "Set {$homeDir} permissions to 755";
                } else {
                    $errors[] = "Failed to set {$homeDir} permissions";
                }
            }
        }
        
        $sshDir = $homeDir . '/.ssh';
        $authorizedKeysFile = $sshDir . '/authorized_keys';
        
        // Create .ssh directory if it doesn't exist
        if (!is_dir($sshDir)) {
            if (mkdir($sshDir, 0700, true)) {
                $fixed[] = "Created {$sshDir}";
            } else {
                $errors[] = "Failed to create {$sshDir}";
                return $this->error("Failed to create .ssh directory", ['fixed' => $fixed, 'errors' => $errors]);
            }
        }
        
        // Fix .ssh directory ownership
        $sshStat = stat($sshDir);
        if ($sshStat) {
            if ($sshStat['uid'] !== $siteUid || $sshStat['gid'] !== $siteGid) {
                if (chown($sshDir, $siteUid) && chgrp($sshDir, $siteGid)) {
                    $fixed[] = "Set {$sshDir} ownership to {$siteUser}:{$siteUser}";
                } else {
                    $errors[] = "Failed to set {$sshDir} ownership";
                }
            }
            
            // Fix .ssh directory permissions (700)
            $sshPerms = $sshStat['mode'] & 0777;
            if ($sshPerms !== 0700) {
                if (chmod($sshDir, 0700)) {
                    $fixed[] = "Set {$sshDir} permissions to 700";
                } else {
                    $errors[] = "Failed to set {$sshDir} permissions";
                }
            }
        }
        
        // Fix authorized_keys file if it exists
        if (file_exists($authorizedKeysFile)) {
            $keyStat = stat($authorizedKeysFile);
            if ($keyStat) {
                if ($keyStat['uid'] !== $siteUid || $keyStat['gid'] !== $siteGid) {
                    if (chown($authorizedKeysFile, $siteUid) && chgrp($authorizedKeysFile, $siteGid)) {
                        $fixed[] = "Set {$authorizedKeysFile} ownership to {$siteUser}:{$siteUser}";
                    } else {
                        $errors[] = "Failed to set {$authorizedKeysFile} ownership";
                    }
                }
                
                // Fix authorized_keys permissions (600)
                $keyPerms = $keyStat['mode'] & 0777;
                if ($keyPerms !== 0600) {
                    if (chmod($authorizedKeysFile, 0600)) {
                        $fixed[] = "Set {$authorizedKeysFile} permissions to 600";
                    } else {
                        $errors[] = "Failed to set {$authorizedKeysFile} permissions";
                    }
                }
            }
        }
        
        // Get updated permission status
        $permStatus = $this->checkSshPermissions($domain, $homeDir, $siteUser);
        
        if (count($errors) > 0) {
            return $this->success([
                'domain' => $domain,
                'site_user' => $siteUser,
                'fixed' => $fixed,
                'errors' => $errors,
                'ssh_permissions' => $permStatus,
                'message' => count($fixed) . ' items fixed, ' . count($errors) . ' errors',
            ], 'Partially fixed');
        }
        
        if (count($fixed) === 0) {
            return $this->success([
                'domain' => $domain,
                'site_user' => $siteUser,
                'fixed' => [],
                'errors' => [],
                'ssh_permissions' => $permStatus,
                'message' => 'All permissions were already correct',
            ], 'No changes needed');
        }
        
        return $this->success([
            'domain' => $domain,
            'site_user' => $siteUser,
            'fixed' => $fixed,
            'errors' => [],
            'ssh_permissions' => $permStatus,
            'message' => count($fixed) . ' items fixed',
        ], 'Permissions fixed');
    }

    /**
     * Get SSH keys for site user
     */
    protected function actionSshKeys(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $homeDir = '/home/' . $domain;
        $authorizedKeysFile = $homeDir . '/.ssh/authorized_keys';
        
        $sshKeys = [];
        if (file_exists($authorizedKeysFile)) {
            $keys = file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($keys as $key) {
                $key = trim($key);
                if (!empty($key) && !str_starts_with($key, '#')) {
                    $sshKeys[] = $key;
                }
            }
        }

        return $this->success([
            'domain' => $domain,
            'keys' => $sshKeys,
        ]);
    }

    /**
     * Add SSH key for site user
     */
    protected function actionAddSshKey(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['key'])) {
            return $this->error('Domain and key are required');
        }

        $domain = $params['domain'];
        $key = trim($params['key']);
        
        // Validate key format
        if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp\d+|ssh-dss)\s+[A-Za-z0-9+\/=]+/', $key)) {
            return $this->error('Invalid SSH key format');
        }

        $homeDir = '/home/' . $domain;
        if (!is_dir($homeDir)) {
            return $this->error("Home directory not found for {$domain}");
        }

        $sshDir = $homeDir . '/.ssh';
        $authorizedKeysFile = $sshDir . '/authorized_keys';
        
        // Try to find the site user from multiple sources
        $siteUser = $this->findSiteUser($domain, $homeDir, $sshDir);
        
        if (!$siteUser) {
            return $this->error("Could not determine site user for {$domain}. Please create an SFTP user first.");
        }
        
        $userInfo = posix_getpwnam($siteUser);
        if (!$userInfo) {
            return $this->error("Site user '{$siteUser}' does not exist");
        }
        
        $siteUid = $userInfo['uid'];
        $siteGid = $userInfo['gid'];
        
        // Create .ssh directory if it doesn't exist
        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
            chown($sshDir, $siteUid);
            chgrp($sshDir, $siteGid);
        }

        // Read existing keys
        $existingKeys = [];
        if (file_exists($authorizedKeysFile)) {
            $existingKeys = file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        // Check if key already exists
        foreach ($existingKeys as $existing) {
            if (trim($existing) === $key) {
                return $this->error('This key already exists');
            }
        }

        // Add new key
        $existingKeys[] = $key;
        file_put_contents($authorizedKeysFile, implode("\n", $existingKeys) . "\n");
        chmod($authorizedKeysFile, 0600);
        
        // Set ownership
        chown($authorizedKeysFile, $siteUid);
        chgrp($authorizedKeysFile, $siteGid);
        
        // Also fix .ssh directory ownership if needed
        chown($sshDir, $siteUid);
        chgrp($sshDir, $siteGid);

        return $this->success([
            'domain' => $domain,
            'site_user' => $siteUser,
            'keys_count' => count($existingKeys),
        ], 'SSH key added successfully');
    }
    
    /**
     * Find the site user for a domain from multiple sources
     */
    private function findSiteUser(string $domain, string $homeDir, string $sshDir): ?string
    {
        // 1. Check .ssh directory ownership first (most reliable if it exists)
        if (is_dir($sshDir)) {
            $sshStat = stat($sshDir);
            if ($sshStat) {
                $userInfo = posix_getpwuid($sshStat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                    return $userInfo['name'];
                }
            }
        }
        
        // 2. Check home directory ownership
        $homeStat = stat($homeDir);
        if ($homeStat) {
            $userInfo = posix_getpwuid($homeStat['uid']);
            if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                return $userInfo['name'];
            }
        }
        
        // 3. Check public_html ownership
        $publicHtml = $homeDir . '/public_html';
        if (is_dir($publicHtml)) {
            $publicStat = stat($publicHtml);
            if ($publicStat) {
                $userInfo = posix_getpwuid($publicStat['uid']);
                if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'nobody') {
                    return $userInfo['name'];
                }
            }
        }
        
        // 4. Try to find user by domain name patterns
        $domainClean = str_replace(['.', '-'], ['_', ''], $domain);
        $possibleUsers = [
            $domainClean,                    // ciaobella_eventscouk
            str_replace('_', '', $domainClean), // ciaobellaeventscouk
            preg_replace('/[^a-z0-9]/i', '', $domain), // ciaobellaeventscouk
            substr($domainClean, 0, 32),     // truncated version
        ];
        
        foreach ($possibleUsers as $possibleUser) {
            $userInfo = @posix_getpwnam($possibleUser);
            if ($userInfo && $userInfo['dir'] === $homeDir) {
                return $possibleUser;
            }
        }
        
        // 5. Look for any user whose home directory matches
        exec("getent passwd | grep ':$homeDir:'", $output);
        if (!empty($output)) {
            $parts = explode(':', $output[0]);
            if (!empty($parts[0]) && $parts[0] !== 'root' && $parts[0] !== 'nobody') {
                return $parts[0];
            }
        }
        
        return null;
    }

    /**
     * Update SSH key for site user
     */
    protected function actionUpdateSshKey(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['index']) || !isset($params['key'])) {
            return $this->error('Domain, index, and key are required');
        }

        $domain = $params['domain'];
        $index = (int) $params['index'];
        $newKey = trim($params['key']);
        
        // Validate key format
        if (!preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp\d+|ssh-dss)\s+[A-Za-z0-9+\/=]+/', $newKey)) {
            return $this->error('Invalid SSH key format');
        }

        $homeDir = '/home/' . $domain;
        $authorizedKeysFile = $homeDir . '/.ssh/authorized_keys';
        
        if (!file_exists($authorizedKeysFile)) {
            return $this->error('No SSH keys configured');
        }

        $keys = file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $validKeys = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if (!empty($key) && !str_starts_with($key, '#')) {
                $validKeys[] = $key;
            }
        }

        if ($index < 0 || $index >= count($validKeys)) {
            return $this->error('Invalid key index');
        }

        // Update the key
        $validKeys[$index] = $newKey;

        // Write back
        file_put_contents($authorizedKeysFile, implode("\n", $validKeys) . "\n");

        return $this->success([
            'domain' => $domain,
            'keys_count' => count($validKeys),
        ], 'SSH key updated successfully');
    }

    /**
     * Remove SSH key for site user
     */
    protected function actionRemoveSshKey(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['index'])) {
            return $this->error('Domain and index are required');
        }

        $domain = $params['domain'];
        $index = (int) $params['index'];
        
        $homeDir = '/home/' . $domain;
        $authorizedKeysFile = $homeDir . '/.ssh/authorized_keys';
        
        if (!file_exists($authorizedKeysFile)) {
            return $this->error('No SSH keys configured');
        }

        $keys = file($authorizedKeysFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $validKeys = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if (!empty($key) && !str_starts_with($key, '#')) {
                $validKeys[] = $key;
            }
        }

        if ($index < 0 || $index >= count($validKeys)) {
            return $this->error('Invalid key index');
        }

        // Remove the key
        array_splice($validKeys, $index, 1);

        // Write back
        if (count($validKeys) > 0) {
            file_put_contents($authorizedKeysFile, implode("\n", $validKeys) . "\n");
        } else {
            // No keys left, remove the file
            unlink($authorizedKeysFile);
        }

        return $this->success([
            'domain' => $domain,
            'keys_count' => count($validKeys),
        ], 'SSH key removed successfully');
    }

    /**
     * Get databases associated with a site
     * Detects from wp-config.php, naming convention, or database linking
     */
    protected function actionGetDatabases(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $homeDir = "/home/{$domain}";
        $publicHtml = "{$homeDir}/public_html";
        
        $databases = [];

        // Method 1: Check wp-config.php for WordPress sites
        $wpConfig = "{$publicHtml}/wp-config.php";
        if (file_exists($wpConfig)) {
            $content = file_get_contents($wpConfig);
            
            // Extract DB_NAME
            if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $matches)) {
                $dbName = $matches[1];
                $dbUser = null;
                
                // Extract DB_USER
                if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $userMatches)) {
                    $dbUser = $userMatches[1];
                }
                
                $databases[] = [
                    'name' => $dbName,
                    'user' => $dbUser,
                    'source' => 'wp-config.php',
                    'type' => 'wordpress',
                ];
            }
        }

        // Method 2: Check for common CMS config files
        $configFiles = [
            "{$publicHtml}/configuration.php" => 'joomla',      // Joomla
            "{$publicHtml}/sites/default/settings.php" => 'drupal', // Drupal
            "{$publicHtml}/app/etc/env.php" => 'magento',       // Magento
            "{$publicHtml}/config/database.php" => 'laravel',   // Laravel
        ];

        foreach ($configFiles as $configFile => $type) {
            if (file_exists($configFile) && empty($databases)) {
                $content = file_get_contents($configFile);
                
                // Generic database name detection
                if (preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                    $databases[] = [
                        'name' => $matches[1],
                        'user' => null,
                        'source' => basename($configFile),
                        'type' => $type,
                    ];
                }
            }
        }

        // Method 3: Check panel database for linked databases
        if (empty($databases)) {
            try {
                $pdo = $this->getPanelDb();
                if ($pdo) {
                    // Check if there's a website_databases or similar linking table
                    $tables = ['website_databases', 'site_databases', 'domain_databases'];
                    foreach ($tables as $table) {
                        try {
                            $stmt = $pdo->prepare("SELECT database_name, db_user FROM {$table} WHERE domain = ? OR website = ?");
                            $stmt->execute([$domain, $domain]);
                            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                            foreach ($rows as $row) {
                                $databases[] = [
                                    'name' => $row['database_name'] ?? $row['db_name'] ?? null,
                                    'user' => $row['db_user'] ?? $row['database_user'] ?? null,
                                    'source' => 'panel_db',
                                    'type' => 'linked',
                                ];
                            }
                        } catch (\Exception $e) {
                            // Table doesn't exist, continue
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore database errors
            }
        }

        // Method 4: Guess by domain naming convention and SFTP user
        if (empty($databases)) {
            // Convert domain to potential database name
            $baseName = preg_replace('/[^a-z0-9]/', '_', strtolower(str_replace('.', '_', $domain)));
            $shortName = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
            
            // Also get the SFTP user from file ownership
            $sftpUser = null;
            if (is_dir($publicHtml)) {
                $stat = stat($publicHtml);
                if ($stat) {
                    $userInfo = posix_getpwuid($stat['uid']);
                    if ($userInfo && $userInfo['name'] !== 'root' && $userInfo['name'] !== 'www-data') {
                        $sftpUser = $userInfo['name'];
                    }
                }
            }
            
            $potentialNames = [
                $baseName,
                $shortName,
                $shortName . '_db',
                substr($shortName, 0, 10) . '_db',
            ];
            
            // Add SFTP user-based guesses (common pattern: user = db name)
            if ($sftpUser) {
                array_unshift($potentialNames, $sftpUser); // Check SFTP username first
                $potentialNames[] = preg_replace('/\d+$/', '', $sftpUser); // Without trailing numbers
                $potentialNames[] = preg_replace('/db$/', '', $sftpUser); // Without 'db' suffix
            }
            
            // Remove duplicates
            $potentialNames = array_unique($potentialNames);
            
            // Check if any of these databases exist
            try {
                $password = $this->getMySqlPassword();
                $pdo = new \PDO(
                    "mysql:unix_socket=/var/run/mysqld/mysqld.sock",
                    'root',
                    $password,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
                
                foreach ($potentialNames as $potentialDb) {
                    if (empty($potentialDb)) continue;
                    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
                    $stmt->execute([$potentialDb]);
                    if ($stmt->fetch()) {
                        $databases[] = [
                            'name' => $potentialDb,
                            'user' => $sftpUser ?: $potentialDb, // Use SFTP user if known
                            'source' => 'convention',
                            'type' => 'guessed',
                        ];
                        break; // Found one, stop looking
                    }
                }
            } catch (\Exception $e) {
                // Ignore MySQL errors
            }
        }

        return $this->success([
            'domain' => $domain,
            'databases' => $databases,
        ]);
    }

    /**
     * Validate site configuration, permissions, folders, SSL, and syntax
     */
    protected function actionValidateSite(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $issues = [];
        $warnings = [];
        $checks = [];

        // 1. Check if vhost config exists
        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
        $configFile = $vhostPath . '/vhost.conf';
        if (!file_exists($configFile)) {
            $configFile = $vhostPath . '/vhconf.conf';
        }

        if (!file_exists($configFile)) {
            $issues[] = [
                'type' => 'config_missing',
                'severity' => 'error',
                'message' => "Vhost config file not found for {$domain}",
                'fixable' => false,
            ];
        } else {
            $checks[] = ['check' => 'vhost_config_exists', 'status' => 'ok'];
            
            // 2. Check config syntax
            $config = file_get_contents($configFile);
            $syntaxErrors = $this->validateConfigSyntax($config);
            if (!empty($syntaxErrors)) {
                $issues[] = [
                    'type' => 'config_syntax_error',
                    'severity' => 'error',
                    'message' => 'Config syntax errors found',
                    'details' => $syntaxErrors,
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'config_syntax', 'status' => 'ok'];
            }

            // 3. Check if config references exist
            if (preg_match('/docRoot\s+([^\n]+)/', $config, $matches)) {
                $docRoot = trim($matches[1]);
                // Replace variables
                $docRoot = str_replace('$VH_ROOT', "/home/{$domain}", $docRoot);
                $docRoot = str_replace('$DOC_ROOT', $docRoot, $docRoot);
                if (!is_dir($docRoot)) {
                    $issues[] = [
                        'type' => 'docroot_missing',
                        'severity' => 'error',
                        'message' => "Document root directory not found: {$docRoot}",
                        'path' => $docRoot,
                        'fixable' => true,
                    ];
                } else {
                    $checks[] = ['check' => 'docroot_exists', 'status' => 'ok'];
                    
                    // Check docroot permissions
                    $stat = stat($docRoot);
                    if ($stat) {
                        $perms = $stat['mode'] & 0777;
                        if ($perms !== 0755 && $perms !== 0750) {
                            $warnings[] = [
                                'type' => 'docroot_permissions',
                                'severity' => 'warning',
                                'message' => "Document root permissions are {$perms}, expected 755",
                                'current' => $perms,
                                'expected' => 0755,
                                'fixable' => true,
                            ];
                        }
                    }
                }
            }
        }

        // 4. Check home directory
        $homeDir = "/home/{$domain}";
        if (!is_dir($homeDir)) {
            $issues[] = [
                'type' => 'home_dir_missing',
                'severity' => 'error',
                'message' => "Home directory not found: {$homeDir}",
                'path' => $homeDir,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'home_dir_exists', 'status' => 'ok'];
            
            // Check home directory permissions
            $stat = stat($homeDir);
            if ($stat) {
                $perms = $stat['mode'] & 0777;
                if ($perms !== 0755) {
                    $warnings[] = [
                        'type' => 'home_dir_permissions',
                        'severity' => 'warning',
                        'message' => "Home directory permissions are {$perms}, expected 755",
                        'current' => $perms,
                        'expected' => 0755,
                        'fixable' => true,
                    ];
                }
            }
        }

        // 5. Check required subdirectories (tmp removed - not required for all sites)
        $requiredDirs = [
            'public_html' => $homeDir . '/public_html',
            'logs' => $homeDir . '/logs',
        ];

        foreach ($requiredDirs as $name => $path) {
            if (!is_dir($path)) {
                $issues[] = [
                    'type' => 'directory_missing',
                    'severity' => 'error',
                    'message' => "Required directory missing: {$name}",
                    'path' => $path,
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => "{$name}_exists", 'status' => 'ok'];
            }
        }

        // 6. Check SSL configuration
        if (file_exists($configFile)) {
            $config = file_get_contents($configFile);
            if (preg_match('/vhssl\s*\{/i', $config)) {
                // SSL block exists in config
                $certPath = '/etc/letsencrypt/live/' . $domain;
                $certFile = $certPath . '/fullchain.pem';
                $keyFile = $certPath . '/privkey.pem';
                
                if (!file_exists($certFile) || !file_exists($keyFile)) {
                    $issues[] = [
                        'type' => 'ssl_cert_missing',
                        'severity' => 'error',
                        'message' => "SSL certificate files not found but SSL block exists in config",
                        'cert_path' => $certPath,
                        'fixable' => true,
                    ];
                } else {
                    $checks[] = ['check' => 'ssl_cert_exists', 'status' => 'ok'];
                    
                    // Check certificate validity
                    $certContent = file_get_contents($certFile);
                    $certInfo = openssl_x509_parse($certContent);
                    if ($certInfo && isset($certInfo['validTo_time_t'])) {
                        $expires = $certInfo['validTo_time_t'];
                        $daysUntilExpiry = ($expires - time()) / 86400;
                        if ($daysUntilExpiry < 30) {
                            $warnings[] = [
                                'type' => 'ssl_expiring_soon',
                                'severity' => 'warning',
                                'message' => "SSL certificate expires in " . round($daysUntilExpiry) . " days",
                                'expires' => date('Y-m-d H:i:s', $expires),
                                'fixable' => false,
                            ];
                        }
                    }
                }
            }
        }

        // 7. Check OLS main config
        $mainConfig = $this->config['paths']['ols_config'];
        if (file_exists($mainConfig)) {
            $mainConfigContent = file_get_contents($mainConfig);
            if (stripos($mainConfigContent, "virtualhost {$domain} {") === false && stripos($mainConfigContent, "virtualHost {$domain} {") === false) {
                $issues[] = [
                    'type' => 'ols_config_missing',
                    'severity' => 'error',
                    'message' => "Site not found in OLS main config",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'ols_config_entry', 'status' => 'ok'];
                
                // Check for syntax errors in main config
                $braceBalance = substr_count($mainConfigContent, '{') - substr_count($mainConfigContent, '}');
                if ($braceBalance !== 0) {
                    $issues[] = [
                        'type' => 'ols_config_syntax',
                        'severity' => 'error',
                        'message' => "OLS main config has unbalanced braces (balance: {$braceBalance})",
                        'fixable' => false,
                    ];
                }
            }
        }

        // 8. Check file ownership consistency
        if (is_dir($homeDir)) {
            $publicHtml = $homeDir . '/public_html';
            if (is_dir($publicHtml)) {
                $stat = stat($publicHtml);
                if ($stat) {
                    $owner = posix_getpwuid($stat['uid']);
                    $group = posix_getgrgid($stat['gid']);
                    $ownerName = $owner ? $owner['name'] : 'unknown';
                    
                    // Check if config references this user
                    if (file_exists($configFile)) {
                        $config = file_get_contents($configFile);
                        if (preg_match('/extUser\s+([^\n]+)/', $config, $matches)) {
                            $configUser = trim($matches[1]);
                            if ($configUser !== $ownerName && $ownerName !== 'www-data' && $configUser !== 'www-data') {
                                $warnings[] = [
                                    'type' => 'ownership_mismatch',
                                    'severity' => 'warning',
                                    'message' => "File ownership ({$ownerName}) doesn't match config user ({$configUser})",
                                    'file_owner' => $ownerName,
                                    'config_user' => $configUser,
                                    'fixable' => true,
                                ];
                            }
                        }
                    }
                }
            }
        }

        // 9. Check allowBrowse setting (critical for SSL/ACME challenges)
        // Only flag as issue if explicitly set to 0 - missing or 1 is fine
        if (file_exists($configFile)) {
            $config = file_get_contents($configFile);
            if (preg_match('/allowBrowse\s+(\d)/', $config, $matches)) {
                $allowBrowse = (int)$matches[1];
                if ($allowBrowse === 0) {
                    $issues[] = [
                        'type' => 'allow_browse_disabled',
                        'severity' => 'error',
                        'message' => "allowBrowse is set to 0 - this blocks static file access and SSL issuance",
                        'current' => 0,
                        'expected' => 1,
                        'fixable' => true,
                    ];
                } else {
                    $checks[] = ['check' => 'allow_browse', 'status' => 'ok'];
                }
            } else {
                // No allowBrowse setting = default behavior which is fine
                $checks[] = ['check' => 'allow_browse', 'status' => 'ok'];
            }
        }

        // 10. Check SFTP/SSH user exists and permissions
        $sftpUser = null;
        if (file_exists($configFile)) {
            $config = file_get_contents($configFile);
            if (preg_match('/extUser\s+([^\n\s]+)/', $config, $matches)) {
                $sftpUser = trim($matches[1]);
            }
        }
        
        if ($sftpUser && $sftpUser !== 'www-data') {
            // Check if Linux user exists
            $userInfo = posix_getpwnam($sftpUser);
            if (!$userInfo) {
                $issues[] = [
                    'type' => 'sftp_user_missing',
                    'severity' => 'error',
                    'message' => "SFTP user '{$sftpUser}' does not exist on the system",
                    'user' => $sftpUser,
                    'fixable' => false,
                ];
            } else {
                $checks[] = ['check' => 'sftp_user_exists', 'status' => 'ok', 'user' => $sftpUser];
                
                // Check .ssh directory
                $sshDir = $homeDir . '/.ssh';
                if (is_dir($sshDir)) {
                    $sshStat = stat($sshDir);
                    if ($sshStat) {
                        $sshPerms = $sshStat['mode'] & 0777;
                        if ($sshPerms !== 0700) {
                            $warnings[] = [
                                'type' => 'ssh_dir_permissions',
                                'severity' => 'warning',
                                'message' => ".ssh directory has permissions " . decoct($sshPerms) . ", expected 700",
                                'path' => $sshDir,
                                'current' => $sshPerms,
                                'expected' => 0700,
                                'fixable' => true,
                            ];
                        } else {
                            $checks[] = ['check' => 'ssh_dir_permissions', 'status' => 'ok'];
                        }
                        
                        // Check authorized_keys file
                        $authKeysFile = $sshDir . '/authorized_keys';
                        if (file_exists($authKeysFile)) {
                            $keysStat = stat($authKeysFile);
                            if ($keysStat) {
                                $keysPerms = $keysStat['mode'] & 0777;
                                if ($keysPerms !== 0600 && $keysPerms !== 0644) {
                                    $warnings[] = [
                                        'type' => 'authorized_keys_permissions',
                                        'severity' => 'warning',
                                        'message' => "authorized_keys has permissions " . decoct($keysPerms) . ", expected 600 or 644",
                                        'path' => $authKeysFile,
                                        'current' => $keysPerms,
                                        'expected' => 0600,
                                        'fixable' => true,
                                    ];
                                } else {
                                    $checks[] = ['check' => 'authorized_keys_permissions', 'status' => 'ok'];
                                }
                            }
                        }
                    }
                }
            }
        }

        // 11. Check listener mappings in OLS main config
        if (file_exists($mainConfig)) {
            $mainConfigContent = file_get_contents($mainConfig);
            
            // Check for Default listener mapping
            if (strpos($mainConfigContent, "map                     {$domain} {$domain}") === false) {
                $warnings[] = [
                    'type' => 'listener_mapping_missing',
                    'severity' => 'warning',
                    'message' => "Domain not found in Default listener mappings",
                    'fixable' => false,
                ];
            } else {
                $checks[] = ['check' => 'listener_mapping', 'status' => 'ok'];
            }
        }

        $hasErrors = !empty($issues);
        $hasWarnings = !empty($warnings);

        return $this->success([
            'domain' => $domain,
            'valid' => !$hasErrors,
            'has_warnings' => $hasWarnings,
            'checks' => $checks,
            'issues' => $issues,
            'warnings' => $warnings,
            'summary' => [
                'total_checks' => count($checks),
                'passed' => count(array_filter($checks, fn($c) => $c['status'] === 'ok')),
                'errors' => count($issues),
                'warnings' => count($warnings),
            ],
        ], $hasErrors ? 'Validation found issues' : ($hasWarnings ? 'Validation passed with warnings' : 'All checks passed'));
    }

    /**
     * Fix site validation issues
     */
    protected function actionFixSite(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $fixed = [];
        $errors = [];

        // First, run validation to get issues
        $validation = $this->actionValidateSite(['domain' => $domain], $actor);
        if (!$validation['success']) {
            return $validation;
        }

        $data = $validation['data'];
        $allIssues = array_merge($data['issues'] ?? [], $data['warnings'] ?? []);

        // Filter to only fixable issues
        $fixableIssues = array_filter($allIssues, fn($issue) => ($issue['fixable'] ?? false) === true);

        foreach ($fixableIssues as $issue) {
            try {
                switch ($issue['type']) {
                    case 'docroot_missing':
                        $path = $issue['path'] ?? "/home/{$domain}/public_html";
                        if (!is_dir($path)) {
                            mkdir($path, 0755, true);
                            $fixed[] = "Created document root: {$path}";
                        }
                        break;

                    case 'home_dir_missing':
                        $path = $issue['path'] ?? "/home/{$domain}";
                        if (!is_dir($path)) {
                            mkdir($path, 0755, true);
                            $fixed[] = "Created home directory: {$path}";
                        }
                        break;

                    case 'directory_missing':
                        $path = $issue['path'];
                        if (!is_dir($path)) {
                            mkdir($path, 0755, true);
                            $fixed[] = "Created directory: {$path}";
                        }
                        break;

                    case 'docroot_permissions':
                    case 'home_dir_permissions':
                        $path = $issue['path'] ?? null;
                        if ($path && is_dir($path)) {
                            chmod($path, $issue['expected']);
                            $fixed[] = "Fixed permissions for: {$path}";
                        }
                        break;

                    case 'ssl_cert_missing':
                        // Remove SSL block from config if cert doesn't exist
                        $this->removeSslFromVhostConfig($domain);
                        $fixed[] = "Removed SSL block from config (certificate not found)";
                        break;

                    case 'ols_config_missing':
                        // Add site to OLS config
                        $homeDir = "/home/{$domain}";
                        $this->addVhostToMainConfig($domain, $homeDir, false);
                        $fixed[] = "Added site to OLS main config";
                        break;

                    case 'ownership_mismatch':
                        // Fix ownership to match config
                        if (isset($issue['config_user'])) {
                            $publicHtml = "/home/{$domain}/public_html";
                            if (is_dir($publicHtml)) {
                                $this->execCommand('chown', ['-R', $issue['config_user'] . ':' . $issue['config_user'], $publicHtml]);
                                $fixed[] = "Fixed ownership for public_html to match config user";
                            }
                        }
                        break;

                    case 'config_syntax_error':
                        // Can't auto-fix syntax errors, but we can try to validate and report
                        $errors[] = "Config syntax errors cannot be auto-fixed. Please review manually.";
                        break;

                    case 'allow_browse_disabled':
                        // Fix allowBrowse 0 to 1
                        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
                        $configFile = $vhostPath . '/vhost.conf';
                        if (!file_exists($configFile)) {
                            $configFile = $vhostPath . '/vhconf.conf';
                        }
                        if (file_exists($configFile)) {
                            $config = file_get_contents($configFile);
                            $newConfig = preg_replace('/allowBrowse\s+0/', 'allowBrowse             1', $config);
                            if ($newConfig !== $config) {
                                file_put_contents($configFile, $newConfig);
                                $fixed[] = "Fixed allowBrowse: changed from 0 to 1";
                            }
                        }
                        break;

                    case 'ssh_dir_permissions':
                        $path = $issue['path'] ?? "/home/{$domain}/.ssh";
                        if (is_dir($path)) {
                            chmod($path, 0700);
                            $fixed[] = "Fixed .ssh directory permissions to 700";
                        }
                        break;

                    case 'authorized_keys_permissions':
                        $path = $issue['path'] ?? "/home/{$domain}/.ssh/authorized_keys";
                        if (file_exists($path)) {
                            chmod($path, 0600);
                            $fixed[] = "Fixed authorized_keys permissions to 600";
                        }
                        break;
                }
            } catch (\Exception $e) {
                $errors[] = "Failed to fix {$issue['type']}: " . $e->getMessage();
            }
        }

        // Reload OLS if we made changes
        if (!empty($fixed)) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
        }

        return $this->success([
            'domain' => $domain,
            'fixed' => $fixed,
            'errors' => $errors,
            'fixed_count' => count($fixed),
        ], count($fixed) > 0 ? "Fixed " . count($fixed) . " issue(s)" : "No issues to fix");
    }

    /**
     * Fix a single site validation issue by type
     */
    protected function actionFixSiteIssue(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }
        if (!isset($params['issue_type'])) {
            return $this->error('Issue type is required');
        }

        $domain = $params['domain'];
        $issueType = $params['issue_type'];
        $fixed = [];
        $errors = [];

        // First, run validation to get the specific issue
        $validation = $this->actionValidateSite(['domain' => $domain], $actor);
        if (!$validation['success']) {
            return $validation;
        }

        $data = $validation['data'];
        $allIssues = array_merge($data['issues'] ?? [], $data['warnings'] ?? []);

        // Find the specific issue
        $targetIssue = null;
        foreach ($allIssues as $issue) {
            if ($issue['type'] === $issueType) {
                $targetIssue = $issue;
                break;
            }
        }

        if (!$targetIssue) {
            return $this->error("Issue type '{$issueType}' not found for this site");
        }

        if (!($targetIssue['fixable'] ?? false)) {
            return $this->error("Issue type '{$issueType}' is not auto-fixable");
        }

        // Fix the single issue
        try {
            switch ($targetIssue['type']) {
                case 'docroot_missing':
                    $path = $targetIssue['path'] ?? "/home/{$domain}/public_html";
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                        $fixed[] = "Created document root: {$path}";
                    }
                    break;

                case 'home_dir_missing':
                    $path = $targetIssue['path'] ?? "/home/{$domain}";
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                        $fixed[] = "Created home directory: {$path}";
                    }
                    break;

                case 'directory_missing':
                    $path = $targetIssue['path'];
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                        $fixed[] = "Created directory: {$path}";
                    }
                    break;

                case 'docroot_permissions':
                case 'home_dir_permissions':
                    $path = $targetIssue['path'] ?? null;
                    if ($path && is_dir($path)) {
                        chmod($path, $targetIssue['expected']);
                        $fixed[] = "Fixed permissions for: {$path}";
                    }
                    break;

                case 'ssl_cert_missing':
                    $this->removeSslFromVhostConfig($domain);
                    $fixed[] = "Removed SSL block from config (certificate not found)";
                    break;

                case 'ols_config_missing':
                    $homeDir = "/home/{$domain}";
                    $this->addVhostToMainConfig($domain, $homeDir, false);
                    $fixed[] = "Added site to OLS main config";
                    break;

                case 'ownership_mismatch':
                    if (isset($targetIssue['config_user'])) {
                        $publicHtml = "/home/{$domain}/public_html";
                        if (is_dir($publicHtml)) {
                            $this->execCommand('chown', ['-R', $targetIssue['config_user'] . ':' . $targetIssue['config_user'], $publicHtml]);
                            $fixed[] = "Fixed ownership for public_html to match config user";
                        }
                    }
                    break;

                case 'allow_browse_disabled':
                    $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
                    $configFile = $vhostPath . '/vhost.conf';
                    if (!file_exists($configFile)) {
                        $configFile = $vhostPath . '/vhconf.conf';
                    }
                    if (file_exists($configFile)) {
                        $config = file_get_contents($configFile);
                        $newConfig = preg_replace('/allowBrowse\s+0/', 'allowBrowse             1', $config);
                        if ($newConfig !== $config) {
                            file_put_contents($configFile, $newConfig);
                            $fixed[] = "Fixed allowBrowse: changed from 0 to 1";
                        }
                    }
                    break;

                case 'ssh_dir_permissions':
                    $path = $targetIssue['path'] ?? "/home/{$domain}/.ssh";
                    if (is_dir($path)) {
                        chmod($path, 0700);
                        $fixed[] = "Fixed .ssh directory permissions to 700";
                    }
                    break;

                case 'authorized_keys_permissions':
                    $path = $targetIssue['path'] ?? "/home/{$domain}/.ssh/authorized_keys";
                    if (file_exists($path)) {
                        chmod($path, 0600);
                        $fixed[] = "Fixed authorized_keys permissions to 600";
                    }
                    break;

                default:
                    return $this->error("Unknown fix action for issue type: {$issueType}");
            }
        } catch (\Exception $e) {
            return $this->error("Failed to fix {$issueType}: " . $e->getMessage());
        }

        // Reload OLS if we made changes
        if (!empty($fixed)) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
        }

        return $this->success([
            'domain' => $domain,
            'issue_type' => $issueType,
            'fixed' => $fixed,
            'fixed_count' => count($fixed),
        ], count($fixed) > 0 ? "Fixed: {$issueType}" : "No changes made");
    }

    /**
     * Validate config syntax
     */
    private function validateConfigSyntax(string $config): array
    {
        $errors = [];

        // Check for balanced braces
        $braceBalance = substr_count($config, '{') - substr_count($config, '}');
        if ($braceBalance !== 0) {
            $errors[] = "Unbalanced braces (difference: {$braceBalance})";
        }

        // Check for common syntax issues
        if (preg_match('/\n\s*\n\s*\{/', $config)) {
            $errors[] = "Possible missing directive before opening brace";
        }

        // Check for unclosed blocks (basic check)
        $lines = explode("\n", $config);
        $openBlocks = 0;
        foreach ($lines as $lineNum => $line) {
            $openBlocks += substr_count($line, '{');
            $openBlocks -= substr_count($line, '}');
            if ($openBlocks < 0) {
                $errors[] = "Unexpected closing brace at line " . ($lineNum + 1);
                break;
            }
        }

        return $errors;
    }

    /**
     * Validate site deletion cleanup
     */
    protected function actionValidateDeletion(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $issues = [];
        $checks = [];

        // 1. Check if vhost config still exists
        $vhostPath = $this->config['paths']['ols_vhosts'] . '/' . $domain;
        if (is_dir($vhostPath)) {
            $issues[] = [
                'type' => 'vhost_config_exists',
                'severity' => 'error',
                'message' => "Vhost config directory still exists: {$vhostPath}",
                'path' => $vhostPath,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'vhost_config_removed', 'status' => 'ok'];
        }

        // 2. Check if home directory still exists
        $homeDir = "/home/{$domain}";
        if (is_dir($homeDir)) {
            $issues[] = [
                'type' => 'home_dir_exists',
                'severity' => 'error',
                'message' => "Home directory still exists: {$homeDir}",
                'path' => $homeDir,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'home_dir_removed', 'status' => 'ok'];
        }

        // 3. Check if site is still in OLS main config
        $mainConfig = $this->config['paths']['ols_config'];
        if (file_exists($mainConfig)) {
            $mainConfigContent = file_get_contents($mainConfig);
            if (stripos($mainConfigContent, "virtualhost {$domain} {") !== false || stripos($mainConfigContent, "virtualHost {$domain} {") !== false) {
                $issues[] = [
                    'type' => 'ols_config_entry_exists',
                    'severity' => 'error',
                    'message' => "Site still found in OLS main config",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'ols_config_entry_removed', 'status' => 'ok'];
            }

            // Check for domain mappings
            if (preg_match('/map\s+' . preg_quote($domain, '/') . '\s+/', $mainConfigContent)) {
                $issues[] = [
                    'type' => 'ols_config_mapping_exists',
                    'severity' => 'error',
                    'message' => "Domain mapping still exists in OLS config",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'ols_config_mapping_removed', 'status' => 'ok'];
            }

            // Check for syntax errors
            $braceBalance = substr_count($mainConfigContent, '{') - substr_count($mainConfigContent, '}');
            if ($braceBalance !== 0) {
                $issues[] = [
                    'type' => 'ols_config_syntax',
                    'severity' => 'error',
                    'message' => "OLS main config has unbalanced braces (balance: {$braceBalance})",
                    'fixable' => false,
                ];
            } else {
                $checks[] = ['check' => 'ols_config_syntax', 'status' => 'ok'];
            }
        }

        // 4. Check if SSL certificates still exist
        $sslPath = '/etc/letsencrypt/live/' . $domain;
        if (is_dir($sslPath)) {
            $issues[] = [
                'type' => 'ssl_cert_exists',
                'severity' => 'warning',
                'message' => "SSL certificate directory still exists: {$sslPath}",
                'path' => $sslPath,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'ssl_cert_removed', 'status' => 'ok'];
        }

        // 5. Check if Linux user still exists
        $siteUser = $this->domainToUsername($domain);
        $userCheck = shell_exec("id " . escapeshellarg($siteUser) . " 2>&1");
        if (strpos($userCheck, 'no such user') === false && !empty(trim($userCheck))) {
            $issues[] = [
                'type' => 'user_exists',
                'severity' => 'warning',
                'message' => "Linux user still exists: {$siteUser}",
                'user' => $siteUser,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'user_removed', 'status' => 'ok'];
        }

        // 6. Check if DNS zone still exists (PowerDNS)
        try {
            $db = $this->getPanelDb();
            $stmt = $db->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            if ($stmt->fetch()) {
                $issues[] = [
                    'type' => 'dns_zone_exists',
                    'severity' => 'warning',
                    'message' => "DNS zone still exists in database",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'dns_zone_removed', 'status' => 'ok'];
            }
        } catch (\Exception $e) {
            // DNS check failed, skip
            $checks[] = ['check' => 'dns_zone_check', 'status' => 'skipped'];
        }

        // 6b. Check the legacy native PowerDNS tables (domains/records).
        // Servers migrated from the original gmysql schema can hold the
        // zone in BOTH table pairs; the saga and panel only managed
        // dns_domains historically, so native rows were a confirmed
        // production leftover (testsite.hu, June 2026).
        try {
            $db = $this->getPanelDb();
            $stmt = $db->prepare("SELECT id FROM domains WHERE name = ?");
            $stmt->execute([$domain]);
            if ($stmt->fetch()) {
                $issues[] = [
                    'type' => 'pdns_zone_exists',
                    'severity' => 'warning',
                    'message' => "DNS zone still exists in native PowerDNS tables",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'pdns_zone_removed', 'status' => 'ok'];
            }
        } catch (\Exception $e) {
            // Native tables absent on fresh installs - fine.
            $checks[] = ['check' => 'pdns_zone_check', 'status' => 'skipped'];
        }

        // 6c. Check panel database_links bookkeeping rows.
        try {
            $db = $this->getPanelDb();
            $stmt = $db->prepare("SELECT id FROM database_links WHERE domain = ?");
            $stmt->execute([$domain]);
            if ($stmt->fetch()) {
                $issues[] = [
                    'type' => 'database_link_exists',
                    'severity' => 'warning',
                    'message' => "database_links row(s) still reference this domain",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'database_link_removed', 'status' => 'ok'];
            }
        } catch (\Exception $e) {
            $checks[] = ['check' => 'database_link_check', 'status' => 'skipped'];
        }

        // 7. Check if mail domain still exists in database
        try {
            $db = $this->getPanelDb();
            $stmt = $db->prepare("SELECT id FROM mail_domains WHERE domain = ?");
            $stmt->execute([$domain]);
            if ($stmt->fetch()) {
                $issues[] = [
                    'type' => 'mail_domain_exists',
                    'severity' => 'warning',
                    'message' => "Mail domain still exists in database",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'mail_domain_removed', 'status' => 'ok'];
            }
        } catch (\Exception $e) {
            // Mail check failed, skip
            $checks[] = ['check' => 'mail_domain_check', 'status' => 'skipped'];
        }

        // 8. Check if mail directory still exists on filesystem
        $mailDir = '/home/vmail/' . $domain;
        if (is_dir($mailDir)) {
            $issues[] = [
                'type' => 'mail_dir_exists',
                'severity' => 'warning',
                'message' => "Mail directory still exists: {$mailDir}",
                'path' => $mailDir,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'mail_dir_removed', 'status' => 'ok'];
        }

        // 9. Check if DKIM keys still exist
        $dkimPath = '/etc/opendkim/keys/' . $domain;
        if (is_dir($dkimPath)) {
            $issues[] = [
                'type' => 'dkim_keys_exist',
                'severity' => 'warning',
                'message' => "DKIM keys still exist: {$dkimPath}",
                'path' => $dkimPath,
                'fixable' => true,
            ];
        } else {
            $checks[] = ['check' => 'dkim_keys_removed', 'status' => 'ok'];
        }

        // 10. Check if domain still in DKIM SigningTable
        $signingTablePath = '/etc/opendkim/SigningTable';
        if (file_exists($signingTablePath)) {
            $signingContent = file_get_contents($signingTablePath);
            if (strpos($signingContent, $domain) !== false) {
                $issues[] = [
                    'type' => 'dkim_signingtable_exists',
                    'severity' => 'warning',
                    'message' => "Domain still in DKIM SigningTable",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'dkim_signingtable_removed', 'status' => 'ok'];
            }
        }

        // 11. Check if domain still in DKIM KeyTable
        $keyTablePath = '/etc/opendkim/KeyTable';
        if (file_exists($keyTablePath)) {
            $keyTableContent = file_get_contents($keyTablePath);
            if (strpos($keyTableContent, $domain) !== false) {
                $issues[] = [
                    'type' => 'dkim_keytable_exists',
                    'severity' => 'warning',
                    'message' => "Domain still in DKIM KeyTable",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'dkim_keytable_removed', 'status' => 'ok'];
            }
        }

        // 12. Check if domain still in Postfix virtual_domains
        $virtualDomainsPath = '/etc/postfix/virtual_domains';
        if (file_exists($virtualDomainsPath)) {
            $virtualContent = file_get_contents($virtualDomainsPath);
            if (preg_match('/^' . preg_quote($domain, '/') . '\s/m', $virtualContent)) {
                $issues[] = [
                    'type' => 'postfix_virtual_exists',
                    'severity' => 'warning',
                    'message' => "Domain still in Postfix virtual_domains",
                    'fixable' => true,
                ];
            } else {
                $checks[] = ['check' => 'postfix_virtual_removed', 'status' => 'ok'];
            }
        }

        $hasErrors = !empty(array_filter($issues, fn($i) => $i['severity'] === 'error'));

        // Build a simple boolean checks object for UI consumption
        $issueTypes = array_column($issues, 'type');
        $checkFlags = [
            'vhost_exists' => in_array('vhost_config_exists', $issueTypes),
            'home_exists' => in_array('home_dir_exists', $issueTypes),
            'user_exists' => in_array('user_exists', $issueTypes),
            'ols_config_exists' => in_array('ols_config_entry_exists', $issueTypes) || in_array('ols_config_mapping_exists', $issueTypes),
            'ssl_exists' => in_array('ssl_cert_exists', $issueTypes),
            'dns_exists' => in_array('dns_zone_exists', $issueTypes) || in_array('pdns_zone_exists', $issueTypes),
            'database_link_exists' => in_array('database_link_exists', $issueTypes),
            'mail_exists' => in_array('mail_domain_exists', $issueTypes),
            'mail_dir_exists' => in_array('mail_dir_exists', $issueTypes),
            'dkim_exists' => in_array('dkim_keys_exist', $issueTypes) || in_array('dkim_signingtable_exists', $issueTypes) || in_array('dkim_keytable_exists', $issueTypes),
            'postfix_exists' => in_array('postfix_virtual_exists', $issueTypes),
        ];

        return $this->success([
            'domain' => $domain,
            'clean' => !$hasErrors,
            'checks' => $checkFlags,
            'passed_checks' => $checks,
            'issues' => $issues,
            'summary' => [
                'total_checks' => count($checks),
                'passed' => count(array_filter($checks, fn($c) => $c['status'] === 'ok')),
                'issues' => count($issues),
            ],
        ], $hasErrors ? 'Deletion cleanup incomplete' : 'Deletion cleanup verified');
    }

    /**
     * Fix site deletion cleanup issues
     */
    protected function actionFixDeletion(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        $fixed = [];
        $errors = [];

        // First, run validation to get issues
        $validation = $this->actionValidateDeletion(['domain' => $domain], $actor);
        if (!$validation['success']) {
            return $validation;
        }

        $data = $validation['data'];
        $issues = $data['issues'] ?? [];

        // Filter to only fixable issues
        $fixableIssues = array_filter($issues, fn($issue) => ($issue['fixable'] ?? false) === true);

        foreach ($fixableIssues as $issue) {
            try {
                switch ($issue['type']) {
                    case 'vhost_config_exists':
                        $path = $issue['path'];
                        if (is_dir($path)) {
                            $this->execCommand('rm', ['-rf', $path]);
                            $fixed[] = "Removed vhost config directory: {$path}";
                        }
                        break;

                    case 'home_dir_exists':
                        $path = $issue['path'];
                        if (is_dir($path)) {
                            // Backup first
                            $timestamp = date('Y-m-d_H-i-s');
                            $backupDir = $this->config['paths']['backups'] . '/deleted_sites/' . $domain . '_' . $timestamp;
                            $backupParent = dirname($backupDir);
                            if (!is_dir($backupParent)) {
                                mkdir($backupParent, 0755, true);
                            }
                            if (rename($path, $backupDir)) {
                                $fixed[] = "Backed up and removed home directory: {$path}";
                            } else {
                                $this->execCommand('rm', ['-rf', $path]);
                                $fixed[] = "Removed home directory: {$path}";
                            }
                        }
                        break;

                    case 'ols_config_entry_exists':
                    case 'ols_config_mapping_exists':
                        // Remove from OLS config
                        $this->removeVhostFromMainConfig($domain);
                        $fixed[] = "Removed site from OLS main config";
                        break;

                    case 'ssl_cert_exists':
                        $path = $issue['path'];
                        if (is_dir($path)) {
                            $this->execCommand('rm', ['-rf', $path]);
                            $fixed[] = "Removed SSL certificate directory: {$path}";
                        }
                        // Also remove from archive and renewal
                        $archivePath = '/etc/letsencrypt/archive/' . $domain;
                        $renewalPath = '/etc/letsencrypt/renewal/' . $domain . '.conf';
                        if (is_dir($archivePath)) {
                            $this->execCommand('rm', ['-rf', $archivePath]);
                        }
                        if (file_exists($renewalPath)) {
                            @unlink($renewalPath);
                        }
                        break;

                    case 'user_exists':
                        $user = $issue['user'] ?? $this->domainToUsername($domain);
                        // Kill user processes first
                        shell_exec("pkill -u " . escapeshellarg($user) . " 2>/dev/null");
                        // Delete the user
                        $this->execCommand('userdel', ['-r', $user]);
                        $fixed[] = "Removed Linux user: {$user}";
                        break;

                    case 'dns_zone_exists':
                        $db = $this->getPanelDb();
                        // Delete records first
                        $stmt = $db->prepare("DELETE FROM dns_records WHERE domain_id IN (SELECT id FROM dns_domains WHERE name = ?)");
                        $stmt->execute([$domain]);
                        // Delete domain
                        $stmt = $db->prepare("DELETE FROM dns_domains WHERE name = ?");
                        $stmt->execute([$domain]);
                        // Purge the pdns cache so the zone stops resolving
                        // immediately instead of after the cache TTL.
                        $this->execCommand('pdns_control', ['purge', $domain . '$']);
                        $fixed[] = "Removed DNS zone from database";
                        break;

                    case 'pdns_zone_exists':
                        $db = $this->getPanelDb();
                        $stmt = $db->prepare("DELETE FROM records WHERE domain_id IN (SELECT id FROM domains WHERE name = ?)");
                        $stmt->execute([$domain]);
                        try {
                            $stmt = $db->prepare("DELETE FROM domainmetadata WHERE domain_id IN (SELECT id FROM domains WHERE name = ?)");
                            $stmt->execute([$domain]);
                        } catch (\Exception $e) {
                            // domainmetadata table may be absent - harmless
                        }
                        $stmt = $db->prepare("DELETE FROM domains WHERE name = ?");
                        $stmt->execute([$domain]);
                        $this->execCommand('pdns_control', ['purge', $domain . '$']);
                        $fixed[] = "Removed DNS zone from native PowerDNS tables";
                        break;

                    case 'database_link_exists':
                        $db = $this->getPanelDb();
                        $stmt = $db->prepare("DELETE FROM database_links WHERE domain = ?");
                        $stmt->execute([$domain]);
                        $fixed[] = "Removed database_links row(s) for domain";
                        break;

                    case 'mail_domain_exists':
                        $db = $this->getPanelDb();
                        // Delete mail accounts first
                        $stmt = $db->prepare("DELETE FROM mail_accounts WHERE domain = ?");
                        $stmt->execute([$domain]);
                        // Delete forwards
                        $stmt = $db->prepare("DELETE FROM mail_forwards WHERE source_domain = ?");
                        $stmt->execute([$domain]);
                        // Delete domain
                        $stmt = $db->prepare("DELETE FROM mail_domains WHERE domain = ?");
                        $stmt->execute([$domain]);
                        $fixed[] = "Removed mail domain from database";
                        break;

                    case 'mail_dir_exists':
                        $mailDir = $issue['path'] ?? '/home/vmail/' . $domain;
                        if (is_dir($mailDir)) {
                            // Backup mail directory before removal
                            $timestamp = date('Y-m-d_H-i-s');
                            $backupDir = ($this->config['paths']['backups'] ?? '/var/www/vps-admin/backups') . '/deleted_mail/' . $domain . '_' . $timestamp;
                            $backupParent = dirname($backupDir);
                            if (!is_dir($backupParent)) {
                                mkdir($backupParent, 0755, true);
                            }
                            // Try to backup first
                            if (rename($mailDir, $backupDir)) {
                                $fixed[] = "Moved mail directory to: {$backupDir}";
                            } else {
                                // If rename fails, try recursive delete
                                $this->execCommand('rm', ['-rf', $mailDir]);
                                $fixed[] = "Removed mail directory: {$mailDir}";
                            }
                        }
                        break;

                    case 'dkim_keys_exist':
                        $dkimPath = $issue['path'] ?? '/etc/opendkim/keys/' . $domain;
                        if (is_dir($dkimPath)) {
                            $this->execCommand('rm', ['-rf', $dkimPath]);
                            $fixed[] = "Removed DKIM keys: {$dkimPath}";
                        }
                        break;

                    case 'dkim_signingtable_exists':
                        $signingTablePath = '/etc/opendkim/SigningTable';
                        if (file_exists($signingTablePath)) {
                            $content = file_get_contents($signingTablePath);
                            $newContent = preg_replace('/^.*' . preg_quote($domain, '/') . '.*$/m', '', $content);
                            $newContent = preg_replace('/\n+/', "\n", trim($newContent)) . "\n";
                            file_put_contents($signingTablePath, $newContent);
                            $fixed[] = "Removed domain from DKIM SigningTable";
                        }
                        break;

                    case 'dkim_keytable_exists':
                        $keyTablePath = '/etc/opendkim/KeyTable';
                        if (file_exists($keyTablePath)) {
                            $content = file_get_contents($keyTablePath);
                            $newContent = preg_replace('/^.*' . preg_quote($domain, '/') . '.*$/m', '', $content);
                            $newContent = preg_replace('/\n+/', "\n", trim($newContent)) . "\n";
                            file_put_contents($keyTablePath, $newContent);
                            $fixed[] = "Removed domain from DKIM KeyTable";
                        }
                        // Reload opendkim after DKIM table changes
                        $this->execCommand('systemctl', ['reload', 'opendkim']);
                        break;

                    case 'postfix_virtual_exists':
                        $virtualDomainsPath = '/etc/postfix/virtual_domains';
                        if (file_exists($virtualDomainsPath)) {
                            $content = file_get_contents($virtualDomainsPath);
                            $newContent = preg_replace('/^' . preg_quote($domain, '/') . '\s*.*$/m', '', $content);
                            $newContent = preg_replace('/\n+/', "\n", trim($newContent)) . "\n";
                            file_put_contents($virtualDomainsPath, $newContent);
                            $this->execCommand('postmap', [$virtualDomainsPath]);
                            $fixed[] = "Removed domain from Postfix virtual_domains";
                        }
                        break;
                }
            } catch (\Exception $e) {
                $errors[] = "Failed to fix {$issue['type']}: " . $e->getMessage();
            }
        }

        // Reload OLS if we made changes
        if (!empty($fixed)) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
        }

        return $this->success([
            'domain' => $domain,
            'fixed' => $fixed,
            'errors' => $errors,
            'fixed_count' => count($fixed),
        ], count($fixed) > 0 ? "Fixed " . count($fixed) . " cleanup issue(s)" : "No cleanup issues to fix");
    }
}

