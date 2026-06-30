<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Adapter for SFTP user / group / chroot jail management.
 *
 * Used by site-provisioning steps that need to give a customer SSH/SFTP
 * access to /home/<domain> without letting them escape into the rest
 * of the system.
 *
 * What it does:
 *   - Create a per-site Linux group + user via useradd/groupadd.
 *   - Set/rotate the user's password.
 *   - Tear down the user + group (only when the home dir is empty or
 *     the caller explicitly opts into rmtree via FilesystemAdapter).
 *   - Probe via `getent` so callers can be idempotent.
 *
 * What it does NOT do:
 *   - Edit /etc/ssh/sshd_config Match blocks. That's an
 *     SshdMatchBlockStep, which uses FilesystemAdapter + OlsAdapter-
 *     style atomic write. Reason: editing sshd_config wrong locks the
 *     operator out of the box, so it deserves its own dedicated step
 *     with extra guards (config-test sshd, restart sshd, fall back).
 *   - Install SSH keys. SshKeyInstallStep handles that.
 *
 * Identifier policy:
 *   - User names: [a-z][a-z0-9_-]{0,30}. POSIX-portable, lowercase to
 *     avoid useradd corner cases on some systems.
 *   - Group names: same rules.
 *   - Home dir: must be absolute. The adapter does NOT create the
 *     directory - FilesystemAdapter::ensureDirectory is the right tool.
 */
