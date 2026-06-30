<?php

namespace Webmail\Addons\Moodboards\Services;

use Webmail\Services\ImageThumbnailService;

class MoodBoardService
{
    private \PDO $db;
    private array $config;
    private string $logFile;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/mood-boards.log';
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTablesExist();
    }
    
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    public function log(string $message): void
    {
        // Size-capped: rotate once past 20 MB so the log can never grow unbounded
        if (@filesize($this->logFile) > 20 * 1024 * 1024) {
            @rename($this->logFile, $this->logFile . '.1');
        }
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
    
    /**
     * Resolve a /api/drive/files/{id}/download URL to a mood board serve URL.
     * This fixes the 401 issue: Drive download URLs require auth, but <img> tags
     * don't send the Bearer token. The mood board serve endpoint serves files without auth.
     */
    private function resolveDriveUrlToMoodBoardUrl(int $boardId, string $url): string
    {
        // Extract drive file ID from URL like /api/drive/files/1786/download
        if (preg_match('#/api/drive/files/(\d+)/(download|thumbnail)#', $url, $matches)) {
            $driveFileId = (int)$matches[1];
            
            // Look up the stored filename in mood_board_uploads
            try {
                $stmt = $this->db->prepare("
                    SELECT stored_filename FROM mood_board_uploads 
                    WHERE board_id = ? AND drive_file_id = ? 
                    LIMIT 1
                ");
                $stmt->execute([$boardId, $driveFileId]);
                $upload = $stmt->fetch();
                
                if ($upload && !empty($upload['stored_filename'])) {
                    return '/api/mood-boards/' . $boardId . '/uploads/' . $upload['stored_filename'];
                }
            } catch (\Exception $e) {
                // Silently fall back to original URL
            }
        }
        
        return $url;
    }
    
    // ========================================
    // TABLE SETUP
    // ========================================
    
    private function ensureTablesExist(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_boards (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    client_id INT UNSIGNED DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    background_color VARCHAR(20) DEFAULT '#f5f5f5',
                    background_image VARCHAR(500) DEFAULT NULL,
                    background_image_size VARCHAR(20) DEFAULT 'cover',
                    
                    canvas_width INT DEFAULT 4000,
                    canvas_height INT DEFAULT 3000,
                    zoom_level DECIMAL(4,2) DEFAULT 1.00,
                    viewport_x INT DEFAULT 0,
                    viewport_y INT DEFAULT 0,
                    is_template TINYINT(1) DEFAULT 0,
                    archived TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_owner (owner_email),
                    INDEX idx_client (client_id),
                    INDEX idx_archived (archived)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    parent_id INT DEFAULT NULL,
                    type VARCHAR(30) NOT NULL,
                    pos_x INT NOT NULL DEFAULT 0,
                    pos_y INT NOT NULL DEFAULT 0,
                    width INT DEFAULT 240,
                    height INT DEFAULT NULL,
                    rotation DECIMAL(5,2) DEFAULT 0,
                    z_index INT DEFAULT 0,
                    locked TINYINT(1) DEFAULT 0,
                    slide_order INT DEFAULT NULL,
                    transition_type VARCHAR(20) DEFAULT 'fly',
                    transition_duration DECIMAL(5,2) DEFAULT NULL,
                    presenter_notes TEXT DEFAULT NULL,
                    title VARCHAR(500) DEFAULT NULL,
                    content TEXT,
                    color VARCHAR(20) DEFAULT NULL,
                    url VARCHAR(2000) DEFAULT NULL,
                    drive_file_id INT DEFAULT NULL,
                    image_url VARCHAR(500) DEFAULT NULL,
                    thumbnail_url VARCHAR(500) DEFAULT NULL,
                    linked_board_id INT DEFAULT NULL,
                    linked_card_id INT DEFAULT NULL,
                    calendar_event_id INT DEFAULT NULL,
                    color_data JSON DEFAULT NULL,
                    style_data JSON DEFAULT NULL,
                    created_by VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_parent (parent_id),
                    INDEX idx_type (type),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Migrate existing tables: convert ENUM type to VARCHAR(30) for extensibility
            try {
                // Check if type column is still ENUM — only ALTER if needed
                $colInfo = $this->db->query("SHOW COLUMNS FROM mood_board_items WHERE Field = 'type'")->fetch();
                if ($colInfo && stripos($colInfo['Type'], 'enum') !== false) {
                    $this->db->exec("
                        ALTER TABLE mood_board_items 
                        MODIFY COLUMN type VARCHAR(30) NOT NULL
                    ");
                    $this->log("Auto-migration: converted mood_board_items.type from ENUM to VARCHAR(30)");
                }
            } catch (\PDOException $e) {
                $this->log("Auto-migration WARNING: Failed to convert type column to VARCHAR(30): " . $e->getMessage());
            }
            
            // Add missing columns (slide_order, transition_type, presenter_notes)
            $existingCols = [];
            $colTypes = [];
            $colCheck = $this->db->query("SHOW COLUMNS FROM mood_board_items");
            while ($row = $colCheck->fetch()) {
                $existingCols[] = $row['Field'];
                $colTypes[$row['Field']] = $row['Type'];
            }
            if (!in_array('slide_order', $existingCols)) {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN slide_order INT DEFAULT NULL AFTER locked");
            }
            if (!in_array('transition_type', $existingCols)) {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN transition_type VARCHAR(20) DEFAULT 'fly' AFTER slide_order");
            } elseif (isset($colTypes['transition_type']) && stripos($colTypes['transition_type'], 'enum') !== false) {
                try {
                    $this->db->exec("ALTER TABLE mood_board_items MODIFY COLUMN transition_type VARCHAR(20) DEFAULT 'fly'");
                    $this->log("Auto-migration: converted transition_type from ENUM to VARCHAR(20)");
                } catch (\PDOException $e) {
                    $this->log("Auto-migration WARNING: Failed to convert transition_type: " . $e->getMessage());
                }
            }
            if (!in_array('transition_duration', $existingCols)) {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN transition_duration DECIMAL(5,2) DEFAULT NULL AFTER transition_type");
            }
            if (!in_array('presenter_notes', $existingCols)) {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN presenter_notes TEXT DEFAULT NULL AFTER transition_duration");
            }
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_todos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_id INT NOT NULL,
                    text VARCHAR(500) NOT NULL,
                    completed TINYINT(1) DEFAULT 0,
                    position INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_item (item_id),
                    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_connections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    from_item_id INT NOT NULL,
                    to_item_id INT NOT NULL,
                    line_style ENUM('solid','dashed','dotted') DEFAULT 'solid',
                    line_color VARCHAR(20) DEFAULT '#666666',
                    line_width TINYINT UNSIGNED DEFAULT 2,
                    arrow_start TINYINT(1) DEFAULT 0,
                    arrow_end TINYINT(1) DEFAULT 1,
                    label VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_from (from_item_id),
                    INDEX idx_to (to_item_id),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE,
                    FOREIGN KEY (from_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (to_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_client_links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id INT UNSIGNED NOT NULL,
                    mood_board_id INT NOT NULL,
                    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_client_mood (client_id, mood_board_id),
                    INDEX idx_client (client_id),
                    INDEX idx_mood_board (mood_board_id),
                    FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_members (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    role ENUM('viewer','editor','admin') DEFAULT 'editor',
                    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_board_member (board_id, email),
                    INDEX idx_board (board_id),
                    INDEX idx_email (email),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_image_set_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_id INT NOT NULL,
                    image_url VARCHAR(500) NOT NULL,
                    thumbnail_url VARCHAR(500) DEFAULT NULL,
                    drive_file_id INT DEFAULT NULL,
                    original_filename VARCHAR(255) DEFAULT NULL,
                    file_size INT DEFAULT NULL,
                    width_px INT DEFAULT NULL,
                    height_px INT DEFAULT NULL,
                    position INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_item (item_id),
                    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Group access table (may reference colleague_groups which might not exist)
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS mood_board_group_access (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        board_id INT NOT NULL,
                        group_id INT UNSIGNED NOT NULL,
                        role ENUM('viewer','editor') DEFAULT 'editor',
                        granted_by VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_mood_board_group (board_id, group_id),
                        INDEX idx_group (group_id),
                        FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } catch (\PDOException $e) {
                // colleague_groups may not exist yet
            }
            
            // Board-to-board linking table
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS mood_board_board_links (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        mood_board_id INT NOT NULL,
                        kanban_board_id INT NOT NULL,
                        linked_by VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_mood_kanban (mood_board_id, kanban_board_id),
                        INDEX idx_mood (mood_board_id),
                        INDEX idx_kanban (kanban_board_id),
                        FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } catch (\PDOException $e) {
                // webmail_boards may not exist yet
            }
            
            // Add invited_by column to members if not present
            try {
                $this->db->exec("ALTER TABLE mood_board_members ADD COLUMN IF NOT EXISTS invited_by VARCHAR(255) DEFAULT NULL AFTER role");
            } catch (\PDOException $e) {
                // Column may already exist
            }
            
            // Ensure mood_board_items has all required columns and enum values
            // (handles cases where table was created before migrations 056/057 ran)
            try {
                $this->db->exec("ALTER TABLE mood_board_items MODIFY COLUMN type ENUM('note','image','text','link','todo_list','file','color_swatch','board_link','frame','image_set','calendar_event','drawing','table','column','folder','shape','pen_shape','video','youtube','line','artboard','audio','slide','group','repeat_grid') NOT NULL");
            } catch (\PDOException $e) {
                // Already up to date
            }
            try {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN IF NOT EXISTS color_data JSON DEFAULT NULL AFTER color");
            } catch (\PDOException $e) {
                // Column already exists
            }
            try {
                $this->db->exec("ALTER TABLE mood_board_items ADD COLUMN IF NOT EXISTS calendar_event_id INT DEFAULT NULL AFTER linked_card_id");
            } catch (\PDOException $e) {
                // Column already exists
            }
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS canvas_strokes LONGTEXT DEFAULT NULL AFTER viewport_y");
            } catch (\PDOException $e) {
                // Column already exists
            }
            try {
                $this->db->exec("ALTER TABLE mood_board_uploads ADD COLUMN IF NOT EXISTS drive_file_id INT DEFAULT NULL AFTER uploaded_by");
            } catch (\PDOException $e) {
                // Column already exists or table doesn't exist yet
            }
            try {
                $this->db->exec("ALTER TABLE mood_board_uploads ADD COLUMN IF NOT EXISTS thumbnail_filename VARCHAR(255) DEFAULT NULL AFTER file_size");
            } catch (\PDOException $e) {
                // Column already exists
            }
            // Upgrade content column from TEXT (64KB) to MEDIUMTEXT (16MB) for large drawings
            try {
                $this->db->exec("ALTER TABLE mood_board_items MODIFY COLUMN content MEDIUMTEXT");
            } catch (\PDOException $e) {
                // Already upgraded or table doesn't exist yet
            }
            // Add color palette and background effects columns
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS color_palette JSON DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS gradient_palette JSON DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS background_effect JSON DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS brush_presets JSON DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS brush_settings JSON DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS bg_audio JSON DEFAULT NULL");
            } catch (\PDOException $e) {
                // Columns already exist
            }
            
            // Add custom anchor positions for connection endpoints (0-1 relative to item bounding box, NULL = auto edge-snap)
            try {
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS from_anchor_x FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS from_anchor_y FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS to_anchor_x FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS to_anchor_y FLOAT DEFAULT NULL");
            } catch (\PDOException $e) {
                // Columns already exist
            }
            
            // Add gradient properties for connections (start/end color gradient along the path)
            try {
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS gradient_enabled TINYINT(1) DEFAULT 0");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS gradient_color_start VARCHAR(20) DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS gradient_color_end VARCHAR(20) DEFAULT NULL");
            } catch (\PDOException $e) {
                // Columns already exist
            }
            
            // Add bend points for connections (NULL = auto curve, set = user-defined cubic bezier control points)
            try {
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS bend_x FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS bend_y FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS bend2_x FLOAT DEFAULT NULL");
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS bend2_y FLOAT DEFAULT NULL");
            } catch (\PDOException $e) {
                // Columns already exist
            }
            
            // Per-connection toggle: render above items (1) or below items (0, default)
            try {
                $this->db->exec("ALTER TABLE mood_board_connections ADD COLUMN IF NOT EXISTS render_above TINYINT(1) DEFAULT 0");
            } catch (\PDOException $e) {
                // Column already exists
            }
            
            // Add background_image_size column (cover / contain / repeat)
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS background_image_size VARCHAR(20) DEFAULT 'cover' AFTER background_image");
            } catch (\PDOException $e) {
                // Column already exists
            }
            
            
            
            // Component blocks table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_components (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL DEFAULT 'Untitled Component',
                    description TEXT DEFAULT NULL,
                    thumbnail_url VARCHAR(500) DEFAULT NULL,
                    items_data JSON NOT NULL,
                    is_global TINYINT(1) DEFAULT 0,
                    category VARCHAR(100) DEFAULT 'custom',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_owner (owner_email),
                    INDEX idx_category (category),
                    INDEX idx_global (is_global)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // User palettes (shareable across boards)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_user_palettes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL COMMENT 'Owner email',
                    name VARCHAR(100) NOT NULL DEFAULT 'Untitled Palette',
                    colors JSON DEFAULT NULL COMMENT 'Array of hex color strings',
                    gradients JSON DEFAULT NULL COMMENT 'Array of gradient objects',
                    is_shared TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = visible to colleagues on same domain',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_email (email),
                    INDEX idx_shared (is_shared, email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_uploads (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    item_id INT DEFAULT NULL,
                    original_filename VARCHAR(255) NOT NULL,
                    stored_filename VARCHAR(255) NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    mime_type VARCHAR(100) DEFAULT NULL,
                    file_size INT DEFAULT 0,
                    thumbnail_filename VARCHAR(255) DEFAULT NULL COMMENT 'Generated thumbnail filename in /thumbs/ subdirectory',
                    width_px INT DEFAULT NULL,
                    height_px INT DEFAULT NULL,
                    uploaded_by VARCHAR(255),
                    drive_file_id INT DEFAULT NULL COMMENT 'Reference to drive_files if stored in Drive',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_item (item_id),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Folders table for organizing mood boards into nested groups
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_folders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    parent_id INT DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    color VARCHAR(20) DEFAULT NULL,
                    sort_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_owner (owner_email),
                    INDEX idx_parent (parent_id),
                    FOREIGN KEY (parent_id) REFERENCES mood_board_folders(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS folder_id INT DEFAULT NULL AFTER client_id");
                $this->db->exec("ALTER TABLE mood_boards ADD INDEX IF NOT EXISTS idx_folder (folder_id)");
            } catch (\PDOException $e) {
                // Column/index already exists
            }
            
            // Activity log table for tracking collaborative changes
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_activity (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    user_name VARCHAR(255) DEFAULT NULL,
                    action VARCHAR(50) NOT NULL,
                    item_id INT DEFAULT NULL,
                    item_type VARCHAR(50) DEFAULT NULL,
                    item_label VARCHAR(500) DEFAULT NULL,
                    target_item_id INT DEFAULT NULL,
                    target_label VARCHAR(500) DEFAULT NULL,
                    metadata JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_user (user_email),
                    INDEX idx_created (created_at),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Comments table for mood board feedback (internal + public)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    item_id INT DEFAULT NULL COMMENT 'NULL = board-level comment',
                    thread_id CHAR(36) NOT NULL,
                    parent_id INT DEFAULT NULL,
                    author_email VARCHAR(255) DEFAULT NULL,
                    author_name VARCHAR(255) NOT NULL,
                    author_avatar_color VARCHAR(7) DEFAULT NULL,
                    content TEXT NOT NULL,
                    pin_x DECIMAL(10,4) DEFAULT NULL,
                    pin_y DECIMAL(10,4) DEFAULT NULL,
                    is_public TINYINT(1) NOT NULL DEFAULT 0,
                    share_token VARCHAR(64) DEFAULT NULL,
                    resolved_at TIMESTAMP NULL DEFAULT NULL,
                    resolved_by VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at TIMESTAMP NULL DEFAULT NULL,
                    INDEX idx_mbc_board (board_id),
                    INDEX idx_mbc_item (item_id),
                    INDEX idx_mbc_thread (thread_id),
                    INDEX idx_mbc_parent (parent_id),
                    INDEX idx_mbc_deleted (deleted_at),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Add allow_comments + notify_on_comment to mood_boards
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS allow_comments TINYINT(1) NOT NULL DEFAULT 1");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS notify_on_comment TINYINT(1) NOT NULL DEFAULT 1");
            } catch (\PDOException $e) {
                $this->log("ensureTablesExist alter mood_boards: " . $e->getMessage());
            }

            // Measurements table + board-level settings
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mood_board_measurements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    x1 FLOAT NOT NULL,
                    y1 FLOAT NOT NULL,
                    x2 FLOAT NOT NULL,
                    y2 FLOAT NOT NULL,
                    distance INT NOT NULL DEFAULT 0,
                    width INT NOT NULL DEFAULT 0,
                    height INT NOT NULL DEFAULT 0,
                    angle FLOAT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_color VARCHAR(20) DEFAULT '#0ea5e9'");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_width DECIMAL(3,1) DEFAULT 1.5");
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN IF NOT EXISTS measure_visible TINYINT(1) DEFAULT 1");
            } catch (\PDOException $e) {
                // Columns already exist
            }

        } catch (\PDOException $e) {
            $this->log("ensureTablesExist error: " . $e->getMessage());
            error_log("MoodBoardService ensureTablesExist CRITICAL: " . $e->getMessage());
        }
    }
    
    // ========================================
    // BOARD SNAPSHOTS
    // ========================================

    /**
     * Create a point-in-time snapshot of all items + connections on a board.
     * Auto-prunes to keep max 30 snapshots per board.
     */
    public function createSnapshot(int $boardId, string $email, string $trigger, ?string $label = null): ?int
    {
        try {
            $itemStmt = $this->db->prepare("SELECT * FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL ORDER BY z_index ASC");
            $itemStmt->execute([$boardId]);
            $items = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);

            $connStmt = $this->db->prepare("SELECT * FROM mood_board_connections WHERE board_id = ? ORDER BY id ASC");
            $connStmt->execute([$boardId]);
            $connections = $connStmt->fetchAll(\PDO::FETCH_ASSOC);

            $itemIds = array_column($items, 'id');
            $todos = [];
            $imageSetItems = [];
            if (!empty($itemIds)) {
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $todoStmt = $this->db->prepare("SELECT * FROM mood_board_todos WHERE item_id IN ($placeholders) ORDER BY item_id, position ASC");
                $todoStmt->execute($itemIds);
                $todos = $todoStmt->fetchAll(\PDO::FETCH_ASSOC);

                $imgStmt = $this->db->prepare("SELECT * FROM mood_board_image_set_items WHERE item_id IN ($placeholders) ORDER BY item_id, position ASC");
                $imgStmt->execute($itemIds);
                $imageSetItems = $imgStmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_snapshots (board_id, user_email, trigger_type, label, items_json, connections_json, todos_json, image_set_json, item_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                strtolower($email),
                $trigger,
                $label,
                json_encode($items, JSON_UNESCAPED_UNICODE),
                json_encode($connections, JSON_UNESCAPED_UNICODE),
                json_encode($todos, JSON_UNESCAPED_UNICODE),
                json_encode($imageSetItems, JSON_UNESCAPED_UNICODE),
                count($items)
            ]);
            $snapshotId = (int)$this->db->lastInsertId();

            // Prune old snapshots (keep max 30 per board)
            $this->db->prepare("
                DELETE FROM mood_board_snapshots
                WHERE board_id = ? AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM mood_board_snapshots WHERE board_id = ? ORDER BY created_at DESC LIMIT 30
                    ) AS keep_rows
                )
            ")->execute([$boardId, $boardId]);

            $this->log("Snapshot #{$snapshotId} created for board #{$boardId} ({$trigger}, {$label}) — " . ($items[0]['id'] ?? 0) . "..{$snapshotId} items=" . count($items));
            return $snapshotId;
        } catch (\PDOException $e) {
            $this->log("createSnapshot error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List snapshots for a board (metadata only, no item data)
     */
    public function getSnapshots(string $email, int $boardId, int $limit = 30): array
    {
        try {
            if (!$this->hasAccess($email, $boardId)) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT id, board_id, user_email, trigger_type, label, item_count, created_at
                FROM mood_board_snapshots
                WHERE board_id = ?
                ORDER BY created_at DESC
                LIMIT " . (int)$limit . "
            ");
            $stmt->execute([$boardId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            $this->log("getSnapshots error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Restore a board from a snapshot. Replaces all items + connections in a transaction.
     */
    public function restoreSnapshot(string $email, int $boardId, int $snapshotId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return false;
            }

            $stmt = $this->db->prepare("SELECT * FROM mood_board_snapshots WHERE id = ? AND board_id = ?");
            $stmt->execute([$snapshotId, $boardId]);
            $snapshot = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$snapshot) return false;

            $items = json_decode($snapshot['items_json'], true) ?: [];
            $connections = json_decode($snapshot['connections_json'], true) ?: [];
            $todos = json_decode($snapshot['todos_json'] ?? 'null', true) ?: [];
            $imageSetItems = json_decode($snapshot['image_set_json'] ?? 'null', true) ?: [];

            if (empty($items)) {
                $this->log("restoreSnapshot: snapshot #{$snapshotId} has no items, aborting");
                return false;
            }

            $this->db->beginTransaction();

            $this->createSnapshot($boardId, $email, 'pre_restore', 'Before restore from snapshot #' . $snapshotId);

            // Delete all current items -- cascades to connections, todos, image_set_items via FK
            $this->db->prepare("DELETE FROM mood_board_items WHERE board_id = ?")->execute([$boardId]);

            // Re-insert items WITH original IDs preserved
            foreach ($items as $item) {
                $cols = array_keys($item);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colNames = implode(',', $cols);
                $values = array_map(fn($c) => $item[$c], $cols);
                $this->db->prepare("INSERT INTO mood_board_items ($colNames) VALUES ($placeholders)")->execute($values);
            }

            // Validate item count before proceeding
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM mood_board_items WHERE board_id = ?");
            $countStmt->execute([$boardId]);
            $actualCount = (int)$countStmt->fetchColumn();
            if ($actualCount !== count($items)) {
                $this->db->rollBack();
                $this->log("restoreSnapshot: count mismatch after item insert (expected " . count($items) . ", got {$actualCount}), rolled back");
                return false;
            }

            // Re-insert connections WITH original IDs
            foreach ($connections as $conn) {
                $cols = array_keys($conn);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colNames = implode(',', $cols);
                $values = array_map(fn($c) => $conn[$c], $cols);
                $this->db->prepare("INSERT INTO mood_board_connections ($colNames) VALUES ($placeholders)")->execute($values);
            }

            // Re-insert todos WITH original IDs (from snapshots that include them)
            foreach ($todos as $todo) {
                $cols = array_keys($todo);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colNames = implode(',', $cols);
                $values = array_map(fn($c) => $todo[$c], $cols);
                $this->db->prepare("INSERT INTO mood_board_todos ($colNames) VALUES ($placeholders)")->execute($values);
            }

            // Re-insert image_set_items WITH original IDs
            foreach ($imageSetItems as $img) {
                $cols = array_keys($img);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colNames = implode(',', $cols);
                $values = array_map(fn($c) => $img[$c], $cols);
                $this->db->prepare("INSERT INTO mood_board_image_set_items ($colNames) VALUES ($placeholders)")->execute($values);
            }

            $this->db->commit();

            $this->logActivity($boardId, $email, 'snapshot_restored', null, null, "Restored from snapshot #{$snapshotId}");
            $this->log("Board #{$boardId} restored from snapshot #{$snapshotId} by {$email} — " . count($items) . " items, " . count($connections) . " connections, " . count($todos) . " todos, " . count($imageSetItems) . " image_set_items");
            return true;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("restoreSnapshot error: " . $e->getMessage());
            return false;
        }
    }

    // ========================================
    // ACTIVITY LOG
    // ========================================
    
    /**
     * Log a board activity entry
     */
    public function logActivity(
        int $boardId,
        string $userEmail,
        string $action,
        ?int $itemId = null,
        ?string $itemType = null,
        ?string $itemLabel = null,
        ?int $targetItemId = null,
        ?string $targetLabel = null,
        ?array $metadata = null
    ): bool {
        try {
            // Resolve display name from email (use part before @)
            $userName = explode('@', $userEmail)[0];
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_activity 
                (board_id, user_email, user_name, action, item_id, item_type, item_label, target_item_id, target_label, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                strtolower($userEmail),
                $userName,
                $action,
                $itemId,
                $itemType,
                $itemLabel ? mb_substr($itemLabel, 0, 500) : null,
                $targetItemId,
                $targetLabel ? mb_substr($targetLabel, 0, 500) : null,
                $metadata ? json_encode($metadata) : null
            ]);
            return true;
        } catch (\PDOException $e) {
            $this->log("logActivity error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get activity log for a board (most recent first)
     */
    public function getActivity(string $email, int $boardId, int $limit = 100, int $offset = 0): array
    {
        try {
            if (!$this->hasAccess($email, $boardId)) {
                return [];
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_activity 
                WHERE board_id = ?
                ORDER BY created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ");
            $stmt->execute([$boardId]);
            $activities = $stmt->fetchAll();
            
            // Decode metadata JSON
            foreach ($activities as &$a) {
                if ($a['metadata']) {
                    $a['metadata'] = json_decode($a['metadata'], true);
                }
            }
            unset($a);
            
            return $activities;
        } catch (\PDOException $e) {
            $this->log("getActivity error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Helper: derive a human label for an item from its data
     */
    public function getItemLabel(array $data): string
    {
        $type = $data['type'] ?? 'item';
        
        // Use title if available
        if (!empty($data['title'])) {
            return mb_substr($data['title'], 0, 60);
        }
        
        // Use content snippet
        if (!empty($data['content'])) {
            $text = strip_tags($data['content']);
            return mb_substr(trim($text), 0, 60) ?: $type;
        }
        
        // Fallback to type name
        $typeNames = [
            'text' => 'Text',
            'note' => 'Note',
            'image' => 'Image',
            'shape' => 'Shape',
            'pen_shape' => 'Drawing',
            'drawing' => 'Drawing',
            'frame' => 'Frame',
            'link' => 'Link',
            'todo_list' => 'Todo List',
            'file' => 'File',
            'color_swatch' => 'Color Swatch',
            'board_link' => 'Board Link',
            'image_set' => 'Image Set',
            'calendar_event' => 'Calendar Event',
            'table' => 'Table',
            'column' => 'Column',
            'folder' => 'Folder',
            'video' => 'Video',
            'youtube' => 'YouTube',
            'line' => 'Line',
            'artboard' => 'Artboard',
        ];
        
        return $typeNames[$type] ?? ucfirst($type);
    }
    
    // ========================================
    // BOARD CRUD
    // ========================================
    
    /**
     * Get all mood boards for a user (owned + shared directly + shared via groups)
     */
    public function getBoards(string $email, bool $includeArchived = false): array
    {
        try {
            $email = strtolower($email);
            $archiveClause = $includeArchived ? '' : 'AND mb.archived = 0';
            
            $sql = "
                SELECT mb.*, 
                       (SELECT COUNT(*) FROM mood_board_items WHERE board_id = mb.id AND deleted_at IS NULL) as item_count,
                       'owner' as user_role
                FROM mood_boards mb
                WHERE mb.owner_email = ? {$archiveClause}
                
                UNION
                
                SELECT mb.*,
                       (SELECT COUNT(*) FROM mood_board_items WHERE board_id = mb.id AND deleted_at IS NULL) as item_count,
                       mbm.role as user_role
                FROM mood_boards mb
                INNER JOIN mood_board_members mbm ON mbm.board_id = mb.id
                WHERE mbm.email = ? {$archiveClause}
            ";
            
            $params = [$email, $email];
            
            // Also include boards shared via groups (if tables exist)
            try {
                $this->db->query("SELECT 1 FROM mood_board_group_access LIMIT 0");
                $sql .= "
                    UNION
                    
                    SELECT mb.*,
                           (SELECT COUNT(*) FROM mood_board_items WHERE board_id = mb.id AND deleted_at IS NULL) as item_count,
                           mga.role as user_role
                    FROM mood_boards mb
                    INNER JOIN mood_board_group_access mga ON mga.board_id = mb.id
                    INNER JOIN colleague_group_members cgm ON cgm.group_id = mga.group_id
                    INNER JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
                    WHERE mb.owner_email != ? {$archiveClause}
                ";
                $params[] = $email;
                $params[] = $email;
            } catch (\PDOException $e) {
                // Table doesn't exist yet, skip
            }
            
            $sql .= " ORDER BY updated_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $boards = $stmt->fetchAll();
            
            // Deduplicate (a board could appear from multiple sources)
            $seen = [];
            $boards = array_filter($boards, function($b) use (&$seen) {
                if (isset($seen[$b['id']])) return false;
                $seen[$b['id']] = true;
                return true;
            });
            $boards = array_values($boards);
            
            // Get client info for linked boards
            foreach ($boards as &$board) {
                if ($board['client_id']) {
                    $clientStmt = $this->db->prepare("SELECT id, domain, display_name FROM clients WHERE id = ?");
                    $clientStmt->execute([$board['client_id']]);
                    $board['client'] = $clientStmt->fetch() ?: null;
                }
            }
            
            return $boards;
        } catch (\PDOException $e) {
            $this->log("getBoards error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get a single mood board with all items and connections
     */
    public function getBoard(string $email, int $boardId): ?array
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId)) {
                return null;
            }
            
            $stmt = $this->db->prepare("SELECT * FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();
            
            if (!$board) return null;
            
            // Get items (excluding soft-deleted)
            $itemStmt = $this->db->prepare("
                SELECT * FROM mood_board_items 
                WHERE board_id = ? AND deleted_at IS NULL
                ORDER BY z_index ASC, created_at ASC
            ");
            $itemStmt->execute([$boardId]);
            $items = $itemStmt->fetchAll();
            
            // Get todos for todo_list items
            $todoItemIds = array_column(array_filter($items, fn($i) => $i['type'] === 'todo_list'), 'id');
            if (!empty($todoItemIds)) {
                $placeholders = implode(',', array_fill(0, count($todoItemIds), '?'));
                $todoStmt = $this->db->prepare("
                    SELECT * FROM mood_board_todos 
                    WHERE item_id IN ({$placeholders}) 
                    ORDER BY position ASC
                ");
                $todoStmt->execute($todoItemIds);
                $allTodos = $todoStmt->fetchAll();
                
                $todosByItem = [];
                foreach ($allTodos as $todo) {
                    $todosByItem[$todo['item_id']][] = $todo;
                }
                
                foreach ($items as &$item) {
                    if ($item['type'] === 'todo_list') {
                        $item['todos'] = $todosByItem[$item['id']] ?? [];
                    }
                }
            }
            
            // Get image_set images
            $imageSetItemIds = array_column(array_filter($items, fn($i) => $i['type'] === 'image_set'), 'id');
            if (!empty($imageSetItemIds)) {
                $placeholders = implode(',', array_fill(0, count($imageSetItemIds), '?'));
                $imgStmt = $this->db->prepare("
                    SELECT * FROM mood_board_image_set_items 
                    WHERE item_id IN ({$placeholders}) 
                    ORDER BY position ASC
                ");
                $imgStmt->execute($imageSetItemIds);
                $allImages = $imgStmt->fetchAll();
                
                $imagesByItem = [];
                foreach ($allImages as $img) {
                    $imagesByItem[$img['item_id']][] = $img;
                }
                
                foreach ($items as &$item) {
                    if ($item['type'] === 'image_set') {
                        $item['images'] = $imagesByItem[$item['id']] ?? [];
                    }
                }
            }
            
            // Build thumbnail lookup for this board
            $thumbLookup = [];
            try {
                $thumbStmt = $this->db->prepare("
                    SELECT stored_filename, thumbnail_filename 
                    FROM mood_board_uploads 
                    WHERE board_id = ? AND thumbnail_filename IS NOT NULL AND thumbnail_filename != '__original__'
                ");
                $thumbStmt->execute([$boardId]);
                while ($row = $thumbStmt->fetch()) {
                    $thumbLookup[$row['stored_filename']] = $row['thumbnail_filename'];
                }
            } catch (\Exception $e) {
                // thumbnail_filename column may not exist yet — ignore
            }
            
            // Parse JSON fields, cast booleans + fix Drive URLs + add thumbnail_url for items
            foreach ($items as &$item) {
                // Cast tinyint fields to proper booleans for JS
                $item['locked'] = (bool)(int)($item['locked'] ?? 0);
                if ($item['style_data']) {
                    $item['style_data'] = json_decode($item['style_data'], true);
                }
                if (isset($item['color_data']) && $item['color_data']) {
                    $item['color_data'] = json_decode($item['color_data'], true);
                }
                // Fix Drive-based image_url: rewrite /api/drive/files/{id}/download
                // to the mood-board serve endpoint which doesn't require auth
                if (!empty($item['image_url']) && str_contains($item['image_url'], '/api/drive/files/')) {
                    $item['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $item['image_url']);
                }
                // Resolve thumbnail_url from upload records
                if (!empty($item['image_url']) && str_contains($item['image_url'], '/uploads/')) {
                    $storedFile = basename($item['image_url']);
                    if (isset($thumbLookup[$storedFile])) {
                        $item['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbLookup[$storedFile];
                    }
                }
                // Also fix image_set images
                if (isset($item['images']) && is_array($item['images'])) {
                    foreach ($item['images'] as &$img) {
                        if (!empty($img['image_url']) && str_contains($img['image_url'], '/api/drive/files/')) {
                            $img['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $img['image_url']);
                        }
                        if (!empty($img['image_url']) && str_contains($img['image_url'], '/uploads/')) {
                            $storedFile = basename($img['image_url']);
                            if (isset($thumbLookup[$storedFile])) {
                                $img['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbLookup[$storedFile];
                            }
                        }
                    }
                }
            }
            
            $board['items'] = $items;
            
            // Get connections (only where both endpoint items still exist and are not soft-deleted)
            $connStmt = $this->db->prepare("
                SELECT c.* FROM mood_board_connections c
                INNER JOIN mood_board_items fi ON fi.id = c.from_item_id AND fi.deleted_at IS NULL
                INNER JOIN mood_board_items ti ON ti.id = c.to_item_id AND ti.deleted_at IS NULL
                WHERE c.board_id = ?
            ");
            $connStmt->execute([$boardId]);
            $board['connections'] = $connStmt->fetchAll();

            // Get measurements
            $board['measurements'] = $this->getMeasurements($boardId);

            // Get members (with colleague display info)
            $board['members'] = $this->getMembers($boardId);
            
            // Get group access
            try {
                $board['groups'] = $this->getGroupAccess($boardId);
            } catch (\Exception $e) {
                $board['groups'] = [];
            }
            
            // Get linked kanban boards
            try {
                $board['linked_boards'] = $this->getLinkedBoards($boardId);
            } catch (\Exception $e) {
                $board['linked_boards'] = [];
            }
            
            // Get client info
            if ($board['client_id']) {
                $clientStmt = $this->db->prepare("SELECT id, domain, display_name FROM clients WHERE id = ?");
                $clientStmt->execute([$board['client_id']]);
                $board['client'] = $clientStmt->fetch() ?: null;
            }
            
            return $board;
        } catch (\PDOException $e) {
            $this->log("getBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get board data with all assets base64-encoded for offline HTML export.
     * Collects every image_url, mask_image_url, background_image, bg_audio file,
     * reads each from disk, and returns them in an asset map keyed by URL.
     */
    public function getBoardForExport(string $email, int $boardId): ?array
    {
        $board = $this->getBoard($email, $boardId);
        if (!$board) return null;

        $assetMap = [];
        $filePathMap = [];
        $baseDir = realpath(__DIR__ . '/../../../../') . '/';

        // Collect all unique asset URLs that need embedding
        $urls = [];
        $videoUrls = [];

        // Board-level assets
        if (!empty($board['background_image'])) {
            $urls[] = $board['background_image'];
        }

        // Background audio (file type only)
        $bgAudio = $board['bg_audio'] ?? null;
        if ($bgAudio) {
            if (is_string($bgAudio)) {
                try { $bgAudio = json_decode($bgAudio, true); } catch (\Exception $e) { $bgAudio = null; }
            }
            if ($bgAudio && ($bgAudio['type'] ?? '') === 'file' && !empty($bgAudio['url'])) {
                $urls[] = $bgAudio['url'];
            }
        }

        // Item-level assets
        foreach ($board['items'] ?? [] as $item) {
            if (!empty($item['image_url'])) $urls[] = $item['image_url'];
            if (!empty($item['thumbnail_url'])) $urls[] = $item['thumbnail_url'];

            $sd = $item['style_data'] ?? [];
            if (is_string($sd)) {
                try { $sd = json_decode($sd, true) ?? []; } catch (\Exception $e) { $sd = []; }
            }
            if (!empty($sd['mask_image_url'])) $urls[] = $sd['mask_image_url'];
            if (!empty($sd['poster'])) $urls[] = $sd['poster'];

            // Image set items
            foreach ($item['images'] ?? [] as $img) {
                if (!empty($img['image_url'])) $urls[] = $img['image_url'];
                if (!empty($img['thumbnail_url'])) $urls[] = $img['thumbnail_url'];
            }

            // Audio/video/file items
            if (!empty($item['url'])) {
                $type = $item['type'] ?? '';
                if (in_array($type, ['audio', 'video', 'file'])) {
                    $urls[] = $item['url'];
                    if ($type === 'video') {
                        $videoUrls[$item['url']] = true;
                    }
                }
            }
        }

        $urls = array_unique(array_filter($urls));

        foreach ($urls as $url) {
            if (str_starts_with($url, 'data:')) continue;
            $filePath = $this->resolveUrlToFilePath($boardId, $url, $baseDir, $email);
            if (!$filePath || !file_exists($filePath)) {
                $this->log("PPTX asset miss: board={$boardId} url=" . substr($url, 0, 120));
                continue;
            }

            if (isset($videoUrls[$url])) {
                $filePathMap[$url] = $filePath;
                continue;
            }

            $mimeType = @mime_content_type($filePath) ?: 'application/octet-stream';
            $content = @file_get_contents($filePath);
            if ($content === false) continue;

            $assetMap[$url] = 'data:' . $mimeType . ';base64,' . base64_encode($content);
        }

        return [
            'board' => $board,
            'assets' => $assetMap,
            'filePaths' => $filePathMap,
        ];
    }

    /**
     * Resolve a mood board asset URL to an absolute file path on disk.
     */
    private function resolveUrlToFilePath(int $boardId, string $url, string $baseDir, string $email = ''): ?string
    {
        // Local upload: /api/mood-boards/{id}/uploads/thumbs/{filename}
        if (preg_match('#/api/mood-boards/(\d+)/uploads/thumbs/(.+)$#', $url, $m)) {
            $urlBoardId = (int)$m[1];
            $path = $baseDir . 'storage/mood-uploads/' . $urlBoardId . '/thumbs/' . basename($m[2]);
            if (file_exists($path)) return $path;
            $path = $baseDir . 'storage/mood-uploads/' . $boardId . '/thumbs/' . basename($m[2]);
            if (file_exists($path)) return $path;
        }

        // Local upload: /api/mood-boards/{id}/uploads/{filename}
        if (preg_match('#/api/mood-boards/(\d+)/uploads/(.+)$#', $url, $m)) {
            $urlBoardId = (int)$m[1];
            $filename = basename($m[2]);

            $path = $baseDir . 'storage/mood-uploads/' . $urlBoardId . '/' . $filename;
            if (file_exists($path)) return $path;
            if ($urlBoardId !== $boardId) {
                $path = $baseDir . 'storage/mood-uploads/' . $boardId . '/' . $filename;
                if (file_exists($path)) return $path;
            }

            $stmt = $this->db->prepare(
                "SELECT board_id, stored_filename, file_path, drive_file_id, uploaded_by FROM mood_board_uploads WHERE board_id IN (?, ?) AND stored_filename = ? LIMIT 1"
            );
            $stmt->execute([$boardId, $urlBoardId, $filename]);
            $row = $stmt->fetch();

            if ($row) {
                $localPath = $baseDir . 'storage/mood-uploads/' . ($row['board_id'] ?? $boardId) . '/' . $row['stored_filename'];
                if (file_exists($localPath)) return $localPath;

                if (!empty($row['drive_file_id'])) {
                    $resolveEmail = $row['uploaded_by'] ?? $email;
                    if ($resolveEmail) {
                        $resolved = $this->resolveDriveFilePath($resolveEmail, (int)$row['drive_file_id']);
                        if ($resolved) return $resolved;
                    }
                }
            }
        }

        // Drive URL: /api/drive/files/{id}/download or /thumbnail
        if (preg_match('#/api/drive/files/(\d+)/(download|thumbnail)#', $url, $m)) {
            $driveFileId = (int)$m[1];

            $stmt = $this->db->prepare(
                "SELECT board_id, stored_filename, file_path, drive_file_id, uploaded_by FROM mood_board_uploads WHERE drive_file_id = ? ORDER BY board_id = ? DESC LIMIT 1"
            );
            $stmt->execute([$driveFileId, $boardId]);
            $row = $stmt->fetch();

            if ($row) {
                $rowBoardId = $row['board_id'] ?? $boardId;
                if (!empty($row['stored_filename'])) {
                    $path = $baseDir . 'storage/mood-uploads/' . $rowBoardId . '/' . $row['stored_filename'];
                    if (file_exists($path)) return $path;
                }
                if (!empty($row['file_path']) && !str_starts_with($row['file_path'], 'drive://')) {
                    $path = $baseDir . $row['file_path'];
                    if (file_exists($path)) return $path;
                }
                $resolveEmail = $row['uploaded_by'] ?? $email;
                if ($resolveEmail) {
                    $resolved = $this->resolveDriveFilePath($resolveEmail, $driveFileId);
                    if ($resolved) return $resolved;
                }
            }

            if ($email) {
                $resolved = $this->resolveDriveFilePath($email, $driveFileId);
                if ($resolved) return $resolved;
            }
        }

        // Relative path (storage/mood-uploads/...)
        if (str_starts_with($url, 'storage/')) {
            $path = $baseDir . $url;
            if (file_exists($path)) return $path;
        }

        return null;
    }

    private function resolveDriveFilePath(string $email, int $driveFileId): ?string
    {
        try {
            $driveService = new \Webmail\Services\DriveService($this->config, $email);
            $filePath = $driveService->getFilePath($email, $driveFileId);
            if ($filePath && file_exists($filePath)) {
                return $filePath;
            }
        } catch (\Throwable $e) {
            // DriveService unavailable or file not found
        }
        return null;
    }

    /**
     * Create a new mood board
     */
    public function createBoard(string $email, array $data): ?array
    {
        try {
            $email = strtolower($email);
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_boards (owner_email, name, description, background_color, background_image, background_image_size, client_id, folder_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email,
                $data['name'],
                $data['description'] ?? null,
                $data['background_color'] ?? '#f5f5f5',
                $data['background_image'] ?? null,
                $data['background_image_size'] ?? 'cover',
                $data['client_id'] ?? null,
                $data['folder_id'] ?? null
            ]);
            
            $boardId = (int)$this->db->lastInsertId();
            
            // If client_id provided, also add to client links
            if (!empty($data['client_id'])) {
                $linkStmt = $this->db->prepare("
                    INSERT IGNORE INTO mood_board_client_links (client_id, mood_board_id) VALUES (?, ?)
                ");
                $linkStmt->execute([$data['client_id'], $boardId]);
            }
            
            $this->log("Board created: #{$boardId} '{$data['name']}' by {$email}");
            
            return $this->getBoard($email, $boardId);
        } catch (\PDOException $e) {
            $this->log("createBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a mood board
     */
    public function updateBoard(string $email, int $boardId, array $data): ?array
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return null;
            }
            
            $fields = [];
            $values = [];
            
            $allowedFields = [
                'name', 'description', 'background_color', 'background_image',
                'background_image_size',
                'canvas_width', 'canvas_height', 'zoom_level', 'viewport_x',
                'viewport_y', 'canvas_strokes', 'archived', 'client_id', 'folder_id',
                'motion_settings', 'color_palette', 'gradient_palette', 'background_effect', 'guides',
                'brush_presets', 'brush_settings', 'bg_audio', 'conn_panel_position',
                'allow_comments', 'notify_on_comment'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                return $this->getBoard($email, $boardId);
            }
            
            $values[] = $boardId;
            $sql = "UPDATE mood_boards SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            $this->log("Board updated: #{$boardId} by {$email}");
            
            return $this->getBoard($email, $boardId);
        } catch (\PDOException $e) {
            $this->log("updateBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a mood board
     */
    public function deleteBoard(string $email, int $boardId): bool
    {
        try {
            $email = strtolower($email);
            
            // Only owner can delete
            $stmt = $this->db->prepare("DELETE FROM mood_boards WHERE id = ? AND owner_email = ?");
            $stmt->execute([$boardId, $email]);
            
            if ($stmt->rowCount() > 0) {
                $this->log("Board deleted: #{$boardId} by {$email}");
                return true;
            }
            return false;
        } catch (\PDOException $e) {
            $this->log("deleteBoard error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Toggle the "ready" state on a mood board
     */
    public function toggleReady(string $email, int $boardId): ?array
    {
        try {
            $email = strtolower($email);

            // Ensure columns exist (safe for repeated calls)
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN is_ready TINYINT(1) DEFAULT 0 AFTER archived");
            } catch (\PDOException $e) { /* column already exists */ }
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN ready_at DATETIME DEFAULT NULL AFTER is_ready");
            } catch (\PDOException $e) { /* column already exists */ }
            try {
                $this->db->exec("ALTER TABLE mood_boards ADD COLUMN marked_ready_by VARCHAR(255) DEFAULT NULL AFTER ready_at");
            } catch (\PDOException $e) { /* column already exists */ }

            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return null;
            }

            // Get current state
            $stmt = $this->db->prepare("SELECT is_ready FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$current) return null;

            $newState = empty($current['is_ready']) ? 1 : 0;

            if ($newState) {
                $stmt = $this->db->prepare("UPDATE mood_boards SET is_ready = 1, ready_at = NOW(), marked_ready_by = ? WHERE id = ?");
                $stmt->execute([$email, $boardId]);
            } else {
                $stmt = $this->db->prepare("UPDATE mood_boards SET is_ready = 0, ready_at = NULL, marked_ready_by = NULL WHERE id = ?");
                $stmt->execute([$boardId]);
            }

            $this->logActivity($boardId, $email, $newState ? 'marked_ready' : 'unmarked_ready');

            // Fire CRM automation hook only when marking as ready (not unmarking)
            if ($newState) {
                $this->fireAutomationMoodBoardReady($boardId, $email);
            }

            return $this->getBoard($email, $boardId);
        } catch (\PDOException $e) {
            $this->log("toggleReady error: " . $e->getMessage());
            return null;
        }
    }

    // ========================================
    // ACCESS CONTROL
    // ========================================
    
    /**
     * Check if user has access to a mood board (owner, direct member, or group member)
     */
    /**
     * Get all email addresses that have access to a board (owner + members)
     */
    public function getBoardMemberEmails(int $boardId): array
    {
        try {
            $emails = [];
            
            // Get owner
            $stmt = $this->db->prepare("SELECT owner_email FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();
            if ($board) {
                $emails[] = strtolower($board['owner_email']);
            }
            
            // Get direct members
            $stmt = $this->db->prepare("SELECT email FROM mood_board_members WHERE board_id = ?");
            $stmt->execute([$boardId]);
            while ($row = $stmt->fetch()) {
                $email = strtolower($row['email']);
                if (!in_array($email, $emails)) {
                    $emails[] = $email;
                }
            }
            
            // Get group-based members
            try {
                $stmt = $this->db->prepare("
                    SELECT DISTINCT c.email 
                    FROM mood_board_group_access mbga
                    INNER JOIN colleague_group_members cgm ON cgm.group_id = mbga.group_id
                    INNER JOIN colleagues c ON c.id = cgm.colleague_id
                    WHERE mbga.board_id = ?
                ");
                $stmt->execute([$boardId]);
                while ($row = $stmt->fetch()) {
                    $email = strtolower($row['email']);
                    if (!in_array($email, $emails)) {
                        $emails[] = $email;
                    }
                }
            } catch (\PDOException $e) {
                // Group tables may not exist yet
            }
            
            return $emails;
        } catch (\PDOException $e) {
            $this->log("getBoardMemberEmails error: " . $e->getMessage());
            return [];
        }
    }

    public function hasAccess(string $email, int $boardId, string $minRole = 'viewer'): bool
    {
        try {
            $email = strtolower($email);
            
            // Owner always has full access
            $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
            $stmt->execute([$boardId, $email]);
            if ($stmt->fetch()) return true;
            
            $roleHierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
            $minRoleLevel = $roleHierarchy[$minRole] ?? 1;
            
            // Check direct membership
            $stmt = $this->db->prepare("SELECT role FROM mood_board_members WHERE board_id = ? AND email = ?");
            $stmt->execute([$boardId, $email]);
            $member = $stmt->fetch();
            
            if ($member) {
                $memberLevel = $roleHierarchy[$member['role']] ?? 1;
                if ($memberLevel >= $minRoleLevel) return true;
            }
            
            // Check group-based access
            try {
                $stmt = $this->db->prepare("
                    SELECT mga.role FROM mood_board_group_access mga
                    INNER JOIN colleague_group_members cgm ON cgm.group_id = mga.group_id
                    INNER JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
                    WHERE mga.board_id = ?
                    ORDER BY FIELD(mga.role, 'editor', 'viewer') ASC
                    LIMIT 1
                ");
                $stmt->execute([$email, $boardId]);
                $groupAccess = $stmt->fetch();
                
                if ($groupAccess) {
                    $groupLevel = $roleHierarchy[$groupAccess['role']] ?? 1;
                    return $groupLevel >= $minRoleLevel;
                }
            } catch (\PDOException $e) {
                // mood_board_group_access table may not exist yet
            }
            
            return false;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    // ========================================
    // ITEM CRUD
    // ========================================
    
    /**
     * Add an item to the canvas
     */
    public function addItem(string $email, int $boardId, array $data): ?array
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return null;
            }
            
            // Scoped max z_index: only among siblings with the same parent_id + lane
            $parentId = $data['parent_id'] ?? null;
            $isSlideAtRoot = ($parentId === null && ($data['type'] ?? '') === 'slide');
            if ($isSlideAtRoot) {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND type = 'slide'");
                $zStmt->execute([$boardId]);
            } elseif ($parentId === null) {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND type <> 'slide'");
                $zStmt->execute([$boardId]);
            } else {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id = ?");
                $zStmt->execute([$boardId, $parentId]);
            }
            $nextZ = (int)$zStmt->fetchColumn();
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_items 
                (board_id, parent_id, type, pos_x, pos_y, width, height, rotation, z_index,
                 slide_order, transition_type, transition_duration, presenter_notes,
                 title, content, color, color_data, url, drive_file_id, image_url, thumbnail_url,
                 linked_board_id, linked_card_id, calendar_event_id, style_data, created_by,
                 component_id, component_instance_id, component_item_index)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                $data['parent_id'] ?? null,
                $data['type'],
                $data['pos_x'] ?? 0,
                $data['pos_y'] ?? 0,
                $data['width'] ?? 240,
                $data['height'] ?? null,
                $data['rotation'] ?? 0,
                $data['z_index'] ?? $nextZ,
                $data['slide_order'] ?? null,
                $data['transition_type'] ?? 'fly',
                $data['transition_duration'] ?? null,
                $data['presenter_notes'] ?? null,
                $data['title'] ?? null,
                $data['content'] ?? null,
                $data['color'] ?? null,
                isset($data['color_data']) ? json_encode($data['color_data']) : null,
                $data['url'] ?? null,
                $data['drive_file_id'] ?? null,
                $data['image_url'] ?? null,
                $data['thumbnail_url'] ?? null,
                $data['linked_board_id'] ?? null,
                $data['linked_card_id'] ?? null,
                $data['calendar_event_id'] ?? null,
                isset($data['style_data']) ? json_encode($data['style_data']) : null,
                $email,
                $data['component_id'] ?? null,
                $data['component_instance_id'] ?? null,
                $data['component_item_index'] ?? null,
            ]);
            
            $itemId = (int)$this->db->lastInsertId();
            
            // If todo_list, add initial todos
            if ($data['type'] === 'todo_list' && !empty($data['todos'])) {
                foreach ($data['todos'] as $i => $todo) {
                    $todoStmt = $this->db->prepare("
                        INSERT INTO mood_board_todos (item_id, text, completed, position)
                        VALUES (?, ?, ?, ?)
                    ");
                    $todoStmt->execute([
                        $itemId,
                        $todo['text'],
                        $todo['completed'] ?? 0,
                        $i
                    ]);
                }
            }
            
            // If image_set, add initial images
            if ($data['type'] === 'image_set' && !empty($data['image_set_items'])) {
                foreach ($data['image_set_items'] as $i => $img) {
                    $imgStmt = $this->db->prepare("
                        INSERT INTO mood_board_image_set_items 
                        (item_id, image_url, thumbnail_url, drive_file_id, original_filename, file_size, width_px, height_px, position)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $imgStmt->execute([
                        $itemId,
                        $img['image_url'],
                        $img['thumbnail_url'] ?? null,
                        $img['drive_file_id'] ?? null,
                        $img['original_filename'] ?? null,
                        $img['file_size'] ?? null,
                        $img['width_px'] ?? null,
                        $img['height_px'] ?? null,
                        $img['position'] ?? $i
                    ]);
                }
            }
            
            $this->log("Item added: #{$itemId} type={$data['type']} to board #{$boardId} by {$email}");
            
            return $this->getItem($itemId);
        } catch (\PDOException $e) {
            $this->log("addItem error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get a single item by ID
     */
    public function getItem(int $itemId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM mood_board_items WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) return null;
            
            // Cast tinyint fields to proper booleans for JS
            $item['locked'] = (bool)(int)($item['locked'] ?? 0);
            
            if ($item['style_data']) {
                $item['style_data'] = json_decode($item['style_data'], true);
            }
            if (isset($item['color_data']) && $item['color_data']) {
                $item['color_data'] = json_decode($item['color_data'], true);
            }
            
            if ($item['type'] === 'todo_list') {
                $todoStmt = $this->db->prepare("
                    SELECT * FROM mood_board_todos WHERE item_id = ? ORDER BY position ASC
                ");
                $todoStmt->execute([$itemId]);
                $item['todos'] = $todoStmt->fetchAll();
            }
            
            if ($item['type'] === 'image_set') {
                $imgStmt = $this->db->prepare("
                    SELECT * FROM mood_board_image_set_items WHERE item_id = ? ORDER BY position ASC
                ");
                $imgStmt->execute([$itemId]);
                $item['images'] = $imgStmt->fetchAll();
                
                // Fix Drive-based URLs for image_set images (same migration as getBoard)
                $boardId = (int)$item['board_id'];
                foreach ($item['images'] as &$img) {
                    if (!empty($img['image_url']) && str_contains($img['image_url'], '/api/drive/files/')) {
                        $img['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $img['image_url']);
                    }
                    if (!empty($img['thumbnail_url']) && str_contains($img['thumbnail_url'], '/api/drive/files/')) {
                        $img['thumbnail_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $img['thumbnail_url']);
                    }
                }
                unset($img);
            }
            
            // Fix Drive-based image_url on the item itself
            if (!empty($item['image_url']) && str_contains($item['image_url'], '/api/drive/files/')) {
                $boardId = (int)$item['board_id'];
                $item['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $item['image_url']);
            }
            
            return $item;
        } catch (\PDOException $e) {
            $this->log("getItem error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update an item (position, content, style, etc.)
     */
    public function updateItem(string $email, int $boardId, int $itemId, array $data): ?array
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return null;
            }
            
            // Verify item belongs to board
            $checkStmt = $this->db->prepare("SELECT id FROM mood_board_items WHERE id = ? AND board_id = ? AND deleted_at IS NULL");
            $checkStmt->execute([$itemId, $boardId]);
            if (!$checkStmt->fetch()) return null;
            
            $fields = [];
            $values = [];
            
            $allowedFields = [
                'parent_id', 'pos_x', 'pos_y', 'width', 'height', 'rotation',
                'z_index', 'locked', 'title', 'content', 'color', 'url',
                'drive_file_id', 'image_url', 'thumbnail_url',
                'linked_board_id', 'linked_card_id', 'calendar_event_id',
                'slide_order', 'transition_type', 'transition_duration', 'presenter_notes',
            ];
            
            // Only include component columns if they exist in the DB
            $hasComponentCols = false;
            try {
                $this->db->query("SELECT component_id FROM mood_board_items LIMIT 0");
                $hasComponentCols = true;
            } catch (\PDOException $e) {
                // columns not yet migrated
            }
            if ($hasComponentCols) {
                $allowedFields[] = 'component_id';
                $allowedFields[] = 'component_instance_id';
                $allowedFields[] = 'component_item_index';
            }
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }
            
            // Handle JSON fields
            if (array_key_exists('style_data', $data)) {
                $fields[] = "style_data = ?";
                $values[] = $data['style_data'] ? json_encode($data['style_data']) : null;
            }
            if (array_key_exists('color_data', $data)) {
                $fields[] = "color_data = ?";
                $values[] = $data['color_data'] ? json_encode($data['color_data']) : null;
            }
            
            if (empty($fields)) {
                return $this->getItem($itemId);
            }
            
            $values[] = $itemId;
            $values[] = $boardId;
            $sql = "UPDATE mood_board_items SET " . implode(', ', $fields) . " WHERE id = ? AND board_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            return $this->getItem($itemId);
        } catch (\PDOException $e) {
            $this->log("updateItem error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Batch update items (for multi-drag / position updates)
     */
    public function batchUpdateItems(string $email, int $boardId, array $updates): bool
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return false;
            }

            // Check once whether component linking columns exist
            $hasComponentCols = false;
            try {
                $this->db->query("SELECT component_id FROM mood_board_items LIMIT 0");
                $hasComponentCols = true;
            } catch (\PDOException $e) {
                // columns not yet migrated
            }
            
            $this->db->beginTransaction();
            
            foreach ($updates as $update) {
                $fields = [];
                $values = [];
                
                $positionalFields = ['pos_x', 'pos_y', 'width', 'height', 'z_index', 'rotation', 'parent_id', 'locked', 'title', 'content', 'color', 'url'];
                foreach ($positionalFields as $field) {
                    if (array_key_exists($field, $update)) {
                        $fields[] = "{$field} = ?";
                        $values[] = $update[$field];
                    }
                }

                if ($hasComponentCols) {
                    $componentFields = ['component_id', 'component_instance_id', 'component_item_index'];
                    foreach ($componentFields as $field) {
                        if (array_key_exists($field, $update)) {
                            $fields[] = "{$field} = ?";
                            $values[] = $update[$field];
                        }
                    }
                }

                // JSON fields (style_data, color_data)
                if (array_key_exists('style_data', $update)) {
                    $fields[] = "style_data = ?";
                    $values[] = $update['style_data'] ? json_encode($update['style_data']) : null;
                }
                if (array_key_exists('color_data', $update)) {
                    $fields[] = "color_data = ?";
                    $values[] = $update['color_data'] ? json_encode($update['color_data']) : null;
                }
                
                if (empty($fields)) continue;
                
                $values[] = $update['id'];
                $values[] = $boardId;
                $sql = "UPDATE mood_board_items SET " . implode(', ', $fields) . " WHERE id = ? AND board_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($values);
            }
            
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            $this->log("batchUpdateItems error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Soft-delete an item from the canvas (sets deleted_at instead of removing row)
     */
    public function deleteItem(string $email, int $boardId, int $itemId): bool
    {
        try {
            $email = strtolower($email);

            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return false;
            }

            $stmt = $this->db->prepare("UPDATE mood_board_items SET deleted_at = NOW() WHERE id = ? AND board_id = ? AND deleted_at IS NULL");
            $stmt->execute([$itemId, $boardId]);

            if ($stmt->rowCount() > 0) {
                $this->log("Item soft-deleted: #{$itemId} from board #{$boardId} by {$email}");
                return true;
            }
            return false;
        } catch (\PDOException $e) {
            $this->log("deleteItem error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore a soft-deleted item
     */
    public function restoreItem(string $email, int $boardId, int $itemId): bool
    {
        try {
            $email = strtolower($email);

            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return false;
            }

            $stmt = $this->db->prepare("UPDATE mood_board_items SET deleted_at = NULL WHERE id = ? AND board_id = ? AND deleted_at IS NOT NULL");
            $stmt->execute([$itemId, $boardId]);

            if ($stmt->rowCount() > 0) {
                $this->log("Item restored: #{$itemId} on board #{$boardId} by {$email}");
                return true;
            }
            return false;
        } catch (\PDOException $e) {
            $this->log("restoreItem error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore all soft-deleted items for a board (bulk undo)
     */
    public function restoreAllItems(string $email, int $boardId): int
    {
        try {
            $email = strtolower($email);

            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return 0;
            }

            $stmt = $this->db->prepare("UPDATE mood_board_items SET deleted_at = NULL WHERE board_id = ? AND deleted_at IS NOT NULL");
            $stmt->execute([$boardId]);
            $count = $stmt->rowCount();

            if ($count > 0) {
                $this->log("Restored {$count} items on board #{$boardId} by {$email}");
            }
            return $count;
        } catch (\PDOException $e) {
            $this->log("restoreAllItems error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Restore multiple soft-deleted items by ID
     */
    public function restoreItems(string $email, int $boardId, array $itemIds): int
    {
        try {
            $email = strtolower($email);

            if (!$this->hasAccess($email, $boardId, 'editor') || empty($itemIds)) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $params = array_merge($itemIds, [$boardId]);

            $stmt = $this->db->prepare("UPDATE mood_board_items SET deleted_at = NULL WHERE id IN ($placeholders) AND board_id = ? AND deleted_at IS NOT NULL");
            $stmt->execute($params);
            $count = $stmt->rowCount();

            if ($count > 0) {
                $this->log("Restored {$count} items on board #{$boardId} by {$email}");
            }
            return $count;
        } catch (\PDOException $e) {
            $this->log("restoreItems error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get recently soft-deleted items for a board (trash view)
     */
    public function getDeletedItems(string $email, int $boardId, int $limit = 50): array
    {
        try {
            if (!$this->hasAccess($email, $boardId)) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_items
                WHERE board_id = ? AND deleted_at IS NOT NULL
                ORDER BY deleted_at DESC
                LIMIT " . (int)$limit . "
            ");
            $stmt->execute([$boardId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            $this->log("getDeletedItems error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Permanently remove items that were soft-deleted more than N days ago
     */
    public function purgeDeletedItems(int $boardId, int $olderThanDays = 30): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM mood_board_items
                WHERE board_id = ? AND deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$boardId, $olderThanDays]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->log("purgeDeletedItems error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Batch soft-delete multiple items from the canvas in one transaction.
     * Connections are preserved (they reference soft-deleted items but won't
     * show because the items are filtered out by deleted_at IS NULL).
     */
    public function batchDeleteItems(string $email, int $boardId, array $itemIds): int
    {
        try {
            $email = strtolower($email);

            if (!$this->hasAccess($email, $boardId, 'editor') || empty($itemIds)) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $params = array_merge($itemIds, [$boardId]);

            $stmt = $this->db->prepare("UPDATE mood_board_items SET deleted_at = NOW() WHERE id IN ($placeholders) AND board_id = ? AND deleted_at IS NULL");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();

            $this->log("Batch soft-deleted {$deleted} items from board #{$boardId} by {$email}");
            return $deleted;
        } catch (\PDOException $e) {
            $this->log("batchDeleteItems error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Batch add multiple items to the canvas in one transaction
     */
    public function batchAddItems(string $email, int $boardId, array $itemsData): array
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'editor') || empty($itemsData)) {
                return [];
            }
            
            // Scoped max z_index: scope by parent_id + lane of the first item in the batch
            $batchParentId = $itemsData[0]['parent_id'] ?? null;
            $batchType = $itemsData[0]['type'] ?? '';
            $batchIsSlideAtRoot = ($batchParentId === null && $batchType === 'slide');
            if ($batchIsSlideAtRoot) {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND type = 'slide'");
                $zStmt->execute([$boardId]);
            } elseif ($batchParentId === null) {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id IS NULL AND type <> 'slide'");
                $zStmt->execute([$boardId]);
            } else {
                $zStmt = $this->db->prepare("SELECT COALESCE(MAX(z_index), 0) + 1 FROM mood_board_items WHERE board_id = ? AND deleted_at IS NULL AND parent_id = ?");
                $zStmt->execute([$boardId, $batchParentId]);
            }
            $nextZ = (int)$zStmt->fetchColumn();
            
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_items 
                (board_id, parent_id, type, pos_x, pos_y, width, height, rotation, z_index,
                 slide_order, transition_type, transition_duration, presenter_notes,
                 title, content, color, color_data, url, drive_file_id, image_url, thumbnail_url,
                 linked_board_id, linked_card_id, calendar_event_id, style_data, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Component linking columns (may not exist if migration hasn't run yet)
            $hasComponentCols = false;
            try {
                $this->db->query("SELECT component_id FROM mood_board_items LIMIT 0");
                $hasComponentCols = true;
            } catch (\Exception $e) {}

            $componentLinkStmt = $hasComponentCols
                ? $this->db->prepare("UPDATE mood_board_items SET component_id = ?, component_instance_id = ?, component_item_index = ? WHERE id = ?")
                : null;
            
            $newIds = [];
            foreach ($itemsData as $i => $data) {
                $z = $data['z_index'] ?? ($nextZ + $i);
                $styleData = isset($data['style_data'])
                    ? (is_string($data['style_data']) ? $data['style_data'] : json_encode($data['style_data']))
                    : null;
                $colorData = isset($data['color_data'])
                    ? (is_string($data['color_data']) ? $data['color_data'] : json_encode($data['color_data']))
                    : null;
                    
                $stmt->execute([
                    $boardId,
                    $data['parent_id'] ?? null,
                    $data['type'] ?? 'text',
                    $data['pos_x'] ?? 0,
                    $data['pos_y'] ?? 0,
                    $data['width'] ?? null,
                    $data['height'] ?? null,
                    $data['rotation'] ?? 0,
                    $z,
                    $data['slide_order'] ?? null,
                    $data['transition_type'] ?? null,
                    $data['transition_duration'] ?? null,
                    $data['presenter_notes'] ?? null,
                    $data['title'] ?? null,
                    $data['content'] ?? null,
                    $data['color'] ?? null,
                    $colorData,
                    $data['url'] ?? null,
                    $data['drive_file_id'] ?? null,
                    $data['image_url'] ?? null,
                    $data['thumbnail_url'] ?? null,
                    $data['linked_board_id'] ?? null,
                    $data['linked_card_id'] ?? null,
                    $data['calendar_event_id'] ?? null,
                    $styleData,
                    $email,
                ]);

                $newId = (int)$this->db->lastInsertId();
                $newIds[] = $newId;

                if ($componentLinkStmt && !empty($data['component_id'])) {
                    $componentLinkStmt->execute([
                        $data['component_id'],
                        $data['component_instance_id'] ?? null,
                        $data['component_item_index'] ?? null,
                        $newId,
                    ]);
                }
            }
            
            $this->db->commit();

            // Fetch all new items in ONE query instead of N individual SELECTs
            $newItems = [];
            if (!empty($newIds)) {
                $placeholders = implode(',', array_fill(0, count($newIds), '?'));
                $fetchStmt = $this->db->prepare("SELECT * FROM mood_board_items WHERE id IN ($placeholders) ORDER BY id ASC");
                $fetchStmt->execute($newIds);
                $rows = $fetchStmt->fetchAll();
                foreach ($rows as $row) {
                    if ($row['style_data']) $row['style_data'] = json_decode($row['style_data'], true);
                    if ($row['color_data']) $row['color_data'] = json_decode($row['color_data'], true);
                    $newItems[] = $row;
                }
            }
            $this->log("Batch added " . count($newItems) . " items to board #{$boardId} by {$email}");
            return $newItems;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("batchAddItems error: " . $e->getMessage());
            throw new \RuntimeException('DB error in batchAddItems: ' . $e->getMessage(), 0, $e);
        }
    }
    
    // ========================================
    // TODO ITEMS (within todo_list items)
    // ========================================
    
    /**
     * Add a todo to a todo_list item
     */
    public function addTodo(string $email, int $boardId, int $itemId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            // Verify item is a todo_list
            $checkStmt = $this->db->prepare("SELECT id FROM mood_board_items WHERE id = ? AND board_id = ? AND type = 'todo_list' AND deleted_at IS NULL");
            $checkStmt->execute([$itemId, $boardId]);
            if (!$checkStmt->fetch()) return null;
            
            // Get max position
            $posStmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM mood_board_todos WHERE item_id = ?");
            $posStmt->execute([$itemId]);
            $position = (int)$posStmt->fetchColumn();
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_todos (item_id, text, completed, position)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $itemId,
                $data['text'],
                $data['completed'] ?? 0,
                $data['position'] ?? $position
            ]);
            
            $todoId = (int)$this->db->lastInsertId();
            
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_todos WHERE id = ?");
            $retStmt->execute([$todoId]);
            return $retStmt->fetch();
        } catch (\PDOException $e) {
            $this->log("addTodo error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a todo item
     */
    public function updateTodo(string $email, int $boardId, int $todoId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            $fields = [];
            $values = [];
            
            if (array_key_exists('text', $data)) { $fields[] = 'text = ?'; $values[] = $data['text']; }
            if (array_key_exists('completed', $data)) { $fields[] = 'completed = ?'; $values[] = (int)$data['completed']; }
            if (array_key_exists('position', $data)) { $fields[] = 'position = ?'; $values[] = (int)$data['position']; }
            
            if (empty($fields)) return null;
            
            $values[] = $todoId;
            $values[] = $boardId;
            $sql = "UPDATE mood_board_todos mt
                    JOIN mood_board_items mi ON mi.id = mt.item_id
                    SET " . implode(', ', array_map(fn($f) => 'mt.' . $f, $fields)) . "
                    WHERE mt.id = ? AND mi.board_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            $retStmt = $this->db->prepare("
                SELECT mt.* FROM mood_board_todos mt
                JOIN mood_board_items mi ON mi.id = mt.item_id
                WHERE mt.id = ? AND mi.board_id = ?
            ");
            $retStmt->execute([$todoId, $boardId]);
            $row = $retStmt->fetch();
            return $row ?: null;
        } catch (\PDOException $e) {
            $this->log("updateTodo error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a todo item
     */
    public function deleteTodo(string $email, int $boardId, int $todoId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("
                DELETE mt FROM mood_board_todos mt
                JOIN mood_board_items mi ON mi.id = mt.item_id
                WHERE mt.id = ? AND mi.board_id = ?
            ");
            $stmt->execute([$todoId, $boardId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("deleteTodo error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // CONNECTIONS (arrows between items)
    // ========================================
    
    /**
     * Create a connection between two items
     */
    public function addConnection(string $email, int $boardId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_connections 
                (board_id, from_item_id, to_item_id, line_style, line_color, line_width,
                 arrow_start, arrow_end, label,
                 from_anchor_x, from_anchor_y, to_anchor_x, to_anchor_y,
                 bend_x, bend_y, bend2_x, bend2_y,
                 glow_enabled, glow_color, glow_opacity, glow_blur,
                 gradient_enabled, gradient_color_start, gradient_color_end,
                 render_above)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                $data['from_item_id'],
                $data['to_item_id'],
                $data['line_style'] ?? 'solid',
                $data['line_color'] ?? '#666666',
                $data['line_width'] ?? 2,
                $data['arrow_start'] ?? 0,
                $data['arrow_end'] ?? 1,
                $data['label'] ?? null,
                $data['from_anchor_x'] ?? null,
                $data['from_anchor_y'] ?? null,
                $data['to_anchor_x'] ?? null,
                $data['to_anchor_y'] ?? null,
                $data['bend_x'] ?? null,
                $data['bend_y'] ?? null,
                $data['bend2_x'] ?? null,
                $data['bend2_y'] ?? null,
                $data['glow_enabled'] ?? 0,
                $data['glow_color'] ?? null,
                $data['glow_opacity'] ?? 60,
                $data['glow_blur'] ?? 6,
                $data['gradient_enabled'] ?? 0,
                $data['gradient_color_start'] ?? null,
                $data['gradient_color_end'] ?? null,
                $data['render_above'] ?? 0
            ]);
            
            $connId = (int)$this->db->lastInsertId();
            
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_connections WHERE id = ?");
            $retStmt->execute([$connId]);
            return $retStmt->fetch();
        } catch (\PDOException $e) {
            $this->log("addConnection error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Batch-create multiple connections in a single transaction.
     */
    public function batchAddConnections(string $email, int $boardId, array $connectionsData): array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return [];

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_connections
                (board_id, from_item_id, to_item_id, line_style, line_color, line_width,
                 arrow_start, arrow_end, label,
                 from_anchor_x, from_anchor_y, to_anchor_x, to_anchor_y,
                 bend_x, bend_y, bend2_x, bend2_y,
                 glow_enabled, glow_color, glow_opacity, glow_blur,
                 gradient_enabled, gradient_color_start, gradient_color_end,
                 render_above)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ids = [];
            $this->db->beginTransaction();
            foreach ($connectionsData as $data) {
                $stmt->execute([
                    $boardId,
                    $data['from_item_id'],
                    $data['to_item_id'],
                    $data['line_style'] ?? 'solid',
                    $data['line_color'] ?? '#666666',
                    $data['line_width'] ?? 2,
                    $data['arrow_start'] ?? 0,
                    $data['arrow_end'] ?? 1,
                    $data['label'] ?? null,
                    $data['from_anchor_x'] ?? null,
                    $data['from_anchor_y'] ?? null,
                    $data['to_anchor_x'] ?? null,
                    $data['to_anchor_y'] ?? null,
                    $data['bend_x'] ?? null,
                    $data['bend_y'] ?? null,
                    $data['bend2_x'] ?? null,
                    $data['bend2_y'] ?? null,
                    $data['glow_enabled'] ?? 0,
                    $data['glow_color'] ?? null,
                    $data['glow_opacity'] ?? 60,
                    $data['glow_blur'] ?? 6,
                    $data['gradient_enabled'] ?? 0,
                    $data['gradient_color_start'] ?? null,
                    $data['gradient_color_end'] ?? null,
                    $data['render_above'] ?? 0,
                ]);
                $ids[] = (int)$this->db->lastInsertId();
            }
            $this->db->commit();

            if (empty($ids)) return [];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_connections WHERE id IN ($placeholders)");
            $retStmt->execute($ids);
            return $retStmt->fetchAll();
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("batchAddConnections error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update a connection
     */
    public function updateConnection(string $email, int $boardId, int $connId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            $fields = [];
            $values = [];
            
            $allowedFields = ['line_style', 'line_color', 'line_width', 'arrow_start', 'arrow_end', 'label', 'from_anchor_x', 'from_anchor_y', 'to_anchor_x', 'to_anchor_y', 'glow_enabled', 'glow_color', 'glow_opacity', 'glow_blur', 'gradient_enabled', 'gradient_color_start', 'gradient_color_end', 'bend_x', 'bend_y', 'bend2_x', 'bend2_y', 'render_above'];
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) return null;
            
            $values[] = $connId;
            $values[] = $boardId;
            $sql = "UPDATE mood_board_connections SET " . implode(', ', $fields) . " WHERE id = ? AND board_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_connections WHERE id = ?");
            $retStmt->execute([$connId]);
            return $retStmt->fetch() ?: null;
        } catch (\PDOException $e) {
            $this->log("updateConnection error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a connection
     */
    public function deleteConnection(string $email, int $boardId, int $connId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("DELETE FROM mood_board_connections WHERE id = ? AND board_id = ?");
            $stmt->execute([$connId, $boardId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("deleteConnection error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge orphan connections whose from_item_id or to_item_id
     * references a soft-deleted or non-existent item.
     */
    public function purgeOrphanConnections(string $email, int $boardId): int
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return 0;

            $stmt = $this->db->prepare("
                DELETE c FROM mood_board_connections c
                LEFT JOIN mood_board_items fi ON fi.id = c.from_item_id AND fi.deleted_at IS NULL
                LEFT JOIN mood_board_items ti ON ti.id = c.to_item_id AND ti.deleted_at IS NULL
                WHERE c.board_id = ? AND (fi.id IS NULL OR ti.id IS NULL)
            ");
            $stmt->execute([$boardId]);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->log("purgeOrphanConnections error: " . $e->getMessage());
            return 0;
        }
    }
    
    // ========================================
    // MEASUREMENTS
    // ========================================

    public function getMeasurements(int $boardId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM mood_board_measurements WHERE board_id = ? ORDER BY id ASC");
            $stmt->execute([$boardId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            $this->log("getMeasurements error: " . $e->getMessage());
            return [];
        }
    }

    public function addMeasurement(string $email, int $boardId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_measurements (board_id, x1, y1, x2, y2, distance, width, height, angle)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                $data['x1'], $data['y1'],
                $data['x2'], $data['y2'],
                $data['distance'] ?? 0,
                $data['width'] ?? 0,
                $data['height'] ?? 0,
                $data['angle'] ?? 0,
            ]);

            $id = (int)$this->db->lastInsertId();
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_measurements WHERE id = ?");
            $retStmt->execute([$id]);
            return $retStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            $this->log("addMeasurement error: " . $e->getMessage());
            return null;
        }
    }

    public function deleteMeasurement(string $email, int $boardId, int $measureId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("DELETE FROM mood_board_measurements WHERE id = ? AND board_id = ?");
            $stmt->execute([$measureId, $boardId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("deleteMeasurement error: " . $e->getMessage());
            return false;
        }
    }

    public function clearMeasurements(string $email, int $boardId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("DELETE FROM mood_board_measurements WHERE board_id = ?");
            $stmt->execute([$boardId]);
            return true;
        } catch (\PDOException $e) {
            $this->log("clearMeasurements error: " . $e->getMessage());
            return false;
        }
    }

    // ========================================
    // CLIENT LINKING
    // ========================================
    
    /**
     * Get mood boards linked to a client
     */
    public function getClientBoards(string $email, int $clientId): array
    {
        try {
            $email = strtolower($email);
            
            $stmt = $this->db->prepare("
                SELECT mb.*, 
                       (SELECT COUNT(*) FROM mood_board_items WHERE board_id = mb.id AND deleted_at IS NULL) as item_count
                FROM mood_boards mb
                INNER JOIN mood_board_client_links mcl ON mcl.mood_board_id = mb.id
                WHERE mcl.client_id = ?
                AND (mb.owner_email = ? OR EXISTS (
                    SELECT 1 FROM mood_board_members WHERE board_id = mb.id AND email = ?
                ))
                ORDER BY mb.updated_at DESC
            ");
            $stmt->execute([$clientId, $email, $email]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->log("getClientBoards error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Link a mood board to a client
     */
    public function linkToClient(string $email, int $clientId, int $boardId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO mood_board_client_links (client_id, mood_board_id) VALUES (?, ?)
            ");
            $stmt->execute([$clientId, $boardId]);
            
            // Also update the board's client_id
            $updateStmt = $this->db->prepare("UPDATE mood_boards SET client_id = ? WHERE id = ?");
            $updateStmt->execute([$clientId, $boardId]);
            
            return true;
        } catch (\PDOException $e) {
            $this->log("linkToClient error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Unlink a mood board from a client
     */
    public function unlinkFromClient(string $email, int $clientId, int $boardId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("DELETE FROM mood_board_client_links WHERE client_id = ? AND mood_board_id = ?");
            $stmt->execute([$clientId, $boardId]);
            
            // Clear client_id from board if this was the linked client
            $updateStmt = $this->db->prepare("UPDATE mood_boards SET client_id = NULL WHERE id = ? AND client_id = ?");
            $updateStmt->execute([$boardId, $clientId]);
            
            return true;
        } catch (\PDOException $e) {
            $this->log("unlinkFromClient error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // MEMBERS
    // ========================================
    
    /**
     * Get members of a mood board (with colleague display info)
     */
    public function getMembers(int $boardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT mbm.*, 
                       oc.display_name, oc.avatar_path, oc.job_title, oc.status as user_status
                FROM mood_board_members mbm
                LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(mbm.email)
                WHERE mbm.board_id = ?
                ORDER BY mbm.added_at
            ");
            $stmt->execute([$boardId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Fallback without colleague info
            try {
                $stmt = $this->db->prepare("SELECT * FROM mood_board_members WHERE board_id = ?");
                $stmt->execute([$boardId]);
                return $stmt->fetchAll();
            } catch (\PDOException $e2) {
                return [];
            }
        }
    }
    
    /**
     * Add a member to a mood board
     */
    public function addMember(string $ownerEmail, int $boardId, string $memberEmail, string $role = 'editor'): bool
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            $memberEmail = strtolower($memberEmail);
            
            // Only owner or admin can add members
            if (!$this->hasAccess($ownerEmail, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, $ownerEmail]);
                if (!$stmt->fetch()) return false;
            }
            
            // Can't add the owner as a member
            $stmt = $this->db->prepare("SELECT owner_email FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();
            if ($board && strtolower($board['owner_email']) === $memberEmail) {
                return false;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_members (board_id, email, role, invited_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role)
            ");
            $stmt->execute([$boardId, $memberEmail, $role, $ownerEmail]);
            
            return true;
        } catch (\PDOException $e) {
            $this->log("addMember error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update a member's role
     */
    public function updateMemberRole(string $ownerEmail, int $boardId, string $memberEmail, string $role): bool
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            $memberEmail = strtolower($memberEmail);
            
            // Only owner or admin can update roles
            if (!$this->hasAccess($ownerEmail, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, $ownerEmail]);
                if (!$stmt->fetch()) return false;
            }
            
            $stmt = $this->db->prepare("UPDATE mood_board_members SET role = ? WHERE board_id = ? AND email = ?");
            $stmt->execute([$role, $boardId, $memberEmail]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("updateMemberRole error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove a member from a mood board
     */
    public function removeMember(string $ownerEmail, int $boardId, string $memberEmail): bool
    {
        try {
            $ownerEmail = strtolower($ownerEmail);
            $memberEmail = strtolower($memberEmail);
            
            // Owner or admin can remove members
            if (!$this->hasAccess($ownerEmail, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, $ownerEmail]);
                if (!$stmt->fetch()) return false;
            }
            
            $delStmt = $this->db->prepare("DELETE FROM mood_board_members WHERE board_id = ? AND email = ?");
            $delStmt->execute([$boardId, $memberEmail]);
            
            return $delStmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("removeMember error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // GROUP ACCESS
    // ========================================
    
    /**
     * Get groups with access to a mood board
     */
    public function getGroupAccess(int $boardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT mga.*, cg.name as group_name, cg.color as group_color, cg.icon as group_icon,
                       (SELECT COUNT(*) FROM colleague_group_members WHERE group_id = cg.id) as member_count
                FROM mood_board_group_access mga
                INNER JOIN colleague_groups cg ON cg.id = mga.group_id
                WHERE mga.board_id = ?
                ORDER BY cg.name
            ");
            $stmt->execute([$boardId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->log("getGroupAccess error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Grant group access to a mood board
     */
    public function addGroupAccess(string $email, int $boardId, int $groupId, string $role = 'editor'): bool
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, $email]);
                if (!$stmt->fetch()) return false;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_group_access (board_id, group_id, role, granted_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role)
            ");
            $stmt->execute([$boardId, $groupId, $role, $email]);
            
            return true;
        } catch (\PDOException $e) {
            $this->log("addGroupAccess error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove group access from a mood board
     */
    public function removeGroupAccess(string $email, int $boardId, int $groupId): bool
    {
        try {
            $email = strtolower($email);
            
            if (!$this->hasAccess($email, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, $email]);
                if (!$stmt->fetch()) return false;
            }
            
            $stmt = $this->db->prepare("DELETE FROM mood_board_group_access WHERE board_id = ? AND group_id = ?");
            $stmt->execute([$boardId, $groupId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("removeGroupAccess error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // BOARD-TO-BOARD LINKING (Mood <-> Kanban)
    // ========================================
    
    /**
     * Get kanban boards linked to a mood board
     */
    public function getLinkedBoards(int $moodBoardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT mbl.*, wb.name as board_name, wb.owner_email as board_owner,
                       (SELECT COUNT(*) FROM webmail_board_cards c 
                        JOIN webmail_board_lists l ON c.list_id = l.id 
                        WHERE l.board_id = wb.id AND c.archived = 0) as card_count
                FROM mood_board_board_links mbl
                INNER JOIN webmail_boards wb ON wb.id = mbl.kanban_board_id
                WHERE mbl.mood_board_id = ?
                ORDER BY mbl.created_at DESC
            ");
            $stmt->execute([$moodBoardId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->log("getLinkedBoards error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get mood boards linked to a kanban board
     */
    public function getMoodBoardsForKanban(string $email, int $kanbanBoardId): array
    {
        try {
            $email = strtolower($email);
            $stmt = $this->db->prepare("
                SELECT mb.*, mbl.created_at as linked_at, mbl.linked_by,
                       (SELECT COUNT(*) FROM mood_board_items WHERE board_id = mb.id AND deleted_at IS NULL) as item_count
                FROM mood_board_board_links mbl
                INNER JOIN mood_boards mb ON mb.id = mbl.mood_board_id
                WHERE mbl.kanban_board_id = ?
                AND (mb.owner_email = ? OR EXISTS (
                    SELECT 1 FROM mood_board_members WHERE board_id = mb.id AND email = ?
                ))
                ORDER BY mbl.created_at DESC
            ");
            $stmt->execute([$kanbanBoardId, $email, $email]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->log("getMoodBoardsForKanban error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Link a mood board to a kanban board
     */
    public function linkToBoard(string $email, int $moodBoardId, int $kanbanBoardId): bool
    {
        try {
            $email = strtolower($email);
            if (!$this->hasAccess($email, $moodBoardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO mood_board_board_links (mood_board_id, kanban_board_id, linked_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$moodBoardId, $kanbanBoardId, $email]);
            return true;
        } catch (\PDOException $e) {
            $this->log("linkToBoard error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Unlink a mood board from a kanban board
     */
    public function unlinkFromBoard(string $email, int $moodBoardId, int $kanbanBoardId): bool
    {
        try {
            $email = strtolower($email);
            if (!$this->hasAccess($email, $moodBoardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("DELETE FROM mood_board_board_links WHERE mood_board_id = ? AND kanban_board_id = ?");
            $stmt->execute([$moodBoardId, $kanbanBoardId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("unlinkFromBoard error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // FILE UPLOADS (to Drive)
    // ========================================
    
    /**
     * Get or create the Drive folder for a mood board's file uploads.
     * With client:    {ClientFolder}/Moodboards/{BoardName}/
     * Without client: Moodboards/{BoardName}/
     * Returns folder ID or null on failure.
     */
    public function getOrCreateMoodboardDriveFolder(string $email, int $boardId): ?int
    {
        try {
            $driveService = new \Webmail\Services\DriveService($this->config, $email);
            
            // Get board info
            $stmt = $this->db->prepare("SELECT name, client_id FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();
            if (!$board) return null;
            
            $boardName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $board['name']);
            $boardName = trim($boardName) ?: 'Board';
            
            $parentFolderId = null;
            
            // If board has a client, look up the client's Drive folder
            if (!empty($board['client_id'])) {
                $clientStmt = $this->db->prepare("SELECT id, display_name, domain, drive_folder_id FROM clients WHERE id = ? AND user_email = ?");
                $clientStmt->execute([$board['client_id'], strtolower($email)]);
                $client = $clientStmt->fetch();
                
                if ($client && !empty($client['drive_folder_id'])) {
                    // Client has a linked Drive folder — put Moodboards inside it
                    $parentFolderId = (int)$client['drive_folder_id'];
                } elseif ($client) {
                    // Client exists but has no Drive folder — create one using client name
                    $clientName = $client['display_name'] ?: $client['domain'] ?: 'Client';
                    $clientName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $clientName);
                    $clientFolder = $driveService->findOrCreateFolder($email, $clientName, null);
                    if ($clientFolder) {
                        $parentFolderId = (int)$clientFolder['id'];
                        // Link this folder to the client
                        $linkStmt = $this->db->prepare("UPDATE clients SET drive_folder_id = ? WHERE id = ?");
                        $linkStmt->execute([$parentFolderId, $client['id']]);
                    }
                }
            }
            
            // Get or create "Moodboards" folder (inside client folder or at root)
            $moodboardsFolder = $driveService->findOrCreateFolder($email, 'Moodboards', $parentFolderId);
            if (!$moodboardsFolder) {
                $this->log("getOrCreateMoodboardDriveFolder: Failed to create Moodboards folder");
                return null;
            }
            
            // Get or create the specific board's folder inside "Moodboards"
            $boardFolder = $driveService->findOrCreateFolder($email, $boardName, (int)$moodboardsFolder['id']);
            if (!$boardFolder) {
                $this->log("getOrCreateMoodboardDriveFolder: Failed to create board folder: $boardName");
                return null;
            }
            
            return (int)$boardFolder['id'];
        } catch (\Exception $e) {
            $this->log("getOrCreateMoodboardDriveFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    /** Allowed upload extensions mapped to acceptable real MIME prefixes/values. */
    private const UPLOAD_ALLOWED_TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov'  => ['video/quicktime'],
        'mp3'  => ['audio/mpeg'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'ogg'  => ['audio/ogg', 'application/ogg', 'video/ogg'],
        'm4a'  => ['audio/mp4', 'audio/x-m4a'],
        'pdf'  => ['application/pdf'],
    ];
    
    private const UPLOAD_MAX_BYTES = 104857600; // 100 MB
    
    /**
     * Validate an uploaded file: extension allowlist + real MIME sniff.
     * Returns an error string, or null when the file is acceptable.
     */
    private function validateUploadedFile(array $fileInfo): ?string
    {
        $name = (string)($fileInfo['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !isset(self::UPLOAD_ALLOWED_TYPES[$ext])) {
            return "File type '.{$ext}' is not allowed";
        }
        
        $size = (int)($fileInfo['size'] ?? 0);
        if ($size <= 0 || $size > self::UPLOAD_MAX_BYTES) {
            return 'File is empty or exceeds the 100 MB limit';
        }
        
        $tmpPath = $fileInfo['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath)) {
            return 'Invalid upload';
        }
        
        // Never trust the client-provided MIME — sniff the actual content
        $realMime = mime_content_type($tmpPath) ?: '';
        $allowed = self::UPLOAD_ALLOWED_TYPES[$ext];
        if (!in_array($realMime, $allowed, true)) {
            return "File content ({$realMime}) does not match its extension '.{$ext}'";
        }
        
        // SVG can carry scripts — reject any with active content
        if ($ext === 'svg') {
            $svg = (string)@file_get_contents($tmpPath, false, null, 0, 262144);
            if ($svg === '' || preg_match('/<\s*script|on\w+\s*=|javascript:|<\s*foreignObject|data:text\/html/i', $svg)) {
                return 'SVG contains disallowed active content';
            }
        }
        
        return null;
    }
    
    /**
     * Upload a file to a mood board — saved to Drive in the proper folder structure.
     * Falls back to local storage if Drive upload fails.
     */
    public function uploadFile(string $email, int $boardId, array $fileInfo): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return null;
            }
            
            $validationError = $this->validateUploadedFile($fileInfo);
            if ($validationError !== null) {
                $this->log("uploadFile rejected '" . ($fileInfo['name'] ?? '?') . "': {$validationError}");
                return null;
            }
            
            return $this->uploadFileLocal($email, $boardId, $fileInfo);
        } catch (\Throwable $e) {
            $this->log("uploadFile error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Upload to Drive and record in mood_board_uploads
     */
    private function uploadFileToDrive(string $email, int $boardId, string $sourcePath, string $originalName, string $mimeType, bool $isUpload = false): ?array
    {
        try {
            $folderId = $this->getOrCreateMoodboardDriveFolder($email, $boardId);
            if (!$folderId) return null;
            
            $driveService = new \Webmail\Services\DriveService($this->config, $email);
            
            // For uploaded files, we need to read from tmp and use uploadFileContent
            $content = file_get_contents($sourcePath);
            if ($content === false) return null;
            
            $driveFile = $driveService->uploadFileContent($email, $originalName, $content, $mimeType, $folderId);
            if (!$driveFile) return null;
            
            // Get image dimensions if applicable
            $widthPx = null;
            $heightPx = null;
            if (str_starts_with($mimeType, 'image/') && file_exists($sourcePath)) {
                $imgInfo = @getimagesize($sourcePath);
                if ($imgInfo) {
                    $widthPx = $imgInfo[0];
                    $heightPx = $imgInfo[1];
                }
            }
            
            // Record in mood_board_uploads with a drive_file_id reference
            $storedName = $driveFile['filename'] ?? basename($driveFile['id'] ?? '');
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_uploads 
                (board_id, original_filename, stored_filename, file_path, mime_type, file_size, width_px, height_px, uploaded_by, drive_file_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                $originalName,
                $storedName,
                'drive://' . $driveFile['id'], // Mark as Drive-stored
                $mimeType,
                $driveFile['size'] ?? strlen($content),
                $widthPx,
                $heightPx,
                $email,
                $driveFile['id']
            ]);
            
            $uploadId = (int)$this->db->lastInsertId();
            
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_uploads WHERE id = ?");
            $retStmt->execute([$uploadId]);
            $upload = $retStmt->fetch();
            
            // URL uses mood board's own serve endpoint (no auth required for img tags)
            $upload['url'] = '/api/mood-boards/' . $boardId . '/uploads/' . $storedName;
            $upload['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/' . $storedName;
            $upload['drive_file_id'] = (int)$driveFile['id'];
            
            // Generate optimized thumbnail (async-safe: runs after DB insert)
            if (str_starts_with($mimeType, 'image/') && file_exists($sourcePath)) {
                try {
                    $thumbService = new ImageThumbnailService();
                    $thumbFilename = $thumbService->generateThumbnail($sourcePath, $boardId, $storedName);
                    if ($thumbFilename) {
                        $thumbStmt = $this->db->prepare("UPDATE mood_board_uploads SET thumbnail_filename = ? WHERE id = ?");
                        $thumbStmt->execute([$thumbFilename, $uploadId]);
                        $upload['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbFilename;
                        $upload['thumbnail_filename'] = $thumbFilename;
                    }
                } catch (\Exception $e) {
                    $this->log("Thumbnail generation failed for upload #{$uploadId}: " . $e->getMessage());
                }
            }
            
            // Write a local cache copy so subsequent reads never touch NAS
            try {
                $cacheDir = dirname(__DIR__, 4) . '/storage/mood-uploads/' . $boardId;
                if (!is_dir($cacheDir)) {
                    mkdir($cacheDir, 0755, true);
                }
                $cachePath = $cacheDir . '/' . $storedName;
                if (!file_exists($cachePath)) {
                    file_put_contents($cachePath, $content);
                }
            } catch (\Throwable $cacheErr) {
                $this->log("Local cache write failed for upload #{$uploadId}: " . $cacheErr->getMessage());
            }
            
            $this->log("File uploaded to Drive: #{$uploadId} '{$originalName}' → drive#{$driveFile['id']} for board #{$boardId} by {$email}");
            
            return $upload;
        } catch (\Exception $e) {
            $this->log("uploadFileToDrive error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fallback: upload to local storage (old method)
     */
    private function uploadFileLocal(string $email, int $boardId, array $fileInfo): ?array
    {
        $uploadDir = __DIR__ . '/../../../../storage/mood-uploads/' . $boardId;
        
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0755, true)) {
                $this->log("uploadFileLocal: mkdir failed for {$uploadDir}");
                return null;
            }
        }
        
        $originalName = $fileInfo['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = 'mood_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $storedName;
        
        if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
            $this->log("uploadFileLocal: Failed to move uploaded file");
            return null;
        }
        
        $widthPx = null;
        $heightPx = null;
        $mimeType = mime_content_type($destPath) ?: ($fileInfo['type'] ?? 'application/octet-stream');
        if (str_starts_with($mimeType, 'image/')) {
            $imgInfo = @getimagesize($destPath);
            if ($imgInfo) {
                $widthPx = $imgInfo[0];
                $heightPx = $imgInfo[1];
            }
        }
        
        $relativePath = 'storage/mood-uploads/' . $boardId . '/' . $storedName;
        
        $stmt = $this->db->prepare("
            INSERT INTO mood_board_uploads 
            (board_id, original_filename, stored_filename, file_path, mime_type, file_size, width_px, height_px, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $boardId, $originalName, $storedName, $relativePath, $mimeType,
            $fileInfo['size'] ?? filesize($destPath), $widthPx, $heightPx, $email
        ]);
        
        $uploadId = (int)$this->db->lastInsertId();
        $retStmt = $this->db->prepare("SELECT * FROM mood_board_uploads WHERE id = ?");
        $retStmt->execute([$uploadId]);
        $upload = $retStmt->fetch();
        $upload['url'] = '/api/mood-boards/' . $boardId . '/uploads/' . $storedName;
        
        // Generate optimized thumbnail
        if (str_starts_with($mimeType, 'image/')) {
            try {
                $thumbService = new ImageThumbnailService();
                $thumbFilename = $thumbService->generateThumbnail($destPath, $boardId, $storedName);
                if ($thumbFilename) {
                    $thumbStmt = $this->db->prepare("UPDATE mood_board_uploads SET thumbnail_filename = ? WHERE id = ?");
                    $thumbStmt->execute([$thumbFilename, $uploadId]);
                    $upload['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbFilename;
                    $upload['thumbnail_filename'] = $thumbFilename;
                }
            } catch (\Exception $e) {
                $this->log("Thumbnail generation failed for local upload #{$uploadId}: " . $e->getMessage());
            }
        }
        
        $this->log("File uploaded (local fallback): #{$uploadId} '{$originalName}' to board #{$boardId} by {$email}");
        return $upload;
    }
    
    /**
     * Import a Drive file into mood board — links the file to the moodboard's Drive folder
     * (copies if in different folder, or just records the link)
     */
    public function importDriveFile(string $email, int $boardId, int $driveFileId, array $config): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            $driveService = new \Webmail\Services\DriveService($config, $email);
            $file = $driveService->getFile($email, $driveFileId);
            
            if (!$file) {
                $this->log("importDriveFile: Drive file #{$driveFileId} not found for {$email}");
                return null;
            }
            
            // Copy the file into the moodboard's Drive folder
            $folderId = $this->getOrCreateMoodboardDriveFolder($email, $boardId);
            $filePath = $driveService->getFilePath($email, $driveFileId);
            
            $newDriveFile = null;
            if ($folderId && $filePath && file_exists($filePath)) {
                $originalName = $file['original_name'] ?? ('drive_file_' . $driveFileId);
                $mimeType = $file['mime_type'] ?? 'application/octet-stream';
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $newDriveFile = $driveService->uploadFileContent($email, $originalName, $content, $mimeType, $folderId);
                }
            }
            
            // Use the new copy's ID, or fall back to original file ID
            $useDriveFileId = $newDriveFile ? (int)$newDriveFile['id'] : $driveFileId;
            $useOriginalName = $file['original_name'] ?? ('drive_file_' . $driveFileId);
            $useMimeType = $file['mime_type'] ?? 'application/octet-stream';
            $useSize = $newDriveFile ? ($newDriveFile['size'] ?? $file['size']) : $file['size'];
            $useFilename = $newDriveFile ? ($newDriveFile['filename'] ?? '') : ($file['filename'] ?? '');
            
            // Get image dimensions
            $widthPx = null;
            $heightPx = null;
            if (str_starts_with($useMimeType, 'image/') && $filePath && file_exists($filePath)) {
                $imgInfo = @getimagesize($filePath);
                if ($imgInfo) {
                    $widthPx = $imgInfo[0];
                    $heightPx = $imgInfo[1];
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_uploads 
                (board_id, original_filename, stored_filename, file_path, mime_type, file_size, width_px, height_px, uploaded_by, drive_file_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId, $useOriginalName, $useFilename,
                'drive://' . $useDriveFileId,
                $useMimeType, $useSize ?? 0,
                $widthPx, $heightPx, $email, $useDriveFileId
            ]);
            
            $uploadId = (int)$this->db->lastInsertId();
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_uploads WHERE id = ?");
            $retStmt->execute([$uploadId]);
            $upload = $retStmt->fetch();
            
            // URL uses mood board's own serve endpoint (no auth required for img tags)
            $upload['url'] = '/api/mood-boards/' . $boardId . '/uploads/' . $useFilename;
            $upload['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/' . $useFilename;
            $upload['drive_file_id'] = $useDriveFileId;
            
            $this->log("Drive file imported: drive#{$driveFileId} → drive#{$useDriveFileId} (upload#{$uploadId}) for board #{$boardId}");
            
            return $upload;
        } catch (\Exception $e) {
            $this->log("importDriveFile error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Link an upload to an item
     */
    public function linkUploadToItem(int $uploadId, int $itemId): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE mood_board_uploads SET item_id = ? WHERE id = ?");
            $stmt->execute([$itemId, $uploadId]);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    // ========================================
    // IMAGE SET MANAGEMENT
    // ========================================
    
    /**
     * Add an image to an image_set item
     */
    public function addImageToSet(string $email, int $boardId, int $itemId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return null;
            
            // Verify item is an image_set and belongs to board
            $checkStmt = $this->db->prepare("SELECT id FROM mood_board_items WHERE id = ? AND board_id = ? AND type = 'image_set' AND deleted_at IS NULL");
            $checkStmt->execute([$itemId, $boardId]);
            if (!$checkStmt->fetch()) return null;
            
            // Get next position
            $posStmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM mood_board_image_set_items WHERE item_id = ?");
            $posStmt->execute([$itemId]);
            $position = (int)$posStmt->fetchColumn();
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_image_set_items 
                (item_id, image_url, thumbnail_url, drive_file_id, original_filename, file_size, width_px, height_px, position)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $itemId,
                $data['image_url'],
                $data['thumbnail_url'] ?? null,
                $data['drive_file_id'] ?? null,
                $data['original_filename'] ?? null,
                $data['file_size'] ?? null,
                $data['width_px'] ?? null,
                $data['height_px'] ?? null,
                $data['position'] ?? $position
            ]);
            
            $imgId = (int)$this->db->lastInsertId();
            
            $retStmt = $this->db->prepare("SELECT * FROM mood_board_image_set_items WHERE id = ?");
            $retStmt->execute([$imgId]);
            return $retStmt->fetch();
        } catch (\PDOException $e) {
            $this->log("addImageToSet error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Batched add-image-to-image_set. One access check, one item
     * verification, one position MAX, one multi-row INSERT instead of
     * N round trips. Returns the freshly-created rows in submission
     * order.
     *
     * @param string $email
     * @param int $boardId
     * @param int $itemId
     * @param array<int, array<string,mixed>> $images Each row may carry
     *        image_url (required), thumbnail_url, drive_file_id,
     *        original_filename, file_size, width_px, height_px
     * @return array{success:int, failed:int, images:array<int,array>}
     */
    public function addImagesToSetBatch(string $email, int $boardId, int $itemId, array $images): array
    {
        $result = ['success' => 0, 'failed' => 0, 'images' => []];

        $images = array_values(array_filter($images, function ($img) {
            return is_array($img) && !empty($img['image_url']);
        }));
        if (empty($images)) return $result;

        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return $result;

            $checkStmt = $this->db->prepare("SELECT id FROM mood_board_items WHERE id = ? AND board_id = ? AND type = 'image_set' AND deleted_at IS NULL");
            $checkStmt->execute([$itemId, $boardId]);
            if (!$checkStmt->fetch()) return $result;

            $posStmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM mood_board_image_set_items WHERE item_id = ?");
            $posStmt->execute([$itemId]);
            $startPos = (int)$posStmt->fetchColumn();

            $this->db->beginTransaction();

            $values = [];
            $params = [];
            foreach ($images as $idx => $img) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $itemId;
                $params[] = $img['image_url'];
                $params[] = $img['thumbnail_url'] ?? null;
                $params[] = isset($img['drive_file_id']) ? (int)$img['drive_file_id'] : null;
                $params[] = $img['original_filename'] ?? null;
                $params[] = isset($img['file_size']) ? (int)$img['file_size'] : null;
                $params[] = isset($img['width_px']) ? (int)$img['width_px'] : null;
                $params[] = isset($img['height_px']) ? (int)$img['height_px'] : null;
                $params[] = $img['position'] ?? ($startPos + $idx);
            }
            $sql = "INSERT INTO mood_board_image_set_items
                (item_id, image_url, thumbnail_url, drive_file_id, original_filename, file_size, width_px, height_px, position)
                VALUES " . implode(',', $values);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $firstId = (int)$this->db->lastInsertId();
            $newIds = [];
            for ($i = 0; $i < count($images); $i++) $newIds[] = $firstId + $i;

            $this->db->commit();

            $placeholders = implode(',', array_fill(0, count($newIds), '?'));
            $hydrate = $this->db->prepare(
                "SELECT * FROM mood_board_image_set_items WHERE id IN ({$placeholders}) ORDER BY id"
            );
            $hydrate->execute($newIds);
            $result['images'] = $hydrate->fetchAll();
            $result['success'] = count($result['images']);
            $result['failed'] = count($images) - $result['success'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("addImagesToSetBatch error: " . $e->getMessage());
            $result['failed'] = count($images);
        }

        return $result;
    }

    /**
     * Remove an image from an image_set
     */
    public function removeImageFromSet(string $email, int $boardId, int $imageId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) return false;
            
            $stmt = $this->db->prepare("
                DELETE isi FROM mood_board_image_set_items isi
                INNER JOIN mood_board_items mi ON mi.id = isi.item_id
                WHERE isi.id = ? AND mi.board_id = ?
            ");
            $stmt->execute([$imageId, $boardId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("removeImageFromSet error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get images in an image_set
     */
    public function getImageSetImages(int $itemId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM mood_board_image_set_items WHERE item_id = ? ORDER BY position ASC");
            $stmt->execute([$itemId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }
    
    /**
     * Duplicate a mood board (with all items and connections)
     */
    public function duplicateBoard(string $email, int $boardId, ?string $newName = null): ?array
    {
        try {
            $email = strtolower($email);
            
            $original = $this->getBoard($email, $boardId);
            if (!$original) return null;
            
            // Create new board
            $newBoard = $this->createBoard($email, [
                'name' => $newName ?? $original['name'] . ' (copy)',
                'description' => $original['description'],
                'background_color' => $original['background_color'],
                'background_image' => $original['background_image'] ?? null,
                'background_image_size' => $original['background_image_size'] ?? 'cover',
                'client_id' => $original['client_id']
            ]);
            
            if (!$newBoard) return null;
            
            $newBoardId = $newBoard['id'];
            $itemIdMap = []; // old_id => new_id
            
            // Copy all items
            foreach ($original['items'] as $item) {
                $newItem = $this->addItem($email, $newBoardId, [
                    'parent_id' => null, // Will remap after
                    'type' => $item['type'],
                    'pos_x' => $item['pos_x'],
                    'pos_y' => $item['pos_y'],
                    'width' => $item['width'],
                    'height' => $item['height'],
                    'rotation' => $item['rotation'],
                    'z_index' => $item['z_index'],
                    'title' => $item['title'],
                    'content' => $item['content'],
                    'color' => $item['color'],
                    'url' => $item['url'],
                    'drive_file_id' => $item['drive_file_id'],
                    'image_url' => $item['image_url'],
                    'thumbnail_url' => $item['thumbnail_url'],
                    'linked_board_id' => $item['linked_board_id'],
                    'linked_card_id' => $item['linked_card_id'],
                    'style_data' => $item['style_data'],
                    'todos' => $item['todos'] ?? []
                ]);
                
                if ($newItem) {
                    $itemIdMap[$item['id']] = $newItem['id'];
                }
            }
            
            // Remap parent_ids
            foreach ($original['items'] as $item) {
                if ($item['parent_id'] && isset($itemIdMap[$item['parent_id']]) && isset($itemIdMap[$item['id']])) {
                    $this->updateItem($email, $newBoardId, $itemIdMap[$item['id']], [
                        'parent_id' => $itemIdMap[$item['parent_id']]
                    ]);
                }
            }
            
            // Copy connections with remapped item IDs
            foreach ($original['connections'] as $conn) {
                if (isset($itemIdMap[$conn['from_item_id']]) && isset($itemIdMap[$conn['to_item_id']])) {
                    $this->addConnection($email, $newBoardId, [
                        'from_item_id' => $itemIdMap[$conn['from_item_id']],
                        'to_item_id' => $itemIdMap[$conn['to_item_id']],
                        'line_style' => $conn['line_style'],
                        'line_color' => $conn['line_color'],
                        'line_width' => $conn['line_width'] ?? 2,
                        'arrow_start' => $conn['arrow_start'],
                        'arrow_end' => $conn['arrow_end'],
                        'label' => $conn['label']
                    ]);
                }
            }
            
            $this->log("Board duplicated: #{$boardId} -> #{$newBoardId} by {$email}");
            
            return $this->getBoard($email, $newBoardId);
        } catch (\PDOException $e) {
            $this->log("duplicateBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    // ========================================
    // PUBLIC SHARING
    // ========================================
    
    /**
     * Create a public share link for a mood board.
     * Only the owner or an admin can create share links.
     */
    public function createShareLink(string $email, int $boardId, string $mode = 'view', ?string $password = null, ?int $expiresHours = null): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'admin')) {
                // Also allow owner
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, strtolower($email)]);
                if (!$stmt->fetch()) return null;
            }
            
            $token = bin2hex(random_bytes(32));
            $expires = $expiresHours ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600)) : null;
            $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
            
            $stmt = $this->db->prepare("
                UPDATE mood_boards 
                SET share_token = ?, share_mode = ?, share_password = ?, share_expires = ?
                WHERE id = ?
            ");
            $stmt->execute([$token, $mode, $hashedPassword, $expires, $boardId]);
            
            $this->log("Share link created for board #{$boardId} by {$email} (mode: {$mode})");
            
            return [
                'token' => $token,
                'mode' => $mode,
                'has_password' => !empty($password),
                'expires_at' => $expires
            ];
        } catch (\PDOException $e) {
            $this->log("createShareLink error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update an existing share link's settings.
     */
    public function updateShareLink(string $email, int $boardId, array $data): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, strtolower($email)]);
                if (!$stmt->fetch()) return null;
            }
            
            $sets = [];
            $params = [];
            
            if (isset($data['mode'])) {
                $sets[] = 'share_mode = ?';
                $params[] = $data['mode'];
            }
            if (array_key_exists('password', $data)) {
                $sets[] = 'share_password = ?';
                $params[] = $data['password'] ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
            }
            if (array_key_exists('expires_hours', $data)) {
                $sets[] = 'share_expires = ?';
                $params[] = $data['expires_hours'] ? date('Y-m-d H:i:s', time() + ($data['expires_hours'] * 3600)) : null;
            }
            
            if (empty($sets)) return null;
            
            $params[] = $boardId;
            $sql = "UPDATE mood_boards SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Return current state
            $stmt = $this->db->prepare("SELECT share_token, share_mode, share_password, share_expires FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $row = $stmt->fetch();
            
            return [
                'token' => $row['share_token'],
                'mode' => $row['share_mode'],
                'has_password' => !empty($row['share_password']),
                'expires_at' => $row['share_expires']
            ];
        } catch (\PDOException $e) {
            $this->log("updateShareLink error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Remove/disable a public share link.
     */
    public function removeShareLink(string $email, int $boardId): bool
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'admin')) {
                $stmt = $this->db->prepare("SELECT id FROM mood_boards WHERE id = ? AND owner_email = ?");
                $stmt->execute([$boardId, strtolower($email)]);
                if (!$stmt->fetch()) return false;
            }
            
            $stmt = $this->db->prepare("
                UPDATE mood_boards 
                SET share_token = NULL, share_mode = 'off', share_password = NULL, share_expires = NULL
                WHERE id = ?
            ");
            $stmt->execute([$boardId]);
            
            $this->log("Share link removed for board #{$boardId} by {$email}");
            return true;
        } catch (\PDOException $e) {
            $this->log("removeShareLink error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get share info by token (public — no auth needed).
     * Returns metadata about the share without exposing the full board.
     */
    public function getShareInfo(string $token): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, owner_email, share_mode, share_password, share_expires,
                       background_color, background_image, background_image_size
                FROM mood_boards 
                WHERE share_token = ? AND share_mode != 'off'
            ");
            $stmt->execute([$token]);
            $board = $stmt->fetch();
            
            if (!$board) return null;
            
            $isExpired = $board['share_expires'] && strtotime($board['share_expires']) < time();
            
            return [
                'board_id' => (int)$board['id'],
                'name' => $board['name'],
                'owner_email' => $board['owner_email'],
                'mode' => $board['share_mode'],
                'requires_password' => !empty($board['share_password']),
                'is_expired' => $isExpired,
                'background_color' => $board['background_color'],
                'background_image' => $board['background_image'] ?? null,
                'background_image_size' => $board['background_image_size'] ?? 'cover',
            ];
        } catch (\PDOException $e) {
            $this->log("getShareInfo error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Validate share password.
     */
    public function validateSharePassword(string $token, string $password): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT share_password FROM mood_boards WHERE share_token = ?");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            
            if (!$row || empty($row['share_password'])) return true; // No password set
            
            return password_verify($password, $row['share_password']);
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get full board data by share token (public — no user auth).
     * Similar to getBoard() but accessed via token instead of user email.
     */
    public function getBoardByShareToken(string $token): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM mood_boards WHERE share_token = ? AND share_mode != 'off'");
            $stmt->execute([$token]);
            $board = $stmt->fetch();
            
            if (!$board) return null;
            
            // Check expiry
            if ($board['share_expires'] && strtotime($board['share_expires']) < time()) {
                return null;
            }
            
            $boardId = (int)$board['id'];
            
            // Get items (excluding soft-deleted)
            $itemStmt = $this->db->prepare("
                SELECT * FROM mood_board_items 
                WHERE board_id = ? AND deleted_at IS NULL
                ORDER BY z_index ASC, created_at ASC
            ");
            $itemStmt->execute([$boardId]);
            $items = $itemStmt->fetchAll();
            
            // Get todos for todo_list items
            $todoItemIds = array_column(array_filter($items, fn($i) => $i['type'] === 'todo_list'), 'id');
            if (!empty($todoItemIds)) {
                $placeholders = implode(',', array_fill(0, count($todoItemIds), '?'));
                $todoStmt = $this->db->prepare("
                    SELECT * FROM mood_board_todos 
                    WHERE item_id IN ({$placeholders}) 
                    ORDER BY position ASC
                ");
                $todoStmt->execute($todoItemIds);
                $allTodos = $todoStmt->fetchAll();
                $todosByItem = [];
                foreach ($allTodos as $todo) {
                    $todosByItem[$todo['item_id']][] = $todo;
                }
                foreach ($items as &$item) {
                    if ($item['type'] === 'todo_list') {
                        $item['todos'] = $todosByItem[$item['id']] ?? [];
                    }
                }
            }
            
            // Get image_set images
            $imageSetItemIds = array_column(array_filter($items, fn($i) => $i['type'] === 'image_set'), 'id');
            if (!empty($imageSetItemIds)) {
                $placeholders = implode(',', array_fill(0, count($imageSetItemIds), '?'));
                $imgStmt = $this->db->prepare("
                    SELECT * FROM mood_board_image_set_items 
                    WHERE item_id IN ({$placeholders}) 
                    ORDER BY position ASC
                ");
                $imgStmt->execute($imageSetItemIds);
                $allImages = $imgStmt->fetchAll();
                $imagesByItem = [];
                foreach ($allImages as $img) {
                    $imagesByItem[$img['item_id']][] = $img;
                }
                foreach ($items as &$item) {
                    if ($item['type'] === 'image_set') {
                        $item['images'] = $imagesByItem[$item['id']] ?? [];
                    }
                }
            }
            
            // Build thumbnail lookup for all uploads in this board
            $thumbLookup = [];
            try {
                $thumbStmt = $this->db->prepare("
                    SELECT stored_filename, thumbnail_filename 
                    FROM mood_board_uploads 
                    WHERE board_id = ? AND thumbnail_filename IS NOT NULL AND thumbnail_filename != '__original__'
                ");
                $thumbStmt->execute([$boardId]);
                while ($row = $thumbStmt->fetch()) {
                    $thumbLookup[$row['stored_filename']] = $row['thumbnail_filename'];
                }
            } catch (\Exception $e) {
                // thumbnail_filename column may not exist yet — ignore
            }
            
            // Parse JSON fields + fix Drive URLs + add thumbnail_url
            foreach ($items as &$item) {
                if ($item['style_data']) {
                    $item['style_data'] = json_decode($item['style_data'], true);
                }
                if (isset($item['color_data']) && $item['color_data']) {
                    $item['color_data'] = json_decode($item['color_data'], true);
                }
                if (!empty($item['image_url']) && str_contains($item['image_url'], '/api/drive/files/')) {
                    $item['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $item['image_url']);
                }
                // Resolve thumbnail_url from the stored filename
                if (!empty($item['image_url']) && str_contains($item['image_url'], '/uploads/')) {
                    $storedFile = basename($item['image_url']);
                    if (isset($thumbLookup[$storedFile])) {
                        $item['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbLookup[$storedFile];
                    }
                }
                if (isset($item['images']) && is_array($item['images'])) {
                    foreach ($item['images'] as &$img) {
                        if (!empty($img['image_url']) && str_contains($img['image_url'], '/api/drive/files/')) {
                            $img['image_url'] = $this->resolveDriveUrlToMoodBoardUrl($boardId, $img['image_url']);
                        }
                        // Resolve thumbnail for image set items too
                        if (!empty($img['image_url']) && str_contains($img['image_url'], '/uploads/')) {
                            $storedFile = basename($img['image_url']);
                            if (isset($thumbLookup[$storedFile])) {
                                $img['thumbnail_url'] = '/api/mood-boards/' . $boardId . '/uploads/thumbs/' . $thumbLookup[$storedFile];
                            }
                        }
                    }
                }
            }
            
            $board['items'] = $items;
            
            // Get connections
            $connStmt = $this->db->prepare("SELECT * FROM mood_board_connections WHERE board_id = ?");
            $connStmt->execute([$boardId]);
            $board['connections'] = $connStmt->fetchAll();
            
            // Strip sensitive data for public view
            unset($board['share_password']);
            $board['members'] = [];
            $board['groups'] = [];
            $board['linked_boards'] = [];
            
            return $board;
        } catch (\PDOException $e) {
            $this->log("getBoardByShareToken error: " . $e->getMessage());
            return null;
        }
    }
    
    // ========================================
    // SHARE ANALYTICS / TRACKING
    // ========================================
    
    /**
     * Track a new share view session.
     */
    public function trackShareView(int $boardId, string $sessionId, array $data): bool
    {
        try {
            // Parse user agent for device/browser/OS
            $ua = $data['user_agent'] ?? '';
            $deviceType = $this->parseDeviceType($ua);
            $browser = $this->parseBrowser($ua);
            $os = $this->parseOS($ua);
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_share_views 
                (board_id, session_id, visitor_ip, user_agent, referrer, device_type, browser, os)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE last_heartbeat_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $boardId,
                $sessionId,
                $data['ip'] ?? null,
                $ua,
                $data['referrer'] ?? null,
                $deviceType,
                $browser,
                $os
            ]);
            
            return true;
        } catch (\PDOException $e) {
            $this->log("trackShareView error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update view session heartbeat (duration tracking).
     */
    public function updateShareViewHeartbeat(string $sessionId, int $durationSeconds, int $slidesViewed = 0): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_share_views 
                SET duration_seconds = ?, slides_viewed = GREATEST(slides_viewed, ?), last_heartbeat_at = CURRENT_TIMESTAMP
                WHERE session_id = ?
            ");
            $stmt->execute([$durationSeconds, $slidesViewed, $sessionId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("updateShareViewHeartbeat error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get share analytics for a specific board.
     */
    public function getShareStats(string $email, int $boardId): ?array
    {
        try {
            if (!$this->hasAccess($email, $boardId, 'viewer')) return null;
            
            // Get share link info
            $stmt = $this->db->prepare("
                SELECT share_token, share_mode, share_password, share_expires 
                FROM mood_boards WHERE id = ?
            ");
            $stmt->execute([$boardId]);
            $shareInfo = $stmt->fetch();
            
            // Total views
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM mood_board_share_views WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $totalViews = (int)$stmt->fetch()['total'];
            
            // Unique visitors (by IP)
            $stmt = $this->db->prepare("SELECT COUNT(DISTINCT visitor_ip) as unique_visitors FROM mood_board_share_views WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $uniqueVisitors = (int)$stmt->fetch()['unique_visitors'];
            
            // Average duration
            $stmt = $this->db->prepare("SELECT AVG(duration_seconds) as avg_duration FROM mood_board_share_views WHERE board_id = ? AND duration_seconds > 0");
            $stmt->execute([$boardId]);
            $avgDuration = round((float)($stmt->fetch()['avg_duration'] ?? 0));
            
            // Total viewing time
            $stmt = $this->db->prepare("SELECT SUM(duration_seconds) as total_duration FROM mood_board_share_views WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $totalDuration = (int)($stmt->fetch()['total_duration'] ?? 0);
            
            // Views per day (last 30 days)
            $stmt = $this->db->prepare("
                SELECT DATE(started_at) as date, COUNT(*) as views 
                FROM mood_board_share_views 
                WHERE board_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(started_at) 
                ORDER BY date DESC
            ");
            $stmt->execute([$boardId]);
            $viewsByDay = $stmt->fetchAll();
            
            // Device breakdown
            $stmt = $this->db->prepare("
                SELECT device_type, COUNT(*) as count 
                FROM mood_board_share_views 
                WHERE board_id = ?
                GROUP BY device_type
            ");
            $stmt->execute([$boardId]);
            $devices = $stmt->fetchAll();
            
            // Browser breakdown
            $stmt = $this->db->prepare("
                SELECT browser, COUNT(*) as count 
                FROM mood_board_share_views 
                WHERE board_id = ? AND browser IS NOT NULL
                GROUP BY browser 
                ORDER BY count DESC 
                LIMIT 10
            ");
            $stmt->execute([$boardId]);
            $browsers = $stmt->fetchAll();
            
            // Recent visitors (last 50)
            $stmt = $this->db->prepare("
                SELECT session_id, visitor_ip, device_type, browser, os, 
                       duration_seconds, slides_viewed, started_at, last_heartbeat_at, referrer
                FROM mood_board_share_views 
                WHERE board_id = ?
                ORDER BY started_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$boardId]);
            $recentVisitors = $stmt->fetchAll();
            
            return [
                'share_info' => [
                    'token' => $shareInfo['share_token'],
                    'mode' => $shareInfo['share_mode'],
                    'has_password' => !empty($shareInfo['share_password']),
                    'expires_at' => $shareInfo['share_expires'],
                    'is_active' => !empty($shareInfo['share_token']) && $shareInfo['share_mode'] !== 'off',
                    'is_expired' => $shareInfo['share_expires'] && strtotime($shareInfo['share_expires']) < time()
                ],
                'summary' => [
                    'total_views' => $totalViews,
                    'unique_visitors' => $uniqueVisitors,
                    'avg_duration_seconds' => $avgDuration,
                    'total_duration_seconds' => $totalDuration
                ],
                'views_by_day' => $viewsByDay,
                'devices' => $devices,
                'browsers' => $browsers,
                'recent_visitors' => $recentVisitors
            ];
        } catch (\PDOException $e) {
            $this->log("getShareStats error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all boards with active public share links for an owner.
     */
    public function getSharedBoards(string $email): array
    {
        try {
            $email = strtolower($email);
            
            $stmt = $this->db->prepare("
                SELECT mb.id, mb.name, mb.description, mb.share_token, mb.share_mode, 
                       mb.share_expires, mb.share_password, mb.created_at, mb.updated_at,
                       mb.background_color, mb.client_id,
                       (SELECT COUNT(*) FROM mood_board_share_views sv WHERE sv.board_id = mb.id) as total_views,
                       (SELECT COUNT(DISTINCT visitor_ip) FROM mood_board_share_views sv WHERE sv.board_id = mb.id) as unique_visitors,
                       (SELECT COALESCE(AVG(duration_seconds), 0) FROM mood_board_share_views sv WHERE sv.board_id = mb.id AND sv.duration_seconds > 0) as avg_duration
                FROM mood_boards mb
                WHERE mb.owner_email = ? AND mb.share_token IS NOT NULL AND mb.share_mode != 'off'
                ORDER BY mb.updated_at DESC
            ");
            $stmt->execute([$email]);
            $boards = $stmt->fetchAll();
            
            // Add client info
            foreach ($boards as &$board) {
                $board['has_password'] = !empty($board['share_password']);
                $board['is_expired'] = $board['share_expires'] && strtotime($board['share_expires']) < time();
                $board['avg_duration'] = round((float)$board['avg_duration']);
                unset($board['share_password']);
                
                if ($board['client_id']) {
                    $clientStmt = $this->db->prepare("SELECT id, domain, display_name FROM clients WHERE id = ?");
                    $clientStmt->execute([$board['client_id']]);
                    $board['client'] = $clientStmt->fetch() ?: null;
                }
            }
            
            return $boards;
        } catch (\PDOException $e) {
            $this->log("getSharedBoards error: " . $e->getMessage());
            return [];
        }
    }
    
    // ========================================
    // USER AGENT PARSING HELPERS
    // ========================================
    
    private function parseDeviceType(string $ua): string
    {
        $ua = strtolower($ua);
        if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'tablet';
        if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry/i', $ua)) return 'mobile';
        if (!empty($ua)) return 'desktop';
        return 'unknown';
    }
    
    private function parseBrowser(string $ua): ?string
    {
        if (empty($ua)) return null;
        if (preg_match('/Edg\//i', $ua)) return 'Edge';
        if (preg_match('/OPR\/|Opera/i', $ua)) return 'Opera';
        if (preg_match('/Chrome\//i', $ua)) return 'Chrome';
        if (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) return 'Safari';
        if (preg_match('/Firefox\//i', $ua)) return 'Firefox';
        if (preg_match('/MSIE|Trident/i', $ua)) return 'IE';
        return 'Other';
    }
    
    private function parseOS(string $ua): ?string
    {
        if (empty($ua)) return null;
        if (preg_match('/Windows NT/i', $ua)) return 'Windows';
        if (preg_match('/Mac OS X/i', $ua)) return 'macOS';
        if (preg_match('/Linux/i', $ua) && !preg_match('/Android/i', $ua)) return 'Linux';
        if (preg_match('/Android/i', $ua)) return 'Android';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'iOS';
        if (preg_match('/CrOS/i', $ua)) return 'ChromeOS';
        return 'Other';
    }

    // ========================================
    // COMPONENT BLOCKS (saved reusable groups)
    // ========================================

    /**
     * Save selected items as a reusable component
     */
    public function saveComponent(string $email, array $data): ?array
    {
        try {
            $email = strtolower($email);
            $name = $data['name'] ?? 'Untitled Component';
            $description = $data['description'] ?? null;
            $itemsData = $data['items_data'] ?? '[]';
            $category = $data['category'] ?? 'custom';
            $isGlobal = $data['is_global'] ?? 0;

            if (is_array($itemsData)) {
                $itemsData = json_encode($itemsData);
            }

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_components (owner_email, name, description, items_data, category, is_global)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$email, $name, $description, $itemsData, $category, $isGlobal]);
            $id = (int)$this->db->lastInsertId();

            return $this->getComponent($email, $id);
        } catch (\PDOException $e) {
            $this->log("saveComponent error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a single component
     */
    public function getComponent(string $email, int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_components
                WHERE id = ? AND (owner_email = ? OR is_global = 1)
            ");
            $stmt->execute([$id, strtolower($email)]);
            $comp = $stmt->fetch();
            if ($comp && $comp['items_data']) {
                $comp['items_data'] = json_decode($comp['items_data'], true);
            }
            return $comp ?: null;
        } catch (\PDOException $e) {
            $this->log("getComponent error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List all components accessible by user (own + global)
     */
    public function getComponents(string $email, ?string $category = null): array
    {
        try {
            $email = strtolower($email);
            $sql = "SELECT * FROM mood_board_components WHERE (owner_email = ? OR is_global = 1)";
            $params = [$email];

            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }

            $sql .= " ORDER BY updated_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $components = $stmt->fetchAll();

            foreach ($components as &$comp) {
                if ($comp['items_data']) {
                    $comp['items_data'] = json_decode($comp['items_data'], true);
                }
            }
            return $components;
        } catch (\PDOException $e) {
            $this->log("getComponents error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update a component
     */
    public function updateComponent(string $email, int $id, array $data): ?array
    {
        try {
            $email = strtolower($email);
            // Only owner can update
            $stmt = $this->db->prepare("SELECT id FROM mood_board_components WHERE id = ? AND owner_email = ?");
            $stmt->execute([$id, $email]);
            if (!$stmt->fetch()) return null;

            $fields = [];
            $values = [];
            $allowed = ['name', 'description', 'category', 'is_global'];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $data)) {
                    $fields[] = "{$f} = ?";
                    $values[] = $data[$f];
                }
            }
            if (array_key_exists('items_data', $data)) {
                $fields[] = "items_data = ?";
                $values[] = is_array($data['items_data']) ? json_encode($data['items_data']) : $data['items_data'];
            }

            if (empty($fields)) return $this->getComponent($email, $id);

            $values[] = $id;
            $sql = "UPDATE mood_board_components SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);

            return $this->getComponent($email, $id);
        } catch (\PDOException $e) {
            $this->log("updateComponent error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a component
     */
    public function deleteComponent(string $email, int $id): bool
    {
        try {
            $email = strtolower($email);
            $stmt = $this->db->prepare("DELETE FROM mood_board_components WHERE id = ? AND owner_email = ?");
            $stmt->execute([$id, $email]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("deleteComponent error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Push component changes to all linked instances across boards.
     * Override-aware: respects _overrides in each instance's style_data.
     * Position is always instance-local and never pushed.
     */
    public function pushComponentChanges(string $email, int $componentId, ?string $skipInstanceId = null): array
    {
        try {
            try {
                $this->db->query("SELECT component_id FROM mood_board_items LIMIT 0");
            } catch (\PDOException $e) {
                return ['updated' => 0, 'instances' => 0, 'error' => 'migration_pending'];
            }

            $email = strtolower($email);
            $component = $this->getComponent($email, $componentId);
            if (!$component || empty($component['items_data'])) {
                return ['updated' => 0, 'instances' => 0];
            }

            $itemsData = $component['items_data'];

            $stmt = $this->db->prepare("
                SELECT id, board_id, component_instance_id, component_item_index,
                       pos_x, pos_y, z_index, style_data, content, title
                FROM mood_board_items
                WHERE component_id = ? AND deleted_at IS NULL
                ORDER BY component_instance_id, component_item_index
            ");
            $stmt->execute([$componentId]);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return ['updated' => 0, 'instances' => 0];
            }

            $instances = [];
            foreach ($rows as $row) {
                $instId = $row['component_instance_id'];
                if ($skipInstanceId && $instId === $skipInstanceId) continue;
                if (!isset($instances[$instId])) $instances[$instId] = [];
                $instances[$instId][] = $row;
            }

            $this->db->beginTransaction();
            $updated = 0;

            foreach ($instances as $instId => $instItems) {
                foreach ($instItems as $instItem) {
                    $idx = (int)($instItem['component_item_index'] ?? 0);
                    if (!isset($itemsData[$idx])) continue;

                    $src = $itemsData[$idx];

                    $existingSD = $instItem['style_data']
                        ? (is_string($instItem['style_data']) ? json_decode($instItem['style_data'], true) : $instItem['style_data'])
                        : [];
                    $overrides = $existingSD['_overrides'] ?? [];

                    $srcSD = isset($src['style_data'])
                        ? (is_string($src['style_data']) ? json_decode($src['style_data'], true) : $src['style_data'])
                        : [];

                    $mergedSD = $existingSD;
                    foreach ($srcSD as $k => $v) {
                        if ($k === '_overrides') continue;
                        if (!in_array($k, $overrides, true)) {
                            $mergedSD[$k] = $v;
                        }
                    }
                    $mergedSD['_overrides'] = $overrides;

                    $sets = ['style_data = ?', 'updated_at = NOW()'];
                    $params = [json_encode($mergedSD)];

                    if (!in_array('content', $overrides, true)) {
                        $sets[] = 'content = ?';
                        $params[] = $src['content'] ?? null;
                    }
                    if (!in_array('title', $overrides, true)) {
                        $sets[] = 'title = ?';
                        $params[] = $src['title'] ?? null;
                    }
                    if (!in_array('width', $overrides, true)) {
                        $sets[] = 'width = ?';
                        $params[] = $src['width'] ?? null;
                    }
                    if (!in_array('height', $overrides, true)) {
                        $sets[] = 'height = ?';
                        $params[] = $src['height'] ?? null;
                    }

                    $sets[] = 'type = ?';
                    $params[] = $src['type'] ?? 'text';
                    $sets[] = 'rotation = ?';
                    $params[] = $src['rotation'] ?? 0;
                    $sets[] = 'color = ?';
                    $params[] = $src['color'] ?? null;
                    $sets[] = 'image_url = ?';
                    $params[] = $src['image_url'] ?? null;
                    $sets[] = 'url = ?';
                    $params[] = $src['url'] ?? null;

                    $params[] = $instItem['id'];
                    $sql = "UPDATE mood_board_items SET " . implode(', ', $sets) . " WHERE id = ?";
                    $this->db->prepare($sql)->execute($params);
                    $updated++;
                }
            }

            $this->db->commit();
            $this->log("Pushed component #{$componentId} changes: {$updated} items across " . count($instances) . " instances");
            return ['updated' => $updated, 'instances' => count($instances)];
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("pushComponentChanges error: " . $e->getMessage());
            return ['updated' => 0, 'instances' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update a component definition from a specific instance item, then push to all other instances.
     */
    public function pushFromItem(string $email, int $componentId, int $itemId, int $boardId): array
    {
        try {
            $email = strtolower($email);
            $component = $this->getComponent($email, $componentId);
            if (!$component || empty($component['items_data'])) {
                return ['success' => false, 'error' => 'component_not_found'];
            }

            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_items
                WHERE id = ? AND board_id = ? AND component_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$itemId, $boardId, $componentId]);
            $item = $stmt->fetch();
            if (!$item) {
                return ['success' => false, 'error' => 'item_not_found'];
            }

            $idx = (int)($item['component_item_index'] ?? 0);
            $itemsData = $component['items_data'];
            if (!isset($itemsData[$idx])) {
                return ['success' => false, 'error' => 'index_out_of_range'];
            }

            $sd = $item['style_data']
                ? (is_string($item['style_data']) ? json_decode($item['style_data'], true) : $item['style_data'])
                : [];
            $cleanSD = $sd;
            unset($cleanSD['_overrides']);

            $itemsData[$idx] = array_merge($itemsData[$idx], [
                'type' => $item['type'],
                'width' => $item['width'],
                'height' => $item['height'],
                'rotation' => $item['rotation'],
                'content' => $item['content'],
                'title' => $item['title'],
                'color' => $item['color'],
                'image_url' => $item['image_url'],
                'url' => $item['url'],
                'style_data' => $cleanSD,
            ]);

            $this->updateComponent($email, $componentId, ['items_data' => $itemsData]);

            $result = $this->pushComponentChanges($email, $componentId, $item['component_instance_id']);
            $result['success'] = true;
            return $result;
        } catch (\PDOException $e) {
            $this->log("pushFromItem error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Detach items from their component (break the link).
     */
    public function detachComponentInstance(string $email, int $boardId, string $instanceId): bool
    {
        try {
            // Check columns exist first
            try {
                $this->db->query("SELECT component_id FROM mood_board_items LIMIT 0");
            } catch (\PDOException $e) {
                return false;
            }

            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("
                UPDATE mood_board_items
                SET component_id = NULL, component_instance_id = NULL, component_item_index = NULL
                WHERE board_id = ? AND component_instance_id = ?
            ");
            $stmt->execute([$boardId, $instanceId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("detachComponentInstance error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Count instances of a component across all boards.
     */
    public function countComponentInstances(int $componentId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT component_instance_id) as cnt
                FROM mood_board_items
                WHERE component_id = ?
            ");
            $stmt->execute([$componentId]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    // ── Design Tokens ──

    /**
     * Get design tokens for a board.
     */
    public function getDesignTokens(string $email, int $boardId): array
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'viewer')) return [];

            $stmt = $this->db->prepare("SELECT design_tokens FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $raw = $stmt->fetchColumn();
            if (!$raw) return [];
            $tokens = json_decode($raw, true);
            return is_array($tokens) ? $tokens : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Save design tokens for a board.
     */
    public function saveDesignTokens(string $email, int $boardId, array $tokens): bool
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("UPDATE mood_boards SET design_tokens = ? WHERE id = ?");
            $stmt->execute([json_encode($tokens), $boardId]);
            return true;
        } catch (\PDOException $e) {
            $this->log("saveDesignTokens error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a design token color and propagate to all items using the old color.
     * Returns the number of items updated.
     */
    public function updateDesignTokenColor(string $email, int $boardId, string $oldColor, string $newColor): int
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return 0;

            $updated = 0;
            $oldLower = strtolower($oldColor);

            // Update item.color
            $stmt = $this->db->prepare("UPDATE mood_board_items SET color = ? WHERE board_id = ? AND LOWER(color) = ?");
            $stmt->execute([$newColor, $boardId, $oldLower]);
            $updated += $stmt->rowCount();

            // Update style_data JSON fields that contain the old color
            // We need to fetch and update items with matching colors in style_data
            $fetchStmt = $this->db->prepare("SELECT id, style_data FROM mood_board_items WHERE board_id = ? AND style_data IS NOT NULL AND deleted_at IS NULL");
            $fetchStmt->execute([$boardId]);
            $items = $fetchStmt->fetchAll();

            $updateSdStmt = $this->db->prepare("UPDATE mood_board_items SET style_data = ? WHERE id = ?");

            $colorFields = [
                'text_color', 'shape_fill', 'shape_border_color', 'shape_text_color',
                'border_color', 'background_color', 'font_color',
            ];

            foreach ($items as $item) {
                $sd = json_decode($item['style_data'], true);
                if (!is_array($sd)) continue;

                $changed = false;
                foreach ($colorFields as $field) {
                    if (isset($sd[$field]) && strtolower($sd[$field]) === $oldLower) {
                        $sd[$field] = $newColor;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $updateSdStmt->execute([json_encode($sd), $item['id']]);
                    $updated++;
                }
            }

            $this->log("Design token color updated on board #{$boardId}: {$oldColor} -> {$newColor}, {$updated} items");
            return $updated;
        } catch (\PDOException $e) {
            $this->log("updateDesignTokenColor error: " . $e->getMessage());
            return 0;
        }
    }

    // ── Global Text Styles ──

    /**
     * Get global text styles for a board.
     */
    public function getGlobalTextStyles(string $email, int $boardId): array
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'viewer')) return [];

            $stmt = $this->db->prepare("SELECT global_text_styles FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $raw = $stmt->fetchColumn();
            if (!$raw) return [];
            $styles = json_decode($raw, true);
            return is_array($styles) ? $styles : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Save global text styles for a board.
     */
    public function saveGlobalTextStyles(string $email, int $boardId, array $styles): bool
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("UPDATE mood_boards SET global_text_styles = ? WHERE id = ?");
            $stmt->execute([json_encode($styles), $boardId]);
            return true;
        } catch (\PDOException $e) {
            $this->log("saveGlobalTextStyles error: " . $e->getMessage());
            return false;
        }
    }

    public function getGlobalCssClasses(string $email, int $boardId): array
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'viewer')) return [];

            $stmt = $this->db->prepare("SELECT global_css_classes FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $raw = $stmt->fetchColumn();
            if (!$raw) return [];
            $classes = json_decode($raw, true);
            return is_array($classes) ? $classes : [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function saveGlobalCssClasses(string $email, int $boardId, array $classes): bool
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return false;

            $stmt = $this->db->prepare("UPDATE mood_boards SET global_css_classes = ? WHERE id = ?");
            $stmt->execute([json_encode($classes), $boardId]);
            return true;
        } catch (\PDOException $e) {
            $this->log("saveGlobalCssClasses error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Propagate a global color change by token ID using semantic _globals binding.
     * Finds all items whose style_data._globals references the given tokenId and
     * updates the resolved color values.
     */
    public function propagateGlobalColor(string $email, int $boardId, string $tokenId, string $newColor): int
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return 0;

            $updated = 0;
            $fetchStmt = $this->db->prepare(
                "SELECT id, color, style_data FROM mood_board_items WHERE board_id = ? AND style_data IS NOT NULL AND deleted_at IS NULL"
            );
            $fetchStmt->execute([$boardId]);
            $items = $fetchStmt->fetchAll();

            $updateSdStmt = $this->db->prepare("UPDATE mood_board_items SET style_data = ?, color = COALESCE(?, color) WHERE id = ?");

            $colorFields = [
                'text_color', 'shape_fill', 'shape_border_color', 'shape_text_color',
                'border_color', 'background_color', 'font_color', 'fill_color',
                'line_color', 'text_stroke_color',
            ];

            foreach ($items as $item) {
                $sd = json_decode($item['style_data'], true);
                if (!is_array($sd) || empty($sd['_globals'])) continue;

                $globals = $sd['_globals'];
                $changed = false;
                $colorChanged = null;

                foreach ($globals as $key => $ref) {
                    if (!is_array($ref) || ($ref['type'] ?? '') !== 'color' || ($ref['id'] ?? '') !== $tokenId) continue;

                    if ($key === '_item_color') {
                        $colorChanged = $newColor;
                    } elseif (in_array($key, $colorFields, true)) {
                        $sd[$key] = $newColor;
                        $changed = true;
                    }
                }

                if ($changed || $colorChanged !== null) {
                    $updateSdStmt->execute([
                        json_encode($sd),
                        $colorChanged,
                        $item['id'],
                    ]);
                    $updated++;
                }
            }

            $this->log("Global color propagated on board #{$boardId}: token={$tokenId} -> {$newColor}, {$updated} items");
            return $updated;
        } catch (\PDOException $e) {
            $this->log("propagateGlobalColor error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Propagate a global text style change by style ID.
     * Finds all items referencing the style in _globals and updates typography fields.
     */
    public function propagateGlobalTextStyle(string $email, int $boardId, string $styleId, array $props): int
    {
        try {
            if (!$this->hasAccess(strtolower($email), $boardId, 'editor')) return 0;

            $updated = 0;
            $fetchStmt = $this->db->prepare(
                "SELECT id, style_data FROM mood_board_items WHERE board_id = ? AND style_data IS NOT NULL AND deleted_at IS NULL"
            );
            $fetchStmt->execute([$boardId]);
            $items = $fetchStmt->fetchAll();

            $updateStmt = $this->db->prepare("UPDATE mood_board_items SET style_data = ? WHERE id = ?");

            $textFields = ['font_family', 'font_weight', 'font_size', 'line_height', 'letter_spacing', 'text_transform'];
            $shapeTextFields = ['shape_font_family', 'shape_font_weight', 'shape_font_size', 'shape_line_height', 'shape_letter_spacing', 'shape_text_transform'];

            foreach ($items as $item) {
                $sd = json_decode($item['style_data'], true);
                if (!is_array($sd) || empty($sd['_globals'])) continue;

                $globals = $sd['_globals'];
                $changed = false;

                if (isset($globals['text_style']) && ($globals['text_style']['id'] ?? '') === $styleId) {
                    foreach ($textFields as $f) {
                        if (isset($props[$f])) { $sd[$f] = $props[$f]; $changed = true; }
                    }
                    if (isset($props['text_color'])) { $sd['text_color'] = $props['text_color']; $changed = true; }
                }

                if (isset($globals['shape_text_style']) && ($globals['shape_text_style']['id'] ?? '') === $styleId) {
                    for ($i = 0; $i < count($textFields); $i++) {
                        if (isset($props[$textFields[$i]])) { $sd[$shapeTextFields[$i]] = $props[$textFields[$i]]; $changed = true; }
                    }
                    if (isset($props['text_color'])) { $sd['shape_text_color'] = $props['text_color']; $changed = true; }
                }

                if ($changed) {
                    $updateStmt->execute([json_encode($sd), $item['id']]);
                    $updated++;
                }
            }

            $this->log("Global text style propagated on board #{$boardId}: style={$styleId}, {$updated} items");
            return $updated;
        } catch (\PDOException $e) {
            $this->log("propagateGlobalTextStyle error: " . $e->getMessage());
            return 0;
        }
    }

    // ========================================
    // USER PALETTES (shareable across boards)
    // ========================================

    /**
     * List user's own palettes + shared palettes from same domain
     */
    public function listUserPalettes(string $email): array
    {
        try {
            $email = strtolower($email);
            $domain = substr($email, strpos($email, '@') + 1);

            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_user_palettes
                WHERE email = ?
                   OR (is_shared = 1 AND email LIKE ?)
                ORDER BY updated_at DESC
            ");
            $stmt->execute([$email, '%@' . $domain]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['colors'] = $row['colors'] ? json_decode($row['colors'], true) : [];
                $row['gradients'] = $row['gradients'] ? json_decode($row['gradients'], true) : [];
                $row['is_shared'] = (bool)$row['is_shared'];
                $row['is_own'] = (strtolower($row['email']) === $email);
            }

            return $rows;
        } catch (\PDOException $e) {
            $this->log("listUserPalettes error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single user palette by ID
     */
    public function getUserPalette(string $email, int $id): ?array
    {
        try {
            $email = strtolower($email);
            $domain = substr($email, strpos($email, '@') + 1);

            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_user_palettes
                WHERE id = ? AND (email = ? OR (is_shared = 1 AND email LIKE ?))
            ");
            $stmt->execute([$id, $email, '%@' . $domain]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) return null;

            $row['colors'] = $row['colors'] ? json_decode($row['colors'], true) : [];
            $row['gradients'] = $row['gradients'] ? json_decode($row['gradients'], true) : [];
            $row['is_shared'] = (bool)$row['is_shared'];
            $row['is_own'] = (strtolower($row['email']) === $email);

            return $row;
        } catch (\PDOException $e) {
            $this->log("getUserPalette error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new user palette
     */
    public function createUserPalette(string $email, array $data): ?array
    {
        try {
            $email = strtolower($email);
            $name = $data['name'] ?? 'Untitled Palette';
            $colors = isset($data['colors']) ? json_encode($data['colors']) : '[]';
            $gradients = isset($data['gradients']) ? json_encode($data['gradients']) : '[]';
            $isShared = !empty($data['is_shared']) ? 1 : 0;

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_user_palettes (email, name, colors, gradients, is_shared)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$email, $name, $colors, $gradients, $isShared]);
            $id = (int)$this->db->lastInsertId();

            return $this->getUserPalette($email, $id);
        } catch (\PDOException $e) {
            $this->log("createUserPalette error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a user palette (only owner can update)
     */
    public function updateUserPalette(string $email, int $id, array $data): ?array
    {
        try {
            $email = strtolower($email);

            $fields = [];
            $values = [];

            if (isset($data['name'])) {
                $fields[] = 'name = ?';
                $values[] = $data['name'];
            }
            if (array_key_exists('colors', $data)) {
                $fields[] = 'colors = ?';
                $values[] = json_encode($data['colors']);
            }
            if (array_key_exists('gradients', $data)) {
                $fields[] = 'gradients = ?';
                $values[] = json_encode($data['gradients']);
            }
            if (isset($data['is_shared'])) {
                $fields[] = 'is_shared = ?';
                $values[] = $data['is_shared'] ? 1 : 0;
            }

            if (empty($fields)) return $this->getUserPalette($email, $id);

            $values[] = $id;
            $values[] = $email;

            $stmt = $this->db->prepare("
                UPDATE mood_board_user_palettes
                SET " . implode(', ', $fields) . "
                WHERE id = ? AND email = ?
            ");
            $stmt->execute($values);

            return $this->getUserPalette($email, $id);
        } catch (\PDOException $e) {
            $this->log("updateUserPalette error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a user palette (only owner can delete)
     */
    public function deleteUserPalette(string $email, int $id): bool
    {
        try {
            $email = strtolower($email);
            $stmt = $this->db->prepare("DELETE FROM mood_board_user_palettes WHERE id = ? AND email = ?");
            $stmt->execute([$id, $email]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("deleteUserPalette error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save current board palette as a new user palette
     */
    public function saveBoardAsUserPalette(string $email, int $boardId, string $name, bool $isShared = false): ?array
    {
        try {
            $email = strtolower($email);
            $board = $this->getBoard($email, $boardId);
            if (!$board) return null;

            $colors = $board['color_palette'] ?? [];
            if (is_string($colors)) $colors = json_decode($colors, true) ?: [];
            $gradients = $board['gradient_palette'] ?? [];
            if (is_string($gradients)) $gradients = json_decode($gradients, true) ?: [];

            return $this->createUserPalette($email, [
                'name' => $name,
                'colors' => $colors,
                'gradients' => $gradients,
                'is_shared' => $isShared,
            ]);
        } catch (\PDOException $e) {
            $this->log("saveBoardAsUserPalette error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Apply a user palette to a board (merge or replace colors + gradients)
     */
    public function applyPaletteToBoard(string $email, int $paletteId, int $boardId, string $mode = 'merge'): ?array
    {
        try {
            $email = strtolower($email);
            $palette = $this->getUserPalette($email, $paletteId);
            if (!$palette) return null;

            if (!$this->hasAccess($email, $boardId, 'editor')) return null;

            $board = $this->getBoard($email, $boardId);
            if (!$board) return null;

            $boardColors = $board['color_palette'] ?? [];
            if (is_string($boardColors)) $boardColors = json_decode($boardColors, true) ?: [];
            $boardGradients = $board['gradient_palette'] ?? [];
            if (is_string($boardGradients)) $boardGradients = json_decode($boardGradients, true) ?: [];

            if ($mode === 'replace') {
                $boardColors = $palette['colors'] ?? [];
                $boardGradients = $palette['gradients'] ?? [];
            } else {
                // Merge: add new colors/gradients that don't already exist
                foreach ($palette['colors'] ?? [] as $color) {
                    if (!in_array($color, $boardColors)) {
                        $boardColors[] = $color;
                    }
                }
                foreach ($palette['gradients'] ?? [] as $gradient) {
                    $key = json_encode($gradient);
                    $exists = false;
                    foreach ($boardGradients as $bg) {
                        if (json_encode($bg) === $key) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $boardGradients[] = $gradient;
                    }
                }
            }

            return $this->updateBoard($email, $boardId, [
                'color_palette' => json_encode($boardColors),
                'gradient_palette' => json_encode($boardGradients),
            ]);
        } catch (\PDOException $e) {
            $this->log("applyPaletteToBoard error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fire CRM automation hook when a moodboard is marked as ready.
     * Silently ignored if CRM automation is not active.
     */
    private function fireAutomationMoodBoardReady(int $boardId, string $email): void
    {
        try {
            // Get board name for the automation context
            $stmt = $this->db->prepare("SELECT name FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $boardName = $stmt->fetchColumn() ?: "Board #{$boardId}";

            $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($this->config);
            $automationService->onMoodBoardReady($boardId, $boardName, $email);
        } catch (\Throwable $e) {
            $this->log("Automation hook error: " . $e->getMessage());
        }
    }
    
    // ========================================
    // FOLDER MANAGEMENT
    // ========================================
    
    public function getFolders(string $email): array
    {
        try {
            $email = strtolower($email);
            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_folders
                WHERE owner_email = ?
                ORDER BY sort_order ASC, name ASC
            ");
            $stmt->execute([$email]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->log("getFolders error: " . $e->getMessage());
            return [];
        }
    }
    
    public function createFolder(string $email, array $data): ?array
    {
        try {
            $email = strtolower($email);
            $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
            
            if ($parentId) {
                $check = $this->db->prepare("SELECT id FROM mood_board_folders WHERE id = ? AND owner_email = ?");
                $check->execute([$parentId, $email]);
                if (!$check->fetch()) {
                    return null;
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO mood_board_folders (owner_email, parent_id, name, color, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $email,
                $parentId,
                $data['name'],
                $data['color'] ?? null,
                $data['sort_order'] ?? 0
            ]);
            
            $folderId = (int)$this->db->lastInsertId();
            $this->log("Folder created: #{$folderId} '{$data['name']}' by {$email}");
            
            $get = $this->db->prepare("SELECT * FROM mood_board_folders WHERE id = ?");
            $get->execute([$folderId]);
            return $get->fetch() ?: null;
        } catch (\PDOException $e) {
            $this->log("createFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    public function updateFolder(string $email, int $folderId, array $data): ?array
    {
        try {
            $email = strtolower($email);
            $check = $this->db->prepare("SELECT id FROM mood_board_folders WHERE id = ? AND owner_email = ?");
            $check->execute([$folderId, $email]);
            if (!$check->fetch()) {
                return null;
            }
            
            $fields = [];
            $values = [];
            $allowed = ['name', 'parent_id', 'color', 'sort_order'];
            
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                $get = $this->db->prepare("SELECT * FROM mood_board_folders WHERE id = ?");
                $get->execute([$folderId]);
                return $get->fetch() ?: null;
            }
            
            // Prevent circular nesting: a folder cannot be its own ancestor
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $targetParent = (int)$data['parent_id'];
                if ($targetParent === $folderId) return null;
                $cursor = $targetParent;
                $visited = [];
                while ($cursor) {
                    if (in_array($cursor, $visited)) break;
                    $visited[] = $cursor;
                    $pStmt = $this->db->prepare("SELECT parent_id FROM mood_board_folders WHERE id = ?");
                    $pStmt->execute([$cursor]);
                    $row = $pStmt->fetch();
                    if (!$row) break;
                    $cursor = $row['parent_id'] ? (int)$row['parent_id'] : null;
                    if ($cursor === $folderId) return null;
                }
            }
            
            $values[] = $folderId;
            $sql = "UPDATE mood_board_folders SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            $get = $this->db->prepare("SELECT * FROM mood_board_folders WHERE id = ?");
            $get->execute([$folderId]);
            return $get->fetch() ?: null;
        } catch (\PDOException $e) {
            $this->log("updateFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    public function deleteFolder(string $email, int $folderId): bool
    {
        try {
            $email = strtolower($email);
            $check = $this->db->prepare("SELECT id FROM mood_board_folders WHERE id = ? AND owner_email = ?");
            $check->execute([$folderId, $email]);
            if (!$check->fetch()) {
                return false;
            }
            
            // Unfile boards in this folder (and descendant folders) before cascade-deleting
            $this->unfileBoardsInFolder($folderId);
            
            $stmt = $this->db->prepare("DELETE FROM mood_board_folders WHERE id = ?");
            $stmt->execute([$folderId]);
            $this->log("Folder deleted: #{$folderId} by {$email}");
            return true;
        } catch (\PDOException $e) {
            $this->log("deleteFolder error: " . $e->getMessage());
            return false;
        }
    }
    
    private function unfileBoardsInFolder(int $folderId): void
    {
        // Collect this folder and all descendant folder IDs
        $ids = [$folderId];
        $queue = [$folderId];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $stmt = $this->db->prepare("SELECT id FROM mood_board_folders WHERE parent_id = ?");
            $stmt->execute([$current]);
            while ($row = $stmt->fetch()) {
                $ids[] = (int)$row['id'];
                $queue[] = (int)$row['id'];
            }
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->prepare("UPDATE mood_boards SET folder_id = NULL WHERE folder_id IN ({$placeholders})")->execute($ids);
    }
    
    public function reorderFolders(string $email, array $orders): bool
    {
        try {
            $email = strtolower($email);
            $stmt = $this->db->prepare("UPDATE mood_board_folders SET sort_order = ? WHERE id = ? AND owner_email = ?");
            foreach ($orders as $item) {
                $stmt->execute([(int)($item['sort_order'] ?? 0), (int)$item['id'], $email]);
            }
            return true;
        } catch (\PDOException $e) {
            $this->log("reorderFolders error: " . $e->getMessage());
            return false;
        }
    }
    
    public function moveBoard(string $email, int $boardId, ?int $folderId): bool
    {
        try {
            $email = strtolower($email);
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                return false;
            }
            if ($folderId !== null) {
                $check = $this->db->prepare("SELECT id FROM mood_board_folders WHERE id = ? AND owner_email = ?");
                $check->execute([$folderId, $email]);
                if (!$check->fetch()) {
                    return false;
                }
            }
            $stmt = $this->db->prepare("UPDATE mood_boards SET folder_id = ? WHERE id = ?");
            $stmt->execute([$folderId, $boardId]);
            return true;
        } catch (\PDOException $e) {
            $this->log("moveBoard error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // TEXT CSV EXPORT / IMPORT
    // ========================================
    
    public function exportTexts(string $email, int $boardId): ?string
    {
        try {
            if (!$this->hasAccess($email, $boardId)) {
                return null;
            }
            
            $stmt = $this->db->prepare("
                SELECT id, title, content FROM mood_board_items
                WHERE board_id = ? AND type = 'text' AND deleted_at IS NULL
                ORDER BY z_index ASC, id ASC
            ");
            $stmt->execute([$boardId]);
            $items = $stmt->fetchAll();
            
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['item_id', 'title', 'content']);
            foreach ($items as $item) {
                fputcsv($handle, [
                    $item['id'],
                    $item['title'] ?? '',
                    $item['content'] ?? ''
                ]);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);
            
            return $csv;
        } catch (\PDOException $e) {
            $this->log("exportTexts error: " . $e->getMessage());
            return null;
        }
    }
    
    public function importTexts(string $email, int $boardId, string $csvContent): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];
        
        try {
            if (!$this->hasAccess($email, $boardId, 'editor')) {
                $result['errors'][] = 'Access denied';
                return $result;
            }
            
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $csvContent);
            rewind($handle);
            
            $header = fgetcsv($handle);
            if (!$header || !in_array('item_id', $header)) {
                $result['errors'][] = 'CSV must have an item_id column';
                fclose($handle);
                return $result;
            }
            
            $colMap = array_flip($header);
            $idIdx = $colMap['item_id'];
            $titleIdx = $colMap['title'] ?? null;
            $contentIdx = $colMap['content'] ?? null;
            
            if ($titleIdx === null && $contentIdx === null) {
                $result['errors'][] = 'CSV must have at least a title or content column';
                fclose($handle);
                return $result;
            }
            
            while (($row = fgetcsv($handle)) !== false) {
                if (empty($row) || !isset($row[$idIdx])) {
                    $result['skipped']++;
                    continue;
                }
                
                $itemId = (int)$row[$idIdx];
                
                // Verify item belongs to this board and is type=text
                $check = $this->db->prepare("SELECT id FROM mood_board_items WHERE id = ? AND board_id = ? AND type = 'text' AND deleted_at IS NULL");
                $check->execute([$itemId, $boardId]);
                if (!$check->fetch()) {
                    $result['skipped']++;
                    continue;
                }
                
                $sets = [];
                $vals = [];
                if ($titleIdx !== null && isset($row[$titleIdx])) {
                    $sets[] = 'title = ?';
                    $vals[] = $row[$titleIdx];
                }
                if ($contentIdx !== null && isset($row[$contentIdx])) {
                    $sets[] = 'content = ?';
                    $vals[] = $row[$contentIdx];
                }
                
                if (!empty($sets)) {
                    $vals[] = $itemId;
                    $sql = "UPDATE mood_board_items SET " . implode(', ', $sets) . " WHERE id = ?";
                    $this->db->prepare($sql)->execute($vals);
                    $result['updated']++;
                }
            }
            
            fclose($handle);
            $this->log("importTexts: board #{$boardId} updated={$result['updated']} skipped={$result['skipped']} by {$email}");
            
        } catch (\PDOException $e) {
            $this->log("importTexts error: " . $e->getMessage());
            $result['errors'][] = 'Database error: ' . $e->getMessage();
        }
        
        return $result;
    }

    // ========================================
    // COMMENTS
    // ========================================

    /**
     * List all comments for a board (non-deleted), grouped by thread.
     */
    public function getComments(int $boardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM mood_board_comments
                WHERE board_id = ? AND deleted_at IS NULL
                ORDER BY created_at ASC
            ");
            $stmt->execute([$boardId]);
            $comments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $threads = [];
            foreach ($comments as $c) {
                $c['id'] = (int)$c['id'];
                $c['board_id'] = (int)$c['board_id'];
                $c['item_id'] = $c['item_id'] !== null ? (int)$c['item_id'] : null;
                $c['parent_id'] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
                $c['is_public'] = (bool)$c['is_public'];
                $c['pin_x'] = $c['pin_x'] !== null ? (float)$c['pin_x'] : null;
                $c['pin_y'] = $c['pin_y'] !== null ? (float)$c['pin_y'] : null;

                $tid = $c['thread_id'];
                if (!isset($threads[$tid])) {
                    $threads[$tid] = [
                        'thread_id' => $tid,
                        'item_id'   => $c['item_id'],
                        'pin_x'     => $c['pin_x'],
                        'pin_y'     => $c['pin_y'],
                        'resolved'  => $c['resolved_at'] !== null,
                        'resolved_at' => $c['resolved_at'],
                        'resolved_by' => $c['resolved_by'],
                        'comments'  => [],
                    ];
                }
                $threads[$tid]['comments'][] = $c;
            }

            return array_values($threads);
        } catch (\PDOException $e) {
            $this->log("getComments error (board #{$boardId}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a comment to a board. Works for both internal and public users.
     * Returns the created comment row or null on failure.
     */
    public function addComment(int $boardId, array $data): ?array
    {
        try {
            // Verify table exists before inserting
            try {
                $this->db->query("SELECT 1 FROM mood_board_comments LIMIT 1");
            } catch (\PDOException $tableCheck) {
                $this->log("addComment: mood_board_comments table missing (error: {$tableCheck->getMessage()}), creating now...");
                // Create the table directly as a fallback
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS mood_board_comments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        board_id INT NOT NULL,
                        item_id INT DEFAULT NULL,
                        thread_id CHAR(36) NOT NULL,
                        parent_id INT DEFAULT NULL,
                        author_email VARCHAR(255) DEFAULT NULL,
                        author_name VARCHAR(255) NOT NULL,
                        author_avatar_color VARCHAR(7) DEFAULT NULL,
                        content TEXT NOT NULL,
                        pin_x DECIMAL(10,4) DEFAULT NULL,
                        pin_y DECIMAL(10,4) DEFAULT NULL,
                        is_public TINYINT(1) NOT NULL DEFAULT 0,
                        share_token VARCHAR(64) DEFAULT NULL,
                        resolved_at TIMESTAMP NULL DEFAULT NULL,
                        resolved_by VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        deleted_at TIMESTAMP NULL DEFAULT NULL,
                        INDEX idx_mbc_board (board_id),
                        INDEX idx_mbc_thread (thread_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $this->log("addComment: mood_board_comments table created successfully");
            }

            $threadId   = $data['thread_id'] ?? $this->generateUuid();
            $parentId   = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
            $itemId     = !empty($data['item_id']) ? (int)$data['item_id'] : null;
            $content    = trim($data['content'] ?? '');
            $authorEmail = $data['author_email'] ?? null;
            $authorName  = trim($data['author_name'] ?? 'Anonymous');
            $colorKey = $data['author_email'] ?? $data['author_name'] ?? 'Anonymous';
            $avatarColor = $data['author_avatar_color'] ?? $this->avatarColorFor($colorKey);
            $pinX        = isset($data['pin_x']) ? (float)$data['pin_x'] : null;
            $pinY        = isset($data['pin_y']) ? (float)$data['pin_y'] : null;
            $isPublic    = !empty($data['is_public']) ? 1 : 0;
            $shareToken  = $data['share_token'] ?? null;

            if (empty($content)) {
                $this->log("addComment: empty content rejected for board #{$boardId}");
                return null;
            }

            $stmt = $this->db->prepare("
                INSERT INTO mood_board_comments
                    (board_id, item_id, thread_id, parent_id, author_email, author_name,
                     author_avatar_color, content, pin_x, pin_y, is_public, share_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId, $itemId, $threadId, $parentId,
                $authorEmail, $authorName, $avatarColor,
                $content, $pinX, $pinY, $isPublic, $shareToken
            ]);

            $id = (int)$this->db->lastInsertId();
            $this->log("addComment: comment #{$id} on board #{$boardId} thread={$threadId} by {$authorName}");

            return $this->getComment($id);
        } catch (\PDOException $e) {
            $this->log("addComment error (board #{$boardId}): " . $e->getMessage());
            throw new \RuntimeException("DB error adding comment: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get a single comment by ID.
     */
    public function getComment(int $commentId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM mood_board_comments WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$commentId]);
            $c = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$c) return null;

            $c['id'] = (int)$c['id'];
            $c['board_id'] = (int)$c['board_id'];
            $c['item_id'] = $c['item_id'] !== null ? (int)$c['item_id'] : null;
            $c['parent_id'] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
            $c['is_public'] = (bool)$c['is_public'];
            $c['pin_x'] = $c['pin_x'] !== null ? (float)$c['pin_x'] : null;
            $c['pin_y'] = $c['pin_y'] !== null ? (float)$c['pin_y'] : null;

            return $c;
        } catch (\PDOException $e) {
            $this->log("getComment error (#{$commentId}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a comment's content.
     * Only the original author can edit (validated in controller).
     */
    public function updateComment(int $commentId, string $content): ?array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_comments SET content = ?, updated_at = NOW()
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([trim($content), $commentId]);

            if ($stmt->rowCount() === 0) {
                $this->log("updateComment: comment #{$commentId} not found or already deleted");
                return null;
            }

            $this->log("updateComment: comment #{$commentId} updated");
            return $this->getComment($commentId);
        } catch (\PDOException $e) {
            $this->log("updateComment error (#{$commentId}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Soft-delete a comment.
     */
    public function deleteComment(int $commentId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_comments SET deleted_at = NOW()
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$commentId]);

            if ($stmt->rowCount() === 0) {
                $this->log("deleteComment: comment #{$commentId} not found or already deleted");
                return false;
            }

            $this->log("deleteComment: comment #{$commentId} soft-deleted");
            return true;
        } catch (\PDOException $e) {
            $this->log("deleteComment error (#{$commentId}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Soft-delete all comments in a thread.
     */
    public function deleteThread(int $boardId, string $threadId): int
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_comments SET deleted_at = NOW()
                WHERE board_id = ? AND thread_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$boardId, $threadId]);
            $count = $stmt->rowCount();
            $this->log("deleteThread: board #{$boardId} thread {$threadId} — {$count} comments soft-deleted");
            return $count;
        } catch (\PDOException $e) {
            $this->log("deleteThread error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Resolve a comment thread.
     */
    public function resolveThread(int $boardId, string $threadId, string $resolvedBy): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_comments
                SET resolved_at = NOW(), resolved_by = ?
                WHERE board_id = ? AND thread_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$resolvedBy, $boardId, $threadId]);

            $this->log("resolveThread: thread {$threadId} on board #{$boardId} resolved by {$resolvedBy}");
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("resolveThread error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unresolve a comment thread.
     */
    public function unresolveThread(int $boardId, string $threadId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mood_board_comments
                SET resolved_at = NULL, resolved_by = NULL
                WHERE board_id = ? AND thread_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$boardId, $threadId]);

            $this->log("unresolveThread: thread {$threadId} on board #{$boardId} re-opened");
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $this->log("unresolveThread error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a board allows public comments.
     */
    public function boardAllowsComments(int $boardId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT allow_comments FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row && (int)$row['allow_comments'] === 1;
        } catch (\PDOException $e) {
            $this->log("boardAllowsComments error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get board owner info for notifications.
     */
    public function getBoardOwnerInfo(int $boardId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, owner_email, name, notify_on_comment FROM mood_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;

            return [
                'id'                => (int)$row['id'],
                'owner_email'       => $row['owner_email'],
                'name'              => $row['name'],
                'notify_on_comment' => (bool)$row['notify_on_comment'],
            ];
        } catch (\PDOException $e) {
            $this->log("getBoardOwnerInfo error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get comment count per item for a board (for badge rendering).
     */
    public function getCommentCountsByItem(int $boardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT item_id, COUNT(DISTINCT thread_id) AS thread_count, COUNT(*) AS comment_count
                FROM mood_board_comments
                WHERE board_id = ? AND deleted_at IS NULL AND item_id IS NOT NULL
                GROUP BY item_id
            ");
            $stmt->execute([$boardId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $r) {
                $result[(int)$r['item_id']] = [
                    'threads'  => (int)$r['thread_count'],
                    'comments' => (int)$r['comment_count'],
                ];
            }
            return $result;
        } catch (\PDOException $e) {
            $this->log("getCommentCountsByItem error: " . $e->getMessage());
            return [];
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function avatarColorFor(string $identifier): string
    {
        $colors = ['#6366f1','#ec4899','#14b8a6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16','#f97316','#10b981'];
        $hash = crc32(strtolower(trim($identifier)));
        return $colors[abs($hash) % count($colors)];
    }
}

