<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Create the per-site MariaDB user.
 *
 * Password handling - this is the security-critical part:
 *   1. Generate 32 bytes of cryptographic randomness via random_bytes
 *      and base64-url-encode to ~43 ASCII chars (no padding).
 *   2. Store the plaintext into the SecretVault under
 *        scope    = "site:<domain>"
 *        key_name = "db_password"
 *      The vault encrypts with libsodium + master key, returns a row id.
 *   3. Pass the plaintext to MysqlAdapter::createUser, then
 *      sodium_memzero() the local variable.
 *   4. Persist NOTHING about the password in StepState.data beyond:
 *        - vault_scope ("site:<domain>")
 *        - vault_key_name ("db_password")
 *        - vault_version (the version number put() returned, for audit)
 *
 *   The SecretMasker fires on the event log so even if we accidentally
 *   logged the plaintext, it'd be redacted before persistence. But we
 *   never log the plaintext in the first place.
 *
 * Idempotence:
 *   - check() returns true iff the MySQL user row exists. We do NOT
 *     also verify the vault has a current password - that's a separate
 *     consistency check by the reconciler.
 *   - execute() on resume reads the password from the vault (if a
 *     prior attempt got that far) rather than rotating to a new one.
 *
 * Compensation: DEGRADE_ONLY.
 *   - dropping the user could orphan grants attached to it, and
 *     rebuilding requires the operator to re-issue grants explicitly.
 *   - The vault entry is left in place so the operator can finish the
 *     install manually if they want.
 */
final class DatabaseUserCreateStep extends AbstractStep
{
    private const VAULT_KEY_NAME = 'db_password';

    public function name(): string
    {
        return StepName::DATABASE_USER_CREATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $mysql = $ctx->requireAdapters()->mysql;
        $user = $this->resolveUserName($ctx, $state);
        $host = $this->resolveHost($ctx);
        return $mysql->userExists($user, $host);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $mysql = $ctx->requireAdapters()->mysql;
        $user = $this->resolveUserName($ctx, $state);
        $host = $this->resolveHost($ctx);
        $scope = $this->vaultScope($ctx);

        $events = [StepEvent::info('creating database user', [
            'user' => $user, 'host' => $host, 'vault_scope' => $scope,
        ])];

        // Decide: rotate password (fresh) vs reuse the one already in vault.
        $hasVaultedPw = $this->vaultHas($ctx, $scope, self::VAULT_KEY_NAME);
        try {
            if ($hasVaultedPw) {
                $events[] = StepEvent::info('reusing password from vault for resume');
                $password = $ctx->vault->get($scope, self::VAULT_KEY_NAME, $ctx->actor);
                $version = (int) ($state->data['vault_version'] ?? 0);
            } else {
                $password = $this->generatePassword();
                $version = $ctx->vault->put(
                    $scope,
                    self::VAULT_KEY_NAME,
                    $password,
                    $ctx->actor,
                    'MariaDB password for ' . $user . '@' . $host,
                );
                // NB: SecretVault::put already sodium_memzero'd its
                // copy of $password. We hold a fresh local copy now;
                // we'll zero ours after createUser() returns.
                $events[] = StepEvent::info('password generated + vaulted', [
                    'vault_version' => $version,
                ]);
            }
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['user' => $user, 'host' => $host]),
                "vault put/get failed: " . $e->getMessage(),
                $events,
            );
        }

        try {
            $created = $mysql->createUser($user, $password, $host);
        } catch (\Throwable $e) {
            // Always zero on every exit path.
            if (function_exists('sodium_memzero')) {
                sodium_memzero($password);
            }
            $msg = "createUser failed: " . $e->getMessage();
            if ($hint = MysqlAdminCredentials::privilegeHint($e)) {
                $msg .= ' | hint: ' . $hint;
            }
            return StepResult::failure(
                $state->mergeData(['user' => $user, 'host' => $host]),
                $msg,
                $events,
            );
        }

        if (function_exists('sodium_memzero')) {
            sodium_memzero($password);
        }

        $events[] = $created
            ? StepEvent::info('user created', ['user' => $user])
            : StepEvent::info('user already present, no-op', ['user' => $user]);

        return StepResult::success(
            $state->mergeData([
                'user' => $user,
                'host' => $host,
                'vault_scope' => $scope,
                'vault_key_name' => self::VAULT_KEY_NAME,
                'vault_version' => $version,
            ])->withCompleted(),
            $events,
            ['created' => $created ? 1 : 0],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: database user NOT dropped (DEGRADE_ONLY); orphan grants are operator-handled',
                ['user' => $state->data['user'] ?? null]
            )]
        );
    }

    private function resolveUserName(SiteContext $ctx, StepState $state): string
    {
        if (!empty($state->data['user']) && is_string($state->data['user'])) {
            return $state->data['user'];
        }
        $payload = $ctx->payload;
        if (!empty($payload['db_user']) && is_string($payload['db_user'])) {
            return $payload['db_user'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['db_user']) && is_string($row['db_user'])) {
            return $row['db_user'];
        }
        return $this->deriveFromDomain($ctx->domain());
    }

    private function resolveHost(SiteContext $ctx): string
    {
        $payload = $ctx->payload;
        if (!empty($payload['db_host']) && is_string($payload['db_host'])) {
            return $payload['db_host'];
        }
        return 'localhost';
    }

    private function vaultScope(SiteContext $ctx): string
    {
        return 'site:' . $ctx->domain();
    }

    private function vaultHas(SiteContext $ctx, string $scope, string $key): bool
    {
        try {
            $list = $ctx->vault->listScope($scope);
            foreach ($list as $row) {
                if (($row['key_name'] ?? null) === $key && (int) ($row['is_current'] ?? 0) === 1) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * Build a high-entropy password that survives MariaDB's quoting
     * rules. base64url avoids characters that would need escaping in a
     * GRANT statement.
     */
    private function generatePassword(): string
    {
        $raw = random_bytes(32);
        // base64url (no padding, no +/, no =)
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function deriveFromDomain(string $domain): string
    {
        // Single source of truth - shared with DatabaseGrantStep's
        // fallback so the grant always lands on the user this step
        // actually creates.
        return ResourceNameDeriver::dbUser($domain);
    }
}
