<?php

namespace Webmail\Services;

/**
 * Single-line JSON formatter for the [ALLMAIL] / mailbox-folder logs.
 *
 * The plan codifies a fixed set of keys (request_id, account_id, folder_id,
 * folder_path, scan_mode, fallback_stage, duration_ms, from_state, to_state,
 * reason). This helper enforces that contract and makes it trivial to grep
 * by event type later.
 *
 * Usage:
 *   error_log(StructuredLog::line('allmail_skip', [
 *       'folder_path' => $folder,
 *       'reason' => 'imap_fetch_overview returned false',
 *   ]));
 *
 * The "evt" key always wins. request_id is auto-attached if absent.
 * Unknown keys are passed through (we want flexibility for new signals
 * without redeploying the logger), but the order of the canonical keys is
 * preserved for grep-friendliness.
 */
final class StructuredLog
{
    /** Channel tag prepended to every line so legacy grep -i ALLMAIL still works. */
    public const CHANNEL = '[ALLMAIL]';

    /** @var string[] Canonical key order for greppable lines. */
    private const CANONICAL_KEYS = [
        'evt',
        'request_id',
        'account_id',
        'user_email',
        'folder_id',
        'folder_path',
        'scan_mode',
        'fallback_stage',
        'duration_ms',
        'from_state',
        'to_state',
        'reason',
        'provider_type',
    ];

    /**
     * Per-request context: keys auto-attached to every emit() call until
     * clearContext() is invoked or the PHP process exits. This lets the
     * controller layer register `provider_type` once after IMAP connect
     * instead of threading it through every Service-level call site.
     *
     * Per-call context wins over the registered context if they collide.
     *
     * @var array<string,scalar|null>
     */
    private static array $context = [];

    /**
     * Set (or extend) the per-request context. Call once after IMAP
     * connect to attach `provider_type`, etc. Repeated calls merge.
     *
     * @param array<string,scalar|null> $kv
     */
    public static function setContext(array $kv): void
    {
        foreach ($kv as $k => $v) {
            self::$context[$k] = $v;
        }
    }

    /**
     * Drop all per-request context. Useful between two unrelated
     * requests served by the same long-lived worker (e.g. CLI tools).
     */
    public static function clearContext(): void
    {
        self::$context = [];
    }

    /**
     * Build a single log line. Returns the full string ready for error_log().
     */
    public static function line(string $evt, array $context = []): string
    {
        // Per-request context first; per-call values can override.
        $merged = self::$context;
        foreach ($context as $k => $v) {
            $merged[$k] = $v;
        }
        $merged['evt'] = $evt;
        if (!isset($merged['request_id'])) {
            $merged['request_id'] = CorrelationId::current();
        }

        $ordered = [];
        foreach (self::CANONICAL_KEYS as $k) {
            if (array_key_exists($k, $merged)) {
                $ordered[$k] = $merged[$k];
                unset($merged[$k]);
            }
        }
        foreach ($merged as $k => $v) {
            $ordered[$k] = $v;
        }

        return self::CHANNEL . ' ' . json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Convenience: emit a line directly via error_log().
     */
    public static function emit(string $evt, array $context = []): void
    {
        error_log(self::line($evt, $context));
    }
}
