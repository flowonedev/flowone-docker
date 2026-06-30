<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown by ProcessCommandRunner when the OS refuses to start a child
 * process at all (proc_open returned false, fork failed, binary not
 * found and we asked for strict resolution).
 *
 * NOT thrown on non-zero exit - that returns a normal CommandResult.
 * This distinction is critical: callers may swallow a non-zero exit
 * after inspecting stderr, but a launch failure means the host
 * environment is broken and the worker should bail.
 */
final class CommandLaunchException extends \RuntimeException
{
    public function __construct(
        public readonly string $binary,
        string $reason,
        ?\Throwable $previous = null
    ) {
        parent::__construct("Failed to launch '{$binary}': {$reason}", 0, $previous);
    }
}
