<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

use VpsAdmin\Agent\Provisioner\Exceptions\CommandLaunchException;

/**
 * proc_open-based CommandRunner.
 *
 * Implementation notes:
 *   - We pass $binary + $args as an array to proc_open which uses an
 *     execve-style direct call. NO /bin/sh layer means metacharacters
 *     in $args are literal. This is the safe path.
 *   - We poll the pipes non-blocking and rely on a single tick loop
 *     to read both streams + check the wallclock + handle timeouts.
 *   - On timeout we send SIGTERM, give the child up to 2 seconds to
 *     clean up, then SIGKILL. This matches systemd's default policy.
 *   - We never use exec/system/shell_exec/passthru anywhere - those
 *     spawn a shell and would let secrets leak via /proc/<pid>/cmdline.
 *
 * Caveats:
 *   - proc_open with an array first arg requires PHP 7.4+ (we have 8.3).
 *   - posix_kill is required for SIGTERM/SIGKILL. The ext-posix
 *     extension is part of the default lsphp83 build; on hosts without
 *     it we fall back to proc_terminate which only sends SIGTERM.
 */
final class ProcessCommandRunner implements CommandRunner
{
    /**
     * Grace period after SIGTERM before escalating to SIGKILL.
     */
    private const SIGTERM_GRACE_SECONDS = 2.0;

    /**
     * Poll interval for the read loop. 20ms balances responsiveness
     * against syscall pressure.
     */
    private const POLL_INTERVAL_USEC = 20_000;

    /**
     * Soft cap on captured output per stream to defend against runaway
     * binaries. The full payload is still available, just truncated.
     * 4 MB per stream is way more than any sane CLI tool emits.
     */
    private const STREAM_CAPACITY_BYTES = 4 * 1024 * 1024;

    public function run(
        string $binary,
        array $args = [],
        ?string $stdin = null,
        int $timeoutSeconds = 30,
        ?array $env = null,
        ?string $cwd = null
    ): CommandResult {
        $argv = $this->buildArgv($binary, $args);
        $commandLine = $this->formatCommandLine($argv);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];

        // proc_open returns a process resource we can inspect.
        $proc = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
        if ($proc === false || !is_resource($proc)) {
            throw new CommandLaunchException(
                $binary,
                "proc_open returned false (cwd=" . ($cwd ?? '<inherit>') . ")"
            );
        }

