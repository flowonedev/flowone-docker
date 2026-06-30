<?php

namespace Webmail\Addons\Tasks\Services;

use Webmail\Addons\Calendar\Services\CalendarService;
use Webmail\Addons\CrmPro\Services\CrmAutomationService;

class TodoService
{
    private \PDO $db;
    private array $config;
    private ?CalendarService $calendarService = null;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTableExists();
    }
    
    /**
     * Sanitize string for database storage (remove invalid UTF-8)
     */
    private function sanitizeString(?string $text): ?string
    {
        if ($text === null) return null;
        
        // Remove invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove any remaining problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        
        return $text;
    }
    
    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_todos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    parent_id INT DEFAULT NULL,
                    title VARCHAR(500) NOT NULL,
                    description TEXT,
                    completed TINYINT(1) DEFAULT 0,
                    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
                    due_date DATE DEFAULT NULL,
                    
                    -- Email reference fields
                    ref_folder VARCHAR(255) DEFAULT NULL,
                    ref_uid INT DEFAULT NULL,
                    ref_message_id VARCHAR(500) DEFAULT NULL,
                    ref_subject VARCHAR(500) DEFAULT NULL,
                    ref_from VARCHAR(255) DEFAULT NULL,
                    ref_date DATETIME DEFAULT NULL,
                    ref_selected_text TEXT DEFAULT NULL,
                    
                    -- Calendar link
                    calendar_event_id INT DEFAULT NULL,
                    
                    position INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    completed_at TIMESTAMP NULL DEFAULT NULL,
                    
                    INDEX idx_email (email),
                    INDEX idx_completed (completed),
                    INDEX idx_parent_id (parent_id),
                    INDEX idx_ref_message_id (ref_message_id(191)),
                    INDEX idx_calendar_event_id (calendar_event_id),
                    FOREIGN KEY (parent_id) REFERENCES webmail_todos(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Add parent_id column if table already exists without it
            $this->db->exec("ALTER TABLE webmail_todos ADD COLUMN IF NOT EXISTS parent_id INT DEFAULT NULL AFTER email");
            
            // Add calendar_event_id column if not exists
            $this->db->exec("ALTER TABLE webmail_todos ADD COLUMN IF NOT EXISTS calendar_event_id INT DEFAULT NULL AFTER ref_selected_text");
            
            // Ensure table uses utf8mb4
            $this->db->exec("ALTER TABLE webmail_todos CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\PDOException $e) {
            // Column might already exist or other non-critical error, ignore
            if (strpos($e->getMessage(), 'Duplicate column') === false && 
                strpos($e->getMessage(), 'already has charset') === false) {
                error_log("TodoService table modification: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get or create the CalendarService
     */
    private function getCalendarService(): CalendarService
    {
        if (!$this->calendarService) {
            $this->calendarService = new CalendarService($this->config);
        }
        return $this->calendarService;
    }
    
    /**
     * Get or create a "Work" calendar for syncing todos
     */
    private function getOrCreateWorkCalendar(string $email): ?array
    {
        $email = strtolower($email);
        $calendarService = $this->getCalendarService();
        
        // Try to find existing "Work" calendar
        $calendars = $calendarService->getCalendars($email);
        foreach ($calendars as $calendar) {
            if (strtolower($calendar['name']) === 'work') {
                return $calendar;
            }
        }
        
        // Create "Work" calendar with orange color
        return $calendarService->createCalendar($email, 'Work', '#f97316', false);
    }
    
    /**
     * Sync todo with calendar event
     */
    private function syncTodoToCalendar(string $email, array $todo): void
    {
        // Only sync if todo has a due date
        if (empty($todo['due_date'])) {
            // If no due date but has calendar event, remove the event
            if (!empty($todo['calendar_event_id'])) {
                $this->removeCalendarEvent($email, $todo);
            }
            return;
        }
        
        $calendarService = $this->getCalendarService();
        $workCalendar = $this->getOrCreateWorkCalendar($email);
        
        if (!$workCalendar) {
            error_log("TodoService: Could not get or create Work calendar for {$email}");
            return;
        }
        
        $eventData = [
            'title' => $todo['title'],
            'description' => $todo['description'] ?? null,
            'start_time' => $todo['due_date'] . ' 09:00:00',
            'end_time' => $todo['due_date'] . ' 10:00:00',
            'all_day' => true,
        ];
        
        if (!empty($todo['calendar_event_id'])) {
            // Update existing event
            $calendarService->updateEvent($email, $todo['calendar_event_id'], $eventData);
        } else {
            // Create new event
            $event = $calendarService->createEvent($email, $workCalendar['id'], $eventData);
            if ($event) {
                // Store the event ID in the todo
                $stmt = $this->db->prepare('UPDATE webmail_todos SET calendar_event_id = ? WHERE id = ?');
                $stmt->execute([$event['id'], $todo['id']]);
            }
        }
    }
    
    /**
     * Remove calendar event linked to a todo
     */
    private function removeCalendarEvent(string $email, array $todo): void
    {
        if (empty($todo['calendar_event_id'])) {
            return;
        }
        
        $calendarService = $this->getCalendarService();
        $calendarService->deleteEvent($email, $todo['calendar_event_id']);
        
        // Clear the calendar_event_id
        $stmt = $this->db->prepare('UPDATE webmail_todos SET calendar_event_id = NULL WHERE id = ?');
        $stmt->execute([$todo['id']]);
    }
    
    /**
     * Get all todos for a user (with nested subtodos)
     */
    public function getTodos(string $email, bool $includeCompleted = false): array
    {
        $email = strtolower($email);
        
        // First, get root todos (no parent)
        $sql = 'SELECT * FROM webmail_todos WHERE email = ? AND parent_id IS NULL';
        if (!$includeCompleted) {
            $sql .= ' AND completed = 0';
        }
        $sql .= ' ORDER BY position ASC, created_at DESC';
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email]);
        $rootTodos = $stmt->fetchAll();
        
        // For each root todo, get ALL its subtodos (always show subtodos regardless of completion)
        $result = [];
        foreach ($rootTodos as $todo) {
            $todo['completed'] = (bool)$todo['completed'];
            $todo['subtodos'] = $this->getSubtodos($email, $todo['id']);
            $result[] = $todo;
        }
        
        return $result;
    }
    
    /**
     * Get subtodos for a parent todo (always returns all, regardless of completion)
     */
    private function getSubtodos(string $email, int $parentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_todos WHERE email = ? AND parent_id = ? ORDER BY position ASC, created_at ASC');
        $stmt->execute([strtolower($email), $parentId]);
        $subtodos = $stmt->fetchAll();
        
        return array_map(function($todo) {
            $todo['completed'] = (bool)$todo['completed'];
            $todo['subtodos'] = []; // Subtodos don't have sub-subtodos
            return $todo;
        }, $subtodos);
    }
    
    /**
     * Get a single todo
     */
    public function getTodo(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_todos WHERE email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $todo = $stmt->fetch();
        
        if (!$todo) return null;
        
        $todo['completed'] = (bool)$todo['completed'];
        return $todo;
    }
    
    /**
     * Create a new todo
     */
    public function createTodo(string $email, array $data): ?array
    {
        $email = strtolower($email);
        
        // Get next position (for subtodos, position within parent)
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 as next_pos FROM webmail_todos WHERE email = ? AND parent_id = ?');
            $stmt->execute([$email, $parentId]);
        } else {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 as next_pos FROM webmail_todos WHERE email = ? AND parent_id IS NULL');
            $stmt->execute([$email]);
        }
        $nextPos = $stmt->fetch()['next_pos'];
        
        $stmt = $this->db->prepare('
            INSERT INTO webmail_todos (
                email, parent_id, title, description, priority, due_date, position,
                ref_folder, ref_uid, ref_message_id, ref_subject, ref_from, ref_date, ref_selected_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $email,
            $parentId,
            $this->sanitizeString($data['title'] ?? 'Untitled'),
            $this->sanitizeString($data['description'] ?? null),
            $data['priority'] ?? 'normal',
            $data['due_date'] ?? null,
            $nextPos,
            $data['ref_folder'] ?? null,
            $data['ref_uid'] ?? null,
            $this->sanitizeString($data['ref_message_id'] ?? null),
            $this->sanitizeString($data['ref_subject'] ?? null),
            $this->sanitizeString($data['ref_from'] ?? null),
            $data['ref_date'] ?? null,
            $this->sanitizeString($data['ref_selected_text'] ?? null),
        ]);
        
        $todo = $this->getTodo($email, (int)$this->db->lastInsertId());
        
        // Sync with calendar if has due date (only for root todos)
        if ($todo && !$parentId && !empty($data['due_date'])) {
            $this->syncTodoToCalendar($email, $todo);
            // Refresh to get the calendar_event_id
            $todo = $this->getTodo($email, $todo['id']);
        }
        
        return $todo;
    }
    
    /**
     * Update a todo
     */
    public function updateTodo(string $email, int $id, array $data): ?array
    {
        $email = strtolower($email);
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['title', 'description', 'priority', 'due_date', 'position'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (array_key_exists('completed', $data)) {
            $fields[] = 'completed = ?';
            $values[] = $data['completed'] ? 1 : 0;
            
            if ($data['completed']) {
                $fields[] = 'completed_at = NOW()';
            } else {
                $fields[] = 'completed_at = NULL';
            }
        }
        
        if (empty($fields)) {
            return $this->getTodo($email, $id);
        }
        
        $values[] = $email;
        $values[] = $id;
        
        $stmt = $this->db->prepare('UPDATE webmail_todos SET ' . implode(', ', $fields) . ' WHERE email = ? AND id = ?');
        $stmt->execute($values);
        
        $todo = $this->getTodo($email, $id);
        
        // Sync with calendar if due_date was changed (only for root todos)
        if ($todo && empty($todo['parent_id']) && array_key_exists('due_date', $data)) {
            $this->syncTodoToCalendar($email, $todo);
            // Refresh to get the updated calendar_event_id
            $todo = $this->getTodo($email, $todo['id']);
        }
        
        // Fire CRM automation hook for task changes
        if ($todo) {
            $changeType = array_key_exists('completed', $data) ? ($data['completed'] ? 'completed' : 'reopened') : 'updated';
            $this->fireAutomationTaskChanged($id, $changeType, $todo, $email);
        }
        
        return $todo;
    }
    
    /**
     * Delete a todo
     */
    public function deleteTodo(string $email, int $id): bool
    {
        $email = strtolower($email);
        
        // Get the todo first to check for calendar event
        $todo = $this->getTodo($email, $id);
        
        $stmt = $this->db->prepare('DELETE FROM webmail_todos WHERE email = ? AND id = ?');
        $stmt->execute([$email, $id]);
        $deleted = $stmt->rowCount() > 0;
        
        // Remove linked calendar event if exists
        if ($deleted && $todo && !empty($todo['calendar_event_id'])) {
            $this->removeCalendarEvent($email, $todo);
        }
        
        return $deleted;
    }
    
    /**
     * Toggle todo completion
     */
    public function toggleTodo(string $email, int $id): ?array
    {
        $todo = $this->getTodo($email, $id);
        if (!$todo) return null;
        
        return $this->updateTodo($email, $id, ['completed' => !$todo['completed']]);
    }
    
    /**
     * Bulk-delete every completed todo for a user, including any subtodos of
     * those completed roots (regardless of the subtodo's own completion
     * state). `webmail_todos.parent_id` has no FK constraint, so we cannot
     * rely on ON DELETE CASCADE — orphaned subtodos must be removed
     * explicitly or they'll keep appearing in the panel pointing at a parent
     * that no longer exists.
     *
     * Linked calendar events on the root rows are best-effort removed before
     * the DELETE so the user's calendar doesn't keep stale entries.
     *
     * @return array{deleted: int, ids: array<int>}
     */
    public function deleteAllCompleted(string $email): array
    {
        $email = strtolower($email);

        // 1. Find completed root ids first. Subtodos of these roots will be
        //    pulled into the deletion set even if they themselves are not
        //    completed.
        $stmt = $this->db->prepare(
            'SELECT id FROM webmail_todos
             WHERE email = ? AND completed = 1 AND parent_id IS NULL'
        );
        $stmt->execute([$email]);
        $completedRootIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        // 2. Build the predicate that covers every row we need to remove:
        //    any completed row, plus any row whose parent is one of those
        //    completed roots.
        $params = [$email];
        $where  = 'completed = 1';
        if (!empty($completedRootIds)) {
            $placeholders = implode(',', array_fill(0, count($completedRootIds), '?'));
            $where  = "(completed = 1 OR parent_id IN ($placeholders))";
            $params = array_merge($params, $completedRootIds);
        }

        // 3. Read the rows once so we can clean calendar events + return the
        //    ids for search-index removal.
        $sel = $this->db->prepare(
            "SELECT id, calendar_event_id, parent_id FROM webmail_todos
             WHERE email = ? AND $where"
        );
        $sel->execute($params);
        $rows = $sel->fetchAll();

        $affectedIds = [];
        foreach ($rows as $row) {
            $affectedIds[] = (int)$row['id'];
            if (empty($row['parent_id']) && !empty($row['calendar_event_id'])) {
                try {
                    $this->removeCalendarEvent($email, $row);
                } catch (\Throwable $e) {
                    error_log('TodoService deleteAllCompleted calendar cleanup: ' . $e->getMessage());
                }
            }
        }

        if (empty($affectedIds)) {
            return ['deleted' => 0, 'ids' => []];
        }

        $del = $this->db->prepare(
            "DELETE FROM webmail_todos WHERE email = ? AND $where"
        );
        $del->execute($params);

        return [
            'deleted' => (int)$del->rowCount(),
            'ids' => $affectedIds,
        ];
    }

    /**
     * Reorder todos
     */
    public function reorderTodos(string $email, array $todoIds): bool
    {
        $email = strtolower($email);
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare('UPDATE webmail_todos SET position = ? WHERE email = ? AND id = ?');
            
            foreach ($todoIds as $position => $id) {
                $stmt->execute([$position, $email, $id]);
            }
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("TodoService reorder error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get count of incomplete todos
     */
    public function getIncompleteCount(string $email): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM webmail_todos WHERE email = ? AND completed = 0');
        $stmt->execute([strtolower($email)]);
        return (int)$stmt->fetch()['count'];
    }
    
    /**
     * Convert email date format to MySQL datetime format
     */
    private function convertDateToMySQL(?string $dateStr): ?string
    {
        if (empty($dateStr)) {
            return null;
        }
        
        try {
            $timestamp = strtotime($dateStr);
            if ($timestamp === false) {
                return null;
            }
            return date('Y-m-d H:i:s', $timestamp);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Create todo from email (with optional subtodos from multi-line text)
     */
    public function createFromEmail(string $email, array $emailData, ?string $selectedText = null): ?array
    {
        // Parse selected text for multiple lines/bullets
        $lines = $this->parseTextIntoLines($selectedText);
        
        // If single line or no text, create simple todo
        if (count($lines) <= 1) {
            $title = $selectedText 
                ? (strlen($selectedText) > 100 ? substr($selectedText, 0, 100) . '...' : $selectedText)
                : ($emailData['subject'] ?? 'Email task');
            
            return $this->createTodo($email, [
                'title' => $title,
                'description' => $selectedText ? null : ($emailData['snippet'] ?? null),
                'ref_folder' => $emailData['folder'] ?? null,
                'ref_uid' => $emailData['uid'] ?? null,
                'ref_message_id' => $emailData['message_id'] ?? null,
                'ref_subject' => $emailData['subject'] ?? null,
                'ref_from' => $emailData['from'] ?? null,
                'ref_date' => $this->convertDateToMySQL($emailData['date'] ?? null),
                'ref_selected_text' => $selectedText,
            ]);
        }
        
        // Multiple lines - create parent todo with subtodos
        $parentTitle = $emailData['subject'] ?? 'Tasks from email';
        
        // Create parent todo
        $parent = $this->createTodo($email, [
            'title' => $parentTitle,
            'ref_folder' => $emailData['folder'] ?? null,
            'ref_uid' => $emailData['uid'] ?? null,
            'ref_message_id' => $emailData['message_id'] ?? null,
            'ref_subject' => $emailData['subject'] ?? null,
            'ref_from' => $emailData['from'] ?? null,
            'ref_date' => $this->convertDateToMySQL($emailData['date'] ?? null),
            'ref_selected_text' => $selectedText,
        ]);
        
        if (!$parent) {
            return null;
        }
        
        // Create subtodos for each line
        foreach ($lines as $line) {
            $this->createTodo($email, [
                'title' => $line,
                'parent_id' => $parent['id'],
            ]);
        }
        
        // Return parent with subtodos
        return $this->getTodoWithSubtodos($email, $parent['id']);
    }
    
    /**
     * Parse text into individual lines/bullets for subtodos
     */
    private function parseTextIntoLines(?string $text): array
    {
        if (empty($text)) {
            return [];
        }
        
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Split by newlines
        $lines = explode("\n", $text);
        
        // Clean up each line
        $cleanLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines
            if (empty($line)) continue;
            
            // Remove common bullet/list markers
            $line = preg_replace('/^[\-\*\•\◦\▪\→\>\d]+[\.\)\:]?\s*/', '', $line);
            $line = trim($line);
            
            if (!empty($line) && strlen($line) > 1) {
                // Truncate very long lines
                if (strlen($line) > 200) {
                    $line = substr($line, 0, 200) . '...';
                }
                $cleanLines[] = $line;
            }
        }
        
        return $cleanLines;
    }
    
    /**
     * Get a todo with its subtodos
     */
    public function getTodoWithSubtodos(string $email, int $id): ?array
    {
        $email = strtolower($email);
        
        // Get parent
        $parent = $this->getTodo($email, $id);
        if (!$parent) return null;
        
        // Get subtodos
        $stmt = $this->db->prepare('SELECT * FROM webmail_todos WHERE email = ? AND parent_id = ? ORDER BY position ASC');
        $stmt->execute([$email, $id]);
        $subtodos = $stmt->fetchAll();
        
        $parent['subtodos'] = array_map(function($todo) {
            $todo['completed'] = (bool)$todo['completed'];
            return $todo;
        }, $subtodos);
        
        return $parent;
    }

    /**
     * Fire CRM automation hook when a task changes.
     * Silently ignored if CRM automation is not active.
     */
    private function fireAutomationTaskChanged(int $taskId, string $changeType, array $taskData, string $email): void
    {
        try {
            $automationService = new CrmAutomationService($this->config);
            $automationService->onTaskChanged($taskId, $changeType, $taskData, $email);
        } catch (\Throwable $e) {
            error_log("TodoService: Automation hook error: " . $e->getMessage());
        }
    }
}

