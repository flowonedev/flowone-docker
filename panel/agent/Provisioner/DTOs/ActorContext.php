<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\DTOs;

/**
 * Identifies WHO did something, for audit purposes.
 *
 * Sources of an ActorContext:
 *   - HTTP request:    user from session/JWT + remote IP + user-agent
 *   - Worker:          "worker:<worker_id>" with the originating job's actor preserved
 *   - Reconciler:      "reconciler" with no user/IP
 *   - CLI script:      "cli:<script_name>" with no IP
 *
 * Immutable by design - if you need to enrich, build a new one with `with*()` helpers.
 */
final class ActorContext
{
    public function __construct(
        public readonly string $username,
        public readonly ?int $userId = null,
        public readonly ?string $sourceIp = null,
        public readonly ?string $apiTokenId = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestId = null,
        public readonly ?string $service = null
    ) {
    }

    public static function system(string $service = 'system'): self
    {
        return new self(
            username: $service,
            service: $service,
        );
    }

    public static function worker(string $workerId, ?ActorContext $original = null): self
    {
        return new self(
            username: $original?->username ?? "worker:{$workerId}",
            userId: $original?->userId,
            sourceIp: $original?->sourceIp,
            apiTokenId: $original?->apiTokenId,
            userAgent: $original?->userAgent,
            requestId: $original?->requestId,
            service: "worker:{$workerId}",
        );
    }

    public static function reconciler(?string $requestId = null): self
    {
        return new self(
            username: 'reconciler',
            requestId: $requestId,
            service: 'reconciler',
        );
    }

    public static function cli(string $scriptName, ?string $username = null): self
    {
        return new self(
            username: $username ?? "cli:{$scriptName}",
            service: "cli:{$scriptName}",
        );
    }

    public function withRequestId(string $requestId): self
    {
        return new self(
            $this->username,
            $this->userId,
            $this->sourceIp,
            $this->apiTokenId,
            $this->userAgent,
            $requestId,
            $this->service,
        );
    }
}
