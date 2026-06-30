<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Thrown by AdmissionController when an upload (or any new-bytes
 * operation) cannot be accepted because of system-wide storage
 * pressure — distinct from per-user quota exhaustion.
 *
 * Extends \RuntimeException so existing catch-blocks in DriveController
 * and other consumers pass it through unchanged (the user gets a
 * human-readable error message regardless). Consumers who want the
 * structured payload (HTTP 503 + Retry-After, telemetry hooks, etc.)
 * can catch the specific type and call `toHttpResponse()`.
 *
 * The exception is intentionally instance-safe to serialise / log:
 * no PDO handles, no journal references, just primitive fields.
 */
final class StorageBudgetExceededException extends \RuntimeException
{
    /**
     * @param array<int,string> $reasons
     */
    public function __construct(
        public readonly int    $bytesAttempted,
        public readonly string $watermark,
        public readonly array  $reasons,
        public readonly int    $retryAfterSec,
        public readonly ?StorageBudgetReport $report = null,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? $this->buildDefaultMessage(),
            503  // HTTP-ish code, used opportunistically by controllers
        );
    }

    /**
     * Format an HTTP 503 response shape ready for controllers:
     *   ['status_code' => 503, 'headers' => [...], 'body' => [...]]
     * Controllers can splat this into their JSON response + headers.
     *
     * @return array{
     *     status_code:int,
     *     headers:array<string,string>,
     *     body:array<string,mixed>,
     * }
     */
    public function toHttpResponse(): array
    {
        return [
            'status_code' => 503,
            'headers' => [
                'Retry-After' => (string) $this->retryAfterSec,
            ],
            'body' => [
                'success'         => false,
                'error'           => 'storage_budget_exceeded',
                'message'         => $this->getMessage(),
                'watermark'       => $this->watermark,
                'reasons'         => $this->reasons,
                'retry_after_sec' => $this->retryAfterSec,
                'bytes_attempted' => $this->bytesAttempted,
            ],
        ];
    }

    private function buildDefaultMessage(): string
    {
        $size = self::formatBytes($this->bytesAttempted);
        $why = empty($this->reasons) ? '' : ' (' . implode('; ', $this->reasons) . ')';
        return "Storage is temporarily full and cannot accept an upload of {$size}; "
             . "please try again in {$this->retryAfterSec}s{$why}";
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $exp = (int) floor(log($bytes, 1024));
        $exp = min($exp, count($units) - 1);
        return sprintf('%.2f %s', $bytes / (1024 ** $exp), $units[$exp]);
    }
}
