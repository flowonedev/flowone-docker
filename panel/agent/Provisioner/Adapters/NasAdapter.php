<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Adapter for NAS (Synology over OpenVPN + NFS) availability checks.
 *
 * The panel needs this to gate steps that depend on the NAS being
 * online, e.g. backup-before-destroy, archive-to-NAS, restore-from-NAS.
 *
 * Why not reuse the email backend's NasHealthCheck:
 *   - That class is a static facade tied to the FlowOne shared
 *     library and the privileged storage daemon. Pulling that into
 *     the panel agent would couple two otherwise-independent systems.
 *   - The panel only needs a lightweight "is the mount alive" probe;
 *     anything fancier belongs in the steps that use it.
 *
 * What this adapter checks (in increasing depth):
 *   1. Mount point is a directory                  (cheap, ~µs)
 *   2. Mount point is actually mounted (`stat -c %m`)  (~ms)
 *   3. Mount point contains the .healthcheck file (~ms)
 *   4. Mount point is writable (creates + removes a probe file) (~tens of ms)
 *
 * Callers pick the depth they need via the `probeDepth` argument to
 * isAvailable(). Step layers commonly use BASIC for fast gating and
 * WRITABLE before destructive backup operations.
 */
final class NasAdapter
{
    public const PROBE_DEPTH_BASIC = 'basic';
    public const PROBE_DEPTH_HEALTHFILE = 'healthfile';
    public const PROBE_DEPTH_WRITABLE = 'writable';

    public const DEFAULT_MOUNT = '/mnt/nas-drive';
    public const DEFAULT_BACKUP_MOUNT = '/mnt/vps-backup';
    public const HEALTH_FILE = '.healthcheck';

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $mountPoint = self::DEFAULT_MOUNT,
        private readonly string $statBin = '/usr/bin/stat',
        /**
         * Whether to use the cheap `stat -c %m` mount check. Disabled
         * in environments where /usr/bin/stat is unavailable so we
         * fall through to the directory-exists check only.
         */
        private readonly bool $useStatMountCheck = true
    ) {
    }

    public function mountPoint(): string
    {
        return $this->mountPoint;
    }

    /**
     * Returns true if the NAS is reachable at the requested depth.
     * Never throws; non-availability is the normal happy path of "the
     * NAS is offline, skip this step".
     */
    public function isAvailable(string $probeDepth = self::PROBE_DEPTH_BASIC): bool
    {
        if (!is_dir($this->mountPoint)) {
            return false;
        }
        if ($this->useStatMountCheck && !$this->isActuallyMounted()) {
            return false;
        }
        if ($probeDepth === self::PROBE_DEPTH_BASIC) {
            return true;
        }
        if (!is_file($this->mountPoint . '/' . self::HEALTH_FILE)) {
            return false;
        }
        if ($probeDepth === self::PROBE_DEPTH_HEALTHFILE) {
            return true;
        }
        // WRITABLE: full round-trip
        return $this->canWriteProbe();
    }

    /**
     * Run all probes and return a structured snapshot. Used by the
     * reconciler and by smoke-test mode in test suites.
     *
     * @return array{
     *   mountPoint:string,
     *   exists:bool,
     *   mounted:bool,
     *   healthFile:bool,
     *   writable:bool,
     *   probeMs:int
     * }
     */
    public function diagnose(): array
    {
        $start = microtime(true);
        $exists = is_dir($this->mountPoint);
        $mounted = $exists && $this->isActuallyMounted();
        $healthFile = $mounted && is_file($this->mountPoint . '/' . self::HEALTH_FILE);
        $writable = $healthFile && $this->canWriteProbe();
        return [
            'mountPoint' => $this->mountPoint,
            'exists' => $exists,
            'mounted' => $mounted,
            'healthFile' => $healthFile,
            'writable' => $writable,
            'probeMs' => (int) ((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * Use coreutils `stat -c %m <path>` to discover the mount point
     * that owns $path. If it doesn't equal $this->mountPoint, the path
     * is on the underlying root fs, meaning the NAS mount silently
     * dropped. This catches "stale mountpoint with empty contents"
     * which `is_dir()` cheerfully reports as true.
     */
    private function isActuallyMounted(): bool
    {
        $r = $this->runner->run($this->statBin, ['-c', '%m', $this->mountPoint], null, 3);
        if (!$r->isSuccess()) {
            return false;
        }
        return trim($r->stdout) === $this->mountPoint;
    }

    private function canWriteProbe(): bool
    {
        $probe = $this->mountPoint . '/.flowone-probe-' . bin2hex(random_bytes(6));
        $payload = (string) microtime(true);
        try {
            $written = @file_put_contents($probe, $payload, LOCK_EX);
            if ($written === false || $written !== strlen($payload)) {
                return false;
            }
            $read = @file_get_contents($probe);
            return $read === $payload;
        } finally {
            if (is_file($probe)) {
                @unlink($probe);
            }
        }
    }
}
