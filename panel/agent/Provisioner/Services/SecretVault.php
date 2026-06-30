<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\VaultException;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Encrypted secret storage backed by libsodium `crypto_secretbox`
 * (XSalsa20-Poly1305) and the `secrets_vault` table.
 *
 * Master key:
 *   - 32 bytes, mode 0400 root, at /etc/flowone/master.key
 *   - Generated once at install time. NEVER in the database. NEVER in git.
 *   - Loss of the master key = total loss of every secret. This is by
 *     design. Back it up out-of-band as part of disaster recovery.
 *
 * Rotation:
 *   - put() inserts version 1 with is_current=1.
 *   - rotate() inserts version N+1 with is_current=1 and sets the old
 *     version is_current=0 + expires_at = now() + 7d so callers can
 *     still decrypt for a rollback window. After 7d the expired rows
 *     are pruned by the reconciler.
 *   - The decrypt path tries `is_current=1` first, then falls back to
 *     any non-expired older version if that fails (so a rotation that
 *     hasn't propagated to all callers yet doesn't break them).
 *
 * Threat model:
 *   - DB dump alone is useless without master.key (assuming AEAD holds).
 *   - Host compromise gives master.key + DB = all secrets exposed. We
 *     do NOT defend against host compromise. That is the boundary
 *     where every panel of every vendor breaks; out of scope.
 */
final class SecretVault
{
    public const DEFAULT_MASTER_KEY_PATH = '/etc/flowone/master.key';
    private const MASTER_KEY_ID = 'master.v1';
    private const ROTATION_RETENTION_DAYS = 7;

    private ?string $masterKey = null;

    public function __construct(
        private readonly PanelDatabase $database,
        private readonly string $masterKeyPath = self::DEFAULT_MASTER_KEY_PATH,
        private readonly ?SecretsAuditWriter $audit = null
    ) {
        if (!extension_loaded('sodium')) {
            throw new VaultException(
                'libsodium extension is required for SecretVault'
            );
        }
    }

    /**
     * Store a secret. If the key already exists, rotate to version N+1.
     */
    public function put(
        string $scope,
        string $keyName,
        string $value,
        ActorContext $actor,
        ?string $description = null,
        ?array $metadata = null
    ): int {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $existing = $this->fetchCurrent($pdo, $scope, $keyName);

            if ($existing !== null) {
                $version = (int) $existing['version'] + 1;
                $this->markVersionExpired($pdo, $existing['id']);
                $action = 'rotate';
            } else {
                $version = 1;
                $action = 'put';
            }

            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->masterKey());
            sodium_memzero($value); // wipe input from memory

            $insert = $pdo->prepare(
                'INSERT INTO secrets_vault
                    (scope, key_name, version, is_current,
                     ciphertext, nonce, algo, master_key_id,
                     description, metadata,
                     created_at)
                  VALUES
                    (:scope, :key_name, :version, 1,
                     :ciphertext, :nonce, :algo, :master_key_id,
                     :description, :metadata,
                     NOW())'
            );
            $insert->execute([
                'scope' => $scope,
                'key_name' => $keyName,
                'version' => $version,
                'ciphertext' => $ciphertext,
                'nonce' => $nonce,
                'algo' => 'crypto_secretbox',
                'master_key_id' => self::MASTER_KEY_ID,
                'description' => $description,
                'metadata' => $metadata !== null ? json_encode($metadata) : null,
            ]);

            $id = (int) $pdo->lastInsertId();

            $this->audit?->record($scope, $keyName, $action, $version, $actor);

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Read the current secret value. Falls back to non-expired older versions
     * if the current row cannot be decrypted (e.g. master key rotation in
     * progress and rows are still encrypted with master.v0).
     */
    public function get(string $scope, string $keyName, ActorContext $actor): string
    {
        $pdo = $this->database->pdo();
        $rows = $this->fetchAllUsableVersions($pdo, $scope, $keyName);

        if ($rows === []) {
            throw new VaultException("Secret not found: {$scope}/{$keyName}");
        }

        $lastError = null;
        foreach ($rows as $row) {
            try {
                $plaintext = $this->decrypt($row);
                $this->audit?->record($scope, $keyName, 'get', (int) $row['version'], $actor);
                return $plaintext;
            } catch (VaultException $e) {
                $lastError = $e;
                continue;
            }
        }

        throw new VaultException(
            "Could not decrypt any version of {$scope}/{$keyName}: " .
            ($lastError?->getMessage() ?? 'unknown error')
        );
    }

