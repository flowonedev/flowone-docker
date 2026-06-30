<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Writes append-only rows to `site_audit_log`.
 *
 * Distinct from the panel-wide `audit_logs` table (generic) - this one is
 * site-specific and carries structured before/after JSON snapshots so the
 * full history of a site is reconstructable.
 *
 * Contract:
 *   - Every destructive or sensitive action must call AuditLogger::record().
 *   - This includes state transitions, secret reads, suspend/resume/archive/restore,
 *     manual config edits, orphan import/destroy, force-destroy.
 *   - Reads happen via /sites/{domain}/audit on the API.
 *
 * Security:
 *   - before/after snapshots are passed through SecretMasker before write
 *     so credentials never leak into the audit log.
 *   - The audit logger NEVER swallows errors - if it cannot write, the
 *     calling action must abort. We refuse to perform destructive actions
 *     without a durable audit trail.
 */
final class AuditLogger
{
    public function __construct(
        private readonly PanelDatabase $database,
        private readonly SecretMasker $masker
    ) {
    }

    /**
     * Persist one audit row. Returns the inserted id.
     *
     * @throws \PDOException if the write fails - callers MUST abort their work.
     */
    public function record(
        string $action,
        ?string $siteDomain,
        ?string $reason,
        ?array $before,
        ?array $after,
        ActorContext $actor,
        ?int $jobId = null
    ): int {
        $pdo = $this->database->pdo();

        // Capture submillisecond precision so events within a single transition
        // can still be ordered deterministically.
        $occurredAt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');

        $stmt = $pdo->prepare(
            'INSERT INTO site_audit_log
              (occurred_at, action, site_domain,
               actor_user_id, actor_username, source_ip, api_token_id, user_agent,
               reason, before_snapshot, after_snapshot,
               job_id, request_id)
             VALUES
              (:occurred_at, :action, :site_domain,
               :actor_user_id, :actor_username, :source_ip, :api_token_id, :user_agent,
               :reason, :before_snapshot, :after_snapshot,
               :job_id, :request_id)'
        );

        $stmt->execute([
            'occurred_at' => $occurredAt,
            'action' => $action,
            'site_domain' => $siteDomain,
            'actor_user_id' => $actor->userId,
            'actor_username' => $actor->username,
            'source_ip' => $actor->sourceIp,
            'api_token_id' => $actor->apiTokenId,
            'user_agent' => $actor->userAgent,
            'reason' => $reason,
            'before_snapshot' => $before !== null
                ? json_encode($this->masker->maskArray($before), JSON_UNESCAPED_SLASHES)
                : null,
            'after_snapshot' => $after !== null
                ? json_encode($this->masker->maskArray($after), JSON_UNESCAPED_SLASHES)
                : null,
            'job_id' => $jobId,
            'request_id' => $actor->requestId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Convenience for the most common case: action with a site context but no
     * structured diff (e.g. a secret read or a manual override).
     */
    public function note(
        string $action,
        ?string $siteDomain,
        string $reason,
        ActorContext $actor
    ): int {
        return $this->record(
            action: $action,
            siteDomain: $siteDomain,
            reason: $reason,
            before: null,
            after: null,
            actor: $actor,
        );
    }
}
