<?php
/**
 * Mail Action Handler
 * 
 * Manages Postfix and Dovecot mail system.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\PanelDbTrait;
use VpsAdmin\Agent\Lib\Validator;

class MailAction extends BaseAction
{
    use PanelDbTrait;

    private string $virtualMailboxPath = '/etc/postfix/virtual_mailboxes';
    private string $virtualAliasPath = '/etc/postfix/virtual_aliases';
    private string $virtualDomainsPath = '/etc/postfix/virtual_domains';
    private string $mailPath = '/home/vmail';
    private ?\PDO $pdo = null;
    private ?string $migrationPhase = null;

    public function getNamespace(): string
    {
        return 'mail';
    }

    public function getMethods(): array
    {
        return ['status', 'domains', 'addDomain', 'removeDomain', 'accounts', 'allAccounts', 'createAccount', 
                'bulkCreateAccounts', 'deleteAccount', 'resetPassword', 'setForcePasswordChange', 'suspendAccount', 
                'resumeAccount', 'forwards', 'allForwards', 'addForward', 
                'removeForward', 'queue', 'queueFlush', 'queueDelete', 'dnsRecords', 'dkimStatus', 'generateDkim', 
                'setupDnsRecord', 'davMigrate'];
    }

    /**
     * Get database connection for DNS operations (now uses our panel's database)
     */
    private function getMailDb(): ?\PDO
    {
        return $this->getPanelDb();
    }

    /**
     * Idempotently ensure the force_password_change column exists on
     * mail_accounts. Lets older deployments self-heal the schema the first time
     * an account is created/flagged, without needing a separate migration run.
     */
    private function ensureForcePasswordChangeColumn(\PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS force_password_change TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\Exception $e) {
            // Non-fatal: column may already exist on engines without IF NOT EXISTS.
            $this->logger->warning('ensureForcePasswordChangeColumn skipped: ' . $e->getMessage());
        }
    }

    /**
     * Idempotently ensure the login-suspension columns exist on mail_accounts.
     * Lets older deployments self-heal the schema the first time an account is
     * listed/suspended, without needing a separate migration run. Mirrors
     * ensureForcePasswordChangeColumn().
     */
    private function ensureLoginSuspendedColumn(\PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS login_suspended TINYINT(1) NOT NULL DEFAULT 0");
            $db->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMP NULL DEFAULT NULL");
            $db->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS suspended_reason VARCHAR(255) DEFAULT NULL");
        } catch (\Exception $e) {
            // Non-fatal: columns may already exist on engines without IF NOT EXISTS.
            $this->logger->warning('ensureLoginSuspendedColumn skipped: ' . $e->getMessage());
        }
    }

    /**
     * Idempotently ensure the mailbox quota column exists on mail_accounts.
     * The quota is read by Dovecot's user_query (CONCAT('*:bytes=', quota_mb*1048576)),
     * so older deployments self-heal the schema the first time accounts are listed.
     * This runs only on the read path (never on the quota-update write path) so a
     * quota change never triggers DDL or risks locking the table.
     */
    private function ensureQuotaColumn(\PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE mail_accounts ADD COLUMN IF NOT EXISTS quota_mb INT DEFAULT 5120 COMMENT '0 = unlimited; default 5 GB'");
        } catch (\Exception $e) {
            // Non-fatal: column may already exist on engines without IF NOT EXISTS.
            $this->logger->warning('ensureQuotaColumn skipped: ' . $e->getMessage());
        }
    }

    /**
     * Coerce a mailbox quota (MB) into a safe value. 0 = unlimited; any other
     * value is clamped to [100, 1048576] (100 MB .. 1 TB). Invalid/absent input
     * falls back to $default. Strict rejection (vs. clamping) for admin changes
     * lives in MailAccountAdminAction; here we only need a sane stored value at
     * account-creation time.
     */
    private function sanitizeQuotaMb($value, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        $mb = (int) $value;
        if ($mb <= 0) {
            return 0; // unlimited
        }
        return max(100, min(1048576, $mb));
    }

    // getPanelDb() is provided by PanelDbTrait (shared with MailAccountAdminAction).

    /**
     * Check if we should dual-write to our tables
     */
    private function isDualWriteEnabled(): bool
    {
        if ($this->migrationPhase !== null) {
            return in_array($this->migrationPhase, ['dual_write', 'switched', 'completed']);
        }

        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            $stmt = $panelDb->query("SELECT migration_phase FROM mail_migration_status WHERE id = 1");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->migrationPhase = $row['migration_phase'] ?? 'not_started';
            return in_array($this->migrationPhase, ['dual_write', 'switched', 'completed']);
        } catch (\Exception $e) {
            // Table doesn't exist yet
            return false;
        }
    }

    /**
     * Write account to our panel database (for dual-write mode)
     */
    private function writeAccountToPanel(string $email, string $domain, string $username, string $passwordHash, string $maildirPath): bool
    {
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            // Ensure domain exists
            $panelDb->prepare("INSERT IGNORE INTO mail_domains (domain) VALUES (?)")->execute([$domain]);

            // Insert or update account
            $stmt = $panelDb->prepare("
                INSERT INTO mail_accounts (email, domain, username, password_hash, maildir_path, status)
                VALUES (?, ?, ?, ?, ?, 'active')
                ON DUPLICATE KEY UPDATE 
                    password_hash = VALUES(password_hash),
                    maildir_path = VALUES(maildir_path),
                    updated_at = NOW()
            ");
            $stmt->execute([$email, $domain, $username, $passwordHash, $maildirPath]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to write account to panel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete account from our panel database (for dual-write mode)
     */
    private function deleteAccountFromPanel(string $email): bool
    {
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            $stmt = $panelDb->prepare("DELETE FROM mail_accounts WHERE email = ?");
            $stmt->execute([$email]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to delete account from panel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update password in our panel database (for dual-write mode)
     */
    private function updatePasswordInPanel(string $email, string $passwordHash): bool
    {
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            $stmt = $panelDb->prepare("UPDATE mail_accounts SET password_hash = ?, updated_at = NOW() WHERE email = ?");
            $stmt->execute([$passwordHash, $email]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to update password in panel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Write forward to our panel database (for dual-write mode)
     */
    private function writeForwardToPanel(string $source, string $destination): bool
    {
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            $domain = explode('@', $source)[1] ?? '';
            $stmt = $panelDb->prepare("
                INSERT IGNORE INTO mail_forwards (source_email, source_domain, destination, status)
                VALUES (?, ?, ?, 'active')
            ");
            $stmt->execute([$source, $domain, $destination]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to write forward to panel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete forward from our panel database (for dual-write mode)
     */
    private function deleteForwardFromPanel(string $source): bool
    {
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return false;
        }

        try {
            $stmt = $panelDb->prepare("DELETE FROM mail_forwards WHERE source_email = ?");
            $stmt->execute([$source]);
            return true;
        } catch (\Exception $e) {
            error_log("Failed to delete forward from panel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Read MySQL password from .my.cnf
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

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['addDomain', 'removeDomain', 'createAccount', 'deleteAccount', 
                                   'addForward', 'removeForward']);
    }

    /**
     * Get mail system status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $postfixStatus = $this->execCommand('systemctl', ['is-active', 'postfix']);
        $dovecotStatus = $this->execCommand('systemctl', ['is-active', 'dovecot']);

        $queueResult = $this->execCommand('postqueue', ['-p']);
        $queueCount = 0;
        if (preg_match('/-- (\d+) Kbytes in (\d+) Request/', $queueResult['output'], $m)) {
            $queueCount = (int)$m[2];
        }

        // Canonical mail hostname as Postfix actually advertises it
        // (postconf -h myhostname). This is the value the TLS certificate is
        // issued for and what clients should use for IMAP/POP3/SMTP, so the
        // panel can show real connection settings instead of a placeholder.
        $hostnameResult = $this->execCommand('postconf', ['-h', 'myhostname']);
        $mailHostname = trim($hostnameResult['output'] ?? '');
        if ($mailHostname === '' || empty($hostnameResult['success'])) {
            $mailHostname = gethostname() ?: '';
        }

        return $this->success([
            'postfix' => [
                'running' => trim($postfixStatus['output']) === 'active',
            ],
            'dovecot' => [
                'running' => trim($dovecotStatus['output']) === 'active',
            ],
            'queue_count' => $queueCount,
            'hostname' => $mailHostname,
        ]);
    }

    /**
     * List mail domains
     */
    protected function actionDomains(array $params, string $actor): array
    {
        $domains = [];

        // Read from our panel's database (primary after migration)
        $pdo = $this->getPanelDb();
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT domain FROM mail_domains WHERE status = 'active' ORDER BY domain");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $domain = $row['domain'];
                    $domains[] = [
                        'domain' => $domain,
                        'accounts' => $this->countAccounts($domain),
                        'forwards' => $this->countForwards($domain),
                    ];
                }
            } catch (\Exception $e) {
                error_log("Mail domains DB error: " . $e->getMessage());
            }
        }

        return $this->success(['domains' => $domains]);
    }

    /**
     * Add a mail domain
     */
    protected function actionAddDomain(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Add to our panel database (primary)
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                // Check if exists
                $stmt = $panelDb->prepare("SELECT id FROM mail_domains WHERE domain = ?");
                $stmt->execute([$domain]);
                if ($stmt->fetch()) {
                    return $this->error("Domain already exists: {$domain}");
                }

                // Insert domain
                $stmt = $panelDb->prepare("INSERT INTO mail_domains (domain, status) VALUES (?, 'active')");
                $stmt->execute([$domain]);
            } catch (\Exception $e) {
                return $this->error("Failed to add domain: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        // Create mail directory
        $mailDir = $this->mailPath . '/' . $domain;
        if (!is_dir($mailDir)) {
            mkdir($mailDir, 0755, true);
            $this->execCommand('chown', ['-R', 'vmail:vmail', $mailDir]);
        }

        // Automatically set up mail DNS records (DKIM, SPF, DMARC)
        $dnsSetup = $this->setupMailDnsRecords($domain, $actor);

        return $this->success([
            'domain' => $domain,
            'dns_setup' => $dnsSetup,
        ], "Mail domain {$domain} added with DNS records configured");
    }

    /**
     * Automatically set up all mail DNS records for a domain
     */
    private function setupMailDnsRecords(string $domain, string $actor): array
    {
        $results = [
            'dkim' => false,
            'spf' => false,
            'dmarc' => false,
            'mx' => false,
            'mail_a' => false,
        ];

        $serverIp = $this->getServerIp();

        // Check if DNS zone exists
        $pdo = $this->getMailDb();
        if (!$pdo) {
            return $results;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                // DNS zone doesn't exist, can't set up records automatically
                return $results;
            }

            $zoneId = $zone['id'];

            // 1. Generate DKIM keys
            $dkimResult = $this->generateDkimKeys($domain);
            $results['dkim'] = $dkimResult['success'];

            // 2. Set up MX record
            $results['mx'] = $this->addOrUpdateDnsRecord($pdo, $zoneId, $domain, $domain, 'MX', "mail.{$domain}", 3600, 10);

            // 3. Set up mail A record  
            $results['mail_a'] = $this->addOrUpdateDnsRecord($pdo, $zoneId, $domain, "mail.{$domain}", 'A', $serverIp, 3600);

            // 4. Set up SPF record (using -all for strict enforcement)
            $spfContent = "v=spf1 mx a ip4:{$serverIp} -all";
            $results['spf'] = $this->addOrUpdateDnsRecord($pdo, $zoneId, $domain, $domain, 'TXT', $spfContent, 3600);

            // 5. Set up DMARC record with strict policy
            $dmarcContent = "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$domain}; fo=1";
            $results['dmarc'] = $this->addOrUpdateDnsRecord($pdo, $zoneId, $domain, "_dmarc.{$domain}", 'TXT', $dmarcContent, 3600);

            // 6. Set up DKIM DNS record if keys were generated
            if ($dkimResult['success'] && !empty($dkimResult['record'])) {
                $dkimName = "default._domainkey.{$domain}";
                $results['dkim_dns'] = $this->addOrUpdateDnsRecord($pdo, $zoneId, $domain, $dkimName, 'TXT', $dkimResult['record'], 3600);
            }

            // Update SOA serial
            $this->updateSoaSerial($pdo, $zoneId, $domain);

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $domain]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to setup mail DNS records: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * Generate DKIM keys for a domain (internal helper)
     */
    /**
     * Parse DKIM record from opendkim-genkey output
     * Returns properly quoted TXT record content for PowerDNS
     */
    private function parseDkimRecord(string $content): ?string
    {
        // Extract all quoted strings from the TXT record
        if (preg_match_all('/"([^"]+)"/', $content, $matches)) {
            if (!empty($matches[1])) {
                // Rebuild with proper quoting for PowerDNS
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
        
        // Skip if already exists
        if (file_exists($privateKeyPath)) {
            // Read existing public key
            $publicKeyPath = "{$dkimPath}/{$selector}.txt";
            if (file_exists($publicKeyPath)) {
                $content = file_get_contents($publicKeyPath);
                $record = $this->parseDkimRecord($content);
                if ($record) {
                    return [
                        'success' => true,
                        'existing' => true,
                        'record' => $record,
                    ];
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
            return ['success' => false, 'error' => $result['output']];
        }

        // Set permissions
        $this->execCommand('chown', ['opendkim:opendkim', $privateKeyPath]);
        $this->execCommand('chmod', ['600', $privateKeyPath]);

        // Add to signing table
        $signingTablePath = '/etc/opendkim/SigningTable';
        $signingEntry = "*@{$domain} {$selector}._domainkey.{$domain}\n";
        
        $signingTable = file_exists($signingTablePath) ? file_get_contents($signingTablePath) : '';
        if (strpos($signingTable, $domain) === false) {
            file_put_contents($signingTablePath, $signingTable . $signingEntry);
        }

        // Add to key table
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
            'existing' => false,
            'record' => $dnsRecord,
        ];
    }

    /**
     * Add or update a DNS record
     */
    private function addOrUpdateDnsRecord(\PDO $pdo, int $zoneId, string $zone, string $name, string $type, string $content, int $ttl, int $prio = 0): bool
    {
        try {
            // Check if record already exists
            $stmt = $pdo->prepare("SELECT id FROM dns_records WHERE domain_id = ? AND name = ? AND type = ?");
            $stmt->execute([$zoneId, $name, $type]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE dns_records SET content = ?, ttl = ?, prio = ? WHERE id = ?");
                $stmt->execute([$content, $ttl, $prio, $existing['id']]);
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$zoneId, $name, $type, $content, $ttl, $prio]);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to add DNS record: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a mail domain
     */
    protected function actionRemoveDomain(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Delete from our panel database (primary)
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                // Delete all accounts for this domain first
                $stmt = $panelDb->prepare("DELETE FROM mail_accounts WHERE domain = ?");
                $stmt->execute([$domain]);
                
                // Delete all forwards for this domain
                $stmt = $panelDb->prepare("DELETE FROM mail_forwards WHERE source_domain = ?");
                $stmt->execute([$domain]);
                
                // Delete the domain
                $stmt = $panelDb->prepare("DELETE FROM mail_domains WHERE domain = ?");
                $stmt->execute([$domain]);
            } catch (\Exception $e) {
                return $this->error("Failed to remove domain: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        return $this->success([
            'domain' => $domain,
        ], "Mail domain {$domain} removed");
    }

    /**
     * List accounts for a domain
     */
    protected function actionAccounts(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $accounts = $this->getAccountsFromDatabase($domain);

        // Fallback to file-based if database query returned empty
        if (empty($accounts) && file_exists($this->virtualMailboxPath)) {
            $content = file_get_contents($this->virtualMailboxPath);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    $parts = preg_split('/\s+/', $line);
                    if (!empty($parts[0]) && str_ends_with($parts[0], '@' . $domain)) {
                        $email = $parts[0];
                        $mailbox = $parts[1] ?? '';
                        
                        // Get mailbox size
                        $mailDir = $this->mailPath . '/' . $domain . '/' . explode('@', $email)[0];
                        $size = is_dir($mailDir) ? $this->getDirectorySize($mailDir) : 0;
                        
                        $accounts[] = [
                            'email' => $email,
                            'domain' => $domain,
                            'mailbox' => $mailbox,
                            'size' => $size,
                            'size_human' => $this->humanFileSize($size),
                        ];
                    }
                }
            }
        }

        return $this->success([
            'domain' => $domain,
            'accounts' => $accounts,
        ]);
    }

    /**
     * List ALL email accounts from all domains
     */
    protected function actionAllAccounts(array $params, string $actor): array
    {
        $accounts = $this->getAccountsFromDatabase();

        // Fallback to file-based
        if (empty($accounts) && file_exists($this->virtualMailboxPath)) {
            $content = file_get_contents($this->virtualMailboxPath);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    $parts = preg_split('/\s+/', $line);
                    if (!empty($parts[0]) && strpos($parts[0], '@') !== false) {
                        $email = $parts[0];
                        $emailParts = explode('@', $email);
                        $domain = $emailParts[1] ?? '';
                        $mailbox = $parts[1] ?? '';
                        
                        // Get mailbox size
                        $mailDir = $this->mailPath . '/' . $domain . '/' . $emailParts[0];
                        $size = is_dir($mailDir) ? $this->getDirectorySize($mailDir) : 0;
                        
                        $accounts[] = [
                            'email' => $email,
                            'domain' => $domain,
                            'mailbox' => $mailbox,
                            'size' => $size,
                            'size_human' => $this->humanFileSize($size),
                        ];
                    }
                }
            }
        }

        // Sort by domain then email
        usort($accounts, function($a, $b) {
            $domainCmp = strcmp($a['domain'] ?? '', $b['domain'] ?? '');
            if ($domainCmp !== 0) return $domainCmp;
            return strcmp($a['email'], $b['email']);
        });

        return $this->success([
            'accounts' => $accounts,
            'count' => count($accounts),
        ]);
    }

    /**
     * Get accounts from database with email app integration data
     */
    private function getAccountsFromDatabase(?string $domain = null): array
    {
        // Read from our panel's database (primary after migration)
        $pdo = $this->getPanelDb();
        if (!$pdo) {
            return [];
        }

        $accounts = [];

        // Make sure the flag columns exist before selecting them (self-heal older DBs).
        $this->ensureForcePasswordChangeColumn($pdo);
        $this->ensureLoginSuspendedColumn($pdo);
        $this->ensureQuotaColumn($pdo);

        try {
            // Query includes email app data (auxiliary accounts, OAuth accounts, and drive usage)
            $sql = "SELECT 
                ma.email, 
                ma.domain, 
                ma.disk_usage_kb,
                ma.quota_mb,
                ma.force_password_change,
                ma.login_suspended,
                ma.suspended_at,
                (SELECT COUNT(*) FROM webmail_accounts wa 
                 WHERE wa.primary_email COLLATE utf8mb4_unicode_ci = ma.email COLLATE utf8mb4_unicode_ci) as aux_accounts,
                (SELECT COUNT(*) FROM webmail_oauth_tokens wot 
                 WHERE wot.primary_email COLLATE utf8mb4_unicode_ci = ma.email COLLATE utf8mb4_unicode_ci) as oauth_accounts,
                COALESCE(dq.used_bytes, 0) as drive_used,
                COALESCE(dq.quota_bytes, -1) as drive_quota
            FROM mail_accounts ma
            LEFT JOIN drive_quotas dq ON dq.user_email COLLATE utf8mb4_unicode_ci = ma.email COLLATE utf8mb4_unicode_ci
            WHERE ma.status = 'active'";
            
            if ($domain) {
                $sql .= " AND ma.domain = ?";
            }
            $sql .= " ORDER BY ma.domain, ma.email";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($domain ? [$domain] : []);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $email = $row['email'];
                $emailDomain = $row['domain'];
                $user = explode('@', $email)[0];
                
                // disk_usage_kb is in KB
                $diskUsage = (int)($row['disk_usage_kb'] ?? 0) * 1024;
                
                // Get actual mailbox size if available
                $mailDir = $this->mailPath . '/' . $emailDomain . '/' . $user;
                $size = is_dir($mailDir) ? $this->getDirectorySize($mailDir) : $diskUsage;
                
                // Email app data
                $auxAccounts = (int)($row['aux_accounts'] ?? 0);
                $oauthAccounts = (int)($row['oauth_accounts'] ?? 0);
                $driveUsed = (int)($row['drive_used'] ?? 0);
                $driveQuota = (int)($row['drive_quota'] ?? -1);
                $usesEmailApp = $auxAccounts > 0 || $oauthAccounts > 0 || $driveUsed > 0;

                // Mailbox quota in MB (0 = unlimited). Dovecot enforces this via
                // its user_query (quota_rule = quota_mb * 1MB).
                $quotaMb = (int)($row['quota_mb'] ?? 0);
                
                // Get linked account details
                $linkedAccountsList = $this->getLinkedAccounts($pdo, $email);
                
                $accounts[] = [
                    'email' => $email,
                    'domain' => $emailDomain,
                    'size' => $size,
                    'size_human' => $this->humanFileSize($size),
                    'mailbox_quota_mb' => $quotaMb,
                    'mailbox_quota_human' => $quotaMb <= 0 ? 'Unlimited' : $this->humanFileSize($quotaMb * 1048576),
                    'force_password_change' => (bool) ($row['force_password_change'] ?? false),
                    'suspended' => (bool) ($row['login_suspended'] ?? false),
                    'suspended_at' => $row['suspended_at'] ?? null,
                    'uses_email_app' => $usesEmailApp,
                    'aux_accounts' => $auxAccounts,
                    'oauth_accounts' => $oauthAccounts,
                    'linked_accounts' => $auxAccounts + $oauthAccounts,
                    'linked_accounts_list' => $linkedAccountsList,
                    'drive_used' => $driveUsed,
                    'drive_used_human' => $this->humanFileSize($driveUsed),
                    'drive_quota' => $driveQuota,
                    'drive_quota_human' => $driveQuota < 0 ? 'Unlimited' : $this->humanFileSize($driveQuota),
                ];
            }
        } catch (\Exception $e) {
            error_log("Mail DB error: " . $e->getMessage());
        }

        return $accounts;
    }
    
    /**
     * Get linked accounts (IMAP and OAuth) for an email
     */
    private function getLinkedAccounts(\PDO $pdo, string $email): array
    {
        $linked = [];
        
        try {
            // Get IMAP auxiliary accounts
            $stmt = $pdo->prepare("
                SELECT account_email, display_name, 'imap' as type 
                FROM webmail_accounts 
                WHERE primary_email COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
            ");
            $stmt->execute([$email]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $linked[] = [
                    'email' => $row['account_email'],
                    'name' => $row['display_name'] ?: $row['account_email'],
                    'type' => 'imap',
                ];
            }
            
            // Get OAuth accounts
            $stmt = $pdo->prepare("
                SELECT oauth_email, display_name, provider, 'oauth' as type 
                FROM webmail_oauth_tokens 
                WHERE primary_email COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci
            ");
            $stmt->execute([$email]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $linked[] = [
                    'email' => $row['oauth_email'],
                    'name' => $row['display_name'] ?: $row['oauth_email'],
                    'type' => 'oauth',
                    'provider' => $row['provider'],
                ];
            }
        } catch (\Exception $e) {
            error_log("Failed to get linked accounts for {$email}: " . $e->getMessage());
        }
        
        return $linked;
    }

    /**
     * Create a mail account
     */
    protected function actionCreateAccount(array $params, string $actor): array
    {
        if (!isset($params['email']) || !isset($params['password'])) {
            return $this->error('Email and password are required');
        }

        $email = strtolower($params['email']);
        $password = $params['password'];
        
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $parts = explode('@', $email);
        $user = $parts[0];
        $domain = $parts[1];

        // Check if account exists in our database
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            $stmt = $panelDb->prepare("SELECT id FROM mail_accounts WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return $this->error("Account already exists: {$email}");
            }
        }

        // Generate password hash using BLF-CRYPT (bcrypt) for compatibility
        $hashResult = $this->execCommand('doveadm', ['pw', '-s', 'BLF-CRYPT', '-p', $password]);
        $passwordHash = trim($hashResult['output']);

        if (!$passwordHash || !$hashResult['success']) {
            // Fallback to SHA512-CRYPT
            $hashResult = $this->execCommand('doveadm', ['pw', '-s', 'SHA512-CRYPT', '-p', $password]);
            $passwordHash = trim($hashResult['output']);
            
            if (!$passwordHash || !$hashResult['success']) {
                $salt = base64_encode(random_bytes(12));
                $passwordHash = '{SHA512-CRYPT}' . crypt($password, '$6$' . $salt);
            }
        }

        $mailboxPath = "{$domain}/{$user}/";
        $mailDir = $this->mailPath . '/' . $domain . '/' . $user;

        // A brand-new account must start with an empty mailbox. We only reach
        // this point when no DB row exists for the address, so any maildir still
        // sitting on disk is an orphan left behind by an older (buggy) delete —
        // inheriting it is what made deleted+recreated accounts come back with
        // their old mail. Move the orphan aside (recoverable) and start fresh.
        $safePath = $user !== '' && $domain !== ''
            && strpos($user, '/') === false && strpos($user, '..') === false
            && strpos($domain, '/') === false && strpos($domain, '..') === false;

        if ($safePath && is_dir($mailDir)) {
            $backupBase = $this->config['paths']['backups'] ?? '';
            $moved = false;
            if ($backupBase !== '') {
                $orphanBackup = rtrim($backupBase, '/') . '/orphan_mail/' . $email . '_' . date('Y-m-d_H-i-s');
                if (!is_dir(dirname($orphanBackup))) {
                    @mkdir(dirname($orphanBackup), 0755, true);
                }
                $moved = @rename($mailDir, $orphanBackup);
                if ($moved) {
                    $this->logger->info("Cleared orphan maildir before creating {$email} -> {$orphanBackup}");
                }
            }
            if (!$moved) {
                $this->execCommand('rm', ['-rf', $mailDir]);
                $this->logger->info("Cleared orphan maildir before creating {$email}: {$mailDir}");
            }
        }

        // Create a fresh mail directory
        if (!is_dir($mailDir)) {
            mkdir($mailDir, 0700, true);
            $this->execCommand('chown', ['-R', 'vmail:vmail', $mailDir]);
        }

        // Require the user to set a new password on first webmail login
        // (used for migrated mailboxes that get a temporary password).
        $forcePasswordChange = !empty($params['force_password_change']) ? 1 : 0;

        // Mailbox quota in MB at creation time. Accept quota_mb (preferred) or
        // legacy quota; fall back to the schema default when absent/invalid.
        $quotaMb = $this->sanitizeQuotaMb($params['quota_mb'] ?? $params['quota'] ?? null, 5120);

        // Write to our panel database (primary)
        if ($panelDb) {
            try {
                $this->ensureForcePasswordChangeColumn($panelDb);
                $this->ensureQuotaColumn($panelDb);

                // Ensure domain exists
                $panelDb->prepare("INSERT IGNORE INTO mail_domains (domain) VALUES (?)")->execute([$domain]);

                // Insert account
                $stmt = $panelDb->prepare("
                    INSERT INTO mail_accounts (email, domain, username, password_hash, maildir_path, status, force_password_change, quota_mb)
                    VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
                ");
                $stmt->execute([$email, $domain, $user, $passwordHash, $mailboxPath, $forcePasswordChange, $quotaMb]);
            } catch (\Exception $e) {
                return $this->error("Failed to create account: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        return $this->success([
            'email' => $email,
            'mailbox' => $mailboxPath,
        ], "Mail account {$email} created");
    }

    /**
     * Bulk-create mail accounts.
     *
     * Accepts params['accounts'] = [{email, password}, ...] and provisions
     * each one via actionCreateAccount so the hashing, maildir creation and
     * DB insert stay identical to single-account creation. Idempotent:
     * accounts that already exist are reported as "skipped" rather than
     * failing the whole batch, which is exactly what a migration re-run needs.
     */
    protected function actionBulkCreateAccounts(array $params, string $actor): array
    {
        if (empty($params['accounts']) || !is_array($params['accounts'])) {
            return $this->error('accounts array is required');
        }

        $results = [];
        $created = 0;
        $skipped = 0;
        $failed = 0;

        // Batch-level default: flag every newly created mailbox to force a
        // password change on first login (set by the migration provisioning).
        $batchForce = !empty($params['force_password_change']);

        foreach ($params['accounts'] as $entry) {
            $email = isset($entry['email']) ? strtolower(trim($entry['email'])) : '';
            $password = $entry['password'] ?? '';

            if ($email === '' || $password === '') {
                $failed++;
                $results[] = ['email' => $email, 'success' => false, 'skipped' => false, 'error' => 'email and password are required'];
                continue;
            }

            $force = $batchForce || !empty($entry['force_password_change']);
            $res = $this->actionCreateAccount(['email' => $email, 'password' => $password, 'force_password_change' => $force], $actor);

            if (!empty($res['success'])) {
                $created++;
                $results[] = ['email' => $email, 'success' => true, 'skipped' => false];
                continue;
            }

            $error = $res['error'] ?? 'Failed to create account';
            $isExisting = stripos($error, 'already exists') !== false;
            if ($isExisting) {
                $skipped++;
            } else {
                $failed++;
            }
            $results[] = ['email' => $email, 'success' => false, 'skipped' => $isExisting, 'error' => $error];
        }

        return $this->success([
            'total' => count($params['accounts']),
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ], "Bulk create finished: {$created} created, {$skipped} skipped, {$failed} failed");
    }

    /**
     * Delete a mail account
     */
    protected function actionDeleteAccount(array $params, string $actor): array
    {
        if (!isset($params['email'])) {
            return $this->error('Email is required');
        }

        $email = strtolower($params['email']);
        
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $parts = explode('@', $email);
        $user = $parts[0];
        $domain = $parts[1];

        // Delete from our panel database (primary)
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                $stmt = $panelDb->prepare("DELETE FROM mail_accounts WHERE email = ?");
                $stmt->execute([$email]);
            } catch (\Exception $e) {
                return $this->error("Failed to delete account: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        // Always clear the LIVE mail directory so the mailbox is genuinely gone.
        // If we leave it on disk, re-creating the same address silently inherits
        // all of the old mail (actionCreateAccount reuses an existing maildir),
        // which is exactly the "deleted account comes back with its data" bug.
        //
        // By default the maildir is moved to a timestamped backup first so an
        // accidental delete stays recoverable (same convention as deleted sites).
        // Pass purge=true (or delete_mail=true) to hard-wipe with no backup.
        // Either way the live location ends up empty.
        $mailDir = $this->mailPath . '/' . $domain . '/' . $user;

        // Safety: only ever touch a real subdirectory of the vmail root, never
        // the root itself, so a malformed email can't escalate into a wider rm.
        $safeToRemove = $user !== '' && $domain !== ''
            && strpos($user, '/') === false && strpos($user, '..') === false
            && strpos($domain, '/') === false && strpos($domain, '..') === false;

        if ($safeToRemove && is_dir($mailDir)) {
            $hardWipe = !empty($params['purge']) || !empty($params['delete_mail']);
            $backupBase = $this->config['paths']['backups'] ?? '';
            $movedToBackup = false;

            if (!$hardWipe && $backupBase !== '') {
                $backupDir = rtrim($backupBase, '/') . '/deleted_mail/' . $email . '_' . date('Y-m-d_H-i-s');
                if (!is_dir(dirname($backupDir))) {
                    @mkdir(dirname($backupDir), 0755, true);
                }
                $movedToBackup = @rename($mailDir, $backupDir);
                if ($movedToBackup) {
                    $this->logger->info("Backed up and removed maildir for {$email} -> {$backupDir}");
                }
            }

            // Backup not wanted, not configured, or the move failed (e.g. the
            // backups dir is on another filesystem): hard-delete so the live
            // maildir is cleared no matter what.
            if (!$movedToBackup) {
                $this->execCommand('rm', ['-rf', $mailDir]);
                $this->logger->info("Removed maildir for {$email}: {$mailDir}");
            }
        }

        return $this->success([
            'email' => $email,
        ], "Mail account {$email} deleted");
    }

    /**
     * Contacts/Calendar migration, run LOCALLY against the co-located Email App.
     * --------------------------------------------------------------------------
     * This is the URL-free, key-free path used by the Panel's "Contacts &
     * Calendar Migration" tool. Instead of making the Panel reach the webmail
     * over HTTP (which needs a domain + shared key), we invoke the Email App's
     * own dav-migrate CLI right here on the same box. No networking, no API key,
     * no webmail-URL configuration.
     *
     * Params:
     *   action      'import' | 'export'
     *   type        'contacts' | 'calendar'
     *   user_email  target mailbox
     *   format      (import, optional) 'vcf' | 'csv' — auto-detected when blank
     *   data        (import) raw VCF/CSV/ICS payload
     *
     * Heavy payloads are shuttled through temp files (never argv) and the exact
     * bytes are preserved so vCard/iCalendar CRLF line endings survive.
     */
    protected function actionDavMigrate(array $params, string $actor): array
    {
        $action = strtolower(trim((string) ($params['action'] ?? '')));
        $type = strtolower(trim((string) ($params['type'] ?? '')));
        $email = strtolower(trim((string) ($params['user_email'] ?? '')));
        $format = strtolower(trim((string) ($params['format'] ?? '')));

        if (!in_array($action, ['import', 'export'], true)) {
            return $this->error("action must be 'import' or 'export'");
        }
        if (!in_array($type, ['contacts', 'calendar'], true)) {
            return $this->error("type must be 'contacts' or 'calendar'");
        }
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        // Locate the Email App backend + its dav-migrate CLI.
        $backendDir = rtrim((string) ($this->config['paths']['email_app'] ?? '/var/www/vps-email/backend'), '/');
        $script = $backendDir . '/scripts/dav-migrate.php';
        if (!is_file($script)) {
            return $this->error("Email App migration helper not found at {$script}. Deploy the latest Email App backend.");
        }

        $php = $this->resolveEmailAppPhp();
        if ($php === null) {
            return $this->error('No suitable PHP binary found to run the Email App migration helper.');
        }

        $tmpFiles = [];
        try {
            if ($action === 'import') {
                $data = (string) ($params['data'] ?? '');
                if (trim($data) === '') {
                    return $this->error('No data provided to import');
                }
                $inFile = tempnam(sys_get_temp_dir(), 'dav_in_');
                if ($inFile === false) {
                    return $this->error('Could not create a temp file for the import payload');
                }
                $tmpFiles[] = $inFile;
                file_put_contents($inFile, $data);
                @chmod($inFile, 0644); // readable if the helper runs as another user

                $args = [
                    $script,
                    '--action=import',
                    '--type=' . $type,
                    '--user=' . $email,
                    '--in=' . $inFile,
                ];
                if ($format !== '') {
                    $args[] = '--format=' . $format;
                }

                $run = $this->execCommand($php, $args, 180);
                $parsed = $this->parseDavCliOutput($run);
                if (!$parsed['success']) {
                    return $this->error($parsed['error']);
                }

                return $this->success([
                    'type' => $type,
                    'user_email' => $email,
                    'imported' => (int) ($parsed['data']['imported'] ?? 0),
                    'updated' => (int) ($parsed['data']['updated'] ?? 0),
                    'total' => (int) ($parsed['data']['total'] ?? 0),
                ], 'Import complete');
            }

            // action === 'export'
            $outFile = tempnam(sys_get_temp_dir(), 'dav_out_');
            if ($outFile === false) {
                return $this->error('Could not create a temp file for the export output');
            }
            $tmpFiles[] = $outFile;
            @chmod($outFile, 0666); // writable if the helper runs as another user

            $args = [
                $script,
                '--action=export',
                '--type=' . $type,
                '--user=' . $email,
                '--out=' . $outFile,
            ];

            $run = $this->execCommand($php, $args, 180);
            $parsed = $this->parseDavCliOutput($run);
            if (!$parsed['success']) {
                return $this->error($parsed['error']);
            }

            $payload = is_readable($outFile) ? (string) file_get_contents($outFile) : '';

            return $this->success([
                'type' => $type,
                'user_email' => $email,
                'data' => $payload,
                'filename' => (string) ($parsed['data']['filename'] ?? ($type . '.txt')),
                'mime' => (string) ($parsed['data']['mime'] ?? 'text/plain'),
                'count' => (int) ($parsed['data']['count'] ?? 0),
            ], 'Export complete');
        } finally {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * Parse the single-line JSON the dav-migrate CLI prints on stdout. The CLI
     * sends diagnostics to stderr, so we read the last non-empty stdout line as
     * the JSON envelope and surface a useful error if the helper failed.
     *
     * @return array{success:bool,data:array,error:string}
     */
    private function parseDavCliOutput(array $run): array
    {
        $output = trim((string) ($run['output'] ?? ''));

        // Find the JSON object in the output (last line that looks like JSON).
        $json = null;
        foreach (array_reverse(preg_split('/\r?\n/', $output) ?: []) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') {
                $json = $line;
                break;
            }
        }

        $decoded = $json !== null ? json_decode($json, true) : null;

        if (is_array($decoded) && !empty($decoded['success'])) {
            return ['success' => true, 'data' => $decoded, 'error' => ''];
        }

        // Prefer the helper's own error message; fall back to raw output.
        $err = is_array($decoded) && isset($decoded['error'])
            ? (string) $decoded['error']
            : ($output !== '' ? $output : 'Email App migration helper failed with no output');

        return ['success' => false, 'data' => [], 'error' => $err];
    }

    /**
     * Find a PHP CLI binary capable of running the Email App helper. Prefers the
     * OpenLiteSpeed lsphp builds the app already runs under, then the system php.
     */
    private function resolveEmailAppPhp(): ?string
    {
        $candidates = [];
        if (!empty($this->config['paths']['email_app_php'])) {
            $candidates[] = (string) $this->config['paths']['email_app_php'];
        }
        $candidates = array_merge($candidates, [
            '/usr/local/lsws/lsphp83/bin/php',
            '/usr/local/lsws/lsphp82/bin/php',
            '/usr/local/lsws/lsphp81/bin/php',
            '/usr/local/lsws/lsphp80/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ]);

        foreach ($candidates as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }

        // Last resort: rely on PATH.
        $which = $this->execCommand('command', ['-v', 'php']);
        $path = trim((string) ($which['output'] ?? ''));
        if ($path !== '' && is_executable($path)) {
            return $path;
        }

        return null;
    }

    /**
     * Reset mail account password
     */
    protected function actionResetPassword(array $params, string $actor): array
    {
        if (!isset($params['email'])) {
            return $this->error('Email is required');
        }
        
        if (!isset($params['password']) || empty($params['password'])) {
            return $this->error('Password is required');
        }

        $email = strtolower(trim($params['email']));
        $password = $params['password'];
        
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $this->logger->info("Resetting password for {$email}");

        // Generate password hash using BLF-CRYPT (bcrypt) for compatibility
        $hashResult = $this->execCommand('doveadm', ['pw', '-s', 'BLF-CRYPT', '-p', $password]);
        $passwordHash = trim($hashResult['output']);

        if (!$passwordHash || !$hashResult['success']) {
            // Fallback to SHA512-CRYPT
            $hashResult = $this->execCommand('doveadm', ['pw', '-s', 'SHA512-CRYPT', '-p', $password]);
            $passwordHash = trim($hashResult['output']);
            
            if (!$passwordHash || !$hashResult['success']) {
                $salt = base64_encode(random_bytes(12));
                $passwordHash = '{SHA512-CRYPT}' . crypt($password, '$6$' . $salt);
            }
        }
        
        $this->logger->info("Generated password hash for {$email}");

        $domain = substr($email, strpos($email, '@') + 1);

        // Update in our panel database
        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return $this->error("Cannot connect to panel database");
        }
        
        try {
            // First check if account exists
            $stmt = $panelDb->prepare("SELECT id, email FROM mail_accounts WHERE email = ? OR LOWER(email) = ?");
            $stmt->execute([$email, $email]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$account) {
                $this->logger->warning("Account not found in database: {$email}");
                return $this->error("Account not found: {$email}");
            }
            
            $this->logger->info("Found account ID {$account['id']} for {$email}");
            
            // Update password using the ID for precision
            $stmt = $panelDb->prepare("UPDATE mail_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$passwordHash, $account['id']]);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                $this->logger->error("Update failed: " . json_encode($errorInfo));
                return $this->error("Database update failed");
            }
            
            $this->logger->info("Password updated successfully for {$email}");
            
        } catch (\Exception $e) {
            $this->logger->error("Exception during password reset: " . $e->getMessage());
            return $this->error("Failed to reset password: " . $e->getMessage());
        }

        return $this->success([
            'email' => $email,
        ], "Password reset for {$email}");
    }

    /**
     * Set or clear the "force password change on next login" flag for a mailbox.
     * Params: email (required), enabled (bool, default true).
     */
    protected function actionSetForcePasswordChange(array $params, string $actor): array
    {
        if (!isset($params['email'])) {
            return $this->error('Email is required');
        }

        $email = strtolower(trim($params['email']));
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $enabled = array_key_exists('enabled', $params) ? (!empty($params['enabled']) ? 1 : 0) : 1;

        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return $this->error("Cannot connect to panel database");
        }

        try {
            $this->ensureForcePasswordChangeColumn($panelDb);

            $stmt = $panelDb->prepare("UPDATE mail_accounts SET force_password_change = ?, updated_at = NOW() WHERE LOWER(email) = ?");
            $stmt->execute([$enabled, $email]);

            if ($stmt->rowCount() === 0) {
                // Verify the account actually exists (rowCount can be 0 when the value is unchanged).
                $check = $panelDb->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
                $check->execute([$email]);
                if (!$check->fetch()) {
                    return $this->error("Account not found: {$email}");
                }
            }
        } catch (\Exception $e) {
            return $this->error("Failed to update flag: " . $e->getMessage());
        }

        return $this->success([
            'email' => $email,
            'force_password_change' => (bool) $enabled,
        ], $enabled ? "Password change will be required on next login for {$email}" : "Password change requirement cleared for {$email}");
    }

    /**
     * Suspend a mailbox: block IMAP/POP3/SMTP-AUTH and webmail login while
     * leaving status='active' so incoming mail keeps being delivered.
     *
     * The actual login block is enforced by Dovecot's password_query
     * (`... AND login_suspended = 0`); here we just flip the flag and then
     * `doveadm kick` the user so any already-open IMAP sessions are dropped
     * immediately instead of surviving until they reconnect.
     *
     * Params: email (required), reason (optional).
     */
    protected function actionSuspendAccount(array $params, string $actor): array
    {
        if (!isset($params['email'])) {
            return $this->error('Email is required');
        }

        $email = strtolower(trim($params['email']));
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $reason = isset($params['reason']) ? trim((string) $params['reason']) : '';
        if ($reason === '') {
            $reason = null;
        } elseif (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return $this->error("Cannot connect to panel database");
        }

        try {
            $this->ensureLoginSuspendedColumn($panelDb);

            $stmt = $panelDb->prepare("
                UPDATE mail_accounts
                SET login_suspended = 1, suspended_at = NOW(), suspended_reason = ?, updated_at = NOW()
                WHERE LOWER(email) = ?
            ");
            $stmt->execute([$reason, $email]);

            if ($stmt->rowCount() === 0) {
                // rowCount can be 0 when nothing changed; confirm the account exists.
                $check = $panelDb->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
                $check->execute([$email]);
                if (!$check->fetch()) {
                    return $this->error("Account not found: {$email}");
                }
            }
        } catch (\Exception $e) {
            return $this->error("Failed to suspend account: " . $e->getMessage());
        }

        // Drop any live IMAP/POP3 sessions so the lockout is immediate. Best
        // effort: if doveadm is unavailable the DB flag still blocks the next
        // login, so a kick failure must not fail the whole operation.
        $kick = $this->execCommand('doveadm', ['kick', $email]);
        if (empty($kick['success'])) {
            $this->logger->warning("doveadm kick failed for {$email}: " . ($kick['output'] ?? ''));
        }

        // Invalidate any open webmail sessions for this mailbox. doveadm kick
        // only covers IMAP/POP3 clients (Outlook, phones); the webmail app keeps
        // its own server-side session rows (with the encrypted IMAP password), so
        // we delete them here to force an immediate webmail logout and wipe the
        // stored credential. Best effort: the table lives in the shared app DB
        // and may be absent on a panel-only box, so failures are non-fatal.
        try {
            $sess = $panelDb->prepare("DELETE FROM webmail_sessions WHERE LOWER(email) = ?");
            $sess->execute([$email]);
        } catch (\Exception $e) {
            $this->logger->warning("Could not revoke webmail sessions for {$email}: " . $e->getMessage());
        }

        $this->logger->info("Suspended mail login for {$email} by {$actor}");

        return $this->success([
            'email' => $email,
            'suspended' => true,
        ], "Login suspended for {$email} (mail still being received)");
    }

    /**
     * Resume a suspended mailbox: re-enable login. Incoming mail was never
     * interrupted, so everything received during the suspension is waiting.
     *
     * Params: email (required).
     */
    protected function actionResumeAccount(array $params, string $actor): array
    {
        if (!isset($params['email'])) {
            return $this->error('Email is required');
        }

        $email = strtolower(trim($params['email']));
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $panelDb = $this->getPanelDb();
        if (!$panelDb) {
            return $this->error("Cannot connect to panel database");
        }

        try {
            $this->ensureLoginSuspendedColumn($panelDb);

            $stmt = $panelDb->prepare("
                UPDATE mail_accounts
                SET login_suspended = 0, suspended_at = NULL, suspended_reason = NULL, updated_at = NOW()
                WHERE LOWER(email) = ?
            ");
            $stmt->execute([$email]);

            if ($stmt->rowCount() === 0) {
                $check = $panelDb->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
                $check->execute([$email]);
                if (!$check->fetch()) {
                    return $this->error("Account not found: {$email}");
                }
            }
        } catch (\Exception $e) {
            return $this->error("Failed to resume account: " . $e->getMessage());
        }

        $this->logger->info("Resumed mail login for {$email} by {$actor}");

        return $this->success([
            'email' => $email,
            'suspended' => false,
        ], "Login resumed for {$email}");
    }

    /**
     * List forwards for a domain
     */
    protected function actionForwards(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $forwards = $this->getForwardsFromDatabase($domain);

        // Fallback to file-based
        if (empty($forwards) && file_exists($this->virtualAliasPath)) {
            $content = file_get_contents($this->virtualAliasPath);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    $parts = preg_split('/\s+/', $line, 2);
                    if (!empty($parts[0]) && str_ends_with($parts[0], '@' . $domain)) {
                        $forwards[] = [
                            'source' => $parts[0],
                            'destination' => $parts[1] ?? '',
                            'domain' => $domain,
                        ];
                    }
                }
            }
        }

        return $this->success([
            'domain' => $domain,
            'forwards' => $forwards,
        ]);
    }

    /**
     * List ALL email forwards from all domains
     */
    protected function actionAllForwards(array $params, string $actor): array
    {
        $forwards = $this->getForwardsFromDatabase();

        // Fallback to file-based
        if (empty($forwards) && file_exists($this->virtualAliasPath)) {
            $content = file_get_contents($this->virtualAliasPath);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    $parts = preg_split('/\s+/', $line, 2);
                    if (!empty($parts[0]) && strpos($parts[0], '@') !== false) {
                        $emailParts = explode('@', $parts[0]);
                        $forwards[] = [
                            'source' => $parts[0],
                            'destination' => $parts[1] ?? '',
                            'domain' => $emailParts[1] ?? '',
                        ];
                    }
                }
            }
        }

        // Sort by domain then source
        usort($forwards, function($a, $b) {
            $domainCmp = strcmp($a['domain'] ?? '', $b['domain'] ?? '');
            if ($domainCmp !== 0) return $domainCmp;
            return strcmp($a['source'], $b['source']);
        });

        return $this->success([
            'forwards' => $forwards,
            'count' => count($forwards),
        ]);
    }

    /**
     * Get forwards from database
     */
    private function getForwardsFromDatabase(?string $domain = null): array
    {
        // Read from our panel's database (primary after migration)
        $pdo = $this->getPanelDb();
        if (!$pdo) {
            return [];
        }

        $forwards = [];

        try {
            $sql = "SELECT id, source_email, source_domain, destination FROM mail_forwards WHERE status = 'active'";
            if ($domain) {
                $sql .= " AND source_domain = ?";
            }
            $sql .= " ORDER BY source_email";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($domain ? [$domain] : []);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $forwards[] = [
                    'id' => $row['id'],
                    'source' => $row['source_email'],
                    'destination' => $row['destination'],
                    'domain' => $row['source_domain'],
                ];
            }
        } catch (\Exception $e) {
            error_log("Mail forwards DB error: " . $e->getMessage());
        }

        return $forwards;
    }

    /**
     * Add a mail forward
     */
    protected function actionAddForward(array $params, string $actor): array
    {
        if (!isset($params['source']) || !isset($params['destination'])) {
            return $this->error('Source and destination are required');
        }

        $source = strtolower($params['source']);
        $destination = strtolower($params['destination']);
        
        if (!Validator::email($source) || !Validator::email($destination)) {
            return $this->error('Invalid email format');
        }

        $domain = explode('@', $source)[1] ?? '';

        // Write to our panel database (primary)
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                // Check if exists
                $stmt = $panelDb->prepare("SELECT id FROM mail_forwards WHERE source_email = ? AND destination = ?");
                $stmt->execute([$source, $destination]);
                if ($stmt->fetch()) {
                    return $this->error("Forward already exists: {$source} -> {$destination}");
                }

                // Insert forward
                $stmt = $panelDb->prepare("
                    INSERT INTO mail_forwards (source_email, source_domain, destination, status)
                    VALUES (?, ?, ?, 'active')
                ");
                $stmt->execute([$source, $domain, $destination]);
            } catch (\Exception $e) {
                return $this->error("Failed to add forward: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        return $this->success([
            'source' => $source,
            'destination' => $destination,
        ], "Forward added: {$source} -> {$destination}");
    }

    /**
     * Remove a mail forward
     * If destination is provided, removes only that specific forward
     * Otherwise removes ALL forwards for the source
     */
    protected function actionRemoveForward(array $params, string $actor): array
    {
        if (!isset($params['source'])) {
            return $this->error('Source is required');
        }

        $source = strtolower($params['source']);
        $destination = isset($params['destination']) ? strtolower($params['destination']) : null;
        
        if (!Validator::email($source)) {
            return $this->error('Invalid email format');
        }
        
        if ($destination && !Validator::email($destination)) {
            return $this->error('Invalid destination email format');
        }

        // Delete from our panel database (primary)
        $panelDb = $this->getPanelDb();
        if ($panelDb) {
            try {
                if ($destination) {
                    // Remove specific forward
                    $stmt = $panelDb->prepare("DELETE FROM mail_forwards WHERE source_email = ? AND destination = ?");
                    $stmt->execute([$source, $destination]);
                    $message = "Forward removed: {$source} -> {$destination}";
                } else {
                    // Remove all forwards for this source
                    $stmt = $panelDb->prepare("DELETE FROM mail_forwards WHERE source_email = ?");
                    $stmt->execute([$source]);
                    $message = "All forwards removed for {$source}";
                }
            } catch (\Exception $e) {
                return $this->error("Failed to remove forward: " . $e->getMessage());
            }
        } else {
            return $this->error("Cannot connect to panel database");
        }

        return $this->success([
            'source' => $source,
            'destination' => $destination,
        ], $message);
    }

    /**
     * Get mail queue
     */
    protected function actionQueue(array $params, string $actor): array
    {
        $result = $this->execCommand('postqueue', ['-j']);
        
        if (!$result['success']) {
            return $this->error('Failed to get queue: ' . $result['output']);
        }

        $queue = [];
        $lines = explode("\n", trim($result['output']));
        
        foreach ($lines as $line) {
            if ($line) {
                $item = json_decode($line, true);
                if ($item) {
                    $queue[] = $item;
                }
            }
        }

        return $this->success([
            'queue' => $queue,
            'count' => count($queue),
        ]);
    }

    /**
     * Flush mail queue
     */
    protected function actionQueueFlush(array $params, string $actor): array
    {
        $result = $this->execCommand('postqueue', ['-f']);
        
        return $this->success([], 'Mail queue flushed');
    }

    /**
     * Delete from queue
     */
    protected function actionQueueDelete(array $params, string $actor): array
    {
        if (!isset($params['queue_id'])) {
            return $this->error('Queue ID is required');
        }

        $queueId = $params['queue_id'];
        
        // Validate queue ID format
        if (!preg_match('/^[A-F0-9]+$/i', $queueId)) {
            return $this->error('Invalid queue ID format');
        }

        $result = $this->execCommand('postsuper', ['-d', $queueId]);
        
        if ($result['success']) {
            return $this->success([
                'queue_id' => $queueId,
            ], "Message {$queueId} deleted from queue");
        }

        return $this->error("Failed to delete message: " . $result['output']);
    }

    /**
     * Count accounts for a domain
     */
    private function countAccounts(string $domain): int
    {
        $pdo = $this->getPanelDb();
        if (!$pdo) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mail_accounts WHERE domain = ? AND status = 'active'");
            $stmt->execute([$domain]);
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Count forwards for a domain
     */
    private function countForwards(string $domain): int
    {
        $pdo = $this->getPanelDb();
        if (!$pdo) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mail_forwards WHERE source_domain = ? AND status = 'active'");
            $stmt->execute([$domain]);
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }

    /**
     * Convert bytes to human readable
     */
    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get recommended DNS records for email (SPF, DKIM, DMARC)
     */
    protected function actionDnsRecords(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Get server IP
        $serverIp = $this->getServerIp();
        
        // Check for existing DKIM key
        $dkimPath = "/etc/opendkim/keys/{$domain}";
        $dkimSelector = 'default';
        $dkimPublicKey = null;
        $dkimRecord = null;
        
        if (file_exists("{$dkimPath}/{$dkimSelector}.txt")) {
            $dkimContent = file_get_contents("{$dkimPath}/{$dkimSelector}.txt");
            // Parse the DKIM TXT record from opendkim-genkey output
            if (preg_match('/\(\s*"([^"]+)"\s*(?:"([^"]+)")?\s*\)/', $dkimContent, $matches)) {
                $dkimPublicKey = str_replace(["\n", "\t", " "], '', $matches[1] . ($matches[2] ?? ''));
            } elseif (preg_match('/v=DKIM1[^)]+/', $dkimContent, $matches)) {
                $dkimRecord = trim($matches[0]);
            }
        }

        // Build recommended records
        $records = [
            'mx' => [
                'type' => 'MX',
                'name' => $domain,
                'content' => "mail.{$domain}",
                'priority' => 10,
                'description' => 'Mail server for receiving emails',
                'example' => "10 mail.{$domain}",
            ],
            'mail_a' => [
                'type' => 'A',
                'name' => "mail.{$domain}",
                'content' => $serverIp,
                'description' => 'Mail server IP address',
                'example' => $serverIp,
            ],
            'spf' => [
                'type' => 'TXT',
                'name' => $domain,
                'content' => "v=spf1 a mx ip4:{$serverIp} -all",
                'description' => 'SPF record to authorize mail servers',
                'example' => "v=spf1 a mx ip4:{$serverIp} -all",
            ],
            'dmarc' => [
                'type' => 'TXT',
                'name' => "_dmarc.{$domain}",
                'content' => "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$domain}; fo=1",
                'description' => 'DMARC policy for handling failed authentication',
                'example' => "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$domain}; fo=1",
            ],
        ];

        // Add DKIM record if available
        if ($dkimPublicKey) {
            $records['dkim'] = [
                'type' => 'TXT',
                'name' => "{$dkimSelector}._domainkey.{$domain}",
                'content' => "v=DKIM1; k=rsa; p={$dkimPublicKey}",
                'description' => 'DKIM public key for email signing',
                'example' => "v=DKIM1; k=rsa; p=...",
                'generated' => true,
            ];
        } elseif ($dkimRecord) {
            $records['dkim'] = [
                'type' => 'TXT',
                'name' => "{$dkimSelector}._domainkey.{$domain}",
                'content' => $dkimRecord,
                'description' => 'DKIM public key for email signing',
                'example' => $dkimRecord,
                'generated' => true,
            ];
        } else {
            $records['dkim'] = [
                'type' => 'TXT',
                'name' => "{$dkimSelector}._domainkey.{$domain}",
                'content' => null,
                'description' => 'DKIM public key for email signing (not generated yet)',
                'example' => 'v=DKIM1; k=rsa; p=...',
                'generated' => false,
            ];
        }

        // Check current DNS status
        foreach ($records as $key => &$record) {
            $record['status'] = $this->checkDnsRecord($domain, $record['name'], $record['type']);
        }

        return $this->success([
            'domain' => $domain,
            'server_ip' => $serverIp,
            'records' => $records,
            'dkim_configured' => !empty($dkimPublicKey) || !empty($dkimRecord),
        ]);
    }

    /**
     * Get DKIM status for a domain
     */
    protected function actionDkimStatus(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $dkimPath = "/etc/opendkim/keys/{$domain}";
        $dkimSelector = 'default';
        
        $privateKeyPath = "{$dkimPath}/{$dkimSelector}.private";
        $publicKeyPath = "{$dkimPath}/{$dkimSelector}.txt";
        
        $configured = false;
        $publicKey = null;
        $record = null;
        
        if (file_exists($publicKeyPath)) {
            $configured = true;
            $content = file_get_contents($publicKeyPath);
            
            // Extract the public key from the TXT record format
            if (preg_match('/p=([A-Za-z0-9+\/=]+)/', $content, $matches)) {
                $publicKey = $matches[1];
            }
            
            // Get the full record
            if (preg_match('/\(\s*"([^"]+)"\s*(?:"([^"]+)")?\s*\)/s', $content, $matches)) {
                $record = trim($matches[1] . ($matches[2] ?? ''));
            }
        }

        // Check if DKIM is in the signing table
        $signingTablePath = '/etc/opendkim/SigningTable';
        $inSigningTable = false;
        if (file_exists($signingTablePath)) {
            $signingTable = file_get_contents($signingTablePath);
            $inSigningTable = strpos($signingTable, $domain) !== false;
        }

        // Check if DKIM is in the key table
        $keyTablePath = '/etc/opendkim/KeyTable';
        $inKeyTable = false;
        if (file_exists($keyTablePath)) {
            $keyTable = file_get_contents($keyTablePath);
            $inKeyTable = strpos($keyTable, $domain) !== false;
        }

        return $this->success([
            'domain' => $domain,
            'selector' => $dkimSelector,
            'configured' => $configured,
            'private_key_exists' => file_exists($privateKeyPath),
            'public_key_exists' => file_exists($publicKeyPath),
            'in_signing_table' => $inSigningTable,
            'in_key_table' => $inKeyTable,
            'public_key' => $publicKey,
            'record' => $record,
            'dns_name' => "{$dkimSelector}._domainkey.{$domain}",
        ]);
    }

    /**
     * Generate DKIM keys for a domain
     */
    protected function actionGenerateDkim(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $selector = $params['selector'] ?? 'default';
        $bits = $params['bits'] ?? 2048;
        
        // Validate bits
        if (!in_array($bits, [1024, 2048, 4096])) {
            $bits = 2048;
        }

        // Create directory
        $dkimPath = "/etc/opendkim/keys/{$domain}";
        if (!is_dir($dkimPath)) {
            mkdir($dkimPath, 0700, true);
        }

        // Check if key already exists
        $privateKeyPath = "{$dkimPath}/{$selector}.private";
        if (file_exists($privateKeyPath) && !($params['force'] ?? false)) {
            return $this->error("DKIM key already exists for {$domain}. Use force=true to regenerate.");
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
            return $this->error('Failed to generate DKIM keys: ' . $result['output']);
        }

        // Set permissions
        $this->execCommand('chown', ['opendkim:opendkim', $privateKeyPath]);
        $this->execCommand('chmod', ['600', $privateKeyPath]);

        // Add to signing table
        $signingTablePath = '/etc/opendkim/SigningTable';
        $signingEntry = "*@{$domain} {$selector}._domainkey.{$domain}\n";
        
        $signingTable = file_exists($signingTablePath) ? file_get_contents($signingTablePath) : '';
        if (strpos($signingTable, $domain) === false) {
            file_put_contents($signingTablePath, $signingTable . $signingEntry);
        }

        // Add to key table
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
        $publicKeyContent = file_exists($publicKeyPath) ? file_get_contents($publicKeyPath) : '';
        
        // Parse the public key for DNS record
        $dnsRecord = null;
        if (preg_match('/\(\s*"([^"]+)"\s*(?:"([^"]+)")?\s*\)/s', $publicKeyContent, $matches)) {
            $dnsRecord = trim($matches[1] . ($matches[2] ?? ''));
        }

        return $this->success([
            'domain' => $domain,
            'selector' => $selector,
            'bits' => $bits,
            'dns_name' => "{$selector}._domainkey.{$domain}",
            'dns_record' => $dnsRecord,
            'public_key_path' => $publicKeyPath,
        ], "DKIM keys generated for {$domain}");
    }

    /**
     * Setup a DNS record (SPF, DKIM, or DMARC) for a domain
     */
    protected function actionSetupDnsRecord(array $params, string $actor): array
    {
        if (!isset($params['domain']) || !isset($params['record_type'])) {
            return $this->error('Domain and record_type are required');
        }

        $domain = $params['domain'];
        $recordType = strtolower($params['record_type']);
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        if (!in_array($recordType, ['spf', 'dkim', 'dmarc', 'mx', 'mail_a'])) {
            return $this->error('Invalid record type. Must be: spf, dkim, dmarc, mx, or mail_a');
        }

        $serverIp = $this->getServerIp();
        
        // Get DNS connection
        try {
            $pdo = $this->getMailDb();
            if (!$pdo) {
                return $this->error('Unable to connect to database');
            }

            // Get zone ID
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$domain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                return $this->error("DNS zone not found for {$domain}. Create the zone first.");
            }

            $zoneId = $zone['id'];
            $name = $domain;
            $type = 'TXT';
            $content = '';
            $ttl = 3600;
            $prio = 0;

            switch ($recordType) {
                case 'spf':
                    $content = $params['content'] ?? "v=spf1 a mx ip4:{$serverIp} -all";
                    break;
                    
                case 'dmarc':
                    $name = "_dmarc.{$domain}";
                    $policy = $params['policy'] ?? 'reject';
                    $rua = $params['rua'] ?? "postmaster@{$domain}";
                    $content = "v=DMARC1; p={$policy}; adkim=s; aspf=s; pct=100; rua=mailto:{$rua}; fo=1";
                    break;
                    
                case 'dkim':
                    $selector = $params['selector'] ?? 'default';
                    $name = "{$selector}._domainkey.{$domain}";
                    
                    // Get the DKIM public key
                    $dkimPath = "/etc/opendkim/keys/{$domain}/{$selector}.txt";
                    if (!file_exists($dkimPath)) {
                        return $this->error("DKIM key not found. Generate DKIM keys first.");
                    }
                    
                    $dkimContent = file_get_contents($dkimPath);
                    if (preg_match('/\(\s*"([^"]+)"\s*(?:"([^"]+)")?\s*\)/s', $dkimContent, $matches)) {
                        $content = trim($matches[1] . ($matches[2] ?? ''));
                    } else {
                        return $this->error("Could not parse DKIM public key");
                    }
                    break;
                    
                case 'mx':
                    $type = 'MX';
                    $content = $params['content'] ?? "mail.{$domain}";
                    $prio = $params['priority'] ?? 10;
                    break;
                    
                case 'mail_a':
                    $type = 'A';
                    $name = "mail.{$domain}";
                    $content = $params['content'] ?? $serverIp;
                    break;
            }

            // Check if record already exists
            $stmt = $pdo->prepare("SELECT id FROM dns_records WHERE domain_id = ? AND name = ? AND type = ?");
            $stmt->execute([$zoneId, $name, $type]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE dns_records SET content = ?, ttl = ?, prio = ? WHERE id = ?");
                $stmt->execute([$content, $ttl, $prio, $existing['id']]);
                $recordId = $existing['id'];
                $action = 'updated';
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$zoneId, $name, $type, $content, $ttl, $prio]);
                $recordId = $pdo->lastInsertId();
                $action = 'created';
            }

            // Update SOA serial
            $this->updateSoaSerial($pdo, $zoneId, $domain);

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $domain]);

            return $this->success([
                'record_id' => $recordId,
                'domain' => $domain,
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'action' => $action,
            ], "DNS record {$action} for {$domain}");

        } catch (\Exception $e) {
            return $this->error('Failed to setup DNS record: ' . $e->getMessage());
        }
    }

    /**
     * Get server IP address
     */
    private function getServerIp(): string
    {
        // Use configured IP first (most reliable - never changes)
        if (!empty($this->config['server']['ip'])) {
            return $this->config['server']['ip'];
        }

        // Fallback: Try hostname -I
        $result = $this->execCommand('hostname', ['-I']);
        if ($result['success']) {
            $ips = explode(' ', trim($result['output']));
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '10.') && !str_starts_with($ip, '192.168.')) {
                    return $ip;
                }
            }
            // Fallback to first IP
            if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP)) {
                return $ips[0];
            }
        }
        
        // Last resort: External service (unreliable)
        $result = $this->execCommand('curl', ['-s', '-m', '5', 'ifconfig.me']);
        if ($result['success'] && filter_var(trim($result['output']), FILTER_VALIDATE_IP)) {
            return trim($result['output']);
        }

        return '0.0.0.0';
    }

    /**
     * Check if a DNS record exists
     */
    private function checkDnsRecord(string $domain, string $name, string $type): array
    {
        $result = $this->execCommand('dig', ['+short', $name, $type]);
        
        $exists = $result['success'] && !empty(trim($result['output']));
        
        return [
            'exists' => $exists,
            'current_value' => $exists ? trim($result['output']) : null,
        ];
    }

    /**
     * Update SOA serial for a zone
     */
    private function updateSoaSerial(\PDO $pdo, int $zoneId, string $domain): void
    {
        $stmt = $pdo->prepare("SELECT id, content FROM dns_records WHERE domain_id = ? AND type = 'SOA'");
        $stmt->execute([$zoneId]);
        $soa = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($soa) {
            $parts = explode(' ', $soa['content']);
            $currentSerial = $parts[2] ?? 0;
            $today = date('Ymd');
            
            if (substr($currentSerial, 0, 8) === $today) {
                $newSerial = $currentSerial + 1;
            } else {
                $newSerial = $today . '01';
            }

            $parts[2] = $newSerial;
            $newContent = implode(' ', $parts);

            $stmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
            $stmt->execute([$newContent, $soa['id']]);
        }
    }
}