    /**
     * Forcibly rotate to a new value (alias for put() since put rotates by default).
     * Exposed separately for clarity at call sites.
     */
    public function rotate(
        string $scope,
        string $keyName,
        string $newValue,
        ActorContext $actor
    ): int {
        return $this->put($scope, $keyName, $newValue, $actor);
    }

    /**
     * Hard-delete a secret. All versions, immediately. No retention window.
     * Used when a site is force-destroyed and we want zero residual data.
     */
    public function wipe(string $scope, string $keyName, ActorContext $actor): int
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM secrets_vault WHERE scope = :scope AND key_name = :key_name'
        );
        $stmt->execute(['scope' => $scope, 'key_name' => $keyName]);
        $removed = $stmt->rowCount();

        if ($removed > 0) {
            $this->audit?->record($scope, $keyName, 'wipe', null, $actor);
        }
        return $removed;
    }

    /**
     * Sweep rows whose `expires_at` is in the past. Safe to run from the
     * reconciler on a schedule.
     */
    public function pruneExpired(): int
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM secrets_vault
              WHERE is_current = 0
                AND expires_at IS NOT NULL
                AND expires_at < NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * List keys in a scope (for admin UI). Never returns plaintext.
     *
     * @return array<int, array{scope:string,key_name:string,version:int,description:?string,created_at:string}>
     */
    public function listScope(string $scope): array
    {
        $pdo = $this->database->pdo();
        $stmt = $pdo->prepare(
            'SELECT scope, key_name, version, description, created_at
               FROM secrets_vault
              WHERE scope = :scope AND is_current = 1
              ORDER BY key_name'
        );
        $stmt->execute(['scope' => $scope]);
        return $stmt->fetchAll();
    }

    /**
     * Lazily load and cache the master key. Refuses if the file has
     * group/world-readable permissions - this would be a real-world
     * security regression we want to catch early.
     */
    private function masterKey(): string
    {
        if ($this->masterKey !== null) {
            return $this->masterKey;
        }

        if (!file_exists($this->masterKeyPath)) {
            throw new VaultException(
                "Master key not found at {$this->masterKeyPath}. " .
                "Generate one with `dd if=/dev/urandom of={$this->masterKeyPath} bs=32 count=1 && chmod 0400 {$this->masterKeyPath}`"
            );
        }

        $perms = fileperms($this->masterKeyPath) & 0777;
        if (($perms & 0077) !== 0) {
            throw new VaultException(sprintf(
                'Master key %s has unsafe permissions 0%o (must be 0400 or 0600 for owner only)',
                $this->masterKeyPath,
                $perms
            ));
        }

        $key = file_get_contents($this->masterKeyPath);
        if ($key === false) {
            throw new VaultException("Could not read master key from {$this->masterKeyPath}");
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new VaultException(sprintf(
                'Master key has wrong length: expected %d bytes, got %d',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key)
            ));
        }

        $this->masterKey = $key;
        return $this->masterKey;
    }

    private function decrypt(array $row): string
    {
        $plaintext = sodium_crypto_secretbox_open(
            $row['ciphertext'],
            $row['nonce'],
            $this->masterKey()
        );
        if ($plaintext === false) {
            throw new VaultException(
                "Authenticated decryption failed for secret id {$row['id']}"
            );
        }
        return $plaintext;
    }

    private function fetchCurrent(\PDO $pdo, string $scope, string $keyName): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM secrets_vault
              WHERE scope = :scope AND key_name = :key_name AND is_current = 1
              FOR UPDATE'
        );
        $stmt->execute(['scope' => $scope, 'key_name' => $keyName]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Returns current row first, then any non-expired prior versions.
     * Used by get() to handle in-progress master-key rotation.
     */
    private function fetchAllUsableVersions(\PDO $pdo, string $scope, string $keyName): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM secrets_vault
              WHERE scope = :scope
                AND key_name = :key_name
                AND (is_current = 1 OR expires_at IS NULL OR expires_at > NOW())
              ORDER BY is_current DESC, version DESC'
        );
        $stmt->execute(['scope' => $scope, 'key_name' => $keyName]);
        return $stmt->fetchAll();
    }

    private function markVersionExpired(\PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare(
            'UPDATE secrets_vault
                SET is_current = 0,
                    rotated_at = NOW(),
                    expires_at = NOW() + INTERVAL :days DAY
              WHERE id = :id'
        );
        $stmt->execute(['days' => self::ROTATION_RETENTION_DAYS, 'id' => $id]);
    }
}
