<?php

namespace Webmail\Services;

/**
 * AccountService - Manages multiple email accounts per user
 * 
 * Allows adding external IMAP accounts (Gmail, Yahoo, custom servers)
 * Credentials are encrypted using AES-256-CBC before storage
 */
class AccountService
{
    private \PDO $db;
    private string $encryptionKey;
    
    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Use dedicated IMAP encryption key if available, otherwise fall back to JWT secret
        $imapKey = $config['imap_encryption_key'] ?? '';
        $keySource = $imapKey ?: ($config['jwt']['secret'] ?? 'default_key');
        $this->encryptionKey = hash('sha256', $keySource, true);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }
    
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_accounts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    primary_email VARCHAR(255) NOT NULL COMMENT 'The main login email (owner)',
                    account_email VARCHAR(255) NOT NULL COMMENT 'The linked account email',
                    display_name VARCHAR(255) DEFAULT NULL,
                    imap_host VARCHAR(255) NOT NULL,
                    imap_port INT DEFAULT 993,
                    imap_encryption ENUM('ssl', 'tls', 'none') DEFAULT 'ssl',
                    smtp_host VARCHAR(255) NOT NULL,
                    smtp_port INT DEFAULT 465,
                    smtp_encryption ENUM('ssl', 'tls', 'none') DEFAULT 'ssl',
                    credentials_encrypted TEXT NOT NULL COMMENT 'AES-encrypted password',
                    is_default TINYINT(1) DEFAULT 0,
                    account_type ENUM('separate', 'linked') DEFAULT 'separate' COMMENT 'separate=full switch, linked=sync into main',
                    sync_frequency INT DEFAULT 15 COMMENT 'Sync frequency in minutes for linked accounts',
                    leave_on_server TINYINT(1) DEFAULT 1 COMMENT 'Keep emails on source server after sync',
                    auto_label VARCHAR(255) DEFAULT NULL COMMENT 'Auto-apply this label to synced emails',
                    sync_enabled TINYINT(1) DEFAULT 1 COMMENT 'Enable/disable sync for linked accounts',
                    last_sync TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_primary_email (primary_email),
                    INDEX idx_account_type (account_type),
                    UNIQUE KEY unique_account (primary_email, account_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add new columns if table already exists (migration)
            $this->migrateAccountsTable();
            
            // Create synced messages tracking table for linked accounts
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_synced_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL COMMENT 'FK to webmail_accounts',
                    source_uid INT NOT NULL COMMENT 'UID in source mailbox',
                    source_folder VARCHAR(255) DEFAULT 'INBOX' COMMENT 'Source folder name',
                    message_id VARCHAR(512) NOT NULL COMMENT 'Message-ID header for matching',
                    local_uid INT DEFAULT NULL COMMENT 'UID in local mailbox after copy',
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    deleted_at TIMESTAMP NULL COMMENT 'When deleted locally (to sync back)',
                    INDEX idx_account_id (account_id),
                    INDEX idx_source_uid (account_id, source_folder, source_uid),
                    INDEX idx_message_id (message_id(255)),
                    UNIQUE KEY unique_message (account_id, source_folder, source_uid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("AccountService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Migrate existing accounts table to add new columns
     */
    private function migrateAccountsTable(): void
    {
        try {
            // Check if account_type column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM webmail_accounts LIKE 'account_type'");
            if ($stmt->rowCount() === 0) {
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN account_type ENUM('separate', 'linked') DEFAULT 'separate' AFTER is_default");
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN sync_frequency INT DEFAULT 15 AFTER account_type");
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN leave_on_server TINYINT(1) DEFAULT 1 AFTER sync_frequency");
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN auto_label VARCHAR(255) DEFAULT NULL AFTER leave_on_server");
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN sync_enabled TINYINT(1) DEFAULT 1 AFTER auto_label");
                $this->db->exec("ALTER TABLE webmail_accounts ADD INDEX idx_account_type (account_type)");
            }
            
            // Add signature column if it doesn't exist
            $stmt = $this->db->query("SHOW COLUMNS FROM webmail_accounts LIKE 'signature'");
            if ($stmt->rowCount() === 0) {
                $this->db->exec("ALTER TABLE webmail_accounts ADD COLUMN signature TEXT DEFAULT NULL AFTER auto_label");
            }
        } catch (\PDOException $e) {
            // Ignore errors if columns already exist
        }
    }
    
    /**
     * Encrypt password for storage
     */
    /**
     * Encrypt password using AES-256-GCM (authenticated encryption)
     * Output format: "gcm:" + base64( iv[12] + tag[16] + ciphertext )
     */
    private function encryptPassword(string $password): string
    {
        $iv = random_bytes(12); // 96-bit IV recommended for GCM
        $tag = '';
        $encrypted = openssl_encrypt($password, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($encrypted === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed');
        }

        // Pack: iv (12) + tag (16) + ciphertext
        return 'gcm:' . base64_encode($iv . $tag . $encrypted);
    }
    
    /**
     * Decrypt password — supports both AES-256-GCM (new) and AES-256-CBC (legacy)
     */
    public function decryptPassword(string $encrypted): string
    {
        // New GCM format
        if (str_starts_with($encrypted, 'gcm:')) {
            $data = base64_decode(substr($encrypted, 4), true);
            if ($data === false || strlen($data) < 28) {
                throw new \RuntimeException('Invalid GCM encrypted data');
            }

            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);

            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

            if ($decrypted === false) {
                throw new \RuntimeException('AES-256-GCM decryption failed — possible tampering');
            }

            return $decrypted;
        }

        // Legacy CBC format (backward compatibility for existing DB records)
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('AES-256-CBC decryption failed');
        }

        return $decrypted;
    }
    
    /**
     * Get all accounts for a user
     */
    public function getAccounts(string $primaryEmail, ?string $accountType = null): array
    {
        $sql = '
            SELECT id, primary_email, account_email, display_name, 
                   imap_host, imap_port, imap_encryption,
                   smtp_host, smtp_port, smtp_encryption,
                   is_default, account_type, sync_frequency, leave_on_server,
                   auto_label, signature, sync_enabled, last_sync, created_at
            FROM webmail_accounts 
            WHERE primary_email = ?
        ';
        $params = [strtolower($primaryEmail)];
        
        if ($accountType) {
            $sql .= ' AND account_type = ?';
            $params[] = $accountType;
        }
        
        $sql .= ' ORDER BY is_default DESC, created_at ASC';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return array_map(function($acc) {
            $acc['is_default'] = (bool)$acc['is_default'];
            $acc['leave_on_server'] = (bool)$acc['leave_on_server'];
            $acc['sync_enabled'] = (bool)$acc['sync_enabled'];
            return $acc;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get only linked accounts (for sync purposes)
     */
    public function getLinkedAccounts(string $primaryEmail): array
    {
        return $this->getAccounts($primaryEmail, 'linked');
    }
    
    /**
     * Get only separate accounts (for account switching)
     */
    public function getSeparateAccounts(string $primaryEmail): array
    {
        return $this->getAccounts($primaryEmail, 'separate');
    }
    
    /**
     * Get a single account by ID
     */
    public function getAccount(string $primaryEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM webmail_accounts 
            WHERE primary_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id]);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['is_default'] = (bool)$account['is_default'];
            // Don't return encrypted credentials in normal queries
            unset($account['credentials_encrypted']);
        }
        
        return $account ?: null;
    }
    
    /**
     * Get account with decrypted credentials (for IMAP/SMTP connections)
     */
    public function getAccountWithCredentials(string $primaryEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM webmail_accounts 
            WHERE primary_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($primaryEmail), $id]);
        $account = $stmt->fetch();
        
        if ($account) {
            $account['is_default'] = (bool)$account['is_default'];
            $account['password'] = $this->decryptPassword($account['credentials_encrypted']);
            unset($account['credentials_encrypted']);
        }
        
        return $account ?: null;
    }
    
    /**
     * Add a new account
     */
    public function addAccount(string $primaryEmail, array $data): ?array
    {
        $primaryEmail = strtolower($primaryEmail);
        $accountEmail = strtolower($data['account_email'] ?? '');
        
        if (!$accountEmail || !($data['password'] ?? '')) {
            return null;
        }
        
        try {
            // Check if account already exists
            $stmt = $this->db->prepare('SELECT id FROM webmail_accounts WHERE primary_email = ? AND account_email = ?');
            $stmt->execute([$primaryEmail, $accountEmail]);
            if ($stmt->fetch()) {
                return null; // Account already exists
            }
            
            $accountType = $data['account_type'] ?? 'separate';
            
            // Check if this should be default (first account, only for separate accounts)
            $isDefault = 0;
            if ($accountType === 'separate') {
                $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM webmail_accounts WHERE primary_email = ? AND account_type = ?');
                $stmt->execute([$primaryEmail, 'separate']);
                $count = $stmt->fetch()['cnt'];
                $isDefault = $count === 0 ? 1 : ($data['is_default'] ?? 0 ? 1 : 0);
                
                // If setting as default, unset other defaults
                if ($isDefault) {
                    $this->db->prepare('UPDATE webmail_accounts SET is_default = 0 WHERE primary_email = ? AND account_type = ?')
                        ->execute([$primaryEmail, 'separate']);
                }
            }
            
            $stmt = $this->db->prepare('
                INSERT INTO webmail_accounts 
                (primary_email, account_email, display_name, imap_host, imap_port, imap_encryption,
                 smtp_host, smtp_port, smtp_encryption, credentials_encrypted, is_default,
                 account_type, sync_frequency, leave_on_server, auto_label, sync_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $primaryEmail,
                $accountEmail,
                $data['display_name'] ?? null,
                $data['imap_host'] ?? 'localhost',
                (int)($data['imap_port'] ?? 993),
                $data['imap_encryption'] ?? 'ssl',
                $data['smtp_host'] ?? $data['imap_host'] ?? 'localhost',
                (int)($data['smtp_port'] ?? 465),
                $data['smtp_encryption'] ?? 'ssl',
                $this->encryptPassword($data['password']),
                $isDefault,
                $accountType,
                (int)($data['sync_frequency'] ?? 15),
                $data['leave_on_server'] ?? 1 ? 1 : 0,
                $data['auto_label'] ?? null,
                $data['sync_enabled'] ?? 1 ? 1 : 0,
            ]);
            
            return $this->getAccount($primaryEmail, (int)$this->db->lastInsertId());
            
        } catch (\PDOException $e) {
            error_log("AccountService addAccount error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update an account
     */
    public function updateAccount(string $primaryEmail, int $id, array $data): ?array
    {
        $primaryEmail = strtolower($primaryEmail);
        
        $fields = [];
        $values = [];
        
        if (isset($data['display_name'])) {
            $fields[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }
        if (isset($data['imap_host'])) {
            $fields[] = 'imap_host = ?';
            $values[] = $data['imap_host'];
        }
        if (isset($data['imap_port'])) {
            $fields[] = 'imap_port = ?';
            $values[] = (int)$data['imap_port'];
        }
        if (isset($data['imap_encryption'])) {
            $fields[] = 'imap_encryption = ?';
            $values[] = $data['imap_encryption'];
        }
        if (isset($data['smtp_host'])) {
            $fields[] = 'smtp_host = ?';
            $values[] = $data['smtp_host'];
        }
        if (isset($data['smtp_port'])) {
            $fields[] = 'smtp_port = ?';
            $values[] = (int)$data['smtp_port'];
        }
        if (isset($data['smtp_encryption'])) {
            $fields[] = 'smtp_encryption = ?';
            $values[] = $data['smtp_encryption'];
        }
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = 'credentials_encrypted = ?';
            $values[] = $this->encryptPassword($data['password']);
        }
        if (isset($data['is_default']) && $data['is_default']) {
            // Unset other defaults first (only for same account type)
            $this->db->prepare('UPDATE webmail_accounts SET is_default = 0 WHERE primary_email = ? AND account_type = (SELECT account_type FROM webmail_accounts WHERE id = ?)')
                ->execute([$primaryEmail, $id]);
            $fields[] = 'is_default = 1';
        }
        // Account type switching
        if (isset($data['account_type']) && in_array($data['account_type'], ['separate', 'linked'])) {
            $fields[] = 'account_type = ?';
            $values[] = $data['account_type'];
        }
        // Linked account specific fields
        if (isset($data['sync_frequency'])) {
            $fields[] = 'sync_frequency = ?';
            $values[] = (int)$data['sync_frequency'];
        }
        if (isset($data['leave_on_server'])) {
            $fields[] = 'leave_on_server = ?';
            $values[] = $data['leave_on_server'] ? 1 : 0;
        }
        if (isset($data['auto_label'])) {
            $fields[] = 'auto_label = ?';
            $values[] = $data['auto_label'] ?: null;
        }
        if (array_key_exists('signature', $data)) {
            $fields[] = 'signature = ?';
            $values[] = $data['signature'] ?: null;
        }
        if (isset($data['sync_enabled'])) {
            $fields[] = 'sync_enabled = ?';
            $values[] = $data['sync_enabled'] ? 1 : 0;
        }
        
        if (empty($fields)) {
            return $this->getAccount($primaryEmail, $id);
        }
        
        $values[] = $primaryEmail;
        $values[] = $id;
        
        try {
            $stmt = $this->db->prepare('
                UPDATE webmail_accounts 
                SET ' . implode(', ', $fields) . ' 
                WHERE primary_email = ? AND id = ?
            ');
            $stmt->execute($values);
            
            return $this->getAccount($primaryEmail, $id);
        } catch (\PDOException $e) {
            error_log("AccountService updateAccount error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete an account
     */
    public function deleteAccount(string $primaryEmail, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_accounts WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Test account connection - tests IMAP and SMTP separately
     */
    public function testConnection(array $config): array
    {
        $result = [
            'success' => false,
            'imap' => ['tested' => false, 'success' => false, 'error' => null, 'folders_count' => 0],
            'smtp' => ['tested' => false, 'success' => false, 'error' => null],
        ];
        
        // Test IMAP
        try {
            $imapService = new ImapService([
                'host' => $config['imap_host'],
                'port' => (int)$config['imap_port'],
                'encryption' => $config['imap_encryption'],
                'validate_cert' => false,
            ]);
            
            $result['imap']['tested'] = true;
            
            if ($imapService->connect($config['account_email'], $config['password'])) {
                $folders = $imapService->listFolders();
                $imapService->disconnect();
                $result['imap']['success'] = true;
                $result['imap']['folders_count'] = count($folders);
            } else {
                $result['imap']['error'] = 'Authentication failed';
            }
        } catch (\Exception $e) {
            $result['imap']['error'] = $e->getMessage();
        }
        
        // Test SMTP if host provided - use PHPMailer's built-in connection test
        if (!empty($config['smtp_host'])) {
            try {
                $result['smtp']['tested'] = true;
                
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->Port = (int)($config['smtp_port'] ?? 465);
                $mail->SMTPAuth = true;
                $mail->Username = $config['account_email'];
                $mail->Password = $config['password'];
                
                $encryption = $config['smtp_encryption'] ?? 'tls';
                if ($encryption === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($encryption === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                }
                
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];
                
                // Test connection without sending
                if ($mail->smtpConnect()) {
                    $mail->smtpClose();
                    $result['smtp']['success'] = true;
                } else {
                    $result['smtp']['error'] = 'SMTP connection failed';
                }
            } catch (\Exception $e) {
                $result['smtp']['error'] = $e->getMessage();
            }
        }
        
        // Overall success if IMAP works (SMTP is optional for reading)
        $result['success'] = $result['imap']['success'];
        
        if (!$result['success']) {
            $result['error'] = $result['imap']['error'] ?? 'Connection failed';
        }
        
        return $result;
    }
    
    /**
     * Auto-detect server settings based on email domain
     */
    public static function detectSettings(string $email): array
    {
        $domain = strtolower(substr($email, strpos($email, '@') + 1));
        
        // Check known providers first
        $knownProviders = self::getKnownDomains();
        if (isset($knownProviders[$domain])) {
            return [
                'detected' => true,
                'provider' => $knownProviders[$domain]['name'],
                'settings' => $knownProviders[$domain],
            ];
        }
        
        // Try common patterns
        $patterns = [
            // Most common pattern: mail.domain.com or imap.domain.com
            ['imap_host' => "mail.$domain", 'smtp_host' => "mail.$domain"],
            ['imap_host' => "imap.$domain", 'smtp_host' => "smtp.$domain"],
            ['imap_host' => $domain, 'smtp_host' => $domain],
        ];
        
        // Try MX record lookup to determine mail server
        $mxHosts = [];
        if (getmxrr($domain, $mxHosts)) {
            $mxHost = strtolower($mxHosts[0]);
            
            // Check if MX points to known provider
            if (strpos($mxHost, 'google') !== false || strpos($mxHost, 'gmail') !== false) {
                return [
                    'detected' => true,
                    'provider' => 'Google Workspace',
                    'settings' => self::getProviderPresets()['gmail'],
                ];
            }
            if (strpos($mxHost, 'outlook') !== false || strpos($mxHost, 'microsoft') !== false) {
                return [
                    'detected' => true,
                    'provider' => 'Microsoft 365',
                    'settings' => self::getProviderPresets()['outlook'],
                ];
            }
            if (strpos($mxHost, 'yahoo') !== false) {
                return [
                    'detected' => true,
                    'provider' => 'Yahoo',
                    'settings' => self::getProviderPresets()['yahoo'],
                ];
            }
        }
        
        // Return best guess with common patterns
        return [
            'detected' => false,
            'provider' => null,
            'settings' => [
                'imap_host' => "mail.$domain",
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => "mail.$domain",
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'suggestions' => [
                ['imap_host' => "imap.$domain", 'smtp_host' => "smtp.$domain"],
                ['imap_host' => $domain, 'smtp_host' => $domain],
            ],
        ];
    }
    
    /**
     * Get known domain to provider mappings
     */
    private static function getKnownDomains(): array
    {
        return [
            // Google
            'gmail.com' => ['name' => 'Gmail'] + self::getProviderPresets()['gmail'],
            'googlemail.com' => ['name' => 'Gmail'] + self::getProviderPresets()['gmail'],
            
            // Microsoft
            'outlook.com' => ['name' => 'Outlook'] + self::getProviderPresets()['outlook'],
            'hotmail.com' => ['name' => 'Hotmail'] + self::getProviderPresets()['outlook'],
            'live.com' => ['name' => 'Live'] + self::getProviderPresets()['outlook'],
            'msn.com' => ['name' => 'MSN'] + self::getProviderPresets()['outlook'],
            
            // Yahoo
            'yahoo.com' => ['name' => 'Yahoo'] + self::getProviderPresets()['yahoo'],
            'yahoo.co.uk' => ['name' => 'Yahoo'] + self::getProviderPresets()['yahoo'],
            'ymail.com' => ['name' => 'Yahoo'] + self::getProviderPresets()['yahoo'],
            
            // Apple
            'icloud.com' => ['name' => 'iCloud'] + self::getProviderPresets()['icloud'],
            'me.com' => ['name' => 'iCloud'] + self::getProviderPresets()['icloud'],
            'mac.com' => ['name' => 'iCloud'] + self::getProviderPresets()['icloud'],
            
            // Other popular providers
            'aol.com' => [
                'name' => 'AOL',
                'imap_host' => 'imap.aol.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.aol.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'zoho.com' => [
                'name' => 'Zoho',
                'imap_host' => 'imap.zoho.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.zoho.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'protonmail.com' => [
                'name' => 'ProtonMail',
                'imap_host' => '127.0.0.1',
                'imap_port' => 1143,
                'imap_encryption' => 'none',
                'smtp_host' => '127.0.0.1',
                'smtp_port' => 1025,
                'smtp_encryption' => 'none',
                'note' => 'Requires ProtonMail Bridge',
            ],
            'gmx.com' => [
                'name' => 'GMX',
                'imap_host' => 'imap.gmx.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.gmx.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            
            // Devcon1 domains
            'devcon1.hu' => [
                'name' => 'Devcon1',
                'imap_host' => 'mail.devcon1.hu',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.devcon1.hu',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'pixelranger.hu' => [
                'name' => 'Devcon1',
                'imap_host' => 'mail.devcon1.hu',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.devcon1.hu',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
        ];
    }
    
    /**
     * Update last sync time
     */
    public function updateLastSync(string $primaryEmail, int $id): void
    {
        $stmt = $this->db->prepare('UPDATE webmail_accounts SET last_sync = NOW() WHERE primary_email = ? AND id = ?');
        $stmt->execute([strtolower($primaryEmail), $id]);
    }
    
    /**
     * Check if a message has already been synced
     */
    public function isMessageSynced(int $accountId, string $folder, int $uid): bool
    {
        $stmt = $this->db->prepare('
            SELECT id FROM webmail_synced_messages 
            WHERE account_id = ? AND source_folder = ? AND source_uid = ? AND deleted_at IS NULL
        ');
        $stmt->execute([$accountId, $folder, $uid]);
        return (bool)$stmt->fetch();
    }
    
    /**
     * Record a synced message
     */
    public function recordSyncedMessage(int $accountId, string $folder, int $sourceUid, string $messageId, ?int $localUid = null): bool
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_synced_messages 
                (account_id, source_folder, source_uid, message_id, local_uid)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE local_uid = VALUES(local_uid), synced_at = NOW()
            ');
            return $stmt->execute([$accountId, $folder, $sourceUid, $messageId, $localUid]);
        } catch (\PDOException $e) {
            error_log("AccountService recordSyncedMessage error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all synced message UIDs for an account/folder
     */
    public function getSyncedMessageUids(int $accountId, string $folder = 'INBOX'): array
    {
        $stmt = $this->db->prepare('
            SELECT source_uid FROM webmail_synced_messages 
            WHERE account_id = ? AND source_folder = ? AND deleted_at IS NULL
        ');
        $stmt->execute([$accountId, $folder]);
        return array_column($stmt->fetchAll(), 'source_uid');
    }
    
    /**
     * Mark a synced message as deleted (for sync back to source)
     */
    public function markSyncedMessageDeleted(int $accountId, string $messageId): bool
    {
        try {
            $stmt = $this->db->prepare('
                UPDATE webmail_synced_messages 
                SET deleted_at = NOW() 
                WHERE account_id = ? AND message_id = ?
            ');
            return $stmt->execute([$accountId, $messageId]);
        } catch (\PDOException $e) {
            error_log("AccountService markSyncedMessageDeleted error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get messages marked for deletion (to sync back to source)
     */
    public function getDeletedSyncedMessages(int $accountId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM webmail_synced_messages 
            WHERE account_id = ? AND deleted_at IS NOT NULL
        ');
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Remove synced message record after successful deletion from source
     */
    public function removeSyncedMessage(int $accountId, int $sourceUid, string $folder): bool
    {
        try {
            $stmt = $this->db->prepare('
                DELETE FROM webmail_synced_messages 
                WHERE account_id = ? AND source_folder = ? AND source_uid = ?
            ');
            return $stmt->execute([$accountId, $folder, $sourceUid]);
        } catch (\PDOException $e) {
            error_log("AccountService removeSyncedMessage error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get accounts that need syncing (linked accounts with sync_enabled)
     */
    public function getAccountsNeedingSync(): array
    {
        $stmt = $this->db->prepare('
            SELECT a.*, a.credentials_encrypted as password_encrypted
            FROM webmail_accounts a
            WHERE a.account_type = ? 
            AND a.sync_enabled = 1
            AND (a.last_sync IS NULL OR a.last_sync < DATE_SUB(NOW(), INTERVAL a.sync_frequency MINUTE))
        ');
        $stmt->execute(['linked']);
        
        return array_map(function($acc) {
            $acc['password'] = $this->decryptPassword($acc['password_encrypted']);
            unset($acc['password_encrypted'], $acc['credentials_encrypted']);
            return $acc;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get PDO instance for use in sync service
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    /**
     * Get preset configurations for common email providers
     */
    public static function getProviderPresets(): array
    {
        return [
            'gmail' => [
                'name' => 'Gmail',
                'imap_host' => 'imap.gmail.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
                'note' => 'Requires App Password if 2FA is enabled',
            ],
            'outlook' => [
                'name' => 'Outlook / Hotmail',
                'imap_host' => 'outlook.office365.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.office365.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'yahoo' => [
                'name' => 'Yahoo Mail',
                'imap_host' => 'imap.mail.yahoo.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.mail.yahoo.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
                'note' => 'Requires App Password',
            ],
            'icloud' => [
                'name' => 'iCloud Mail',
                'imap_host' => 'imap.mail.me.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.mail.me.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
                'note' => 'Requires App-specific password',
            ],
            'devcon1' => [
                'name' => 'Devcon1',
                'imap_host' => 'mail.devcon1.hu',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.devcon1.hu',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
            'custom' => [
                'name' => 'Custom IMAP Server',
                'imap_host' => '',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => '',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
            ],
        ];
    }
}

