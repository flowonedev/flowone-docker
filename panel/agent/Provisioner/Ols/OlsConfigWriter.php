<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

use VpsAdmin\Agent\Provisioner\Exceptions\OlsValidationException;
use VpsAdmin\Agent\Provisioner\Ols\Ast\Document;

/**
 * Atomic OLS config writer.
 *
 * Why this exists:
 *   - The legacy `file_put_contents($mainConfig, ...)` is NOT atomic. A
 *     crash mid-write produces a truncated config; OLS refuses to start.
 *   - Editing in place provides no recovery if the new content is bad.
 *   - The legacy code did make a single `.bak` copy, but it overwrites
 *     itself on the next call, so rollback to "last known good" is a
 *     race.
 *
 * What this does:
 *   1. Stages the new content in a sibling temp file (same directory so
 *      rename() is guaranteed atomic on the same filesystem).
 *   2. Optionally calls a $validator(string $stagedPath) closure that
 *      can run `lswsctrl test` against the staged file via a flag flip,
 *      OR do structural sanity checks. If the validator throws,
 *      OlsConfigWriter unlinks the staged file and rethrows. The
 *      original config is untouched.
 *   3. Copies the current file to a timestamped backup
 *      (httpd_config.conf.backup.YYYY-MM-DD_HH-mm-ss) so we have an
 *      append-only history, and to the canonical `.bak` slot for
 *      one-step rollback by hand.
 *   4. rename() the staged file over the original. POSIX rename is
 *      atomic. After this point, OLS sees the new content on its next
 *      restart.
 *   5. Restores ownership (lsadm:nogroup) and permissions (0644).
 *   6. Optionally prunes old timestamped backups beyond a retention
 *      window so the directory doesn't grow without bound.
 *
 * If ANY step fails, the writer leaves either the previous file intact
 * (early failure) or has the timestamped backup available for manual
 * rollback (later failure).
 */
final class OlsConfigWriter
{
    public const DEFAULT_OWNER = 'lsadm:nogroup';
    public const DEFAULT_MODE = 0644;
    public const DEFAULT_BACKUP_RETENTION = 30;

    public function __construct(
        /**
         * Closure to run shell commands. Injection seam so tests can
         * stub out chown/chmod. Signature:
         *   fn(string $binary, array $args): array{exit:int, stdout:string, stderr:string}
         */
        private readonly ?\Closure $execCommand = null,
        private readonly string $owner = self::DEFAULT_OWNER,
        private readonly int $mode = self::DEFAULT_MODE,
        private readonly int $backupRetention = self::DEFAULT_BACKUP_RETENTION
    ) {
    }

    /**
     * Atomically replace the file at $targetPath with the rendered
     * Document. Returns metadata about the operation for the audit log.
     *
     * @param callable|null $validator Optional fn(string $stagedPath): void.
     *                                 Throws OlsValidationException to abort.
     *
     * @return array{
     *   target: string,
     *   bytes: int,
     *   timestamped_backup: string,
     *   rolling_backup: string,
     *   pruned: int
     * }
     */
    public function write(string $targetPath, Document $document, ?callable $validator = null): array
    {
        $rendered = $document->toString();
        // Defensive: the OLS daemon expects a trailing newline.
        if ($rendered === '' || substr($rendered, -1) !== "\n") {
            $rendered .= "\n";
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException("OLS config directory missing: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                "OLS config directory not writable: {$dir} (writer runs as " . $this->whoAmI() . ")"
            );
        }

        $stagedPath = $targetPath . '.staging.' . bin2hex(random_bytes(6));
        $bytes = $this->writeStaged($stagedPath, $rendered);

        try {
            if ($validator !== null) {
                $validator($stagedPath);
            }
        } catch (\Throwable $e) {
            @unlink($stagedPath);
            throw $e;
        }

        $timestampedBackup = $targetPath . '.backup.' . date('Y-m-d_H-i-s') . '.' . bin2hex(random_bytes(2));
        $rollingBackup = $targetPath . '.bak';

        if (is_file($targetPath)) {
            if (!@copy($targetPath, $timestampedBackup)) {
                @unlink($stagedPath);
                throw new \RuntimeException("Failed to write timestamped backup to {$timestampedBackup}");
            }
            // Rolling backup is best-effort - if it fails we still have
            // the timestamped one.
            @copy($targetPath, $rollingBackup);
        }

        // Atomic swap.
        if (!@rename($stagedPath, $targetPath)) {
            @unlink($stagedPath);
            throw new \RuntimeException("Failed to rename staged config over {$targetPath}");
        }

        $this->restoreOwnershipAndPermissions($targetPath);

        $pruned = $this->pruneOldBackups($targetPath);

        return [
            'target' => $targetPath,
            'bytes' => $bytes,
            'timestamped_backup' => $timestampedBackup,
            'rolling_backup' => $rollingBackup,
            'pruned' => $pruned,
        ];
    }

    /**
     * Render the document to a sibling temp file with strict error
     * handling and fsync. Returns the byte count written.
     */
    private function writeStaged(string $stagedPath, string $content): int
    {
        $fp = @fopen($stagedPath, 'cb');
        if ($fp === false) {
            throw new \RuntimeException("Could not open staging file {$stagedPath} for writing");
        }
        // Lock to prevent two concurrent writers landing on the same
        // staging path - improbable due to random suffix, but cheap.
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new \RuntimeException("Could not lock staging file {$stagedPath}");
        }
        ftruncate($fp, 0);
        $written = fwrite($fp, $content);
        if ($written === false || $written !== strlen($content)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($stagedPath);
            throw new \RuntimeException(
                sprintf("Short write to %s (wrote %s of %d bytes)", $stagedPath, var_export($written, true), strlen($content))
            );
        }
        fflush($fp);
        // fsync() lets the kernel push the bytes to durable storage so a
        // power loss between rename and commit doesn't return an empty
        // file. Not catastrophic if it's missing (rename() will still
        // succeed) but safer.
        if (function_exists('fsync')) {
            @fsync($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $written;
    }

    /**
     * Run `chown` and `chmod` on the new file so OLS can still read it.
     * Tolerates exec failures by logging via stderr trace - we do NOT
     * abort the write here because the rename already succeeded and
     * reverting would require knowing the pre-write permissions exactly.
     */
    private function restoreOwnershipAndPermissions(string $path): void
    {
        if ($this->execCommand !== null) {
            try {
                ($this->execCommand)('chown', [$this->owner, $path]);
            } catch (\Throwable) {
                // best effort
            }
            try {
                ($this->execCommand)('chmod', [sprintf('%04o', $this->mode), $path]);
            } catch (\Throwable) {
                // best effort
            }
            return;
        }

        // Fallback: PHP-native chmod (chown requires root and may fail).
        @chmod($path, $this->mode);
    }

    /**
     * Drop timestamped backups older than the configured retention count.
     * Returns the number of files removed. The rolling `.bak` slot is
     * never touched.
     */
    private function pruneOldBackups(string $targetPath): int
    {
        $pattern = $targetPath . '.backup.*';
        $files = glob($pattern);
        if ($files === false || count($files) <= $this->backupRetention) {
            return 0;
        }
        // Sort oldest-first by modification time.
        usort($files, static fn($a, $b) => filemtime($a) <=> filemtime($b));
        $excess = count($files) - $this->backupRetention;
        $removed = 0;
        for ($i = 0; $i < $excess; $i++) {
            if (@unlink($files[$i])) {
                $removed++;
            }
        }
        return $removed;
    }

    private function whoAmI(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            return $info['name'] ?? 'unknown';
        }
        return getenv('USER') ?: 'unknown';
    }
}