        try {
            // Feed stdin first then close.
            if ($stdin !== null && $stdin !== '') {
                $this->writeStdinAndClose($pipes[0], $stdin);
            } else {
                fclose($pipes[0]);
            }
            // Always close the parent's write end of stdin so the child
            // sees EOF.
            unset($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $startedAt = microtime(true);
            $deadline = $startedAt + max(1, $timeoutSeconds);
            $stdout = '';
            $stderr = '';
            $timedOut = false;
            $stdoutCapped = false;
            $stderrCapped = false;
            $observedExit = null;

            while (true) {
                $status = proc_get_status($proc);
                $running = (bool) ($status['running'] ?? false);

                // Drain whatever the child has emitted so far.
                if (!$stdoutCapped) {
                    $chunk = $this->readNonBlocking($pipes[1]);
                    if ($chunk !== '') {
                        $stdout .= $chunk;
                        if (strlen($stdout) >= self::STREAM_CAPACITY_BYTES) {
                            $stdout = substr($stdout, 0, self::STREAM_CAPACITY_BYTES);
                            $stdoutCapped = true;
                        }
                    }
                }
                if (!$stderrCapped) {
                    $chunk = $this->readNonBlocking($pipes[2]);
                    if ($chunk !== '') {
                        $stderr .= $chunk;
                        if (strlen($stderr) >= self::STREAM_CAPACITY_BYTES) {
                            $stderr = substr($stderr, 0, self::STREAM_CAPACITY_BYTES);
                            $stderrCapped = true;
                        }
                    }
                }

                if (!$running) {
                    // Capture the exit code at the FIRST observation of
                    // termination. proc_get_status reports the real code
                    // only once; a subsequent call (or proc_close after
                    // the child has been reaped) returns -1. Discarding it
                    // here was surfacing spurious exitCode=-1 for fast
                    // children (chown, groupadd, ...) in the long-lived
                    // agent process.
                    $code = $status['exitcode'] ?? null;
                    if (is_int($code) && $code !== -1) {
                        $observedExit = $code;
                    }
                    // Final drain after the child exited - there may be
                    // bytes still queued on the pipes.
                    $stdout .= $this->drainStream($pipes[1]);
                    $stderr .= $this->drainStream($pipes[2]);
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    $this->terminate($proc, $status['pid'] ?? null);
                    // Drain remaining output after termination.
                    $stdout .= $this->drainStream($pipes[1]);
                    $stderr .= $this->drainStream($pipes[2]);
                    break;
                }

                usleep(self::POLL_INTERVAL_USEC);
            }

            $exitCode = $this->waitAndClose($proc, $pipes, $observedExit);
            if ($timedOut) {
                // Mark timeouts distinctly. The OS may report 143 (SIGTERM)
                // or 137 (SIGKILL) but our convention is -1 for timeout.
                $exitCode = -1;
            }

            return new CommandResult(
                exitCode: $exitCode,
                stdout: $stdout,
                stderr: $stderr,
                durationSeconds: microtime(true) - $startedAt,
                timedOut: $timedOut,
                commandLine: $commandLine,
            );
        } catch (\Throwable $e) {
            // Ensure we never leak the process on an exception.
            $this->safeClose($proc, $pipes);
            throw $e;
        }
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function buildArgv(string $binary, array $args): array
    {
        $argv = [$binary];
        foreach ($args as $a) {
            // Force string coercion so callers can pass ints (e.g. UIDs).
            $argv[] = (string) $a;
        }
        return $argv;
    }

    /**
     * @param list<string> $argv
     */
    private function formatCommandLine(array $argv): string
    {
        // Just for display. No shell-escaping needed because the runner
        // doesn't actually re-parse this anywhere.
        $parts = [];
        foreach ($argv as $a) {
            if ($a === '' || preg_match('/[\s\'"\\\\]/', $a) === 1) {
                $parts[] = "'" . str_replace("'", "'\\''", $a) . "'";
            } else {
                $parts[] = $a;
            }
        }
        return implode(' ', $parts);
    }

    private function writeStdinAndClose($pipe, string $payload): void
    {
        $written = 0;
        $total = strlen($payload);
        while ($written < $total) {
            $n = @fwrite($pipe, substr($payload, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
        @fflush($pipe);
        @fclose($pipe);
    }

    private function readNonBlocking($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        $out = '';
        while (true) {
            $chunk = @fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        return $out;
    }

    private function drainStream($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        stream_set_blocking($pipe, true);
        $out = '';
        while (!feof($pipe)) {
            $chunk = @fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        return $out;
    }

    /**
     * SIGTERM, wait, SIGKILL escalation. Defends against children that
     * ignore SIGTERM (legitimately busy in a syscall, or buggy).
     */
    private function terminate($proc, $pid): void
    {
        if (function_exists('posix_kill') && $pid) {
            @posix_kill((int) $pid, defined('SIGTERM') ? SIGTERM : 15);
        } else {
            @proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
        }

        $until = microtime(true) + self::SIGTERM_GRACE_SECONDS;
        while (microtime(true) < $until) {
            $status = proc_get_status($proc);
            if (!($status['running'] ?? false)) {
                return;
            }
            usleep(self::POLL_INTERVAL_USEC);
        }

        if (function_exists('posix_kill') && $pid) {
            @posix_kill((int) $pid, defined('SIGKILL') ? SIGKILL : 9);
        } else {
            @proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
        }
    }

    /**
     * Close pipes and reap the child. Returns the actual exit code or
     * -2 if the process state can't be determined.
     */
    private function waitAndClose($proc, array $pipes, ?int $observedExit = null): int
    {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        // If the poll loop already captured the real exit code at the
        // moment of termination, trust it: proc_get_status/proc_close
        // here would return -1 because the child is already reaped.
        if ($observedExit !== null) {
            @proc_close($proc);
            return $observedExit;
        }
        $status = proc_get_status($proc);
        $exit = $status['exitcode'] ?? -2;
        // proc_close returns the exit code if proc_get_status hasn't
        // reaped it yet. Either way, we end up with a sane integer.
        $closed = proc_close($proc);
        if (!is_int($exit) || $exit === -1) {
            $exit = $closed;
        }
        return (int) $exit;
    }

    private function safeClose($proc, array $pipes): void
    {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }
}
