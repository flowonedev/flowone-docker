<?php

namespace Webmail\Addons\Chat\Services;

/**
 * CategoryService - Channel Category Management
 *
 * Manages Discord-style channel categories: named groups
 * that hold channels in a user-defined order.
 */
class CategoryService
{
    private \PDO $db;
    private array $config;
    private ?\Webmail\Services\RedisCacheService $redis = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureSchema());

        try {
            $this->redis = new \Webmail\Services\RedisCacheService($config);
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    private function ensureSchema(): void
    {
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'chat_channel_categories'");
            if ($check->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE chat_channel_categories (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        organization_domain VARCHAR(255) NOT NULL,
                        name VARCHAR(100) NOT NULL,
                        position INT UNSIGNED DEFAULT 0,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_cat_domain (organization_domain)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                error_log('CategoryService: Created chat_channel_categories table');
            }

            $cols = ['category_id', 'position'];
            foreach ($cols as $col) {
                $stmt = $this->db->query("SHOW COLUMNS FROM chat_conversations LIKE '{$col}'");
                if ($stmt->rowCount() === 0) {
                    if ($col === 'category_id') {
                        $this->db->exec('ALTER TABLE chat_conversations ADD COLUMN category_id INT UNSIGNED NULL');
                    } else {
                        $this->db->exec('ALTER TABLE chat_conversations ADD COLUMN position INT UNSIGNED DEFAULT 0');
                    }
                    error_log("CategoryService: Added column {$col} to chat_conversations");
                }
            }
        } catch (\PDOException $e) {
            error_log('CategoryService: ensureSchema error: ' . $e->getMessage());
        }
    }

    private function getColleague(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ? LIMIT 1');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function getDomain(string $email): string
    {
        return strtolower(substr($email, strpos($email, '@') + 1));
    }

    private function broadcastToDomain(string $domain, string $eventType, array $payload): void
    {
        if (!$this->redis) return;

        $stmt = $this->db->prepare('SELECT email FROM organization_colleagues WHERE organization_domain = ?');
        $stmt->execute([$domain]);
        $emails = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($emails as $email) {
            try {
                $this->redis->publish("webmail:mailbox:{$email}", json_encode([
                    'type' => $eventType,
                    'payload' => $payload
                ]));
            } catch (\Throwable $e) {
                // continue
            }
        }
    }

    /**
     * List all categories for a domain, ordered by position, with their channels.
     */
    public function listCategories(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        $categories = $this->db->prepare("
            SELECT id, name, position, created_by, created_at
            FROM chat_channel_categories
            WHERE organization_domain = ?
            ORDER BY position ASC, id ASC
        ");
        $categories->execute([$domain]);
        $cats = $categories->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($cats as &$cat) {
            $cat['id'] = (int)$cat['id'];
            $cat['position'] = (int)$cat['position'];
            $cat['created_by'] = $cat['created_by'] ? (int)$cat['created_by'] : null;
        }

        // Channels grouped by category
        $channelStmt = $this->db->prepare("
            SELECT c.id, c.name, c.slug, c.category_id, c.position, c.is_public, c.is_default, c.topic,
                   (SELECT COUNT(*) FROM chat_participants WHERE conversation_id = c.id) as member_count,
                   (SELECT 1 FROM chat_participants WHERE conversation_id = c.id AND colleague_id = ?) as is_member
            FROM chat_conversations c
            WHERE c.organization_domain = ? AND c.type = 'channel'
              AND (c.is_public = 1 OR EXISTS (SELECT 1 FROM chat_participants WHERE conversation_id = c.id AND colleague_id = ?))
            ORDER BY c.position ASC, c.name ASC
        ");
        $channelStmt->execute([$colleague['id'], $domain, $colleague['id']]);
        $channels = $channelStmt->fetchAll(\PDO::FETCH_ASSOC);

        $channelsByCategory = [];
        $uncategorized = [];
        foreach ($channels as $ch) {
            $ch['id'] = (int)$ch['id'];
            $ch['category_id'] = $ch['category_id'] ? (int)$ch['category_id'] : null;
            $ch['position'] = (int)$ch['position'];
            $ch['member_count'] = (int)$ch['member_count'];
            $ch['is_member'] = (bool)$ch['is_member'];
            $ch['is_public'] = (bool)$ch['is_public'];
            $ch['is_default'] = (bool)$ch['is_default'];

            if ($ch['category_id']) {
                $channelsByCategory[$ch['category_id']][] = $ch;
            } else {
                $uncategorized[] = $ch;
            }
        }

        foreach ($cats as &$cat) {
            $cat['channels'] = $channelsByCategory[$cat['id']] ?? [];
        }

        return [
            'success' => true,
            'categories' => $cats,
            'uncategorized' => $uncategorized,
        ];
    }

    /**
     * Create a new category.
     */
    public function createCategory(string $userEmail, string $name): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $name = trim($name);
        if (empty($name) || strlen($name) > 100) {
            return ['success' => false, 'error' => 'Category name must be 1-100 characters'];
        }

        $domain = $colleague['organization_domain'];

        // Get next position
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 as next_pos FROM chat_channel_categories WHERE organization_domain = ?');
        $stmt->execute([$domain]);
        $nextPos = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO chat_channel_categories (organization_domain, name, position, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$domain, $name, $nextPos, $colleague['id']]);
        $catId = (int)$this->db->lastInsertId();

        $category = [
            'id' => $catId,
            'name' => $name,
            'position' => $nextPos,
            'created_by' => (int)$colleague['id'],
            'channels' => [],
        ];

        $this->broadcastToDomain($domain, 'CHANNEL_CATEGORY_CREATED', ['category' => $category]);

        return ['success' => true, 'category' => $category];
    }

    /**
     * Update a category (rename).
     */
    public function updateCategory(string $userEmail, int $categoryId, array $data): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        $stmt = $this->db->prepare('SELECT * FROM chat_channel_categories WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$categoryId, $domain]);
        $cat = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$cat) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (empty($name) || strlen($name) > 100) {
                return ['success' => false, 'error' => 'Category name must be 1-100 characters'];
            }
            $updates[] = 'name = ?';
            $params[] = $name;
        }

        if (isset($data['position'])) {
            $updates[] = 'position = ?';
            $params[] = (int)$data['position'];
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'Nothing to update'];
        }

        $params[] = $categoryId;
        $this->db->prepare('UPDATE chat_channel_categories SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

        $stmt = $this->db->prepare('SELECT id, name, position, created_by, created_at FROM chat_channel_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $updated = $stmt->fetch(\PDO::FETCH_ASSOC);
        $updated['id'] = (int)$updated['id'];
        $updated['position'] = (int)$updated['position'];

        $this->broadcastToDomain($domain, 'CHANNEL_CATEGORY_UPDATED', ['category' => $updated]);

        return ['success' => true, 'category' => $updated];
    }

    /**
     * Delete a category. Channels in it become uncategorized.
     */
    public function deleteCategory(string $userEmail, int $categoryId): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        $stmt = $this->db->prepare('SELECT id FROM chat_channel_categories WHERE id = ? AND organization_domain = ?');
        $stmt->execute([$categoryId, $domain]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        // Move channels to uncategorized
        $this->db->prepare('UPDATE chat_conversations SET category_id = NULL WHERE category_id = ?')->execute([$categoryId]);

        $this->db->prepare('DELETE FROM chat_channel_categories WHERE id = ?')->execute([$categoryId]);

        $this->broadcastToDomain($domain, 'CHANNEL_CATEGORY_DELETED', ['category_id' => $categoryId]);

        return ['success' => true];
    }

    /**
     * Bulk reorder categories and their channels.
     * Payload: { categories: [{ id, position, channels: [{ id, position }] }] }
     */
    public function reorder(string $userEmail, array $categoriesData): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        try {
            $this->db->beginTransaction();

            $catStmt = $this->db->prepare('UPDATE chat_channel_categories SET position = ? WHERE id = ? AND organization_domain = ?');
            $chStmt = $this->db->prepare('UPDATE chat_conversations SET category_id = ?, position = ? WHERE id = ? AND type = \'channel\'');

            foreach ($categoriesData as $catData) {
                $catId = (int)($catData['id'] ?? 0);
                $catPos = (int)($catData['position'] ?? 0);

                if ($catId > 0) {
                    $catStmt->execute([$catPos, $catId, $domain]);
                }

                foreach (($catData['channels'] ?? []) as $chData) {
                    $chId = (int)($chData['id'] ?? 0);
                    $chPos = (int)($chData['position'] ?? 0);
                    if ($chId > 0) {
                        $chStmt->execute([$catId > 0 ? $catId : null, $chPos, $chId]);
                    }
                }
            }

            // Handle uncategorized channels (catId = 0 means uncategorized)
            if (isset($categoriesData[0]) && ($categoriesData[0]['id'] ?? 0) === 0) {
                foreach (($categoriesData[0]['channels'] ?? []) as $chData) {
                    $chId = (int)($chData['id'] ?? 0);
                    $chPos = (int)($chData['position'] ?? 0);
                    if ($chId > 0) {
                        $chStmt->execute([null, $chPos, $chId]);
                    }
                }
            }

            $this->db->commit();

            $this->broadcastToDomain($domain, 'CHANNEL_CATEGORIES_REORDERED', []);

            return ['success' => true];
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log('CategoryService::reorder error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Reorder failed'];
        }
    }

    /**
     * Assign a channel to a category.
     */
    public function assignChannel(string $userEmail, int $channelId, ?int $categoryId): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $domain = $colleague['organization_domain'];

        if ($categoryId) {
            $stmt = $this->db->prepare('SELECT id FROM chat_channel_categories WHERE id = ? AND organization_domain = ?');
            $stmt->execute([$categoryId, $domain]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Category not found'];
            }
        }

        $this->db->prepare("UPDATE chat_conversations SET category_id = ? WHERE id = ? AND type = 'channel'")->execute([$categoryId, $channelId]);

        $this->broadcastToDomain($domain, 'CHANNEL_CATEGORY_ASSIGNED', [
            'channel_id' => $channelId,
            'category_id' => $categoryId,
        ]);

        return ['success' => true];
    }
}
