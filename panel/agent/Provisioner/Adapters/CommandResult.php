<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Immutable record of one CommandRunner invocation.
 *
 * Adapters return this directly when callers care about the full
 * outcome. Most adapter methods unwrap it into a typed result instead
 * (e.g. MysqlAdapter::listDatabases() returns list<string>, not a
 * CommandResult), but the raw form is always available for diagnostics.
 *
 * Convention for $exitCode:
 *   - 0 on success
 *   - >0 on failure (whatever the binary returned)
 *   - -1 if the runner killed the process due to timeout
 *   - -2 if the runner aborted before the child started (rare; OS error)
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $durationSeconds,
        public readonly bool $timedOut,
        /**
         * The actual command line that was launched, for log replay.
         * No secret values appear here because adapters pass secrets via
         * stdin or env, never as positional args.
         */
        public readonly string $commandLine
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function isFailure(): bool
    {
        return !$this->isSuccess();
    }

    /**
     * Compact summary for log lines / events. Capped to keep the event
     * row a reasonable size even if a binary went wild on stderr.
     */
    public function summary(int $excerptBytes = 200): string
    {
        $marker = $this->timedOut ? 'TIMEOUT' : "exit={$this->exitCode}";
        $stdout = $this->shorten($this->stdout, $excerptBytes);
        $stderr = $this->shorten($this->stderr, $excerptBytes);
        return sprintf(
            '%s [%s] stdout=%s stderr=%s (%.0fms)',
            $this->commandLine,
            $marker,
            $stdout === '' ? '<empty>' : $stdout,
            $stderr === '' ? '<empty>' : $stderr,
            $this->durationSeconds * 1000
        );
    }

    private function shorten(string $s, int $max): string
    {
        $s = trim($s);
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max - 3) . '...';
    }
}
