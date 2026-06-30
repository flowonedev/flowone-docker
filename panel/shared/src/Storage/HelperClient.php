<?php

declare(strict_types=1);

namespace FlowOne\Storage;

use FlowOne\Storage\Exceptions\HelperRpcException;

/**
 * Unix-socket RPC client to the privileged storage helper.
 *
 * Wire format: one JSON request per line, one JSON response per line.
 *
 *   request  -> { "id": <int>, "action": "<name>", "args": {...}, "boot_epoch": <int> }
 *   response -> { "id": <int>, "ok": true|false, "data": {...} | null, "error": "..." | null }
 *
 * Allowed actions are gated server-side; the client just forwards strings.
 *
 * Authentication: relies on SO_PEERCRED on the helper side. The helper
 * matches the connecting UID against its allowed-peer config and refuses
 * mismatches. The client itself does not authenticate the helper — it
 * trusts the socket's filesystem permissions (root:flowone-storage, 0660).
 */
final class HelperClient
{
    public function __construct(
        private string $socketPath,
        private int $timeoutSec = 30,
    ) {}

    public static function fromConfig(): self
    {
        $config = Config::load();
        return new self(
            (string) $config['helper']['socket_path'],
            (int) $config['helper']['rpc_timeout_sec'],
        );
    }

    /**
     * Smoke-check: returns true if the helper responds to a "ping" within
     * 2 seconds. Used by storage-ctl and the monitor daemon at startup.
     */
    public function ping(): bool
    {
        try {
            $resp = $this->call('ping', [], timeoutSec: 2);
            return ($resp['ok'] ?? false) === true;
        } catch (HelperRpcException) {
            return false;
        }
    }

    /**
     * Execute an RPC and return the decoded response.
     *
     * @param array<string,mixed> $args
     * @return array{ok:bool, data:mixed, error:?string}
     */
    public function call(string $action, array $args = [], ?int $timeoutSec = null, int $bootEpoch = 0): array
    {
        if (!file_exists($this->socketPath)) {
            throw new HelperRpcException("helper socket missing: {$this->socketPath}");
        }

        $effectiveTimeout = $timeoutSec ?? $this->timeoutSec;

        $sock = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $effectiveTimeout,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            throw new HelperRpcException("connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, $effectiveTimeout);

        try {
            $req = [
                'id'         => random_int(1, PHP_INT_MAX),
                'action'     => $action,
                'args'       => $args,
                'boot_epoch' => $bootEpoch,
            ];
            $line = json_encode($req, JSON_UNESCAPED_SLASHES) . "\n";
            $written = @fwrite($sock, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new HelperRpcException("short write to helper socket");
            }

            $resp = @stream_get_line($sock, 1024 * 1024, "\n");
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                throw new HelperRpcException("helper RPC timed out after {$effectiveTimeout}s");
            }
            if ($resp === false || $resp === '') {
                throw new HelperRpcException("empty response from helper");
            }
            $decoded = json_decode($resp, true);
            if (!is_array($decoded) || !isset($decoded['ok'])) {
                throw new HelperRpcException("malformed helper response: " . substr($resp, 0, 200));
            }

            return [
                'ok'    => (bool) $decoded['ok'],
                'data'  => $decoded['data'] ?? null,
                'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
            ];
        } finally {
            @fclose($sock);
        }
    }
}
