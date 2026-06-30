<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Migration Checklist Controller
 *
 * A DB-persisted readiness board for an email migration. The admin runs the
 * whole migration from the Panel and ticks off phases (server deployed, users
 * synced/checked, contacts synced/checked, calendar synced/checked, DNS cut
 * over, …) as they go. State lives in the migration_checklist table so it
 * survives reloads and is shared across admins.
 */
class MigrationChecklistController extends BaseController
{
    public function __construct($container)
    {
        parent::__construct($container);
        $this->ensureTableExists();
    }

    /**
     * Default board. Ordered by phase; `category` groups them in the UI.
     * item_key is stable so re-seeding never duplicates a row.
     */
    private function defaultItems(): array
    {
        return [
            ['infrastructure', 'server_deployed', 'Server provisioned & deployed'],
            ['infrastructure', 'services_verified', 'Mail / cron / queue services verified'],
            ['infrastructure', 'imapsync_installed', 'imapsync + Redis installed'],

            ['mailboxes', 'mailboxes_created', 'Destination mailboxes created (bulk)'],
            ['mailboxes', 'mail_initial_sync', 'Initial mail sync complete'],
            ['mailboxes', 'mail_delta_sync', 'Delta sync running'],
            ['mailboxes', 'mail_final_sync', 'Final mail sync (cutover) complete'],
            ['mailboxes', 'mail_verified', 'Mail migration verified (counts match)'],
            ['mailboxes', 'mail_user_check', 'Users checked their mail'],

            ['contacts', 'contacts_synced', 'Contacts imported'],
            ['contacts', 'contacts_verified', 'Contacts checked'],

            ['calendar', 'calendar_synced', 'Calendar imported'],
            ['calendar', 'calendar_verified', 'Calendar checked'],

            ['dns', 'autodiscover_records', 'Autodiscover / autoconfig DNS added'],
            ['dns', 'mx_cutover', 'MX records cut over to new server'],
            ['dns', 'dns_propagated', 'DNS propagation confirmed'],

            ['handover', 'users_notified', 'Users notified they can log in'],
            ['handover', 'migration_complete', 'Migration complete'],
        ];
    }

