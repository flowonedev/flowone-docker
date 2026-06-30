<?php

namespace Webmail\Addons\EmailTracking\Services;

/**
 * TrackingService - Email read receipt tracking
 * 
 * Tracks when sent emails are opened using a tracking pixel
 * Stores read events and provides notifications
 */
class TrackingService
{
    private \PDO $db;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Tracked emails table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL COMMENT 'Sender email',
                    tracking_id VARCHAR(64) NOT NULL UNIQUE,
                    campaign_id VARCHAR(36) DEFAULT NULL,
                    subject VARCHAR(500),
                    message_id VARCHAR(255) DEFAULT NULL COMMENT 'Message-ID of the sent email (for reopening from notifications)',
                    recipients TEXT COMMENT 'JSON array of recipients',
                    sender_ip VARCHAR(45) DEFAULT NULL COMMENT 'IP address when email was sent',
                    sender_ua_hash VARCHAR(64) DEFAULT NULL COMMENT 'Hash of user-agent when email was sent',
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    INDEX idx_tracking_id (tracking_id),
                    INDEX idx_campaign_id (campaign_id),
                    INDEX idx_message_id (message_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add sender_ip column if not exists
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD COLUMN sender_ip VARCHAR(45) DEFAULT NULL COMMENT 'IP address when email was sent' AFTER recipients");
            } catch (\PDOException $e) { /* Column may already exist */ }
            
