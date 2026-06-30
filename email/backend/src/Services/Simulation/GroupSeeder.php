<?php

declare(strict_types=1);

namespace Webmail\Services\Simulation;

use PDO;

/**
 * Seeds realistic team groups for simulation users so the colleague list isn't ungrouped.
 *
 * Distribution for 30 simulated users (mirrors a typical agency mix):
 *   - CEO                 : 1
 *   - Creative Directors  : 2
 *   - Account Managers    : 5
 *   - Designers           : 12
 *   - Copywriters         : 10
 *
 * Each group is namespaced by run_id so multiple parallel runs (or repeat clicks) never
 * collide on the UNIQUE (organization_domain, name) index. The visible label keeps the
 * clean role name plus a [SIM rXXXX] tag so admins can tell sim groups from real ones.
 */
final class GroupSeeder
{
    /**
     * @var list<array{
     *   key: string,
     *   name: string,
     *   description: string,
     *   color: string,
     *   icon: string,
     *   count: int,
     *   job_title: string
     * }>
     */
    private const PROFILE = [
        [
            'key' => 'ceo',
            'name' => 'CEO',
            'description' => 'Chief executives — strategic oversight.',
            'color' => '#0f172a',
            'icon' => 'workspace_premium',
            'count' => 1,
            'job_title' => 'CEO',
        ],
        [
            'key' => 'creative_directors',
            'name' => 'Creative Directors',
            'description' => 'Lead creative direction across projects.',
            'color' => '#7c3aed',
            'icon' => 'auto_awesome',
            'count' => 2,
            'job_title' => 'Creative Director',
        ],
        [
            'key' => 'account_managers',
            'name' => 'Account Managers',
            'description' => 'Client relationships, scope, and delivery.',
            'color' => '#0ea5e9',
            'icon' => 'badge',
            'count' => 5,
            'job_title' => 'Account Manager',
        ],
        [
            'key' => 'designers',
            'name' => 'Designers',
            'description' => 'Visual design, branding, UI work.',
            'color' => '#ec4899',
            'icon' => 'palette',
            'count' => 12,
            'job_title' => 'Designer',
        ],
        [
            'key' => 'copywriters',
            'name' => 'Copywriters',
            'description' => 'Concept, scripts, and editorial copy.',
            'color' => '#f59e0b',
            'icon' => 'edit_note',
            'count' => 10,
            'job_title' => 'Copywriter',
        ],
    ];

    public function __construct(private array $config, private RunRegistry $registry)
    {
    }

    /**
     * @param list<array{email: string, display_name: string, id: int}> $users
     * @return array{
     *   group_ids: array<string, int>,
     *   memberships: int,
     *   roles: array<int, string>
     * }
     */
    public function seed(string $runId, string $ownerEmail, string $domain, array $users): array
    {
        if (count($users) !== 30) {
            // The role distribution sums to exactly 30; bail loudly if upstream changes that.
            throw new \LogicException('GroupSeeder expects exactly 30 users, got ' . count($users));
        }

        $db = \Webmail\Core\Database::getConnection($this->config);
        $domain = strtolower($domain);

        $groupIds = [];
        $rolesByUserId = [];
        $totalMembers = 0;

        // Slice the user array in declaration order: indices 0..0 = CEO, 1..2 = Creative
        // Directors, 3..7 = Account Managers, 8..19 = Designers, 20..29 = Copywriters.
        // UserSeeder is deterministic for a given run_id so the slice is stable.
        $cursor = 0;

        foreach (self::PROFILE as $profile) {
            $groupId = $this->insertGroup($db, $runId, $ownerEmail, $domain, $profile);
            $groupIds[$profile['key']] = $groupId;
            $this->registry->track($runId, RunRegistry::TYPE_GROUP, $groupId, null);

            $slice = array_slice($users, $cursor, $profile['count']);
            foreach ($slice as $user) {
                $memberId = $this->insertMember($db, $runId, $groupId, (int) $user['id'], $ownerEmail);
                $this->registry->track($runId, RunRegistry::TYPE_GROUP_MEMBER, $memberId, null);
                $totalMembers++;

                $this->setJobTitle($db, (int) $user['id'], $profile['job_title']);
                $rolesByUserId[(int) $user['id']] = $profile['key'];
            }
            $cursor += $profile['count'];
        }

        return [
            'group_ids' => $groupIds,
            'memberships' => $totalMembers,
            'roles' => $rolesByUserId,
        ];
    }

    /**
     * @param array{key: string, name: string, description: string, color: string, icon: string} $profile
     */
    private function insertGroup(PDO $db, string $runId, string $ownerEmail, string $domain, array $profile): int
    {
        // Sim groups carry the run_id in the visible name so the UNIQUE (domain, name)
        // index doesn't conflict with real groups or with other concurrent runs.
        $name = $profile['name'] . ' [SIM ' . $runId . ']';
        $stmt = $db->prepare('
            INSERT INTO colleague_groups (
                organization_domain, name, description, color, icon, sort_order,
                created_by, simulation_run_id
            ) VALUES (?, ?, ?, ?, ?, 0, ?, ?)
        ');
        $stmt->execute([
            $domain,
            $name,
            $profile['description'],
            $profile['color'],
            $profile['icon'],
            $ownerEmail,
            $runId,
        ]);
        return (int) $db->lastInsertId();
    }

    private function insertMember(PDO $db, string $runId, int $groupId, int $colleagueId, string $addedBy): int
    {
        $stmt = $db->prepare('
            INSERT INTO colleague_group_members (group_id, colleague_id, added_by, simulation_run_id)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$groupId, $colleagueId, $addedBy, $runId]);
        return (int) $db->lastInsertId();
    }

    private function setJobTitle(PDO $db, int $colleagueId, string $jobTitle): void
    {
        $stmt = $db->prepare('UPDATE organization_colleagues SET job_title = ? WHERE id = ?');
        $stmt->execute([$jobTitle, $colleagueId]);
    }
}
