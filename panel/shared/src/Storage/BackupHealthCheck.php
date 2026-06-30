<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Backup destination probe.
 *
 * Cheap inline checks the runner performs before each snapshot:
 *
 *   1. destination_mount exists AND is a real mount (not a stub dir
 *      left over after an unmount)
 *   2. healthcheck_file under destination_mount is present
 *   3. destination_root is writable (best-effort touch + delete)
 *
 * Returns a HealthReport. Runner aborts the snapshot on any failure
 * and journals the reason — silent backup failures are the worst
 * kind, so we'd rather refuse loudly than write to /tmp by accident.
 */
final class BackupHealthCheck
{
    public function __construct(
        private string $destinationMount,
        private string $destinationRoot,
        private string $healthcheckFile,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $b = $cfg['backup'] ?? [];
        return new self(
            destinationMount: (string) ($b['destination_mount'] ?? '/mnt/vps-backup'),
            destinationRoot:  (string) ($b['destination_root']  ?? '/mnt/vps-backup/drive-snapshots'),
            healthcheckFile:  (string) ($b['healthcheck_file']  ?? '.healthcheck'),
        );
    }

    /**
     * @return array{ok: bool, reasons: list<string>, mount: string,
     *               root: string, root_writable: bool}
     */
    public function probe(): array
    {
        $reasons = [];

        // rtrim('/', '/') returns '' — never strip away a bare root
        // mount. Keep the raw value when trimming would empty it.
        $mount = rtrim($this->destinationMount, '/');
        if ($mount === '') {
            $mount = $this->destinationMount;
        }
        if (!is_dir($mount)) {
            $reasons[] = "destination_mount missing: {$mount}";
        }

        // Strict mount check: /proc/mounts must contain the mount
        // point. Without this we'd happily write to a stub directory
        // if the backup NFS share went away.
        if (!$this->isMounted($mount)) {
            $reasons[] = "destination_mount not in /proc/mounts: {$mount}";
        }

        // Healthcheck path: avoid producing '//etc/hostname' on
        // mount='/'. The leading slash from the mount is sufficient.
        $healthPath = ($mount === '/' ? '/' : $mount . '/') . ltrim($this->healthcheckFile, '/');
        if (!is_file($healthPath)) {
            $reasons[] = "healthcheck file missing: {$healthPath}";
        }

        $root = rtrim($this->destinationRoot, '/');
        if (!is_dir($root) && !@mkdir($root, 0755, true)) {
            $reasons[] = "destination_root cannot be created: {$root}";
        }

        $writable = false;
        if (is_dir($root)) {
            $writable = $this->writeProbe($root);
            if (!$writable) {
                $reasons[] = "destination_root not writable: {$root}";
            }
        }

        return [
            'ok'            => empty($reasons),
            'reasons'       => $reasons,
            'mount'         => $mount,
            'root'          => $root,
            'root_writable' => $writable,
        ];
    }

    private function isMounted(string $path): bool
    {
        // /proc/mounts is the most reliable source on Linux; fall back
        // to /etc/mtab on systems where /proc isn't mounted.
        $sources = ['/proc/mounts', '/etc/mtab'];
        foreach ($sources as $src) {
            if (!is_readable($src)) continue;
            $fh = @fopen($src, 'r');
            if ($fh === false) continue;
            try {
                while (($line = fgets($fh)) !== false) {
                    $cols = preg_split('/\s+/', $line, 3);
                    if (($cols[1] ?? '') === $path) {
                        return true;
                    }
                }
            } finally {
                @fclose($fh);
            }
        }
        return false;
    }

    private function writeProbe(string $root): bool
    {
        $probe = $root . '/.flowone_backup_probe_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $ok = @file_put_contents($probe, 'ok') !== false;
        @unlink($probe);
        return $ok;
    }
}
