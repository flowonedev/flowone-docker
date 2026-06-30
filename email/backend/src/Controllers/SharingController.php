<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;

/**
 * SharingController - Centralized sharing & access management API
 * 
 * Aggregates sharing data across Drive, Boards, Calendars, Moodboards, and Collab Docs.
 * Provides a unified view of "who has access to what" with management capabilities.
 */
class SharingController extends BaseController
{
    private ?\PDO $db = null;

    private function getDb(): \PDO
    {
        if ($this->db) return $this->db;

        $this->db = \Webmail\Core\Database::getConnection($this->config);
        return $this->db;
    }

    /**
     * GET /sharing/overview
     * Returns all sharing data for the current user in both directions.
     */
    public function overview(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = strtolower($this->getActiveEmail());
        $db = $this->getDb();

        $sharedByMe = [
            'drive_files' => $this->getMySharedFiles($db, $email),
            'drive_folders' => $this->getMySharedFolders($db, $email),
            'boards' => $this->getMySharedBoards($db, $email),
            'calendars' => $this->getMySharedCalendars($db, $email),
            'moodboards' => $this->getMySharedMoodboards($db, $email),
            'collab_docs' => $this->getMySharedCollabDocs($db, $email),
        ];

        $sharedWithMe = [
            'drive_files' => $this->getFilesSharedWithMe($db, $email),
            'drive_folders' => $this->getFoldersSharedWithMe($db, $email),
            'boards' => $this->getBoardsSharedWithMe($db, $email),
            'calendars' => $this->getCalendarsSharedWithMe($db, $email),
            'moodboards' => $this->getMoodboardsSharedWithMe($db, $email),
            'collab_docs' => $this->getCollabDocsSharedWithMe($db, $email),
        ];

        return Response::success([
            'shared_by_me' => $sharedByMe,
            'shared_with_me' => $sharedWithMe,
        ]);
    }

    /**
     * DELETE /sharing/revoke
     * Revoke a sharing permission.
     * Body: { type, id, target_email? }
     */
    public function revoke(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = strtolower($this->getActiveEmail());
        $type = $request->input('type', '');
        $id = (int)$request->input('id', 0);
        $targetEmail = $request->input('target_email', '');

        if (!$type || !$id) {
            return Response::error('type and id are required', 400);
        }

        $db = $this->getDb();

        try {
            switch ($type) {
                case 'drive_file_link':
                    return $this->revokeDriveFileLink($db, $email, $id);

                case 'drive_folder_link':
                    return $this->revokeDriveFolderLink($db, $email, $id);

                case 'drive_folder_collab':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeDriveFolderCollab($db, $email, $id, $targetEmail);

                case 'drive_file_collab':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeDriveFileCollab($db, $email, $id, $targetEmail);

                case 'board_member':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeBoardMember($db, $email, $id, $targetEmail);

                case 'calendar_share':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeCalendarShare($db, $email, $id, $targetEmail);

                case 'mood_member':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeMoodMember($db, $email, $id, $targetEmail);

                case 'collab_perm':
                    if (!$targetEmail) return Response::error('target_email is required', 400);
                    return $this->revokeCollabPerm($db, $email, $id, $targetEmail);

                default:
                    return Response::error('Invalid type', 400);
            }
        } catch (\Throwable $e) {
            error_log("[SharingController] revoke error: " . $e->getMessage());
            return Response::error('Failed to revoke access', 500);
        }
    }

