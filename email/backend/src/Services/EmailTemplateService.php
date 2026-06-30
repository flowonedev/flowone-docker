<?php

namespace Webmail\Services;

class EmailTemplateService
{
    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);

        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }

    private function ensureTableExists(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'email_templates'");
            if ($result->rowCount() === 0) {
                $migrationFile = __DIR__ . '/../../migrations/039_email_templates.sql';
                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            $this->db->exec($statement);
                        }
                    }
                    error_log("EmailTemplateService: Created email_templates table from migration");
                }
            }
        } catch (\PDOException $e) {
            error_log("EmailTemplateService: Failed to run migration: " . $e->getMessage());
        }
    }

    /**
     * Get domain from email address
     */
    private function getDomain(string $email): string
    {
        $parts = explode('@', $email);
        return strtolower($parts[1] ?? $parts[0]);
    }

    /**
     * Sanitize string for database storage
     */
    private function sanitizeString(?string $text): ?string
    {
        if ($text === null) return null;
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return $text;
    }

    /**
     * List all templates accessible by user (own + shared from org)
     */
    public function list(string $userEmail): array
    {
        $domain = $this->getDomain($userEmail);

        $stmt = $this->db->prepare("
            SELECT * FROM email_templates
            WHERE created_by = ? OR (organization_domain = ? AND is_shared = 1)
            ORDER BY sort_order ASC, created_at DESC
        ");
        $stmt->execute([strtolower($userEmail), $domain]);

        return $stmt->fetchAll();
    }

    /**
     * Get a single template by ID (with access check)
     */
    public function get(int $id, string $userEmail): ?array
    {
        $domain = $this->getDomain($userEmail);

        $stmt = $this->db->prepare("
            SELECT * FROM email_templates
            WHERE id = ? AND (created_by = ? OR (organization_domain = ? AND is_shared = 1))
        ");
        $stmt->execute([$id, strtolower($userEmail), $domain]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Create a new template
     */
    public function create(string $userEmail, array $data): array
    {
        $domain = $this->getDomain($userEmail);
        $email = strtolower($userEmail);

        $stmt = $this->db->prepare("
            INSERT INTO email_templates 
                (created_by, organization_domain, name, description, category, icon, html_content, thumbnail, is_shared, sort_order)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $email,
            $domain,
            $this->sanitizeString($data['name'] ?? 'Untitled Block'),
            $this->sanitizeString($data['description'] ?? null),
            $data['category'] ?? 'custom',
            $data['icon'] ?? 'dashboard_customize',
            $data['html_content'] ?? '<p></p>',
            $data['thumbnail'] ?? null,
            (int)($data['is_shared'] ?? 0),
            (int)($data['sort_order'] ?? 0),
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->get($id, $userEmail);
    }

    /**
     * Update a template (only owner can edit)
     */
    public function update(int $id, string $userEmail, array $data): ?array
    {
        // Verify ownership
        $stmt = $this->db->prepare("SELECT id FROM email_templates WHERE id = ? AND created_by = ?");
        $stmt->execute([$id, strtolower($userEmail)]);
        if (!$stmt->fetch()) {
            return null;
        }

        $fields = [];
        $values = [];

        $allowedFields = ['name', 'description', 'category', 'icon', 'html_content', 'thumbnail', 'is_shared', 'sort_order'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if (in_array($field, ['name', 'description'])) {
                    $fields[] = "$field = ?";
                    $values[] = $this->sanitizeString($data[$field]);
                } elseif (in_array($field, ['is_shared', 'sort_order'])) {
                    $fields[] = "$field = ?";
                    $values[] = (int) $data[$field];
                } else {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return $this->get($id, $userEmail);
        }

        $values[] = $id;
        $sql = "UPDATE email_templates SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($values);

        return $this->get($id, $userEmail);
    }

    /**
     * Delete a template (only owner can delete)
     */
    public function delete(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare("DELETE FROM email_templates WHERE id = ? AND created_by = ?");
        $stmt->execute([$id, strtolower($userEmail)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reorder templates
     */
    public function reorder(string $userEmail, array $order): bool
    {
        $email = strtolower($userEmail);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE email_templates SET sort_order = ? WHERE id = ? AND created_by = ?");
            foreach ($order as $index => $id) {
                $stmt->execute([$index, $id, $email]);
            }
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("EmailTemplateService reorder error: " . $e->getMessage());
            return false;
        }
    }
}

