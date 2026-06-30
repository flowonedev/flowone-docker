<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class TestSimulationService
{
    public const ENABLED = true;

    /** @var list<string> */
    public const ALLOWED_DOMAINS = ['pixelranger.hu', 'whiterabbit.hu', 'greyskull.hu'];

    public function __construct(private array $config)
    {
    }

    public function assertEnabled(): void
    {
        if (!self::ENABLED) {
            throw new \RuntimeException('FEATURE_DISABLED');
        }
    }

    public function assertAllowedDomain(string $email): void
    {
        $at = strrchr(strtolower($email), '@');
        $domain = $at ? substr($at, 1) : '';
        if (!in_array($domain, self::ALLOWED_DOMAINS, true)) {
            throw new \RuntimeException('DOMAIN_NOT_ALLOWED');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function generateRun(string $ownerEmail, bool $promoteAdminAccepted): array
    {
        $this->assertEnabled();
        $ownerEmail = strtolower(trim($ownerEmail));
        $this->assertAllowedDomain($ownerEmail);
        $domain = substr(strrchr($ownerEmail, '@') ?: '', 1);

        $checker = new PreflightChecker($this->config);
        $pf = $checker->check($ownerEmail);
        if (!$pf['ok']) {
            throw new \RuntimeException('PREFLIGHT:' . json_encode($pf['missing'], JSON_THROW_ON_ERROR));
        }
        if ($pf['requires_admin_promotion'] && !$promoteAdminAccepted) {
            throw new \RuntimeException('REQUIRES_ADMIN_PROMOTION');
        }

        $db = \Webmail\Core\Database::getConnection($this->config);
        $lockName = 'test_simulation_' . md5($ownerEmail);
        // Plan: 30s wait. CLI lock test holds the lock externally so the wait still resolves quickly
        // for the test (which expects LOCK_FAILED only after the timeout) — but production callers get
        // the full grace period to handle double-clicks / browser retries.
        $lockStmt = $db->query('SELECT GET_LOCK(' . $db->quote($lockName) . ', 30) AS lk');
        $lk = $lockStmt ? (int) $lockStmt->fetchColumn() : 0;
        if ($lk !== 1) {
            throw new \RuntimeException('LOCK_FAILED');
        }

        $registry = new RunRegistry($this->config);
        $runId = 'r' . bin2hex(random_bytes(4));

        try {
            $db->beginTransaction();

            $label = '[SIM ' . $runId . ']';
            $db->prepare('
                INSERT INTO flowone_test_runs (run_id, owner_email, owner_domain, label, summary_json)
                VALUES (?, ?, ?, ?, NULL)
            ')->execute([$runId, $ownerEmail, $domain, $label]);

            $this->ensureOwnerColleagueRow($db, $ownerEmail, $domain);

            if ($pf['requires_admin_promotion'] && $promoteAdminAccepted) {
                $prevStmt = $db->prepare('SELECT is_admin FROM organization_colleagues WHERE LOWER(email) = ? LIMIT 1');
                $prevStmt->execute([$ownerEmail]);
                $prev = (int) ($prevStmt->fetchColumn() ?: 0);
                $db->prepare('UPDATE organization_colleagues SET is_admin = 1 WHERE LOWER(email) = ?')->execute([$ownerEmail]);
                $registry->track($runId, RunRegistry::TYPE_ADMIN_PROMOTION, null, [
                    'user_email' => $ownerEmail,
                    'prev_is_admin' => $prev,
                ]);
            }

            $userSeeder = new UserSeeder($this->config, $registry);
            $users = $userSeeder->seed($runId, $ownerEmail, $domain);
            $simEmails = array_column($users, 'email');

            // Group sim users into realistic teams (CEO / Creative Directors / Account
            // Managers / Designers / Copywriters) so the colleague list isn't ungrouped.
            $groupSeeder = new GroupSeeder($this->config, $registry);
            $groupResult = $groupSeeder->seed($runId, $ownerEmail, $domain, $users);

            $boardSeeder = new BoardSeeder($this->config, $registry);
            $boards = $boardSeeder->seed($runId, $ownerEmail, $simEmails);

            $hubSeeder = new HubSeeder($this->config, $registry);
            $hubSeeder->seed($runId, $ownerEmail, $boards['board_ids']);

            $subtaskSeeder = new SubtaskSeeder($this->config, $registry);
            $subs = $subtaskSeeder->seed($runId, $ownerEmail, $boards['cards'], $simEmails);

            $sessionSeeder = new SessionSeeder($this->config, $registry);
            // Preserve seed_sessions so SessionSeeder can skip "open" parent cards (plan §2).
            // All subtasks seed sessions unconditionally.
            $allCardRows = array_merge(
                array_map(
                    static fn (array $c): array => [
                        'card_id' => $c['card_id'],
                        'seed_sessions' => $c['seed_sessions'] ?? true,
                    ],
                    $boards['cards']
                ),
                array_map(
                    static fn (array $c): array => [
                        'card_id' => $c['card_id'],
                        'seed_sessions' => true,
                    ],
                    $subs
                )
            );
            $sessionSeeder->seed($runId, $allCardRows, $simEmails);

            $actCards = array_merge(
                array_map(static fn (array $c): array => [
                    'card_id' => $c['card_id'],
                    'board_id' => $c['board_id'],
                ], $boards['cards']),
                array_map(static fn (array $c): array => [
                    'card_id' => $c['card_id'],
                    'board_id' => $c['board_id'],
                ], $subs)
            );
            (new ActivitySeeder($this->config, $registry))->seed($runId, $ownerEmail, $actCards);

            $summary = [
                'run_id' => $runId,
                'colleagues' => 30,
                'groups' => count($groupResult['group_ids']),
                'group_memberships' => $groupResult['memberships'],
                'boards' => 5,
                'parent_cards' => 40,
                'subtask_cards' => count($subs),
                'owner_email' => $ownerEmail,
            ];
            $db->prepare('UPDATE flowone_test_runs SET summary_json = ? WHERE run_id = ? AND LOWER(owner_email) = LOWER(?)')
                ->execute([json_encode($summary, JSON_THROW_ON_ERROR), $runId, $ownerEmail]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote($lockName) . ')');
        }

        return $summary;
    }

    private function ensureOwnerColleagueRow(PDO $db, string $ownerEmail, string $domain): void
    {
        $st = $db->prepare('SELECT id FROM organization_colleagues WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([$ownerEmail]);
        if ($st->fetchColumn()) {
            return;
        }
        $local = strstr($ownerEmail, '@', true) ?: $ownerEmail;
        $name = ucwords(str_replace(['.', '_'], ' ', $local));
        $db->prepare('
            INSERT INTO organization_colleagues
              (organization_domain, email, display_name, is_admin, status, synced_from_mailserver, is_simulation)
            VALUES (?, ?, ?, 1, \'active\', 0, 0)
        ')->execute([$domain, $ownerEmail, $name]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRuns(string $ownerEmail): array
    {
        $this->assertEnabled();
        $ownerEmail = strtolower(trim($ownerEmail));
        $this->assertAllowedDomain($ownerEmail);
        $db = \Webmail\Core\Database::getConnection($this->config);
        $stmt = $db->prepare('
            SELECT run_id, label, created_at, summary_json
            FROM flowone_test_runs
            WHERE LOWER(owner_email) = LOWER(?)
            ORDER BY id DESC
        ');
        $stmt->execute([$ownerEmail]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            if (!empty($r['summary_json'])) {
                $r['summary'] = json_decode((string) $r['summary_json'], true);
            }
            unset($r['summary_json']);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRun(string $ownerEmail, string $runId): array
    {
        $this->assertEnabled();
        $ownerEmail = strtolower(trim($ownerEmail));
        $this->assertAllowedDomain($ownerEmail);
        $registry = new RunRegistry($this->config);
        return $registry->deleteRun($runId, $ownerEmail);
    }

    /**
     * @return array{runs_deleted: int, details: list<array<string, mixed>>}
     */
    public function deleteAllRuns(string $ownerEmail): array
    {
        $this->assertEnabled();
        $ownerEmail = strtolower(trim($ownerEmail));
        $this->assertAllowedDomain($ownerEmail);
        $runs = $this->listRuns($ownerEmail);
        $runIds = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['run_id'] ?? ''),
            $runs
        )));
        $details = [];
        foreach ($runIds as $rid) {
            if ($rid !== '') {
                $details[] = $this->deleteRun($ownerEmail, $rid);
            }
        }
        return ['runs_deleted' => count($details), 'details' => $details];
    }
}
