<?php

namespace Collab\Services;

/**
 * CollabPermissionService
 * 
 * Handles document sharing and permission management.
 */
class CollabPermissionService
{
    private \PDO $db;
    private string $prefix;
    
    public function __construct(array $config, string $prefix = 'collab_')
    {
        $this->prefix = $prefix;
        
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1',
            $config['db']['name'] ?? 'devc_vps_dash'
        );
        
        $this->db = new \PDO(
            $dsn,
            $config['db']['user'] ?? 'vpsadmin',
            $config['db']['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
    
    /**
     * Get user's role for a document
     */
    public function getUserRole(string $uuid, string $email): ?string
    {
        $email = strtolower($email);
        
        // Check if user is owner
        $stmt = $this->db->prepare("
            SELECT owner_email FROM {$this->prefix}documents 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        $doc = $stmt->fetch();
        
        if (!$doc) {
            return null;
        }
        
        if ($doc['owner_email'] === $email) {
            return 'owner';
        }
        
        // Check permissions table
        $stmt = $this->db->prepare("
            SELECT p.role
            FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$uuid, $email]);
        $perm = $stmt->fetch();
        
        return $perm ? $perm['role'] : null;
    }
    
    /**
     * Check if user has at least the specified permission level
     */
    public function hasPermission(string $uuid, string $email, string $requiredRole): bool
    {
        $role = $this->getUserRole($uuid, $email);
        
        if (!$role) {
            return false;
        }
        
        $roleHierarchy = [
            'owner' => 3,
            'editor' => 2,
            'viewer' => 1,
        ];
        
        return ($roleHierarchy[$role] ?? 0) >= ($roleHierarchy[$requiredRole] ?? 0);
    }
    
    /**
     * List all permissions for a document
     */
    public function listPermissions(string $uuid): array
    {
        $stmt = $this->db->prepare("
            SELECT p.user_email, p.role, p.invited_by, p.created_at, p.accepted_at
            FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND d.deleted_at IS NULL
            ORDER BY 
                CASE p.role 
                    WHEN 'owner' THEN 1 
                    WHEN 'editor' THEN 2 
                    ELSE 3 
                END,
                p.created_at
        ");
        $stmt->execute([$uuid]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Add permission for a user
     */
    public function addPermission(string $uuid, string $email, string $role, string $invitedBy): ?array
    {
        $email = strtolower($email);
        $invitedBy = strtolower($invitedBy);
        
        // Get document ID
        $stmt = $this->db->prepare("
            SELECT id FROM {$this->prefix}documents 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        $doc = $stmt->fetch();
        
        if (!$doc) {
            return null;
        }
        
        // Insert or update permission
        $stmt = $this->db->prepare("
            INSERT INTO {$this->prefix}permissions (document_id, user_email, role, invited_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role), invited_by = VALUES(invited_by), updated_at = NOW()
        ");
        $stmt->execute([$doc['id'], $email, $role, $invitedBy]);
        
        return [
            'user_email' => $email,
            'role' => $role,
            'invited_by' => $invitedBy,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Update permission role
     */
    public function updatePermission(string $uuid, string $email, string $role): bool
    {
        $email = strtolower($email);
        
        // Cannot change owner role
        $stmt = $this->db->prepare("
            SELECT p.role
            FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$uuid, $email]);
        $currentPerm = $stmt->fetch();
        
        if (!$currentPerm) {
            return false;
        }
        
        if ($currentPerm['role'] === 'owner') {
            return false; // Cannot demote owner
        }
        
        $stmt = $this->db->prepare("
            UPDATE {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            SET p.role = ?, p.updated_at = NOW()
            WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$role, $uuid, $email]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove permission
     */
    public function removePermission(string $uuid, string $email): bool
    {
        $email = strtolower($email);
        
        // Cannot remove owner
        $stmt = $this->db->prepare("
            SELECT p.role
            FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$uuid, $email]);
        $perm = $stmt->fetch();
        
        if (!$perm || $perm['role'] === 'owner') {
            return false;
        }
        
        $stmt = $this->db->prepare("
            DELETE p FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$uuid, $email]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get collaborator count for a document
     */
    public function getCollaboratorCount(string $uuid): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM {$this->prefix}permissions p
            JOIN {$this->prefix}documents d ON p.document_id = d.id
            WHERE d.uuid = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Transfer ownership to another user
     */
    public function transferOwnership(string $uuid, string $currentOwner, string $newOwner): bool
    {
        $currentOwner = strtolower($currentOwner);
        $newOwner = strtolower($newOwner);
        
        $this->db->beginTransaction();
        
        try {
            // Get document
            $stmt = $this->db->prepare("
                SELECT id FROM {$this->prefix}documents 
                WHERE uuid = ? AND owner_email = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$uuid, $currentOwner]);
            $doc = $stmt->fetch();
            
            if (!$doc) {
                $this->db->rollBack();
                return false;
            }
            
            // Update document owner
            $stmt = $this->db->prepare("
                UPDATE {$this->prefix}documents SET owner_email = ? WHERE id = ?
            ");
            $stmt->execute([$newOwner, $doc['id']]);
            
            // Update current owner's permission to editor
            $stmt = $this->db->prepare("
                UPDATE {$this->prefix}permissions SET role = 'editor' 
                WHERE document_id = ? AND user_email = ?
            ");
            $stmt->execute([$doc['id'], $currentOwner]);
            
            // Update or insert new owner's permission
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}permissions (document_id, user_email, role)
                VALUES (?, ?, 'owner')
                ON DUPLICATE KEY UPDATE role = 'owner'
            ");
            $stmt->execute([$doc['id'], $newOwner]);
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}

