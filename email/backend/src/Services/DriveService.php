<?php

namespace Webmail\Services;

use Webmail\Services\StorageService;
use Webmail\Services\NasHealthCheck;
use function Webmail\Helpers\debug_log;

/**
 * DriveService - File storage system similar to Google Drive
 * 
 * Features:
 * - Upload/download files
 * - Folder management
 * - Share links for large attachments
 * - Quota tracking (unlimited by default, configurable via control panel)
 */
class DriveService
{
    private \PDO $db;
    private string $storagePath;
    private int $defaultQuota = -1; // -1 = unlimited
    private array $config;
    private StorageService $storage;

    /**
     * Phase 5b: lazy-built tier recall orchestrator. Null until the
     * first cold-file read forces us to build it; null forever when
     * the phase5b_drive_recall kill switch is off OR the shared
     * storage library is unavailable.
     */
    private ?\FlowOne\Storage\TierRecallService $tierRecallService = null;
    private bool $tierRecallServiceUnavailable = false;

    /**
     * Phase 6b: lazy-built admission controller. Same conservative
     * fail-open pattern as the recall service — null when the kill
     * switch is off, when the shared lib isn't deployed, or when
     * config bootstrap fails. In all of those cases admission is a
     * no-op and pre-existing per-user quota checks are unchanged.
     */
    private ?\FlowOne\Storage\AdmissionController $admissionController = null;
    private bool $admissionControllerUnavailable = false;

    /**
     * Phase 6d: lazy-built LRU stamper. Same fail-open contract.
     * Null when phase6d_lru_selection is off, shared lib missing,
     * or migration 168 hasn't run (last_read_at column absent).
     */
    private ?\FlowOne\Storage\LastReadTouch $lastReadTouch = null;
    private bool $lastReadTouchUnavailable = false;

    /**
     * Records which storage tier the most recent getUserPath() resolved to.
     * Values: 'nfs' (NAS happy-path, the configured primary), or 'local' (the
     * VPS fallback used when NasHealthCheck reports the NAS down). Read by
     * upload paths so the DB `storage_location` column reflects where the
     * bytes actually live, not where StorageService *thinks* they should live.
     *
     * NOTE: this is a per-request scratchpad. It is only meaningful immediately
     * after a getUserPath() call - never inspect it before one runs.
     */
    private string $lastResolvedTier = 'nfs';

    /** Lazy versioning sub-service (owns drive_file_versions lifecycle). */
    private ?DriveVersioningService $versioningService = null;

    /**
     * Lazy universal-search indexer. Built on first content write so every
     * Drive content-mutation path (upload, OnlyOffice save, attachment save)
     * keeps Meilisearch/MySQL search content fresh. Null until first use.
     */
    private ?SearchIndexerService $searchIndexer = null;

    public function __construct(array $config, ?string $userEmail = null)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        // Initialize StorageService for abstracted file operations
        // Pass userEmail so Panel can determine storage based on domain
        $this->storage = new StorageService($config, $userEmail);
        
        // Storage path - now comes from StorageService (based on Panel config)
        $this->storagePath = $this->storage->getBasePath();
        
