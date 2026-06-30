<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 3 per-tenant read/write probe.
 *
 * The Phase 1 root probe (writes /mnt/nas-drive/.flowone_rw_probe_*)
 * tells us whether the mount itself is writable, but it can't catch
 * tenant-specific failures — e.g. the NAS share is mounted rw but a
 * subdirectory ACL or quota denies writes to /mnt/nas-drive/backups/
 * specifically.
 *
 * To keep total probe load bounded we ROTATE through active tenants:
 * one tenant probed per monitor cycle (probe interval default 10s).
 * With 2 active tenants (drive + backups) each tenant is checked
 * every 20s, which is far below the staleness threshold of 120s.
 *
 * Probes write a 64-byte random file, read it back, then unlink. The
 * file name carries a recognisable prefix .flowone_tenant_probe_* per
 * server-side-testing.mdc.
 *
 * Probe outcomes feed back into the Phase 2 read breaker (latency +
 * error rate) so a single-tenant slowdown also surfaces in the
 * overall breaker state.
 */
final class TenantProber
{
    private TenantResolver $resolver;
    private int $rwBytes;
    private int $cursor = 0;
    private ?OperationJournal $journal;

    public function __construct(TenantResolver $resolver, int $rwBytes = 64, ?OperationJournal $journal = null)
    {
        $this->resolver = $resolver;
        $this->rwBytes  = max(8, $rwBytes);
        $this->journal  = $journal;
    }

    public static function fromConfig(?array $config = null, ?OperationJournal $journal = null): self
    {
        $config = $config ?? Config::load();
        return new self(
            resolver: TenantResolver::fromConfig($config),
            rwBytes:  (int) ($config['probe']['rw_probe_bytes'] ?? 64),
            journal:  $journal,
        );
    }

    /**
     * Probe the next active tenant in round-robin order. Returns null
     * when there are no active tenants (chaos disabled and no real
     * tenants would be unusual; null lets the caller skip cleanly).
     *
     * Result keys:
     *   tenant       string
     *   status       'ok' | 'error' | 'skipped'
     *   message      human-readable
     *   duration_sec float
     *   bytes        int (only on ok)
     *
     * @return array<string,mixed>|null
     */
    public function probeNext(): ?array
    {
        $active = $this->resolver->activeNames();
        if (count($active) === 0) {
            return null;
        }
        $tenant = $active[$this->cursor % count($active)];
        $this->cursor++;
        return $this->probeOne($tenant);
    }

    /**
     * @return array<string,mixed>
     */
    public function probeOne(string $tenant): array
    {
        $start = MonotonicClock::nowNs();
        $result = [
            'tenant'       => $tenant,
            'status'       => 'error',
            'message'      => '',
            'duration_sec' => 0.0,
        ];

        try {
            $root = $this->resolver->rootFor($tenant);
            if (!is_dir($root)) {
                $result['status']  = 'skipped';
                $result['message'] = "tenant root {$root} does not exist (run TenantBootstrap)";
                $this->journal?->record('tenant_probe_skipped_no_root', [
                    'tenant' => $tenant, 'root' => $root,
                ]);
                return $this->finalise($result, $start);
            }
            $payload  = random_bytes($this->rwBytes);
            $fileName = '.flowone_tenant_probe_' . getmypid() . '_' . bin2hex(random_bytes(4));
            $absPath  = $this->resolver->pathInside($tenant, $fileName);

            $written = @file_put_contents($absPath, $payload);
            if ($written !== strlen($payload)) {
                $result['message'] = "write failed at {$absPath}";
                $this->journal?->record('tenant_probe_write_failed', [
                    'tenant' => $tenant, 'path' => $absPath,
                ]);
                return $this->finalise($result, $start);
            }
            $readBack = @file_get_contents($absPath);
            @unlink($absPath);
            if ($readBack !== $payload) {
                $result['message'] = "read-back mismatch at {$absPath}";
                $this->journal?->record('tenant_probe_read_mismatch', [
                    'tenant' => $tenant, 'path' => $absPath,
                ]);
                return $this->finalise($result, $start);
            }
            $result['status']  = 'ok';
            $result['message'] = 'rw probe ok';
            $result['bytes']   = strlen($payload);
        } catch (\Throwable $e) {
            $result['message'] = 'exception: ' . $e->getMessage();
            $this->journal?->record('tenant_probe_exception', [
                'tenant' => $tenant, 'error' => $e->getMessage(),
            ]);
        }
        return $this->finalise($result, $start);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function finalise(array $r, int $startNs): array
    {
        $r['duration_sec'] = round(MonotonicClock::elapsedSec($startNs), 4);
        return $r;
    }
}