    private function ensureTableExists(): void
    {
        try {
            $db = $this->container->getDatabase();

            $db->exec("
                CREATE TABLE IF NOT EXISTS migration_checklist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    item_key VARCHAR(64) NOT NULL UNIQUE,
                    category VARCHAR(64) NOT NULL DEFAULT 'general',
                    label VARCHAR(255) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    done TINYINT(1) NOT NULL DEFAULT 0,
                    notes TEXT,
                    done_by VARCHAR(128),
                    done_at DATETIME,
                    is_custom TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_category (category),
                    INDEX idx_sort (sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->seedDefaults($db);
        } catch (\Exception $e) {
            debug_log('Failed to create migration_checklist table: ' . $e->getMessage());
        }
    }

    /**
     * Insert any default items that don't exist yet. INSERT IGNORE keeps it
     * idempotent and preserves user edits / custom items already in the table.
     */
    private function seedDefaults(\PDO $db): void
    {
        $stmt = $db->prepare("
            INSERT IGNORE INTO migration_checklist (item_key, category, label, sort_order, is_custom)
            VALUES (?, ?, ?, ?, 0)
        ");
        $order = 0;
        foreach ($this->defaultItems() as [$category, $key, $label]) {
            $stmt->execute([$key, $category, $label, $order]);
            $order += 10;
        }
    }

    /**
     * GET /api/migration-checklist
     * Returns all items grouped-ready plus a progress summary.
     */
    public function index(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $items = $db->query("
            SELECT id, item_key, category, label, sort_order, done, notes, done_by, done_at, is_custom
            FROM migration_checklist
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        // Normalise booleans and integers for the frontend.
        $total = 0;
        $completed = 0;
        foreach ($items as &$item) {
            $item['done'] = (bool) $item['done'];
            $item['is_custom'] = (bool) $item['is_custom'];
            $item['id'] = (int) $item['id'];
            $item['sort_order'] = (int) $item['sort_order'];
            $total++;
            if ($item['done']) {
                $completed++;
            }
        }
        unset($item);

        return Response::success([
            'items' => $items,
            'summary' => [
                'total' => $total,
                'completed' => $completed,
                'percent' => $total > 0 ? (int) round($completed / $total * 100) : 0,
            ],
        ], 'Success');
    }

    /**
     * PUT /api/migration-checklist/{id}
     * Toggle done state and/or update notes for one item.
     */
    public function update(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT id, done FROM migration_checklist WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$item) {
            return Response::error('Checklist item not found', 404);
        }

        $body = $request->getBody();
        $sets = [];
        $params = [];

        if (array_key_exists('done', $body)) {
            $done = filter_var($body['done'], FILTER_VALIDATE_BOOLEAN);
            $sets[] = 'done = ?';
            $params[] = $done ? 1 : 0;
            // Stamp who/when when transitioning to done; clear when un-ticking.
            if ($done) {
                $sets[] = 'done_by = ?';
                $params[] = $this->getActor();
                $sets[] = 'done_at = NOW()';
            } else {
                $sets[] = 'done_by = NULL';
                $sets[] = 'done_at = NULL';
            }
        }

        if (array_key_exists('notes', $body)) {
            $sets[] = 'notes = ?';
            $params[] = $body['notes'] !== '' ? (string) $body['notes'] : null;
        }

        if (empty($sets)) {
            return Response::error('Nothing to update (provide done and/or notes)');
        }

        $params[] = $id;
        $db->prepare("UPDATE migration_checklist SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);

        $this->logAction('migration_checklist.update', "item:{$id}", 'success', [
            'done' => $body['done'] ?? null,
        ]);

        return $this->index($request);
    }

    /**
     * POST /api/migration-checklist
     * Add a custom item to the board.
     */
    public function create(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $label = trim((string) $request->input('label', ''));
        if ($label === '') {
            return Response::error('label is required');
        }
        $category = trim((string) $request->input('category', 'custom')) ?: 'custom';

        $db = $this->container->getDatabase();
        $maxOrder = (int) $db->query("SELECT COALESCE(MAX(sort_order), 0) FROM migration_checklist")->fetchColumn();

        // Build a stable, unique key from the label.
        $base = 'custom_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
        $base = trim(substr($base, 0, 50), '_');
        $key = $base;
        $i = 1;
        $check = $db->prepare("SELECT 1 FROM migration_checklist WHERE item_key = ?");
        while (true) {
            $check->execute([$key]);
            if ($check->fetchColumn() === false) {
                break;
            }
            $key = $base . '_' . (++$i);
        }

        $stmt = $db->prepare("
            INSERT INTO migration_checklist (item_key, category, label, sort_order, is_custom)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$key, $category, $label, $maxOrder + 10]);

        $this->logAction('migration_checklist.create', $key, 'success', ['label' => $label]);

        return $this->index($request);
    }

    /**
     * DELETE /api/migration-checklist/{id}
     * Remove an item. Built-in (non-custom) items are protected so the
     * standard board can't be accidentally gutted; reset re-seeds them.
     */
    public function delete(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT id, is_custom, item_key FROM migration_checklist WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$item) {
            return Response::error('Checklist item not found', 404);
        }
        if (!$item['is_custom']) {
            return Response::error('Built-in checklist items cannot be deleted (un-tick instead)');
        }

        $db->prepare("DELETE FROM migration_checklist WHERE id = ?")->execute([$id]);
        $this->logAction('migration_checklist.delete', $item['item_key'], 'success');

        return $this->index($request);
    }

    /**
     * POST /api/migration-checklist/reset
     * Re-seed any missing default items (does not clear progress).
     */
    public function reset(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $this->seedDefaults($db);
        $this->logAction('migration_checklist.reset', 'board', 'success');

        return $this->index($request);
    }
}
