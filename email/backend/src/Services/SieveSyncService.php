<?php

namespace Webmail\Services;

/**
 * SieveSyncService - Single entry point for all Sieve script synchronization.
 *
 * Loads filters, blocked senders, safe senders, and vacation config,
 * then generates a unified Sieve script and writes it via ManageSieve
 * or direct disk write (for cron / OOO / OAuth accounts).
 */
class SieveSyncService
{
    private array $config;
    private ?SpamService $spamService = null;
    private ?FilterService $filterService = null;

    /**
     * Set true when a data source (filters / blocked / safe senders) throws
     * while loading — almost always a DB connectivity/driver problem. When set,
     * callers MUST refuse to write, because generateScript() would otherwise
     * emit an empty script and silently wipe the user's real rules.
     */
    private bool $dataLoadFailed = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Sync all Sieve rules for a user.
     * Tries ManageSieve first (if password available), falls back to disk.
     */
    public function sync(string $email, ?string $password = null): array
    {
        $script = $this->generateScript($email);

        if ($this->dataLoadFailed) {
            return $this->abortOnDataLoadFailure($email);
        }

        if ($password) {
            $result = $this->syncViaManageSieve($email, $password, $script);
            if ($result['success']) {
                return $result;
            }
            error_log("[SieveSyncService] ManageSieve failed for {$email}, falling back to disk: " . ($result['error'] ?? ''));
        }

        return $this->syncViaDisk($email, $script);
    }

    /**
     * Generate the complete Sieve script for a user from all data sources.
     */
    public function generateScript(string $email): string
    {
        $this->dataLoadFailed = false;
        $filters = $this->loadFilters($email);
        $blockedSenders = $this->loadBlockedSenders($email);
        $safeSenders = $this->loadSafeSenders($email);
        $vacation = self::getActiveVacationConfig($email);
        $spamFolder = $this->loadSpamFolder($email);

        $sieveService = new SieveService($this->config['imap'] ?? []);
        return $sieveService->generateFullScript($filters, $vacation, $blockedSenders, $safeSenders, $spamFolder);
    }

    // ==================== WRITE PATHS ====================

