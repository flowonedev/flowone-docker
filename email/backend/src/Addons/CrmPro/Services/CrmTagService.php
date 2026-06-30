<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmTagService
 * 
 * Manages tags, tag assignments, custom field definitions, and custom field values.
 */
class CrmTagService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Tags
    // =========================================================================

    public function listTags(string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT t.*, 
                   (SELECT COUNT(*) FROM crm_tag_assignments ta WHERE ta.tag_id = t.id) as usage_count
            FROM crm_tags t 
            WHERE t.user_email = ?
            ORDER BY t.tag_group, t.name
        ');
        $stmt->execute([$userEmail]);
        return $stmt->fetchAll();
    }

    public function createTag(string $userEmail, array $data): array
    {
        $stmt = $this->db->prepare('
            INSERT INTO crm_tags (user_email, name, color, tag_group)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $userEmail,
            $data['name'],
            $data['color'] ?? '#6366f1',
            $data['tag_group'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM crm_tags WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function updateTag(int $id, string $userEmail, array $data): ?array
    {
        $fields = [];
        $params = [];
        foreach (['name', 'color', 'tag_group'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return null;

        $params[] = $id;
        $params[] = $userEmail;
        $this->db->prepare("UPDATE crm_tags SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?")
            ->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM crm_tags WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function deleteTag(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_tags WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Tag Assignments
    // =========================================================================

    public function assignTag(int $clientId, int $tagId): bool
    {
        try {
            $this->db->prepare('INSERT IGNORE INTO crm_tag_assignments (client_id, tag_id) VALUES (?, ?)')
                ->execute([$clientId, $tagId]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function removeTag(int $clientId, int $tagId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_tag_assignments WHERE client_id = ? AND tag_id = ?');
        $stmt->execute([$clientId, $tagId]);
        return $stmt->rowCount() > 0;
    }

    public function getClientTags(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT t.* FROM crm_tags t
            JOIN crm_tag_assignments ta ON ta.tag_id = t.id
            WHERE ta.client_id = ?
            ORDER BY t.name
        ');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Custom Field Definitions
    // =========================================================================

    public function listFieldDefinitions(string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM crm_custom_field_definitions
            WHERE user_email = ?
            ORDER BY sort_order, field_name
        ');
        $stmt->execute([$userEmail]);
        $fields = $stmt->fetchAll();
        foreach ($fields as &$f) {
            if ($f['field_options']) {
                $f['field_options'] = json_decode($f['field_options'], true);
            }
        }
        return $fields;
    }

    public function createFieldDefinition(string $userEmail, array $data): array
    {
        $options = null;
        if (!empty($data['field_options']) && is_array($data['field_options'])) {
            $options = json_encode($data['field_options']);
        }

        $stmt = $this->db->prepare('
            INSERT INTO crm_custom_field_definitions (user_email, field_name, field_type, field_options, is_required, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userEmail,
            $data['field_name'],
            $data['field_type'] ?? 'text',
            $options,
            (int)($data['is_required'] ?? 0),
            (int)($data['sort_order'] ?? 0),
        ]);

        $id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM crm_custom_field_definitions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function updateFieldDefinition(int $id, string $userEmail, array $data): ?array
    {
        $fields = [];
        $params = [];
        foreach (['field_name', 'field_type', 'is_required', 'sort_order'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (array_key_exists('field_options', $data)) {
            $fields[] = 'field_options = ?';
            $params[] = is_array($data['field_options']) ? json_encode($data['field_options']) : $data['field_options'];
        }
        if (empty($fields)) return null;

        $params[] = $id;
        $params[] = $userEmail;
        $this->db->prepare("UPDATE crm_custom_field_definitions SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?")
            ->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM crm_custom_field_definitions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function deleteFieldDefinition(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_custom_field_definitions WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Custom Field Values
    // =========================================================================

    public function getClientFieldValues(int $clientId, string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT cfd.*, cfv.field_value, cfv.id as value_id
            FROM crm_custom_field_definitions cfd
            LEFT JOIN crm_custom_field_values cfv ON cfv.field_id = cfd.id AND cfv.client_id = ?
            WHERE cfd.user_email = ?
            ORDER BY cfd.sort_order, cfd.field_name
        ');
        $stmt->execute([$clientId, $userEmail]);
        $fields = $stmt->fetchAll();
        foreach ($fields as &$f) {
            if ($f['field_options']) {
                $f['field_options'] = json_decode($f['field_options'], true);
            }
        }
        return $fields;
    }

    public function setClientFieldValue(int $clientId, int $fieldId, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->db->prepare('DELETE FROM crm_custom_field_values WHERE client_id = ? AND field_id = ?')
                ->execute([$clientId, $fieldId]);
        } else {
            $this->db->prepare('
                INSERT INTO crm_custom_field_values (client_id, field_id, field_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)
            ')->execute([$clientId, $fieldId, $value]);
        }
    }

    public function setClientFieldValues(int $clientId, array $fieldValues): void
    {
        foreach ($fieldValues as $fieldId => $value) {
            $this->setClientFieldValue($clientId, (int)$fieldId, $value);
        }
    }
}

