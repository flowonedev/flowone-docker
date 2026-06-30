<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmSharingService
 * 
 * Manages internal CRM sharing: share with individual colleagues or groups,
 * permission checks, and accessible CRM resolution.
 */
class CrmSharingService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTables();
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        // organization_colleagues and colleague_groups are created by migration 032
        // We only ensure CRM-specific tables here
        // IMPORTANT: Must use utf8mb4_unicode_ci to match organization_colleagues collation

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_shares (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                shared_with_email VARCHAR(255) NOT NULL,
                permission ENUM('viewer', 'editor', 'manager') DEFAULT 'viewer',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_share (owner_email, shared_with_email),
                INDEX idx_owner (owner_email),
                INDEX idx_shared_with (shared_with_email),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_group_access (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                group_id INT UNSIGNED NOT NULL,
                permission ENUM('viewer', 'editor', 'manager') DEFAULT 'viewer',
                granted_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_group_share (owner_email, group_id),
                INDEX idx_owner (owner_email),
                INDEX idx_group (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_share_activity (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_email VARCHAR(255) NOT NULL,
                colleague_email VARCHAR(255) NOT NULL,
                action VARCHAR(50) NOT NULL,
                target_type VARCHAR(50) DEFAULT NULL,
                target_id INT UNSIGNED DEFAULT NULL,
                detail TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_owner (owner_email, created_at),
                INDEX idx_colleague (colleague_email, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Convert existing tables if they were created with the wrong collation (one-time fix)
        $this->fixCollation('crm_shares');
        $this->fixCollation('crm_group_access');
        $this->fixCollation('crm_share_activity');
    }

    // =========================================================================
    // Share with Colleague
    // =========================================================================

    /**
     * Share CRM with an individual colleague
     */
    public function shareWithColleague(string $ownerEmail, string $sharedWithEmail, string $permission = 'viewer'): array
    {
        // Can't share with yourself
        if (strtolower($ownerEmail) === strtolower($sharedWithEmail)) {
            throw new \InvalidArgumentException('Cannot share CRM with yourself');
        }

        $stmt = $this->db->prepare("
            INSERT INTO crm_shares (owner_email, shared_with_email, permission, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE permission = VALUES(permission), is_active = 1, updated_at = NOW()
        ");
        $stmt->execute([strtolower($ownerEmail), strtolower($sharedWithEmail), $permission]);

        $this->logActivity($ownerEmail, $ownerEmail, 'shared_crm', 'colleague', null, "Shared with {$sharedWithEmail} as {$permission}");

        return $this->getShare($ownerEmail, $sharedWithEmail);
    }

    /**
     * Get a specific share
     */
    public function getShare(string $ownerEmail, string $sharedWithEmail): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_shares
            WHERE owner_email = ? AND shared_with_email = ?
        ");
        $stmt->execute([strtolower($ownerEmail), strtolower($sharedWithEmail)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update permission for an existing share
     */
    public function updateSharePermission(int $shareId, string $ownerEmail, string $permission): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_shares SET permission = ? WHERE id = ? AND owner_email = ?
        ");
        return $stmt->execute([$permission, $shareId, strtolower($ownerEmail)]);
    }

    /**
     * Revoke a share (individual)
     */
    public function revokeShare(int $shareId, string $ownerEmail): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM crm_shares WHERE id = ? AND owner_email = ?
        ");
        return $stmt->execute([$shareId, strtolower($ownerEmail)]);
    }

    // =========================================================================
    // Share with Group
    // =========================================================================

    /**
     * Share CRM with a colleague group
     */
    public function shareWithGroup(string $ownerEmail, int $groupId, string $permission = 'viewer', string $grantedBy = ''): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO crm_group_access (owner_email, group_id, permission, granted_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE permission = VALUES(permission), granted_by = VALUES(granted_by), updated_at = NOW()
        ");
        $stmt->execute([strtolower($ownerEmail), $groupId, $permission, $grantedBy ?: $ownerEmail]);

        $this->logActivity($ownerEmail, $ownerEmail, 'shared_crm_group', 'group', $groupId, "Shared with group {$groupId} as {$permission}");

        return $this->getGroupShare($ownerEmail, $groupId);
    }

    /**
     * Get a specific group share
     */
    public function getGroupShare(string $ownerEmail, int $groupId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_group_access
            WHERE owner_email = ? AND group_id = ?
        ");
        $stmt->execute([strtolower($ownerEmail), $groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Update permission for a group share
     */
    public function updateGroupPermission(int $accessId, string $ownerEmail, string $permission): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_group_access SET permission = ? WHERE id = ? AND owner_email = ?
        ");
        return $stmt->execute([$permission, $accessId, strtolower($ownerEmail)]);
    }

    /**
     * Revoke group access
     */
    public function revokeGroupAccess(int $accessId, string $ownerEmail): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM crm_group_access WHERE id = ? AND owner_email = ?
        ");
        return $stmt->execute([$accessId, strtolower($ownerEmail)]);
    }

    // =========================================================================
    // Queries: My Shares + Shared With Me
    // =========================================================================

    /**
     * Get all shares owned by this user (who I shared my CRM with)
     */
    public function getMyShares(string $ownerEmail): array
    {
        $email = strtolower($ownerEmail);

        // Individual shares
        $stmt = $this->db->prepare("
            SELECT cs.*, 
                   oc.display_name as colleague_name, oc.avatar_path as colleague_avatar
            FROM crm_shares cs
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = cs.shared_with_email
            WHERE cs.owner_email = ? AND cs.is_active = 1
            ORDER BY cs.created_at DESC
        ");
        $stmt->execute([$email]);
        $individualShares = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group shares
        $stmt = $this->db->prepare("
            SELECT cga.*,
                   g.name as group_name, g.color as group_color, g.icon as group_icon,
                   (SELECT COUNT(*) FROM colleague_group_members WHERE group_id = cga.group_id) as member_count
            FROM crm_group_access cga
            JOIN colleague_groups g ON g.id = cga.group_id
            WHERE cga.owner_email = ?
            ORDER BY cga.created_at DESC
        ");
        $stmt->execute([$email]);
        $groupShares = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'individual' => $individualShares,
            'groups' => $groupShares
        ];
    }

    /**
     * Get CRMs shared with me (via individual shares or groups I belong to)
     */
    public function getSharedWithMe(string $myEmail): array
    {
        $email = strtolower($myEmail);

        // Individual shares TO me
        $stmt = $this->db->prepare("
            SELECT cs.id, cs.owner_email, cs.permission, cs.created_at,
                   oc.display_name as owner_name, oc.avatar_path as owner_avatar
            FROM crm_shares cs
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = cs.owner_email
            WHERE cs.shared_with_email = ? AND cs.is_active = 1
            ORDER BY cs.created_at DESC
        ");
        $stmt->execute([$email]);
        $individual = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group shares where I'm a member
        $stmt = $this->db->prepare("
            SELECT cga.id, cga.owner_email, cga.permission, cga.group_id, cga.created_at,
                   g.name as group_name,
                   oc.display_name as owner_name, oc.avatar_path as owner_avatar
            FROM crm_group_access cga
            INNER JOIN colleague_group_members cgm ON cgm.group_id = cga.group_id
            INNER JOIN organization_colleagues oco ON oco.id = cgm.colleague_id AND LOWER(oco.email) = ?
            LEFT JOIN colleague_groups g ON g.id = cga.group_id
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = cga.owner_email
            WHERE cga.owner_email != ?
            ORDER BY cga.created_at DESC
        ");
        $stmt->execute([$email, $email]);
        $fromGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'individual' => $individual,
            'from_groups' => $fromGroups
        ];
    }

    // =========================================================================
    // Permission Gate
    // =========================================================================

    /**
     * Check if a requester can access another user's CRM.
     * Returns the highest permission level or null if no access.
     */
    public function canAccessCrm(string $requesterEmail, string $ownerEmail): ?string
    {
        $requester = strtolower($requesterEmail);
        $owner = strtolower($ownerEmail);

        // Own CRM = full access
        if ($requester === $owner) {
            return 'manager';
        }

        $highestPermission = null;

        // Check individual share
        $stmt = $this->db->prepare("
            SELECT permission FROM crm_shares
            WHERE owner_email = ? AND shared_with_email = ? AND is_active = 1
        ");
        $stmt->execute([$owner, $requester]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $highestPermission = $row['permission'];
        }

        // Check group shares
        $stmt = $this->db->prepare("
            SELECT cga.permission
            FROM crm_group_access cga
            INNER JOIN colleague_group_members cgm ON cgm.group_id = cga.group_id
            INNER JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
            WHERE cga.owner_email = ?
        ");
        $stmt->execute([$requester, $owner]);
        $groupPerms = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($groupPerms as $perm) {
            if ($this->permissionLevel($perm) > $this->permissionLevel($highestPermission)) {
                $highestPermission = $perm;
            }
        }

        return $highestPermission;
    }

    /**
     * Get all owner emails whose CRM this user can access
     */
    public function getAccessibleCrmOwners(string $myEmail): array
    {
        $email = strtolower($myEmail);
        $owners = [];

        // Individual shares
        $stmt = $this->db->prepare("
            SELECT DISTINCT owner_email FROM crm_shares
            WHERE shared_with_email = ? AND is_active = 1
        ");
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ownerEmail) {
            $owners[$ownerEmail] = true;
        }

        // Group shares
        $stmt = $this->db->prepare("
            SELECT DISTINCT cga.owner_email
            FROM crm_group_access cga
            INNER JOIN colleague_group_members cgm ON cgm.group_id = cga.group_id
            INNER JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
            WHERE cga.owner_email != ?
        ");
        $stmt->execute([$email, $email]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ownerEmail) {
            $owners[$ownerEmail] = true;
        }

        return array_keys($owners);
    }

    // =========================================================================
    // Activity Log
    // =========================================================================

    /**
     * Log a share-related activity
     */
    public function logActivity(string $ownerEmail, string $colleagueEmail, string $action, ?string $targetType = null, ?int $targetId = null, ?string $detail = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO crm_share_activity (owner_email, colleague_email, action, target_type, target_id, detail)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            strtolower($ownerEmail),
            strtolower($colleagueEmail),
            $action,
            $targetType,
            $targetId,
            $detail
        ]);
    }

    /**
     * Get recent share activity for an owner's CRM
     */
    public function getActivity(string $ownerEmail, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT csa.*, oc.display_name as colleague_name
            FROM crm_share_activity csa
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = csa.colleague_email
            WHERE csa.owner_email = ?
            ORDER BY csa.created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([strtolower($ownerEmail)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Convert a table to utf8mb4_unicode_ci if it's using a different collation
     */
    private function fixCollation(string $table): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $collation = $stmt->fetchColumn();
            if ($collation && $collation !== 'utf8mb4_unicode_ci') {
                $this->db->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (\PDOException $e) { /* table might not exist */ }
    }

    private function permissionLevel(?string $permission): int
    {
        return match ($permission) {
            'manager' => 3,
            'editor' => 2,
            'viewer' => 1,
            default => 0,
        };
    }
}

