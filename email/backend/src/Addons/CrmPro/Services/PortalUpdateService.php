<?php

namespace Webmail\Addons\CrmPro\Services;

/**
 * PortalUpdateService - Portal Updates, Comments, Files & Read Tracking
 * 
 * Handles the full lifecycle of client portal updates:
 * - Internal users push updates to clients (design reviews, milestones, general announcements)
 * - Portal users view updates, mark them as read, and leave comments
 * - Internal users respond to comments
 * - Files can be attached to updates (linked to Drive or uploaded directly)
 * - Threaded comment system with author types (internal vs portal)
 * 
 * Data isolation: portal users only see updates for their own client_id.
 */
class PortalUpdateService
{
    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Updates CRUD (Internal User Actions)
    // =========================================================================

    /**
     * Create a new portal update for a client.
     *
     * @param int $clientId
     * @param string $createdBy Internal user email
     * @param array $data [title, content_html, content_text, update_type, mood_board_id, mood_board_share_token, drive_file_ids, board_id, board_card_id, is_pinned]
     * @return array The created update
     */
    public function createUpdate(int $clientId, string $createdBy, array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO portal_updates 
                (client_id, created_by, title, content_html, content_text, update_type, 
                 mood_board_id, mood_board_share_token, drive_file_ids, board_id, board_card_id, is_pinned)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $driveFileIds = isset($data['drive_file_ids']) && is_array($data['drive_file_ids'])
            ? json_encode($data['drive_file_ids'])
            : null;

        $stmt->execute([
            $clientId,
            $createdBy,
            $data['title'] ?? 'Untitled Update',
            $data['content_html'] ?? null,
            $data['content_text'] ?? null,
            $data['update_type'] ?? 'general',
            $data['mood_board_id'] ?? null,
            $data['mood_board_share_token'] ?? null,
            $driveFileIds,
            $data['board_id'] ?? null,
            $data['board_card_id'] ?? null,
            $data['is_pinned'] ?? 0
        ]);

        $updateId = (int)$this->db->lastInsertId();
        return $this->getUpdateById($updateId);
    }

    /**
     * Get a single update by ID.
     */
    public function getUpdateById(int $updateId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM portal_updates WHERE id = ?");
        $stmt->execute([$updateId]);
        $update = $stmt->fetch();
        if (!$update) return null;

        $update['drive_file_ids'] = $update['drive_file_ids'] ? json_decode($update['drive_file_ids'], true) : [];
        $update['files'] = $this->getUpdateFiles($updateId);
        $update['comment_count'] = $this->getCommentCount($updateId);
        return $update;
    }

    /**
     * List updates for a client (internal view - sees all).
     */
    public function listUpdatesForClient(int $clientId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        // Total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM portal_updates WHERE client_id = ?");
        $countStmt->execute([$clientId]);
        $total = (int)$countStmt->fetchColumn();

        // Fetch updates
        $stmt = $this->db->prepare("
            SELECT * FROM portal_updates 
            WHERE client_id = ? 
            ORDER BY is_pinned DESC, created_at DESC 
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([$clientId]);
        $updates = $stmt->fetchAll();

        foreach ($updates as &$update) {
            $update['drive_file_ids'] = $update['drive_file_ids'] ? json_decode($update['drive_file_ids'], true) : [];
            $update['files'] = $this->getUpdateFiles((int)$update['id']);
            $update['comment_count'] = $this->getCommentCount((int)$update['id']);
        }

        return [
            'items' => $updates,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage)
        ];
    }

