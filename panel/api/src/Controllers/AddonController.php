<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * AddonController - Manages toggleable feature addons
 * 
 * Addons are optional feature sets that can be enabled/disabled from the Panel.
 * External services (like the Email App) query addon status via API key auth.
 */
class AddonController extends BaseController
{
    /**
     * List all addons with their current status
     * Auth: JWT (admin)
     */
    public function list(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $db = $this->container->getDatabase();
            $this->ensureTable($db);

            $stmt = $db->prepare("SELECT * FROM panel_addons ORDER BY name ASC");
            $stmt->execute();
            $addons = $stmt->fetchAll();

            return Response::success([
                'addons' => $addons,
                'count' => count($addons),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Toggle an addon on or off
     * Auth: JWT (admin)
     */
    public function toggle(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        try {
            $slug = $request->getParam('slug');
            if (empty($slug)) {
                return Response::error('Addon slug is required', 400);
            }

            $db = $this->container->getDatabase();
            $this->ensureTable($db);

            // Get current state
            $stmt = $db->prepare("SELECT * FROM panel_addons WHERE slug = ?");
            $stmt->execute([$slug]);
            $addon = $stmt->fetch();

            if (!$addon) {
                return Response::notFound('Addon not found: ' . $slug);
            }

            $newState = $addon['enabled'] ? 0 : 1;
            $user = $this->getCurrentUser();
            $actor = $user ? $user->username : 'admin';

            $stmt = $db->prepare("
                UPDATE panel_addons 
                SET enabled = ?, 
                    enabled_at = IF(? = 1, NOW(), enabled_at),
                    enabled_by = IF(? = 1, ?, enabled_by),
                    updated_at = NOW()
                WHERE slug = ?
            ");
            $stmt->execute([$newState, $newState, $newState, $actor, $slug]);

            // Clear cache (global + all per-user caches)
            $this->cache->delete('addon_status');
            $this->cache->deletePattern('addon_status:*');

            // Log the action (non-blocking — audit failure must not break toggle)
            try {
                $this->logAction(
                    'addon.toggle',
                    "addon:{$slug}",
                    'success',
                    ['addon' => $slug, 'new_state' => $newState ? 'enabled' : 'disabled']
                );
            } catch (\Throwable $logErr) {
                // Silently ignore audit log failures
            }

            // Notify Email App to invalidate its addon cache (fire-and-forget)
            $this->notifyEmailApp();

            // Fetch updated addon
            $stmt = $db->prepare("SELECT * FROM panel_addons WHERE slug = ?");
            $stmt->execute([$slug]);
            $addon = $stmt->fetch();

            return Response::success([
                'addon' => $addon,
            ], $newState ? 'Addon enabled' : 'Addon disabled');

        } catch (\Throwable $e) {
            error_log("AddonController::toggle error: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Response::error('Toggle failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * External API endpoint - returns enabled addon statuses
     * Auth: API Key (X-Api-Key header)
     * Called by Email App to check which addons are enabled
     *
     * Supports optional ?email= query param to resolve per-user/group overrides.
     * Priority: user override > group override (most permissive) > global toggle.
     */
    public function status(Request $request): Response
    {
        // Validate API key (timing-safe comparison)
        $apiKey = $request->getHeader('X-Api-Key') ?? $request->getQuery('api_key');
        $validKeys = $this->container->getConfig('external_api.keys', []);

        $keyValid = false;
        if ($apiKey) {
            foreach ($validKeys as $name => $validKey) {
                if (!empty($validKey) && hash_equals((string) $validKey, (string) $apiKey)) {
                    $keyValid = true;
                    break;
                }
            }
        }
        if (!$keyValid) {
            return Response::unauthorized('Invalid or missing API key');
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTable($db);

            $email = trim($request->getQuery('email', ''));

            // If no email, return global statuses (cached)
            if (empty($email)) {
                $cached = $this->cache->get('addon_status');
                if ($cached !== null) {
                    return Response::success($cached);
                }

                $stmt = $db->prepare("SELECT slug, enabled FROM panel_addons");
                $stmt->execute();
                $rows = $stmt->fetchAll();

                $statuses = [];
                foreach ($rows as $row) {
                    $statuses[$row['slug']] = (bool)$row['enabled'];
                }

                $this->cache->set('addon_status', $statuses, 300);
                return Response::success($statuses);
            }

            // Per-user resolution: user override > group override > global
            $cacheKey = "addon_status:{$email}";
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached);
            }

            // 1. Global
            $stmt = $db->prepare("SELECT slug, enabled FROM panel_addons");
            $stmt->execute();
            $globals = [];
            foreach ($stmt->fetchAll() as $row) {
                $globals[$row['slug']] = (bool)$row['enabled'];
            }

            // 2. Group overrides (most permissive wins)
            $groupOverrides = [];
            try {
                $stmt = $db->prepare("
                    SELECT a.addon_slug, a.enabled
                    FROM emailAddons_assignments a
                    JOIN emailAddons_group_members gm ON a.target_type = 'group' AND a.target_id = CAST(gm.group_id AS CHAR)
                    WHERE gm.email = ?
                ");
                $stmt->execute([$email]);
                foreach ($stmt->fetchAll() as $row) {
                    if (!isset($groupOverrides[$row['addon_slug']]) || (bool)$row['enabled']) {
                        $groupOverrides[$row['addon_slug']] = (bool)$row['enabled'];
                    }
                }
            } catch (\PDOException $e) {
                // Tables may not exist yet — ignore
            }

            // 3. User overrides
            $userOverrides = [];
            try {
                $stmt = $db->prepare("
                    SELECT addon_slug, enabled
                    FROM emailAddons_assignments
                    WHERE target_type = 'user' AND target_id = ?
                ");
                $stmt->execute([$email]);
                foreach ($stmt->fetchAll() as $row) {
                    $userOverrides[$row['addon_slug']] = (bool)$row['enabled'];
                }
            } catch (\PDOException $e) {
                // Tables may not exist yet — ignore
            }

            // 4. Merge
            $resolved = [];
            foreach ($globals as $slug => $globalEnabled) {
                if (isset($userOverrides[$slug])) {
                    $resolved[$slug] = $userOverrides[$slug];
                } elseif (isset($groupOverrides[$slug])) {
                    $resolved[$slug] = $groupOverrides[$slug];
                } else {
                    $resolved[$slug] = $globalEnabled;
                }
            }

            $this->cache->set($cacheKey, $resolved, 120); // Shorter cache for user-specific
            return Response::success($resolved);

        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bulk-set per-user addon overrides from the Email App's onboarding wizard.
     * Auth: API Key (X-Api-Key header) — service-to-service only.
     * Body: { "email": "user@...", "addons": { "project_hub": true, "board_pro": false, ... } }
     */
    public function onboardingAssign(Request $request): Response
    {
        $apiKey = $request->getHeader('X-Api-Key') ?? $request->getQuery('api_key');
        $validKeys = $this->container->getConfig('external_api.keys', []);

        $keyValid = false;
        if ($apiKey) {
            foreach ($validKeys as $name => $validKey) {
                if (!empty($validKey) && hash_equals((string) $validKey, (string) $apiKey)) {
                    $keyValid = true;
                    break;
                }
            }
        }
        if (!$keyValid) {
            return Response::unauthorized('Invalid or missing API key');
        }

        $email = trim($request->input('email', ''));
        $addons = $request->input('addons', []);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email is required', 400);
        }
        if (empty($addons) || !is_array($addons)) {
            return Response::error('addons map is required (slug => bool)', 400);
        }

        try {
            $db = $this->container->getDatabase();
            $this->ensureTable($db);

            $this->ensureAssignmentsTable($db);

            $stmt = $db->prepare("
                INSERT INTO emailAddons_assignments (addon_slug, target_type, target_id, enabled, assigned_by)
                VALUES (?, 'user', ?, ?, 'onboarding')
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), assigned_by = 'onboarding', updated_at = NOW()
            ");

            $applied = 0;
            $validSlugs = $db->query("SELECT slug FROM panel_addons")->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($addons as $slug => $enabled) {
                if (!in_array($slug, $validSlugs, true)) continue;
                $stmt->execute([$slug, $email, $enabled ? 1 : 0]);
                $applied++;
            }

            $this->cache->delete('addon_status');
            $this->cache->deletePattern('addon_status:*');
            $this->notifyEmailApp();

            return Response::success([
                'email' => $email,
                'applied' => $applied,
            ], 'Onboarding addon preferences applied');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Ensure emailAddons_assignments table exists (same schema as EmailAddonsController).
     */
    private function ensureAssignmentsTable(\PDO $db): void
    {
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

    /**
     * Notify the Email App to invalidate its addon cache.
     * Fire-and-forget: failures are logged but never break the toggle flow.
     */
    private function notifyEmailApp(): void
    {
        try {
            $emailAppUrl = $this->container->getConfig('email_app.api_url', '');
            $emailAppKey = $this->container->getConfig('email_app.api_key', '');

            if (empty($emailAppUrl) || empty($emailAppKey)) {
                return; // Email App integration not configured — skip silently
            }

            $url = rtrim($emailAppUrl, '/') . '/addons/invalidate';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,        // Don't block toggle for more than 3s
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER => [
                    'X-Api-Key: ' . $emailAppKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                error_log("AddonController::notifyEmailApp: Failed (HTTP {$httpCode}): {$error}");
            }
        } catch (\Throwable $e) {
            error_log("AddonController::notifyEmailApp: " . $e->getMessage());
        }
    }

    /**
     * Ensure the panel_addons table exists and seed default data
     */
    private function ensureTable(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS panel_addons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                icon VARCHAR(50) DEFAULT 'extension',
                enabled TINYINT(1) DEFAULT 0,
                enabled_at DATETIME DEFAULT NULL,
                enabled_by VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default addons if they don't exist
        $defaults = [
            [
                'slug' => 'crm_pro',
                'name' => 'CRM Pro & Client Portal',
                'description' => 'Extended CRM with client portal, document signing, invoicing, pipeline, tags, reminders, unified timeline, and reporting dashboard.',
                'icon' => 'business_center',
            ],
                [
                    'slug' => 'moodboards',
                    'name' => 'Mood Boards',
                    'description' => 'Creative canvas with drag-and-drop design boards, image sets, color palettes, connections, sharing, and client linking.',
                    'icon' => 'dashboard_customize',
                ],
                [
                    'slug' => 'kanban_boards',
                    'name' => 'Kanban Boards',
                    'description' => 'Project management with Kanban boards, lists, cards, labels, checklists, attachments, email linking, progress reports, and financials.',
                    'icon' => 'view_kanban',
                ],
                [
                    'slug' => 'chat',
                    'name' => 'Chat & Calls',
                    'description' => 'Real-time messaging with DMs, group chats, channels, voice/video calls, huddles, threads, webhooks, file sharing, and scheduled messages.',
                    'icon' => 'chat',
                ],
                [
                    'slug' => 'email_marketing',
                    'name' => 'Email Marketing',
                    'description' => 'Mailing lists management and email campaigns with bulk sending, rate limiting, progress tracking, pause/resume, and retry capabilities.',
                    'icon' => 'campaign',
                ],
                [
                    'slug' => 'team',
                    'name' => 'Team Management',
                    'description' => 'Organization team management with colleagues, groups, sick status tracking, folder/board/calendar sharing, and team collaboration.',
                    'icon' => 'diversity_3',
                ],
                [
                    'slug' => 'calendar',
                    'name' => 'Calendar',
                    'description' => 'Full calendar with events, invitations, participants, Google & Microsoft sync, calendar sharing, meetings, subscriptions, and connections.',
                    'icon' => 'calendar_month',
                ],
                [
                    'slug' => 'tasks',
                    'name' => 'My Tasks',
                    'description' => 'Personal task management with priorities, subtasks, email-to-task conversion, and board card conversion.',
                    'icon' => 'task_alt',
                ],
                [
                    'slug' => 'email_tracking',
                    'name' => 'Email Tracking',
                    'description' => 'Read receipt tracking with pixel insertion, open notifications, read time analytics, and per-recipient tracking.',
                    'icon' => 'mark_email_read',
                ],
                [
                    'slug' => 'time_tracker',
                    'name' => 'Time Tracker',
                    'description' => 'Automatic and manual time tracking per client, activity breakdowns, email compose/read time, and billable hour reports.',
                    'icon' => 'timer',
                ],
                [
                    'slug' => 'reactions',
                    'name' => 'Reactions',
                    'description' => 'Outlook-style emoji reactions on emails, reaction display badges, incoming reaction detection, and reaction notifications.',
                    'icon' => 'add_reaction',
                ],
                [
                    'slug' => 'ai_assistant',
                    'name' => 'AI Assistant',
                    'description' => 'AI-powered email summaries, text rewriting, draft reply generation, and AI usage analytics.',
                    'icon' => 'auto_awesome',
                ],
                [
                    'slug' => 'board_pro',
                    'name' => 'Board Pro',
                    'description' => 'Advanced board features: email-native cards, financial layer, automations, multi-lens views, AI intelligence, executive reports, and moodboard integration.',
                    'icon' => 'view_kanban',
                    'default_enabled' => false,
                ],
                [
                    'slug' => 'project_hub',
                    'name' => 'Project Hub',
                    'description' => 'Command center for project management: Spaces, Folders, multi-assignee tracking, task dependencies, workload planner, team presence, enhanced comments, and subtasks. Upgrades the Boards experience.',
                    'icon' => 'hub',
                    'default_enabled' => false,
                ],
                [
                    'slug' => 'automation_hub',
                    'name' => 'Automation Hub',
                    'description' => 'Visual workflow automation engine with triggers, actions, conditions, server monitoring, Telegram bot integration, and scheduled tasks.',
                    'icon' => 'settings_suggest',
                    'default_enabled' => false,
                ],
                [
                    'slug' => 'universal_search',
                    'name' => 'Universal Search',
                    'description' => 'Super Search across emails, attachments, drive files, boards, cards, todos, clients, calendar events, chat messages, and moodboards with AI-powered answers, Meilisearch integration, and automatic background indexing.',
                    'icon' => 'search',
                ],
                [
                    'slug' => 'news_reader',
                    'name' => 'News Reader',
                    'description' => 'Flipboard-style RSS reader with a collapsible bottom ticker (right-to-left marquee), curated HU/EN/US feed catalog, custom feed URLs, in-app fullscreen reader, sandboxed iframe, and per-user unread tracking.',
                    'icon' => 'newspaper',
                    'default_enabled' => false,
                ],
            ];

        foreach ($defaults as $addon) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM panel_addons WHERE slug = ?");
            $stmt->execute([$addon['slug']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $defaultEnabled = ($addon['default_enabled'] ?? true) ? 1 : 0;
                $stmt = $db->prepare("
                    INSERT INTO panel_addons (slug, name, description, icon, enabled)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$addon['slug'], $addon['name'], $addon['description'], $addon['icon'], $defaultEnabled]);
            }
        }
    }
}

