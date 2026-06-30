<?php

namespace Webmail\Addons\Team\Services;

use Webmail\Services\RedisCacheService;
use Webmail\Addons\CrmPro\Services\CrmAutomationService;

/**
 * ColleagueService - Organization colleague management
 * 
 * Features:
 * - Sync colleagues from Dovecot/Postfix mail server
 * - Group management with drag-drop UI support
 * - Profile sync broadcasting via Redis/WebSocket
 * - Group-based permissions for Drive, Boards, Calendar
 */
class ColleagueService
{
    private \PDO $db;
    private array $config;
    private ?RedisCacheService $redis = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        // Main app database (also contains mail_accounts table from Dovecot)
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Ensure tables exist
        $this->ensureTablesExist();
        
        // Redis for broadcasting (optional - catches both Exception and Error)
        try {
            $this->redis = new RedisCacheService($config);
        } catch (\Throwable $e) {
            error_log("ColleagueService: Redis unavailable: " . $e->getMessage());
            $this->redis = null;
        }
    }
    
    /**
     * Ensure all required tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            // Organization colleagues
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS organization_colleagues (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    organization_domain VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    display_name VARCHAR(255) DEFAULT NULL,
                    avatar_path VARCHAR(500) DEFAULT NULL,
                    job_title VARCHAR(255) DEFAULT NULL,
                    department VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    is_admin TINYINT(1) DEFAULT 0,
                    status ENUM('active', 'away', 'offline', 'do_not_disturb') DEFAULT 'active',
                    last_seen_at TIMESTAMP NULL,
                    profile_updated_at TIMESTAMP NULL,
                    synced_from_mailserver TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_email (email),
                    INDEX idx_domain (organization_domain),
                    INDEX idx_admin (organization_domain, is_admin),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Colleague groups
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS colleague_groups (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    organization_domain VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    color VARCHAR(20) DEFAULT '#6366f1',
                    icon VARCHAR(50) DEFAULT 'group',
                    can_see_all_boards TINYINT(1) NOT NULL DEFAULT 0,
                    can_see_all_tasks TINYINT(1) NOT NULL DEFAULT 0,
                    can_manage_members TINYINT(1) NOT NULL DEFAULT 0,
                    can_view_financials TINYINT(1) NOT NULL DEFAULT 0,
                    admin_equivalent TINYINT(1) NOT NULL DEFAULT 0,
                    sort_order INT DEFAULT 0,
                    created_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_domain_name (organization_domain, name),
                    INDEX idx_domain (organization_domain),
                    INDEX idx_sort (organization_domain, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Group memberships
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS colleague_group_members (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    group_id INT UNSIGNED NOT NULL,
                    colleague_id INT UNSIGNED NOT NULL,
                    added_by VARCHAR(255) NOT NULL,
                    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_group_colleague (group_id, colleague_id),
                    INDEX idx_colleague (colleague_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Drive folder group access
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_folder_group_access (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    group_id INT UNSIGNED NOT NULL,
                    permission ENUM('viewer', 'editor') DEFAULT 'viewer',
                    granted_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_folder_group (folder_id, group_id),
                    INDEX idx_group (group_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Board group access
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS board_group_access (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    board_id INT UNSIGNED NOT NULL,
                    group_id INT UNSIGNED NOT NULL,
                    can_edit TINYINT(1) DEFAULT 0,
                    can_view_financials TINYINT(1) DEFAULT 0,
                    granted_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_board_group (board_id, group_id),
                    INDEX idx_group (group_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Calendar group access
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS calendar_group_access (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    calendar_id INT UNSIGNED NOT NULL,
                    group_id INT UNSIGNED NOT NULL,
                    can_edit TINYINT(1) DEFAULT 0,
                    can_see_details TINYINT(1) DEFAULT 1,
                    granted_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_calendar_group (calendar_id, group_id),
                    INDEX idx_group (group_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Profile events
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS colleague_profile_events (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    colleague_id INT UNSIGNED NOT NULL,
                    organization_domain VARCHAR(255) NOT NULL,
                    event_type ENUM('profile_updated', 'avatar_changed', 'status_changed', 'group_added', 'group_removed') NOT NULL,
                    event_data JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_colleague (colleague_id),
                    INDEX idx_domain_time (organization_domain, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Ensure status_text column exists (for custom user status)
            $this->ensureStatusTextColumn();

        } catch (\PDOException $e) {
            error_log("ColleagueService: Table creation error: " . $e->getMessage());
        }
    }

    /**
     * Self-healing: add status_text column if missing
     */
    private function ensureStatusTextColumn(): void
    {
        try {
            $result = $this->db->query("SHOW COLUMNS FROM organization_colleagues LIKE 'status_text'");
            if ($result->rowCount() === 0) {
                $this->db->exec("ALTER TABLE organization_colleagues ADD COLUMN status_text VARCHAR(100) DEFAULT NULL AFTER status");
                error_log("ColleagueService: Added status_text column to organization_colleagues");
            }
        } catch (\PDOException $e) {
            error_log("ColleagueService: Failed to add status_text column: " . $e->getMessage());
        }
    }

    /**
     * Check if user is an admin for their domain
     */
    public function isAdmin(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT is_admin FROM organization_colleagues WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $result = $stmt->fetch();
        return $result && (bool)$result['is_admin'];
    }
    
    /**
     * Get domain from email
     */
    private function getDomain(string $email): string
    {
        return strtolower(substr($email, strpos($email, '@') + 1));
    }

    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com',
        'live.com', 'yahoo.com', 'yahoo.co.uk', 'icloud.com',
        'me.com', 'aol.com', 'protonmail.com', 'proton.me',
        'mail.com', 'zoho.com', 'yandex.com', 'gmx.com', 'gmx.net',
        'tutanota.com', 'fastmail.com', 'hey.com',
    ];

    public static function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::PUBLIC_EMAIL_DOMAINS, true);
    }

    /**
     * Ensure colleague exists (auto-create if not).
     * First user on a custom domain becomes admin automatically.
     * Public email domains (gmail, etc.) get auto-admin + isolation.
     */
    public function ensureColleagueExists(string $email, ?string $displayName = null): ?array
    {
        $email = strtolower($email);
        $existing = $this->getColleagueByEmail($email);

        if ($existing) {
            return $existing;
        }

        $domain = $this->getDomain($email);
        $name = $displayName ?: $this->extractNameFromEmail($email);
        $isPublic = self::isPublicDomain($domain);

        $isFirstForDomain = false;
        if (!$isPublic) {
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM organization_colleagues WHERE organization_domain = ?'
            );
            $countStmt->execute([$domain]);
            $isFirstForDomain = ((int) $countStmt->fetchColumn()) === 0;
        }

        $isAdmin = ($isPublic || $isFirstForDomain) ? 1 : 0;

        $stmt = $this->db->prepare('
            INSERT INTO organization_colleagues (organization_domain, email, display_name, is_admin)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$domain, $email, $name, $isAdmin]);

        $newColleague = $this->getColleagueByEmail($email);

        if (!$isFirstForDomain && !$isPublic && $newColleague) {
            $this->broadcastColleagueUpdate($domain, (int) $newColleague['id'], 'created');
            $this->notifyAdminsOfNewMember($domain, $email, $name);
        }

        return $newColleague;
    }
    
    /**
     * Extract name from email address
     */
    private function extractNameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];
        $name = preg_replace('/[._0-9]+/', ' ', $localPart);
        return ucwords(trim($name)) ?: $localPart;
    }

    /**
     * Notify all admins in a domain that a new member has auto-joined.
     */
    private function notifyAdminsOfNewMember(string $domain, string $newEmail, string $displayName): void
    {
        try {
            $tracking = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);

            $stmt = $this->db->prepare(
                'SELECT email FROM organization_colleagues WHERE organization_domain = ? AND is_admin = 1 AND email != ?'
            );
            $stmt->execute([$domain, $newEmail]);
            $admins = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($admins as $adminEmail) {
                $notifId = $tracking->createNotification(
                    $adminEmail,
                    'team_member_joined',
                    'New team member',
                    "{$displayName} ({$newEmail}) has joined the system!",
                    ['member_email' => $newEmail, 'member_name' => $displayName]
                );

                if ($notifId && $this->redis) {
                    $this->redis->publishEvent($adminEmail, 'NOTIFICATION', [
                        'id' => $notifId,
                        'type' => 'team_member_joined',
                        'title' => 'New team member',
                        'message' => "{$displayName} ({$newEmail}) has joined the system!",
                        'data' => ['member_email' => $newEmail, 'member_name' => $displayName],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("notifyAdminsOfNewMember error: " . $e->getMessage());
        }
    }

    // ========================================
    // SYNC FROM DOVECOT/POSTFIX
    // ========================================
    
    /**
     * Sync colleagues from mail_accounts table (Dovecot uses same DB)
     */
    public function syncFromMailServer(string $adminEmail): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        return $this->performSync($this->getDomain($adminEmail));
    }

    /**
     * Internal sync -- can be called without admin check for auto-sync
     */
    private function performSync(string $domain): array
    {
        // Cleanup 1: remove any malformed entries (e.g. double-domain from bad CONCAT)
        try {
            $cleaned = $this->db->prepare("DELETE FROM organization_colleagues WHERE email LIKE ?");
            $cleaned->execute(['%@%@%']);
            $removedCount = $cleaned->rowCount();
            if ($removedCount > 0) {
                error_log("ColleagueService: cleaned {$removedCount} malformed email entries");
            }
        } catch (\PDOException $e) {
            error_log("ColleagueService: cleanup error: " . $e->getMessage());
        }

        $allEmails = [];
        $sources = [];
        $likeDomain = '%@' . $domain;

        // Cleanup 2: fix any colleagues with wrong organization_domain
        try {
            $fixDomain = $this->db->prepare("
                UPDATE organization_colleagues 
                SET organization_domain = ?
                WHERE email LIKE ? AND organization_domain != ?
            ");
            $fixDomain->execute([$domain, $likeDomain, $domain]);
            $fixedCount = $fixDomain->rowCount();
            if ($fixedCount > 0) {
                error_log("ColleagueService: fixed domain for {$fixedCount} colleagues to [{$domain}]");
            }
        } catch (\PDOException $e) {
            error_log("ColleagueService: domain fix error: " . $e->getMessage());
        }

        $scanTable = function (string $query, array $params, string $column, string $sourceName) use (&$allEmails, &$sources) {
            try {
                $stmt = $this->db->prepare($query);
                $stmt->execute($params);
                $found = 0;
                foreach ($stmt->fetchAll() as $row) {
                    $e = strtolower(trim($row[$column]));
                    if (empty($e) || substr_count($e, '@') !== 1) continue;
                    if (!isset($allEmails[$e])) $found++;
                    $allEmails[$e] = true;
                }
                $sources[$sourceName] = $found;
            } catch (\PDOException $e) {
                $sources[$sourceName] = 'error: ' . $e->getMessage();
                error_log("ColleagueService sync source [{$sourceName}] error: " . $e->getMessage());
            }
        };

        // Source 1: mail_accounts via domain column (Dovecot canonical)
        $scanTable(
            'SELECT email FROM mail_accounts WHERE domain = ?',
            [$domain], 'email', 'mail_accounts'
        );

        // Source 2: webmail_accounts (logged-in accounts)
        $scanTable('SELECT DISTINCT account_email FROM webmail_accounts WHERE account_email LIKE ?', [$likeDomain], 'account_email', 'webmail_accounts');

        // Source 3: webmail_accounts primary_email (owners who may have a different login)
        $scanTable('SELECT DISTINCT primary_email FROM webmail_accounts WHERE primary_email LIKE ?', [$likeDomain], 'primary_email', 'webmail_primary');

        // Source 4: webmail_account_settings
        $scanTable('SELECT DISTINCT email FROM webmail_account_settings WHERE email LIKE ?', [$likeDomain], 'email', 'account_settings');

        // Source 5: board owners
        $scanTable('SELECT DISTINCT owner_email FROM webmail_boards WHERE owner_email LIKE ?', [$likeDomain], 'owner_email', 'board_owners');

        // Source 6: board members
        $scanTable('SELECT DISTINCT user_email FROM webmail_board_members WHERE user_email LIKE ?', [$likeDomain], 'user_email', 'board_members');

        // Source 7: card assignees (Project Hub)
        $scanTable('SELECT DISTINCT user_email FROM projecthub_card_assignees WHERE user_email LIKE ?', [$likeDomain], 'user_email', 'card_assignees');

        // Source 8: organization_colleagues already in DB (preserve manually-added)
        $scanTable(
            'SELECT email FROM organization_colleagues WHERE organization_domain = ?',
            [$domain], 'email', 'existing_colleagues'
        );

        // Source 9: email contacts (frequently used addresses)
        $scanTable(
            'SELECT DISTINCT contact_email FROM email_contacts WHERE contact_email LIKE ?',
            [$likeDomain], 'contact_email', 'email_contacts'
        );

        // Source 10: email contacts (user_email / owner side)
        $scanTable(
            'SELECT DISTINCT user_email FROM email_contacts WHERE user_email LIKE ?',
            [$likeDomain], 'user_email', 'email_contacts_owners'
        );

        $synced = 0;
        $updated = 0;

        foreach (array_keys($allEmails) as $email) {
            $name = $this->extractNameFromEmail($email);

            $stmt = $this->db->prepare('
                INSERT INTO organization_colleagues
                    (organization_domain, email, display_name, synced_from_mailserver)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    organization_domain = VALUES(organization_domain),
                    display_name = COALESCE(display_name, VALUES(display_name)),
                    synced_from_mailserver = 1,
                    updated_at = NOW()
            ');
            $stmt->execute([$domain, $email, $name]);
            $synced++;
        }

        // Verify: count what's actually in the DB now
        $verifyStmt = $this->db->prepare('SELECT COUNT(*) FROM organization_colleagues WHERE organization_domain = ?');
        $verifyStmt->execute([$domain]);
        $dbCount = (int)$verifyStmt->fetchColumn();

        error_log("ColleagueService sync [{$domain}]: found=" . count($allEmails) . " synced={$synced} db_total={$dbCount} sources=" . json_encode($sources) . " emails=" . implode(', ', array_keys($allEmails)));

        return [
            'success' => true,
            'synced' => $synced,
            'total' => count($allEmails),
            'db_total' => $dbCount,
            'sources' => $sources,
            'emails_found' => array_keys($allEmails),
        ];
    }
    
    /**
     * Manually add a colleague (admin only)
     */
    public function addColleague(string $adminEmail, array $data): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        if (empty($data['email'])) {
            return ['success' => false, 'error' => 'Email is required'];
        }
        
        $email = strtolower($data['email']);
        $domain = $this->getDomain($adminEmail);
        $collegeDomain = $this->getDomain($email);

        if (self::isPublicDomain($domain)) {
            return ['success' => false, 'error' => 'Cannot manually add colleagues on public email domains (Gmail, Outlook, etc.)'];
        }

        if ($domain !== $collegeDomain) {
            return ['success' => false, 'error' => 'Colleague must be from the same domain'];
        }
        
        // Check if exists -- if found but different domain, fix the domain
        $existing = $this->getColleagueByEmail($email);
        if ($existing) {
            if ($existing['organization_domain'] !== $domain) {
                $stmt = $this->db->prepare('UPDATE organization_colleagues SET organization_domain = ? WHERE id = ?');
                $stmt->execute([$domain, $existing['id']]);
                return ['success' => true, 'id' => $existing['id'], 'fixed_domain' => true];
            }
            return ['success' => false, 'error' => "Colleague already exists (id: {$existing['id']}, domain: {$existing['organization_domain']})"];
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO organization_colleagues 
                (organization_domain, email, display_name, job_title, department, phone, is_admin)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $domain,
            $email,
            $data['display_name'] ?? $this->extractNameFromEmail($email),
            $data['job_title'] ?? null,
            $data['department'] ?? null,
            $data['phone'] ?? null,
            $data['is_admin'] ?? 0
        ]);
        
        $id = (int)$this->db->lastInsertId();
        
        $this->broadcastColleagueUpdate($domain, $id, 'created');
        
        return ['success' => true, 'id' => $id];
    }
    
    // ========================================
    // COLLEAGUE MANAGEMENT
    // ========================================
    
    /**
     * Get all colleagues for a domain
     */
    public function getColleagues(string $userEmail): array
    {
        $domain = $this->getDomain($userEmail);

        // Auto-sync if very few colleagues exist (first-time or incomplete sync)
        try {
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM organization_colleagues WHERE organization_domain = ?');
            $countStmt->execute([$domain]);
            $existingCount = (int)$countStmt->fetchColumn();

            if ($existingCount < 3) {
                error_log("ColleagueService: auto-sync triggered for [{$domain}] (only {$existingCount} colleagues)");
                $this->performSync($domain);
            }
        } catch (\PDOException $e) {
            error_log("ColleagueService: auto-sync check failed: " . $e->getMessage());
        }

        $stmt = $this->db->prepare('
            SELECT c.*, 
                   GROUP_CONCAT(DISTINCT g.id ORDER BY g.name) as group_ids,
                   GROUP_CONCAT(DISTINCT g.name ORDER BY g.name) as group_names,
                   GROUP_CONCAT(DISTINCT g.color ORDER BY g.name) as group_colors
            FROM organization_colleagues c
            LEFT JOIN colleague_group_members gm ON c.id = gm.colleague_id
            LEFT JOIN colleague_groups g ON gm.group_id = g.id
            WHERE c.organization_domain = ?
            GROUP BY c.id
            ORDER BY c.display_name ASC
        ');
        $stmt->execute([$domain]);
        
        return array_map(function($row) {
            $row['is_admin'] = (bool)$row['is_admin'];
            $row['synced_from_mailserver'] = (bool)$row['synced_from_mailserver'];
            $row['group_ids'] = $row['group_ids'] ? array_map('intval', explode(',', $row['group_ids'])) : [];
            $row['group_names'] = $row['group_names'] ? explode(',', $row['group_names']) : [];
            $row['group_colors'] = $row['group_colors'] ? explode(',', $row['group_colors']) : [];
            return $row;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get colleagues by id, scoped to the requesting user's organization.
     * Used to resolve share-notify recipients server-side.
     */
    public function getColleaguesByIds(string $userEmail, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $domain = $this->getDomain($userEmail);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "SELECT id, email, display_name
             FROM organization_colleagues
             WHERE organization_domain = ? AND id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$domain], $ids));

        return $stmt->fetchAll();
    }

    /**
     * Get single colleague by email
     */
    public function getColleagueByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        $result = $stmt->fetch();
        
        if ($result) {
            $result['is_admin'] = (bool)$result['is_admin'];
            $result['synced_from_mailserver'] = (bool)$result['synced_from_mailserver'];
        }
        
        return $result ?: null;
    }
    
    /**
     * Get colleague by ID
     */
    public function getColleagueById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result) {
            $result['is_admin'] = (bool)$result['is_admin'];
            $result['synced_from_mailserver'] = (bool)$result['synced_from_mailserver'];
        }
        
        return $result ?: null;
    }
    
    /**
     * Update colleague profile (admin only, or self)
     */
    public function updateColleague(string $actorEmail, int $colleagueId, array $data): array
    {
        $isSelf = false;
        $colleague = $this->getColleagueById($colleagueId);
        
        if (!$colleague) {
            return ['success' => false, 'error' => 'Colleague not found'];
        }
        
        // Check permissions: must be admin OR updating own profile
        if ($colleague['email'] === strtolower($actorEmail)) {
            $isSelf = true;
        } elseif (!$this->isAdmin($actorEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        // Self can only update certain fields
        $allowedFields = $isSelf 
            ? ['display_name', 'avatar_path', 'job_title', 'phone', 'status', 'status_text']
            : ['display_name', 'avatar_path', 'job_title', 'department', 'phone', 'is_admin', 'status', 'status_text'];
        
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                // Convert is_admin to integer (0 or 1) to avoid SQL errors
                if ($field === 'is_admin') {
                    $params[] = $data[$field] ? 1 : 0;
                } else {
                    $params[] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }
        
        $updates[] = "profile_updated_at = NOW()";
        $params[] = $colleagueId;
        
        $sql = "UPDATE organization_colleagues SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // Determine event type
        $eventType = 'profile_updated';
        if (isset($data['avatar_path'])) {
            $eventType = 'avatar_changed';
        } elseif (isset($data['status'])) {
            $eventType = 'status_changed';
        }
        
        // Log event and broadcast via WebSocket
        $this->logProfileEvent($colleagueId, $eventType, $data);
        $this->broadcastColleagueUpdate($colleague['organization_domain'], $colleagueId, 'updated');
        
        // Fire automation hook when status_text changes (e.g. "Out sick today")
        if (array_key_exists('status_text', $data)) {
            $this->_fireColleagueStatusAutomation(
                $colleagueId,
                $colleague['email'],
                $colleague['status'] ?? 'active',
                $data['status_text'],
                $colleague['organization_domain']
            );
        }
        
        return ['success' => true];
    }
    
    /**
     * Update colleague's presence status
     */
    public function updateColleagueStatus(string $userEmail, string $status): array
    {
        $colleague = $this->getColleagueByEmail($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'Colleague not found'];
        }
        
        if (!in_array($status, ['active', 'away', 'do_not_disturb', 'offline'])) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        
        $stmt = $this->db->prepare('
            UPDATE organization_colleagues 
            SET status = ?, last_seen_at = NOW() 
            WHERE id = ?
        ');
        $stmt->execute([$status, $colleague['id']]);
        
        // Broadcast status change via WebSocket
        $this->broadcastColleagueUpdate($colleague['organization_domain'], $colleague['id'], 'status_changed', [
            'status' => $status,
            'email' => $userEmail
        ]);
        
        return ['success' => true];
    }
    
    /**
     * Delete colleague (admin only)
     */
    public function deleteColleague(string $adminEmail, int $colleagueId): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $colleague = $this->getColleagueById($colleagueId);
        if (!$colleague) {
            return ['success' => false, 'error' => 'Colleague not found'];
        }
        
        // Can't delete yourself
        if ($colleague['email'] === strtolower($adminEmail)) {
            return ['success' => false, 'error' => 'Cannot delete yourself'];
        }
        
        $domain = $colleague['organization_domain'];
        
        $stmt = $this->db->prepare('DELETE FROM organization_colleagues WHERE id = ?');
        $stmt->execute([$colleagueId]);
        
        $this->broadcastColleagueUpdate($domain, $colleagueId, 'deleted');
        
        return ['success' => true];
    }
    
    /**
     * Update last seen timestamp
     */
    public function updateLastSeen(string $email): void
    {
        $stmt = $this->db->prepare('UPDATE organization_colleagues SET last_seen_at = NOW() WHERE email = ?');
        $stmt->execute([strtolower($email)]);
    }
    
    // ========================================
    // GROUP MANAGEMENT
    // ========================================
    
    /**
     * Get all groups for a domain
     */
    public function getGroups(string $userEmail): array
    {
        $domain = $this->getDomain($userEmail);
        
        $stmt = $this->db->prepare('
            SELECT g.*, 
                   COUNT(gm.colleague_id) as member_count
            FROM colleague_groups g
            LEFT JOIN colleague_group_members gm ON g.id = gm.group_id
            WHERE g.organization_domain = ?
            GROUP BY g.id
            ORDER BY g.sort_order ASC, g.name ASC
        ');
        $stmt->execute([$domain]);
        
        return array_map(function($row) {
            $row['member_count'] = (int)$row['member_count'];
            $row['sort_order'] = (int)$row['sort_order'];
            $row['can_see_all_boards'] = (bool)($row['can_see_all_boards'] ?? false);
            $row['can_see_all_tasks'] = (bool)($row['can_see_all_tasks'] ?? false);
            $row['can_manage_members'] = (bool)($row['can_manage_members'] ?? false);
            $row['can_view_financials'] = (bool)($row['can_view_financials'] ?? false);
            $row['admin_equivalent'] = (bool)($row['admin_equivalent'] ?? false);
            return $row;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get group with members
     */
    public function getGroupWithMembers(string $userEmail, int $groupId): ?array
    {
        $domain = $this->getDomain($userEmail);
        
        $stmt = $this->db->prepare('
            SELECT * FROM colleague_groups 
            WHERE id = ? AND organization_domain = ?
        ');
        $stmt->execute([$groupId, $domain]);
        $group = $stmt->fetch();
        
        if (!$group) return null;
        
        // Get members
        $stmt = $this->db->prepare('
            SELECT c.* 
            FROM organization_colleagues c
            JOIN colleague_group_members gm ON c.id = gm.colleague_id
            WHERE gm.group_id = ?
            ORDER BY c.display_name ASC
        ');
        $stmt->execute([$groupId]);
        $group['members'] = array_map(function($row) {
            $row['is_admin'] = (bool)$row['is_admin'];
            return $row;
        }, $stmt->fetchAll());
        
        return $group;
    }
    
    /**
     * Create a new group (admin only)
     */
    public function createGroup(string $adminEmail, array $data): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        if (empty($data['name'])) {
            return ['success' => false, 'error' => 'Group name is required'];
        }
        
        $domain = $this->getDomain($adminEmail);
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO colleague_groups (
                    organization_domain, name, description, color, icon,
                    can_see_all_boards, can_see_all_tasks, can_manage_members, can_view_financials, admin_equivalent,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $domain,
                $data['name'],
                $data['description'] ?? null,
                $data['color'] ?? '#6366f1',
                $data['icon'] ?? 'group',
                !empty($data['can_see_all_boards']) ? 1 : 0,
                !empty($data['can_see_all_tasks']) ? 1 : 0,
                !empty($data['can_manage_members']) ? 1 : 0,
                !empty($data['can_view_financials']) ? 1 : 0,
                !empty($data['admin_equivalent']) ? 1 : 0,
                $adminEmail
            ]);
            
            $groupId = (int)$this->db->lastInsertId();
            
            // Broadcast to all domain users (non-blocking)
            try {
                $this->broadcastGroupUpdate($domain, $groupId, 'created');
            } catch (\Exception $e) {
                error_log("ColleagueService: Broadcast failed: " . $e->getMessage());
            }
            
            return ['success' => true, 'id' => $groupId];
        } catch (\PDOException $e) {
            error_log("ColleagueService: createGroup error: " . $e->getMessage());
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return ['success' => false, 'error' => 'A group with this name already exists'];
            }
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update group (admin only)
     */
    public function updateGroup(string $adminEmail, int $groupId, array $data): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $domain = $this->getDomain($adminEmail);
        
        $allowedFields = ['name', 'description', 'color', 'icon', 'sort_order'];
        $booleanFields = ['can_see_all_boards', 'can_see_all_tasks', 'can_manage_members', 'can_view_financials', 'admin_equivalent'];
        $updates = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field] ? 1 : 0;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No valid fields'];
        }
        
        $params[] = $groupId;
        $params[] = $domain;
        
        $sql = "UPDATE colleague_groups SET " . implode(', ', $updates) . " WHERE id = ? AND organization_domain = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $this->broadcastGroupUpdate($domain, $groupId, 'updated');
        
        return ['success' => true];
    }
    
    /**
     * Delete group (admin only)
     */
    public function deleteGroup(string $adminEmail, int $groupId): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $domain = $this->getDomain($adminEmail);
        
        $stmt = $this->db->prepare('DELETE FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        $this->broadcastGroupUpdate($domain, $groupId, 'deleted');
        
        return ['success' => true];
    }
    
    /**
     * Get members of a specific group
     */
    public function getGroupMembers(string $userEmail, int $groupId): array
    {
        $domain = $this->getDomain($userEmail);
        
        // Verify group belongs to user's domain
        $stmt = $this->db->prepare('SELECT id FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        $stmt = $this->db->prepare('
            SELECT c.id, c.email, c.display_name, c.avatar_path, c.job_title, c.department
            FROM organization_colleagues c
            JOIN colleague_group_members m ON m.colleague_id = c.id
            WHERE m.group_id = ? AND c.organization_domain = ?
            ORDER BY c.display_name ASC, c.email ASC
        ');
        $stmt->execute([$groupId, $domain]);
        
        return ['success' => true, 'members' => $stmt->fetchAll()];
    }
    
    /**
     * Add colleague to group (admin only)
     * Supports bulk add for easy assignment
     */
    public function addToGroup(string $adminEmail, int $groupId, array $colleagueIds): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $domain = $this->getDomain($adminEmail);
        $added = 0;
        
        // Verify group belongs to domain
        $stmt = $this->db->prepare('SELECT id FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        foreach ($colleagueIds as $colleagueId) {
            try {
                $stmt = $this->db->prepare('
                    INSERT IGNORE INTO colleague_group_members (group_id, colleague_id, added_by)
                    VALUES (?, ?, ?)
                ');
                $stmt->execute([$groupId, $colleagueId, $adminEmail]);
                if ($stmt->rowCount() > 0) {
                    $added++;
                    $this->logProfileEvent($colleagueId, 'group_added', ['group_id' => $groupId]);
                }
            } catch (\PDOException $e) {
                // Ignore duplicate entries or FK violations
            }
        }
        
        $this->broadcastGroupUpdate($domain, $groupId, 'members_changed');
        
        return ['success' => true, 'added' => $added];
    }
    
    /**
     * Remove colleague from group (admin only)
     */
    public function removeFromGroup(string $adminEmail, int $groupId, int $colleagueId): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $stmt = $this->db->prepare('DELETE FROM colleague_group_members WHERE group_id = ? AND colleague_id = ?');
        $stmt->execute([$groupId, $colleagueId]);
        
        $this->logProfileEvent($colleagueId, 'group_removed', ['group_id' => $groupId]);
        
        $domain = $this->getDomain($adminEmail);
        $this->broadcastGroupUpdate($domain, $groupId, 'members_changed');
        
        return ['success' => true];
    }
    
    /**
     * Set colleague's groups (bulk update - replaces all groups)
     * Great for drag-drop UI
     */
    public function setColleagueGroups(string $adminEmail, int $colleagueId, array $groupIds): array
    {
        if (!$this->isAdmin($adminEmail)) {
            return ['success' => false, 'error' => 'Admin access required'];
        }
        
        $colleague = $this->getColleagueById($colleagueId);
        if (!$colleague) {
            return ['success' => false, 'error' => 'Colleague not found'];
        }
        
        // Remove from all groups
        $stmt = $this->db->prepare('DELETE FROM colleague_group_members WHERE colleague_id = ?');
        $stmt->execute([$colleagueId]);
        
        // Add to new groups
        foreach ($groupIds as $groupId) {
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO colleague_group_members (group_id, colleague_id, added_by)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$groupId, $colleagueId, $adminEmail]);
        }
        
        $this->broadcastColleagueUpdate($colleague['organization_domain'], $colleagueId, 'groups_changed');
        
        return ['success' => true];
    }
    
    // ========================================
    // REAL-TIME SYNC
    // ========================================
    
    private function logProfileEvent(int $colleagueId, string $type, ?array $data = null): void
    {
        $colleague = $this->getColleagueById($colleagueId);
        if (!$colleague) return;
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO colleague_profile_events (colleague_id, organization_domain, event_type, event_data)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([
                $colleagueId,
                $colleague['organization_domain'],
                $type,
                $data ? json_encode($data) : null
            ]);
        } catch (\PDOException $e) {
            error_log("ColleagueService: Failed to log profile event: " . $e->getMessage());
        }
    }
    
    private function broadcastColleagueUpdate(string $domain, int $colleagueId, string $action, array $extraData = []): void
    {
        if (!$this->redis) return;
        
        try {
            $colleague = $action === 'deleted' ? ['id' => $colleagueId] : $this->getColleagueById($colleagueId);
            
            $payload = [
                'action' => $action,
                'colleague_id' => $colleagueId,
                'colleague' => $colleague
            ];
            
            // Merge extra data if provided
            if (!empty($extraData)) {
                $payload = array_merge($payload, $extraData);
            }
            
            // Publish to each colleague's individual Redis channel
            // (the mailsync WS server subscribes to webmail:mailbox:* pattern)
            $stmt = $this->db->prepare('SELECT email FROM organization_colleagues WHERE organization_domain = ?');
            $stmt->execute([$domain]);
            $emails = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($emails as $email) {
                $this->redis->publishEvent($email, 'COLLEAGUE_UPDATED', $payload);
            }
        } catch (\Exception $e) {
            error_log("ColleagueService: Failed to broadcast colleague update: " . $e->getMessage());
        }
    }
    
    private function broadcastGroupUpdate(string $domain, int $groupId, string $action): void
    {
        if (!$this->redis) return;
        
        try {
            $payload = [
                'action' => $action,
                'group_id' => $groupId
            ];
            
            // Publish to each colleague's individual Redis channel
            $stmt = $this->db->prepare('SELECT email FROM organization_colleagues WHERE organization_domain = ?');
            $stmt->execute([$domain]);
            $emails = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($emails as $email) {
                $this->redis->publishEvent($email, 'COLLEAGUE_GROUP_UPDATED', $payload);
            }
        } catch (\Exception $e) {
            error_log("ColleagueService: Failed to broadcast group update: " . $e->getMessage());
        }
    }
    
    // ========================================
    // GROUP-BASED PERMISSIONS
    // ========================================
    
    /**
     * Get all groups a user belongs to
     */
    public function getUserGroups(string $email): array
    {
        $stmt = $this->db->prepare('
            SELECT g.* 
            FROM colleague_groups g
            JOIN colleague_group_members gm ON g.id = gm.group_id
            JOIN organization_colleagues c ON c.id = gm.colleague_id
            WHERE c.email = ?
        ');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetchAll();
    }

    /**
     * Get the effective (merged) permissions for a user across all their groups.
     * Any permission that is TRUE in ANY group the user belongs to is TRUE overall.
     * Returns an associative array of permission flags.
     */
    public function getEffectiveGroupPermissions(string $email): array
    {
        $defaults = [
            'can_see_all_boards' => false,
            'can_see_all_tasks'  => false,
            'can_manage_members' => false,
            'can_view_financials' => false,
            'admin_equivalent'   => false,
        ];

        $stmt = $this->db->prepare('
            SELECT
                MAX(g.can_see_all_boards) AS can_see_all_boards,
                MAX(g.can_see_all_tasks) AS can_see_all_tasks,
                MAX(g.can_manage_members) AS can_manage_members,
                MAX(g.can_view_financials) AS can_view_financials,
                MAX(g.admin_equivalent) AS admin_equivalent
            FROM colleague_groups g
            JOIN colleague_group_members gm ON g.id = gm.group_id
            JOIN organization_colleagues c ON c.id = gm.colleague_id
            WHERE c.email = ?
        ');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['can_see_all_boards'] === null) {
            return $defaults;
        }

        return [
            'can_see_all_boards' => (bool)$row['can_see_all_boards'],
            'can_see_all_tasks'  => (bool)$row['can_see_all_tasks'],
            'can_manage_members' => (bool)$row['can_manage_members'],
            'can_view_financials' => (bool)$row['can_view_financials'],
            'admin_equivalent'   => (bool)$row['admin_equivalent'],
        ];
    }

    /**
     * Check if a user has a specific group permission (via any of their groups).
     */
    public function hasGroupPermission(string $email, string $permission): bool
    {
        $perms = $this->getEffectiveGroupPermissions($email);
        if (!empty($perms['admin_equivalent'])) {
            return true;
        }
        return !empty($perms[$permission]);
    }
    
    /**
     * Share Drive folder with a group
     */
    public function shareFolderWithGroup(string $ownerEmail, int $folderId, int $groupId, string $permission = 'viewer'): array
    {
        $domain = $this->getDomain($ownerEmail);
        
        // Verify group is in same domain
        $stmt = $this->db->prepare('SELECT id, name FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        $group = $stmt->fetch();
        
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        if (!in_array($permission, ['viewer', 'editor'])) {
            $permission = 'viewer';
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO drive_folder_group_access (folder_id, group_id, permission, granted_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE permission = VALUES(permission)
        ');
        $stmt->execute([$folderId, $groupId, $permission, $ownerEmail]);
        
        $this->_fireDriveFolderPermissionAutomation($folderId, $ownerEmail, "Group '{$group['name']}' granted {$permission} access");
        
        return ['success' => true, 'group' => $group['name'], 'permission' => $permission];
    }
    
    /**
     * Remove group access from Drive folder
     */
    public function removeFolderGroupAccess(string $ownerEmail, int $folderId, int $groupId): array
    {
        // Get group name before deletion for the audit trail
        $stmt = $this->db->prepare('SELECT name FROM colleague_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $groupName = $stmt->fetchColumn() ?: "Group #{$groupId}";

        $stmt = $this->db->prepare('DELETE FROM drive_folder_group_access WHERE folder_id = ? AND group_id = ?');
        $stmt->execute([$folderId, $groupId]);
        
        $this->_fireDriveFolderPermissionAutomation($folderId, $ownerEmail, "Group '{$groupName}' access removed");
        
        return ['success' => true];
    }
    
    /**
     * Get group access for a folder
     */
    public function getFolderGroupAccess(int $folderId): array
    {
        $stmt = $this->db->prepare('
            SELECT dfa.*, g.name as group_name, g.color as group_color, g.icon as group_icon
            FROM drive_folder_group_access dfa
            JOIN colleague_groups g ON dfa.group_id = g.id
            WHERE dfa.folder_id = ?
        ');
        $stmt->execute([$folderId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Share Board with a group
     */
    public function shareBoardWithGroup(string $ownerEmail, int $boardId, int $groupId, bool $canEdit = false, bool $canViewFinancials = false): array
    {
        $domain = $this->getDomain($ownerEmail);
        
        // Verify group is in same domain
        $stmt = $this->db->prepare('SELECT id, name FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        $group = $stmt->fetch();
        
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO board_group_access (board_id, group_id, can_edit, can_view_financials, granted_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), can_view_financials = VALUES(can_view_financials)
        ');
        $stmt->execute([$boardId, $groupId, $canEdit ? 1 : 0, $canViewFinancials ? 1 : 0, $ownerEmail]);
        
        return ['success' => true, 'group' => $group['name']];
    }
    
    /**
     * Share Calendar with a group
     */
    public function shareCalendarWithGroup(string $ownerEmail, int $calendarId, int $groupId, bool $canEdit = false, bool $canSeeDetails = true): array
    {
        $domain = $this->getDomain($ownerEmail);
        
        // Verify group is in same domain
        $stmt = $this->db->prepare('SELECT id, name FROM colleague_groups WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$groupId, $domain]);
        $group = $stmt->fetch();
        
        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        
        $stmt = $this->db->prepare('
            INSERT INTO calendar_group_access (calendar_id, group_id, can_edit, can_see_details, granted_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE can_edit = VALUES(can_edit), can_see_details = VALUES(can_see_details)
        ');
        $stmt->execute([$calendarId, $groupId, $canEdit ? 1 : 0, $canSeeDetails ? 1 : 0, $ownerEmail]);
        
        return ['success' => true, 'group' => $group['name']];
    }
    
    /**
     * Check if user has access to a resource via group membership
     */
    public function hasGroupAccess(string $email, string $resourceType, int $resourceId): ?string
    {
        $groupIds = array_column($this->getUserGroups($email), 'id');
        
        if (empty($groupIds)) return null;
        
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        
        switch ($resourceType) {
            case 'drive_folder':
                $stmt = $this->db->prepare("
                    SELECT MAX(CASE WHEN permission = 'editor' THEN 2 ELSE 1 END) as level
                    FROM drive_folder_group_access
                    WHERE folder_id = ? AND group_id IN ($placeholders)
                ");
                break;
            case 'board':
                $stmt = $this->db->prepare("
                    SELECT MAX(CASE WHEN can_edit = 1 THEN 2 ELSE 1 END) as level
                    FROM board_group_access
                    WHERE board_id = ? AND group_id IN ($placeholders)
                ");
                break;
            case 'calendar':
                $stmt = $this->db->prepare("
                    SELECT MAX(CASE WHEN can_edit = 1 THEN 2 ELSE 1 END) as level
                    FROM calendar_group_access
                    WHERE calendar_id = ? AND group_id IN ($placeholders)
                ");
                break;
            default:
                return null;
        }
        
        $params = array_merge([$resourceId], $groupIds);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        if ($result && $result['level']) {
            return $result['level'] == 2 ? 'editor' : 'viewer';
        }
        
        return null;
    }

    /**
     * Fire automation hook when colleague status_text changes (e.g. "Out sick today")
     */
    private function _fireColleagueStatusAutomation(int $colleagueId, string $colleagueEmail, string $status, ?string $statusText, string $domain): void
    {
        try {
            $automationService = new CrmAutomationService($this->config);
            $automationService->onColleagueStatusChanged($colleagueId, $colleagueEmail, $status, $statusText, $domain);
        } catch (\Throwable $e) {
            error_log("ColleagueService: Automation hook error (colleague status): " . $e->getMessage());
        }
    }

    /**
     * Fire automation hook when drive folder permissions change via group sharing
     */
    private function _fireDriveFolderPermissionAutomation(int $folderId, string $changedByEmail, string $changeDetail): void
    {
        try {
            // Look up folder name
            $stmt = $this->db->prepare("SELECT name FROM drive_folders WHERE id = ?");
            $stmt->execute([$folderId]);
            $folderName = $stmt->fetchColumn() ?: "Folder #{$folderId}";

            $automationService = new CrmAutomationService($this->config);
            $automationService->onDriveFolderPermissionChanged($folderId, $folderName, $changedByEmail, $changeDetail);
        } catch (\Throwable $e) {
            error_log("ColleagueService: Automation hook error (drive folder): " . $e->getMessage());
        }
    }
}

