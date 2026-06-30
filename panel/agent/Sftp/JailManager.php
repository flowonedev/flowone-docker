<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

use VpsAdmin\Agent\Provisioner\Adapters\CommandResult;
use VpsAdmin\Agent\Provisioner\Adapters\CommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;

/**
 * Filesystem-jail side of an additional SFTP user.
 *
 * Owns: target-path validation, the root-owned jail directory, the
 * read-write bind mount of the real folder into the jail, its
 * persistence in /etc/fstab (as a delimited marker block, never a full
 * rewrite), and the POSIX ACL that grants the SFTP user access to the
 * site's existing files without changing ownership.
 *
 * It does NOT create users (SftpAccountManager) or edit sshd_config
 * (SshdSftpConfigurator).
 *
 * All mutating methods assume the caller already holds SftpLock.
 */
final class JailManager
{
    public const JAIL_BASE = '/srv/sftp-jails';
    private const FSTAB = '/etc/fstab';

    private CommandRunner $runner;
    private FilesystemAdapter $fs;

    public function __construct(
        ?CommandRunner $runner = null,
        private readonly int $minDepth = 1,
        private readonly string $fstabPath = self::FSTAB,
        private readonly string $jailBase = self::JAIL_BASE
    ) {
        $this->runner = $runner ?? new ProcessCommandRunner();
        $this->fs = new FilesystemAdapter($this->runner, [$this->jailBase]);
    }

    // ─── Target validation (#2 / #20) ─────────────────────────

    /**
     * Canonicalize $target and prove it is a real folder safely inside
     * $homeRoot, deep enough to never be the site root or public_html.
     * Returns the canonical absolute path or throws.
     */
    public function validateTarget(string $homeRoot, string $target): string
    {
        $homeRoot = rtrim($homeRoot, '/');
        if ($homeRoot === '' || $homeRoot[0] !== '/') {
            throw new \InvalidArgumentException("Invalid home root: {$homeRoot}");
        }

        $canon = realpath($target);
        if ($canon === false || !is_dir($canon)) {
            throw new \InvalidArgumentException("Target folder does not exist: {$target}");
        }

        // Must live strictly under the site home (rejects symlink escapes
        // because realpath() already resolved every component).
        if (!str_starts_with($canon . '/', $homeRoot . '/') || $canon === $homeRoot) {
            throw new \InvalidArgumentException(
                "Target must be inside {$homeRoot}: {$target}"
            );
        }

        // Block the site root and the docroot root themselves.
        $blocked = [$homeRoot, $homeRoot . '/public_html'];
        if (in_array($canon, $blocked, true)) {
            throw new \InvalidArgumentException(
                "Choosing the home or public_html root is not allowed; pick a subfolder"
            );
        }

        // Minimum depth below the home root.
        $rest = trim(substr($canon, strlen($homeRoot)), '/');
        $depth = $rest === '' ? 0 : count(explode('/', $rest));
        if ($depth < $this->minDepth) {
            throw new \InvalidArgumentException(
                "Target is too shallow; choose a folder at least {$this->minDepth} level(s) below {$homeRoot}"
            );
        }

        return $canon;
    }

    // ─── Jail layout ──────────────────────────────────────────

    public function jailRootFor(string $user): string
    {
        return $this->jailBase . '/' . $user;
    }

    public function mountPointFor(string $user, string $label): string
    {
        return $this->jailRootFor($user) . '/' . $label;
    }

    public function ensureBase(): void
    {
        $this->fs->ensureDirectory($this->jailBase, 0755);
        $this->ensureRootOwned($this->jailBase);
        $this->fs->chmodPath($this->jailBase, 0755);
    }

    /**
     * Build the full jail for a user: root-owned jail root, mount point,
     * bind mount, fstab persistence, setgid + ACL on the target.
     *
     * @return array{jail_root:string, mount_point:string}
     */
    public function ensureJail(string $user, string $canonTarget, string $label): array
    {
        $this->ensureBase();
        $jailRoot = $this->jailRootFor($user);
        $mountPoint = $this->mountPointFor($user, $label);

        // ChrootDirectory %h requires the jail (and parents) root-owned
        // and not writable by group/other.
        $this->fs->ensureDirectory($jailRoot, 0755);
        $this->ensureRootOwned($jailRoot);
        $this->fs->chmodPath($jailRoot, 0755);
        $this->fs->ensureDirectory($mountPoint, 0755);

        if (!$this->isMounted($mountPoint)) {
            $this->bindMount($canonTarget, $mountPoint);
        }
        $this->addFstabBlock($user, $canonTarget, $mountPoint);

        // setgid only here. The POSIX ACL is applied separately by the
        // caller AFTER the Linux account exists, because setfacl resolves
        // the username to a UID and fails ("Invalid argument near
        // character 3") if the user is not yet created.
        $this->applySetgid($canonTarget);

        return ['jail_root' => $jailRoot, 'mount_point' => $mountPoint];
    }

