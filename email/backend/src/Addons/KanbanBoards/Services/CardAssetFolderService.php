<?php

namespace Webmail\Addons\KanbanBoards\Services;

use PDO;

class CardAssetFolderService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function getFolders(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM card_asset_folders
            WHERE card_id = ?
            ORDER BY position ASC, name ASC
        ");
        $stmt->execute([$cardId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['card_id'] = (int)$r['card_id'];
            $r['parent_id'] = $r['parent_id'] ? (int)$r['parent_id'] : null;
            $r['drive_folder_id'] = $r['drive_folder_id'] ? (int)$r['drive_folder_id'] : null;
            $r['position'] = (int)$r['position'];
        }
        return $rows;
    }

    public function createFolder(int $cardId, string $name, ?int $parentId, string $createdBy): ?array
    {
        if ($parentId) {
            $check = $this->db->prepare("SELECT id FROM card_asset_folders WHERE id = ? AND card_id = ?");
            $check->execute([$parentId, $cardId]);
            if (!$check->fetch()) return null;
        }

        $stmt = $this->db->prepare("
            INSERT INTO card_asset_folders (card_id, parent_id, name, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$cardId, $parentId, trim($name), strtolower($createdBy)]);
        $id = (int)$this->db->lastInsertId();

        return $this->getById($id);
    }

    public function renameFolder(int $folderId, string $name): bool
    {
        $stmt = $this->db->prepare("UPDATE card_asset_folders SET name = ? WHERE id = ?");
        return $stmt->execute([trim($name), $folderId]);
    }

    public function deleteFolder(int $folderId): bool
    {
        $this->db->prepare("UPDATE webmail_card_attachments SET folder_id = NULL WHERE folder_id = ?")
            ->execute([$folderId]);

        $children = $this->db->prepare("SELECT id FROM card_asset_folders WHERE parent_id = ?");
        $children->execute([$folderId]);
        foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $this->deleteFolder((int)$childId);
        }

        $stmt = $this->db->prepare("DELETE FROM card_asset_folders WHERE id = ?");
        $stmt->execute([$folderId]);
        return true;
    }

    public function moveAttachment(int $attachmentId, ?int $folderId): bool
    {
        if ($folderId) {
            $check = $this->db->prepare("SELECT id FROM card_asset_folders WHERE id = ?");
            $check->execute([$folderId]);
            if (!$check->fetch()) return false;
        }

        $stmt = $this->db->prepare("UPDATE webmail_card_attachments SET folder_id = ? WHERE id = ?");
        return $stmt->execute([$folderId, $attachmentId]);
    }

    public function getOrCreateByName(int $cardId, string $name, string $createdBy): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM card_asset_folders
            WHERE card_id = ? AND parent_id IS NULL AND name = ?
            LIMIT 1
        ");
        $stmt->execute([$cardId, $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['id'] = (int)$row['id'];
            $row['card_id'] = (int)$row['card_id'];
            $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
            return $row;
        }

        return $this->createFolder($cardId, $name, null, $createdBy);
    }

    /**
     * Ensure a Drive folder mirror exists for a card asset folder.
     * Creates: Boards / [Board] / [Card] / [Folder]
     */
    public function ensureDriveFolder(int $folderId, string $userEmail): ?int
    {
        $folder = $this->getById($folderId);
        if (!$folder || $folder['drive_folder_id']) {
            return $folder['drive_folder_id'] ?? null;
        }

        $cardId = (int)$folder['card_id'];
        $stmt = $this->db->prepare("
            SELECT c.title, l.board_id, b.name AS board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$card) return null;

        $drive = new \Webmail\Services\DriveService($this->config);
        $email = strtolower($userEmail);

        $boardsRoot = $drive->findOrCreateFolder($email, 'Boards', null);
        if (!$boardsRoot) return null;

        $cleanBoard = trim(preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $card['board_name'])) ?: 'Board';
        $boardFolder = $drive->findOrCreateFolder($email, $cleanBoard, $boardsRoot['id']);
        if (!$boardFolder) return null;

        $cleanCard = trim(preg_replace('/[^a-zA-Z0-9\s\-_\p{L}]/u', '', $card['title'])) ?: 'Card';
        $cardDriveFolder = $drive->findOrCreateFolder($email, $cleanCard, $boardFolder['id']);
        if (!$cardDriveFolder) return null;

        $parentDriveFolderId = $cardDriveFolder['id'];
        if ($folder['parent_id']) {
            $parentDriveFolderId = $this->ensureDriveFolder((int)$folder['parent_id'], $userEmail) ?? $cardDriveFolder['id'];
        }

        $driveFolder = $drive->findOrCreateFolder($email, $folder['name'], $parentDriveFolderId);
        if (!$driveFolder) return null;

        $this->db->prepare("UPDATE card_asset_folders SET drive_folder_id = ? WHERE id = ?")
            ->execute([$driveFolder['id'], $folderId]);

        return (int)$driveFolder['id'];
    }

    private function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM card_asset_folders WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['id'] = (int)$row['id'];
        $row['card_id'] = (int)$row['card_id'];
        $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
        $row['drive_folder_id'] = $row['drive_folder_id'] ? (int)$row['drive_folder_id'] : null;
        $row['position'] = (int)$row['position'];
        return $row;
    }
}