        $this->ensureTablesExist();
    }

    /**
     * Version lifecycle (create/list/restore/delete/pin/label/prune) lives
     * in DriveVersioningService; this service stays the owner of paths,
     * quota and tiering.
     */
    public function versioning(): DriveVersioningService
    {
        if ($this->versioningService === null) {
            $this->versioningService = new DriveVersioningService($this->config, $this);
        }
        return $this->versioningService;
    }

    /**
     * Lazy universal-search indexer accessor.
     */
    private function getSearchIndexer(): SearchIndexerService
    {
        if ($this->searchIndexer === null) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }

    /**
     * Re-index a drive file's content for universal search (Meilisearch + MySQL).
     *
     * Centralizes search indexing so EVERY content-mutation path keeps the
     * index fresh — not just the multipart upload path. Without this, files
     * created/edited via OnlyOffice or saved from email attachments match by
     * filename only and never highlight their body text.
     *
     * Contract:
     *  - Must be called with a FRESHLY-loaded drive_files row (after the DB
     *    write is fully persisted) so the indexed metadata matches disk.
     *  - Must NEVER be called inside an open transaction: indexing reads the
     *    committed file from disk, so uncommitted rows would index stale data.
     *  - Is non-fatal by design: a search-engine hiccup must never break a save.
     *
     * Recursion is impossible here: indexDriveFile() only reads the filesystem
     * and upserts into universal_search_index + Meilisearch; it never writes
     * drive_files nor calls back into DriveService.
     */
    private function reindexFileForSearch(array $file): void
    {
        // Guards: skip rows that can't be indexed or have no content yet.
        // The size===0 guard also damps rapid OnlyOffice force-save callbacks
        // on brand-new/empty rows.
        if (empty($file['id']) || empty($file['user_email'])) {
            return;
        }
        if ((int)($file['size'] ?? 0) === 0) {
            return;
        }

        try {
            // A missing/deleted folder must not block indexing; indexDriveFile
            // accepts a null folder.
            $folder = null;
            if (!empty($file['folder_id'])) {
                $folder = $this->getFolder($file['user_email'], (int)$file['folder_id']) ?: null;
            }

            $this->getSearchIndexer()->indexDriveFile($file['user_email'], $file, $folder);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[DriveSearch] Failed indexing file %s (%s): %s',
                $file['id'] ?? 'unknown',
                $file['original_name'] ?? $file['filename'] ?? 'unknown',
                $e->getMessage()
            ));
        }
    }
    
    /**
     * Get the database connection
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    /**
     * Get the StorageService instance
     */
    public function getStorage(): StorageService
    {
        return $this->storage;
    }
    
    /**
     * Get storage statistics (disk usage, driver info)
     */
    public function getStorageStats(): array
    {
        return $this->storage->getUsageStats();
    }
    
    /**
     * Test storage connection
     */
    public function testStorageConnection(): array
    {
        return $this->storage->testConnection();
    }
    
    private function ensureTablesExist(): void
    {
        try {
            // Folders table (with share columns for folder sharing)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_folders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    parent_id INT DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    share_token VARCHAR(64) DEFAULT NULL,
                    share_expires DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    INDEX idx_parent_id (parent_id),
                    INDEX idx_share_token (share_token),
                    FOREIGN KEY (parent_id) REFERENCES drive_folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Migration: Add share columns to existing drive_folders table if they don't exist
            $this->addColumnIfNotExists('drive_folders', 'share_token', 'VARCHAR(64) DEFAULT NULL');
            $this->addColumnIfNotExists('drive_folders', 'share_expires', 'DATETIME NULL');
            $this->addColumnIfNotExists('drive_folders', 'color', 'VARCHAR(20) DEFAULT NULL');
            
            // Migration: Add trash columns to drive_folders
            $this->addColumnIfNotExists('drive_folders', 'created_by', 'VARCHAR(255) DEFAULT NULL');
            $this->addColumnIfNotExists('drive_folders', 'is_trashed', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('drive_folders', 'trashed_at', 'TIMESTAMP NULL');
            $this->addColumnIfNotExists('drive_folders', 'original_parent_id', 'INT DEFAULT NULL');
            
            // Migration: Add enhanced sharing columns to drive_folders
            $this->addColumnIfNotExists('drive_folders', 'max_downloads', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('drive_folders', 'download_count', 'INT DEFAULT 0');
            $this->addColumnIfNotExists('drive_folders', 'share_password', 'VARCHAR(255) DEFAULT NULL');
            
            // Migration: Add client_id to drive_folders for client linking
            $this->addColumnIfNotExists('drive_folders', 'client_id', 'INT DEFAULT NULL');
            
            // Migration: Add board_id to drive_folders for board-linked folders
            $this->addColumnIfNotExists('drive_folders', 'board_id', 'INT DEFAULT NULL');
            $this->addIndexIfNotExists('drive_folders', 'idx_client_id', 'client_id');
            
            // Migration: Add size column to drive_folders for folder size tracking
            $this->addColumnIfNotExists('drive_folders', 'size', 'BIGINT DEFAULT 0');
            
            // Files table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_files (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    folder_id INT DEFAULT NULL,
                    filename VARCHAR(255) NOT NULL COMMENT 'Stored filename (hashed)',
                    original_name VARCHAR(255) NOT NULL,
                    size BIGINT NOT NULL DEFAULT 0,
                    mime_type VARCHAR(100) DEFAULT 'application/octet-stream',
                    share_token VARCHAR(64) DEFAULT NULL,
                    share_expires TIMESTAMP NULL,
                    is_email_attachment TINYINT(1) DEFAULT 0 COMMENT 'If 1, auto-delete when share expires',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    INDEX idx_folder_id (folder_id),
                    INDEX idx_share_token (share_token),
                    INDEX idx_email_attachment (is_email_attachment, share_expires),
                    FOREIGN KEY (folder_id) REFERENCES drive_folders(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Migration: Add is_email_attachment column if not exists
            $this->addColumnIfNotExists('drive_files', 'is_email_attachment', 'TINYINT(1) DEFAULT 0');
            
            // Migration: Add versioning and activity tracking columns to drive_files
            $this->addColumnIfNotExists('drive_files', 'created_by', 'VARCHAR(255) DEFAULT NULL');
            $this->addColumnIfNotExists('drive_files', 'last_modified_by', 'VARCHAR(255) DEFAULT NULL');
            $this->addColumnIfNotExists('drive_files', 'current_version', 'INT DEFAULT 1');
            $this->addColumnIfNotExists('drive_files', 'last_opened_at', 'TIMESTAMP NULL');
            $this->addColumnIfNotExists('drive_files', 'last_opened_by', 'VARCHAR(255) DEFAULT NULL');
            
            // Migration: Add trash columns to drive_files
            $this->addColumnIfNotExists('drive_files', 'is_trashed', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('drive_files', 'trashed_at', 'TIMESTAMP NULL');
            $this->addColumnIfNotExists('drive_files', 'original_folder_id', 'INT DEFAULT NULL');
            
            // Migration: Add enhanced sharing columns to drive_files
            $this->addColumnIfNotExists('drive_files', 'max_downloads', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('drive_files', 'download_count', 'INT DEFAULT 0');
            $this->addColumnIfNotExists('drive_files', 'share_password', 'VARCHAR(255) DEFAULT NULL');

            // Migration 169: source email tracking (lets the email view
            // surface a "Saved to Drive" indicator + Share action on the
            // original attachment card after it has been saved).
            $this->addColumnIfNotExists('drive_files', 'source_email_folder', 'VARCHAR(255) DEFAULT NULL');
            $this->addColumnIfNotExists('drive_files', 'source_email_uid', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('drive_files', 'source_email_part', 'VARCHAR(64) DEFAULT NULL');
            $this->addIndexIfNotExists('drive_files', 'idx_drive_files_email_source', 'user_email, source_email_folder, source_email_uid');
            
            // File versions table (migration 190 adds the metadata columns
            // and the unique key on existing installs)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_file_versions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT NOT NULL,
                    version_number INT NOT NULL DEFAULT 1,
                    filename VARCHAR(255) NOT NULL COMMENT 'Stored filename (hashed)',
                    size BIGINT NOT NULL DEFAULT 0,
                    storage_location VARCHAR(10) NULL DEFAULT NULL,
                    mime_type VARCHAR(255) NULL DEFAULT NULL,
                    checksum VARCHAR(64) NULL DEFAULT NULL,
                    label VARCHAR(255) NULL DEFAULT NULL,
                    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                    modified_by VARCHAR(255) NOT NULL COMMENT 'Email of who uploaded this version',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_file_id (file_id),
                    UNIQUE KEY uq_file_version (file_id, version_number)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // User quotas table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_quotas (
                    user_email VARCHAR(255) PRIMARY KEY,
                    quota_bytes BIGINT DEFAULT -1 COMMENT '-1 = unlimited',
                    used_bytes BIGINT DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Folder collaborators table (for user-specific sharing)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_folder_collaborators (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    permission ENUM('viewer', 'editor') DEFAULT 'viewer',
                    invited_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_folder_user (folder_id, user_email),
                    INDEX idx_user_email (user_email),
                    FOREIGN KEY (folder_id) REFERENCES drive_folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Editing status table (tracks who is currently editing which files)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS drive_editing_status (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT DEFAULT NULL,
                    filename VARCHAR(255) NOT NULL COMMENT 'Original filename being edited',
                    folder_id INT DEFAULT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_heartbeat TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_file_id (file_id),
                    INDEX idx_folder_id (folder_id),
                    INDEX idx_user_email (user_email),
                    INDEX idx_last_heartbeat (last_heartbeat)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
        } catch (\PDOException $e) {
            error_log("DriveService table creation error: " . $e->getMessage());
        }
    }
    
    /**
     * Add column to table if it doesn't exist (for migrations)
     */
    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            
            if ($stmt->fetchColumn() == 0) {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                error_log("DriveService: Added column {$column} to {$table}");
            }
        } catch (\PDOException $e) {
            error_log("DriveService migration error ({$table}.{$column}): " . $e->getMessage());
        }
    }
    
    /**
     * Add index to table if it doesn't exist (for migrations)
     */
    private function addIndexIfNotExists(string $table, string $indexName, string $column): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $indexName]);
            
            if ($stmt->fetchColumn() == 0) {
                $this->db->exec("ALTER TABLE {$table} ADD INDEX {$indexName} ({$column})");
                error_log("DriveService: Added index {$indexName} to {$table}");
            }
        } catch (\PDOException $e) {
            error_log("DriveService migration error (index {$table}.{$indexName}): " . $e->getMessage());
        }
    }
    
    /**
     * Get user's storage path.
     *
     * Side effect: updates $this->lastResolvedTier with the tier actually used
     * ('nfs' for the configured primary, 'local' when the NAS fallback fired).
     * Callers that record `storage_location` in the DB MUST use that field
     * (or the resolveStorageLocation() helper) instead of $this->storage->getDriver()
     * - the StorageService driver only reflects the configured primary and
     * does not know about runtime fallback.
     */
    public function getUserPath(string $email): string
    {
        $hash = md5(strtolower($email));
        $path = $this->storagePath . '/' . $hash;

        if (NasHealthCheck::shouldSkipPath($path)) {
            $localBase = dirname(__DIR__, 2) . '/storage/drive';
            $configPath = $this->config['drive']['storage_path'] ?? '';
            if ($configPath && !NasHealthCheck::isNasPath($configPath)) {
                $localBase = $configPath;
            }
            $localFallback = $localBase . '/' . $hash;
            if (!is_dir($localFallback)) {
                mkdir($localFallback, 0755, true);
            }
            error_log("[DriveService] NAS unavailable, using local fallback: $localFallback");
            $this->lastResolvedTier = 'local';
            return $localFallback;
        }

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // The configured base path itself drives the tier label: if Panel
        // resolved a non-NAS primary (e.g. local-only storage), we still
        // report 'local' so the column matches reality.
        $this->lastResolvedTier = NasHealthCheck::isNasPath($this->storagePath) ? 'nfs' : 'local';
        return $path;
    }

    /**
     * Returns the storage_location value to persist for an insert that just
     * called getUserPath(). When an explicit path is provided, the tier is
     * derived from the path itself (used by code paths like copyFile() where
     * the destination is computed without going through getUserPath again).
     */
    public function resolveStorageLocation(?string $explicitPath = null): string
    {
        if ($explicitPath !== null) {
            return NasHealthCheck::isNasPath($explicitPath) ? 'nfs' : 'local';
        }
        return $this->lastResolvedTier ?: $this->storage->getDriver();
    }

    /**
     * If the just-inserted file landed on local disk because the NAS was
     * unavailable, record a row in drive_pending_nas_migration so the
     * `drive-pending-nas-migrate` cron can move the bytes to NAS once the
     * mount is healthy again.
     *
     * No-op when:
     *   - The file did not fall back (lastResolvedTier !== 'local'), OR
     *   - The configured primary is not NAS (i.e. local-only deployment;
     *     there is nothing to migrate to).
     *
     * This method swallows all errors: the upload itself already succeeded
     * and the file is safe on local disk. The worst case if enqueue fails
     * is that the file stays on local disk - acceptable, never silent
     * data loss.
     */
    public function enqueueNasMigrationIfNeeded(int $fileId, string $email, string $localPath): void
    {
        try {
            if ($this->lastResolvedTier !== 'local') {
                return;
            }
            if (!NasHealthCheck::isNasPath($this->storagePath)) {
                return;
            }

            $hash = md5(strtolower($email));
            $filename = basename($localPath);
            $nasTargetPath = rtrim($this->storagePath, '/') . '/' . $hash . '/' . $filename;

            $stmt = $this->db->prepare(
                'INSERT INTO drive_pending_nas_migration
                    (file_id, local_path, nas_target_path, user_email, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $fileId,
                $localPath,
                $nasTargetPath,
                strtolower($email),
                'pending',
            ]);

            error_log("[DriveService] Queued file {$fileId} for NAS migration (local={$localPath})");
        } catch (\Throwable $e) {
            error_log('[DriveService] enqueueNasMigrationIfNeeded failed (non-fatal): ' . $e->getMessage());
        }
    }
    
    /**
     * Get user quota info
     */
    public function getQuota(string $email): array
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('SELECT quota_bytes, used_bytes FROM drive_quotas WHERE user_email = ?');
        $stmt->execute([$email]);
        $quota = $stmt->fetch();
        
        if (!$quota) {
            // Create default quota entry
            $this->db->prepare('INSERT INTO drive_quotas (user_email, quota_bytes, used_bytes) VALUES (?, ?, 0)')
                ->execute([$email, $this->defaultQuota]);
            
            $quota = ['quota_bytes' => $this->defaultQuota, 'used_bytes' => 0];
        }
        
        return [
            'quota' => (int)$quota['quota_bytes'], // -1 = unlimited
            'used' => (int)$quota['used_bytes'],
            'available' => $quota['quota_bytes'] == -1 ? -1 : max(0, $quota['quota_bytes'] - $quota['used_bytes']),
            'unlimited' => $quota['quota_bytes'] == -1,
        ];
    }
    
    /**
     * Update used space
     */
    public function updateUsedSpace(string $email, int $delta): void
    {
        $email = strtolower($email);
        
        // Ensure quota record exists
        $this->getQuota($email);
        
        $this->db->prepare('UPDATE drive_quotas SET used_bytes = GREATEST(0, used_bytes + ?) WHERE user_email = ?')
            ->execute([$delta, $email]);
    }
    
    /**
     * Check if user has enough quota
     */
    public function hasQuota(string $email, int $bytes): bool
    {
        $quota = $this->getQuota($email);
        if ($quota['unlimited']) return true;
        return $quota['available'] >= $bytes;
    }
    
    // ===== FOLDER OPERATIONS =====
    
    /**
     * Get all folders for a user (filtered by parent, excludes trashed)
     */
    public function getFolders(string $email, ?int $parentId = null): array
    {
        $email = strtolower($email);
        
        if ($parentId === null) {
            $stmt = $this->db->prepare('
                SELECT f.*, b.name as board_name, c.name as client_name 
                FROM drive_folders f 
                LEFT JOIN webmail_boards b ON f.board_id = b.id 
                LEFT JOIN clients c ON f.client_id = c.id
                WHERE f.user_email = ? AND f.parent_id IS NULL AND (f.is_trashed = 0 OR f.is_trashed IS NULL) 
                ORDER BY f.name
            ');
            $stmt->execute([$email]);
        } else {
            $stmt = $this->db->prepare('
                SELECT f.*, b.name as board_name, c.name as client_name 
                FROM drive_folders f 
                LEFT JOIN webmail_boards b ON f.board_id = b.id 
                LEFT JOIN clients c ON f.client_id = c.id
                WHERE f.user_email = ? AND f.parent_id = ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL) 
                ORDER BY f.name
            ');
            $stmt->execute([$email, $parentId]);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all folders for a user (for tree view, excludes trashed)
     */
    public function getAllFolders(string $email): array
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare('
            SELECT f.*, c.name as client_name 
            FROM drive_folders f 
            LEFT JOIN clients c ON f.client_id = c.id
            WHERE f.user_email = ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL) 
            ORDER BY f.name
        ');
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all subfolder IDs recursively under a given folder
     * Used for client folder inheritance - all subfolders inherit the client association
     * 
     * @param string $email User's email
     * @param int $folderId Parent folder ID
     * @return array Array of all subfolder IDs (at any depth)
     */
    public function getAllSubfolderIds(string $email, int $folderId): array
    {
        $email = strtolower($email);
        $allSubfolderIds = [];
        
        // Get direct children of this folder
        $stmt = $this->db->prepare('
            SELECT id FROM drive_folders 
            WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email, $folderId]);
        $children = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // For each child, add it and recursively get its children
        foreach ($children as $childId) {
            $allSubfolderIds[] = (int)$childId;
            // Recursively get subfolders of this child
            $childSubfolders = $this->getAllSubfolderIds($email, (int)$childId);
            $allSubfolderIds = array_merge($allSubfolderIds, $childSubfolders);
        }
        
        return $allSubfolderIds;
    }
    
    /**
     * Get folder by ID
     */
    public function getFolder(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Create folder
     */
    public function createFolder(string $email, string $name, ?int $parentId = null, ?int $clientId = null): ?array
    {
        $email = strtolower($email);
        
        try {
            if ($parentId !== null) {
                $parent = $this->getFolder($email, $parentId);
                if (!$parent || !empty($parent['is_trashed'])) {
                    return null;
                }
            }

            $stmt = $this->db->prepare('INSERT INTO drive_folders (user_email, name, parent_id, client_id, size) VALUES (?, ?, ?, ?, 0)');
            $stmt->execute([$email, $name, $parentId, $clientId]);
            
            return $this->getFolder($email, (int)$this->db->lastInsertId());
        } catch (\PDOException $e) {
            error_log("DriveService createFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update folder's client_id
     */
    public function updateFolderClient(string $email, int $folderId, ?int $clientId): bool
    {
        $email = strtolower($email);
        
        try {
            $stmt = $this->db->prepare('UPDATE drive_folders SET client_id = ? WHERE user_email = ? AND id = ?');
            $stmt->execute([$clientId, $email, $folderId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("DriveService updateFolderClient error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate folder size (sum of all files in folder and subfolders)
     */
    public function calculateFolderSize(string $email, int $folderId): int
    {
        $email = strtolower($email);
        $totalSize = 0;
        
        // Sum direct files in this folder
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(size), 0) as total 
            FROM drive_files 
            WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email, $folderId]);
        $result = $stmt->fetch();
        $totalSize += (int)($result['total'] ?? 0);
        
        // Add sizes from subfolders recursively
        $stmt = $this->db->prepare('
            SELECT id FROM drive_folders 
            WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        foreach ($subfolders as $subfolder) {
            $totalSize += $this->calculateFolderSize($email, $subfolder['id']);
        }
        
        return $totalSize;
    }
    
    /**
     * Update folder size in database
     */
    public function updateFolderSize(string $email, int $folderId): int
    {
        $email = strtolower($email);
        $size = $this->calculateFolderSize($email, $folderId);
        
        try {
            $stmt = $this->db->prepare('UPDATE drive_folders SET size = ? WHERE user_email = ? AND id = ?');
            $stmt->execute([$size, $email, $folderId]);
        } catch (\PDOException $e) {
            error_log("DriveService updateFolderSize error: " . $e->getMessage());
        }
        
        return $size;
    }
    
    /**
     * Update size for a folder and all its parent folders
     */
    public function updateFolderSizeWithParents(string $email, ?int $folderId): void
    {
        if (!$folderId) return;
        
        $email = strtolower($email);
        
        // Update the folder itself
        $this->updateFolderSize($email, $folderId);
        
        // Get parent folder and update recursively
        $stmt = $this->db->prepare('SELECT parent_id FROM drive_folders WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $folderId]);
        $folder = $stmt->fetch();
        
        if ($folder && $folder['parent_id']) {
            $this->updateFolderSizeWithParents($email, $folder['parent_id']);
        }
    }
    
    /**
     * Recalculate sizes for all folders (batch operation)
     */
    public function recalculateAllFolderSizes(string $email): void
    {
        $email = strtolower($email);
        
        // Get all root folders first
        $stmt = $this->db->prepare('
            SELECT id FROM drive_folders 
            WHERE user_email = ? AND parent_id IS NULL AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email]);
        $rootFolders = $stmt->fetchAll();
        
        foreach ($rootFolders as $folder) {
            $this->recalculateFolderSizeRecursive($email, $folder['id']);
        }
    }
    
    /**
     * Recursively recalculate folder size (bottom-up)
     */
    private function recalculateFolderSizeRecursive(string $email, int $folderId): int
    {
        $email = strtolower($email);
        
        // First recalculate all subfolders
        $stmt = $this->db->prepare('
            SELECT id FROM drive_folders 
            WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        $subfolderSize = 0;
        foreach ($subfolders as $subfolder) {
            $subfolderSize += $this->recalculateFolderSizeRecursive($email, $subfolder['id']);
        }
        
        // Calculate direct files size
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(size), 0) as total 
            FROM drive_files 
            WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$email, $folderId]);
        $result = $stmt->fetch();
        $directFilesSize = (int)($result['total'] ?? 0);
        
        $totalSize = $directFilesSize + $subfolderSize;
        
        // Update this folder's size
        $stmt = $this->db->prepare('UPDATE drive_folders SET size = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$totalSize, $email, $folderId]);
        
        return $totalSize;
    }
    
    /**
     * Find client by email address (checks domain or full email for generic providers)
     */
    public function findClientByEmail(string $userEmail, string $senderEmail): ?array
    {
        $userEmail = strtolower($userEmail);
        $senderEmail = strtolower(trim($senderEmail));
        
        // Extract domain from sender email
        $parts = explode('@', $senderEmail);
        if (count($parts) !== 2 || empty($parts[1])) {
            return null;
        }
        $domain = $parts[1];
        
        // List of generic email providers - for these, use full email as client identifier
        $genericDomains = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 
            'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
            'aol.com', 'mail.com', 'protonmail.com', 'proton.me',
            'yandex.com', 'gmx.com', 'zoho.com'
        ];
        
        $isGeneric = in_array(strtolower($domain), $genericDomains);
        $clientIdentifier = $isGeneric ? $senderEmail : $domain;
        
        // Look up client with this domain/email
        $stmt = $this->db->prepare('
            SELECT id, display_name, domain, drive_folder_id
            FROM clients
            WHERE user_email = ? AND domain = ?
        ');
        $stmt->execute([$userEmail, $clientIdentifier]);
        $result = $stmt->fetch();
        
        if ($result) {
            return [
                'id' => (int)$result['id'],
                'name' => $result['display_name'],
                'domain' => $result['domain'],
                'drive_folder_id' => $result['drive_folder_id'] ? (int)$result['drive_folder_id'] : null
            ];
        }
        
        // Check domain aliases (merged clients)
        try {
            $stmt = $this->db->prepare('
                SELECT c.id, c.display_name, c.domain, c.drive_folder_id
                FROM client_domain_aliases cda
                JOIN clients c ON c.id = cda.client_id AND c.user_email = cda.user_email
                WHERE cda.user_email = ? AND cda.alias_domain = ?
                LIMIT 1
            ');
            $stmt->execute([$userEmail, $clientIdentifier]);
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'id' => (int)$result['id'],
                    'name' => $result['display_name'],
                    'domain' => $result['domain'],
                    'drive_folder_id' => $result['drive_folder_id'] ? (int)$result['drive_folder_id'] : null
                ];
            }
        } catch (\PDOException $e) {
            // Table may not exist yet
        }
        
        return null;
    }
    
    /**
     * Find a folder by name or create it if it doesn't exist
     */
    public function findOrCreateFolder(string $email, string $name, ?int $parentId = null): ?array
    {
        $email = strtolower($email);
        
        // Try to find existing folder (exclude trashed folders)
        $sql = 'SELECT * FROM drive_folders WHERE user_email = ? AND name = ? AND (is_trashed = 0 OR is_trashed IS NULL)';
        $params = [$email, $name];
        
        if ($parentId === null) {
            $sql .= ' AND parent_id IS NULL';
        } else {
            $sql .= ' AND parent_id = ?';
            $params[] = $parentId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $folder = $stmt->fetch();
        
        if ($folder) {
            return $folder;
        }
        
        // Create if not found
        return $this->createFolder($email, $name, $parentId);
    }
    
    /**
     * Get or create folder structure for board files
     * Creates: Boards / [Board Name] /
     */
    public function getOrCreateBoardFolder(string $email, string $boardName): ?array
    {
        $email = strtolower($email);
        
        // Create/find "Boards" root folder
        $boardsFolder = $this->findOrCreateFolder($email, 'Boards', null);
        if (!$boardsFolder) {
            error_log("DriveService: Failed to create/find Boards folder");
            return null;
        }
        
        // Clean board name for folder use
        $cleanBoardName = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $boardName);
        $cleanBoardName = trim($cleanBoardName);
        if (empty($cleanBoardName)) {
            $cleanBoardName = 'Board';
        }
        
        // Create/find board-specific folder
        $boardFolder = $this->findOrCreateFolder($email, $cleanBoardName, $boardsFolder['id']);
        if (!$boardFolder) {
            error_log("DriveService: Failed to create/find board folder: $cleanBoardName");
            return null;
        }
        
        return $boardFolder;
    }
    
    /**
     * Rename folder
     */
    public function renameFolder(string $email, int $id, string $newName): bool
    {
        $stmt = $this->db->prepare('UPDATE drive_folders SET name = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$newName, strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Update folder color
     */
    public function updateFolderColor(string $email, int $id, ?string $color): bool
    {
        $stmt = $this->db->prepare('UPDATE drive_folders SET color = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$color, strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Move folder to a new parent (or root if parentId is null)
     */
    public function moveFolder(string $email, int $id, ?int $parentId): bool
    {
        $email = strtolower($email);
        
        // Verify folder exists and belongs to user
        $folder = $this->getFolder($email, $id);
        if (!$folder) {
            return false;
        }
        
        // If moving to a parent, verify parent exists and is not a descendant
        if ($parentId !== null) {
            $parent = $this->getFolder($email, $parentId);
            if (!$parent) {
                return false;
            }
            
            // Check that parent is not a descendant of the folder being moved
            if ($this->isDescendantOf($email, $parentId, $id)) {
                return false;
            }
        }
        
        $stmt = $this->db->prepare('UPDATE drive_folders SET parent_id = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$parentId, $email, $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Check if a folder is a descendant of another folder
     */
    private function isDescendantOf(string $email, int $folderId, int $ancestorId): bool
    {
        $current = $this->getFolder($email, $folderId);
        while ($current && $current['parent_id'] !== null) {
            if ($current['parent_id'] === $ancestorId) {
                return true;
            }
            $current = $this->getFolder($email, $current['parent_id']);
        }
        return false;
    }
    
    /**
     * Check if a folder is directly linked to a board (the main board folder)
     * Only protects the main folder, not subfolders inside it
     * @return array|null Returns board info if referenced, null if not
     */
    public function getFolderBoardReference(int $folderId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT b.id, b.name 
            FROM webmail_boards b 
            INNER JOIN drive_folders df ON df.board_id = b.id 
            WHERE df.id = ?
        ");
        $stmt->execute([$folderId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get drive folder for a specific board
     * @param string $userEmail
     * @param int $boardId
     * @return array|null Folder data or null if not found
     */
    public function getBoardFolder(string $userEmail, int $boardId): ?array
    {
        $userEmail = strtolower($userEmail);
        
        // First try by board_id
        $stmt = $this->db->prepare("
            SELECT id, name, parent_id, color, created_at
            FROM drive_folders
            WHERE user_email = ? AND board_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $boardId]);
        $folder = $stmt->fetch();
        
        if ($folder) {
            return $folder;
        }
        
        // Fallback: Try to find by board name inside "Boards" folder
        // This handles boards created before board_id linking was added
        $stmt = $this->db->prepare("
            SELECT b.name as board_name
            FROM webmail_boards b
            WHERE b.id = ?
        ");
        $stmt->execute([$boardId]);
        $board = $stmt->fetch();
        
        if (!$board) {
            return null;
        }
        
        // Find "Boards" folder
        $stmt = $this->db->prepare("
            SELECT id FROM drive_folders
            WHERE user_email = ? AND name = 'Boards' AND parent_id IS NULL AND (is_trashed = 0 OR is_trashed IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$userEmail]);
        $boardsParent = $stmt->fetch();
        
        if (!$boardsParent) {
            return null;
        }
        
        // Find folder with board name inside "Boards"
        $stmt = $this->db->prepare("
            SELECT id, name, parent_id, color, created_at
            FROM drive_folders
            WHERE user_email = ? AND parent_id = ? AND name = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $boardsParent['id'], $board['board_name']]);
        $folder = $stmt->fetch();
        
        if ($folder) {
            // Update the folder to have board_id for future lookups
            $updateStmt = $this->db->prepare("UPDATE drive_folders SET board_id = ? WHERE id = ?");
            $updateStmt->execute([$boardId, $folder['id']]);
        }
        
        return $folder;
    }
    
    /**
     * Delete folder (and all contents recursively)
     * Protected folders: main board folder, "Boards" system folder
     * @return bool|string True on success, error message string on failure
     */
    public function deleteFolder(string $email, int $id): bool|string
    {
        $email = strtolower($email);
        
        // Check if this is the "Boards" system folder (root level folder named "Boards")
        $stmt = $this->db->prepare('SELECT name, parent_id FROM drive_folders WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $email]);
        $folder = $stmt->fetch();
        
        if ($folder && $folder['name'] === 'Boards' && $folder['parent_id'] === null) {
            return "Cannot delete the Boards folder - it is a system folder that contains your board project folders.";
        }
        
        if ($folder && $folder['name'] === 'Attachments' && $folder['parent_id'] === null) {
            return "Cannot delete the Attachments folder - it is a system folder that contains your saved email attachments.";
        }
        
        if ($folder && $folder['name'] === 'Chats' && $folder['parent_id'] === null) {
            return "Cannot delete the Chats folder - it is a system folder that contains your chat conversation files.";
        }
        
        if ($folder && $folder['name'] === 'Invoices' && $folder['parent_id'] === null) {
            return "Cannot delete the Invoices folder - it is a system folder that contains your generated invoice PDFs.";
        }
        
        if ($folder && $folder['name'] === 'Moodboards' && $folder['parent_id'] === null) {
            return "Cannot delete the Moodboards folder - it is a system folder that contains your moodboard assets.";
        }
        
        // Only check if THIS folder is directly linked to a board (main board folder)
        // Subfolders and files inside board folders CAN be deleted
        $boardRef = $this->getFolderBoardReference($id);
        if ($boardRef) {
            return "Cannot delete folder - it is the main folder linked to board \"{$boardRef['name']}\". Unlink the folder from the board first.";
        }
        
        // Recursively delete all contents
        $this->deleteFolderContentsRecursive($email, $id);
        
        // Delete the folder itself
        $stmt = $this->db->prepare('DELETE FROM drive_folders WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Batched folder delete: runs the same guards and recursive content
     * cleanup as deleteFolder() but defers the parent-folder-size walk to
     * the end so a batch of N folders does ONE aggregation per unique
     * affected parent instead of N.
     *
     * @param string $email
     * @param array<int> $folderIds
     * @return array{success:int,failed:int,errors:array<string>,affectedParents:array<int>}
     */
    public function deleteManyFolders(string $email, array $folderIds): array
    {
        $email = strtolower($email);
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'errors' => [], 'affectedParents' => []];
        if (empty($folderIds)) return $result;

        $affectedParents = [];

        foreach ($folderIds as $id) {
            // Re-fetch each row inside the loop so we see folders that
            // were already removed earlier in the batch (e.g. a parent
            // deleted earlier already swept this child away).
            $stmt = $this->db->prepare('SELECT name, parent_id FROM drive_folders WHERE id = ? AND user_email = ?');
            $stmt->execute([$id, $email]);
            $folder = $stmt->fetch();

            if (!$folder) {
                // Already gone -- count as success (idempotent).
                $result['success']++;
                continue;
            }

            // System-folder guards. Same list as deleteFolder().
            if ($folder['parent_id'] === null) {
                $sysNames = ['Boards', 'Attachments', 'Chats', 'Invoices', 'Moodboards'];
                if (in_array($folder['name'], $sysNames, true)) {
                    $result['failed']++;
                    $result['errors'][] = "Folder #{$id} ({$folder['name']}) is a system folder";
                    continue;
                }
            }

            // Board-link guard.
            $boardRef = $this->getFolderBoardReference($id);
            if ($boardRef) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id} is the main folder linked to board \"{$boardRef['name']}\"";
                continue;
            }

            // Track parent for deferred size update.
            if ($folder['parent_id'] !== null) {
                $affectedParents[(int)$folder['parent_id']] = true;
            }

            try {
                $this->deleteFolderContentsRecursive($email, $id);
                $delStmt = $this->db->prepare('DELETE FROM drive_folders WHERE user_email = ? AND id = ?');
                $delStmt->execute([$email, $id]);
                if ($delStmt->rowCount() > 0) {
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Folder #{$id} not found";
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id}: " . $e->getMessage();
                error_log("[DriveService::deleteManyFolders] {$id}: " . $e->getMessage());
            }
        }

        $result['affectedParents'] = array_keys($affectedParents);
        return $result;
    }

    /**
     * Batched file delete: physical unlink + DB removal for many files at
     * once. Preserves the per-file deleteFilePhysical() semantics (NAS
     * unlink, .trashed cleanup, per-file quota update) but collapses the
     * DB DELETE into a single IN(...) query and defers folder-size
     * aggregation to ONE call per unique affected folder.
     *
     * @param string $email
     * @param array<int> $fileIds
     * @return array{success:int,failed:int,errors:array<string>,freedBytes:int,affectedFolders:array<int>}
     */
    public function deleteManyFiles(string $email, array $fileIds): array
    {
        $email = strtolower($email);
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'errors' => [], 'freedBytes' => 0, 'affectedFolders' => []];
        if (empty($fileIds)) return $result;

        // Load all rows in one query.
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, folder_id, filename, size, storage_location, nas_relative_path
             FROM drive_files
             WHERE user_email = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$email], $fileIds));
        $files = $stmt->fetchAll();

        $foundIds = array_column($files, 'id');
        $missing = array_diff($fileIds, array_map('intval', $foundIds));
        foreach ($missing as $missId) {
            // Idempotent: already-gone counts as success.
            $result['success']++;
        }

        $affectedFolders = [];

        foreach ($files as $file) {
            try {
                $this->deleteFilePhysical($email, $file);
                $result['freedBytes'] += (int)($file['size'] ?? 0);
                if (!empty($file['folder_id'])) {
                    $affectedFolders[(int)$file['folder_id']] = true;
                }
                $result['success']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "File #{$file['id']}: " . $e->getMessage();
                error_log("[DriveService::deleteManyFiles] {$file['id']}: " . $e->getMessage());
            }
        }

        // Single batched DB DELETE.
        if (!empty($foundIds)) {
            $foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
            $delStmt = $this->db->prepare(
                "DELETE FROM drive_files WHERE user_email = ? AND id IN ({$foundPlaceholders})"
            );
            $delStmt->execute(array_merge([$email], $foundIds));
        }

        $result['affectedFolders'] = array_keys($affectedFolders);
        return $result;
    }

    /**
     * Batched file move: one UPDATE WHERE id IN (...) instead of N.
     * Folder-size aggregation is deferred -- caller should run
     * updateFolderSizeWithParents() once per unique folder in
     * `affectedFolders` (which includes both old folders and the target).
     *
     * @param string $email
     * @param array<int> $fileIds
     * @param int|null $targetFolderId
     * @return array{success:int,failed:int,affectedFolders:array<int>}
     */
    public function moveManyFiles(string $email, array $fileIds, ?int $targetFolderId): array
    {
        $email = strtolower($email);
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'affectedFolders' => []];
        if (empty($fileIds)) return $result;

        // Capture old folders.
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, folder_id FROM drive_files WHERE user_email = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$email], $fileIds));
        $rows = $stmt->fetchAll();

        $affected = [];
        $foundIds = [];
        foreach ($rows as $r) {
            $foundIds[] = (int)$r['id'];
            if (!empty($r['folder_id'])) $affected[(int)$r['folder_id']] = true;
        }
        if ($targetFolderId !== null) $affected[$targetFolderId] = true;

        if (!empty($foundIds)) {
            $foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
            $updStmt = $this->db->prepare(
                "UPDATE drive_files SET folder_id = ? WHERE user_email = ? AND id IN ({$foundPlaceholders})"
            );
            $updStmt->execute(array_merge([$targetFolderId, $email], $foundIds));
            $result['success'] = $updStmt->rowCount();
        }
        $result['failed'] = count($fileIds) - count($foundIds);
        $result['affectedFolders'] = array_keys($affected);
        return $result;
    }

    /**
     * Batched folder move: same descendant-check guard as moveFolder()
     * but collapses the writes for movable folders into one UPDATE and
     * defers parent-size aggregation to the caller.
     *
     * @param string $email
     * @param array<int> $folderIds
     * @param int|null $targetParentId
     * @return array{success:int,failed:int,errors:array<string>,affectedParents:array<int>}
     */
    public function moveManyFolders(string $email, array $folderIds, ?int $targetParentId): array
    {
        $email = strtolower($email);
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'errors' => [], 'affectedParents' => []];
        if (empty($folderIds)) return $result;

        // Target sanity: if provided, must exist for this user.
        if ($targetParentId !== null) {
            $parent = $this->getFolder($email, $targetParentId);
            if (!$parent) {
                $result['failed'] = count($folderIds);
                $result['errors'][] = "Target folder #{$targetParentId} not found";
                return $result;
            }
        }

        $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, parent_id FROM drive_folders WHERE user_email = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$email], $folderIds));
        $rows = $stmt->fetchAll();

        $eligible = [];
        $affected = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];

            // Reject self-move and moving into a descendant.
            if ($targetParentId !== null && $targetParentId === $id) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id}: cannot move into itself";
                continue;
            }
            if ($targetParentId !== null && $this->isDescendantOf($email, $targetParentId, $id)) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id}: cannot move into its own descendant";
                continue;
            }

            $eligible[] = $id;
            if (!empty($r['parent_id'])) $affected[(int)$r['parent_id']] = true;
        }

        $result['failed'] += count($folderIds) - count($rows);

        if ($targetParentId !== null) $affected[$targetParentId] = true;

        if (!empty($eligible)) {
            $eligiblePlaceholders = implode(',', array_fill(0, count($eligible), '?'));
            $updStmt = $this->db->prepare(
                "UPDATE drive_folders SET parent_id = ? WHERE user_email = ? AND id IN ({$eligiblePlaceholders})"
            );
            $updStmt->execute(array_merge([$targetParentId, $email], $eligible));
            $result['success'] = $updStmt->rowCount();
        }

        $result['affectedParents'] = array_keys($affected);
        return $result;
    }

    /**
     * Batched soft-delete: mark many files as trashed in a single UPDATE.
     * Preserves the per-file semantics of trashFile() (snapshot
     * original_folder_id, NULL out folder_id, set trashed_at = NOW()).
     *
     * @param string $email
     * @param array<int> $fileIds
     * @return array{success:int,failed:int,affectedFolders:array<int>}
     */
    public function trashManyFiles(string $email, array $fileIds): array
    {
        $email = strtolower($email);
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'affectedFolders' => []];
        if (empty($fileIds)) return $result;

        // Capture old folders for deferred size recompute.
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, folder_id FROM drive_files
             WHERE user_email = ? AND id IN ({$placeholders})
               AND (is_trashed = 0 OR is_trashed IS NULL)"
        );
        $stmt->execute(array_merge([$email], $fileIds));
        $rows = $stmt->fetchAll();

        $foundIds = [];
        $affected = [];
        foreach ($rows as $r) {
            $foundIds[] = (int)$r['id'];
            if (!empty($r['folder_id'])) $affected[(int)$r['folder_id']] = true;
        }

        if (!empty($foundIds)) {
            $foundPlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
            $upd = $this->db->prepare(
                "UPDATE drive_files
                 SET is_trashed = 1, trashed_at = NOW(),
                     original_folder_id = folder_id, folder_id = NULL
                 WHERE user_email = ? AND id IN ({$foundPlaceholders})"
            );
            $upd->execute(array_merge([$email], $foundIds));
            $result['success'] = $upd->rowCount();
        }
        $result['failed'] = count($fileIds) - $result['success'];
        $result['affectedFolders'] = array_keys($affected);
        return $result;
    }

    /**
     * Batched soft-delete: trash many folders + recursively trash their
     * contents. Applies the same system-folder and board-link guards as
     * trashFolder() and aggregates affected parent folders for a single
     * size walk per parent.
     *
     * @param string $email
     * @param array<int> $folderIds
     * @return array{success:int,failed:int,errors:array<string>,affectedParents:array<int>}
     */
    public function trashManyFolders(string $email, array $folderIds): array
    {
        $email = strtolower($email);
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'errors' => [], 'affectedParents' => []];
        if (empty($folderIds)) return $result;

        $affectedParents = [];
        $systemFolders = ['Boards', 'Attachments', 'Chats', 'Invoices', 'Moodboards'];

        foreach ($folderIds as $id) {
            $stmt = $this->db->prepare('SELECT name, parent_id FROM drive_folders WHERE id = ? AND user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)');
            $stmt->execute([$id, $email]);
            $folder = $stmt->fetch();
            if (!$folder) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id} not found";
                continue;
            }
            if ($folder['parent_id'] === null && in_array($folder['name'], $systemFolders, true)) {
                $result['failed']++;
                $result['errors'][] = "Cannot trash the {$folder['name']} folder - it is a system folder.";
                continue;
            }
            $boardRef = $this->getFolderBoardReference($id);
            if ($boardRef) {
                $result['failed']++;
                $result['errors'][] = "Cannot trash folder - it is linked to board \"{$boardRef['name']}\".";
                continue;
            }
            if ($folder['parent_id'] !== null) {
                $affectedParents[(int)$folder['parent_id']] = true;
            }
            try {
                $upd = $this->db->prepare('
                    UPDATE drive_folders
                    SET is_trashed = 1, trashed_at = NOW(),
                        original_parent_id = parent_id, parent_id = NULL
                    WHERE user_email = ? AND id = ?
                ');
                $upd->execute([$email, $id]);
                if ($upd->rowCount() > 0) {
                    $this->trashFolderContentsRecursive($email, $id);
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Folder #{$id} not updated";
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = "Folder #{$id}: " . $e->getMessage();
                error_log("[DriveService::trashManyFolders] {$id}: " . $e->getMessage());
            }
        }

        $result['affectedParents'] = array_keys($affectedParents);
        return $result;
    }

    /**
     * Batched restore-from-trash for many files. For each file, if its
     * original_folder_id still exists and is not trashed, restore into
     * that folder; otherwise restore at root (folder_id = NULL).
     *
     * @param string $email
     * @param array<int> $fileIds
     * @return array{success:int,failed:int,affectedFolders:array<int>}
     */
    public function restoreManyFiles(string $email, array $fileIds): array
    {
        $email = strtolower($email);
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'affectedFolders' => []];
        if (empty($fileIds)) return $result;

        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, original_folder_id FROM drive_files
             WHERE user_email = ? AND id IN ({$placeholders}) AND is_trashed = 1"
        );
        $stmt->execute(array_merge([$email], $fileIds));
        $rows = $stmt->fetchAll();

        // Resolve which original parents are still alive (single query).
        $origIds = array_values(array_unique(array_filter(array_map(
            fn($r) => isset($r['original_folder_id']) ? (int)$r['original_folder_id'] : 0,
            $rows
        ))));
        $aliveParents = [];
        if (!empty($origIds)) {
            $ph = implode(',', array_fill(0, count($origIds), '?'));
            $aliveStmt = $this->db->prepare(
                "SELECT id FROM drive_folders
                 WHERE user_email = ? AND id IN ({$ph})
                   AND (is_trashed = 0 OR is_trashed IS NULL)"
            );
            $aliveStmt->execute(array_merge([$email], $origIds));
            foreach ($aliveStmt->fetchAll() as $a) $aliveParents[(int)$a['id']] = true;
        }

        // Group by resolved target so each group can use ONE UPDATE.
        $groups = []; // targetFolderId (int|null) => [fileIds]
        $affected = [];
        foreach ($rows as $r) {
            $orig = $r['original_folder_id'] !== null ? (int)$r['original_folder_id'] : null;
            $target = ($orig && isset($aliveParents[$orig])) ? $orig : null;
            $key = $target === null ? 'null' : (string)$target;
            $groups[$key] = $groups[$key] ?? ['target' => $target, 'ids' => []];
            $groups[$key]['ids'][] = (int)$r['id'];
            if ($target !== null) $affected[$target] = true;
        }

        foreach ($groups as $g) {
            $ids = $g['ids'];
            $target = $g['target'];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->db->prepare(
                "UPDATE drive_files
                 SET is_trashed = 0, trashed_at = NULL,
                     folder_id = ?, original_folder_id = NULL
                 WHERE user_email = ? AND id IN ({$ph})"
            );
            $upd->execute(array_merge([$target, $email], $ids));
            $result['success'] += $upd->rowCount();
        }
        $result['failed'] = count($fileIds) - $result['success'];
        $result['affectedFolders'] = array_keys($affected);
        return $result;
    }

    /**
     * Batched restore-from-trash for many folders. Per-folder semantics
     * match restoreFolder(): use original_parent_id when still alive,
     * else root; recursively restore contents.
     *
     * @param string $email
     * @param array<int> $folderIds
     * @return array{success:int,failed:int,affectedParents:array<int>}
     */
    public function restoreManyFolders(string $email, array $folderIds): array
    {
        $email = strtolower($email);
        $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), fn($x) => $x > 0)));
        $result = ['success' => 0, 'failed' => 0, 'affectedParents' => []];
        if (empty($folderIds)) return $result;

        $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, original_parent_id FROM drive_folders
             WHERE user_email = ? AND id IN ({$placeholders}) AND is_trashed = 1"
        );
        $stmt->execute(array_merge([$email], $folderIds));
        $rows = $stmt->fetchAll();

        // Resolve alive original parents in one query.
        $origIds = array_values(array_unique(array_filter(array_map(
            fn($r) => isset($r['original_parent_id']) ? (int)$r['original_parent_id'] : 0,
            $rows
        ))));
        $aliveParents = [];
        if (!empty($origIds)) {
            $ph = implode(',', array_fill(0, count($origIds), '?'));
            $aliveStmt = $this->db->prepare(
                "SELECT id FROM drive_folders
                 WHERE user_email = ? AND id IN ({$ph})
                   AND (is_trashed = 0 OR is_trashed IS NULL)"
            );
            $aliveStmt->execute(array_merge([$email], $origIds));
            foreach ($aliveStmt->fetchAll() as $a) $aliveParents[(int)$a['id']] = true;
        }

        $affected = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $orig = $r['original_parent_id'] !== null ? (int)$r['original_parent_id'] : null;
            $target = ($orig && isset($aliveParents[$orig])) ? $orig : null;

            $upd = $this->db->prepare('
                UPDATE drive_folders
                SET is_trashed = 0, trashed_at = NULL,
                    parent_id = ?, original_parent_id = NULL
                WHERE user_email = ? AND id = ?
            ');
            $upd->execute([$target, $email, $id]);
            if ($upd->rowCount() > 0) {
                $this->restoreFolderContentsRecursive($email, $id);
                $result['success']++;
                if ($target !== null) $affected[$target] = true;
            } else {
                $result['failed']++;
            }
        }
        $result['failed'] += count($folderIds) - count($rows);
        $result['affectedParents'] = array_keys($affected);
        return $result;
    }

    /**
     * Recursively delete folder contents (files, subfolders, and their contents)
     */
    private function deleteFolderContentsRecursive(string $email, int $folderId): void
    {
        // First, recursively delete all subfolders
        $stmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ?');
        $stmt->execute([$email, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        foreach ($subfolders as $subfolder) {
            // Recursively delete subfolder contents
            $this->deleteFolderContentsRecursive($email, $subfolder['id']);
            
            // Delete the subfolder from DB
            $deleteStmt = $this->db->prepare('DELETE FROM drive_folders WHERE user_email = ? AND id = ?');
            $deleteStmt->execute([$email, $subfolder['id']]);
        }
        
        // Get all files in this folder
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND folder_id = ?');
        $stmt->execute([$email, $folderId]);
        $files = $stmt->fetchAll();
        
        // Delete physical files, update quota, and remove from DB
        foreach ($files as $file) {
            $this->deleteFilePhysical($email, $file);
            
            // Delete file from DB
            $deleteStmt = $this->db->prepare('DELETE FROM drive_files WHERE user_email = ? AND id = ?');
            $deleteStmt->execute([$email, $file['id']]);
        }
    }
    
    /**
     * @deprecated Use deleteFolderContentsRecursive instead
     */
    private function getFilesInFolderRecursive(string $email, int $folderId): array
    {
        $files = [];
        
        // Get files in this folder
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND folder_id = ?');
        $stmt->execute([$email, $folderId]);
        $files = array_merge($files, $stmt->fetchAll());
        
        // Get subfolders
        $stmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ?');
        $stmt->execute([$email, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        foreach ($subfolders as $subfolder) {
            $files = array_merge($files, $this->getFilesInFolderRecursive($email, $subfolder['id']));
        }
        
        return $files;
    }
    
    // ===== FILE OPERATIONS =====
    
    /**
     * Get files in folder (excludes trashed)
     */
    public function getFiles(string $email, ?int $folderId = null): array
    {
        $email = strtolower($email);
        
        if ($folderId === null) {
            $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND folder_id IS NULL AND (is_trashed = 0 OR is_trashed IS NULL) ORDER BY original_name');
            $stmt->execute([$email]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL) ORDER BY original_name');
            $stmt->execute([$email, $folderId]);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all files by mime type prefix (searches all folders)
     */
    public function getAllFilesByMimeType(string $email, string $mimePrefix): array
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('
            SELECT f.*
            FROM drive_files f
            WHERE f.user_email = ? 
            AND f.mime_type LIKE ?
            ORDER BY f.uploaded_at DESC
            LIMIT 100
        ');
        $stmt->execute([$email, $mimePrefix . '%']);
        $files = $stmt->fetchAll();
        
        // Add thumbnail/preview URLs for all files
        foreach ($files as &$file) {
            if (!empty($file['share_token'])) {
                // Use public share URL if available
                $file['thumbnail_url'] = "/api/drive/public/{$file['share_token']}/download";
                $file['url'] = "/api/drive/public/{$file['share_token']}/download";
            } else {
                // Use authenticated preview URL as fallback
                $file['thumbnail_url'] = "/api/drive/files/{$file['id']}/preview";
                $file['url'] = "/api/drive/files/{$file['id']}/preview";
            }
            $file['name'] = $file['original_name'] ?? $file['filename'];
        }
        
        return $files;
    }
    
    /**
     * Search folders and files by name across the entire Drive.
     *
     * Mirrors getFolders()/getFiles() result shapes so the frontend grid can
     * render the matches with the same fields it already expects. Partial
     * (substring) match, excludes trashed items, scoped to the user.
     *
     * @return array{folders: array, files: array}
     */
    public function searchByName(string $email, string $query, int $limit = 200): array
    {
        $email = strtolower($email);
        $like = '%' . $query . '%';

        $folderStmt = $this->db->prepare('
            SELECT f.*, b.name as board_name, c.name as client_name
            FROM drive_folders f
            LEFT JOIN webmail_boards b ON f.board_id = b.id
            LEFT JOIN clients c ON f.client_id = c.id
            WHERE f.user_email = ? AND f.name LIKE ? AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ORDER BY f.name
            LIMIT ?
        ');
        $folderStmt->bindValue(1, $email);
        $folderStmt->bindValue(2, $like);
        $folderStmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $folderStmt->execute();
        $folders = $folderStmt->fetchAll();

        $fileStmt = $this->db->prepare('
            SELECT * FROM drive_files
            WHERE user_email = ? AND original_name LIKE ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY original_name
            LIMIT ?
        ');
        $fileStmt->bindValue(1, $email);
        $fileStmt->bindValue(2, $like);
        $fileStmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $fileStmt->execute();
        $files = $fileStmt->fetchAll();

        return ['folders' => $folders, 'files' => $files];
    }
    
    /**
     * Get file by ID
     */
    public function getFile(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get file by share token
     * Checks expiry and download limits
     */
    public function getFileByShareToken(string $token): ?array
    {
        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare('
            SELECT id, user_email, original_name, filename, mime_type, size,
                   share_token, share_expires, storage_location,
                   tier_state, nas_relative_path, checksum,
                   created_at, updated_at
            FROM drive_files
            WHERE share_token = ?
            AND (share_expires IS NULL OR share_expires > ?)
        ');
        $stmt->execute([$token, $now]);
        $file = $stmt->fetch() ?: null;
        
        if (!$file) return null;
        
        // Check download limit (if columns exist)
        try {
            $limitStmt = $this->db->prepare('SELECT max_downloads, download_count FROM drive_files WHERE id = ?');
            $limitStmt->execute([$file['id']]);
            $limits = $limitStmt->fetch();
            if ($limits && $limits['max_downloads'] !== null && $limits['download_count'] >= $limits['max_downloads']) {
                return null; // Download limit reached
            }
            $file['max_downloads'] = $limits['max_downloads'] ?? null;
            $file['download_count'] = $limits['download_count'] ?? 0;
        } catch (\Exception $e) {
            // Columns don't exist yet - no download limits
            $file['max_downloads'] = null;
            $file['download_count'] = 0;
        }
        
        return $file;
    }
    
    /**
     * Check if a shared file requires password
     */
    public function shareRequiresPassword(string $token): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT share_password FROM drive_files WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            return $result && !empty($result['share_password']);
        } catch (\Exception $e) {
            // Column doesn't exist yet
            return false;
        }
    }
    
    /**
     * Validate share password for a file
     */
    public function validateFileSharePassword(string $token, string $password): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT share_password FROM drive_files WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            
            if (!$result || empty($result['share_password'])) {
                return true; // No password required
            }
            
            return password_verify($password, $result['share_password']);
        } catch (\Exception $e) {
            // Column doesn't exist yet
            return true;
        }
    }
    
    /**
     * Increment download count for a file
     */
    public function incrementFileDownloadCount(string $token): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE drive_files SET download_count = download_count + 1 WHERE share_token = ?');
            $stmt->execute([$token]);
        } catch (\Exception $e) {
            // Column doesn't exist yet
        }
    }
    
    /**
     * Get share info for a file (without full file data)
     */
    public function getFileShareInfo(string $token): ?array
    {
        // Use PHP time for consistent timezone handling
        $now = date('Y-m-d H:i:s');
        
        try {
            $stmt = $this->db->prepare('
                SELECT id, original_name, size, mime_type, share_expires, max_downloads, download_count, 
                       (share_password IS NOT NULL AND share_password != "") as requires_password
                FROM drive_files 
                WHERE share_token = ? 
                AND (share_expires IS NULL OR share_expires > ?)
            ');
            $stmt->execute([$token, $now]);
            $info = $stmt->fetch() ?: null;
            
            if (!$info) return null;
            
            $info['requires_password'] = (bool)$info['requires_password'];
            $info['downloads_remaining'] = $info['max_downloads'] !== null 
                ? max(0, $info['max_downloads'] - $info['download_count']) 
                : null;
            $info['limit_reached'] = $info['max_downloads'] !== null && $info['download_count'] >= $info['max_downloads'];
            
            return $info;
        } catch (\Exception $e) {
            // Fallback query without new columns
            $stmt = $this->db->prepare('
                SELECT id, original_name, size, mime_type, share_expires
                FROM drive_files 
                WHERE share_token = ? 
                AND (share_expires IS NULL OR share_expires > ?)
            ');
            $stmt->execute([$token, $now]);
            $info = $stmt->fetch() ?: null;
            
            if (!$info) return null;
            
            $info['requires_password'] = false;
            $info['max_downloads'] = null;
            $info['download_count'] = 0;
            $info['downloads_remaining'] = null;
            $info['limit_reached'] = false;
            
            return $info;
        }
    }
    
    /**
     * Upload a file
     */
    // Blocked file extensions (server-side scripts, executables, double-extension attacks)
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'sh', 'bash', 'csh', 'ksh',
        'jsp', 'jspx', 'asp', 'aspx',
        'py', 'pl', 'rb', 'cgi',
        'htaccess', 'htpasswd',
    ];

    // Blocked MIME types (executable, scripts, server-side code)
    private const BLOCKED_MIME_TYPES = [
        'application/x-httpd-php', 'application/x-php', 'text/x-php',
        'application/x-executable', 'application/x-sharedlib',
        'application/x-msdos-program', 'application/x-msdownload',
        'application/x-dosexec', 'application/bat', 'application/x-bat',
        'application/x-sh', 'application/x-csh',
        'application/java-archive', 'application/x-java-class',
    ];

    public function uploadFile(string $email, array $uploadedFile, ?int $folderId = null): ?array
    {
        $email = strtolower($email);
        
        if (!isset($uploadedFile['tmp_name']) || empty($uploadedFile['tmp_name'])) {
            $postMax = ini_get('post_max_size');
            $uploadMax = ini_get('upload_max_filesize');
            $cl = $_SERVER['CONTENT_LENGTH'] ?? '?';
            error_log("DriveService: No tmp_name. post_max_size={$postMax}, upload_max_filesize={$uploadMax}, content_length={$cl}");
            throw new \RuntimeException("Upload failed: file data is empty (server limits: upload_max_filesize={$uploadMax}, post_max_size={$postMax})");
        }
        
        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            error_log("DriveService: is_uploaded_file returned false for " . $uploadedFile['tmp_name']);
            throw new \RuntimeException("Upload rejected: temporary file validation failed");
        }
        
        // --- File type validation (security: block dangerous uploads) ---
        $originalName = $uploadedFile['name'] ?? '';
        
        $nameParts = explode('.', strtolower($originalName));
        array_shift($nameParts);
        foreach ($nameParts as $ext) {
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                error_log("DriveService: Blocked upload of dangerous file type: {$originalName}");
                throw new \RuntimeException("File type '.{$ext}' is not allowed");
            }
        }
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMimeType = $finfo->file($uploadedFile['tmp_name']);
        if ($detectedMimeType && in_array($detectedMimeType, self::BLOCKED_MIME_TYPES, true)) {
            error_log("DriveService: Blocked upload with dangerous MIME type: {$detectedMimeType} ({$originalName})");
            throw new \RuntimeException("File type '{$detectedMimeType}' is not allowed");
        }
        // --- End file type validation ---
        
        $size = filesize($uploadedFile['tmp_name']);
        
        if (!$this->hasQuota($email, $size)) {
            error_log("DriveService: Quota exceeded for $email, file size: $size");
            throw new \RuntimeException("Not enough storage space (file: " . self::formatSize($size) . ")");
        }
        // Phase 6b: system-wide admission control. No-op when the
        // kill switch is off. May throw StorageBudgetExceededException
        // (extends RuntimeException, caught by DriveController).
        $this->maybeAdmitUpload($size);

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        
        $userPath = $this->getUserPath($email);
        $targetPath = $userPath . '/' . $filename;
        
        debug_log("DriveService: Attempting to move file to $targetPath");
        
        // move_uploaded_file() can return false over a soft NFS mount
        // (root_squash perms / cross-device rename) even though the destination
        // is writable. Fall back to copy() like the avatar upload path before
        // giving up; the size verification below still guards partial writes.
        if (!@move_uploaded_file($uploadedFile['tmp_name'], $targetPath)
            && !@copy($uploadedFile['tmp_name'], $targetPath)) {
            $err = error_get_last()['message'] ?? 'unknown';
            error_log("DriveService: move/copy failed. Target: $targetPath, Error: $err");
            throw new \RuntimeException("Failed to save file to storage");
        }

        // Phase 1d: verify the on-disk size matches the upload size before
        // we commit the DB row. Over a soft NFS mount move_uploaded_file()
        // can return true on a partial write; without this guard the DB
        // would point at a truncated archive and the user gets a corrupt
        // file on download. We use clearstatcache() so we don't hit a
        // cached fstat from a probe earlier in the request.
        clearstatcache(true, $targetPath);
        $written = @filesize($targetPath);
        if ($written === false || $written !== $size) {
            @unlink($targetPath);
            error_log("DriveService: upload size mismatch. Expected={$size}, on-disk=" . var_export($written, true) . ", target={$targetPath}");
            throw new \RuntimeException('Upload write verification failed: size mismatch');
        }

        debug_log("DriveService: File moved successfully to $targetPath");
        
        try {
            debug_log("DriveService: Preparing DB insert for $email, folder: " . ($folderId ?? 'NULL'));
            
            $stmt = $this->db->prepare('
                INSERT INTO drive_files (user_email, folder_id, filename, original_name, size, mime_type, storage_location)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            
            $mimeType = $detectedMimeType ?: (mime_content_type($targetPath) ?? 'application/octet-stream');
            $storageLocation = $this->resolveStorageLocation();

            debug_log("DriveService: Executing insert - filename: $filename, original: {$originalName}, size: $size, mime: $mimeType, storage: $storageLocation");
            
            $stmt->execute([
                $email,
                $folderId,
                $filename,
                $uploadedFile['name'],
                $size,
                $mimeType,
                $storageLocation,
            ]);
            
            $insertId = (int)$this->db->lastInsertId();
            debug_log("DriveService: Insert successful, ID: $insertId");

            // Phase 1c: if the file fell back to local storage because NAS
            // was down, queue it for migration so the next healthy cron pass
            // moves it to NAS.
            $this->enqueueNasMigrationIfNeeded($insertId, $email, $targetPath);

            $this->updateUsedSpace($email, $size);
            debug_log("DriveService: Quota updated");

            if ($folderId) {
                $this->updateFolderSizeWithParents($email, $folderId);
            }

            $file = $this->getFile($email, $insertId);
            if (!$file) {
                throw new \RuntimeException("File saved but failed to read back from database (ID: $insertId)");
            }

            return $file;

        } catch (\PDOException $e) {
            @unlink($targetPath);
            error_log("DriveService uploadFile PDO error: " . $e->getMessage());
            throw new \RuntimeException("Database error while saving file record");
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            @unlink($targetPath);
            error_log("DriveService uploadFile general error: " . $e->getMessage());
            throw new \RuntimeException("Unexpected error: " . $e->getMessage());
        }
    }

    /**
     * Upload a file from a local file path to Drive
     * Used for saving chat attachments to Drive
     */
    public function uploadFromPath(string $email, string $sourcePath, ?int $folderId = null, ?string $customName = null): ?array
    {
        if (!file_exists($sourcePath)) {
            error_log("DriveService uploadFromPath: File not found: $sourcePath");
            return null;
        }
        
        $content = file_get_contents($sourcePath);
        if ($content === false) {
            error_log("DriveService uploadFromPath: Failed to read file: $sourcePath");
            return null;
        }
        
        $originalName = $customName ?? basename($sourcePath);
        $mimeType = mime_content_type($sourcePath) ?: 'application/octet-stream';
        
        return $this->uploadFileContent($email, $originalName, $content, $mimeType, $folderId);
    }
    
    /**
     * Upload file content directly (not from HTTP upload)
     * Used for saving email attachments to Drive
     */
    public function uploadFileContent(
        string $email,
        string $originalName,
        string $content,
        string $mimeType = 'application/octet-stream',
        ?int $folderId = null,
        ?string $sourceFolder = null,
        ?int $sourceUid = null,
        ?string $sourcePart = null
    ): ?array {
        $email = strtolower($email);
        $size = strlen($content);
        
        // Check quota
        if (!$this->hasQuota($email, $size)) {
            error_log("DriveService uploadFileContent: Quota exceeded for $email, file size: $size");
            return null;
        }
        // Phase 6b: system-wide admission. Throws on refusal so
        // programmatic callers (CRM portal, chat) get a clear signal
        // rather than a silent null return.
        $this->maybeAdmitUpload($size);
        
        // Generate unique filename
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        
        $userPath = $this->getUserPath($email);
        $targetPath = $userPath . '/' . $filename;

        // Phase 1d: verify the write size before inserting. NFS soft mounts
        // can return success on a short write, leaving a truncated file.
        // file_put_contents() returns the byte count, which we compare to
        // the expected size and bail (deleting the half-written file) if
        // they disagree.
        $written = file_put_contents($targetPath, $content);
        if ($written === false || $written !== $size) {
            @unlink($targetPath);
            error_log("DriveService uploadFileContent: write verification failed for {$targetPath}. Expected={$size}, written=" . var_export($written, true));
            return null;
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_files
                    (user_email, folder_id, filename, original_name, size, mime_type, storage_location,
                     source_email_folder, source_email_uid, source_email_part)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $storageLocation = $this->resolveStorageLocation();

            $stmt->execute([
                $email,
                $folderId,
                $filename,
                $originalName,
                $size,
                $mimeType,
                $storageLocation,
                $sourceFolder,
                $sourceUid,
                $sourcePart,
            ]);
            
            $insertId = (int)$this->db->lastInsertId();

            // Phase 1c: queue NAS migration if this upload landed on local
            // fallback. See enqueueNasMigrationIfNeeded() for the no-op cases.
            $this->enqueueNasMigrationIfNeeded($insertId, $email, $targetPath);

            $this->updateUsedSpace($email, $size);

            if ($folderId) {
                $this->updateFolderSizeWithParents($email, $folderId);
            }

            // Index content so programmatic uploads (mailbox/message/chat
            // "save to Drive") are searchable by body text, not just filename.
            $file = $this->getFile($email, $insertId);
            if ($file) {
                $this->reindexFileForSearch($file);
            }
            return $file;

        } catch (\PDOException $e) {
            unlink($targetPath);
            error_log("DriveService uploadFileContent PDO error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get file path for download
     */
    public function getFilePath(string $email, int $id): ?string
    {
        $file = $this->getFile($email, $id);
        if (!$file) {
            error_log("DriveService getFilePath: File not found in DB for email=$email, id=$id");
            return null;
        }

        // Phase 5b: if the file is cold-tiered, recall it to VPS first.
        // Returns null when the row isn't cold, the kill switch is off,
        // or recall hiccupped — in any of those cases we fall through
        // to the pre-existing resolveFilePath() flow without behavior
        // change. When recall succeeds, the returned path is the
        // freshly-warm VPS path and we serve from there.
        $recalled = $this->maybeRecallCold($file);
        if ($recalled !== null && file_exists($recalled)) {
            // Phase 6d: stamp last_read_at so the LRU tier-down selector
            // counts this recall as recent activity.
            $this->maybeTouchLastRead((int) $file['id']);
            return $recalled;
        }

        // Use resolveFilePath to check both local and NAS storage
        $path = $this->resolveFilePath($email, $file['filename'], $file['storage_location'] ?? null);
        
        if ($path) {
            error_log("DriveService getFilePath: Found file at $path (storage: " . ($file['storage_location'] ?? 'unknown') . ")");
            $this->maybeTouchLastRead((int) $file['id']);
            return $path;
        }
        
        // Fallback: try the old method
        $fallbackPath = $this->getUserPath($email) . '/' . $file['filename'];
        error_log("DriveService getFilePath: resolveFilePath failed, trying fallback=$fallbackPath, exists=" . (file_exists($fallbackPath) ? 'yes' : 'no'));
        
        if (!file_exists($fallbackPath)) {
            // Log directory contents for debugging
            $dir = $this->getUserPath($email);
            if (is_dir($dir)) {
                $files = scandir($dir);
                error_log("DriveService getFilePath: Directory contents: " . implode(', ', array_slice($files, 0, 10)));
            } else {
                error_log("DriveService getFilePath: Directory does not exist: $dir");
            }
        }

        if (file_exists($fallbackPath)) {
            $this->maybeTouchLastRead((int) $file['id']);
            return $fallbackPath;
        }
        return null;
    }
    
    /**
     * Prepare a file for download with bounded recall semantics.
     *
     * Phase 5b: when a Drive file is in cold tier_state, the bytes live
     * on NAS and must be recalled to VPS before serving. The naive
     * approach (just call getFilePath()) does that recall synchronously
     * inside the HTTP request, which can block a PHP worker for many
     * seconds on a large file. This wrapper splits the path:
     *
     *   - Hot / non-cold files: same behavior as getFilePath().
     *     Returns status='ready' + 'path'.
     *
     *   - Cold files <= drive.sync_recall_max_bytes (default 25 MiB):
     *     synchronous recall (small enough to be tolerable inline).
     *     Returns status='ready' + 'path' on success, status='restore_failed'
     *     on hiccup.
     *
     *   - Cold files > threshold: fires an async warmer (CLI process)
     *     that does the recall in the background and returns status='restoring'
     *     with a 'retry_after' hint. The frontend should display a
     *     "restoring from archive" state and poll the download endpoint;
     *     subsequent requests get status='ready' once the file is warm.
     *
     *   - Missing: status='not_found'.
     *
     * @return array{status: string, path?: string, file?: array, retry_after?: int, message?: string}
     */
    public function prepareForDownload(string $email, int $id): array
    {
        $file = $this->getFile($email, $id);
        if (!$file) {
            return ['status' => 'not_found', 'message' => 'File not found'];
        }

        $tierState = $file['tier_state'] ?? null;
        $size = (int) ($file['size'] ?? 0);

        // Threshold below which sync recall is acceptable. 25 MiB default
        // is the rough cutoff where a recall stays under the typical
        // HTTP idle timeout (60s) even on a 10 MB/s NFS link.
        $threshold = (int) ($this->config['drive']['sync_recall_max_bytes'] ?? 25 * 1024 * 1024);

        // Non-cold rows: regular fast path. The 'recalling' state is
        // treated like 'cold' so a parallel request that sees the row
        // mid-recall still produces a 202 instead of racing.
        if ($tierState !== \FlowOne\Storage\TierState::COLD
            && $tierState !== \FlowOne\Storage\TierState::RECALLING) {
            $path = $this->getFilePath($email, $id);
            return $path !== null
                ? ['status' => 'ready', 'path' => $path, 'file' => $file]
                : ['status' => 'not_found', 'message' => 'File not on disk'];
        }

        // Cold and small: do it inline. getFilePath() drives maybeRecallCold().
        if ($size <= $threshold) {
            $path = $this->getFilePath($email, $id);
            return $path !== null
                ? ['status' => 'ready', 'path' => $path, 'file' => $file]
                : ['status' => 'restore_failed', 'file' => $file,
                   'message' => 'Failed to restore file from cold storage'];
        }

        // Cold and big: don't block the request. Fire a background warmer
        // so the recall progresses while the client polls, and return 202.
        // We only spawn a new warmer when tier_state is still 'cold' - if
        // it's already 'recalling', another worker is on it.
        if ($tierState === \FlowOne\Storage\TierState::COLD) {
            $this->triggerBackgroundRecall($id);
        }

        $retryAfter = $this->estimateRecallSeconds($size);
        return [
            'status' => 'restoring',
            'retry_after' => $retryAfter,
            'file' => $file,
            'message' => 'File is being restored from cold storage. Retry shortly.',
        ];
    }

    /**
     * Estimate how long a cold-file recall will take, capped at sane
     * bounds for the Retry-After header. The heuristic assumes a 10 MB/s
     * effective NFS throughput, which is conservative for the VPN link.
     */
    private function estimateRecallSeconds(int $bytes): int
    {
        $assumedRate = 10 * 1024 * 1024;
        $est = (int) ceil($bytes / max(1, $assumedRate));
        return max(3, min(60, $est));
    }

    /**
     * Spawn a detached PHP CLI process that warms a single cold file.
     * Best-effort: failures are silent. The next request from the
     * client will either find tier_state='hot' (warmer succeeded) or
     * still 'cold'/'recalling' (in flight), and will get another 202.
     */
    private function triggerBackgroundRecall(int $fileId): void
    {
        if (!function_exists('shell_exec') || !function_exists('escapeshellarg')) {
            return;
        }
        $script = realpath(__DIR__ . '/../../cron/drive-recall-warm.php');
        if ($script === false) {
            error_log('[DriveService] drive-recall-warm.php missing, skipping background recall');
            return;
        }
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/local/lsws/lsphp83/bin/php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --file-id=' . (int) $fileId
            . ' > /dev/null 2>&1 &';
        @shell_exec($cmd);
    }

    /**
     * Get file path by share token
     */
    public function getFilePathByToken(string $token): ?array
    {
        $file = $this->getFileByShareToken($token);
        if (!$file) {
            error_log("DriveService getFilePathByToken: File not found for token: $token");
            return null;
        }

        // Phase 5b: recall cold-tiered files to VPS before serving.
        // See getFilePath() for the safety contract.
        $recalled = $this->maybeRecallCold($file);
        $path = ($recalled !== null && file_exists($recalled))
            ? $recalled
            : $this->resolveFilePath($file['user_email'], $file['filename'], $file['storage_location'] ?? null);
        
        if (!$path) {
            // Fallback to old method
            $path = $this->getUserPath($file['user_email']) . '/' . $file['filename'];
            if (!file_exists($path)) {
                error_log("DriveService getFilePathByToken: File not found in any location (user: {$file['user_email']}, filename: {$file['filename']})");
                return null;
            }
        }

        // Phase 6d: stamp last_read_at — shared-link downloads count as
        // reads for the purpose of LRU candidate selection.
        $this->maybeTouchLastRead((int) $file['id']);

        return [
            'path' => $path,
            'filename' => $file['original_name'],
            'mime_type' => $file['mime_type'],
            'size' => $file['size'],
        ];
    }
    
    /**
     * Delete a file
     */
    public function deleteFile(string $email, int $id): bool
    {
        $email = strtolower($email);
        $file = $this->getFile($email, $id);
        
        if (!$file) return false;
        
        $folderId = $file['folder_id'] ?? null;
        
        // Version history dies with the file (bytes + quota refunds included);
        // skipping this orphans version rows and permanently leaks quota.
        $this->versioning()->deleteAllVersions($email, $id);
        
        // Delete physical file
        $this->deleteFilePhysical($email, $file);
        
        // Delete from database
        $stmt = $this->db->prepare('DELETE FROM drive_files WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        
        // Update folder size
        if ($folderId) {
            $this->updateFolderSizeWithParents($email, $folderId);
        }
        
        return $stmt->rowCount() > 0;
    }
    
    private function deleteFilePhysical(string $email, array $file): void
    {
        $deleted = false;
        
        // Handle NAS-stored files
        if (isset($file['storage_location']) && $file['storage_location'] === 'nas' && !empty($file['nas_relative_path'])) {
            // Try to delete from NAS
            $nasPath = $this->resolveNasFilePath($file['nas_relative_path'], $email);
            if ($nasPath && file_exists($nasPath)) {
                unlink($nasPath);
                $deleted = true;
                error_log("[DriveService] Deleted NAS file: {$nasPath}");
            }
            
            // Also check for .trashed versions in NAS .trash folder
            $this->cleanupNasTrashFile($file);
        }
        
        // Always try local path too (in case file was migrated or fallback)
        $localPath = $this->getUserPath($email) . '/' . $file['filename'];
        if (file_exists($localPath)) {
            unlink($localPath);
            $deleted = true;
        }
        
        // Update quota only if file was actually deleted
        if ($deleted) {
            $this->updateUsedSpace($email, -$file['size']);
        }
    }
    
    /**
     * Resolve NAS file path from relative path
     */
    private function resolveNasFilePath(string $relativePath, ?string $ownerEmail = null): ?string
    {
        $storage = $this->getStorageServiceInstance();
        if (!$storage) {
            return null;
        }
        
        $basePath = $storage->getBasePath();
        if (!$basePath) {
            return null;
        }
        
        $cleanRelativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $candidates = [
            $basePath . '/' . $cleanRelativePath,
        ];

        if ($ownerEmail) {
            $lowerEmail = strtolower($ownerEmail);
            $emailHash = md5($lowerEmail);
            $candidates[] = $basePath . '/' . $lowerEmail . '/' . $cleanRelativePath;
            $candidates[] = $basePath . '/' . $emailHash . '/' . $cleanRelativePath;
        }

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Return the first candidate as a fallback for callers that only need
        // a best-effort path to log or probe later.
        return $candidates[0];
    }

    /**
     * Phase 5b: lazy-build the shared tier-recall service. Returns null
     * (and remembers that for the rest of the request) when:
     *   - the phase5b_drive_recall kill switch is off
     *   - the FlowOne\Storage\TierRecallService class isn't loadable
     *     (shared library not deployed yet)
     *   - the shared storage config can't be loaded
     * In all those cases, DriveService falls back to its pre-Phase-5b
     * read path (resolveFilePath/resolveNasFilePath) and behaves exactly
     * as it did before this change. ZERO risk to existing reads.
     */
    private function getTierRecallService(): ?\FlowOne\Storage\TierRecallService
    {
        if ($this->tierRecallService !== null) {
            return $this->tierRecallService;
        }
        if ($this->tierRecallServiceUnavailable) {
            return null;
        }

        try {
            if (!class_exists(\FlowOne\Storage\TierRecallService::class)
                || !class_exists(\FlowOne\Storage\Config::class)) {
                $this->tierRecallServiceUnavailable = true;
                return null;
            }
            $storageConfig = \FlowOne\Storage\Config::load();
            if (!($storageConfig['phases']['phase5b_drive_recall'] ?? false)) {
                $this->tierRecallServiceUnavailable = true;
                return null;
            }
            $vpsBase = (string) ($this->config['drive']['storage_path']
                ?? '/var/www/vps-email/storage/drive');
            $this->tierRecallService = \FlowOne\Storage\TierRecallService::build(
                pdo:          $this->db,
                tenant:       'email-drive',
                vpsBasePath:  $vpsBase,
                storageConfig: $storageConfig,
            );
            return $this->tierRecallService;
        } catch (\Throwable $e) {
            // ANY failure to bootstrap the recall path is non-fatal:
            // we log and degrade to the legacy resolveFilePath() flow.
            error_log("[DriveService] tier recall service unavailable: " . $e->getMessage());
            $this->tierRecallServiceUnavailable = true;
            return null;
        }
    }

    /**
     * Phase 5b: if the given file row is in `cold` tier_state, walk
     * it through the recall flow (NAS -> VPS, verify, transition to
     * hot) and return the resulting VPS absolute path. Returns null
     * for any non-cold row OR when the recall path is unavailable;
     * the caller MUST fall back to the legacy resolveFilePath() in
     * that case so existing behavior is preserved exactly.
     *
     * Throws RuntimeException only when the row IS cold but recall
     * failed; the caller can present a transient error to the user.
     *
     * @param array<string,mixed> $file  a row from drive_files
     */
    private function maybeRecallCold(array $file): ?string
    {
        $tierState = $file['tier_state'] ?? null;
        // Fast path: row pre-dates migration 167, or tier_state is one
        // of the warm states. No recall needed — leave the caller to
        // do its normal path resolution.
        if ($tierState === null) {
            return null;
        }
        if ($tierState !== \FlowOne\Storage\TierState::COLD) {
            return null;
        }

        $svc = $this->getTierRecallService();
        if ($svc === null) {
            // Kill switch off or shared lib missing — fall back to
            // legacy resolveFilePath() which knows how to read from
            // NAS via nas_relative_path. Cold files still readable.
            return null;
        }

        // We have to thread the file_id through. Pull it from the row.
        $fileId = (int) ($file['id'] ?? 0);
        if ($fileId <= 0) {
            return null;
        }
        // recallCold() throws on failure; caller catches and may
        // present a "try again" message, OR — for read-path safety —
        // we swallow it here and let legacy resolveFilePath() try
        // the NAS path directly. Choose: swallow + fall back, so a
        // recall hiccup never breaks an existing-working read.
        try {
            return $svc->recallCold($fileId);
        } catch (\Throwable $e) {
            error_log("[DriveService] recall failed for file_id={$fileId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Phase 6b: lazy-build the admission controller. Same safety
     * contract as getTierRecallService(): any failure to bootstrap
     * the path is non-fatal and admission becomes a no-op (the
     * existing hasQuota check still runs). Returns null when:
     *   - phase6b_admission_control flag is OFF
     *   - the FlowOne\Storage\AdmissionController class isn't loadable
     *   - the shared storage config can't be loaded
     *   - the StorageBudget can't be constructed
     */
    private function getAdmissionController(): ?\FlowOne\Storage\AdmissionController
    {
        if ($this->admissionController !== null) {
            return $this->admissionController;
        }
        if ($this->admissionControllerUnavailable) {
            return null;
        }
        try {
            if (!class_exists(\FlowOne\Storage\AdmissionController::class)
                || !class_exists(\FlowOne\Storage\Config::class)) {
                $this->admissionControllerUnavailable = true;
                return null;
            }
            $storageConfig = \FlowOne\Storage\Config::load();
            if (!($storageConfig['phases']['phase6b_admission_control'] ?? false)) {
                $this->admissionControllerUnavailable = true;
                return null;
            }
            $this->admissionController = \FlowOne\Storage\AdmissionController::build(
                pdo:           $this->db,
                storageConfig: $storageConfig,
            );
            return $this->admissionController;
        } catch (\Throwable $e) {
            error_log("[DriveService] admission controller unavailable: " . $e->getMessage());
            $this->admissionControllerUnavailable = true;
            return null;
        }
    }

    /**
     * Phase 6b: gate on system-wide budget before accepting $size
     * bytes of upload. Sibling of hasQuota() (per-user check), which
     * is unchanged. Behaves as follows:
     *
     *   - Kill switch OFF / shared lib missing -> no-op (legacy behavior)
     *   - Budget OK                            -> no-op
     *   - Budget refuses                       -> throws
     *     \FlowOne\Storage\StorageBudgetExceededException, which
     *     extends \RuntimeException so existing DriveController
     *     catch-blocks render a user-facing error correctly. The
     *     controller can additionally detect the typed exception and
     *     respond with HTTP 503 + Retry-After.
     *
     * Always called AFTER the per-user hasQuota() check so the message
     * the user sees is the most specific one (per-user quota beats
     * system-wide pressure).
     */
    public function maybeAdmitUpload(int $size): void
    {
        $svc = $this->getAdmissionController();
        if ($svc === null) {
            return;
        }
        $svc->admit($size);
    }

    /**
     * Phase 6d: lazy-build the LRU stamper. Same fail-open contract
     * as the other lazy services. Returns null when:
     *   - phase6d_lru_selection is OFF
     *   - shared lib not loaded
     *   - last_read_at column missing (migration 168 hasn't run)
     */
    private function getLastReadTouch(): ?\FlowOne\Storage\LastReadTouch
    {
        if ($this->lastReadTouch !== null) {
            return $this->lastReadTouch;
        }
        if ($this->lastReadTouchUnavailable) {
            return null;
        }
        try {
            if (!class_exists(\FlowOne\Storage\LastReadTouch::class)
                || !class_exists(\FlowOne\Storage\Config::class)) {
                $this->lastReadTouchUnavailable = true;
                return null;
            }
            $cfg = \FlowOne\Storage\Config::load();
            if (!($cfg['phases']['phase6d_lru_selection'] ?? false)) {
                $this->lastReadTouchUnavailable = true;
                return null;
            }
            $this->lastReadTouch = \FlowOne\Storage\LastReadTouch::build($this->db, $cfg);
            return $this->lastReadTouch;
        } catch (\Throwable $e) {
            error_log("[DriveService] LRU touch unavailable: " . $e->getMessage());
            $this->lastReadTouchUnavailable = true;
            return null;
        }
    }

    /**
     * Phase 6d: stamp last_read_at for a file we just served. Always
     * non-throwing (read already succeeded; we don't want to punish
     * the user for a DB hiccup here). No-op when the flag is off.
     */
    public function maybeTouchLastRead(int $fileId): void
    {
        $svc = $this->getLastReadTouch();
        if ($svc === null) {
            return;
        }
        try {
            $svc->touch($fileId);
        } catch (\Throwable $e) {
            // Defence in depth — LastReadTouch::touch() already swallows
            // its own errors, but if the LastReadTouch instance itself
            // throws (extremely unlikely), don't let it propagate.
            error_log("[DriveService] LRU touch swallowed: " . $e->getMessage());
        }
    }

    /**
     * Clean up .trashed files in NAS trash folder
     */
    private function cleanupNasTrashFile(array $file): void
    {
        $storage = $this->getStorageServiceInstance();
        if (!$storage) {
            return;
        }
        
        $basePath = $storage->getBasePath();
        if (!$basePath) {
            return;
        }
        
        $trashFolder = $basePath . '/.trash';
        
        if (!is_dir($trashFolder)) {
            return;
        }
        
        // Find and delete any .trashed versions of this file
        $pattern = $file['original_name'] . '.*.trashed';
        $files = glob($trashFolder . '/' . $pattern);
        
        foreach ($files as $trashedFile) {
            if (file_exists($trashedFile)) {
                unlink($trashedFile);
                error_log("[DriveService] Cleaned up NAS trash file: {$trashedFile}");
            }
        }
    }
    
    /**
     * Get StorageService instance (uses the one already initialized)
     */
    private function getStorageServiceInstance(): ?StorageService
    {
        return $this->storage ?? null;
    }
    
    /**
     * Move file to folder
     */
    public function moveFile(string $email, int $id, ?int $folderId): bool
    {
        $email = strtolower($email);
        
        // Get current folder before move
        $file = $this->getFile($email, $id);
        $oldFolderId = $file ? ($file['folder_id'] ?? null) : null;
        
        $stmt = $this->db->prepare('UPDATE drive_files SET folder_id = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$folderId, $email, $id]);
        $success = $stmt->rowCount() > 0;
        
        if ($success) {
            // Update old folder size
            if ($oldFolderId) {
                $this->updateFolderSizeWithParents($email, $oldFolderId);
            }
            // Update new folder size
            if ($folderId) {
                $this->updateFolderSizeWithParents($email, $folderId);
            }
        }
        
        return $success;
    }
    
    /**
     * Copy a file to another folder (creates a physical duplicate)
     */
    public function copyFile(string $email, int $id, ?int $targetFolderId): ?array
    {
        $email = strtolower($email);
        $file = $this->getFile($email, $id);
        if (!$file) return null;

        $srcPath = $this->getFilePath($email, $id);
        if (!$srcPath || !file_exists($srcPath)) return null;

        $ext = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $newFilename = uniqid('cpy_') . '_' . time() . ($ext ? '.' . $ext : '');
        $newPath = dirname($srcPath) . '/' . $newFilename;

        if (!copy($srcPath, $newPath)) return null;

        $copyName = preg_replace('/(\.[^.]+)$/', ' (copy)$1', $file['original_name']);

        $stmt = $this->db->prepare('
            INSERT INTO drive_files (user_email, folder_id, filename, original_name, size, mime_type, storage_location, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ');
        $stmt->execute([
            $email,
            $targetFolderId,
            $newFilename,
            $copyName,
            $file['size'],
            $file['mime_type'],
            $file['storage_location'] ?? $this->resolveStorageLocation($newPath),
        ]);

        $newId = (int)$this->db->lastInsertId();
        if ($targetFolderId) {
            $this->updateFolderSizeWithParents($email, $targetFolderId);
        }

        return $this->getFile($email, $newId);
    }

    /**
     * Copy a folder (and its contents) to another parent
     */
    public function copyFolder(string $email, int $id, ?int $targetParentId): ?int
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        $folder = $stmt->fetch();
        if (!$folder) return null;

        if ($targetParentId !== null && $targetParentId === $id) return null;

        $newFolder = $this->createFolder($email, $folder['name'] . ' (copy)', $targetParentId);
        if (!$newFolder) return null;
        $newFolderId = (int)$newFolder['id'];

        $fileStmt = $this->db->prepare('SELECT id FROM drive_files WHERE user_email = ? AND folder_id = ?');
        $fileStmt->execute([$email, $id]);
        foreach ($fileStmt->fetchAll() as $f) {
            $this->copyFile($email, (int)$f['id'], $newFolderId);
        }

        $childStmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ?');
        $childStmt->execute([$email, $id]);
        foreach ($childStmt->fetchAll() as $child) {
            $this->copyFolder($email, (int)$child['id'], $newFolderId);
        }

        return $newFolderId;
    }

    /**
     * Rename file
     */
    public function renameFile(string $email, int $id, string $newName): bool
    {
        $stmt = $this->db->prepare('UPDATE drive_files SET original_name = ? WHERE user_email = ? AND id = ?');
        $stmt->execute([$newName, strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    // ===== EMAIL ATTACHMENT SAVING =====
    
    /**
     * Get or create the Attachments folder
     */
    public function getOrCreateAttachmentsFolder(string $email): ?array
    {
        $email = strtolower($email);
        
        // Look for existing "Attachments" folder at root
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND parent_id IS NULL AND name = ?');
        $stmt->execute([$email, 'Attachments']);
        $folder = $stmt->fetch();
        
        if ($folder) {
            return $folder;
        }
        
        // Create the Attachments folder
        return $this->createFolder($email, 'Attachments', null);
    }
    
    /**
     * Get or create a subfolder for email attachments
     * Creates folder with format: "YYYY-MM-DD - Subject"
     * @param int|null $clientId Optional client ID to link this folder to
     */
    public function getOrCreateEmailFolder(string $email, int $attachmentsFolderId, string $subject, ?string $emailDate = null, ?int $clientId = null): ?array
    {
        $email = strtolower($email);
        
        // Clean subject for folder name
        $cleanSubject = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $subject);
        $cleanSubject = trim(substr($cleanSubject, 0, 50));
        if (empty($cleanSubject)) {
            $cleanSubject = 'Email';
        }
        
        // Create folder name with date prefix
        $datePrefix = $emailDate ? date('Y-m-d', strtotime($emailDate)) : date('Y-m-d');
        $folderName = $datePrefix . ' - ' . $cleanSubject;
        
        // Check if folder already exists
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND parent_id = ? AND name = ?');
        $stmt->execute([$email, $attachmentsFolderId, $folderName]);
        $folder = $stmt->fetch();
        
        if ($folder) {
            // If folder exists but doesn't have client_id and we have one, update it
            if ($clientId && empty($folder['client_id'])) {
                $this->updateFolderClient($email, $folder['id'], $clientId);
                $folder['client_id'] = $clientId;
            }
            return $folder;
        }
        
        // Create the subfolder with client_id
        return $this->createFolder($email, $folderName, $attachmentsFolderId, $clientId);
    }
    
    /**
     * Save email attachment content directly to Drive
     * Auto-creates Attachments/EmailSubject folder structure
     */
    public function saveEmailAttachment(
        string $email,
        string $filename,
        string $content,
        string $mimeType,
        string $emailSubject,
        ?string $emailDate = null,
        ?string $senderEmail = null,
        ?string $sourceFolder = null,
        ?int $sourceUid = null,
        ?string $sourcePart = null
    ): ?array {
        $email = strtolower($email);
        $size = strlen($content);
        
        // Check quota
        if (!$this->hasQuota($email, $size)) {
            error_log("DriveService: Quota exceeded for $email when saving attachment");
            return null;
        }
        // Phase 6b: system-wide admission for email attachment saves.
        $this->maybeAdmitUpload($size);
        
        $clientFolder = null;
        $clientId = null;
        
        // If sender email provided, check if we have a client with a linked drive folder
        if ($senderEmail) {
            $clientFolder = $this->getClientFolderByEmail($email, $senderEmail);
            if ($clientFolder) {
                $clientId = $clientFolder['client_id'];
            } else {
                // Even without a linked folder, try to get the client_id for linking
                $clientId = $this->getClientIdByEmail($email, $senderEmail);
            }
        }
        
        // Determine base folder for Attachments
        // If client has a linked folder, create Attachments inside it
        // Otherwise, create Attachments at root level
        if ($clientFolder) {
            // Get or create Attachments folder inside client folder
            $attachmentsFolder = $this->getOrCreateAttachmentsFolderInParent($email, $clientFolder['id']);
        } else {
            // Get or create Attachments folder at root
            $attachmentsFolder = $this->getOrCreateAttachmentsFolder($email);
        }
        
        if (!$attachmentsFolder) {
            error_log("DriveService: Failed to create Attachments folder");
            return null;
        }
        
        // Get or create email-specific subfolder with client_id if detected
        $emailFolder = $this->getOrCreateEmailFolder($email, $attachmentsFolder['id'], $emailSubject, $emailDate, $clientId);
        if (!$emailFolder) {
            error_log("DriveService: Failed to create email folder");
            return null;
        }
        
        // Generate unique stored filename
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $storedFilename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        
        $userPath = $this->getUserPath($email);
        $targetPath = $userPath . '/' . $storedFilename;

        // Phase 1d: verify the write size before inserting. See uploadFileContent()
        // for the rationale (NFS soft mount partial-write trap).
        $written = file_put_contents($targetPath, $content);
        if ($written === false || $written !== $size) {
            @unlink($targetPath);
            error_log("DriveService: attachment write verification failed for {$targetPath}. Expected={$size}, written=" . var_export($written, true));
            return null;
        }

        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_files
                    (user_email, folder_id, filename, original_name, size, mime_type, storage_location,
                     source_email_folder, source_email_uid, source_email_part)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $storageLocation = $this->resolveStorageLocation();

            $stmt->execute([
                $email,
                $emailFolder['id'],
                $storedFilename,
                $filename,
                $size,
                $mimeType,
                $storageLocation,
                $sourceFolder,
                $sourceUid,
                $sourcePart,
            ]);
            
            $insertId = (int)$this->db->lastInsertId();

            // Phase 1c: queue NAS migration if this upload landed on local
            // fallback. See enqueueNasMigrationIfNeeded() for the no-op cases.
            $this->enqueueNasMigrationIfNeeded($insertId, $email, $targetPath);

            $this->updateUsedSpace($email, $size);

            if ($emailFolder['id']) {
                $this->updateFolderSizeWithParents($email, $emailFolder['id']);
            }

            $file = $this->getFile($email, $insertId);

            // Index the saved attachment's content so the Drive copy is
            // searchable by body text (not just filename), matching the
            // original email attachment's search behaviour.
            if ($file) {
                $this->reindexFileForSearch($file);
            }

            return [
                'file' => $file,
                'folder' => $emailFolder,
                'attachments_folder' => $attachmentsFolder,
                'client_folder' => $clientFolder
            ];
            
        } catch (\PDOException $e) {
            unlink($targetPath);
            error_log("DriveService saveEmailAttachment error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all Drive files saved from a specific IMAP message.
     * Used by the email view to surface a "Saved to Drive" indicator and
     * a Share action on attachment cards. Returns one row per saved
     * attachment, with enough info for the frontend to render the badge,
     * deep-link into Drive, and create or reuse a share link.
     */
    public function getEmailAttachmentSavedFiles(string $email, string $folder, int $uid): array
    {
        $email = strtolower($email);
        if ($folder === '' || $uid <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT id, folder_id, original_name, mime_type, size,
                       share_token, share_expires, source_email_part,
                       created_at
                FROM drive_files
                WHERE user_email = ?
                  AND source_email_folder = ?
                  AND source_email_uid = ?
                  AND (is_trashed IS NULL OR is_trashed = 0)
                ORDER BY id ASC
            ');
            $stmt->execute([$email, $folder, $uid]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('DriveService getEmailAttachmentSavedFiles error: ' . $e->getMessage());
            return [];
        }

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'folder_id' => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
                'filename' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size' => (int)$row['size'],
                'part' => $row['source_email_part'],
                'share_token' => $row['share_token'] ?: null,
                'share_expires' => $row['share_expires'] ?: null,
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * Resolve which Drive files correspond to a list of IMAP attachments.
     *
     * Strategy:
     *  1. Precise match by source_email_folder/uid (rows tagged at save
     *     time by saveEmailAttachment / uploadFileContent).
     *  2. Filename+size fallback for attachments that didn't match.
     *     This covers files that were saved to Drive before the
     *     source-tracking columns existed.
     *  3. Self-heal: when a fallback match is found, UPDATE the row's
     *     source_email_* columns so subsequent lookups go through the
     *     fast precise path and to lock the match in place.
     *
     * $attachments format: [['part' => '1.2', 'filename' => 'x.pdf', 'size' => 12345], ...]
     *
     * Returns the same shape as getEmailAttachmentSavedFiles(), with an
     * added boolean `fallback` field that's true when the match came
     * from filename+size rather than the source columns.
     */
    public function resolveSavedFilesForEmailMessage(string $email, string $folder, int $uid, array $attachments): array
    {
        $email = strtolower($email);
        if ($folder === '' || $uid <= 0) return [];

        $precise = $this->getEmailAttachmentSavedFiles($email, $folder, $uid);
        $matchedParts = [];
        foreach ($precise as $row) {
            if (!empty($row['part'])) $matchedParts[$row['part']] = true;
        }
        // Tag precise rows so the frontend can distinguish them.
        foreach ($precise as &$row) { $row['fallback'] = false; }
        unset($row);

        $results = $precise;

        // Build the fallback candidates (attachments not matched by source).
        foreach ($attachments as $a) {
            $part = isset($a['part']) ? (string)$a['part'] : null;
            $name = $a['filename'] ?? null;
            $size = isset($a['size']) ? (int)$a['size'] : null;
            if ($part === null || $name === null || !$size) continue;
            if (isset($matchedParts[$part])) continue;

            $hit = $this->findLikelySavedFileByName($email, $name, $size);
            if (!$hit) continue;

            // Backfill the source columns so future lookups are O(1) and
            // unambiguous. We do this best-effort; failure is logged but
            // does not break the response.
            try {
                $upd = $this->db->prepare('
                    UPDATE drive_files
                       SET source_email_folder = ?,
                           source_email_uid = ?,
                           source_email_part = ?
                     WHERE user_email = ? AND id = ?
                       AND (source_email_uid IS NULL OR source_email_folder IS NULL)
                ');
                $upd->execute([$folder, $uid, $part, $email, (int)$hit['id']]);
            } catch (\PDOException $e) {
                error_log('DriveService backfill source_email_* failed: ' . $e->getMessage());
            }

            $results[] = [
                'id' => (int)$hit['id'],
                'folder_id' => $hit['folder_id'] !== null ? (int)$hit['folder_id'] : null,
                'filename' => $hit['original_name'],
                'mime_type' => $hit['mime_type'],
                'size' => (int)$hit['size'],
                'part' => $part,
                'share_token' => $hit['share_token'] ?: null,
                'share_expires' => $hit['share_expires'] ?: null,
                'created_at' => $hit['created_at'],
                'fallback' => true,
            ];
            $matchedParts[$part] = true;
        }

        return $results;
    }

    /**
     * Filename+size lookup used by the fallback path of
     * resolveSavedFilesForEmailMessage(). Prefers rows under an
     * "Attachments" folder lineage (which is where saveEmailAttachment
     * places files by default) to reduce false positives across
     * unrelated copies of a file in a user's Drive.
     */
    private function findLikelySavedFileByName(string $email, string $originalName, int $size): ?array
    {
        $email = strtolower($email);

        try {
            $stmt = $this->db->prepare('
                SELECT id, folder_id, original_name, mime_type, size,
                       share_token, share_expires, created_at
                FROM drive_files
                WHERE user_email = ?
                  AND original_name = ?
                  AND size = ?
                  AND (is_trashed IS NULL OR is_trashed = 0)
                ORDER BY id DESC
                LIMIT 5
            ');
            $stmt->execute([$email, $originalName, $size]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('DriveService findLikelySavedFileByName error: ' . $e->getMessage());
            return null;
        }

        if (empty($rows)) return null;

        // Prefer rows whose folder lineage contains a folder named
        // "Attachments" — that's the canonical home for files saved via
        // the email view's auto-folder flow. We resolve up to 5
        // candidates so the lookup stays cheap on pathological inboxes.
        foreach ($rows as $row) {
            if ($this->isFolderUnderAttachments((int)($row['folder_id'] ?? 0))) {
                return $row;
            }
        }

        // No "Attachments" lineage match — fall back to the most recent
        // row by id. Conservative: only return a match if there is
        // exactly one candidate, to avoid pinning the wrong file.
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * True if any ancestor of $folderId is a folder named "Attachments".
     * Walks up to 8 levels to bound work; deeper structures fall back
     * to "no match" rather than scanning indefinitely.
     */
    private function isFolderUnderAttachments(int $folderId): bool
    {
        if ($folderId <= 0) return false;
        try {
            $stmt = $this->db->prepare('SELECT id, name, parent_id FROM drive_folders WHERE id = ?');
            for ($i = 0; $i < 8 && $folderId > 0; $i++) {
                $stmt->execute([$folderId]);
                $row = $stmt->fetch();
                if (!$row) return false;
                if (strcasecmp($row['name'] ?? '', 'Attachments') === 0) return true;
                $folderId = (int)($row['parent_id'] ?? 0);
            }
        } catch (\PDOException $e) {
            // Swallow: this is a heuristic, not load-bearing.
        }
        return false;
    }

    /**
     * Get client's linked drive folder by sender email
     * Looks up client by email domain and returns their linked drive folder if exists
     */
    private function getClientFolderByEmail(string $userEmail, string $senderEmail): ?array
    {
        $userEmail = strtolower($userEmail);
        $senderEmail = strtolower(trim($senderEmail));
        
        // Extract domain from sender email
        $parts = explode('@', $senderEmail);
        if (count($parts) !== 2 || empty($parts[1])) {
            return null;
        }
        $domain = $parts[1];
        
        // List of generic email providers - for these, use full email as client identifier
        $genericDomains = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 
            'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
            'aol.com', 'mail.com', 'protonmail.com', 'proton.me',
            'yandex.com', 'gmx.com', 'zoho.com'
        ];
        
        $isGeneric = in_array(strtolower($domain), $genericDomains);
        $clientIdentifier = $isGeneric ? $senderEmail : $domain;
        
        // Look up client with this domain/email and a linked drive folder
        $stmt = $this->db->prepare('
            SELECT c.id, c.display_name, c.drive_folder_id, df.id as folder_id, df.name as folder_name
            FROM clients c
            INNER JOIN drive_folders df ON df.id = c.drive_folder_id
            WHERE c.user_email = ? AND c.domain = ? AND c.drive_folder_id IS NOT NULL
        ');
        $stmt->execute([$userEmail, $clientIdentifier]);
        $result = $stmt->fetch();
        
        if ($result && $result['folder_id']) {
            return [
                'id' => (int)$result['folder_id'],
                'name' => $result['folder_name'],
                'client_id' => (int)$result['id'],
                'client_name' => $result['display_name']
            ];
        }
        
        return null;
    }
    
    /**
     * Get client ID from sender email (even if no folder is linked)
     * Returns just the client_id for linking purposes
     */
    public function getClientIdByEmail(string $userEmail, string $senderEmail): ?int
    {
        $userEmail = strtolower($userEmail);
        $senderEmail = strtolower(trim($senderEmail));
        
        // Extract domain from sender email
        $parts = explode('@', $senderEmail);
        if (count($parts) !== 2 || empty($parts[1])) {
            return null;
        }
        $domain = $parts[1];
        
        // List of generic email providers
        $genericDomains = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 
            'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
            'aol.com', 'mail.com', 'protonmail.com', 'proton.me',
            'yandex.com', 'gmx.com', 'zoho.com'
        ];
        
        $isGeneric = in_array(strtolower($domain), $genericDomains);
        $clientIdentifier = $isGeneric ? $senderEmail : $domain;
        
        // Look up any client with this domain/email
        $stmt = $this->db->prepare('
            SELECT id FROM clients WHERE user_email = ? AND domain = ?
        ');
        $stmt->execute([$userEmail, $clientIdentifier]);
        $result = $stmt->fetch();
        
        return $result ? (int)$result['id'] : null;
    }
    
    /**
     * Get or create Attachments folder inside a parent folder (e.g., client folder)
     */
    public function getOrCreateAttachmentsFolderInParent(string $email, int $parentId): ?array
    {
        $email = strtolower($email);
        
        // Look for existing "Attachments" folder in parent
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND parent_id = ? AND name = ?');
        $stmt->execute([$email, $parentId, 'Attachments']);
        $folder = $stmt->fetch();
        
        if ($folder) {
            return $folder;
        }
        
        // Create the Attachments folder inside parent
        return $this->createFolder($email, 'Attachments', $parentId);
    }
    
    /**
     * Find folder by name (case-insensitive)
     */
    public function findFolderByName(string $email, string $name, ?int $parentId = null): ?array
    {
        $email = strtolower($email);
        
        if ($parentId === null) {
            $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND parent_id IS NULL AND LOWER(name) = LOWER(?)');
            $stmt->execute([$email, $name]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND parent_id = ? AND LOWER(name) = LOWER(?)');
            $stmt->execute([$email, $parentId, $name]);
        }
        
        return $stmt->fetch() ?: null;
    }
    
    // ===== SHARING =====
    
    /**
     * Create share link for file
     * @param bool $isEmailAttachment If true, file will be auto-deleted when share expires
     * @param int|null $maxDownloads Maximum number of downloads allowed (null = unlimited)
     * @param string|null $password Password to protect the share link
     */
    public function createShareLink(string $email, int $id, ?int $expiresHours = null, bool $isEmailAttachment = false, ?int $maxDownloads = null, ?string $password = null): ?string
    {
        $file = $this->getFile($email, $id);
        if (!$file) return null;
        
        $token = bin2hex(random_bytes(32));
        $expires = $expiresHours ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600)) : null;
        $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
        
        $rowsAffected = 0;
        
        try {
            // Build the update query dynamically
            $sql = 'UPDATE drive_files SET share_token = ?, share_expires = ?, max_downloads = ?, download_count = 0, share_password = ?';
            $params = [$token, $expires, $maxDownloads, $hashedPassword];
            
            if ($isEmailAttachment) {
                $sql .= ', is_email_attachment = 1';
            }
            
            $sql .= ' WHERE user_email = ? AND id = ?';
            $params[] = strtolower($email);
            $params[] = $id;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rowsAffected = $stmt->rowCount();
        } catch (\Exception $e) {
            // Fallback without new columns (migration may not have run yet)
            error_log("createShareLink fallback: " . $e->getMessage());
            $sql = 'UPDATE drive_files SET share_token = ?, share_expires = ?';
            $params = [$token, $expires];
            
            if ($isEmailAttachment) {
                $sql .= ', is_email_attachment = 1';
            }
            
            $sql .= ' WHERE user_email = ? AND id = ?';
            $params[] = strtolower($email);
            $params[] = $id;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rowsAffected = $stmt->rowCount();
        }
        
        // Verify token was saved
        if ($rowsAffected === 0) {
            error_log("createShareLink: No rows affected for file $id, email $email");
            return null;
        }
        
        return $token;
    }
    
    /**
     * Update share link settings for a file
     */
    public function updateShareLink(string $email, int $id, ?int $expiresHours = null, ?int $maxDownloads = null, ?string $password = null, bool $resetDownloadCount = false): bool
    {
        $file = $this->getFile($email, $id);
        if (!$file || !$file['share_token']) return false;

        // Build the SET clause from ONLY the fields the caller actually
        // provided. Previously this method unconditionally wrote
        // share_expires and max_downloads, so an edit that changed just the
        // password (sending null for the other two) silently wiped an
        // existing expiry/limit. The contract now mirrors the password one:
        //   - null  => keep the existing value (field omitted)
        //   - 0     => clear it (never expires / unlimited downloads)
        //   - > 0   => set the new value
        $sets = [];
        $params = [];

        if ($expiresHours !== null) {
            if ($expiresHours > 0) {
                $sets[] = 'share_expires = ?';
                $params[] = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));
            } else {
                $sets[] = 'share_expires = NULL';
            }
        }

        if ($maxDownloads !== null) {
            if ($maxDownloads > 0) {
                $sets[] = 'max_downloads = ?';
                $params[] = $maxDownloads;
            } else {
                $sets[] = 'max_downloads = NULL';
            }
        }

        // Password: null keeps existing, empty string clears it, non-empty sets it.
        if ($password !== null) {
            if ($password !== '') {
                $sets[] = 'share_password = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $sets[] = 'share_password = NULL';
            }
        }

        if ($resetDownloadCount) {
            $sets[] = 'download_count = 0';
        }

        // Nothing to change: the share exists (verified above), so this is a
        // successful no-op rather than a 404.
        if (empty($sets)) {
            return true;
        }

        $sql = 'UPDATE drive_files SET ' . implode(', ', $sets) . ' WHERE user_email = ? AND id = ?';
        $params[] = strtolower($email);
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // We already confirmed the row exists; report success on execute
        // rather than rowCount (an update to identical values affects 0 rows).
        return true;
    }
    
    /**
     * Create share link for existing Drive file (for email attachment)
     * Marks as email attachment so expired files get auto-cleaned
     */
    public function createShareLinkForEmail(string $email, int $id, int $expiresHours = 2160): ?string
    {
        return $this->createShareLink($email, $id, $expiresHours, true);
    }
    
    /**
     * Mark file as email attachment (will be auto-deleted when share expires)
     */
    public function markAsEmailAttachment(string $email, int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE drive_files SET is_email_attachment = 1 WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Clean up expired email attachments
     * Called by cron or scheduled task
     * @return int Number of files deleted
     */
    public function cleanupExpiredEmailAttachments(): int
    {
        // Find all email attachments with expired share links
        $stmt = $this->db->prepare('
            SELECT id, user_email, filename, size 
            FROM drive_files 
            WHERE is_email_attachment = 1 
            AND share_expires IS NOT NULL 
            AND share_expires < NOW()
        ');
        $stmt->execute();
        $expiredFiles = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $deletedCount = 0;
        
        foreach ($expiredFiles as $file) {
            // Delete physical file
            $path = $this->getUserPath($file['user_email']) . '/' . $file['filename'];
            if (file_exists($path)) {
                unlink($path);
            }
            
            // Update quota
            $this->updateUsedSpace($file['user_email'], -$file['size']);
            
            // Delete from database
            $deleteStmt = $this->db->prepare('DELETE FROM drive_files WHERE id = ?');
            $deleteStmt->execute([$file['id']]);
            
            $deletedCount++;
            error_log("DriveService: Auto-deleted expired email attachment ID {$file['id']} for {$file['user_email']}");
        }
        
        return $deletedCount;
    }
    
    /**
     * Remove share link
     */
    public function removeShareLink(string $email, int $id): bool
    {
        $lowerEmail = strtolower($email);

        $check = $this->db->prepare('SELECT id FROM drive_files WHERE user_email = ? AND id = ?');
        $check->execute([$lowerEmail, $id]);
        if (!$check->fetch()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE drive_files SET share_token = NULL, share_expires = NULL, max_downloads = NULL, download_count = 0, share_password = NULL WHERE user_email = ? AND id = ?');
            $stmt->execute([$lowerEmail, $id]);
        } catch (\Exception $e) {
            $stmt = $this->db->prepare('UPDATE drive_files SET share_token = NULL, share_expires = NULL WHERE user_email = ? AND id = ?');
            $stmt->execute([$lowerEmail, $id]);
        }
        return true;
    }
    
    /**
     * Create share link for folder
     * @param int|null $maxDownloads Maximum number of downloads allowed (null = unlimited)
     * @param string|null $password Password to protect the share link
     */
    public function createFolderShareLink(string $email, int $id, ?int $expiresHours = null, ?int $maxDownloads = null, ?string $password = null): ?string
    {
        $folder = $this->getFolder($email, $id);
        if (!$folder) return null;
        
        $token = bin2hex(random_bytes(32));
        $expires = $expiresHours ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600)) : null;
        $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
        
        $rowsAffected = 0;
        $fullFeatures = true;
        
        try {
            // Try with new columns first
            $stmt = $this->db->prepare('UPDATE drive_folders SET share_token = ?, share_expires = ?, max_downloads = ?, download_count = 0, share_password = ? WHERE user_email = ? AND id = ?');
            $stmt->execute([$token, $expires, $maxDownloads, $hashedPassword, strtolower($email), $id]);
            $rowsAffected = $stmt->rowCount();
        } catch (\Exception $e) {
            // Fallback without new columns (migration may not have run yet)
            $fullFeatures = false;
            error_log("createFolderShareLink WARNING: Download limits and password not saved - migration 007 not run. Error: " . $e->getMessage());
            $stmt = $this->db->prepare('UPDATE drive_folders SET share_token = ?, share_expires = ? WHERE user_email = ? AND id = ?');
            $stmt->execute([$token, $expires, strtolower($email), $id]);
            $rowsAffected = $stmt->rowCount();
        }
        
        // Verify token was saved
        if ($rowsAffected === 0) {
            error_log("createFolderShareLink: No rows affected for folder $id, email $email");
            return null;
        }
        
        // Log if features were lost
        if (!$fullFeatures && ($maxDownloads !== null || $password !== null)) {
            error_log("createFolderShareLink: FEATURES LOST - maxDownloads=$maxDownloads, hasPassword=" . ($password ? 'yes' : 'no') . " - Run migration 007_drive_sharing_enhanced.sql!");
        }
        
        return $token;
    }
    
    /**
     * Update share link settings for a folder
     */
    public function updateFolderShareLink(string $email, int $id, ?int $expiresHours = null, ?int $maxDownloads = null, ?string $password = null, bool $resetDownloadCount = false): bool
    {
        $folder = $this->getFolder($email, $id);
        if (!$folder || !$folder['share_token']) return false;
        
        $expires = $expiresHours ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600)) : null;
        
        $sql = 'UPDATE drive_folders SET share_expires = ?, max_downloads = ?';
        $params = [$expires, $maxDownloads];
        
        if ($password !== null) {
            $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
            $sql .= ', share_password = ?';
            $params[] = $hashedPassword;
        }
        
        if ($resetDownloadCount) {
            $sql .= ', download_count = 0';
        }
        
        $sql .= ' WHERE user_email = ? AND id = ?';
        $params[] = strtolower($email);
        $params[] = $id;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove folder share link
     */
    public function removeFolderShareLink(string $email, int $id): bool
    {
        $lowerEmail = strtolower($email);

        // Check folder exists and belongs to user
        $check = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND id = ?');
        $check->execute([$lowerEmail, $id]);
        if (!$check->fetch()) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE drive_folders SET share_token = NULL, share_expires = NULL, max_downloads = NULL, download_count = 0, share_password = NULL WHERE user_email = ? AND id = ?');
            $stmt->execute([$lowerEmail, $id]);
        } catch (\Exception $e) {
            $stmt = $this->db->prepare('UPDATE drive_folders SET share_token = NULL, share_expires = NULL WHERE user_email = ? AND id = ?');
            $stmt->execute([$lowerEmail, $id]);
        }
        return true;
    }
    
    /**
     * Check if a shared folder requires password
     */
    public function folderShareRequiresPassword(string $token): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT share_password FROM drive_folders WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            return $result && !empty($result['share_password']);
        } catch (\Exception $e) {
            // Column doesn't exist yet (migration not run)
            return false;
        }
    }
    
    /**
     * Validate share password for a folder
     */
    public function validateFolderSharePassword(string $token, string $password): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT share_password FROM drive_folders WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            
            if (!$result || empty($result['share_password'])) {
                return true; // No password required
            }
            
            return password_verify($password, $result['share_password']);
        } catch (\Exception $e) {
            // Column doesn't exist yet (migration not run)
            return true;
        }
    }
    
    /**
     * Increment download count for a folder
     */
    public function incrementFolderDownloadCount(string $token): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE drive_folders SET download_count = download_count + 1 WHERE share_token = ?');
            $stmt->execute([$token]);
        } catch (\Exception $e) {
            // Column doesn't exist yet (migration not run)
        }
    }
    
    /**
     * Check if folder download limit has been reached
     * Returns true if download is allowed, false if limit reached
     */
    public function canDownloadFromFolder(string $token): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT max_downloads, download_count FROM drive_folders WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            
            if (!$result) return false; // Folder not found
            
            // If no limit set (null), allow unlimited downloads
            if ($result['max_downloads'] === null) return true;
            
            // Check if under the limit
            return $result['download_count'] < $result['max_downloads'];
        } catch (\Exception $e) {
            // Columns don't exist - allow download (no limits feature)
            return true;
        }
    }
    
    /**
     * Get remaining downloads for a folder share
     */
    public function getFolderRemainingDownloads(string $token): ?int
    {
        try {
            $stmt = $this->db->prepare('SELECT max_downloads, download_count FROM drive_folders WHERE share_token = ?');
            $stmt->execute([$token]);
            $result = $stmt->fetch();
            
            if (!$result || $result['max_downloads'] === null) return null; // No limit
            
            return max(0, $result['max_downloads'] - $result['download_count']);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get share info for a folder (without full folder data)
     */
    public function getFolderShareInfo(string $token): ?array
    {
        // Use PHP time for consistent timezone handling
        $now = date('Y-m-d H:i:s');
        
        try {
            $stmt = $this->db->prepare('
                SELECT id, name, share_expires, max_downloads, download_count, 
                       (share_password IS NOT NULL AND share_password != "") as requires_password
                FROM drive_folders 
                WHERE share_token = ? 
                AND (share_expires IS NULL OR share_expires > ?)
            ');
            $stmt->execute([$token, $now]);
            $info = $stmt->fetch() ?: null;
            
            if (!$info) return null;
            
            $info['requires_password'] = (bool)$info['requires_password'];
            $info['downloads_remaining'] = $info['max_downloads'] !== null 
                ? max(0, $info['max_downloads'] - $info['download_count']) 
                : null;
            $info['limit_reached'] = $info['max_downloads'] !== null && $info['download_count'] >= $info['max_downloads'];
            
            return $info;
        } catch (\Exception $e) {
            // Columns don't exist yet - use fallback query
            $stmt = $this->db->prepare('
                SELECT id, name, share_expires
                FROM drive_folders 
                WHERE share_token = ? 
                AND (share_expires IS NULL OR share_expires > ?)
            ');
            $stmt->execute([$token, $now]);
            $info = $stmt->fetch() ?: null;
            
            if (!$info) return null;
            
            $info['requires_password'] = false;
            $info['max_downloads'] = null;
            $info['download_count'] = 0;
            $info['downloads_remaining'] = null;
            $info['limit_reached'] = false;
            
            return $info;
        }
    }
    
    /**
     * Get folder and its contents by share token (public access)
     */
    public function getFolderByShareToken(string $token): ?array
    {
        error_log("getFolderByShareToken: Looking up token: " . substr($token, 0, 16) . "...");
        
        // First check if the token exists at all (without expiry check)
        $checkStmt = $this->db->prepare('SELECT id, share_token, share_expires FROM drive_folders WHERE share_token = ?');
        $checkStmt->execute([$token]);
        $checkResult = $checkStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$checkResult) {
            error_log("getFolderByShareToken: Token not found in database");
        } else {
            error_log("getFolderByShareToken: Token found for folder ID " . $checkResult['id'] . ", expires: " . ($checkResult['share_expires'] ?? 'never'));
            if ($checkResult['share_expires'] && strtotime($checkResult['share_expires']) < time()) {
                error_log("getFolderByShareToken: Token is EXPIRED (expires: " . $checkResult['share_expires'] . ", now: " . date('Y-m-d H:i:s') . ")");
            }
        }
        
        // Use PHP time for consistent timezone handling
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            SELECT id, user_email, name, share_expires 
            FROM drive_folders 
            WHERE share_token = ? 
            AND (share_expires IS NULL OR share_expires > ?)
        ');
        $stmt->execute([$token, $now]);
        $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$folder) {
            error_log("getFolderByShareToken: Query with expiry check returned no results (now=$now)");
            return null;
        }
        
        error_log("getFolderByShareToken: Successfully retrieved folder: " . $folder['name']);
        
        // Check download limit (if columns exist)
        try {
            $limitStmt = $this->db->prepare('SELECT max_downloads, download_count FROM drive_folders WHERE id = ?');
            $limitStmt->execute([$folder['id']]);
            $limits = $limitStmt->fetch();
            if ($limits && $limits['max_downloads'] !== null && $limits['download_count'] >= $limits['max_downloads']) {
                return null; // Download limit reached
            }
        } catch (\Exception $e) {
            // Columns don't exist yet - no download limits
        }
        
        // Get files in this folder
        try {
            $fileStmt = $this->db->prepare('
                SELECT id, original_name, mime_type, size, created_at 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
                ORDER BY original_name
            ');
            $fileStmt->execute([$folder['user_email'], $folder['id']]);
            $files = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Fallback without is_trashed column
            $fileStmt = $this->db->prepare('
                SELECT id, original_name, mime_type, size, created_at 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ?
                ORDER BY original_name
            ');
            $fileStmt->execute([$folder['user_email'], $folder['id']]);
            $files = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        
        // Get subfolders
        try {
            $subStmt = $this->db->prepare('
                SELECT id, name, created_at 
                FROM drive_folders 
                WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
                ORDER BY name
            ');
            $subStmt->execute([$folder['user_email'], $folder['id']]);
            $subfolders = $subStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Fallback without is_trashed column
            $subStmt = $this->db->prepare('
                SELECT id, name, created_at 
                FROM drive_folders 
                WHERE user_email = ? AND parent_id = ?
                ORDER BY name
            ');
            $subStmt->execute([$folder['user_email'], $folder['id']]);
            $subfolders = $subStmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        
        return [
            'folder' => [
                'id' => $folder['id'],
                'name' => $folder['name'],
            ],
            'files' => $files,
            'subfolders' => $subfolders,
        ];
    }
    
    /**
     * Get file from a shared folder (validates folder token and file belongs to folder or any subfolder)
     */
    public function getFileFromSharedFolder(string $folderToken, int $fileId): ?array
    {
        error_log("getFileFromSharedFolder: token=" . substr($folderToken, 0, 16) . "..., fileId=$fileId");
        
        // Use PHP time for consistent timezone handling (matches getFolderByShareToken)
        $now = date('Y-m-d H:i:s');
        
        // First verify the folder share token
        $folderStmt = $this->db->prepare('
            SELECT id, user_email FROM drive_folders 
            WHERE share_token = ? 
            AND (share_expires IS NULL OR share_expires > ?)
        ');
        $folderStmt->execute([$folderToken, $now]);
        $sharedFolder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$sharedFolder) {
            error_log("getFileFromSharedFolder: Shared folder not found or expired");
            return null;
        }
        error_log("getFileFromSharedFolder: Found shared folder ID=" . $sharedFolder['id'] . ", user=" . $sharedFolder['user_email']);
        
        // Get the file
        $fileStmt = $this->db->prepare('
            SELECT * FROM drive_files 
            WHERE id = ? AND user_email = ?
        ');
        $fileStmt->execute([$fileId, $sharedFolder['user_email']]);
        $file = $fileStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$file) {
            error_log("getFileFromSharedFolder: File ID $fileId not found for user " . $sharedFolder['user_email']);
            return null;
        }
        error_log("getFileFromSharedFolder: Found file: " . $file['original_name'] . ", folder_id=" . ($file['folder_id'] ?? 'NULL'));
        
        // Check if file is in shared folder or any of its subfolders
        if (!$this->isInSharedFolderTree($sharedFolder['id'], $file['folder_id'], $sharedFolder['user_email'])) {
            error_log("getFileFromSharedFolder: File not in shared folder tree. Shared folder ID=" . $sharedFolder['id'] . ", file folder_id=" . ($file['folder_id'] ?? 'NULL'));
            return null;
        }
        
        // Use resolveFilePath to check both local and NAS storage
        $path = $this->resolveFilePath($sharedFolder['user_email'], $file['filename'], $file['storage_location'] ?? null);
        error_log("getFileFromSharedFolder: File path: " . ($path ?? 'NULL'));
        
        if (!$path) {
            error_log("getFileFromSharedFolder: Physical file does not exist in any storage location");
            return null;
        }
        
        error_log("getFileFromSharedFolder: SUCCESS - returning file info");
        // Phase 6d: shared-folder browse downloads count as real reads.
        $this->maybeTouchLastRead((int) $file['id']);
        return [
            'path' => $path,
            'mime_type' => $file['mime_type'],
            'filename' => $file['original_name'],
            'size' => $file['size'],
        ];
    }
    
    /**
     * Create a zip file of shared folder contents
     * @param string $folderToken The share token
     * @param array|null $fileIds Optional array of specific file IDs to include (null = all files)
     * @param int|null $subfolderId Optional subfolder ID to zip (null = root shared folder)
     * @return array|null Zip file info or null if failed
     */
    public function createSharedFolderZip(string $folderToken, ?array $fileIds = null, ?int $subfolderId = null): ?array
    {
        $now = date('Y-m-d H:i:s');
        
        // Verify the folder share token
        $folderStmt = $this->db->prepare('
            SELECT id, user_email, name FROM drive_folders 
            WHERE share_token = ? 
            AND (share_expires IS NULL OR share_expires > ?)
        ');
        $folderStmt->execute([$folderToken, $now]);
        $sharedFolder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$sharedFolder) {
            error_log("createSharedFolderZip: Shared folder not found or expired");
            return null;
        }
        
        $userEmail = $sharedFolder['user_email'];
        $userPath = $this->getUserPath($userEmail);
        
        // Determine which folder to zip
        $targetFolderId = $subfolderId ?? $sharedFolder['id'];
        $folderName = $sharedFolder['name'];
        
        // If subfolder, verify it's in the shared tree and get its name
        if ($subfolderId !== null) {
            if (!$this->isInSharedFolderTree($sharedFolder['id'], $subfolderId, $userEmail)) {
                error_log("createSharedFolderZip: Subfolder not in shared tree");
                return null;
            }
            $subStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
            $subStmt->execute([$subfolderId, $userEmail]);
            $sub = $subStmt->fetch(\PDO::FETCH_ASSOC);
            if ($sub) {
                $folderName = $sub['name'];
            }
        }
        
        // Get files to include
        $filesToZip = [];
        
        if ($fileIds !== null && count($fileIds) > 0) {
            // Specific files requested - verify each one
            foreach ($fileIds as $fileId) {
                $fileInfo = $this->getFileFromSharedFolder($folderToken, (int)$fileId);
                if ($fileInfo) {
                    $filesToZip[] = $fileInfo;
                }
            }
        } else {
            // Get all files from the folder and subfolders recursively
            $filesToZip = $this->getAllFilesInFolderTree($targetFolderId, $userEmail, $userPath, $sharedFolder['id']);
        }
        
        if (empty($filesToZip)) {
            error_log("createSharedFolderZip: No files to zip");
            return null;
        }
        
        // Create temp zip file
        $tempDir = sys_get_temp_dir();
        $zipFilename = 'shared_' . substr(md5($folderToken . time()), 0, 8) . '.zip';
        $zipPath = $tempDir . '/' . $zipFilename;
        
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error_log("createSharedFolderZip: Failed to create zip file");
            return null;
        }
        
        // Add files to zip - use addFromString to avoid path resolution issues
        $addedCount = 0;
        foreach ($filesToZip as $file) {
            $filePath = $file['path'];
            if (file_exists($filePath) && is_readable($filePath)) {
                $entryName = $file['relative_path'] ?? $file['filename'];
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    if ($zip->addFromString($entryName, $content)) {
                        $addedCount++;
                    }
                }
            }
        }
        
        $zip->close();
        
        if (!file_exists($zipPath) || $addedCount === 0) {
            error_log("createSharedFolderZip: Zip file was not created or no files added");
            return null;
        }
        
        return [
            'path' => $zipPath,
            'filename' => $folderName . '.zip',
            'size' => filesize($zipPath),
            'mime_type' => 'application/zip'
        ];
    }
    
    /**
     * Recursively get all files in a folder tree
     */
    private function getAllFilesInFolderTree(int $folderId, string $userEmail, string $userPath, int $rootSharedFolderId, string $relativePath = ''): array
    {
        $files = [];
        
        // Get folder name for path
        $folderStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
        $folderStmt->execute([$folderId, $userEmail]);
        $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
        
        // Build relative path (skip root folder name for cleaner structure)
        $currentPath = $relativePath;
        if ($folderId !== $rootSharedFolderId && $folder) {
            $currentPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
        }
        
        // Get files in this folder
        try {
            $fileStmt = $this->db->prepare('
                SELECT id, original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $fileStmt->execute([$userEmail, $folderId]);
        } catch (\Exception $e) {
            // Fallback without is_trashed
            $fileStmt = $this->db->prepare('
                SELECT id, original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ?
            ');
            $fileStmt->execute([$userEmail, $folderId]);
        }
        
        while ($file = $fileStmt->fetch(\PDO::FETCH_ASSOC)) {
            // Use resolveFilePath to check both local and NAS storage
            $filePath = $this->resolveFilePath($userEmail, $file['filename'], $file['storage_location'] ?? null);
            if ($filePath) {
                $files[] = [
                    'path' => $filePath,
                    'filename' => $file['original_name'],
                    'relative_path' => $currentPath ? $currentPath . '/' . $file['original_name'] : $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'size' => $file['size']
                ];
            } else {
                error_log("getAllFilesInFolderTree: File not found - {$file['original_name']}, storage=" . ($file['storage_location'] ?? 'null'));
            }
        }
        
        // Get subfolders and recurse
        $subStmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ?');
        $subStmt->execute([$userEmail, $folderId]);
        
        while ($sub = $subStmt->fetch(\PDO::FETCH_ASSOC)) {
            $subFiles = $this->getAllFilesInFolderTree($sub['id'], $userEmail, $userPath, $rootSharedFolderId, $currentPath);
            $files = array_merge($files, $subFiles);
        }
        
        return $files;
    }
    
    /**
     * Debug folder contents - check what files exist and their paths
     */
    public function debugFolderContents(string $userEmail, ?int $folderId = null): array
    {
        $hash = md5(strtolower($userEmail));
        $userPath = $this->getUserPath($userEmail);
        
        // All possible storage paths
        $nasPath = '/mnt/nas-drive/' . $hash;
        $localConfigPath = ($this->config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive') . '/' . $hash;
        $originalLocalPath = '/var/www/vps-email/storage/drive/' . $hash;
        
        $debug = [
            'user_email' => $userEmail,
            'user_hash' => $hash,
            'folder_id' => $folderId,
            'storage_config' => [
                'storagePath' => $this->storagePath,
                'storage_driver' => $this->storage->getDriver(),
                'storage_base' => $this->storage->getBasePath(),
                'config_drive_path' => $this->config['drive']['storage_path'] ?? 'NOT SET',
            ],
            'paths_to_check' => [
                'user_path (current)' => [
                    'path' => $userPath,
                    'exists' => is_dir($userPath),
                    'readable' => is_readable($userPath),
                ],
                'nas_path' => [
                    'path' => $nasPath,
                    'exists' => is_dir($nasPath),
                    'readable' => is_readable($nasPath),
                ],
                'local_config_path' => [
                    'path' => $localConfigPath,
                    'exists' => is_dir($localConfigPath),
                    'readable' => is_readable($localConfigPath),
                ],
                'original_local_path' => [
                    'path' => $originalLocalPath,
                    'exists' => is_dir($originalLocalPath),
                    'readable' => is_readable($originalLocalPath),
                ],
            ],
            'files_in_db' => [],
            'files_on_disk' => [],
            'missing_files' => [],
        ];
        
        // Get files from database with storage_location
        if ($folderId !== null) {
            $stmt = $this->db->prepare('
                SELECT id, original_name, filename, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $stmt->execute([$userEmail, $folderId]);
        } else {
            $stmt = $this->db->prepare('
                SELECT id, original_name, filename, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)
                LIMIT 20
            ');
            $stmt->execute([$userEmail]);
        }
        
        while ($file = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $storageLocation = $file['storage_location'] ?? null;
            
            // Build all paths to check for this file
            $pathsForFile = [
                'user_path' => $userPath . '/' . $file['filename'],
                'nas_path' => $nasPath . '/' . $file['filename'],
                'local_config_path' => $localConfigPath . '/' . $file['filename'],
                'original_local_path' => $originalLocalPath . '/' . $file['filename'],
            ];
            
            // Check which paths exist
            $foundPath = null;
            $pathResults = [];
            foreach ($pathsForFile as $name => $path) {
                $exists = file_exists($path);
                $pathResults[$name] = [
                    'path' => $path,
                    'exists' => $exists,
                ];
                if ($exists && !$foundPath) {
                    $foundPath = $path;
                }
            }
            
            // Also try resolveFilePath
            $resolvedPath = $this->resolveFilePath($userEmail, $file['filename'], $storageLocation);
            
            $debug['files_in_db'][] = [
                'id' => $file['id'],
                'original_name' => $file['original_name'],
                'stored_filename' => $file['filename'],
                'storage_location_in_db' => $storageLocation,
                'resolved_path' => $resolvedPath,
                'found_at' => $foundPath,
                'path_checks' => $pathResults,
                'size_in_db' => $file['size'],
            ];
            
            if (!$resolvedPath) {
                $debug['missing_files'][] = [
                    'name' => $file['original_name'],
                    'filename' => $file['filename'],
                    'storage_location' => $storageLocation,
                    'paths_checked' => array_keys($pathResults),
                ];
            }
        }
        
        // List actual files in each directory that exists
        foreach ($debug['paths_to_check'] as $name => $info) {
            if ($info['exists'] && is_dir($info['path'])) {
                $actualFiles = @scandir($info['path']);
                if ($actualFiles) {
                    $debug['files_on_disk'][$name] = array_values(array_filter($actualFiles, fn($f) => $f !== '.' && $f !== '..'));
                }
            }
        }
        
        $debug['summary'] = [
            'db_file_count' => count($debug['files_in_db']),
            'missing_count' => count($debug['missing_files']),
        ];
        
        return $debug;
    }
    
    /**
     * Create a zip of user's entire drive or a specific folder
     * @param string $userEmail The user's email
     * @param int|null $folderId Optional folder ID (null = entire drive)
     * @return array|null Zip file info or null if failed
     */
    public function createDriveZip(string $userEmail, ?int $folderId = null): ?array
    {
        // IMPORTANT: Lowercase email for database queries
        $userEmail = strtolower($userEmail);
        
        $userPath = $this->getUserPath($userEmail);
        error_log("createDriveZip: userEmail=$userEmail, folderId=" . ($folderId ?? 'null') . ", userPath=$userPath");
        
        $filesToZip = [];
        $zipName = 'My Drive';
        
        if ($folderId !== null) {
            // Get folder name
            $folderStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
            $folderStmt->execute([$folderId, $userEmail]);
            $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$folder) {
                error_log("createDriveZip: Folder not found");
                return null;
            }
            
            $zipName = $folder['name'];
            $filesToZip = $this->getAllFilesInUserFolder($folderId, $userEmail, $userPath, $folderId);
        } else {
            // Get all files in the entire drive
            $filesToZip = $this->getAllFilesInUserDrive($userEmail, $userPath);
        }
        
        error_log("createDriveZip: Found " . count($filesToZip) . " files to zip");
        foreach ($filesToZip as $f) {
            error_log("createDriveZip: File path={$f['path']}, exists=" . (file_exists($f['path']) ? 'yes' : 'no'));
        }
        
        if (empty($filesToZip)) {
            error_log("createDriveZip: No files to zip");
            return null;
        }
        
        // Create temp zip file
        $tempDir = sys_get_temp_dir();
        $zipFilename = 'drive_' . substr(md5($userEmail . time()), 0, 8) . '.zip';
        $zipPath = $tempDir . '/' . $zipFilename;
        
        error_log("createDriveZip: Creating zip at $zipPath");
        
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            error_log("createDriveZip: Failed to create zip file, error code: $openResult");
            return null;
        }
        
        // Add files to zip - use addFromString to avoid path resolution issues
        $addedCount = 0;
        foreach ($filesToZip as $file) {
            $filePath = $file['path'];
            if (file_exists($filePath) && is_readable($filePath)) {
                $entryName = $file['relative_path'] ?? $file['filename'];
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $result = $zip->addFromString($entryName, $content);
                    if ($result) {
                        $addedCount++;
                        error_log("createDriveZip: Added $filePath as $entryName (" . strlen($content) . " bytes)");
                    } else {
                        error_log("createDriveZip: FAILED to add $filePath to zip");
                    }
                } else {
                    error_log("createDriveZip: Could not read file: $filePath");
                }
            } else {
                error_log("createDriveZip: File not found or not readable: $filePath");
            }
        }
        
        error_log("createDriveZip: Added $addedCount files to zip");
        
        $closeResult = $zip->close();
        error_log("createDriveZip: Zip close result: " . ($closeResult ? 'success' : 'failed'));
        
        if (!file_exists($zipPath) || $addedCount === 0) {
            error_log("createDriveZip: Zip file was not created at $zipPath or no files added (addedCount=$addedCount)");
            @unlink($zipPath); // Clean up empty zip if it was created
            return null;
        }
        
        $finalSize = filesize($zipPath);
        error_log("createDriveZip: Zip created, size=$finalSize bytes");
        
        return [
            'path' => $zipPath,
            'filename' => $zipName . '.zip',
            'size' => $finalSize,
            'mime_type' => 'application/zip'
        ];
    }
    
    /**
     * Create ZIP files stored in Drive with 1GB splitting
     * Returns created files that can be shared like any other drive files
     * 
     * @param string $userEmail The user's email
     * @param int|null $folderId Optional folder ID (null = entire drive)
     * @return array Info about created ZIP files
     */
    public function createDriveZipToDrive(string $userEmail, ?int $folderId = null): array
    {
        // IMPORTANT: Lowercase email for database queries
        $userEmail = strtolower($userEmail);
        
        // Set unlimited execution time for large archives
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        
        $userPath = $this->getUserPath($userEmail);
        $maxPartSize = 1024 * 1024 * 1024; // 1GB per part
        
        // Collect all files to zip
        $filesToZip = [];
        $zipBaseName = 'My Drive';
        
        if ($folderId !== null) {
            $folderStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
            $folderStmt->execute([$folderId, $userEmail]);
            $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$folder) {
                return ['success' => false, 'message' => 'Folder not found', 'files' => []];
            }
            
            $zipBaseName = $folder['name'];
            $filesToZip = $this->getAllFilesInUserFolder($folderId, $userEmail, $userPath, $folderId);
        } else {
            $filesToZip = $this->getAllFilesInUserDrive($userEmail, $userPath);
        }
        
        if (empty($filesToZip)) {
            return ['success' => false, 'message' => 'No files to archive', 'files' => []];
        }
        
        // Calculate total size for progress estimation
        $totalSize = array_sum(array_map(fn($f) => $f['size'] ?? 0, $filesToZip));
        
        // Ensure "Downloads" folder exists
        $downloadsFolder = $this->findOrCreateFolder($userEmail, 'Downloads', null);
        if (!$downloadsFolder) {
            return ['success' => false, 'message' => 'Failed to create Downloads folder', 'files' => []];
        }
        
        // Create timestamp for unique naming
        $timestamp = date('Y-m-d_H-i-s');
        $createdFiles = [];
        
        // If total size is small enough, create single zip
        if ($totalSize <= $maxPartSize) {
            $result = $this->createSingleZipToDrive($userEmail, $userPath, $filesToZip, $zipBaseName, $timestamp, $downloadsFolder['id']);
            if ($result) {
                $createdFiles[] = $result;
            }
        } else {
            // Split into multiple parts
            $createdFiles = $this->createSplitZipsToDrive($userEmail, $userPath, $filesToZip, $zipBaseName, $timestamp, $downloadsFolder['id'], $maxPartSize);
        }
        
        if (empty($createdFiles)) {
            return ['success' => false, 'message' => 'Failed to create ZIP files', 'files' => []];
        }
        
        return [
            'success' => true,
            'message' => count($createdFiles) === 1 
                ? 'ZIP file created in Downloads folder' 
                : count($createdFiles) . ' ZIP parts created in Downloads folder',
            'files' => $createdFiles,
            'folder_id' => $downloadsFolder['id'],
            'total_files' => count($filesToZip),
            'total_size' => $totalSize
        ];
    }
    
    /**
     * Create a single ZIP and store in Drive
     */
    private function createSingleZipToDrive(
        string $userEmail, 
        string $userPath, 
        array $filesToZip, 
        string $baseName, 
        string $timestamp,
        int $folderId
    ): ?array {
        $tempDir = sys_get_temp_dir();
        $zipFilename = $baseName . '_' . $timestamp . '.zip';
        $tempZipPath = $tempDir . '/zip_' . substr(md5(uniqid()), 0, 12) . '.zip';
        
        $zip = new \ZipArchive();
        if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error_log("createSingleZipToDrive: Failed to create ZIP");
            return null;
        }
        
        foreach ($filesToZip as $file) {
            if (file_exists($file['path']) && is_readable($file['path'])) {
                $content = file_get_contents($file['path']);
                if ($content !== false) {
                    $zip->addFromString($file['relative_path'], $content);
                }
            }
        }
        
        $zip->close();
        
        if (!file_exists($tempZipPath)) {
            return null;
        }
        
        // Import the ZIP into Drive
        $driveFile = $this->importFileToDrive($userEmail, $tempZipPath, $zipFilename, $folderId);
        @unlink($tempZipPath);
        
        return $driveFile;
    }
    
    /**
     * Create multiple ZIP parts for large archives
     */
    private function createSplitZipsToDrive(
        string $userEmail, 
        string $userPath, 
        array $filesToZip, 
        string $baseName, 
        string $timestamp,
        int $folderId,
        int $maxPartSize
    ): array {
        $createdFiles = [];
        $partNumber = 1;
        $currentPartFiles = [];
        $currentPartSize = 0;
        
        foreach ($filesToZip as $file) {
            $fileSize = $file['size'] ?? (file_exists($file['path']) ? filesize($file['path']) : 0);
            
            // If adding this file would exceed limit, create current part and start new one
            if ($currentPartSize + $fileSize > $maxPartSize && !empty($currentPartFiles)) {
                $partFile = $this->createZipPart($userEmail, $userPath, $currentPartFiles, $baseName, $timestamp, $partNumber, $folderId);
                if ($partFile) {
                    $createdFiles[] = $partFile;
                }
                $partNumber++;
                $currentPartFiles = [];
                $currentPartSize = 0;
            }
            
            $currentPartFiles[] = $file;
            $currentPartSize += $fileSize;
        }
        
        // Create final part
        if (!empty($currentPartFiles)) {
            $partFile = $this->createZipPart($userEmail, $userPath, $currentPartFiles, $baseName, $timestamp, $partNumber, $folderId);
            if ($partFile) {
                $createdFiles[] = $partFile;
            }
        }
        
        return $createdFiles;
    }
    
    /**
     * Create a single ZIP part
     */
    private function createZipPart(
        string $userEmail, 
        string $userPath, 
        array $files, 
        string $baseName, 
        string $timestamp,
        int $partNumber,
        int $folderId
    ): ?array {
        $tempDir = sys_get_temp_dir();
        $zipFilename = sprintf('%s_%s_part%02d.zip', $baseName, $timestamp, $partNumber);
        $tempZipPath = $tempDir . '/zip_' . substr(md5(uniqid()), 0, 12) . '.zip';
        
        $zip = new \ZipArchive();
        if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        
        foreach ($files as $file) {
            if (file_exists($file['path']) && is_readable($file['path'])) {
                $content = file_get_contents($file['path']);
                if ($content !== false) {
                    $zip->addFromString($file['relative_path'], $content);
                }
            }
        }
        
        $zip->close();
        
        if (!file_exists($tempZipPath)) {
            return null;
        }
        
        $driveFile = $this->importFileToDrive($userEmail, $tempZipPath, $zipFilename, $folderId);
        @unlink($tempZipPath);
        
        return $driveFile;
    }
    
    /**
     * Import a file from path into Drive as a new file
     */
    private function importFileToDrive(string $userEmail, string $sourcePath, string $filename, int $folderId): ?array
    {
        if (!file_exists($sourcePath)) {
            return null;
        }
        
        $userPath = $this->getUserPath($userEmail);
        $size = filesize($sourcePath);
        $mimeType = 'application/zip';
        
        // Generate unique storage filename
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $storedFilename = substr(md5(uniqid() . $filename), 0, 32) . '.' . $ext;
        $destPath = $userPath . '/' . $storedFilename;
        
        if (!copy($sourcePath, $destPath)) {
            error_log("importFileToDrive: Failed to copy file");
            return null;
        }

        // Phase 1d: verify destination size matches the source size before
        // committing. NFS soft-mount partial-write guard.
        clearstatcache(true, $destPath);
        $written = @filesize($destPath);
        if ($written === false || $written !== $size) {
            @unlink($destPath);
            error_log("importFileToDrive: size mismatch. Expected={$size}, on-disk=" . var_export($written, true));
            return null;
        }

        $checksum = md5_file($destPath);
        
        // Insert into database
        $now = date('Y-m-d H:i:s');
        $storageLocation = $this->resolveStorageLocation();
        $stmt = $this->db->prepare('
            INSERT INTO drive_files (user_email, folder_id, original_name, filename, mime_type, size, checksum, created_at, updated_at, storage_location)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userEmail, $folderId, $filename, $storedFilename, $mimeType, $size, $checksum, $now, $now, $storageLocation]);

        $fileId = (int)$this->db->lastInsertId();

        // Phase 1c: queue NAS migration if this import landed on local fallback.
        $this->enqueueNasMigrationIfNeeded($fileId, $userEmail, $destPath);

        $this->updateQuota($userEmail, $size);

        return [
            'id' => $fileId,
            'original_name' => $filename,
            'filename' => $storedFilename,
            'mime_type' => $mimeType,
            'size' => $size,
            'folder_id' => $folderId,
            'created_at' => $now
        ];
    }
    
    /**
     * Create a zip of selected files
     * @param string $userEmail The user's email
     * @param array $fileIds Array of file IDs to include
     * @return array|null Zip file info or null if failed
     */
    public function createFilesZip(string $userEmail, array $fileIds): ?array
    {
        // IMPORTANT: Lowercase email for database queries
        $userEmail = strtolower($userEmail);
        
        if (empty($fileIds)) {
            error_log("createFilesZip: No file IDs provided");
            return null;
        }
        
        error_log("createFilesZip: Starting for user=$userEmail, fileIds=" . implode(',', $fileIds));
        
        $filesToZip = [];
        
        foreach ($fileIds as $fileId) {
            $stmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE id = ? AND user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $stmt->execute([(int)$fileId, $userEmail]);
            $file = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($file) {
                // Resolve the actual file path - check both local and NAS
                $filePath = $this->resolveFilePath($userEmail, $file['filename'], $file['storage_location'] ?? null);
                
                if ($filePath) {
                    $filesToZip[] = [
                        'path' => $filePath,
                        'filename' => $file['original_name'],
                        'relative_path' => $file['original_name'],
                        'mime_type' => $file['mime_type'],
                        'size' => $file['size']
                    ];
                    error_log("createFilesZip: Found file {$file['original_name']} at $filePath");
                } else {
                    error_log("createFilesZip: File not found - {$file['original_name']}, filename={$file['filename']}, storage=" . ($file['storage_location'] ?? 'null'));
                }
            }
        }
        
        if (empty($filesToZip)) {
            error_log("createFilesZip: No files to zip");
            return null;
        }
        
        // Create temp zip file
        $tempDir = sys_get_temp_dir();
        $zipFilename = 'files_' . substr(md5($userEmail . time()), 0, 8) . '.zip';
        $zipPath = $tempDir . '/' . $zipFilename;
        
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error_log("createFilesZip: Failed to create zip file");
            return null;
        }
        
        // Add files to zip - use addFromString to avoid path resolution issues
        $addedCount = 0;
        foreach ($filesToZip as $file) {
            $filePath = $file['path'];
            if (file_exists($filePath) && is_readable($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    if ($zip->addFromString($file['relative_path'], $content)) {
                        $addedCount++;
                    }
                }
            }
        }
        
        $zip->close();
        
        if (!file_exists($zipPath) || $addedCount === 0) {
            error_log("createFilesZip: Zip file was not created or no files added");
            return null;
        }
        
        $zipName = count($filesToZip) === 1 ? pathinfo($filesToZip[0]['filename'], PATHINFO_FILENAME) : 'Selected Files';
        
        return [
            'path' => $zipPath,
            'filename' => $zipName . '.zip',
            'size' => filesize($zipPath),
            'mime_type' => 'application/zip'
        ];
    }
    
    /**
     * Create a ZIP from a selection of files and folders
     * This handles mixed selections where user selects both files and folders
     */
    public function createSelectionZip(string $userEmail, array $fileIds, array $folderIds, ?string $debugSessionId = null): ?array
    {
        // IMPORTANT: Lowercase email for database queries
        $userEmail = strtolower($userEmail);
        
        // Log debug session ID at start
        if ($debugSessionId) {
            error_log("createSelectionZip: Debug session ID received: $debugSessionId");
        } else {
            error_log("createSelectionZip: WARNING - No debug session ID provided");
        }
        
        $userPath = $this->getUserPath($userEmail);
        $filesToZip = [];
        
        $this->logZipDebug($debugSessionId ?? '', 'START', [
            'userEmail' => $userEmail,
            'fileIds' => $fileIds,
            'folderIds' => $folderIds,
            'userPath' => $userPath
        ]);
        
        // Add individual files
        foreach ($fileIds as $fileId) {
            $this->logZipDebug($debugSessionId ?? '', 'PROCESSING_FILE', ['fileId' => $fileId]);
            
            $stmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE id = ? AND user_email = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $stmt->execute([(int)$fileId, $userEmail]);
            $file = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($file) {
                $this->logZipDebug($debugSessionId ?? '', 'FILE_FOUND_IN_DB', [
                    'fileId' => $fileId,
                    'original_name' => $file['original_name'],
                    'filename' => $file['filename'],
                    'storage_location' => $file['storage_location'] ?? 'null',
                    'size' => $file['size']
                ]);
                
                // Resolve the actual file path - check both local and NAS
                $filePath = $this->resolveFilePath($userEmail, $file['filename'], $file['storage_location'] ?? null);
                
                if ($filePath) {
                    $fileInfo = [
                        'path' => $filePath,
                        'filename' => $file['original_name'],
                        'relative_path' => $file['original_name'],
                        'mime_type' => $file['mime_type'],
                        'size' => $file['size'],
                        'exists' => file_exists($filePath),
                        'readable' => is_readable($filePath),
                        'actual_size' => file_exists($filePath) ? filesize($filePath) : 0
                    ];
                    
                    $filesToZip[] = $fileInfo;
                    
                    $this->logZipDebug($debugSessionId ?? '', 'FILE_ADDED', $fileInfo);
                } else {
                    $this->logZipDebug($debugSessionId ?? '', 'FILE_PATH_RESOLVE_FAILED', [
                        'fileId' => $fileId,
                        'filename' => $file['filename'],
                        'storage_location' => $file['storage_location'] ?? 'null'
                    ]);
                }
            } else {
                $this->logZipDebug($debugSessionId ?? '', 'FILE_NOT_FOUND_IN_DB', ['fileId' => $fileId]);
            }
        }
        
        // Add folder contents
        foreach ($folderIds as $folderId) {
            $this->logZipDebug($debugSessionId ?? '', 'PROCESSING_FOLDER', ['folderId' => $folderId]);
            
            // Get folder name
            $folderStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
            $folderStmt->execute([(int)$folderId, $userEmail]);
            $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($folder) {
                $this->logZipDebug($debugSessionId ?? '', 'FOLDER_FOUND', [
                    'folderId' => $folderId,
                    'folderName' => $folder['name']
                ]);
                
                // Get all files in this folder recursively
                $folderFiles = $this->getAllFilesInUserFolder((int)$folderId, $userEmail, $userPath, (int)$folderId, $folder['name'], $debugSessionId);
                
                $this->logZipDebug($debugSessionId ?? '', 'FOLDER_FILES_COLLECTED', [
                    'folderId' => $folderId,
                    'folderName' => $folder['name'],
                    'fileCount' => count($folderFiles)
                ]);
                
                $filesToZip = array_merge($filesToZip, $folderFiles);
            } else {
                $this->logZipDebug($debugSessionId ?? '', 'FOLDER_NOT_FOUND', ['folderId' => $folderId]);
            }
        }
        
        $this->logZipDebug($debugSessionId ?? '', 'FILES_COLLECTED', [
            'totalFiles' => count($filesToZip),
            'files' => array_map(fn($f) => [
                'name' => $f['filename'],
                'path' => $f['path'],
                'size' => $f['size'] ?? 0,
                'exists' => $f['exists'] ?? file_exists($f['path'] ?? ''),
                'readable' => $f['readable'] ?? (isset($f['path']) && is_readable($f['path']))
            ], $filesToZip)
        ]);
        
        if (empty($filesToZip)) {
            $this->logZipDebug($debugSessionId ?? '', 'NO_FILES_TO_ZIP', []);
            return null;
        }
        
        // Create temp zip file
        $tempDir = sys_get_temp_dir();
        $zipFilename = 'selection_' . substr(md5($userEmail . time()), 0, 8) . '.zip';
        $zipPath = $tempDir . '/' . $zipFilename;
        
        $this->logZipDebug($debugSessionId ?? '', 'CREATING_ZIP', [
            'zipPath' => $zipPath,
            'tempDir' => $tempDir
        ]);
        
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($openResult !== true) {
            $this->logZipDebug($debugSessionId ?? '', 'ZIP_OPEN_FAILED', [
                'errorCode' => $openResult,
                'errorMessage' => $this->getZipErrorMessage($openResult)
            ]);
            return null;
        }
        
        $this->logZipDebug($debugSessionId ?? '', 'ZIP_OPENED', ['zipPath' => $zipPath]);
        
        // Add files to zip
        $addedCount = 0;
        $failedCount = 0;
        
        foreach ($filesToZip as $index => $file) {
            $filePath = $file['path'];
            $entryName = $file['relative_path'] ?? $file['filename'];
            
            $this->logZipDebug($debugSessionId ?? '', 'ADDING_FILE_TO_ZIP', [
                'index' => $index + 1,
                'total' => count($filesToZip),
                'entryName' => $entryName,
                'filePath' => $filePath,
                'exists' => file_exists($filePath),
                'readable' => is_readable($filePath)
            ]);
            
            if (file_exists($filePath) && is_readable($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $contentSize = strlen($content);
                    $addResult = $zip->addFromString($entryName, $content);
                    
                    if ($addResult) {
                        $addedCount++;
                        $this->logZipDebug($debugSessionId ?? '', 'FILE_ADDED_TO_ZIP', [
                            'entryName' => $entryName,
                            'contentSize' => $contentSize,
                            'addedCount' => $addedCount
                        ]);
                    } else {
                        $failedCount++;
                        $this->logZipDebug($debugSessionId ?? '', 'FILE_ADD_TO_ZIP_FAILED', [
                            'entryName' => $entryName,
                            'reason' => 'addFromString returned false'
                        ]);
                    }
                } else {
                    $failedCount++;
                    $this->logZipDebug($debugSessionId ?? '', 'FILE_READ_FAILED', [
                        'entryName' => $entryName,
                        'filePath' => $filePath
                    ]);
                }
            } else {
                $failedCount++;
                $this->logZipDebug($debugSessionId ?? '', 'FILE_NOT_ACCESSIBLE', [
                    'entryName' => $entryName,
                    'filePath' => $filePath,
                    'exists' => file_exists($filePath),
                    'readable' => is_readable($filePath)
                ]);
            }
        }
        
        $this->logZipDebug($debugSessionId ?? '', 'CLOSING_ZIP', [
            'addedCount' => $addedCount,
            'failedCount' => $failedCount
        ]);
        
        $closeResult = $zip->close();
        
        if (!$closeResult) {
            $this->logZipDebug($debugSessionId ?? '', 'ZIP_CLOSE_FAILED', []);
        }
        
        $finalSize = file_exists($zipPath) ? filesize($zipPath) : 0;
        
        $this->logZipDebug($debugSessionId ?? '', 'ZIP_CREATED', [
            'zipPath' => $zipPath,
            'finalSize' => $finalSize,
            'addedCount' => $addedCount,
            'failedCount' => $failedCount,
            'exists' => file_exists($zipPath)
        ]);
        
        if (!file_exists($zipPath) || $addedCount === 0) {
            $this->logZipDebug($debugSessionId ?? '', 'ZIP_VALIDATION_FAILED', [
                'exists' => file_exists($zipPath),
                'addedCount' => $addedCount
            ]);
            return null;
        }
        
        // Generate a friendly name
        $itemCount = count($fileIds) + count($folderIds);
        $zipName = $itemCount === 1 
            ? (count($folderIds) === 1 ? $filesToZip[0]['relative_path'] ?? 'Folder' : pathinfo($filesToZip[0]['filename'] ?? 'File', PATHINFO_FILENAME))
            : "$itemCount Items";
        
        $this->logZipDebug($debugSessionId ?? '', 'COMPLETE', [
            'zipPath' => $zipPath,
            'zipName' => $zipName,
            'finalSize' => $finalSize
        ]);
        
        return [
            'path' => $zipPath,
            'filename' => $zipName . '.zip',
            'size' => $finalSize,
            'mime_type' => 'application/zip'
        ];
    }
    
    /**
     * Get all files in the entire user drive (recursive)
     */
    private function getAllFilesInUserDrive(string $userEmail, string $userPath): array
    {
        $files = [];
        
        // Get root-level files - include storage_location
        try {
            $fileStmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id IS NULL AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $fileStmt->execute([$userEmail]);
        } catch (\Exception $e) {
            $fileStmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, NULL as storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id IS NULL
            ');
            $fileStmt->execute([$userEmail]);
        }
        
        while ($file = $fileStmt->fetch(\PDO::FETCH_ASSOC)) {
            // Resolve the actual file path - check both local and NAS
            $filePath = $this->resolveFilePath($userEmail, $file['filename'], $file['storage_location'] ?? null);
            
            if ($filePath) {
                $files[] = [
                    'path' => $filePath,
                    'filename' => $file['original_name'],
                    'relative_path' => $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'size' => $file['size']
                ];
                error_log("getAllFilesInUserDrive: Found root file {$file['original_name']} at $filePath");
            } else {
                error_log("getAllFilesInUserDrive: Root file not found - {$file['original_name']}, filename={$file['filename']}, storage=" . ($file['storage_location'] ?? 'null'));
            }
        }
        
        // Get root-level folders and recurse
        $folderStmt = $this->db->prepare('SELECT id, name FROM drive_folders WHERE user_email = ? AND parent_id IS NULL');
        $folderStmt->execute([$userEmail]);
        
        while ($folder = $folderStmt->fetch(\PDO::FETCH_ASSOC)) {
            $folderFiles = $this->getAllFilesInUserFolder($folder['id'], $userEmail, $userPath, null, $folder['name']);
            $files = array_merge($files, $folderFiles);
        }
        
        return $files;
    }
    
    /**
     * Get all files in a user folder (recursive)
     */
    /**
     * Get the base storage path for a given storage location
     * Handles both local and NAS storage locations
     */
    private function getStorageBasePath(?string $storageLocation): string
    {
        // Possible storage locations
        $nasPath = '/mnt/nas-drive';
        $localPath = $this->config['drive']['storage_path'] ?? dirname(__DIR__, 2) . '/storage/drive';
        
        // If storage location indicates NAS/NFS
        if ($storageLocation && in_array(strtolower($storageLocation), ['nfs', 'nas', 'nfs_mount'])) {
            return $nasPath;
        }
        
        // If storage location indicates local
        if ($storageLocation && strtolower($storageLocation) === 'local') {
            return $localPath;
        }
        
        // Default to current storage path
        return $this->storagePath;
    }
    
    /**
     * Resolve the actual file path, checking both local and NAS storage
     */
    public function resolveFilePath(string $userEmail, string $filename, ?string $storageLocation = null): ?string
    {
        $hash = md5(strtolower($userEmail));

        if ($storageLocation && in_array(strtolower($storageLocation), ['nfs', 'nas', 'nfs_mount'], true)) {
            $nasPathStmt = $this->db->prepare('
                SELECT nas_relative_path
                FROM drive_files
                WHERE user_email = ? AND filename = ? AND nas_relative_path IS NOT NULL AND nas_relative_path != ""
                ORDER BY id DESC
                LIMIT 1
            ');
            $nasPathStmt->execute([strtolower($userEmail), $filename]);
            $nasRelativePath = $nasPathStmt->fetchColumn();

            if ($nasRelativePath) {
                $resolvedNasPath = $this->resolveNasFilePath((string)$nasRelativePath, $userEmail);
                $nasDown = !NasHealthCheck::isAvailable();
                if ($resolvedNasPath && (!$nasDown || !NasHealthCheck::isNasPath($resolvedNasPath))) {
                    if (file_exists($resolvedNasPath) && is_readable($resolvedNasPath)) {
                        return $resolvedNasPath;
                    }
                }
            }
        }
        
        // Possible storage locations to check
        $pathsToCheck = [];
        
        // If we have a storage location, check that first
        if ($storageLocation) {
            $basePath = $this->getStorageBasePath($storageLocation);
            $pathsToCheck[] = $basePath . '/' . $hash . '/' . $filename;
        }
        
        // Always check current storage path
        $pathsToCheck[] = $this->storagePath . '/' . $hash . '/' . $filename;
        
        // Also check NAS if not already checked
        $nasPath = '/mnt/nas-drive/' . $hash . '/' . $filename;
        if (!in_array($nasPath, $pathsToCheck)) {
            $pathsToCheck[] = $nasPath;
        }
        
        // Also check local fallback if different
        $localPath = ($this->config['drive']['storage_path'] ?? dirname(__DIR__, 2) . '/storage/drive') . '/' . $hash . '/' . $filename;
        if (!in_array($localPath, $pathsToCheck)) {
            $pathsToCheck[] = $localPath;
        }
        
        // ALSO check the ORIGINAL local storage path (before NAS was implemented)
        // This ensures files uploaded before NAS migration are still accessible
        $originalLocalPath = '/var/www/vps-email/storage/drive/' . $hash . '/' . $filename;
        if (!in_array($originalLocalPath, $pathsToCheck)) {
            $pathsToCheck[] = $originalLocalPath;
        }
        
        // Check each path — skip NAS paths when mount is unavailable
        $nasDown = !NasHealthCheck::isAvailable();
        foreach ($pathsToCheck as $path) {
            if ($nasDown && NasHealthCheck::isNasPath($path)) {
                continue;
            }
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        error_log("resolveFilePath: File not found in any location - filename=$filename, userEmail=$userEmail, storageLocation=$storageLocation, nasDown=$nasDown, checked: " . implode(', ', $pathsToCheck));
        return null;
    }
    
    /**
     * Log debug information for zip creation
     */
    private function logZipDebug(string $sessionId, string $step, array $data = []): void
    {
        if (empty($sessionId) || $sessionId === 'default') {
            // Log when session ID is missing for debugging
            if ($step === 'START') {
                error_log("logZipDebug: WARNING - No session ID provided for START step");
            }
            return; // Skip logging if no session ID
        }
        
        // Use app storage instead of sys_get_temp_dir() for consistent cross-request access
        $debugDir = __DIR__ . '/../../storage/cache/zip_debug';
        if (!is_dir($debugDir)) {
            @mkdir($debugDir, 0755, true);
        }
        $debugFile = $debugDir . '/zip_debug_' . $sessionId . '.json';
        
        // Log first write attempt for debugging
        if ($step === 'START') {
            error_log("logZipDebug: Writing START step to: $debugFile");
        }
        $debug = [];
        
        if (file_exists($debugFile)) {
            $content = @file_get_contents($debugFile);
            if ($content !== false) {
                $debug = json_decode($content, true) ?: [];
            }
        }
        
        if (!isset($debug['steps'])) {
            $debug['steps'] = [];
        }
        
        if (!isset($debug['session_id'])) {
            $debug['session_id'] = $sessionId;
        }
        
        $debug['steps'][] = [
            'timestamp' => microtime(true),
            'time' => date('H:i:s.v'),
            'step' => $step,
            'data' => $data
        ];
        
        if (!isset($debug['files'])) {
            $debug['files'] = [];
        }
        
        if (!isset($debug['errors'])) {
            $debug['errors'] = [];
        }
        
        $debug['last_update'] = microtime(true);
        $debug['status'] = $step;
        
        $jsonData = json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = @file_put_contents($debugFile, $jsonData, LOCK_EX);
        
        // Force flush to disk
        if ($result !== false) {
            @fflush(fopen($debugFile, 'r+'));
        }
        
        // Log if file write fails or for first step
        if ($result === false) {
            error_log("logZipDebug: Failed to write debug file: $debugFile");
            error_log("logZipDebug: Session ID: $sessionId, Step: $step");
            error_log("logZipDebug: Temp dir exists: " . (is_dir(sys_get_temp_dir()) ? 'yes' : 'no'));
            error_log("logZipDebug: Temp dir writable: " . (is_writable(sys_get_temp_dir()) ? 'yes' : 'no'));
        } else {
            error_log("logZipDebug: Wrote step '$step' to $debugFile, bytes: $result, steps count: " . count($debug['steps']));
        }
    }
    
    /**
     * Get ZIP error message from error code
     */
    private function getZipErrorMessage(int $code): string
    {
        $errors = [
            \ZipArchive::ER_OK => 'No error',
            \ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            \ZipArchive::ER_RENAME => 'Renaming temporary file failed',
            \ZipArchive::ER_CLOSE => 'Closing zip archive failed',
            \ZipArchive::ER_SEEK => 'Seek error',
            \ZipArchive::ER_READ => 'Read error',
            \ZipArchive::ER_WRITE => 'Write error',
            \ZipArchive::ER_CRC => 'CRC error',
            \ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
            \ZipArchive::ER_NOENT => 'No such file',
            \ZipArchive::ER_EXISTS => 'File already exists',
            \ZipArchive::ER_OPEN => 'Can\'t open file',
            \ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
            \ZipArchive::ER_ZLIB => 'Zlib error',
            \ZipArchive::ER_MEMORY => 'Memory allocation failure',
            \ZipArchive::ER_CHANGED => 'Entry has been changed',
            \ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
            \ZipArchive::ER_EOF => 'Premature EOF',
            \ZipArchive::ER_INVAL => 'Invalid argument',
            \ZipArchive::ER_NOZIP => 'Not a zip archive',
            \ZipArchive::ER_INTERNAL => 'Internal error',
            \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            \ZipArchive::ER_REMOVE => 'Can\'t remove file',
            \ZipArchive::ER_DELETED => 'Entry has been deleted',
        ];
        
        return $errors[$code] ?? "Unknown error ($code)";
    }
    
    private function getAllFilesInUserFolder(int $folderId, string $userEmail, string $userPath, ?int $rootFolderId = null, string $relativePath = '', ?string $debugSessionId = null): array
    {
        $files = [];
        
        // Get folder name for path
        $folderStmt = $this->db->prepare('SELECT name FROM drive_folders WHERE id = ? AND user_email = ?');
        $folderStmt->execute([$folderId, $userEmail]);
        $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
        
        // Build relative path
        $currentPath = $relativePath;
        if ($rootFolderId !== null && $folderId !== $rootFolderId && $folder) {
            $currentPath = $relativePath ? $relativePath . '/' . $folder['name'] : $folder['name'];
        } elseif ($rootFolderId === null && $folder && $relativePath === '') {
            // For drive root, use folder name as start of path
            $currentPath = $folder['name'];
        }
        
        // Get files in this folder - include storage_location to find files correctly
        try {
            $fileStmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $fileStmt->execute([$userEmail, $folderId]);
        } catch (\Exception $e) {
            // Fallback without storage_location if column doesn't exist
            $fileStmt = $this->db->prepare('
                SELECT original_name, filename, mime_type, size, NULL as storage_location 
                FROM drive_files 
                WHERE user_email = ? AND folder_id = ?
            ');
            $fileStmt->execute([$userEmail, $folderId]);
        }
        
        while ($file = $fileStmt->fetch(\PDO::FETCH_ASSOC)) {
            // Resolve the actual file path - check both local and NAS
            $filePath = $this->resolveFilePath($userEmail, $file['filename'], $file['storage_location'] ?? null);
            
            if ($filePath) {
                $fileInfo = [
                    'path' => $filePath,
                    'filename' => $file['original_name'],
                    'relative_path' => $currentPath ? $currentPath . '/' . $file['original_name'] : $file['original_name'],
                    'mime_type' => $file['mime_type'],
                    'size' => $file['size'],
                    'exists' => file_exists($filePath),
                    'readable' => is_readable($filePath),
                    'actual_size' => file_exists($filePath) ? filesize($filePath) : 0
                ];
                
                $files[] = $fileInfo;
                
                if ($debugSessionId) {
                    $this->logZipDebug($debugSessionId, 'FOLDER_FILE_FOUND', [
                        'folderId' => $folderId,
                        'relativePath' => $currentPath,
                        'file' => $fileInfo
                    ]);
                }
            } else {
                if ($debugSessionId) {
                    $this->logZipDebug($debugSessionId, 'FOLDER_FILE_NOT_FOUND', [
                        'folderId' => $folderId,
                        'filename' => $file['filename'],
                        'storage_location' => $file['storage_location'] ?? 'null'
                    ]);
                }
            }
        }
        
        // Get subfolders and recurse
        $subStmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ?');
        $subStmt->execute([$userEmail, $folderId]);
        
        while ($sub = $subStmt->fetch(\PDO::FETCH_ASSOC)) {
            $subFiles = $this->getAllFilesInUserFolder($sub['id'], $userEmail, $userPath, $rootFolderId, $currentPath, $debugSessionId);
            $files = array_merge($files, $subFiles);
        }
        
        return $files;
    }
    
    /**
     * Check if a folder is the shared folder or a descendant of it
     */
    private function isInSharedFolderTree(int $sharedFolderId, ?int $folderId, string $userEmail): bool
    {
        error_log("isInSharedFolderTree: sharedFolderId=$sharedFolderId, folderId=" . ($folderId ?? 'NULL'));
        
        if ($folderId === null) {
            error_log("isInSharedFolderTree: File has no folder_id (is at root level) - NOT in shared tree");
            return false;
        }
        if ($folderId === $sharedFolderId) {
            error_log("isInSharedFolderTree: File is directly in shared folder - OK");
            return true;
        }
        
        // Walk up the folder tree
        $currentId = $folderId;
        $maxDepth = 50; // Prevent infinite loops
        
        while ($currentId !== null && $maxDepth > 0) {
            if ($currentId === $sharedFolderId) {
                error_log("isInSharedFolderTree: Found shared folder in parent chain - OK");
                return true;
            }
            
            $stmt = $this->db->prepare('SELECT parent_id FROM drive_folders WHERE id = ? AND user_email = ?');
            $stmt->execute([$currentId, $userEmail]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$result) break;
            
            $currentId = $result['parent_id'];
            $maxDepth--;
        }
        
        error_log("isInSharedFolderTree: File is NOT in shared folder tree");
        return false;
    }
    
    /**
     * Get subfolder contents from a shared folder (validates parent token)
     */
    public function getSubfolderFromSharedFolder(string $folderToken, int $subfolderId): ?array
    {
        // Use PHP time for consistent timezone handling
        $now = date('Y-m-d H:i:s');
        
        // Verify the root share token
        $rootStmt = $this->db->prepare('
            SELECT id, user_email FROM drive_folders 
            WHERE share_token = ? 
            AND (share_expires IS NULL OR share_expires > ?)
        ');
        $rootStmt->execute([$folderToken, $now]);
        $sharedFolder = $rootStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$sharedFolder) return null;
        
        // Check if the subfolder is inside the shared folder tree
        if (!$this->isInSharedFolderTree($sharedFolder['id'], $subfolderId, $sharedFolder['user_email'])) {
            return null;
        }
        
        // Get subfolder info
        $folderStmt = $this->db->prepare('
            SELECT id, name, parent_id FROM drive_folders 
            WHERE id = ? AND user_email = ?
        ');
        $folderStmt->execute([$subfolderId, $sharedFolder['user_email']]);
        $folder = $folderStmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$folder) return null;
        
        // Get files in this subfolder
        $fileStmt = $this->db->prepare('
            SELECT id, original_name, mime_type, size, created_at 
            FROM drive_files 
            WHERE user_email = ? AND folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY original_name
        ');
        $fileStmt->execute([$sharedFolder['user_email'], $subfolderId]);
        $files = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get nested subfolders
        $subStmt = $this->db->prepare('
            SELECT id, name, created_at 
            FROM drive_folders 
            WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY name
        ');
        $subStmt->execute([$sharedFolder['user_email'], $subfolderId]);
        $subfolders = $subStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Build breadcrumb path from shared folder to this subfolder
        $path = $this->buildPathFromSharedFolder($sharedFolder['id'], $subfolderId, $sharedFolder['user_email']);
        
        return [
            'folder' => [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'parent_id' => $folder['parent_id'],
            ],
            'files' => $files,
            'subfolders' => $subfolders,
            'path' => $path,
            'shared_folder_id' => $sharedFolder['id'],
        ];
    }
    
    /**
     * Build breadcrumb path from shared folder to target folder
     */
    private function buildPathFromSharedFolder(int $sharedFolderId, int $targetFolderId, string $userEmail): array
    {
        $path = [];
        $currentId = $targetFolderId;
        $maxDepth = 50;
        
        while ($currentId !== null && $currentId !== $sharedFolderId && $maxDepth > 0) {
            $stmt = $this->db->prepare('SELECT id, name, parent_id FROM drive_folders WHERE id = ? AND user_email = ?');
            $stmt->execute([$currentId, $userEmail]);
            $folder = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$folder) break;
            
            array_unshift($path, ['id' => $folder['id'], 'name' => $folder['name']]);
            $currentId = $folder['parent_id'];
            $maxDepth--;
        }
        
        return $path;
    }
    
    // ===== UTILITIES =====
    
    /**
     * Get folder breadcrumbs (path from root)
     */
    public function getFolderPath(string $email, int $folderId): array
    {
        $path = [];
        $current = $this->getFolder($email, $folderId);
        
        while ($current) {
            array_unshift($path, $current);
            $current = $current['parent_id'] ? $this->getFolder($email, $current['parent_id']) : null;
        }
        
        return $path;
    }
    
    /**
     * Format file size for display
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
    
    // ===== TRASH OPERATIONS =====
    
    /**
     * Move file to trash (soft delete)
     */
    public function trashFile(string $email, int $id): bool
    {
        $email = strtolower($email);
        $file = $this->getFile($email, $id);
        
        if (!$file) return false;
        
        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET is_trashed = 1, trashed_at = NOW(), original_folder_id = folder_id, folder_id = NULL 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$email, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Move folder to trash (soft delete)
     * Also marks all contents as trashed
     */
    public function trashFolder(string $email, int $id): bool|string
    {
        $email = strtolower($email);
        $folder = $this->getFolder($email, $id);
        
        if (!$folder) return false;
        
        // Protect system folders from being trashed
        $systemFolders = ['Boards', 'Attachments', 'Chats', 'Invoices', 'Moodboards'];
        if (in_array($folder['name'], $systemFolders) && $folder['parent_id'] === null) {
            return "Cannot trash the {$folder['name']} folder - it is a system folder.";
        }
        
        // Protect board-linked folders
        $boardRef = $this->getFolderBoardReference($id);
        if ($boardRef) {
            return "Cannot trash folder - it is linked to board \"{$boardRef['name']}\".";
        }
        
        // Trash the folder itself
        $stmt = $this->db->prepare('
            UPDATE drive_folders 
            SET is_trashed = 1, trashed_at = NOW(), original_parent_id = parent_id, parent_id = NULL 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$email, $id]);
        
        // Also trash all subfolders and files within (recursive)
        $this->trashFolderContentsRecursive($email, $id);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Recursively trash folder contents
     */
    private function trashFolderContentsRecursive(string $email, int $folderId): void
    {
        // Trash files in this folder
        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET is_trashed = 1, trashed_at = NOW() 
            WHERE user_email = ? AND folder_id = ?
        ');
        $stmt->execute([$email, $folderId]);
        
        // Get subfolders
        $stmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)');
        $stmt->execute([$email, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        foreach ($subfolders as $subfolder) {
            // Trash the subfolder
            $trashStmt = $this->db->prepare('
                UPDATE drive_folders 
                SET is_trashed = 1, trashed_at = NOW() 
                WHERE user_email = ? AND id = ?
            ');
            $trashStmt->execute([$email, $subfolder['id']]);
            
            // Recursively trash contents
            $this->trashFolderContentsRecursive($email, $subfolder['id']);
        }
    }
    
    /**
     * Restore file from trash
     */
    public function restoreFile(string $email, int $id): bool
    {
        $email = strtolower($email);
        
        // Get file to check original folder
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND id = ? AND is_trashed = 1');
        $stmt->execute([$email, $id]);
        $file = $stmt->fetch();
        
        if (!$file) return false;
        
        // Check if original folder still exists and is not trashed
        $targetFolderId = null;
        if ($file['original_folder_id']) {
            $folderStmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND id = ? AND (is_trashed = 0 OR is_trashed IS NULL)');
            $folderStmt->execute([$email, $file['original_folder_id']]);
            if ($folderStmt->fetch()) {
                $targetFolderId = $file['original_folder_id'];
            }
        }
        
        // Restore file
        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET is_trashed = 0, trashed_at = NULL, folder_id = ?, original_folder_id = NULL 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$targetFolderId, $email, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Restore folder from trash
     * Also restores all contents
     */
    public function restoreFolder(string $email, int $id): bool
    {
        $email = strtolower($email);
        
        // Get folder to check original parent
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND id = ? AND is_trashed = 1');
        $stmt->execute([$email, $id]);
        $folder = $stmt->fetch();
        
        if (!$folder) return false;
        
        // Check if original parent still exists and is not trashed
        $targetParentId = null;
        if ($folder['original_parent_id']) {
            $parentStmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND id = ? AND (is_trashed = 0 OR is_trashed IS NULL)');
            $parentStmt->execute([$email, $folder['original_parent_id']]);
            if ($parentStmt->fetch()) {
                $targetParentId = $folder['original_parent_id'];
            }
        }
        
        // Restore folder
        $stmt = $this->db->prepare('
            UPDATE drive_folders 
            SET is_trashed = 0, trashed_at = NULL, parent_id = ?, original_parent_id = NULL 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$targetParentId, $email, $id]);
        
        // Restore contents
        $this->restoreFolderContentsRecursive($email, $id);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Recursively restore folder contents
     */
    private function restoreFolderContentsRecursive(string $email, int $folderId): void
    {
        // Restore files in this folder
        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET is_trashed = 0, trashed_at = NULL 
            WHERE user_email = ? AND (folder_id = ? OR original_folder_id = ?) AND is_trashed = 1
        ');
        $stmt->execute([$email, $folderId, $folderId]);
        
        // Get subfolders that were trashed with this folder
        $stmt = $this->db->prepare('SELECT id FROM drive_folders WHERE user_email = ? AND (parent_id = ? OR original_parent_id = ?) AND is_trashed = 1');
        $stmt->execute([$email, $folderId, $folderId]);
        $subfolders = $stmt->fetchAll();
        
        foreach ($subfolders as $subfolder) {
            // Restore the subfolder
            $restoreStmt = $this->db->prepare('
                UPDATE drive_folders 
                SET is_trashed = 0, trashed_at = NULL, parent_id = ? 
                WHERE user_email = ? AND id = ?
            ');
            $restoreStmt->execute([$folderId, $email, $subfolder['id']]);
            
            // Recursively restore contents
            $this->restoreFolderContentsRecursive($email, $subfolder['id']);
        }
    }
    
    /**
     * Get all trashed items for a user
     */
    public function getTrashedItems(string $email): array
    {
        $email = strtolower($email);
        
        // Get trashed files (only root-level trashed items, not nested)
        $stmt = $this->db->prepare('
            SELECT f.*, 
                   COALESCE(fo.name, "Root") as original_location
            FROM drive_files f
            LEFT JOIN drive_folders fo ON f.original_folder_id = fo.id
            WHERE f.user_email = ? AND f.is_trashed = 1
            ORDER BY f.trashed_at DESC
        ');
        $stmt->execute([$email]);
        $files = $stmt->fetchAll();
        
        // Get trashed folders (only root-level trashed folders)
        $stmt = $this->db->prepare('
            SELECT f.*, 
                   COALESCE(pf.name, "Root") as original_location
            FROM drive_folders f
            LEFT JOIN drive_folders pf ON f.original_parent_id = pf.id
            WHERE f.user_email = ? AND f.is_trashed = 1 
            AND (f.original_parent_id IS NULL OR f.original_parent_id NOT IN (
                SELECT id FROM drive_folders WHERE user_email = ? AND is_trashed = 1
            ))
            ORDER BY f.trashed_at DESC
        ');
        $stmt->execute([$email, $email]);
        $folders = $stmt->fetchAll();
        
        return [
            'files' => $files,
            'folders' => $folders,
        ];
    }
    
    /**
     * Empty trash - permanently delete all trashed items
     */
    public function emptyTrash(string $email): int
    {
        $email = strtolower($email);
        $deletedCount = 0;
        
        // Get all trashed files
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND is_trashed = 1');
        $stmt->execute([$email]);
        $files = $stmt->fetchAll();
        
        foreach ($files as $file) {
            if ($this->permanentlyDeleteFile($email, $file['id'])) {
                $deletedCount++;
            }
        }
        
        // Get all trashed folders (delete from leaves up)
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE user_email = ? AND is_trashed = 1');
        $stmt->execute([$email]);
        $folders = $stmt->fetchAll();
        
        foreach ($folders as $folder) {
            if ($this->permanentlyDeleteFolder($email, $folder['id'])) {
                $deletedCount++;
            }
        }
        
        return $deletedCount;
    }
    
    /**
     * Permanently delete a file (removes from trash)
     */
    public function permanentlyDeleteFile(string $email, int $id): bool
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        $file = $stmt->fetch();
        
        if (!$file) return false;
        
        // Delete all versions first
        $this->versioning()->deleteAllVersions($email, $id);
        
        // Delete physical file
        $this->deleteFilePhysical($email, $file);
        
        // Delete from database
        $stmt = $this->db->prepare('DELETE FROM drive_files WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Permanently delete a folder (removes from trash)
     */
    public function permanentlyDeleteFolder(string $email, int $id): bool
    {
        $email = strtolower($email);
        
        // Delete all contents first
        $this->deleteFolderContentsRecursive($email, $id);
        
        // Delete the folder
        $stmt = $this->db->prepare('DELETE FROM drive_folders WHERE user_email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Auto-cleanup trash items older than specified days
     */
    public function autoCleanupTrash(int $daysOld = 30): int
    {
        $deletedCount = 0;
        
        // Get all trashed files older than X days
        $stmt = $this->db->prepare('
            SELECT user_email, id FROM drive_files 
            WHERE is_trashed = 1 AND trashed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ');
        $stmt->execute([$daysOld]);
        $files = $stmt->fetchAll();
        
        foreach ($files as $file) {
            if ($this->permanentlyDeleteFile($file['user_email'], $file['id'])) {
                $deletedCount++;
            }
        }
        
        // Get all trashed folders older than X days
        $stmt = $this->db->prepare('
            SELECT user_email, id FROM drive_folders 
            WHERE is_trashed = 1 AND trashed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ');
        $stmt->execute([$daysOld]);
        $folders = $stmt->fetchAll();
        
        foreach ($folders as $folder) {
            if ($this->permanentlyDeleteFolder($folder['user_email'], $folder['id'])) {
                $deletedCount++;
            }
        }
        
        return $deletedCount;
    }
    
    // ===== FILE VERSIONING =====
    
    /**
     * Check for existing file with same name and handle versioning
     * Returns the file ID (existing or new)
     */
    public function uploadFileWithVersioning(string $email, array $uploadedFile, ?int $folderId = null): ?array
    {
        $email = strtolower($email);
        $originalName = $uploadedFile['name'];
        
        // Check for existing file with same name in same folder
        $existingFile = $this->findFileByName($email, $originalName, $folderId);
        
        if ($existingFile) {
            // Create new version of existing file
            return $this->versioning()->createNewVersion($email, $existingFile['id'], $uploadedFile);
        }
        
        // No existing file, create new
        return $this->uploadFile($email, $uploadedFile, $folderId);
    }
    
    /**
     * Find file by name in folder
     */
    public function findFileByName(string $email, string $name, ?int $folderId): ?array
    {
        $email = strtolower($email);
        
        if ($folderId === null) {
            $stmt = $this->db->prepare('
                SELECT * FROM drive_files 
                WHERE user_email = ? AND original_name = ? AND folder_id IS NULL 
                AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $stmt->execute([$email, $name]);
        } else {
            $stmt = $this->db->prepare('
                SELECT * FROM drive_files 
                WHERE user_email = ? AND original_name = ? AND folder_id = ? 
                AND (is_trashed = 0 OR is_trashed IS NULL)
            ');
            $stmt->execute([$email, $name, $folderId]);
        }
        
        return $stmt->fetch() ?: null;
    }
    
    // ===== CHUNKED / RESUMABLE UPLOAD =====
    //
    // A single PHP request body cannot exceed ~2GB across the LSAPI pipe
    // (OpenLiteSpeed -> lsphp overflows: "packetLen < 0"). For larger files the
    // browser slices the file into small parts and POSTs them to
    // /drive/upload-chunk; the parts are appended into one assembly file and,
    // on the final chunk, committed exactly like a normal upload.

    /**
     * Guard the client-supplied upload id against path traversal. The id is
     * used as a filename, so restrict it to a safe character set.
     */
    private function assertValidUploadId(string $uploadId): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uploadId)) {
            throw new \RuntimeException('Invalid upload id');
        }
        return $uploadId;
    }

    /**
     * Directory that holds in-progress chunk assemblies for a user. Kept under
     * the user's storage dir so the final rename() into place is same-filesystem
     * (atomic) and the partial bytes count against the same volume.
     */
    private function getChunkDir(string $email): string
    {
        $userPath = $this->getUserPath($email); // also sets lastResolvedTier
        $chunkDir = $userPath . '/.chunks';
        if (!is_dir($chunkDir)) {
            @mkdir($chunkDir, 0755, true);
        }
        return $chunkDir;
    }

    /**
     * Bytes already assembled for an upload id (0 if none). Used by the client
     * to resume after an interrupted upload.
     */
    public function getChunkUploadStatus(string $email, string $uploadId): int
    {
        $email = strtolower($email);
        $this->assertValidUploadId($uploadId);
        $partPath = $this->getChunkDir($email) . '/' . $uploadId . '.part';
        clearstatcache(true, $partPath);
        return is_file($partPath) ? (int) filesize($partPath) : 0;
    }

    /**
     * Append one chunk to the assembly file. Chunks must arrive in order; the
     * offset check makes retries idempotent and rejects gaps so the client can
     * resume from getChunkUploadStatus(). Returns total bytes assembled so far.
     */
    public function appendUploadChunk(
        string $email,
        string $uploadId,
        int $chunkIndex,
        int $chunkSize,
        string $tmpChunkPath
    ): int {
        $email = strtolower($email);
        $this->assertValidUploadId($uploadId);

        if (!is_uploaded_file($tmpChunkPath)) {
            throw new \RuntimeException('Chunk validation failed');
        }

        $partPath = $this->getChunkDir($email) . '/' . $uploadId . '.part';

        clearstatcache(true, $partPath);
        $current = is_file($partPath) ? (int) filesize($partPath) : 0;

        // A fresh upload (index 0) always starts clean - drop any stale leftover
        // sharing this id.
        if ($chunkIndex === 0 && $current > 0) {
            @unlink($partPath);
            $current = 0;
        }

        $thisLen = (int) filesize($tmpChunkPath);
        $expectedOffset = $chunkIndex * $chunkSize;

        // Idempotent retry: this exact chunk is already on disk.
        if ($current === $expectedOffset + $thisLen) {
            return $current;
        }
        if ($current !== $expectedOffset) {
            throw new \RuntimeException("Chunk offset mismatch (have {$current}, expected {$expectedOffset})");
        }

        $in = @fopen($tmpChunkPath, 'rb');
        $out = @fopen($partPath, 'ab');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            throw new \RuntimeException('Failed to open chunk for assembly');
        }
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false) {
            throw new \RuntimeException('Failed to write chunk to assembly');
        }

        clearstatcache(true, $partPath);
        return (int) filesize($partPath);
    }

    /**
     * Commit a fully-assembled chunked upload: validate size/type/quota, move
     * the assembly into place, and write the drive_files row. Mirrors
     * uploadFile()/createNewVersion() but works on an already-assembled path
     * (no is_uploaded_file / move_uploaded_file, and never reads the file into
     * memory). Supports versioning when a file of the same name exists.
     */
    public function finalizeChunkedUpload(
        string $email,
        string $uploadId,
        string $originalName,
        int $expectedSize,
        ?int $folderId = null
    ): ?array {
        $email = strtolower($email);
        $this->assertValidUploadId($uploadId);

        // --- File type validation (mirror uploadFile) ---
        $nameParts = explode('.', strtolower($originalName));
        array_shift($nameParts);
        foreach ($nameParts as $ext) {
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                $this->cleanupChunkUpload($email, $uploadId);
                throw new \RuntimeException("File type '.{$ext}' is not allowed");
            }
        }

        $partPath = $this->getChunkDir($email) . '/' . $uploadId . '.part';
        clearstatcache(true, $partPath);
        if (!is_file($partPath)) {
            throw new \RuntimeException('Assembled upload not found (it may have expired)');
        }

        $assembledSize = (int) filesize($partPath);
        if ($assembledSize !== $expectedSize) {
            @unlink($partPath);
            throw new \RuntimeException("Assembled size mismatch (have {$assembledSize}, expected {$expectedSize})");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMimeType = $finfo->file($partPath);
        if ($detectedMimeType && in_array($detectedMimeType, self::BLOCKED_MIME_TYPES, true)) {
            @unlink($partPath);
            throw new \RuntimeException("File type '{$detectedMimeType}' is not allowed");
        }

        if (!$this->hasQuota($email, $assembledSize)) {
            @unlink($partPath);
            throw new \RuntimeException("Not enough storage space (file: " . self::formatSize($assembledSize) . ")");
        }
        // Phase 6b: system-wide admission control (may throw
        // StorageBudgetExceededException, caught by DriveController).
        $this->maybeAdmitUpload($assembledSize);

        // Move the assembly into its final, randomized name.
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        $userPath = $this->getUserPath($email);
        $targetPath = $userPath . '/' . $filename;

        if (!@rename($partPath, $targetPath)) {
            // Cross-device or NFS hiccup: fall back to a streamed copy.
            if (!$this->streamCopyFile($partPath, $targetPath)) {
                @unlink($partPath);
                error_log("DriveService finalizeChunkedUpload: failed to store assembled file at {$targetPath}");
                throw new \RuntimeException('Failed to save file to storage');
            }
            @unlink($partPath);
        }

        clearstatcache(true, $targetPath);
        $written = @filesize($targetPath);
        if ($written === false || $written !== $assembledSize) {
            @unlink($targetPath);
            throw new \RuntimeException('Upload write verification failed: size mismatch');
        }

        $mimeType = $detectedMimeType ?: (mime_content_type($targetPath) ?: 'application/octet-stream');
        $storageLocation = $this->resolveStorageLocation();

        try {
            $existing = $this->findFileByName($email, $originalName, $folderId);

            if ($existing) {
                $fileId = (int) $existing['id'];

                $archive = $this->versioning()->archiveCurrentAsVersion($email, $existing);
                if (!$archive) {
                    @unlink($targetPath);
                    throw new \RuntimeException('Failed to archive previous version');
                }

                $newVersion = $archive['version_number'] + 1;
                $this->db->prepare('
                    UPDATE drive_files
                    SET filename = ?, size = ?, mime_type = ?, current_version = ?, last_modified_by = ?, storage_location = ?, updated_at = NOW()
                    WHERE user_email = ? AND id = ?
                ')->execute([$filename, $assembledSize, $mimeType, $newVersion, $email, $storageLocation, $email, $fileId]);

                $this->enqueueNasMigrationIfNeeded($fileId, $email, $targetPath);
                $this->updateUsedSpace($email, $assembledSize);
                if ($folderId) {
                    $this->updateFolderSizeWithParents($email, $folderId);
                }

                $this->versioning()->pruneFileVersions($email, $fileId);

                return $this->getFile($email, $fileId);
            }

            $stmt = $this->db->prepare('
                INSERT INTO drive_files (user_email, folder_id, filename, original_name, size, mime_type, storage_location)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$email, $folderId, $filename, $originalName, $assembledSize, $mimeType, $storageLocation]);
            $insertId = (int) $this->db->lastInsertId();

            $this->enqueueNasMigrationIfNeeded($insertId, $email, $targetPath);
            $this->updateUsedSpace($email, $assembledSize);
            if ($folderId) {
                $this->updateFolderSizeWithParents($email, $folderId);
            }

            return $this->getFile($email, $insertId);
        } catch (\PDOException $e) {
            @unlink($targetPath);
            error_log("DriveService finalizeChunkedUpload PDO error: " . $e->getMessage());
            throw new \RuntimeException("Database error while saving file record");
        }
    }

    /**
     * Best-effort removal of an in-progress chunk assembly.
     */
    private function cleanupChunkUpload(string $email, string $uploadId): void
    {
        try {
            $partPath = $this->getChunkDir($email) . '/' . $uploadId . '.part';
            if (is_file($partPath)) {
                @unlink($partPath);
            }
        } catch (\Throwable $e) {
            // ignore - cleanup is non-critical
        }
    }

    /**
     * Streamed file copy (low memory) used as a rename() fallback.
     */
    private function streamCopyFile(string $from, string $to): bool
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'wb');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            return false;
        }
        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        fclose($out);
        return $ok;
    }

    /**
     * Update file content from a local file path
     * Used for programmatic file updates (e.g., from collab editor save)
     * 
     * @param string $email User email
     * @param int $fileId File ID to update
     * @param string $sourcePath Path to the source file to copy content from
     * @param bool $createVersion Whether to save the current version before updating
     * @return array|null Updated file data or null on failure
     */
    public function updateFileContent(string $email, int $fileId, string $sourcePath, bool $createVersion = true): ?array
    {
        $email = strtolower($email);
        $file = $this->getFile($email, $fileId);
        
        if (!$file) {
            error_log("DriveService updateFileContent: File not found for email=$email, fileId=$fileId");
            return null;
        }
        
        if (!file_exists($sourcePath)) {
            error_log("DriveService updateFileContent: Source file not found: $sourcePath");
            return null;
        }
        
        $newSize = filesize($sourcePath);
        $oldSize = (int)$file['size'];
        
        // Quota: when a version is kept the old bytes STAY on disk, so the
        // whole new content is a net addition; without versioning only the
        // delta changes.
        $sizeDiff = $newSize - $oldSize;
        $quotaCharge = $createVersion ? $newSize : $sizeDiff;
        if ($quotaCharge > 0 && !$this->hasQuota($email, $quotaCharge)) {
            error_log("DriveService updateFileContent: Quota exceeded");
            return null;
        }
        // Phase 6b: only gate when the write would actually add bytes
        // to the system; shrinking edits or no-ops sail through.
        if ($quotaCharge > 0) {
            $this->maybeAdmitUpload($quotaCharge);
        }
        
        try {
            // Save current version to history if requested
            if ($createVersion) {
                if (!$this->versioning()->archiveCurrentAsVersion($email, $file)) {
                    error_log("DriveService updateFileContent: Failed to archive current version");
                    return null;
                }
            }
            
            // Generate new filename for the updated content
            $extension = pathinfo($file['original_name'], PATHINFO_EXTENSION);
            $newFilename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
            
            $userPath = $this->getUserPath($email);
            $targetPath = $userPath . '/' . $newFilename;
            
            // Copy source file to target
            if (!copy($sourcePath, $targetPath)) {
                error_log("DriveService updateFileContent: Failed to copy file to $targetPath");
                return null;
            }
            
            // Update file record
            $newVersion = $createVersion ? ((int)($file['current_version'] ?? 1) + 1) : (int)($file['current_version'] ?? 1);
            $mimeType = mime_content_type($targetPath) ?? $file['mime_type'];
            $storageLocation = $this->resolveStorageLocation();
            
            $stmt = $this->db->prepare('
                UPDATE drive_files 
                SET filename = ?, size = ?, mime_type = ?, current_version = ?, last_modified_by = ?, storage_location = ?, updated_at = NOW()
                WHERE user_email = ? AND id = ?
            ');
            $stmt->execute([$newFilename, $newSize, $mimeType, $newVersion, $email, $storageLocation, $email, $fileId]);
            
            $this->enqueueNasMigrationIfNeeded($fileId, $email, $targetPath);
            
            // Update quota
            if ($quotaCharge != 0) {
                $this->updateUsedSpace($email, $quotaCharge);
            }
            
            // Delete old file if different from new - but never if a version
            // row adopted that filename (e.g. a prior versions/snapshot call),
            // otherwise we would destroy the version's bytes.
            if ($file['filename'] !== $newFilename && !$createVersion) {
                $oldPath = $userPath . '/' . $file['filename'];
                $refs = $this->db->prepare('SELECT COUNT(*) FROM drive_file_versions WHERE file_id = ? AND filename = ?');
                $refs->execute([$fileId, $file['filename']]);
                if (file_exists($oldPath) && (int)$refs->fetchColumn() === 0) {
                    unlink($oldPath);
                }
            }
            
            if ($createVersion) {
                $this->versioning()->pruneFileVersions($email, $fileId);
            }
            
            // Re-index the freshly persisted row so OnlyOffice edits (and any
            // other content replacement) become searchable/highlightable.
            $updatedFile = $this->getFile($email, $fileId);
            if ($updatedFile) {
                $this->reindexFileForSearch($updatedFile);
            }
            return $updatedFile;
            
        } catch (\PDOException $e) {
            error_log("DriveService updateFileContent error: " . $e->getMessage());
            return null;
        }
    }
    
    // ===== ACTIVITY TRACKING =====
    
    /**
     * Record file access (for "last opened" tracking)
     */
    public function recordFileAccess(string $email, int $fileId): bool
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET last_opened_at = NOW(), last_opened_by = ? 
            WHERE id = ? AND user_email = ?
        ');
        $stmt->execute([$email, $fileId, $email]);
        
        return $stmt->rowCount() > 0;
    }

    // ===== VIEW-ONLY RESTRICTIONS + ACCESS LOG =====

    /**
     * Get the no_download / no_print flags for a file. Returns null when the
     * file does not exist. Tolerates the columns not existing yet.
     */
    public function getFileRestrictions(int $fileId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT no_download, no_print FROM drive_files WHERE id = ?');
            $stmt->execute([$fileId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            return [
                'no_download' => (bool)($row['no_download'] ?? false),
                'no_print' => (bool)($row['no_print'] ?? false),
            ];
        } catch (\Exception $e) {
            return ['no_download' => false, 'no_print' => false];
        }
    }

    /**
     * Set the no_download / no_print flags for a file. Owner-only: the update
     * is scoped to the caller's own row. Returns false when the caller does
     * not own the file.
     */
    public function setFileRestrictions(string $email, int $fileId, bool $noDownload, bool $noPrint): bool
    {
        $email = strtolower($email);

        $check = $this->db->prepare('SELECT id FROM drive_files WHERE id = ? AND user_email = ?');
        $check->execute([$fileId, $email]);
        if (!$check->fetch()) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE drive_files
            SET no_download = ?, no_print = ?
            WHERE id = ? AND user_email = ?
        ');
        $stmt->execute([$noDownload ? 1 : 0, $noPrint ? 1 : 0, $fileId, $email]);
        return true;
    }

    /**
     * Resolve the caller's effective role on a file:
     * 'owner' | 'editor' | 'viewer' | 'none'.
     *
     * Used to scope view-only restrictions (no download / no print) to
     * viewers only. Anonymous accessors (public link / guest links) always
     * resolve to 'viewer'.
     */
    public function getEffectiveRole(int $fileId, ?string $email): string
    {
        if ($email === null || $email === '') {
            return 'viewer';
        }
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT user_email FROM drive_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 'none';
        }
        if (strtolower((string)$row['user_email']) === $email) {
            return 'owner';
        }

        $permission = null;

        try {
            $sharing = new DriveFileSharingService($this->db);
            $direct = $sharing->resolveDirectFileAccess($email, $fileId);
            if ($direct) {
                $permission = ($direct['permission'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';
            }
        } catch (\Exception $e) {
            // ignore, fall through to folder access
        }

        if ($permission !== 'editor') {
            $folder = $this->hasFileCollaboratorAccess($email, $fileId);
            if ($folder) {
                $p = ($folder['permission'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';
                if ($permission === null || $p === 'editor') {
                    $permission = $p;
                }
            }
        }

        return $permission ?? 'none';
    }

    /**
     * Whether download must be blocked for the given accessor. Only VIEW-access
     * recipients (and anonymous public/guest viewers) are blocked when the file
     * has no_download set; the owner and editors are never blocked.
     */
    public function isViewerDownloadBlocked(array $file, ?string $email): bool
    {
        if (empty($file['no_download'])) {
            return false;
        }
        $role = $this->getEffectiveRole((int)($file['id'] ?? 0), $email);
        return $role !== 'owner' && $role !== 'editor';
    }

    /**
     * Append an access-log row for a file (who / when / what action).
     * Best-effort; never throws into the request path.
     */
    public function logFileAccess(int $fileId, ?string $email, string $action = 'open', ?string $ip = null, ?string $ua = null): void
    {
        if ($fileId <= 0) {
            return;
        }
        $allowed = ['open', 'download', 'print', 'download_blocked'];
        if (!in_array($action, $allowed, true)) {
            $action = 'open';
        }
        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_file_access_log (file_id, user_email, action, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $fileId,
                strtolower((string)($email ?? 'anonymous')),
                $action,
                $ip !== null ? substr($ip, 0, 45) : null,
                $ua !== null ? substr($ua, 0, 500) : null,
            ]);
        } catch (\Exception $e) {
            error_log('logFileAccess error: ' . $e->getMessage());
        }
    }

    /**
     * Aggregated "who opened this file" history for the Properties panel.
     * Returns rows: { user_email, open_count, first_opened_at, last_opened_at },
     * most recently opened first. Only 'open' actions are counted.
     */
    public function getFileAccessLog(int $fileId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_email,
                       COUNT(*) AS open_count,
                       MIN(created_at) AS first_opened_at,
                       MAX(created_at) AS last_opened_at
                FROM drive_file_access_log
                WHERE file_id = ? AND action = 'open'
                GROUP BY user_email
                ORDER BY last_opened_at DESC
            ");
            $stmt->execute([$fileId]);
            return array_map(static function ($r) {
                return [
                    'user_email' => $r['user_email'],
                    'open_count' => (int)$r['open_count'],
                    'first_opened_at' => $r['first_opened_at'],
                    'last_opened_at' => $r['last_opened_at'],
                ];
            }, $stmt->fetchAll());
        } catch (\Exception $e) {
            return [];
        }
    }

    // ===== STARRED + RECENT =====

    /**
     * Toggle the star flag on a file. Returns the new state.
     */
    public function toggleStarFile(string $email, int $fileId): ?bool
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT is_starred FROM drive_files WHERE id = ? AND user_email = ?');
        $stmt->execute([$fileId, $email]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $new = ((int)($row['is_starred'] ?? 0)) === 1 ? 0 : 1;
        $upd = $this->db->prepare('UPDATE drive_files SET is_starred = ? WHERE id = ? AND user_email = ?');
        $upd->execute([$new, $fileId, $email]);

        return $new === 1;
    }

    /**
     * Toggle the star flag on a folder. Returns the new state.
     */
    public function toggleStarFolder(string $email, int $folderId): ?bool
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT is_starred FROM drive_folders WHERE id = ? AND user_email = ?');
        $stmt->execute([$folderId, $email]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $new = ((int)($row['is_starred'] ?? 0)) === 1 ? 0 : 1;
        $upd = $this->db->prepare('UPDATE drive_folders SET is_starred = ? WHERE id = ? AND user_email = ?');
        $upd->execute([$new, $folderId, $email]);

        return $new === 1;
    }

    /**
     * Record folder access (powers the Recent view for folders).
     */
    public function recordFolderAccess(string $email, int $folderId): bool
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('
            UPDATE drive_folders
            SET last_accessed_at = NOW()
            WHERE id = ? AND user_email = ?
        ');
        $stmt->execute([$folderId, $email]);

        return $stmt->rowCount() > 0;
    }

    /**
     * List starred files + folders for the user (excludes trashed items).
     */
    public function getStarredItems(string $email): array
    {
        $email = strtolower($email);

        $f = $this->db->prepare('
            SELECT f.*, COALESCE(fo.name, "Root") AS location
            FROM drive_files f
            LEFT JOIN drive_folders fo ON f.folder_id = fo.id
            WHERE f.user_email = ?
              AND f.is_starred = 1
              AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ORDER BY f.original_name
        ');
        $f->execute([$email]);
        $files = $f->fetchAll();

        $fo = $this->db->prepare('
            SELECT fo.*, COALESCE(pf.name, "Root") AS location
            FROM drive_folders fo
            LEFT JOIN drive_folders pf ON fo.parent_id = pf.id
            WHERE fo.user_email = ?
              AND fo.is_starred = 1
              AND (fo.is_trashed = 0 OR fo.is_trashed IS NULL)
            ORDER BY fo.name
        ');
        $fo->execute([$email]);
        $folders = $fo->fetchAll();

        return [
            'files' => $files,
            'folders' => $folders,
        ];
    }

    /**
     * List recently accessed files + folders for the user (excludes trashed).
     * Ordered by last_opened_at (files) / last_accessed_at (folders) DESC.
     */
    public function getRecentItems(string $email, int $limit = 50): array
    {
        $email = strtolower($email);
        $limit = max(1, min(200, $limit));

        $f = $this->db->prepare('
            SELECT f.*, COALESCE(fo.name, "Root") AS location
            FROM drive_files f
            LEFT JOIN drive_folders fo ON f.folder_id = fo.id
            WHERE f.user_email = ?
              AND f.last_opened_at IS NOT NULL
              AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
            ORDER BY f.last_opened_at DESC
            LIMIT ' . $limit . '
        ');
        $f->execute([$email]);
        $files = $f->fetchAll();

        $fo = $this->db->prepare('
            SELECT fo.*, COALESCE(pf.name, "Root") AS location
            FROM drive_folders fo
            LEFT JOIN drive_folders pf ON fo.parent_id = pf.id
            WHERE fo.user_email = ?
              AND fo.last_accessed_at IS NOT NULL
              AND (fo.is_trashed = 0 OR fo.is_trashed IS NULL)
            ORDER BY fo.last_accessed_at DESC
            LIMIT ' . $limit . '
        ');
        $fo->execute([$email]);
        $folders = $fo->fetchAll();

        return [
            'files' => $files,
            'folders' => $folders,
        ];
    }
    
    /**
     * Get file with detailed information (includes folder path, creator info)
     */
    public function getFileWithDetails(string $email, int $id): ?array
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare('
            SELECT f.*, 
                   fo.name as folder_name,
                   fo.id as folder_id
            FROM drive_files f
            LEFT JOIN drive_folders fo ON f.folder_id = fo.id
            WHERE f.user_email = ? AND f.id = ?
        ');
        $stmt->execute([$email, $id]);
        $file = $stmt->fetch();
        
        if (!$file) return null;
        
        // Build full folder path
        $folderPath = [];
        if ($file['folder_id']) {
            $folderPath = $this->getFolderPath($email, $file['folder_id']);
        }
        
        $file['folder_path'] = $folderPath;
        $file['folder_path_string'] = $folderPath ? implode(' / ', array_column($folderPath, 'name')) : 'Root';
        
        return $file;
    }
    
    /**
     * Get files with details for list view (includes folder info)
     */
    public function getFilesWithDetails(string $email, ?int $folderId = null): array
    {
        $email = strtolower($email);
        
        if ($folderId === null) {
            $stmt = $this->db->prepare('
                SELECT f.*, 
                       "Root" as location
                FROM drive_files f
                WHERE f.user_email = ? AND f.folder_id IS NULL 
                AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
                ORDER BY f.original_name
            ');
            $stmt->execute([$email]);
        } else {
            $stmt = $this->db->prepare('
                SELECT f.*, 
                       fo.name as location
                FROM drive_files f
                LEFT JOIN drive_folders fo ON f.folder_id = fo.id
                WHERE f.user_email = ? AND f.folder_id = ? 
                AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
                ORDER BY f.original_name
            ');
            $stmt->execute([$email, $folderId]);
        }
        
        return $stmt->fetchAll();
    }
    
    // ===== FOLDER COLLABORATORS =====
    
    /**
     * Add a collaborator to a folder
     * @param string $ownerEmail Email of the folder owner
     * @param int $folderId Folder ID
     * @param string $collaboratorEmail Email of the user to add
     * @param string $permission 'viewer' or 'editor'
     * @return array Result with success status
     */
    public function addFolderCollaborator(string $ownerEmail, int $folderId, string $collaboratorEmail, string $permission = 'viewer'): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $collaboratorEmail = strtolower($collaboratorEmail);
        
        // Verify folder belongs to owner
        $folder = $this->getFolder($ownerEmail, $folderId);
        if (!$folder) {
            return ['success' => false, 'error' => 'Folder not found or access denied'];
        }
        
        // Can't add yourself
        if ($ownerEmail === $collaboratorEmail) {
            return ['success' => false, 'error' => 'Cannot share folder with yourself'];
        }
        
        // Validate permission
        if (!in_array($permission, ['viewer', 'editor'])) {
            $permission = 'viewer';
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_folder_collaborators (folder_id, user_email, permission, invited_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE permission = VALUES(permission), updated_at = NOW()
            ');
            $stmt->execute([$folderId, $collaboratorEmail, $permission, $ownerEmail]);
            
            $this->_fireDriveFolderPermissionAutomation($folderId, $folder['name'] ?? "Folder #{$folderId}", $ownerEmail, "Collaborator {$collaboratorEmail} added with {$permission} access");
            
            return [
                'success' => true,
                'collaborator' => [
                    'email' => $collaboratorEmail,
                    'permission' => $permission,
                    'invited_by' => $ownerEmail
                ]
            ];
        } catch (\PDOException $e) {
            error_log("Failed to add collaborator: " . $e->getMessage());
            // Return actual error for debugging
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Remove a collaborator from a folder
     */
    public function removeFolderCollaborator(string $ownerEmail, int $folderId, string $collaboratorEmail): bool
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            $collaboratorEmail = strtolower($collaboratorEmail);
            
            // Verify folder belongs to owner
            $folder = $this->getFolder($ownerEmail, $folderId);
            if (!$folder) {
                return false;
            }
            
            // Get folder name before deletion for audit trail
            $folder = $this->getFolder($ownerEmail, $folderId);

            $stmt = $this->db->prepare('
                DELETE FROM drive_folder_collaborators 
                WHERE folder_id = ? AND user_email = ?
            ');
            $stmt->execute([$folderId, $collaboratorEmail]);
            
            $removed = $stmt->rowCount() > 0;
            if ($removed) {
                $this->_fireDriveFolderPermissionAutomation($folderId, $folder['name'] ?? "Folder #{$folderId}", $ownerEmail, "Collaborator {$collaboratorEmail} removed");
            }
            
            return $removed;
        } catch (\Exception $e) {
            error_log("removeFolderCollaborator error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update collaborator permission
     */
    public function updateCollaboratorPermission(string $ownerEmail, int $folderId, string $collaboratorEmail, string $permission): bool
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            $collaboratorEmail = strtolower($collaboratorEmail);
            
            // Verify folder belongs to owner
            $folder = $this->getFolder($ownerEmail, $folderId);
            if (!$folder) {
                return false;
            }
            
            if (!in_array($permission, ['viewer', 'editor'])) {
                return false;
            }
            
            $stmt = $this->db->prepare('
                UPDATE drive_folder_collaborators 
                SET permission = ?, updated_at = NOW()
                WHERE folder_id = ? AND user_email = ?
            ');
            $stmt->execute([$permission, $folderId, $collaboratorEmail]);
            
            $updated = $stmt->rowCount() > 0;
            if ($updated) {
                $folder = $this->getFolder($ownerEmail, $folderId);
                $this->_fireDriveFolderPermissionAutomation($folderId, $folder['name'] ?? "Folder #{$folderId}", $ownerEmail, "Collaborator {$collaboratorEmail} permission changed to {$permission}");
            }
            
            return $updated;
        } catch (\Exception $e) {
            error_log("updateCollaboratorPermission error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all collaborators for a folder
     */
    public function getFolderCollaborators(string $ownerEmail, int $folderId): array
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            
            // Verify folder belongs to owner
            $folder = $this->getFolder($ownerEmail, $folderId);
            if (!$folder) {
                return [];
            }
            
            $stmt = $this->db->prepare('
                SELECT user_email as email, permission, invited_by, accepted_at, created_at
                FROM drive_folder_collaborators
                WHERE folder_id = ?
                ORDER BY created_at DESC
            ');
            $stmt->execute([$folderId]);
            
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            // Table might not exist yet
            error_log("getFolderCollaborators error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get group access for a folder
     */
    public function getFolderGroupAccess(string $ownerEmail, int $folderId): array
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            
            // Verify folder belongs to owner
            $folder = $this->getFolder($ownerEmail, $folderId);
            if (!$folder) {
                return [];
            }
            
            $stmt = $this->db->prepare('
                SELECT ga.group_id, g.name as group_name, g.color as group_color, 
                       g.icon as group_icon, ga.permission, ga.granted_by, ga.created_at,
                       (SELECT COUNT(*) FROM colleague_group_members WHERE group_id = ga.group_id) as member_count
                FROM drive_folder_group_access ga
                JOIN colleague_groups g ON ga.group_id = g.id
                WHERE ga.folder_id = ?
                ORDER BY g.name ASC
            ');
            $stmt->execute([$folderId]);
            
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("getFolderGroupAccess error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Remove group access from a folder
     */
    public function removeGroupAccess(string $ownerEmail, int $folderId, int $groupId): array
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            
            // Verify folder belongs to owner
            $folder = $this->getFolder($ownerEmail, $folderId);
            if (!$folder) {
                return ['success' => false, 'error' => 'Folder not found'];
            }
            
            $stmt = $this->db->prepare('DELETE FROM drive_folder_group_access WHERE folder_id = ? AND group_id = ?');
            $stmt->execute([$folderId, $groupId]);
            
            return ['success' => true];
        } catch (\Exception $e) {
            error_log("removeGroupAccess error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to remove group access'];
        }
    }
    
    /**
     * Get folders shared with a user (Shared with me)
     */
    public function getSharedWithMe(string $email): array
    {
        try {
            $email = strtolower($email);
            
            $stmt = $this->db->prepare('
                SELECT f.*, c.permission, c.invited_by, c.created_at as shared_at,
                       (SELECT COUNT(*) FROM drive_files WHERE folder_id = f.id AND (is_trashed = 0 OR is_trashed IS NULL)) as file_count,
                       (SELECT COUNT(*) FROM drive_folders WHERE parent_id = f.id AND (is_trashed = 0 OR is_trashed IS NULL)) as subfolder_count
                FROM drive_folder_collaborators c
                JOIN drive_folders f ON c.folder_id = f.id
                WHERE c.user_email = ? 
                AND (f.is_trashed = 0 OR f.is_trashed IS NULL)
                ORDER BY c.created_at DESC
            ');
            $stmt->execute([$email]);
            
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            // Table might not exist yet
            error_log("getSharedWithMe error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if user has collaborator access to a folder
     * @param string $email User email to check
     * @param int $folderId Folder ID
     * @param string|null $requiredPermission 'viewer', 'editor', or null (any access)
     * @return array|false Returns collaborator record if has access, false otherwise
     */
    public function hasCollaboratorAccess(string $email, int $folderId, ?string $requiredPermission = null)
    {
        try {
            $email = strtolower($email);
            
            $stmt = $this->db->prepare('
                SELECT c.*, f.user_email as owner_email
                FROM drive_folder_collaborators c
                JOIN drive_folders f ON c.folder_id = f.id
                WHERE c.user_email = ? AND c.folder_id = ?
            ');
            $stmt->execute([$email, $folderId]);
            $access = $stmt->fetch();
            
            if (!$access) {
                return false;
            }
            
            // If specific permission required, check it
            if ($requiredPermission === 'editor' && $access['permission'] !== 'editor') {
                return false;
            }
            
            return $access;
        } catch (\Exception $e) {
            // Table might not exist yet
            error_log("hasCollaboratorAccess error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check collaborator access for a file (through its parent folder)
     */
    public function hasFileCollaboratorAccess(string $email, int $fileId, ?string $requiredPermission = null)
    {
        $email = strtolower($email);
        
        // Get the file's folder
        $stmt = $this->db->prepare('SELECT folder_id FROM drive_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file || !$file['folder_id']) {
            return false; // Files at root cannot have collaborator access
        }
        
        // Check if user has access to this folder or any shared ancestor.
        return $this->getCollaboratorTreeAccess($email, (int)$file['folder_id'], $requiredPermission);
    }

    private function getCollaboratorTreeAccess(string $email, int $folderId, ?string $requiredPermission = null)
    {
        $email = strtolower($email);
        $currentFolderId = $folderId;
        $maxDepth = 100;

        while ($currentFolderId && $maxDepth > 0) {
            $access = $this->hasCollaboratorAccess($email, $currentFolderId, $requiredPermission);
            if ($access) {
                $access['shared_root_folder_id'] = $currentFolderId;
                return $access;
            }

            $stmt = $this->db->prepare('SELECT parent_id FROM drive_folders WHERE id = ?');
            $stmt->execute([$currentFolderId]);
            $parentId = $stmt->fetchColumn();
            $currentFolderId = $parentId !== false && $parentId !== null ? (int)$parentId : 0;
            $maxDepth--;
        }

        return false;
    }

    public function createFolderAsCollaborator(string $email, int $parentFolderId, string $name): ?array
    {
        $email = strtolower($email);
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $access = $this->getCollaboratorTreeAccess($email, $parentFolderId, 'editor');
        if (!$access) {
            return null;
        }

        $ownerEmail = strtolower($access['owner_email']);
        $parentFolder = $this->getFolder($ownerEmail, $parentFolderId);
        if (!$parentFolder || !empty($parentFolder['is_trashed'])) {
            return null;
        }

        $existing = $this->findFolderByName($ownerEmail, $name, $parentFolderId);
        if ($existing && empty($existing['is_trashed'])) {
            return $existing;
        }

        $folder = $this->createFolder($ownerEmail, $name, $parentFolderId);
        if (!$folder) {
            return null;
        }

        $stmt = $this->db->prepare('UPDATE drive_folders SET created_by = ? WHERE id = ?');
        $stmt->execute([$email, $folder['id']]);

        return $this->getFolder($ownerEmail, (int)$folder['id']);
    }
    
    /**
     * Get folder contents as a collaborator
     */
    public function getCollaboratorFolderContents(string $email, int $folderId): ?array
    {
        $email = strtolower($email);
        
        // Check collaborator access
        $access = $this->hasCollaboratorAccess($email, $folderId);
        if (!$access) {
            return null;
        }
        
        $ownerEmail = $access['owner_email'];
        
        // Get folder info
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE id = ?');
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch();
        
        if (!$folder) return null;
        
        // Get subfolders
        $stmt = $this->db->prepare('
            SELECT * FROM drive_folders 
            WHERE parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY name
        ');
        $stmt->execute([$folderId]);
        $folders = $stmt->fetchAll();
        
        // Get files
        $stmt = $this->db->prepare('
            SELECT * FROM drive_files 
            WHERE folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY original_name
        ');
        $stmt->execute([$folderId]);
        $files = $stmt->fetchAll();
        
        return [
            'folder' => $folder,
            'folders' => $folders,
            'files' => $files,
            'permission' => $access['permission'],
            'owner_email' => $ownerEmail
        ];
    }
    
    /**
     * Get subfolder contents within a shared folder (as collaborator)
     */
    public function getCollaboratorSubfolderContents(string $email, int $rootFolderId, int $subfolderId): ?array
    {
        $email = strtolower($email);
        
        // Check collaborator access to the root shared folder
        $access = $this->hasCollaboratorAccess($email, $rootFolderId);
        if (!$access) {
            return null;
        }
        
        // Verify the subfolder is within the shared folder tree
        if (!$this->isSubfolderOf($subfolderId, $rootFolderId)) {
            return null;
        }
        
        // Get subfolder info
        $stmt = $this->db->prepare('SELECT * FROM drive_folders WHERE id = ?');
        $stmt->execute([$subfolderId]);
        $folder = $stmt->fetch();
        
        if (!$folder) return null;
        
        // Get subfolders
        $stmt = $this->db->prepare('
            SELECT * FROM drive_folders 
            WHERE parent_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY name
        ');
        $stmt->execute([$subfolderId]);
        $folders = $stmt->fetchAll();
        
        // Get files
        $stmt = $this->db->prepare('
            SELECT * FROM drive_files 
            WHERE folder_id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
            ORDER BY original_name
        ');
        $stmt->execute([$subfolderId]);
        $files = $stmt->fetchAll();
        
        // Build path from root shared folder to current subfolder
        $path = $this->buildPathFromTo($rootFolderId, $subfolderId);
        
        return [
            'folder' => $folder,
            'folders' => $folders,
            'files' => $files,
            'path' => $path,
            'permission' => $access['permission'],
            'owner_email' => $access['owner_email']
        ];
    }
    
    /**
     * Check if a folder is a subfolder of another folder
     */
    private function isSubfolderOf(int $subfolderId, int $parentFolderId): bool
    {
        if ($subfolderId === $parentFolderId) return true;
        
        $stmt = $this->db->prepare('SELECT parent_id FROM drive_folders WHERE id = ?');
        $currentId = $subfolderId;
        $maxDepth = 50; // Prevent infinite loops
        
        while ($currentId && $maxDepth > 0) {
            $stmt->execute([$currentId]);
            $result = $stmt->fetch();
            
            if (!$result) return false;
            
            $parentId = $result['parent_id'] !== null ? (int)$result['parent_id'] : null;
            if ($parentId === $parentFolderId) return true;
            
            $currentId = $parentId;
            $maxDepth--;
        }
        
        return false;
    }
    
    /**
     * Build path array from one folder to another
     */
    private function buildPathFromTo(int $fromFolderId, int $toFolderId): array
    {
        if ($fromFolderId === $toFolderId) return [];
        
        $path = [];
        $stmt = $this->db->prepare('SELECT id, name, parent_id FROM drive_folders WHERE id = ?');
        $currentId = $toFolderId;
        $maxDepth = 50;
        
        while ($currentId && $currentId !== $fromFolderId && $maxDepth > 0) {
            $stmt->execute([$currentId]);
            $folder = $stmt->fetch();
            
            if (!$folder) break;
            
            array_unshift($path, ['id' => $folder['id'], 'name' => $folder['name']]);
            $currentId = $folder['parent_id'];
            $maxDepth--;
        }
        
        return $path;
    }
    
    /**
     * Get a file from a shared folder for download (as collaborator)
     * Returns file data with constructed storage_path
     */
    public function getSharedFileForDownload(string $email, int $folderId, int $fileId): ?array
    {
        $email = strtolower($email);
        
        // Check collaborator access to the root shared folder
        $access = $this->hasCollaboratorAccess($email, $folderId);
        if (!$access) {
            error_log("getSharedFileForDownload: No collaborator access for $email to folder $folderId");
            return null;
        }
        
        // Get the file first
        $stmt = $this->db->prepare('
            SELECT * FROM drive_files 
            WHERE id = ? AND (is_trashed = 0 OR is_trashed IS NULL)
        ');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file) {
            error_log("getSharedFileForDownload: File $fileId not found");
            return null;
        }
        
        // Check if the file is in the shared folder or any of its subfolders
        $fileFolderId = $file['folder_id'] !== null ? (int)$file['folder_id'] : null;
        
        error_log("getSharedFileForDownload: File folder_id=$fileFolderId, shared folder_id=$folderId");
        
        // If file has no folder (root level of owner's drive), it can't be in a shared folder
        if ($fileFolderId === null) {
            error_log("getSharedFileForDownload: File $fileId is in root (no folder_id), not in shared folder");
            return null;
        }
        
        // Check if file is directly in the shared folder or in a subfolder
        if ($fileFolderId !== $folderId && !$this->isSubfolderOf($fileFolderId, $folderId)) {
            error_log("getSharedFileForDownload: File $fileId (in folder $fileFolderId) is not in shared folder $folderId or its subfolders");
            return null;
        }
        
        // Construct the storage path using the file owner's email
        // Use resolveFilePath to check both local and NAS storage
        $ownerEmail = $file['user_email'];
        $storagePath = $this->resolveFilePath($ownerEmail, $file['filename'], $file['storage_location'] ?? null);
        
        if (!$storagePath) {
            error_log("getSharedFileForDownload: File not found in any storage location for file $fileId");
            return null;
        }
        
        $file['storage_path'] = $storagePath;
        
        error_log("getSharedFileForDownload: Access granted for file $fileId, path=$storagePath");
        
        return $file;
    }
    
    /**
     * Upload file to a shared folder (as collaborator with editor permission)
     */
    public function uploadFileAsCollaborator(string $email, int $folderId, array $uploadedFile): ?array
    {
        $email = strtolower($email);
        
        // Check editor access on the target folder or any shared ancestor.
        $access = $this->getCollaboratorTreeAccess($email, $folderId, 'editor');
        if (!$access) {
            return null;
        }
        
        $ownerEmail = $access['owner_email'];
        $targetFolder = $this->getFolder($ownerEmail, $folderId);
        if (!$targetFolder || !empty($targetFolder['is_trashed'])) {
            return null;
        }
        
        // Upload file to owner's storage
        if (!isset($uploadedFile['tmp_name']) || empty($uploadedFile['tmp_name'])) {
            return null;
        }
        
        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            return null;
        }
        
        $originalName = $uploadedFile['name'] ?? '';
        $nameParts = explode('.', strtolower($originalName));
        array_shift($nameParts);
        foreach ($nameParts as $ext) {
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                error_log("DriveService: Blocked collaborator upload of dangerous file type: {$originalName}");
                return null;
            }
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMimeType = $finfo->file($uploadedFile['tmp_name']);
        if ($detectedMimeType && in_array($detectedMimeType, self::BLOCKED_MIME_TYPES, true)) {
            error_log("DriveService: Blocked collaborator upload with dangerous MIME type: {$detectedMimeType} ({$originalName})");
            return null;
        }

        $size = filesize($uploadedFile['tmp_name']);
        
        // Check owner's quota
        if (!$this->hasQuota($ownerEmail, $size)) {
            return null;
        }
        // Phase 6b: system-wide admission for collaborator uploads
        // — gate uses the OWNER's budget (uploads land in the
        // shared folder, which the owner ultimately pays storage for).
        $this->maybeAdmitUpload($size);

        // Generate unique filename
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        
        $userPath = $this->getUserPath($ownerEmail);
        $targetPath = $userPath . '/' . $filename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            return null;
        }

        // Phase 1d: verify size on disk matches the uploaded size before
        // committing the DB row. NFS soft-mount partial-write guard.
        clearstatcache(true, $targetPath);
        $written = @filesize($targetPath);
        if ($written === false || $written !== $size) {
            @unlink($targetPath);
            error_log("DriveService collaborator upload: size mismatch. Expected={$size}, on-disk=" . var_export($written, true));
            return null;
        }

        $mimeType = $detectedMimeType ?: (mime_content_type($targetPath) ?? 'application/octet-stream');
        $storageLocation = $this->resolveStorageLocation();

        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_files (user_email, folder_id, filename, original_name, size, mime_type, created_by, last_modified_by, storage_location)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $stmt->execute([
                $ownerEmail,
                $folderId,
                $filename,
                $originalName,
                $size,
                $mimeType,
                $email, // Created by collaborator
                $email,
                $storageLocation
            ]);
            
            $fileId = (int) $this->db->lastInsertId();

            // Phase 1c: queue NAS migration if this collaborator upload
            // landed on local fallback (the owner is the storage payer).
            $this->enqueueNasMigrationIfNeeded($fileId, $ownerEmail, $targetPath);

            $this->updateUsedSpace($ownerEmail, $size);
            $this->updateFolderSizeWithParents($ownerEmail, $folderId);

            return $this->getFileById($fileId);

        } catch (\PDOException $e) {
            error_log("Collaborator upload failed: " . $e->getMessage());
            unlink($targetPath);
            return null;
        }
    }

    /**
     * Delete file as collaborator (with editor permission)
     */
    public function deleteFileAsCollaborator(string $email, int $fileId): bool
    {
        $email = strtolower($email);
        
        // Get file info
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if (!$file || !$file['folder_id']) {
            return false;
        }
        
        // Check editor access to the folder or any shared ancestor.
        $access = $this->getCollaboratorTreeAccess($email, (int)$file['folder_id'], 'editor');
        if (!$access) {
            return false;
        }

        $ownerEmail = strtolower($access['owner_email']);
        return $this->trashFile($ownerEmail, $fileId);
    }
    
    /**
     * Get a file by ID (internal use)
     */
    private function getFileById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drive_files WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get a file by ID with storage path
     * Returns file info including full storage path
     */
    public function getFileByIdWithPath(int $id): ?array
    {
        $file = $this->getFileById($id);
        if (!$file) {
            return null;
        }
        
        // Build full storage path using resolveFilePath to check both local and NAS
        $storagePath = $this->resolveFilePath($file['user_email'], $file['filename'], $file['storage_location'] ?? null);
        
        if (!$storagePath) {
            error_log("getFileByIdWithPath: File $id not found in any storage location");
            return null;
        }
        
        $file['storage_path'] = $storagePath;
        
        return $file;
    }
    
    // ========================
    // FILE EDITING STATUS
    // ========================
    
    /**
     * Set editing status for a file (user started/stopped editing)
     */
    public function setEditingStatus(string $email, string $filename, ?int $folderId = null, bool $isEditing = true): bool
    {
        $email = strtolower($email);
        
        // First, clean up expired sessions (older than 5 minutes)
        $this->clearExpiredEditingSessions();
        
        if ($isEditing) {
            // Try to find the file ID if we have folder info
            $fileId = null;
            if ($folderId !== null) {
                $stmt = $this->db->prepare('
                    SELECT id FROM drive_files 
                    WHERE original_name = ? AND folder_id = ? AND user_email = ? AND is_trashed = 0
                ');
                $stmt->execute([$filename, $folderId, $email]);
                $fileId = $stmt->fetchColumn() ?: null;
            } else {
                // Search in root folder
                $stmt = $this->db->prepare('
                    SELECT id FROM drive_files 
                    WHERE original_name = ? AND folder_id IS NULL AND user_email = ? AND is_trashed = 0
                ');
                $stmt->execute([$filename, $email]);
                $fileId = $stmt->fetchColumn() ?: null;
            }
            
            // Insert or update editing status
            $stmt = $this->db->prepare('
                INSERT INTO drive_editing_status (file_id, filename, folder_id, user_email, started_at, last_heartbeat)
                VALUES (?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE last_heartbeat = NOW()
            ');
            $stmt->execute([$fileId, $filename, $folderId, $email]);
            
            return true;
        } else {
            // Remove editing status
            if ($folderId !== null) {
                $stmt = $this->db->prepare('
                    DELETE FROM drive_editing_status 
                    WHERE filename = ? AND folder_id = ? AND user_email = ?
                ');
                $stmt->execute([$filename, $folderId, $email]);
            } else {
                $stmt = $this->db->prepare('
                    DELETE FROM drive_editing_status 
                    WHERE filename = ? AND folder_id IS NULL AND user_email = ?
                ');
                $stmt->execute([$filename, $email]);
            }
            
            return true;
        }
    }
    
    /**
     * Get editing status for files in a folder (who is editing what)
     */
    public function getEditingStatus(string $email, ?int $folderId = null): array
    {
        $email = strtolower($email);
        
        // Clean up expired sessions first
        $this->clearExpiredEditingSessions();
        
        // Get all editing sessions for files in the specified folder
        // Include ALL editors (including the current user) so the UI can show editing status
        if ($folderId !== null) {
            $stmt = $this->db->prepare('
                SELECT es.*, 
                       TIMESTAMPDIFF(SECOND, es.started_at, NOW()) as editing_duration,
                       (es.user_email = ?) as is_self
                FROM drive_editing_status es
                WHERE es.folder_id = ?
                  AND es.last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ');
            $stmt->execute([$email, $folderId]);
        } else {
            // Root folder
            $stmt = $this->db->prepare('
                SELECT es.*, 
                       TIMESTAMPDIFF(SECOND, es.started_at, NOW()) as editing_duration,
                       (es.user_email = ?) as is_self
                FROM drive_editing_status es
                WHERE es.folder_id IS NULL
                  AND es.last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ');
            $stmt->execute([$email]);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all active editors for files in shared folders the user has access to
     */
    public function getSharedFolderEditors(string $email): array
    {
        $email = strtolower($email);
        
        // Clean up expired sessions first
        $this->clearExpiredEditingSessions();
        
        // Get editing status for files in folders shared with or owned by this user
        $stmt = $this->db->prepare('
            SELECT 
                es.filename,
                es.folder_id,
                es.user_email as editor_email,
                es.started_at,
                es.last_heartbeat,
                f.name as folder_name,
                TIMESTAMPDIFF(SECOND, es.started_at, NOW()) as editing_duration
            FROM drive_editing_status es
            LEFT JOIN drive_folders f ON es.folder_id = f.id
            WHERE es.user_email != ?
              AND es.last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
              AND (
                  f.user_email = ?
                  OR EXISTS (
                      SELECT 1 FROM drive_folder_collaborators fc 
                      WHERE fc.folder_id = es.folder_id AND fc.user_email = ?
                  )
              )
            ORDER BY es.last_heartbeat DESC
        ');
        $stmt->execute([$email, $email, $email]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Clear expired editing sessions (older than 5 minutes without heartbeat)
     */
    public function clearExpiredEditingSessions(): int
    {
        $stmt = $this->db->prepare('
            DELETE FROM drive_editing_status 
            WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ');
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Heartbeat to keep editing session alive
     */
    public function heartbeatEditingStatus(string $email, string $filename, ?int $folderId = null): bool
    {
        $email = strtolower($email);
        
        if ($folderId !== null) {
            $stmt = $this->db->prepare('
                UPDATE drive_editing_status 
                SET last_heartbeat = NOW()
                WHERE filename = ? AND folder_id = ? AND user_email = ?
            ');
            $stmt->execute([$filename, $folderId, $email]);
        } else {
            $stmt = $this->db->prepare('
                UPDATE drive_editing_status 
                SET last_heartbeat = NOW()
                WHERE filename = ? AND folder_id IS NULL AND user_email = ?
            ');
            $stmt->execute([$filename, $email]);
        }
        
        return $stmt->rowCount() > 0;
    }
    
    // ============================================
    // NAS DIRECT ACCESS METHODS
    // ============================================
    
    /**
     * Get storage info (driver type, source, etc.)
     */
    public function getStorageInfo(): array
    {
        return [
            'driver' => $this->storage->getDriver(),
            'source' => $this->storage->isFromPanel() ? 'panel' : 'fallback',
            'storage_name' => $this->storage->getStorageName(),
            'base_path' => $this->storage->getBasePath(),
        ];
    }
    
    /**
     * Find a file by its checksum in a specific folder
     */
    public function findFileByChecksum(string $email, string $checksum, ?int $folderId = null): ?array
    {
        $email = strtolower($email);
        
        if ($folderId !== null) {
            $stmt = $this->db->prepare('
                SELECT * FROM drive_files 
                WHERE user_email = ? AND checksum = ? AND folder_id = ? AND is_trashed = 0
            ');
            $stmt->execute([$email, $checksum, $folderId]);
        } else {
            $stmt = $this->db->prepare('
                SELECT * FROM drive_files 
                WHERE user_email = ? AND checksum = ? AND folder_id IS NULL AND is_trashed = 0
            ');
            $stmt->execute([$email, $checksum]);
        }
        
        $file = $stmt->fetch();
        return $file ?: null;
    }
    
    /**
     * Update the NAS relative path for a file
     */
    public function updateFileNasPath(int $fileId, string $nasRelativePath): bool
    {
        $stmt = $this->db->prepare('SELECT user_email FROM drive_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $ownerEmail = $stmt->fetchColumn();
        $resolvedPath = $ownerEmail ? $this->resolveNasFilePath($nasRelativePath, (string)$ownerEmail) : null;

        $stmt = $this->db->prepare('
            UPDATE drive_files 
            SET nas_relative_path = ?, storage_location = ?, storage_path = COALESCE(?, storage_path), updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$nasRelativePath, 'nas', $resolvedPath, $fileId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Register a file that was uploaded directly to NAS (metadata only)
     */
    public function registerNasFile(
        string $email,
        string $originalName,
        ?int $folderId,
        int $size,
        string $checksum,
        string $mimeType,
        string $nasRelativePath
    ): ?array {
        $email = strtolower($email);
        
        // Generate a unique storage filename for DB bookkeeping, but keep the
        // actual NAS path in nas_relative_path/storage_path.
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedFilename = uniqid('nas_') . '_' . time() . ($ext ? '.' . $ext : '');
        $storagePath = $this->resolveNasFilePath($nasRelativePath, $email);
        
        $stmt = $this->db->prepare('
            INSERT INTO drive_files (
                user_email, folder_id, original_name, filename, storage_path,
                size, mime_type, checksum, nas_relative_path, storage_location,
                created_by, last_modified_by, current_version, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ');
        
        $stmt->execute([
            $email,
            $folderId,
            $originalName,
            $storedFilename,
            $storagePath,
            $size,
            $mimeType,
            $checksum,
            $nasRelativePath,
            'nas',
            $email,
            $email
        ]);
        
        $fileId = (int)$this->db->lastInsertId();
        
        if ($fileId) {
            // Update folder sizes
            if ($folderId) {
                $this->recalculateFolderSize($email, $folderId);
            }
            
            return $this->getFileById($fileId);
        }
        
        return null;
    }
    
    /**
     * Update file metadata (checksum, size, etc.)
     */
    public function updateFileMeta(int $fileId, array $updates): ?array
    {
        if (empty($updates)) {
            return $this->getFileById($fileId);
        }
        
        $allowedFields = ['checksum', 'size', 'updated_at', 'last_modified_by', 'nas_relative_path', 'storage_location'];
        $setClauses = [];
        $params = [];
        
        foreach ($updates as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $setClauses[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($setClauses)) {
            return $this->getFileById($fileId);
        }
        
        $params[] = $fileId;
        
        $sql = 'UPDATE drive_files SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->getFileById($fileId);
    }
    
    /**
     * Create a sync event record
     */
    public function createSyncEvent(
        string $email,
        string $action,
        string $itemType,
        ?int $itemId,
        string $itemName,
        string $status = 'success',
        ?string $details = null
    ): bool {
        $email = strtolower($email);
        
        // Check if table exists first
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'drive_sync_events'")->fetch();
            if (!$tableCheck) {
                // Table doesn't exist yet - that's OK, just skip
                return true;
            }
            
            $stmt = $this->db->prepare('
                INSERT INTO drive_sync_events (user_email, action, item_type, item_id, item_name, status, details, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$email, $action, $itemType, $itemId, $itemName, $status, $details]);
            
            return true;
        } catch (\Exception $e) {
            // Log but don't fail if sync events table has issues
            error_log("createSyncEvent error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fire automation hook when drive folder permissions change
     */
    private function _fireDriveFolderPermissionAutomation(int $folderId, string $folderName, string $changedByEmail, string $changeDetail): void
    {
        try {
            $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($this->config);
            $automationService->onDriveFolderPermissionChanged($folderId, $folderName, $changedByEmail, $changeDetail);
        } catch (\Throwable $e) {
            error_log("DriveService: Automation hook error (folder permission): " . $e->getMessage());
        }
    }
}