    /**
     * Push script via ManageSieve protocol (requires user password).
     */
    private function syncViaManageSieve(string $email, string $password, string $script): array
    {
        try {
            $sieveService = new SieveService($this->config['imap'] ?? []);

            if (!$sieveService->connect($email, $password)) {
                return ['success' => false, 'error' => $sieveService->getLastError() ?? 'Connection failed'];
            }

            $hasContent = $this->scriptHasActiveRules($script);

            if ($hasContent) {
                if (!$sieveService->putScript(SieveService::SCRIPT_NAME, $script)) {
                    $sieveService->disconnect();
                    return ['success' => false, 'error' => $sieveService->getLastError() ?? 'Failed to upload script'];
                }
                if (!$sieveService->activateScript(SieveService::SCRIPT_NAME)) {
                    $sieveService->disconnect();
                    return ['success' => false, 'error' => 'Failed to activate script'];
                }
            } else {
                $scripts = $sieveService->listScripts();
                foreach ($scripts as $s) {
                    if ($s['name'] === SieveService::SCRIPT_NAME) {
                        if ($s['active']) {
                            $sieveService->deactivateScripts();
                        }
                        $sieveService->deleteScript(SieveService::SCRIPT_NAME);
                        break;
                    }
                }
            }

            $sieveService->disconnect();

            error_log("[SieveSyncService] ManageSieve sync OK for {$email}, active=" . ($hasContent ? 'yes' : 'no'));
            return ['success' => true, 'script' => $script, 'method' => 'managesieve'];

        } catch (\Throwable $e) {
            error_log("[SieveSyncService] ManageSieve exception for {$email}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write script to disk (sudo-based, works without password / from cron).
     */
    public function syncViaDisk(string $email, ?string $script = null): array
    {
        if ($script === null) {
            $script = $this->generateScript($email);
        }

        if ($this->dataLoadFailed) {
            return $this->abortOnDataLoadFailure($email);
        }

        try {
            $parts = explode('@', $email);
            if (count($parts) !== 2) {
                return ['success' => false, 'error' => "Invalid email format: {$email}"];
            }

            [$user, $domain] = $parts;
            $maildir = "/home/vmail/{$domain}/{$user}";
            $sieveDir = "{$maildir}/sieve";
            $mainScript = "{$sieveDir}/webmail_filters.sieve";

            if (!is_dir($sieveDir)) {
                $output = [];
                $rc = 0;
                exec("sudo mkdir -p " . escapeshellarg($sieveDir) . " && sudo chown vmail:vmail " . escapeshellarg($sieveDir) . " 2>&1", $output, $rc);
                if ($rc !== 0) {
                    return ['success' => false, 'error' => "Failed to create sieve dir: " . implode(' ', $output)];
                }
            }

            if (!$this->writeFileAsVmail($mainScript, $script)) {
                return ['success' => false, 'error' => "Failed to write script: {$mainScript}"];
            }

            $this->compileSieveScripts($sieveDir, $email);

            // Clean up legacy separate vacation files
            $vacationFile = "{$sieveDir}/vacation.sieve";
            @exec("sudo rm -f " . escapeshellarg($vacationFile) . " " . escapeshellarg("{$sieveDir}/vacation.svbin") . " 2>/dev/null");

            error_log("[SieveSyncService] Disk sync OK for {$email}: " . strlen($script) . " bytes");
            return ['success' => true, 'script' => $script, 'method' => 'disk'];

        } catch (\Throwable $e) {
            error_log("[SieveSyncService] Disk write exception for {$email}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ==================== VACATION CONFIG ====================

    /**
     * Read OOO settings from a user's settings file and check if vacation is active.
     */
    public static function getActiveVacationConfig(string $email, string $settingsDir = '/var/www/vps-email/data/settings'): ?array
    {
        $hash = md5(strtolower($email));
        $file = "{$settingsDir}/{$hash}.json";

        if (!file_exists($file)) {
            return null;
        }

        $settings = json_decode(file_get_contents($file), true);
        if (!is_array($settings) || empty($settings['ooo_enabled'])) {
            return null;
        }

        $now = new \DateTime();

        if (!empty($settings['ooo_start_date'])) {
            $start = new \DateTime($settings['ooo_start_date']);
            if ($now < $start) return null;
        }

        if (!empty($settings['ooo_end_date'])) {
            $end = new \DateTime($settings['ooo_end_date']);
            if ($now > $end) return null;
        }

        $subject = $settings['ooo_subject'] ?? 'Out of Office';
        if (empty(trim(str_replace('{original_subject}', '', $subject)))) {
            $subject = 'Out of Office';
        }
        $subject = trim(str_replace('{original_subject}', '', $subject));

        $message = $settings['ooo_message'] ?? '';
        if (empty(trim(strip_tags($message)))) {
            return null;
        }

        return [
            'enabled' => true,
            'subject' => $subject,
            'message' => $message,
            'from' => $email,
        ];
    }

    // ==================== DATA LOADING ====================

    /**
     * Build the failure result returned when a data source could not be read.
     * Writing in that state would emit an empty script and wipe the user's
     * real rules, so we refuse and leave the existing Sieve untouched.
     */
    private function abortOnDataLoadFailure(string $email): array
    {
        $msg = "Refusing to sync {$email}: data sources unavailable (DB read failed). "
            . "Existing Sieve left untouched to avoid wiping the user's rules.";
        error_log("[SieveSyncService] {$msg}");
        return ['success' => false, 'error' => $msg, 'aborted' => true];
    }

    private function loadFilters(string $email): array
    {
        try {
            if ($this->filterService === null) {
                $this->filterService = new FilterService($this->config);
            }
            return $this->filterService->getFilters($email);
        } catch (\Throwable $e) {
            $this->dataLoadFailed = true;
            error_log("[SieveSyncService] Could not load filters for {$email}: " . $e->getMessage());
            return [];
        }
    }

    private function loadBlockedSenders(string $email): array
    {
        try {
            return $this->getSpamService()->getBlockedSenders($email);
        } catch (\Throwable $e) {
            $this->dataLoadFailed = true;
            error_log("[SieveSyncService] Could not load blocked senders for {$email}: " . $e->getMessage());
            return [];
        }
    }

    private function loadSafeSenders(string $email): array
    {
        try {
            return $this->getSpamService()->getSafeSenders($email);
        } catch (\Throwable $e) {
            $this->dataLoadFailed = true;
            error_log("[SieveSyncService] Could not load safe senders for {$email}: " . $e->getMessage());
            return [];
        }
    }

    private function loadSpamFolder(string $email): string
    {
        try {
            return $this->getSpamService()->getSpamFolder($email);
        } catch (\Throwable $e) {
            error_log("[SieveSyncService] Could not load spam folder for {$email}: " . $e->getMessage());
            return 'INBOX.Spam';
        }
    }

    private function getSpamService(): SpamService
    {
        if ($this->spamService === null) {
            $this->spamService = new SpamService($this->config);
        }
        return $this->spamService;
    }

    // ==================== DISK WRITE HELPERS ====================

    private function writeFileAsVmail(string $path, string $content): bool
    {
        $result = @file_put_contents($path, $content);
        if ($result !== false) {
            @chown($path, 'vmail');
            @chgrp($path, 'vmail');
            return true;
        }

        $tmpFile = tempnam('/tmp', 'sieve_');
        if ($tmpFile === false) {
            error_log("[SieveSyncService] Failed to create temp file");
            return false;
        }

        file_put_contents($tmpFile, $content);
        $escapedPath = escapeshellarg($path);
        $escapedTmp = escapeshellarg($tmpFile);
        $output = [];
        $rc = 0;
        exec("sudo cp {$escapedTmp} {$escapedPath} && sudo chown vmail:vmail {$escapedPath} 2>&1", $output, $rc);
        @unlink($tmpFile);

        if ($rc !== 0) {
            error_log("[SieveSyncService] Failed to write file: {$path} - " . implode(' ', $output));
            return false;
        }

        return true;
    }

    private function compileSieveScripts(string $sieveDir, string $email): void
    {
        $mainScript = "{$sieveDir}/webmail_filters.sieve";
        if (!file_exists($mainScript)) {
            return;
        }

        $output = [];
        $returnCode = 0;
        exec("sudo sievec " . escapeshellarg($mainScript) . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("[SieveSyncService] sievec failed for {$email}: " . implode("\n", $output));
            $output2 = [];
            exec("sievec " . escapeshellarg($mainScript) . " 2>&1", $output2, $returnCode);
            if ($returnCode !== 0) {
                error_log("[SieveSyncService] sievec also failed without sudo: " . implode("\n", $output2));
                return;
            }
        }

        $svbin = "{$sieveDir}/webmail_filters.svbin";
        exec("sudo chown vmail:vmail " . escapeshellarg($svbin) . " 2>/dev/null");

        // The active-script symlink lives INSIDE the sieve storage dir
        // (~/sieve/.dovecot.sieve), NOT in the mailbox home root. On servers where the
        // userdb home == the Maildir namespace root (separator "."), a ~/.dovecot.sieve
        // symlink sitting among the .Folder dirs makes Dovecot's maildir lister probe it
        // as a mailbox and log "stat(~/.dovecot.sieve/tmp) failed: Not a directory" on
        // every IMAP enumeration. Keeping it under ~/sieve/ removes it from the namespace
        // root entirely. (Pigeonhole excludes the configured active path from the script
        // list, so it never shows up as a phantom script.)
        $maildir = dirname($sieveDir);
        $activeLink = "{$sieveDir}/.dovecot.sieve";

        // Drop our new link plus any legacy root-level link/binary written by older builds,
        // so re-syncing an existing mailbox self-heals it.
        $legacyLink = "{$maildir}/.dovecot.sieve";
        $legacyBin = "{$maildir}/.dovecot.svbin";
        exec("sudo rm -f " . escapeshellarg($activeLink) . " " . escapeshellarg($legacyLink) . " " . escapeshellarg($legacyBin) . " 2>/dev/null");

        $output = [];
        $rc = 0;
        // Target is relative to the link's own dir (both now live in ~/sieve).
        exec("sudo ln -sf 'webmail_filters.sieve' " . escapeshellarg($activeLink) . " && sudo chown -h vmail:vmail " . escapeshellarg($activeLink) . " 2>&1", $output, $rc);

        if ($rc !== 0) {
            error_log("[SieveSyncService] Failed to create symlink: {$activeLink} - " . implode(' ', $output));
        }
    }

    /**
     * Check whether the generated script has any real rules (not just headers/requires).
     */
    private function scriptHasActiveRules(string $script): bool
    {
        return (bool)preg_match('/\b(if|fileinto|discard|vacation|keep|redirect)\b/', $script);
    }
}
