<?php

namespace Webmail\Addons\KanbanBoards\Services;

class BoardService
{
    private \PDO $db;
    private ?\Webmail\Services\DriveService $driveService = null;
    private ?\Webmail\Addons\Calendar\Services\CalendarService $calendarService = null;
    private ?\Webmail\Addons\EmailTracking\Services\TrackingService $trackingService = null;
    private ?\Webmail\Addons\ProjectHub\Services\ProjectHubService $projectHubService = null;
    private array $config;
    private string $logFile;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/boards.log';
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTablesExist();
    }
    
    /**
     * Get database connection (for direct queries)
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    /**
     * Log message to file for debugging
     */
    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
    
    /**
     * Log an activity to the activity_log table
     * @param string $userEmail Who performed the action
     * @param string $actionType Type of action (e.g., 'card_created', 'task_completed')
     * @param string $entityType Type of entity (e.g., 'board', 'card', 'todo')
     * @param int|null $entityId ID of the entity
     * @param string|null $entityName Name/title for display
     * @param int|null $boardId Related board ID
     * @param int|null $clientId Related client ID
     * @param array|null $metadata Additional data (old/new values, etc.)
     */
    public function logActivity(
        string $userEmail,
        string $actionType,
        string $entityType,
        ?int $entityId = null,
        ?string $entityName = null,
        ?int $boardId = null,
        ?int $clientId = null,
        ?array $metadata = null
    ): bool {
        try {
            // Ensure activity_log table exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    action_type VARCHAR(50) NOT NULL,
                    entity_type VARCHAR(50) NOT NULL,
                    entity_id INT DEFAULT NULL,
                    entity_name VARCHAR(255) DEFAULT NULL,
                    board_id INT DEFAULT NULL,
                    client_id INT DEFAULT NULL,
                    metadata JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_client (client_id),
                    INDEX idx_user (user_email),
                    INDEX idx_created (created_at),
                    INDEX idx_action (action_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $this->db->prepare('
                INSERT INTO activity_log (user_email, action_type, entity_type, entity_id, entity_name, board_id, client_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                strtolower($userEmail),
                $actionType,
                $entityType,
                $entityId,
                $entityName,
                $boardId,
                $clientId,
                $metadata ? json_encode($metadata) : null
            ]);
            $this->log("Activity logged: {$actionType} on {$entityType} by {$userEmail}");
            return true;
        } catch (\PDOException $e) {
            $this->log("Failed to log activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get activity log entries for a board
     */
    public function getBoardActivityLog(int $boardId, int $limit = 50, int $offset = 0): array
    {
        try {
            // Note: LIMIT/OFFSET must be interpolated as integers, not placeholders
            // PDO's emulated prepares converts placeholders to quoted strings which causes SQL errors
            $stmt = $this->db->prepare("
                SELECT * FROM activity_log 
                WHERE board_id = ?
                ORDER BY created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ");
            $stmt->execute([$boardId]);
            $logs = $stmt->fetchAll();
            
            // Decode metadata JSON
            foreach ($logs as &$log) {
                if ($log['metadata']) {
                    $log['metadata'] = json_decode($log['metadata'], true);
                }
            }
            
            return $logs;
        } catch (\PDOException $e) {
            $this->log("Failed to get board activity log: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get activity log entries for a client
     * Includes activities directly tagged with client_id AND activities from boards linked to the client
     */
    public function getClientActivityLog(int $clientId, int $limit = 50, int $offset = 0): array
    {
        try {
            // Get board IDs linked to this client
            $boardStmt = $this->db->prepare("SELECT board_id FROM client_boards WHERE client_id = ?");
            $boardStmt->execute([$clientId]);
            $linkedBoardIds = $boardStmt->fetchAll(\PDO::FETCH_COLUMN);
            
            if (!empty($linkedBoardIds)) {
                // Query activities with client_id OR from linked boards
                $placeholders = implode(',', array_fill(0, count($linkedBoardIds), '?'));
                $params = [$clientId];
                $params = array_merge($params, $linkedBoardIds);
                
                $stmt = $this->db->prepare("
                    SELECT * FROM activity_log 
                    WHERE client_id = ? OR board_id IN ({$placeholders})
                    ORDER BY created_at DESC
                    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
                ");
                $stmt->execute($params);
            } else {
                // No linked boards, only query by client_id
                $stmt = $this->db->prepare("
                    SELECT * FROM activity_log 
                    WHERE client_id = ?
                    ORDER BY created_at DESC
                    LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
                ");
                $stmt->execute([$clientId]);
            }
            
            $logs = $stmt->fetchAll();
            
            // Decode metadata JSON
            foreach ($logs as &$log) {
                if ($log['metadata']) {
                    $log['metadata'] = json_decode($log['metadata'], true);
                }
            }
            
            return $logs;
        } catch (\PDOException $e) {
            $this->log("Failed to get client activity log: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add a column to a table if it doesn't already exist
     */
    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        try {
            // Check if column exists
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
                // Column doesn't exist, add it
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                $this->log("Added column {$column} to {$table}");
            }
        } catch (\PDOException $e) {
            // Log but don't fail - column might already exist or other non-critical issue
            $this->log("Note: Could not add column {$column} to {$table}: " . $e->getMessage());
        }
    }
    
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (isset($cache[$key])) return $cache[$key];
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$key] = (bool)($stmt->fetch()['cnt'] ?? 0);
        } catch (\PDOException $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    /**
     * Get or create DriveService instance
     */
    private function getDriveService(): \Webmail\Services\DriveService
    {
        if (!$this->driveService) {
            $this->driveService = new \Webmail\Services\DriveService($this->config);
        }
        return $this->driveService;
    }
    
    /**
     * Get or create CalendarService instance
     */
    private function getCalendarService(): \Webmail\Addons\Calendar\Services\CalendarService
    {
        if (!$this->calendarService) {
            $this->calendarService = new \Webmail\Addons\Calendar\Services\CalendarService($this->config);
        }
        return $this->calendarService;
    }
    
    /**
     * Get or create TrackingService instance for notifications
     */
    private function getTrackingService(): \Webmail\Addons\EmailTracking\Services\TrackingService
    {
        if (!$this->trackingService) {
            $this->trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);
        }
        return $this->trackingService;
    }

    private function getProjectHubService(): \Webmail\Addons\ProjectHub\Services\ProjectHubService
    {
        if (!$this->projectHubService) {
            $this->projectHubService = new \Webmail\Addons\ProjectHub\Services\ProjectHubService($this->config);
        }
        return $this->projectHubService;
    }

    /**
     * Push a real-time notification via Redis so the frontend picks it up instantly.
     */
    private function pushRealtimeNotification(string $userEmail, int $notifId, string $type, string $title, string $message, array $data = []): void
    {
        try {
            if (!extension_loaded('redis')) return;
            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) $redis->auth($password);
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';

            $redis->publish($prefix . 'mailbox:' . $userEmail, json_encode([
                'type' => 'NOTIFICATION_CREATED',
                'payload' => [
                    'id' => $notifId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'is_read' => false,
                    'created_at' => date('c'),
                ],
                'timestamp' => round(microtime(true) * 1000),
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log("BoardService Redis push error: " . $e->getMessage());
        }
    }

    /**
     * Fire an event into the Automation Hub so matching workflows execute.
     */
    private function fireAutomationEvent(string $triggerType, array $context): void
    {
        try {
            $engine = new \Webmail\Addons\AutomationHub\Services\WorkflowEngineService($this->config);
            $engine->onEvent($triggerType, $context);
        } catch (\Throwable $e) {
            error_log("BoardService automation event error [{$triggerType}]: " . $e->getMessage());
        }
    }

    /**
     * Get all board member emails (and the owner) except a given email.
     */
    private function getBoardRecipients(int $boardId, string $excludeEmail): array
    {
        $excludeEmail = strtolower($excludeEmail);

        $ownerStmt = $this->db->prepare("SELECT owner_email FROM webmail_boards WHERE id = ?");
        $ownerStmt->execute([$boardId]);
        $owner = $ownerStmt->fetchColumn();

        $membersStmt = $this->db->prepare("SELECT user_email FROM webmail_board_members WHERE board_id = ?");
        $membersStmt->execute([$boardId]);
        $members = $membersStmt->fetchAll(\PDO::FETCH_COLUMN);

        $all = $members;
        if ($owner) $all[] = strtolower($owner);

        return array_values(array_unique(array_filter($all, fn($e) => strtolower($e) !== $excludeEmail)));
    }
    
    /**
     * Create all required tables
     */
    private function ensureTablesExist(): void
    {
        try {
            // Boards table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_boards (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    background_color VARCHAR(20) DEFAULT '#1e1e26',
                    background_image VARCHAR(500) DEFAULT NULL,
                    background_blur INT DEFAULT 0,
                    background_overlay_color VARCHAR(20) DEFAULT NULL,
                    background_overlay_opacity INT DEFAULT 0,
                    archived TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_owner (owner_email),
                    INDEX idx_archived (archived)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Add columns if they don't exist (for existing installations)
            $this->addColumnIfNotExists('webmail_boards', 'background_blur', 'INT DEFAULT 0');
            $this->addColumnIfNotExists('webmail_boards', 'background_overlay_color', 'VARCHAR(20) DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_boards', 'background_overlay_opacity', 'INT DEFAULT 0');
            $this->addColumnIfNotExists('webmail_boards', 'client_id', 'INT UNSIGNED DEFAULT NULL');
            
            // Board members table (for multi-user collaboration)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_members (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    role ENUM('owner', 'editor', 'viewer') DEFAULT 'viewer',
                    invited_by VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_board_member (board_id, user_email),
                    INDEX idx_user_email (user_email),
                    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Add permission columns to board members
            $this->addColumnIfNotExists('webmail_board_members', 'can_view_financials', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_members', 'can_view_client', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_members', 'can_view_contacts', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_members', 'can_view_emails', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_members', 'can_access_drive', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_members', 'drive_folder_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_members', 'drive_permission', "ENUM('viewer', 'editor') DEFAULT 'viewer'");
            $this->addColumnIfNotExists('webmail_board_members', 'member_type', "ENUM('internal','guest') NOT NULL DEFAULT 'internal'");
            
            // Activity log table for tracking all changes
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    action_type VARCHAR(50) NOT NULL,
                    entity_type VARCHAR(50) NOT NULL,
                    entity_id INT DEFAULT NULL,
                    entity_name VARCHAR(255) DEFAULT NULL,
                    board_id INT DEFAULT NULL,
                    client_id INT DEFAULT NULL,
                    metadata JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board (board_id),
                    INDEX idx_client (client_id),
                    INDEX idx_user (user_email),
                    INDEX idx_created (created_at),
                    INDEX idx_action (action_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Board lists (columns)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_lists (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    position INT DEFAULT 0,
                    archived TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_board_id (board_id),
                    INDEX idx_position (position),
                    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Add milestone/financial columns to lists
            $this->addColumnIfNotExists('webmail_board_lists', 'expected_amount', 'DECIMAL(12,2) DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_lists', 'invoice_date', 'DATE DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_lists', 'is_milestone', 'TINYINT(1) DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_lists', 'currency', "VARCHAR(3) DEFAULT 'HUF'");
            $this->addColumnIfNotExists('webmail_board_lists', 'payment_status', "VARCHAR(20) DEFAULT 'unpaid'");
            $this->addColumnIfNotExists('webmail_board_lists', 'list_color', 'VARCHAR(7) DEFAULT NULL');
            
            // Add payment terms override to boards
            $this->addColumnIfNotExists('webmail_boards', 'payment_terms_days', 'INT DEFAULT NULL');
            
            // Labels (board-level)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_labels (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    name VARCHAR(100),
                    color VARCHAR(20) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board_id (board_id),
                    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Cards
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_cards (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    list_id INT NOT NULL,
                    title VARCHAR(500) NOT NULL,
                    description TEXT,
                    position INT DEFAULT 0,
                    due_date DATETIME DEFAULT NULL,
                    start_date DATETIME DEFAULT NULL,
                    completed TINYINT(1) DEFAULT 0,
                    completed_at TIMESTAMP NULL DEFAULT NULL,
                    cover_color VARCHAR(20) DEFAULT NULL,
                    card_color VARCHAR(7) DEFAULT NULL,
                    cover_image_id INT DEFAULT NULL,
                    calendar_event_id INT DEFAULT NULL,
                    created_by VARCHAR(255),
                    assigned_to VARCHAR(255) DEFAULT NULL,
                    archived TINYINT(1) DEFAULT 0,
                    parent_card_id INT DEFAULT NULL,
                    time_estimate_seconds INT UNSIGNED DEFAULT NULL,
                    time_budget_alert_sent TINYINT(1) NOT NULL DEFAULT 0,
                    full_task_visibility TINYINT(1) NOT NULL DEFAULT 0,
                    simulation_run_id VARCHAR(16) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_list_id (list_id),
                    INDEX idx_position (position),
                    INDEX idx_due_date (due_date),
                    INDEX idx_assigned_to (assigned_to),
                    INDEX idx_parent_card (parent_card_id),
                    INDEX idx_webmail_board_cards_sim_run (simulation_run_id),
                    FOREIGN KEY (list_id) REFERENCES webmail_board_lists(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Self-heal columns that older installs predate (mirrors migrations
            // 107/139/142/148/154 + ProjectHub's parent_card_id, which only ran
            // if the table existed at migration time).
            $this->addColumnIfNotExists('webmail_board_cards', 'card_color', 'VARCHAR(7) DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_cards', 'parent_card_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_cards', 'time_estimate_seconds', 'INT UNSIGNED DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_board_cards', 'time_budget_alert_sent', 'TINYINT(1) NOT NULL DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_cards', 'full_task_visibility', 'TINYINT(1) NOT NULL DEFAULT 0');
            $this->addColumnIfNotExists('webmail_board_cards', 'simulation_run_id', 'VARCHAR(16) NULL');
            
            // Card labels (many-to-many)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_card_labels (
                    card_id INT NOT NULL,
                    label_id INT NOT NULL,
                    PRIMARY KEY (card_id, label_id),
                    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE,
                    FOREIGN KEY (label_id) REFERENCES webmail_board_labels(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Checklists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_card_checklists (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    position INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Checklist items
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_checklist_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    checklist_id INT NOT NULL,
                    title VARCHAR(500) NOT NULL,
                    completed TINYINT(1) DEFAULT 0,
                    position INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_checklist_id (checklist_id),
                    FOREIGN KEY (checklist_id) REFERENCES webmail_card_checklists(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Migration: Ensure title column is at least 500 characters (or TEXT for unlimited)
            try {
                $stmt = $this->db->prepare("
                    SELECT CHARACTER_MAXIMUM_LENGTH 
                    FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'webmail_checklist_items' 
                    AND COLUMN_NAME = 'title'
                ");
                $stmt->execute();
                $result = $stmt->fetch();
                
                if ($result && (int)$result['CHARACTER_MAXIMUM_LENGTH'] < 1000) {
                    // Increase to TEXT for unlimited length, or at least 1000 characters
                    $this->db->exec("ALTER TABLE webmail_checklist_items MODIFY COLUMN title TEXT NOT NULL");
                    $this->log("Updated webmail_checklist_items.title to TEXT");
                }
            } catch (\PDOException $e) {
                // Column might not exist yet or other issue - log but don't fail
                $this->log("Note: Could not update title column: " . $e->getMessage());
            }
            
            // Card attachments (links to drive_files)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_card_attachments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    drive_file_id INT DEFAULT NULL,
                    name VARCHAR(255) NOT NULL,
                    url VARCHAR(1000) DEFAULT NULL,
                    is_cover TINYINT(1) DEFAULT 0,
                    created_by VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    INDEX idx_drive_file_id (drive_file_id),
                    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("ALTER TABLE webmail_card_attachments ADD COLUMN IF NOT EXISTS drive_file_id INT DEFAULT NULL");
            $this->db->exec("ALTER TABLE webmail_card_attachments ADD COLUMN IF NOT EXISTS url VARCHAR(1000) DEFAULT NULL");
            $this->db->exec("ALTER TABLE webmail_card_attachments ADD COLUMN IF NOT EXISTS is_cover TINYINT(1) DEFAULT 0");
            
            // Card comments
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_card_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    content TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $this->addColumnIfNotExists('webmail_card_comments', 'parent_comment_id', 'INT DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_card_comments', 'edited_at', 'TIMESTAMP NULL DEFAULT NULL');
            $this->addColumnIfNotExists('webmail_card_comments', 'mentions', 'JSON DEFAULT NULL');
            
            // Card activity log
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_card_activity (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    details JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_id (card_id),
                    INDEX idx_created_at (created_at),
                    FOREIGN KEY (card_id) REFERENCES webmail_board_cards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Email-Board links table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_emails (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    email_uid INT NOT NULL,
                    email_folder VARCHAR(255) NOT NULL,
                    email_subject VARCHAR(500),
                    email_from VARCHAR(255),
                    thread_id VARCHAR(255),
                    linked_by VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board_id (board_id),
                    INDEX idx_email (email_uid, email_folder),
                    INDEX idx_thread (thread_id),
                    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Progress report history
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_board_progress_reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    board_id INT NOT NULL,
                    sent_by VARCHAR(255) NOT NULL,
                    sent_to VARCHAR(500) NOT NULL,
                    subject VARCHAR(500),
                    content TEXT,
                    cards_included JSON,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_board_id (board_id),
                    INDEX idx_sent_at (sent_at),
                    FOREIGN KEY (board_id) REFERENCES webmail_boards(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
        } catch (\PDOException $e) {
            error_log("BoardService table creation error: " . $e->getMessage());
        }
    }
    
    // ========================================
    // BOARD METHODS
    // ========================================
    
    /**
     * Get all boards for a user (owned + member of + group access)
     */
    public function getBoards(string $email, bool $includeArchived = false): array
    {
        $email = strtolower($email);

        $canSeeAll = false;
        $userDomain = $this->getDomainFromEmail($email);
        $groupIds = [];
        try {
            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
            $canSeeAll = $colleagueService->hasGroupPermission($email, 'can_see_all_boards');
            if (!$canSeeAll) {
                $groupIds = array_column($colleagueService->getUserGroups($email), 'id');
            }
        } catch (\Throwable $e) {
            $this->log("getBoards group lookup error: " . $e->getMessage());
        }

        $selectFields = "
            SELECT DISTINCT b.*, 
                   CASE WHEN LOWER(b.owner_email) = ? THEN 'owner' ELSE COALESCE(m.role, CASE WHEN bga.group_id IS NOT NULL THEN (CASE WHEN bga.can_edit = 1 THEN 'editor' ELSE 'viewer' END) ELSE 'viewer' END) END as user_role,
                   (SELECT COUNT(*) FROM webmail_board_cards c 
                    JOIN webmail_board_lists l ON c.list_id = l.id 
                    WHERE l.board_id = b.id AND c.archived = 0) as card_count,
                   (SELECT COUNT(*) FROM webmail_board_cards c 
                    JOIN webmail_board_lists l ON c.list_id = l.id 
                    WHERE l.board_id = b.id AND c.archived = 0 AND c.completed = 1) as completed_count,
                   (SELECT COUNT(*) FROM webmail_board_cards c 
                    JOIN webmail_board_lists l ON c.list_id = l.id 
                    WHERE l.board_id = b.id AND c.archived = 0 AND c.completed = 0 
                    AND c.due_date IS NOT NULL AND c.due_date < NOW()) as overdue_count,
                   (SELECT COUNT(*) FROM webmail_board_lists l 
                    WHERE l.board_id = b.id AND l.archived = 0) as list_count,
                   (SELECT COUNT(*) FROM webmail_board_emails be WHERE be.board_id = b.id) as email_count,
                   (SELECT COUNT(*) FROM webmail_board_members bm WHERE bm.board_id = b.id) as member_count,
                   (SELECT c.display_name FROM client_boards cb 
                    JOIN clients c ON c.id = cb.client_id 
                    WHERE cb.board_id = b.id LIMIT 1) as client_name
        ";

        $params = [$email];

        if ($canSeeAll && $userDomain) {
            $sql = $selectFields . "
                FROM webmail_boards b
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = ?
                LEFT JOIN board_group_access bga ON 1=0
                JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(b.owner_email) AND oc.organization_domain = ?
                WHERE 1=1
            ";
            $params[] = $email;
            $params[] = $userDomain;
        } elseif (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $sql = $selectFields . "
                FROM webmail_boards b
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = ?
                LEFT JOIN board_group_access bga ON bga.board_id = b.id AND bga.group_id IN ($placeholders)
                WHERE (LOWER(b.owner_email) = ? OR LOWER(m.user_email) = ? OR bga.group_id IS NOT NULL)
            ";
            $params[] = $email;
            $params = array_merge($params, $groupIds);
            $params[] = $email;
            $params[] = $email;
        } else {
            $sql = $selectFields . "
                FROM webmail_boards b
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = ?
                LEFT JOIN board_group_access bga ON 1=0
                WHERE (LOWER(b.owner_email) = ? OR LOWER(m.user_email) = ?)
            ";
            $params[] = $email;
            $params[] = $email;
            $params[] = $email;
        }

        if (!$includeArchived) {
            $sql .= " AND b.archived = 0";
        }
        
        $sql .= " ORDER BY b.updated_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $boards = array_map(function($board) {
            $board['archived'] = (bool)$board['archived'];
            $board['card_count'] = (int)($board['card_count'] ?? 0);
            $board['completed_count'] = (int)($board['completed_count'] ?? 0);
            $board['overdue_count'] = (int)($board['overdue_count'] ?? 0);
            $board['list_count'] = (int)($board['list_count'] ?? 0);
            $board['email_count'] = (int)($board['email_count'] ?? 0);
            $board['member_count'] = (int)($board['member_count'] ?? 0);
            $board['total_revenue'] = 0;
            $board['total_cost'] = 0;
            $board['automation_count'] = 0;
            $board['email_rule_count'] = 0;
            return $board;
        }, $stmt->fetchAll());
        
        if (empty($boards)) return $boards;
        
        $boardIds = array_column($boards, 'id');
        $boardMap = [];
        foreach ($boards as &$b) {
            $boardMap[$b['id']] = &$b;
        }
        unset($b);
        
        // Batch-load financial stats (boardpro table may not exist)
        try {
            $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
            $finStmt = $this->db->prepare("
                SELECT l.board_id,
                       COALESCE(SUM(cf.estimated_revenue), 0) as total_revenue,
                       COALESCE(SUM(cf.estimated_cost), 0) as total_cost
                FROM boardpro_card_financials cf
                JOIN webmail_board_cards c ON cf.card_id = c.id AND c.archived = 0
                JOIN webmail_board_lists l ON c.list_id = l.id
                WHERE l.board_id IN ($placeholders)
                GROUP BY l.board_id
            ");
            $finStmt->execute($boardIds);
            foreach ($finStmt->fetchAll() as $row) {
                $bid = (int)$row['board_id'];
                if (isset($boardMap[$bid])) {
                    $boardMap[$bid]['total_revenue'] = (float)$row['total_revenue'];
                    $boardMap[$bid]['total_cost'] = (float)$row['total_cost'];
                }
            }
        } catch (\PDOException $e) {
            // boardpro_card_financials table may not exist
        }
        
        // Batch-load automation counts (boardpro table may not exist)
        try {
            $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
            $autoStmt = $this->db->prepare("
                SELECT board_id, COUNT(*) as automation_count
                FROM boardpro_automation_rules
                WHERE board_id IN ($placeholders) AND is_active = 1
                GROUP BY board_id
            ");
            $autoStmt->execute($boardIds);
            foreach ($autoStmt->fetchAll() as $row) {
                $bid = (int)$row['board_id'];
                if (isset($boardMap[$bid])) {
                    $boardMap[$bid]['automation_count'] = (int)$row['automation_count'];
                }
            }
        } catch (\PDOException $e) {
            // boardpro_automation_rules table may not exist
        }
        
        // Batch-load email rule counts (boardpro table may not exist)
        try {
            $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
            $emailRuleStmt = $this->db->prepare("
                SELECT board_id, COUNT(*) as email_rule_count
                FROM boardpro_email_rules
                WHERE board_id IN ($placeholders) AND is_active = 1
                GROUP BY board_id
            ");
            $emailRuleStmt->execute($boardIds);
            foreach ($emailRuleStmt->fetchAll() as $row) {
                $bid = (int)$row['board_id'];
                if (isset($boardMap[$bid])) {
                    $boardMap[$bid]['email_rule_count'] = (int)$row['email_rule_count'];
                }
            }
        } catch (\PDOException $e) {
            // boardpro_email_rules table may not exist
        }
        
        return $boards;
    }
    
    /**
     * Get a single board with full data
     */
    public function getBoard(string $email, int $boardId): ?array
    {
        $email = strtolower($email);
        
        // Check access
        if (!$this->hasAccess($email, $boardId)) {
            return null;
        }
        
        // Get board with all permission columns (case-insensitive email comparison)
        try {
            $stmt = $this->db->prepare("
                SELECT b.*, 
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 'owner' ELSE COALESCE(m.role, 'viewer') END as user_role,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 1 ELSE COALESCE(m.can_view_financials, 0) END as can_view_financials,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 1 ELSE COALESCE(m.can_view_client, 0) END as can_view_client,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 1 ELSE COALESCE(m.can_view_contacts, 0) END as can_view_contacts,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 1 ELSE COALESCE(m.can_view_emails, 0) END as can_view_emails,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 1 ELSE COALESCE(m.can_access_drive, 0) END as can_access_drive,
                       (SELECT COUNT(*) FROM webmail_board_cards c 
                        JOIN webmail_board_lists l ON c.list_id = l.id 
                        WHERE l.board_id = b.id AND c.archived = 0) as card_count,
                       (SELECT COUNT(*) FROM webmail_board_emails be WHERE be.board_id = b.id) as email_count
                FROM webmail_boards b
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = LOWER(?)
                WHERE b.id = ?
            ");
            $stmt->execute([$email, $email, $email, $email, $email, $email, $email, $boardId]);
            $board = $stmt->fetch();
        } catch (\PDOException $e) {
            // Fallback without permission columns
            $stmt = $this->db->prepare("
                SELECT b.*, 
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 'owner' ELSE COALESCE(m.role, 'viewer') END as user_role,
                       (SELECT COUNT(*) FROM webmail_board_cards c 
                        JOIN webmail_board_lists l ON c.list_id = l.id 
                        WHERE l.board_id = b.id AND c.archived = 0) as card_count,
                       (SELECT COUNT(*) FROM webmail_board_emails be WHERE be.board_id = b.id) as email_count
                FROM webmail_boards b
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = LOWER(?)
                WHERE b.id = ?
            ");
            $stmt->execute([$email, $email, $boardId]);
            $board = $stmt->fetch();
            if ($board) {
                // Owner has all permissions, members have none by default
                $isOwner = strtolower($board['owner_email']) === strtolower($email);
                $board['can_view_financials'] = $isOwner ? 1 : 0;
                $board['can_view_client'] = $isOwner ? 1 : 0;
                $board['can_view_contacts'] = $isOwner ? 1 : 0;
                $board['can_view_emails'] = $isOwner ? 1 : 0;
                $board['can_access_drive'] = $isOwner ? 1 : 0;
            }
        }
        
        if (!$board) return null;
        
        $board['archived'] = (bool)$board['archived'];
        $board['card_count'] = (int)($board['card_count'] ?? 0);
        $board['email_count'] = (int)($board['email_count'] ?? 0);
        // Cast all permissions to boolean
        $board['can_view_financials'] = (bool)($board['can_view_financials'] ?? false);
        $board['can_view_client'] = (bool)($board['can_view_client'] ?? false);
        $board['can_view_contacts'] = (bool)($board['can_view_contacts'] ?? false);
        $board['can_view_emails'] = (bool)($board['can_view_emails'] ?? false);
        $board['can_access_drive'] = (bool)($board['can_access_drive'] ?? false);
        
        // Ensure background settings are properly cast
        $board['background_blur'] = isset($board['background_blur']) ? (int)$board['background_blur'] : 0;
        $board['background_overlay_opacity'] = isset($board['background_overlay_opacity']) ? (int)$board['background_overlay_opacity'] : 0;
        $board['background_overlay_color'] = $board['background_overlay_color'] ?? null;
        
        $isOwner = strtolower($board['owner_email'] ?? '') === strtolower($email);
        $filterAssignee = $isOwner ? null : $email;
        $board['lists'] = $this->getLists($boardId, false, $filterAssignee);
        $board['labels'] = $this->getLabels($boardId);
        $board['members'] = $this->getMembers($boardId);
        
        // Get linked client from client_boards table
        try {
            $clientStmt = $this->db->prepare('
                SELECT c.id, c.display_name, c.domain, c.status
                FROM client_boards cb
                JOIN clients c ON c.id = cb.client_id
                WHERE cb.board_id = ?
                LIMIT 1
            ');
            $clientStmt->execute([$boardId]);
            $linkedClient = $clientStmt->fetch();
            $board['linked_client'] = $linkedClient ?: null;
        } catch (\PDOException $e) {
            $board['linked_client'] = null;
        }
        
        // Strip financial data from lists if user doesn't have permission
        if (!$board['can_view_financials']) {
            foreach ($board['lists'] as &$list) {
                unset($list['expected_amount']);
                unset($list['invoice_date']);
                unset($list['currency']);
                unset($list['payment_status']);
            }
        }
        
        return $board;
    }
    
    /**
     * Check if user has access to a board
     */
    public function hasAccess(string $email, int $boardId, string $minRole = 'viewer'): bool
    {
        $email = strtolower($email);
        
        $stmt = $this->db->prepare("
            SELECT b.owner_email, m.role
            FROM webmail_boards b
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = ?
            WHERE b.id = ?
        ");
        $stmt->execute([$email, $boardId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        $ownerEmail = strtolower($result['owner_email']);
        if ($ownerEmail === $email) {
            return true;
        }
        
        // Check direct member role
        $role = $result['role'];
        $roleHierarchy = ['viewer' => 1, 'editor' => 2, 'owner' => 3];
        if ($role && ($roleHierarchy[$role] ?? 0) >= ($roleHierarchy[$minRole] ?? 0)) {
            return true;
        }

        // Check group-level permissions (can_see_all_boards or board_group_access)
        try {
            $colleagueService = new \Webmail\Addons\Team\Services\ColleagueService($this->config);

            if ($colleagueService->hasGroupPermission($email, 'can_see_all_boards')) {
                $boardDomain = $this->getDomainFromEmail($ownerEmail);
                $userDomain = $this->getDomainFromEmail($email);
                if ($boardDomain === $userDomain) {
                    return true;
                }
            }

            $groupAccess = $colleagueService->getGroupAccessLevel($email, 'board', $boardId);
            if ($groupAccess !== null) {
                $groupRole = $groupAccess >= 2 ? 'editor' : 'viewer';
                if (($roleHierarchy[$groupRole] ?? 0) >= ($roleHierarchy[$minRole] ?? 0)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->log("hasAccess group check error: " . $e->getMessage());
        }

        return false;
    }

    private function getDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        return strtolower($parts[1] ?? '');
    }
    
    /**
     * Create a new board
     */
    public function createBoard(string $email, array $data): ?array
    {
        $email = strtolower($email);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_boards (owner_email, name, description, background_color)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $email,
                $data['name'] ?? 'Untitled Board',
                $data['description'] ?? null,
                $data['background_color'] ?? '#1e1e26'
            ]);
            
            $boardId = (int)$this->db->lastInsertId();
            
            // Create default lists
            $defaultLists = $data['default_lists'] ?? ['To Do', 'In Progress', 'Done'];
            foreach ($defaultLists as $position => $listName) {
                $this->createList($email, $boardId, ['name' => $listName, 'position' => $position]);
            }
            
            // Create default labels
            $defaultLabels = [
                ['name' => 'Priority', 'color' => '#ef4444'],
                ['name' => 'Bug', 'color' => '#f97316'],
                ['name' => 'Feature', 'color' => '#22c55e'],
                ['name' => 'Enhancement', 'color' => '#3b82f6'],
                ['name' => 'Question', 'color' => '#a855f7'],
            ];
            foreach ($defaultLabels as $label) {
                $this->createLabel($boardId, $label);
            }
            
            // Create Drive folder for board attachments
            $this->createBoardDriveFolder($email, $boardId, $data['name'] ?? 'Untitled Board');
            
            // Log activity
            $this->logActivity($email, 'board_created', 'board', $boardId, $data['name'] ?? 'Untitled Board', $boardId);
            
            return $this->getBoard($email, $boardId);
        } catch (\PDOException $e) {
            error_log("BoardService createBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create Drive folder for board attachments
     */
    private function createBoardDriveFolder(string $email, int $boardId, string $boardName): ?int
    {
        try {
            $drive = $this->getDriveService();
            $email = strtolower($email);
            
            // First, ensure "Boards" parent folder exists
            $boardsFolder = $drive->findOrCreateFolder($email, 'Boards', null);
            if (!$boardsFolder) return null;
            
            // Create folder for this specific board
            $folder = $drive->createFolder($email, $boardName, $boardsFolder['id']);
            if (!$folder) return null;
            
            // Link the folder to the board by setting board_id
            $stmt = $this->db->prepare("UPDATE drive_folders SET board_id = ? WHERE id = ? AND user_email = ?");
            $stmt->execute([$boardId, $folder['id'], $email]);
            
            return $folder['id'];
        } catch (\Exception $e) {
            error_log("BoardService createBoardDriveFolder error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update a board
     */
    public function updateBoard(string $email, int $boardId, array $data): ?array
    {
        if (!$this->hasAccess($email, $boardId, 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'background_color', 'background_image', 'background_blur', 'background_overlay_color', 'background_overlay_opacity', 'archived', 'client_id', 'start_date', 'end_date', 'budget_hours'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                if ($field === 'archived') {
                    $values[] = $data[$field] ? 1 : 0;
                } elseif (in_array($field, ['background_blur', 'background_overlay_opacity'])) {
                    $values[] = (int)$data[$field];
                } elseif ($field === 'client_id') {
                    $values[] = $data[$field] ? (int)$data[$field] : null;
                } elseif (in_array($field, ['start_date', 'end_date'])) {
                    $values[] = $data[$field] ?: null;
                } elseif ($field === 'budget_hours') {
                    $values[] = ($data[$field] !== null && $data[$field] !== '') ? (float)$data[$field] : null;
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) {
            return $this->getBoard($email, $boardId);
        }
        
        // Get current board data for logging
        $currentBoard = $this->getBoard($email, $boardId);
        
        $values[] = $boardId;
        
        $stmt = $this->db->prepare("UPDATE webmail_boards SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        
        // Log activity based on what changed
        if (isset($data['name']) && $data['name'] !== ($currentBoard['name'] ?? '')) {
            $this->logActivity($email, 'board_renamed', 'board', $boardId, $data['name'], $boardId, null, [
                'old_name' => $currentBoard['name'] ?? '',
                'new_name' => $data['name']
            ]);
        }
        if (isset($data['archived']) && $data['archived']) {
            $this->logActivity($email, 'board_archived', 'board', $boardId, $currentBoard['name'] ?? '', $boardId);
        }
        
        return $this->getBoard($email, $boardId);
    }
    
    /**
     * Delete a board
     */
    public function deleteBoard(string $email, int $boardId): bool
    {
        // Only owner can delete
        $stmt = $this->db->prepare("SELECT owner_email, name FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        $board = $stmt->fetch();
        
        if (!$board || strtolower($board['owner_email']) !== strtolower($email)) {
            return false;
        }
        
        // Log before deletion
        $this->logActivity($email, 'board_deleted', 'board', $boardId, $board['name'], null);
        
        $stmt = $this->db->prepare("DELETE FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Close a board
     */
    public function closeBoard(string $email, int $boardId): ?array
    {
        if (!$this->hasAccess($email, $boardId, 'editor')) {
            return null;
        }

        $this->addColumnIfNotExists('webmail_boards', 'is_closed', 'TINYINT(1) DEFAULT 0');
        $this->addColumnIfNotExists('webmail_boards', 'closed_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfNotExists('webmail_boards', 'closed_by', 'VARCHAR(255) DEFAULT NULL');

        $stmt = $this->db->prepare("
            UPDATE webmail_boards SET is_closed = 1, closed_at = NOW(), closed_by = ? WHERE id = ?
        ");
        $stmt->execute([strtolower($email), $boardId]);

        $board = $this->getBoard($email, $boardId);
        $this->logActivity($email, 'board_closed', 'board', $boardId, $board['name'] ?? '', $boardId);

        // Fire CRM automation hook
        $this->fireAutomationBoardClosed($boardId, $board['name'] ?? '', $email);

        return $board;
    }

    /**
     * Reopen a closed board
     */
    public function reopenBoard(string $email, int $boardId): ?array
    {
        if (!$this->hasAccess($email, $boardId, 'editor')) {
            return null;
        }

        $stmt = $this->db->prepare("
            UPDATE webmail_boards SET is_closed = 0, closed_at = NULL, closed_by = NULL WHERE id = ?
        ");
        $stmt->execute([$boardId]);

        $board = $this->getBoard($email, $boardId);
        $this->logActivity($email, 'board_reopened', 'board', $boardId, $board['name'] ?? '', $boardId);

        return $board;
    }

    // ========================================
    // MEMBER METHODS
    // ========================================
    
    /**
     * Get board members
     */
    public function getMembers(int $boardId): array
    {
        // Get owner
        $stmt = $this->db->prepare("SELECT owner_email FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        $board = $stmt->fetch();
        
        $members = [];
        if ($board) {
            $members[] = [
                'email' => $board['owner_email'],
                'role' => 'owner',
                'is_owner' => true,
                'member_type' => 'internal',
                'is_guest' => false,
                'can_view_financials' => true,
                'can_view_client' => true,
                'can_view_contacts' => true,
                'can_view_emails' => true,
                'can_access_drive' => true
            ];
        }
        
        // Get other members with all permission columns
        try {
            $stmt = $this->db->prepare("
                SELECT user_email as email, role, member_type, invited_by, created_at, 
                       can_view_financials, can_view_client, can_view_contacts, 
                       can_view_emails, can_access_drive
                FROM webmail_board_members
                WHERE board_id = ?
                ORDER BY created_at
            ");
            $stmt->execute([$boardId]);
            
            foreach ($stmt->fetchAll() as $member) {
                $member['is_owner'] = false;
                $member['member_type'] = $member['member_type'] ?? 'internal';
                $member['is_guest'] = ($member['member_type'] === 'guest');
                $member['can_view_financials'] = (bool)($member['can_view_financials'] ?? false);
                $member['can_view_client'] = (bool)($member['can_view_client'] ?? false);
                $member['can_view_contacts'] = (bool)($member['can_view_contacts'] ?? false);
                $member['can_view_emails'] = (bool)($member['can_view_emails'] ?? false);
                $member['can_access_drive'] = (bool)($member['can_access_drive'] ?? false);
                $members[] = $member;
            }
        } catch (\PDOException $e) {
            // Fallback without new columns (for backward compatibility)
            $stmt = $this->db->prepare("
                SELECT user_email as email, role, invited_by, created_at
                FROM webmail_board_members
                WHERE board_id = ?
                ORDER BY created_at
            ");
            $stmt->execute([$boardId]);
            
            foreach ($stmt->fetchAll() as $member) {
                $member['is_owner'] = false;
                $member['member_type'] = 'internal';
                $member['is_guest'] = false;
                $member['can_view_financials'] = false;
                $member['can_view_client'] = false;
                $member['can_view_contacts'] = false;
                $member['can_view_emails'] = false;
                $member['can_access_drive'] = false;
                $members[] = $member;
            }
        }
        
        return $members;
    }
    
    /**
     * Add a member to a board
     * @param array $permissions Optional permissions: can_view_financials, can_view_client, can_view_contacts, can_view_emails, can_access_drive
     */
    public function addMember(string $email, int $boardId, string $memberEmail, string $role = 'editor', array $permissions = []): bool
    {
        if (!$this->hasAccess($email, $boardId, 'owner')) {
            return false;
        }
        
        $memberEmail = strtolower($memberEmail);
        $inviterEmail = strtolower($email);
        
        // Can't add owner as member
        $stmt = $this->db->prepare("SELECT owner_email, name FROM webmail_boards WHERE id = ?");
        $stmt->execute([$boardId]);
        $board = $stmt->fetch();
        
        if (!$board || strtolower($board['owner_email']) === $memberEmail) {
            return false;
        }
        
        try {
            // Detect guest: different domain from board owner
            $ownerDomain = $this->getDomainFromEmail($board['owner_email']);
            $memberDomain = $this->getDomainFromEmail($memberEmail);
            $isGuest = ($ownerDomain !== $memberDomain);
            $memberType = $isGuest ? 'guest' : 'internal';

            // Check if already a member
            $stmt = $this->db->prepare("SELECT id FROM webmail_board_members WHERE board_id = ? AND user_email = ?");
            $stmt->execute([$boardId, $memberEmail]);
            $existing = $stmt->fetch();
            
            // Guests get restrictive defaults unless explicitly overridden
            if ($isGuest) {
                $canViewFinancials = (int)($permissions['can_view_financials'] ?? false);
                $canViewClient = 0;
                $canViewContacts = 0;
                $canViewEmails = 0;
                $canAccessDrive = (int)($permissions['can_access_drive'] ?? false);
            } else {
                $canViewFinancials = (int)($permissions['can_view_financials'] ?? false);
                $canViewClient = (int)($permissions['can_view_client'] ?? false);
                $canViewContacts = (int)($permissions['can_view_contacts'] ?? false);
                $canViewEmails = (int)($permissions['can_view_emails'] ?? false);
                $canAccessDrive = (int)($permissions['can_access_drive'] ?? false);
            }
            $driveFolderId = $permissions['drive_folder_id'] ?? null;
            $drivePermission = $permissions['drive_permission'] ?? 'viewer';
            
            // If drive access is enabled and a folder is specified, add collaborator
            if ($canAccessDrive && $driveFolderId) {
                $driveService = $this->getDriveService();
                if ($driveService) {
                    $driveService->addFolderCollaborator(
                        $board['owner_email'],
                        (int)$driveFolderId,
                        $memberEmail,
                        $drivePermission
                    );
                }
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO webmail_board_members (board_id, user_email, role, invited_by, member_type, can_view_financials, can_view_client, can_view_contacts, can_view_emails, can_access_drive, drive_folder_id, drive_permission)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role), member_type = VALUES(member_type), can_view_financials = VALUES(can_view_financials), 
                    can_view_client = VALUES(can_view_client), can_view_contacts = VALUES(can_view_contacts),
                    can_view_emails = VALUES(can_view_emails), can_access_drive = VALUES(can_access_drive),
                    drive_folder_id = VALUES(drive_folder_id), drive_permission = VALUES(drive_permission)
            ");
            $stmt->execute([$boardId, $memberEmail, $role, $inviterEmail, $memberType, $canViewFinancials, $canViewClient, $canViewContacts, $canViewEmails, $canAccessDrive, $driveFolderId, $drivePermission]);
            
            // Log activity
            $this->logActivity($inviterEmail, $existing ? 'member_updated' : 'board_shared', 'member', null, $memberEmail, $boardId, null, [
                'member_email' => $memberEmail,
                'role' => $role,
                'permissions' => $permissions
            ]);
            
            // Send notification to the new member (only if this is a new invite)
            if (!$existing) {
                try {
                    $tracking = $this->getTrackingService();
                    $roleLabel = $role === 'editor' ? 'edit' : 'view';
                    $notifData = [
                        'board_id' => $boardId,
                        'board_name' => $board['name'],
                        'invited_by' => $inviterEmail,
                        'role' => $role
                    ];
                    $notifMessage = "{$inviterEmail} invited you to collaborate on \"{$board['name']}\" (can {$roleLabel})";
                    $notifId = $tracking->createNotification(
                        $memberEmail,
                        'board_invite',
                        'Board Invitation',
                        $notifMessage,
                        $notifData
                    );

                    if ($notifId) {
                        $this->pushRealtimeNotification($memberEmail, $notifId, 'board_invite', 'Board Invitation', $notifMessage, $notifData);
                    }
                } catch (\Throwable $e) {
                    error_log("BoardService notification error: " . $e->getMessage());
                }

                // Fire automation event
                $this->fireAutomationEvent('trigger.board.shared', [
                    'board_id' => $boardId,
                    'board_name' => $board['name'],
                    'member_email' => $memberEmail,
                    'invited_by' => $inviterEmail,
                    'role' => $role,
                    'user_email' => $memberEmail,
                ]);

                // Auto-create Project Hub space/folder structure for the new member
                try {
                    $this->getProjectHubService()->ensureMemberHubStructure($memberEmail, $boardId);
                } catch (\Throwable $e) {
                    error_log("BoardService: hub structure mirror failed for {$memberEmail}: " . $e->getMessage());
                }
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("BoardService addMember PDO error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("BoardService addMember error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Remove a member from a board
     */
    public function removeMember(string $email, int $boardId, string $memberEmail): bool
    {
        if (!$this->hasAccess($email, $boardId, 'owner')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_board_members WHERE board_id = ? AND user_email = ?");
        $stmt->execute([$boardId, strtolower($memberEmail)]);
        
        $removed = $stmt->rowCount() > 0;
        
        if ($removed) {
            $this->logActivity($email, 'board_unshared', 'member', null, $memberEmail, $boardId, null, [
                'member_email' => $memberEmail
            ]);
        }
        
        return $removed;
    }
    
    /**
     * Update member role
     */
    public function updateMemberRole(string $email, int $boardId, string $memberEmail, string $role): bool
    {
        if (!$this->hasAccess($email, $boardId, 'owner')) {
            return false;
        }
        
        $stmt = $this->db->prepare("UPDATE webmail_board_members SET role = ? WHERE board_id = ? AND user_email = ?");
        $stmt->execute([$role, $boardId, strtolower($memberEmail)]);
        
        $updated = $stmt->rowCount() > 0;
        
        if ($updated) {
            $this->logActivity($email, 'member_role_changed', 'member', null, $memberEmail, $boardId, null, [
                'member_email' => $memberEmail,
                'new_role' => $role
            ]);
        }
        
        return $updated;
    }
    
    /**
     * Update all permissions for a member
     */
    public function updateMemberPermissions(string $email, int $boardId, string $memberEmail, array $permissions): bool
    {
        if (!$this->hasAccess($email, $boardId, 'owner')) {
            return false;
        }
        
        try {
            $fields = [];
            $values = [];
            
            $allowedPermissions = ['can_view_financials', 'can_view_client', 'can_view_contacts', 'can_view_emails', 'can_access_drive'];
            foreach ($allowedPermissions as $perm) {
                if (array_key_exists($perm, $permissions)) {
                    $fields[] = "$perm = ?";
                    $values[] = $permissions[$perm] ? 1 : 0;
                }
            }
            
            if (empty($fields)) {
                return true;
            }
            
            // Also update role if provided
            if (isset($permissions['role'])) {
                $fields[] = "role = ?";
                $values[] = $permissions['role'];
            }
            
            $values[] = $boardId;
            $values[] = strtolower($memberEmail);
            
            $stmt = $this->db->prepare("UPDATE webmail_board_members SET " . implode(', ', $fields) . " WHERE board_id = ? AND user_email = ?");
            $stmt->execute($values);
            
            $updated = $stmt->rowCount() > 0;
            
            if ($updated) {
                $this->logActivity($email, 'member_permissions_changed', 'member', null, $memberEmail, $boardId, null, [
                    'member_email' => $memberEmail,
                    'permissions' => $permissions
                ]);
            }
            
            return true; // Return true even if no rows changed (permissions were already set)
        } catch (\PDOException $e) {
            error_log("BoardService updateMemberPermissions PDO error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("BoardService updateMemberPermissions error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    // ========================================
    // LIST METHODS
    // ========================================
    
    /**
     * Get all lists for a board
     */
    public function getLists(int $boardId, bool $includeArchived = false, ?string $filterAssignee = null): array
    {
        $sql = "SELECT * FROM webmail_board_lists WHERE board_id = ?";
        if (!$includeArchived) {
            $sql .= " AND archived = 0";
        }
        $sql .= " ORDER BY position ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$boardId]);
        $lists = $stmt->fetchAll();
        
        // Get cards for each list
        foreach ($lists as &$list) {
            $list['archived'] = (bool)$list['archived'];
            $list['is_milestone'] = (bool)($list['is_milestone'] ?? false);
            $list['expected_amount'] = $list['expected_amount'] !== null ? (float)$list['expected_amount'] : null;
            $list['currency'] = $list['currency'] ?? 'HUF';
            $list['cards'] = $this->getCards($list['id'], false, $filterAssignee);
        }
        
        return $lists;
    }
    
    /**
     * Get financial summary for a board (milestones with amounts)
     */
    public function getBoardFinancials(string $email, int $boardId, ?int $paymentTermsDays = null): array
    {
        if (!$this->hasAccess($email, $boardId)) {
            return [];
        }
        
        // Get board for payment terms override
        $board = $this->getBoard($email, $boardId);
        if (!$board) {
            return [];
        }
        
        // Use board's payment terms if set, otherwise use provided or default 30
        $effectivePaymentTerms = $board['payment_terms_days'] ?? $paymentTermsDays ?? 30;
        
        $milestones = [];
        $totalsByCurrency = [
            'HUF' => 0,
            'EUR' => 0,
            'USD' => 0,
            'RON' => 0
        ];
        
        // Collect billing lists and prefetch progress in ONE batch instead
        // of one query-pair per list.
        $billingLists = array_values(array_filter(
            $board['lists'] ?? [],
            fn($l) => !empty($l['expected_amount']) && (float)$l['expected_amount'] > 0
        ));
        $billingListIds = array_map(fn($l) => (int)$l['id'], $billingLists);
        $progressMap = $this->getMilestoneProgressBatch($billingListIds);

        foreach ($billingLists as $list) {
            if ($list['expected_amount'] && $list['expected_amount'] > 0) {
                $invoiceDate = $list['invoice_date'] ?? null;
                $paymentDate = null;
                $currency = $list['currency'] ?? 'HUF';

                if ($invoiceDate) {
                    $paymentDate = date('Y-m-d', strtotime($invoiceDate . " + {$effectivePaymentTerms} days"));
                }

                $progress = $progressMap[(int)$list['id']] ?? [
                    'progress_percent' => 0,
                    'total_cards' => 0,
                    'completed_cards' => 0,
                    'total_todos' => 0,
                    'completed_todos' => 0,
                ];

                $milestones[] = [
                    'list_id' => $list['id'],
                    'list_name' => $list['name'],
                    'expected_amount' => (float)$list['expected_amount'],
                    'currency' => $currency,
                    'invoice_date' => $invoiceDate,
                    'payment_date' => $paymentDate,
                    'completion_percent' => $progress['progress_percent'],
                    'total_cards' => $progress['total_cards'],
                    'completed_cards' => $progress['completed_cards'],
                    'total_todos' => $progress['total_todos'],
                    'completed_todos' => $progress['completed_todos'],
                    'is_milestone' => (bool)($list['is_milestone'] ?? false),
                    'payment_status' => $list['payment_status'] ?? 'unpaid',
                ];
                
                $totalsByCurrency[$currency] = ($totalsByCurrency[$currency] ?? 0) + (float)$list['expected_amount'];
            }
        }
        
        // Filter out zero totals
        $totalsByCurrency = array_filter($totalsByCurrency, fn($amount) => $amount > 0);
        
        return [
            'board_id' => $boardId,
            'board_name' => $board['name'],
            'payment_terms_days' => $effectivePaymentTerms,
            'milestones' => $milestones,
            'totals_by_currency' => $totalsByCurrency,
        ];
    }
    
    /**
     * Get all financials across all boards for a user (Global Financial Overview)
     */
    public function getAllFinancials(string $email, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $email = strtolower($email);
        
        // Get all boards this user has access to (owned + shared with financial view permission)
        // Try different queries with fallbacks for missing columns
        $boards = [];
        
        try {
            // First try with client_id join and can_view_financials (case-insensitive)
            $stmt = $this->db->prepare("
                SELECT DISTINCT b.*, c.display_name as client_name, c.id as client_id_from_join
                FROM webmail_boards b
                LEFT JOIN clients c ON b.client_id = c.id
                WHERE (LOWER(b.owner_email) = LOWER(?) OR 
                       EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = b.id AND LOWER(bm.user_email) = LOWER(?) AND COALESCE(bm.can_view_financials, 0) = 1))
                AND b.archived = 0
                ORDER BY b.name ASC
            ");
            $stmt->execute([$email, $email]);
            $boards = $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Fallback: try without client_id join
            error_log("getAllFinancials fallback 1 due to: " . $e->getMessage());
            try {
                $stmt = $this->db->prepare("
                    SELECT DISTINCT b.*, NULL as client_name, NULL as client_id_from_join
                    FROM webmail_boards b
                    WHERE (LOWER(b.owner_email) = LOWER(?) OR 
                           EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = b.id AND LOWER(bm.user_email) = LOWER(?)))
                    AND b.archived = 0
                    ORDER BY b.name ASC
                ");
                $stmt->execute([$email, $email]);
                $boards = $stmt->fetchAll();
            } catch (\PDOException $e2) {
                // Final fallback: just owned boards
                error_log("getAllFinancials fallback 2 due to: " . $e2->getMessage());
                $stmt = $this->db->prepare("
                    SELECT b.*, NULL as client_name, NULL as client_id_from_join
                    FROM webmail_boards b
                    WHERE LOWER(b.owner_email) = LOWER(?)
                    AND b.archived = 0
                    ORDER BY b.name ASC
                ");
                $stmt->execute([$email]);
                $boards = $stmt->fetchAll();
            }
        }
        
        $allMilestones = [];
        $totalsByCurrency = [
            'HUF' => 0,
            'EUR' => 0,
            'USD' => 0,
            'RON' => 0
        ];
        $paidTotalsByCurrency = [];
        
        // -------------------------------------------------------------
        // ONE batched query for ALL lists across ALL accessible boards.
        // Replaces N "lists per board" queries; the previous version
        // also re-fired 4 correlated subqueries per list. Below uses
        // LEFT JOINs + GROUP BY so each list's totals are produced in a
        // single pass.
        // -------------------------------------------------------------
        $listsByBoard = [];
        if (!empty($boards)) {
            $boardIds = array_map(fn($b) => (int)$b['id'], $boards);
            $bph = implode(',', array_fill(0, count($boardIds), '?'));
            $sql = "
                SELECT l.*,
                       COALESCE(card_stats.total_cards, 0)      AS total_cards,
                       COALESCE(card_stats.completed_cards, 0)  AS completed_cards,
                       COALESCE(todo_stats.total_todos, 0)      AS total_todos,
                       COALESCE(todo_stats.completed_todos, 0)  AS completed_todos
                FROM webmail_board_lists l
                LEFT JOIN (
                    SELECT list_id,
                           COUNT(*) AS total_cards,
                           SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_cards
                    FROM webmail_board_cards
                    WHERE archived = 0
                    GROUP BY list_id
                ) AS card_stats ON card_stats.list_id = l.id
                LEFT JOIN (
                    SELECT c.list_id,
                           COUNT(ci.id) AS total_todos,
                           SUM(CASE WHEN ci.completed = 1 THEN 1 ELSE 0 END) AS completed_todos
                    FROM webmail_checklist_items ci
                    JOIN webmail_card_checklists cl ON ci.checklist_id = cl.id
                    JOIN webmail_board_cards c ON cl.card_id = c.id
                    WHERE c.archived = 0
                    GROUP BY c.list_id
                ) AS todo_stats ON todo_stats.list_id = l.id
                WHERE l.board_id IN ({$bph})
                  AND l.archived = 0
                  AND l.expected_amount IS NOT NULL
                  AND l.expected_amount > 0
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($boardIds);
            foreach ($stmt->fetchAll() as $row) {
                $listsByBoard[(int)$row['board_id']][] = $row;
            }
        }

        // ONE batched lookup for client payment_terms_days, vs one query
        // per board with an empty payment_terms_days + client_id.
        $clientTermsMap = [];
        $clientIdsNeedingTerms = [];
        foreach ($boards as $b) {
            if (empty($b['payment_terms_days']) && !empty($b['client_id'])) {
                $clientIdsNeedingTerms[] = (int)$b['client_id'];
            }
        }
        $clientIdsNeedingTerms = array_values(array_unique($clientIdsNeedingTerms));
        if (!empty($clientIdsNeedingTerms)) {
            $cph = implode(',', array_fill(0, count($clientIdsNeedingTerms), '?'));
            $cStmt = $this->db->prepare(
                "SELECT id, payment_terms_days FROM clients WHERE id IN ({$cph})"
            );
            $cStmt->execute($clientIdsNeedingTerms);
            foreach ($cStmt->fetchAll() as $row) {
                $clientTermsMap[(int)$row['id']] = (int)($row['payment_terms_days'] ?? 30);
            }
        }

        foreach ($boards as $board) {
            $lists = $listsByBoard[(int)$board['id']] ?? [];
            if (empty($lists)) continue;

            $paymentTerms = $board['payment_terms_days'] ?? null;
            if (!$paymentTerms && !empty($board['client_id'])) {
                $paymentTerms = $clientTermsMap[(int)$board['client_id']] ?? 30;
            }
            if (!$paymentTerms) {
                $paymentTerms = 30;
            }

            foreach ($lists as $list) {
                $invoiceDate = $list['invoice_date'] ?? null;
                
                // Apply date filter
                if ($dateFrom && $invoiceDate && $invoiceDate < $dateFrom) continue;
                if ($dateTo && $invoiceDate && $invoiceDate > $dateTo) continue;
                
                $paymentDate = null;
                if ($invoiceDate) {
                    $paymentDate = date('Y-m-d', strtotime($invoiceDate . " + {$paymentTerms} days"));
                }
                
                // Calculate progress
                $totalCards = (int)$list['total_cards'];
                $completedCards = (int)$list['completed_cards'];
                $totalTodos = (int)$list['total_todos'];
                $completedTodos = (int)$list['completed_todos'];
                
                // Progress based on todos if available, otherwise cards
                $progressPercent = 0;
                if ($totalTodos > 0) {
                    $progressPercent = round(($completedTodos / $totalTodos) * 100);
                } elseif ($totalCards > 0) {
                    $progressPercent = round(($completedCards / $totalCards) * 100);
                }
                
                $currency = $list['currency'] ?? 'HUF';
                
                $paymentStatus = $list['payment_status'] ?? 'unpaid';
                
                $allMilestones[] = [
                    'id' => $list['id'],
                    'name' => $list['name'],
                    'board_id' => $board['id'],
                    'board_name' => $board['name'],
                    'client_id' => $board['client_id_from_join'] ?? $board['client_id'] ?? null,
                    'client_name' => $board['client_name'] ?? 'No Client',
                    'expected_amount' => (float)$list['expected_amount'],
                    'currency' => $currency,
                    'invoice_date' => $invoiceDate,
                    'payment_date' => $paymentDate,
                    'progress_percent' => $progressPercent,
                    'total_cards' => $totalCards,
                    'completed_cards' => $completedCards,
                    'total_todos' => $totalTodos,
                    'completed_todos' => $completedTodos,
                    'is_milestone' => (bool)($list['is_milestone'] ?? false),
                    'payment_status' => $paymentStatus,
                ];
                
                $totalsByCurrency[$currency] = ($totalsByCurrency[$currency] ?? 0) + (float)$list['expected_amount'];
                
                if ($paymentStatus === 'paid') {
                    $paidTotalsByCurrency[$currency] = ($paidTotalsByCurrency[$currency] ?? 0) + (float)$list['expected_amount'];
                }
            }
        }
        
        // Sort by invoice date
        usort($allMilestones, function($a, $b) {
            if (!$a['invoice_date'] && !$b['invoice_date']) return 0;
            if (!$a['invoice_date']) return 1;
            if (!$b['invoice_date']) return -1;
            return strcmp($a['invoice_date'], $b['invoice_date']);
        });
        
        // Group by month
        $byMonth = [];
        foreach ($allMilestones as $milestone) {
            $monthKey = $milestone['invoice_date'] 
                ? substr($milestone['invoice_date'], 0, 7) 
                : 'unscheduled';
            
            if (!isset($byMonth[$monthKey])) {
                $byMonth[$monthKey] = [
                    'month' => $monthKey,
                    'label' => $monthKey === 'unscheduled' 
                        ? 'Unscheduled' 
                        : date('F Y', strtotime($milestone['invoice_date'])),
                    'milestones' => [],
                    'totals' => []
                ];
            }
            
            $byMonth[$monthKey]['milestones'][] = $milestone;
            $currency = $milestone['currency'];
            $byMonth[$monthKey]['totals'][$currency] = 
                ($byMonth[$monthKey]['totals'][$currency] ?? 0) + $milestone['expected_amount'];
        }
        
        // Filter out zero totals
        $totalsByCurrency = array_filter($totalsByCurrency, fn($amount) => $amount > 0);
        $paidTotalsByCurrency = array_filter($paidTotalsByCurrency, fn($amount) => $amount > 0);

        // =====================================================================
        // Board Pro card-level estimates (if boardpro_card_financials table exists)
        // =====================================================================
        $cardEstimates = [];
        $estimateTotals = [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    b.id AS board_id,
                    b.name AS board_name,
                    c.display_name AS client_name,
                    b.client_id,
                    cf.currency,
                    SUM(cf.estimated_revenue) AS total_revenue,
                    SUM(cf.estimated_cost) AS total_cost,
                    COUNT(cf.id) AS card_count,
                    SUM(CASE WHEN cf.invoice_status = 'paid' THEN cf.estimated_revenue ELSE 0 END) AS paid_revenue,
                    SUM(CASE WHEN cf.invoice_status IN ('draft','sent','overdue') THEN cf.estimated_revenue ELSE 0 END) AS unpaid_revenue
                FROM boardpro_card_financials cf
                JOIN webmail_board_cards bc ON bc.id = cf.card_id AND bc.archived = 0
                JOIN webmail_board_lists bl ON bl.id = bc.list_id AND bl.archived = 0
                JOIN webmail_boards b ON b.id = bl.board_id
                LEFT JOIN webmail_board_members bm ON bm.board_id = b.id AND LOWER(bm.user_email) = LOWER(?)
                LEFT JOIN clients c ON c.id = b.client_id
                WHERE (LOWER(b.owner_email) = LOWER(?) OR bm.user_email IS NOT NULL)
                  AND b.archived = 0
                GROUP BY b.id, b.name, c.display_name, b.client_id, cf.currency
                ORDER BY b.name ASC
            ");
            $stmt->execute([$email, $email]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $boardId = (int) $row['board_id'];
                $cur = $row['currency'] ?? 'HUF';
                if (!isset($cardEstimates[$boardId])) {
                    $cardEstimates[$boardId] = [
                        'board_id' => $boardId,
                        'board_name' => $row['board_name'],
                        'client_name' => $row['client_name'] ?? 'No Client',
                        'client_id' => $row['client_id'] ? (int) $row['client_id'] : null,
                        'currencies' => [],
                    ];
                }
                $rev = (float) $row['total_revenue'];
                $cost = (float) $row['total_cost'];
                $cardEstimates[$boardId]['currencies'][$cur] = [
                    'revenue' => $rev,
                    'cost' => $cost,
                    'margin' => $rev - $cost,
                    'card_count' => (int) $row['card_count'],
                    'paid_revenue' => (float) $row['paid_revenue'],
                    'unpaid_revenue' => (float) $row['unpaid_revenue'],
                ];
                $estimateTotals[$cur] = ($estimateTotals[$cur] ?? 0) + $rev;
            }
        } catch (\PDOException $e) {
            // boardpro_card_financials table may not exist — that's fine
        }

        // =====================================================================
        // CRM invoice summary (if crm_invoices table exists)
        // =====================================================================
        $invoiceSummary = [];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    currency,
                    COUNT(*) AS total_invoices,
                    SUM(total) AS total_invoiced,
                    SUM(paid_amount) AS total_paid,
                    SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) AS paid_total,
                    SUM(CASE WHEN status = 'overdue' THEN total - COALESCE(paid_amount, 0) ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count
                FROM crm_invoices
                WHERE LOWER(user_email) = LOWER(?)
                GROUP BY currency
            ");
            $stmt->execute([$email]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $cur = $row['currency'] ?? 'HUF';
                $invoiceSummary[$cur] = [
                    'total_invoices' => (int) $row['total_invoices'],
                    'total_invoiced' => (float) $row['total_invoiced'],
                    'total_paid' => (float) $row['total_paid'],
                    'paid_total' => (float) $row['paid_total'],
                    'overdue_total' => (float) $row['overdue_total'],
                    'draft_count' => (int) $row['draft_count'],
                    'sent_count' => (int) $row['sent_count'],
                    'paid_count' => (int) $row['paid_count'],
                    'overdue_count' => (int) $row['overdue_count'],
                ];
            }
        } catch (\PDOException $e) {
            // crm_invoices may not exist — that's fine
        }

        return [
            'milestones' => $allMilestones,
            'by_month' => array_values($byMonth),
            'totals_by_currency' => $totalsByCurrency,
            'paid_totals_by_currency' => $paidTotalsByCurrency,
            'card_estimates' => array_values($cardEstimates),
            'card_estimate_totals' => $estimateTotals,
            'invoice_summary' => $invoiceSummary,
        ];
    }
    
    /**
     * Update member financial permission - with error handling for missing column
     */
    public function updateMemberFinancialPermission(string $email, int $boardId, string $memberEmail, bool $canViewFinancials): bool
    {
        try {
            // Only owner can change financial permissions
            $board = $this->getBoard($email, $boardId);
            if (!$board || strtolower($board['owner_email']) !== strtolower($email)) {
                return false;
            }
            
            $stmt = $this->db->prepare("
                UPDATE webmail_board_members 
                SET can_view_financials = ? 
                WHERE board_id = ? AND user_email = ?
            ");
            $stmt->execute([$canViewFinancials ? 1 : 0, $boardId, strtolower($memberEmail)]);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("updateMemberFinancialPermission error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user can view financials for a board
     */
    public function canViewFinancials(string $email, int $boardId): bool
    {
        try {
            $email = strtolower($email);
            
            // Check if owner
            $stmt = $this->db->prepare("SELECT owner_email FROM webmail_boards WHERE id = ?");
            $stmt->execute([$boardId]);
            $board = $stmt->fetch();
            
            if ($board && strtolower($board['owner_email']) === $email) {
                return true;
            }
            
            // Check member permission
            $stmt = $this->db->prepare("
                SELECT can_view_financials FROM webmail_board_members 
                WHERE board_id = ? AND user_email = ?
            ");
            $stmt->execute([$boardId, $email]);
            $member = $stmt->fetch();
            
            return $member && $member['can_view_financials'];
        } catch (\PDOException $e) {
            // If column doesn't exist, owner can view but members cannot
            error_log("canViewFinancials error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get milestone progress (todos across all cards in a list)
     */
    public function getMilestoneProgress(int $listId): array
    {
        // Get card completion
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_cards,
                SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_cards
            FROM webmail_board_cards 
            WHERE list_id = ? AND archived = 0
        ");
        $stmt->execute([$listId]);
        $cardStats = $stmt->fetch();
        
        // Get todo completion across all cards in this list
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_todos,
                SUM(CASE WHEN ci.completed = 1 THEN 1 ELSE 0 END) as completed_todos
            FROM webmail_checklist_items ci
            JOIN webmail_card_checklists cl ON ci.checklist_id = cl.id
            JOIN webmail_board_cards c ON cl.card_id = c.id
            WHERE c.list_id = ? AND c.archived = 0
        ");
        $stmt->execute([$listId]);
        $todoStats = $stmt->fetch();
        
        $totalCards = (int)($cardStats['total_cards'] ?? 0);
        $completedCards = (int)($cardStats['completed_cards'] ?? 0);
        $totalTodos = (int)($todoStats['total_todos'] ?? 0);
        $completedTodos = (int)($todoStats['completed_todos'] ?? 0);
        
        // Calculate progress - prioritize todos, fall back to cards
        $progressPercent = 0;
        if ($totalTodos > 0) {
            $progressPercent = round(($completedTodos / $totalTodos) * 100);
        } elseif ($totalCards > 0) {
            $progressPercent = round(($completedCards / $totalCards) * 100);
        }
        
        return [
            'total_cards' => $totalCards,
            'completed_cards' => $completedCards,
            'total_todos' => $totalTodos,
            'completed_todos' => $completedTodos,
            'progress_percent' => $progressPercent,
            'ready_to_invoice' => $progressPercent >= 100
        ];
    }

    /**
     * Get milestone progress for many lists at once.
     * Two batched queries instead of 2N queries.
     * Returns map of list_id => progress array (same shape as getMilestoneProgress).
     */
    public function getMilestoneProgressBatch(array $listIds): array
    {
        $listIds = array_values(array_unique(array_map('intval', $listIds)));
        if (empty($listIds)) return [];

        $ph = implode(',', array_fill(0, count($listIds), '?'));

        $cardStmt = $this->db->prepare("
            SELECT list_id,
                   COUNT(*) AS total_cards,
                   SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) AS completed_cards
            FROM webmail_board_cards
            WHERE list_id IN ({$ph}) AND archived = 0
            GROUP BY list_id
        ");
        $cardStmt->execute($listIds);
        $cardRows = [];
        foreach ($cardStmt->fetchAll() as $row) {
            $cardRows[(int)$row['list_id']] = $row;
        }

        $todoStmt = $this->db->prepare("
            SELECT c.list_id,
                   COUNT(ci.id) AS total_todos,
                   SUM(CASE WHEN ci.completed = 1 THEN 1 ELSE 0 END) AS completed_todos
            FROM webmail_checklist_items ci
            JOIN webmail_card_checklists cl ON ci.checklist_id = cl.id
            JOIN webmail_board_cards c ON cl.card_id = c.id
            WHERE c.list_id IN ({$ph}) AND c.archived = 0
            GROUP BY c.list_id
        ");
        $todoStmt->execute($listIds);
        $todoRows = [];
        foreach ($todoStmt->fetchAll() as $row) {
            $todoRows[(int)$row['list_id']] = $row;
        }

        $result = [];
        foreach ($listIds as $lid) {
            $totalCards     = (int)($cardRows[$lid]['total_cards']      ?? 0);
            $completedCards = (int)($cardRows[$lid]['completed_cards']  ?? 0);
            $totalTodos     = (int)($todoRows[$lid]['total_todos']      ?? 0);
            $completedTodos = (int)($todoRows[$lid]['completed_todos']  ?? 0);

            $progressPercent = 0;
            if ($totalTodos > 0) {
                $progressPercent = round(($completedTodos / $totalTodos) * 100);
            } elseif ($totalCards > 0) {
                $progressPercent = round(($completedCards / $totalCards) * 100);
            }

            $result[$lid] = [
                'total_cards' => $totalCards,
                'completed_cards' => $completedCards,
                'total_todos' => $totalTodos,
                'completed_todos' => $completedTodos,
                'progress_percent' => $progressPercent,
                'ready_to_invoice' => $progressPercent >= 100,
            ];
        }

        return $result;
    }

    /**
     * Create a new list
     */
    public function createList(string $email, int $boardId, array $data): ?array
    {
        if (!$this->hasAccess($email, $boardId, 'editor')) {
            return null;
        }
        
        // Get next position
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_board_lists WHERE board_id = ?");
        $stmt->execute([$boardId]);
        $nextPos = $stmt->fetch()['next_pos'];
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_board_lists (board_id, name, position, expected_amount, invoice_date, is_milestone, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $boardId,
            $data['name'] ?? 'Untitled List',
            $data['position'] ?? $nextPos,
            $data['expected_amount'] ?? null,
            $data['invoice_date'] ?? null,
            $data['is_milestone'] ?? 0,
            $data['currency'] ?? 'HUF'
        ]);
        
        $listId = (int)$this->db->lastInsertId();
        
        // Log activity
        $this->logActivity($email, 'list_created', 'list', $listId, $data['name'] ?? 'Untitled List', $boardId);
        
        return $this->getList($listId);
    }
    
    /**
     * Get a single list
     */
    public function getList(int $listId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        
        if (!$list) return null;
        
        $list['archived'] = (bool)$list['archived'];
        $list['is_milestone'] = (bool)($list['is_milestone'] ?? false);
        $list['expected_amount'] = $list['expected_amount'] !== null ? (float)$list['expected_amount'] : null;
        $list['currency'] = $list['currency'] ?? 'HUF';
        $list['cards'] = $this->getCards($listId);
        
        return $list;
    }
    
    /**
     * Update a list
     */
    public function updateList(string $email, int $listId, array $data): ?array
    {
        // Get board ID for access check
        $stmt = $this->db->prepare("SELECT board_id FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        
        if (!$list || !$this->hasAccess($email, $list['board_id'], 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'position', 'archived', 'collapsed', 'list_color', 'expected_amount', 'invoice_date', 'is_milestone', 'currency', 'payment_status'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                if ($field === 'archived' || $field === 'is_milestone' || $field === 'collapsed') {
                    $values[] = $data[$field] ? 1 : 0;
                } elseif ($field === 'expected_amount') {
                    $values[] = $data[$field] !== null && $data[$field] !== '' ? (float)$data[$field] : null;
                } elseif ($field === 'currency') {
                    $values[] = in_array($data[$field], ['HUF', 'EUR', 'USD', 'RON']) ? $data[$field] : 'HUF';
                } elseif ($field === 'payment_status') {
                    $values[] = in_array($data[$field], ['unpaid', 'paid']) ? $data[$field] : 'unpaid';
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) {
            return $this->getList($listId);
        }
        
        $values[] = $listId;
        
        $stmt = $this->db->prepare("UPDATE webmail_board_lists SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        
        return $this->getList($listId);
    }
    
    /**
     * Delete a list
     */
    public function deleteList(string $email, int $listId): bool
    {
        $stmt = $this->db->prepare("SELECT board_id, name FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        
        if (!$list || !$this->hasAccess($email, $list['board_id'], 'editor')) {
            return false;
        }
        
        // Log before deletion
        $this->logActivity($email, 'list_deleted', 'list', $listId, $list['name'], $list['board_id']);
        
        $stmt = $this->db->prepare("DELETE FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Reorder lists
     */
    public function reorderLists(string $email, int $boardId, array $listIds): bool
    {
        if (!$this->hasAccess($email, $boardId, 'editor')) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE webmail_board_lists SET position = ? WHERE id = ? AND board_id = ?");
            
            foreach ($listIds as $position => $listId) {
                $stmt->execute([$position, $listId, $boardId]);
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("BoardService reorderLists error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // CARD METHODS
    // ========================================
    
    /**
     * Get all cards for a list
     */
    public function getCards(int $listId, bool $includeArchived = false, ?string $filterAssignee = null): array
    {
        $params = [$listId];
        $sql = "SELECT * FROM webmail_board_cards WHERE list_id = ?";
        if (!$includeArchived) {
            $sql .= " AND archived = 0";
        }
        if ($filterAssignee) {
            $assignee = strtolower($filterAssignee);
            $sql .= " AND (
                LOWER(assigned_to) LIKE ?
                OR id IN (SELECT card_id FROM projecthub_card_assignees WHERE LOWER(user_email) = ?)
                OR id IN (
                    SELECT DISTINCT parent_card_id FROM webmail_board_cards
                    WHERE parent_card_id IS NOT NULL
                      AND (LOWER(assigned_to) LIKE ? OR id IN (SELECT card_id FROM projecthub_card_assignees WHERE LOWER(user_email) = ?))
                )
            )";
            $params[] = '%' . $assignee . '%';
            $params[] = $assignee;
            $params[] = '%' . $assignee . '%';
            $params[] = $assignee;
        }
        $sql .= " ORDER BY position ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $cards = $stmt->fetchAll();

        // ONE batched enrichment pass = 7 IN-clause queries regardless of N
        // cards, vs 7*N queries the per-card enrichCard path would issue.
        return $this->enrichCardsBatch($cards);
    }

    /**
     * Batched version of enrichCard for many rows. Issues a fixed
     * number of IN-clause aggregate queries (labels, checklist
     * progress, attachment count, comment count, card_assignees,
     * work-session totals, financials) and merges the results onto
     * each card. The per-card enrichCard() is preserved for the
     * single-card `getCard` path which already pays a per-card cost.
     *
     * @param array<int,array<string,mixed>> $cards
     * @return array<int,array<string,mixed>>
     */
    public function enrichCardsBatch(array $cards): array
    {
        if (empty($cards)) return [];

        $cardIds = array_values(array_unique(array_filter(array_map(
            fn($c) => isset($c['id']) ? (int)$c['id'] : 0,
            $cards
        ))));
        if (empty($cardIds)) {
            // Still normalise bool columns for caller compat.
            foreach ($cards as &$c) {
                $c['completed'] = (bool)($c['completed'] ?? false);
                $c['archived'] = (bool)($c['archived'] ?? false);
                $c['full_task_visibility'] = (bool)($c['full_task_visibility'] ?? false);
                $c['labels'] = [];
                $c['checklist_total'] = 0;
                $c['checklist_done'] = 0;
                $c['attachment_count'] = 0;
                $c['comment_count'] = 0;
                $c['card_assignees'] = [];
                $c['time_spent_seconds'] = 0;
            }
            return $cards;
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));

        // 1) Labels per card.
        $labelsByCard = array_fill_keys($cardIds, []);
        try {
            $stmt = $this->db->prepare(
                "SELECT cl.card_id, l.*
                 FROM webmail_board_labels l
                 JOIN webmail_card_labels cl ON l.id = cl.label_id
                 WHERE cl.card_id IN ({$placeholders})"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $cid = (int)$row['card_id'];
                unset($row['card_id']);
                $labelsByCard[$cid][] = $row;
            }
        } catch (\Exception $e) { /* keep empty */ }

        // 2) Checklist progress per card.
        $progressByCard = array_fill_keys($cardIds, ['total' => 0, 'done' => 0]);
        try {
            $stmt = $this->db->prepare(
                "SELECT c.card_id,
                        COUNT(ci.id) AS total,
                        SUM(ci.completed) AS done
                 FROM webmail_card_checklists c
                 LEFT JOIN webmail_checklist_items ci ON ci.checklist_id = c.id
                 WHERE c.card_id IN ({$placeholders})
                 GROUP BY c.card_id"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $cid = (int)$row['card_id'];
                $progressByCard[$cid] = [
                    'total' => (int)$row['total'],
                    'done' => (int)$row['done'],
                ];
            }
        } catch (\Exception $e) { /* keep zeros */ }

        // 3) Attachment count per card.
        $attCountByCard = array_fill_keys($cardIds, 0);
        try {
            $stmt = $this->db->prepare(
                "SELECT card_id, COUNT(*) AS c
                 FROM webmail_card_attachments
                 WHERE card_id IN ({$placeholders})
                 GROUP BY card_id"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $attCountByCard[(int)$row['card_id']] = (int)$row['c'];
            }
        } catch (\Exception $e) { /* keep zeros */ }

        // 4) Comment count per card.
        $cmtCountByCard = array_fill_keys($cardIds, 0);
        try {
            $stmt = $this->db->prepare(
                "SELECT card_id, COUNT(*) AS c
                 FROM webmail_card_comments
                 WHERE card_id IN ({$placeholders})
                 GROUP BY card_id"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $cmtCountByCard[(int)$row['card_id']] = (int)$row['c'];
            }
        } catch (\Exception $e) { /* keep zeros */ }

        // 5) Multi-assignees per card.
        $assigneesByCard = array_fill_keys($cardIds, []);
        try {
            $stmt = $this->db->prepare(
                "SELECT card_id, user_email, role, status
                 FROM projecthub_card_assignees
                 WHERE card_id IN ({$placeholders})"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $cid = (int)$row['card_id'];
                unset($row['card_id']);
                $assigneesByCard[$cid][] = $row;
            }
        } catch (\Exception $e) { /* keep empty */ }

        // 6) Work-session time totals per card.
        $timeByCard = array_fill_keys($cardIds, 0);
        try {
            $stmt = $this->db->prepare(
                "SELECT card_id, COALESCE(SUM(duration_seconds), 0) AS total
                 FROM projecthub_work_sessions
                 WHERE card_id IN ({$placeholders})
                 GROUP BY card_id"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $timeByCard[(int)$row['card_id']] = (int)$row['total'];
            }
        } catch (\Exception $e) { /* keep zeros */ }

        // 7) Optional financials (board-pro addon).
        $financialsByCard = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT card_id, estimated_revenue, estimated_cost, currency
                 FROM boardpro_card_financials
                 WHERE card_id IN ({$placeholders})"
            );
            $stmt->execute($cardIds);
            foreach ($stmt->fetchAll() as $row) {
                $financialsByCard[(int)$row['card_id']] = $row;
            }
        } catch (\Exception $e) {
            // boardpro_card_financials table may not exist if board-pro addon not active
        }

        // Stitch everything together.
        foreach ($cards as &$card) {
            $cid = (int)$card['id'];
            $card['completed'] = (bool)($card['completed'] ?? false);
            $card['archived'] = (bool)($card['archived'] ?? false);
            $card['full_task_visibility'] = (bool)($card['full_task_visibility'] ?? false);
            $card['labels'] = $labelsByCard[$cid] ?? [];
            $card['checklist_total'] = $progressByCard[$cid]['total'] ?? 0;
            $card['checklist_done'] = $progressByCard[$cid]['done'] ?? 0;
            $card['attachment_count'] = $attCountByCard[$cid] ?? 0;
            $card['comment_count'] = $cmtCountByCard[$cid] ?? 0;
            $card['card_assignees'] = $assigneesByCard[$cid] ?? [];
            $card['time_spent_seconds'] = $timeByCard[$cid] ?? 0;
            if (isset($financialsByCard[$cid])) {
                $fin = $financialsByCard[$cid];
                $card['estimated_revenue'] = $fin['estimated_revenue'] !== null ? (float)$fin['estimated_revenue'] : null;
                $card['estimated_cost'] = $fin['estimated_cost'] !== null ? (float)$fin['estimated_cost'] : null;
                $card['financial_currency'] = $fin['currency'] ?? 'HUF';
            }
        }
        unset($card);
        return $cards;
    }
    
    /**
     * Enrich card with labels, checklists, attachments, comments count
     */
    private function enrichCard(array $card): array
    {
        $card['completed'] = (bool)$card['completed'];
        $card['archived'] = (bool)$card['archived'];
        $card['full_task_visibility'] = (bool)($card['full_task_visibility'] ?? false);
        
        // Get labels
        $stmt = $this->db->prepare("
            SELECT l.* FROM webmail_board_labels l
            JOIN webmail_card_labels cl ON l.id = cl.label_id
            WHERE cl.card_id = ?
        ");
        $stmt->execute([$card['id']]);
        $card['labels'] = $stmt->fetchAll();
        
        // Get checklist progress
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(completed) as done
            FROM webmail_checklist_items ci
            JOIN webmail_card_checklists c ON ci.checklist_id = c.id
            WHERE c.card_id = ?
        ");
        $stmt->execute([$card['id']]);
        $progress = $stmt->fetch();
        $card['checklist_total'] = (int)$progress['total'];
        $card['checklist_done'] = (int)$progress['done'];
        
        // Get attachment count
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM webmail_card_attachments WHERE card_id = ?");
        $stmt->execute([$card['id']]);
        $card['attachment_count'] = (int)$stmt->fetch()['count'];
        
        // Get comment count
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM webmail_card_comments WHERE card_id = ?");
        $stmt->execute([$card['id']]);
        $card['comment_count'] = (int)$stmt->fetch()['count'];
        
        // Get multi-assignees from projecthub_card_assignees
        try {
            $stmt = $this->db->prepare("SELECT user_email, role, status FROM projecthub_card_assignees WHERE card_id = ?");
            $stmt->execute([$card['id']]);
            $card['card_assignees'] = $stmt->fetchAll();
        } catch (\Exception $e) {
            $card['card_assignees'] = [];
        }

        // Total tracked time from PH work sessions
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) AS total FROM projecthub_work_sessions WHERE card_id = ?");
            $stmt->execute([$card['id']]);
            $card['time_spent_seconds'] = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            $card['time_spent_seconds'] = 0;
        }

        // Get financial summary (revenue/cost) if board-pro table exists
        try {
            $stmt = $this->db->prepare("
                SELECT estimated_revenue, estimated_cost, currency
                FROM boardpro_card_financials WHERE card_id = ?
            ");
            $stmt->execute([$card['id']]);
            $fin = $stmt->fetch();
            if ($fin) {
                $card['estimated_revenue'] = $fin['estimated_revenue'] !== null ? (float)$fin['estimated_revenue'] : null;
                $card['estimated_cost'] = $fin['estimated_cost'] !== null ? (float)$fin['estimated_cost'] : null;
                $card['financial_currency'] = $fin['currency'] ?? 'HUF';
            }
        } catch (\Exception $e) {
            // boardpro_card_financials table may not exist if board-pro addon not active
        }
        
        return $card;
    }
    
    /**
     * Get a single card with full details
     */
    public function getCard(string $email, int $cardId): ?array
    {
        $this->log("getCard called: email=$email, cardId=$cardId");
        
        try {
            $stmt = $this->db->prepare("
                SELECT c.*, l.board_id, b.owner_email AS board_owner_email,
                       CASE WHEN LOWER(b.owner_email) = LOWER(?) THEN 'owner' ELSE COALESCE(m.role, 'viewer') END AS board_user_role
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON c.list_id = l.id
                JOIN webmail_boards b ON b.id = l.board_id
                LEFT JOIN webmail_board_members m ON m.board_id = b.id AND LOWER(m.user_email) = LOWER(?)
                WHERE c.id = ?
            ");
            $stmt->execute([$email, $email, $cardId]);
            $card = $stmt->fetch();
            
            $this->log("getCard query result: " . ($card ? "found card" : "card not found"));
            
            if (!$card) {
                $this->log("getCard: Card not found in database");
                return null;
            }
            
            if (!$this->hasAccess($email, $card['board_id'])) {
                $this->log("getCard: Access denied for email=$email to board_id={$card['board_id']}");
                return null;
            }
            
            $this->log("getCard: Enriching card...");
            $card = $this->enrichCard($card);
            
            // Get full checklists
            try {
                $this->log("getCard: Getting checklists...");
                $card['checklists'] = $this->getChecklists($cardId);
            } catch (\Exception $e) {
                $this->log("getCard checklists error: " . $e->getMessage());
                $card['checklists'] = [];
            }
            
            // Get attachments
            try {
                $this->log("getCard: Getting attachments...");
                $card['attachments'] = $this->getAttachments($cardId);
            } catch (\Exception $e) {
                $this->log("getCard attachments error: " . $e->getMessage());
                $card['attachments'] = [];
            }
            
            // Get comments
            try {
                $this->log("getCard: Getting comments...");
                $card['comments'] = $this->getComments($cardId);
            } catch (\Exception $e) {
                $this->log("getCard comments error: " . $e->getMessage());
                $card['comments'] = [];
            }
            
            // Get activity
            try {
                $this->log("getCard: Getting activity...");
                $card['activity'] = $this->getActivity($cardId, 20);
            } catch (\Exception $e) {
                $this->log("getCard activity error: " . $e->getMessage());
                $card['activity'] = [];
            }
            
            $this->log("getCard: Success");
            return $card;
        } catch (\Exception $e) {
            $this->log("getCard FATAL error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return null;
        }
    }
    
    /**
     * Create a new card
     */
    public function createCard(string $email, int $listId, array $data): ?array
    {
        // Get board ID for access check
        $stmt = $this->db->prepare("SELECT board_id FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        
        if (!$list || !$this->hasAccess($email, $list['board_id'], 'editor')) {
            return null;
        }
        
        // Get next position
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_board_cards WHERE list_id = ?");
        $stmt->execute([$listId]);
        $nextPos = $stmt->fetch()['next_pos'];
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_board_cards (list_id, title, description, position, due_date, start_date, assigned_to, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $listId,
            $data['title'] ?? 'Untitled Card',
            $data['description'] ?? null,
            $data['position'] ?? $nextPos,
            $data['due_date'] ?? null,
            $data['start_date'] ?? null,
            $data['assigned_to'] ?? null,
            strtolower($email)
        ]);
        
        $cardId = (int)$this->db->lastInsertId();
        $boardId = $list['board_id'];
        $cardTitle = $data['title'] ?? 'Untitled Card';
        
        // Log activity
        $this->logActivity($email, 'card_created', 'card', $cardId, $cardTitle, $boardId);
        
        // Create calendar event if due date set
        if (!empty($data['due_date'])) {
            $this->syncCardToCalendar($email, $cardId);
        }

        // Notify all board members (except creator)
        try {
            $boardStmt = $this->db->prepare("SELECT name FROM webmail_boards WHERE id = ?");
            $boardStmt->execute([$boardId]);
            $boardName = $boardStmt->fetchColumn() ?: 'Board';
            $listStmt = $this->db->prepare("SELECT name FROM webmail_board_lists WHERE id = ?");
            $listStmt->execute([$listId]);
            $listName = $listStmt->fetchColumn() ?: 'List';

            $creatorName = explode('@', $email)[0];
            $tracking = $this->getTrackingService();
            $recipients = $this->getBoardRecipients($boardId, $email);

            foreach ($recipients as $recipientEmail) {
                $notifData = [
                    'card_id' => $cardId,
                    'card_title' => $cardTitle,
                    'board_id' => $boardId,
                    'board_name' => $boardName,
                    'list_name' => $listName,
                    'created_by' => $email,
                ];
                $notifMessage = "{$creatorName} added \"{$cardTitle}\" to {$listName} on \"{$boardName}\"";
                $notifId = $tracking->createNotification(
                    $recipientEmail,
                    'card_created',
                    'New Card Added',
                    $notifMessage,
                    $notifData
                );
                if ($notifId) {
                    $this->pushRealtimeNotification($recipientEmail, $notifId, 'card_created', 'New Card Added', $notifMessage, $notifData);
                }
            }

            // Fire automation event
            $this->fireAutomationEvent('trigger.board.card_created', [
                'card_id' => $cardId,
                'card_title' => $cardTitle,
                'board_id' => $boardId,
                'board_name' => $boardName,
                'list_name' => $listName,
                'created_by' => $email,
                'assigned_to' => $data['assigned_to'] ?? '',
                'user_email' => $email,
            ]);
        } catch (\Throwable $e) {
            error_log("BoardService card_created notification/event error: " . $e->getMessage());
        }
        
        return $this->getCard($email, $cardId);
    }

    /**
     * Get subtasks for a card (cards with parent_card_id = given card)
     */
    public function getSubtasks(string $email, int $parentCardId): array
    {
        $parent = $this->getCard($email, $parentCardId);
        if (!$parent) return [];

        try {
            $stmt = $this->db->prepare("
                SELECT c.*, l.board_id, l.name AS list_name,
                    (SELECT COUNT(*) FROM webmail_board_cards ch WHERE ch.parent_card_id = c.id) AS child_count
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON l.id = c.list_id
                WHERE c.parent_card_id = ?
                ORDER BY c.position ASC, c.created_at ASC
            ");
            $stmt->execute([$parentCardId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            $this->log("getSubtasks error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a subtask (a card with parent_card_id set)
     */
    public function createSubtask(string $email, int $parentCardId, array $data): ?array
    {
        $parent = $this->getCard($email, $parentCardId);
        if (!$parent) return null;

        $listId = $parent['list_id'];

        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(position), -1) + 1 AS next_pos
            FROM webmail_board_cards WHERE parent_card_id = ?
        ");
        $stmt->execute([$parentCardId]);
        $nextPos = $stmt->fetch()['next_pos'];

        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_board_cards
                    (list_id, title, description, position, due_date, assigned_to, created_by, parent_card_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $listId,
                $data['title'] ?? 'New Subtask',
                $data['description'] ?? null,
                $nextPos,
                $data['due_date'] ?? null,
                $data['assigned_to'] ?? null,
                strtolower($email),
                $parentCardId,
            ]);

            $subtaskId = (int)$this->db->lastInsertId();
            $this->logActivity($email, 'subtask_created', 'card', $subtaskId, $data['title'] ?? 'New Subtask', $parent['board_id']);

            return $this->getCard($email, $subtaskId);
        } catch (\PDOException $e) {
            $this->log("createSubtask error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Batched subtask create. Single parent lookup, single position
     * MAX(), single multi-row INSERT, single getCardsByIds() hydration.
     * Replaces the N-per-paste loop in SubtasksList::handlePaste.
     *
     * @param string $email
     * @param int $parentCardId
     * @param array<int, array<string,mixed>> $rows  Each row: title (required), description, due_date, assigned_to
     * @return array{success:int, failed:int, subtasks:array<int,array>}
     */
    public function createSubtasksBatch(string $email, int $parentCardId, array $rows): array
    {
        $result = ['success' => 0, 'failed' => 0, 'subtasks' => []];

        // Sanitise: drop rows without a non-empty title.
        $rows = array_values(array_filter($rows, function ($r) {
            return is_array($r) && isset($r['title']) && trim((string)$r['title']) !== '';
        }));
        if (empty($rows)) return $result;

        $parent = $this->getCard($email, $parentCardId);
        if (!$parent) return $result;

        $listId = $parent['list_id'];
        $boardId = $parent['board_id'];
        $emailLc = strtolower($email);

        // ONE query to find next position.
        $posStmt = $this->db->prepare(
            "SELECT COALESCE(MAX(position), -1) + 1 AS next_pos
             FROM webmail_board_cards WHERE parent_card_id = ?"
        );
        $posStmt->execute([$parentCardId]);
        $nextPos = (int)($posStmt->fetch()['next_pos'] ?? 0);

        try {
            $this->db->beginTransaction();

            // Multi-row INSERT in one statement.
            $values = [];
            $params = [];
            foreach ($rows as $idx => $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                $params[] = $listId;
                $params[] = (string)$row['title'];
                $params[] = $row['description'] ?? null;
                $params[] = $nextPos + $idx;
                $params[] = $row['due_date'] ?? null;
                $params[] = $row['assigned_to'] ?? null;
                $params[] = $emailLc;
                $params[] = $parentCardId;
            }
            $sql = "INSERT INTO webmail_board_cards
                (list_id, title, description, position, due_date, assigned_to, created_by, parent_card_id)
                VALUES " . implode(',', $values);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            // lastInsertId() returns the FIRST id of the multi-row insert; subsequent ids are consecutive.
            $firstId = (int)$this->db->lastInsertId();
            $newIds = [];
            for ($i = 0; $i < count($rows); $i++) {
                $newIds[] = $firstId + $i;
            }

            $this->db->commit();

            // ONE activity-log row per created subtask is the existing
            // contract; do it outside the transaction to avoid blocking
            // the INSERT path on logging.
            foreach ($newIds as $i => $id) {
                $this->logActivity($email, 'subtask_created', 'card', $id, (string)$rows[$i]['title'], $boardId);
            }

            // Hydrate the new rows in one query.
            $placeholders = implode(',', array_fill(0, count($newIds), '?'));
            $hydrate = $this->db->prepare(
                "SELECT * FROM webmail_board_cards WHERE id IN ({$placeholders})"
            );
            $hydrate->execute($newIds);
            $idxById = array_flip($newIds);
            $hydrated = array_fill(0, count($newIds), null);
            foreach ($hydrate->fetchAll() as $row) {
                $hydrated[$idxById[(int)$row['id']]] = $row;
            }
            $result['subtasks'] = array_values(array_filter($hydrated));
            $result['success'] = count($result['subtasks']);
            $result['failed'] = count($rows) - $result['success'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->log("createSubtasksBatch error: " . $e->getMessage());
            $result['failed'] = count($rows);
        }

        return $result;
    }

    /**
     * Update a card
     */
    public function updateCard(string $email, int $cardId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, l.board_id, l.name as list_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();
        
        if (!$card || !$this->hasAccess($email, $card['board_id'], 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        $changes = [];
        
        $allowedFields = ['title', 'description', 'position', 'due_date', 'start_date', 'completed', 'cover_color', 'card_color', 'assigned_to', 'archived', 'time_estimate_seconds'];

        // Owner-only fields
        $isOwner = strtolower($card['owner_email'] ?? '') === strtolower($email);
        if (!$isOwner) {
            try {
                $boardStmt = $this->db->prepare("SELECT owner_email FROM webmail_boards WHERE id = ?");
                $boardStmt->execute([$card['board_id']]);
                $boardRow = $boardStmt->fetch();
                $isOwner = $boardRow && strtolower($boardRow['owner_email']) === strtolower($email);
            } catch (\Throwable $e) { /* ignore */ }
        }
        if ($isOwner) {
            $allowedFields[] = 'full_task_visibility';
        }

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                
                if ($field === 'completed' || $field === 'archived' || $field === 'full_task_visibility') {
                    $values[] = $data[$field] ? 1 : 0;
                } else {
                    $values[] = $data[$field];
                }
                
                // Track changes for activity log
                if (($card[$field] ?? null) !== $data[$field]) {
                    $changes[$field] = ['from' => $card[$field] ?? null, 'to' => $data[$field]];
                }
            }
        }
        
        // Handle completion timestamp
        if (array_key_exists('completed', $data)) {
            if ($data['completed'] && !$card['completed']) {
                $fields[] = 'completed_at = NOW()';
            } elseif (!$data['completed'] && $card['completed']) {
                $fields[] = 'completed_at = NULL';
            }
        }
        
        if (empty($fields)) {
            return $this->getCard($email, $cardId);
        }
        
        $values[] = $cardId;
        
        $stmt = $this->db->prepare("UPDATE webmail_board_cards SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        
        // Log activity based on what changed
        if (isset($changes['completed'])) {
            if ($data['completed']) {
                $this->logActivity($email, 'card_completed', 'card', $cardId, $card['title'], $card['board_id']);
                
                // Send task completion notification to the card creator and assignee
                try {
                    $boardStmt = $this->db->prepare("SELECT b.name as board_name FROM webmail_boards b WHERE b.id = ?");
                    $boardStmt->execute([$card['board_id']]);
                    $boardInfo = $boardStmt->fetch();
                    $boardName = $boardInfo ? $boardInfo['board_name'] : 'Board';
                    
                    $tracking = $this->getTrackingService();
                    $completedBy = explode('@', $email)[0];
                    
                    $notifData = [
                        'card_id' => $cardId,
                        'card_title' => $card['title'],
                        'board_id' => $card['board_id'],
                        'board_name' => $boardName,
                        'completed_by' => $email
                    ];
                    $notifMsg = "{$completedBy} completed \"{$card['title']}\" on \"{$boardName}\"";

                    $recipients = $this->getBoardRecipients($card['board_id'], $email);
                    foreach ($recipients as $recipientEmail) {
                        $nid = $tracking->createNotification($recipientEmail, 'task_completed', 'Task Completed', $notifMsg, $notifData);
                        if ($nid) {
                            $this->pushRealtimeNotification($recipientEmail, $nid, 'task_completed', 'Task Completed', $notifMsg, $notifData);
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("BoardService task completion notification error: " . $e->getMessage());
                }

                $this->fireAutomationEvent('trigger.board.card_completed', [
                    'card_id' => $cardId,
                    'card_title' => $card['title'],
                    'board_id' => $card['board_id'],
                    'board_name' => $boardName ?? '',
                    'list_name' => $card['list_name'] ?? '',
                    'completed_by' => $email,
                    'assigned_to' => $card['assigned_to'] ?? '',
                    'user_email' => $email,
                ]);
            } else {
                $this->logActivity($email, 'card_reopened', 'card', $cardId, $card['title'], $card['board_id']);
            }
        } elseif (isset($changes['title'])) {
            $this->logActivity($email, 'card_renamed', 'card', $cardId, $data['title'], $card['board_id'], null, [
                'old_title' => $card['title'],
                'new_title' => $data['title']
            ]);
        } elseif (!empty($changes)) {
            $this->logActivity($email, 'card_updated', 'card', $cardId, $card['title'], $card['board_id'], null, $changes);
        }
        
        // Send notification if someone was assigned
        if (isset($changes['assigned_to']) && !empty($data['assigned_to'])) {
            $assignedEmail = strtolower($data['assigned_to']);
            // Don't notify yourself
            if ($assignedEmail !== strtolower($email)) {
                try {
                    // Get board name for notification
                    $stmt = $this->db->prepare("
                        SELECT b.name as board_name 
                        FROM webmail_boards b
                        JOIN webmail_board_lists l ON b.id = l.board_id
                        JOIN webmail_board_cards c ON l.id = c.list_id
                        WHERE c.id = ?
                    ");
                    $stmt->execute([$cardId]);
                    $boardInfo = $stmt->fetch();
                    
                    $tracking = $this->getTrackingService();
                    $assignNotifData = [
                        'card_id' => $cardId,
                        'card_title' => $card['title'],
                        'assigned_by' => $email,
                        'board_id' => $card['board_id']
                    ];
                    $assignNotifMsg = "{$email} assigned you to \"{$card['title']}\" on \"{$boardInfo['board_name']}\"";
                    $assignNotifId = $tracking->createNotification(
                        $assignedEmail, 'card_assigned', 'Card Assigned to You', $assignNotifMsg, $assignNotifData
                    );
                    if ($assignNotifId) {
                        $this->pushRealtimeNotification($assignedEmail, $assignNotifId, 'card_assigned', 'Card Assigned to You', $assignNotifMsg, $assignNotifData);
                    }
                } catch (\Throwable $e) {
                    error_log("BoardService card assignment notification error: " . $e->getMessage());
                }
            }
        }
        
        // Sync calendar if due date changed
        if (array_key_exists('due_date', $data)) {
            $this->syncCardToCalendar($email, $cardId);
        }
        
        return $this->getCard($email, $cardId);
    }
    
    /**
     * Move a card to a different list
     */
    public function moveCard(string $email, int $cardId, int $newListId, ?int $position = null): ?array
    {
        // Get current card and board
        $stmt = $this->db->prepare("
            SELECT c.*, l.board_id, l.name as old_list_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();
        
        if (!$card) return null;
        
        // Get new list info
        $stmt = $this->db->prepare("SELECT board_id, name FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$newListId]);
        $newList = $stmt->fetch();
        
        if (!$newList || !$this->hasAccess($email, $newList['board_id'], 'editor')) {
            return null;
        }
        
        // Get position if not specified
        if ($position === null) {
            $stmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_board_cards WHERE list_id = ?");
            $stmt->execute([$newListId]);
            $position = $stmt->fetch()['next_pos'];
        }
        
        $stmt = $this->db->prepare("UPDATE webmail_board_cards SET list_id = ?, position = ? WHERE id = ?");
        $stmt->execute([$newListId, $position, $cardId]);
        
        // Log activity
        $this->logActivity($email, 'card_moved', 'card', $cardId, $card['title'], $card['board_id'], null, [
            'from_list' => $card['old_list_name'],
            'to_list' => $newList['name']
        ]);

        // Fire automation event
        try {
            $boardStmt = $this->db->prepare("SELECT name FROM webmail_boards WHERE id = ?");
            $boardStmt->execute([$card['board_id']]);
            $boardName = $boardStmt->fetchColumn() ?: 'Board';

            $this->fireAutomationEvent('trigger.board.card_moved', [
                'card_id' => $cardId,
                'card_title' => $card['title'],
                'board_id' => $card['board_id'],
                'board_name' => $boardName,
                'from_list' => $card['old_list_name'],
                'to_list' => $newList['name'],
                'list_name' => $newList['name'],
                'assigned_to' => $card['assigned_to'] ?? '',
                'user_email' => $email,
            ]);
        } catch (\Throwable $e) {
            error_log("BoardService card_moved automation error: " . $e->getMessage());
        }
        
        return $this->getCard($email, $cardId);
    }
    
    /**
     * Delete a card
     */
    public function deleteCard(string $email, int $cardId): bool
    {
        $stmt = $this->db->prepare("
            SELECT c.title, l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        // Log before deletion
        $this->logActivity($email, 'card_deleted', 'card', $cardId, $result['title'], $result['board_id']);
        
        $stmt = $this->db->prepare("DELETE FROM webmail_board_cards WHERE id = ?");
        $stmt->execute([$cardId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Reorder cards within a list
     */
    public function reorderCards(string $email, int $listId, array $cardIds): bool
    {
        $stmt = $this->db->prepare("SELECT board_id FROM webmail_board_lists WHERE id = ?");
        $stmt->execute([$listId]);
        $list = $stmt->fetch();
        
        if (!$list || !$this->hasAccess($email, $list['board_id'], 'editor')) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE webmail_board_cards SET position = ? WHERE id = ? AND list_id = ?");
            
            foreach ($cardIds as $position => $cardId) {
                $stmt->execute([$position, $cardId, $listId]);
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("BoardService reorderCards error: " . $e->getMessage());
            return false;
        }
    }
    
    // ========================================
    // LABEL METHODS
    // ========================================
    
    /**
     * Get labels for a board
     */
    public function getLabels(int $boardId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM webmail_board_labels WHERE board_id = ? ORDER BY name");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Create a label
     */
    public function createLabel(int $boardId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            INSERT INTO webmail_board_labels (board_id, name, color)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([
            $boardId,
            $data['name'] ?? null,
            $data['color'] ?? '#808080'
        ]);
        
        $labelId = (int)$this->db->lastInsertId();
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_board_labels WHERE id = ?");
        $stmt->execute([$labelId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Update a label
     */
    public function updateLabel(string $email, int $labelId, array $data): ?array
    {
        $stmt = $this->db->prepare("SELECT board_id FROM webmail_board_labels WHERE id = ?");
        $stmt->execute([$labelId]);
        $label = $stmt->fetch();
        
        if (!$label || !$this->hasAccess($email, $label['board_id'], 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        
        if (array_key_exists('name', $data)) {
            $fields[] = 'name = ?';
            $values[] = $data['name'];
        }
        if (array_key_exists('color', $data)) {
            $fields[] = 'color = ?';
            $values[] = $data['color'];
        }
        if (array_key_exists('is_type', $data)) {
            $fields[] = 'is_type = ?';
            $values[] = (int)$data['is_type'];
        }
        
        if (empty($fields)) {
            $stmt = $this->db->prepare("SELECT * FROM webmail_board_labels WHERE id = ?");
            $stmt->execute([$labelId]);
            return $stmt->fetch() ?: null;
        }
        
        $values[] = $labelId;
        
        $stmt = $this->db->prepare("UPDATE webmail_board_labels SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_board_labels WHERE id = ?");
        $stmt->execute([$labelId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Delete a label
     */
    public function deleteLabel(string $email, int $labelId): bool
    {
        $stmt = $this->db->prepare("SELECT board_id FROM webmail_board_labels WHERE id = ?");
        $stmt->execute([$labelId]);
        $label = $stmt->fetch();
        
        if (!$label || !$this->hasAccess($email, $label['board_id'], 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_board_labels WHERE id = ?");
        $stmt->execute([$labelId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Add label to card
     */
    public function addLabelToCard(string $email, int $cardId, int $labelId): bool
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("INSERT IGNORE INTO webmail_card_labels (card_id, label_id) VALUES (?, ?)");
            $stmt->execute([$cardId, $labelId]);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    /**
     * Remove label from card
     */
    public function removeLabelFromCard(string $email, int $cardId, int $labelId): bool
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_card_labels WHERE card_id = ? AND label_id = ?");
        $stmt->execute([$cardId, $labelId]);
        
        return $stmt->rowCount() > 0;
    }
    
    // ========================================
    // CHECKLIST METHODS
    // ========================================
    
    /**
     * Get checklists for a card
     */
    public function getChecklists(int $cardId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM webmail_card_checklists WHERE card_id = ? ORDER BY position ASC"
        );
        $stmt->execute([$cardId]);
        $checklists = $stmt->fetchAll();
        if (empty($checklists)) return [];

        // ONE batched query for ALL items across this card's checklists,
        // then pivot by checklist_id in PHP. Replaces the per-checklist
        // SELECT inside the loop.
        $checklistIds = array_map(fn($c) => (int)$c['id'], $checklists);
        $ph = implode(',', array_fill(0, count($checklistIds), '?'));
        $itemsStmt = $this->db->prepare(
            "SELECT * FROM webmail_checklist_items
             WHERE checklist_id IN ({$ph})
             ORDER BY checklist_id ASC, position ASC"
        );
        $itemsStmt->execute($checklistIds);

        $itemsByChecklist = array_fill_keys($checklistIds, []);
        foreach ($itemsStmt->fetchAll() as $item) {
            $cid = (int)$item['checklist_id'];
            $item['completed'] = (bool)$item['completed'];
            $itemsByChecklist[$cid][] = $item;
        }

        foreach ($checklists as &$checklist) {
            $checklist['items'] = $itemsByChecklist[(int)$checklist['id']] ?? [];
        }
        unset($checklist);

        return $checklists;
    }
    
    /**
     * Create a checklist
     */
    public function createChecklist(string $email, int $cardId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id, c.title as card_title
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        // Get next position
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_card_checklists WHERE card_id = ?");
        $stmt->execute([$cardId]);
        $nextPos = $stmt->fetch()['next_pos'];
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_card_checklists (card_id, title, position)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([
            $cardId,
            $data['title'] ?? 'Checklist',
            $data['position'] ?? $nextPos
        ]);
        
        $checklistId = (int)$this->db->lastInsertId();
        
        // Log activity
        $this->logActivity($email, 'checklist_created', 'checklist', $checklistId, $data['title'] ?? 'Checklist', $result['board_id'], null, [
            'card_title' => $result['card_title']
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_checklists WHERE id = ?");
        $stmt->execute([$checklistId]);
        $checklist = $stmt->fetch();
        $checklist['items'] = [];
        
        return $checklist;
    }
    
    /**
     * Update a checklist
     */
    public function updateChecklist(string $email, int $checklistId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id as card_id, l.board_id
            FROM webmail_card_checklists cl
            JOIN webmail_board_cards c ON cl.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE cl.id = ?
        ");
        $stmt->execute([$checklistId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        
        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            $values[] = $data['title'];
        }
        if (array_key_exists('position', $data)) {
            $fields[] = 'position = ?';
            $values[] = $data['position'];
        }
        
        if (!empty($fields)) {
            $values[] = $checklistId;
            $stmt = $this->db->prepare("UPDATE webmail_card_checklists SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
        }
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_checklists WHERE id = ?");
        $stmt->execute([$checklistId]);
        $checklist = $stmt->fetch();
        
        if ($checklist) {
            $stmt = $this->db->prepare("SELECT * FROM webmail_checklist_items WHERE checklist_id = ? ORDER BY position ASC");
            $stmt->execute([$checklistId]);
            $checklist['items'] = array_map(function($item) {
                $item['completed'] = (bool)$item['completed'];
                return $item;
            }, $stmt->fetchAll());
        }
        
        return $checklist;
    }
    
    /**
     * Delete a checklist
     */
    public function deleteChecklist(string $email, int $checklistId): bool
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id
            FROM webmail_card_checklists cl
            JOIN webmail_board_cards c ON cl.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE cl.id = ?
        ");
        $stmt->execute([$checklistId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_card_checklists WHERE id = ?");
        $stmt->execute([$checklistId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Add item to checklist
     */
    public function addChecklistItem(string $email, int $checklistId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id as card_id, c.title as card_title, l.board_id
            FROM webmail_card_checklists cl
            JOIN webmail_board_cards c ON cl.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE cl.id = ?
        ");
        $stmt->execute([$checklistId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        // Get next position
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(position), -1) + 1 as next_pos FROM webmail_checklist_items WHERE checklist_id = ?");
        $stmt->execute([$checklistId]);
        $nextPos = $stmt->fetch()['next_pos'];
        
        // Truncate title to prevent database errors (max 10000 characters for TEXT field)
        $title = $data['title'] ?? 'Item';
        $title = mb_substr($title, 0, 10000, 'UTF-8');
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_checklist_items (checklist_id, title, position)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([
            $checklistId,
            $title,
            $data['position'] ?? $nextPos
        ]);
        
        $itemId = (int)$this->db->lastInsertId();
        
        // Log activity
        $this->logActivity($email, 'todo_created', 'todo', $itemId, $title, $result['board_id'], null, [
            'card_title' => $result['card_title']
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        $item['completed'] = (bool)$item['completed'];
        
        return $item;
    }
    
    /**
     * Update checklist item
     */
    public function updateChecklistItem(string $email, int $itemId, array $data): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id as card_id, c.title as card_title, l.board_id, i.title as item_title, i.completed as was_completed
            FROM webmail_checklist_items i
            JOIN webmail_card_checklists cl ON i.checklist_id = cl.id
            JOIN webmail_board_cards c ON cl.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE i.id = ?
        ");
        $stmt->execute([$itemId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        $fields = [];
        $values = [];
        
        if (array_key_exists('title', $data)) {
            $fields[] = 'title = ?';
            // Truncate title to prevent database errors (max 10000 characters for TEXT field)
            $values[] = mb_substr($data['title'], 0, 10000, 'UTF-8');
        }
        if (array_key_exists('completed', $data)) {
            $fields[] = 'completed = ?';
            $values[] = $data['completed'] ? 1 : 0;
        }
        if (array_key_exists('position', $data)) {
            $fields[] = 'position = ?';
            $values[] = $data['position'];
        }
        if (array_key_exists('drive_file_id', $data)) {
            $fields[] = 'drive_file_id = ?';
            $values[] = $data['drive_file_id'];
        }
        if (array_key_exists('assigned_to', $data)) {
            $fields[] = 'assigned_to = ?';
            $values[] = $data['assigned_to'] ?: null;
        }
        
        if (!empty($fields)) {
            $values[] = $itemId;
            $stmt = $this->db->prepare("UPDATE webmail_checklist_items SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
        }
        
        // Log activity for completion changes
        if (array_key_exists('completed', $data)) {
            $wasCompleted = (bool)$result['was_completed'];
            $isCompleted = (bool)$data['completed'];
            if ($isCompleted && !$wasCompleted) {
                $this->logActivity($email, 'todo_completed', 'todo', $itemId, $result['item_title'], $result['board_id'], null, [
                    'card_title' => $result['card_title']
                ]);
            } elseif (!$isCompleted && $wasCompleted) {
                $this->logActivity($email, 'todo_uncompleted', 'todo', $itemId, $result['item_title'], $result['board_id'], null, [
                    'card_title' => $result['card_title']
                ]);
            }
        }
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        $item['completed'] = (bool)$item['completed'];
        // Include card_id for WebSocket event publishing
        $item['card_id'] = $result['card_id'];
        
        return $item;
    }
    
    /**
     * Delete checklist item
     */
    public function deleteChecklistItem(string $email, int $itemId): bool
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id, i.title, c.title as card_title
            FROM webmail_checklist_items i
            JOIN webmail_card_checklists cl ON i.checklist_id = cl.id
            JOIN webmail_board_cards c ON cl.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE i.id = ?
        ");
        $stmt->execute([$itemId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        // Log before deletion
        $this->logActivity($email, 'todo_deleted', 'todo', $itemId, $result['title'], $result['board_id'], null, [
            'card_title' => $result['card_title']
        ]);
        
        $stmt = $this->db->prepare("DELETE FROM webmail_checklist_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        return $stmt->rowCount() > 0;
    }
    
    // ========================================
    // ATTACHMENT METHODS
    // ========================================
    
    /**
     * Get attachments for a card
     */
    public function getAttachments(int $cardId): array
    {
        $hasFolderCol = $this->columnExists('webmail_card_attachments', 'folder_id');
        $folderSelect = $hasFolderCol ? ', a.folder_id AS asset_folder_id' : '';

        $stmt = $this->db->prepare("
            SELECT a.*, f.original_name, f.mime_type, f.size,
                   f.folder_id AS drive_folder_id
                   {$folderSelect}
            FROM webmail_card_attachments a
            LEFT JOIN drive_files f ON a.drive_file_id = f.id
            WHERE a.card_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$cardId]);
        
        return array_map(function($att) use ($hasFolderCol) {
            $att['is_cover'] = (bool)$att['is_cover'];
            $att['asset_folder_id'] = $hasFolderCol ? ($att['asset_folder_id'] ?? null ? (int)$att['asset_folder_id'] : null) : null;
            return $att;
        }, $stmt->fetchAll());
    }
    
    /**
     * Add attachment to card (from Drive file ID)
     */
    public function addAttachment(string $email, int $cardId, int $driveFileId, ?string $name = null, ?int $folderId = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        // Get file info
        $stmt = $this->db->prepare("SELECT original_name FROM drive_files WHERE id = ?");
        $stmt->execute([$driveFileId]);
        $file = $stmt->fetch();
        
        if (!$file) return null;
        
        $hasFolderCol = $this->columnExists('webmail_card_attachments', 'folder_id');
        
        if ($hasFolderCol) {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_attachments (card_id, drive_file_id, name, created_by, folder_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $driveFileId, $name ?? $file['original_name'], strtolower($email), $folderId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_attachments (card_id, drive_file_id, name, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $driveFileId, $name ?? $file['original_name'], strtolower($email)]);
        }
        
        $attachmentId = (int)$this->db->lastInsertId();
        
        // Log activity
        $this->logActivity($cardId, $email, 'added_attachment', ['name' => $name ?? $file['original_name']]);
        
        $stmt = $this->db->prepare("
            SELECT a.*, f.original_name, f.mime_type, f.size
            FROM webmail_card_attachments a
            LEFT JOIN drive_files f ON a.drive_file_id = f.id
            WHERE a.id = ?
        ");
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch();
        $att['is_cover'] = (bool)$att['is_cover'];
        $att['asset_folder_id'] = $hasFolderCol ? ((isset($att['folder_id']) && $att['folder_id']) ? (int)$att['folder_id'] : null) : null;
        
        return $att;
    }
    
    /**
     * Add URL attachment to card
     */
    public function addUrlAttachment(string $email, int $cardId, string $url, string $name): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO webmail_card_attachments (card_id, name, url, created_by)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $cardId,
            $name,
            $url,
            strtolower($email)
        ]);
        
        $attachmentId = (int)$this->db->lastInsertId();
        
        // Log activity
        $this->logActivity($email, 'added_attachment', 'card', $cardId, $name, (int)$result['board_id']);
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch();
        $att['is_cover'] = (bool)$att['is_cover'];
        
        return $att;
    }
    
    /**
     * Add Drive file as attachment to card
     */
    public function addDriveAttachment(string $email, int $cardId, int $driveFileId, string $name, ?int $folderId = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            SELECT id, original_name, mime_type, size
            FROM drive_files
            WHERE id = ?
        ");
        $stmt->execute([$driveFileId]);
        $driveFile = $stmt->fetch();
        
        if (!$driveFile) {
            $this->log("Drive file not found: $driveFileId");
            return null;
        }
        
        $hasFolderCol = $this->columnExists('webmail_card_attachments', 'folder_id');
        
        if ($hasFolderCol) {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_attachments (card_id, drive_file_id, name, created_by, folder_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $driveFileId, $name ?: $driveFile['original_name'], strtolower($email), $folderId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_attachments (card_id, drive_file_id, name, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $driveFileId, $name ?: $driveFile['original_name'], strtolower($email)]);
        }
        
        $attachmentId = (int)$this->db->lastInsertId();
        
        $this->logActivity($email, 'added_attachment', 'card', $cardId, $name ?: $driveFile['original_name'], (int)$result['board_id']);
        
        $stmt = $this->db->prepare("
            SELECT a.*, f.original_name, f.mime_type, f.size
            FROM webmail_card_attachments a
            LEFT JOIN drive_files f ON a.drive_file_id = f.id
            WHERE a.id = ?
        ");
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch();
        $att['is_cover'] = (bool)$att['is_cover'];
        $att['asset_folder_id'] = $hasFolderCol ? ((isset($att['folder_id']) && $att['folder_id']) ? (int)$att['folder_id'] : null) : null;
        
        return $att;
    }
    
    /**
     * Delete attachment
     */
    public function deleteAttachment(string $email, int $attachmentId): bool
    {
        $stmt = $this->db->prepare("
            SELECT a.card_id, l.board_id
            FROM webmail_card_attachments a
            JOIN webmail_board_cards c ON a.card_id = c.id
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE a.id = ?
        ");
        $stmt->execute([$attachmentId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_card_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Set attachment as card cover
     */
    public function setAttachmentAsCover(string $email, int $cardId, int $attachmentId): bool
    {
        $stmt = $this->db->prepare("
            SELECT l.board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $result = $stmt->fetch();
        
        if (!$result || !$this->hasAccess($email, $result['board_id'], 'editor')) {
            return false;
        }
        
        // Clear existing covers
        $stmt = $this->db->prepare("UPDATE webmail_card_attachments SET is_cover = 0 WHERE card_id = ?");
        $stmt->execute([$cardId]);
        
        // Set new cover
        $stmt = $this->db->prepare("UPDATE webmail_card_attachments SET is_cover = 1 WHERE id = ? AND card_id = ?");
        $stmt->execute([$attachmentId, $cardId]);
        
        // Also update card's cover_image_id
        $stmt = $this->db->prepare("UPDATE webmail_board_cards SET cover_image_id = ? WHERE id = ?");
        $stmt->execute([$attachmentId, $cardId]);
        
        return true;
    }
    
    // ========================================
    // COMMENT METHODS
    // ========================================
    
    /**
     * Get comments for a card
     */
    public function getComments(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_card_comments
            WHERE card_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add comment to card. Persists structured mentions JSON and notifies via Project Hub resolver.
     *
     * @param array<mixed>|null $structuredMentions Optional [{email,name},...] from client
     */
    public function addComment(string $email, int $cardId, string $content, ?int $parentCommentId = null, ?array $structuredMentions = null): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, l.board_id, b.name as board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();

        if (!$card || !$this->hasAccess($email, $card['board_id'])) {
            return null;
        }

        // Sanitize HTML content
        $content = $this->sanitizeCommentContent($content);

        $mentionsRows = \Webmail\Addons\ProjectHub\Services\CardCommentMentionParser::mergeMentions($content, $structuredMentions);
        $mentionsJson = $mentionsRows === [] ? null : json_encode($mentionsRows, JSON_UNESCAPED_UNICODE);
        $mentionedEmails = array_column($mentionsRows, 'email');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_comments (card_id, user_email, content, parent_comment_id, mentions)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cardId,
                strtolower($email),
                $content,
                $parentCommentId,
                $mentionsJson,
            ]);
            $commentId = (int) $this->db->lastInsertId();

            $this->logActivity($email, 'comment_added', 'card', $cardId, $card['title'], $card['board_id'], null, ['preview' => substr(strip_tags($content), 0, 100)]);

            $plainPreview = substr(strip_tags($content), 0, 50);
            $notif = new \Webmail\Addons\ProjectHub\Services\ProjectHubNotificationService($this->config);
            $notif->notifyCommentWithMentions(
                $cardId,
                $email,
                'New comment',
                "{$email} commented on \"{$card['title']}\": " . $plainPreview . (strlen(strip_tags($content)) > 50 ? '...' : ''),
                [
                    'comment_id' => $commentId,
                    'preview' => substr(strip_tags($content), 0, 100),
                    'card_title' => $card['title'],
                ],
                $mentionedEmails
            );

            // TrackingService bootstrap (called inside notifyCommentWithMentions) runs DDL
            // that implicitly commits in MySQL — guard against double-commit.
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('BoardService::addComment failed: ' . $e->getMessage());

            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM webmail_card_comments WHERE id = ?');
        $stmt->execute([$commentId]);

        return $stmt->fetch() ?: null;
    }
    
    /**
     * Update comment
     */
    public function updateComment(string $email, int $commentId, string $content, ?int $parentCommentId = null, ?string $mentions = null): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment || strtolower($comment['user_email']) !== strtolower($email)) {
            return null;
        }
        
        $content = $this->sanitizeCommentContent($content);
        
        $sets = ['content = ?', 'edited_at = NOW()'];
        $params = [$content];
        
        if ($parentCommentId !== null) {
            $sets[] = 'parent_comment_id = ?';
            $params[] = $parentCommentId;
        }
        if ($mentions !== null) {
            $sets[] = 'mentions = ?';
            $params[] = $mentions;
        }
        
        $params[] = $commentId;
        $stmt = $this->db->prepare("UPDATE webmail_card_comments SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
        
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Delete comment
     */
    public function deleteComment(string $email, int $commentId): bool
    {
        // Only the author can delete their comment
        $stmt = $this->db->prepare("SELECT * FROM webmail_card_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        
        if (!$comment || strtolower($comment['user_email']) !== strtolower($email)) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_card_comments WHERE id = ?");
        $stmt->execute([$commentId]);
        
        return $stmt->rowCount() > 0;
    }
    
    // ========================================
    // ACTIVITY METHODS (Legacy card activity - kept for backward compatibility)
    // ========================================
    
    /**
     * Log card activity (legacy - uses webmail_card_activity table)
     */
    private function logCardActivityLegacy(int $cardId, string $email, string $action, array $details = []): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_activity (card_id, user_email, action, details)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $cardId,
                strtolower($email),
                $action,
                json_encode($details)
            ]);
        } catch (\PDOException $e) {
            error_log("BoardService logCardActivityLegacy error: " . $e->getMessage());
        }
    }
    
    /**
     * Get activity for a card
     */
    public function getActivity(int $cardId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_card_activity
            WHERE card_id = ?
            ORDER BY created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$cardId]);
        
        return array_map(function($activity) {
            $activity['details'] = json_decode($activity['details'], true) ?? [];
            return $activity;
        }, $stmt->fetchAll());
    }
    
    // ========================================
    // CALENDAR INTEGRATION
    // ========================================
    
    /**
     * Sync card due date to calendar
     */
    public function syncCardToCalendar(string $email, int $cardId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT c.*, l.board_id, b.name as board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            WHERE c.id = ?
        ");
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();
        
        if (!$card) return null;
        
        try {
            $calendar = $this->getCalendarService();
            
            // Get default calendar
            $defaultCal = $calendar->getDefaultCalendar($email);
            if (!$defaultCal) {
                // Create default calendar if none exists
                $defaultCal = $calendar->createCalendar($email, 'Default', '#22c55e', true);
            }
            
            if (!$defaultCal) return null;
            
            $eventData = [
                'title' => "[{$card['board_name']}] {$card['title']}",
                'description' => $card['description'],
                'start_time' => $card['due_date'],
                'end_time' => $card['due_date'],
                'all_day' => true,
                'reminders' => [15, 60, 1440] // 15 min, 1 hour, 1 day before
            ];
            
            if ($card['calendar_event_id']) {
                // Update existing event
                if ($card['due_date']) {
                    $calendar->updateEvent($email, $card['calendar_event_id'], $eventData);
                } else {
                    // Due date removed, delete event
                    $calendar->deleteEvent($email, $card['calendar_event_id']);
                    $stmt = $this->db->prepare("UPDATE webmail_board_cards SET calendar_event_id = NULL WHERE id = ?");
                    $stmt->execute([$cardId]);
                }
                return $card['calendar_event_id'];
            } else if ($card['due_date']) {
                // Create new event
                $event = $calendar->createEvent($email, $defaultCal['id'], $eventData);
                if ($event) {
                    $stmt = $this->db->prepare("UPDATE webmail_board_cards SET calendar_event_id = ? WHERE id = ?");
                    $stmt->execute([$event['id'], $cardId]);
                    return $event['id'];
                }
            }
        } catch (\Exception $e) {
            error_log("BoardService syncCardToCalendar error: " . $e->getMessage());
        }
        
        return null;
    }
    
    // ========================================
    // SEARCH & FILTERING
    // ========================================
    
    /**
     * Search cards across all boards
     */
    public function searchCards(string $email, string $query, ?int $boardId = null): array
    {
        $email = strtolower($email);
        $query = '%' . $query . '%';
        
        $sql = "
            SELECT c.*, l.name as list_name, b.name as board_name, b.id as board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
            WHERE (b.owner_email = ? OR m.user_email = ?)
            AND (c.title LIKE ? OR c.description LIKE ?)
            AND c.archived = 0
        ";
        
        $params = [$email, $email, $email, $query, $query];
        
        if ($boardId) {
            $sql .= " AND b.id = ?";
            $params[] = $boardId;
        }
        
        $sql .= " ORDER BY c.updated_at DESC LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->enrichCardsBatch($stmt->fetchAll());
    }

    /**
     * Get cards by due date range
     */
    public function getCardsByDueDate(string $email, string $startDate, string $endDate, ?int $boardId = null): array
    {
        $email = strtolower($email);
        
        $sql = "
            SELECT c.*, l.name as list_name, b.name as board_name, b.id as board_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
            WHERE (b.owner_email = ? OR m.user_email = ?)
            AND c.due_date >= ? AND c.due_date <= ?
            AND c.archived = 0
        ";
        
        $params = [$email, $email, $email, $startDate, $endDate];
        
        if ($boardId) {
            $sql .= " AND b.id = ?";
            $params[] = $boardId;
        }
        
        $sql .= " ORDER BY c.due_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->enrichCardsBatch($stmt->fetchAll());
    }

    /**
     * Get cards assigned to user
     */
    public function getAssignedCards(string $email, ?int $boardId = null): array
    {
        $email = strtolower($email);
        
        $sql = "
            SELECT c.*, l.name as list_name, b.name as board_name, b.id as board_id,
                   df.id as board_drive_folder_id
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            JOIN webmail_boards b ON l.board_id = b.id
            LEFT JOIN drive_folders df ON df.board_id = b.id AND (df.is_trashed = 0 OR df.is_trashed IS NULL)
            WHERE c.assigned_to = ?
            AND c.archived = 0
            AND c.completed = 0
        ";
        
        $params = [$email];
        
        if ($boardId) {
            $sql .= " AND b.id = ?";
            $params[] = $boardId;
        }
        
        $sql .= " ORDER BY c.due_date IS NULL ASC, c.due_date ASC, c.updated_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->enrichCardsBatch($stmt->fetchAll());
    }
    
    // ========================================
    // EMAIL-BOARD LINKING
    // ========================================
    
    /**
     * Link an email to a board
     */
    public function linkEmailToBoard(string $userEmail, int $boardId, array $emailData): ?array
    {
        $userEmail = strtolower($userEmail);
        
        $this->log("linkEmailToBoard: user=$userEmail, board=$boardId, uid={$emailData['uid']}, folder={$emailData['folder']}");
        
        if (!$this->hasAccess($userEmail, $boardId, 'editor')) {
            $this->log("linkEmailToBoard: Access denied for user $userEmail to board $boardId");
            return null;
        }
        
        try {
            // Check if already linked
            $stmt = $this->db->prepare("
                SELECT id FROM webmail_board_emails 
                WHERE board_id = ? AND email_uid = ? AND email_folder = ?
            ");
            $stmt->execute([$boardId, $emailData['uid'], $emailData['folder']]);
            if ($stmt->fetch()) {
                $this->log("linkEmailToBoard: Email already linked");
                return null; // Already linked
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO webmail_board_emails 
                (board_id, email_uid, email_folder, email_subject, email_from, thread_id, linked_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $boardId,
                $emailData['uid'],
                $emailData['folder'],
                $emailData['subject'] ?? null,
                $emailData['from'] ?? null,
                $emailData['thread_id'] ?? null,
                $userEmail
            ]);
            
            $linkId = $this->db->lastInsertId();
            $this->log("linkEmailToBoard: Success, linkId=$linkId");
            
            return $this->getBoardEmail($linkId);
        } catch (\PDOException $e) {
            $this->log("linkEmailToBoard: Database error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Unlink an email from a board
     */
    public function unlinkEmailFromBoard(string $userEmail, int $linkId): bool
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare("
            SELECT be.board_id FROM webmail_board_emails be WHERE be.id = ?
        ");
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();
        
        if (!$link || !$this->hasAccess($userEmail, $link['board_id'], 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM webmail_board_emails WHERE id = ?");
        $stmt->execute([$linkId]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get single board email link
     */
    public function getBoardEmail(int $linkId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT be.*, b.name as board_name
            FROM webmail_board_emails be
            JOIN webmail_boards b ON be.board_id = b.id
            WHERE be.id = ?
        ");
        $stmt->execute([$linkId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get all emails linked to a board
     */
    public function getBoardEmails(string $userEmail, int $boardId): array
    {
        $userEmail = strtolower($userEmail);
        
        if (!$this->hasAccess($userEmail, $boardId, 'viewer')) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_board_emails 
            WHERE board_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Update email link location (when email is moved to different folder)
     */
    public function updateEmailLinkLocation(string $userEmail, int $boardId, int $oldUid, string $oldFolder, int $newUid, string $newFolder): bool
    {
        $userEmail = strtolower($userEmail);
        
        if (!$this->hasAccess($userEmail, $boardId, 'editor')) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            UPDATE webmail_board_emails 
            SET email_uid = ?, email_folder = ?
            WHERE board_id = ? AND email_uid = ? AND email_folder = ?
        ");
        $stmt->execute([$newUid, $newFolder, $boardId, $oldUid, $oldFolder]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get board info by email (check if email is linked to any board)
     */
    public function getBoardByEmail(string $userEmail, int $emailUid, string $folder): ?array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare("
            SELECT be.*, b.id as board_id, b.name as board_name, b.background_color
            FROM webmail_board_emails be
            JOIN webmail_boards b ON be.board_id = b.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
            WHERE be.email_uid = ? AND be.email_folder = ?
            AND (b.owner_email = ? OR m.user_email = ?)
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $emailUid, $folder, $userEmail, $userEmail]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Batch fetch board links for multiple emails
     */
    public function getBoardsByEmailsBatch(string $userEmail, array $emails): array
    {
        $userEmail = strtolower($userEmail);
        
        $this->log("getBoardsByEmailsBatch: user=$userEmail, email_count=" . count($emails));
        
        if (empty($emails)) {
            return [];
        }
        
        // Build query for all emails
        $placeholders = [];
        $params = [];
        
        foreach ($emails as $email) {
            $placeholders[] = "(be.email_uid = ? AND be.email_folder = ?)";
            $params[] = (int)$email['uid'];
            $params[] = $email['folder'];
        }
        
        $whereClause = implode(' OR ', $placeholders);
        
        $sql = "
            SELECT be.email_uid, be.email_folder, be.id as link_id, be.thread_id, be.email_subject,
                   b.id as board_id, b.name as board_name, b.background_color
            FROM webmail_board_emails be
            JOIN webmail_boards b ON be.board_id = b.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND LOWER(m.user_email) = ?
            WHERE ({$whereClause})
            AND (LOWER(b.owner_email) = ? OR LOWER(m.user_email) = ?)
        ";
        
        $stmt = $this->db->prepare($sql);
        
        // Execute with user email at the beginning and end for permission check
        $allParams = array_merge([$userEmail], $params, [$userEmail, $userEmail]);
        $stmt->execute($allParams);
        
        $results = [];
        while ($row = $stmt->fetch()) {
            $results[$row['email_uid']] = [
                'id' => $row['link_id'],
                'board_id' => $row['board_id'],
                'board_name' => $row['board_name'],
                'background_color' => $row['background_color'],
                'thread_id' => $row['thread_id'],
                'email_subject' => $row['email_subject']
            ];
        }
        
        $this->log("getBoardsByEmailsBatch: found " . count($results) . " results");
        
        return $results;
    }
    
    /**
     * Get boards by thread ID
     */
    public function getBoardsByThread(string $userEmail, string $threadId): array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare("
            SELECT DISTINCT b.id, b.name, b.background_color, be.email_subject
            FROM webmail_board_emails be
            JOIN webmail_boards b ON be.board_id = b.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
            WHERE be.thread_id = ?
            AND (b.owner_email = ? OR m.user_email = ?)
        ");
        $stmt->execute([$userEmail, $threadId, $userEmail, $userEmail]);
        return $stmt->fetchAll();
    }
    
    // ========================================
    // PROGRESS REPORT
    // ========================================
    
    /**
     * Get progress since last report
     */
    public function getProgressSinceLastReport(string $userEmail, int $boardId): array
    {
        $userEmail = strtolower($userEmail);
        
        if (!$this->hasAccess($userEmail, $boardId, 'viewer')) {
            return [
                'last_report_date' => null,
                'lists' => [],
                'cards' => []
            ];
        }
        
        // Get last report date
        $stmt = $this->db->prepare("
            SELECT sent_at FROM webmail_board_progress_reports 
            WHERE board_id = ? 
            ORDER BY sent_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$boardId]);
        $lastReport = $stmt->fetch();
        $lastReportDate = $lastReport ? $lastReport['sent_at'] : null;
        
        // Get all lists
        $stmt = $this->db->prepare("
            SELECT * FROM webmail_board_lists 
            WHERE board_id = ? AND archived = 0
            ORDER BY position ASC
        ");
        $stmt->execute([$boardId]);
        $lists = $stmt->fetchAll();
        
        // Get ALL cards (not just updated ones) - we'll filter by checklist progress
        $cardsSql = "
            SELECT c.*, l.name as list_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON c.list_id = l.id
            WHERE l.board_id = ? AND c.archived = 0
            ORDER BY c.completed DESC, l.position ASC, c.position ASC
        ";
        
        $stmt = $this->db->prepare($cardsSql);
        $stmt->execute([$boardId]);
        $allCards = $stmt->fetchAll();
        
        $cards = [];
        
        // Enrich cards with checklists and filter based on progress
        foreach ($allCards as $card) {
            $card['completed'] = (bool)$card['completed'];
            
            // Get all checklist items
            $stmt = $this->db->prepare("
                SELECT ci.*, cl.title as checklist_name
                FROM webmail_checklist_items ci
                JOIN webmail_card_checklists cl ON ci.checklist_id = cl.id
                WHERE cl.card_id = ?
                ORDER BY cl.position ASC, ci.position ASC
            ");
            $stmt->execute([$card['id']]);
            $allItems = $stmt->fetchAll();
            
            $this->log("getProgressSinceLastReport: Card ID {$card['id']} has " . count($allItems) . " checklist items raw");
            
            $card['checklist_items'] = [];
            $card['completed_items'] = [];
            $card['pending_items'] = [];
            $hasProgress = false;
            
            foreach ($allItems as $item) {
                $item['checked'] = (bool)$item['completed'];
                $item['text'] = $item['title']; // Normalize column name
                $card['checklist_items'][] = $item;
                
                if ($item['checked']) {
                    $card['completed_items'][] = $item;
                    $hasProgress = true; // Any completed item counts as progress
                } else {
                    $card['pending_items'][] = $item;
                }
            }
            
            // Include ALL cards that have any activity
            $hasCompletedItems = count($card['completed_items']) > 0;
            $hasAnyChecklistItems = count($card['checklist_items']) > 0;
            
            // For now, include ALL cards to debug
            $cards[] = $card;
            
            $this->log("getProgressSinceLastReport: Card '{$card['title']}' - completed={$card['completed']}, checklist_items=" . count($card['checklist_items']) . ", completed_items=" . count($card['completed_items']));
        }
        
        // Debug logging
        $this->log("getProgressSinceLastReport: board_id=$boardId, total_cards=" . count($allCards) . ", filtered_cards=" . count($cards));
        
        return [
            'last_report_date' => $lastReportDate,
            'lists' => $lists,
            'cards' => $cards
        ];
    }
    
    /**
     * Generate progress report HTML
     */
    public function generateProgressReportHtml(string $userEmail, int $boardId): string
    {
        $userEmail = strtolower($userEmail);
        
        $this->log("generateProgressReportHtml: START board_id=$boardId user=$userEmail");
        
        $board = $this->getBoard($userEmail, $boardId);
        if (!$board) {
            $this->log("generateProgressReportHtml: Board not found for board_id=$boardId user=$userEmail");
            // Return an error HTML instead of empty string
            return '<div style="padding: 32px; text-align: center; color: #ef4444;">
                <p style="font-size: 48px; margin: 0 0 16px 0;">⚠️</p>
                <p style="font-size: 16px; font-weight: 600;">Board not found or access denied</p>
                <p style="font-size: 14px; color: #6b7280; margin-top: 8px;">Board ID: ' . $boardId . ', User: ' . htmlspecialchars($userEmail) . '</p>
            </div>';
        }
        
        $this->log("generateProgressReportHtml: Board found: " . $board['name']);
        
        $progress = $this->getProgressSinceLastReport($userEmail, $boardId);
        
        $this->log("generateProgressReportHtml: Progress cards count: " . count($progress['cards'] ?? []));
        
        // Ensure progress has the right structure
        if (!isset($progress['cards'])) {
            $progress['cards'] = [];
        }
        
        $bgColor = $board['background_color'] ?? '#0ea5e9';
        $sinceText = $progress['last_report_date'] 
            ? 'since ' . date('M j, Y', strtotime($progress['last_report_date']))
            : '';
        
        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f3f4f6;">
    <div style="max-width: 640px; margin: 0 auto; padding: 24px;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, ' . htmlspecialchars($bgColor) . ' 0%, ' . $this->adjustColor($bgColor, -20) . ' 100%); border-radius: 16px 16px 0 0; padding: 32px; text-align: center;">
            <h1 style="color: white; margin: 0 0 8px 0; font-size: 28px; font-weight: 700;">' . htmlspecialchars($board['name']) . '</h1>
            <p style="color: rgba(255,255,255,0.9); margin: 0; font-size: 14px;">Progress Report ' . $sinceText . '</p>
        </div>
        
        <!-- Content -->
        <div style="background: white; border-radius: 0 0 16px 16px; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">';
        
        // Group cards by completion status
        $completedCards = array_filter($progress['cards'], fn($c) => $c['completed']);
        $inProgressCards = array_filter($progress['cards'], fn($c) => !$c['completed']);
        
        if (count($completedCards) > 0) {
            $html .= '
            <!-- Completed Section -->
            <div style="margin-bottom: 32px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                    <div style="width: 24px; height: 24px; background: #22c55e; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <span style="color: white; font-size: 14px;">✓</span>
                    </div>
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">Completed (' . count($completedCards) . ')</h2>
                </div>';
            
            foreach ($completedCards as $card) {
                $html .= $this->renderCardForEmail($card, true);
            }
            
            $html .= '</div>';
        }
        
        if (count($inProgressCards) > 0) {
            $html .= '
            <!-- In Progress Section -->
            <div style="margin-bottom: 32px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
                    <div style="width: 24px; height: 24px; background: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <span style="color: white; font-size: 14px;">→</span>
                    </div>
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600; color: #111827;">In Progress (' . count($inProgressCards) . ')</h2>
                </div>';
            
            foreach ($inProgressCards as $card) {
                $html .= $this->renderCardForEmail($card, false);
            }
            
            $html .= '</div>';
        }
        
        if (count($progress['cards']) === 0) {
            $html .= '
            <div style="text-align: center; padding: 32px; color: #6b7280;">
                <p style="font-size: 48px; margin: 0 0 16px 0;">📋</p>
                <p style="margin: 0;">No updates since the last report.</p>
            </div>';
        }
        
        $html .= '
            <!-- Footer -->
            <div style="border-top: 1px solid #e5e7eb; padding-top: 24px; margin-top: 24px; text-align: center;">
                <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                    Generated on ' . date('F j, Y \a\t g:i A') . '
                </p>
            </div>
        </div>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    /**
     * Render a single card for email
     */
    private function renderCardForEmail(array $card, bool $completed): string
    {
        $checkIcon = $completed 
            ? '<span style="color: #22c55e;">✓</span>' 
            : '<span style="color: #9ca3af;">○</span>';
        
        $html = '
        <div style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 12px; background: #fafafa;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="font-size: 20px; line-height: 1;">' . $checkIcon . '</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: #111827; ' . ($completed ? 'text-decoration: line-through; color: #6b7280;' : '') . '">' . htmlspecialchars($card['title']) . '</h3>
                    <p style="margin: 0; font-size: 12px; color: #9ca3af;">' . htmlspecialchars($card['list_name']) . '</p>';
        
        if (!empty($card['description'])) {
            $html .= '<p style="margin: 8px 0 0 0; font-size: 13px; color: #6b7280;">' . nl2br(htmlspecialchars(substr($card['description'], 0, 200))) . '</p>';
        }
        
        // Render checklist items
        if (!empty($card['checklist_items'])) {
            $checkedCount = count(array_filter($card['checklist_items'], fn($i) => $i['checked']));
            $totalCount = count($card['checklist_items']);
            
            $html .= '
            <div style="margin-top: 12px; padding: 12px; background: white; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px; font-weight: 500;">
                    CHECKLIST (' . $checkedCount . '/' . $totalCount . ')
                </div>';
            
            foreach ($card['checklist_items'] as $item) {
                $itemCheck = $item['checked'] 
                    ? '<span style="color: #22c55e; margin-right: 6px;">☑</span>' 
                    : '<span style="color: #d1d5db; margin-right: 6px;">☐</span>';
                $itemStyle = $item['checked'] ? 'text-decoration: line-through; color: #9ca3af;' : 'color: #374151;';
                
                $html .= '<div style="font-size: 13px; margin-bottom: 4px; ' . $itemStyle . '">' . $itemCheck . htmlspecialchars($item['text']) . '</div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '
                </div>
            </div>
        </div>';
        
        return $html;
    }
    
    /**
     * Save progress report record
     */
    public function saveProgressReport(string $userEmail, int $boardId, string $sentTo, string $subject, string $content, array $cardIds): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO webmail_board_progress_reports 
            (board_id, sent_by, sent_to, subject, content, cards_included)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $boardId,
            strtolower($userEmail),
            $sentTo,
            $subject,
            $content,
            json_encode($cardIds)
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get progress report history
     */
    public function getProgressReportHistory(string $userEmail, int $boardId): array
    {
        $userEmail = strtolower($userEmail);
        
        if (!$this->hasAccess($userEmail, $boardId, 'viewer')) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT id, sent_by, sent_to, subject, sent_at
            FROM webmail_board_progress_reports 
            WHERE board_id = ?
            ORDER BY sent_at DESC
            LIMIT 50
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Adjust color brightness
     */
    private function adjustColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));
        
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * Sanitize comment content - allow safe HTML (img tags with drive preview URLs, basic formatting)
     */
    private function sanitizeCommentContent(string $content): string
    {
        // Remove script tags and event handlers
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/', '', $content);
        
        // Remove javascript: and vbscript: URLs
        $content = preg_replace('/href\s*=\s*["\']?\s*(javascript|vbscript):[^"\'>\s]*/i', 'href="#"', $content);
        $content = preg_replace('/src\s*=\s*["\']?\s*(javascript|vbscript):[^"\'>\s]*/i', 'src=""', $content);
        
        // Only allow img tags with src pointing to drive preview URLs or data URIs
        // Allow img tags with safe attributes
        $content = preg_replace_callback('/<img\s+([^>]*)>/i', function($matches) {
            $attrs = $matches[1];
            $hasValidSrc = false;
            $src = '';
            
            // Extract src attribute
            if (preg_match('/src\s*=\s*["\']([^"\']*)["\']/', $attrs, $srcMatch)) {
                $src = $srcMatch[1];
                // Allow drive preview URLs (/drive/files/ or /api/drive/files/)
                // Allow drive share URLs (/drive/share/ or /api/drive/share/)
                // Allow data URIs
                if (strpos($src, '/drive/files/') !== false || 
                    strpos($src, '/drive/share/') !== false || 
                    strpos($src, 'data:image/') === 0) {
                    $hasValidSrc = true;
                } else {
                    // Remove unsafe img tags
                    return '';
                }
            } else {
                // No src attribute, remove the tag
                return '';
            }
            
            // Keep only safe attributes
            $safeAttrs = [];
            $safeAttrs[] = 'src="' . htmlspecialchars($src, ENT_QUOTES) . '"';
            
            if (preg_match('/alt\s*=\s*["\']([^"\']*)["\']/', $attrs, $altMatch)) {
                $safeAttrs[] = 'alt="' . htmlspecialchars($altMatch[1], ENT_QUOTES) . '"';
            }
            
            if (preg_match('/style\s*=\s*["\']([^"\']*)["\']/', $attrs, $styleMatch)) {
                // Allow safe CSS properties: max-width, width, height, border-radius, margin, padding, display
                $style = $styleMatch[1];
                // Filter out dangerous CSS (expressions, javascript, etc.)
                if (!preg_match('/(expression|javascript|import|@import|behavior|binding)/i', $style)) {
                    // Allow common safe CSS properties
                    $safeAttrs[] = 'style="' . htmlspecialchars($style, ENT_QUOTES) . '"';
                }
            }
            
            return '<img ' . implode(' ', $safeAttrs) . '>';
        }, $content);
        
        // Remove any remaining dangerous tags
        $content = preg_replace('/<(iframe|object|embed|form|input|button|select|textarea|applet|script|style)\b[^>]*>(.*?)<\/\1>/is', '', $content);
        $content = preg_replace('/<(iframe|object|embed|form|input|button|select|textarea|applet|script|style)\b[^>]*\/?>/is', '', $content);
        
        return trim($content);
    }
    
    // ===== BOARD DRIVE INTEGRATION =====
    
    /**
     * Get or create a Drive folder for a board
     * Creates "Boards/{BoardName}/" folder structure
     * @param string $ownerEmail Board owner email
     * @param int $boardId Board ID
     * @return array|null Folder info or null on failure
     */
    public function getOrCreateBoardDriveFolder(string $ownerEmail, int $boardId): ?array
    {
        $ownerEmail = strtolower($ownerEmail);
        
        // Get board info
        $stmt = $this->db->prepare("SELECT name FROM webmail_boards WHERE id = ? AND owner_email = ?");
        $stmt->execute([$boardId, $ownerEmail]);
        $board = $stmt->fetch();
        
        if (!$board) {
            return null;
        }
        
        $driveService = $this->getDriveService();
        if (!$driveService) {
            return null;
        }
        
        // Check if board already has a linked folder
        $stmt = $this->db->prepare("SELECT id, name FROM drive_folders WHERE board_id = ? AND user_email = ?");
        $stmt->execute([$boardId, $ownerEmail]);
        $existingFolder = $stmt->fetch();
        
        if ($existingFolder) {
            return $existingFolder;
        }
        
        // First, get or create "Boards" parent folder
        $stmt = $this->db->prepare("SELECT id FROM drive_folders WHERE user_email = ? AND name = 'Boards' AND parent_id IS NULL");
        $stmt->execute([$ownerEmail]);
        $boardsFolder = $stmt->fetch();
        
        $boardsFolderId = null;
        if ($boardsFolder) {
            $boardsFolderId = $boardsFolder['id'];
        } else {
            // Create "Boards" folder
            $stmt = $this->db->prepare("INSERT INTO drive_folders (user_email, parent_id, name, color, created_by) VALUES (?, NULL, 'Boards', '#64748b', ?)");
            $stmt->execute([$ownerEmail, $ownerEmail]);
            $boardsFolderId = $this->db->lastInsertId();
        }
        
        // Create folder for this board
        $folderName = preg_replace('/[<>:"\/\\|?*]/', '_', $board['name']); // Clean name for filesystem safety
        $stmt = $this->db->prepare("INSERT INTO drive_folders (user_email, parent_id, name, color, board_id, created_by) VALUES (?, ?, ?, '#3b82f6', ?, ?)");
        $stmt->execute([$ownerEmail, $boardsFolderId, $folderName, $boardId, $ownerEmail]);
        $folderId = $this->db->lastInsertId();
        
        // Also link this folder to any clients already linked to the board
        $this->linkBoardFolderToLinkedClients($boardId, (int)$folderId);
        
        return [
            'id' => $folderId,
            'name' => $folderName,
            'parent_id' => $boardsFolderId,
            'board_id' => $boardId
        ];
    }
    
    /**
     * Get the Drive folder linked to a board
     * @param int $boardId Board ID
     * @return array|null Folder info or null
     */
    public function getBoardDriveFolder(int $boardId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name, user_email FROM drive_folders WHERE board_id = ?");
        $stmt->execute([$boardId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Link an existing folder to a board
     * Sets board_id on the folder and also links to any linked client
     * @param string $userEmail User email (must be board owner)
     * @param int $boardId Board ID
     * @param int $folderId Folder ID to link
     * @return array|null Folder info or null on failure
     */
    public function linkExistingFolderToBoard(string $userEmail, int $boardId, int $folderId): ?array
    {
        $userEmail = strtolower($userEmail);
        
        try {
            // Verify board ownership
            $stmt = $this->db->prepare("SELECT id, name FROM webmail_boards WHERE id = ? AND owner_email = ?");
            $stmt->execute([$boardId, $userEmail]);
            $board = $stmt->fetch();
            
            if (!$board) {
                error_log("BoardService linkExistingFolderToBoard: Board #$boardId not found or not owned by $userEmail");
                return null;
            }
            
            // Verify folder exists and belongs to user
            $stmt = $this->db->prepare("SELECT id, name FROM drive_folders WHERE id = ? AND user_email = ?");
            $stmt->execute([$folderId, $userEmail]);
            $folder = $stmt->fetch();
            
            if (!$folder) {
                error_log("BoardService linkExistingFolderToBoard: Folder #$folderId not found or not owned by $userEmail");
                return null;
            }
            
            // Link folder to board (set board_id on the folder)
            $stmt = $this->db->prepare("UPDATE drive_folders SET board_id = ? WHERE id = ?");
            $stmt->execute([$boardId, $folderId]);
            
            error_log("BoardService: Linked folder #$folderId ({$folder['name']}) to board #$boardId ({$board['name']})");
            
            // Also link this folder to any client linked to the board
            $this->linkBoardFolderToLinkedClients($boardId, $folderId);
            
            return [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'board_id' => $boardId
            ];
        } catch (\Exception $e) {
            error_log("BoardService linkExistingFolderToBoard error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Link a board's folder to all clients linked to that board
     * Updates clients.drive_folder_id for clients that don't have one
     * @param int $boardId Board ID
     * @param int $folderId Folder ID to link
     */
    private function linkBoardFolderToLinkedClients(int $boardId, int $folderId): void
    {
        try {
            // Update all clients linked to this board that don't have a drive_folder_id
            $stmt = $this->db->prepare("
                UPDATE clients c
                INNER JOIN client_boards cb ON cb.client_id = c.id
                SET c.drive_folder_id = ?
                WHERE cb.board_id = ? AND (c.drive_folder_id IS NULL OR c.drive_folder_id = 0)
            ");
            $stmt->execute([$folderId, $boardId]);
            
            $affected = $stmt->rowCount();
            if ($affected > 0) {
                error_log("BoardService: Linked folder #$folderId to $affected client(s) via board #$boardId");
            }
        } catch (\Exception $e) {
            error_log("BoardService linkBoardFolderToLinkedClients error: " . $e->getMessage());
        }
    }
    
    /**
     * Set a member's Drive access for a board
     * @param string $ownerEmail Board owner email
     * @param int $boardId Board ID
     * @param string $memberEmail Member email
     * @param string $permission 'viewer' or 'editor'
     * @param int|null $folderId Specific folder ID (or null to use board's folder)
     * @return bool
     */
    public function setMemberDriveAccess(string $ownerEmail, int $boardId, string $memberEmail, string $permission = 'viewer', ?int $folderId = null): bool
    {
        $ownerEmail = strtolower($ownerEmail);
        $memberEmail = strtolower($memberEmail);
        
        // Verify ownership
        $stmt = $this->db->prepare("SELECT id FROM webmail_boards WHERE id = ? AND owner_email = ?");
        $stmt->execute([$boardId, $ownerEmail]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        // Verify member exists
        $stmt = $this->db->prepare("SELECT id FROM webmail_board_members WHERE board_id = ? AND user_email = ?");
        $stmt->execute([$boardId, $memberEmail]);
        $member = $stmt->fetch();
        if (!$member) {
            return false;
        }
        
        // Get folder ID if not provided
        if (!$folderId) {
            $folder = $this->getOrCreateBoardDriveFolder($ownerEmail, $boardId);
            if (!$folder) {
                return false;
            }
            $folderId = $folder['id'];
        }
        
        // Update member record
        $stmt = $this->db->prepare("
            UPDATE webmail_board_members 
            SET can_access_drive = 1, drive_folder_id = ?, drive_permission = ?
            WHERE board_id = ? AND user_email = ?
        ");
        $stmt->execute([$folderId, $permission, $boardId, $memberEmail]);
        
        // Add as collaborator to the folder
        $driveService = $this->getDriveService();
        if ($driveService) {
            $driveService->addFolderCollaborator($ownerEmail, $folderId, $memberEmail, $permission);
        }
        
        return true;
    }
    
    /**
     * Revoke a member's Drive access
     */
    public function revokeMemberDriveAccess(string $ownerEmail, int $boardId, string $memberEmail): bool
    {
        $ownerEmail = strtolower($ownerEmail);
        $memberEmail = strtolower($memberEmail);
        
        // Get current member info
        $stmt = $this->db->prepare("SELECT drive_folder_id FROM webmail_board_members WHERE board_id = ? AND user_email = ?");
        $stmt->execute([$boardId, $memberEmail]);
        $member = $stmt->fetch();
        
        if (!$member) {
            return false;
        }
        
        // Update member record
        $stmt = $this->db->prepare("
            UPDATE webmail_board_members 
            SET can_access_drive = 0, drive_folder_id = NULL
            WHERE board_id = ? AND user_email = ?
        ");
        $stmt->execute([$boardId, $memberEmail]);
        
        // Remove collaborator from folder
        if ($member['drive_folder_id']) {
            $driveService = $this->getDriveService();
            if ($driveService) {
                $driveService->removeFolderCollaborator($ownerEmail, (int)$member['drive_folder_id'], $memberEmail);
            }
        }
        
        return true;
    }
    
    /**
     * Get members with Drive access for a board
     */
    public function getMembersWithDriveAccess(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT user_email, role, drive_folder_id, drive_permission 
            FROM webmail_board_members 
            WHERE board_id = ? AND can_access_drive = 1
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll();
    }
    
    // =========================================================================
    // TRACKED URLS (Website Time Tracking)
    // =========================================================================
    
    /**
     * Get tracked URLs for a board
     */
    public function getTrackedUrls(int $boardId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, board_id, client_id, url_domain, display_name, title_match, is_active, created_at, updated_at
            FROM board_tracked_urls
            WHERE board_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$boardId]);
        $urls = $stmt->fetchAll();
        
        // Convert is_active to boolean
        foreach ($urls as &$url) {
            $url['is_active'] = (bool)$url['is_active'];
        }
        
        return $urls;
    }
    
    /**
     * Add tracked URL to a board
     */
    public function addTrackedUrl(int $boardId, string $urlDomain, ?string $displayName = null, ?string $titleMatch = null): ?int
    {
        // Get board's client_id from client_boards table (first/primary link)
        $stmt = $this->db->prepare("
            SELECT client_id 
            FROM client_boards 
            WHERE board_id = ? 
            ORDER BY linked_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$boardId]);
        $link = $stmt->fetch();
        
        if (!$link || !$link['client_id']) {
            error_log("Failed to add tracked URL: Board $boardId is not linked to a client");
            return null;
        }
        
        $clientId = $link['client_id'];
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO board_tracked_urls (board_id, client_id, url_domain, display_name, title_match, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$boardId, $clientId, $urlDomain, $displayName, $titleMatch]);
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Duplicate entry or other error
            error_log("Failed to add tracked URL: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update tracked URL
     */
    public function updateTrackedUrl(int $urlId, int $boardId, string $urlDomain, ?string $displayName = null, ?string $titleMatch = null, $isActive = null): bool
    {
        try {
            $updates = ['url_domain = ?', 'display_name = ?', 'title_match = ?'];
            $params = [$urlDomain, $displayName, $titleMatch];
            
            if ($isActive !== null) {
                $updates[] = 'is_active = ?';
                $params[] = $isActive ? 1 : 0;
            }
            
            $params[] = $urlId;
            $params[] = $boardId;
            
            $sql = "UPDATE board_tracked_urls SET " . implode(', ', $updates) . " WHERE id = ? AND board_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("Failed to update tracked URL: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete tracked URL
     */
    public function deleteTrackedUrl(int $urlId, int $boardId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM board_tracked_urls WHERE id = ? AND board_id = ?");
        $stmt->execute([$urlId, $boardId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all URL mappings for a user (for FlowOneDrive sync)
     * Returns all tracked URLs from boards the user has access to
     */
    public function getAllUrlMappings(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT
                u.id,
                u.url_domain as domain,
                u.board_id,
                u.client_id,
                NULL as card_id,
                b.name as board_name,
                COALESCE(c.display_name, c.domain) as client_name,
                u.display_name,
                u.title_match,
                u.is_active
            FROM board_tracked_urls u
            INNER JOIN webmail_boards b ON u.board_id = b.id
            LEFT JOIN clients c ON u.client_id = c.id
            LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
            WHERE (b.owner_email = ? OR m.user_email = ?) AND u.is_active = 1
            ORDER BY u.url_domain
        ");
        $stmt->execute([$userEmail, $userEmail, $userEmail]);
        $mappings = $stmt->fetchAll();

        // Card-level tracked URLs from Project Hub
        try {
            $stmt2 = $this->db->prepare("
                SELECT DISTINCT
                    ctu.id,
                    ctu.url_domain as domain,
                    bl.board_id,
                    cb.client_id,
                    ctu.card_id,
                    b.name as board_name,
                    COALESCE(cl.display_name, cl.domain) as client_name,
                    ctu.display_name,
                    ctu.title_match,
                    1 as is_active
                FROM projecthub_card_tracked_urls ctu
                JOIN webmail_board_cards bc ON bc.id = ctu.card_id
                JOIN webmail_board_lists bl ON bl.id = bc.list_id
                JOIN webmail_boards b ON b.id = bl.board_id
                LEFT JOIN client_boards cb ON cb.board_id = bl.board_id
                LEFT JOIN clients cl ON cl.id = cb.client_id
                LEFT JOIN webmail_board_members m ON b.id = m.board_id AND m.user_email = ?
                WHERE (b.owner_email = ? OR m.user_email = ?) AND ctu.is_active = 1
            ");
            $stmt2->execute([$userEmail, $userEmail, $userEmail]);
            $cardMappings = $stmt2->fetchAll() ?: [];
            $mappings = array_merge($mappings, $cardMappings);
        } catch (\PDOException $e) {
            error_log("[BoardService] Card URL mappings query failed: " . $e->getMessage());
        }

        foreach ($mappings as &$mapping) {
            $mapping['is_active'] = (bool)$mapping['is_active'];
            $mapping['card_id'] = $mapping['card_id'] ? (int)$mapping['card_id'] : null;
        }

        return $mappings;
    }

    /**
     * Fire CRM automation hook when a board is closed.
     * Silently ignored if CRM automation is not active.
     */
    private function fireAutomationBoardClosed(int $boardId, string $boardName, string $email): void
    {
        try {
            $automationService = new \Webmail\Addons\CrmPro\Services\CrmAutomationService($this->config);
            $automationService->onBoardClosed($boardId, $boardName, $email);
        } catch (\Throwable $e) {
            error_log("BoardService: Automation hook error: " . $e->getMessage());
        }
    }
}