    /**
     * PUT /sharing/update-role
     * Update a sharing role/permission.
     * Body: { type, id, target_email, new_role }
     */
    public function updateRole(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = strtolower($this->getActiveEmail());
        $type = $request->input('type', '');
        $id = (int)$request->input('id', 0);
        $targetEmail = strtolower($request->input('target_email', ''));
        $newRole = $request->input('new_role', '');

        if (!$type || !$id || !$targetEmail || !$newRole) {
            return Response::error('type, id, target_email, and new_role are required', 400);
        }

        $db = $this->getDb();

        try {
            switch ($type) {
                case 'drive_folder_collab':
                    $allowed = ['viewer', 'editor'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE drive_folder_collaborators SET permission = ?
                        WHERE folder_id = ? AND LOWER(user_email) = ?
                        AND folder_id IN (SELECT id FROM drive_folders WHERE LOWER(user_email) = ?)
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                case 'drive_file_collab':
                    $allowed = ['viewer', 'editor'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE drive_file_collaborators SET permission = ?
                        WHERE file_id = ? AND LOWER(user_email) = ?
                        AND file_id IN (SELECT id FROM drive_files WHERE LOWER(user_email) = ?)
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                case 'board_member':
                    $allowed = ['viewer', 'editor', 'admin'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE webmail_board_members SET role = ?
                        WHERE board_id = ? AND LOWER(user_email) = ?
                        AND board_id IN (SELECT id FROM webmail_boards WHERE LOWER(owner_email) = ?)
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                case 'calendar_share':
                    $allowed = ['view', 'edit'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE calendar_shares SET permission = ?
                        WHERE calendar_id = ? AND LOWER(shared_with_email) = ?
                        AND LOWER(owner_email) = ?
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                case 'mood_member':
                    $allowed = ['viewer', 'editor', 'admin'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE mood_board_members SET role = ?
                        WHERE board_id = ? AND LOWER(email) = ?
                        AND board_id IN (SELECT id FROM mood_boards WHERE LOWER(owner_email) = ?)
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                case 'collab_perm':
                    $allowed = ['viewer', 'editor'];
                    if (!in_array($newRole, $allowed)) return Response::error('Invalid role', 400);
                    $stmt = $db->prepare('
                        UPDATE collab_permissions SET role = ?
                        WHERE document_id = ? AND LOWER(user_email) = ?
                        AND document_id IN (SELECT id FROM collab_documents WHERE LOWER(owner_email) = ?)
                    ');
                    $stmt->execute([$newRole, $id, $targetEmail, $email]);
                    break;

                default:
                    return Response::error('Invalid type', 400);
            }

            return Response::success(null, 'Role updated');
        } catch (\Throwable $e) {
            error_log("[SharingController] updateRole error: " . $e->getMessage());
            return Response::error('Failed to update role', 500);
        }
    }

    // ========================================
    // SHARED BY ME - Data fetchers
    // ========================================

    private function getMySharedFiles(\PDO $db, string $email): array
    {
        try {
            // Files with public share links
            $stmt = $db->prepare('
                SELECT id, original_name as name, mime_type, size, share_token, share_expires as expires,
                       download_count, max_downloads, is_email_attachment, created_at
                FROM drive_files
                WHERE LOWER(user_email) = ? AND share_token IS NOT NULL
                ORDER BY created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email]);
            $linkFiles = $stmt->fetchAll();

            // Files shared with specific people
            $stmt2 = $db->prepare('
                SELECT f.id, f.original_name as name, f.mime_type, f.size, f.created_at,
                       c.user_email as collab_email, c.permission as collab_permission
                FROM drive_files f
                INNER JOIN drive_file_collaborators c ON c.file_id = f.id
                WHERE LOWER(f.user_email) = ?
                ORDER BY f.id, c.user_email
                LIMIT 500
            ');
            $stmt2->execute([$email]);

            $collabMap = [];
            foreach ($stmt2->fetchAll() as $row) {
                $fid = $row['id'];
                if (!isset($collabMap[$fid])) {
                    $collabMap[$fid] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'mime_type' => $row['mime_type'],
                        'size' => $row['size'],
                        'created_at' => $row['created_at'],
                        'collaborators' => [],
                    ];
                }
                $collabMap[$fid]['collaborators'][] = [
                    'email' => $row['collab_email'],
                    'permission' => $row['collab_permission'],
                ];
            }

            $merged = [];
            $seenIds = [];
            foreach ($linkFiles as $f) {
                $f['collaborators'] = $collabMap[$f['id']]['collaborators'] ?? [];
                $merged[] = $f;
                $seenIds[$f['id']] = true;
            }
            foreach ($collabMap as $fid => $f) {
                if (!isset($seenIds[$fid])) {
                    $f['share_token'] = null;
                    $f['expires'] = null;
                    $f['download_count'] = null;
                    $f['max_downloads'] = null;
                    $merged[] = $f;
                }
            }

            return $merged;
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedFiles error: " . $e->getMessage());
            return [];
        }
    }

    private function getMySharedFolders(\PDO $db, string $email): array
    {
        try {
            // Folders with public share links
            $stmt = $db->prepare('
                SELECT f.id, f.name, f.share_token, f.share_expires as expires,
                       f.download_count, f.max_downloads, f.created_at
                FROM drive_folders f
                WHERE LOWER(f.user_email) = ? AND f.share_token IS NOT NULL
                ORDER BY f.created_at DESC
                LIMIT 100
            ');
            $stmt->execute([$email]);
            $sharedFolders = $stmt->fetchAll();

            // Folders with collaborators
            $stmt2 = $db->prepare('
                SELECT f.id, f.name, f.created_at,
                       c.user_email as collab_email, c.permission as collab_permission
                FROM drive_folders f
                INNER JOIN drive_folder_collaborators c ON c.folder_id = f.id
                WHERE LOWER(f.user_email) = ?
                ORDER BY f.id, c.user_email
                LIMIT 500
            ');
            $stmt2->execute([$email]);
            $collabRows = $stmt2->fetchAll();

            // Group collaborators by folder
            $collabMap = [];
            foreach ($collabRows as $row) {
                $fid = $row['id'];
                if (!isset($collabMap[$fid])) {
                    $collabMap[$fid] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'created_at' => $row['created_at'],
                        'collaborators' => [],
                    ];
                }
                $collabMap[$fid]['collaborators'][] = [
                    'email' => $row['collab_email'],
                    'permission' => $row['collab_permission'],
                ];
            }

            // Merge: add collaborators to shared folders, or add collab-only folders
            $merged = [];
            $seenIds = [];
            foreach ($sharedFolders as $f) {
                $f['collaborators'] = $collabMap[$f['id']]['collaborators'] ?? [];
                $merged[] = $f;
                $seenIds[$f['id']] = true;
            }
            foreach ($collabMap as $fid => $f) {
                if (!isset($seenIds[$fid])) {
                    $f['share_token'] = null;
                    $f['expires'] = null;
                    $f['download_count'] = null;
                    $f['max_downloads'] = null;
                    $merged[] = $f;
                }
            }

            return $merged;
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedFolders error: " . $e->getMessage());
            return [];
        }
    }

    private function getMySharedBoards(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT b.id, b.name, b.created_at,
                       m.user_email as member_email, m.role as member_role
                FROM webmail_boards b
                INNER JOIN webmail_board_members m ON m.board_id = b.id
                WHERE LOWER(b.owner_email) = ?
                ORDER BY b.id, m.user_email
                LIMIT 500
            ');
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll();

            $boards = [];
            foreach ($rows as $row) {
                $bid = $row['id'];
                if (!isset($boards[$bid])) {
                    $boards[$bid] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'created_at' => $row['created_at'],
                        'members' => [],
                    ];
                }
                $boards[$bid]['members'][] = [
                    'email' => $row['member_email'],
                    'role' => $row['member_role'],
                ];
            }
            return array_values($boards);
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedBoards error: " . $e->getMessage());
            return [];
        }
    }

    private function getMySharedCalendars(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT c.id, c.name, c.color, cs.shared_with_email, cs.permission,
                       cs.can_see_details, cs.created_at as shared_at
                FROM calendars c
                INNER JOIN calendar_shares cs ON cs.calendar_id = c.id
                WHERE LOWER(cs.owner_email) = ? AND cs.shared_with_email IS NOT NULL
                ORDER BY c.id, cs.shared_with_email
                LIMIT 200
            ');
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll();

            $calendars = [];
            foreach ($rows as $row) {
                $cid = $row['id'];
                if (!isset($calendars[$cid])) {
                    $calendars[$cid] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'color' => $row['color'],
                        'shared_with' => [],
                    ];
                }
                $calendars[$cid]['shared_with'][] = [
                    'email' => $row['shared_with_email'],
                    'permission' => $row['permission'],
                    'can_see_details' => (bool)$row['can_see_details'],
                ];
            }
            return array_values($calendars);
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedCalendars error: " . $e->getMessage());
            return [];
        }
    }

    private function getMySharedMoodboards(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT mb.id, mb.name, mb.share_token, mb.share_mode, mb.share_expires,
                       mm.email as member_email, mm.role as member_role
                FROM mood_boards mb
                LEFT JOIN mood_board_members mm ON mm.board_id = mb.id
                WHERE LOWER(mb.owner_email) = ?
                  AND (mb.share_token IS NOT NULL OR mm.id IS NOT NULL)
                ORDER BY mb.id, mm.email
                LIMIT 500
            ');
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll();

            $boards = [];
            foreach ($rows as $row) {
                $bid = $row['id'];
                if (!isset($boards[$bid])) {
                    $boards[$bid] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'has_public_link' => !empty($row['share_token']),
                        'share_mode' => $row['share_mode'],
                        'share_expires' => $row['share_expires'],
                        'members' => [],
                    ];
                }
                if ($row['member_email']) {
                    $boards[$bid]['members'][] = [
                        'email' => $row['member_email'],
                        'role' => $row['member_role'],
                    ];
                }
            }
            return array_values($boards);
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedMoodboards error: " . $e->getMessage());
            return [];
        }
    }

