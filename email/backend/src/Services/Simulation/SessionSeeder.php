<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class SessionSeeder
{
    private const SOURCES = ['manual', 'timer', 'drive_edit', 'board_view', 'card_view', 'website_work', 'calendar_event'];

    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @param list<array{card_id: int, seed_sessions?: bool, card_index?: int}> $allCards
     *        card_id required; seed_sessions=false skips session generation for that card
     *        (used by "open" bucket parent cards per plan §2).
     */
    public function seed(string $runId, array $allCards, array $simEmails): void
    {
        $db = \Webmail\Core\Database::getConnection($this->config);
        $profiles = ScenarioPlanner::userProfiles($runId);
        foreach ($allCards as $c) {
            $cid = (int) $c['card_id'];
            // Plan §2: "20% not started (no sessions yet, all assignees still 'assigned')"
            if (array_key_exists('seed_sessions', $c) && $c['seed_sessions'] === false) {
                continue;
            }
            $q = $db->prepare('SELECT user_email, status FROM projecthub_card_assignees WHERE card_id = ?');
            $q->execute([$cid]);
            $assignees = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($assignees as $as) {
                $email = strtolower((string) $as['user_email']);
                $status = (string) $as['status'];
                if ($status === 'assigned' && (crc32($runId . $cid . $email) % 3) !== 0) {
                    continue;
                }
                $userIdx = $this->simIndex($email, $simEmails);
                $mult = 1.0;
                if ($userIdx !== null) {
                    $prof = $profiles[$userIdx] ?? 'balanced';
                    $mult = $prof === 'overloaded' ? 2.5 : ($prof === 'light' ? 0.35 : 1.0);
                }
                $days = 14;
                for ($d = 0; $d < $days; $d++) {
                    if ((crc32($runId . $cid . $email . $d) % 4) === 0) {
                        continue;
                    }
                    $base = 600 + (crc32($runId . $cid . $email . 'x' . $d) % 7200);
                    $sec = (int) max(120, $base * $mult);
                    $src = self::SOURCES[crc32($runId . $d . $email) % count(self::SOURCES)];
                    $day = gmdate('Y-m-d H:i:s', strtotime('-' . $d . ' days 10:00:00'));
                    $end = gmdate('Y-m-d H:i:s', strtotime($day) + $sec);
                    $db->prepare('
                        INSERT INTO projecthub_work_sessions
                          (card_id, user_email, source, started_at, ended_at, duration_seconds, simulation_run_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ')->execute([$cid, $email, $src, $day, $end, $sec, $runId]);
                    $sid = (int) $db->lastInsertId();
                    $this->registry->track($runId, RunRegistry::TYPE_WORK_SESSION, $sid, null);
                }
            }
        }

        $this->recalcAssigneeTotals($db, $runId);
        $this->inflateOverBudget($db, $runId);
        $this->recalcAssigneeTotals($db, $runId);
    }

    private function simIndex(string $email, array $simEmails): ?int
    {
        foreach ($simEmails as $i => $e) {
            if (strtolower($e) === $email) {
                return $i;
            }
        }
        return null;
    }

    private function recalcAssigneeTotals(PDO $db, string $runId): void
    {
        $sql = '
            UPDATE projecthub_card_assignees ca
            INNER JOIN (
              SELECT card_id, user_email, SUM(duration_seconds) AS t
              FROM projecthub_work_sessions
              WHERE simulation_run_id = ?
              GROUP BY card_id, user_email
            ) x ON x.card_id = ca.card_id AND LOWER(x.user_email) = LOWER(ca.user_email)
            SET ca.time_spent_seconds = LEAST(99999999, x.t)
            WHERE ca.simulation_run_id = ?
        ';
        $stmt = $db->prepare($sql);
        $stmt->execute([$runId, $runId]);
    }

    private function inflateOverBudget(PDO $db, string $runId): void
    {
        $stmt = $db->prepare('
            SELECT id AS card_id, time_estimate_seconds AS est
            FROM webmail_board_cards
            WHERE simulation_run_id = ? AND parent_card_id IS NULL
              AND time_estimate_seconds IS NOT NULL AND time_estimate_seconds > 0
        ');
        $stmt->execute([$runId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) $row['card_id'];
            $est = (int) $row['est'];
            $pseudo = abs(crc32((string) $cid . $runId)) % 40;
            if (ScenarioPlanner::cardBudgetOutcome($pseudo) !== 'over') {
                continue;
            }
            $sumStmt = $db->prepare('SELECT COALESCE(SUM(duration_seconds),0) FROM projecthub_work_sessions WHERE card_id = ?');
            $sumStmt->execute([$cid]);
            $cur = (int) $sumStmt->fetchColumn();
            if ($cur >= (int) ($est * 1.1)) {
                continue;
            }
            $need = (int) ($est * 1.25) - $cur;
            if ($need <= 0) {
                continue;
            }
            $q2 = $db->prepare('SELECT user_email FROM projecthub_card_assignees WHERE card_id = ? LIMIT 1');
            $q2->execute([$cid]);
            $em = strtolower((string) $q2->fetchColumn());
            if ($em === '') {
                continue;
            }
            $day = gmdate('Y-m-d H:i:s', strtotime('-2 days 14:00:00'));
            $end = gmdate('Y-m-d H:i:s', strtotime($day) + $need);
            $db->prepare('
                INSERT INTO projecthub_work_sessions
                  (card_id, user_email, source, started_at, ended_at, duration_seconds, simulation_run_id)
                VALUES (?, ?, \'manual\', ?, ?, ?, ?)
            ')->execute([$cid, $em, $day, $end, $need, $runId]);
            $this->registry->track($runId, RunRegistry::TYPE_WORK_SESSION, (int) $db->lastInsertId(), null);
        }
    }
}
