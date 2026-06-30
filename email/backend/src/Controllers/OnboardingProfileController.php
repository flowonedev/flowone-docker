<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\AddonService;

/**
 * OnboardingProfileController
 *
 * Receives the user's onboarding answers (work mode, role, explicit addon map)
 * and pushes the addon overrides to the Panel via AddonService.
 * Persists the raw profile inside the user's settings JSON file.
 */
class OnboardingProfileController extends BaseController
{
    private string $settingsDir = '/var/www/vps-email/data/settings';

    private const VALID_ADDON_SLUGS = [
        'calendar', 'time_tracker', 'tasks', 'email_tracking', 'reactions',
        'universal_search', 'kanban_boards', 'team', 'project_hub', 'board_pro',
        'crm_pro', 'automation_hub', 'chat', 'moodboards', 'email_marketing',
        'ai_assistant', 'news_reader',
    ];

    /**
     * Save onboarding profile and push addon preferences to Panel.
     *
     * Body: {
     *   "work_mode": "solo"|"team",
     *   "role": "admin"|"project_manager"|"business_owner"|"team_member"|null,
     *   "addons": { "kanban_boards": true, "crm_pro": false, ... }
     * }
     */
    public function save(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) {
            return Response::error('User not found', 401);
        }

        $workMode = $request->input('work_mode', '');
        if (!in_array($workMode, ['solo', 'team'], true)) {
            return Response::error('work_mode must be "solo" or "team"', 400);
        }

        $role = $request->input('role');
        if ($workMode === 'team' && !in_array($role, ['admin', 'project_manager', 'business_owner', 'team_member'], true)) {
            return Response::error('role is required for team mode', 400);
        }

        $userAddons = $request->input('addons', []);
        if (empty($userAddons) || !is_array($userAddons)) {
            return Response::error('addons map is required', 400);
        }

        // Always-on addons + user-selected ones
        $addons = [
            'calendar'         => true,
            'time_tracker'     => true,
            'tasks'            => true,
            'email_tracking'   => true,
            'reactions'        => true,
            'universal_search' => true,
            'ai_assistant'     => true,
        ];

        // Apply user selections for toggleable addons
        $toggleable = [
            'kanban_boards', 'board_pro', 'project_hub', 'crm_pro',
            'chat', 'team', 'automation_hub', 'email_marketing', 'moodboards',
            'news_reader',
        ];
        foreach ($toggleable as $slug) {
            $addons[$slug] = !empty($userAddons[$slug]);
        }

        // Derive perspective
        $isTeam = $workMode === 'team';
        $perspective = 'operations';
        if ($isTeam && in_array($role, ['business_owner', 'admin']) && !empty($addons['crm_pro'])) {
            $perspective = 'executive';
        } elseif ($isTeam) {
            $perspective = 'delivery';
        }

        // Push addon overrides to Panel
        $addonService = new AddonService($this->config, $email);
        $panelSuccess = $addonService->setUserAddons($email, $addons);

        if ($panelSuccess) {
            $addonService->refreshStatus();
        }

        $profile = [
            'work_mode'    => $workMode,
            'role'         => $role,
            'addons'       => $addons,
            'perspective'  => $perspective,
            'completed_at' => date('c'),
        ];

        $this->saveProfileToSettings($email, $profile);

        return Response::success([
            'profile'      => $profile,
            'perspective'  => $perspective,
            'addons'       => $addons,
            'panel_synced' => $panelSuccess,
        ], 'Onboarding profile saved');
    }

    /**
     * Get the saved onboarding profile for pre-filling the wizard.
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getUser($request);
        if (!$email) {
            return Response::error('User not found', 401);
        }

        $settings = $this->loadSettings($email);
        $profile = $settings['onboarding_profile'] ?? null;

        return Response::success(['profile' => $profile]);
    }

    private function getSettingsPath(string $email): string
    {
        return $this->settingsDir . '/' . md5(strtolower($email)) . '.json';
    }

    private function loadSettings(string $email): array
    {
        $file = $this->getSettingsPath($email);
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function saveProfileToSettings(string $email, array $profile): void
    {
        $file = $this->getSettingsPath($email);
        $settings = $this->loadSettings($email);
        $settings['onboarding_profile'] = $profile;

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($file, $json);
        }
    }
}
