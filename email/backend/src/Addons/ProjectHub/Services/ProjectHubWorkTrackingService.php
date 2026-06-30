<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubWorkTrackingService
{
    private PDO $db;
    private array $config;
    private string $logFile;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logFile = __DIR__ . '/../../../../storage/project-hub.log';
        $this->db = \Webmail\Core\Database::getConnection($config);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureSchema());
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_card_assignees (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    role ENUM('assignee','reviewer','observer') DEFAULT 'assignee',
                    status ENUM('assigned','working','review','done','blocked') DEFAULT 'assigned',
                    started_at TIMESTAMP NULL DEFAULT NULL,
                    completed_at TIMESTAMP NULL DEFAULT NULL,
                    time_spent_seconds INT UNSIGNED DEFAULT 0,
                    difficulty_weight TINYINT UNSIGNED DEFAULT 1,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_card_user (card_id, user_email),
                    INDEX idx_user (user_email),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_work_sessions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    source ENUM('manual','drive_edit','board_view','timer','card_view','website_work','portal_call','calendar_event','local_watch') DEFAULT 'manual',
                    entity_type VARCHAR(50) DEFAULT NULL,
                    entity_id INT UNSIGNED DEFAULT NULL,
                    entity_name VARCHAR(255) DEFAULT NULL,
                    started_at TIMESTAMP NULL DEFAULT NULL,
                    ended_at TIMESTAMP NULL DEFAULT NULL,
                    duration_seconds INT UNSIGNED DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_card_user (card_id, user_email),
                    INDEX idx_user (user_email),
                    INDEX idx_started (started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->exec("
                ALTER TABLE projecthub_card_assignees
                ADD COLUMN IF NOT EXISTS difficulty_weight TINYINT UNSIGNED DEFAULT 1
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_watchers (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    card_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_card_watcher (card_id, user_email),
                    INDEX idx_card (card_id),
                    INDEX idx_user (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS projecthub_comment_reactions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    comment_id INT NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    emoji VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_reaction (comment_id, user_email, emoji),
                    INDEX idx_comment (comment_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->exec("
                ALTER TABLE projecthub_comment_reactions MODIFY COLUMN emoji VARCHAR(50) NOT NULL
            ");
            $this->db->exec("
                ALTER TABLE webmail_board_labels ADD COLUMN IF NOT EXISTS is_type TINYINT(1) DEFAULT 0
            ");
        } catch (\Throwable $e) {
            $this->log('ensureSchema: ' . $e->getMessage());
        }
    }

    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * Build common SQL filter clauses for member_email and group_id.
     * Returns [sqlFragment, params[]] to append to a WHERE clause.
     * $emailColumn is the SQL column to filter (e.g. 'a.user_email' or 'ca.user_email').
     */
    private function buildMemberFilters(array $filters, string $emailColumn = 'a.user_email'): array
    {
        $sql = '';
        $params = [];

        if (!empty($filters['member_email'])) {
            $sql .= " AND $emailColumn = ?";
            $params[] = strtolower($filters['member_email']);
        }

        if (!empty($filters['group_id'])) {
            $sql .= "
              AND $emailColumn IN (
                  SELECT oc.email FROM organization_colleagues oc
                  JOIN colleague_group_members cgm ON cgm.colleague_id = oc.id
                  WHERE cgm.group_id = ?
              )
            ";
            $params[] = (int)$filters['group_id'];
        }

        return [$sql, $params];
    }

    /**
     * Resolve filters to a set of allowed emails for PHP-side post-filtering.
     * Returns null when no member/group filter is active (= allow all).
     * Returns an associative array [email => true] when filtering.
     */
    private function resolveAllowedEmails(array $filters): ?array
    {
        $sets = [];

        if (!empty($filters['member_email'])) {
            $sets[] = [strtolower($filters['member_email'])];
        }

        if (!empty($filters['group_id'])) {
            $stmt = $this->db->prepare("
                SELECT oc.email FROM organization_colleagues oc
                JOIN colleague_group_members cgm ON cgm.colleague_id = oc.id
                WHERE cgm.group_id = ?
            ");
            $stmt->execute([(int)$filters['group_id']]);
            $emails = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
                $emails[] = strtolower((string)$email);
            }
            $sets[] = $emails;
        }

        if (!empty($filters['role_slug'])) {
            $slug = strtolower(trim((string)$filters['role_slug']));
            $stmt = $this->db->prepare("
                SELECT LOWER(pur.user_email) FROM projecthub_user_roles pur
                JOIN projecthub_roles pr ON pr.id = pur.role_id
                WHERE pr.slug = ?
            ");
            $stmt->execute([$slug]);
            $emails = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
                $emails[] = strtolower((string)$email);
            }
            $sets[] = $emails;
        }

        if ($sets === []) {
            return null;
        }

        $inter = array_fill_keys(array_shift($sets), true);
        foreach ($sets as $list) {
            $next = [];
            foreach ($list as $e) {
                $e = strtolower((string)$e);
                if ($e !== '' && isset($inter[$e])) {
                    $next[$e] = true;
                }
            }
            $inter = $next;
        }

        return $inter;
    }

    /**
     * EXISTS filter: user has role slug (no row multiplication in aggregates).
     *
     * @return array{0: string, 1: array<int, string|int>}
     */
    private function buildRoleSlugExistsFilter(array $filters, string $emailSqlExpr): array
    {
        if (empty($filters['role_slug'])) {
            return ['', []];
        }
        $slug = strtolower(trim((string)$filters['role_slug']));
        $sql = " AND EXISTS (
            SELECT 1 FROM projecthub_user_roles pur
            JOIN projecthub_roles pr ON pr.id = pur.role_id
            WHERE LOWER(pur.user_email) = LOWER($emailSqlExpr) AND pr.slug = ?
        )";

        return [$sql, [$slug]];
    }

    /**
     * @param array<int, string> $emails
     * @return array<string, string> lowercased email => comma-separated role slugs
     */
    private function fetchRoleSlugsByEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($e) => strtolower(trim((string)$e)),
            $emails
        ))));
        if ($emails === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $this->db->prepare("
            SELECT LOWER(pur.user_email) AS e, GROUP_CONCAT(DISTINCT pr.slug ORDER BY pr.slug) AS slugs
            FROM projecthub_user_roles pur
            JOIN projecthub_roles pr ON pr.id = pur.role_id
            WHERE LOWER(pur.user_email) IN ($placeholders)
            GROUP BY LOWER(pur.user_email)
        ");
        $stmt->execute($emails);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $e = strtolower((string)($row['e'] ?? ''));
            if ($e !== '') {
                $out[$e] = (string)($row['slugs'] ?? '');
            }
        }

        return $out;
    }

    // =========================================================================
    // Card Assignees CRUD
    // =========================================================================

    public function getCardAssignees(int $cardId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_card_assignees
            WHERE card_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Batched fetch: returns a map of card_id => assignees[] in ONE
     * IN-clause query. Replaces the per-card Promise.all loop in
     * useSubtaskLinkedCards::loadSubtaskAssignees and similar callers.
     *
     * @param array<int> $cardIds
     * @return array<int, array<int,array>>
     */
    public function getCardAssigneesBatch(array $cardIds): array
    {
        $cardIds = array_values(array_unique(array_filter(array_map('intval', $cardIds), fn($x) => $x > 0)));
        $out = [];
        foreach ($cardIds as $id) $out[$id] = [];
        if (empty($cardIds)) return $out;

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM projecthub_card_assignees
             WHERE card_id IN ({$placeholders})
             ORDER BY card_id ASC, created_at ASC"
        );
        $stmt->execute($cardIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['card_id'];
            $out[$cid][] = $row;
        }
        return $out;
    }

    public function getAssignee(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_card_assignees WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function addAssignee(int $cardId, string $userEmail, string $role = 'assignee'): ?array
    {
        try {
            $email = strtolower($userEmail);
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_card_assignees (card_id, user_email, role, status)
                VALUES (?, ?, ?, 'assigned')
                ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$cardId, $email, $role]);

            $this->syncPrimaryAssignee($cardId);
            $this->autoAddBoardMember($cardId, $email);
            $this->log("Assignee added: {$email} to card {$cardId} as {$role}");

            return $this->getAssigneeByCardAndUser($cardId, $email);
        } catch (\PDOException $e) {
            $this->log("addAssignee error: " . $e->getMessage());
            return null;
        }
    }

    private function autoAddBoardMember(int $cardId, string $userEmail): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT b.id AS board_id
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON l.id = c.list_id
                JOIN webmail_boards b ON b.id = l.board_id
                WHERE c.id = ?
            ");
            $stmt->execute([$cardId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return;

            $boardId = (int)$row['board_id'];
            $stmt2 = $this->db->prepare("
                INSERT IGNORE INTO webmail_board_members (board_id, user_email, role)
                VALUES (?, ?, 'editor')
            ");
            $stmt2->execute([$boardId, $userEmail]);
        } catch (\Throwable $e) {
            $this->log("autoAddBoardMember warning: " . $e->getMessage());
        }
    }

    public function updateAssignee(int $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        foreach (['role', 'status', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }
        if (array_key_exists('difficulty_weight', $data)) {
            $w = (int) $data['difficulty_weight'];
            if ($w < 1) {
                $w = 1;
            }
            if ($w > 5) {
                $w = 5;
            }
            $fields[] = 'difficulty_weight = ?';
            $values[] = $w;
        }

        if (empty($fields)) {
            return $this->getAssignee($id);
        }

        $values[] = $id;
        $stmt = $this->db->prepare("
            UPDATE projecthub_card_assignees SET " . implode(', ', $fields) . " WHERE id = ?
        ");
        $stmt->execute($values);
        return $this->getAssignee($id);
    }

    public function updateAssigneeStatus(int $id, string $newStatus): ?array
    {
        $assignee = $this->getAssignee($id);
        if (!$assignee) return null;

        $updates = ['status' => $newStatus];

        if ($newStatus === 'working' && !$assignee['started_at']) {
            $updates['started_at'] = date('Y-m-d H:i:s');
        }
        if ($newStatus === 'done' && !$assignee['completed_at']) {
            $updates['completed_at'] = date('Y-m-d H:i:s');
        }
        if ($newStatus !== 'done') {
            $updates['completed_at'] = null;
        }

        $fields = [];
        $values = [];
        foreach ($updates as $field => $value) {
            $fields[] = "{$field} = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $stmt = $this->db->prepare("
            UPDATE projecthub_card_assignees SET " . implode(', ', $fields) . " WHERE id = ?
        ");
        $stmt->execute($values);

        $this->syncPrimaryAssignee($assignee['card_id']);
        $this->log("Assignee {$id} status changed to {$newStatus}");
        return $this->getAssignee($id);
    }

    public function removeAssignee(int $id): bool
    {
        $assignee = $this->getAssignee($id);
        if (!$assignee) return false;

        $stmt = $this->db->prepare("DELETE FROM projecthub_card_assignees WHERE id = ?");
        $stmt->execute([$id]);

        $this->syncPrimaryAssignee($assignee['card_id']);
        $this->log("Assignee {$id} removed from card {$assignee['card_id']}");
        return $stmt->rowCount() > 0;
    }

    /**
     * Batched delete: remove many assignee rows in ONE DELETE WHERE
     * IN(...) and run syncPrimaryAssignee ONCE per affected card.
     * Returns the rows that were deleted (with card_id and user_email)
     * so the caller can fire per-row events / activity log entries.
     *
     * @param array<int> $assigneeIds
     * @return array{deleted:int, rows:array<int,array>}
     */
    public function removeAssigneesBatch(array $assigneeIds): array
    {
        $assigneeIds = array_values(array_unique(array_filter(array_map('intval', $assigneeIds), fn($x) => $x > 0)));
        if (empty($assigneeIds)) return ['deleted' => 0, 'rows' => []];

        // Snapshot rows before delete so we can return them and run the
        // syncPrimaryAssignee pass on each unique card.
        $placeholders = implode(',', array_fill(0, count($assigneeIds), '?'));
        $sel = $this->db->prepare(
            "SELECT * FROM projecthub_card_assignees WHERE id IN ({$placeholders})"
        );
        $sel->execute($assigneeIds);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) return ['deleted' => 0, 'rows' => []];

        $foundIds = array_map(fn($r) => (int)$r['id'], $rows);
        $foundPh = implode(',', array_fill(0, count($foundIds), '?'));
        $del = $this->db->prepare(
            "DELETE FROM projecthub_card_assignees WHERE id IN ({$foundPh})"
        );
        $del->execute($foundIds);
        $deleted = $del->rowCount();

        $affectedCards = array_values(array_unique(array_map(fn($r) => (int)$r['card_id'], $rows)));
        foreach ($affectedCards as $cid) {
            $this->syncPrimaryAssignee($cid);
        }

        return ['deleted' => $deleted, 'rows' => $rows];
    }

    private function getAssigneeByCardAndUser(int $cardId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_card_assignees
            WHERE card_id = ? AND user_email = ?
        ");
        $stmt->execute([$cardId, strtolower($userEmail)]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Keep webmail_board_cards.assigned_to in sync with the first assignee.
     * This preserves backward compatibility when Project Hub is turned off.
     */
    private function syncPrimaryAssignee(int $cardId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_email FROM projecthub_card_assignees
                WHERE card_id = ? AND role = 'assignee'
                ORDER BY created_at ASC
                LIMIT 1
            ");
            $stmt->execute([$cardId]);
            $primary = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt2 = $this->db->prepare("
                UPDATE webmail_board_cards SET assigned_to = ? WHERE id = ?
            ");
            $stmt2->execute([$primary ? $primary['user_email'] : null, $cardId]);
        } catch (\PDOException $e) {
            $this->log("syncPrimaryAssignee error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Work Sessions
    // =========================================================================

    public function logWorkSession(int $cardId, string $userEmail, array $data): ?array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_work_sessions
                    (card_id, user_email, source, entity_type, entity_id, entity_name, started_at, ended_at, duration_seconds)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cardId,
                strtolower($userEmail),
                $data['source'] ?? 'manual',
                $data['entity_type'] ?? null,
                $data['entity_id'] ?? null,
                $data['entity_name'] ?? null,
                $data['started_at'] ?? date('Y-m-d H:i:s'),
                $data['ended_at'] ?? null,
                $data['duration_seconds'] ?? 0,
            ]);
            $id = (int)$this->db->lastInsertId();

            $this->recalcAssigneeTime($cardId, $userEmail);

            // Auto-update status to 'working' if still 'assigned'
            $assignee = $this->getAssigneeByCardAndUser($cardId, $userEmail);
            if ($assignee && $assignee['status'] === 'assigned') {
                $this->updateAssigneeStatus($assignee['id'], 'working');
            }

            // Sessions originating from the client-time pipeline (clientTimeTracker
            // or the Electron agent posting to /clients/{id}/time, then bridged into
            // Project Hub) are already counted in webmail_client_time_tracking.
            // Re-bridging them would double-count client hours. card_view overlaps
            // with the client tracker's direct board_task entry for the same open card.
            $source = $data['source'] ?? 'manual';
            $alreadyClientTracked = in_array($source, ['card_view', 'board_view', 'drive_edit', 'website_work'], true);
            if (!$alreadyClientTracked) {
                $this->bridgeClientTimeTracking($cardId, strtolower($userEmail), (int)($data['duration_seconds'] ?? 0));
            }

            $this->checkTimeBudget($cardId, strtolower($userEmail));

            $stmt2 = $this->db->prepare("SELECT * FROM projecthub_work_sessions WHERE id = ?");
            $stmt2->execute([$id]);
            return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $this->log("logWorkSession error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Bridge work session time into webmail_client_time_tracking
     * by looking up the card's board -> client association.
     */
    private function bridgeClientTimeTracking(int $cardId, string $userEmail, int $durationSeconds): void
    {
        if ($durationSeconds <= 0) return;

        try {
            $stmt = $this->db->prepare("
                SELECT b.client_id
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON l.id = c.list_id
                JOIN webmail_boards b ON b.id = l.board_id
                WHERE c.id = ? AND b.client_id IS NOT NULL
            ");
            $stmt->execute([$cardId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || !$row['client_id']) return;

            $clientId = (int)$row['client_id'];
            $today = date('Y-m-d');

            $stmt2 = $this->db->prepare("
                INSERT INTO webmail_client_time_tracking
                    (user_email, client_id, activity_type, entity_id, entity_name, duration_seconds, tracked_date)
                VALUES (?, ?, 'board_task', ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    duration_seconds = duration_seconds + VALUES(duration_seconds),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt2->execute([
                $userEmail,
                $clientId,
                (string)$cardId,
                null,
                $durationSeconds,
                $today,
            ]);

            $this->log("Bridged {$durationSeconds}s to client_time_tracking for client {$clientId}, card {$cardId}");
        } catch (\PDOException $e) {
            $this->log("bridgeClientTimeTracking error: " . $e->getMessage());
        }
    }

    public function getCardWorkSessions(int $cardId, ?string $userEmail = null): array
    {
        if ($userEmail) {
            $stmt = $this->db->prepare("
                SELECT * FROM projecthub_work_sessions
                WHERE card_id = ? AND user_email = ?
                ORDER BY started_at DESC
            ");
            $stmt->execute([$cardId, strtolower($userEmail)]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM projecthub_work_sessions
                WHERE card_id = ?
                ORDER BY started_at DESC
            ");
            $stmt->execute([$cardId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Copy all work sessions from one card to another, preserving original users and timestamps.
     * Returns the count of sessions copied.
     */
    public function copyWorkSessions(int $sourceCardId, int $targetCardId): int
    {
        $sessions = $this->getCardWorkSessions($sourceCardId);
        if (empty($sessions)) return 0;

        $copied = 0;
        $stmt = $this->db->prepare("
            INSERT INTO projecthub_work_sessions
                (card_id, user_email, source, entity_type, entity_id, entity_name, started_at, ended_at, duration_seconds)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($sessions as $s) {
            try {
                $stmt->execute([
                    $targetCardId,
                    $s['user_email'],
                    $s['source'] ?? 'manual',
                    $s['entity_type'] ?? null,
                    $s['entity_id'] ?? null,
                    $s['entity_name'] ?? null,
                    $s['started_at'] ?? null,
                    $s['ended_at'] ?? null,
                    (int)($s['duration_seconds'] ?? 0),
                ]);
                $copied++;
            } catch (\Throwable $e) {
                error_log("copyWorkSessions error for session {$s['id']}: " . $e->getMessage());
            }
        }

        if ($copied > 0) {
            $this->updateAssigneeTrackedTime($targetCardId);
        }

        return $copied;
    }

    /**
     * Recalculate tracked time for all assignees of a card from work sessions.
     */
    private function updateAssigneeTrackedTime(int $cardId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE projecthub_card_assignees a
                SET a.time_spent_seconds = (
                    SELECT COALESCE(SUM(ws.duration_seconds), 0)
                    FROM projecthub_work_sessions ws
                    WHERE ws.card_id = a.card_id AND ws.user_email = a.user_email
                )
                WHERE a.card_id = ?
            ");
            $stmt->execute([$cardId]);
        } catch (\Throwable $e) {
            error_log("updateAssigneeTrackedTime error: " . $e->getMessage());
        }
    }

    public function getSessionsSummaryByBoard(string $userEmail, string $period = 'week', ?int $boardId = null): array
    {
        $userEmail = strtolower($userEmail);

        $startDate = match ($period) {
            'today' => date('Y-m-d'),
            'week' => date('Y-m-d', strtotime('-6 days')),
            'month' => date('Y-m-d', strtotime('-29 days')),
            'year' => date('Y-m-d', strtotime('-364 days')),
            default => '2020-01-01',
        };

        $sql = "
            SELECT
                ws.id AS session_id,
                ws.card_id,
                ws.source,
                ws.duration_seconds,
                ws.started_at,
                ws.ended_at,
                ws.entity_name,
                c.title AS card_title,
                c.parent_card_id,
                b.id AS board_id,
                b.name AS board_name
            FROM projecthub_work_sessions ws
            JOIN webmail_board_cards c ON c.id = ws.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE ws.user_email = ?
              AND DATE(ws.started_at) >= ?
        ";

        $params = [$userEmail, $startDate];

        if ($boardId) {
            $sql .= " AND b.id = ?";
            $params[] = $boardId;
        }

        $sql .= " ORDER BY b.name ASC, c.title ASC, ws.started_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $boards = [];
        foreach ($rows as $row) {
            $bid = (int)$row['board_id'];
            $cid = (int)$row['card_id'];

            if (!isset($boards[$bid])) {
                $boards[$bid] = [
                    'board_id' => $bid,
                    'board_name' => $row['board_name'],
                    'total_seconds' => 0,
                    'cards' => [],
                ];
            }

            if (!isset($boards[$bid]['cards'][$cid])) {
                $boards[$bid]['cards'][$cid] = [
                    'card_id' => $cid,
                    'title' => $row['card_title'],
                    'parent_card_id' => $row['parent_card_id'] ? (int)$row['parent_card_id'] : null,
                    'total_seconds' => 0,
                    'last_active' => null,
                    'sessions' => [],
                ];
            }

            $dur = (int)$row['duration_seconds'];
            $boards[$bid]['total_seconds'] += $dur;
            $boards[$bid]['cards'][$cid]['total_seconds'] += $dur;

            if ($row['started_at'] && (!$boards[$bid]['cards'][$cid]['last_active'] || $row['started_at'] > $boards[$bid]['cards'][$cid]['last_active'])) {
                $boards[$bid]['cards'][$cid]['last_active'] = $row['started_at'];
            }

            $boards[$bid]['cards'][$cid]['sessions'][] = [
                'id' => (int)$row['session_id'],
                'source' => $row['source'],
                'duration_seconds' => $dur,
                'started_at' => $row['started_at'],
                'ended_at' => $row['ended_at'],
                'entity_name' => $row['entity_name'],
            ];
        }

        // Convert associative maps to indexed arrays, sort boards by total time desc
        $result = array_values($boards);
        usort($result, fn($a, $b) => $b['total_seconds'] - $a['total_seconds']);
        foreach ($result as &$board) {
            $board['cards'] = array_values($board['cards']);
            usort($board['cards'], fn($a, $b) => $b['total_seconds'] - $a['total_seconds']);
        }

        return $result;
    }

    public function getAssigneeTime(int $assigneeId): array
    {
        $assignee = $this->getAssignee($assigneeId);
        if (!$assignee) {
            return ['total_seconds' => 0, 'sessions_count' => 0];
        }

        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                COUNT(*) AS sessions_count
            FROM projecthub_work_sessions
            WHERE card_id = ? AND user_email = ?
        ");
        $stmt->execute([$assignee['card_id'], $assignee['user_email']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_seconds' => 0, 'sessions_count' => 0];
    }

    private function checkTimeBudget(int $cardId, string $userEmail): void
    {
        try {
            $budgetService = new ProjectHubTimeBudgetService($this->config);
            $budgetService->checkAndAlert($cardId, $userEmail);
        } catch (\Throwable $e) {
            $this->log("checkTimeBudget error: " . $e->getMessage());
        }
    }

    private function recalcAssigneeTime(int $cardId, string $userEmail): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(duration_seconds), 0) AS total
                FROM projecthub_work_sessions
                WHERE card_id = ? AND user_email = ?
            ");
            $stmt->execute([$cardId, strtolower($userEmail)]);
            $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $stmt2 = $this->db->prepare("
                UPDATE projecthub_card_assignees
                SET time_spent_seconds = ?
                WHERE card_id = ? AND user_email = ?
            ");
            $stmt2->execute([$total, $cardId, strtolower($userEmail)]);
        } catch (\PDOException $e) {
            $this->log("recalcAssigneeTime error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Drive Activity Bridge
    // =========================================================================

    /**
     * Given a Drive file ID being edited, find cards it's attached to and
     * log a work session for the current user if they are an assignee.
     */
    public function bridgeDriveActivity(int $driveFileId, string $userEmail, int $durationSeconds, ?string $fileName = null): array
    {
        $email = strtolower($userEmail);
        $results = [];

        try {
            // 1) Cards that have this file directly attached
            $stmt = $this->db->prepare("
                SELECT DISTINCT ca.card_id
                FROM webmail_card_attachments ca
                WHERE ca.drive_file_id = ?
            ");
            $stmt->execute([$driveFileId]);
            $cardIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            // 2) Cards in boards whose PH folders contain this file
            $stmt2 = $this->db->prepare("
                SELECT DISTINCT c.id AS card_id
                FROM projecthub_folder_files pff
                JOIN projecthub_folder_boards fb ON fb.folder_id = pff.folder_id
                JOIN webmail_board_lists l ON l.board_id = fb.board_id
                JOIN webmail_board_cards c ON c.list_id = l.id
                WHERE pff.drive_file_id = ?
                  AND c.completed = 0
            ");
            $stmt2->execute([$driveFileId]);
            $folderCardIds = $stmt2->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            $allCardIds = array_unique(array_merge($cardIds, $folderCardIds));

            foreach ($allCardIds as $cardId) {
                $assignee = $this->getAssigneeByCardAndUser($cardId, $email);
                if (!$assignee) continue;

                $session = $this->logWorkSession($cardId, $email, [
                    'source' => 'drive_edit',
                    'entity_type' => 'drive_file',
                    'entity_id' => $driveFileId,
                    'entity_name' => $fileName,
                    'duration_seconds' => $durationSeconds,
                ]);

                if ($session) {
                    $results[] = [
                        'card_id' => $cardId,
                        'session_id' => $session['id'],
                        'assignee_status' => $assignee['status'] ?? null,
                    ];
                }
            }
        } catch (\PDOException $e) {
            $this->log("bridgeDriveActivity error: " . $e->getMessage());
        }

        return $results;
    }

    // =========================================================================
    // Workload Planner Queries
    // =========================================================================

    /**
     * Fetch labels for a set of card IDs.
     * Returns [card_id => [label, ...]]
     */
    private function getLabelsForCards(array $cardIds): array
    {
        if (empty($cardIds)) return [];

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $stmt = $this->db->prepare("
            SELECT cl.card_id, lb.id AS label_id, lb.name, lb.color, lb.is_type
            FROM webmail_card_labels cl
            JOIN webmail_board_labels lb ON lb.id = cl.label_id
            WHERE cl.card_id IN ($placeholders)
        ");
        $stmt->execute($cardIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['card_id']][] = [
                'id' => (int)$r['label_id'],
                'name' => $r['name'],
                'color' => $r['color'],
                'is_type' => (bool)$r['is_type'],
            ];
        }
        return $map;
    }

    /**
     * Timeline data: all assignees with card date ranges for a given period.
     * Supports filtering by member_email, group_id, label_id.
     */
    public function getWorkloadTimeline(string $startDate, string $endDate, ?int $spaceId = null, array $filters = []): array
    {
        $query = "
            SELECT 
                a.user_email,
                a.role AS assignee_role,
                a.status AS assignee_status,
                a.time_spent_seconds,
                c.id AS card_id,
                c.title AS card_title,
                c.start_date,
                c.due_date,
                c.completed,
                l.id AS list_id,
                l.name AS list_name,
                l.board_id,
                b.name AS board_name
            FROM projecthub_card_assignees a
            JOIN webmail_board_cards c ON c.id = a.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.archived = 0
              AND c.parent_card_id IS NULL
              AND (
                  (c.start_date IS NOT NULL AND c.start_date <= ?)
                  OR (c.due_date IS NOT NULL AND c.due_date >= ?)
                  OR (c.start_date IS NULL AND c.due_date IS NULL AND a.status IN ('assigned','working','review'))
              )
        ";
        $params = [$endDate, $startDate];

        if ($spaceId) {
            $query .= "
              AND l.board_id IN (
                  SELECT fb.board_id FROM projecthub_folder_boards fb
                  JOIN projecthub_folders f ON f.id = fb.folder_id
                  WHERE f.space_id = ?
              )
            ";
            $params[] = $spaceId;
        }

        [$memberSql, $memberParams] = $this->buildMemberFilters($filters, 'a.user_email');
        $query .= $memberSql;
        $params = array_merge($params, $memberParams);

        if (!empty($filters['label_id'])) {
            $query .= "
              AND c.id IN (
                  SELECT cl.card_id FROM webmail_card_labels cl
                  JOIN webmail_board_labels lb2 ON lb2.id = cl.label_id
                  WHERE lb2.name = (SELECT name FROM webmail_board_labels WHERE id = ?)
              )
            ";
            $params[] = (int)$filters['label_id'];
        }

        $query .= " ORDER BY a.user_email, c.start_date ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cardIds = array_unique(array_column($rows, 'card_id'));
        try {
            $labelsMap = $this->getLabelsForCards(array_map('intval', $cardIds));
        } catch (\Throwable $e) {
            $this->log('getLabelsForCards error: ' . $e->getMessage());
            $labelsMap = [];
        }

        $members = [];
        foreach ($rows as $row) {
            $email = $row['user_email'];
            if (!isset($members[$email])) {
                $members[$email] = [
                    'email' => $email,
                    'cards' => [],
                ];
            }
            $cid = (int)$row['card_id'];
            $members[$email]['cards'][] = [
                'card_id' => $cid,
                'title' => $row['card_title'],
                'start_date' => $row['start_date'],
                'due_date' => $row['due_date'],
                'completed' => (bool)$row['completed'],
                'status' => $row['assignee_status'],
                'role' => $row['assignee_role'],
                'time_spent' => (int)$row['time_spent_seconds'],
                'board_id' => (int)$row['board_id'],
                'board_name' => $row['board_name'],
                'list_id' => (int)$row['list_id'],
                'list_name' => $row['list_name'],
                'labels' => $labelsMap[$cid] ?? [],
            ];
        }

        return array_values($members);
    }

    /**
     * Get all distinct type labels used across boards (for filter dropdown).
     * Deduplicates by name.
     */
    public function getAvailableTypeLabels(): array
    {
        $stmt = $this->db->prepare("
            SELECT MIN(lb.id) AS id, lb.name, lb.color, lb.is_type
            FROM webmail_board_labels lb
            WHERE lb.is_type = 1
            GROUP BY lb.name, lb.color, lb.is_type
            ORDER BY lb.name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all distinct labels used across boards (for filter dropdown).
     * Deduplicates by name, keeping one representative per unique label name.
     */
    public function getAvailableLabels(): array
    {
        $stmt = $this->db->prepare("
            SELECT MIN(lb.id) AS id, lb.name, lb.color, lb.is_type
            FROM webmail_board_labels lb
            GROUP BY lb.name, lb.color, lb.is_type
            ORDER BY lb.is_type DESC, lb.name ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Live data: who is doing what right now
     */
    public function getWorkloadLive(array $filters = []): array
    {
        [$memberSql, $memberParams] = $this->buildMemberFilters($filters, 'a.user_email');

        $query = "
            SELECT 
                a.user_email,
                a.status,
                a.time_spent_seconds,
                c.id AS card_id,
                c.title AS card_title,
                l.board_id,
                b.name AS board_name,
                ws.started_at AS last_session_start
            FROM projecthub_card_assignees a
            JOIN webmail_board_cards c ON c.id = a.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN (
                SELECT card_id, user_email, MAX(started_at) AS started_at
                FROM projecthub_work_sessions
                GROUP BY card_id, user_email
            ) ws ON ws.card_id = a.card_id AND ws.user_email = a.user_email
            WHERE a.status = 'working'
              AND c.archived = 0
              $memberSql
            ORDER BY ws.started_at DESC
        ";
        $stmt = $this->db->prepare($query);
        $stmt->execute($memberParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Group by member -- show latest working card per person
        $members = [];
        foreach ($rows as $row) {
            $email = $row['user_email'];
            if (!isset($members[$email])) {
                $members[$email] = [
                    'email' => $email,
                    'current_card' => [
                        'card_id' => (int)$row['card_id'],
                        'title' => $row['card_title'],
                        'board_name' => $row['board_name'],
                    ],
                    'status' => $row['status'],
                    'last_activity_at' => $row['last_session_start'],
                    'time_spent_today' => $this->getUserTimeToday($email),
                ];
            }
        }

        return array_values($members);
    }

    /**
     * Drill-down: all cards for a specific member
     */
    public function getMemberWorkload(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*,
                c.title AS card_title,
                c.start_date,
                c.due_date,
                c.completed,
                l.board_id,
                b.name AS board_name
            FROM projecthub_card_assignees a
            JOIN webmail_board_cards c ON c.id = a.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE a.user_email = ?
              AND c.archived = 0
            ORDER BY 
                FIELD(a.status, 'working', 'assigned', 'review', 'blocked', 'done'),
                c.due_date ASC
        ");
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUserTimeToday(string $userEmail): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(duration_seconds), 0) AS total
            FROM projecthub_work_sessions
            WHERE user_email = ? AND DATE(started_at) = CURDATE()
        ");
        $stmt->execute([strtolower($userEmail)]);
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    // =========================================================================
    // Comment Reactions
    // =========================================================================

    public function toggleReaction(int $commentId, string $userEmail, string $emoji): array
    {
        $email = strtolower($userEmail);

        // Check if reaction exists
        $stmt = $this->db->prepare("
            SELECT id FROM projecthub_comment_reactions
            WHERE comment_id = ? AND user_email = ? AND emoji = ?
        ");
        $stmt->execute([$commentId, $email, $emoji]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->db->prepare("DELETE FROM projecthub_comment_reactions WHERE id = ?")->execute([$existing['id']]);
            $action = 'removed';
        } else {
            $this->db->prepare("
                INSERT INTO projecthub_comment_reactions (comment_id, user_email, emoji) VALUES (?, ?, ?)
            ")->execute([$commentId, $email, $emoji]);
            $action = 'added';
        }

        return [
            'action' => $action,
            'reactions' => $this->getCommentReactions($commentId),
        ];
    }

    public function getCommentReactions(int $commentId): array
    {
        $stmt = $this->db->prepare("
            SELECT emoji, GROUP_CONCAT(user_email) AS users, COUNT(*) AS count
            FROM projecthub_comment_reactions
            WHERE comment_id = ?
            GROUP BY emoji
        ");
        $stmt->execute([$commentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Batched fetch: load all reactions for many comments in ONE query
     * instead of N. Used by EnhancedComments.vue when rendering a thread.
     *
     * @param array<int> $commentIds
     * @return array<int, array> Keyed by comment_id, each value is the
     *                            same shape as getCommentReactions().
     */
    public function getCommentReactionsBatch(array $commentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $commentIds), fn($x) => $x > 0)));
        if (empty($ids)) return [];

        // Cap so the IN(...) clause doesn't grow unbounded.
        $ids = array_slice($ids, 0, 500);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT comment_id, emoji, GROUP_CONCAT(user_email) AS users, COUNT(*) AS count
            FROM projecthub_comment_reactions
            WHERE comment_id IN ({$placeholders})
            GROUP BY comment_id, emoji
        ");
        $stmt->execute($ids);

        $out = [];
        foreach ($ids as $id) $out[$id] = []; // ensure key exists for every requested id
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int)$row['comment_id'];
            unset($row['comment_id']);
            $out[$cid][] = $row;
        }
        return $out;
    }

    /**
     * Batched assignee add: one INSERT IGNORE for many emails. Returns
     * the count of newly-inserted rows plus the refreshed assignee list
     * for the card. Used by CardAssigneesPanel.vue "assign group".
     *
     * @param int $cardId
     * @param array<string> $emails
     * @param string $role
     * @return array{added:int,skipped:int,assignees:array<array>}
     */
    public function addAssigneesBatch(int $cardId, array $emails, string $role = 'assignee'): array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            fn($e) => strtolower(trim((string)$e)),
            $emails
        ))));
        if (empty($emails)) {
            return ['added' => 0, 'skipped' => 0, 'assignees' => $this->getCardAssignees($cardId)];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($emails), '(?,?,?,\'assigned\')'));
            $values = [];
            foreach ($emails as $e) {
                $values[] = $cardId;
                $values[] = $e;
                $values[] = $role;
            }

            $stmt = $this->db->prepare("
                INSERT INTO projecthub_card_assignees (card_id, user_email, role, status)
                VALUES {$placeholders}
                ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute($values);
            $touched = $stmt->rowCount();

            $this->syncPrimaryAssignee($cardId);
            foreach ($emails as $e) {
                $this->autoAddBoardMember($cardId, $e);
            }
            $this->log("Bulk assigned " . count($emails) . " user(s) to card {$cardId}");

            // MySQL: rowCount for INSERT...ON DUPLICATE returns 1 per
            // insert + 2 per updated row (when row actually changes), so
            // it's a noisy estimate. We treat it as "touched" rather
            // than "added".
            return [
                'added' => $touched,
                'skipped' => max(0, count($emails) - $touched),
                'assignees' => $this->getCardAssignees($cardId),
            ];
        } catch (\PDOException $e) {
            $this->log("addAssigneesBatch error: " . $e->getMessage());
            return ['added' => 0, 'skipped' => count($emails), 'assignees' => $this->getCardAssignees($cardId), 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Comment Read Tracking
    // =========================================================================

    public function markCommentsRead(int $cardId, string $userEmail): bool
    {
        try {
            $email = strtolower($userEmail);

            // Get the latest comment ID for this card
            $stmt = $this->db->prepare("
                SELECT MAX(id) AS max_id FROM webmail_card_comments WHERE card_id = ?
            ");
            $stmt->execute([$cardId]);
            $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'];

            $stmt2 = $this->db->prepare("
                INSERT INTO projecthub_comment_reads (card_id, user_email, last_read_comment_id, last_read_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    last_read_comment_id = IFNULL(VALUES(last_read_comment_id), last_read_comment_id),
                    last_read_at = NOW()
            ");
            $stmt2->execute([$cardId, $email, $maxId ?: null]);
            return true;
        } catch (\PDOException $e) {
            $this->log("markCommentsRead error: " . $e->getMessage());
            return false;
        }
    }

    public function getUnreadCommentCount(int $cardId, string $userEmail): int
    {
        try {
            $email = strtolower($userEmail);

            $stmt = $this->db->prepare("
                SELECT last_read_comment_id FROM projecthub_comment_reads
                WHERE card_id = ? AND user_email = ?
            ");
            $stmt->execute([$cardId, $email]);
            $read = $stmt->fetch(PDO::FETCH_ASSOC);
            $lastReadId = $read ? (int)$read['last_read_comment_id'] : 0;

            $stmt2 = $this->db->prepare("
                SELECT COUNT(*) AS cnt FROM webmail_card_comments
                WHERE card_id = ? AND id > ? AND user_email != ?
            ");
            $stmt2->execute([$cardId, $lastReadId, $email]);
            return (int)$stmt2->fetch(PDO::FETCH_ASSOC)['cnt'];
        } catch (\PDOException $e) {
            return 0;
        }
    }

    // =========================================================================
    // Comment Attachments
    // =========================================================================

    public function addCommentAttachment(int $commentId, array $data): ?array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO projecthub_comment_attachments (comment_id, type, drive_file_id, drive_folder_id, url, name)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $commentId,
                $data['type'] ?? 'file',
                $data['drive_file_id'] ?? null,
                $data['drive_folder_id'] ?? null,
                $data['url'] ?? null,
                $data['name'] ?? null,
            ]);
            $id = (int)$this->db->lastInsertId();

            $stmt2 = $this->db->prepare("SELECT * FROM projecthub_comment_attachments WHERE id = ?");
            $stmt2->execute([$id]);
            return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            $this->log("addCommentAttachment error: " . $e->getMessage());
            return null;
        }
    }

    public function getCommentAttachments(int $commentId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM projecthub_comment_attachments WHERE comment_id = ?
        ");
        $stmt->execute([$commentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // My Work (server-side aggregation)
    // =========================================================================

    public function getMyWork(string $userEmail, string $grouping = 'day'): array
    {
        $email = strtolower($userEmail);

        $clientJoin = "LEFT JOIN projecthub_folder_boards pfb ON pfb.board_id = b.id LEFT JOIN projecthub_folders pf ON pf.id = pfb.folder_id LEFT JOIN projecthub_spaces ps ON ps.id = pf.space_id LEFT JOIN clients cl ON cl.id = ps.client_id";
        $clientSelect = "cl.display_name AS client_name";
        $ownerSelect = "b.owner_email AS board_owner";

        // Query 1: cards assigned via projecthub_card_assignees
        $stmt = $this->db->prepare("
            SELECT
                c.id AS card_id, c.parent_card_id, c.title, c.due_date, c.start_date, c.completed, c.description, c.assigned_to,
                c.created_at, c.created_by, c.time_estimate_seconds,
                ca.status, ca.role, ca.difficulty_weight, ca.time_spent_seconds,
                l.id AS list_id, l.name AS list_name,
                b.id AS board_id, b.name AS board_name,
                {$clientSelect}, {$ownerSelect}
            FROM projecthub_card_assignees ca
            JOIN webmail_board_cards c ON c.id = ca.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            {$clientJoin}
            WHERE ca.user_email = ?
        ");
        $stmt->execute([$email]);
        $assignedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $phCards = [];
        $reasonMap = [];
        $cardIndex = [];
        foreach ($assignedRows as $card) {
            $cid = (int)$card['card_id'];
            if (!isset($cardIndex[$cid])) {
                $cardIndex[$cid] = count($phCards);
                $phCards[] = $card;
            }
            $reasonMap[$cid] = ['assigned'];
        }

        // Query 2: cards assigned via webmail_board_cards.assigned_to (comma-separated)
        $stmt2 = $this->db->prepare("
            SELECT
                c.id AS card_id, c.parent_card_id, c.title, c.due_date, c.start_date, c.completed, c.description, c.assigned_to,
                c.created_at, c.created_by, c.time_estimate_seconds,
                NULL AS status, NULL AS role, 1 AS difficulty_weight, 0 AS time_spent_seconds,
                l.id AS list_id, l.name AS list_name,
                b.id AS board_id, b.name AS board_name,
                {$clientSelect}, {$ownerSelect}
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            {$clientJoin}
            WHERE (LOWER(c.assigned_to) = ? OR LOWER(c.assigned_to) LIKE ? OR LOWER(c.assigned_to) LIKE ? OR LOWER(c.assigned_to) LIKE ?)
        ");
        $stmt2->execute([$email, "$email,%", "%,$email,%", "%,$email"]);
        $directCards = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($directCards as $dc) {
            $cid = $dc['card_id'];
            if (!isset($cardIndex[$cid])) {
                $cardIndex[$cid] = count($phCards);
                $phCards[] = $dc;
                $reasonMap[$cid] = ['direct_assigned'];
            } else {
                $reasonMap[$cid][] = 'direct_assigned';
            }
        }

        // Query 3: ALL top-level cards from boards owned by user
        $stmt3 = $this->db->prepare("
            SELECT
                c.id AS card_id, c.parent_card_id, c.title, c.due_date, c.start_date, c.completed, c.description, c.assigned_to,
                c.created_at, c.created_by, c.time_estimate_seconds,
                NULL AS status, NULL AS role, 1 AS difficulty_weight, 0 AS time_spent_seconds,
                l.id AS list_id, l.name AS list_name,
                b.id AS board_id, b.name AS board_name,
                {$clientSelect}, {$ownerSelect}
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            {$clientJoin}
            WHERE LOWER(b.owner_email) = ? AND (c.parent_card_id IS NULL OR c.parent_card_id = 0) AND c.archived = 0
        ");
        $stmt3->execute([$email]);
        foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [] as $oc) {
            $cid = $oc['card_id'];
            if (!isset($cardIndex[$cid])) {
                $cardIndex[$cid] = count($phCards);
                $phCards[] = $oc;
                $reasonMap[$cid] = ['owner'];
            } else {
                $reasonMap[$cid][] = 'owner';
            }
        }

        foreach ($phCards as &$card) {
            $card['reasons'] = array_values(array_unique($reasonMap[$card['card_id']] ?? []));
        }
        unset($card);

        // Promote subtask cards to their parent cards
        $parentIdsNeeded = [];
        $subtaskCardIds = [];
        foreach ($phCards as $card) {
            $pid = (int)($card['parent_card_id'] ?? 0);
            if ($pid > 0) {
                $subtaskCardIds[(int)$card['card_id']] = true;
                if (!isset($cardIndex[$pid])) {
                    $parentIdsNeeded[$pid] = true;
                }
            }
        }
        if (!empty($parentIdsNeeded)) {
            $pids = array_keys($parentIdsNeeded);
            $placeholders = implode(',', array_fill(0, count($pids), '?'));
            $pStmt = $this->db->prepare("
                SELECT c.id AS card_id, c.parent_card_id, c.title, c.due_date, c.start_date, c.completed, c.description, c.assigned_to,
                       c.created_at, c.created_by, c.time_estimate_seconds,
                       NULL AS status, NULL AS role, 1 AS difficulty_weight, 0 AS time_spent_seconds,
                       l.id AS list_id, l.name AS list_name,
                       b.id AS board_id, b.name AS board_name,
                       {$clientSelect}, {$ownerSelect}
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON l.id = c.list_id
                JOIN webmail_boards b ON b.id = l.board_id
                {$clientJoin}
                WHERE c.id IN ({$placeholders})
            ");
            $pStmt->execute($pids);
            foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pc) {
                $cid = (int)$pc['card_id'];
                if (!isset($cardIndex[$cid])) {
                    $cardIndex[$cid] = count($phCards);
                    $phCards[] = $pc;
                    $reasonMap[$cid] = ['subtask_assigned'];
                }
            }
        }
        // Remove subtask-level entries; they'll appear nested under their parent
        $phCards = array_values(array_filter($phCards, function ($card) use ($subtaskCardIds) {
            return !isset($subtaskCardIds[(int)$card['card_id']]);
        }));

        foreach ($phCards as &$card) {
            $card['is_subtask'] = false;
        }
        unset($card);

        // Sort: incomplete first, then by urgency/due date
        usort($phCards, function ($a, $b) {
            $aComp = $a['completed'] ? 1 : 0;
            $bComp = $b['completed'] ? 1 : 0;
            if ($aComp !== $bComp) return $aComp - $bComp;

            $aPri = $this->duePriority($a);
            $bPri = $this->duePriority($b);
            if ($aPri !== $bPri) return $aPri - $bPri;

            $aDue = $a['due_date'] ?? '9999-12-31';
            $bDue = $b['due_date'] ?? '9999-12-31';
            return strcmp($aDue, $bDue);
        });

        $cards = $this->enrichMyWorkCards($phCards, $email);

        if ($grouping === 'none') return $cards;

        $grouped = [];
        foreach ($cards as $card) {
            $key = $this->getGroupKey($card, $grouping);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['label' => $key, 'cards' => []];
            }
            $grouped[$key]['cards'][] = $card;
        }
        return array_values($grouped);
    }

    private function duePriority(array $card): int
    {
        if (($card['status'] ?? '') === 'blocked') return 0;
        if (!empty($card['due_date'])) {
            $due = strtotime($card['due_date']);
            $today = strtotime('today');
            if ($due < $today) return 1;
            if ($due === $today) return 2;
            return 3;
        }
        return 4;
    }

    public function getMyCreated(string $userEmail): array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("
            SELECT
                c.id AS card_id, c.parent_card_id, c.title, c.due_date, c.start_date, c.completed, c.assigned_to,
                c.created_at, c.created_by, c.time_estimate_seconds,
                l.name AS list_name, b.id AS board_id, b.name AS board_name,
                cl.display_name AS client_name, b.owner_email AS board_owner
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            LEFT JOIN projecthub_folder_boards pfb ON pfb.board_id = b.id
            LEFT JOIN projecthub_folders pf ON pf.id = pfb.folder_id
            LEFT JOIN projecthub_spaces ps ON ps.id = pf.space_id
            LEFT JOIN clients cl ON cl.id = ps.client_id
            WHERE LOWER(c.created_by) = ? AND (c.parent_card_id IS NULL OR c.parent_card_id = 0)
            ORDER BY c.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$email]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->enrichMyWorkCards($cards, $email);
    }

    private function enrichMyWorkCards(array $cards, string $email): array
    {
        if (empty($cards)) {
            return [];
        }

        $boardIds = [];
        $cardIds = [];
        foreach ($cards as $card) {
            if (!empty($card['board_id'])) {
                $boardIds[] = (int)$card['board_id'];
            }
            if (!empty($card['card_id'])) {
                $cardIds[] = (int)$card['card_id'];
            }
        }

        $cardIds = array_values(array_unique($cardIds));
        $financialAccessByBoard = $this->loadFinancialAccessMap(array_values(array_unique($boardIds)), $email);
        $financialsByCard = $this->loadCardFinancialsMap($cardIds);
        $assigneesByCard = $this->loadCardAssigneesMap($cardIds);
        $userTrackedTimeByCard = $this->loadCardUserTrackedTimeMap($cardIds, $email);
        $totalTrackedTimeByCard = $this->loadCardTotalTrackedTimeMap($cardIds);
        $activityByCard = $this->loadCardActivityMap($cardIds);
        $seenByCard = $this->loadCardSeenMap($cardIds, $email);
        $updateSignalsByCard = $this->loadCardUpdateSignalMap($cardIds, $email);

        foreach ($cards as &$card) {
            $card['completed'] = (bool)($card['completed'] ?? false);

            $boardId = (int)($card['board_id'] ?? 0);
            $cardId = (int)($card['card_id'] ?? 0);
            $isOwner = !empty($card['board_owner']) && strtolower((string)$card['board_owner']) === $email;
            $canViewFinancials = $isOwner || ($financialAccessByBoard[$boardId] ?? false);

            $card['can_view_financials'] = $canViewFinancials;
            $card['estimated_revenue'] = null;
            $card['estimated_cost'] = null;
            $card['financial_currency'] = null;
            $card['time_spent_seconds'] = (int)($userTrackedTimeByCard[$cardId] ?? 0);
            $card['total_tracked_seconds'] = (int)($totalTrackedTimeByCard[$cardId] ?? 0);
            $card['time_estimate_seconds'] = isset($card['time_estimate_seconds']) ? (int)$card['time_estimate_seconds'] : null;
            $card['assignees'] = $assigneesByCard[$cardId] ?? $this->buildFallbackAssignees($card);
            $card['latest_comments'] = $activityByCard[$cardId]['latest_comments'] ?? [];
            $card['comment_count'] = $activityByCard[$cardId]['comment_count'] ?? 0;
            $card['attachments'] = $activityByCard[$cardId]['attachments'] ?? [];
            $card['latest_attachments'] = $activityByCard[$cardId]['latest_attachments'] ?? [];
            $card['attachment_count'] = $activityByCard[$cardId]['attachment_count'] ?? 0;

            $lastSeenAt = $seenByCard[$cardId]['last_seen_at'] ?? null;
            $latestUpdateAt = null;
            foreach ([
                $updateSignalsByCard[$cardId]['latest_comment_at'] ?? null,
                $updateSignalsByCard[$cardId]['latest_attachment_at'] ?? null,
                $updateSignalsByCard[$cardId]['latest_assignee_at'] ?? null,
            ] as $timestamp) {
                if (!$timestamp) {
                    continue;
                }
                if ($latestUpdateAt === null || strtotime($timestamp) > strtotime($latestUpdateAt)) {
                    $latestUpdateAt = $timestamp;
                }
            }

            $createdBy = strtolower((string)($card['created_by'] ?? ''));
            if (!$lastSeenAt && !empty($card['created_at']) && $createdBy !== '' && $createdBy !== $email) {
                if ($latestUpdateAt === null || strtotime((string)$card['created_at']) > strtotime($latestUpdateAt)) {
                    $latestUpdateAt = $card['created_at'];
                }
            }

            $card['last_seen_at'] = $lastSeenAt;
            $card['latest_update_at'] = $latestUpdateAt;
            $card['has_updates'] = $latestUpdateAt !== null && ($lastSeenAt === null || strtotime($latestUpdateAt) > strtotime($lastSeenAt));

            if ($canViewFinancials && isset($financialsByCard[$cardId])) {
                $card['estimated_revenue'] = $financialsByCard[$cardId]['estimated_revenue'];
                $card['estimated_cost'] = $financialsByCard[$cardId]['estimated_cost'];
                $card['financial_currency'] = $financialsByCard[$cardId]['financial_currency'];
            }
        }
        unset($card);

        // Fetch subtasks for all top-level cards in one batch query
        $topLevelIds = [];
        foreach ($cards as $card) {
            if (empty($card['is_subtask'])) {
                $topLevelIds[] = (int)$card['card_id'];
            }
        }

        $subtaskIdSet = [];

        if (!empty($topLevelIds)) {
            $ph = implode(',', array_fill(0, count($topLevelIds), '?'));
            $stStmt = $this->db->prepare("
                SELECT c.id, c.parent_card_id, c.title, c.completed, c.position, c.assigned_to,
                       c.due_date, c.start_date, c.created_at, c.time_estimate_seconds,
                       (SELECT GROUP_CONCAT(ca2.user_email) FROM projecthub_card_assignees ca2 WHERE ca2.card_id = c.id) AS assignee_emails,
                       (SELECT GROUP_CONCAT(DISTINCT ca3.status) FROM projecthub_card_assignees ca3 WHERE ca3.card_id = c.id) AS assignee_statuses,
                       (SELECT COUNT(*) FROM webmail_board_cards ch WHERE ch.parent_card_id = c.id) AS child_count
                FROM webmail_board_cards c
                WHERE c.parent_card_id IN ({$ph}) AND c.archived = 0
                ORDER BY c.position ASC, c.created_at ASC
            ");
            $stStmt->execute($topLevelIds);
            $allSubtasks = $stStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $subtasksByParent = [];
            $subtasksWithChildren = [];
            foreach ($allSubtasks as $st) {
                $pid = (int)$st['parent_card_id'];
                $st['completed'] = (bool)$st['completed'];
                $st['has_children'] = (int)$st['child_count'] > 0;
                $st['children'] = [];
                $subtasksByParent[$pid][] = $st;
                $subtaskIdSet[(int)$st['id']] = true;
                if ($st['has_children']) {
                    $subtasksWithChildren[] = (int)$st['id'];
                }
            }

            // Fetch sub-subtasks (level 2) for subtasks that have children
            if (!empty($subtasksWithChildren)) {
                $ph2 = implode(',', array_fill(0, count($subtasksWithChildren), '?'));
                $l2Stmt = $this->db->prepare("
                    SELECT c.id, c.parent_card_id, c.title, c.completed, c.position, c.assigned_to,
                           c.due_date, c.created_at,
                           (SELECT GROUP_CONCAT(ca2.user_email) FROM projecthub_card_assignees ca2 WHERE ca2.card_id = c.id) AS assignee_emails,
                           (SELECT GROUP_CONCAT(DISTINCT ca3.status) FROM projecthub_card_assignees ca3 WHERE ca3.card_id = c.id) AS assignee_statuses
                    FROM webmail_board_cards c
                    WHERE c.parent_card_id IN ({$ph2}) AND c.archived = 0
                    ORDER BY c.position ASC, c.created_at ASC
                ");
                $l2Stmt->execute($subtasksWithChildren);
                $level2 = $l2Stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                $childrenByParent = [];
                foreach ($level2 as $ch) {
                    $ch['completed'] = (bool)$ch['completed'];
                    $childrenByParent[(int)$ch['parent_card_id']][] = $ch;
                    $subtaskIdSet[(int)$ch['id']] = true;
                }

                // Attach children to their parent subtasks
                foreach ($subtasksByParent as &$stGroup) {
                    foreach ($stGroup as &$st) {
                        if ($st['has_children']) {
                            $st['children'] = $childrenByParent[(int)$st['id']] ?? [];
                        }
                    }
                    unset($st);
                }
                unset($stGroup);
            }

            $matchesUser = static function (array $item) use ($email): bool {
                $emails = [];
                foreach ([$item['assignee_emails'] ?? '', $item['assigned_to'] ?? ''] as $raw) {
                    foreach (explode(',', (string)$raw) as $part) {
                        $part = strtolower(trim($part));
                        if ($part !== '') {
                            $emails[$part] = true;
                        }
                    }
                }
                return isset($emails[$email]);
            };

            foreach ($cards as &$card) {
                $cid = (int)$card['card_id'];
                $subtasks = $subtasksByParent[$cid] ?? [];
                $isOwner = !empty($card['board_owner']) && strtolower((string)$card['board_owner']) === $email;

                if (!$isOwner) {
                    $visibleSubtasks = [];
                    foreach ($subtasks as $st) {
                        $children = array_values(array_filter($st['children'] ?? [], $matchesUser));
                        $st['children'] = $children;

                        if ($matchesUser($st) || !empty($children)) {
                            $visibleSubtasks[] = $st;
                        }
                    }
                    $subtasks = $visibleSubtasks;
                }

                $card['subtasks'] = $subtasks;
                $card['subtask_count'] = count($card['subtasks']);
                $card['subtask_done_count'] = count(array_filter($card['subtasks'], fn($s) => $s['completed']));
            }
            unset($card);
        }

        // Remove cards that already appear as subtasks of other cards
        if (!empty($subtaskIdSet)) {
            $cards = array_values(array_filter($cards, function ($card) use ($subtaskIdSet) {
                return !isset($subtaskIdSet[(int)$card['card_id']]);
            }));
        }

        return $cards;
    }

    private function loadFinancialAccessMap(array $boardIds, string $email): array
    {
        if (empty($boardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $params = array_merge([$email], $boardIds);

        $stmt = $this->db->prepare("
            SELECT board_id, COALESCE(can_view_financials, 0) AS can_view_financials
            FROM webmail_board_members
            WHERE LOWER(user_email) = ?
            AND board_id IN ($placeholders)
        ");
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['board_id']] = (bool)$row['can_view_financials'];
        }

        return $map;
    }

    private function loadCardUserTrackedTimeMap(array $cardIds, string $email): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $params = array_merge([$email], $cardIds);

        $stmt = $this->db->prepare("
            SELECT card_id, COALESCE(SUM(duration_seconds), 0) AS total_seconds
            FROM projecthub_work_sessions
            WHERE LOWER(user_email) = ?
            AND card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']] = (int)($row['total_seconds'] ?? 0);
        }

        return $map;
    }

    private function loadCardTotalTrackedTimeMap(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));

        $stmt = $this->db->prepare("
            SELECT card_id, COALESCE(SUM(duration_seconds), 0) AS total_seconds
            FROM projecthub_work_sessions
            WHERE card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $stmt->execute($cardIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']] = (int)($row['total_seconds'] ?? 0);
        }

        return $map;
    }

    private function loadCardSeenMap(array $cardIds, string $email): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $params = array_merge([$email], $cardIds);
        $stmt = $this->db->prepare("
            SELECT card_id, last_read_at, last_read_comment_id
            FROM projecthub_comment_reads
            WHERE LOWER(user_email) = ?
            AND card_id IN ($placeholders)
        ");
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']] = [
                'last_seen_at' => $row['last_read_at'] ?? null,
                'last_read_comment_id' => $row['last_read_comment_id'] !== null ? (int)$row['last_read_comment_id'] : null,
            ];
        }

        return $map;
    }

    private function loadCardFinancialsMap(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
            $stmt = $this->db->prepare("
                SELECT card_id, estimated_revenue, estimated_cost, currency
                FROM boardpro_card_financials
                WHERE card_id IN ($placeholders)
            ");
            $stmt->execute($cardIds);

            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $map[(int)$row['card_id']] = [
                    'estimated_revenue' => $row['estimated_revenue'] !== null ? (float)$row['estimated_revenue'] : null,
                    'estimated_cost' => $row['estimated_cost'] !== null ? (float)$row['estimated_cost'] : null,
                    'financial_currency' => $row['currency'] ?? 'HUF',
                ];
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadCardAssigneesMap(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
        $stmt = $this->db->prepare("
            SELECT id, card_id, user_email, role, status, time_spent_seconds, difficulty_weight
            FROM projecthub_card_assignees
            WHERE card_id IN ($placeholders)
            ORDER BY created_at ASC
        ");
        $stmt->execute($cardIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cardId = (int)$row['card_id'];
            if (!isset($map[$cardId])) {
                $map[$cardId] = [];
            }

            $map[$cardId][] = [
                'id' => (int)$row['id'],
                'user_email' => strtolower((string)$row['user_email']),
                'role' => $row['role'] ?? 'assignee',
                'status' => $row['status'] ?? 'assigned',
                'time_spent_seconds' => (int)($row['time_spent_seconds'] ?? 0),
                'difficulty_weight' => (int)($row['difficulty_weight'] ?? 1),
            ];
        }

        return $map;
    }

    private function loadCardUpdateSignalMap(array $cardIds, string $email): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $map = [];
        foreach ($cardIds as $cardId) {
            $map[(int)$cardId] = [
                'latest_comment_at' => null,
                'latest_attachment_at' => null,
                'latest_assignee_at' => null,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));

        $commentParams = array_merge([$email], $cardIds);
        $commentStmt = $this->db->prepare("
            SELECT card_id, MAX(created_at) AS latest_comment_at
            FROM webmail_card_comments
            WHERE LOWER(user_email) != ?
            AND card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $commentStmt->execute($commentParams);
        foreach ($commentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']]['latest_comment_at'] = $row['latest_comment_at'] ?? null;
        }

        $attachmentParams = array_merge([$email], $cardIds);
        $attachmentStmt = $this->db->prepare("
            SELECT card_id, MAX(created_at) AS latest_attachment_at
            FROM webmail_card_attachments
            WHERE (created_by IS NULL OR LOWER(created_by) != ?)
            AND card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $attachmentStmt->execute($attachmentParams);
        foreach ($attachmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']]['latest_attachment_at'] = $row['latest_attachment_at'] ?? null;
        }

        $assigneeParams = array_merge([$email], $cardIds);
        $assigneeStmt = $this->db->prepare("
            SELECT card_id, MAX(updated_at) AS latest_assignee_at
            FROM projecthub_card_assignees
            WHERE LOWER(user_email) != ?
            AND card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $assigneeStmt->execute($assigneeParams);
        foreach ($assigneeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']]['latest_assignee_at'] = $row['latest_assignee_at'] ?? null;
        }

        return $map;
    }

    private function buildFallbackAssignees(array $card): array
    {
        $emails = array_filter(array_map(
            fn($email) => strtolower(trim((string)$email)),
            explode(',', (string)($card['assigned_to'] ?? ''))
        ));

        return array_map(static fn($email) => [
            'id' => null,
            'user_email' => $email,
            'role' => 'assignee',
            'status' => !empty($card['completed']) ? 'done' : ($card['status'] ?? 'assigned'),
            'time_spent_seconds' => 0,
            'difficulty_weight' => 1,
        ], $emails);
    }

    private function loadCardActivityMap(array $cardIds): array
    {
        if (empty($cardIds)) {
            return [];
        }

        $map = [];
        foreach ($cardIds as $cardId) {
            $map[(int)$cardId] = [
                'latest_comments' => [],
                'comment_count' => 0,
                'attachments' => [],
                'latest_attachments' => [],
                'attachment_count' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($cardIds), '?'));

        $commentCountStmt = $this->db->prepare("
            SELECT card_id, COUNT(*) AS cnt
            FROM webmail_card_comments
            WHERE card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $commentCountStmt->execute($cardIds);
        foreach ($commentCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']]['comment_count'] = (int)$row['cnt'];
        }

        $attachmentCountStmt = $this->db->prepare("
            SELECT card_id, COUNT(*) AS cnt
            FROM webmail_card_attachments
            WHERE card_id IN ($placeholders)
            GROUP BY card_id
        ");
        $attachmentCountStmt->execute($cardIds);
        foreach ($attachmentCountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['card_id']]['attachment_count'] = (int)$row['cnt'];
        }

        $commentStmt = $this->db->prepare("
            SELECT card_id, user_email, content, created_at
            FROM webmail_card_comments
            WHERE card_id IN ($placeholders)
            ORDER BY card_id ASC, created_at DESC, id DESC
        ");
        $commentStmt->execute($cardIds);
        foreach ($commentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cardId = (int)$row['card_id'];
            if (count($map[$cardId]['latest_comments']) >= 2) {
                continue;
            }
            $map[$cardId]['latest_comments'][] = [
                'user_email' => strtolower((string)($row['user_email'] ?? '')),
                'content' => trim((string)($row['content'] ?? '')),
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $attachmentStmt = $this->db->prepare("
            SELECT
                a.card_id,
                a.id,
                a.name,
                a.created_at,
                a.drive_file_id,
                a.url,
                f.original_name,
                f.mime_type,
                f.folder_id
            FROM webmail_card_attachments a
            LEFT JOIN drive_files f ON f.id = a.drive_file_id
            WHERE a.card_id IN ($placeholders)
            ORDER BY a.card_id ASC, a.created_at DESC, a.id DESC
        ");
        $attachmentStmt->execute($cardIds);
        foreach ($attachmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cardId = (int)$row['card_id'];
            $attachment = [
                'id' => (int)$row['id'],
                'name' => trim((string)($row['name'] ?? 'Attachment')),
                'created_at' => $row['created_at'] ?? null,
                'drive_file_id' => $row['drive_file_id'] !== null ? (int)$row['drive_file_id'] : null,
                'url' => $row['url'] ?? null,
                'original_name' => $row['original_name'] ?? null,
                'mime_type' => $row['mime_type'] ?? null,
                'folder_id' => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
            ];
            $map[$cardId]['attachments'][] = $attachment;
            if (count($map[$cardId]['latest_attachments']) >= 3) {
                continue;
            }
            $map[$cardId]['latest_attachments'][] = $attachment;
        }

        return $map;
    }

    private function getGroupKey(array $card, string $grouping): string
    {
        if ($grouping === 'board') {
            return $card['board_name'] ?? 'No board';
        }
        if ($grouping === 'client') {
            return $card['client_name'] ?? 'No client';
        }
        if ($grouping === 'status') {
            return ucfirst($card['status'] ?? 'assigned');
        }
        if ($grouping === 'list') {
            return $card['list_name'] ?? 'Unknown list';
        }

        $dueDate = $card['due_date'] ?? null;
        if (!$dueDate) return 'No due date';
        $today = date('Y-m-d');
        if ($dueDate < $today) return 'Overdue';
        if ($dueDate === $today) return 'Today';
        if ($grouping === 'day') return $dueDate;
        $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($dueDate)));
        return "Week of $weekStart";
    }

    // =========================================================================
    // Director Summary (per-person aggregates)
    // =========================================================================

    public function getDirectorSummary(array $filters = []): array
    {
        [$memberSql, $memberParams] = $this->buildMemberFilters($filters, 'ca.user_email');
        [$roleSql, $roleParams] = $this->buildRoleSlugExistsFilter($filters, 'ca.user_email');
        $memberSql .= $roleSql;
        $memberParams = array_merge($memberParams, $roleParams);

        $memberMap = [];

        $phStmt = $this->db->prepare("
            SELECT
                ca.user_email,
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN ca.status = 'done' THEN 1 ELSE 0 END) AS done_tasks,
                SUM(CASE WHEN ca.status = 'working' THEN 1 ELSE 0 END) AS working_tasks,
                SUM(CASE WHEN ca.status = 'blocked' THEN 1 ELSE 0 END) AS blocked_tasks,
                SUM(CASE WHEN ca.status = 'review' THEN 1 ELSE 0 END) AS review_tasks,
                SUM(ca.time_spent_seconds) AS total_time,
                SUM(CASE WHEN c.due_date IS NOT NULL AND c.due_date < CURDATE() AND c.completed = 0 THEN 1 ELSE 0 END) AS overdue_tasks,
                SUM(ca.difficulty_weight) AS total_difficulty
            FROM projecthub_card_assignees ca
            JOIN webmail_board_cards c ON c.id = ca.card_id
            WHERE c.archived = 0
            $memberSql
            GROUP BY ca.user_email
        ");
        $phStmt->execute($memberParams);
        foreach ($phStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $email = strtolower($row['user_email']);
            $memberMap[$email] = $row;
            $memberMap[$email]['user_email'] = $email;
            $memberMap[$email]['_ph_card_ids'] = [];
        }

        $allowedEmails = $this->resolveAllowedEmails($filters);

        $phCardStmt = $this->db->query("SELECT card_id, user_email FROM projecthub_card_assignees");
        $phCardIdsByEmail = [];
        foreach ($phCardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $phCardIdsByEmail[strtolower($row['user_email'])][] = (int)$row['card_id'];
        }
        foreach ($memberMap as $email => &$m) {
            $m['_ph_card_ids'] = $phCardIdsByEmail[$email] ?? [];
        }
        unset($m);

        $directStmt = $this->db->query("
            SELECT c.id AS card_id, c.assigned_to, c.completed, c.due_date
            FROM webmail_board_cards c
            WHERE c.archived = 0
              AND c.assigned_to IS NOT NULL
              AND c.assigned_to != ''
        ");
        foreach ($directStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $emails = array_filter(array_map(
                fn($e) => strtolower(trim($e)),
                explode(',', $row['assigned_to'])
            ));
            $cardId = (int)$row['card_id'];
            foreach ($emails as $email) {
                if (!$email) continue;
                if ($allowedEmails !== null && !isset($allowedEmails[$email])) continue;
                if (isset($memberMap[$email]) && in_array($cardId, $memberMap[$email]['_ph_card_ids'])) {
                    continue;
                }
                if (!isset($memberMap[$email])) {
                    $memberMap[$email] = [
                        'user_email' => $email,
                        'total_tasks' => 0, 'done_tasks' => 0, 'working_tasks' => 0,
                        'blocked_tasks' => 0, 'review_tasks' => 0, 'total_time' => 0,
                        'overdue_tasks' => 0, 'total_difficulty' => 0, '_ph_card_ids' => [],
                    ];
                }
                $memberMap[$email]['total_tasks']++;
                if (!empty($row['completed'])) {
                    $memberMap[$email]['done_tasks']++;
                } elseif (!empty($row['due_date']) && $row['due_date'] < date('Y-m-d')) {
                    $memberMap[$email]['overdue_tasks']++;
                }
            }
        }

        $createdStmt = $this->db->query("
            SELECT LOWER(created_by) AS creator, COUNT(*) AS created_count
            FROM webmail_board_cards
            WHERE created_by IS NOT NULL AND created_by != ''
            GROUP BY LOWER(created_by)
        ");
        $createdMap = [];
        foreach ($createdStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $createdMap[$row['creator']] = (int)$row['created_count'];
        }

        $members = [];
        foreach ($memberMap as $email => $m) {
            unset($m['_ph_card_ids']);
            $m['created_tasks'] = $createdMap[$email] ?? 0;

            $active = (int)$m['total_tasks'] - (int)$m['done_tasks'] - (int)$m['blocked_tasks'];
            $score = max(0, $active) + ((int)$m['overdue_tasks'] * 2);
            $m['load_score'] = $score;
            $m['load_level'] = $score <= 3 ? 'light' : ($score <= 7 ? 'moderate' : ($score <= 12 ? 'heavy' : 'overloaded'));

            $members[] = $m;
        }

        $rolesMap = $this->fetchRoleSlugsByEmails(array_column($members, 'user_email'));
        foreach ($members as &$m) {
            $csv = $rolesMap[strtolower((string)($m['user_email'] ?? ''))] ?? '';
            $m['roles'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
        }
        unset($m);

        usort($members, fn($a, $b) => $b['load_score'] <=> $a['load_score']);

        return $members;
    }

    // =========================================================================
    // Traffic Table (per-member per-period)
    // =========================================================================

    public function getTrafficData(string $startDate, string $endDate, string $granularity = 'day', array $filters = []): array
    {
        [$memberSql, $memberParams] = $this->buildMemberFilters($filters, 'ca.user_email');
        [$roleSqlCa, $roleParamsCa] = $this->buildRoleSlugExistsFilter($filters, 'ca.user_email');
        $memberSql .= $roleSqlCa;
        $memberParams = array_merge($memberParams, $roleParamsCa);
        $allowedEmails = $this->resolveAllowedEmails($filters);

        $phStmt = $this->db->prepare("
            SELECT ca.user_email, c.id AS card_id, c.due_date, ca.difficulty_weight
            FROM projecthub_card_assignees ca
            JOIN webmail_board_cards c ON c.id = ca.card_id
            WHERE c.archived = 0 AND c.due_date BETWEEN ? AND ?
            $memberSql
        ");
        $phStmt->execute(array_merge([$startDate, $endDate], $memberParams));
        $phRows = $phStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $seenKeys = [];
        $taskRows = [];
        foreach ($phRows as $r) {
            $key = strtolower($r['user_email']) . ':' . $r['card_id'];
            $seenKeys[$key] = true;
            $taskRows[] = [
                'email' => strtolower($r['user_email']),
                'due_date' => $r['due_date'],
                'difficulty' => (int)($r['difficulty_weight'] ?? 1),
            ];
        }

        $directStmt = $this->db->prepare("
            SELECT c.id AS card_id, c.assigned_to, c.due_date
            FROM webmail_board_cards c
            WHERE c.archived = 0
              AND c.assigned_to IS NOT NULL AND c.assigned_to != ''
              AND c.due_date BETWEEN ? AND ?
        ");
        $directStmt->execute([$startDate, $endDate]);
        foreach ($directStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $emails = array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', $row['assigned_to'])));
            foreach ($emails as $email) {
                if ($allowedEmails !== null && !isset($allowedEmails[$email])) continue;
                $key = $email . ':' . $row['card_id'];
                if (isset($seenKeys[$key])) continue;
                $seenKeys[$key] = true;
                $taskRows[] = [
                    'email' => $email,
                    'due_date' => $row['due_date'],
                    'difficulty' => 1,
                ];
            }
        }

        [$wsSql, $wsParams] = $this->buildMemberFilters($filters, 'ws.user_email');
        [$roleSqlWs, $roleParamsWs] = $this->buildRoleSlugExistsFilter($filters, 'ws.user_email');
        $wsSql .= $roleSqlWs;
        $wsParams = array_merge($wsParams, $roleParamsWs);
        $sessionStmt = $this->db->prepare("
            SELECT ws.user_email, DATE(ws.started_at) AS work_date, SUM(ws.duration_seconds) AS total_seconds
            FROM projecthub_work_sessions ws
            WHERE ws.started_at BETWEEN ? AND ?
            $wsSql
            GROUP BY ws.user_email, DATE(ws.started_at)
        ");
        $sessionStmt->execute(array_merge([$startDate, $endDate . ' 23:59:59'], $wsParams));
        $sessions = $sessionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sessionMap = [];
        foreach ($sessions as $s) {
            $key = strtolower($s['user_email']) . '|' . $s['work_date'];
            $sessionMap[$key] = (int)$s['total_seconds'];
        }

        $memberData = [];
        foreach ($taskRows as $row) {
            $email = $row['email'];
            $date = $row['due_date'];
            $periodKey = $granularity === 'week'
                ? date('Y-m-d', strtotime('monday this week', strtotime($date)))
                : $date;

            if (!isset($memberData[$email])) {
                $memberData[$email] = ['email' => $email, 'periods' => []];
            }
            if (!isset($memberData[$email]['periods'][$periodKey])) {
                $memberData[$email]['periods'][$periodKey] = [
                    'period' => $periodKey,
                    'task_count' => 0,
                    'hours' => 0,
                    'difficulty' => 0,
                ];
            }
            $memberData[$email]['periods'][$periodKey]['task_count']++;
            $memberData[$email]['periods'][$periodKey]['difficulty'] += $row['difficulty'];
        }

        foreach ($sessionMap as $key => $seconds) {
            [$email, $date] = explode('|', $key);
            $periodKey = $granularity === 'week'
                ? date('Y-m-d', strtotime('monday this week', strtotime($date)))
                : $date;

            if (!isset($memberData[$email])) {
                $memberData[$email] = ['email' => $email, 'periods' => []];
            }
            if (!isset($memberData[$email]['periods'][$periodKey])) {
                $memberData[$email]['periods'][$periodKey] = [
                    'period' => $periodKey,
                    'task_count' => 0,
                    'hours' => 0,
                    'difficulty' => 0,
                ];
            }
            $memberData[$email]['periods'][$periodKey]['hours'] += round($seconds / 3600, 2);
        }

        foreach ($memberData as &$member) {
            $member['periods'] = array_values($member['periods']);
        }
        unset($member);

        $rolesMap = $this->fetchRoleSlugsByEmails(array_keys($memberData));
        foreach ($memberData as $email => &$member) {
            $csv = $rolesMap[strtolower((string)$email)] ?? '';
            $member['roles'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
        }
        unset($member);

        return array_values($memberData);
    }

    // =========================================================================
    // Team Schedule (Excel-like: tasks per member per day)
    // =========================================================================

    public function getTeamSchedule(string $startDate, string $endDate, array $filters = []): array
    {
        [$memberSql, $memberParams] = $this->buildMemberFilters($filters, 'ca.user_email');
        [$roleSql, $roleParams] = $this->buildRoleSlugExistsFilter($filters, 'ca.user_email');
        $memberSql .= $roleSql;
        $memberParams = array_merge($memberParams, $roleParams);
        $allowedEmails = $this->resolveAllowedEmails($filters);

        $dateFilter = "
            AND (
                (c.start_date IS NOT NULL AND c.due_date IS NOT NULL AND c.start_date <= ? AND c.due_date >= ?)
                OR (c.start_date IS NULL AND c.due_date IS NOT NULL AND c.due_date BETWEEN ? AND ?)
                OR (c.start_date IS NOT NULL AND c.due_date IS NULL AND c.start_date BETWEEN ? AND ?)
            )
        ";
        $dateParams = [$endDate, $startDate, $startDate, $endDate, $startDate, $endDate];

        $phStmt = $this->db->prepare("
            SELECT
                ca.user_email,
                ca.status AS assignee_status,
                c.id AS card_id, c.title, c.start_date, c.due_date, c.completed,
                l.name AS list_name, b.id AS board_id, b.name AS board_name
            FROM projecthub_card_assignees ca
            JOIN webmail_board_cards c ON c.id = ca.card_id
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.archived = 0 $dateFilter
            $memberSql
            ORDER BY ca.user_email, c.due_date ASC
        ");
        $phStmt->execute(array_merge($dateParams, $memberParams));
        $phRows = $phStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $seenKeys = [];
        foreach ($phRows as $r) {
            $seenKeys[strtolower($r['user_email']) . ':' . $r['card_id']] = true;
        }

        $directStmt = $this->db->prepare("
            SELECT
                c.id AS card_id, c.title, c.start_date, c.due_date, c.completed, c.assigned_to,
                l.name AS list_name, b.id AS board_id, b.name AS board_name
            FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.archived = 0
              AND c.assigned_to IS NOT NULL AND c.assigned_to != ''
              $dateFilter
            ORDER BY c.due_date ASC
        ");
        $directStmt->execute($dateParams);
        $directRows = $directStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allRows = $phRows;
        foreach ($directRows as $row) {
            $emails = array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', $row['assigned_to'])));
            foreach ($emails as $email) {
                if ($allowedEmails !== null && !isset($allowedEmails[$email])) continue;
                $key = $email . ':' . $row['card_id'];
                if (isset($seenKeys[$key])) continue;
                $seenKeys[$key] = true;
                $row['user_email'] = $email;
                $row['assignee_status'] = !empty($row['completed']) ? 'done' : 'assigned';
                $allRows[] = $row;
            }
        }

        $cardIds = array_unique(array_column($allRows, 'card_id'));
        $labelsMap = !empty($cardIds) ? $this->getLabelsForCards(array_map('intval', $cardIds)) : [];

        $members = [];
        foreach ($allRows as $row) {
            $email = strtolower($row['user_email']);
            if (!isset($members[$email])) {
                $members[$email] = ['email' => $email, 'days' => []];
            }

            $cid = (int)$row['card_id'];
            $taskStart = $row['start_date'] ?? $row['due_date'];
            $taskEnd = $row['due_date'] ?? $row['start_date'];

            if (!$taskStart && !$taskEnd) {
                continue;
            }

            $rangeStart = max($startDate, $taskStart ?? $startDate);
            $rangeEnd = min($endDate, $taskEnd ?? $endDate);
            $cur = new \DateTime($rangeStart);
            $last = new \DateTime($rangeEnd);

            while ($cur <= $last) {
                $dayKey = $cur->format('Y-m-d');
                $members[$email]['days'][$dayKey][] = $this->buildScheduleTask($row, $cid, $labelsMap);
                $cur->modify('+1 day');
            }
        }

        return array_values($members);
    }

    private function buildScheduleTask(array $row, int $cid, array $labelsMap): array
    {
        return [
            'card_id' => $cid,
            'title' => $row['title'],
            'status' => $row['assignee_status'],
            'completed' => (bool)$row['completed'],
            'board_name' => $row['board_name'],
            'board_id' => (int)$row['board_id'],
            'list_name' => $row['list_name'],
            'start_date' => $row['start_date'],
            'due_date' => $row['due_date'],
            'labels' => $labelsMap[$cid] ?? [],
        ];
    }

    // =========================================================================
    // Watchers
    // =========================================================================

    public function getCardWatchers(int $cardId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM projecthub_watchers WHERE card_id = ? ORDER BY created_at ASC");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addWatcher(int $cardId, string $userEmail): ?array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("INSERT IGNORE INTO projecthub_watchers (card_id, user_email) VALUES (?, ?)");
        $stmt->execute([$cardId, $email]);

        $fetch = $this->db->prepare("SELECT * FROM projecthub_watchers WHERE card_id = ? AND user_email = ?");
        $fetch->execute([$cardId, $email]);
        return $fetch->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function removeWatcher(int $cardId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projecthub_watchers WHERE card_id = ? AND user_email = ?");
        $stmt->execute([$cardId, strtolower($userEmail)]);
        return $stmt->rowCount() > 0;
    }

    public function isWatching(int $cardId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM projecthub_watchers WHERE card_id = ? AND user_email = ?");
        $stmt->execute([$cardId, strtolower($userEmail)]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get all emails that should receive notifications for a card:
     * assignees + watchers (deduplicated).
     */
    public function getCardNotificationRecipients(int $cardId): array
    {
        $resolver = new NotificationRecipientResolver($this->config);

        return $resolver->resolve($cardId, '', ['assignees', 'watchers'], [], false);
    }

    // =========================================================================
    // Notification Preferences
    // =========================================================================

    private const PH_NOTIF_TYPES = [
        'ph_assigned',
        'ph_status_changed',
        'ph_card_updated',
        'ph_comment_added',
        'ph_mention',
        'ph_dependency_added',
        'ph_dependency_removed',
        'ph_watcher_added',
        'ph_share_created',
        'ph_time_budget_warning',
        'ph_inactivity',
    ];

    public function getNotificationPrefs(string $userEmail): array
    {
        $stmt = $this->db->prepare(
            "SELECT notif_type, channel_inapp, channel_push, channel_email
             FROM projecthub_notification_prefs
             WHERE user_email = ?"
        );
        $stmt->execute([strtolower($userEmail)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byType = [];
        foreach ($rows as $r) {
            $byType[$r['notif_type']] = $r;
        }

        $result = [];
        foreach (self::PH_NOTIF_TYPES as $type) {
            $result[] = [
                'notif_type'    => $type,
                'channel_inapp' => (bool)($byType[$type]['channel_inapp'] ?? true),
                'channel_push'  => (bool)($byType[$type]['channel_push'] ?? true),
                'channel_email' => (bool)($byType[$type]['channel_email'] ?? false),
            ];
        }
        return $result;
    }

    public function updateNotificationPrefs(string $userEmail, array $prefs): void
    {
        $email = strtolower($userEmail);

        $stmt = $this->db->prepare(
            "INSERT INTO projecthub_notification_prefs
                (user_email, notif_type, channel_inapp, channel_push, channel_email)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                channel_inapp = VALUES(channel_inapp),
                channel_push  = VALUES(channel_push),
                channel_email = VALUES(channel_email)"
        );

        foreach ($prefs as $p) {
            $type = $p['notif_type'] ?? '';
            if (!in_array($type, self::PH_NOTIF_TYPES, true)) continue;

            $stmt->execute([
                $email,
                $type,
                (int)($p['channel_inapp'] ?? 1),
                (int)($p['channel_push'] ?? 1),
                (int)($p['channel_email'] ?? 0),
            ]);
        }
    }
}
