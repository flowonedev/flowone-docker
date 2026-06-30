<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\AIAssistant\Services\AIService;
use Webmail\Services\RedisCacheService;
use Webmail\Services\SieveSyncService;
use Webmail\Services\TrustedSenderSync;

class SettingsController extends BaseController
{
    private string $settingsDir = '/var/www/vps-email/data/settings';
    private string $globalSettingsDir = '/var/www/vps-email/data/global';

    /**
     * Get the active account email (primary or secondary)
     */
    protected function getActiveEmail(): string
    {
        $accountId = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? null;
        
        error_log("SettingsController::getActiveEmail - X-Account-ID header: " . ($accountId ?? 'null') . ", userEmail: " . $this->userEmail);
        
        if ($accountId && $accountId !== 'primary') {
            // Get secondary account email
            $accountEmail = $this->getSecondaryAccountEmail((int)$accountId);
            error_log("SettingsController::getActiveEmail - Secondary account lookup for ID $accountId: " . ($accountEmail ?? 'null'));
            if ($accountEmail) {
                return $accountEmail;
            }
        }
        
        return $this->userEmail;
    }


    /**
     * Get user settings
     * GET /settings
     */
    public function get(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $activeEmail = $this->getActiveEmail();
        $settings = $this->loadSettings($activeEmail);

        return Response::success([
            'settings' => $settings,
            'account_email' => $activeEmail,
        ]);
    }

    /**
     * Update user settings
     * PUT /settings
     */
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $activeEmail = $this->getActiveEmail();
        $current = $this->loadSettings($activeEmail);
        
        // Allowed settings to update
        $allowedKeys = [
            'display_name', 'signature', 'messages_per_page', 'theme', 'accent_color', 
            'layout_mode', 'display_density', 'perspective', 'setup_completed', 'auto_mark_read', 'confirm_delete', 'folder_order',
            'refresh_interval', 'large_attachment_threshold', 'block_remote_images', 'override_email_styling', 'ambient_background',
            // AI settings
            'ai_model', 'ai_writing_style', 'ai_prompt_summarize', 'ai_prompt_rewrite', 'ai_prompt_draft_reply',
            // Out of Office settings
            'ooo_enabled', 'ooo_subject', 'ooo_message', 'ooo_start_date', 'ooo_end_date',
            // Undo send delay
            'undo_send_delay',
            // Compose style
            'compose_style',
            // Mentions (Phase 3)
            //   auto_add_mentions_to_recipients — when ON, picking a person
            //     from the @-popup also adds them to To: (Outlook default).
            //   notify_on_mention — when ON, getting @-mentioned creates an
            //     in-app notification (with dedup by Message-ID).
            'auto_add_mentions_to_recipients', 'notify_on_mention',
            // News Reader — Markets panel basket selection. Arrays of
            // ticker symbols / CoinGecko ids; validated server-side
            // against the MarketsService allow-list before being used.
            'news_markets_stocks', 'news_markets_crypto',
        ];
        
