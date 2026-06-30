<?php

namespace Webmail\Services;

/**
 * DriveFileSharingService - file-level sharing with specific people and groups.
 *
 * Owns the drive_file_collaborators and drive_file_group_access tables
 * (migration 191). Mirrors the folder-level collaborator system in
 * DriveService but for single files, so a user can share one document
 * with a colleague or a colleague group without sharing the whole folder.
 *
 * Permissions: 'viewer' (open/download/preview) or 'editor' (edit in the
 * office editor). Group access resolves through colleague_group_members.
 */
class DriveFileSharingService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // Ownership helper
    // =========================================================================

    /**
     * Fetch a non-trashed file owned by the given user, or null.
     */
    private function getOwnedFile(string $ownerEmail, int $fileId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, user_email, original_name, folder_id
            FROM drive_files
            WHERE id = ? AND user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$fileId, strtolower($ownerEmail)]);
        $file = $stmt->fetch();
        return $file ?: null;
    }

    // =========================================================================
    // People (individual collaborators)
    // =========================================================================

    public function addCollaborator(string $ownerEmail, int $fileId, string $collaboratorEmail, string $permission = 'viewer'): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $collaboratorEmail = strtolower($collaboratorEmail);

        $file = $this->getOwnedFile($ownerEmail, $fileId);
        if (!$file) {
            return ['success' => false, 'error' => 'File not found or access denied'];
        }
        if ($ownerEmail === $collaboratorEmail) {
            return ['success' => false, 'error' => 'Cannot share file with yourself'];
        }
        if (!in_array($permission, ['viewer', 'editor'], true)) {
            $permission = 'viewer';
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_file_collaborators (file_id, user_email, permission, invited_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission), updated_at = NOW()
            ');
            $stmt->execute([$fileId, $collaboratorEmail, $permission, $ownerEmail]);

            return [
                'success' => true,
                'collaborator' => [
                    'email' => $collaboratorEmail,
                    'permission' => $permission,
                    'invited_by' => $ownerEmail,
                ],
            ];
        } catch (\PDOException $e) {
            error_log('addFileCollaborator error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }

    public function removeCollaborator(string $ownerEmail, int $fileId, string $collaboratorEmail): bool
    {
        try {
            if (!$this->getOwnedFile($ownerEmail, $fileId)) {
                return false;
            }
            $stmt = $this->db->prepare('DELETE FROM drive_file_collaborators WHERE file_id = ? AND user_email = ?');
            $stmt->execute([$fileId, strtolower($collaboratorEmail)]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log('removeFileCollaborator error: ' . $e->getMessage());
            return false;
        }
    }

    public function updateCollaboratorPermission(string $ownerEmail, int $fileId, string $collaboratorEmail, string $permission): bool
    {
        if (!in_array($permission, ['viewer', 'editor'], true)) {
            return false;
        }
        try {
            if (!$this->getOwnedFile($ownerEmail, $fileId)) {
                return false;
            }
            $stmt = $this->db->prepare('
                UPDATE drive_file_collaborators
                SET permission = ?, updated_at = NOW()
                WHERE file_id = ? AND user_email = ?
            ');
            $stmt->execute([$permission, $fileId, strtolower($collaboratorEmail)]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log('updateFileCollaboratorPermission error: ' . $e->getMessage());
            return false;
        }
    }

    public function getCollaborators(string $ownerEmail, int $fileId): array
    {
        try {
            if (!$this->getOwnedFile($ownerEmail, $fileId)) {
                return [];
            }
            $stmt = $this->db->prepare('
                SELECT user_email as email, permission, invited_by, created_at
                FROM drive_file_collaborators
                WHERE file_id = ?
                ORDER BY created_at DESC
            ');
            $stmt->execute([$fileId]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log('getFileCollaborators error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // Groups
    // =========================================================================

    public function addGroupAccess(string $ownerEmail, int $fileId, int $groupId, string $permission = 'viewer'): array
    {
        $ownerEmail = strtolower($ownerEmail);

        if (!$this->getOwnedFile($ownerEmail, $fileId)) {
            return ['success' => false, 'error' => 'File not found or access denied'];
        }
        if (!in_array($permission, ['viewer', 'editor'], true)) {
            $permission = 'viewer';
        }

        try {
            $stmt = $this->db->prepare('SELECT id FROM colleague_groups WHERE id = ?');
            $stmt->execute([$groupId]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Group not found'];
            }

            $stmt = $this->db->prepare('
                INSERT INTO drive_file_group_access (file_id, group_id, permission, granted_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission)
            ');
            $stmt->execute([$fileId, $groupId, $permission, $ownerEmail]);

            return ['success' => true, 'group_id' => $groupId, 'permission' => $permission];
        } catch (\PDOException $e) {
            error_log('addFileGroupAccess error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }

    public function getGroupAccess(string $ownerEmail, int $fileId): array
    {
        try {
            if (!$this->getOwnedFile($ownerEmail, $fileId)) {
                return [];
            }
            $stmt = $this->db->prepare('
                SELECT ga.group_id, g.name as group_name, g.color as group_color,
                       g.icon as group_icon, ga.permission, ga.granted_by, ga.created_at,
                       (SELECT COUNT(*) FROM colleague_group_members WHERE group_id = ga.group_id) as member_count
                FROM drive_file_group_access ga
                JOIN colleague_groups g ON ga.group_id = g.id
                WHERE ga.file_id = ?
                ORDER BY g.name ASC
            ');
            $stmt->execute([$fileId]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log('getFileGroupAccess error: ' . $e->getMessage());
            return [];
        }
    }

    public function removeGroupAccess(string $ownerEmail, int $fileId, int $groupId): array
    {
        try {
            if (!$this->getOwnedFile($ownerEmail, $fileId)) {
                return ['success' => false, 'error' => 'File not found'];
            }
            $stmt = $this->db->prepare('DELETE FROM drive_file_group_access WHERE file_id = ? AND group_id = ?');
            $stmt->execute([$fileId, $groupId]);
            return ['success' => true];
        } catch (\Exception $e) {
            error_log('removeFileGroupAccess error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to remove group access'];
        }
    }

    // =========================================================================
    // Access resolution
    // =========================================================================

    /**
     * Resolve direct file-level access (person share or group share) for a user.
     * Does NOT include folder-tree collaborator access - callers combine this
     * with DriveService::hasFileCollaboratorAccess() for the full picture.
     *
     * @return array|false ['permission' => viewer|editor, 'owner_email' => ..., 'via' => 'user'|'group'] or false
     */
    public function resolveDirectFileAccess(string $email, int $fileId, ?string $requiredPermission = null)
    {
        $email = strtolower($email);

        try {
            // Direct person share
            $stmt = $this->db->prepare('
                SELECT c.permission, c.invited_by, f.user_email as owner_email
                FROM drive_file_collaborators c
                JOIN drive_files f ON f.id = c.file_id
                WHERE c.file_id = ? AND c.user_email = ?
            ');
            $stmt->execute([$fileId, $email]);
            $direct = $stmt->fetch();

            // Group share - take the highest permission across the user's groups
            $stmt = $this->db->prepare("
                SELECT ga.permission, ga.granted_by as invited_by, f.user_email as owner_email
                FROM drive_file_group_access ga
                JOIN colleague_group_members gm ON gm.group_id = ga.group_id
                JOIN organization_colleagues oc ON oc.id = gm.colleague_id
                JOIN drive_files f ON f.id = ga.file_id
                WHERE ga.file_id = ? AND LOWER(oc.email) = ?
                ORDER BY FIELD(ga.permission, 'editor', 'viewer')
                LIMIT 1
            ");
            $stmt->execute([$fileId, $email]);
            $viaGroup = $stmt->fetch();

            $access = false;
            if ($direct) {
                $access = ['permission' => $direct['permission'], 'owner_email' => $direct['owner_email'], 'invited_by' => $direct['invited_by'], 'via' => 'user'];
            }
            if ($viaGroup && (!$access || ($access['permission'] !== 'editor' && $viaGroup['permission'] === 'editor'))) {
                $access = ['permission' => $viaGroup['permission'], 'owner_email' => $viaGroup['owner_email'], 'invited_by' => $viaGroup['invited_by'], 'via' => 'group'];
            }

            if (!$access) {
                return false;
            }
            if ($requiredPermission === 'editor' && $access['permission'] !== 'editor') {
                return false;
            }
            return $access;
        } catch (\Exception $e) {
            error_log('resolveDirectFileAccess error: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Shared with me
    // =========================================================================

    /**
     * Files shared directly with a user (person shares + group shares),
     * deduplicated by file with editor permission winning. Trashed files
     * and files the user owns are excluded.
     */
    public function getFilesSharedWith(string $email): array
    {
        $email = strtolower($email);
        $byFileId = [];

        try {
            $stmt = $this->db->prepare("
                SELECT f.*, c.permission, c.invited_by, c.created_at as shared_at, 'user' as shared_via
                FROM drive_file_collaborators c
                JOIN drive_files f ON f.id = c.file_id
                WHERE c.user_email = ?
                  AND f.user_email != ?
                  AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ");
            $stmt->execute([$email, $email]);
            foreach ($stmt->fetchAll() as $row) {
                $byFileId[(int)$row['id']] = $row;
            }

            $stmt = $this->db->prepare("
                SELECT f.*, ga.permission, ga.granted_by as invited_by, ga.created_at as shared_at,
                       'group' as shared_via, g.name as group_name
                FROM drive_file_group_access ga
                JOIN colleague_groups g ON g.id = ga.group_id
                JOIN colleague_group_members gm ON gm.group_id = ga.group_id
                JOIN organization_colleagues oc ON oc.id = gm.colleague_id
                JOIN drive_files f ON f.id = ga.file_id
                WHERE LOWER(oc.email) = ?
                  AND f.user_email != ?
                  AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ");
            $stmt->execute([$email, $email]);
            foreach ($stmt->fetchAll() as $row) {
                $fileId = (int)$row['id'];
                $existing = $byFileId[$fileId] ?? null;
                if (!$existing || ($existing['permission'] !== 'editor' && $row['permission'] === 'editor')) {
                    $byFileId[$fileId] = $row;
                }
            }
        } catch (\Exception $e) {
            error_log('getFilesSharedWith error: ' . $e->getMessage());
        }

        $files = array_values($byFileId);
        usort($files, fn($a, $b) => strcmp($b['shared_at'] ?? '', $a['shared_at'] ?? ''));
        return $files;
    }
}
