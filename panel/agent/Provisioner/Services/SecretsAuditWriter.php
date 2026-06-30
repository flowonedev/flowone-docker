<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Writes append-only rows to `secrets_audit`.
 *
 * Separate from the site audit log because secret reads can be very
 * frequent (every step that needs a credential calls SecretVault::get())
 * and would drown out site-level audit events. They get their own table.
 *
 * Optional dependency of SecretVault: if not injected, reads/writes are
 * still recorded by the underlying SecretVault operations but no audit
 * trail is produced. Always inject in production; the only reason to
 * omit is when bootstrapping a fresh install before any audit table
 * exists.
 */
final class SecretsAuditWriter
{
    public function __construct(
        private readonly PanelDatabase $database
    ) {
    }

    public function record(
        string $scope,
        string $keyName,
        string $action,
        ?int $version,
        ActorContext $actor
    ): void {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO secrets_audit
                (scope, key_name, action, version,
                 actor_user_id, actor_username, actor_service,
                 source_ip, request_id, occurred_at)
              VALUES
                (:scope, :key_name, :action, :version,
                 :actor_user_id, :actor_username, :actor_service,
                 :source_ip, :request_id, :occurred_at)'
        );
        $stmt->execute([
            'scope' => $scope,
            'key_name' => $keyName,
            'action' => $action,
            'version' => $version,
            'actor_user_id' => $actor->userId,
            'actor_username' => $actor->username,
            'actor_service' => $actor->service,
            'source_ip' => $actor->sourceIp,
            'request_id' => $actor->requestId,
            'occurred_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v'),
        ]);
    }
}