    // ─── Mount primitives ─────────────────────────────────────

    /**
     * Whether $path is a mount point. Read from /proc/self/mountinfo in
     * pure PHP (no subprocess) so it can never be confused by the agent
     * daemon reaping a short-lived `mountpoint` child before we read its
     * exit code. Falls back to mountpoint(1) only where procfs is absent
     * (e.g. a non-Linux dev box).
     */
    public function isMounted(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        $mountinfo = @file_get_contents('/proc/self/mountinfo');
        if (is_string($mountinfo) && $mountinfo !== '') {
            foreach (explode("\n", $mountinfo) as $line) {
                // Field index 4 (man 5 proc) is the mount point. Our mount
                // points live under /srv/sftp-jails and contain no spaces.
                $fields = explode(' ', $line);
                if (isset($fields[4]) && $fields[4] === $real) {
                    return true;
                }
            }
            return false;
        }
        return $this->runner->run('mountpoint', ['-q', $path], null, 5)->isSuccess();
    }

    private function bindMount(string $target, string $mountPoint): void
    {
        // Verify by re-reading the mount table, not by the exit code: the
        // daemon can surface a spurious exitCode=-1 for this fast child.
        $r = $this->runner->run('mount', ['--bind', $target, $mountPoint], null, 20);
        if (!$this->isMounted($mountPoint)) {
            throw new \RuntimeException("bind mount failed: " . $r->summary());
        }
    }

    /**
     * Unmount, guarding with isMounted first. Throws on a busy mount so
     * the caller keeps the row in `deleting`/`error` for retry rather
     * than deleting DB state while files are still served (#6 / #15).
     * Success is confirmed by re-checking the mount table.
     */
    public function unmount(string $mountPoint): void
    {
        if (!$this->isMounted($mountPoint)) {
            return;
        }
        $r = $this->runner->run('umount', [$mountPoint], null, 20);
        if ($this->isMounted($mountPoint)) {
            throw new \RuntimeException(
                "umount failed (mount may be busy): " . $r->summary()
            );
        }
    }

    // ─── Target preparation + ACLs ────────────────────────────

    /**
     * Set the setgid bit on the target so files the SFTP user creates
     * inherit the folder's owning group (keeps the site/web user able to
     * read uploads). Done PHP-native (preserving the existing mode bits)
     * to avoid a fragile one-shot `chmod` subprocess.
     *
     * Does NOT touch ACLs: setfacl needs the Linux account to exist, so the
     * caller applies the ACL via applyAcl() after createAccount().
     */
    private function applySetgid(string $target): void
    {
        clearstatcache(true, $target);
        $perms = @fileperms($target);
        if ($perms !== false) {
            @chmod($target, ($perms & 07777) | 02000);
        }
    }

    public function applyAcl(string $target, string $user): void
    {
        $access = $this->runner->run('setfacl', ['-m', "u:{$user}:rwX", $target], null, 20);
        if (!$this->ran($access)) {
            throw new \RuntimeException("setfacl (access) failed: " . $access->summary());
        }
        // Default ACL so NEW files/dirs are reachable without a costly
        // recursive pass over the existing tree (#1).
        $default = $this->runner->run('setfacl', ['-d', '-m', "u:{$user}:rwX", $target], null, 20);
        if (!$this->ran($default)) {
            throw new \RuntimeException("setfacl (default) failed: " . $default->summary());
        }
    }

    /** Best-effort ACL removal so entries don't accumulate (#14). */
    public function removeAcl(string $target, string $user): void
    {
        if (!is_dir($target)) {
            return;
        }
        $this->runner->run('setfacl', ['-x', "u:{$user}", $target], null, 20);
        $this->runner->run('setfacl', ['-d', '-x', "u:{$user}", $target], null, 20);
    }

    // ─── fstab marker block (#7) ──────────────────────────────

    private function blockMarkers(string $user): array
    {
        return ["# >>> flowone-sftp:{$user}", "# <<< flowone-sftp:{$user}"];
    }

    public function fstabHasBlock(string $user): bool
    {
        $content = $this->fs->readFile($this->fstabPath) ?? '';
        [$begin] = $this->blockMarkers($user);
        return str_contains($content, $begin);
    }