    private function getMySharedCollabDocs(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT d.id, d.title, d.created_at,
                       p.user_email as shared_email, p.role as shared_role
                FROM collab_documents d
                INNER JOIN collab_permissions p ON p.document_id = d.id
                WHERE LOWER(d.owner_email) = ? AND d.deleted_at IS NULL
                  AND LOWER(p.user_email) != ?
                ORDER BY d.id, p.user_email
                LIMIT 500
            ');
            $stmt->execute([$email, $email]);
            $rows = $stmt->fetchAll();

            $docs = [];
            foreach ($rows as $row) {
                $did = $row['id'];
                if (!isset($docs[$did])) {
                    $docs[$did] = [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'created_at' => $row['created_at'],
                        'shared_with' => [],
                    ];
                }
                $docs[$did]['shared_with'][] = [
                    'email' => $row['shared_email'],
                    'role' => $row['shared_role'],
                ];
            }
            return array_values($docs);
        } catch (\Throwable $e) {
            error_log("[SharingController] getMySharedCollabDocs error: " . $e->getMessage());
            return [];
        }
    }

    // ========================================
    // SHARED WITH ME - Data fetchers
    // ========================================

    private function getFilesSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT f.id, f.original_name as name, f.mime_type, f.size, f.user_email as owner,
                       c.permission as my_permission, c.created_at as shared_at
                FROM drive_file_collaborators c
                INNER JOIN drive_files f ON f.id = c.file_id
                WHERE LOWER(c.user_email) = ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
                ORDER BY c.created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getFilesSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    private function getFoldersSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT f.id, f.name, f.user_email as owner, c.permission as my_permission, c.created_at as shared_at
                FROM drive_folder_collaborators c
                INNER JOIN drive_folders f ON f.id = c.folder_id
                WHERE LOWER(c.user_email) = ?
                ORDER BY c.created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getFoldersSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    private function getBoardsSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT b.id, b.name, b.owner_email as owner, m.role as my_role, m.created_at as shared_at
                FROM webmail_board_members m
                INNER JOIN webmail_boards b ON b.id = m.board_id
                WHERE LOWER(m.user_email) = ? AND LOWER(b.owner_email) != ?
                ORDER BY m.created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email, $email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getBoardsSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    private function getCalendarsSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT c.id, c.name, c.color, cs.owner_email as owner,
                       cs.permission as my_permission, cs.can_see_details, cs.created_at as shared_at
                FROM calendar_shares cs
                INNER JOIN calendars c ON c.id = cs.calendar_id
                WHERE LOWER(cs.shared_with_email) = ?
                ORDER BY cs.created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getCalendarsSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    private function getMoodboardsSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT mb.id, mb.name, mb.owner_email as owner,
                       mm.role as my_role, mm.added_at as shared_at
                FROM mood_board_members mm
                INNER JOIN mood_boards mb ON mb.id = mm.board_id
                WHERE LOWER(mm.email) = ? AND LOWER(mb.owner_email) != ?
                ORDER BY mm.added_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email, $email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getMoodboardsSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    private function getCollabDocsSharedWithMe(\PDO $db, string $email): array
    {
        try {
            $stmt = $db->prepare('
                SELECT d.id, d.title, d.owner_email as owner,
                       p.role as my_role, p.created_at as shared_at
                FROM collab_permissions p
                INNER JOIN collab_documents d ON d.id = p.document_id
                WHERE LOWER(p.user_email) = ? AND LOWER(d.owner_email) != ?
                  AND d.deleted_at IS NULL
                ORDER BY p.created_at DESC
                LIMIT 200
            ');
            $stmt->execute([$email, $email]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log("[SharingController] getCollabDocsSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }

    // ========================================
    // REVOKE helpers
    // ========================================

    private function revokeDriveFileLink(\PDO $db, string $email, int $fileId): Response
    {
        $stmt = $db->prepare('
            UPDATE drive_files SET share_token = NULL, share_expires = NULL, download_count = 0, max_downloads = NULL
            WHERE id = ? AND LOWER(user_email) = ?
        ');
        $stmt->execute([$fileId, $email]);
        return $stmt->rowCount() > 0
            ? Response::success(null, 'Share link removed')
            : Response::error('File not found or not owned by you', 404);
    }

    private function revokeDriveFolderLink(\PDO $db, string $email, int $folderId): Response
    {
        $stmt = $db->prepare('
            UPDATE drive_folders SET share_token = NULL, share_expires = NULL, download_count = 0, max_downloads = NULL
            WHERE id = ? AND LOWER(user_email) = ?
        ');
        $stmt->execute([$folderId, $email]);
        return $stmt->rowCount() > 0
            ? Response::success(null, 'Folder share link removed')
            : Response::error('Folder not found or not owned by you', 404);
    }

    private function revokeDriveFolderCollab(\PDO $db, string $email, int $folderId, string $targetEmail): Response
    {
        // Verify ownership
        $check = $db->prepare('SELECT id FROM drive_folders WHERE id = ? AND LOWER(user_email) = ?');
        $check->execute([$folderId, $email]);
        if (!$check->fetch()) {
            return Response::error('Folder not found or not owned by you', 404);
        }

        $stmt = $db->prepare('DELETE FROM drive_folder_collaborators WHERE folder_id = ? AND LOWER(user_email) = ?');
        $stmt->execute([$folderId, strtolower($targetEmail)]);
        return Response::success(null, 'Collaborator removed');
    }

    private function revokeDriveFileCollab(\PDO $db, string $email, int $fileId, string $targetEmail): Response
    {
        // Verify ownership
        $check = $db->prepare('SELECT id FROM drive_files WHERE id = ? AND LOWER(user_email) = ?');
        $check->execute([$fileId, $email]);
        if (!$check->fetch()) {
            return Response::error('File not found or not owned by you', 404);
        }

        $stmt = $db->prepare('DELETE FROM drive_file_collaborators WHERE file_id = ? AND LOWER(user_email) = ?');
        $stmt->execute([$fileId, strtolower($targetEmail)]);
        return Response::success(null, 'Collaborator removed');
    }

    private function revokeBoardMember(\PDO $db, string $email, int $boardId, string $targetEmail): Response
    {
        $check = $db->prepare('SELECT id FROM webmail_boards WHERE id = ? AND LOWER(owner_email) = ?');
        $check->execute([$boardId, $email]);
        if (!$check->fetch()) {
            return Response::error('Board not found or not owned by you', 404);
        }

        $stmt = $db->prepare('DELETE FROM webmail_board_members WHERE board_id = ? AND LOWER(user_email) = ?');
        $stmt->execute([$boardId, strtolower($targetEmail)]);
        return Response::success(null, 'Member removed');
    }

    private function revokeCalendarShare(\PDO $db, string $email, int $calendarId, string $targetEmail): Response
    {
        $stmt = $db->prepare('
            DELETE FROM calendar_shares
            WHERE calendar_id = ? AND LOWER(owner_email) = ? AND LOWER(shared_with_email) = ?
        ');
        $stmt->execute([$calendarId, $email, strtolower($targetEmail)]);
        return $stmt->rowCount() > 0
            ? Response::success(null, 'Calendar share removed')
            : Response::error('Share not found', 404);
    }

    private function revokeMoodMember(\PDO $db, string $email, int $boardId, string $targetEmail): Response
    {
        $check = $db->prepare('SELECT id FROM mood_boards WHERE id = ? AND LOWER(owner_email) = ?');
        $check->execute([$boardId, $email]);
        if (!$check->fetch()) {
            return Response::error('Moodboard not found or not owned by you', 404);
        }

        $stmt = $db->prepare('DELETE FROM mood_board_members WHERE board_id = ? AND LOWER(email) = ?');
        $stmt->execute([$boardId, strtolower($targetEmail)]);
        return Response::success(null, 'Member removed');
    }

    private function revokeCollabPerm(\PDO $db, string $email, int $docId, string $targetEmail): Response
    {
        $check = $db->prepare('SELECT id FROM collab_documents WHERE id = ? AND LOWER(owner_email) = ?');
        $check->execute([$docId, $email]);
        if (!$check->fetch()) {
            return Response::error('Document not found or not owned by you', 404);
        }

        $stmt = $db->prepare('DELETE FROM collab_permissions WHERE document_id = ? AND LOWER(user_email) = ?');
        $stmt->execute([$docId, strtolower($targetEmail)]);
        return Response::success(null, 'Permission removed');
    }
}

