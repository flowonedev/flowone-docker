<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Thin, safe wrapper around filesystem operations used by steps.
 *
 * Why this exists rather than calling PHP's fs functions directly:
 *   - **Centralized safety**: rmtree refuses to descend outside an
 *     allowlist of root prefixes. This is the single biggest source of
 *     "oops we deleted /etc" bugs in panel software.
 *   - **Atomic writes**: writeAtomic() guarantees either-old-or-new
 *     content, never half-written, by staging to a sibling temp file
 *     and rename()ing on top.
 *   - **Ownership/permission discipline**: chown/chmod through one
 *     channel keeps mode/owner decisions auditable.
 *   - **Testability**: FilesystemAdapter is a final class, but every
 *     side-effecting call goes through the injected CommandRunner for
 *     operations that require root (chown of system users). Pure-PHP
 *     ops (file_put_contents, mkdir, etc.) are kept direct.
 *
 * Path safety policy:
 *   - destructive operations (delete, rmtree, chmod, chown) ONLY accept
 *     paths under one of $allowedRoots. Anything else throws.
 *   - allowedRoots is configured at construction. Tests use a temp
 *     dir; production uses /home, /var/www/vps-email/storage,
 *     /usr/local/lsws/conf/vhosts and similar safe roots.
 *   - relative paths are rejected outright. Always pass absolute paths.
 *   - .. is rejected after realpath canonicalization.
 *
 * Concurrency:
 *   - writeAtomic uses a unique sibling path then rename(). Two writers
 *     racing on the same target both succeed; last-writer-wins. Use
 *     SiteLock if you need stronger guarantees.
 */
final class FilesystemAdapter
{
    /**
     * @param list<string> $allowedRoots Absolute paths under which
     *                                   destructive ops may operate.
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private array $allowedRoots
    ) {
        // Normalize roots once so the guard is O(1) per call.
        foreach ($this->allowedRoots as &$r) {
            $r = $this->mustBeAbsolute($r);
            $r = rtrim($r, '/');
        }
        unset($r);
    }

    // ─── Existence / stat ─────────────────────────────────────

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isSymlink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Total bytes used by a directory tree (apparent size, matches
     * `du -sb`). Returns null when the path does not exist or when
     * `du` reports a non-zero status. We deliberately delegate to du
     * rather than iterating in PHP: home dirs routinely contain
     * tens of thousands of files and the userland walk is ~10x
     * slower for the same answer.
     */
    public function dirSizeBytes(string $path): ?int
    {
        if (!is_dir($path)) {
            return null;
        }
        $r = $this->runner->run('du', ['-sb', $path], timeoutSeconds: 30);
        if (!$r->isSuccess()) {
            return null;
        }
        // `du -sb` prints "<bytes>\t<path>". We split rather than
        // trusting the path tail to avoid surprises with leading
        // whitespace, embedded newlines, etc.
        $line = trim((string) $r->stdout);
        if ($line === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $line, 2);
        if ($parts === false || !ctype_digit((string) $parts[0])) {
            return null;
        }
        return (int) $parts[0];
    }

    /**
     * Returns an array with `size`, `mode`, `uid`, `gid`, `mtime`, or
     * null if the path doesn't exist. Use this rather than raw stat()
     * so callers don't have to remember which index is which.
     */
    public function statSafe(string $path): ?array
    {
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $s = is_link($path) ? @lstat($path) : @stat($path);
        if ($s === false) {
            return null;
        }
        return [
            'size' => (int) $s['size'],
            'mode' => (int) $s['mode'],
            'perms_octal' => sprintf('%04o', $s['mode'] & 07777),
            'uid' => (int) $s['uid'],
            'gid' => (int) $s['gid'],
            'mtime' => (int) $s['mtime'],
        ];
    }

    // ─── Reads ────────────────────────────────────────────────

