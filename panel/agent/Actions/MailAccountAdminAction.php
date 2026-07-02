<?php
/**
 * Mail Account Admin Action Handler
 *
 * Per-user account administration that lives outside the (already huge)
 * MailAction: set DRIVE + EMAIL (Dovecot) quotas and reset a user's webmail
 * 2FA. The agent stays the single writer of mail_accounts / webmail_* and the
 * single caller of doveadm, so the panel API never touches these directly.
 *
 * Namespace: mailacct
 *   - setQuotas : set mailbox quota_mb and/or drive quota_bytes for one user
 *   - reset2fa  : clear webmail 2FA (secret, backup codes, trusted devices, sessions)
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\MailPodBridge;
use VpsAdmin\Agent\Lib\MailServerDb;
use VpsAdmin\Agent\Lib\PanelDbTrait;
use VpsAdmin\Agent\Lib\Validator;

class MailAccountAdminAction extends BaseAction
{
    use PanelDbTrait;

    private ?MailPodBridge $mailPod = null;

    private function mailPod(): MailPodBridge
    {
        if ($this->mailPod === null) {
            $this->mailPod = new MailPodBridge(
                fn (string $cmd, array $args, int $timeout = 0) => $this->execCommand($cmd, $args, $timeout)
            );
        }
        return $this->mailPod;
    }

    /**
     * PDO to the database Dovecot actually reads mail_accounts from: the
     * containerized `mailserver` DB on Docker boxes, the panel DB natively.
     */
    private function mailDb(): ?\PDO
    {
        if (!$this->mailPod()->active()) {
            return $this->getPanelDb();
        }
        return MailServerDb::connect();
    }

    /** Run doveadm on the host or inside the mail pod, whichever owns mail. */
    private function doveadm(array $args, int $timeout = 20): array
    {
        if ($this->mailPod()->active()) {
            return $this->mailPod()->exec(array_merge(['doveadm'], $args), $timeout);
        }
        return $this->execCommand('doveadm', $args, $timeout);
    }

    // Mailbox quota bounds (MB). 0 = unlimited.
    private const MAILBOX_MIN_MB = 100;
    private const MAILBOX_MAX_MB = 1048576; // 1 TB

    // Drive quota bounds (bytes). -1 = unlimited.
    private const DRIVE_MIN_BYTES = 104857600; // 100 MB

    public function getNamespace(): string
    {
        return 'mailacct';
    }

    public function getMethods(): array
    {
        return ['setQuotas', 'reset2fa'];
    }

    public function requiresBackup(string $method): bool
    {
        // Pure DB mutations on app tables; no config files to back up.
        return false;
    }

    /**
     * Set the mailbox quota (quota_mb) and/or drive quota (quota_bytes) for one
     * mailbox. Either may be omitted; at least one must be present. Ranges are
     * validated strictly (out-of-range values are rejected, not clamped).
     *
     * Params:
     *   email             (required)
     *   quota_mb          (optional int) 0 = unlimited, else 100..1048576
     *   drive_quota_bytes (optional int) -1 = unlimited, else >= 104857600
     */
    protected function actionSetQuotas(array $params, string $actor): array
    {
        $email = strtolower(trim((string) ($params['email'] ?? '')));
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        $hasMailbox = array_key_exists('quota_mb', $params) && $params['quota_mb'] !== null && $params['quota_mb'] !== '';
        $hasDrive = array_key_exists('drive_quota_bytes', $params) && $params['drive_quota_bytes'] !== null && $params['drive_quota_bytes'] !== '';

        if (!$hasMailbox && !$hasDrive) {
            return $this->error('Provide quota_mb and/or drive_quota_bytes');
        }

        $quotaMb = null;
        if ($hasMailbox) {
            $err = $this->validateMailboxQuota($params['quota_mb'], $quotaMb);
            if ($err !== null) {
                return $this->error($err);
            }
        }

        $driveBytes = null;
        if ($hasDrive) {
            $err = $this->validateDriveQuota($params['drive_quota_bytes'], $driveBytes);
            if ($err !== null) {
                return $this->error($err);
            }
        }

        // mail_accounts must be written where Dovecot reads it (mailserver DB
        // on Docker boxes); drive_quotas is an app table and stays panel-side.
        $mailDb = $this->mailDb();
        if (!$mailDb) {
            return $this->error('Cannot connect to mail database');
        }

        // The mailbox must exist; this catches typos before we write anything.
        $check = $mailDb->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
        $check->execute([$email]);
        if (!$check->fetch()) {
            return $this->error("Account not found: {$email}");
        }

        $applied = [];
        $warnings = [];

        if ($quotaMb !== null) {
            try {
                $stmt = $mailDb->prepare("UPDATE mail_accounts SET quota_mb = ?, updated_at = NOW() WHERE LOWER(email) = ?");
                $stmt->execute([$quotaMb, $email]);
            } catch (\Exception $e) {
                return $this->error('Failed to set mailbox quota: ' . $e->getMessage());
            }
            // Keep the panel DB copy in sync on Docker (webmail features read it).
            if ($this->mailPod()->active()) {
                try {
                    $panel = $this->getPanelDb();
                    if ($panel) {
                        $mirror = $panel->prepare("UPDATE mail_accounts SET quota_mb = ?, updated_at = NOW() WHERE LOWER(email) = ?");
                        $mirror->execute([$quotaMb, $email]);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning("setQuotas: panel DB mirror failed for {$email}: " . $e->getMessage());
                }
            }
            $applied['quota_mb'] = $quotaMb;

            // Apply the new limit to Dovecot. With the `maildir:User quota`
            // backend the LIMIT is cached in the mailbox's maildirsize file;
            // `doveadm quota recalc` only recomputes USAGE and leaves that
            // stale limit line in place, so a changed quota (including
            // switching to/from unlimited) never takes effect. We must drop
            // maildirsize so Dovecot regenerates it from the new quota_rule.
            // Warning-only: a refresh failure must not fail the DB write.
            $refresh = $this->refreshDovecotQuota($email);
            if (!$refresh['success']) {
                $msg = 'Quota saved, but the Dovecot quota refresh failed (it will refresh on next login): '
                    . trim((string) $refresh['output']);
                $warnings[] = $msg;
                $this->logger->warning("Dovecot quota refresh failed for {$email}", [
                    'output' => $refresh['output'],
                    'removed' => $refresh['removed'],
                ]);
            }
        }

        if ($driveBytes !== null) {
            $panel = $this->getPanelDb();
            if (!$panel) {
                return $this->error('Cannot connect to panel database for drive quota');
            }
            try {
                // drive_quotas is owned by the Email App; row may not exist yet.
                $stmt = $panel->prepare("
                    INSERT INTO drive_quotas (user_email, quota_bytes)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE quota_bytes = VALUES(quota_bytes)
                ");
                $stmt->execute([$email, $driveBytes]);
            } catch (\Exception $e) {
                return $this->error('Failed to set drive quota: ' . $e->getMessage());
            }
            $applied['drive_quota_bytes'] = $driveBytes;
        }

        $this->logger->info("Updated quotas for {$email} by {$actor}", $applied);

        return $this->success([
            'email' => $email,
            'applied' => $applied,
            'warnings' => $warnings,
        ], $warnings ? implode(' ', $warnings) : 'Quotas updated');
    }

    /**
     * Reset a user's webmail two-factor auth so they can sign in with password
     * only and re-enroll. Clears the TOTP secret + backup codes, revokes trusted
     * devices, and signs out active webmail sessions. Each step is guarded: the
     * webmail_* tables live in the shared app DB and may be absent on a
     * panel-only box, so a missing table is non-fatal.
     *
     * Params: email (required)
     */
    protected function actionReset2fa(array $params, string $actor): array
    {
        $email = strtolower(trim((string) ($params['email'] ?? '')));
        if (!Validator::email($email)) {
            return $this->error('Invalid email format');
        }

        // Existence check against the authoritative mail DB; the webmail_*
        // tables below are app tables and always live in the panel DB.
        $mailDb = $this->mailDb();
        if (!$mailDb) {
            return $this->error('Cannot connect to mail database');
        }

        $check = $mailDb->prepare("SELECT id FROM mail_accounts WHERE LOWER(email) = ?");
        $check->execute([$email]);
        if (!$check->fetch()) {
            return $this->error("Account not found: {$email}");
        }

        $db = $this->getPanelDb();
        if (!$db) {
            return $this->error('Cannot connect to panel database');
        }

        $disabled = false;
        $devicesRemoved = 0;
        $sessionsRevoked = 0;

        // 1. Disable 2FA + wipe secret and backup codes.
        try {
            $stmt = $db->prepare("UPDATE webmail_2fa SET enabled = 0, secret = NULL, backup_codes = NULL WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            $disabled = $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            $this->logger->warning("reset2fa: could not clear webmail_2fa for {$email}: " . $e->getMessage());
        }

        // 2. Revoke trusted devices (otherwise a remembered device skips 2FA).
        try {
            $stmt = $db->prepare("DELETE FROM webmail_2fa_trusted_devices WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            $devicesRemoved = $stmt->rowCount();
        } catch (\Exception $e) {
            $this->logger->warning("reset2fa: could not clear trusted devices for {$email}: " . $e->getMessage());
        }

        // 3. Sign out active webmail sessions so a logged-in session can't linger.
        try {
            $stmt = $db->prepare("DELETE FROM webmail_sessions WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            $sessionsRevoked = $stmt->rowCount();
        } catch (\Exception $e) {
            $this->logger->warning("reset2fa: could not revoke sessions for {$email}: " . $e->getMessage());
        }

        $this->logger->info("Reset 2FA for {$email} by {$actor}", [
            'disabled' => $disabled,
            'devices_removed' => $devicesRemoved,
            'sessions_revoked' => $sessionsRevoked,
        ]);

        return $this->success([
            'email' => $email,
            'was_enabled' => $disabled,
            'devices_removed' => $devicesRemoved,
            'sessions_revoked' => $sessionsRevoked,
        ], "2FA reset for {$email}");
    }

    /**
     * Make a changed mailbox quota take effect in Dovecot's maildir backend.
     *
     * The maildir quota backend caches the LIMIT in the first line of the
     * mailbox's maildirsize file (e.g. "536870912S"). `doveadm quota recalc`
     * only recomputes usage entries; it does NOT rewrite that limit line, so
     * once a mailbox has a maildirsize the old cap survives every quota change
     * -- including a switch to unlimited (quota_mb = 0 -> quota_rule
     * "*:bytes=0"). The reliable cross-version fix is to delete maildirsize so
     * Dovecot regenerates it (with the current quota_rule) on recalc/next
     * access. The agent runs as root, so it can unlink the vmail-owned file.
     *
     * @return array{success:bool,output:string,removed:list<string>}
     */
    private function refreshDovecotQuota(string $email): array
    {
        $inPod = $this->mailPod()->active();
        $removed = [];
        foreach ($this->maildirsizePaths($email) as $path) {
            if ($inPod) {
                // Maildirs live inside the mail pod's volume, not on the host.
                if ($this->mailPod()->fileExists($path)) {
                    $rm = $this->mailPod()->exec(['rm', '-f', $path], 20);
                    if (!empty($rm['success'])) {
                        $removed[] = $path;
                    }
                }
            } elseif (is_file($path) && @unlink($path)) {
                $removed[] = $path;
            }
        }

        $recalc = $this->doveadm(['quota', 'recalc', '-u', $email], 20);

        return [
            'success' => !empty($recalc['success']),
            'output' => (string) ($recalc['output'] ?? ''),
            'removed' => $removed,
        ];
    }

    /**
     * Candidate maildirsize locations for a mailbox. Prefers the maildir path
     * Dovecot itself reports (authoritative), with computed fallbacks for the
     * home root and a Maildir/ subdirectory layout.
     *
     * @return list<string>
     */
    private function maildirsizePaths(string $email): array
    {
        $candidates = [];

        // Authoritative: the mail location Dovecot resolves for this user,
        // e.g. "maildir:/home/vmail/example.com/user[:LAYOUT=fs]".
        $mail = $this->doveadm(['user', '-f', 'mail', $email], 10);
        if (!empty($mail['success'])) {
            $root = self::maildirRootFromMailField((string) ($mail['output'] ?? ''));
            if ($root !== null) {
                $candidates[] = $root . '/maildirsize';
            }
        }

        // Fallback: the home Dovecot reports, covering both maildir-in-home
        // and the classic Maildir/ subdirectory layout.
        $home = $this->doveadm(['user', '-f', 'home', $email], 10);
        if (!empty($home['success'])) {
            $h = rtrim(trim((string) ($home['output'] ?? '')), '/');
            if ($h !== '' && $h[0] === '/') {
                $candidates[] = $h . '/maildirsize';
                $candidates[] = $h . '/Maildir/maildirsize';
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Parse the maildir root path out of a Dovecot "mail" field value.
     *
     * The value is "<driver>:<path>[:opt=val...]", e.g.
     * "maildir:/home/vmail/example.com/user" or
     * "maildir:/home/vmail/example.com/user:LAYOUT=fs". Returns the absolute
     * root path with any trailing slash trimmed, or null if the value is not a
     * usable absolute maildir path. Pure + static so it is unit-testable
     * without a live Dovecot.
     */
    private static function maildirRootFromMailField(string $value): ?string
    {
        $value = trim($value);
        $colon = strpos($value, ':');
        if ($colon === false) {
            return null;
        }
        $rest = substr($value, $colon + 1);
        $root = explode(':', $rest)[0]; // drop any :opt=val suffixes
        if ($root === '' || $root[0] !== '/') {
            return null;
        }
        return rtrim($root, '/');
    }

    /**
     * Validate a mailbox quota (MB). On success sets $out and returns null;
     * on failure returns an error message. 0 = unlimited.
     */
    private function validateMailboxQuota($value, ?int &$out): ?string
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
            return 'Mailbox quota must be a whole number of MB';
        }
        $mb = (int) $value;
        if ($mb === 0) {
            $out = 0; // unlimited
            return null;
        }
        if ($mb < self::MAILBOX_MIN_MB || $mb > self::MAILBOX_MAX_MB) {
            return 'Mailbox quota must be 0 (unlimited) or between '
                . self::MAILBOX_MIN_MB . ' MB and ' . self::MAILBOX_MAX_MB . ' MB';
        }
        $out = $mb;
        return null;
    }

    /**
     * Validate a drive quota (bytes). On success sets $out and returns null;
     * on failure returns an error message. -1 = unlimited.
     */
    private function validateDriveQuota($value, ?int &$out): ?string
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
            return 'Drive quota must be a whole number of bytes';
        }
        $bytes = (int) $value;
        if ($bytes === -1) {
            $out = -1; // unlimited
            return null;
        }
        if ($bytes < self::DRIVE_MIN_BYTES) {
            return 'Drive quota must be -1 (unlimited) or at least '
                . self::DRIVE_MIN_BYTES . ' bytes (100 MB)';
        }
        $out = $bytes;
        return null;
    }
}
