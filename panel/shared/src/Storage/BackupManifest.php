<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Per-snapshot signed inventory.
 *
 * Walks a snapshot directory, computes per-file (size, mtime, md5?)
 * fingerprints, and writes a single HMAC-signed JSON envelope at
 * the snapshot root. Operators (and the verifier) read this envelope
 * back to detect:
 *
 *   - missing files       (manifest claims file X, disk has no file X)
 *   - size drift          (manifest says 1024 bytes, disk says 1023)
 *   - mtime drift         (manifest says ts=T, disk says ts=T')
 *   - bit-rot             (--full-checksum mode only: md5 mismatch)
 *   - unauthorised edits  (any tampering invalidates the HMAC)
 *
 * Manifest envelope shape:
 *
 *   {
 *     "snapshot": { "kind": "daily", "date_key": "2026-05-18", "root_path": "..." },
 *     "tenants":  ["drive"],
 *     "files":    { "drive/<userhash>/<filename>": { "size": N, "mtime": T, "md5": "..." }, ... },
 *     "summary":  { "file_count": N, "byte_count": B, "computed_at": T },
 *     "options":  { "full_checksum": false }
 *   }
 *
 * The whole payload is then wrapped by HmacSigner::signToJson().
 *
 * Manifests are intentionally chunky (one big file vs one-per-tenant)
 * because:
 *   - readers want atomicity ("is THIS snapshot good?")
 *   - tampering one tenant must invalidate the whole snapshot,
 *     forcing operator attention
 *   - even a 100k-file manifest is < 10 MiB and easy to ship
 */
final class BackupManifest
{
    public function __construct(
        private HmacSigner $signer,
        private string     $manifestName,
        private bool       $includeMd5 = false,
    ) {}

    public static function fromConfig(array $cfg, HmacSigner $signer): self
    {
        $m = $cfg['backup']['manifest'] ?? [];
        return new self(
            signer:       $signer,
            manifestName: (string) ($m['name']          ?? 'manifest.json.sig'),
            includeMd5:   (bool)   ($m['full_checksum'] ?? false),
        );
    }

    public function manifestName(): string { return $this->manifestName; }

    /**
     * Walk $snapshot's root (or $explicitRoot when set — needed
     * during atomic promotion when the manifest is written into
     * the .tmp directory before rename) and produce + write the
     * signed manifest. Returns the manifest payload (pre-sign).
     */
    public function buildAndWrite(BackupSnapshot $snapshot, array $tenants, ?string $explicitRoot = null): array
    {
        $files = [];
        $byteTotal = 0;
        $root = $explicitRoot !== null ? rtrim($explicitRoot, '/') : $snapshot->rootPath();

        foreach ($tenants as $tenant) {
            $tenantDir = $root . '/' . $tenant;
            if (!is_dir($tenantDir)) continue;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tenantDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iter as $info) {
                if (!$info->isFile()) continue;
                $abs = $info->getPathname();
                $rel = ltrim(str_replace($root, '', $abs), '/');
                $size = $info->getSize();
                $entry = [
                    'size'  => (int) $size,
                    'mtime' => (int) $info->getMTime(),
                ];
                if ($this->includeMd5) {
                    $md5 = @md5_file($abs);
                    if ($md5 === false || $md5 === '') {
                        throw new \RuntimeException("BackupManifest: cannot md5 {$abs}");
                    }
                    $entry['md5'] = $md5;
                }
                $files[$rel] = $entry;
                $byteTotal += (int) $size;
            }
        }

        $payload = [
            'snapshot' => $snapshot->toArray(),
            'tenants'  => array_values($tenants),
            'files'    => $files,
            'summary'  => [
                'file_count'  => count($files),
                'byte_count'  => $byteTotal,
                'computed_at' => time(),
            ],
            'options'  => [
                'full_checksum' => $this->includeMd5,
            ],
        ];

        $path = $root . '/' . $this->manifestName;
        $json = $this->signer->signToJson($payload);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException("BackupManifest: cannot write {$path}");
        }
        return $payload;
    }

    /**
     * Read + verify a previously-written manifest. Returns the
     * unwrapped payload on success, null on missing / corrupted /
     * signature-invalid.
     */
    public function read(BackupSnapshot $snapshot): ?array
    {
        $path = $snapshot->manifestPath($this->manifestName);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;
        $verified = $this->signer->verifyJson($raw);
        return is_array($verified) ? $verified : null;
    }
}
