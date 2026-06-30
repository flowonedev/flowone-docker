<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 5 tier byte mover.
 *
 * Pure I/O primitive: copies bytes between VPS local disk and a NAS
 * tenant subpath, with a verify-after-copy step that proves the
 * destination really has the bytes we expected before we tell the
 * caller "ok". No DB awareness, no state-machine knowledge — those
 * live one layer up in the worker / recall service.
 *
 * Safety guarantees:
 *
 *   1. Streaming copy (fread/fwrite in chunks). Never slurps a whole
 *      file into RAM, so a 5 GB tier-down doesn't OOM the daemon.
 *
 *   2. Atomic destination: writes to a sibling tempfile with a
 *      ".flowone_inflight_*" prefix, fsyncs it, then rename()s into
 *      place. Either the destination ends up complete or it doesn't
 *      exist at all — never half-written.
 *
 *   3. Tenant-safety: every destination path goes through
 *      TenantResolver::pathInside(), which refuses path traversal,
 *      absolute paths, and symlink escapes. Then a second check via
 *      Invariants::assertPathInsideTenant() locks it in.
 *
 *   4. Checksum verify: after rename, the destination is re-read and
 *      its MD5 must equal the expected checksum. If not, the
 *      destination is unlink()ed and the operation reports failure;
 *      we never declare a tier-down successful when the bytes differ.
 *
 *   5. Per-file mutex: caller provides a MountLock keyed by file
 *      identity so two concurrent workers can't race on the same
 *      file. (Worker uses file_id; recall uses the same.)
 *
 * Both directions (tierDown VPS->NAS, recall NAS->VPS) use the same
 * core routine to avoid drift between them.
 */
final class TierBytesMover
{
    public const CHUNK_BYTES = 1 << 20; // 1 MiB

    public function __construct(
        private TenantResolver $resolver,
        private Invariants $invariants,
        private ?OperationJournal $journal = null,
    ) {}

    public static function fromConfig(?array $config = null, ?OperationJournal $journal = null): self
    {
        return new self(
            resolver: TenantResolver::fromConfig($config),
            invariants: new Invariants($journal, strict: false),
            journal: $journal,
        );
    }

