<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubRoleService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Role CRUD
    // =========================================================================

    public function getRoles(): array
    {
        $stmt = $this->db->query("
            SELECT r.*, COUNT(s.id) AS status_count
            FROM projecthub_roles r
            LEFT JOIN projecthub_role_statuses s ON s.role_id = r.id
            GROUP BY r.id
            ORDER BY r.sort_order ASC, r.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getRole(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_roles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createRole(array $data, string $createdBy): ?array
    {
        $slug = $this->slugify($data['name']);
        $existing = $this->db->prepare("SELECT id FROM projecthub_roles WHERE slug = ?");
        $existing->execute([$slug]);
        if ($existing->fetch()) {
            $slug .= '-' . time();
        }

        $maxOrder = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM projecthub_roles")->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO projecthub_roles (name, slug, color, icon, description, sort_order, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $slug,
            $data['color'] ?? '#6366f1',
            $data['icon'] ?? 'badge',
            $data['description'] ?? null,
            $maxOrder + 1,
            $createdBy,
        ]);

        return $this->getRole((int)$this->db->lastInsertId());
    }

    public function updateRole(int $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        foreach (['name', 'color', 'icon', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) return $this->getRole($id);

        $values[] = $id;
        $this->db->prepare("UPDATE projecthub_roles SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        return $this->getRole($id);
    }

    public function deleteRole(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_roles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reorderRoles(array $ids): void
    {
        $stmt = $this->db->prepare("UPDATE projecthub_roles SET sort_order = ? WHERE id = ?");
        foreach ($ids as $order => $id) {
            $stmt->execute([$order, (int)$id]);
        }
    }

    // =========================================================================
    // Role Status CRUD
    // =========================================================================

    public function getRoleStatuses(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_role_statuses
            WHERE role_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createRoleStatus(int $roleId, array $data): ?array
    {
        $slug = $this->slugify($data['name']);

        $existing = $this->db->prepare(
            "SELECT id FROM projecthub_role_statuses WHERE role_id = ? AND slug = ?"
        );
        $existing->execute([$roleId, $slug]);
        if ($existing->fetch()) {
            $slug .= '_' . time();
        }

        $stmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM projecthub_role_statuses WHERE role_id = ?");
        $stmt->execute([$roleId]);
        $maxOrder = (int)$stmt->fetchColumn();

        $ins = $this->db->prepare("
            INSERT INTO projecthub_role_statuses (role_id, name, slug, color, icon, is_terminal, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $roleId,
            $data['name'],
            $slug,
            $data['color'] ?? '#6b7280',
            $data['icon'] ?? 'circle',
            $data['is_terminal'] ?? 0,
            $maxOrder + 1,
        ]);

        $newId = (int)$this->db->lastInsertId();
        $fetch = $this->db->prepare("SELECT * FROM projecthub_role_statuses WHERE id = ?");
        $fetch->execute([$newId]);
        return $fetch->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateRoleStatus(int $statusId, array $data): ?array
    {
        $fields = [];
        $values = [];

        foreach (['name', 'color', 'icon', 'is_terminal'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }

        if (!empty($fields)) {
            $values[] = $statusId;
            $this->db->prepare("UPDATE projecthub_role_statuses SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        }

        $fetch = $this->db->prepare("SELECT * FROM projecthub_role_statuses WHERE id = ?");
        $fetch->execute([$statusId]);
        return $fetch->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteRoleStatus(int $statusId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_role_statuses WHERE id = ?");
        $stmt->execute([$statusId]);
        return $stmt->rowCount() > 0;
    }

    public function reorderRoleStatuses(int $roleId, array $ids): void
    {
        $stmt = $this->db->prepare("UPDATE projecthub_role_statuses SET sort_order = ? WHERE role_id = ? AND id = ?");
        foreach ($ids as $order => $id) {
            $stmt->execute([$order, $roleId, (int)$id]);
        }
    }

    // =========================================================================
    // User-Role Mapping
    // =========================================================================

    public function getUserRoles(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT ur.*, r.name AS role_name, r.slug AS role_slug, r.color AS role_color, r.icon AS role_icon
            FROM projecthub_user_roles ur
            JOIN projecthub_roles r ON r.id = ur.role_id
            WHERE ur.user_email = ?
            ORDER BY r.sort_order ASC
        ");
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getRoleUsers(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT ur.*, ur.user_email
            FROM projecthub_user_roles ur
            WHERE ur.role_id = ?
            ORDER BY ur.user_email ASC
        ");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function assignUserRole(string $userEmail, int $roleId, string $assignedBy): ?array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO projecthub_user_roles (user_email, role_id, assigned_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$email, $roleId, strtolower($assignedBy)]);

        $fetch = $this->db->prepare("SELECT * FROM projecthub_user_roles WHERE user_email = ? AND role_id = ?");
        $fetch->execute([$email, $roleId]);
        return $fetch->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function removeUserRole(string $userEmail, int $roleId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_user_roles WHERE user_email = ? AND role_id = ?");
        $stmt->execute([strtolower($userEmail), $roleId]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Status Validation
    // =========================================================================

    /**
     * Get allowed statuses for a user based on their role on a card.
     * Falls back to the default 'assignee' role statuses if user has no explicit role.
     */
    public function getStatusesForCardAssignee(int $assigneeId): array
    {
        $stmt = $this->db->prepare("
            SELECT ca.user_email, ca.role
            FROM projecthub_card_assignees ca
            WHERE ca.id = ?
        ");
        $stmt->execute([$assigneeId]);
        $assignee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignee) return [];

        $roleSlug = $assignee['role'] ?? 'assignee';
        return $this->getStatusesByRoleSlug($roleSlug);
    }

    public function getStatusesByRoleSlug(string $roleSlug): array
    {
        $stmt = $this->db->prepare("
            SELECT rs.*
            FROM projecthub_role_statuses rs
            JOIN projecthub_roles r ON r.id = rs.role_id
            WHERE r.slug = ?
            ORDER BY rs.sort_order ASC
        ");
        $stmt->execute([$roleSlug]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function validateStatusChange(int $assigneeId, string $newStatusSlug): bool
    {
        $allowed = $this->getStatusesForCardAssignee($assigneeId);
        if (empty($allowed)) return true; // no statuses configured = allow all
        return in_array($newStatusSlug, array_column($allowed, 'slug'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }
}