final class SftpAdapter
{
    private const NAME_REGEX = '/^[a-z][a-z0-9_-]{0,30}$/';
    private const PASSWORD_MAX_LEN = 256;

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $useraddBin = '/usr/sbin/useradd',
        private readonly string $userdelBin = '/usr/sbin/userdel',
        private readonly string $usermodBin = '/usr/sbin/usermod',
        private readonly string $groupaddBin = '/usr/sbin/groupadd',
        private readonly string $groupdelBin = '/usr/sbin/groupdel',
        private readonly string $chpasswdBin = '/usr/sbin/chpasswd',
        private readonly string $getentBin = '/usr/bin/getent'
    ) {
    }

    // ─── Existence probes ─────────────────────────────────────

    public function groupExists(string $group): bool
    {
        $this->assertSafeName($group);
        return $this->runner->run($this->getentBin, ['group', $group], null, 5)->isSuccess();
    }

    public function userExists(string $user): bool
    {
        $this->assertSafeName($user);
        return $this->runner->run($this->getentBin, ['passwd', $user], null, 5)->isSuccess();
    }

    // ─── Group lifecycle ──────────────────────────────────────

    public function createGroup(string $group, ?int $gid = null): bool
    {
        $this->assertSafeName($group);
        if ($this->groupExists($group)) {
            return false;
        }
        $args = [];
        if ($gid !== null) {
            $args[] = '-g';
            $args[] = (string) $gid;
        }
        $args[] = $group;
        $r = $this->runner->run($this->groupaddBin, $args);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("groupadd failed for {$group}: " . $r->summary());
        }
        return true;
    }

    public function deleteGroup(string $group): bool
    {
        $this->assertSafeName($group);
        if (!$this->groupExists($group)) {
            return false;
        }
        $r = $this->runner->run($this->groupdelBin, [$group]);
        if (!$r->isSuccess()) {
            // groupdel commonly fails because the group is still
            // assigned as a user's primary group. Surface that clearly.
            throw new \RuntimeException("groupdel failed for {$group}: " . $r->summary());
        }
        return true;
    }

    // ─── User lifecycle ───────────────────────────────────────

    /**
     * Create a user with the given home dir, primary group, and shell.
     *
     * Defaults are tuned for SFTP-chroot:
     *   - shell: /bin/false (no interactive login)
     *   - move home dir into place (-d)
     *   - do NOT create the home dir (-M); caller already provisioned
     *     it via FilesystemAdapter so ownership/mode are predictable.
     *
     * Idempotent: returns false if the user already exists.
     */
    public function createUser(
        string $user,
        string $homeDir,
        string $primaryGroup,
        string $shell = '/bin/false'
    ): bool {
        $this->assertSafeName($user);
        $this->assertSafeName($primaryGroup);
        if (!str_starts_with($homeDir, '/')) {
            throw new \InvalidArgumentException("homeDir must be absolute: {$homeDir}");
        }
        if ($shell !== '/bin/false' && $shell !== '/sbin/nologin' && $shell !== '/bin/bash' && $shell !== '/bin/sh') {
            throw new \InvalidArgumentException("Unexpected shell: {$shell}");
        }
        if ($this->userExists($user)) {
            return false;
        }

        $args = [
            '-d', $homeDir,
            '-g', $primaryGroup,
            '-M',           // do not create home (caller did)
            '-s', $shell,
            $user,
        ];
        $r = $this->runner->run($this->useraddBin, $args);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("useradd failed for {$user}: " . $r->summary());
        }
        return true;
    }

    /**
     * Set/rotate the user's password. The plaintext goes in via stdin
     * to chpasswd so it never appears on argv.
     */
    public function setPassword(string $user, string $password): void
    {
        $this->assertSafeName($user);
        if ($password === '' || strlen($password) > self::PASSWORD_MAX_LEN) {
            throw new \InvalidArgumentException("Password length out of range");
        }
        $stdin = $user . ':' . $password . "\n";
        $r = $this->runner->run($this->chpasswdBin, [], $stdin);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("chpasswd failed for {$user}: " . $r->summary());
        }
    }

    public function changeShell(string $user, string $shell): void
    {
        $this->assertSafeName($user);
        if ($shell !== '/bin/false' && $shell !== '/sbin/nologin' && $shell !== '/bin/bash' && $shell !== '/bin/sh') {
            throw new \InvalidArgumentException("Unexpected shell: {$shell}");
        }
        $r = $this->runner->run($this->usermodBin, ['-s', $shell, $user]);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("usermod -s failed for {$user}: " . $r->summary());
        }
    }

    /**
     * Add user to a supplementary group. Idempotent: re-adding is a
     * no-op.
     */
    public function addUserToGroup(string $user, string $group): void
    {
        $this->assertSafeName($user);
        $this->assertSafeName($group);
        $r = $this->runner->run($this->usermodBin, ['-a', '-G', $group, $user]);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("usermod -aG failed: " . $r->summary());
        }
    }

    /**
     * Delete the user. Does NOT remove the home directory - that is
     * FilesystemAdapter::rmtree's job. Returns true if a delete
     * happened, false if the user was already absent.
     */
    /**
     * Remove a Linux user.
     *
     * `$force` passes `userdel -f`, which removes the account even
     * when the user has running processes or is logged in. This is
     * mandatory for saga DELETE flows, where the home directory has
     * already been removed by an earlier step - any leftover process
     * is by definition a zombie that's lost its working tree, and
     * `userdel` would otherwise refuse with exit code 8.
     *
     * `-r` (remove home + mail spool) is deliberately NOT used here
     * - the saga removed the home dir in its own audited step, and
     * the kernel's behavior around mail spools varies across
     * distributions. Keeping this method narrow makes the audit
     * trail crystal clear: "userdel removed the account, nothing
     * else".
     */
    public function deleteUser(string $user, bool $force = false): bool
    {
        $this->assertSafeName($user);
        if (!$this->userExists($user)) {
            return false;
        }
        $args = [];
        if ($force) {
            $args[] = '-f';
        }
        $args[] = $user;
        $r = $this->runner->run($this->userdelBin, $args);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("userdel failed for {$user}: " . $r->summary());
        }
        return true;
    }

    /**
     * Look up the numeric UID/GID for a user. Returns null if absent.
     *
     * @return array{uid:int, gid:int, home:string, shell:string}|null
     */
    public function inspectUser(string $user): ?array
    {
        $this->assertSafeName($user);
        $r = $this->runner->run($this->getentBin, ['passwd', $user], null, 5);
        if (!$r->isSuccess()) {
            return null;
        }
        $line = trim($r->stdout);
        // passwd format: name:x:UID:GID:GECOS:HOME:SHELL
        $parts = explode(':', $line);
        if (count($parts) < 7) {
            return null;
        }
        return [
            'uid' => (int) $parts[2],
            'gid' => (int) $parts[3],
            'home' => $parts[5],
            'shell' => $parts[6],
        ];
    }

    /**
     * Resolve the user's primary group NAME (not gid).
     *
     * Saga-provisioned sites use the convention `group name === user
     * name`, but legacy / service users on a hand-built host often
     * don't (e.g. `email_devcon` belongs to group `www-data`). The
     * reconciler's drift probe needs the real group so it doesn't
     * report a phantom missing-group every tick.
     *
     * Returns null when the user doesn't exist or when getent fails
     * to resolve the gid (e.g. the group was deleted while the user
     * is still in passwd - rare but worth handling).
     */
    public function primaryGroupName(string $user): ?string
    {
        $info = $this->inspectUser($user);
        if ($info === null) {
            return null;
        }
        $r = $this->runner->run($this->getentBin, ['group', (string) $info['gid']], null, 5);
        if (!$r->isSuccess()) {
            return null;
        }
        $line = trim($r->stdout);
        // group format: name:x:GID:members
        $parts = explode(':', $line);
        if (count($parts) < 1 || $parts[0] === '') {
            return null;
        }
        return $parts[0];
    }

    /**
     * Does the group still have anyone referencing it?
     *
     * Counts:
     *   - any user with this group as their PRIMARY group (via gid in /etc/passwd)
     *   - any user listed as a SUPPLEMENTARY member of the group (4th field of getent group)
     *
     * Returns true when at least one reference remains, false when
     * the group is completely orphaned, and false when the group
     * itself doesn't exist (defensive default - nothing to protect).
     *
     * Used by SftpGroupRemoveStep to refuse to delete shared groups
     * like `www-data` or `users` that other sites / system services
     * still depend on. groupdel(8) would itself refuse if the group
     * is a user's primary group, but it would happily delete a group
     * with only supplementary members - which would silently break
     * file ownership on other sites. This check closes that gap.
     */
    public function groupHasMembers(string $group): bool
    {
        $this->assertSafeName($group);
        $r = $this->runner->run($this->getentBin, ['group', $group], null, 5);
        if (!$r->isSuccess()) {
            return false;
        }
        $line = trim($r->stdout);
        $parts = explode(':', $line);
        if (count($parts) < 4) {
            return false;
        }
        $gid = (int) $parts[2];
        $supplementary = trim($parts[3]);
        if ($supplementary !== '') {
            return true;
        }
        $passwd = $this->runner->run($this->getentBin, ['passwd'], null, 10);
        if (!$passwd->isSuccess()) {
            return false;
        }
        foreach (explode("\n", $passwd->stdout) as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            $cols = explode(':', $row);
            if (count($cols) < 4) {
                continue;
            }
            if ((int) $cols[3] === $gid) {
                return true;
            }
        }
        return false;
    }

    // ─── Internals ────────────────────────────────────────────

    private function assertSafeName(string $name): void
    {
        if (preg_match(self::NAME_REGEX, $name) !== 1) {
            throw new \InvalidArgumentException(
                "Unix identifier failed safe-name check: '{$name}'"
            );
        }
    }
}