    public function addFstabBlock(string $user, string $target, string $mountPoint): void
    {
        [$begin, $end] = $this->blockMarkers($user);
        $content = $this->fs->readFile($this->fstabPath) ?? '';
        $content = $this->stripBlock($content, $begin, $end);

        $src = str_replace(' ', '\\040', $target);
        $dst = str_replace(' ', '\\040', $mountPoint);
        $block = $begin . "\n"
            . $src . ' ' . $dst . " none bind,nofail 0 0\n"
            . $end . "\n";

        $content = rtrim($content, "\n") . "\n" . $block;
        $this->fs->writeAtomic($this->fstabPath, $content, 0644);
    }

    public function removeFstabBlock(string $user): void
    {
        [$begin, $end] = $this->blockMarkers($user);
        $content = $this->fs->readFile($this->fstabPath);
        if ($content === null) {
            return;
        }
        $stripped = $this->stripBlock($content, $begin, $end);
        if ($stripped !== $content) {
            $this->fs->writeAtomic($this->fstabPath, rtrim($stripped, "\n") . "\n", 0644);
        }
    }

    private function stripBlock(string $content, string $begin, string $end): string
    {
        $pattern = '/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R?/s';
        return (string) preg_replace($pattern, "\n", $content);
    }

    // ─── Teardown + repair ────────────────────────────────────

    /**
     * Tear the jail down. Throws if the mount is busy (caller keeps the
     * row retryable). Removes fstab block, ACL entries, and the jail dir
     * only after the mount is confirmed gone.
     */
    public function teardown(string $user, ?string $target, string $mountPoint): void
    {
        $this->unmount($mountPoint);
        $this->removeFstabBlock($user);
        if ($target !== null) {
            $this->removeAcl($target, $user);
        }
        $jailRoot = $this->jailRootFor($user);
        if (is_dir($jailRoot) && !$this->isMounted($mountPoint)) {
            $this->fs->rmtree($jailRoot);
        }
    }

    /**
     * Re-establish drifted jail state WITHOUT fabricating the target
     * (#21). Returns a list of human-readable fixes applied; throws if
     * the target folder is gone (cannot be safely recreated).
     *
     * The POSIX ACL is intentionally NOT reapplied here: setfacl needs the
     * Linux account, which the caller may have to (re)create first, so the
     * caller applies applyAcl() afterwards.
     *
     * @return list<string>
     */
    public function repair(string $user, string $canonTarget, string $label): array
    {
        if (!is_dir($canonTarget)) {
            throw new \RuntimeException("Target folder missing; repair will not recreate it: {$canonTarget}");
        }
        $fixes = [];
        $jailRoot = $this->jailRootFor($user);
        $mountPoint = $this->mountPointFor($user, $label);

        if (!is_dir($jailRoot)) {
            $this->fs->ensureDirectory($jailRoot, 0755);
            $this->ensureRootOwned($jailRoot);
            $fixes[] = 'recreated jail root';
        }
        if (!is_dir($mountPoint)) {
            $this->fs->ensureDirectory($mountPoint, 0755);
            $fixes[] = 'recreated mount point';
        }
        if (!$this->isMounted($mountPoint)) {
            $this->bindMount($canonTarget, $mountPoint);
            $fixes[] = 'remounted bind';
        }
        if (!$this->fstabHasBlock($user)) {
            $this->addFstabBlock($user, $canonTarget, $mountPoint);
            $fixes[] = 'restored fstab block';
        }

        return $fixes;
    }

    // ─── Robustness helpers ───────────────────────────────────

    /**
     * Guarantee a path is root:root for chroot safety. The agent runs as
     * root, so freshly mkdir'd directories are ALREADY root-owned - the
     * common case short-circuits with no subprocess at all. We only fall
     * back to PHP-native chown/chgrp, then the adapter's shell chown, if
     * a pre-existing path has the wrong owner.
     *
     * This avoids the spurious `chown ... [exit=-1]` the agent daemon can
     * return for that very fast child (it reaps the process before the
     * runner reads the real exit code).
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

    /**
     * Treat the daemon's spurious "reaped too fast" result (exitCode -1,
     * no timeout, empty output) as success. A genuine command failure
     * reports a positive exit code and almost always writes to stderr, so
     * this only forgives the known runner artifact.
     */
    private function ran(CommandResult $r): bool
    {
        return $r->isSuccess()
            || ($r->exitCode === -1 && !$r->timedOut
                && trim($r->stderr) === '' && trim($r->stdout) === '');
    }
}
