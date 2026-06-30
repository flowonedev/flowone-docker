<?php

namespace Webmail\Services;

use Webmail\Addons\Team\Services\ColleagueService;
use Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService;

class WatchFolderService
{
    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // PERMISSIONS
    // =========================================================================

    private function canManageShared(string $email, ?int $boardId = null): bool
    {
        $svc = new ColleagueService($this->config);
        if ($svc->isAdmin($email)) return true;

        if ($boardId) {
            $stmt = $this->db->prepare("SELECT 1 FROM webmail_boards WHERE id = ? AND LOWER(owner_email) = ?");
            $stmt->execute([$boardId, strtolower($email)]);
            if ($stmt->fetchColumn()) return true;
        }

        return false;
    }

    public function isAdmin(string $email): bool
    {
        $svc = new ColleagueService($this->config);
        return $svc->isAdmin($email);
    }

    public function isWatchFolderVisibleTo(int $watchFolderId, string $email): bool
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare("
            SELECT 1 FROM watch_folders wf
            LEFT JOIN webmail_boards b ON b.id = wf.board_id
            WHERE wf.id = ? AND (
                (wf.assigned_emails IS NOT NULL AND JSON_CONTAINS(wf.assigned_emails, JSON_QUOTE(?)))
                OR (wf.assigned_emails IS NULL AND wf.board_id IS NOT NULL AND (
                    LOWER(b.owner_email) = ?
                    OR EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = wf.board_id AND LOWER(bm.user_email) = ?)
                ))
                OR wf.creator_email = ?
            )
        ");
        $stmt->execute([$watchFolderId, $email, $email, $email, $email]);
        return (bool)$stmt->fetchColumn();
    }

    public function isBoardMember(int $boardId, string $email): bool
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare("
            SELECT 1 FROM webmail_boards b
            WHERE b.id = ? AND (
                LOWER(b.owner_email) = ?
                OR EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = ? AND LOWER(bm.user_email) = ?)
            )
        ");
        $stmt->execute([$boardId, $email, $boardId, $email]);
        return (bool)$stmt->fetchColumn();
    }

    public function isCardAssigneeOrBoardMember(int $cardId, string $email): bool
    {
        $email = strtolower($email);
        $stmt = $this->db->prepare("
            SELECT 1 FROM webmail_board_cards c
            JOIN webmail_board_lists l ON l.id = c.list_id
            LEFT JOIN webmail_boards b ON b.id = l.board_id
            WHERE c.id = ? AND (
                LOWER(b.owner_email) = ?
                OR EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = l.board_id AND LOWER(bm.user_email) = ?)
                OR EXISTS (SELECT 1 FROM projecthub_card_assignees ca WHERE ca.card_id = c.id AND LOWER(ca.user_email) = ?)
            )
        ");
        $stmt->execute([$cardId, $email, $email, $email]);
        return (bool)$stmt->fetchColumn();
    }

    // =========================================================================
    // WATCH FOLDERS
    // =========================================================================

    public function getWatchFolders(string $userEmail): array
    {
        $email = strtolower($userEmail);

        $stmt = $this->db->prepare("
            SELECT wf.*, c.name AS client_name, b.name AS board_name
            FROM watch_folders wf
            LEFT JOIN clients c ON c.id = wf.client_id
            LEFT JOIN webmail_boards b ON b.id = wf.board_id
            WHERE (
                (wf.assigned_emails IS NOT NULL AND JSON_CONTAINS(wf.assigned_emails, JSON_QUOTE(?)))
                OR (wf.assigned_emails IS NULL AND wf.board_id IS NOT NULL AND (
                    LOWER(b.owner_email) = ?
                    OR EXISTS (SELECT 1 FROM webmail_board_members bm WHERE bm.board_id = wf.board_id AND LOWER(bm.user_email) = ?)
                ))
                OR wf.creator_email = ?
            )
            ORDER BY wf.name
        ");
        $stmt->execute([$email, $email, $email, $email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get watch folders with paths resolved for a specific user (applies path overrides).
     * Used by the Drive app to get ready-to-watch paths.
     */
    public function getResolvedWatchFolders(string $userEmail): array
    {
        $folders = $this->getWatchFolders($userEmail);
        $overrides = $this->getActiveOverrides($userEmail);

        foreach ($folders as &$folder) {
            $folder['resolved_path'] = $this->applyOverrides($folder['folder_path'], $overrides);
        }

        return $folders;
    }

    public function createWatchFolder(string $email, array $data): ?array
    {
        $boardId = !empty($data['board_id']) ? (int)$data['board_id'] : null;
        if (!$this->canManageShared($email, $boardId)) {
            return null;
        }

        $folderPath = trim($data['folder_path'] ?? '');
        if (!$folderPath) return null;

        $clientId = !empty($data['client_id']) ? (int)$data['client_id'] : null;

        if (!$clientId && $boardId) {
            $clientId = $this->resolveClientFromBoard($boardId);
        }

        $stmt = $this->db->prepare("
            INSERT INTO watch_folders (creator_email, name, folder_path, client_id, board_id, card_id, scope, assigned_emails)
            VALUES (?, ?, ?, ?, ?, ?, 'shared', ?)
        ");
        $stmt->execute([
            strtolower($email),
            trim($data['name']),
            $folderPath,
            $clientId,
            $boardId,
            !empty($data['card_id']) ? (int)$data['card_id'] : null,
            !empty($data['assigned_emails']) ? json_encode($data['assigned_emails']) : null,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $this->getWatchFolder($id);
    }

    public function updateWatchFolder(string $email, int $id, array $data): ?array
    {
        $folder = $this->getWatchFolder($id);
        if (!$folder) return null;

        $boardId = !empty($folder['board_id']) ? (int)$folder['board_id'] : null;
        if (!$this->canManageShared($email, $boardId)) return null;

        $sets = [];
        $params = [];
        foreach (['name', 'folder_path', 'client_id', 'board_id', 'card_id'] as $col) {
            if (array_key_exists($col, $data)) {
                $sets[] = "$col = ?";
                $params[] = $data[$col];
            }
        }
        if (array_key_exists('assigned_emails', $data)) {
            $sets[] = "assigned_emails = ?";
            $params[] = !empty($data['assigned_emails']) ? json_encode($data['assigned_emails']) : null;
        }
        if (empty($sets)) return $folder;

        $params[] = $id;
        $this->db->prepare("UPDATE watch_folders SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        return $this->getWatchFolder($id);
    }

    public function deleteWatchFolder(string $email, int $id): bool
    {
        $folder = $this->getWatchFolder($id);
        if (!$folder) return false;

        $boardId = !empty($folder['board_id']) ? (int)$folder['board_id'] : null;
        if (!$this->canManageShared($email, $boardId)) return false;

        $this->db->prepare("DELETE FROM watch_folders WHERE id = ?")->execute([$id]);
        return true;
    }

    private function getWatchFolder(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT wf.*, c.name AS client_name, b.name AS board_name
            FROM watch_folders wf
            LEFT JOIN clients c ON c.id = wf.client_id
            LEFT JOIN webmail_boards b ON b.id = wf.board_id
            WHERE wf.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function resolveClientFromBoard(int $boardId): ?int
    {
        $stmt = $this->db->prepare("SELECT client_id FROM webmail_boards WHERE id = ? AND client_id IS NOT NULL");
        $stmt->execute([$boardId]);
        $directId = $stmt->fetchColumn();
        if ($directId) return (int)$directId;

        $stmt = $this->db->prepare("SELECT client_id FROM client_boards WHERE board_id = ? LIMIT 1");
        $stmt->execute([$boardId]);
        $linkedId = $stmt->fetchColumn();
        return $linkedId ? (int)$linkedId : null;
    }

    // =========================================================================
    // PATH OVERRIDES
    // =========================================================================

    /**
     * Apply a user's path overrides to a canonical folder path.
     */
    private function applyOverrides(string $folderPath, array $overrides): string
    {
        $normalized = str_replace('\\', '/', $folderPath);

        foreach ($overrides as $override) {
            $match = str_replace('\\', '/', $override['match_prefix']);
            if (stripos($normalized, $match) === 0) {
                $replacement = str_replace('\\', '/', $override['replace_prefix']);
                return rtrim($replacement, '/') . '/' . ltrim(substr($normalized, strlen($match)), '/');
            }
        }

        return $folderPath;
    }

    private function getActiveOverrides(string $userEmail): array
    {
        $email = strtolower($userEmail);
        $stmt = $this->db->prepare("
            SELECT * FROM watch_folder_path_overrides
            WHERE user_email = ? AND is_active = 1
            ORDER BY LENGTH(match_prefix) DESC
        ");
        $stmt->execute([$email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getPathOverrides(string $actorEmail, ?string $targetEmail = null): array
    {
        $email = strtolower($targetEmail ?? $actorEmail);

        if ($targetEmail && strtolower($actorEmail) !== $email) {
            if (!$this->isAdmin($actorEmail)) return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM watch_folder_path_overrides
            WHERE user_email = ?
            ORDER BY match_prefix
        ");
        $stmt->execute([$email]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function upsertPathOverride(string $actorEmail, string $targetEmail, array $data): ?array
    {
        $email = strtolower($targetEmail);

        if (strtolower($actorEmail) !== $email && !$this->isAdmin($actorEmail)) {
            return null;
        }

        $matchPrefix = trim($data['match_prefix'] ?? '');
        $replacePrefix = trim($data['replace_prefix'] ?? '');
        $label = trim($data['label'] ?? '');
        $isActive = isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1;

        if (!$matchPrefix || !$replacePrefix) return null;

        if (!empty($data['id'])) {
            $stmt = $this->db->prepare("
                UPDATE watch_folder_path_overrides
                SET match_prefix = ?, replace_prefix = ?, label = ?, is_active = ?
                WHERE id = ? AND user_email = ?
            ");
            $stmt->execute([$matchPrefix, $replacePrefix, $label ?: null, $isActive, (int)$data['id'], $email]);
            return $this->getPathOverride((int)$data['id']);
        }

        $stmt = $this->db->prepare("
            INSERT INTO watch_folder_path_overrides (user_email, match_prefix, replace_prefix, label, is_active)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE replace_prefix = VALUES(replace_prefix), label = VALUES(label), is_active = VALUES(is_active)
        ");
        $stmt->execute([$email, $matchPrefix, $replacePrefix, $label ?: null, $isActive]);
        $id = (int)$this->db->lastInsertId();

        return $this->getPathOverride($id) ?? $this->getPathOverrideByPrefix($email, $matchPrefix);
    }

    public function deletePathOverride(string $actorEmail, string $targetEmail, int $overrideId): bool
    {
        $email = strtolower($targetEmail);
        if (strtolower($actorEmail) !== $email && !$this->isAdmin($actorEmail)) return false;

        $this->db->prepare("DELETE FROM watch_folder_path_overrides WHERE id = ? AND user_email = ?")
            ->execute([$overrideId, $email]);
        return true;
    }

    public function getTeamOverrideStatus(): array
    {
        $stmt = $this->db->query("
            SELECT oc.email, oc.display_name,
                   COUNT(po.id) AS override_count
            FROM organization_colleagues oc
            LEFT JOIN watch_folder_path_overrides po ON po.user_email = oc.email AND po.is_active = 1
            GROUP BY oc.email, oc.display_name
            ORDER BY oc.display_name
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getPathOverride(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM watch_folder_path_overrides WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function getPathOverrideByPrefix(string $email, string $matchPrefix): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM watch_folder_path_overrides WHERE user_email = ? AND match_prefix = ?");
        $stmt->execute([$email, $matchPrefix]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // =========================================================================
    // FILE ACTIVITY
    // =========================================================================

    public function logFileActivity(string $email, array $data): ?array
    {
        $watchFolderId = (int)$data['watch_folder_id'];
        if (!$this->isWatchFolderVisibleTo($watchFolderId, $email)) {
            return null;
        }

        if (!empty($data['card_id'])) {
            if (!$this->isCardAssigneeOrBoardMember((int)$data['card_id'], $email)) {
                return null;
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO watch_folder_activity
                (watch_folder_id, user_email, file_name, file_path, duration_seconds, client_id, board_id, card_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $watchFolderId,
            strtolower($email),
            $data['file_name'],
            $data['file_path'] ?? null,
            (int)($data['duration_seconds'] ?? 0),
            !empty($data['client_id']) ? (int)$data['client_id'] : null,
            !empty($data['board_id']) ? (int)$data['board_id'] : null,
            !empty($data['card_id']) ? (int)$data['card_id'] : null,
        ]);

        $activityId = (int)$this->db->lastInsertId();
        $activity = array_merge($data, ['id' => $activityId, 'user_email' => $email]);

        if (!empty($data['card_id'])) {
            try {
                $workService = new ProjectHubWorkTrackingService($this->config);
                $workService->logWorkSession(
                    (int)$data['card_id'],
                    $email,
                    [
                        'source'          => 'local_watch',
                        'entity_type'     => 'watch_file',
                        'entity_name'     => $data['file_name'],
                        'duration_seconds' => (int)($data['duration_seconds'] ?? 0),
                    ]
                );
            } catch (\Throwable $e) {
                error_log("WatchFolderService work session bridge error: " . $e->getMessage());
            }
        }

        $this->notifyAssignees($activity);

        return $activity;
    }

    private function notifyAssignees(array $activity): void
    {
        $editorEmail = strtolower($activity['user_email']);
        $recipients = [];

        if (!empty($activity['card_id'])) {
            $stmt = $this->db->prepare("SELECT user_email FROM projecthub_card_assignees WHERE card_id = ?");
            $stmt->execute([(int)$activity['card_id']]);
            $recipients = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'user_email');
        } elseif (!empty($activity['board_id'])) {
            $stmt = $this->db->prepare("SELECT user_email FROM webmail_board_members WHERE board_id = ?");
            $stmt->execute([(int)$activity['board_id']]);
            $recipients = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'user_email');
        }

        $recipients = array_filter($recipients, fn($e) => strtolower($e) !== $editorEmail);
        if (empty($recipients)) return;

        $boardName = '';
        $cardTitle = '';
        if (!empty($activity['board_id'])) {
            $s = $this->db->prepare("SELECT name FROM webmail_boards WHERE id = ?");
            $s->execute([(int)$activity['board_id']]);
            $boardName = $s->fetchColumn() ?: '';
        }
        if (!empty($activity['card_id'])) {
            $s = $this->db->prepare("SELECT title FROM webmail_board_cards WHERE id = ?");
            $s->execute([(int)$activity['card_id']]);
            $cardTitle = $s->fetchColumn() ?: '';
        }

        $dur = (int)($activity['duration_seconds'] ?? 0);
        $durStr = $dur >= 3600
            ? round($dur / 3600, 1) . 'h'
            : round($dur / 60) . ' min';

        $title = "File modified" . ($boardName ? " in $boardName" : '');
        $message = "{$editorEmail} edited {$activity['file_name']} for {$durStr}";

        $notifData = json_encode([
            'watch_folder_id' => $activity['watch_folder_id'] ?? null,
            'file_name' => $activity['file_name'],
            'file_path' => $activity['file_path'] ?? null,
            'duration_seconds' => $dur,
            'editor_email' => $editorEmail,
            'client_id' => $activity['client_id'] ?? null,
            'board_id' => $activity['board_id'] ?? null,
            'card_id' => $activity['card_id'] ?? null,
            'board_name' => $boardName,
            'card_title' => $cardTitle,
        ]);

        $insertStmt = $this->db->prepare("
            INSERT INTO notifications (user_email, type, title, message, data)
            VALUES (?, 'watch_file_edited', ?, ?, ?)
        ");

        foreach ($recipients as $recipientEmail) {
            try {
                $insertStmt->execute([$recipientEmail, $title, $message, $notifData]);
                $notifId = (int)$this->db->lastInsertId();
                $this->pushRealtimeNotification($recipientEmail, $notifId, 'watch_file_edited', $title, $message, json_decode($notifData, true));
            } catch (\Throwable $e) {
                error_log("WatchFolderService notify error for {$recipientEmail}: " . $e->getMessage());
            }
        }
    }

    private function pushRealtimeNotification(string $userEmail, int $notifId, string $type, string $title, string $message, array $data = []): void
    {
        try {
            if (!extension_loaded('redis')) return;
            $redis = new \Redis();
            $host = $this->config['redis']['host'] ?? '127.0.0.1';
            $port = $this->config['redis']['port'] ?? 6379;
            $redis->connect($host, $port, 2.0);
            $password = $this->config['redis']['password'] ?? null;
            if ($password) $redis->auth($password);
            $prefix = $this->config['redis']['prefix'] ?? 'webmail:';

            $redis->publish($prefix . 'mailbox:' . $userEmail, json_encode([
                'type' => 'NOTIFICATION_CREATED',
                'payload' => [
                    'id' => $notifId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'is_read' => false,
                    'created_at' => date('c'),
                ],
                'timestamp' => round(microtime(true) * 1000),
            ]));
            $redis->close();
        } catch (\Throwable $e) {
            error_log("WatchFolderService Redis push error: " . $e->getMessage());
        }
    }

    public function getCardFileActivity(string $email, int $cardId, int $limit = 20): array
    {
        if (!$this->isCardAssigneeOrBoardMember($cardId, $email)) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT wfa.*, oc.display_name AS user_display_name
            FROM watch_folder_activity wfa
            LEFT JOIN organization_colleagues oc ON oc.email COLLATE utf8mb4_unicode_ci = wfa.user_email COLLATE utf8mb4_unicode_ci
            WHERE wfa.card_id = ?
            ORDER BY wfa.created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$cardId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getBoardFileActivity(string $email, int $boardId, int $limit = 50): array
    {
        if (!$this->isBoardMember($boardId, $email)) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT wfa.*, oc.display_name AS user_display_name
            FROM watch_folder_activity wfa
            LEFT JOIN organization_colleagues oc ON oc.email COLLATE utf8mb4_unicode_ci = wfa.user_email COLLATE utf8mb4_unicode_ci
            WHERE wfa.board_id = ?
            ORDER BY wfa.created_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$boardId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
