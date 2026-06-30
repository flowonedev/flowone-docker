<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmAutomationService
 * 
 * Rule-based automation engine: CRUD rules, evaluate triggers against live data,
 * execute actions (create reminders, send emails, move deals, etc.).
 */
class CrmAutomationService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTables();
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_automation_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                trigger_type ENUM(
                    'deal_stage_idle','deal_stage_changed','client_health_low',
                    'invoice_overdue','no_contact_days','deal_won','deal_lost',
                    'task_changed','board_closed','moodboard_ready',
                    'time_spent_reached','colleague_sick_status',
                    'drive_folder_permission_changed',
                    'email_opened','email_link_clicked'
                ) NOT NULL,
                trigger_config JSON NOT NULL,
                action_type ENUM(
                    'create_reminder','send_email','create_invoice_draft',
                    'move_deal_stage','notify_user','start_sequence',
                    'assign_task','send_chat_message','reassign_deals'
                ) NOT NULL,
                action_config JSON NOT NULL,
                last_run_at DATETIME DEFAULT NULL,
                run_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_email),
                INDEX idx_active (is_active, trigger_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_automation_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id VARCHAR(255) NOT NULL,
                action_taken VARCHAR(100) NOT NULL,
                result_detail TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rule (rule_id),
                INDEX idx_target (target_type, target_id),
                INDEX idx_user_date (user_email, created_at),
                FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Sharing tables
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_automation_rule_shares (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                shared_with_email VARCHAR(255) NOT NULL,
                permission ENUM('viewer','editor') DEFAULT 'viewer',
                shared_by_email VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rule_share (rule_id, shared_with_email),
                INDEX idx_shared_with (shared_with_email),
                INDEX idx_rule (rule_id),
                FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_automation_rule_group_shares (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_id INT UNSIGNED NOT NULL,
                group_id INT UNSIGNED NOT NULL,
                permission ENUM('viewer','editor') DEFAULT 'viewer',
                shared_by_email VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rule_group_share (rule_id, group_id),
                INDEX idx_group (group_id),
                INDEX idx_rule (rule_id),
                FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Convert existing tables if they were created with wrong collation (one-time fix)
        $this->fixCollation('crm_automation_rules');
        $this->fixCollation('crm_automation_log');
        $this->fixCollation('crm_automation_rule_shares');
        $this->fixCollation('crm_automation_rule_group_shares');

        // Add visibility column if it doesn't exist
        try {
            $this->db->exec("ALTER TABLE crm_automation_rules ADD COLUMN visibility ENUM('private','shared') DEFAULT 'private' AFTER is_active");
            $this->db->exec("ALTER TABLE crm_automation_rules ADD INDEX idx_visibility (visibility)");
        } catch (\PDOException $e) { /* column already exists */ }

        // Self-heal: expand ENUMs on existing tables if they were created with older definitions
        $this->expandEnumsIfNeeded();
    }

    /**
     * Convert a table to utf8mb4_unicode_ci if it's using a different collation
     */
    private function fixCollation(string $table): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $collation = $stmt->fetchColumn();
            if ($collation && $collation !== 'utf8mb4_unicode_ci') {
                $this->db->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (\PDOException $e) { /* table might not exist */ }
    }

    /**
     * Expand ENUM columns on existing tables to include new trigger/action types.
     * Safe to call repeatedly (no-op if already expanded).
     */
    private function expandEnumsIfNeeded(): void
    {
        try {
            // Check if trigger_type already has the newest values
            $stmt = $this->db->query("SHOW COLUMNS FROM crm_automation_rules LIKE 'trigger_type'");
            $row = $stmt->fetch();
            if ($row && strpos($row['Type'], 'campaign_engagement_threshold') === false) {
                $this->db->exec("
                    ALTER TABLE crm_automation_rules MODIFY trigger_type ENUM(
                        'deal_stage_idle','deal_stage_changed','client_health_low',
                        'invoice_overdue','no_contact_days','deal_won','deal_lost',
                        'task_changed','board_closed','moodboard_ready',
                        'time_spent_reached','colleague_sick_status',
                        'drive_folder_permission_changed',
                        'email_opened','email_link_clicked',
                        'campaign_engagement_threshold'
                    ) NOT NULL
                ");
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM crm_automation_rules LIKE 'action_type'");
            $row = $stmt->fetch();
            if ($row && strpos($row['Type'], 'assign_task') === false) {
                $this->db->exec("
                    ALTER TABLE crm_automation_rules MODIFY action_type ENUM(
                        'create_reminder','send_email','create_invoice_draft',
                        'move_deal_stage','notify_user','start_sequence',
                        'assign_task','send_chat_message','reassign_deals'
                    ) NOT NULL
                ");
            }

            // Expand target_type in log table from ENUM to VARCHAR if still ENUM
            $stmt = $this->db->query("SHOW COLUMNS FROM crm_automation_log LIKE 'target_type'");
            $row = $stmt->fetch();
            if ($row && strpos($row['Type'], 'enum') !== false) {
                $this->db->exec("ALTER TABLE crm_automation_log MODIFY target_type VARCHAR(50) NOT NULL");
            }

            // Widen target_id from INT to VARCHAR for string-based composite keys
            $stmt = $this->db->query("SHOW COLUMNS FROM crm_automation_log LIKE 'target_id'");
            $row = $stmt->fetch();
            if ($row && strpos($row['Type'], 'int') !== false) {
                $this->db->exec("ALTER TABLE crm_automation_log MODIFY target_id VARCHAR(255) NOT NULL");
            }
        } catch (\PDOException $e) {
            error_log("CrmAutomation: ENUM expansion error: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Rule CRUD
    // =========================================================================

    public function listRules(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);

        // Own rules
        $stmt = $this->db->prepare("
            SELECT r.*, 
                'owner' as access_role,
                (SELECT COUNT(*) FROM crm_automation_log l WHERE l.rule_id = r.id) as execution_count
            FROM crm_automation_rules r
            WHERE r.user_email = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userEmail]);
        $ownRules = $stmt->fetchAll();

        // Rules shared directly with me
        $stmt = $this->db->prepare("
            SELECT r.*,
                s.permission as access_role,
                s.shared_by_email as shared_by,
                (SELECT COUNT(*) FROM crm_automation_log l WHERE l.rule_id = r.id) as execution_count
            FROM crm_automation_rules r
            JOIN crm_automation_rule_shares s ON s.rule_id = r.id
            WHERE LOWER(s.shared_with_email) = ? AND r.visibility = 'shared'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userEmail]);
        $directShared = $stmt->fetchAll();

        // Rules shared with my groups
        $stmt = $this->db->prepare("
            SELECT r.*,
                gs.permission as access_role,
                gs.shared_by_email as shared_by,
                (SELECT COUNT(*) FROM crm_automation_log l WHERE l.rule_id = r.id) as execution_count
            FROM crm_automation_rules r
            JOIN crm_automation_rule_group_shares gs ON gs.rule_id = r.id
            JOIN colleague_group_members cgm ON cgm.group_id = gs.group_id
            JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
            WHERE r.visibility = 'shared'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userEmail]);
        $groupShared = $stmt->fetchAll();

        // Merge and deduplicate (own rules take priority, then direct > group)
        $seen = [];
        $allRules = [];

        foreach (array_merge($ownRules, $directShared, $groupShared) as $rule) {
            if (isset($seen[$rule['id']])) continue;
            $seen[$rule['id']] = true;

            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];
            $rule['is_own'] = (strtolower($rule['user_email']) === $userEmail);
            $allRules[] = $rule;
        }

        return $allRules;
    }

    public function getRule(int $id, string $userEmail): ?array
    {
        $userEmail = strtolower($userEmail);

        // Try own rule first
        $stmt = $this->db->prepare("SELECT * FROM crm_automation_rules WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $userEmail]);
        $rule = $stmt->fetch();

        if (!$rule) {
            // Try shared rule (direct or group)
            $rule = $this->getSharedRule($id, $userEmail);
        }

        if ($rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];
            $rule['is_own'] = (strtolower($rule['user_email']) === $userEmail);
        }

        return $rule ?: null;
    }

    /**
     * Get a rule shared with this user (direct or via group)
     */
    private function getSharedRule(int $ruleId, string $userEmail): ?array
    {
        $userEmail = strtolower($userEmail);

        // Direct share
        $stmt = $this->db->prepare("
            SELECT r.*, s.permission as access_role, s.shared_by_email as shared_by
            FROM crm_automation_rules r
            JOIN crm_automation_rule_shares s ON s.rule_id = r.id
            WHERE r.id = ? AND LOWER(s.shared_with_email) = ? AND r.visibility = 'shared'
        ");
        $stmt->execute([$ruleId, $userEmail]);
        $rule = $stmt->fetch();
        if ($rule) return $rule;

        // Group share
        $stmt = $this->db->prepare("
            SELECT r.*, gs.permission as access_role, gs.shared_by_email as shared_by
            FROM crm_automation_rules r
            JOIN crm_automation_rule_group_shares gs ON gs.rule_id = r.id
            JOIN colleague_group_members cgm ON cgm.group_id = gs.group_id
            JOIN organization_colleagues oc ON oc.id = cgm.colleague_id AND LOWER(oc.email) = ?
            WHERE r.id = ? AND r.visibility = 'shared'
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $ruleId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Check if user has access to a rule (owner or shared)
     */
    public function hasAccess(int $ruleId, string $userEmail, string $requiredPermission = 'viewer'): bool
    {
        $rule = $this->getRule($ruleId, $userEmail);
        if (!$rule) return false;
        if ($rule['is_own']) return true;

        $role = $rule['access_role'] ?? 'viewer';
        if ($requiredPermission === 'viewer') return true;
        if ($requiredPermission === 'editor') return $role === 'editor';
        return false;
    }

    public function createRule(string $userEmail, array $data): array
    {
        $visibility = $data['visibility'] ?? 'private';
        $stmt = $this->db->prepare("
            INSERT INTO crm_automation_rules 
            (user_email, name, description, is_active, visibility, trigger_type, trigger_config, action_type, action_config)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userEmail,
            $data['name'],
            $data['description'] ?? null,
            $data['is_active'] ?? 1,
            $visibility,
            $data['trigger_type'],
            json_encode($data['trigger_config'] ?? []),
            $data['action_type'],
            json_encode($data['action_config'] ?? []),
        ]);

        $ruleId = (int)$this->db->lastInsertId();

        // Handle sharing data
        if ($visibility === 'shared') {
            $this->syncRuleShares($ruleId, $userEmail, $data['shared_with'] ?? [], $data['shared_groups'] ?? []);
        }

        return $this->getRule($ruleId, $userEmail);
    }

    public function updateRule(int $id, string $userEmail, array $data): ?array
    {
        // Check if user owns the rule or has editor access
        $rule = $this->getRule($id, $userEmail);
        if (!$rule) return null;

        $isOwner = $rule['is_own'] ?? (strtolower($rule['user_email']) === strtolower($userEmail));
        if (!$isOwner && ($rule['access_role'] ?? 'viewer') !== 'editor') {
            return null; // No edit permission
        }

        $fields = [];
        $params = [];

        foreach (['name', 'description', 'is_active', 'trigger_type', 'action_type', 'visibility'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (isset($data['trigger_config'])) {
            $fields[] = "trigger_config = ?";
            $params[] = json_encode($data['trigger_config']);
        }
        if (isset($data['action_config'])) {
            $fields[] = "action_config = ?";
            $params[] = json_encode($data['action_config']);
        }

        if (empty($fields)) return $rule;

        $params[] = $id;
        // Only the owner can update the rule itself; editors can too if they have access
        $stmt = $this->db->prepare("UPDATE crm_automation_rules SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        // Sync sharing (only owner can manage shares)
        if ($isOwner && array_key_exists('visibility', $data)) {
            if ($data['visibility'] === 'shared') {
                $this->syncRuleShares($id, $userEmail, $data['shared_with'] ?? [], $data['shared_groups'] ?? []);
            } else {
                // Reverted to private — remove all shares
                $this->db->prepare("DELETE FROM crm_automation_rule_shares WHERE rule_id = ?")->execute([$id]);
                $this->db->prepare("DELETE FROM crm_automation_rule_group_shares WHERE rule_id = ?")->execute([$id]);
            }
        }

        return $this->getRule($id, $userEmail);
    }

    public function deleteRule(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_automation_rules WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    public function toggleRule(int $id, string $userEmail): ?array
    {
        $rule = $this->getRule($id, $userEmail);
        if (!$rule) return null;

        $newActive = $rule['is_active'] ? 0 : 1;
        $stmt = $this->db->prepare("UPDATE crm_automation_rules SET is_active = ? WHERE id = ? AND user_email = ?");
        $stmt->execute([$newActive, $id, $userEmail]);

        return $this->getRule($id, $userEmail);
    }

    // =========================================================================
    // Rule Sharing
    // =========================================================================

    /**
     * Sync sharing for a rule: set individual + group shares
     * @param array $sharedWith [{ email: string, permission: 'viewer'|'editor' }, ...]
     * @param array $sharedGroups [{ group_id: int, permission: 'viewer'|'editor' }, ...]
     */
    private function syncRuleShares(int $ruleId, string $ownerEmail, array $sharedWith, array $sharedGroups): void
    {
        // Individual shares: replace all
        $this->db->prepare("DELETE FROM crm_automation_rule_shares WHERE rule_id = ?")->execute([$ruleId]);

        if (!empty($sharedWith)) {
            $stmt = $this->db->prepare("
                INSERT INTO crm_automation_rule_shares (rule_id, shared_with_email, permission, shared_by_email)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($sharedWith as $share) {
                $email = strtolower($share['email'] ?? '');
                if (!$email || $email === strtolower($ownerEmail)) continue;
                $permission = in_array($share['permission'] ?? '', ['viewer', 'editor']) ? $share['permission'] : 'viewer';
                $stmt->execute([$ruleId, $email, $permission, $ownerEmail]);
            }
        }

        // Group shares: replace all
        $this->db->prepare("DELETE FROM crm_automation_rule_group_shares WHERE rule_id = ?")->execute([$ruleId]);

        if (!empty($sharedGroups)) {
            $stmt = $this->db->prepare("
                INSERT INTO crm_automation_rule_group_shares (rule_id, group_id, permission, shared_by_email)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($sharedGroups as $gs) {
                $groupId = (int)($gs['group_id'] ?? 0);
                if (!$groupId) continue;
                $permission = in_array($gs['permission'] ?? '', ['viewer', 'editor']) ? $gs['permission'] : 'viewer';
                $stmt->execute([$ruleId, $groupId, $permission, $ownerEmail]);
            }
        }
    }

    /**
     * Get sharing details for a rule (individual + group shares)
     */
    public function getRuleShares(int $ruleId, string $userEmail): ?array
    {
        $rule = $this->getRule($ruleId, $userEmail);
        if (!$rule) return null;

        $isOwner = $rule['is_own'] ?? (strtolower($rule['user_email']) === strtolower($userEmail));

        // Individual shares
        $stmt = $this->db->prepare("
            SELECT s.*, oc.display_name as colleague_name, oc.avatar_path as avatar_url
            FROM crm_automation_rule_shares s
            LEFT JOIN organization_colleagues oc ON LOWER(oc.email) = LOWER(s.shared_with_email)
            WHERE s.rule_id = ?
            ORDER BY s.created_at ASC
        ");
        $stmt->execute([$ruleId]);
        $shares = $stmt->fetchAll();

        // Group shares
        $stmt = $this->db->prepare("
            SELECT gs.*, cg.name as group_name, cg.color, cg.icon
            FROM crm_automation_rule_group_shares gs
            LEFT JOIN colleague_groups cg ON cg.id = gs.group_id
            WHERE gs.rule_id = ?
            ORDER BY gs.created_at ASC
        ");
        $stmt->execute([$ruleId]);
        $groupShares = $stmt->fetchAll();

        return [
            'visibility' => $rule['visibility'] ?? 'private',
            'is_owner' => $isOwner,
            'shares' => $shares,
            'group_shares' => $groupShares,
        ];
    }

    /**
     * Duplicate a shared rule for a user (copy to own rules)
     */
    public function duplicateRule(int $ruleId, string $userEmail): ?array
    {
        $rule = $this->getRule($ruleId, $userEmail);
        if (!$rule) return null;

        $newRule = $this->createRule($userEmail, [
            'name' => $rule['name'] . ' (copy)',
            'description' => $rule['description'],
            'is_active' => 0, // Start inactive
            'visibility' => 'private',
            'trigger_type' => $rule['trigger_type'],
            'trigger_config' => $rule['trigger_config'],
            'action_type' => $rule['action_type'],
            'action_config' => $rule['action_config'],
        ]);

        return $newRule;
    }

    /**
     * Test-fire a rule with a synthetic target (skips debounce)
     */
    public function testFireRule(int $ruleId, string $userEmail): void
    {
        $rule = $this->getRule($ruleId, $userEmail);
        if (!$rule) {
            throw new \RuntimeException('Rule not found');
        }

        error_log("CrmAutomation::testFireRule - rule #{$ruleId}, trigger={$rule['trigger_type']}, action={$rule['action_type']}, action_config=" . json_encode($rule['action_config']));

        // Build a synthetic target based on trigger type
        $target = match ($rule['trigger_type']) {
            'moodboard_ready' => ['type' => 'moodboard', 'id' => 0, 'data' => ['name' => 'Test Moodboard', 'title' => 'Test Moodboard']],
            'board_closed' => ['type' => 'board', 'id' => 0, 'data' => ['name' => 'Test Board', 'title' => 'Test Board']],
            'task_changed' => ['type' => 'task', 'id' => 0, 'data' => ['name' => 'Test Task', 'title' => 'Test Task', 'status' => 'pending']],
            'colleague_sick_status' => ['type' => 'colleague', 'id' => 0, 'data' => ['name' => 'Test Colleague', 'email' => $userEmail, 'status' => 'sick']],
            'drive_folder_permission_changed' => ['type' => 'drive_folder', 'id' => 0, 'data' => ['name' => 'Test Folder', 'title' => 'Test Folder']],
            default => ['type' => 'deal', 'id' => 0, 'data' => ['title' => 'Test Deal', 'name' => 'Test Deal', 'stage' => 'lead', 'value' => 0]],
        };

        // Execute without debounce check
        $this->executeAction($rule, $target, $userEmail);
    }

    // =========================================================================
    // Execution Log
    // =========================================================================

    public function getLog(string $userEmail, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT l.*, r.name as rule_name, r.trigger_type, r.action_type
            FROM crm_automation_log l
            LEFT JOIN crm_automation_rules r ON r.id = l.rule_id
            WHERE l.user_email = ?
            ORDER BY l.created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([$userEmail]);
        return $stmt->fetchAll();
    }

    private function logExecution(int $ruleId, string $userEmail, string $targetType, string|int $targetId, string $action, ?string $detail = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO crm_automation_log (rule_id, user_email, target_type, target_id, action_taken, result_detail)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$ruleId, $userEmail, $targetType, $targetId, $action, $detail]);

        // Update rule tracking
        $stmt = $this->db->prepare("UPDATE crm_automation_rules SET last_run_at = NOW(), run_count = run_count + 1 WHERE id = ?");
        $stmt->execute([$ruleId]);
    }

    // =========================================================================
    // Trigger Evaluation (called by cron worker)
    // =========================================================================

    /**
     * Evaluate all active rules for a specific user and execute matching actions.
     * Returns count of actions taken.
     */
    public function evaluateRulesForUser(string $userEmail): int
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_automation_rules WHERE user_email = ? AND is_active = 1");
        $stmt->execute([$userEmail]);
        $rules = $stmt->fetchAll();

        $actionsExecuted = 0;

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            $targets = $this->findTargets($rule, $userEmail);

            foreach ($targets as $target) {
                // Check if we already acted on this target recently (debounce: once per 24h per target)
                if ($this->wasRecentlyActedOn($rule['id'], $target['type'], $target['id'])) {
                    continue;
                }

                $this->executeAction($rule, $target, $userEmail);
                $actionsExecuted++;
            }
        }

        return $actionsExecuted;
    }

    /**
     * Evaluate all active rules across ALL users (for cron).
     */
    public function evaluateAllRules(): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT user_email FROM crm_automation_rules WHERE is_active = 1");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($users as $userEmail) {
            $count = $this->evaluateRulesForUser($userEmail);
            if ($count > 0) {
                $results[$userEmail] = $count;
            }
        }

        return $results;
    }

    // =========================================================================
    // Target Finding (per trigger type)
    // =========================================================================

    private function findTargets(array $rule, string $userEmail): array
    {
        $config = $rule['trigger_config'];
        $targets = [];

        switch ($rule['trigger_type']) {
            case 'deal_stage_idle':
                $targets = $this->findDealsStaleInStage(
                    $userEmail,
                    $config['stage'] ?? 'lead',
                    (int)($config['days'] ?? 7)
                );
                break;

            case 'invoice_overdue':
                $targets = $this->findOverdueInvoices(
                    $userEmail,
                    (int)($config['days'] ?? 7)
                );
                break;

            case 'no_contact_days':
                $targets = $this->findSilentClients(
                    $userEmail,
                    (int)($config['days'] ?? 14)
                );
                break;

            case 'client_health_low':
                $targets = $this->findLowHealthClients(
                    $userEmail,
                    (int)($config['threshold'] ?? 30)
                );
                break;

            case 'time_spent_reached':
                $targets = $this->findTimeSpentTargets(
                    $userEmail,
                    $config
                );
                break;

            // Event-driven triggers (not cron):
            // deal_stage_changed, deal_won, deal_lost -> onDealStageChanged()
            // task_changed -> onTaskChanged()
            // board_closed -> onBoardClosed()
            // moodboard_ready -> onMoodBoardReady()
            // colleague_sick_status -> onColleagueStatusChanged()
            // drive_folder_permission_changed -> onDriveFolderPermissionChanged()
        }

        return $targets;
    }

    private function findDealsStaleInStage(string $userEmail, string $stage, int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT id, client_id, title, pipeline_stage 
            FROM crm_deals 
            WHERE user_email = ? 
              AND pipeline_stage = ?
              AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userEmail, $stage, $days]);
        $deals = $stmt->fetchAll();

        return array_map(fn($d) => ['type' => 'deal', 'id' => (int)$d['id'], 'data' => $d], $deals);
    }

    private function findOverdueInvoices(string $userEmail, int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT id, client_id, invoice_number, total, paid_amount, due_date
            FROM crm_invoices
            WHERE user_email = ?
              AND status NOT IN ('paid', 'cancelled', 'refunded')
              AND due_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userEmail, $days]);
        $invoices = $stmt->fetchAll();

        return array_map(fn($i) => ['type' => 'invoice', 'id' => (int)$i['id'], 'data' => $i], $invoices);
    }

    private function findSilentClients(string $userEmail, int $days): array
    {
        // Clients with no recent activity (based on time tracking or timeline)
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.domain
            FROM clients c
            WHERE c.user_email = ?
              AND c.status = 'active'
              AND NOT EXISTS (
                  SELECT 1 FROM webmail_client_time_tracking t
                  WHERE t.client_id = c.id AND t.user_email = c.user_email
                    AND t.tracked_date > DATE_SUB(CURDATE(), INTERVAL ? DAY)
              )
        ");
        $stmt->execute([$userEmail, $days]);
        $clients = $stmt->fetchAll();

        return array_map(fn($c) => ['type' => 'client', 'id' => (int)$c['id'], 'data' => $c], $clients);
    }

    private function findLowHealthClients(string $userEmail, int $threshold): array
    {
        // We compute a simple health score inline based on last activity recency
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.domain,
                GREATEST(0, 100 - COALESCE(DATEDIFF(CURDATE(), 
                    (SELECT MAX(t.tracked_date) FROM webmail_client_time_tracking t WHERE t.client_id = c.id AND t.user_email = c.user_email)
                ), 100)) as health_score
            FROM clients c
            WHERE c.user_email = ? AND c.status = 'active'
            HAVING health_score < ?
        ");
        $stmt->execute([$userEmail, $threshold]);
        $clients = $stmt->fetchAll();

        return array_map(fn($c) => ['type' => 'client', 'id' => (int)$c['id'], 'data' => $c], $clients);
    }

    /**
     * Find targets where tracked time exceeds a threshold.
     * Supports filtering by:
     *   - board_id: specific board/project
     *   - colleague_email: time tracked by a specific colleague (not just rule owner)
     *   - scope: 'board' or 'client' (what to group by)
     *   - hours: threshold in hours
     *   - period: day/week/month/quarter
     */
    private function findTimeSpentTargets(string $userEmail, array $config): array
    {
        $hours    = (float)($config['hours'] ?? 10);
        $period   = $config['period'] ?? 'month';
        $scope    = $config['scope'] ?? 'client';  // 'client' or 'board'
        $boardId  = !empty($config['board_id']) ? (int)$config['board_id'] : null;
        $trackEmail = !empty($config['colleague_email']) ? strtolower($config['colleague_email']) : $userEmail;

        $interval = match ($period) {
            'day'     => 'INTERVAL 1 DAY',
            'week'    => 'INTERVAL 7 DAY',
            'month'   => 'INTERVAL 30 DAY',
            'quarter' => 'INTERVAL 90 DAY',
            default   => 'INTERVAL 30 DAY',
        };

        $targets = [];

        if ($scope === 'board' || $boardId) {
            // Group by board (entity_id) — find boards where time exceeds threshold
            $where = "t.user_email = ? AND t.tracked_date > DATE_SUB(CURDATE(), {$interval})
                       AND t.activity_type IN ('board_view', 'board_task')
                       AND t.entity_id IS NOT NULL AND t.entity_id != ''";
            $params = [$trackEmail];

            if ($boardId) {
                $where .= " AND t.entity_id = ?";
                $params[] = (string)$boardId;
            }

            $params[] = $hours;

            $stmt = $this->db->prepare("
                SELECT t.entity_id as board_id,
                       MAX(t.entity_name) as board_name,
                       t.user_email as tracked_by,
                       ROUND(SUM(t.duration_seconds) / 3600.0, 1) as total_hours
                FROM webmail_client_time_tracking t
                WHERE {$where}
                GROUP BY t.entity_id, t.user_email
                HAVING total_hours >= ?
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                // Try to get board name from boards table if entity_name is empty
                $bName = $row['board_name'] ?: $this->lookupBoardName((int)$row['board_id']);
                $targets[] = [
                    'type' => 'board',
                    'id'   => (int)$row['board_id'],
                    'data' => [
                        'name'         => $bName,
                        'title'        => $bName,
                        'total_hours'  => (float)$row['total_hours'],
                        'tracked_by'   => $row['tracked_by'],
                        'period'       => $period,
                        'threshold'    => $hours,
                    ],
                ];
            }
        } else {
            // Group by client — original behaviour but fixed (duration_seconds, not minutes)
            $where = "t.user_email = ? AND c.status = 'active'
                       AND t.tracked_date > DATE_SUB(CURDATE(), {$interval})";
            $params = [$trackEmail];
            $params[] = $hours;

            $stmt = $this->db->prepare("
                SELECT c.id, COALESCE(c.display_name, c.name, c.domain) as name, c.domain,
                       t.user_email as tracked_by,
                       ROUND(SUM(t.duration_seconds) / 3600.0, 1) as total_hours
                FROM clients c
                JOIN webmail_client_time_tracking t ON t.client_id = c.id AND t.user_email = ?
                WHERE c.status = 'active'
                  AND t.tracked_date > DATE_SUB(CURDATE(), {$interval})
                GROUP BY c.id, t.user_email
                HAVING total_hours >= ?
            ");
            $stmt->execute($params);
            $clients = $stmt->fetchAll();

            foreach ($clients as $c) {
                $targets[] = [
                    'type' => 'client',
                    'id'   => (int)$c['id'],
                    'data' => [
                        'name'         => $c['name'],
                        'domain'       => $c['domain'],
                        'total_hours'  => (float)$c['total_hours'],
                        'tracked_by'   => $c['tracked_by'],
                        'period'       => $period,
                        'threshold'    => $hours,
                    ],
                ];
            }
        }

        return $targets;
    }

    // =========================================================================
    // Action Execution
    // =========================================================================

    private function executeAction(array $rule, array $target, string $userEmail): void
    {
        $config = $rule['action_config'];

        // Resolve template variables in all string fields of action_config
        $vars = $this->buildTemplateVariables($rule, $target, $userEmail);
        $config = $this->resolveConfigVariables($config, $vars);
        $rule['action_config'] = $config; // update so individual executors also see resolved values

        try {
            switch ($rule['action_type']) {
                case 'create_reminder':
                    $this->executeCreateReminder($rule, $target, $userEmail, $config);
                    break;

                case 'send_email':
                    $this->executeSendEmail($rule, $target, $userEmail, $config);
                    break;

                case 'create_invoice_draft':
                    $this->executeCreateInvoiceDraft($rule, $target, $userEmail, $config);
                    break;

                case 'move_deal_stage':
                    $this->executeMoveDealStage($rule, $target, $userEmail, $config);
                    break;

                case 'notify_user':
                    $this->executeNotifyUser($rule, $target, $userEmail, $config);
                    break;

                case 'start_sequence':
                    $this->executeStartSequence($rule, $target, $userEmail, $config);
                    break;

                case 'assign_task':
                    $this->executeAssignTask($rule, $target, $userEmail, $config);
                    break;

                case 'send_chat_message':
                    $this->executeSendChatMessage($rule, $target, $userEmail, $config);
                    break;

                case 'reassign_deals':
                    $this->executeReassignDeals($rule, $target, $userEmail, $config);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logExecution(
                $rule['id'], $userEmail, $target['type'], $target['id'],
                $rule['action_type'] . '_error',
                $e->getMessage()
            );
            error_log("CrmAutomation action error [rule:{$rule['id']}]: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Template Variable Resolution
    // =========================================================================

    /**
     * Build a map of all available template variables from the rule, target and context.
     * Keys are like {board_name}, {task_title}, etc.
     */
    private function buildTemplateVariables(array $rule, array $target, string $userEmail): array
    {
        $data = $target['data'] ?? [];

        // Common variables available for every trigger
        $vars = [
            '{rule_name}'      => $rule['name'] ?? '',
            '{trigger_type}'   => $rule['trigger_type'] ?? '',
            '{target_type}'    => $target['type'] ?? '',
            '{target_id}'      => (string)($target['id'] ?? ''),
            '{target_name}'    => $data['name'] ?? $data['title'] ?? "#{$target['id']}",
            '{your_email}'     => $userEmail,
            '{today}'          => date('Y-m-d'),
            '{now}'            => date('Y-m-d H:i'),
        ];

        // Your name (try to look it up)
        try {
            $stmt = $this->db->prepare("SELECT display_name FROM organization_colleagues WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$userEmail]);
            $row = $stmt->fetch();
            $vars['{your_name}'] = $row['display_name'] ?? explode('@', $userEmail)[0];
        } catch (\Throwable $e) {
            $vars['{your_name}'] = explode('@', $userEmail)[0];
        }

        // Deal variables
        if ($target['type'] === 'deal' || !empty($data['pipeline_stage'])) {
            $vars['{deal_title}']   = $data['title'] ?? '';
            $vars['{deal_value}']   = isset($data['value']) ? number_format((float)$data['value'], 0) : '';
            $vars['{deal_stage}']   = $data['pipeline_stage'] ?? '';
            $vars['{deal_id}']      = (string)($target['type'] === 'deal' ? $target['id'] : '');
            // Get client name if client_id is present
            if (!empty($data['client_id'])) {
                $vars['{client_name}']  = $this->lookupClientName((int)$data['client_id'], $userEmail);
                $vars['{client_id}']    = (string)$data['client_id'];
            }
        }

        // Client variables
        if ($target['type'] === 'client') {
            $vars['{client_name}']   = $data['name'] ?? $data['display_name'] ?? $data['domain'] ?? '';
            $vars['{client_domain}'] = $data['domain'] ?? '';
            $vars['{client_email}']  = $data['domain'] ? "info@{$data['domain']}" : '';
            $vars['{client_id}']     = (string)$target['id'];
        }

        // Task variables
        if ($target['type'] === 'task') {
            $vars['{task_title}']    = $data['title'] ?? '';
            $vars['{task_status}']   = $data['status'] ?? '';
            $vars['{task_priority}'] = $data['priority'] ?? '';
            $vars['{task_id}']       = (string)$target['id'];
            $vars['{task_due_date}'] = $data['due_date'] ?? '';
            $vars['{task_assignee}'] = $data['assignee_email'] ?? $data['assigned_to'] ?? '';
            // Look up board name if board_id is present
            if (!empty($data['board_id'])) {
                $vars['{board_name}'] = $this->lookupBoardName((int)$data['board_id']);
                $vars['{board_id}']   = (string)$data['board_id'];
            }
        }

        // Board variables
        if ($target['type'] === 'board') {
            $vars['{board_name}']    = $data['name'] ?? $data['title'] ?? '';
            $vars['{board_id}']      = (string)$target['id'];
            $vars['{board_owner}']   = $data['owner_email'] ?? '';
            $vars['{closed_by}']     = $data['closed_by'] ?? $userEmail;
        }

        // Moodboard variables
        if ($target['type'] === 'moodboard') {
            $vars['{moodboard_name}']  = $data['name'] ?? $data['title'] ?? '';
            $vars['{moodboard_id}']    = (string)$target['id'];
            $vars['{moodboard_owner}'] = $data['owner_email'] ?? '';
            $vars['{marked_ready_by}'] = $data['marked_ready_by'] ?? $userEmail;
        }

        // Colleague variables
        if ($target['type'] === 'colleague') {
            $vars['{colleague_name}']   = $data['name'] ?? $data['display_name'] ?? $data['email'] ?? '';
            $vars['{colleague_email}']  = $data['email'] ?? '';
            $vars['{colleague_status}'] = $data['status'] ?? '';
            $vars['{status_text}']      = $data['status_text'] ?? '';
        }

        // Drive folder variables
        if ($target['type'] === 'drive_folder') {
            $vars['{folder_name}']    = $data['name'] ?? $data['title'] ?? '';
            $vars['{folder_id}']      = (string)$target['id'];
            $vars['{changed_by}']     = $data['changed_by'] ?? '';
            $vars['{change_detail}']  = $data['change_detail'] ?? '';
        }

        // Invoice variables (may be enriched on client targets with overdue invoices)
        if (!empty($data['invoice_number'])) {
            $vars['{invoice_number}'] = $data['invoice_number'];
            $vars['{invoice_amount}'] = isset($data['total_amount']) ? number_format((float)$data['total_amount'], 0) : '';
        }

        // Time tracking variables (enriched by findTimeSpentTargets)
        if (!empty($data['total_hours'])) {
            $vars['{total_hours}']  = (string)$data['total_hours'];
            $vars['{tracked_by}']   = $data['tracked_by'] ?? $userEmail;
            $vars['{period}']       = $data['period'] ?? '';
            $vars['{threshold}']    = isset($data['threshold']) ? (string)$data['threshold'] : '';
        }

        // Email tracking variables (from email_opened / email_link_clicked triggers)
        if ($target['type'] === 'email_tracking') {
            $vars['{recipient_email}'] = $data['recipient_email'] ?? '';
            $vars['{recipient_name}']  = $data['recipient_name'] ?? '';
            $vars['{email_subject}']   = $data['subject'] ?? '';
            $vars['{link_url}']        = $data['link_url'] ?? '';
            $vars['{campaign_name}']      = $data['campaign_name'] ?? '';
            $vars['{tracking_id}']        = $data['tracking_id'] ?? '';
            $vars['{engagement_percent}'] = $data['engagement_percent'] ?? '';
            $vars['{links_clicked}']      = $data['links_clicked'] ?? '';
            $vars['{total_links}']        = $data['total_links'] ?? '';
        }

        return $vars;
    }

    /**
     * Recursively resolve template variables in all string values of $config array.
     */
    private function resolveConfigVariables(array $config, array $vars): array
    {
        $keys = array_keys($vars);
        $values = array_values($vars);

        foreach ($config as $k => $v) {
            if (is_string($v)) {
                $config[$k] = str_replace($keys, $values, $v);
            } elseif (is_array($v)) {
                $config[$k] = $this->resolveConfigVariables($v, $vars);
            }
        }
        return $config;
    }

    /**
     * Look up client display name by ID
     */
    private function lookupClientName(int $clientId, string $userEmail): string
    {
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(display_name, name, domain) as cname FROM clients WHERE id = ? LIMIT 1");
            $stmt->execute([$clientId]);
            $row = $stmt->fetch();
            return $row['cname'] ?? '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Look up board name by ID
     */
    private function lookupBoardName(int $boardId): string
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM webmail_boards WHERE id = ? LIMIT 1");
            $stmt->execute([$boardId]);
            return $stmt->fetchColumn() ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function executeCreateReminder(array $rule, array $target, string $userEmail, array $config): void
    {
        $reminderService = new CrmReminderService($this->config);

        $clientId = $target['data']['client_id'] ?? $target['id'];
        $dealId = $target['type'] === 'deal' ? $target['id'] : null;

        $reminderData = [
            'client_id' => $clientId,
            'title' => $config['title'] ?? "Auto: {$rule['name']}",
            'description' => $config['description'] ?? "Triggered by automation rule: {$rule['name']}",
            'remind_at' => date('Y-m-d H:i:s', strtotime('+' . ($config['delay_hours'] ?? 0) . ' hours')),
            'deal_id' => $dealId,
        ];

        $reminderService->createReminder($userEmail, $reminderData);

        $this->logExecution(
            $rule['id'], $userEmail, $target['type'], $target['id'],
            'create_reminder',
            "Created reminder: {$reminderData['title']}"
        );
    }

    private function executeSendEmail(array $rule, array $target, string $userEmail, array $config): void
    {
        // Queue email through existing EmailQueueService
        try {
            $emailQueue = new \Webmail\Addons\EmailMarketing\Services\EmailQueueService($this->config);

            $templateId = $config['template_id'] ?? null;
            $subject = $config['subject'] ?? "Follow-up: {$rule['name']}";
            $body = $config['body'] ?? $config['body_html'] ?? '';

            // If template_id provided, load template
            if ($templateId) {
                $templateService = new \Webmail\Services\EmailTemplateService($this->config);
                $template = $templateService->get($templateId, $userEmail);
                if ($template) {
                    $subject = $template['subject'] ?? $subject;
                    $body = $template['body'] ?? $body;
                }
            }

            // Resolve template variables in subject and body
            // (config was already resolved, but template content may contain variables too)
            $vars = $this->buildTemplateVariables($rule, $target, $userEmail);
            $keys = array_keys($vars);
            $values = array_values($vars);
            $subject = str_replace($keys, $values, $subject);
            $body = str_replace($keys, $values, $body);

            // Convert plain-text line breaks to HTML if the body isn't already HTML
            if (strip_tags($body) === $body) {
                $body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            }

            // Get recipient email from target
            $recipientEmail = $this->getTargetEmail($target, $userEmail);
            if (!$recipientEmail) {
                $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_email_skipped', 'No recipient email found');
                return;
            }

            $parentCampaignId = $target['data']['campaign_id'] ?? null;

            $emailQueue->queueEmail([
                'user_email' => $userEmail,
                'to' => $recipientEmail,
                'subject' => $subject,
                'body' => $body,
                'source' => 'crm_automation',
                'source_id' => $rule['id'],
                'parent_campaign_id' => $parentCampaignId,
            ]);

            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_email', "Email queued to {$recipientEmail}");
        } catch (\Throwable $e) {
            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_email_error', $e->getMessage());
        }
    }

    private function executeCreateInvoiceDraft(array $rule, array $target, string $userEmail, array $config): void
    {
        $invoiceService = new CrmInvoiceService($this->config);

        $clientId = $target['data']['client_id'] ?? $target['id'];

        $invoiceData = [
            'client_id' => $clientId,
            'status' => 'draft',
            'notes' => "Auto-generated by rule: {$rule['name']}",
            'currency' => $config['currency'] ?? 'HUF',
        ];

        $invoice = $invoiceService->createInvoice($userEmail, $invoiceData);

        $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'create_invoice_draft', "Invoice #{$invoice['invoice_number']} created as draft");
    }

    private function executeMoveDealStage(array $rule, array $target, string $userEmail, array $config): void
    {
        $dealService = new CrmDealService($this->config);
        $toStage = $config['to_stage'] ?? 'contacted';

        // If a specific deal_id is configured, use that instead of the trigger target
        $dealId = !empty($config['deal_id']) ? (int)$config['deal_id'] : null;

        if ($dealId) {
            // Specific deal selected by user in the rule config
            $dealService->updateStage($dealId, $userEmail, $toStage);
            $dealName = $config['deal_name'] ?? "Deal #{$dealId}";
            $this->logExecution($rule['id'], $userEmail, 'deal', $dealId, 'move_deal_stage', "Moved '{$dealName}' to stage: {$toStage}");
        } elseif ($target['type'] === 'deal') {
            // Move the deal that triggered this rule
            $dealService->updateStage($target['id'], $userEmail, $toStage);
            $this->logExecution($rule['id'], $userEmail, 'deal', $target['id'], 'move_deal_stage', "Moved to stage: {$toStage}");
        } else {
            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'move_deal_stage_skipped', "No deal to move — target is {$target['type']} and no specific deal_id configured");
        }
    }

    private function executeNotifyUser(array $rule, array $target, string $userEmail, array $config): void
    {
        $message = $config['message'] ?? "Automation alert: {$rule['name']}";
        $targetLabel = $target['data']['title'] ?? $target['data']['name'] ?? "#{$target['id']}";

        // 1. Create a REAL general notification (appears in Notifications > General tab)
        try {
            $trackingService = new \Webmail\Addons\EmailTracking\Services\TrackingService($this->config);
            $notifTitle = "⚡ Automation: {$rule['name']}";
            $notifMessage = $message;

            // Build useful data payload for the notification
            $notifData = [
                'automation_rule_id' => $rule['id'],
                'target_type' => $target['type'],
                'target_id' => $target['id'],
                'target_name' => $targetLabel,
            ];

            // Add navigation hints based on target type
            switch ($target['type']) {
                case 'deal':
                    $notifData['deal_id'] = $target['id'];
                    $notifData['client_id'] = $target['data']['client_id'] ?? null;
                    break;
                case 'board':
                    $notifData['board_id'] = $target['id'];
                    break;
                case 'moodboard':
                    $notifData['moodboard_id'] = $target['id'];
                    break;
                case 'task':
                    $notifData['task_id'] = $target['id'];
                    $notifData['board_id'] = $target['data']['board_id'] ?? null;
                    break;
                case 'colleague':
                    $notifData['colleague_email'] = $target['data']['email'] ?? null;
                    break;
                case 'client':
                    $notifData['client_id'] = $target['id'];
                    break;
            }

            $trackingService->createNotification(
                $userEmail,
                'automation',           // type
                $notifTitle,            // title
                $notifMessage,          // message
                $notifData              // data (JSON)
            );

            error_log("CrmAutomation::executeNotifyUser - Created general notification for {$userEmail}: {$notifTitle}");
        } catch (\Throwable $e) {
            error_log("CrmAutomation::executeNotifyUser - Failed to create notification: " . $e->getMessage());
        }

        // 2. Also create a CRM reminder as a secondary record (shows in CRM reminders)
        try {
            $clientId = $target['data']['client_id'] ?? ($target['type'] === 'client' ? $target['id'] : null);
            if ($clientId) {
                $reminderService = new CrmReminderService($this->config);
                $reminderService->createReminder($userEmail, [
                    'client_id' => $clientId,
                    'title' => $message,
                    'description' => "Target: {$target['type']} #{$target['id']} - {$targetLabel}",
                    'remind_at' => date('Y-m-d H:i:s'),
                    'deal_id' => $target['type'] === 'deal' ? $target['id'] : null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("CrmAutomation::executeNotifyUser - Reminder creation failed: " . $e->getMessage());
        }

        $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'notify_user', $message);
    }

    private function executeStartSequence(array $rule, array $target, string $userEmail, array $config): void
    {
        $sequenceId = $config['sequence_id'] ?? null;
        if (!$sequenceId) return;

        $sequenceService = new CrmSequenceService($this->config);

        $clientId = $target['data']['client_id'] ?? $target['id'];
        $dealId = $target['type'] === 'deal' ? $target['id'] : null;

        $sequenceService->enrollInSequence($sequenceId, $clientId, $userEmail, $dealId);

        $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'start_sequence', "Enrolled in sequence #{$sequenceId}");
    }

    private function executeAssignTask(array $rule, array $target, string $userEmail, array $config): void
    {
        $todoService = new \Webmail\Addons\Tasks\Services\TodoService($this->config);

        $assignee = $config['assignee_email'] ?? $userEmail;
        $title = $config['title'] ?? "Auto-task: {$rule['name']}";
        $description = $config['description'] ?? "Created by automation rule: {$rule['name']}";

        // Map priority to valid ENUM values: low, normal, high
        $rawPriority = $config['priority'] ?? 'normal';
        $priorityMap = ['low' => 'low', 'medium' => 'normal', 'normal' => 'normal', 'high' => 'high'];
        $priority = $priorityMap[$rawPriority] ?? 'normal';

        $dueInDays = (int)($config['due_in_days'] ?? 3);

        // Create the task under the assignee's account (or rule owner if same)
        $taskOwner = $assignee ?: $userEmail;

        $todoData = [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'due_date' => date('Y-m-d', strtotime("+{$dueInDays} days")),
        ];

        error_log("CrmAutomation::executeAssignTask - creating task for '{$taskOwner}': " . json_encode($todoData));

        $todo = $todoService->createTodo($taskOwner, $todoData);

        $this->logExecution(
            $rule['id'], $userEmail, $target['type'], $target['id'],
            'assign_task',
            "Task created: {$title}" . ($assignee !== $userEmail ? " (assigned to {$assignee})" : "")
        );
    }

    private function executeSendChatMessage(array $rule, array $target, string $userEmail, array $config): void
    {
        error_log("CrmAutomation::executeSendChatMessage - rule #{$rule['id']}, raw_action_config=" . json_encode($rule['action_config']) . ", resolved_config=" . json_encode($config));

        $message = $config['message'] ?? "Automation alert: {$rule['name']}";

        // Support both 'conversation_id' (from new picker) and numeric-string values
        $rawConvId = $config['conversation_id'] ?? null;
        $conversationId = null;
        if ($rawConvId !== null && $rawConvId !== '' && $rawConvId !== 0 && $rawConvId !== '0') {
            $conversationId = (int)$rawConvId;
        }

        error_log("CrmAutomation::executeSendChatMessage - rawConvId=" . var_export($rawConvId, true) . ", conversationId=" . var_export($conversationId, true));

        try {
            $chatService = new \Webmail\Addons\Chat\Services\ChatService($this->config);

            // Build message with context about what triggered it
            $targetLabel = $target['data']['title'] ?? $target['data']['name'] ?? "#{$target['id']}";
            $fullMessage = "{$message}\n\n⚡ Auto: {$target['type']} — {$targetLabel}";

            if ($conversationId) {
                // Send directly to the selected conversation (channel, group, or DM)
                // skipAccessCheck=true because automation should be able to post to any channel
                $result = $chatService->sendMessage($conversationId, $userEmail, $fullMessage, null, null, null, false, true);

                if (!empty($result['success'])) {
                    $channelName = $config['conversation_name'] ?? "#{$conversationId}";
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message', "Chat message sent to {$channelName}");
                } else {
                    $error = $result['error'] ?? 'Unknown error';
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_error', "Failed to send to conversation #{$conversationId}: {$error}");
                }
            } else {
                // Log that no conversation was selected — this helps debug
                $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_info', 
                    "No conversation_id in config. Raw: " . json_encode($config) . ". Falling back to auto-DM.");

                // Auto DM: try to find a colleague related to the target and send them a DM
                $recipientEmail = $this->getTargetEmail($target, $userEmail);
                if (!$recipientEmail) {
                    // Fallback: send to the rule owner themselves as a self-notification
                    $recipientEmail = $userEmail;
                }

                // Look up the recipient colleague ID for the DM
                $colleagueStmt = $this->db->prepare("SELECT id FROM organization_colleagues WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $colleagueStmt->execute([$recipientEmail]);
                $colleagueId = $colleagueStmt->fetchColumn();

                if (!$colleagueId) {
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_skipped', "Colleague not found for {$recipientEmail}");
                    return;
                }

                $dmResult = $chatService->getOrCreateDMConversation($userEmail, (int)$colleagueId);

                if (empty($dmResult['success']) || empty($dmResult['conversation']['id'])) {
                    $error = $dmResult['error'] ?? 'Could not create DM conversation';
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_error', $error);
                    return;
                }

                $dmConversationId = (int)$dmResult['conversation']['id'];
                $result = $chatService->sendMessage($dmConversationId, $userEmail, $fullMessage);

                if (!empty($result['success'])) {
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message', "DM sent to {$recipientEmail}");
                } else {
                    $error = $result['error'] ?? 'Unknown error';
                    $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_error', "DM to {$recipientEmail} failed: {$error}");
                }
            }
        } catch (\Throwable $e) {
            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'send_chat_message_error', $e->getMessage());
        }
    }

    private function executeReassignDeals(array $rule, array $target, string $userEmail, array $config): void
    {
        $fromEmail = $config['from_email'] ?? null;
        $toEmail = $config['to_email'] ?? null;

        if (!$fromEmail || !$toEmail) {
            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'reassign_deals_skipped', 'Missing from_email or to_email');
            return;
        }

        try {
            // Find all active deals belonging to from_email and transfer them to to_email
            $stmt = $this->db->prepare("
                SELECT id, title FROM crm_deals
                WHERE user_email = ? AND pipeline_stage NOT IN ('won', 'lost')
            ");
            $stmt->execute([strtolower($fromEmail)]);
            $deals = $stmt->fetchAll();

            if (empty($deals)) {
                $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'reassign_deals_skipped', "No active deals found for {$fromEmail}");
                return;
            }

            $updateStmt = $this->db->prepare("UPDATE crm_deals SET user_email = ?, updated_at = NOW() WHERE id = ?");
            $reassigned = 0;
            foreach ($deals as $deal) {
                $updateStmt->execute([strtolower($toEmail), $deal['id']]);
                $reassigned++;
            }

            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'reassign_deals', "Reassigned {$reassigned} deals from {$fromEmail} to {$toEmail}");
        } catch (\Throwable $e) {
            $this->logExecution($rule['id'], $userEmail, $target['type'], $target['id'], 'reassign_deals_error', $e->getMessage());
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Check if we already acted on this target in the last 24h (debounce)
     */
    private function wasRecentlyActedOn(int $ruleId, string $targetType, string|int $targetId): bool
    {
        // Only count successful actions as "recently acted on" — not skipped/error/info entries
        // Use a 5-minute cooldown for event-based triggers (board_closed, moodboard_ready, etc.)
        // and 24-hour cooldown for polling-based triggers (deal_stage_idle, invoice_overdue, etc.)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM crm_automation_log
            WHERE rule_id = ? AND target_type = ? AND target_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
              AND action_taken NOT LIKE '%_skipped'
              AND action_taken NOT LIKE '%_error'
              AND action_taken NOT LIKE '%_info'
        ");
        $stmt->execute([$ruleId, $targetType, $targetId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get email address for a target (client contact email, etc.)
     */
    private function getTargetEmail(array $target, string $userEmail): ?string
    {
        if ($target['type'] === 'client') {
            $stmt = $this->db->prepare("SELECT domain FROM clients WHERE id = ? AND user_email = ?");
            $stmt->execute([$target['id'], $userEmail]);
            $row = $stmt->fetch();
            // Look for a contact email
            $stmt2 = $this->db->prepare("SELECT email FROM crm_contacts WHERE client_id = ? LIMIT 1");
            $stmt2->execute([$target['id']]);
            $contact = $stmt2->fetch();
            return $contact['email'] ?? ($row['domain'] ? "info@{$row['domain']}" : null);
        }

        if ($target['type'] === 'deal') {
            $clientId = $target['data']['client_id'] ?? null;
            if ($clientId) {
                $stmt = $this->db->prepare("SELECT email FROM crm_contacts WHERE client_id = ? LIMIT 1");
                $stmt->execute([$clientId]);
                $contact = $stmt->fetch();
                return $contact['email'] ?? null;
            }
        }

        if ($target['type'] === 'invoice') {
            $clientId = $target['data']['client_id'] ?? null;
            if ($clientId) {
                $stmt = $this->db->prepare("SELECT email FROM crm_contacts WHERE client_id = ? LIMIT 1");
                $stmt->execute([$clientId]);
                $contact = $stmt->fetch();
                return $contact['email'] ?? null;
            }
        }

        if ($target['type'] === 'email_tracking') {
            return $target['data']['recipient_email'] ?? null;
        }

        return null;
    }

    // =========================================================================
    // Event-triggered rule evaluation (called from hooks, not cron)
    // =========================================================================

    /**
     * Check rules that match a specific event (deal_stage_changed, deal_won, deal_lost)
     * Called synchronously from CrmDealService::updateStage()
     */
    public function onDealStageChanged(int $dealId, string $fromStage, string $toStage, string $userEmail): void
    {
        $triggerTypes = ['deal_stage_changed'];
        if ($toStage === 'won') $triggerTypes[] = 'deal_won';
        if ($toStage === 'lost') $triggerTypes[] = 'deal_lost';

        $placeholders = implode(',', array_fill(0, count($triggerTypes), '?'));
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$userEmail], $triggerTypes));
        $rules = $stmt->fetchAll();

        // Get deal data
        $dealStmt = $this->db->prepare("SELECT * FROM crm_deals WHERE id = ?");
        $dealStmt->execute([$dealId]);
        $deal = $dealStmt->fetch();
        if (!$deal) return;

        $target = ['type' => 'deal', 'id' => $dealId, 'data' => $deal];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // For deal_stage_changed, check if the trigger config specifies a particular stage
            if ($rule['trigger_type'] === 'deal_stage_changed') {
                $configStage = $rule['trigger_config']['stage'] ?? null;
                if ($configStage && $configStage !== $toStage) continue;
            }

            if ($this->wasRecentlyActedOn($rule['id'], 'deal', $dealId)) continue;

            $this->executeAction($rule, $target, $userEmail);
        }

        // Fire Automation Hub workflow events (non-blocking)
        try {
            $hubEngine = new \Webmail\Addons\AutomationHub\Services\WorkflowEngineService($this->config);
            $hubContext = [
                'deal_id' => $dealId, 'from_stage' => $fromStage, 'to_stage' => $toStage,
                'user_email' => $userEmail, 'deal_title' => $deal['title'] ?? '',
            ];
            $hubEngine->onEvent('trigger.crm.deal_stage_changed', $hubContext);
            if ($toStage === 'won') $hubEngine->onEvent('trigger.crm.deal_won', $hubContext);
            if ($toStage === 'lost') $hubEngine->onEvent('trigger.crm.deal_lost', $hubContext);
        } catch (\Throwable $e) {
            // Automation Hub errors must never break CRM automations
        }
    }

    /**
     * Event hook: task changed (status, assignee, etc.)
     * Called from TodoService when a task is updated.
     */
    public function onTaskChanged(int $taskId, string $changeType, array $taskData, string $userEmail): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'task_changed'
        ");
        $stmt->execute([$userEmail]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        $target = ['type' => 'task', 'id' => $taskId, 'data' => $taskData];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Optional filter: specific change types (completed, assigned, priority_changed, etc.)
            $watchedChange = $rule['trigger_config']['change_type'] ?? null;
            if ($watchedChange && $watchedChange !== $changeType) continue;

            if ($this->wasRecentlyActedOn($rule['id'], 'task', $taskId)) continue;

            $this->executeAction($rule, $target, $userEmail);
        }
    }

    /**
     * Event hook: board closed
     * Called from BoardService::closeBoard()
     */
    public function onBoardClosed(int $boardId, string $boardName, string $userEmail): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'board_closed'
        ");
        $stmt->execute([$userEmail]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        $target = ['type' => 'board', 'id' => $boardId, 'data' => ['name' => $boardName, 'title' => $boardName]];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Optional: watch a specific board
            $watchedBoardId = $rule['trigger_config']['board_id'] ?? null;
            if ($watchedBoardId && (int)$watchedBoardId !== $boardId) continue;

            if ($this->wasRecentlyActedOn($rule['id'], 'board', $boardId)) continue;

            $this->executeAction($rule, $target, $userEmail);
        }
    }

    /**
     * Event hook: moodboard marked as ready
     * Called from MoodBoardService::toggleReady()
     */
    public function onMoodBoardReady(int $boardId, string $boardName, string $userEmail): void
    {
        error_log("CrmAutomation::onMoodBoardReady - boardId={$boardId}, boardName={$boardName}, user={$userEmail}");

        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'moodboard_ready'
        ");
        $stmt->execute([$userEmail]);
        $rules = $stmt->fetchAll();

        error_log("CrmAutomation::onMoodBoardReady - found " . count($rules) . " matching rules");

        if (empty($rules)) return;

        $target = ['type' => 'moodboard', 'id' => $boardId, 'data' => ['name' => $boardName, 'title' => $boardName]];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            error_log("CrmAutomation::onMoodBoardReady - rule #{$rule['id']}: action={$rule['action_type']}, config=" . json_encode($rule['action_config']));

            $watchedBoardId = $rule['trigger_config']['board_id'] ?? null;
            if ($watchedBoardId && (int)$watchedBoardId !== $boardId) {
                error_log("CrmAutomation::onMoodBoardReady - rule #{$rule['id']} skipped: board_id filter mismatch");
                continue;
            }

            if ($this->wasRecentlyActedOn($rule['id'], 'moodboard', $boardId)) {
                error_log("CrmAutomation::onMoodBoardReady - rule #{$rule['id']} skipped: recently acted on");
                continue;
            }

            error_log("CrmAutomation::onMoodBoardReady - executing rule #{$rule['id']}");
            $this->executeAction($rule, $target, $userEmail);
        }
    }

    /**
     * Event hook: colleague status changed (e.g. set to "sick")
     * Called from ColleagueService::updateColleagueStatus() or status_text updates
     */
    public function onColleagueStatusChanged(int $colleagueId, string $colleagueEmail, string $status, ?string $statusText, string $domain): void
    {
        // Find all users in the same domain who have sick_status automation rules
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE is_active = 1 AND trigger_type = 'colleague_sick_status'
              AND user_email LIKE ?
        ");
        $stmt->execute(['%@' . $domain]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        // Check if status matches "sick" conditions
        $isSick = ($status === 'sick') ||
                  (stripos($statusText ?? '', 'sick') !== false) ||
                  (stripos($statusText ?? '', 'ill') !== false) ||
                  (stripos($statusText ?? '', 'beteg') !== false); // Hungarian

        if (!$isSick) return;

        $target = [
            'type' => 'colleague',
            'id' => $colleagueId,
            'data' => [
                'email' => $colleagueEmail,
                'name' => $colleagueEmail,
                'status' => $status,
                'status_text' => $statusText,
                'title' => "Colleague sick: {$colleagueEmail}",
            ]
        ];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Optional: watch specific colleague
            $watchedEmail = $rule['trigger_config']['colleague_email'] ?? null;
            if ($watchedEmail && strtolower($watchedEmail) !== strtolower($colleagueEmail)) continue;

            if ($this->wasRecentlyActedOn($rule['id'], 'colleague', $colleagueId)) continue;

            $this->executeAction($rule, $target, $rule['user_email']);
        }
    }

    /**
     * Event hook: drive folder permission changed
     * Called from DriveService or ColleagueService when folder sharing changes
     */
    public function onDriveFolderPermissionChanged(int $folderId, string $folderName, string $changedByEmail, string $changeDetail): void
    {
        // Get the folder owner email
        $stmt = $this->db->prepare("SELECT user_email FROM drive_folders WHERE id = ?");
        $stmt->execute([$folderId]);
        $ownerEmail = $stmt->fetchColumn();

        if (!$ownerEmail) return;

        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'drive_folder_permission_changed'
        ");
        $stmt->execute([$ownerEmail]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        $target = [
            'type' => 'drive_folder',
            'id' => $folderId,
            'data' => [
                'name' => $folderName,
                'title' => $folderName,
                'changed_by' => $changedByEmail,
                'change_detail' => $changeDetail,
            ]
        ];

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Optional: watch a specific folder
            $watchedFolderId = $rule['trigger_config']['folder_id'] ?? null;
            if ($watchedFolderId && (int)$watchedFolderId !== $folderId) continue;

            if ($this->wasRecentlyActedOn($rule['id'], 'drive_folder', $folderId)) continue;

            $this->executeAction($rule, $target, $ownerEmail);
        }
    }

    /**
     * Fired when a tracked email is opened by a recipient.
     * Called from TrackingService::recordReadEvent().
     */
    public function onEmailOpened(string $trackingId, string $recipientEmail, string $senderEmail, ?string $campaignId, string $subject): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'email_opened'
        ");
        $stmt->execute([$senderEmail]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        // Look up campaign name if applicable
        $campaignName = '';
        if ($campaignId) {
            try {
                $cStmt = $this->db->prepare("SELECT name FROM email_campaigns WHERE campaign_id = ? LIMIT 1");
                $cStmt->execute([$campaignId]);
                $campaignName = $cStmt->fetchColumn() ?: '';
            } catch (\Throwable $e) {}
        }

        // Try to look up recipient display name
        $recipientName = '';
        try {
            $nStmt = $this->db->prepare("SELECT COALESCE(display_name, name) as dname FROM crm_contacts WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $nStmt->execute([$recipientEmail]);
            $recipientName = $nStmt->fetchColumn() ?: explode('@', $recipientEmail)[0];
        } catch (\Throwable $e) {
            $recipientName = explode('@', $recipientEmail)[0];
        }

        // Try to find CRM client by recipient email for sequence enrollment
        $clientId = null;
        try {
            $clStmt = $this->db->prepare("SELECT client_id FROM crm_contacts WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $clStmt->execute([$recipientEmail]);
            $clientId = $clStmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {}

        $target = [
            'type' => 'email_tracking',
            'id' => $trackingId,
            'data' => [
                'tracking_id' => $trackingId,
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $subject,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'client_id' => $clientId,
            ]
        ];

        $debounceKey = $trackingId . ':' . strtolower($recipientEmail);

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Scope filter
            $scope = $rule['trigger_config']['scope'] ?? 'all';
            if ($scope === 'campaigns' && !$campaignId) continue;
            if ($scope === 'regular' && $campaignId) continue;

            // Specific campaign filter
            $watchCampaign = $rule['trigger_config']['campaign_id'] ?? null;
            if ($watchCampaign && $watchCampaign !== $campaignId) continue;

            if ($this->wasRecentlyActedOn($rule['id'], 'email_open', $debounceKey)) continue;

            $this->executeAction($rule, $target, $senderEmail);
        }
        
        if ($campaignId) {
            $this->onCampaignEngagementCheck($senderEmail, $campaignId, $recipientEmail, $recipientName, $subject, 'open');
        }
    }

    /**
     * Fired when a tracked link in an email is clicked.
     * Called from TrackingService::recordClickEvent().
     */
    public function onEmailLinkClicked(string $trackingId, string $recipientEmail, string $senderEmail, ?string $campaignId, string $subject, string $linkUrl, string $linkToken): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_automation_rules
            WHERE user_email = ? AND is_active = 1 AND trigger_type = 'email_link_clicked'
        ");
        $stmt->execute([$senderEmail]);
        $rules = $stmt->fetchAll();

        if (empty($rules)) return;

        $campaignName = '';
        if ($campaignId) {
            try {
                $cStmt = $this->db->prepare("SELECT name FROM email_campaigns WHERE campaign_id = ? LIMIT 1");
                $cStmt->execute([$campaignId]);
                $campaignName = $cStmt->fetchColumn() ?: '';
            } catch (\Throwable $e) {}
        }

        $recipientName = '';
        try {
            $nStmt = $this->db->prepare("SELECT COALESCE(display_name, name) as dname FROM crm_contacts WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $nStmt->execute([$recipientEmail]);
            $recipientName = $nStmt->fetchColumn() ?: explode('@', $recipientEmail)[0];
        } catch (\Throwable $e) {
            $recipientName = explode('@', $recipientEmail)[0];
        }

        $clientId = null;
        try {
            $clStmt = $this->db->prepare("SELECT client_id FROM crm_contacts WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $clStmt->execute([$recipientEmail]);
            $clientId = $clStmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {}

        $target = [
            'type' => 'email_tracking',
            'id' => $trackingId,
            'data' => [
                'tracking_id' => $trackingId,
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $subject,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'link_url' => $linkUrl,
                'client_id' => $clientId,
            ]
        ];

        $debounceKey = $trackingId . ':' . $linkToken . ':' . strtolower($recipientEmail);

        foreach ($rules as $rule) {
            $rule['trigger_config'] = json_decode($rule['trigger_config'], true) ?: [];
            $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];

            // Scope filter
            $scope = $rule['trigger_config']['scope'] ?? 'all';
            if ($scope === 'campaigns' && !$campaignId) continue;
            if ($scope === 'regular' && $campaignId) continue;

            // Specific campaign filter
            $watchCampaign = $rule['trigger_config']['campaign_id'] ?? null;
            if ($watchCampaign && $watchCampaign !== $campaignId) continue;

            // Link URL filter
            $linkMatch = $rule['trigger_config']['link_match'] ?? 'any';
            if ($linkMatch === 'contains') {
                $linkValue = $rule['trigger_config']['link_value'] ?? '';
                if ($linkValue && stripos($linkUrl, $linkValue) === false) continue;
            } elseif ($linkMatch === 'exact') {
                $linkValue = $rule['trigger_config']['link_value'] ?? '';
                if ($linkValue && strcasecmp($linkUrl, $linkValue) !== 0) continue;
            }

            if ($this->wasRecentlyActedOn($rule['id'], 'email_click', $debounceKey)) continue;

            $this->executeAction($rule, $target, $senderEmail);
        }
        
        if ($campaignId) {
            $this->onCampaignEngagementCheck($senderEmail, $campaignId, $recipientEmail, $recipientName, $subject, 'click');
        }
    }
    
    /**
     * Check campaign engagement threshold triggers.
     * Called from onEmailOpened() and onEmailLinkClicked() when event is for a campaign.
     */
    private function onCampaignEngagementCheck(
        string $senderEmail,
        string $campaignId,
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $eventType
    ): void {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM crm_automation_rules
                WHERE user_email = ? AND is_active = 1 AND trigger_type = 'campaign_engagement_threshold'
            ");
            $stmt->execute([$senderEmail]);
            $rules = $stmt->fetchAll();
            
            if (empty($rules)) return;
            
            foreach ($rules as $rule) {
                $config = json_decode($rule['trigger_config'], true) ?: [];
                $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];
                
                $ruleCampaignId = $config['campaign_id'] ?? '';
                if ($ruleCampaignId && $ruleCampaignId !== $campaignId) continue;
                
                $metric = $config['metric'] ?? 'link_click_rate';
                $threshold = (float)($config['threshold'] ?? 50);
                
                if ($metric === 'opened' && $eventType !== 'open') continue;
                if (in_array($metric, ['link_click_rate', 'video_link_click_rate']) && $eventType !== 'click') continue;
                
                // Check dedup: already fired for this recipient+campaign+rule?
                $firedStmt = $this->db->prepare("
                    SELECT id FROM campaign_engagement_fired 
                    WHERE rule_id = ? AND campaign_id = ? AND recipient_email = ?
                ");
                $firedStmt->execute([$rule['id'], $campaignId, strtolower($recipientEmail)]);
                if ($firedStmt->fetch()) continue;
                
                $engagementPercent = $this->calculateCampaignEngagement($campaignId, $recipientEmail, $metric);
                
                if ($engagementPercent < $threshold) continue;
                
                // Record fired
                try {
                    $this->db->prepare("
                        INSERT IGNORE INTO campaign_engagement_fired (rule_id, campaign_id, recipient_email, engagement_percent)
                        VALUES (?, ?, ?, ?)
                    ")->execute([$rule['id'], $campaignId, strtolower($recipientEmail), $engagementPercent]);
                } catch (\Throwable $e) {
                    continue;
                }
                
                $campaignName = '';
                try {
                    $cStmt = $this->db->prepare("SELECT subject FROM email_campaigns WHERE campaign_id = ? LIMIT 1");
                    $cStmt->execute([$campaignId]);
                    $campaignName = $cStmt->fetchColumn() ?: '';
                } catch (\Throwable $e) {}
                
                $totalLinks = 0;
                $clickedLinks = 0;
                try {
                    $tStmt = $this->db->prepare("SELECT COUNT(DISTINCT lt.link_token) FROM email_link_tracking lt JOIN email_tracking et ON et.tracking_id = lt.tracking_id WHERE et.campaign_id = ?");
                    $tStmt->execute([$campaignId]);
                    $totalLinks = (int)$tStmt->fetchColumn();
                    
                    $cStmt2 = $this->db->prepare("SELECT COUNT(DISTINCT ce.link_token) FROM email_click_events ce JOIN email_link_tracking lt ON lt.link_token = ce.link_token JOIN email_tracking et ON et.tracking_id = lt.tracking_id WHERE et.campaign_id = ? AND LOWER(ce.recipient_email) = LOWER(?)");
                    $cStmt2->execute([$campaignId, $recipientEmail]);
                    $clickedLinks = (int)$cStmt2->fetchColumn();
                } catch (\Throwable $e) {}
                
                $target = [
                    'type' => 'email_tracking',
                    'id' => $campaignId . ':' . strtolower($recipientEmail),
                    'data' => [
                        'tracking_id' => $campaignId,
                        'recipient_email' => $recipientEmail,
                        'recipient_name' => $recipientName,
                        'subject' => $subject,
                        'campaign_id' => $campaignId,
                        'campaign_name' => $campaignName,
                        'engagement_percent' => round($engagementPercent, 1),
                        'links_clicked' => $clickedLinks,
                        'total_links' => $totalLinks,
                    ]
                ];
                
                $this->executeAction($rule, $target, $senderEmail);
            }
        } catch (\Throwable $e) {
            error_log("onCampaignEngagementCheck error: " . $e->getMessage());
        }
    }
    
    private function calculateCampaignEngagement(string $campaignId, string $recipientEmail, string $metric): float
    {
        try {
            if ($metric === 'opened') {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM email_read_events re
                    JOIN email_tracking et ON et.tracking_id = re.tracking_id
                    WHERE et.campaign_id = ? AND LOWER(re.recipient_email) = LOWER(?)
                ");
                $stmt->execute([$campaignId, $recipientEmail]);
                return (float)$stmt->fetchColumn();
            }
            
            $videoOnly = ($metric === 'video_link_click_rate');
            
            // Total unique links in campaign
            $stmt = $this->db->prepare("
                SELECT lt.original_url, lt.link_token
                FROM email_link_tracking lt
                JOIN email_tracking et ON et.tracking_id = lt.tracking_id
                WHERE et.campaign_id = ?
                GROUP BY lt.original_url
            ");
            $stmt->execute([$campaignId]);
            $allLinks = $stmt->fetchAll();
            
            if (empty($allLinks)) return 0;
            
            $targetLinks = $allLinks;
            if ($videoOnly) {
                $targetLinks = array_filter($allLinks, fn($l) => $this->isVideoLink($l['original_url']));
            }
            
            $totalLinks = count($targetLinks);
            if ($totalLinks === 0) return 0;
            
            // Unique links clicked by this recipient
            $linkTokens = array_column($targetLinks, 'link_token');
            if (empty($linkTokens)) return 0;
            
            $placeholders = implode(',', array_fill(0, count($linkTokens), '?'));
            $params = array_merge($linkTokens, [strtolower($recipientEmail)]);
            
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT ce.link_token)
                FROM email_click_events ce
                WHERE ce.link_token IN ({$placeholders}) AND LOWER(ce.recipient_email) = LOWER(?)
            ");
            $stmt->execute($params);
            $clickedCount = (int)$stmt->fetchColumn();
            
            return ($clickedCount / $totalLinks) * 100;
        } catch (\Throwable $e) {
            error_log("calculateCampaignEngagement error: " . $e->getMessage());
            return 0;
        }
    }
    
    private function isVideoLink(string $url): bool
    {
        $videoPatterns = [
            'youtube.com/watch', 'youtu.be/', 'youtube.com/embed',
            'vimeo.com/', 'player.vimeo.com',
            'wistia.com/', 'fast.wistia.com',
            'loom.com/share', 'loom.com/embed',
            'dailymotion.com/', 'dai.ly/',
        ];
        foreach ($videoPatterns as $pattern) {
            if (stripos($url, $pattern) !== false) return true;
        }
        return false;
    }
}

