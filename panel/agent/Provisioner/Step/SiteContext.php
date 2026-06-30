<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

use VpsAdmin\Agent\Provisioner\Adapters\Adapters;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Runtime context passed to every step's check/execute/compensate/verify call.
 *
 * Carries everything a step needs to do its work WITHOUT it having to
 * know about job queues, sockets, or process management. This is the
 * narrow waist of the architecture - if a step needs a service that
 * isn't on SiteContext, we add it here once for everyone.
 *
 * Immutable. Workers build it fresh per job; steps treat it as
 * read-only.
 *
 * Adapters (OlsAdapter, MysqlAdapter, PostfixAdapter, ...) get added
 * to this DTO in Step 3 when we build them. Foundation steps only
 * need the panel-level services.
 */
final class SiteContext
{
    /**
     * @param array<string, mixed> $siteRow Current `sites` row (decoded).
     *                                      Step is allowed to read fields like
     *                                      domain, sftp_user, home_dir, etc.
     *                                      Steps MUST NOT mutate this map -
     *                                      the worker rebuilds the SiteContext
     *                                      with the fresh row after each step.
     * @param array<string, mixed> $payload Original job payload (e.g. POST /sites body).
     */
    public function __construct(
        public readonly array $siteRow,
        public readonly int $jobId,
        public readonly string $requestId,
        public readonly ActorContext $actor,
        public readonly AuditLogger $audit,
        public readonly SecretVault $vault,
        public readonly ServerCapabilities $capabilities,
        public readonly PanelDatabase $database,
        public readonly array $payload = [],
        public readonly bool $dryRun = false,
        /**
         * Hard deadline. If `microtime(true)` exceeds this, the step
         * should return StepResult::timeout() rather than start any new
         * external call. The worker enforces a wall-clock SIGKILL on
         * the subprocess but graceful timeout produces a better
         * StepState for resume.
         */
        public readonly ?float $deadlineUnixMicro = null,
        /**
         * Infrastructure adapters bundle. NULL during the bootstrap
         * window before adapters are wired (foundation tests and the
         * first compilation passes). Steps that need an adapter check
         * `$ctx->adapters() ?: throw new \RuntimeException(...)` or
         * declare it as a hard dependency in their docstring.
         */
        public readonly ?Adapters $adapters = null
    ) {
    }

    public function adapters(): ?Adapters
    {
        return $this->adapters;
    }

    /**
     * Convenience that throws when the SiteContext was constructed
     * without adapters. Steps that need adapters should use this rather
     * than the nullable property so the failure surface is clear.
     */
    public function requireAdapters(): Adapters
    {
        if ($this->adapters === null) {
            throw new \RuntimeException(
                "SiteContext was built without an Adapters bundle - this code path requires one"
            );
        }
        return $this->adapters;
    }

    public function domain(): string
    {
        return (string) ($this->siteRow['domain'] ?? '');
    }

    public function siteId(): int
    {
        return (int) ($this->siteRow['id'] ?? 0);
    }

    public function hasCapability(string $key): bool
    {
        return $this->capabilities->has($key);
    }

    /**
     * Check how much wallclock time we have left. Useful for steps that
     * decide whether to start a new external call or punt to retry.
     */
    public function remainingMs(): ?int
    {
        if ($this->deadlineUnixMicro === null) {
            return null;
        }
        $remaining = $this->deadlineUnixMicro - microtime(true);
        return $remaining > 0 ? (int) ($remaining * 1000) : 0;
    }

    public function isDeadlineExceeded(): bool
    {
        return $this->deadlineUnixMicro !== null
            && microtime(true) >= $this->deadlineUnixMicro;
    }

    /**
     * Convenience for steps that want to emit a structured log line
     * without a full event. The worker collects these via the same
     * channel as StepEvent.
     */
    public function logger(): array
    {
        // Returning the trace fields - the worker wraps them into a log.
        return [
            'job_id' => $this->jobId,
            'request_id' => $this->requestId,
            'domain' => $this->domain(),
            'actor' => $this->actor->username,
        ];
    }
}
