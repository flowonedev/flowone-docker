<?php

namespace Webmail\Services;

/**
 * Bridges the two trusted sender systems:
 * 1. Settings file (trusted_senders array) - controls remote image loading
 * 2. webmail_safe_senders DB table - controls Sieve rule generation
 *
 * When a sender is trusted/untrusted in either system, this class
 * propagates the change to the other.
 */
class TrustedSenderSync
{
    private string $settingsDir;
    private SpamService $spamService;

    public function __construct(array $config, string $settingsDir = '/var/www/vps-email/data/settings')
    {
        $this->settingsDir = $settingsDir;
        $this->spamService = new SpamService($config);
    }

    /**
     * Add a trusted sender to BOTH systems.
     */
    public function addTrusted(string $userEmail, string $senderEmail): array
    {
        $senderEmail = strtolower(trim($senderEmail));
        $addedToSettings = $this->addToSettings($userEmail, $senderEmail);
        $addedToDb = $this->spamService->addSafeSender($userEmail, $senderEmail, false);

        return [
            'settings' => $addedToSettings,
            'database' => $addedToDb,
        ];
    }

    /**
     * Remove a trusted sender from BOTH systems.
     */
    public function removeTrusted(string $userEmail, string $senderEmail): array
    {
        $senderEmail = strtolower(trim($senderEmail));
        $removedFromSettings = $this->removeFromSettings($userEmail, $senderEmail);
        $removedFromDb = $this->removeSafeSenderByEmail($userEmail, $senderEmail);

        return [
            'settings' => $removedFromSettings,
            'database' => $removedFromDb,
        ];
    }

    /**
     * Remove a trusted sender from BOTH systems by safe_sender DB ID.
     * Used when removal originates from the spam settings page.
     */
    public function removeTrustedById(string $userEmail, int $safeSenderId): array
    {
        $email = $this->getSafeSenderEmail($userEmail, $safeSenderId);
        $removedFromDb = $this->spamService->removeSafeSender($userEmail, $safeSenderId);

        $removedFromSettings = false;
        if ($email) {
            $removedFromSettings = $this->removeFromSettings($userEmail, $email);
        }

        return [
            'settings' => $removedFromSettings,
            'database' => $removedFromDb,
        ];
    }

    /**
     * Migrate all settings trusted senders into webmail_safe_senders.
     * Called once on spam settings page load to backfill existing data.
     */
    public function migrateSettingsToDb(string $userEmail): int
    {
        $settingsSenders = $this->getSettingsTrustedSenders($userEmail);
        if (empty($settingsSenders)) {
            return 0;
        }

        $dbSenders = $this->spamService->getSafeSenders($userEmail);
        $dbEmails = array_map(fn($s) => strtolower($s['safe_email'] ?? ''), $dbSenders);

        $migrated = 0;
        foreach ($settingsSenders as $email) {
            $email = strtolower(trim($email));
            if ($email && !in_array($email, $dbEmails, true)) {
                if ($this->spamService->addSafeSender($userEmail, $email, false)) {
                    $migrated++;
                }
            }
        }

        if ($migrated > 0) {
            error_log("[TrustedSenderSync] Migrated {$migrated} settings trusted senders to DB for {$userEmail}");
        }

        return $migrated;
    }

    // ==================== Settings file helpers ====================

    public function getSettingsTrustedSenders(string $userEmail): array
    {
        $settings = $this->loadSettings($userEmail);
        return $settings['trusted_senders'] ?? [];
    }

    private function addToSettings(string $userEmail, string $senderEmail): bool
    {
        $settings = $this->loadSettings($userEmail);
        $trusted = $settings['trusted_senders'] ?? [];

        if (!in_array($senderEmail, $trusted, true)) {
            $trusted[] = $senderEmail;
            $settings['trusted_senders'] = $trusted;
            return $this->saveSettings($userEmail, $settings);
        }

        return true;
    }

    private function removeFromSettings(string $userEmail, string $senderEmail): bool
    {
        $settings = $this->loadSettings($userEmail);
        $trusted = $settings['trusted_senders'] ?? [];

        $filtered = array_values(array_filter($trusted, fn($s) => strtolower($s) !== strtolower($senderEmail)));

        if (count($filtered) !== count($trusted)) {
            $settings['trusted_senders'] = $filtered;
            return $this->saveSettings($userEmail, $settings);
        }

        return true;
    }

    private function loadSettings(string $email): array
    {
        $file = $this->getSettingsPath($email);
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    private function saveSettings(string $email, array $settings): bool
    {
        $dir = $this->settingsDir;
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $file = $this->getSettingsPath($email);
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($file, $json) !== false;
    }

    private function getSettingsPath(string $email): string
    {
        return $this->settingsDir . '/' . md5(strtolower($email)) . '.json';
    }

    // ==================== DB helpers ====================

    private function removeSafeSenderByEmail(string $userEmail, string $senderEmail): bool
    {
        $safeSenders = $this->spamService->getSafeSenders($userEmail);
        foreach ($safeSenders as $sender) {
            if (strtolower($sender['safe_email'] ?? '') === strtolower($senderEmail)) {
                return $this->spamService->removeSafeSender($userEmail, (int)$sender['id']);
            }
        }
        return true;
    }

    private function getSafeSenderEmail(string $userEmail, int $id): ?string
    {
        $safeSenders = $this->spamService->getSafeSenders($userEmail);
        foreach ($safeSenders as $sender) {
            if ((int)$sender['id'] === $id) {
                return $sender['safe_email'] ?? null;
            }
        }
        return null;
    }
}
