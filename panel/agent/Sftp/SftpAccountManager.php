<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

use VpsAdmin\Agent\Provisioner\Adapters\CommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\SftpAdapter;

/**
 * Linux-account side of an additional SFTP user.
 *
 * Owns: the shared `flowone_sftp` group, the per-user account
 * (useradd/userdel via the validated SftpAdapter), password
 * set/lock/unlock, and the root-owned authorized_keys file that the
 * sshd Match block points `AuthorizedKeysFile` at.
 *
 * It does NOT touch sshd_config (SshdSftpConfigurator) or mounts/ACLs
 * (JailManager). Single responsibility: "the account exists, can or
 * cannot password-auth, and has these keys".
 *
 * Keys live OUTSIDE the chroot in /etc/ssh/flowone-sftp-keys/<user>
 * (dir 0700 root:root, files 0600 root:root) because the user's home is
 * the root-owned jail and OpenSSH refuses authorized_keys under a
 * user-writable path.
 */
final class SftpAccountManager
{
    public const GROUP = 'flowone_sftp';
    public const KEY_DIR = '/etc/ssh/flowone-sftp-keys';
    private const SHELL = '/bin/false';
    private const PASSWD_BIN = '/usr/bin/passwd';

    private CommandRunner $runner;
    private SftpAdapter $sftp;
    private FilesystemAdapter $fs;

    public function __construct(?CommandRunner $runner = null)
    {
        $this->runner = $runner ?? new ProcessCommandRunner();
        $this->sftp = new SftpAdapter($this->runner);
        // allowedRoots only gate destructive fs ops; key writes use
        // writeAtomic/ensureDirectory which are not gated.
        $this->fs = new FilesystemAdapter($this->runner, [self::KEY_DIR, '/srv/sftp-jails', '/home']);
    }

    // ─── Group + key dir bootstrap ────────────────────────────

    public function ensureGroup(): void
    {
        if (!$this->sftp->groupExists(self::GROUP)) {
            $this->sftp->createGroup(self::GROUP);
        }
    }

    public function ensureKeyDir(): void
    {
        $this->fs->ensureDirectory(self::KEY_DIR, 0700);
        $this->fs->chmodPath(self::KEY_DIR, 0700);
        $this->ensureRootOwned(self::KEY_DIR);
    }

    // ─── Account lifecycle ────────────────────────────────────

    public function userExists(string $user): bool
    {
        return $this->sftp->userExists($user);
    }

    /**
     * Create the jailed account: home = jail root (root-owned), no home
     * creation, non-login shell, primary group = $primaryGroup, plus the
     * shared flowone_sftp supplementary group the Match block keys on.
     */
    public function createAccount(string $user, string $jailHome, string $primaryGroup): void
    {
        $this->ensureGroup();
        if (!$this->sftp->groupExists($primaryGroup)) {
            // Fall back to the shared group if the site group is missing
            // (e.g. a skip_sftp site owned by www-data resolves elsewhere).
            $primaryGroup = self::GROUP;
        }
        $this->sftp->createUser($user, $jailHome, $primaryGroup, self::SHELL);
        $this->sftp->addUserToGroup($user, self::GROUP);
    }

    public function deleteAccount(string $user): void
    {
        $this->sftp->deleteUser($user, true);
    }

    public function inGroup(string $user, string $group): bool
    {
        $r = $this->runner->run('/usr/bin/id', ['-nG', $user], null, 5);
        if (!$r->isSuccess()) {
            return false;
        }
        $groups = preg_split('/\s+/', trim($r->stdout)) ?: [];
        return in_array($group, $groups, true);
    }

    public function addToGroup(string $user, string $group): void
    {
        $this->sftp->addUserToGroup($user, $group);
    }