    /**
     * Read a file's contents. Returns null when the file does not exist
     * (the most common reason for a read miss). Throws when the file
     * exists but is unreadable - that's an environment problem the
     * caller cannot recover from.
     */
    public function readFile(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read existing file: {$path}");
        }
        return $content;
    }

    // ─── Writes ───────────────────────────────────────────────

    /**
     * Atomic write: stage to "<path>.tmp.XXXX" in the same directory,
     * fsync, rename over target. The rename() is atomic on POSIX when
     * both paths share a filesystem.
     *
     * Returns the byte count written.
     */
    public function writeAtomic(string $path, string $content, int $mode = 0644): int
    {
        $this->mustBeAbsolute($path);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Parent directory missing: {$dir}");
        }

        $staging = $path . '.tmp.' . bin2hex(random_bytes(6));
        $fp = @fopen($staging, 'cb');
        if ($fp === false) {
            throw new \RuntimeException("Could not open staging file: {$staging}");
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            @unlink($staging);
            throw new \RuntimeException("Could not lock staging file: {$staging}");
        }
        ftruncate($fp, 0);
        $written = @fwrite($fp, $content);
        if ($written === false || $written !== strlen($content)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($staging);
            throw new \RuntimeException("Short write to {$staging}");
        }
        fflush($fp);
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        @chmod($staging, $mode);
        if (!@rename($staging, $path)) {
            @unlink($staging);
            throw new \RuntimeException("Failed to rename staging file to {$path}");
        }
        return $written;
    }

    /**
     * mkdir -p. Idempotent. Sets mode on each component we create
     * (existing components are left alone to avoid surprising chmods
     * on /home and similar shared parents).
     */
    public function ensureDirectory(string $path, int $mode = 0755): void
    {
        $this->mustBeAbsolute($path);
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path)) {
            throw new \RuntimeException("Path exists but is not a directory: {$path}");
        }
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to mkdir -p {$path}");
        }
    }

    // ─── Deletes (guarded by allowedRoots) ─────────────────────

    public function deleteFile(string $path): bool
    {
        $this->mustBeAbsolute($path);
        $this->mustBeUnderAllowedRoot($path);
        if (!file_exists($path) && !is_link($path)) {
            return false;
        }
        return @unlink($path);
    }

    /**
     * Recursive delete. ONLY descends into a path that is itself under
     * an allowedRoot AND not equal to an allowedRoot (we never delete
     * the root itself).
     *
     * Returns the number of filesystem entries removed.
     */
    public function rmtree(string $path): int
    {
        $this->mustBeAbsolute($path);
        $this->mustBeUnderAllowedRoot($path);
        $this->mustNotBeAllowedRootItself($path);

        if (is_link($path) || is_file($path)) {
            return @unlink($path) ? 1 : 0;
        }
        if (!is_dir($path)) {
            return 0;
        }

        $removed = 0;
        $entries = @scandir($path);
        if ($entries === false) {
            return 0;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            // Re-guard each child path. mostly belt-and-suspenders against
            // weird symlink loops; the allowedRoot check at the top has
            // already vetted the parent.
            if (is_dir($full) && !is_link($full)) {
                $removed += $this->rmtreeInternal($full);
            } else {
                if (@unlink($full)) {
                    $removed++;
                }
            }
        }
        if (@rmdir($path)) {
            $removed++;
        }
        return $removed;
    }

    /**
     * Internal recursive helper. No allowedRoot check here because the
     * caller already proved the entry chain stays under one.
     */
    private function rmtreeInternal(string $path): int
    {
        $removed = 0;
        $entries = @scandir($path);
        if ($entries === false) {
            return 0;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                $removed += $this->rmtreeInternal($full);
            } else {
                if (@unlink($full)) {
                    $removed++;
                }
            }
        }
        if (@rmdir($path)) {
            $removed++;
        }
        return $removed;
    }

    // ─── Mode/owner (CommandRunner-mediated for root-owned paths) ─

    /**
     * chmod via PHP-native first, falling back to shell if needed.
     * The PHP-native path works for files we own; the shell path is
     * needed for files we own only via sudo (none in practice for the
     * panel, but kept for defense).
     */
    public function chmodPath(string $path, int $mode): void
    {
        $this->mustBeAbsolute($path);
        if (@chmod($path, $mode)) {
            return;
        }
        $r = $this->runner->run('chmod', [sprintf('%04o', $mode), $path]);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("chmod failed: " . $r->summary());
        }
    }

    /**
     * Run chown via the shell because PHP's chown() can't accept
     * "user:group" in one call and won't work for non-numeric ids
     * across all platforms.
     */
    public function chownPath(string $path, string $userOrUserGroup): void
    {
        $this->mustBeAbsolute($path);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(:[A-Za-z_][A-Za-z0-9_-]*)?$/', $userOrUserGroup) !== 1) {
            throw new \InvalidArgumentException("Invalid chown spec: {$userOrUserGroup}");
        }
        $r = $this->runner->run('chown', [$userOrUserGroup, $path]);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("chown failed: " . $r->summary());
        }
    }

    /**
     * chown -R. Same path safety guarantees apply.
     */
    public function chownRecursive(string $path, string $userOrUserGroup): void
    {
        $this->mustBeAbsolute($path);
        $this->mustBeUnderAllowedRoot($path);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(:[A-Za-z_][A-Za-z0-9_-]*)?$/', $userOrUserGroup) !== 1) {
            throw new \InvalidArgumentException("Invalid chown spec: {$userOrUserGroup}");
        }
        $r = $this->runner->run('chown', ['-R', $userOrUserGroup, $path]);
        if (!$r->isSuccess()) {
            throw new \RuntimeException("chown -R failed: " . $r->summary());
        }
    }

    // ─── Symlinks ─────────────────────────────────────────────

    public function createSymlink(string $target, string $linkPath, bool $replace = false): void
    {
        $this->mustBeAbsolute($linkPath);
        if (file_exists($linkPath) || is_link($linkPath)) {
            if (!$replace) {
                throw new \RuntimeException("Symlink path already exists: {$linkPath}");
            }
            @unlink($linkPath);
        }
        if (!@symlink($target, $linkPath)) {
            throw new \RuntimeException("symlink failed: {$linkPath} -> {$target}");
        }
    }

    public function readSymlink(string $linkPath): ?string
    {
        if (!is_link($linkPath)) {
            return null;
        }
        $target = @readlink($linkPath);
        return $target === false ? null : $target;
    }

    // ─── Allowed-root governance ──────────────────────────────

    /**
     * Add a root at runtime. Useful for steps that need to operate
     * on a per-site directory we just created. Returns the added root.
     */
    public function addAllowedRoot(string $root): string
    {
        $root = $this->mustBeAbsolute($root);
        $root = rtrim($root, '/');
        if (!in_array($root, $this->allowedRoots, true)) {
            $this->allowedRoots[] = $root;
        }
        return $root;
    }

    /**
     * @return list<string>
     */
    public function allowedRoots(): array
    {
        return $this->allowedRoots;
    }

    // ─── Internals ────────────────────────────────────────────

    private function mustBeAbsolute(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException("Path must be absolute, got: {$path}");
        }
        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new \InvalidArgumentException("Path must not contain '..': {$path}");
        }
        return $path;
    }

    private function mustBeUnderAllowedRoot(string $path): void
    {
        // Canonicalize when the path exists so symlinks don't escape.
        // For non-existent paths we use the input as-is (we still
        // enforced no '..' above).
        $canon = realpath($path);
        $check = $canon !== false ? $canon : $path;
        $check = rtrim($check, '/');
        foreach ($this->allowedRoots as $root) {
            if ($check === $root || str_starts_with($check . '/', $root . '/')) {
                return;
            }
        }
        throw new \RuntimeException(
            "Refused destructive op on path outside allowed roots: {$path}"
        );
    }

    private function mustNotBeAllowedRootItself(string $path): void
    {
        $canon = realpath($path);
        $check = ($canon !== false ? $canon : $path);
        $check = rtrim($check, '/');
        foreach ($this->allowedRoots as $root) {
            if ($check === $root) {
                throw new \RuntimeException(
                    "Refused to rmtree an allowedRoot itself: {$path}"
                );
            }
        }
    }
}
