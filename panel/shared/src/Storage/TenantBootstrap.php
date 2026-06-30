<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 3 tenant directory bootstrap.
 *
 * Called once at monitord startup (when phase3_tenant_layout = true)
 * and again whenever the NAS transitions from offline to readable.
 * Idempotent: only creates dirs that don't already exist.
 *
 * Refuses to do anything if the mount point is unreachable, so a
 * Synology outage doesn't generate a thousand "Permission denied"
 * journal lines.
 *
 * Permissions: we set 0755 with current owner (the daemon user,
 * flowone-storage). Consumers in later phases that need different
 * ownership for their tenant root (e.g. www-data for drive uploads
 * via FPM) can chown via the helper RPC after Phase 5 lands.
 */
final class TenantBootstrap
{
    public function __construct(
        private TenantResolver $resolver,
        private string $mountPoint,
        private ?OperationJournal $journal = null,
    ) {}

    public static function fromConfig(?array $config = null, ?OperationJournal $journal = null): self
    {
        $config = $config ?? Config::load();
        return new self(
            resolver:   TenantResolver::fromConfig($config),
            mountPoint: (string) $config['nas']['mount_point'],
            journal:    $journal,
        );
    }

    /**
     * Returns a structured result for every active tenant.
     *
     * Result entry keys:
     *   tenant         tenant name
     *   path           absolute path attempted
     *   created        true if mkdir created a new directory this call
     *   exists         true if the path exists after the call
     *   writable       is_writable() result
     *   error          string message on failure, null otherwise
     *
     * @return list<array<string,mixed>>
     */
    public function ensureAll(): array
    {
        $results = [];

        // Mount sanity: refuse to bootstrap if the mount itself isn't readable.
        $mountReal = realpath($this->mountPoint);
        if ($mountReal === false || !is_dir($mountReal)) {
            $this->journal?->record('tenant_bootstrap_skipped_mount_unreachable', [
                'mount_point' => $this->mountPoint,
            ]);
            return $results;
        }

        foreach ($this->resolver->activeNames() as $name) {
            $results[] = $this->ensureOne($name);
        }
        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureOne(string $name): array
    {
        $entry = [
            'tenant'   => $name,
            'path'     => null,
            'created'  => false,
            'exists'   => false,
            'writable' => false,
            'error'    => null,
        ];

        try {
            $root = $this->resolver->rootFor($name);
            $entry['path'] = $root;

            $existedBefore = is_dir($root);
            if (!$existedBefore) {
                // mkdir is best-effort; NFS may report success even if
                // remote-side ACLs reject later writes. We check
                // writability after the call to surface that.
                $ok = @mkdir($root, 0755, true);
                $entry['created'] = $ok && is_dir($root);
                if (!$ok && !is_dir($root)) {
                    $err = error_get_last();
                    $entry['error'] = $err['message'] ?? 'mkdir failed';
                    $this->journal?->record('tenant_bootstrap_mkdir_failed', [
                        'tenant' => $name,
                        'path'   => $root,
                        'error'  => $entry['error'],
                    ]);
                    return $entry;
                }
            }
            $entry['exists']   = is_dir($root);
            $entry['writable'] = is_writable($root);

            if ($entry['created']) {
                $this->journal?->record('tenant_bootstrap_created', [
                    'tenant' => $name,
                    'path'   => $root,
                ]);
            }
        } catch (\Throwable $e) {
            $entry['error'] = $e->getMessage();
            $this->journal?->record('tenant_bootstrap_error', [
                'tenant' => $name,
                'error'  => $e->getMessage(),
            ]);
        }
        return $entry;
    }
}
