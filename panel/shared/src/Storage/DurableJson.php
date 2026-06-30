<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Triple-file durable JSON writer.
 *
 * Pattern (one writer, many readers):
 *
 *   1. write payload to {file}.tmp.{pid}.{rand}
 *   2. fsync(tmp)
 *   3. if current exists: rename(current -> {file}.bak)
 *   4. rename(tmp -> current)
 *   5. fsync(parent dir)
 *
 * Properties:
 *   - At any instant, at least one of {current, bak} contains a valid payload.
 *   - The atomic rename means current never appears partially written.
 *   - Power loss or crash mid-write at most loses the latest update; readers
 *     fall back to .bak.
 *   - Parent-dir fsync ensures the rename itself is durable, not just the
 *     file contents.
 *
 * The payload is opaque to this class — sign it with HmacSigner BEFORE
 * passing the JSON in. Reads return the raw JSON string; verification is
 * the caller's responsibility (StorageHealth does this).
 */
final class DurableJson
{
    public function __construct(
        private string $dir,
        private string $name,
        private string $tmpSuffix = '.tmp',
        private string $bakSuffix = '.bak',
    ) {}

    public function currentPath(): string
    {
        return rtrim($this->dir, '/') . '/' . $this->name;
    }

    public function backupPath(): string
    {
        return $this->currentPath() . $this->bakSuffix;
    }

    private function tmpPath(): string
    {
        return $this->currentPath()
            . $this->tmpSuffix
            . '.' . getmypid()
            . '.' . bin2hex(random_bytes(4));
    }

    /**
     * Write a JSON string durably. Throws on failure (caller must NOT swallow).
     */
    public function write(string $json): void
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("DurableJson: cannot create dir {$this->dir}");
            }
        }

        $tmp = $this->tmpPath();
        $current = $this->currentPath();
        $bak = $this->backupPath();

        // 1. Write tmp + fsync.
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("DurableJson: cannot open tmp {$tmp}");
        }
        try {
            $written = @fwrite($fh, $json);
            if ($written === false || $written !== strlen($json)) {
                throw new \RuntimeException("DurableJson: short write to {$tmp}");
            }
            if (function_exists('fflush')) {
                @fflush($fh);
            }
            if (function_exists('fsync')) {
                @fsync($fh);
            }
        } finally {
            @fclose($fh);
        }

        // 2. Atomic rotate.
        if (is_file($current)) {
            // Best-effort backup rotation; if rename fails (e.g. another writer
            // raced us, which should never happen with a single writer), we
            // log via error_log but still attempt the main rename so the
            // freshest state lands.
            if (!@rename($current, $bak)) {
                error_log("[DurableJson] backup rotate failed {$current} -> {$bak}");
            }
        }
        if (!@rename($tmp, $current)) {
            @unlink($tmp);
            throw new \RuntimeException("DurableJson: cannot promote {$tmp} -> {$current}");
        }

        // 3. fsync parent dir so the rename is durable.
        $this->fsyncDir($this->dir);
    }

    /**
     * Read current. Returns null if missing or unreadable.
     */
    public function readCurrent(): ?string
    {
        return $this->readFile($this->currentPath());
    }

    /**
     * Read backup. Returns null if missing or unreadable.
     */
    public function readBackup(): ?string
    {
        return $this->readFile($this->backupPath());
    }

    /**
     * Convenience: returns the first readable string from [current, bak],
     * along with a label indicating which one was used. Useful for
     * surfacing "is_stale" in client responses.
     *
     * @return array{string|null, 'current'|'backup'|'none'}
     */
    public function readAny(): array
    {
        $current = $this->readCurrent();
        if ($current !== null) {
            return [$current, 'current'];
        }
        $backup = $this->readBackup();
        if ($backup !== null) {
            return [$backup, 'backup'];
        }
        return [null, 'none'];
    }

    private function readFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }
        return $contents;
    }

    /**
     * fsync a directory so a rename within it is durable. On platforms
     * where fsync of a directory is unsupported (Windows, some shells),
     * fall back silently — the on-disk durability is then best-effort.
     */
    private function fsyncDir(string $dir): void
    {
        if (!function_exists('fsync')) {
            return;
        }
        if (stripos(PHP_OS, 'WIN') === 0) {
            return;
        }
        $fh = @opendir($dir);
        if ($fh === false) {
            return;
        }
        // We need a file descriptor for the directory itself, not a
        // dirhandle. opendir() gives us a dirhandle, which can't be fsynced.
        // Use a low-level workaround: open the directory for reading.
        @closedir($fh);
        $df = @fopen($dir, 'rb');
        if ($df === false) {
            return;
        }
        @fsync($df);
        @fclose($df);
    }
}
