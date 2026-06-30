<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubFileService
{
    private PDO $db;
    private array $config;
    private string $logFile;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/project-hub.log';
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] [FileService] $message\n", FILE_APPEND);
    }

    // =========================================================================
    // Folder Files
    // =========================================================================

    public function listFolderFiles(int $folderId, string $userEmail): array
    {
        $lastSeen = $this->getLastSeenAt($folderId, $userEmail);

        $stmt = $this->db->prepare("
            SELECT
                pff.id,
                pff.folder_id,
                pff.drive_file_id,
                pff.group_name,
                pff.added_by,
                pff.created_at,
                df.original_name,
                df.mime_type,
                df.size,
                df.filename AS storage_filename
            FROM projecthub_folder_files pff
            JOIN drive_files df ON df.id = pff.drive_file_id
            WHERE pff.folder_id = ?
              AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
            ORDER BY pff.created_at DESC
        ");
        $stmt->execute([$folderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) use ($lastSeen) {
            $row['unseen'] = $lastSeen ? ($row['created_at'] > $lastSeen) : true;
            return $row;
        }, $rows);
    }

    public function addFileToFolder(int $folderId, int $driveFileId, string $groupName, string $userEmail): ?array
    {
        $existing = $this->db->prepare("
            SELECT id FROM projecthub_folder_files
            WHERE folder_id = ? AND drive_file_id = ?
        ");
        $existing->execute([$folderId, $driveFileId]);
        if ($existing->fetch()) {
            $this->log("File $driveFileId already linked to folder $folderId");
            return null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO projecthub_folder_files (folder_id, drive_file_id, group_name, added_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$folderId, $driveFileId, $groupName, $userEmail]);
        $id = (int)$this->db->lastInsertId();

        return $this->getFolderFile($id);
    }

    public function getFolderFile(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                pff.id, pff.folder_id, pff.drive_file_id, pff.group_name,
                pff.added_by, pff.created_at,
                df.original_name, df.mime_type, df.size
            FROM projecthub_folder_files pff
            JOIN drive_files df ON df.id = pff.drive_file_id
            WHERE pff.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateFileGroup(int $id, string $groupName): bool
    {
        $stmt = $this->db->prepare("
            UPDATE projecthub_folder_files SET group_name = ? WHERE id = ?
        ");
        return $stmt->execute([$groupName, $id]);
    }

    public function batchUpdateGroup(array $ids, string $groupName): int
    {
        if (empty($ids)) return 0;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$groupName], array_map('intval', $ids));

        $stmt = $this->db->prepare("
            UPDATE projecthub_folder_files
            SET group_name = ?
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function removeFile(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_folder_files WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getFileGroups(int $folderId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT group_name, COUNT(*) as count
            FROM projecthub_folder_files
            WHERE folder_id = ?
            GROUP BY group_name
            ORDER BY group_name
        ");
        $stmt->execute([$folderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDriveFileIdsForExport(int $folderId, ?string $groupName = null): array
    {
        $sql = "
            SELECT pff.drive_file_id
            FROM projecthub_folder_files pff
            JOIN drive_files df ON df.id = pff.drive_file_id
            WHERE pff.folder_id = ?
              AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
        ";
        $params = [$folderId];

        if ($groupName) {
            $sql .= " AND pff.group_name = ?";
            $params[] = $groupName;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'drive_file_id');
    }

    // =========================================================================
    // Unseen Tracking
    // =========================================================================

    public function markSeen(int $folderId, string $userEmail): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO projecthub_folder_file_views (folder_id, user_email, last_seen_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_seen_at = NOW()
        ");
        $stmt->execute([$folderId, $userEmail]);
    }

    public function getUnseenCount(int $folderId, string $userEmail): int
    {
        $lastSeen = $this->getLastSeenAt($folderId, $userEmail);

        if (!$lastSeen) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM projecthub_folder_files WHERE folder_id = ?
            ");
            $stmt->execute([$folderId]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM projecthub_folder_files pff
            JOIN drive_files df ON df.id = pff.drive_file_id
            WHERE pff.folder_id = ?
              AND pff.created_at > ?
              AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
        ");
        $stmt->execute([$folderId, $lastSeen]);
        return (int)$stmt->fetchColumn();
    }

    public function getUnseenCountsBatch(array $folderIds, string $userEmail): array
    {
        if (empty($folderIds)) return [];

        $result = [];
        foreach ($folderIds as $fid) {
            $result[(int)$fid] = $this->getUnseenCount((int)$fid, $userEmail);
        }
        return $result;
    }

    private function getLastSeenAt(int $folderId, string $userEmail): ?string
    {
        $stmt = $this->db->prepare("
            SELECT last_seen_at FROM projecthub_folder_file_views
            WHERE folder_id = ? AND user_email = ?
        ");
        $stmt->execute([$folderId, $userEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['last_seen_at'] : null;
    }

    // =========================================================================
    // Folder Links
    // =========================================================================

    public function listFolderLinks(int $folderId, string $userEmail): array
    {
        $lastSeen = $this->getLastSeenAt($folderId, $userEmail);

        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_folder_links
            WHERE folder_id = ?
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([$folderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) use ($lastSeen) {
            $row['unseen'] = $lastSeen ? ($row['created_at'] > $lastSeen) : true;
            return $row;
        }, $rows);
    }

    public function addLink(int $folderId, array $data, string $userEmail): array
    {
        $maxSort = $this->db->prepare("
            SELECT COALESCE(MAX(sort_order), 0) FROM projecthub_folder_links WHERE folder_id = ?
        ");
        $maxSort->execute([$folderId]);
        $nextSort = (int)$maxSort->fetchColumn() + 1;

        $stmt = $this->db->prepare("
            INSERT INTO projecthub_folder_links (folder_id, title, url, link_type, group_name, added_by, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $folderId,
            $data['title'],
            $data['url'],
            $data['link_type'] ?? 'url',
            $data['group_name'] ?? null,
            $userEmail,
            $nextSort,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->getLink($id);
    }

    public function getLink(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_folder_links WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateLink(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['title', 'url', 'link_type', 'group_name', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) return false;

        $params[] = $id;
        $sql = "UPDATE projecthub_folder_links SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteLink(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_folder_links WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