    /**
     * Remove a user from a supplementary group. Used to disable an
     * account: dropping flowone_sftp means the sshd Match block no
     * longer applies and the /bin/false shell blocks the default sftp
     * subsystem, so the account is inert until re-added. Idempotent.
     */
    public function removeFromGroup(string $user, string $group): void
    {
        if (!$this->inGroup($user, $group)) {
            return;
        }
        $r = $this->runner->run('/usr/bin/gpasswd', ['-d', $user, $group], null, 10);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("gpasswd -d failed for {$user}/{$group}: " . $r->summary());
        }
    }

    // ─── Password / auth control ──────────────────────────────

    public function setPassword(string $user, string $password): void
    {
        $this->sftp->setPassword($user, $password);
    }

    /** Lock the unix password so only key-auth can work. */
    public function lockPassword(string $user): void
    {
        $r = $this->runner->run(self::PASSWD_BIN, ['-l', $user], null, 10);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("passwd -l failed for {$user}: " . $r->summary());
        }
    }

    public function unlockPassword(string $user): void
    {
        $r = $this->runner->run(self::PASSWD_BIN, ['-u', $user], null, 10);
        if (!$r->isSuccess()) {
            // passwd -u fails when there is no password set yet; that's
            // not an error for our purposes (account simply has no
            // password to unlock).
            return;
        }
    }

    /**
     * True when the account's password is locked (passwd -S field 2 is
     * "L" or "LK"). Returns null when the status can't be read.
     */
    public function isPasswordLocked(string $user): ?bool
    {
        $r = $this->runner->run(self::PASSWD_BIN, ['-S', $user], null, 5);
        if (!$r->isSuccess()) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($r->stdout)) ?: [];
        $state = $parts[1] ?? '';
        return in_array($state, ['L', 'LK'], true);
    }

    // ─── authorized_keys file (root-owned, outside the chroot) ─

    public function keyFilePath(string $user): string
    {
        return self::KEY_DIR . '/' . $user;
    }

    /**
     * @param list<string> $keys Raw public-key lines.
     */
    public function writeKeys(string $user, array $keys): void
    {
        $this->ensureKeyDir();
        $clean = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '' || str_starts_with($key, '#')) {
                continue;
            }
            if (!self::looksLikePublicKey($key)) {
                throw new \InvalidArgumentException('Invalid SSH public key line');
            }
            $clean[] = $key;
        }
        $path = $this->keyFilePath($user);
        $this->fs->writeAtomic($path, implode("\n", $clean) . ($clean ? "\n" : ''), 0600);
        $this->ensureRootOwned($path);
        $this->fs->chmodPath($path, 0600);
    }

    /** @return list<string> */
    public function readKeys(string $user): array
    {
        $path = $this->keyFilePath($user);
        $content = $this->fs->readFile($path);
        if ($content === null) {
            return [];
        }
        $out = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }
        return $out;
    }

    public function removeKeyFile(string $user): void
    {
        $path = $this->keyFilePath($user);
        if ($this->fs->exists($path)) {
            @unlink($path);
        }
    }

    public function keyFileExists(string $user): bool
    {
        return $this->fs->exists($this->keyFilePath($user));
    }

    /**
     * Guarantee root:root ownership without depending on a `chown`
     * subprocess. The agent runs as root, so files it just wrote via
     * writeAtomic are already root-owned (common case short-circuits).
     * Only a pre-existing mis-owned path triggers PHP-native chown, with
     * the adapter's shell chown as a last resort.
     */
    private function ensureRootOwned(string $path): void
    {
        clearstatcache(true, $path);
        $st = @stat($path);
        if ($st !== false && ($st['uid'] ?? -1) === 0 && ($st['gid'] ?? -1) === 0) {
            return;
        }
        @chown($path, 'root');
        @chgrp($path, 'root');
        clearstatcache(true, $path);
        $st = @stat($path);
        if ($st === false || ($st['uid'] ?? -1) !== 0 || ($st['gid'] ?? -1) !== 0) {
            $this->fs->chownPath($path, 'root:root');
        }
    }

    private static function looksLikePublicKey(string $line): bool
    {
        // Reject embedded newlines (defends authorized_keys injection)
        // and require a known key type as the first token.
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            return false;
        }
        return preg_match(
            '/^(ssh-ed25519|ssh-rsa|ssh-dss|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com)\s+[A-Za-z0-9+\/]+=*(\s+\S.*)?$/',
            $line
        ) === 1;
    }
}
