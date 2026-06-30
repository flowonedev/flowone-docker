<?php

namespace Webmail\Services;

class FilterService
{
    private \PDO $db;
    
    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTableExists();
    }
    
    private function ensureTableExists(): void
    {
        try {
            // Use TEXT instead of JSON for broader compatibility
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS webmail_filters (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    enabled TINYINT(1) DEFAULT 1,
                    priority INT DEFAULT 0,
                    conditions TEXT NOT NULL,
                    actions TEXT NOT NULL,
                    stop_processing TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_email (email),
                    INDEX idx_enabled (enabled)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\PDOException $e) {
            error_log("FilterService table creation error: " . $e->getMessage());
            // Table might already exist, continue
        }
    }
    
    public function getFilters(string $email): array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_filters WHERE email = ? ORDER BY priority DESC, id ASC');
        $stmt->execute([strtolower($email)]);
        $filters = $stmt->fetchAll();
        
        // Decode JSON fields
        return array_map(function($f) {
            $f['conditions'] = json_decode($f['conditions'], true) ?: [];
            $f['actions'] = json_decode($f['actions'], true) ?: [];
            $f['enabled'] = (bool)$f['enabled'];
            $f['stop_processing'] = (bool)$f['stop_processing'];
            return $f;
        }, $filters);
    }
    
    public function getFilter(string $email, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_filters WHERE email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        $filter = $stmt->fetch();
        
        if (!$filter) return null;
        
        $filter['conditions'] = json_decode($filter['conditions'], true) ?: [];
        $filter['actions'] = json_decode($filter['actions'], true) ?: [];
        $filter['enabled'] = (bool)$filter['enabled'];
        $filter['stop_processing'] = (bool)$filter['stop_processing'];
        
        return $filter;
    }
    
    public function createFilter(string $email, array $data): ?array
    {
        $email = strtolower($email);
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO webmail_filters (email, name, enabled, priority, conditions, actions, stop_processing) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            
            $enabled = isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1;
            $stopProcessing = isset($data['stop_processing']) ? ($data['stop_processing'] ? 1 : 0) : 0;
            $conditions = is_array($data['conditions'] ?? null) ? json_encode($data['conditions']) : '[]';
            $actions = is_array($data['actions'] ?? null) ? json_encode($data['actions']) : '[]';
            
            error_log("Creating filter with: email=$email, name=" . ($data['name'] ?? 'Untitled') . ", conditions=$conditions, actions=$actions");
            
            $stmt->execute([
                $email,
                $data['name'] ?? 'Untitled Filter',
                $enabled,
                (int)($data['priority'] ?? 0),
                $conditions,
                $actions,
                $stopProcessing,
            ]);
            
            $id = (int)$this->db->lastInsertId();
            error_log("Filter created with ID: $id");
            
            return $this->getFilter($email, $id);
        } catch (\PDOException $e) {
            error_log("FilterService createFilter PDO error: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function updateFilter(string $email, int $id, array $data): ?array
    {
        $email = strtolower($email);
        
        $fields = [];
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $values[] = $data['name'];
        }
        if (isset($data['enabled'])) {
            $fields[] = 'enabled = ?';
            $values[] = $data['enabled'] ? 1 : 0;
        }
        if (isset($data['priority'])) {
            $fields[] = 'priority = ?';
            $values[] = $data['priority'];
        }
        if (isset($data['conditions'])) {
            $fields[] = 'conditions = ?';
            $values[] = json_encode($data['conditions']);
        }
        if (isset($data['actions'])) {
            $fields[] = 'actions = ?';
            $values[] = json_encode($data['actions']);
        }
        if (isset($data['stop_processing'])) {
            $fields[] = 'stop_processing = ?';
            $values[] = $data['stop_processing'] ? 1 : 0;
        }
        
        if (empty($fields)) return $this->getFilter($email, $id);
        
        $values[] = $email;
        $values[] = $id;
        
        $stmt = $this->db->prepare('UPDATE webmail_filters SET ' . implode(', ', $fields) . ' WHERE email = ? AND id = ?');
        $stmt->execute($values);
        
        return $this->getFilter($email, $id);
    }
    
    public function deleteFilter(string $email, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_filters WHERE email = ? AND id = ?');
        $stmt->execute([strtolower($email), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Update folder references in all filters when a folder is renamed or moved
     * This ensures filters don't break when users rename/move their folders
     * Also handles child folders: if parent is renamed, all child references are updated
     */
    public function updateFolderReferences(string $email, string $oldFolder, string $newFolder): int
    {
        $email = strtolower($email);
        $updatedCount = 0;
        
        // Get all filters for this email
        $filters = $this->getFilters($email);
        
        foreach ($filters as $filter) {
            $actions = $filter['actions'] ?? [];
            $updated = false;
            
            // Check each action for folder references
            foreach ($actions as &$action) {
                if ($action['action'] === 'move') {
                    $currentFolder = $action['value'];
                    
                    // Exact match - folder was directly renamed/moved
                    if ($currentFolder === $oldFolder) {
                        $action['value'] = $newFolder;
                        $updated = true;
                    }
                    // Child folder match - parent was renamed/moved
                    // e.g., if INBOX.Work -> INBOX.Projects, then INBOX.Work.SubFolder -> INBOX.Projects.SubFolder
                    elseif (strpos($currentFolder, $oldFolder . '.') === 0) {
                        // Replace the old parent path with the new one
                        $action['value'] = $newFolder . substr($currentFolder, strlen($oldFolder));
                        $updated = true;
                    }
                }
            }
            
            if ($updated) {
                // Update the filter in database
                $this->updateFilter($email, $filter['id'], ['actions' => $actions]);
                $updatedCount++;
                error_log("Updated filter '{$filter['name']}' (ID: {$filter['id']}): updated folder reference after '$oldFolder' -> '$newFolder'");
            }
        }
        
        return $updatedCount;
    }
    
    /**
     * Update label references in all filters when a label is renamed
     */
    public function updateLabelReferences(string $email, string $oldName, string $newName): int
    {
        $email = strtolower($email);
        $updatedCount = 0;
        
        $filters = $this->getFilters($email);
        
        foreach ($filters as $filter) {
            $actions = $filter['actions'] ?? [];
            $conditions = $filter['conditions'] ?? [];
            $updated = false;
            
            // Check actions for label references
            foreach ($actions as &$action) {
                if ($action['action'] === 'label' && $action['value'] === $oldName) {
                    $action['value'] = $newName;
                    $updated = true;
                }
            }
            
            // Check conditions for has_label references (in groups format)
            if (isset($conditions['groups'])) {
                foreach ($conditions['groups'] as &$group) {
                    if (isset($group['rules'])) {
                        foreach ($group['rules'] as &$rule) {
                            if ($rule['field'] === 'has_label' && $rule['value'] === $oldName) {
                                $rule['value'] = $newName;
                                $updated = true;
                            }
                        }
                    }
                }
            }
            
            // Check legacy format conditions
            if (isset($conditions['rules'])) {
                foreach ($conditions['rules'] as &$rule) {
                    if ($rule['field'] === 'has_label' && $rule['value'] === $oldName) {
                        $rule['value'] = $newName;
                        $updated = true;
                    }
                }
            }
            
            if ($updated) {
                $updateData = ['actions' => $actions];
                if (isset($conditions['groups']) || isset($conditions['rules'])) {
                    $updateData['conditions'] = $conditions;
                }
                $this->updateFilter($email, $filter['id'], $updateData);
                $updatedCount++;
                error_log("Updated filter '{$filter['name']}' (ID: {$filter['id']}): changed label '$oldName' to '$newName'");
            }
        }
        
        return $updatedCount;
    }
    
    /**
     * Check if a message matches filter conditions
     * Supports both legacy format (flat rules array) and new format (condition groups)
     */
    public function matchesFilter(array $message, array $conditions): bool
    {
        if (empty($conditions)) return false;
        
        // Check if using new groups format
        if (isset($conditions['groups']) && is_array($conditions['groups'])) {
            return $this->matchesFilterGroups($message, $conditions);
        }
        
        // Legacy format with flat rules array
        $mainMatch = $this->matchesFilterRules($message, $conditions);
        
        if (!$mainMatch) {
            return false;
        }
        
        // Check exceptions for legacy format too
        $exceptions = $conditions['exceptions'] ?? null;
        if ($exceptions && !empty($exceptions['rules'])) {
            $exceptionMatches = $this->matchesFilterRules($message, $exceptions);
            if ($exceptionMatches) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Match against condition groups (AND/OR between groups)
     */
    private function matchesFilterGroups(array $message, array $conditions): bool
    {
        $groups = $conditions['groups'] ?? [];
        $groupsMatchType = $conditions['match'] ?? 'all'; // How to combine group results
        
        if (empty($groups)) return false;
        
        $groupResults = [];
        
        foreach ($groups as $group) {
            $groupResult = $this->matchesFilterRules($message, $group);
            $groupResults[] = $groupResult;
        }
        
        if (empty($groupResults)) return false;
        
        // Combine group results based on match type
        $mainConditionsMatch = false;
        if ($groupsMatchType === 'all') {
            // All groups must match (AND)
            $mainConditionsMatch = !in_array(false, $groupResults);
        } else {
            // Any group can match (OR)
            $mainConditionsMatch = in_array(true, $groupResults);
        }
        
        // If main conditions don't match, no need to check exceptions
        if (!$mainConditionsMatch) {
            return false;
        }
        
        // Check exceptions - if ANY exception matches, exclude this message
        $exceptions = $conditions['exceptions'] ?? null;
        if ($exceptions && !empty($exceptions['rules'])) {
            $exceptionMatchType = $exceptions['match'] ?? 'any'; // Usually 'any' for exceptions
            $exceptionMatches = $this->matchesFilterRules($message, $exceptions);
            
            // If exception matches, exclude the message (return false)
            if ($exceptionMatches) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Match against a flat array of rules (original logic)
     */
    private function matchesFilterRules(array $message, array $conditions): bool
    {
        $matchType = $conditions['match'] ?? 'all'; // 'all' or 'any'
        $rules = $conditions['rules'] ?? [];
        
        if (empty($rules)) return false;
        
        $matches = [];
        
        foreach ($rules as $rule) {
            $field = $rule['field'] ?? '';
            $operator = $rule['operator'] ?? 'contains';
            $value = strtolower($rule['value'] ?? '');
            
            // Skip empty values unless operator doesn't need one
            if (!$value && !in_array($operator, ['is_empty', 'is_not_empty'])) continue;
            
            $fieldValue = $this->getFieldValue($message, $field);
            $matches[] = $this->evaluateRule($fieldValue, $operator, $value);
        }
        
        if (empty($matches)) return false;
        
        if ($matchType === 'all') {
            return !in_array(false, $matches);
        } else {
            return in_array(true, $matches);
        }
    }
    
    private function getFieldValue(array $message, string $field): string
    {
        switch ($field) {
            case 'from':
                $from = $message['from'] ?? [];
                if (is_array($from) && !empty($from)) {
                    return strtolower(($from[0]['name'] ?? '') . ' ' . ($from[0]['email'] ?? ''));
                }
                return '';
            case 'to':
                $to = $message['to'] ?? [];
                if (is_array($to)) {
                    return strtolower(implode(' ', array_map(fn($t) => ($t['name'] ?? '') . ' ' . ($t['email'] ?? ''), $to)));
                }
                return '';
            case 'subject':
                return strtolower($message['subject'] ?? '');
            case 'body':
                return strtolower(($message['body_text'] ?? '') . ' ' . strip_tags($message['body_html'] ?? ''));
            case 'has_attachment':
                return !empty($message['attachments']) ? 'true' : 'false';
            case 'has_label':
                // Return space-separated list of label names for the message
                $labels = $message['labels'] ?? [];
                if (is_array($labels)) {
                    return strtolower(implode(' ', array_map(fn($l) => $l['name'] ?? '', $labels)));
                }
                return '';
            case 'linked_account':
                return strtolower($message['linked_account'] ?? '');
            default:
                return '';
        }
    }
    
    private function evaluateRule(string $fieldValue, string $operator, string $value): bool
    {
        switch ($operator) {
            case 'contains':
                return str_contains($fieldValue, $value);
            case 'not_contains':
                return !str_contains($fieldValue, $value);
            case 'equals':
                return $fieldValue === $value;
            case 'not_equals':
                return $fieldValue !== $value;
            case 'starts_with':
                return str_starts_with($fieldValue, $value);
            case 'ends_with':
                return str_ends_with($fieldValue, $value);
            case 'is_empty':
                return empty(trim($fieldValue));
            case 'is_not_empty':
                return !empty(trim($fieldValue));
            case 'matches_regex':
                return @preg_match('/' . $value . '/i', $fieldValue) === 1;
            default:
                return false;
        }
    }
    
    /**
     * Get available filter fields
     */
    public static function getAvailableFields(): array
    {
        return [
            ['id' => 'from', 'name' => 'From', 'description' => 'Sender name and email'],
            ['id' => 'to', 'name' => 'To', 'description' => 'Recipients'],
            ['id' => 'subject', 'name' => 'Subject', 'description' => 'Email subject line'],
            ['id' => 'body', 'name' => 'Body', 'description' => 'Email body content'],
            ['id' => 'has_attachment', 'name' => 'Has Attachment', 'description' => 'Whether email has attachments'],
            ['id' => 'has_label', 'name' => 'Has Label', 'description' => 'Message has specific label'],
            ['id' => 'linked_account', 'name' => 'Linked Account', 'description' => 'Source linked account email (for synced emails)'],
        ];
    }
    
    /**
     * Get available filter operators
     */
    public static function getAvailableOperators(): array
    {
        return [
            ['id' => 'contains', 'name' => 'contains'],
            ['id' => 'not_contains', 'name' => 'does not contain'],
            ['id' => 'equals', 'name' => 'equals'],
            ['id' => 'not_equals', 'name' => 'does not equal'],
            ['id' => 'starts_with', 'name' => 'starts with'],
            ['id' => 'ends_with', 'name' => 'ends with'],
            ['id' => 'is_empty', 'name' => 'is empty'],
            ['id' => 'is_not_empty', 'name' => 'is not empty'],
            ['id' => 'matches_regex', 'name' => 'matches regex'],
        ];
    }
    
    /**
     * Get available filter actions
     */
    public static function getAvailableActions(): array
    {
        return [
            ['id' => 'move', 'name' => 'Move to folder', 'hasValue' => true, 'valueType' => 'folder'],
            ['id' => 'delete', 'name' => 'Delete', 'hasValue' => false],
            ['id' => 'mark_read', 'name' => 'Mark as read', 'hasValue' => false],
            ['id' => 'mark_unread', 'name' => 'Mark as unread', 'hasValue' => false],
            ['id' => 'star', 'name' => 'Add star', 'hasValue' => false],
            ['id' => 'unstar', 'name' => 'Remove star', 'hasValue' => false],
            ['id' => 'label', 'name' => 'Apply label', 'hasValue' => true, 'valueType' => 'label'],
        ];
    }
}

