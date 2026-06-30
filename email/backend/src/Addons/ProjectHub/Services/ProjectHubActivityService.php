<?php

namespace Webmail\Addons\ProjectHub\Services;

use PDO;

class ProjectHubActivityService
{
    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    private const BOARD_LOG_ACTION_MAP = [
        'assignee_added'     => 'card_updated',
        'assignee_removed'   => 'card_updated',
        'status_changed'     => 'card_updated',
        'card_updated'       => 'card_updated',
        'card_completed'     => 'card_completed',
        'card_reopened'      => 'card_reopened',
        'comment_added'      => 'comment_added',
        'dependency_added'   => 'card_updated',
        'dependency_removed' => 'card_updated',
        'watcher_added'      => 'card_updated',
        'card_promoted'      => 'card_created',
        'work_session'       => 'card_updated',
        'client_share_created' => 'card_updated',
        'client_share_download' => 'card_updated',
    ];

    /**
     * Log a card activity event to the per-card table,
     * and optionally to the board-wide activity_log.
     *
     * @param bool $skipBoardLog Set true when BoardService already wrote to activity_log
     *                           (e.g. proxyUpdateCard, proxyAddComment).
     * @param bool $throwOnFailure When true, DB errors propagate (use inside an outer transaction).
     */
    public function log(int $cardId, string $userEmail, string $action, array $details = [], bool $skipBoardLog = false, bool $throwOnFailure = false): void
    {
        $userEmail = strtolower($userEmail);
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO webmail_card_activity (card_id, user_email, action, details)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$cardId, $userEmail, $action, $detailsJson]);
        } catch (\Throwable $e) {
            error_log("ProjectHubActivityService::log card_activity error: " . $e->getMessage());
            if ($throwOnFailure) {
                throw $e;
            }
        }

        if (!$skipBoardLog) {
            try {
                $this->logToBoardActivityLog($cardId, $userEmail, $action, $details, !$throwOnFailure);
            } catch (\Throwable $e) {
                error_log("ProjectHubActivityService::logToBoardActivityLog error: " . $e->getMessage());
                if ($throwOnFailure) {
                    throw $e;
                }
            }
        }
    }

    private function logToBoardActivityLog(int $cardId, string $userEmail, string $action, array $details, bool $swallowErrors = true): void
    {
        try {
            $cardStmt = $this->db->prepare("
                SELECT c.title, l.board_id
                FROM webmail_board_cards c
                JOIN webmail_board_lists l ON c.list_id = l.id
                WHERE c.id = ?
            ");
            $cardStmt->execute([$cardId]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

            if (!$card || empty($card['board_id'])) {
                return;
            }

            $boardActionType = self::BOARD_LOG_ACTION_MAP[$action] ?? null;
            if (!$boardActionType) {
                return;
            }

            $metadata = $details;
            $metadata['card_title'] = $card['title'];
            $metadata['ph_action'] = $action;

            $this->db->prepare('
                INSERT INTO activity_log (user_email, action_type, entity_type, entity_id, entity_name, board_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $userEmail,
                $boardActionType,
                'card',
                $cardId,
                $card['title'],
                (int)$card['board_id'],
                json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            error_log("ProjectHubActivityService::logToBoardActivityLog error: " . $e->getMessage());
            if (!$swallowErrors) {
                throw $e;
            }
        }
    }

    /**
     * Get unified card timeline merging multiple data sources,
     * sorted by date descending.
     */
    public function getCardTimeline(int $cardId, int $limit = 50, int $offset = 0): array
    {
        $events = [];

        $events = array_merge($events, $this->getActivityRows($cardId));
        $events = array_merge($events, $this->getWorkSessionRows($cardId));
        $events = array_merge($events, $this->getCommentRows($cardId));
        $events = array_merge($events, $this->getAssigneeRows($cardId));
        $events = array_merge($events, $this->getCardCreationRow($cardId));
        $events = array_merge($events, $this->getAttachmentRows($cardId));

        $events = $this->deduplicateEvents($events);

        usort($events, fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));

        return array_slice($events, $offset, $limit);
    }

    public function getCardTimelineCount(int $cardId): int
    {
        $events = $this->getCardTimeline($cardId, 9999, 0);
        return count($events);
    }

    /**
     * Exclude activity rows whose action matches a source table we already merge
     * (work_session, comment_added) to avoid duplicates.
     */
    private function getActivityRows(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, card_id, user_email, action, details, created_at
                FROM webmail_card_activity
                WHERE card_id = ?
                  AND action NOT IN ('work_session', 'comment_added')
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($r) => [
                'id'         => 'act_' . $r['id'],
                'type'       => 'activity',
                'action'     => $r['action'],
                'user_email' => $r['user_email'],
                'details'    => json_decode($r['details'] ?: '{}', true) ?: [],
                'created_at' => $r['created_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getWorkSessionRows(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_email, source, entity_name, duration_seconds, started_at, ended_at
                FROM projecthub_work_sessions
                WHERE card_id = ?
                ORDER BY started_at DESC
                LIMIT 200
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($r) => [
                'id'         => 'ws_' . $r['id'],
                'type'       => 'work_session',
                'action'     => 'work_session',
                'user_email' => $r['user_email'],
                'details'    => [
                    'source'           => $r['source'],
                    'entity_name'      => $r['entity_name'],
                    'duration_seconds' => (int)$r['duration_seconds'],
                    'started_at'       => $r['started_at'],
                    'ended_at'         => $r['ended_at'],
                ],
                'created_at' => $r['started_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getCommentRows(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_email, content, created_at
                FROM webmail_card_comments
                WHERE card_id = ?
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($r) => [
                'id'         => 'cmt_' . $r['id'],
                'type'       => 'comment',
                'action'     => 'comment_added',
                'user_email' => $r['user_email'],
                'details'    => [
                    'content' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($r['content'] ?? ''))), 0, 200),
                ],
                'created_at' => $r['created_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Pull assignee history from projecthub_card_assignees as "assigned" events.
     * This provides historical data even before explicit logging was added.
     */
    private function getAssigneeRows(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, user_email, role, status, created_at
                FROM projecthub_card_assignees
                WHERE card_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($r) => [
                'id'         => 'asg_' . $r['id'],
                'type'       => 'assignee_snapshot',
                'action'     => 'assignee_added',
                'user_email' => $r['user_email'],
                'details'    => [
                    'assignee_email' => $r['user_email'],
                    'role' => $r['role'],
                    'current_status' => $r['status'],
                ],
                'created_at' => $r['created_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Card creation event from the card itself.
     */
    private function getCardCreationRow(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, title, created_by, created_at
                FROM webmail_board_cards
                WHERE id = ?
            ");
            $stmt->execute([$cardId]);
            $card = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$card) return [];

            return [[
                'id'         => 'card_created',
                'type'       => 'card_event',
                'action'     => 'card_created',
                'user_email' => $card['created_by'] ?? '',
                'details'    => [
                    'title' => $card['title'],
                ],
                'created_at' => $card['created_at'],
            ]];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * File attachment events.
     */
    private function getAttachmentRows(int $cardId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, created_by, created_at
                FROM webmail_card_attachments
                WHERE card_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$cardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($r) => [
                'id'         => 'att_' . $r['id'],
                'type'       => 'attachment',
                'action'     => 'file_added',
                'user_email' => $r['created_by'] ?? '',
                'details'    => [
                    'filename' => $r['name'],
                ],
                'created_at' => $r['created_at'],
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Remove duplicate assignee_added events when we have both
     * an explicit activity log row AND an assignee_snapshot row for the same
     * user around the same time.
     */
    private function deduplicateEvents(array $events): array
    {
        $activityAssignKeys = [];
        $activityCommentKeys = [];

        foreach ($events as $ev) {
            if ($ev['type'] === 'activity' && $ev['action'] === 'assignee_added') {
                $email = strtolower($ev['details']['assignee_email'] ?? '');
                if ($email) {
                    $activityAssignKeys[$email] = true;
                }
            }
        }

        return array_values(array_filter($events, function ($ev) use ($activityAssignKeys) {
            if ($ev['type'] === 'assignee_snapshot') {
                $email = strtolower($ev['details']['assignee_email'] ?? $ev['user_email'] ?? '');
                if (isset($activityAssignKeys[$email])) {
                    return false;
                }
            }
            return true;
        }));
    }
}
