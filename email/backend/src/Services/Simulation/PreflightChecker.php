<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

final class PreflightChecker
{
    private PDO $db;

    public function __construct(private array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * @return array{
     *   ok: bool,
     *   missing: list<string>,
     *   requires_admin_promotion: bool,
     *   owner_is_admin: bool,
     *   feature_enabled: bool,
     *   domain_allowed: bool
     * }
     */
    public function check(string $ownerEmail): array
    {
        $missing = [];

        if (!TestSimulationService::ENABLED) {
            $missing[] = 'flag:feature_disabled';
        }

        $at = strrchr(strtolower($ownerEmail), '@');
        $domain = $at ? substr($at, 1) : '';
        $domainAllowed = in_array($domain, TestSimulationService::ALLOWED_DOMAINS, true);
        if (!$domainAllowed) {
            $missing[] = 'domain:not_allowed';
        }

        $tables = [
            'organization_colleagues', 'webmail_boards', 'webmail_board_lists', 'webmail_board_cards',
            'projecthub_spaces', 'projecthub_folders', 'projecthub_folder_boards', 'projecthub_card_assignees',
            'projecthub_work_sessions', 'webmail_card_activity', 'activity_log',
            'colleague_groups', 'colleague_group_members',
            'flowone_test_runs', 'flowone_test_run_entities',
        ];
        foreach ($tables as $t) {
            if (!$this->tableExists($t)) {
                $missing[] = 'table:' . $t;
            }
        }

        $columns = [
            ['webmail_board_cards', 'time_estimate_seconds'],
            ['webmail_board_cards', 'time_budget_alert_sent'],
            ['webmail_board_cards', 'parent_card_id'],
            ['webmail_board_cards', 'simulation_run_id'],
            ['projecthub_card_assignees', 'difficulty_weight'],
            ['projecthub_card_assignees', 'simulation_run_id'],
            ['webmail_boards', 'client_id'],
            ['webmail_boards', 'simulation_run_id'],
            ['organization_colleagues', 'is_simulation'],
            ['organization_colleagues', 'simulation_run_id'],
            ['projecthub_work_sessions', 'simulation_run_id'],
            ['projecthub_spaces', 'simulation_run_id'],
            ['webmail_card_activity', 'simulation_run_id'],
            ['activity_log', 'simulation_run_id'],
            ['projecthub_folder_boards', 'simulation_run_id'],
            ['colleague_groups', 'simulation_run_id'],
            ['colleague_group_members', 'simulation_run_id'],
        ];
        foreach ($columns as [$tbl, $col]) {
            if (!$this->columnExists($tbl, $col)) {
                $missing[] = "column:{$tbl}.{$col}";
            }
        }

        $ownerIsAdmin = false;
        if ($domainAllowed && $ownerEmail !== '') {
            try {
                $colleague = new \Webmail\Addons\Team\Services\ColleagueService($this->config);
                $ownerIsAdmin = $colleague->isAdmin($ownerEmail);
            } catch (\Throwable) {
                $ownerIsAdmin = false;
            }
        }

        return [
            'ok' => $missing === [],
            'missing' => $missing,
            'requires_admin_promotion' => $missing === [] && !$ownerIsAdmin,
            'owner_is_admin' => $ownerIsAdmin,
            'feature_enabled' => TestSimulationService::ENABLED,
            'domain_allowed' => $domainAllowed,
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1
        ');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1
        ');
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }
}
