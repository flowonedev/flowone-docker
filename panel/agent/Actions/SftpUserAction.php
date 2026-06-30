<?php

declare(strict_types=1);

/**
 * SFTP User Action Handler
 *
 * Manages ADDITIONAL chroot-jailed SFTP users per site, on top of the
 * single primary site user (sites.sftp_user). Orchestration only:
 *   - validation (path canonicalization + min depth, generated names),
 *   - DB row lifecycle in `sftp_users`,
 *   - encrypted password storage in the secrets vault,
 *   - audit logging,
 * delegating all OS work to the focused helpers under VpsAdmin\Agent\Sftp
 * (SftpAccountManager, JailManager, SshdSftpConfigurator), each call
 * serialized under SftpLock.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\PanelDb;
use VpsAdmin\Agent\Lib\Validator;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Sftp\JailManager;
use VpsAdmin\Agent\Sftp\SftpAccountManager;
use VpsAdmin\Agent\Sftp\SftpLock;
use VpsAdmin\Agent\Sftp\SftpSessionStore;
use VpsAdmin\Agent\Sftp\SshdSftpConfigurator;

class SftpUserAction extends BaseAction
{
    private const SAFE_GROUP_REGEX = '/^[a-z_][a-z0-9_-]{0,30}$/';

    private ?SftpAccountManager $accounts = null;
    private ?JailManager $jail = null;
    private ?SshdSftpConfigurator $sshd = null;

    public function getNamespace(): string
    {
        return 'sftpUser';
    }

    public function getMethods(): array
    {
        return [
            'list', 'browse', 'create', 'setPassword', 'addKey', 'removeKey',
            'setStatus', 'delete', 'ensureSshdBlock', 'repair',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        // Helpers do their own atomic writes + rollback; the generic
        // file-backup machinery does not apply to user/mount operations.
        return false;
    }

    // ─── Read ─────────────────────────────────────────────────

    protected function actionList(array $params, string $actor): array
    {
        $db = PanelDb::get();
        if ($db === null) {
            return $this->error('Panel database unavailable');
        }

        if (!empty($params['domain'])) {
            $stmt = $db->prepare('SELECT * FROM sftp_users WHERE domain = ? ORDER BY created_at DESC');
            $stmt->execute([$params['domain']]);
        } else {
            // Global (admin) listing across all sites.
            $stmt = $db->query('SELECT * FROM sftp_users ORDER BY created_at DESC');
        }

        $rows = array_map([$this, 'publicRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $rows = $this->mergeSessionAggregates($rows, $db);
        return $this->success(['users' => $rows]);
    }

    /**
     * Decorate each list row with live-session + transfer roll-ups
     * (online?, active count, total sessions, lifetime bytes) so the UI can
     * show "online now / last login / total transferred" at a glance.
     * Tolerant: if the sessions table is missing (pre-migration), rows keep
     * zeroed defaults rather than failing the whole listing.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mergeSessionAggregates(array $rows, \PDO $db): array
    {
        $agg = [];
        try {
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            $agg = (new SftpSessionStore($db))->aggregatesForUsers($ids);
        } catch (\Throwable) {
            $agg = [];
        }
        foreach ($rows as &$row) {
            $a = $agg[(int) $row['id']] ?? ['active' => 0, 'sessions' => 0, 'total_bytes' => 0];
            $row['online'] = $a['active'] > 0;
            $row['active_sessions'] = $a['active'];
            $row['session_count'] = $a['sessions'];
            $row['total_bytes'] = $a['total_bytes'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Read-only directory browser scoped to a site's home tree, so the
     * operator can pick the target folder instead of typing a path. Lists
     * immediate SUBDIRECTORIES only (no files), never follows symlinks,
     * and refuses to step outside /home/<domain>.
     */
    protected function actionBrowse(array $params, string $actor): array
    {
        $domain = (string) ($params['domain'] ?? '');
        if ($domain === '' || !Validator::hostname($domain)) {
            return $this->error('Valid domain is required');
        }
        $site = $this->siteRow($domain);
        if ($site === null) {
            return $this->error("Unknown site: {$domain}");
        }
        $homeRoot = rtrim($site['home_dir'] ?: ('/home/' . $domain), '/');

        $requested = trim((string) ($params['path'] ?? ''));
        if ($requested === '') {
            // Start at public_html when it exists - that's where uploads
            // folders usually live - otherwise the home root.
            $pub = $homeRoot . '/public_html';
            $requested = is_dir($pub) ? $pub : $homeRoot;
        } elseif ($requested[0] !== '/') {
            $requested = $homeRoot . '/' . $requested;
        }

        $canon = realpath($requested);
        if ($canon === false || !is_dir($canon)) {
            return $this->error('Folder not found');
        }
        // Containment: realpath already resolved every component, so a
        // symlink that points outside the home would fail this check.
        if ($canon !== $homeRoot && !str_starts_with($canon . '/', $homeRoot . '/')) {
            return $this->error('Path is outside the site home');
        }

        $dirs = [];
        foreach (scandir($canon) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $canon . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                $dirs[] = ['name' => $entry, 'path' => $full];
            }
        }
        usort($dirs, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $parent = null;
        if ($canon !== $homeRoot) {
            $up = dirname($canon);
            if ($up === $homeRoot || str_starts_with($up . '/', $homeRoot . '/')) {
                $parent = $up;
            }
        }

        // A folder is selectable only if it satisfies the same rules
        // create() enforces (not the home or public_html root, deep
        // enough). Surfacing this lets the UI disable "Use this folder".
        $selectable = true;
        try {
            $this->jail()->validateTarget($homeRoot, $canon);
        } catch (\Throwable) {
            $selectable = false;
        }

        return $this->success([
            'home_root' => $homeRoot,
            'path' => $canon,
            'parent' => $parent,
            'selectable' => $selectable,
            'dirs' => $dirs,
        ]);
    }

    // ─── Create ───────────────────────────────────────────────

    protected function actionCreate(array $params, string $actor): array
    {
        $domain = (string) ($params['domain'] ?? '');
        $target = (string) ($params['target_path'] ?? '');
        if ($domain === '' || !Validator::hostname($domain)) {
            return $this->error('Valid domain is required');
        }
        if ($target === '') {
            return $this->error('target_path is required');
        }

        $site = $this->siteRow($domain);
        if ($site === null) {
            return $this->error("Unknown site: {$domain}");
        }
        $homeRoot = $site['home_dir'] ?: ('/home/' . $domain);

        $authType = in_array($params['auth_type'] ?? 'password', ['password', 'key', 'both'], true)
            ? $params['auth_type'] : 'password';
        $displayName = isset($params['display_name']) ? substr((string) $params['display_name'], 0, 128) : null;
        $requestedUsername = trim((string) ($params['username'] ?? ''));

        try {
            return SftpLock::run(function () use ($domain, $target, $homeRoot, $authType, $displayName, $requestedUsername, $params, $actor, $site) {
                $this->bootstrapInfra();
                $canonTarget = $this->jail()->validateTarget($homeRoot, $target);
                $label = $this->deriveLabel((string) ($params['label'] ?? ''), $canonTarget);

                // The operator may choose the exact SFTP login. We validate
                // its format strictly and refuse reserved/system names or any
                // name that already exists on the box (which also covers the
                // site's own primary user and accounts like root/www-data).
                $fixedUsername = null;
                if ($requestedUsername !== '') {
                    $fixedUsername = self::validateUsernameFormat($requestedUsername);
                    if (self::isReservedUsername($fixedUsername) || $this->accounts()->userExists($fixedUsername)) {
                        return $this->error(
                            "Username \"{$fixedUsername}\" is reserved or already exists on the server; choose another"
                        );
                    }
                }

                $row = $this->reserveRow((int) $site['id'], $domain, $label, $canonTarget, $authType, $displayName, $actor, $fixedUsername);
                $username = $row['linux_username'];

                try {
                    $this->jail()->ensureJail($username, $canonTarget, $label);
                    $primaryGroup = $this->targetOwningGroup($canonTarget) ?? SftpAccountManager::GROUP;
                    $this->accounts()->createAccount($username, $row['jail_root'], $primaryGroup);
                    // ACL last: setfacl resolves the username to a UID, so it
                    // can only run once the Linux account exists.
                    $this->jail()->applyAcl($canonTarget, $username);

                    $plainPassword = $this->applyAuth($username, $authType, $params, $domain, $actor);
                    $keys = $this->normalizeKeys($params);
                    if ($keys !== []) {
                        $this->accounts()->writeKeys($username, $keys);
                    }
                } catch (\Throwable $e) {
                    $this->setStatus((int) $row['id'], 'error');
                    $this->logger->error('sftpUser.create OS provisioning failed', [
                        'user' => $username, 'error' => $e->getMessage(),
                    ]);
                    return $this->error('Failed to provision SFTP user: ' . $e->getMessage(), [
                        'id' => (int) $row['id'], 'linux_username' => $username, 'status' => 'error',
                    ]);
                }

                $out = [
                    'id' => (int) $row['id'],
                    'linux_username' => $username,
                    'domain' => $domain,
                    'target_path' => $canonTarget,
                    'label' => $label,
                    'auth_type' => $authType,
                ];
                if ($plainPassword !== null) {
                    // Returned once so the operator can hand it to the
                    // user; it is stored encrypted in the vault.
                    $out['password'] = $plainPassword;
                }
                return $this->success($out, 'SFTP user created');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ─── Password / keys ──────────────────────────────────────

    protected function actionSetPassword(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        $password = (string) ($params['password'] ?? '');
        if (strlen($password) < 8) {
            return $this->error('Password must be at least 8 characters');
        }
        try {
            return SftpLock::run(function () use ($row, $password, $actor) {
                $this->bootstrapInfra();
                $this->accounts()->setPassword($row['linux_username'], $password);
                $this->accounts()->unlockPassword($row['linux_username']);
                $this->storePassword($row['domain'], $row['linux_username'], $password, $actor);
                if ($row['auth_type'] === 'key') {
                    $this->updateColumn((int) $row['id'], 'auth_type', 'both');
                }
                return $this->success(['id' => (int) $row['id']], 'Password updated');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    protected function actionAddKey(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        $key = trim((string) ($params['key'] ?? ''));
        if ($key === '') {
            return $this->error('key is required');
        }
        try {
            return SftpLock::run(function () use ($row, $key) {
                $this->bootstrapInfra();
                $keys = $this->accounts()->readKeys($row['linux_username']);
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
                $this->accounts()->writeKeys($row['linux_username'], $keys);
                return $this->success(['id' => (int) $row['id'], 'keys' => count($keys)], 'Key added');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    protected function actionRemoveKey(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        try {
            return SftpLock::run(function () use ($params, $row) {
                $this->bootstrapInfra();
                $keys = $this->accounts()->readKeys($row['linux_username']);
                if (isset($params['index']) && is_numeric($params['index'])) {
                    $idx = (int) $params['index'];
                    if (isset($keys[$idx])) {
                        array_splice($keys, $idx, 1);
                    }
                } elseif (!empty($params['key'])) {
                    $keys = array_values(array_filter($keys, fn($k) => $k !== trim((string) $params['key'])));
                }
                $this->accounts()->writeKeys($row['linux_username'], $keys);
                return $this->success(['id' => (int) $row['id'], 'keys' => count($keys)], 'Key removed');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ─── Status (enable / disable) ────────────────────────────

    protected function actionSetStatus(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        $status = $params['status'] ?? '';
        if (!in_array($status, ['active', 'disabled'], true)) {
            return $this->error('status must be active or disabled');
        }
        try {
            return SftpLock::run(function () use ($row, $status) {
                $this->bootstrapInfra();
                $user = $row['linux_username'];
                if ($status === 'disabled') {
                    $this->accounts()->lockPassword($user);
                    $this->accounts()->removeFromGroup($user, SftpAccountManager::GROUP);
                } else {
                    $this->accounts()->addToGroup($user, SftpAccountManager::GROUP);
                    if ($row['auth_type'] !== 'key') {
                        $this->accounts()->unlockPassword($user);
                    }
                }
                $this->setStatus((int) $row['id'], $status);
                return $this->success(['id' => (int) $row['id'], 'status' => $status], 'Status updated');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ─── Delete ───────────────────────────────────────────────

    protected function actionDelete(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        $this->setStatus((int) $row['id'], 'deleting');
        try {
            return SftpLock::run(function () use ($row, $actor) {
                $this->bootstrapInfra();
                $user = $row['linux_username'];
                // Teardown throws on a busy mount -> keep row in error for retry.
                try {
                    $this->jail()->teardown($user, $row['target_path'] ?: null, $row['mount_point']);
                } catch (\Throwable $e) {
                    $this->setStatus((int) $row['id'], 'error');
                    return $this->error('Could not unmount jail (still busy?): ' . $e->getMessage(), [
                        'id' => (int) $row['id'], 'status' => 'error',
                    ]);
                }
                $this->accounts()->removeKeyFile($user);
                if ($this->accounts()->userExists($user)) {
                    $this->accounts()->deleteAccount($user);
                }
                $this->wipePassword($row['domain'], $user, $actor);
                $this->deleteRow((int) $row['id']);
                return $this->success(['id' => (int) $row['id']], 'SFTP user deleted');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ─── Infra setup + repair ─────────────────────────────────

    protected function actionEnsureSshdBlock(array $params, string $actor): array
    {
        try {
            return SftpLock::run(function () {
                $changed = $this->bootstrapInfra();
                return $this->success(['changed' => $changed], $changed ? 'sshd block ensured' : 'already configured');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    protected function actionRepair(array $params, string $actor): array
    {
        $row = $this->requireRow($params);
        if (is_array($row) && isset($row['__error'])) {
            return $this->error($row['__error']);
        }
        try {
            return SftpLock::run(function () use ($row) {
                $this->bootstrapInfra();
                $user = $row['linux_username'];
                $canon = realpath($row['target_path']);
                if ($canon === false || !is_dir($canon)) {
                    $this->setStatus((int) $row['id'], 'error');
                    return $this->error('Target folder is missing; repair will not recreate it', [
                        'id' => (int) $row['id'], 'status' => 'error',
                    ]);
                }
                $fixes = $this->jail()->repair($user, $canon, $row['label']);

                if (!$this->accounts()->userExists($user)) {
                    $primaryGroup = $this->targetOwningGroup($canon) ?? SftpAccountManager::GROUP;
                    $this->accounts()->createAccount($user, $row['jail_root'], $primaryGroup);
                    $fixes[] = 'recreated account';
                } elseif (!$this->accounts()->inGroup($user, SftpAccountManager::GROUP)
                    && $row['status'] !== 'disabled') {
                    $this->accounts()->addToGroup($user, SftpAccountManager::GROUP);
                    $fixes[] = 'restored group membership';
                }

                // ACL last: setfacl needs the account to exist to resolve
                // its UID, so it must run after the account is (re)created.
                $this->jail()->applyAcl($canon, $user);
                $fixes[] = 'reapplied ACL';

                if (!$this->accounts()->keyFileExists($user)) {
                    $this->accounts()->writeKeys($user, []);
                    $fixes[] = 'recreated key file';
                }

                if ($row['auth_type'] === 'key') {
                    $this->accounts()->lockPassword($user);
                }
                if ($row['status'] !== 'disabled') {
                    $this->setStatus((int) $row['id'], 'active');
                }
                return $this->success(['id' => (int) $row['id'], 'fixes' => $fixes], 'Repair complete');
            });
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    // ─── Orchestration helpers ────────────────────────────────

    private function bootstrapInfra(): bool
    {
        $this->jail()->ensureBase();
        $this->accounts()->ensureGroup();
        $this->accounts()->ensureKeyDir();
        return $this->sshd()->ensureConfigured();
    }

    /**
     * Insert a row. When $fixedUsername is null the Linux name is
     * generated, regenerating on a unique collision (#19). When the
     * operator supplied a name we insert it once and surface a clear
     * "already taken" error on a unique collision (no silent rename).
     */
    private function reserveRow(
        int $siteId,
        string $domain,
        string $label,
        string $target,
        string $authType,
        ?string $displayName,
        string $actor,
        ?string $fixedUsername = null
    ): array {
        $db = PanelDb::get();
        if ($db === null) {
            throw new \RuntimeException('Panel database unavailable');
        }
        $lastError = null;
        $attempts = $fixedUsername !== null ? 1 : 6;
        for ($i = 0; $i < $attempts; $i++) {
            $username = $fixedUsername ?? $this->generateUsername($siteId);
            $jailRoot = JailManager::JAIL_BASE . '/' . $username;
            $mountPoint = $jailRoot . '/' . $label;
            try {
                $stmt = $db->prepare(
                    'INSERT INTO sftp_users
                        (domain, linux_username, display_name, target_path, jail_root, mount_point, label, auth_type, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?, "active", ?)'
                );
                $stmt->execute([
                    $domain, $username, $displayName, $target, $jailRoot, $mountPoint, $label, $authType, $actor,
                ]);
                return [
                    'id' => (int) $db->lastInsertId(),
                    'linux_username' => $username,
                    'jail_root' => $jailRoot,
                    'mount_point' => $mountPoint,
                ];
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') !== '23000') {
                    throw $e;
                }
                if ($fixedUsername !== null) {
                    throw new \RuntimeException("That username is already taken: {$fixedUsername}");
                }
                $lastError = $e;
            }
        }
        throw new \RuntimeException('Could not allocate a unique SFTP username', 0, $lastError);
    }

    /**
     * Apply the auth model and return the plaintext password if one was
     * set (so the caller can show it once). Key-only accounts get their
     * password locked (#4).
     */
    private function applyAuth(string $username, string $authType, array $params, string $domain, string $actor): ?string
    {
        if ($authType === 'key') {
            $this->accounts()->lockPassword($username);
            return null;
        }
        $password = (string) ($params['password'] ?? '');
        if ($password === '') {
            $password = $this->generatePassword();
        }
        $this->accounts()->setPassword($username, $password);
        $this->storePassword($domain, $username, $password, $actor);
        return $password;
    }

    private function normalizeKeys(array $params): array
    {
        if (!empty($params['keys']) && is_array($params['keys'])) {
            return $params['keys'];
        }
        if (!empty($params['key'])) {
            return [(string) $params['key']];
        }
        return [];
    }

    private function deriveLabel(string $given, string $canonTarget): string
    {
        $label = $given !== '' ? $given : basename($canonTarget);
        $label = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '-', $label) ?? '');
        $label = trim($label, '-');
        return $label !== '' ? substr($label, 0, 64) : 'data';
    }

    private function generateUsername(int $siteId): string
    {
        $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        return substr('sftp_' . $siteId . '_' . $rand, 0, 31);
    }

    /**
     * Validate + normalize an operator-supplied Linux login name. Returns
     * the trimmed, lowercased name; throws InvalidArgumentException on any
     * violation. Pure + static so it is unit-testable without the DB/OS.
     *
     * Rules: 3-32 chars, must start with a lowercase letter, then lowercase
     * letters / digits / underscore / hyphen. This is stricter than the
     * useradd NAME_REGEX (no leading underscore or digit, no trailing '$')
     * so a panel name can never look like a system/service account.
     */
    private static function validateUsernameFormat(string $raw): string
    {
        $name = strtolower(trim($raw));
        if ($name === '') {
            throw new \InvalidArgumentException('Username is required');
        }
        if (strlen($name) < 3 || strlen($name) > 32) {
            throw new \InvalidArgumentException('Username must be between 3 and 32 characters');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Username must start with a lowercase letter and contain only lowercase letters, digits, underscores or hyphens'
            );
        }
        return $name;
    }

    /**
     * Names we never let an operator claim, even if no such Linux account
     * exists yet, because they are (or could become) privileged/service
     * accounts. The live userExists() check in actionCreate is the primary
     * guard; this is defense in depth for not-yet-created system names.
     */
    private static function isReservedUsername(string $name): bool
    {
        static $reserved = [
            'root', 'admin', 'administrator', 'daemon', 'bin', 'sys', 'sync',
            'games', 'man', 'lp', 'mail', 'news', 'uucp', 'proxy', 'backup',
            'list', 'irc', 'gnats', 'nobody', 'sshd', 'ftp', 'sftp', 'www',
            'www-data', 'apache', 'nginx', 'httpd', 'mysql', 'mariadb',
            'redis', 'postfix', 'dovecot', 'vmail', 'postgres', 'systemd',
            'messagebus', 'syslog', 'operator', 'shutdown', 'halt',
        ];
        if (in_array($name, $reserved, true)) {
            return true;
        }
        // Block system-style prefixes (systemd-resolve, _apt, etc.).
        return str_starts_with($name, 'systemd') || str_starts_with($name, '_');
    }

    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Ab'), '=');
    }

    private function targetOwningGroup(string $path): ?string
    {
        $stat = @stat($path);
        if ($stat === false || !function_exists('posix_getgrgid')) {
            return null;
        }
        $group = posix_getgrgid($stat['gid']);
        if ($group === false) {
            return null;
        }
        $name = $group['name'];
        if ($name === 'root' || preg_match(self::SAFE_GROUP_REGEX, $name) !== 1) {
            return null;
        }
        return $name;
    }

    // ─── Secrets vault ────────────────────────────────────────

    private function storePassword(string $domain, string $username, string $password, string $actor): void
    {
        try {
            $vault = new SecretVault(PanelDatabase::instance());
            $vault->put('site:' . $domain, 'sftp_user:' . $username, $password, ActorContext::cli('sftpUser', $actor));
        } catch (\Throwable $e) {
            // Vault may be unavailable (no master key on dev boxes). The
            // password is still set on the account and returned once.
            $this->logger->warning('sftpUser: could not store password in vault', ['error' => $e->getMessage()]);
        }
    }

    private function wipePassword(string $domain, string $username, string $actor): void
    {
        try {
            $vault = new SecretVault(PanelDatabase::instance());
            $vault->wipe('site:' . $domain, 'sftp_user:' . $username, ActorContext::cli('sftpUser', $actor));
        } catch (\Throwable $e) {
            $this->logger->warning('sftpUser: could not wipe vault secret', ['error' => $e->getMessage()]);
        }
    }

    // ─── DB helpers ───────────────────────────────────────────

    private function siteRow(string $domain): ?array
    {
        $db = PanelDb::get();
        if ($db === null) {
            return null;
        }
        $stmt = $db->prepare('SELECT id, domain, home_dir, document_root FROM sites WHERE domain = ? LIMIT 1');
        $stmt->execute([$domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Resolve the sftp_users row from params (id + optional domain
     * scope). Returns the row, or ['__error' => msg] on failure.
     */
    private function requireRow(array $params): array
    {
        $db = PanelDb::get();
        if ($db === null) {
            return ['__error' => 'Panel database unavailable'];
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return ['__error' => 'id is required'];
        }
        $stmt = $db->prepare('SELECT * FROM sftp_users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['__error' => 'SFTP user not found'];
        }
        // When a domain is supplied (per-site endpoints), enforce scope.
        if (!empty($params['domain']) && $row['domain'] !== $params['domain']) {
            return ['__error' => 'SFTP user does not belong to this site'];
        }
        return $row;
    }

    private function setStatus(int $id, string $status): void
    {
        $this->updateColumn($id, 'status', $status);
    }

    private function updateColumn(int $id, string $column, string $value): void
    {
        $allowed = ['status', 'auth_type'];
        if (!in_array($column, $allowed, true)) {
            return;
        }
        $db = PanelDb::get();
        if ($db === null) {
            return;
        }
        $stmt = $db->prepare("UPDATE sftp_users SET {$column} = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    }

    private function deleteRow(int $id): void
    {
        $db = PanelDb::get();
        if ($db === null) {
            return;
        }
        $stmt = $db->prepare('DELETE FROM sftp_users WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Shape a row for API output (no secrets, key count instead of keys). */
    private function publicRow(array $row): array
    {
        $username = $row['linux_username'];
        $keys = [];
        try {
            // Public keys are not secret - safe to surface so the UI can
            // list/remove them by index.
            $keys = $this->accounts()->readKeys($username);
        } catch (\Throwable) {
            // ignore - listing must not fail because of a missing key file
        }
        return [
            'id' => (int) $row['id'],
            'domain' => $row['domain'],
            'linux_username' => $username,
            'display_name' => $row['display_name'],
            'target_path' => $row['target_path'],
            'label' => $row['label'],
            'auth_type' => $row['auth_type'],
            'status' => $row['status'],
            'keys' => $keys,
            'key_count' => count($keys),
            'last_login_at' => $row['last_login_at'],
            'last_login_ip' => $row['last_login_ip'],
            'login_count' => (int) $row['login_count'],
            'created_at' => $row['created_at'],
        ];
    }

    // ─── Lazy helper accessors ────────────────────────────────

    private function accounts(): SftpAccountManager
    {
        return $this->accounts ??= new SftpAccountManager();
    }

    private function jail(): JailManager
    {
        return $this->jail ??= new JailManager();
    }

    private function sshd(): SshdSftpConfigurator
    {
        return $this->sshd ??= new SshdSftpConfigurator();
    }
}
