<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * The single seam through which every adapter speaks to the host OS.
 *
 * Why this exists:
 *   - Tests need to stub out shell commands. With `exec()` sprinkled
 *     across 30 actions we can't reliably mock anything.
 *   - Timeouts are critical for provisioning - a hung `certbot` or
 *     `lswsctrl restart` must not block the worker indefinitely.
 *   - Capturing stdout/stderr/exit/duration in one place makes for a
 *     uniform "what did this command do" log line.
 *
 * Implementations:
 *   - ProcessCommandRunner: production implementation using proc_open
 *     with SIGTERM-then-SIGKILL timeout policy.
 *   - FakeCommandRunner (test-only, in tests/lib): records calls and
 *     replays scripted CommandResult instances. Lives next to the test
 *     harness, not in agent/.
 *
 * Contract:
 *   - $binary must be an absolute path OR a name resolvable via PATH.
 *     We do NOT shell-escape and re-invoke through /bin/sh; arguments
 *     are passed as an array so shell metacharacters in $args are
 *     literal, not interpreted. This eliminates an entire class of
 *     injection bugs.
 *   - $stdin, when non-null, is fed to the child's stdin and the pipe
 *     is closed immediately.
 *   - $timeoutSeconds is a wallclock budget. On expiry the runner
 *     sends SIGTERM, waits up to 2 seconds for graceful exit, then
 *     SIGKILL. CommandResult::$timedOut is true and exitCode is -1.
 *   - $env, when non-null, REPLACES the child's environment (no
 *     inheritance). When null, the parent's $_ENV is inherited.
 *   - $cwd, when non-null, changes the child's working directory.
 *
 * NEVER throws on non-zero exit. CommandResult::isSuccess() is the
 * caller's check. The runner throws ONLY when the OS refuses to start
 * the process (proc_open returned false, fork() failed, etc).
 */
interface CommandRunner
{
    /**
     * @param string                     $binary         Absolute path or PATH-resolvable name
     * @param list<string>               $args           Literal args (no shell interpolation)
     * @param string|null                $stdin          Optional stdin content
     * @param int                        $timeoutSeconds Wallclock timeout
     * @param array<string,string>|null  $env            Replaces child env; null = inherit
     * @param string|null                $cwd            Child working directory
     */
    public function run(
        string $binary,
        array $args = [],
        ?string $stdin = null,
        int $timeoutSeconds = 30,
        ?array $env = null,
        ?string $cwd = null
    ): CommandResult;
}
