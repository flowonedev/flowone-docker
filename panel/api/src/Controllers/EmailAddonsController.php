<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * EmailAddonsController - Manages email user groups and per-user/group addon assignments
 *
 * Tables (auto-created):
 *   emailAddons_groups         — user groups for addon management
 *   emailAddons_group_members  — group membership (by email)
 *   emailAddons_assignments    — per-user / per-group addon overrides
 *
 * Reads from webmail_sessions to discover email users who have logged in.
 */
class EmailAddonsController extends BaseController
{
    // ─── Email Users ────────────────────────────────────────────────────

    /**
     * List all email addresses that have logged in at least once.
     * Pulls distinct emails from the webmail_sessions table.
     */
    public function users(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            // Get unique emails from webmail_sessions with aggregated stats
            // The webmail_sessions table may not exist yet if the Email App hasn't been set up
            $users = [];
            try {
                $hasColleagues = (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'organization_colleagues'")->fetchColumn();

                $colleagueJoin = $hasColleagues
                    ? "LEFT JOIN organization_colleagues oc ON oc.email = ws.email"
                    : "";
                $colleagueCols = $hasColleagues
                    ? ", oc.display_name, oc.avatar_path, oc.job_title, oc.department"
                    : ", NULL AS display_name, NULL AS avatar_path, NULL AS job_title, NULL AS department";

                $stmt = $db->prepare("
                    SELECT 
                        ws.email,
                        MIN(ws.created_at) AS first_seen,
                        MAX(ws.last_active_at) AS last_active,
                        COUNT(*) AS session_count,
                        MAX(ws.browser) AS last_browser,
                        MAX(ws.os) AS last_os,
                        MAX(ws.ip_address) AS last_ip,
                        MAX(CASE 
                            WHEN ws.expires_at > NOW() AND ws.last_active_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1
                            ELSE 0
                        END) AS is_online
                        {$colleagueCols}
                    FROM webmail_sessions ws
                    {$colleagueJoin}
                    GROUP BY ws.email
                    ORDER BY MAX(ws.last_active_at) DESC
                ");
                $stmt->execute();
                $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                // webmail_sessions table doesn't exist — no users to show
                $users = [];
            }

            // Attach group memberships for each user
            $stmtGroups = $db->prepare("
                SELECT gm.email, g.id AS group_id, g.name, g.color, g.icon
                FROM emailAddons_group_members gm
                JOIN emailAddons_groups g ON g.id = gm.group_id
                ORDER BY g.name
            ");
            $stmtGroups->execute();
            $allMemberships = $stmtGroups->fetchAll(\PDO::FETCH_ASSOC);

            $membershipMap = [];
            foreach ($allMemberships as $m) {
                $membershipMap[$m['email']][] = [
                    'group_id' => (int) $m['group_id'],
                    'name' => $m['name'],
                    'color' => $m['color'],
                    'icon' => $m['icon'],
                ];
            }

            // Attach addon overrides for each user
            $stmtAssign = $db->prepare("
                SELECT addon_slug, target_id AS email, enabled
                FROM emailAddons_assignments
                WHERE target_type = 'user'
            ");
            $stmtAssign->execute();
            $userAssignments = $stmtAssign->fetchAll(\PDO::FETCH_ASSOC);

            $assignMap = [];
            foreach ($userAssignments as $a) {
                $assignMap[$a['email']][$a['addon_slug']] = (bool) $a['enabled'];
            }

            // Fetch onboarding quiz scores (if table exists)
            $quizMap = [];
            try {
                $quizStmt = $db->query("SELECT email, score, total, percent, attempts, created_at FROM onboarding_quiz_scores");
                foreach ($quizStmt->fetchAll(\PDO::FETCH_ASSOC) as $q) {
                    $quizMap[$q['email']] = [
                        'score'    => (int) $q['score'],
                        'total'    => (int) $q['total'],
                        'percent'  => (int) $q['percent'],
                        'attempts' => (int) $q['attempts'],
                        'taken_at' => $q['created_at'],
                    ];
                }
            } catch (\PDOException $e) {
                // Table may not exist yet
            }

            foreach ($users as &$user) {
                $user['groups'] = $membershipMap[$user['email']] ?? [];
                $user['addon_overrides'] = $assignMap[$user['email']] ?? (object) [];
                $user['session_count'] = (int) $user['session_count'];
                $user['is_online'] = (bool) ($user['is_online'] ?? false);
                $user['quiz_score'] = $quizMap[$user['email']] ?? null;
            }
            unset($user);

            return Response::success([
                'users' => $users,
                'count' => count($users),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get session history for a specific email user.
     * Returns individual sessions with login time, duration, active status, device info.
     */
    public function userSessions(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = urldecode($request->getParam('email') ?? '');
        if (empty($email)) {
            return Response::error('Email is required', 400);
        }

        try {
            $db = $this->container->getDatabase();

            $sessions = [];
            try {
                $stmt = $db->prepare("
                    SELECT 
                        id,
                        browser,
                        os,
                        ip_address,
                        location,
                        created_at,
                        last_active_at,
                        expires_at,
                        CASE 
                            WHEN expires_at > NOW() AND last_active_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1
                            ELSE 0
                        END AS is_online
                    FROM webmail_sessions
                    WHERE email = ?
                    ORDER BY created_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$email]);
                $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($sessions as &$s) {
                    $s['id'] = (int) $s['id'];
                    $s['is_online'] = (bool) $s['is_online'];
                    // Duration in seconds between created_at and last_active_at
                    $start = strtotime($s['created_at']);
                    $end = strtotime($s['last_active_at']);
                    $s['duration_seconds'] = max(0, $end - $start);
                }
                unset($s);
            } catch (\PDOException $e) {
                // Table doesn't exist
            }

            return Response::success([
                'email' => $email,
                'sessions' => $sessions,
                'count' => count($sessions),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ─── Groups CRUD ────────────────────────────────────────────────────

    /**
     * List all groups with member counts.
     */
    public function listGroups(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            $stmt = $db->prepare("
                SELECT 
                    g.*,
                    COUNT(gm.id) AS member_count
                FROM emailAddons_groups g
                LEFT JOIN emailAddons_group_members gm ON gm.group_id = g.id
                GROUP BY g.id
                ORDER BY g.name ASC
            ");
            $stmt->execute();
            $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Attach members and addon overrides for each group
            foreach ($groups as &$group) {
                $group['member_count'] = (int) $group['member_count'];
                $group['id'] = (int) $group['id'];

                // Get members
                $hasColleagues = (bool) $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'organization_colleagues'")->fetchColumn();
                $colJoin = $hasColleagues ? "LEFT JOIN organization_colleagues oc ON oc.email = gm.email" : "";
                $colSelect = $hasColleagues ? ", oc.display_name" : ", NULL AS display_name";

                $stmtMembers = $db->prepare("
                    SELECT gm.email, gm.added_by, gm.added_at {$colSelect}
                    FROM emailAddons_group_members gm
                    {$colJoin}
                    WHERE gm.group_id = ?
                    ORDER BY gm.email
                ");
                $stmtMembers->execute([$group['id']]);
                $group['members'] = $stmtMembers->fetchAll(\PDO::FETCH_ASSOC);

                // Get addon overrides for this group
                $stmtAssign = $db->prepare("
                    SELECT addon_slug, enabled
                    FROM emailAddons_assignments
                    WHERE target_type = 'group' AND target_id = ?
                ");
                $stmtAssign->execute([(string) $group['id']]);
                $overrides = $stmtAssign->fetchAll(\PDO::FETCH_ASSOC);
                $group['addon_overrides'] = [];
                foreach ($overrides as $o) {
                    $group['addon_overrides'][$o['addon_slug']] = (bool) $o['enabled'];
                }
            }
            unset($group);

            return Response::success([
                'groups' => $groups,
                'count' => count($groups),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new group.
     */
    public function createGroup(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $name = trim($request->input('name', ''));
        if (empty($name)) {
            return Response::error('Group name is required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            $description = $request->input('description', '');
            $color = $request->input('color', '#6366f1');
            $icon = $request->input('icon', 'group');
            $actor = $this->getActor();

            $stmt = $db->prepare("
                INSERT INTO emailAddons_groups (name, description, color, icon, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $color, $icon, $actor]);

            $id = (int) $db->lastInsertId();

            // Optionally add initial members
            $members = $request->input('members', []);
            if (!empty($members) && is_array($members)) {
                $stmtMember = $db->prepare("
                    INSERT IGNORE INTO emailAddons_group_members (group_id, email, added_by)
                    VALUES (?, ?, ?)
                ");
                foreach ($members as $email) {
                    $email = trim($email);
                    if (!empty($email)) {
                        $stmtMember->execute([$id, $email, $actor]);
                    }
                }
            }

            $this->logAction('emailAddons.group.create', "group:{$name}", 'success', ['group_id' => $id]);

            return Response::success(['id' => $id, 'name' => $name], 'Group created');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return Response::error('A group with that name already exists', 409);
            }
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a group.
     */
    public function updateGroup(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        if (!$id) {
            return Response::error('Group ID is required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            // Check group exists
            $stmt = $db->prepare("SELECT * FROM emailAddons_groups WHERE id = ?");
            $stmt->execute([$id]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$group) {
                return Response::notFound('Group not found');
            }

            $name = trim($request->input('name', $group['name']));
            $description = $request->input('description', $group['description']);
            $color = $request->input('color', $group['color']);
            $icon = $request->input('icon', $group['icon']);

            $stmt = $db->prepare("
                UPDATE emailAddons_groups
                SET name = ?, description = ?, color = ?, icon = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $color, $icon, $id]);

            $this->logAction('emailAddons.group.update', "group:{$name}", 'success', ['group_id' => $id]);

            return Response::success(['id' => $id, 'name' => $name], 'Group updated');
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return Response::error('A group with that name already exists', 409);
            }
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a group (cascades members and assignments).
     */
    public function deleteGroup(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        if (!$id) {
            return Response::error('Group ID is required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            // Check group exists
            $stmt = $db->prepare("SELECT name FROM emailAddons_groups WHERE id = ?");
            $stmt->execute([$id]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$group) {
                return Response::notFound('Group not found');
            }

            // Delete assignments for this group
            $db->prepare("DELETE FROM emailAddons_assignments WHERE target_type = 'group' AND target_id = ?")->execute([(string) $id]);
            // Delete group (members cascade via FK)
            $db->prepare("DELETE FROM emailAddons_groups WHERE id = ?")->execute([$id]);

            $this->logAction('emailAddons.group.delete', "group:{$group['name']}", 'success', ['group_id' => $id]);

            return Response::success(null, "Group '{$group['name']}' deleted");
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ─── Group Members ──────────────────────────────────────────────────

    /**
     * Add member(s) to a group.
     * Body: { "emails": ["user@example.com", ...] }
     */
    public function addMembers(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $emails = $request->input('emails', []);

        if (!$id) {
            return Response::error('Group ID is required', 400);
        }
        if (empty($emails) || !is_array($emails)) {
            return Response::error('At least one email is required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            // Check group exists
            $stmt = $db->prepare("SELECT id FROM emailAddons_groups WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return Response::notFound('Group not found');
            }

            $actor = $this->getActor();
            $added = 0;
            $stmtMember = $db->prepare("
                INSERT IGNORE INTO emailAddons_group_members (group_id, email, added_by)
                VALUES (?, ?, ?)
            ");

            foreach ($emails as $email) {
                $email = trim(strtolower($email));
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmtMember->execute([$id, $email, $actor]);
                    $added += $stmtMember->rowCount();
                }
            }

            return Response::success(['added' => $added], "{$added} member(s) added");
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove a member from a group.
     */
    public function removeMember(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $email = $request->getParam('email');

        if (!$id || !$email) {
            return Response::error('Group ID and email are required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM emailAddons_group_members WHERE group_id = ? AND email = ?");
            $stmt->execute([$id, $email]);

            if ($stmt->rowCount() === 0) {
                return Response::notFound('Member not found in group');
            }

            return Response::success(null, 'Member removed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ─── Addon Assignments ──────────────────────────────────────────────

    /**
     * List all assignments (per-user and per-group overrides).
     */
    public function listAssignments(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            $stmt = $db->prepare("
                SELECT a.*, 
                    CASE WHEN a.target_type = 'group' THEN g.name ELSE a.target_id END AS target_label
                FROM emailAddons_assignments a
                LEFT JOIN emailAddons_groups g ON a.target_type = 'group' AND g.id = CAST(a.target_id AS UNSIGNED)
                ORDER BY a.addon_slug, a.target_type, a.target_id
            ");
            $stmt->execute();
            $assignments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($assignments as &$a) {
                $a['enabled'] = (bool) $a['enabled'];
                $a['id'] = (int) $a['id'];
            }
            unset($a);

            return Response::success([
                'assignments' => $assignments,
                'count' => count($assignments),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Set an addon override for a user or group.
     * Body: { "addon_slug": "...", "target_type": "user"|"group", "target_id": "email|group_id", "enabled": true|false }
     */
    public function setAssignment(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $addonSlug = trim($request->input('addon_slug', ''));
        $targetType = $request->input('target_type', '');
        $targetId = trim($request->input('target_id', ''));
        $enabled = (bool) $request->input('enabled', true);

        if (empty($addonSlug) || !in_array($targetType, ['user', 'group']) || empty($targetId)) {
            return Response::error('addon_slug, target_type (user|group), and target_id are required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            $actor = $this->getActor();

            $stmt = $db->prepare("
                INSERT INTO emailAddons_assignments (addon_slug, target_type, target_id, enabled, assigned_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), assigned_by = VALUES(assigned_by), updated_at = NOW()
            ");
            $stmt->execute([$addonSlug, $targetType, $targetId, $enabled ? 1 : 0, $actor]);

            // Invalidate addon status caches (global + per-user)
            $this->cache->delete('addon_status');
            $this->cache->deletePattern('addon_status:*');
            $this->notifyEmailApp();

            $this->logAction('emailAddons.assign', "{$targetType}:{$targetId}", 'success', [
                'addon' => $addonSlug,
                'enabled' => $enabled,
            ]);

            return Response::success(null, 'Assignment saved');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove an addon override for a user or group.
     * Body: { "addon_slug": "...", "target_type": "user"|"group", "target_id": "..." }
     */
    public function removeAssignment(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $addonSlug = trim($request->input('addon_slug', ''));
        $targetType = $request->input('target_type', '');
        $targetId = trim($request->input('target_id', ''));

        if (empty($addonSlug) || !in_array($targetType, ['user', 'group']) || empty($targetId)) {
            return Response::error('addon_slug, target_type, and target_id are required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("
                DELETE FROM emailAddons_assignments
                WHERE addon_slug = ? AND target_type = ? AND target_id = ?
            ");
            $stmt->execute([$addonSlug, $targetType, $targetId]);

            $this->cache->delete('addon_status');
            $this->cache->deletePattern('addon_status:*');
            $this->notifyEmailApp();

            return Response::success(null, 'Assignment removed');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resolve effective addon statuses for a specific email address.
     * Priority: user override > group override (most permissive) > global
     * Query: ?email=user@example.com
     */
    public function resolveForUser(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $email = trim($request->getQuery('email', ''));
        if (empty($email)) {
            return Response::error('email query parameter is required', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTables($db);

            $resolved = $this->resolveAddonsForEmail($db, $email);

            return Response::success([
                'email' => $email,
                'addons' => $resolved,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ─── Internal helpers ───────────────────────────────────────────────

    /**
     * Resolve effective addon statuses for an email.
     * Priority: user override > group override (most permissive) > global toggle.
     */
    private function resolveAddonsForEmail(\PDO $db, string $email): array
    {
        // 1. Get global addon states
        $stmt = $db->prepare("SELECT slug, enabled FROM panel_addons");
        $stmt->execute();
        $globals = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $globals[$row['slug']] = (bool) $row['enabled'];
        }

        // 2. Get group overrides (user's groups)
        $stmt = $db->prepare("
            SELECT a.addon_slug, a.enabled
            FROM emailAddons_assignments a
            JOIN emailAddons_group_members gm ON a.target_type = 'group' AND a.target_id = CAST(gm.group_id AS CHAR)
            WHERE gm.email = ?
        ");
        $stmt->execute([$email]);
        $groupOverrides = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Most permissive: if ANY group enables it, it's enabled
            if (!isset($groupOverrides[$row['addon_slug']]) || (bool) $row['enabled']) {
                $groupOverrides[$row['addon_slug']] = (bool) $row['enabled'];
            }
        }

        // 3. Get user overrides
        $stmt = $db->prepare("
            SELECT addon_slug, enabled
            FROM emailAddons_assignments
            WHERE target_type = 'user' AND target_id = ?
        ");
        $stmt->execute([$email]);
        $userOverrides = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $userOverrides[$row['addon_slug']] = (bool) $row['enabled'];
        }

        // 4. Merge: user > group > global
        $result = [];
        foreach ($globals as $slug => $globalEnabled) {
            if (isset($userOverrides[$slug])) {
                $result[$slug] = [
                    'enabled' => $userOverrides[$slug],
                    'source' => 'user',
                ];
            } elseif (isset($groupOverrides[$slug])) {
                $result[$slug] = [
                    'enabled' => $groupOverrides[$slug],
                    'source' => 'group',
                ];
            } else {
                $result[$slug] = [
                    'enabled' => $globalEnabled,
                    'source' => 'global',
                ];
            }
        }

        return $result;
    }

    /**
     * Notify the Email App to invalidate its addon cache.
     */
    private function notifyEmailApp(): void
    {
        try {
            $emailAppUrl = $this->container->getConfig('email_app.api_url', '');
            $emailAppKey = $this->container->getConfig('email_app.api_key', '');

            if (empty($emailAppUrl) || empty($emailAppKey)) {
                return;
            }

            $url = rtrim($emailAppUrl, '/') . '/addons/invalidate';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => [
                    'X-Api-Key: ' . $emailAppKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // Fire-and-forget
        }
    }

    /**
     * Auto-create tables if they don't exist.
     */
    private function ensureTables(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS emailAddons_groups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                color VARCHAR(20) DEFAULT '#6366f1',
                icon VARCHAR(50) DEFAULT 'group',
                created_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS emailAddons_group_members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                group_id INT UNSIGNED NOT NULL,
                email VARCHAR(255) NOT NULL,
                added_by VARCHAR(255) NOT NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_group_email (group_id, email),
                INDEX idx_email (email),
                FOREIGN KEY (group_id) REFERENCES emailAddons_groups(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS emailAddons_assignments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                addon_slug VARCHAR(50) NOT NULL,
                target_type ENUM('user', 'group') NOT NULL,
                target_id VARCHAR(255) NOT NULL COMMENT 'email for user, group_id for group',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                assigned_by VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_assignment (addon_slug, target_type, target_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

