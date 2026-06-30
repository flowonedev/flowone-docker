<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubService
{
    private PDO $db;
    private array $config;
    private string $logFile;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/project-hub.log';
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTablesExist();
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTablesExist(): void
    {
        try {
            // Spaces -- top-level containers (e.g., "PENNY", "Kaiser")
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_spaces (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    color VARCHAR(20) DEFAULT '#6366f1',
                    icon VARCHAR(50) DEFAULT 'folder_special',
                    sort_order INT DEFAULT 0,
                    archived TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user (user_email),
                    INDEX idx_sort (sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Folders -- groups within a space (e.g., "Pluss_promo")
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_folders (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    space_id INT UNSIGNED NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    color VARCHAR(20) DEFAULT NULL,
                    icon VARCHAR(50) DEFAULT 'folder',
                    sort_order INT DEFAULT 0,
                    archived TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_space (space_id),
                    INDEX idx_sort (sort_order),
                    FOREIGN KEY (space_id) REFERENCES projecthub_spaces(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Folder <-> Board links
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_folder_boards (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    board_id INT NOT NULL,
                    sort_order INT DEFAULT 0,
                    UNIQUE KEY unique_folder_board (folder_id, board_id),
                    INDEX idx_board (board_id),
                    FOREIGN KEY (folder_id) REFERENCES projecthub_folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Bookmarks -- folder-level links
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_bookmarks (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    url VARCHAR(2000) NOT NULL,
                    favicon_url VARCHAR(500) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_folder (folder_id),
                    FOREIGN KEY (folder_id) REFERENCES projecthub_folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Multi-assignee per card
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_card_assignees (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    role ENUM('assignee','reviewer','observer') DEFAULT 'assignee',
                    status ENUM('assigned','working','review','done','blocked') DEFAULT 'assigned',
                    started_at TIMESTAMP NULL DEFAULT NULL,
                    completed_at TIMESTAMP NULL DEFAULT NULL,
                    time_spent_seconds INT UNSIGNED DEFAULT 0,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_card_user (card_id, user_email),
                    INDEX idx_user (user_email),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Work sessions -- granular time log per assignee per card
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_work_sessions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    source ENUM('manual','drive_edit','board_view','timer','card_view','website_work','portal_call','calendar_event','local_watch') DEFAULT 'manual',
                    entity_type VARCHAR(50) DEFAULT NULL,
                    entity_id INT UNSIGNED DEFAULT NULL,
                    entity_name VARCHAR(255) DEFAULT NULL,
                    started_at TIMESTAMP NULL DEFAULT NULL,
                    ended_at TIMESTAMP NULL DEFAULT NULL,
                    duration_seconds INT UNSIGNED DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_user (card_id, user_email),
                    INDEX idx_user (user_email),
                    INDEX idx_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Add client_id to spaces (links to clients table)
            $this->addColumnIfNotExists('projecthub_spaces', 'client_id', 'INT UNSIGNED DEFAULT NULL');

            // Space members (future expansion for sharing)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_space_members (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    space_id INT UNSIGNED NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    role VARCHAR(20) DEFAULT 'member',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_space_member (space_id, user_email),
                    FOREIGN KEY (space_id) REFERENCES projecthub_spaces(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Comment attachments
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_comment_attachments (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    comment_id INT NOT NULL,
                    type ENUM('file','drive_file','drive_folder','url') DEFAULT 'file',
                    drive_file_id INT UNSIGNED DEFAULT NULL,
                    drive_folder_id INT UNSIGNED DEFAULT NULL,
                    url VARCHAR(2000) DEFAULT NULL,
                    name VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_comment (comment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Comment reactions
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_comment_reactions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    comment_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    emoji VARCHAR(10) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_reaction (comment_id, user_email, emoji),
                    INDEX idx_comment (comment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Comment read tracking (for unread badges)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_comment_reads (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    last_read_comment_id INT UNSIGNED DEFAULT NULL,
                    last_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_card_user (card_id, user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Task dependencies
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_card_dependencies (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    depends_on_card_id INT NOT NULL,
                    type ENUM('finish_to_start','start_to_start','finish_to_finish') DEFAULT 'finish_to_start',
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_dependency (card_id, depends_on_card_id),
                    INDEX idx_card (card_id),
                    INDEX idx_depends_on (depends_on_card_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_subtask_card_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    parent_card_id INT NOT NULL,
                    subtask_card_id INT NOT NULL,
                    linked_card_id INT NOT NULL,
                    created_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_subtask_card (subtask_card_id),
                    UNIQUE KEY uq_linked_card (linked_card_id),
                    INDEX idx_parent_card (parent_card_id),
                    INDEX idx_linked_card (linked_card_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Modifications to existing tables
            $this->addColumnIfNotExists('webmail_board_cards', 'parent_card_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_labels', 'is_type', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_checklist_items', 'drive_file_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_checklist_items', 'assigned_to', "VARCHAR(255) DEFAULT NULL");
            $this->addColumnIfNotExists('webmail_card_comments', 'parent_comment_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_card_comments', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_card_comments', 'mentions', 'JSON DEFAULT NULL');

            // Add index on parent_card_id for subtask lookups
            $this->addIndexIfNotExists('webmail_board_cards', 'idx_parent_card', 'parent_card_id');

            // Folder-level file management (Drive-backed references)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_folder_files (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    drive_file_id INT UNSIGNED NOT NULL,
                    group_name VARCHAR(50) NOT NULL DEFAULT 'General',
                    added_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_folder (folder_id),
                    INDEX idx_drive_file (drive_file_id),
                    INDEX idx_folder_group (folder_id, group_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Per-user unseen file/link tracking
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_folder_file_views (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_folder_user (folder_id, user_email),
                    INDEX idx_folder (folder_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Folder-level link collection
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_folder_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    url TEXT NOT NULL,
                    link_type VARCHAR(30) NOT NULL DEFAULT 'url',
                    group_name VARCHAR(50) DEFAULT NULL,
                    added_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    sort_order INT NOT NULL DEFAULT 0,
                    INDEX idx_folder (folder_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

        } catch (\PDOException $e) {
            $this->log("ensureTablesExist error: " . $e->getMessage());
        }
    }

    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $result = $stmt->fetch();

            if ($result && $result['cnt'] == 0) {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                $this->log("Added column {$column} to {$table}");
            }
        } catch (\PDOException $e) {
            $this->log("Note: Could not add column {$column} to {$table}: " . $e->getMessage());
        }
    }

    private function addIndexIfNotExists(string $table, string $indexName, string $columns): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $indexName]);
            $result = $stmt->fetch();

            if ($result && $result['cnt'] == 0) {
                $this->db->exec("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
                $this->log("Added index {$indexName} to {$table}");
            }
        } catch (\PDOException $e) {
            $this->log("Note: Could not add index {$indexName} to {$table}: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Spaces CRUD
    // =========================================================================

    public function getSpaces(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, c.display_name AS client_name
            FROM projecthub_spaces s
            LEFT JOIN clients c ON c.id = s.client_id
            WHERE s.user_email = ? AND s.archived = 0
            ORDER BY s.sort_order ASC, s.name ASC
        ");
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSpace(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, c.display_name AS client_name
            FROM projecthub_spaces s
            LEFT JOIN clients c ON c.id = s.client_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function createSpace(string $userEmail, array $data): ?array
    {
        $email = strtolower($userEmail);
        $name = $data['name'] ?? 'New Space';

        // Prevent duplicate space names per user
        $check = $this->db->prepare("SELECT id FROM projecthub_spaces WHERE user_email = ? AND name = ? AND archived = 0");
        $check->execute([$email, $name]);
        if ($check->fetch()) {
            throw new \RuntimeException("A space named \"$name\" already exists");
        }

        $stmt = $this->db->prepare("
            INSERT INTO projecthub_spaces (user_email, name, color, icon, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $email,
            $name,
            $data['color'] ?? '#6366f1',
            $data['icon'] ?? 'folder_special',
            $data['sort_order'] ?? 0
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->log("Space created: {$id} by {$userEmail}");
        return $this->getSpace($id);
    }

    public function updateSpace(int $id, array $data): ?array
    {
        $oldClientId = null;
        if (array_key_exists('client_id', $data)) {
            $stmt = $this->db->prepare("SELECT client_id FROM projecthub_spaces WHERE id = ?");
            $stmt->execute([$id]);
            $oldClientId = $stmt->fetchColumn() ?: null;
        }

        $fields = [];
        $values = [];

        foreach (['name', 'color', 'icon', 'sort_order', 'archived', 'client_id', 'is_favorite'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                if ($field === 'client_id') $values[] = $data[$field] ? (int)$data[$field] : null;
                elseif ($field === 'is_favorite') $values[] = $data[$field] ? 1 : 0;
                else $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->getSpace($id);
        }

        $values[] = $id;
        $stmt = $this->db->prepare("
            UPDATE projecthub_spaces SET " . implode(', ', $fields) . " WHERE id = ?
        ");
        $stmt->execute($values);

        if (array_key_exists('client_id', $data)) {
            $newClientId = $data['client_id'] ? (int)$data['client_id'] : null;
            $this->syncClientBoardsForSpace($id, $newClientId, $oldClientId ? (int)$oldClientId : null);
        }

        return $this->getSpace($id);
    }

    /**
     * Bidirectional sync: when a client changes on a space, update
     * client_boards accordingly. Handles assign, re-assign, and unassign.
     */
    private function syncClientBoardsForSpace(int $spaceId, ?int $newClientId, ?int $oldClientId = null): void
    {
        try {
            $boardIds = $this->getBoardIdsForSpace($spaceId);
            if (empty($boardIds)) return;

            $placeholders = implode(',', array_fill(0, count($boardIds), '?'));

            if ($oldClientId && $oldClientId !== $newClientId) {
                $stmt = $this->db->prepare("
                    DELETE FROM client_boards
                    WHERE client_id = ? AND board_id IN ({$placeholders})
                ");
                $stmt->execute(array_merge([$oldClientId], $boardIds));
                $this->log("Removed client_boards: old client={$oldClientId} for space={$spaceId}");
            }

            if ($newClientId) {
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO client_boards (client_id, board_id)
                    SELECT ?, fb.board_id
                    FROM projecthub_folder_boards fb
                    JOIN projecthub_folders f ON f.id = fb.folder_id
                    WHERE f.space_id = ?
                ");
                $stmt->execute([$newClientId, $spaceId]);
                $this->log("Synced client_boards: client={$newClientId} for space={$spaceId}, boards=" . implode(',', $boardIds));
            }
        } catch (\PDOException $e) {
            $this->log("syncClientBoardsForSpace error: " . $e->getMessage());
        }
    }

    private function getBoardIdsForSpace(int $spaceId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT fb.board_id
            FROM projecthub_folder_boards fb
            JOIN projecthub_folders f ON f.id = fb.folder_id
            WHERE f.space_id = ?
        ");
        $stmt->execute([$spaceId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    public function deleteSpace(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_spaces WHERE id = ?");
        $stmt->execute([$id]);
        $this->log("Space deleted: {$id}");
        return $stmt->rowCount() > 0;
    }

    public function reorderSpaces(string $userEmail, array $orderedIds): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE projecthub_spaces SET sort_order = ? WHERE id = ? AND user_email = ?
            ");
            $email = strtolower($userEmail);
            foreach ($orderedIds as $index => $id) {
                $stmt->execute([$index, $id, $email]);
            }
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $this->log("reorderSpaces error: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Folders CRUD
    // =========================================================================

    public function getFolders(int $spaceId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_folders
            WHERE space_id = ? AND archived = 0
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$spaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getFolder(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_folders WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function createFolder(int $spaceId, string $userEmail, array $data): ?array
    {
        $stmt = $this->db->prepare("
            INSERT INTO projecthub_folders (space_id, user_email, name, color, icon, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $spaceId,
            strtolower($userEmail),
            $data['name'] ?? 'New Folder',
            $data['color'] ?? null,
            $data['icon'] ?? 'folder',
            $data['sort_order'] ?? 0
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->log("Folder created: {$id} in space {$spaceId} by {$userEmail}");
        return $this->getFolder($id);
    }

    public function updateFolder(int $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        foreach (['name', 'color', 'icon', 'sort_order', 'archived'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->getFolder($id);
        }

        $values[] = $id;
        $stmt = $this->db->prepare("
            UPDATE projecthub_folders SET " . implode(', ', $fields) . " WHERE id = ?
        ");
        $stmt->execute($values);
        return $this->getFolder($id);
    }

    public function deleteFolder(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_folders WHERE id = ?");
        $stmt->execute([$id]);
        $this->log("Folder deleted: {$id}");
        return $stmt->rowCount() > 0;
    }

    public function reorderFolders(array $orderedIds): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE projecthub_folders SET sort_order = ? WHERE id = ?");
            foreach ($orderedIds as $index => $id) {
                $stmt->execute([$index, $id]);
            }
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $this->log("reorderFolders error: " . $e->getMessage());
            return false;
        }
    }

    public function duplicateFolder(int $folderId, string $userEmail): ?array
    {
        $original = $this->getFolder($folderId);
        if (!$original) return null;

        $newFolder = $this->createFolder(
            (int)$original['space_id'],
            $userEmail,
            [
                'name' => $original['name'] . ' (Copy)',
                'color' => $original['color'],
                'icon' => $original['icon'],
            ]
        );
        if (!$newFolder) return null;

        $boards = $this->getFolderBoards($folderId);
        foreach ($boards as $board) {
            $this->linkBoard((int)$newFolder['id'], (int)$board['board_id']);
        }

        return $this->getFolder((int)$newFolder['id']);
    }

    // =========================================================================
    // Folder <-> Board Links
    // =========================================================================

    public function getFolderBoards(int $folderId): array
    {
        $stmt = $this->db->prepare("
            SELECT fb.id AS link_id, fb.sort_order, fb.board_id,
                   b.name AS board_name, b.owner_email, b.background_color,
                   b.archived AS board_archived
            FROM projecthub_folder_boards fb
            JOIN webmail_boards b ON b.id = fb.board_id
            WHERE fb.folder_id = ?
            ORDER BY fb.sort_order ASC
        ");
        $stmt->execute([$folderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function linkBoardToFolder(int $folderId, int $boardId, int $sortOrder = 0): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_folder_boards (folder_id, board_id, sort_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
            ");
            $stmt->execute([$folderId, $boardId, $sortOrder]);
            $this->log("Board {$boardId} linked to folder {$folderId}");

            $this->autoLinkBoardToSpaceClient($folderId, $boardId);

            return true;
        } catch (\PDOException $e) {
            $this->log("linkBoardToFolder error: " . $e->getMessage());
            return false;
        }
    }

    private function autoLinkBoardToSpaceClient(int $folderId, int $boardId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.client_id
                FROM projecthub_folders f
                JOIN projecthub_spaces s ON s.id = f.space_id
                WHERE f.id = ? AND s.client_id IS NOT NULL
            ");
            $stmt->execute([$folderId]);
            $clientId = $stmt->fetchColumn();

            if ($clientId) {
                $this->db->prepare("INSERT IGNORE INTO client_boards (client_id, board_id) VALUES (?, ?)")
                    ->execute([$clientId, $boardId]);
                $this->log("Auto-linked board {$boardId} to client {$clientId} via folder {$folderId}");
            }
        } catch (\PDOException $e) {
            $this->log("autoLinkBoardToSpaceClient error: " . $e->getMessage());
        }
    }

    public function unlinkBoardFromFolder(int $folderId, int $boardId): bool
    {
        $this->autoUnlinkBoardFromSpaceClient($folderId, $boardId);

        $stmt = $this->db->prepare("
            DELETE FROM projecthub_folder_boards WHERE folder_id = ? AND board_id = ?
        ");
        $stmt->execute([$folderId, $boardId]);
        return $stmt->rowCount() > 0;
    }

    private function autoUnlinkBoardFromSpaceClient(int $folderId, int $boardId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.client_id
                FROM projecthub_folders f
                JOIN projecthub_spaces s ON s.id = f.space_id
                WHERE f.id = ? AND s.client_id IS NOT NULL
            ");
            $stmt->execute([$folderId]);
            $clientId = $stmt->fetchColumn();

            if ($clientId) {
                $this->db->prepare("DELETE FROM client_boards WHERE client_id = ? AND board_id = ?")
                    ->execute([$clientId, $boardId]);
                $this->log("Auto-unlinked board {$boardId} from client {$clientId} via folder {$folderId}");
            }
        } catch (\PDOException $e) {
            $this->log("autoUnlinkBoardFromSpaceClient error: " . $e->getMessage());
        }
    }

    public function reorderFolderBoards(int $folderId, array $orderedBoardIds): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE projecthub_folder_boards SET sort_order = ? WHERE folder_id = ? AND board_id = ?
            ");
            foreach ($orderedBoardIds as $index => $boardId) {
                $stmt->execute([$index, $folderId, $boardId]);
            }
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $this->log("reorderFolderBoards error: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Shared Board Hub Structure
    // =========================================================================

    /**
     * Find or create a space for a user by name (avoids duplicates).
     */
    public function getOrCreateSpace(string $userEmail, string $name, string $color = '#6366f1', string $icon = 'folder_special'): array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("SELECT * FROM projecthub_spaces WHERE user_email = ? AND name = ? AND archived = 0 LIMIT 1");
        $stmt->execute([$email, $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return $existing;

        $stmt = $this->db->prepare("INSERT INTO projecthub_spaces (user_email, name, color, icon, sort_order) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$email, $name, $color, $icon]);
        $id = (int)$this->db->lastInsertId();
        $this->log("Auto-created space {$id} '{$name}' for {$email}");
        return $this->getSpace($id);
    }

    /**
     * Find or create a folder in a space for a user by name (avoids duplicates).
     */
    public function getOrCreateFolder(int $spaceId, string $userEmail, string $name, ?string $color = null, string $icon = 'folder'): array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("SELECT * FROM projecthub_folders WHERE space_id = ? AND user_email = ? AND name = ? AND archived = 0 LIMIT 1");
        $stmt->execute([$spaceId, $email, $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) return $existing;

        $stmt = $this->db->prepare("INSERT INTO projecthub_folders (space_id, user_email, name, color, icon, sort_order) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([$spaceId, $email, $name, $color, $icon]);
        $id = (int)$this->db->lastInsertId();
        $this->log("Auto-created folder {$id} '{$name}' in space {$spaceId} for {$email}");
        return $this->getFolder($id);
    }

    /**
     * Auto-create hub space/folder structure for a new board member,
     * mirroring the owner's hierarchy.
     */
    public function ensureMemberHubStructure(string $memberEmail, int $boardId): void
    {
        $email = strtolower($memberEmail);

        // Already linked to one of this user's folders?
        $check = $this->db->prepare("
            SELECT fb.id FROM projecthub_folder_boards fb
            JOIN projecthub_folders f ON f.id = fb.folder_id
            JOIN projecthub_spaces s ON s.id = f.space_id
            WHERE fb.board_id = ? AND s.user_email = ?
            LIMIT 1
        ");
        $check->execute([$boardId, $email]);
        if ($check->fetch()) return;

        // Look up owner's hub structure for this board
        $stmt = $this->db->prepare("
            SELECT s.name AS space_name, s.color AS space_color, s.icon AS space_icon,
                   f.name AS folder_name, f.color AS folder_color, f.icon AS folder_icon
            FROM projecthub_folder_boards fb
            JOIN projecthub_folders f ON f.id = fb.folder_id
            JOIN projecthub_spaces s ON s.id = f.space_id
            JOIN webmail_boards b ON b.id = fb.board_id
            WHERE fb.board_id = ? AND LOWER(f.user_email) = LOWER(b.owner_email)
            LIMIT 1
        ");
        $stmt->execute([$boardId]);
        $ownerStructure = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ownerStructure) return; // owner hasn't organized it, will appear in Unsorted

        try {
            $space = $this->getOrCreateSpace($email, $ownerStructure['space_name'], $ownerStructure['space_color'] ?? '#6366f1', $ownerStructure['space_icon'] ?? 'folder_special');
            $folder = $this->getOrCreateFolder($space['id'], $email, $ownerStructure['folder_name'], $ownerStructure['folder_color'], $ownerStructure['folder_icon'] ?? 'folder');
            $this->linkBoardToFolder($folder['id'], $boardId);
            $this->log("Auto-mirrored hub structure for member {$email} on board {$boardId}: space={$space['id']}, folder={$folder['id']}");
        } catch (\Throwable $e) {
            $this->log("ensureMemberHubStructure error for {$email} board {$boardId}: " . $e->getMessage());
        }
    }

    /**
     * Get all boards not linked to any of the user's own folders (for the "Unsorted" section)
     */
    public function getUnsortedBoards(string $userEmail): array
    {
        $email = strtolower($userEmail);
        $userDomain = strtolower(explode('@', $email)[1] ?? '');

        $canSeeAll = false;
        $groupIds = [];
        try {
            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
            $canSeeAll = $colleagueService->hasGroupPermission($email, 'can_see_all_boards');
            if (!$canSeeAll) {
                $groupIds = array_column($colleagueService->getUserGroups($email), 'id');
            }
        } catch (\Throwable $e) {
            // Team addon might not be active
        }

        $params = [$email, $email];

        if ($canSeeAll && $userDomain) {
            $sql = "
                SELECT b.*
                FROM webmail_boards b
                LEFT JOIN projecthub_folder_boards fb ON fb.board_id = b.id
                    AND fb.folder_id IN (
                        SELECT f.id FROM projecthub_folders f
                        JOIN projecthub_spaces s ON s.id = f.space_id
                        WHERE s.user_email = ?
                    )
                JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(b.owner_email) AND oc.organization_domain = ?
                WHERE fb.id IS NULL AND b.archived = 0
                ORDER BY b.name ASC
            ";
            $params = [$email, $userDomain];
        } elseif (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $sql = "
                SELECT DISTINCT b.*
                FROM webmail_boards b
                LEFT JOIN projecthub_folder_boards fb ON fb.board_id = b.id
                    AND fb.folder_id IN (
                        SELECT f.id FROM projecthub_folders f
                        JOIN projecthub_spaces s ON s.id = f.space_id
                        WHERE s.user_email = ?
                    )
                LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND LOWER(bm.user_email) = ?
                LEFT JOIN board_group_access bga ON bga.board_id = b.id AND bga.group_id IN ($placeholders)
                WHERE fb.id IS NULL
                  AND b.archived = 0
                  AND (LOWER(b.owner_email) = ? OR bm.user_email IS NOT NULL OR bga.group_id IS NOT NULL)
                ORDER BY b.name ASC
            ";
            $params = [$email, $email];
            $params = array_merge($params, $groupIds);
            $params[] = $email;
        } else {
            $sql = "
                SELECT b.*
                FROM webmail_boards b
                LEFT JOIN projecthub_folder_boards fb ON fb.board_id = b.id
                    AND fb.folder_id IN (
                        SELECT f.id FROM projecthub_folders f
                        JOIN projecthub_spaces s ON s.id = f.space_id
                        WHERE s.user_email = ?
                    )
                LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND LOWER(bm.user_email) = ?
                WHERE fb.id IS NULL
                  AND b.archived = 0
                  AND (LOWER(b.owner_email) = ? OR bm.user_email IS NOT NULL)
                ORDER BY b.name ASC
            ";
            $params = [$email, $email, $email];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // Bookmarks CRUD
    // =========================================================================

    public function getBookmarks(int $folderId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_bookmarks
            WHERE folder_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$folderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createBookmark(int $folderId, string $userEmail, array $data): ?array
    {
        $stmt = $this->db->prepare("
            INSERT INTO projecthub_bookmarks (folder_id, user_email, title, url, favicon_url)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $folderId,
            strtolower($userEmail),
            $data['title'] ?? 'Untitled',
            $data['url'] ?? '',
            $data['favicon_url'] ?? null
        ]);
        $id = (int)$this->db->lastInsertId();

        $stmt2 = $this->db->prepare("SELECT * FROM projecthub_bookmarks WHERE id = ?");
        $stmt2->execute([$id]);
        return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteBookmark(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_bookmarks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Folder Overview (aggregated data)
    // =========================================================================

    public function getFolderOverview(int $folderId, string $userEmail): array
    {
        $email = strtolower($userEmail);
        $boards = $this->getFolderBoards($folderId);
        $boardIds = array_column($boards, 'board_id');

        if (empty($boardIds)) {
            return [
                'boards' => [],
                'total_cards' => 0,
                'completed_cards' => 0,
                'bookmarks' => $this->getBookmarks($folderId),
            ];
        }

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));

        foreach ($boards as &$board) {
            $bid = (int)$board['board_id'];
            $isOwner = strtolower($board['owner_email'] ?? '') === $email;

            if ($isOwner) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(c.id) AS total_cards,
                           SUM(CASE WHEN c.completed = 1 THEN 1 ELSE 0 END) AS completed_cards
                    FROM webmail_board_cards c
                    JOIN webmail_board_lists l ON l.id = c.list_id
                    WHERE l.board_id = ? AND c.archived = 0 AND c.parent_card_id IS NULL
                ");
                $stmt->execute([$bid]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(c.id) AS total_cards,
                           SUM(CASE WHEN c.completed = 1 THEN 1 ELSE 0 END) AS completed_cards
                    FROM webmail_board_cards c
                    JOIN webmail_board_lists l ON l.id = c.list_id
                    WHERE l.board_id = ? AND c.archived = 0 AND c.parent_card_id IS NULL
                      AND (LOWER(c.assigned_to) LIKE ? OR c.id IN (
                          SELECT card_id FROM projecthub_card_assignees WHERE LOWER(user_email) = ?
                      ) OR c.id IN (
                          SELECT DISTINCT parent_card_id FROM webmail_board_cards
                          WHERE parent_card_id IS NOT NULL
                            AND (LOWER(assigned_to) LIKE ? OR id IN (SELECT card_id FROM projecthub_card_assignees WHERE LOWER(user_email) = ?))
                      ))
                ");
                $stmt->execute([$bid, '%' . $email . '%', $email, '%' . $email . '%', $email]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $board['total_cards'] = (int)($row['total_cards'] ?? 0);
            $board['completed_cards'] = (int)($row['completed_cards'] ?? 0);
        }
        unset($board);

        $totalCards = array_sum(array_column($boards, 'total_cards'));
        $completedCards = array_sum(array_column($boards, 'completed_cards'));

        return [
            'boards' => $boards,
            'total_cards' => $totalCards,
            'completed_cards' => $completedCards,
            'bookmarks' => $this->getBookmarks($folderId),
        ];
    }

    public function getFolderOverviewEnriched(int $folderId, string $userEmail): array
    {
        $base = $this->getFolderOverview($folderId, $userEmail);
        $boardIds = array_column($base['boards'] ?? [], 'board_id');

        if (empty($boardIds)) {
            $base['recent_cards'] = [];
            $base['assignee_summary'] = [];
            $base['status_summary'] = [];
            return $base;
        }

        $ph = implode(',', array_fill(0, count($boardIds), '?'));

        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.completed, c.due_date, c.updated_at, c.assigned_to,
                   l.board_id, b.name AS board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE l.board_id IN ($ph) AND c.archived = 0 AND c.parent_card_id IS NULL
            ORDER BY c.updated_at DESC
            LIMIT 10
        ");
        $stmt->execute($boardIds);
        $base['recent_cards'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT ws.user_email,
                   COALESCE(oc.display_name, ws.user_email) AS display_name,
                   COUNT(DISTINCT ws.card_id) AS card_count,
                   SUM(ws.duration_seconds) AS total_seconds
            FROM projecthub_work_sessions ws
            JOIN webmail_board_cards c ON c.id = ws.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            LEFT JOIN organization_colleagues oc ON oc.email = ws.user_email
            WHERE l.board_id IN ($ph)
            GROUP BY ws.user_email
            ORDER BY total_seconds DESC
        ");
        $stmt->execute($boardIds);
        $base['assignee_summary'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT l.name AS list_name,
                   COUNT(c.id) AS card_count
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            WHERE l.board_id IN ($ph) AND c.archived = 0 AND c.parent_card_id IS NULL
            GROUP BY l.name
            ORDER BY card_count DESC
        ");
        $stmt->execute($boardIds);
        $base['status_summary'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $base;
    }

    /**
     * Get all card attachments across all boards linked to a folder,
     * grouped by board -> card.
     */
    public function getFolderBoardAttachments(int $folderId): array
    {
        $boards = $this->getFolderBoards($folderId);
        if (empty($boards)) return ['boards' => []];

        $boardIds = array_map(fn($b) => (int)$b['board_id'], $boards);
        $ph = implode(',', array_fill(0, count($boardIds), '?'));

        // ONE IN-clause query for ALL attachments across boards in this folder.
        $stmt = $this->db->prepare("
            SELECT l.board_id, c.id AS card_id, c.title AS card_title,
                   a.id, a.name, a.drive_file_id, a.url, a.is_cover, a.created_at,
                   f.original_name, f.mime_type, f.size, f.folder_id AS drive_folder_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_card_attachments a ON a.card_id = c.id
            LEFT JOIN drive_files f ON f.id = a.drive_file_id
            WHERE l.board_id IN ({$ph}) AND c.archived = 0
            ORDER BY l.board_id ASC, c.title ASC, a.created_at DESC
        ");
        $stmt->execute($boardIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Group by board_id -> card_id in PHP.
        $byBoard = []; // bid => [cid => cardEntry]
        foreach ($rows as $row) {
            $bid = (int)$row['board_id'];
            $cid = (int)$row['card_id'];
            if (!isset($byBoard[$bid])) $byBoard[$bid] = [];
            if (!isset($byBoard[$bid][$cid])) {
                $byBoard[$bid][$cid] = [
                    'card_id' => $cid,
                    'card_title' => $row['card_title'],
                    'attachments' => [],
                ];
            }
            $byBoard[$bid][$cid]['attachments'][] = [
                'id' => (int)$row['id'],
                'name' => $row['original_name'] ?: $row['name'],
                'mime_type' => $row['mime_type'],
                'size' => $row['size'] ? (int)$row['size'] : null,
                'drive_file_id' => $row['drive_file_id'] ? (int)$row['drive_file_id'] : null,
                'url' => $row['url'],
                'is_cover' => (bool)$row['is_cover'],
                'drive_folder_id' => $row['drive_folder_id'] ? (int)$row['drive_folder_id'] : null,
            ];
        }

        $result = [];
        foreach ($boards as $board) {
            $bid = (int)$board['board_id'];
            if (empty($byBoard[$bid])) continue;
            $result[] = [
                'board_id' => $bid,
                'board_name' => $board['board_name'] ?? 'Board',
                'cards' => array_values($byBoard[$bid]),
            ];
        }

        return ['boards' => $result];
    }

    /**
     * Get all tracked URLs from cards across all boards linked to a folder,
     * grouped by board.
     */
    public function getFolderTrackedUrls(int $folderId): array
    {
        $boards = $this->getFolderBoards($folderId);
        if (empty($boards)) return ['boards' => []];

        $boardIds = array_map(fn($b) => (int)$b['board_id'], $boards);
        $ph = implode(',', array_fill(0, count($boardIds), '?'));

        // ONE IN-clause query for ALL tracked URLs across boards in this folder.
        $stmt = $this->db->prepare("
            SELECT l.board_id, ctu.id, ctu.card_id, ctu.url_domain, ctu.display_name,
                   ctu.title_match, ctu.is_active, ctu.created_at,
                   c.title AS card_title
            FROM projecthub_card_tracked_urls ctu
            JOIN webmail_board_cards c ON c.id = ctu.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            WHERE l.board_id IN ({$ph}) AND c.archived = 0
            ORDER BY l.board_id ASC, c.title ASC, ctu.url_domain ASC
        ");
        $stmt->execute($boardIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $byBoard = [];
        foreach ($rows as $row) {
            $bid = (int)$row['board_id'];
            $byBoard[$bid][] = [
                'id' => (int)$row['id'],
                'card_id' => (int)$row['card_id'],
                'card_title' => $row['card_title'],
                'url_domain' => $row['url_domain'],
                'display_name' => $row['display_name'],
                'title_match' => $row['title_match'],
                'is_active' => (bool)$row['is_active'],
            ];
        }

        $result = [];
        foreach ($boards as $board) {
            $bid = (int)$board['board_id'];
            if (empty($byBoard[$bid])) continue;
            $result[] = [
                'board_id' => $bid,
                'board_name' => $board['board_name'] ?? 'Board',
                'urls' => $byBoard[$bid],
            ];
        }

        return ['boards' => $result];
    }

    public function getSpaceOverview(int $spaceId, ?string $userEmail = null): array
    {
        $space = $this->getSpace($spaceId);
        if (!$space) return [];

        $folders = $this->getFolders($spaceId);
        $allBoardIds = [];

        // ONE batched query for ALL folder-board links + card counts in
        // this space, vs one query (with a correlated subquery per row)
        // per folder.
        $folderBoardRows = [];
        if (!empty($folders)) {
            $folderIds = array_map(fn($f) => (int)$f['id'], $folders);
            $fph = implode(',', array_fill(0, count($folderIds), '?'));
            $stmt = $this->db->prepare("
                SELECT fb.folder_id,
                       fb.sort_order,
                       fb.board_id,
                       b.name AS board_name,
                       COALESCE(cc.card_count, 0) AS card_count
                FROM projecthub_folder_boards fb
                JOIN webmail_boards b ON b.id = fb.board_id
                LEFT JOIN (
                    SELECT l.board_id, COUNT(*) AS card_count
                    FROM webmail_board_cards c
                    JOIN webmail_board_lists l ON l.id = c.list_id
                    WHERE c.archived = 0 AND c.parent_card_id IS NULL
                    GROUP BY l.board_id
                ) AS cc ON cc.board_id = fb.board_id
                WHERE fb.folder_id IN ({$fph})
                ORDER BY fb.folder_id ASC, fb.sort_order ASC
            ");
            $stmt->execute($folderIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $folderBoardRows[(int)$row['folder_id']][] = $row;
            }
        }

        foreach ($folders as &$folder) {
            $boards = $folderBoardRows[(int)$folder['id']] ?? [];
            // Strip the helper folder_id column from output rows.
            foreach ($boards as &$b) unset($b['folder_id']);
            unset($b);
            $folder['boards'] = $boards;
            $folder['board_count'] = count($boards);
            $folder['card_count'] = array_sum(array_column($boards, 'card_count'));
            foreach ($boards as $b) {
                $allBoardIds[] = (int)$b['board_id'];
            }
        }
        unset($folder);

        $result = [
            'space' => $space,
            'folders' => $folders,
            'recent_cards' => [],
            'status_summary' => [],
            'time_summary' => ['total_seconds' => 0, 'by_user' => []],
            'client_context' => null,
        ];

        // Fetch lightweight client context if space is linked to a client
        if (!empty($space['client_id']) && $userEmail) {
            $result['client_context'] = $this->getSpaceClientContext((int)$space['client_id'], $userEmail);
        }

        if (empty($allBoardIds)) return $result;

        $ph = implode(',', array_fill(0, count($allBoardIds), '?'));

        $stmt = $this->db->prepare("
            SELECT c.id, c.title, c.completed, c.due_date, c.updated_at, c.assigned_to,
                   l.board_id, b.name AS board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE l.board_id IN ($ph) AND c.archived = 0 AND c.parent_card_id IS NULL
            ORDER BY c.updated_at DESC
            LIMIT 10
        ");
        $stmt->execute($allBoardIds);
        $result['recent_cards'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT l.name AS list_name, COUNT(c.id) AS card_count
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            WHERE l.board_id IN ($ph) AND c.archived = 0 AND c.parent_card_id IS NULL
            GROUP BY l.name
            ORDER BY card_count DESC
        ");
        $stmt->execute($allBoardIds);
        $result['status_summary'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            SELECT ws.user_email,
                   COALESCE(oc.display_name, ws.user_email) AS display_name,
                   SUM(ws.duration_seconds) AS total_seconds
            FROM projecthub_work_sessions ws
            JOIN webmail_board_cards c ON c.id = ws.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            LEFT JOIN organization_colleagues oc ON oc.email = ws.user_email
            WHERE l.board_id IN ($ph)
              AND ws.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY ws.user_email
            ORDER BY total_seconds DESC
        ");
        $stmt->execute($allBoardIds);
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $result['time_summary']['by_user'] = $users;
        $result['time_summary']['total_seconds'] = array_sum(array_column($users, 'total_seconds'));

        return $result;
    }

    /**
     * Lightweight client context for space overview.
     * Returns core status fields without heavy email/thread scanning.
     */
    private function getSpaceClientContext(int $clientId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, display_name, domain, status, phone, hourly_rate, notes,
                   last_activity_at, last_email_direction,
                   open_task_count, overdue_task_count, next_deadline
            FROM clients
            WHERE id = ? AND user_email = ?
        ");
        $stmt->execute([$clientId, strtolower($userEmail)]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) return null;

        // Primary contact
        $contactStmt = $this->db->prepare("
            SELECT email, name FROM client_contacts
            WHERE client_id = ? ORDER BY email_count DESC LIMIT 1
        ");
        $contactStmt->execute([$clientId]);
        $primaryContact = $contactStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Responsibility message
        $responsibility = 'Work progressing';
        if ($client['overdue_task_count'] > 0) {
            $responsibility = 'Overdue tasks require attention';
        } elseif ($client['last_email_direction'] === 'outbound') {
            $responsibility = 'Waiting on client response';
        } elseif ($client['open_task_count'] > 0) {
            $responsibility = 'Waiting on internal work';
        } elseif (!$client['last_activity_at']) {
            $responsibility = 'No recent activity';
        }

        return [
            'id' => (int)$client['id'],
            'display_name' => $client['display_name'],
            'domain' => $client['domain'],
            'status' => $client['status'] ?? 'active',
            'phone' => $client['phone'] ?? null,
            'hourly_rate' => $client['hourly_rate'] ?? null,
            'notes' => $client['notes'] ?? null,
            'last_activity_at' => $client['last_activity_at'],
            'last_email_direction' => $client['last_email_direction'],
            'open_task_count' => (int)($client['open_task_count'] ?? 0),
            'overdue_task_count' => (int)($client['overdue_task_count'] ?? 0),
            'next_deadline' => $client['next_deadline'],
            'responsibility' => $responsibility,
            'primary_contact' => $primaryContact,
        ];
    }

    // =========================================================================
    // Dependencies CRUD
    // =========================================================================

    public function getCardDependencies(int $cardId): array
    {
        // "Waiting on" -- cards this task depends on
        $stmt = $this->db->prepare("
            SELECT d.id, d.type, d.created_by, d.created_at,
                   c.id AS card_id, c.title AS card_title, c.completed,
                   l.board_id, b.name AS board_name
            FROM projecthub_card_dependencies d
            JOIN webmail_board_cards c ON c.id = d.depends_on_card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE d.card_id = ?
        ");
        $stmt->execute([$cardId]);
        $waitingOn = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // "Blocking" -- cards that depend on this task
        $stmt2 = $this->db->prepare("
            SELECT d.id, d.type, d.created_by, d.created_at,
                   c.id AS card_id, c.title AS card_title, c.completed,
                   l.board_id, b.name AS board_name
            FROM projecthub_card_dependencies d
            JOIN webmail_board_cards c ON c.id = d.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE d.depends_on_card_id = ?
        ");
        $stmt2->execute([$cardId]);
        $blocking = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'waiting_on' => $waitingOn,
            'blocking' => $blocking,
        ];
    }

    public function createDependency(int $cardId, int $dependsOnCardId, string $type, string $createdBy): ?array
    {
        if ($cardId === $dependsOnCardId) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_card_dependencies (card_id, depends_on_card_id, type, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $dependsOnCardId, $type, strtolower($createdBy)]);
            $id = (int)$this->db->lastInsertId();

            $stmt2 = $this->db->prepare("SELECT * FROM projecthub_card_dependencies WHERE id = ?");
            $stmt2->execute([$id]);
            $this->log("Dependency created: card {$cardId} depends on {$dependsOnCardId}");
            return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            $this->log("createDependency error: " . $e->getMessage());
            return null;
        }
    }

    public function deleteDependency(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_card_dependencies WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function getSubtaskCardLinks(int $parentCardId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                scl.subtask_card_id,
                scl.linked_card_id,
                scl.created_at,
                c.title AS linked_card_title,
                l.board_id AS linked_board_id,
                b.name AS linked_board_name
            FROM projecthub_subtask_card_links scl
            JOIN webmail_board_cards c ON c.id = scl.linked_card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE scl.parent_card_id = ?
            ORDER BY scl.created_at ASC
        ");
        $stmt->execute([$parentCardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCardOriginLink(int $cardId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                'subtask' AS relation_type,
                c.parent_card_id,
                c.id AS subtask_card_id,
                NULL AS linked_card_id,
                p.title AS parent_card_title,
                c.title AS subtask_card_title,
                lp.board_id AS parent_board_id,
                bp.name AS parent_board_name
            FROM webmail_board_cards c
            JOIN webmail_board_cards p ON p.id = c.parent_card_id
            JOIN webmail_board_lists lp ON lp.id = p.list_id
            JOIN webmail_boards bp ON bp.id = lp.board_id
            WHERE c.id = ? AND c.parent_card_id IS NOT NULL AND c.parent_card_id > 0
            LIMIT 1
        ");
        $stmt->execute([$cardId]);
        $directParent = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($directParent) {
            return $directParent;
        }

        $stmt2 = $this->db->prepare("
            SELECT
                'linked_card' AS relation_type,
                scl.parent_card_id,
                scl.subtask_card_id,
                scl.linked_card_id,
                p.title AS parent_card_title,
                s.title AS subtask_card_title,
                lp.board_id AS parent_board_id,
                bp.name AS parent_board_name
            FROM projecthub_subtask_card_links scl
            JOIN webmail_board_cards p ON p.id = scl.parent_card_id
            JOIN webmail_board_cards s ON s.id = scl.subtask_card_id
            JOIN webmail_board_lists lp ON lp.id = p.list_id
            JOIN webmail_boards bp ON bp.id = lp.board_id
            WHERE scl.linked_card_id = ?
            LIMIT 1
        ");
        $stmt2->execute([$cardId]);
        $linkedOrigin = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $linkedOrigin ?: null;
    }

    public function createSubtaskCardLink(int $parentCardId, int $subtaskCardId, int $linkedCardId, string $createdBy): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM webmail_board_cards
                WHERE id = ? AND parent_card_id = ?
            ");
            $stmt->execute([$subtaskCardId, $parentCardId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return null;
            }

            $stmt2 = $this->db->prepare("
                SELECT id FROM webmail_board_cards
                WHERE id = ? AND (parent_card_id IS NULL OR parent_card_id = 0)
            ");
            $stmt2->execute([$linkedCardId]);
            if (!$stmt2->fetch(PDO::FETCH_ASSOC)) {
                return null;
            }

            $stmt3 = $this->db->prepare("
                INSERT INTO projecthub_subtask_card_links
                    (parent_card_id, subtask_card_id, linked_card_id, created_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    linked_card_id = VALUES(linked_card_id),
                    created_by = VALUES(created_by),
                    created_at = CURRENT_TIMESTAMP
            ");
            $stmt3->execute([$parentCardId, $subtaskCardId, $linkedCardId, strtolower($createdBy)]);

            $links = $this->getSubtaskCardLinks($parentCardId);
            foreach ($links as $link) {
                if ((int)$link['subtask_card_id'] === $subtaskCardId) {
                    return $link;
                }
            }
            return null;
        } catch (\PDOException $e) {
            $this->log("createSubtaskCardLink error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * When a card is completed, check if any dependent cards can be unblocked
     */
    public function checkAndUnblockDependents(int $completedCardId): array
    {
        // ONE query: dependents of $completedCardId that have NO remaining
        // incomplete prerequisites (NOT EXISTS subquery on dependencies).
        // Replaces N+1: one query per dependent to count remaining prereqs.
        $stmt = $this->db->prepare("
            SELECT DISTINCT d.card_id
            FROM projecthub_card_dependencies d
            WHERE d.depends_on_card_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM projecthub_card_dependencies d2
                  JOIN webmail_board_cards c2 ON c2.id = d2.depends_on_card_id
                  WHERE d2.card_id = d.card_id AND c2.completed = 0
              )
        ");
        $stmt->execute([$completedCardId]);
        $readyCardIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (empty($readyCardIds)) {
            return [];
        }

        // ONE batched UPDATE for ALL ready cards' blocked assignees.
        $ph = implode(',', array_fill(0, count($readyCardIds), '?'));

        // Pre-collect which cards actually have blocked assignees so we
        // can return only the cards that were really unblocked (matches
        // the legacy "rowCount > 0" gate).
        $checkStmt = $this->db->prepare(
            "SELECT DISTINCT card_id FROM projecthub_card_assignees
             WHERE card_id IN ({$ph}) AND status = 'blocked'"
        );
        $checkStmt->execute($readyCardIds);
        $unblockedCards = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (!empty($unblockedCards)) {
            $uph = implode(',', array_fill(0, count($unblockedCards), '?'));
            $update = $this->db->prepare(
                "UPDATE projecthub_card_assignees
                 SET status = 'assigned'
                 WHERE card_id IN ({$uph}) AND status = 'blocked'"
            );
            $update->execute($unblockedCards);
        }

        return $unblockedCards;
    }

    // =========================================================================
    // Full Hierarchy Query (for sidebar tree)
    // =========================================================================

    public function getFullHierarchy(string $userEmail): array
    {
        $email = strtolower($userEmail);

        $this->organizeSharedBoards($email);

        // 1) ONE query for the user's spaces.
        $spaces = $this->getSpaces($email);
        if (empty($spaces)) {
            return [
                'spaces' => [],
                'unsorted' => $this->getUnsortedBoards($email),
            ];
        }

        $spaceIds = array_map(fn($s) => (int)$s['id'], $spaces);

        // 2) ONE IN-clause query for ALL folders across those spaces.
        $folderPh = implode(',', array_fill(0, count($spaceIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM projecthub_folders
             WHERE space_id IN ({$folderPh}) AND archived = 0
             ORDER BY sort_order ASC, name ASC"
        );
        $stmt->execute($spaceIds);
        $allFolders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $foldersBySpace = array_fill_keys($spaceIds, []);
        $folderIds = [];
        foreach ($allFolders as $f) {
            $sid = (int)$f['space_id'];
            $foldersBySpace[$sid][] = $f;
            $folderIds[] = (int)$f['id'];
        }

        // 3) ONE IN-clause query for ALL folder-board links across those folders.
        $boardsByFolder = array_fill_keys($folderIds, []);
        if (!empty($folderIds)) {
            $boardPh = implode(',', array_fill(0, count($folderIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT fb.folder_id, fb.id AS link_id, fb.sort_order, fb.board_id,
                        b.name AS board_name, b.owner_email, b.background_color,
                        b.archived AS board_archived
                 FROM projecthub_folder_boards fb
                 JOIN webmail_boards b ON b.id = fb.board_id
                 WHERE fb.folder_id IN ({$boardPh})
                 ORDER BY fb.folder_id ASC, fb.sort_order ASC"
            );
            $stmt->execute($folderIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $fid = (int)$row['folder_id'];
                unset($row['folder_id']);
                $boardsByFolder[$fid][] = $row;
            }
        }

        // Stitch in PHP.
        foreach ($spaces as &$space) {
            $sid = (int)$space['id'];
            $space['folders'] = array_map(function ($folder) use ($boardsByFolder) {
                $fid = (int)$folder['id'];
                $folder['boards'] = $boardsByFolder[$fid] ?? [];
                return $folder;
            }, $foldersBySpace[$sid] ?? []);
        }
        unset($space);

        return [
            'spaces' => $spaces,
            'unsorted' => $this->getUnsortedBoards($email),
        ];
    }

    /**
     * Retroactively create hub structure for shared boards that the
     * user is a member of but hasn't organized yet (owner must have a
     * structure). Batched: ONE query to find unorganized boards, ONE
     * IN-clause query to fetch ALL owner structures, then groups by
     * (space_name, folder_name) so each unique space/folder is
     * created at most once per call.
     */
    private function organizeSharedBoards(string $userEmail): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT b.id AS board_id
                FROM webmail_boards b
                JOIN webmail_board_members bm ON bm.board_id = b.id AND LOWER(bm.user_email) = ?
                LEFT JOIN projecthub_folder_boards fb ON fb.board_id = b.id
                    AND fb.folder_id IN (
                        SELECT f.id FROM projecthub_folders f
                        JOIN projecthub_spaces s ON s.id = f.space_id
                        WHERE LOWER(s.user_email) = ?
                    )
                WHERE b.archived = 0
                  AND LOWER(b.owner_email) != ?
                  AND fb.id IS NULL
            ");
            $stmt->execute([$userEmail, $userEmail, $userEmail]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($rows)) return;

            $boardIds = array_values(array_unique(array_map(fn($r) => (int)$r['board_id'], $rows)));
            if (empty($boardIds)) return;

            // ONE IN-clause query for ALL owner structures, vs N
            // single-row lookups inside ensureMemberHubStructure().
            $ph = implode(',', array_fill(0, count($boardIds), '?'));
            $sStmt = $this->db->prepare("
                SELECT fb.board_id,
                       s.name AS space_name, s.color AS space_color, s.icon AS space_icon,
                       f.name AS folder_name, f.color AS folder_color, f.icon AS folder_icon
                FROM projecthub_folder_boards fb
                JOIN projecthub_folders f ON f.id = fb.folder_id
                JOIN projecthub_spaces s ON s.id = f.space_id
                JOIN webmail_boards b ON b.id = fb.board_id
                WHERE fb.board_id IN ({$ph})
                  AND LOWER(f.user_email) = LOWER(b.owner_email)
            ");
            $sStmt->execute($boardIds);
            $structures = $sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // First-match-wins per board to mirror the LIMIT 1 in the
            // single-board path.
            $byBoard = [];
            foreach ($structures as $row) {
                $bid = (int)$row['board_id'];
                if (!isset($byBoard[$bid])) $byBoard[$bid] = $row;
            }

            // Cache spaces/folders we create or fetch so duplicate
            // (space_name, folder_name) tuples don't fire redundant
            // INSERTs / SELECTs.
            $spaceCache = []; // name => row
            $folderCache = []; // "{space_id}:{folder_name}" => row

            foreach ($boardIds as $boardId) {
                $struct = $byBoard[$boardId] ?? null;
                if (!$struct) continue; // owner hasn't organized this board
                try {
                    $spaceName = $struct['space_name'];
                    if (!isset($spaceCache[$spaceName])) {
                        $spaceCache[$spaceName] = $this->getOrCreateSpace(
                            $userEmail,
                            $spaceName,
                            $struct['space_color'] ?? '#6366f1',
                            $struct['space_icon'] ?? 'folder_special'
                        );
                    }
                    $space = $spaceCache[$spaceName];

                    $folderKey = $space['id'] . ':' . $struct['folder_name'];
                    if (!isset($folderCache[$folderKey])) {
                        $folderCache[$folderKey] = $this->getOrCreateFolder(
                            (int)$space['id'],
                            $userEmail,
                            $struct['folder_name'],
                            $struct['folder_color'],
                            $struct['folder_icon'] ?? 'folder'
                        );
                    }
                    $folder = $folderCache[$folderKey];

                    $this->linkBoardToFolder((int)$folder['id'], $boardId);
                } catch (\Throwable $e) {
                    $this->log("organizeSharedBoards batch entry error for board {$boardId}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->log("organizeSharedBoards error for {$userEmail}: " . $e->getMessage());
        }
    }
}
