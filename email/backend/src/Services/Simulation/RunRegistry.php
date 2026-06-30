<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

/**
 * Ledger for test simulation runs: insert tracking + ordered delete + orphan sweep.
 */
final class RunRegistry
{
    public const TYPE_ADMIN_PROMOTION = 'admin_promotion';
    public const TYPE_COLLEAGUE = 'colleague';
    public const TYPE_SPACE = 'space';
    public const TYPE_FOLDER = 'folder';
    public const TYPE_FOLDER_BOARD_LINK = 'folder_board_link';
    public const TYPE_BOARD = 'board';
    public const TYPE_BOARD_MEMBER = 'board_member';
    public const TYPE_LIST = 'list';
    public const TYPE_CARD = 'card';
    public const TYPE_SUBTASK = 'subtask';
    public const TYPE_ASSIGNEE = 'assignee';
    public const TYPE_WORK_SESSION = 'work_session';
    public const TYPE_CARD_ACTIVITY = 'card_activity';
    public const TYPE_ACTIVITY_LOG = 'activity_log';
    public const TYPE_GROUP = 'group';
    public const TYPE_GROUP_MEMBER = 'group_member';

    private PDO $db;

    public function __construct(private array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function track(string $runId, string $entityType, ?int $entityId, ?array $entityPkJson = null): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO flowone_test_run_entities (run_id, entity_type, entity_id, entity_pk_json)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $runId,
            $entityType,
            $entityId,
            $entityPkJson === null ? null : json_encode($entityPkJson, JSON_THROW_ON_ERROR),
        ]);
    }

    public function assertRunOwner(string $runId, string $ownerEmail): void
    {
        $stmt = $this->db->prepare('SELECT owner_email FROM flowone_test_runs WHERE run_id = ? LIMIT 1');
        $stmt->execute([$runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('RUN_NOT_FOUND');
        }
        if (strtolower((string) $row['owner_email']) !== strtolower($ownerEmail)) {
            throw new \RuntimeException('FORBIDDEN_OWNER');
        }
    }

    /**
     * @return array{deleted: array<string, int>, admin_reverted: bool}
     */
    public function deleteRun(string $runId, string $ownerEmail): array
    {
        $this->assertRunOwner($runId, $ownerEmail);
        $deleted = [
            'work_sessions' => 0,
            'activity_log' => 0,
            'card_activity' => 0,
            'assignees' => 0,
            'folder_board_links' => 0,
            'boards' => 0,
            'spaces' => 0,
            'group_members' => 0,
            'groups' => 0,
            'colleagues' => 0,
            'orphan_sweep' => 0,
        ];

        $adminReverted = false;
        $this->db->beginTransaction();
        try {
            $adminReverted = $this->revertAdminPromotion($runId);

            $deleted['work_sessions'] += $this->deleteByType($runId, self::TYPE_WORK_SESSION, 'projecthub_work_sessions');
            $deleted['activity_log'] += $this->deleteByType($runId, self::TYPE_ACTIVITY_LOG, 'activity_log');
            $deleted['card_activity'] += $this->deleteByType($runId, self::TYPE_CARD_ACTIVITY, 'webmail_card_activity');
            $deleted['assignees'] += $this->deleteByType($runId, self::TYPE_ASSIGNEE, 'projecthub_card_assignees');
            $deleted['folder_board_links'] += $this->deleteByType($runId, self::TYPE_FOLDER_BOARD_LINK, 'projecthub_folder_boards');
            $deleted['boards'] += $this->deleteByType($runId, self::TYPE_BOARD, 'webmail_boards');
            $deleted['spaces'] += $this->deleteByType($runId, self::TYPE_SPACE, 'projecthub_spaces');
            // Memberships before groups (FK ON DELETE CASCADE would handle it, but ledger
            // first keeps row counts honest for the test suite). Groups before colleagues
            // because colleague_group_members references colleagues too.
            $deleted['group_members'] += $this->deleteByType($runId, self::TYPE_GROUP_MEMBER, 'colleague_group_members');
            $deleted['groups'] += $this->deleteByType($runId, self::TYPE_GROUP, 'colleague_groups');
            $deleted['colleagues'] += $this->deleteByType($runId, self::TYPE_COLLEAGUE, 'organization_colleagues');

            $deleted['orphan_sweep'] += $this->orphanSweep($runId);

            $stmt = $this->db->prepare('DELETE FROM flowone_test_runs WHERE run_id = ? AND LOWER(owner_email) = LOWER(?)');
            $stmt->execute([$runId, $ownerEmail]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['deleted' => $deleted, 'admin_reverted' => $adminReverted];
    }

    private function revertAdminPromotion(string $runId): bool
    {
        $stmt = $this->db->prepare('
            SELECT entity_pk_json FROM flowone_test_run_entities
            WHERE run_id = ? AND entity_type = ?
        ');
        $stmt->execute([$runId, self::TYPE_ADMIN_PROMOTION]);
        $any = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pk = $row['entity_pk_json'] ? json_decode((string) $row['entity_pk_json'], true) : null;
            if (!is_array($pk) || empty($pk['user_email'])) {
                continue;
            }
            $email = strtolower((string) $pk['user_email']);
            $prev = (int) ($pk['prev_is_admin'] ?? 0);
            $upd = $this->db->prepare('UPDATE organization_colleagues SET is_admin = ? WHERE LOWER(email) = ?');
            $upd->execute([$prev, $email]);
            $any = true;
        }
        return $any;
    }

    private function deleteByType(string $runId, string $type, string $table): int
    {
        $allowed = [
            self::TYPE_WORK_SESSION => 'projecthub_work_sessions',
            self::TYPE_ACTIVITY_LOG => 'activity_log',
            self::TYPE_CARD_ACTIVITY => 'webmail_card_activity',
            self::TYPE_ASSIGNEE => 'projecthub_card_assignees',
            self::TYPE_FOLDER_BOARD_LINK => 'projecthub_folder_boards',
            self::TYPE_BOARD => 'webmail_boards',
            self::TYPE_SPACE => 'projecthub_spaces',
            self::TYPE_GROUP => 'colleague_groups',
            self::TYPE_GROUP_MEMBER => 'colleague_group_members',
            self::TYPE_COLLEAGUE => 'organization_colleagues',
        ];
        if (($allowed[$type] ?? '') !== $table) {
            throw new \InvalidArgumentException('Invalid delete table mapping');
        }
        $stmt = $this->db->prepare("
            SELECT entity_id, entity_pk_json FROM flowone_test_run_entities
            WHERE run_id = ? AND entity_type = ?
        ");
        $stmt->execute([$runId, $type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($rows as $r) {
            $id = $r['entity_id'] !== null ? (int) $r['entity_id'] : null;
            $pk = $r['entity_pk_json'] ? json_decode((string) $r['entity_pk_json'], true) : null;
            if ($type === self::TYPE_BOARD_MEMBER && is_array($pk)) {
                $bid = (int) ($pk['board_id'] ?? 0);
                $ue = strtolower((string) ($pk['user_email'] ?? ''));
                if ($bid > 0 && $ue !== '') {
                    $d = $this->db->prepare('DELETE FROM webmail_board_members WHERE board_id = ? AND user_email = ?');
                    $d->execute([$bid, $ue]);
                    $n += $d->rowCount();
                }
                continue;
            }
            if ($id !== null && $id > 0) {
                $d = $this->db->prepare("DELETE FROM {$table} WHERE id = ?");
                $d->execute([$id]);
                $n += $d->rowCount();
            }
        }
        return $n;
    }

    private function orphanSweep(string $runId): int
    {
        $total = 0;
        $stmt = $this->db->prepare('DELETE FROM projecthub_work_sessions WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM projecthub_card_assignees WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM webmail_card_activity WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM activity_log WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM webmail_board_cards WHERE simulation_run_id = ? AND parent_card_id IS NOT NULL');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM webmail_board_cards WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM projecthub_folder_boards WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM webmail_boards WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM projecthub_spaces WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        // Group memberships first; the FK to colleagues would CASCADE on the next
        // statement anyway but explicit cleanup keeps the orphan_sweep count meaningful.
        $stmt = $this->db->prepare('DELETE FROM colleague_group_members WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM colleague_groups WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        $stmt = $this->db->prepare('DELETE FROM organization_colleagues WHERE simulation_run_id = ?');
        $stmt->execute([$runId]);
        $total += $stmt->rowCount();
        return $total;
    }
}
