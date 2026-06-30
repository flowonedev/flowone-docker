<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * MigrationDataController
 * ---------------------------------------------------------------
 * Admin-driven migration of CONTACTS (VCF/CSV) and CALENDAR (ICS) into
 * FlowOne, sitting alongside the imapsync mail migration in the same
 * Panel "Mail" migration UI.
 *
 * The Panel does not store contacts/calendars itself. Because the Panel and
 * the Email App live on the same server, it hands the work to the privileged
 * agent (mail.davMigrate), which runs the Email App's own dav-migrate CLI
 * locally — no webmail URL, no shared API key, no networking. FlowOne imports
 * it into the target user's address book / default calendar (idempotent by
 * UID), so re-running a delta is safe. Each run is logged in `dav_migrations`
 * for the history list.
 */
class MigrationDataController extends BaseController
{
    public function __construct($container)
    {
        parent::__construct($container);
        $this->ensureTableExists();
    }

    private function ensureTableExists(): void
    {
        try {
            $db = $this->container->getDatabase();
            $db->exec("
                CREATE TABLE IF NOT EXISTS dav_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('contacts','calendar') NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    format VARCHAR(16) DEFAULT NULL,
                    source_label VARCHAR(255) DEFAULT NULL,
                    total INT DEFAULT 0,
                    imported INT DEFAULT 0,
                    updated INT DEFAULT 0,
                    status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
                    error_message TEXT DEFAULT NULL,
                    created_by INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_type (type),
                    INDEX idx_user (user_email),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log('dav_migrations table creation error: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/migration/dav-import — recent contacts/calendar imports.
     */
    public function list(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $limit = (int) ($request->getQuery('limit') ?? 50);
        $limit = max(1, min($limit, 200));
        $stmt = $db->prepare("SELECT * FROM dav_migrations ORDER BY created_at DESC LIMIT {$limit}");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Response::success([
            'migrations' => $rows,
            'contacts_imported' => $this->sumColumn($rows, 'contacts', 'imported'),
            'calendar_imported' => $this->sumColumn($rows, 'calendar', 'imported'),
        ]);
    }

    /**
     * DELETE /api/migration/dav-import — clear the import history list.
     * Only removes the bookkeeping rows; imported contacts/events are untouched.
     */
    public function clear(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $db = $this->container->getDatabase();
            $deleted = (int) $db->exec('DELETE FROM dav_migrations');
        } catch (\Throwable $e) {
            error_log('dav_migrations clear error: ' . $e->getMessage());
            return Response::error('Could not clear import history');
        }

        try {
            $this->logAction('dav_migration.clear_history', 'mail', 'completed', ['deleted' => $deleted]);
        } catch (\Throwable $e) {
            error_log('dav_migration.clear audit log error: ' . $e->getMessage());
        }

        return Response::success(['deleted' => $deleted], 'Import history cleared');
    }

    /**
     * POST /api/migration/dav-import
     * Body: { user_email, type: contacts|calendar, data, format?, source_label? }
     * Forwards to FlowOne and records the result.
     */
    public function import(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $data = $request->getBody();
        $userEmail = strtolower(trim((string) ($data['user_email'] ?? '')));
        $type = strtolower((string) ($data['type'] ?? ''));
        $payload = (string) ($data['data'] ?? '');
        $format = isset($data['format']) ? strtolower((string) $data['format']) : null;
        $sourceLabel = isset($data['source_label']) ? substr((string) $data['source_label'], 0, 255) : null;

        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid user_email is required');
        }
        if (!in_array($type, ['contacts', 'calendar'], true)) {
            return Response::error("type must be 'contacts' or 'calendar'");
        }
        if (trim($payload) === '') {
            return Response::error('No data provided to import');
        }

        // Run the import locally against the co-located Email App via the
        // privileged agent — no webmail URL, no API key, no networking.
        $result = $this->agent->execute('mail.davMigrate', [
            'action' => 'import',
            'type' => $type,
            'user_email' => $userEmail,
            'format' => $format,
            'data' => $payload,
        ], $this->getActor(), 240);

        $user = $this->getCurrentUser();
        $createdBy = $user->sub ?? $user->id ?? null;

        $ok = (bool) ($result['success'] ?? false);
        $counts = $result['data'] ?? [];
        $status = $ok ? 'completed' : 'failed';
        $errorMessage = $ok ? null : ($result['error'] ?? 'Import failed');

        // Bookkeeping (history row + audit log) must NEVER turn a successful
        // import into a 500. The agent has already written the data, so swallow
        // any persistence error here and still report the real outcome.
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("
                INSERT INTO dav_migrations
                    (type, user_email, format, source_label, total, imported, updated, status, error_message, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type, $userEmail, $format, $sourceLabel,
                (int) ($counts['total'] ?? 0), (int) ($counts['imported'] ?? 0), (int) ($counts['updated'] ?? 0),
                $status, $errorMessage, $createdBy,
            ]);
        } catch (\Throwable $e) {
            error_log('dav_migrations insert error: ' . $e->getMessage());
        }

        try {
            $this->logAction('dav_migration.import', 'mail', $status, [
                'type' => $type,
                'user_email' => $userEmail,
                'counts' => $counts,
            ]);
        } catch (\Throwable $e) {
            error_log('dav_migration.import audit log error: ' . $e->getMessage());
        }

        if (!$ok) {
            return Response::error($errorMessage ?: 'Import failed', 502);
        }

        return Response::success([
            'type' => $type,
            'user_email' => $userEmail,
            'imported' => (int) ($counts['imported'] ?? 0),
            'updated' => (int) ($counts['updated'] ?? 0),
            'total' => (int) ($counts['total'] ?? 0),
        ], "Imported {$type}: " . ((int) ($counts['imported'] ?? 0)) . ' new, ' . ((int) ($counts['updated'] ?? 0)) . ' updated');
    }

    /**
     * POST /api/migration/dav-export
     * Body: { user_email, type: contacts|calendar }
     * Pulls the user's contacts/calendar back out of FlowOne and returns the
     * raw file payload so the dashboard can stream it as a download.
     */
    public function export(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $data = $request->getBody();
        $userEmail = strtolower(trim((string) ($data['user_email'] ?? '')));
        $type = strtolower((string) ($data['type'] ?? ''));

        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid user_email is required');
        }
        if (!in_array($type, ['contacts', 'calendar'], true)) {
            return Response::error("type must be 'contacts' or 'calendar'");
        }

        // Pull the data straight out of the co-located Email App via the agent.
        $result = $this->agent->execute('mail.davMigrate', [
            'action' => 'export',
            'type' => $type,
            'user_email' => $userEmail,
        ], $this->getActor(), 240);

        $ok = (bool) ($result['success'] ?? false);
        $status = $ok ? 'completed' : 'failed';
        try {
            $this->logAction('dav_migration.export', 'mail', $status, [
                'type' => $type,
                'user_email' => $userEmail,
            ]);
        } catch (\Throwable $e) {
            error_log('dav_migration.export audit log error: ' . $e->getMessage());
        }

        if (!$ok) {
            $errorMessage = $result['error'] ?? 'Export failed';
            return Response::error($errorMessage ?: 'Export failed', 502);
        }

        $payload = $result['data'] ?? [];
        return Response::success([
            'type' => $type,
            'user_email' => $userEmail,
            'data' => (string) ($payload['data'] ?? ''),
            'filename' => (string) ($payload['filename'] ?? ($type . '.txt')),
            'mime' => (string) ($payload['mime'] ?? 'text/plain'),
            'count' => (int) ($payload['count'] ?? 0),
        ], "Exported {$type}: " . ((int) ($payload['count'] ?? 0)) . ' item(s)');
    }

    private function sumColumn(array $rows, string $type, string $col): int
    {
        $sum = 0;
        foreach ($rows as $r) {
            if (($r['type'] ?? '') === $type && ($r['status'] ?? '') === 'completed') {
                $sum += (int) ($r[$col] ?? 0);
            }
        }
        return $sum;
    }
}
