<?php

namespace Webmail\Services;

/**
 * SpamService - Manages spam blocking, safe senders, and Rspamd Bayes training.
 */
class SpamService
{
    private ?\PDO $db = null;
    private array $config;
    private bool $initialized = false;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initDatabase();
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        if ($this->initialized) {
            return;
        }
        
        try {
            $this->db = \Webmail\Core\Database::getConnection($this->config);
            
            \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
            $this->initialized = true;
        } catch (\PDOException $e) {
            error_log("SpamService database connection error: " . $e->getMessage());
            // Don't throw - allow service to be created, methods will return empty/false
        }
    }
    
    /**
     * Check if database is available
     */
    private function isDbAvailable(): bool
    {
        return $this->db !== null;
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Blocked senders table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_blocked_senders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    blocked_email VARCHAR(255) NOT NULL,
                    blocked_domain VARCHAR(255) DEFAULT NULL,
                    reason VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_block (user_email, blocked_email),
                    INDEX idx_user (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Safe senders (whitelist) table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_safe_senders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    safe_email VARCHAR(255) NOT NULL,
                    safe_domain VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_safe (user_email, safe_email),
                    INDEX idx_user (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Spam statistics table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_spam_stats (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    action ENUM('reported_spam', 'not_spam', 'blocked', 'unblocked', 'safe_added', 'safe_removed', 'auto_deleted') NOT NULL,
                    target_email VARCHAR(255) DEFAULT NULL,
                    message_id VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_date (user_email, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Spam settings table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_spam_settings (
                    user_email VARCHAR(255) PRIMARY KEY,
                    auto_delete_days INT DEFAULT 30,
                    auto_training_enabled TINYINT(1) DEFAULT 1,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("SpamService table creation error: " . $e->getMessage());
        }
    }
    
    // ==================== BLOCKED SENDERS ====================
    
    /**
     * Get all blocked senders for a user
     */
    public function getBlockedSenders(string $userEmail): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        $stmt = $this->db->prepare('
            SELECT id, blocked_email, blocked_domain, reason, created_at 
            FROM webmail_blocked_senders 
            WHERE user_email = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetchAll();
    }
    
    /**
     * Block a sender
     */
    public function blockSender(string $userEmail, string $blockedEmail, ?string $reason = null, bool $blockDomain = false): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        $blockedEmail = strtolower($blockedEmail);
        $domain = $blockDomain ? $this->extractDomain($blockedEmail) : null;
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_blocked_senders (user_email, blocked_email, blocked_domain, reason) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE blocked_domain = VALUES(blocked_domain), reason = VALUES(reason)
            ');
            $stmt->execute([$userEmail, $blockedEmail, $domain, $reason]);
            
            // When blocking a domain, upgrade all existing entries from the same domain
            if ($blockDomain && $domain) {
                $stmt = $this->db->prepare('
                    UPDATE webmail_blocked_senders 
                    SET blocked_domain = ?, reason = COALESCE(reason, ?)
                    WHERE user_email = ? AND blocked_email LIKE ? AND blocked_domain IS NULL
                ');
                $stmt->execute([$domain, $reason, $userEmail, '%@' . $domain]);
            }
            
            // Log the action
            $this->logAction($userEmail, 'blocked', $blockedEmail);
            
            return true;
        } catch (\PDOException $e) {
            error_log("SpamService::blockSender error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Unblock a sender
     */
    public function unblockSender(string $userEmail, int $id): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        
        try {
            // Get the blocked email before deleting for logging
            $stmt = $this->db->prepare('SELECT blocked_email FROM webmail_blocked_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, $userEmail]);
            $row = $stmt->fetch();
            
            if (!$row) return false;
            
            $stmt = $this->db->prepare('DELETE FROM webmail_blocked_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, $userEmail]);
            
            $this->logAction($userEmail, 'unblocked', $row['blocked_email']);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("SpamService::unblockSender error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a sender is blocked
     */
    public function isSenderBlocked(string $userEmail, string $senderEmail): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        $senderEmail = strtolower($senderEmail);
        $domain = $this->extractDomain($senderEmail);
        
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM webmail_blocked_senders 
            WHERE user_email = ? AND (blocked_email = ? OR blocked_domain = ?)
        ');
        $stmt->execute([$userEmail, $senderEmail, $domain]);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    // ==================== SAFE SENDERS ====================
    
    /**
     * Get all safe senders for a user
     */
    public function getSafeSenders(string $userEmail): array
    {
        if (!$this->isDbAvailable()) {
            return [];
        }
        
        $stmt = $this->db->prepare('
            SELECT id, safe_email, safe_domain, created_at 
            FROM webmail_safe_senders 
            WHERE user_email = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add a safe sender
     */
    public function addSafeSender(string $userEmail, string $safeEmail, bool $trustDomain = false): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        $safeEmail = strtolower($safeEmail);
        $domain = $trustDomain ? $this->extractDomain($safeEmail) : null;
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_safe_senders (user_email, safe_email, safe_domain) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE safe_domain = VALUES(safe_domain)
            ');
            $stmt->execute([$userEmail, $safeEmail, $domain]);
            
            $this->logAction($userEmail, 'safe_added', $safeEmail);
            
            return true;
        } catch (\PDOException $e) {
            error_log("SpamService::addSafeSender error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove a safe sender
     */
    public function removeSafeSender(string $userEmail, int $id): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        
        try {
            // Get the safe email before deleting for logging
            $stmt = $this->db->prepare('SELECT safe_email FROM webmail_safe_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, $userEmail]);
            $row = $stmt->fetch();
            
            if (!$row) return false;
            
            $stmt = $this->db->prepare('DELETE FROM webmail_safe_senders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, $userEmail]);
            
            $this->logAction($userEmail, 'safe_removed', $row['safe_email']);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("SpamService::removeSafeSender error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a sender is in the safe list
     */
    public function isSenderSafe(string $userEmail, string $senderEmail): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        $senderEmail = strtolower($senderEmail);
        $domain = $this->extractDomain($senderEmail);
        
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM webmail_safe_senders 
            WHERE user_email = ? AND (safe_email = ? OR safe_domain = ?)
        ');
        $stmt->execute([$userEmail, $senderEmail, $domain]);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    // ==================== SPAM TRAINING ====================
    
    /**
     * Train SpamAssassin that this email is spam
     */
    public function trainAsSpam(string $userEmail, string $rawEmail): bool
    {
        return $this->trainBayes($userEmail, $rawEmail, 'spam');
    }
    
    /**
     * Train the engine that this email is NOT spam (ham).
     */
    public function trainAsHam(string $userEmail, string $rawEmail): bool
    {
        return $this->trainBayes($userEmail, $rawEmail, 'ham');
    }
    
    /**
     * Feed a message to Rspamd's Bayes classifier (learn_spam / learn_ham) via
     * the local controller. Rspamd is the standardized engine, so this replaces
     * the legacy SpamAssassin sa-learn path (which trained a Bayes DB nothing
     * consulted once the milter became Rspamd). Best-effort: failures are logged
     * but never interrupt the user's report-spam / not-spam action.
     */
    private function trainBayes(string $userEmail, string $rawEmail, string $type): bool
    {
        if (!$this->isTrainingEnabled($userEmail)) {
            error_log("SpamService: Training disabled for $userEmail");
            return false;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'bayes_train_');
        if (!$tempFile) {
            error_log("SpamService: Failed to create temp file");
            return false;
        }

        try {
            file_put_contents($tempFile, $rawEmail);

            $rspamc = $this->config['rspamd']['rspamc_path'] ?? '/usr/bin/rspamc';
            // Controller is bound to localhost and trusts 127.0.0.1 (secure_ip),
            // so privileged learn commands need no password from this host.
            $controller = $this->config['rspamd']['controller'] ?? '127.0.0.1:11334';
            $password = (string)($this->config['rspamd']['password'] ?? '');
            $command = $type === 'spam' ? 'learn_spam' : 'learn_ham';

            $parts = [escapeshellcmd($rspamc), '-h', escapeshellarg($controller), '-t', '10'];
            if ($password !== '') {
                $parts[] = '-P';
                $parts[] = escapeshellarg($password);
            }
            $parts[] = $command;
            $parts[] = '< ' . escapeshellarg($tempFile) . ' 2>&1';
            $cmd = implode(' ', $parts);

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            $outputStr = implode("\n", $output);
            error_log("SpamService: rspamc $command output: $outputStr, return code: $returnCode");

            // rspamc exits 0 on success; "already learned as <class>" also exits 0.
            // Treat an explicit already-learned message as success too.
            if ($returnCode === 0) {
                return true;
            }
            return stripos($outputStr, 'already learned') !== false;
        } catch (\Exception $e) {
            error_log("SpamService::trainBayes error: " . $e->getMessage());
            return false;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
    
    /**
     * Check if auto-training is enabled for user
     */
    private function isTrainingEnabled(string $userEmail): bool
    {
        // Check global config first
        if (!($this->config['spamassassin']['training_enabled'] ?? true)) {
            return false;
        }
        
        // Check user setting
        $stmt = $this->db->prepare('SELECT auto_training_enabled FROM webmail_spam_settings WHERE user_email = ?');
        $stmt->execute([strtolower($userEmail)]);
        $row = $stmt->fetch();
        
        return $row ? (bool)$row['auto_training_enabled'] : true;
    }
    
    // ==================== SPAM SETTINGS ====================
    
    /**
     * Get spam settings for a user
     */
    public function getSettings(string $userEmail): array
    {
        if (!$this->isDbAvailable()) {
            return [
                'auto_delete_days' => 30,
                'auto_training_enabled' => true,
            ];
        }
        
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare('SELECT * FROM webmail_spam_settings WHERE user_email = ?');
        $stmt->execute([$userEmail]);
        $settings = $stmt->fetch();
        
        if (!$settings) {
            // Return defaults
            return [
                'auto_delete_days' => 30,
                'auto_training_enabled' => true,
            ];
        }
        
        return [
            'auto_delete_days' => (int)$settings['auto_delete_days'],
            'auto_training_enabled' => (bool)$settings['auto_training_enabled'],
        ];
    }
    
    /**
     * Update spam settings for a user
     */
    public function updateSettings(string $userEmail, array $settings): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }
        
        $userEmail = strtolower($userEmail);
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_spam_settings (user_email, auto_delete_days, auto_training_enabled) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    auto_delete_days = VALUES(auto_delete_days),
                    auto_training_enabled = VALUES(auto_training_enabled)
            ');
            
            $stmt->execute([
                $userEmail,
                $settings['auto_delete_days'] ?? 30,
                ($settings['auto_training_enabled'] ?? true) ? 1 : 0,
            ]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("SpamService::updateSettings error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the stored spam folder name for a user (used by Sieve generation).
     */
    public function getSpamFolder(string $userEmail): string
    {
        if (!$this->isDbAvailable()) {
            return 'INBOX.Spam';
        }

        $stmt = $this->db->prepare('SELECT spam_folder FROM webmail_spam_settings WHERE user_email = ?');
        $stmt->execute([strtolower($userEmail)]);
        $row = $stmt->fetch();

        return ($row && !empty($row['spam_folder'])) ? $row['spam_folder'] : 'INBOX.Spam';
    }

    /**
     * Store the discovered spam folder name for a user.
     */
    public function setSpamFolder(string $userEmail, string $folderName): bool
    {
        if (!$this->isDbAvailable()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_spam_settings (user_email, spam_folder)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE spam_folder = VALUES(spam_folder)
            ');
            $stmt->execute([strtolower($userEmail), $folderName]);
            return true;
        } catch (\PDOException $e) {
            error_log("[SpamService::setSpamFolder] Error: " . $e->getMessage());
            return false;
        }
    }

    // ==================== STATISTICS ====================
    
    /**
     * Get spam statistics for a user
     */
    public function getStats(string $userEmail, int $days = 30): array
    {
        $stats = [
            'reported_spam' => 0,
            'not_spam' => 0,
            'blocked' => 0,
            'unblocked' => 0,
            'safe_added' => 0,
            'safe_removed' => 0,
            'auto_deleted' => 0,
            'period_days' => $days,
            'total_blocked' => 0,
        ];
        
        if (!$this->isDbAvailable()) {
            return $stats;
        }
        
        $userEmail = strtolower($userEmail);
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stmt = $this->db->prepare('
            SELECT action, COUNT(*) as count 
            FROM webmail_spam_stats 
            WHERE user_email = ? AND created_at >= ?
            GROUP BY action
        ');
        $stmt->execute([$userEmail, $since]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $stats[$row['action']] = (int)$row['count'];
        }
        
        // Calculate total blocked (blocked senders + reported spam)
        $stats['total_blocked'] = $stats['reported_spam'] + $stats['blocked'];
        
        return $stats;
    }
    
    /**
     * Log a spam-related action
     */
    private function logAction(string $userEmail, string $action, ?string $targetEmail, ?string $messageId = null): void
    {
        if (!$this->isDbAvailable()) {
            return;
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_spam_stats (user_email, action, target_email, message_id) 
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([strtolower($userEmail), $action, $targetEmail, $messageId]);
        } catch (\PDOException $e) {
            error_log("SpamService::logAction error: " . $e->getMessage());
        }
    }
    
    // ==================== PUBLIC LOGGING ====================
    
    /**
     * Log a spam report action (regardless of training outcome)
     */
    public function logReportedSpam(string $userEmail, ?string $senderEmail = null, ?string $messageId = null): void
    {
        $this->logAction($userEmail, 'reported_spam', $senderEmail, $messageId);
    }
    
    /**
     * Log a not-spam action (regardless of training outcome)
     */
    public function logNotSpam(string $userEmail, ?string $senderEmail = null, ?string $messageId = null): void
    {
        $this->logAction($userEmail, 'not_spam', $senderEmail, $messageId);
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Extract domain from email address
     */
    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
    
    /**
     * Parse X-Spam headers from raw email
     */
    public function parseSpamHeaders(string $rawHeaders): array
    {
        $result = [
            'is_spam' => false,
            'score' => null,
            'threshold' => null,
            'tests' => [],
            'status' => null,
        ];
        
        // Parse X-Spam-Flag
        if (preg_match('/^X-Spam-Flag:\s*(YES|NO)/mi', $rawHeaders, $m)) {
            $result['is_spam'] = strtoupper($m[1]) === 'YES';
        }
        
        // Parse X-Spam-Status: Yes/No, score=5.2 required=5.0 tests=...
        if (preg_match('/^X-Spam-Status:\s*(.+?)(?:\r?\n(?!\s)|$)/mi', $rawHeaders, $m)) {
            $statusLine = $m[1];
            
            // Handle multi-line headers
            if (preg_match('/^X-Spam-Status:\s*(.+?)(?=\r?\n[^\s]|\r?\n$|$)/mis', $rawHeaders, $multiMatch)) {
                $statusLine = preg_replace('/\r?\n\s+/', ' ', trim($multiMatch[1])) ?? $statusLine;
            }
            
            $result['status'] = $statusLine;
            
            // Check if spam
            if (preg_match('/^(Yes|No)/i', $statusLine, $yesNo)) {
                $result['is_spam'] = strtolower($yesNo[1]) === 'yes';
            }
            
            // Extract score
            if (preg_match('/score=([0-9.-]+)/i', $statusLine, $scoreMatch)) {
                $result['score'] = (float)$scoreMatch[1];
            }
            
            // Extract threshold
            if (preg_match('/required=([0-9.-]+)/i', $statusLine, $threshMatch)) {
                $result['threshold'] = (float)$threshMatch[1];
            }
            
            // Extract tests
            if (preg_match('/tests=([^\s]+)/i', $statusLine, $testsMatch)) {
                $result['tests'] = array_filter(explode(',', $testsMatch[1]));
            }
        }
        
        // Parse X-Spam-Score if present (some setups use this)
        if ($result['score'] === null && preg_match('/^X-Spam-Score:\s*([0-9.-]+)/mi', $rawHeaders, $m)) {
            $result['score'] = (float)$m[1];
        }
        
        return $result;
    }
}

