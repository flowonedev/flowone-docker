<?php

namespace Webmail\Services;

class LabelService
{
    private \PDO $db;
    
    public const COLORS = [
        'red' => '#ef4444',
        'orange' => '#f97316',
        'amber' => '#f59e0b',
        'yellow' => '#eab308',
        'lime' => '#84cc16',
        'green' => '#22c55e',
        'emerald' => '#10b981',
        'teal' => '#14b8a6',
        'cyan' => '#06b6d4',
        'sky' => '#0ea5e9',
        'blue' => '#3b82f6',
        'indigo' => '#6366f1',
        'violet' => '#8b5cf6',
        'purple' => '#a855f7',
        'fuchsia' => '#d946ef',
        'pink' => '#ec4899',
        'rose' => '#f43f5e',
    ];
    
    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }
    
    public function getLabels(string $email): array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_labels WHERE email = ? ORDER BY name');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetchAll();
    }
    
    public function createLabel(string $email, string $name, string $color): ?array
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare('SELECT id FROM webmail_labels WHERE email = ? AND name = ?');
        $stmt->execute([$email, $name]);
        if ($stmt->fetch()) return null;
        
        $stmt = $this->db->prepare('INSERT INTO webmail_labels (email, name, color) VALUES (?, ?, ?)');
        $stmt->execute([$email, $name, $color]);
        
        return ['id' => (int)$this->db->lastInsertId(), 'email' => $email, 'name' => $name, 'color' => $color];
    }
    
    public function updateLabel(string $email, int $labelId, string $name, string $color): bool
    {
        $stmt = $this->db->prepare('UPDATE webmail_labels SET name = ?, color = ? WHERE id = ? AND email = ?');
        $stmt->execute([$name, $color, $labelId, strtolower($email)]);
        return $stmt->rowCount() > 0;
    }
    
    public function deleteLabel(string $email, int $labelId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webmail_message_labels WHERE label_id = ?');
        $stmt->execute([$labelId]);
        $stmt = $this->db->prepare('DELETE FROM webmail_labels WHERE id = ? AND email = ?');
        $stmt->execute([$labelId, strtolower($email)]);
        return $stmt->rowCount() > 0;
    }
    
    public function getMessageLabels(string $email, string $messageId): array
    {
        // Normalize message ID (strip angle brackets)
        $messageId = trim($messageId, '<>');
        
        $stmt = $this->db->prepare('
            SELECT l.* FROM webmail_labels l
            JOIN webmail_message_labels ml ON l.id = ml.label_id
            WHERE ml.email = ? AND ml.message_id = ?
        ');
        $stmt->execute([strtolower($email), $messageId]);
        return $stmt->fetchAll();
    }
    
    public function getMessageLabelsForList(string $email, array $messageIds): array
    {
        if (empty($messageIds)) return [];
        
        // Normalize message IDs (strip angle brackets)
        $messageIds = array_map(fn($id) => trim($id, '<>'), $messageIds);
        
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("
            SELECT ml.message_id, l.id, l.name, l.color 
            FROM webmail_message_labels ml
            JOIN webmail_labels l ON l.id = ml.label_id
            WHERE ml.email = ? AND ml.message_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([strtolower($email)], $messageIds));
        
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = $row['message_id'];
            if (!isset($result[$mid])) $result[$mid] = [];
            $result[$mid][] = ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color']];
        }
        return $result;
    }
    
    public function addLabelToMessage(string $email, string $messageId, int $labelId): bool
    {
        $email = strtolower($email);
        // Normalize message ID (strip angle brackets)
        $messageId = trim($messageId, '<>');
        
        $stmt = $this->db->prepare('SELECT 1 FROM webmail_message_labels WHERE email = ? AND message_id = ? AND label_id = ?');
        $stmt->execute([$email, $messageId, $labelId]);
        if ($stmt->fetch()) return true;
        
        $stmt = $this->db->prepare('INSERT INTO webmail_message_labels (email, message_id, label_id) VALUES (?, ?, ?)');
        $stmt->execute([$email, $messageId, $labelId]);
        return true;
    }
    
    public function removeLabelFromMessage(string $email, string $messageId, int $labelId): bool
    {
        // Normalize message ID (strip angle brackets)
        $messageId = trim($messageId, '<>');
        
        error_log("LabelService::removeLabelFromMessage - email: $email, message_id: $messageId, label_id: $labelId");
        
        try {
            $stmt = $this->db->prepare('DELETE FROM webmail_message_labels WHERE email = ? AND message_id = ? AND label_id = ?');
            $stmt->execute([strtolower($email), $messageId, $labelId]);
            $rowCount = $stmt->rowCount();
            error_log("LabelService::removeLabelFromMessage - deleted $rowCount rows");
            return true;
        } catch (\Exception $e) {
            error_log("LabelService::removeLabelFromMessage - ERROR: " . $e->getMessage());
            return false;
        }
    }
    
    public function getColors(): array
    {
        return self::COLORS;
    }
    
    /**
     * Get message IDs that have ALL of the specified labels (by name)
     * Used for filtering search results by label
     */
    public function getMessageIdsWithLabels(string $email, array $labelNames): array
    {
        if (empty($labelNames)) return [];
        
        $email = strtolower($email);
        
        // First get the label IDs for the given names
        $placeholders = implode(',', array_fill(0, count($labelNames), '?'));
        $stmt = $this->db->prepare("
            SELECT id FROM webmail_labels 
            WHERE email = ? AND LOWER(name) IN ($placeholders)
        ");
        $params = array_merge([$email], array_map('strtolower', $labelNames));
        $stmt->execute($params);
        $labelIds = array_column($stmt->fetchAll(), 'id');
        
        if (empty($labelIds)) return [];
        
        // If we need ALL labels (AND), we need messages that have all the label IDs
        // For now we implement ANY (OR) matching which is more intuitive for filtering
        $labelPlaceholders = implode(',', array_fill(0, count($labelIds), '?'));
        $stmt = $this->db->prepare("
            SELECT DISTINCT message_id FROM webmail_message_labels 
            WHERE email = ? AND label_id IN ($labelPlaceholders)
        ");
        $stmt->execute(array_merge([$email], $labelIds));
        
        return array_column($stmt->fetchAll(), 'message_id');
    }
}