        foreach ($allowedKeys as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                $current[$key] = $value;
            }
        }
        
        // Handle AI API key separately (needs encryption)
        $aiApiKey = $request->input('ai_api_key');
        if ($aiApiKey !== null) {
            if (empty($aiApiKey)) {
                // Clear API key
                unset($current['ai_api_key_encrypted']);
            } else if ($aiApiKey !== '********') {
                // Only update if not masked value
                $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
                $current['ai_api_key_encrypted'] = AIService::encryptApiKey($aiApiKey, $secret);
            }
        }

        // Validate messages_per_page
        if (isset($current['messages_per_page'])) {
            $current['messages_per_page'] = max(10, min(100, (int)$current['messages_per_page']));
        }

        // Validate theme
        if (isset($current['theme']) && !in_array($current['theme'], ['light', 'dark', 'system'])) {
            $current['theme'] = 'system';
        }

        // Validate perspective
        if (isset($current['perspective']) && !in_array($current['perspective'], ['executive', 'delivery', 'operations'])) {
            $current['perspective'] = 'operations';
        }

        // Validate undo_send_delay (allowed: 0, 10, 20, 30, 60)
        if (isset($current['undo_send_delay'])) {
            $allowed = [0, 10, 20, 30, 60];
            $val = (int)$current['undo_send_delay'];
            $current['undo_send_delay'] = in_array($val, $allowed, true) ? $val : 0;
        }

        // Validate Markets baskets — keep only string entries, strip
        // empties, and cap length defensively. Final allow-list check
        // happens in MarketsService when the basket is actually used,
        // so we don't have to import that class here.
        foreach (['news_markets_stocks', 'news_markets_crypto'] as $marketKey) {
            if (isset($current[$marketKey])) {
                $arr = $current[$marketKey];
                if (!is_array($arr)) {
                    unset($current[$marketKey]);
                    continue;
                }
                $clean = [];
                foreach ($arr as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $clean[] = trim($v);
                    }
                }
                $current[$marketKey] = array_values(array_unique(array_slice($clean, 0, 12)));
            }
        }

        $saved = $this->saveSettings($activeEmail, $current);
        
        if (!$saved) {
            return Response::error('Failed to save settings to disk', 500);
        }
        
        // Sync OOO settings to Dovecot mail_accounts table for server-side vacation auto-reply
        $this->syncOooToDovecot($activeEmail, $current);
        
        // Publish real-time settings change event for cross-device sync
        // Only publish visual/UI settings that should sync instantly
        $syncableSettings = ['theme', 'accent_color', 'display_density', 'layout_mode', 'perspective', 'ambient_background'];
        $changedSettings = [];
        foreach ($syncableSettings as $key) {
            $value = $request->input($key);
            if ($value !== null) {
                $changedSettings[$key] = $value;
            }
        }
        
        if (!empty($changedSettings)) {
            try {
                $cache = new RedisCacheService($this->config);
                $cache->publishSettingsChanged($activeEmail, $changedSettings);
            } catch (\Exception $e) {
                error_log('[SettingsController] Failed to publish settings change: ' . $e->getMessage());
            }
        }

        BootstrapController::invalidateCache($this->config, $activeEmail);

        return Response::success([
            'settings' => $current,
            'account_email' => $activeEmail,
        ], 'Settings updated');
    }

    /**
     * Change password
     * PUT /settings/password
     */
    public function changePassword(Request $request): Response
    {
        $imapError = $this->requireImap($request);
        if ($imapError) {
            return $imapError;
        }

        $email = strtolower($this->userEmail);

        // Is the user currently *required* to change their password (migrated
        // mailbox / admin flag)? If so we relax the current-password check — the
        // forced modal only collects the new password (twice) and the user has
        // already authenticated this session.
        $forced = false;
        $db = null;
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            $stmt = $db->prepare("SELECT force_password_change FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
            $stmt->execute([$email]);
            $val = $stmt->fetchColumn();
            $forced = $val !== false && (int) $val === 1;
        } catch (\Throwable $e) {
            $forced = false;
        }

        $newPassword = $request->input('new_password');
        if ($newPassword === null || $newPassword === '') {
            return Response::error('new_password is required', 400);
        }

        if (!$forced) {
            $currentPassword = $request->input('current_password');
            if ($currentPassword === null || $currentPassword === '') {
                return Response::error('current_password is required', 400);
            }
            // Verify current password matches
            if ($currentPassword !== $this->userPassword) {
                return Response::error('Current password is incorrect', 400);
            }
        }

        // Validate new password: min 12 chars + complexity (uppercase, lowercase, digit, special)
        if (strlen($newPassword) < 12) {
            return Response::error('New password must be at least 12 characters', 400);
        }
        if (!preg_match('/[A-Z]/', $newPassword)) {
            return Response::error('New password must contain at least one uppercase letter', 400);
        }
        if (!preg_match('/[a-z]/', $newPassword)) {
            return Response::error('New password must contain at least one lowercase letter', 400);
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            return Response::error('New password must contain at least one digit', 400);
        }
        if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            return Response::error('New password must contain at least one special character', 400);
        }

        // Generate a Dovecot-compatible, scheme-prefixed password hash.
        $hash = $this->generatePasswordHash($newPassword);
        if ($hash === '') {
            return Response::error('Failed to generate password hash', 500);
        }

        // Write straight to the shared mail_accounts table (Dovecot reads from
        // here) and clear the force-change flag in the same statement. Falls
        // back to a hash-only update if the flag column hasn't been added yet.
        try {
            if ($db === null) {
                $db = \Webmail\Core\Database::getConnection($this->config);
            }

            try {
                $stmt = $db->prepare("UPDATE mail_accounts SET password_hash = ?, force_password_change = 0, updated_at = NOW() WHERE LOWER(email) = ?");
                $stmt->execute([$hash, $email]);
            } catch (\Throwable $colError) {
                // Older DB without the flag column — update the hash only.
                $stmt = $db->prepare("UPDATE mail_accounts SET password_hash = ?, updated_at = NOW() WHERE LOWER(email) = ?");
                $stmt->execute([$hash, $email]);
            }

            if ($stmt->rowCount() === 0) {
                $check = $db->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ? LIMIT 1");
                $check->execute([$email]);
                if (!$check->fetch()) {
                    return Response::error('Mail account not found', 404);
                }
            }
        } catch (\Throwable $e) {
            error_log('changePassword DB update failed: ' . $e->getMessage());
            return Response::error('Failed to update password', 500);
        }

        return Response::success([
            'force_password_change' => false,
        ], 'Password changed successfully');
    }

    /**
     * Generate a Dovecot-compatible, scheme-prefixed password hash.
     * Prefers `doveadm pw` (matches accounts created by the panel agent); falls
     * back to PHP crypt() with an explicit {SHA512-CRYPT} prefix.
     */
    private function generatePasswordHash(string $password): string
    {
        $doveadm = $this->resolveDoveadm();
        if ($doveadm !== '') {
            foreach (['SHA512-CRYPT', 'BLF-CRYPT'] as $scheme) {
                $out = trim((string) shell_exec($doveadm . ' pw -s ' . $scheme . ' -p ' . escapeshellarg($password) . ' 2>/dev/null'));
                if ($out !== '') {
                    return $out;
                }
            }
        }

        // Pure-PHP fallback so password change still works when doveadm is not
        // reachable from the web SAPI's restricted PATH.
        $salt = '$6$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$';
        $crypt = crypt($password, $salt);
        return (is_string($crypt) && strlen($crypt) > 13) ? '{SHA512-CRYPT}' . $crypt : '';
    }

    /**
     * Resolve an absolute path to the doveadm binary, or '' if not found.
     */
    private function resolveDoveadm(): string
    {
        $which = trim((string) shell_exec('command -v doveadm 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }
        foreach (['/usr/bin/doveadm', '/usr/local/bin/doveadm', '/usr/sbin/doveadm'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        return '';
    }

    /**
     * Load settings for user
     */
    private function loadSettings(string $email): array
    {
        $file = $this->getSettingsPath($email);
        error_log("loadSettings: File path: $file, exists: " . (file_exists($file) ? 'yes' : 'no'));
        
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $settings = json_decode($content, true);
            if (is_array($settings)) {
                error_log("loadSettings: Loaded settings keys: " . implode(', ', array_keys($settings)));
                return array_merge($this->getDefaults(), $settings);
            }
        }

        error_log("loadSettings: Using defaults");
        return $this->getDefaults();
    }

    /**
     * Save settings for user
     */
    private function saveSettings(string $email, array $settings): bool
    {
        $dir = $this->settingsDir;
        if (!is_dir($dir)) {
            error_log("saveSettings: Creating directory: $dir");
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create settings directory: $dir");
                return false;
            }
        }

        $file = $this->getSettingsPath($email);
        error_log("saveSettings: Saving to file: $file");
        error_log("saveSettings: Keys being saved: " . implode(', ', array_keys($settings)));
        
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log("Failed to encode settings to JSON: " . json_last_error_msg());
            return false;
        }
        
        $result = file_put_contents($file, $json);
        if ($result === false) {
            error_log("Failed to write settings file: $file");
            return false;
        }
        
        error_log("saveSettings: Successfully wrote " . strlen($json) . " bytes");
        return true;
    }

    /**
     * Get settings file path
     */
    private function getSettingsPath(string $email): string
    {
        $normalizedEmail = strtolower($email);
        $hash = md5($normalizedEmail);
        error_log("getSettingsPath: email='$email', normalized='$normalizedEmail', hash='$hash'");
        return $this->settingsDir . '/' . $hash . '.json';
    }

    /**
     * Get default settings
     */
    private function getDefaults(): array
    {
        return $this->config['defaults'] ?? [
            'display_name' => '',
            'signature' => '',
            'messages_per_page' => 50,
            'theme' => 'system',
            'accent_color' => 'green',
            'layout_mode' => 'columns',
            'perspective' => 'operations',
            'setup_completed' => false,
            'auto_mark_read' => true,
            'confirm_delete' => true,
            'folder_order' => [], // Custom folder ordering
            'refresh_interval' => 60, // seconds (0 = disabled)
            'large_attachment_threshold' => 10, // MB (0 = disabled)
            'block_remote_images' => true, // Block images from untrusted senders
            'override_email_styling' => true, // Force readable colors in emails
            // Out of Office auto-reply
            'ooo_enabled' => false,
            'ooo_subject' => '',
            'ooo_message' => '',
            'ooo_start_date' => '',
            'ooo_end_date' => '',
            'undo_send_delay' => 0,
            // Mentions defaults — both ON to mirror Outlook out-of-the-box.
            'auto_add_mentions_to_recipients' => true,
            'notify_on_mention' => true,
        ];
    }
    
    /**
     * Get global AI settings path (shared across all accounts for a user)
     */
    private function getGlobalAISettingsPath(): string
    {
        // Use the PRIMARY user email (not active account) for global AI settings
        $primaryEmail = $this->userEmail;
        $hash = md5(strtolower($primaryEmail));
        return $this->globalSettingsDir . '/ai_' . $hash . '.json';
    }
    
    /**
     * Load global AI settings
     */
    private function loadGlobalAISettings(): array
    {
        $file = $this->getGlobalAISettingsPath();
        error_log("loadGlobalAISettings: Path: $file, exists: " . (file_exists($file) ? 'yes' : 'no'));
        
        $defaults = [
            'ai_model' => 'gpt-5-nano',
            'ai_writing_style' => 'professional',
            'ai_temperature' => 1.0,
            'ai_prompt_summarize' => '',
            'ai_prompt_rewrite' => '',
            'ai_prompt_draft_reply' => '',
        ];
        
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $settings = json_decode($content, true);
            if (is_array($settings)) {
                error_log("loadGlobalAISettings: Loaded keys: " . implode(', ', array_keys($settings)));
                return array_merge($defaults, $settings);
            }
        }
        
        return $defaults;
    }
    
    /**
     * Save global AI settings
     */
    private function saveGlobalAISettings(array $settings): bool
    {
        $dir = $this->globalSettingsDir;
        if (!is_dir($dir)) {
            error_log("saveGlobalAISettings: Creating directory: $dir");
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create global settings directory: $dir");
                return false;
            }
        }
        
        $file = $this->getGlobalAISettingsPath();
        error_log("saveGlobalAISettings: Saving to: $file");
        error_log("saveGlobalAISettings: Keys: " . implode(', ', array_keys($settings)));
        
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log("Failed to encode AI settings to JSON");
            return false;
        }
        
        $result = file_put_contents($file, $json);
        if ($result === false) {
            error_log("Failed to write AI settings file: $file");
            return false;
        }
        
        error_log("saveGlobalAISettings: Successfully wrote " . strlen($json) . " bytes");
        return true;
    }

    /**
     * Get AI settings
     * GET /settings/ai
     */
    public function getAISettings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        // AI settings are GLOBAL (shared across all accounts)
        $settings = $this->loadGlobalAISettings();
        
        // Check if API key is configured
        $isConfigured = !empty($settings['ai_api_key_encrypted']);
        error_log("getAISettings: isConfigured: " . ($isConfigured ? 'true' : 'false'));
        
        return Response::success([
            'configured' => $isConfigured,
            'model' => $settings['ai_model'] ?? 'gpt-5-nano',
            'writing_style' => $settings['ai_writing_style'] ?? 'professional',
            'temperature' => $settings['ai_temperature'] ?? 1.0,
            'prompts' => [
                'summarize' => $settings['ai_prompt_summarize'] ?? '',
                'rewrite' => $settings['ai_prompt_rewrite'] ?? '',
                'draft_reply' => $settings['ai_prompt_draft_reply'] ?? '',
            ],
            'available_models' => AIService::getModels(),
            'available_styles' => AIService::getWritingStyles(),
            'default_prompts' => AIService::getDefaultPrompts(),
        ]);
    }

    /**
     * Update AI settings
     * PUT /settings/ai
     */
    public function updateAISettings(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        // AI settings are GLOBAL (shared across all accounts)
        $current = $this->loadGlobalAISettings();
        
        // Update AI settings
        $aiFields = ['ai_model', 'ai_writing_style', 'ai_prompt_summarize', 'ai_prompt_rewrite', 'ai_prompt_draft_reply'];
        foreach ($aiFields as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $current[$field] = $value;
            }
        }
        
        // Handle temperature (numeric value 0-2)
        $temperature = $request->input('ai_temperature');
        if ($temperature !== null) {
            $current['ai_temperature'] = max(0, min(2, (float)$temperature));
        }
        
        // Handle API key
        $apiKey = $request->input('ai_api_key');
        error_log("updateAISettings: Received API key: " . ($apiKey ? (strlen($apiKey) > 10 ? substr($apiKey, 0, 10) . '...' : 'short-key') : 'null'));
        
        if ($apiKey !== null) {
            if (empty($apiKey)) {
                error_log("updateAISettings: Removing API key");
                unset($current['ai_api_key_encrypted']);
            } else if ($apiKey !== '********') {
                $secret = $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
                $encrypted = AIService::encryptApiKey($apiKey, $secret);
                error_log("updateAISettings: Encrypted API key, length: " . strlen($encrypted));
                $current['ai_api_key_encrypted'] = $encrypted;
            }
        }
        
        // Validate model
        $validModels = array_keys(AIService::getModels());
        if (isset($current['ai_model']) && !in_array($current['ai_model'], $validModels)) {
            $current['ai_model'] = 'gpt-5-nano';
        }
        
        // Validate style
        $validStyles = array_keys(AIService::getWritingStyles());
        if (isset($current['ai_writing_style']) && !in_array($current['ai_writing_style'], $validStyles)) {
            $current['ai_writing_style'] = 'professional';
        }

        $saved = $this->saveGlobalAISettings($current);
        
        if (!$saved) {
            error_log("updateAISettings: Failed to save global AI settings!");
            return Response::error('Failed to save AI settings', 500);
        }

        $isConfigured = !empty($current['ai_api_key_encrypted']);
        error_log("updateAISettings: Saved successfully, configured: " . ($isConfigured ? 'true' : 'false'));

        return Response::success([
            'configured' => $isConfigured,
            'model' => $current['ai_model'] ?? 'gpt-5-nano',
            'writing_style' => $current['ai_writing_style'] ?? 'professional',
        ], 'AI settings updated');
    }
    
    // ===== TRUSTED SENDERS =====
    
    /**
     * Get trusted senders list
     * GET /settings/trusted-senders
     */
    public function getTrustedSenders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $activeEmail = $this->getActiveEmail();
        $settings = $this->loadSettings($activeEmail);
        $trustedSenders = $settings['trusted_senders'] ?? [];

        return Response::success([
            'trusted_senders' => $trustedSenders,
        ]);
    }
    
    /**
     * Add a trusted sender
     * POST /settings/trusted-senders
     */
    public function addTrustedSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $email = $request->input('email');
        if (empty($email)) {
            $email = $request->getQuery('email');
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address is required', 400);
        }

        $activeEmail = $this->getActiveEmail();
        $normalizedEmail = strtolower(trim($email));

        $sync = new TrustedSenderSync($this->config, $this->settingsDir);
        $sync->addTrusted($activeEmail, $normalizedEmail);

        $settings = $this->loadSettings($activeEmail);
        $trustedSenders = $settings['trusted_senders'] ?? [];

        return Response::success([
            'trusted_senders' => $trustedSenders,
            'added' => $normalizedEmail,
        ], 'Sender added to trusted list');
    }
    
    /**
     * Batched import of trusted senders. Used by the one-time
     * localStorage -> backend migration in stores/settings.js
     * (which used to fire N POSTs in a loop -- one per address).
     *
     * Body: { emails: string[] }
     *
     * POST /settings/trusted-senders/import
     */
    public function importTrustedSenders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $emails = (array)$request->input('emails', []);
        if (empty($emails)) {
            return Response::error('emails[] is required', 400);
        }
        if (count($emails) > 500) {
            $emails = array_slice($emails, 0, 500);
        }

        $activeEmail = $this->getActiveEmail();
        $sync = new TrustedSenderSync($this->config, $this->settingsDir);

        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($emails as $email) {
            $normalized = strtolower(trim((string)$email));
            if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "{$email}: invalid email";
                continue;
            }
            try {
                $result = $sync->addTrusted($activeEmail, $normalized);
                if ($result['settings'] || $result['database']) {
                    $added++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "{$normalized}: " . $e->getMessage();
            }
        }

        $settings = $this->loadSettings($activeEmail);
        return Response::success([
            'trusted_senders' => $settings['trusted_senders'] ?? [],
            'added' => $added,
            'skipped' => $skipped,
            'errors' => $errors,
        ], "Imported {$added} trusted sender(s)");
    }

    /**
     * Remove a trusted sender
     * DELETE /settings/trusted-senders
     */
    public function removeTrustedSender(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $email = $request->input('email') ?? $request->getParam('email');
        if (empty($email)) {
            return Response::error('Email address is required', 400);
        }

        $activeEmail = $this->getActiveEmail();
        $normalizedEmail = strtolower(trim($email));

        $sync = new TrustedSenderSync($this->config, $this->settingsDir);
        $sync->removeTrusted($activeEmail, $normalizedEmail);

        $settings = $this->loadSettings($activeEmail);
        $trustedSenders = $settings['trusted_senders'] ?? [];

        return Response::success([
            'trusted_senders' => $trustedSenders,
            'removed' => $normalizedEmail,
        ], 'Sender removed from trusted list');
    }
    
    // ==================== STORAGE SETTINGS ====================
    // Note: Storage configuration is managed via Panel. These endpoints are read-only.
    
    /**
     * Get storage configuration (read-only, from Panel)
     * GET /settings/storage
     */
    public function getStorageConfig(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        try {
            // Pass userEmail so Panel can determine storage based on domain
            $driveService = new \Webmail\Services\DriveService($this->config, $this->userEmail);
            $storage = $driveService->getStorage();
            
            return Response::success([
                'driver' => $storage->getDriver(),
                'storage_name' => $storage->getStorageName(),
                'config' => $storage->getConfig(),
                'stats' => $storage->getUsageStats(),
                'is_from_panel' => $storage->isFromPanel(),
            ]);
        } catch (\Exception $e) {
            error_log("getStorageConfig error: " . $e->getMessage());
            return Response::error('Failed to load storage configuration', 500);
        }
    }
    
    /**
     * Get storage usage statistics
     * GET /settings/storage/stats
     */
    public function getStorageStats(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        try {
            $driveService = new \Webmail\Services\DriveService($this->config, $this->userEmail);
            $stats = $driveService->getStorageStats();
            
            // Also get database stats (total files, folders, users)
            $db = $driveService->getDb();
            
            $fileCount = $db->query('SELECT COUNT(*) FROM drive_files WHERE is_trashed = 0 OR is_trashed IS NULL')->fetchColumn();
            $folderCount = $db->query('SELECT COUNT(*) FROM drive_folders WHERE is_trashed = 0 OR is_trashed IS NULL')->fetchColumn();
            $userCount = $db->query('SELECT COUNT(DISTINCT user_email) FROM drive_files')->fetchColumn();
            $totalSize = $db->query('SELECT COALESCE(SUM(size), 0) FROM drive_files WHERE is_trashed = 0 OR is_trashed IS NULL')->fetchColumn();
            
            $stats['database'] = [
                'total_files' => (int)$fileCount,
                'total_folders' => (int)$folderCount,
                'total_users' => (int)$userCount,
                'total_size_bytes' => (int)$totalSize,
                'total_size_formatted' => $this->formatBytes((int)$totalSize),
            ];
            
            return Response::success($stats);
        } catch (\Exception $e) {
            error_log("getStorageStats error: " . $e->getMessage());
            return Response::error('Failed to load storage statistics', 500);
        }
    }
    
    /**
     * Format bytes to human readable string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Sync Out of Office settings to Dovecot.
     * Delegates all Sieve work to SieveSyncService (single source of truth).
     */
    private function syncOooToDovecot(string $email, array $settings): void
    {
        try {
            $vacation = SieveSyncService::getActiveVacationConfig($email);

            $this->updateOooDbStatus($email, $vacation);

            $syncService = new SieveSyncService($this->config);
            $result = $syncService->syncViaDisk($email);
            if (!$result['success']) {
                error_log("[Sieve] Disk sync failed for {$email}: " . ($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            error_log("[Sieve] Failed to sync OOO to Dovecot: " . $e->getMessage());
        }
    }
    
    /**
     * Update mail_accounts DB status for OOO (non-critical, for UI display)
     */
    private function updateOooDbStatus(string $email, ?array $vacation): void
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            if ($vacation) {
                $stmt = $db->prepare("
                    UPDATE mail_accounts 
                    SET status = 'vacation',
                        vacation_message = ?,
                        vacation_subject = ?
                    WHERE email = ?
                ");
                $plainMessage = strip_tags(html_entity_decode($vacation['message'] ?? '', ENT_QUOTES, 'UTF-8'));
                $stmt->execute([substr(trim($plainMessage), 0, 5000), substr($vacation['subject'] ?? '', 0, 255), $email]);
                error_log("[Sieve] Enabled vacation in DB for {$email}");
            } else {
                $stmt = $db->prepare("
                    UPDATE mail_accounts 
                    SET status = 'active',
                        vacation_message = NULL,
                        vacation_subject = NULL
                    WHERE email = ? AND status = 'vacation'
                ");
                $stmt->execute([$email]);
                if ($stmt->rowCount() > 0) {
                    error_log("[Sieve] Disabled vacation in DB for {$email}");
                }
            }
        } catch (\Exception $dbErr) {
            error_log("[Sieve] DB update skipped (non-critical): " . $dbErr->getMessage());
        }
    }
}