    /**
     * List updates for a portal user (filtered by their client_id).
     * Includes read status for this portal access.
     */
    public function listUpdatesForPortal(int $clientId, int $portalAccessId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM portal_updates WHERE client_id = ?");
        $countStmt->execute([$clientId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT pu.*, 
                   pur.read_at,
                   CASE WHEN pur.id IS NOT NULL THEN 1 ELSE 0 END AS is_read
            FROM portal_updates pu
            LEFT JOIN portal_update_reads pur ON pur.update_id = pu.id AND pur.portal_access_id = ?
            WHERE pu.client_id = ?
            ORDER BY pu.is_pinned DESC, pu.created_at DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([$portalAccessId, $clientId]);
        $updates = $stmt->fetchAll();

        foreach ($updates as &$update) {
            $update['drive_file_ids'] = $update['drive_file_ids'] ? json_decode($update['drive_file_ids'], true) : [];
            $update['files'] = $this->getUpdateFiles((int)$update['id']);
            $update['comment_count'] = $this->getCommentCount((int)$update['id']);
        }

        return [
            'items' => $updates,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
            'unread_count' => $this->getUnreadCount($clientId, $portalAccessId)
        ];
    }

    /**
     * Get a single update for a portal user (with read status).
     */
    public function getUpdateForPortal(int $updateId, int $clientId, int $portalAccessId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pu.*,
                   pur.read_at,
                   CASE WHEN pur.id IS NOT NULL THEN 1 ELSE 0 END AS is_read
            FROM portal_updates pu
            LEFT JOIN portal_update_reads pur ON pur.update_id = pu.id AND pur.portal_access_id = ?
            WHERE pu.id = ? AND pu.client_id = ?
        ");
        $stmt->execute([$portalAccessId, $updateId, $clientId]);
        $update = $stmt->fetch();
        if (!$update) return null;

        $update['drive_file_ids'] = $update['drive_file_ids'] ? json_decode($update['drive_file_ids'], true) : [];
        $update['files'] = $this->getUpdateFiles($updateId);
        $update['comments'] = $this->getComments($updateId);
        return $update;
    }

    /**
     * Delete an update (internal user only).
     */
    public function deleteUpdate(int $updateId, int $clientId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM portal_updates WHERE id = ? AND client_id = ?");
        $stmt->execute([$updateId, $clientId]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Read Tracking
    // =========================================================================

    /**
     * Mark an update as read for a portal user.
     */
    public function markAsRead(int $updateId, int $portalAccessId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO portal_update_reads (update_id, portal_access_id, read_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ");
            $stmt->execute([$updateId, $portalAccessId]);
            return true;
        } catch (\PDOException $e) {
            error_log("PortalUpdateService: Error marking read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread count for a portal user.
     */
    public function getUnreadCount(int $clientId, int $portalAccessId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM portal_updates pu
            LEFT JOIN portal_update_reads pur ON pur.update_id = pu.id AND pur.portal_access_id = ?
            WHERE pu.client_id = ? AND pur.id IS NULL
        ");
        $stmt->execute([$portalAccessId, $clientId]);
        return (int)$stmt->fetchColumn();
    }

    // =========================================================================
    // Comments (threaded)
    // =========================================================================

    /**
     * Add a comment to an update.
     *
     * @param int $updateId
     * @param string $authorType 'internal' or 'portal'
     * @param string $authorEmail
     * @param string|null $authorName
     * @param string $contentText
     * @param int|null $parentCommentId For threaded replies
     * @return array The created comment
     */
    public function addComment(
        int $updateId,
        string $authorType,
        string $authorEmail,
        ?string $authorName,
        string $contentText,
        ?int $parentCommentId = null
    ): array {
        // Validate parent belongs to same update if provided
        if ($parentCommentId !== null) {
            $parentStmt = $this->db->prepare("SELECT id FROM portal_comments WHERE id = ? AND update_id = ?");
            $parentStmt->execute([$parentCommentId, $updateId]);
            if (!$parentStmt->fetch()) {
                throw new \InvalidArgumentException('Parent comment not found or belongs to different update');
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO portal_comments (update_id, author_type, author_email, author_name, content_text, parent_comment_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$updateId, $authorType, $authorEmail, $authorName, $contentText, $parentCommentId]);

        $commentId = (int)$this->db->lastInsertId();
        return $this->getCommentById($commentId);
    }

    /**
     * Get all comments for an update (flat list, ordered chronologically).
     * Frontend handles threading by parent_comment_id.
     */
    public function getComments(int $updateId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM portal_comments 
            WHERE update_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$updateId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single comment.
     */
    public function getCommentById(int $commentId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM portal_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get comment count for an update.
     */
    public function getCommentCount(int $updateId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM portal_comments WHERE update_id = ?");
        $stmt->execute([$updateId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete a comment (only by author or internal admin).
     */
    public function deleteComment(int $commentId, string $requesterEmail, bool $isInternal): bool
    {
        // Internal users can delete any comment; portal users can only delete their own
        if ($isInternal) {
            $stmt = $this->db->prepare("DELETE FROM portal_comments WHERE id = ?");
            $stmt->execute([$commentId]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM portal_comments WHERE id = ? AND author_email = ? AND author_type = 'portal'");
            $stmt->execute([$commentId, $requesterEmail]);
        }
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Update Files
    // =========================================================================

    /**
     * Attach files to an update.
     *
     * @param int $updateId
     * @param array $files Array of [filename, original_name, mime_type, file_size, drive_file_id]
     * @return array The attached files
     */
    public function attachFiles(int $updateId, array $files): array
    {
        $attachedFiles = [];
        foreach ($files as $file) {
            $stmt = $this->db->prepare("
                INSERT INTO portal_update_files (update_id, filename, original_name, mime_type, file_size, drive_file_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $updateId,
                $file['filename'] ?? '',
                $file['original_name'] ?? $file['filename'] ?? 'unknown',
                $file['mime_type'] ?? null,
                $file['file_size'] ?? 0,
                $file['drive_file_id'] ?? null
            ]);
            $attachedFiles[] = $this->getFileById((int)$this->db->lastInsertId());
        }
        return $attachedFiles;
    }

    /**
     * Get all files for an update.
     */
    public function getUpdateFiles(int $updateId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM portal_update_files WHERE update_id = ? ORDER BY created_at ASC");
        $stmt->execute([$updateId]);
        return $stmt->fetchAll();
    }

    /**
     * Get a single file.
     */
    public function getFileById(int $fileId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM portal_update_files WHERE id = ?");
        $stmt->execute([$fileId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get a file with its update (for access validation).
     */
    public function getFileWithUpdate(int $fileId, int $updateId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT puf.*, pu.client_id 
            FROM portal_update_files puf
            JOIN portal_updates pu ON puf.update_id = pu.id
            WHERE puf.id = ? AND puf.update_id = ?
        ");
        $stmt->execute([$fileId, $updateId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete a file from an update.
     */
    public function deleteFile(int $fileId, int $updateId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM portal_update_files WHERE id = ? AND update_id = ?");
        $stmt->execute([$fileId, $updateId]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Read status for internal users (who's read this update?)
    // =========================================================================

    /**
     * Get read receipts for an update (internal view).
     */
    public function getReadReceipts(int $updateId): array
    {
        $stmt = $this->db->prepare("
            SELECT pur.*, pa.email, pa.name
            FROM portal_update_reads pur
            JOIN portal_access pa ON pur.portal_access_id = pa.id
            WHERE pur.update_id = ?
            ORDER BY pur.read_at DESC
        ");
        $stmt->execute([$updateId]);
        return $stmt->fetchAll();
    }

    /**
     * Validate that an update belongs to a given client.
     */
    public function validateUpdateBelongsToClient(int $updateId, int $clientId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM portal_updates WHERE id = ? AND client_id = ?");
        $stmt->execute([$updateId, $clientId]);
        return (bool)$stmt->fetch();
    }
}