            // Add sender_ua_hash column if not exists (for user-agent fingerprinting)
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD COLUMN sender_ua_hash VARCHAR(64) DEFAULT NULL COMMENT 'Hash of user-agent when email was sent' AFTER sender_ip");
            } catch (\PDOException $e) { /* Column may already exist */ }
            
            // Add message_id column if not exists (Message-ID of the sent email,
            // used to resolve the exact IMAP message when opening from a notification)
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD COLUMN message_id VARCHAR(255) DEFAULT NULL AFTER subject");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD INDEX idx_message_id (message_id)");
            } catch (\PDOException $e) { /* Index may already exist */ }
            
            // Add campaign_id column if not exists (links tracking to email campaigns)
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD COLUMN campaign_id VARCHAR(36) DEFAULT NULL AFTER tracking_id");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE email_tracking ADD INDEX idx_campaign_id (campaign_id)");
            } catch (\PDOException $e) { /* Index may already exist */ }
            
            // Read events table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_read_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tracking_id VARCHAR(64) NOT NULL,
                    recipient_email VARCHAR(255) DEFAULT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tracking_id (tracking_id),
                    INDEX idx_rate_limit (tracking_id, recipient_email, ip_address, read_at),
                    FOREIGN KEY (tracking_id) REFERENCES email_tracking(tracking_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add rate limiting index if not exists
            try {
                $this->db->exec("ALTER TABLE email_read_events ADD INDEX idx_rate_limit (tracking_id, recipient_email, ip_address, read_at)");
            } catch (\PDOException $e) { /* Index may already exist */ }
            
            // Notifications table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    type VARCHAR(50) NOT NULL DEFAULT 'system',
                    title VARCHAR(255) NOT NULL,
                    message TEXT,
                    data TEXT COMMENT 'JSON additional data',
                    is_read TINYINT(1) DEFAULT 0,
                    pinned TINYINT(1) DEFAULT 0,
                    tracking_id VARCHAR(64) DEFAULT NULL COMMENT 'Links to email_tracking for grouping',
                    campaign_id VARCHAR(36) DEFAULT NULL,
                    read_events TEXT COMMENT 'JSON array of all read events for this email',
                    last_read_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    INDEX idx_is_read (is_read),
                    INDEX idx_pinned (pinned),
                    INDEX idx_tracking_id (tracking_id),
                    INDEX idx_campaign_id (campaign_id),
                    INDEX idx_created_at (created_at),
                    INDEX idx_type (type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            try {
                $this->db->exec("ALTER TABLE notifications ADD COLUMN campaign_id VARCHAR(36) DEFAULT NULL AFTER tracking_id");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD INDEX idx_campaign_id (campaign_id)");
            } catch (\PDOException $e) { /* Index may already exist */ }

            // Migrate type column from ENUM to VARCHAR if needed (allows any notification type)
            try {
                $this->db->exec("ALTER TABLE notifications MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'system'");
            } catch (\PDOException $e) { /* Already migrated or other issue */ }
            
            // Add columns to existing table if they don't exist
            try {
                $this->db->exec("ALTER TABLE notifications ADD COLUMN pinned TINYINT(1) DEFAULT 0 AFTER is_read");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD COLUMN tracking_id VARCHAR(64) DEFAULT NULL AFTER pinned");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD COLUMN read_events TEXT COMMENT 'JSON array of all read events' AFTER tracking_id");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD COLUMN last_read_at TIMESTAMP NULL DEFAULT NULL AFTER read_events");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD INDEX idx_pinned (pinned)");
            } catch (\PDOException $e) { /* Index may already exist */ }
            try {
                $this->db->exec("ALTER TABLE notifications ADD INDEX idx_tracking_id (tracking_id)");
            } catch (\PDOException $e) { /* Index may already exist */ }
            
            // Per-recipient tracking tokens
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_tracking_recipients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tracking_id VARCHAR(64) NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    recipient_token VARCHAR(64) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tracking_id (tracking_id),
                    INDEX idx_token (recipient_token),
                    FOREIGN KEY (tracking_id) REFERENCES email_tracking(tracking_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Link tracking table (stores each unique link per tracked email)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_link_tracking (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tracking_id VARCHAR(64) NOT NULL,
                    link_token VARCHAR(64) NOT NULL UNIQUE,
                    original_url TEXT NOT NULL,
                    link_index INT NOT NULL DEFAULT 0,
                    block_id VARCHAR(36) DEFAULT NULL,
                    block_type VARCHAR(50) DEFAULT NULL,
                    block_name VARCHAR(100) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_tracking_id (tracking_id),
                    INDEX idx_link_token (link_token),
                    INDEX idx_block_id (block_id),
                    FOREIGN KEY (tracking_id) REFERENCES email_tracking(tracking_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Add block tracking columns to email_link_tracking if not exists
            try {
                $this->db->exec("ALTER TABLE email_link_tracking ADD COLUMN block_id VARCHAR(36) DEFAULT NULL AFTER link_index");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE email_link_tracking ADD COLUMN block_type VARCHAR(50) DEFAULT NULL AFTER block_id");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE email_link_tracking ADD COLUMN block_name VARCHAR(100) DEFAULT NULL AFTER block_type");
            } catch (\PDOException $e) { /* Column may already exist */ }
            try {
                $this->db->exec("ALTER TABLE email_link_tracking ADD INDEX idx_block_id (block_id)");
            } catch (\PDOException $e) { /* Index may already exist */ }

            // Click events table (each time a link is clicked)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_click_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    link_token VARCHAR(64) NOT NULL,
                    recipient_token VARCHAR(64) DEFAULT NULL,
                    recipient_email VARCHAR(255) DEFAULT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_link_token (link_token),
                    INDEX idx_recipient (recipient_token),
                    INDEX idx_rate_limit (link_token, recipient_email, ip_address, clicked_at),
                    FOREIGN KEY (link_token) REFERENCES email_link_tracking(link_token) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("TrackingService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate a tracking ID for a new email
     */
    public function generateTrackingId(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Create a tracking record for a sent email
     * Returns array of recipient tokens for per-recipient tracking
     */
    public function createTracking(string $userEmail, string $trackingId, string $subject, array $recipients, ?string $campaignId = null, ?string $messageId = null): array|false
    {
        try {
            // Capture sender's IP and user-agent hash to filter out their own opens later
            $senderIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $senderUa = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $senderUaHash = $senderUa ? hash('sha256', $senderUa) : null;
            
            // Normalize the Message-ID: store it WITHOUT angle brackets so the
            // locate resolver can re-wrap consistently per IMAP server quirks.
            $normalizedMessageId = $messageId !== null ? trim($messageId, '<> ') : null;
            $normalizedMessageId = ($normalizedMessageId === '') ? null : $normalizedMessageId;
            
            $stmt = $this->db->prepare('
                INSERT INTO email_tracking (user_email, tracking_id, campaign_id, subject, message_id, recipients, sender_ip, sender_ua_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                strtolower($userEmail),
                $trackingId,
                $campaignId,
                $subject,
                $normalizedMessageId,
                json_encode($recipients),
                $senderIp,
                $senderUaHash,
            ]);
            
            // Create per-recipient tokens
            $recipientTokens = [];
            $tokenStmt = $this->db->prepare('
                INSERT INTO email_tracking_recipients (tracking_id, recipient_email, recipient_token)
                VALUES (?, ?, ?)
            ');
            
            foreach ($recipients as $recipient) {
                $email = is_array($recipient) ? ($recipient['email'] ?? $recipient['address'] ?? '') : $recipient;
                if ($email) {
                    $token = bin2hex(random_bytes(16));
                    $emailLower = strtolower($email);
                    $tokenStmt->execute([$trackingId, $emailLower, $token]);
                    $recipientTokens[$emailLower] = $token;
                }
            }
            
            return $recipientTokens;
        } catch (\PDOException $e) {
            error_log("TrackingService createTracking error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Resolve a tracking record to the actual IMAP message (folder + uid) so a
     * read-receipt / link-click notification can open the email regardless of
     * how long ago it was sent or which folder it now lives in.
     *
     * Search order (common case first, legacy recovery last):
     *   1. Sent folder(s)  + Message-ID
     *   2. Sent folder(s)  + subject
     *   3. Other folder(s) + Message-ID
     *   4. Other folder(s) + subject
     *
     * @return array{folder: string, uid: int}|null
     */
    public function locateEmail(string $userEmail, string $trackingId, \Webmail\Services\ImapService $imap): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT subject, message_id FROM email_tracking WHERE user_email = ? AND tracking_id = ? LIMIT 1');
            $stmt->execute([strtolower($userEmail), $trackingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $subject = trim((string)($row['subject'] ?? ''));
            $messageId = $row['message_id'] ?? null;
            $messageId = $messageId !== null ? trim($messageId, '<> ') : null;
            if ($messageId === '') {
                $messageId = null;
            }

            // Split selectable folders into Sent vs everything else.
            $sentFolders = [];
            $otherFolders = [];
            foreach ($imap->listFolders() as $f) {
                if (empty($f['is_selectable'])) {
                    continue;
                }
                $name = (string)($f['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $isSent = (($f['special_use'] ?? null) === '\\Sent')
                    || (($f['type'] ?? '') === 'sent')
                    || (stripos($name, 'sent') !== false);
                if ($isSent) {
                    $sentFolders[] = $name;
                } else {
                    $otherFolders[] = $name;
                }
            }

            if ($messageId !== null) {
                $hit = $this->locateByMessageId($imap, $sentFolders, $messageId);
                if ($hit) return $hit;
            }
            $hit = $this->locateBySubject($imap, $sentFolders, $subject);
            if ($hit) return $hit;

            if ($messageId !== null) {
                $hit = $this->locateByMessageId($imap, $otherFolders, $messageId);
                if ($hit) return $hit;
            }
            $hit = $this->locateBySubject($imap, $otherFolders, $subject);
            if ($hit) return $hit;

            return null;
        } catch (\Throwable $e) {
            error_log("TrackingService locateEmail error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a message by Message-ID across the given folders.
     * @return array{folder: string, uid: int}|null
     */
    private function locateByMessageId(\Webmail\Services\ImapService $imap, array $folders, string $messageId): ?array
    {
        foreach ($folders as $folder) {
            // searchHeader strips angle brackets itself, but normalize anyway.
            $results = $imap->searchHeader($folder, 'Message-ID', trim($messageId, '<> '));
            foreach ($results as $msg) {
                if (!empty($msg['uid'])) {
                    return ['folder' => $folder, 'uid' => (int)$msg['uid']];
                }
            }
        }
        return null;
    }

    /**
     * Find a message by subject across the given folders. Prefers an exact
     * (case-insensitive) subject match; otherwise falls back to the newest
     * result the IMAP SUBJECT search returned.
     * @return array{folder: string, uid: int}|null
     */
    private function locateBySubject(\Webmail\Services\ImapService $imap, array $folders, string $subject): ?array
    {
        $subject = trim($subject);
        if ($subject === '') {
            return null;
        }
        // Quote so parseSearchQuery emits a single SUBJECT criterion.
        $query = 'subject:"' . str_replace('"', '', $subject) . '"';
        foreach ($folders as $folder) {
            $results = $imap->search($folder, $query);
            if (empty($results)) {
                continue;
            }
            foreach ($results as $msg) {
                $msgSubject = trim((string)($msg['subject'] ?? ''));
                if (!empty($msg['uid']) && strcasecmp($msgSubject, $subject) === 0) {
                    return ['folder' => $folder, 'uid' => (int)$msg['uid']];
                }
            }
            // No exact match in this folder; take the newest narrowed result
            // (search() returns newest-first).
            if (!empty($results[0]['uid'])) {
                return ['folder' => $folder, 'uid' => (int)$results[0]['uid']];
            }
        }
        return null;
    }
    
    /**
     * Record a read event (called when tracking pixel is loaded)
     * $idOrToken can be either:
     * - A tracking_id (64 char) - old style, won't identify recipient
     * - A recipient_token (32 char) - new style, identifies who opened
     * 
     * IMPORTANT: Filters out sender's own opens to ensure accurate tracking
     */
    public function recordReadEvent(string $idOrToken, ?string $recipientEmail = null): bool
    {
        try {
            $trackingId = $idOrToken;
            
            // Check if this is a recipient-specific token (32 chars vs 64 for tracking_id)
            if (strlen($idOrToken) === 32) {
                // Look up the recipient token
                $tokenStmt = $this->db->prepare('
                    SELECT tracking_id, recipient_email 
                    FROM email_tracking_recipients 
                    WHERE recipient_token = ?
                ');
                $tokenStmt->execute([$idOrToken]);
                $tokenData = $tokenStmt->fetch();
                
                if ($tokenData) {
                    $trackingId = $tokenData['tracking_id'];
                    $recipientEmail = $tokenData['recipient_email'];
                }
            }
            
            // Get the tracking record (seconds_since_sent lets us reject the
            // mail-provider prefetch that fires moments after the email is sent)
            $stmt = $this->db->prepare('SELECT *, TIMESTAMPDIFF(SECOND, sent_at, NOW()) AS seconds_since_sent FROM email_tracking WHERE tracking_id = ?');
            $stmt->execute([$trackingId]);
            $tracking = $stmt->fetch();
            
            if (!$tracking) {
                return false;
            }
            
            // Get IP and user agent
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $userAgentHash = $userAgent ? hash('sha256', $userAgent) : null;
            $referer = $_SERVER['HTTP_REFERER'] ?? null;
            
            // ============================================
            // SENDER FILTERING - Don't count sender's opens
            // ============================================
            
            // Check 1: If the request comes from our own webmail domain (sender viewing sent email)
            if ($referer) {
                $refererHost = parse_url($referer, PHP_URL_HOST);
                $refererPath = parse_url($referer, PHP_URL_PATH) ?? '';
                $currentHost = $_SERVER['HTTP_HOST'] ?? null;
                
                // Check if referrer is from our domain (more lenient matching)
                $isSameDomain = false;
                if ($refererHost && $currentHost) {
                    // Exact match or subdomain match
                    $isSameDomain = (
                        $refererHost === $currentHost ||
                        str_ends_with($refererHost, '.' . $currentHost) ||
                        str_ends_with($currentHost, '.' . $refererHost) ||
                        // Also check if they share the same base domain
                        $this->getBaseDomain($refererHost) === $this->getBaseDomain($currentHost)
                    );
                }
                
                if ($isSameDomain) {
                    // Skip - this is the sender viewing their own email from the webmail
                    error_log("TrackingService: Skipping self-read (same domain referer: $refererHost, path: $refererPath)");
                    return true;
                }
            }
            
            // Check 2: If the IP matches the sender's IP when they sent the email
            $senderIp = $tracking['sender_ip'] ?? null;
            if ($senderIp && $ipAddress && $senderIp === $ipAddress) {
                // Skip - this is likely the sender viewing their own email from the same IP
                error_log("TrackingService: Skipping self-read (sender IP match: $ipAddress)");
                return true;
            }
            
            // Check 3: If the user-agent hash matches the sender's user-agent when they sent the email
            // This catches cases where IP changed but same browser/device is used
            $senderUaHash = $tracking['sender_ua_hash'] ?? null;
            if ($senderUaHash && $userAgentHash && $senderUaHash === $userAgentHash) {
                // Additional check: only skip if IP is in same subnet or referer is present
                // This prevents false positives from users with identical user agents
                $ipSameSubnet = $senderIp && $ipAddress && $this->isSameSubnet($senderIp, $ipAddress);
                if ($ipSameSubnet || $referer) {
                    error_log("TrackingService: Skipping self-read (sender UA hash match + same subnet/referer)");
                    return true;
                }
            }
            
            // Check 4: Check if the recipient email matches the sender email (shouldn't happen, but just in case)
            $senderEmail = $tracking['user_email'] ?? null;
            if ($senderEmail && $recipientEmail && strtolower($senderEmail) === strtolower($recipientEmail)) {
                // Skip - sender's email shouldn't be in recipients
                error_log("TrackingService: Skipping self-read (sender email in recipients)");
                return true;
            }

            // ============================================
            // Check 5: AUTOMATED PREFETCH / PROXY SCAN
            // ============================================
            // Gmail (GoogleImageProxy), Apple Mail Privacy Protection and Outlook
            // fetch remote images automatically, often within seconds of delivery
            // and BEFORE a human ever opens the message. Counting those produces
            // false "instant reads" (and, now that read receipts push to mobile, a
            // phantom notification). Suppress any open that lands inside the
            // prefetch window after the email was sent. Genuine later opens (also
            // proxied) arrive outside the window and still count.
            $prefetchWindow = (int)($this->config['email_tracking']['prefetch_window_seconds'] ?? 15);
            $ageSeconds = isset($tracking['seconds_since_sent']) && $tracking['seconds_since_sent'] !== null
                ? (int)$tracking['seconds_since_sent']
                : null;
            if ($prefetchWindow > 0 && $ageSeconds !== null && $ageSeconds <= $prefetchWindow) {
                error_log("TrackingService: Skipping automated prefetch read (age={$ageSeconds}s <= {$prefetchWindow}s, ua=" . ($userAgent ?? 'n/a') . ")");
                return true;
            }

            // ============================================
            // RATE LIMITING - Don't count frequent reloads
            // ============================================
            
            // Deduplicate rapid-fire pixel reloads (same recipient+IP within 30 seconds)
            $recentStmt = $this->db->prepare('
                SELECT id FROM email_read_events 
                WHERE tracking_id = ? 
                  AND (recipient_email = ? OR (recipient_email IS NULL AND ? IS NULL))
                  AND (ip_address = ? OR (ip_address IS NULL AND ? IS NULL))
                  AND read_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
                LIMIT 1
            ');
            $recentStmt->execute([$trackingId, $recipientEmail, $recipientEmail, $ipAddress, $ipAddress]);
            if ($recentStmt->fetch()) {
                return true;
            }
            
            // Check if this is a first-ever read from this recipient
            $existingStmt = $this->db->prepare('
                SELECT id FROM email_read_events 
                WHERE tracking_id = ? AND (recipient_email = ? OR (recipient_email IS NULL AND ? IS NULL))
                LIMIT 1
            ');
            $existingStmt->execute([$trackingId, $recipientEmail, $recipientEmail]);
            $isFirstRead = !$existingStmt->fetch();
            
            // Record the read event
            $stmt = $this->db->prepare('
                INSERT INTO email_read_events (tracking_id, recipient_email, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $trackingId,
                $recipientEmail,
                $ipAddress,
                $userAgent,
            ]);
            
            // Only update/create notification when we actually recorded a new read event
            // (not for rate-limited duplicates)
            $this->updateOrCreateReadNotification($tracking, $recipientEmail);

            // Fire CRM automation triggers for email opens
            try {
                $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($this->config);
                $automationService->onEmailOpened(
                    $trackingId,
                    $recipientEmail ?? '',
                    $tracking['user_email'],
                    $tracking['campaign_id'] ?? null,
                    $tracking['subject'] ?? ''
                );
            } catch (\Throwable $e) {
                error_log("Automation trigger error on email open: " . $e->getMessage());
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("TrackingService recordReadEvent error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update existing notification or create new one for a read event
     * Groups all read events for the same email under one notification
     */
    private function updateOrCreateReadNotification(array $tracking, ?string $recipientEmail): void
    {
        $trackingId = $tracking['tracking_id'];
        $userEmail = $tracking['user_email'];
        $recipients = json_decode($tracking['recipients'], true) ?: [];
        
        // Get reader display name
        $readerDisplay = $recipientEmail ?: 'Someone';
        if ($recipientEmail) {
            foreach ($recipients as $r) {
                $email = is_array($r) ? ($r['email'] ?? $r['address'] ?? '') : $r;
                if (strtolower($email) === strtolower($recipientEmail)) {
                    $name = is_array($r) ? ($r['name'] ?? '') : '';
                    $readerDisplay = $name ?: $recipientEmail;
                    break;
                }
            }
        }
        
        $subject = $tracking['subject'] ?: 'your email';
        $shortSubject = strlen($subject) > 40 ? substr($subject, 0, 40) . '...' : $subject;
        $now = date('Y-m-d H:i:s');
        
        // Build all recipients list for context
        $allRecipients = array_map(function($r) {
            if (is_array($r)) {
                $email = $r['email'] ?? $r['address'] ?? '';
                $name = $r['name'] ?? '';
                return $name ? "$name <$email>" : $email;
            }
            return $r;
        }, $recipients);
        
        // New read event to add
        $newEvent = [
            'reader' => $readerDisplay,
            'reader_email' => $recipientEmail,
            'read_at' => $now,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        
        // Check if notification already exists for this tracking_id
        $stmt = $this->db->prepare('
            SELECT id, read_events, data FROM notifications 
            WHERE user_email = ? AND tracking_id = ? AND type = "read_receipt"
            LIMIT 1
        ');
        $stmt->execute([strtolower($userEmail), $trackingId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing notification - add new read event
            $readEvents = json_decode($existing['read_events'], true) ?: [];
            $readEvents[] = $newEvent;
            
            $data = json_decode($existing['data'], true) ?: [];
            $data['last_reader'] = $readerDisplay;
            $data['last_reader_email'] = $recipientEmail;
            $data['total_reads'] = count($readEvents);
            
            // Get unique readers count
            $uniqueReaders = [];
            foreach ($readEvents as $event) {
                $key = $event['reader_email'] ?? $event['reader'];
                if ($key) $uniqueReaders[$key] = true;
            }
            $data['unique_readers'] = count($uniqueReaders);
            
            $stmt = $this->db->prepare('
                UPDATE notifications 
                SET read_events = ?, data = ?, last_read_at = ?, is_read = 0,
                    title = ?, message = ?
                WHERE id = ?
            ');
            
            $title = "$readerDisplay read your email";
            $message = "\"$shortSubject\" - " . count($readEvents) . " opens";
            
            $stmt->execute([
                json_encode($readEvents),
                json_encode($data),
                $now,
                $title,
                $message,
                $existing['id']
            ]);
            $this->publishNotificationEvent(
                strtolower($userEmail),
                (int)$existing['id'],
                'read_receipt',
                $title,
                $message,
                $data,
                true
            );
        } else {
            // Create new notification
            $readEvents = [$newEvent];
            $data = [
                'tracking_id' => $trackingId,
                'recipient' => $recipientEmail,
                'recipient_display' => $readerDisplay,
                'subject' => $tracking['subject'],
                'all_recipients' => $allRecipients,
                'last_reader' => $readerDisplay,
                'last_reader_email' => $recipientEmail,
                'total_reads' => 1,
                'unique_readers' => 1,
            ];
            
            $campaignId = $tracking['campaign_id'] ?? null;
            $stmt = $this->db->prepare('
                INSERT INTO notifications (user_email, type, title, message, data, tracking_id, campaign_id, read_events, last_read_at)
                VALUES (?, "read_receipt", ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                strtolower($userEmail),
                "$readerDisplay read your email",
                "\"$shortSubject\" was opened",
                json_encode($data),
                $trackingId,
                $campaignId,
                json_encode($readEvents),
                $now,
            ]);
            $insertId = (int)$this->db->lastInsertId();
            if ($insertId > 0) {
                $this->publishNotificationEvent(
                    strtolower($userEmail),
                    $insertId,
                    'read_receipt',
                    "$readerDisplay read your email",
                    "\"$shortSubject\" was opened",
                    $data,
                    false
                );
            }
        }
    }
    
    /**
     * Get tracking info for an email
     */
    public function getTracking(string $userEmail, string $trackingId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT t.*, 
                   (SELECT COUNT(*) FROM email_read_events WHERE tracking_id = t.tracking_id) as read_count,
                   (SELECT MIN(read_at) FROM email_read_events WHERE tracking_id = t.tracking_id) as first_read_at
            FROM email_tracking t
            WHERE t.user_email = ? AND t.tracking_id = ?
        ');
        $stmt->execute([strtolower($userEmail), $trackingId]);
        $tracking = $stmt->fetch();
        
        if ($tracking) {
            $tracking['recipients'] = json_decode($tracking['recipients'], true) ?: [];
            
            // Get read events
            $eventsStmt = $this->db->prepare('
                SELECT * FROM email_read_events 
                WHERE tracking_id = ? 
                ORDER BY read_at DESC
            ');
            $eventsStmt->execute([$trackingId]);
            $tracking['read_events'] = $eventsStmt->fetchAll();
            
            // Get click stats
            $tracking['click_stats'] = $this->getClickStats($trackingId);
        }
        
        return $tracking ?: null;
    }
    
    /**
     * Get all tracked emails for a user
     */
    public function getTrackedEmails(string $userEmail, int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT t.*, 
                   (SELECT COUNT(*) FROM email_read_events WHERE tracking_id = t.tracking_id) as read_count,
                   (SELECT MAX(read_at) FROM email_read_events WHERE tracking_id = t.tracking_id) as last_read_at
            FROM email_tracking t
            WHERE t.user_email = ?
            ORDER BY t.sent_at DESC
            LIMIT ' . (int)$limit . '
        ');
        $stmt->execute([strtolower($userEmail)]);
        
        return array_map(function($t) {
            $t['recipients'] = json_decode($t['recipients'], true) ?: [];
            return $t;
        }, $stmt->fetchAll());
    }
    
    /**
     * Generate tracking pixel HTML for a SINGLE recipient
     * This is the correct way - each recipient gets their own unique pixel
     */
    public function getSingleRecipientPixel(string $recipientToken, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $pixelUrl = $baseUrl . '/api/track/' . $recipientToken . '.gif';
        return '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" style="display:none;" alt="" />';
    }
    
    /**
     * Generate tracking pixel HTML using generic tracking ID (fallback)
     * Use this only when per-recipient tracking is not possible
     */
    public function getGenericTrackingPixel(string $trackingId, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $pixelUrl = $baseUrl . '/api/track/' . $trackingId . '.gif';
        return '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" style="display:none;" alt="" />';
    }
    
    /**
     * @deprecated Use getSingleRecipientPixel() instead for accurate tracking
     * Generate tracking pixel HTML
     * If recipientTokens is provided, generates multiple pixels (one per recipient)
     * WARNING: This creates incorrect tracking when all pixels are in one email!
     */
    public function getTrackingPixel(string $trackingId, string $baseUrl, ?array $recipientTokens = null): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        
        // If we have recipient tokens, create pixels for each
        // NOTE: This is WRONG if all pixels go in one email - use getSingleRecipientPixel instead
        if ($recipientTokens && count($recipientTokens) > 0) {
            $pixels = [];
            foreach ($recipientTokens as $email => $token) {
                $pixelUrl = $baseUrl . '/api/track/' . $token . '.gif';
                $pixels[] = '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" style="display:none;" alt="" data-r="' . htmlspecialchars(base64_encode($email)) . '" />';
            }
            return implode('', $pixels);
        }
        
        // Fallback to generic tracking ID
        $pixelUrl = $baseUrl . '/api/track/' . $trackingId . '.gif';
        return '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" style="display:none;" alt="" />';
    }
    
    /**
     * Get recipient tokens for a tracking ID
     */
    public function getRecipientTokens(string $trackingId): array
    {
        $stmt = $this->db->prepare('
            SELECT recipient_email, recipient_token 
            FROM email_tracking_recipients 
            WHERE tracking_id = ?
        ');
        $stmt->execute([$trackingId]);
        
        $tokens = [];
        while ($row = $stmt->fetch()) {
            $tokens[$row['recipient_email']] = $row['recipient_token'];
        }
        return $tokens;
    }
    
    // ===== LINK CLICK TRACKING =====
    
    /**
     * Rewrite all <a href="..."> links in an HTML body to point through the click tracker.
     * Skips mailto:, tel:, #, and the unsubscribe link.
     */
    public function rewriteLinks(string $html, string $trackingId, string $recipientToken, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $linkIndex = 0;

        $result = preg_replace_callback(
            '/<a\s([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
            function ($matches) use ($trackingId, $recipientToken, $baseUrl, &$linkIndex) {
                $attrsBefore = $matches[1];
                $url = $matches[2];
                $attrsAfter = $matches[3] ?? '';

                if (
                    str_starts_with($url, 'mailto:') ||
                    str_starts_with($url, 'tel:') ||
                    str_starts_with($url, '#') ||
                    str_contains($url, '/api/unsubscribe/') ||
                    str_contains($url, '/api/track/')
                ) {
                    return $matches[0];
                }

                // Extract block tracking info from URL fragment: #__blk=id,type,name
                $blockId = null;
                $blockType = null;
                $blockName = null;
                if (preg_match('/#(?:.*&)?__blk=([^,&]+),([^,&]+),([^,&#]+)/', $url, $bm)) {
                    $blockId = urldecode($bm[1]);
                    $blockType = urldecode($bm[2]);
                    $blockName = urldecode($bm[3]);
                    // Strip the __blk fragment from the URL before storing
                    $url = preg_replace('/([#&])__blk=[^&#]+/', '$1', $url);
                    $url = rtrim($url, '#&');
                }

                // Also check data-block-* attributes (legacy support)
                $allAttrs = $attrsBefore . ' ' . $attrsAfter;
                if (!$blockId && preg_match('/data-block-id=["\']([^"\']+)["\']/i', $allAttrs, $bm)) {
                    $blockId = $bm[1];
                }
                if (!$blockType && preg_match('/data-block-type=["\']([^"\']+)["\']/i', $allAttrs, $bm)) {
                    $blockType = $bm[1];
                }
                if (!$blockName && preg_match('/data-block-name=["\']([^"\']+)["\']/i', $allAttrs, $bm)) {
                    $blockName = $bm[1];
                }

                $cleanBefore = preg_replace('/\s*data-block-(id|type|name)=["\'][^"\']*["\']/i', '', $attrsBefore);
                $cleanAfter = preg_replace('/\s*data-block-(id|type|name)=["\'][^"\']*["\']/i', '', $attrsAfter);

                try {
                    $linkToken = bin2hex(random_bytes(16));
                    $stmt = $this->db->prepare('
                        INSERT INTO email_link_tracking (tracking_id, link_token, original_url, link_index, block_id, block_type, block_name)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$trackingId, $linkToken, $url, $linkIndex, $blockId, $blockType, $blockName]);
                    $linkIndex++;

                    $trackUrl = $baseUrl . '/api/click/' . $linkToken . '/' . $recipientToken;
                    return '<a ' . $cleanBefore . 'href="' . htmlspecialchars($trackUrl) . '"' . $cleanAfter . '>';
                } catch (\Throwable $e) {
                    error_log("TrackingService rewriteLinks error: " . $e->getMessage());
                    return $matches[0];
                }
            },
            $html
        );

        if ($result === null) {
            error_log('[TrackingService::rewriteLinks] preg_replace_callback failed (PCRE error ' . preg_last_error() . '), returning original HTML');
            return $html;
        }

        return $result;
    }

    /**
     * Record a click event and return the original URL for redirect.
     */
    public function recordClickEvent(string $linkToken, ?string $recipientToken = null): ?string
    {
        try {
            // Look up the link and its parent tracking record
            $stmt = $this->db->prepare('
                SELECT lt.original_url, lt.tracking_id, et.user_email, et.subject, et.campaign_id
                FROM email_link_tracking lt
                LEFT JOIN email_tracking et ON et.tracking_id = lt.tracking_id
                WHERE lt.link_token = ?
            ');
            $stmt->execute([$linkToken]);
            $link = $stmt->fetch();
            if (!$link) return null;

            // Resolve recipient email from token
            $recipientEmail = null;
            if ($recipientToken) {
                $rStmt = $this->db->prepare('SELECT recipient_email FROM email_tracking_recipients WHERE recipient_token = ?');
                $rStmt->execute([$recipientToken]);
                $rRow = $rStmt->fetch();
                if ($rRow) $recipientEmail = $rRow['recipient_email'];
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // Deduplicate rapid double-clicks (same link+recipient+IP within 5 seconds)
            $recentStmt = $this->db->prepare('
                SELECT id FROM email_click_events
                WHERE link_token = ?
                  AND (recipient_email = ? OR (recipient_email IS NULL AND ? IS NULL))
                  AND (ip_address = ? OR (ip_address IS NULL AND ? IS NULL))
                  AND clicked_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
                LIMIT 1
            ');
            $recentStmt->execute([$linkToken, $recipientEmail, $recipientEmail, $ip, $ip]);
            if (!$recentStmt->fetch()) {
                $insertStmt = $this->db->prepare('
                    INSERT INTO email_click_events (link_token, recipient_token, recipient_email, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $insertStmt->execute([$linkToken, $recipientToken, $recipientEmail, $ip, $ua]);

                $this->createClickNotification($link['tracking_id'], $recipientEmail, $link['original_url']);

                // Fire CRM automation triggers for link clicks
                try {
                    $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($this->config);
                    $automationService->onEmailLinkClicked(
                        $link['tracking_id'],
                        $recipientEmail ?? '',
                        $link['user_email'] ?? '',
                        $link['campaign_id'] ?? null,
                        $link['subject'] ?? '',
                        $link['original_url'],
                        $linkToken
                    );
                } catch (\Throwable $e) {
                    error_log("Automation trigger error on link click: " . $e->getMessage());
                }
            }

            return $link['original_url'];
        } catch (\Throwable $e) {
            error_log("TrackingService recordClickEvent error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get click stats for a tracked email.
     */
    public function getClickStats(string $trackingId): array
    {
        $linksStmt = $this->db->prepare('
            SELECT lt.link_token, lt.original_url, lt.link_index,
                   lt.block_id, lt.block_type, lt.block_name,
                   COUNT(ce.id) as click_count,
                   COUNT(DISTINCT ce.recipient_email) as unique_clickers,
                   MIN(ce.clicked_at) as first_click,
                   MAX(ce.clicked_at) as last_click
            FROM email_link_tracking lt
            LEFT JOIN email_click_events ce ON ce.link_token = lt.link_token
            WHERE lt.tracking_id = ?
            GROUP BY lt.id
            ORDER BY lt.link_index ASC
        ');
        $linksStmt->execute([$trackingId]);
        return $linksStmt->fetchAll();
    }

    /**
     * Get detailed click events for a specific link.
     */
    public function getLinkClickEvents(string $linkToken, int $limit = 100): array
    {
        $stmt = $this->db->prepare('
            SELECT recipient_email, ip_address, user_agent, clicked_at
            FROM email_click_events
            WHERE link_token = ?
            ORDER BY clicked_at DESC
            LIMIT ' . (int)$limit
        );
        $stmt->execute([$linkToken]);
        return $stmt->fetchAll();
    }

    /**
     * Get distinct tracked links for a user, optionally filtered by campaign.
     */
    public function getTrackedLinks(string $userEmail, ?string $campaignId = null): array
    {
        try {
            $sql = '
                SELECT DISTINCT lt.original_url,
                       COUNT(DISTINCT ce.id) as total_clicks,
                       COUNT(DISTINCT ce.recipient_email) as unique_clickers
                FROM email_link_tracking lt
                JOIN email_tracking et ON et.tracking_id = lt.tracking_id
                LEFT JOIN email_click_events ce ON ce.link_token = lt.link_token
                WHERE et.user_email = ?
            ';
            $params = [$userEmail];

            if ($campaignId) {
                $sql .= ' AND et.campaign_id = ?';
                $params[] = $campaignId;
            }

            $sql .= ' GROUP BY lt.original_url ORDER BY total_clicks DESC LIMIT 200';

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("TrackingService getTrackedLinks error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create or update a notification when a link is clicked.
     */
    private function createClickNotification(string $trackingId, ?string $recipientEmail, string $url): void
    {
        try {
            $stmt = $this->db->prepare('SELECT user_email, subject, campaign_id FROM email_tracking WHERE tracking_id = ?');
            $stmt->execute([$trackingId]);
            $tracking = $stmt->fetch();
            if (!$tracking) return;

            $clicker = $recipientEmail ?: 'Someone';
            $now = date('Y-m-d H:i:s');
            $shortSubject = mb_strlen($tracking['subject'] ?? '') > 40
                ? mb_substr($tracking['subject'], 0, 40) . '...'
                : ($tracking['subject'] ?: 'your email');

            $newEvent = [
                'clicker' => $clicker,
                'clicker_email' => $recipientEmail,
                'url' => $url,
                'clicked_at' => $now,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ];

            $userEmail = strtolower($tracking['user_email']);

            $stmt = $this->db->prepare('
                SELECT id, read_events, data FROM notifications
                WHERE user_email = ? AND tracking_id = ? AND type = "link_click"
                LIMIT 1
            ');
            $stmt->execute([$userEmail, $trackingId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $clickEvents = json_decode($existing['read_events'], true) ?: [];
                $clickEvents[] = $newEvent;

                $data = json_decode($existing['data'], true) ?: [];
                $data['last_clicker'] = $clicker;
                $data['last_clicker_email'] = $recipientEmail;
                $data['last_url'] = $url;
                $data['total_clicks'] = count($clickEvents);

                $uniqueClickers = [];
                foreach ($clickEvents as $evt) {
                    $key = $evt['clicker_email'] ?? $evt['clicker'];
                    if ($key) $uniqueClickers[$key] = true;
                }
                $data['unique_clickers'] = count($uniqueClickers);

                $title = "$clicker clicked a link";
                $message = "\"$shortSubject\" - " . count($clickEvents) . " clicks";

                $stmt = $this->db->prepare('
                    UPDATE notifications
                    SET read_events = ?, data = ?, last_read_at = ?, is_read = 0,
                        title = ?, message = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    json_encode($clickEvents),
                    json_encode($data),
                    $now,
                    $title,
                    $message,
                    $existing['id'],
                ]);
                $this->publishNotificationEvent(
                    $userEmail,
                    (int)$existing['id'],
                    'link_click',
                    $title,
                    $message,
                    $data,
                    true
                );
            } else {
                $clickEvents = [$newEvent];
                $data = [
                    'tracking_id' => $trackingId,
                    'recipient_email' => $recipientEmail,
                    'url' => $url,
                    'subject' => $tracking['subject'],
                    'last_clicker' => $clicker,
                    'last_clicker_email' => $recipientEmail,
                    'last_url' => $url,
                    'total_clicks' => 1,
                    'unique_clickers' => 1,
                ];

                $campaignId = $tracking['campaign_id'] ?? null;
                $stmt = $this->db->prepare('
                    INSERT INTO notifications (user_email, type, title, message, data, tracking_id, campaign_id, read_events, last_read_at)
                    VALUES (?, "link_click", ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $userEmail,
                    "$clicker clicked a link",
                    "In \"$shortSubject\"",
                    json_encode($data),
                    $trackingId,
                    $campaignId,
                    json_encode($clickEvents),
                    $now,
                ]);
                $insertId = (int)$this->db->lastInsertId();
                if ($insertId > 0) {
                    $this->publishNotificationEvent(
                        $userEmail,
                        $insertId,
                        'link_click',
                        "$clicker clicked a link",
                        "In \"$shortSubject\"",
                        $data,
                        false
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log("TrackingService createClickNotification error: " . $e->getMessage());
        }
    }

    // ===== NOTIFICATIONS =====
    
    /**
     * Create a notification
     */
    public function createNotification(string $userEmail, string $type, string $title, string $message, array $data = []): ?int
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO notifications (user_email, type, title, message, data)
                VALUES (?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                strtolower($userEmail),
                $type,
                $title,
                $message,
                json_encode($data),
            ]);
            
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("TrackingService createNotification error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Normalize a naive datetime string (as stored by date('Y-m-d H:i:s') /
     * MySQL CURRENT_TIMESTAMP, which carry no timezone marker) into an
     * absolute ISO-8601 timestamp with an explicit offset.
     *
     * Without this, the browser parses "2026-06-08 09:05:00" as *local* time,
     * which shifts every read-receipt time by the viewer's UTC offset (a
     * "2 hours ago" gap for UTC+2 users). Interpreting the value in the
     * server's default timezone — exactly what date('c') does on the realtime
     * push path — keeps the DB-fetched and live-pushed times consistent and
     * lets the client convert to the viewer's local time correctly.
     */
    private function toIsoTimestamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return $value;
        }
        // Already timezone-aware (ends in Z or +HH:MM / -HHMM offset)? Leave it.
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v)) {
            return $v;
        }
        try {
            // Interpret the naive value in the server's default timezone and
            // emit an absolute timestamp, matching date('c') used elsewhere.
            return (new \DateTime($v))->format('c');
        } catch (\Exception $e) {
            return $v;
        }
    }

    /**
     * Get notifications for a user
     */
    public function getNotifications(string $userEmail, bool $unreadOnly = false, int $limit = 100): array
    {
        $sql = 'SELECT n.*, COALESCE(n.campaign_id, et.campaign_id) AS campaign_id
                FROM notifications n
                LEFT JOIN email_tracking et ON n.tracking_id = et.tracking_id
                WHERE n.user_email = ?';
        $params = [strtolower($userEmail)];
        
        if ($unreadOnly) {
            $sql .= ' AND n.is_read = 0';
        }
        
        $sql .= ' ORDER BY n.pinned DESC, COALESCE(n.last_read_at, n.created_at) DESC LIMIT ' . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return array_map(function($n) {
            $n['is_read'] = (bool)$n['is_read'];
            $n['pinned'] = (bool)($n['pinned'] ?? false);
            $n['data'] = json_decode($n['data'], true) ?: [];
            $n['read_events'] = json_decode($n['read_events'] ?? '[]', true) ?: [];

            // Emit absolute, timezone-aware timestamps so the client renders
            // them in the viewer's local time instead of misreading the naive
            // UTC values as local (which caused a +offset "ago" gap).
            $n['created_at'] = $this->toIsoTimestamp($n['created_at'] ?? null);
            $n['last_read_at'] = $this->toIsoTimestamp($n['last_read_at'] ?? null);
            $n['read_events'] = array_map(function($event) {
                if (isset($event['read_at'])) {
                    $event['read_at'] = $this->toIsoTimestamp($event['read_at']);
                }
                if (isset($event['clicked_at'])) {
                    $event['clicked_at'] = $this->toIsoTimestamp($event['clicked_at']);
                }
                return $event;
            }, $n['read_events']);

            return $n;
        }, $stmt->fetchAll());
    }
    
    /**
     * Pin or unpin a notification
     */
    public function togglePin(string $userEmail, int $notificationId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE notifications 
            SET pinned = NOT pinned 
            WHERE id = ? AND user_email = ?
        ');
        return $stmt->execute([$notificationId, strtolower($userEmail)]);
    }
    
    /**
     * Set pin status for a notification
     */
    public function setPinned(string $userEmail, int $notificationId, bool $pinned): bool
    {
        $stmt = $this->db->prepare('
            UPDATE notifications 
            SET pinned = ? 
            WHERE id = ? AND user_email = ?
        ');
        return $stmt->execute([$pinned ? 1 : 0, $notificationId, strtolower($userEmail)]);
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount(string $userEmail): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM notifications WHERE user_email = ? AND is_read = 0');
        $stmt->execute([strtolower($userEmail)]);
        return (int)$stmt->fetch()['cnt'];
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(string $userEmail, int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE notifications SET is_read = 1 WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($userEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(string $userEmail): int
    {
        $stmt = $this->db->prepare('UPDATE notifications SET is_read = 1 WHERE user_email = ? AND is_read = 0');
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->rowCount();
    }
    
    /**
     * Delete a notification
     */
    public function deleteNotification(string $userEmail, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($userEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Clear notifications for a user, optionally scoped to a notification tab.
     *
     * Supported scopes:
     *   - 'email'     -> read_receipt / link_click rows without a campaign_id
     *   - 'campaigns' -> read_receipt / link_click rows linked to a campaign
     *   - 'general'   -> everything except read_receipt / link_click
     *   - null / 'all' -> all notifications (back-compat)
     */
    public function clearAllNotifications(string $userEmail, ?string $scope = null): int
    {
        $email = strtolower($userEmail);
        $emailTypes = ['read_receipt', 'link_click'];

        switch ($scope) {
            case 'email':
                $sql = "DELETE FROM notifications
                        WHERE user_email = ?
                          AND type IN ('read_receipt','link_click')
                          AND (campaign_id IS NULL OR campaign_id = 0)";
                $params = [$email];
                break;
            case 'campaigns':
                $sql = "DELETE FROM notifications
                        WHERE user_email = ?
                          AND type IN ('read_receipt','link_click')
                          AND campaign_id IS NOT NULL
                          AND campaign_id > 0";
                $params = [$email];
                break;
            case 'general':
                $sql = "DELETE FROM notifications
                        WHERE user_email = ?
                          AND type NOT IN ('read_receipt','link_click')";
                $params = [$email];
                break;
            case null:
            case '':
            case 'all':
            default:
                $sql = "DELETE FROM notifications WHERE user_email = ?";
                $params = [$email];
                break;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    /**
     * Delete old notifications
     */
    public function cleanupOldNotifications(int $daysOld = 30): int
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }
    
    /**
     * Consolidate duplicate read receipt notifications
     * Merges notifications for the same email (by subject) into one
     */
    public function consolidateDuplicateNotifications(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        $merged = 0;
        $deleted = 0;
        
        // Get all read_receipt notifications
        $stmt = $this->db->prepare('
            SELECT id, data, read_events, created_at, last_read_at, is_read, pinned, tracking_id, title, message
            FROM notifications 
            WHERE user_email = ? AND type = "read_receipt"
            ORDER BY created_at ASC
        ');
        $stmt->execute([$userEmail]);
        $notifications = $stmt->fetchAll();
        
        // Group by subject (from data JSON) OR by tracking_id if available
        $bySubject = [];
        foreach ($notifications as $n) {
            $data = json_decode($n['data'], true) ?: [];
            
            // Try to get subject from data, or extract from message/title
            $subject = $data['subject'] ?? '';
            if (!$subject && !empty($n['message'])) {
                // Try to extract from message like: "\"subject here\" was opened"
                if (preg_match('/^"(.+?)"/', $n['message'], $matches)) {
                    $subject = $matches[1];
                }
            }
            
            if (!$subject) continue;
            
            // Normalize subject for grouping (remove Re:, Fwd:, etc.)
            $normalizedSubject = preg_replace('/^(Re|Fwd|Fw):\s*/i', '', $subject);
            $normalizedSubject = mb_strtolower(trim($normalizedSubject), 'UTF-8');
            
            if (!isset($bySubject[$normalizedSubject])) {
                $bySubject[$normalizedSubject] = [];
            }
            $bySubject[$normalizedSubject][] = $n;
        }
        
        // Merge duplicates
        foreach ($bySubject as $subject => $group) {
            if (count($group) <= 1) continue;
            
            // Keep the first one (oldest), merge all others into it
            $keep = $group[0];
            $keepId = $keep['id'];
            $keepData = json_decode($keep['data'], true) ?: [];
            $keepEvents = json_decode($keep['read_events'] ?? '[]', true) ?: [];
            $keepPinned = $keep['pinned'];
            $keepUnread = !$keep['is_read'];
            $lastReadAt = $keep['last_read_at'];
            
            // Collect unique readers for de-duplication
            $seenReaders = [];
            $uniqueEvents = [];
            
            // Add events from the keeper first
            foreach ($keepEvents as $event) {
                $key = ($event['reader_email'] ?? $event['reader'] ?? '') . '|' . ($event['read_at'] ?? '');
                if (!isset($seenReaders[$key])) {
                    $seenReaders[$key] = true;
                    $uniqueEvents[] = $event;
                }
            }
            
            $idsToDelete = [];
            
            for ($i = 1; $i < count($group); $i++) {
                $dup = $group[$i];
                $idsToDelete[] = $dup['id'];
                
                // Merge read_events
                $dupEvents = json_decode($dup['read_events'] ?? '[]', true) ?: [];
                foreach ($dupEvents as $event) {
                    $key = ($event['reader_email'] ?? $event['reader'] ?? '') . '|' . ($event['read_at'] ?? '');
                    if (!isset($seenReaders[$key])) {
                        $seenReaders[$key] = true;
                        $uniqueEvents[] = $event;
                    }
                }
                
                // If any duplicate had a legacy single-read event in data, add it too
                $dupData = json_decode($dup['data'], true) ?: [];
                if (!empty($dupData['recipient_display']) || !empty($dupData['recipient'])) {
                    $legacyEvent = [
                        'reader' => $dupData['recipient_display'] ?? $dupData['recipient'],
                        'reader_email' => $dupData['recipient'] ?? $dupData['last_reader_email'] ?? null,
                        'read_at' => $dup['created_at'],
                    ];
                    $key = ($legacyEvent['reader_email'] ?? $legacyEvent['reader'] ?? '') . '|' . $legacyEvent['read_at'];
                    if (!isset($seenReaders[$key])) {
                        $seenReaders[$key] = true;
                        $uniqueEvents[] = $legacyEvent;
                    }
                }
                
                // Keep pinned status if any was pinned
                if ($dup['pinned']) $keepPinned = true;
                
                // Keep unread status if any was unread
                if (!$dup['is_read']) $keepUnread = true;
                
                // Track latest read time
                if ($dup['last_read_at'] && (!$lastReadAt || $dup['last_read_at'] > $lastReadAt)) {
                    $lastReadAt = $dup['last_read_at'];
                }
                
                $deleted++;
            }
            
            // Sort events by time (newest first)
            usort($uniqueEvents, function($a, $b) {
                return strcmp($b['read_at'] ?? '', $a['read_at'] ?? '');
            });
            
            // Update the keeper with merged data
            $keepData['total_reads'] = count($uniqueEvents);
            $uniqueReaders = [];
            foreach ($uniqueEvents as $event) {
                $email = $event['reader_email'] ?? $event['reader'] ?? '';
                if ($email) $uniqueReaders[$email] = true;
            }
            $keepData['unique_readers'] = count($uniqueReaders);
            
            // Get latest reader for the title
            $latestReader = $uniqueEvents[0]['reader'] ?? $uniqueEvents[0]['reader_email'] ?? 'Someone';
            $keepData['last_reader'] = $latestReader;
            $keepData['last_reader_email'] = $uniqueEvents[0]['reader_email'] ?? null;
            
            $title = "$latestReader read your email";
            $shortSubject = strlen($keepData['subject'] ?? '') > 40 
                ? substr($keepData['subject'], 0, 40) . '...' 
                : ($keepData['subject'] ?? 'your email');
            $message = "\"$shortSubject\" - " . count($uniqueEvents) . " opens";
            
            // Update the keeper notification
            $updateStmt = $this->db->prepare('
                UPDATE notifications 
                SET data = ?, read_events = ?, title = ?, message = ?, 
                    pinned = ?, is_read = ?, last_read_at = ?
                WHERE id = ?
            ');
            $updateStmt->execute([
                json_encode($keepData),
                json_encode($uniqueEvents),
                $title,
                $message,
                $keepPinned ? 1 : 0,
                $keepUnread ? 0 : 1,
                $lastReadAt,
                $keepId
            ]);
            
            // Delete the duplicates
            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $deleteStmt = $this->db->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
                $deleteStmt->execute($idsToDelete);
            }
            
            $merged++;
        }
        
        return [
            'groups_merged' => $merged,
            'duplicates_deleted' => $deleted
        ];
    }

    /**
     * Push NOTIFICATION_CREATED to Redis so mailsync can relay to the web client in real time.
     * Matches the envelope used by BoardService::pushRealtimeNotification.
     */
    private function publishNotificationEvent(
        string $userEmail,
        int $notifId,
        string $type,
        string $title,
        string $message,
        array $data,
        bool $isUpdate
    ): void {
        try {
            if (!extension_loaded('redis')) {
                return;
            }
            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = (int)($this->config['redis']['port'] ?? 6379);
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) {
                $redis->auth($password);
            }
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';
            $channel = $prefix . 'mailbox:' . strtolower($userEmail);

            $redis->publish($channel, json_encode([
                'type' => 'NOTIFICATION_CREATED',
                'payload' => [
                    'id' => $notifId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'is_read' => false,
                    'created_at' => date('c'),
                    'last_read_at' => date('c'),
                    'is_update' => $isUpdate,
                ],
                'timestamp' => (int)round(microtime(true) * 1000),
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log('TrackingService notification publish error: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract base domain from a hostname (e.g., "mail.example.com" -> "example.com")
     */
    private function getBaseDomain(string $host): string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        
        // Handle cases like "localhost" or single-part domains
        if ($count <= 2) {
            return $host;
        }
        
        // Return last two parts (e.g., "example.com")
        return implode('.', array_slice($parts, -2));
    }
    
    /**
     * Check if two IP addresses are in the same /24 subnet (for IPv4)
     * This helps catch cases where the user's IP changed slightly (e.g., DHCP renewal)
     */
    private function isSameSubnet(string $ip1, string $ip2): bool
    {
        // Only works for IPv4
        if (!filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $parts1 = explode('.', $ip1);
        $parts2 = explode('.', $ip2);
        
        // Compare first 3 octets (same /24 subnet)
        return $parts1[0] === $parts2[0] &&
               $parts1[1] === $parts2[1] &&
               $parts1[2] === $parts2[2];
    }
}

