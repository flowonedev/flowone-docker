<?php

namespace Webmail\Core;

/**
 * Deferred work queue — run side-effects AFTER the HTTP response has been
 * sent to the client.
 *
 * Problem this solves: controllers like MailboxController::message() spend
 * the bulk of their wall-clock time on work the browser doesn't need to
 * wait for:
 *   - Meilisearch indexing of the email body
 *   - parsing the body for @mentions and persisting them
 *   - bumping a client-activity timestamp
 *
 * Pre-Deferred, all of that ran inline before `return Response::success()`,
 * so a "click to open" felt like a few-hundred-millisecond pause even on
 * a cache hit.
 *
 * Mechanism:
 *   1. The controller calls `Deferred::after(fn() => doExpensiveThing());`
 *      while building the response.
 *   2. `Response::send()` (under PHP-FPM / LSAPI) calls
 *      `fastcgi_finish_request()` — the body is on the wire, the client
 *      TCP connection is closed, the browser starts rendering.
 *   3. `public/index.php` then calls `Deferred::flush()`, which runs every
 *      queued callback inside its own try/catch so one failure can't
 *      poison the rest.
 *
 * Callers must NOT throw uncaught exceptions from a deferred callback —
 * the queue catches and logs Throwables but a controller depending on
 * "this work definitely happened" should NOT defer it.
 *
 * Test/CLI usage: callbacks still run, they just run before the script
 * exits (because there's no fastcgi connection to close first). That
 * keeps test ordering deterministic.
 */
class Deferred
{
    /** @var array<int, array{0:string, 1:callable}> queued [label, callable] pairs */
    private static array $queue = [];

    private static bool $flushing = false;

    /**
     * Register a callable to run AFTER the response is sent. The label
     * is purely diagnostic — it goes into error_log() if the callable
     * throws, so name it something searchable.
     */
    public static function after(string $label, callable $work): void
    {
        // If we're already inside flush() (a deferred job tried to defer
        // more work), run it inline — we can't reorder a flush in
        // progress, and we don't want to silently drop work either.
        if (self::$flushing) {
            try {
                $work();
            } catch (\Throwable $e) {
                error_log('[Deferred] inline (during flush) ' . $label . ' failed: ' . $e->getMessage());
            }
            return;
        }
        self::$queue[] = [$label, $work];
    }

    /**
     * Drain the queue. Safe to call multiple times — already-run items
     * are removed. Call this from public/index.php immediately after
     * `$response->send()`.
     */
    public static function flush(): void
    {
        if (self::$flushing) return;
        self::$flushing = true;
        try {
            while (!empty(self::$queue)) {
                [$label, $work] = array_shift(self::$queue);
                $started = microtime(true);
                try {
                    $work();
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[Deferred] %s failed after %dms: %s',
                        $label,
                        (int) ((microtime(true) - $started) * 1000),
                        $e->getMessage()
                    ));
                }
            }
        } finally {
            self::$flushing = false;
        }
    }

    /**
     * Diagnostic — number of jobs currently queued. Useful in tests.
     */
    public static function pending(): int
    {
        return count(self::$queue);
    }

    /**
     * Test helper — drop every queued job without running it.
     */
    public static function clearForTests(): void
    {
        self::$queue = [];
    }

    private function __construct() {}
    private function __clone() {}
}