    /**
     * Copy from VPS local disk to a NAS tenant subpath. After rename
     * + verify, the destination's MD5 must equal $expectedMd5.
     *
     * @return array{ok:bool,bytes:int,duration_ms:int,actual_checksum:?string,destination:?string,error:?string}
     */
    public function tierDown(
        string $vpsAbsPath,
        string $tenant,
        string $relativeUnderTenant,
        string $expectedMd5
    ): array {
        $start = MonotonicClock::nowNs();
        $result = [
            'ok'              => false,
            'bytes'           => 0,
            'duration_ms'     => 0,
            'actual_checksum' => null,
            'destination'     => null,
            'error'           => null,
        ];

        try {
            if (!is_file($vpsAbsPath)) {
                $result['error'] = "source missing on vps: {$vpsAbsPath}";
                return $this->finalise($result, $start);
            }
            if (!is_readable($vpsAbsPath)) {
                $result['error'] = "source unreadable: {$vpsAbsPath}";
                return $this->finalise($result, $start);
            }

            // Resolve destination through TenantResolver (tenant safety #1).
            $destAbs = $this->resolver->pathInside($tenant, $relativeUnderTenant);
            $tenantRoot = $this->resolver->rootFor($tenant);

            // Defence in depth (tenant safety #2).
            if (!$this->invariants->assertPathInsideTenant($destAbs, $tenantRoot)) {
                $result['error'] = "path safety check refused destination: {$destAbs}";
                return $this->finalise($result, $start);
            }

            $destDir = dirname($destAbs);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $err = error_get_last()['message'] ?? 'mkdir failed';
                $result['error'] = "could not create destination dir {$destDir}: {$err}";
                return $this->finalise($result, $start);
            }

            $written = $this->streamCopyAtomic($vpsAbsPath, $destAbs);
            if ($written === null) {
                $result['error'] = 'stream copy failed (see journal)';
                return $this->finalise($result, $start);
            }
            $result['bytes'] = $written;
            $result['destination'] = $destAbs;

            // Verify the destination matches the expected checksum.
            $actual = @md5_file($destAbs);
            $result['actual_checksum'] = is_string($actual) ? $actual : null;
            if (!is_string($actual) || !hash_equals(strtolower($expectedMd5), strtolower($actual))) {
                @unlink($destAbs);
                $result['error'] = sprintf(
                    'checksum mismatch after tier-down: expected=%s got=%s (destination removed)',
                    $expectedMd5,
                    $actual ?: 'unreadable'
                );
                $this->journal?->record('tier_down_checksum_mismatch', [
                    'src' => $vpsAbsPath,
                    'dst' => $destAbs,
                    'expected' => $expectedMd5,
                    'actual'   => $actual ?: null,
                ]);
                return $this->finalise($result, $start);
            }

            $result['ok'] = true;
            $this->journal?->record('tier_down_ok', [
                'src' => $vpsAbsPath,
                'dst' => $destAbs,
                'bytes' => $written,
            ]);
        } catch (\Throwable $e) {
            $result['error'] = 'exception: ' . $e->getMessage();
            $this->journal?->record('tier_down_exception', [
                'src' => $vpsAbsPath,
                'tenant' => $tenant,
                'relative' => $relativeUnderTenant,
                'error' => $e->getMessage(),
            ]);
        }
        return $this->finalise($result, $start);
    }

    /**
     * Copy from a NAS tenant subpath to VPS local disk. After rename
     * + verify, the destination's MD5 must equal $expectedMd5.
     *
     * @return array{ok:bool,bytes:int,duration_ms:int,actual_checksum:?string,destination:?string,error:?string}
     */
    public function recall(
        string $tenant,
        string $relativeUnderTenant,
        string $vpsAbsPath,
        string $expectedMd5
    ): array {
        $start = MonotonicClock::nowNs();
        $result = [
            'ok'              => false,
            'bytes'           => 0,
            'duration_ms'     => 0,
            'actual_checksum' => null,
            'destination'     => null,
            'error'           => null,
        ];

        try {
            $srcAbs = $this->resolver->pathInside($tenant, $relativeUnderTenant);
            $tenantRoot = $this->resolver->rootFor($tenant);
            if (!$this->invariants->assertPathInsideTenant($srcAbs, $tenantRoot)) {
                $result['error'] = "path safety check refused source: {$srcAbs}";
                return $this->finalise($result, $start);
            }
            if (!is_file($srcAbs)) {
                $result['error'] = "source missing on nas: {$srcAbs}";
                $this->journal?->record('recall_source_missing', [
                    'tenant' => $tenant, 'relative' => $relativeUnderTenant, 'abs' => $srcAbs,
                ]);
                return $this->finalise($result, $start);
            }
            if (!is_readable($srcAbs)) {
                $result['error'] = "source unreadable: {$srcAbs}";
                return $this->finalise($result, $start);
            }

            $destDir = dirname($vpsAbsPath);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $err = error_get_last()['message'] ?? 'mkdir failed';
                $result['error'] = "could not create vps dir {$destDir}: {$err}";
                return $this->finalise($result, $start);
            }

            $written = $this->streamCopyAtomic($srcAbs, $vpsAbsPath);
            if ($written === null) {
                $result['error'] = 'stream copy failed (see journal)';
                return $this->finalise($result, $start);
            }
            $result['bytes'] = $written;
            $result['destination'] = $vpsAbsPath;

            $actual = @md5_file($vpsAbsPath);
            $result['actual_checksum'] = is_string($actual) ? $actual : null;
            if (!is_string($actual) || !hash_equals(strtolower($expectedMd5), strtolower($actual))) {
                @unlink($vpsAbsPath);
                $result['error'] = sprintf(
                    'checksum mismatch after recall: expected=%s got=%s (destination removed)',
                    $expectedMd5,
                    $actual ?: 'unreadable'
                );
                $this->journal?->record('recall_checksum_mismatch', [
                    'src' => $srcAbs,
                    'dst' => $vpsAbsPath,
                    'expected' => $expectedMd5,
                    'actual'   => $actual ?: null,
                ]);
                return $this->finalise($result, $start);
            }

            $result['ok'] = true;
            $this->journal?->record('recall_ok', [
                'src' => $srcAbs,
                'dst' => $vpsAbsPath,
                'bytes' => $written,
            ]);
        } catch (\Throwable $e) {
            $result['error'] = 'exception: ' . $e->getMessage();
            $this->journal?->record('recall_exception', [
                'tenant' => $tenant,
                'relative' => $relativeUnderTenant,
                'error' => $e->getMessage(),
            ]);
        }
        return $this->finalise($result, $start);
    }

    /**
     * Best-effort fsync + unlink of a VPS file after a tier-down has
     * been verified. Called by the destructive-mode tier worker. Idempotent.
     */
    public function unlinkVpsCopy(string $vpsAbsPath): bool
    {
        if (!file_exists($vpsAbsPath)) {
            return true; // idempotent
        }
        $ok = @unlink($vpsAbsPath);
        if (!$ok) {
            $this->journal?->record('vps_unlink_failed', [
                'path' => $vpsAbsPath,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);
        }
        return $ok;
    }

    /**
     * Stream-copy $src to $dst via a sibling tempfile then atomic
     * rename. Returns the number of bytes written, or null on
     * failure (with journal record). Caller is responsible for the
     * dest directory existing.
     */
    private function streamCopyAtomic(string $src, string $dst): ?int
    {
        $tmp = $dst . '.flowone_inflight_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $bytes = 0;
        $sh = null;
        $dh = null;
        try {
            $sh = @fopen($src, 'rb');
            if ($sh === false) {
                $this->journal?->record('mover_open_src_failed', ['src' => $src]);
                return null;
            }
            $dh = @fopen($tmp, 'wb');
            if ($dh === false) {
                fclose($sh);
                $this->journal?->record('mover_open_dst_failed', ['dst' => $tmp]);
                return null;
            }
            while (!feof($sh)) {
                $buf = fread($sh, self::CHUNK_BYTES);
                if ($buf === false) {
                    fclose($sh); fclose($dh); @unlink($tmp);
                    $this->journal?->record('mover_read_failed', ['src' => $src]);
                    return null;
                }
                if ($buf === '') {
                    break;
                }
                $w = fwrite($dh, $buf);
                if ($w === false || $w !== strlen($buf)) {
                    fclose($sh); fclose($dh); @unlink($tmp);
                    $this->journal?->record('mover_short_write', [
                        'dst' => $tmp,
                        'expected' => strlen($buf),
                        'got' => $w === false ? 'false' : (string) $w,
                    ]);
                    return null;
                }
                $bytes += strlen($buf);
            }
            // Best-effort fsync the destination before rename. fflush
            // is always available; fsync requires PHP 8.1+ but the
            // server has 8.3 so this is fine.
            @fflush($dh);
            if (function_exists('fsync')) {
                @fsync($dh);
            }
            fclose($sh); $sh = null;
            fclose($dh); $dh = null;

            // Atomic rename.
            if (!@rename($tmp, $dst)) {
                @unlink($tmp);
                $this->journal?->record('mover_rename_failed', [
                    'tmp' => $tmp, 'dst' => $dst,
                    'error' => error_get_last()['message'] ?? 'unknown',
                ]);
                return null;
            }
            return $bytes;
        } catch (\Throwable $e) {
            if (is_resource($sh)) fclose($sh);
            if (is_resource($dh)) fclose($dh);
            if (file_exists($tmp)) @unlink($tmp);
            $this->journal?->record('mover_exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return array{ok:bool,bytes:int,duration_ms:int,actual_checksum:?string,destination:?string,error:?string}
     */
    private function finalise(array $r, int $startNs): array
    {
        $r['duration_ms'] = (int) round(MonotonicClock::elapsedSec($startNs) * 1000);
        /** @var array{ok:bool,bytes:int,duration_ms:int,actual_checksum:?string,destination:?string,error:?string} $r */
        return $r;
    }
}
