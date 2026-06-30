<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

use VpsAdmin\Agent\Provisioner\Adapters\Adapters;

/**
 * Probes the on-disk / in-OLS / in-MariaDB / in-/etc/passwd presence of
 * every subsystem we know how to remediate.
 *
 * Probing is intentionally read-only. The prober NEVER mutates state.
 * Drift remediation happens later, after the assessor decides what
 * (if anything) to do.
 *
 * Inputs:
 *   - the `sites` row (decoded): we trust `domain`, `db_name`,
 *     `sftp_user`, `home_dir`, `document_root` for what to probe.
 *   - Adapters bundle: the live infrastructure facade.
 *
 * Adapter NULL contract:
 *   - Some probes may be unavailable (no MySQL admin creds, no root for
 *     SFTP lookup). In that case we return `null` for that subsystem so
 *     the assessor knows it cannot conclude anything.
 *
 * Why this lives in its own class rather than methods on the
 * ReconcilerService:
 *   - Tests can construct a SiteProber against a fake Adapters bundle
 *     and exercise the per-subsystem logic without standing up MariaDB
 *     or root-level SFTP.
 *   - Each probe maps 1:1 to a corresponding CREATE step's `check()`
 *     so the parity is auditable: if a step's check returned true we
 *     expect the prober to confirm presence.
 */
final class SiteProber implements SiteProberInterface
{
    public function __construct(
        private readonly Adapters $adapters
    ) {
    }

    /**
     * @param array<string, mixed> $siteRow Decoded row from `sites`.
     */
    public function probe(array $siteRow): SiteHealthProbe
    {
        $domain = (string) ($siteRow['domain'] ?? '');
        if ($domain === '') {
            return new SiteHealthProbe(
                domain: $domain,
                vhostConfigPresent: null,
                homeDirPresent: null,
                documentRootPresent: null,
                databasePresent: null,
                databaseUserPresent: null,
                sftpUserPresent: null,
                sftpGroupPresent: null,
                errors: ['domain column is empty'],
                probedAtUnix: microtime(true),
            );
        }

        $errors = [];

        $vhost = $this->probeVhost($domain, $errors);
        $home = $this->probeHomeDir($siteRow, $errors);
        $docroot = $this->probeDocumentRoot($siteRow, $errors);
        [$db, $dbUser] = $this->probeDatabase($siteRow, $errors);
        [$sftpUser, $sftpGroup] = $this->probeSftp($siteRow, $errors);
        $sizeBytes = ($home === true)
            ? $this->probeSizeBytes($siteRow, $errors)
            : null;
        [$sslEnabled, $sslExpiresAt, $sslIssuer] = $this->probeSsl($domain, $errors);

        return new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: $vhost,
            homeDirPresent: $home,
            documentRootPresent: $docroot,
            databasePresent: $db,
            databaseUserPresent: $dbUser,
            sftpUserPresent: $sftpUser,
            sftpGroupPresent: $sftpGroup,
            errors: $errors,
            probedAtUnix: microtime(true),
            sizeBytes: $sizeBytes,
            sslEnabled: $sslEnabled,
            sslExpiresAt: $sslExpiresAt,
            sslIssuer: $sslIssuer,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $errors
     */
    private function probeSizeBytes(array $row, array &$errors): ?int
    {
        $home = isset($row['home_dir']) && is_string($row['home_dir']) && $row['home_dir'] !== ''
            ? (string) $row['home_dir']
            : '/home/' . ($row['domain'] ?? '');
        try {
            return $this->adapters->fs->dirSizeBytes($home);
        } catch (\Throwable $e) {
            $errors[] = 'size probe failed: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Reads /etc/letsencrypt/live/<domain>/fullchain.pem and parses
     * the cert. We return a tuple instead of a DTO to keep the
     * existing test fakes (which build SiteHealthProbe directly)
     * unaffected.
     *
     * @param list<string> $errors
     * @return array{0: ?bool, 1: ?string, 2: ?string}
     */
    private function probeSsl(string $domain, array &$errors): array
    {
        $certPath = '/etc/letsencrypt/live/' . $domain . '/fullchain.pem';
        try {
            if (!$this->adapters->fs->isFile($certPath)) {
                return [false, null, null];
            }
            $pem = $this->adapters->fs->readFile($certPath);
            if ($pem === null || $pem === '') {
                return [false, null, null];
            }
        } catch (\Throwable $e) {
            $errors[] = 'ssl probe (read) failed: ' . $e->getMessage();
            return [null, null, null];
        }
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed)) {
            $errors[] = 'ssl probe: could not parse cert at ' . $certPath;
            return [false, null, null];
        }
        $expiresAt = isset($parsed['validTo_time_t'])
            ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
            : null;
        $issuer = null;
        if (isset($parsed['issuer']) && is_array($parsed['issuer'])) {
            $issuer = (string) ($parsed['issuer']['CN'] ?? $parsed['issuer']['O'] ?? '');
            $issuer = $issuer === '' ? null : substr($issuer, 0, 64);
        }
        return [true, $expiresAt, $issuer];
    }

    /**
     * @param list<string> $errors
     */
    private function probeVhost(string $domain, array &$errors): ?bool
    {
        try {
            return $this->adapters->ols->vhostConfigExists($domain);
        } catch (\Throwable $e) {
            $errors[] = 'vhost probe failed: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $errors
     */
    private function probeHomeDir(array $row, array &$errors): ?bool
    {
        $home = isset($row['home_dir']) && is_string($row['home_dir']) && $row['home_dir'] !== ''
            ? (string) $row['home_dir']
            : '/home/' . ($row['domain'] ?? '');
        try {
            return $this->adapters->fs->isDirectory($home);
        } catch (\Throwable $e) {
            $errors[] = 'home probe failed: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $errors
     */
    private function probeDocumentRoot(array $row, array &$errors): ?bool
    {
        $docroot = $row['document_root'] ?? null;
        $home = $row['home_dir'] ?? null;
        $domain = $row['domain'] ?? '';
        if (!is_string($docroot) || $docroot === '') {
            // Derive from convention: /home/<domain>/public_html
            $base = is_string($home) && $home !== '' ? $home : '/home/' . $domain;
            $docroot = rtrim($base, '/') . '/public_html';
        }
        try {
            return $this->adapters->fs->isDirectory($docroot);
        } catch (\Throwable $e) {
            $errors[] = 'docroot probe failed: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $errors
     * @return array{0: ?bool, 1: ?bool}  [database, user]
     */
    private function probeDatabase(array $row, array &$errors): array
    {
        $dbName = $row['db_name'] ?? null;
        $dbUser = $row['db_user'] ?? null;
        $hasDb = is_string($dbName) && $dbName !== '';
        $hasUser = is_string($dbUser) && $dbUser !== '';
        if (!$hasDb && !$hasUser) {
            // Site doesn't declare a DB. No probe.
            return [null, null];
        }
        $dbResult = null;
        $userResult = null;
        try {
            if ($hasDb) {
                $dbResult = $this->adapters->mysql->databaseExists((string) $dbName);
            }
        } catch (\Throwable $e) {
            $errors[] = 'database probe failed: ' . $e->getMessage();
        }
        try {
            if ($hasUser) {
                $userResult = $this->adapters->mysql->userExists((string) $dbUser);
            }
        } catch (\Throwable $e) {
            $errors[] = 'database user probe failed: ' . $e->getMessage();
        }
        return [$dbResult, $userResult];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $errors
     * @return array{0: ?bool, 1: ?bool}  [user, group]
     */
    private function probeSftp(array $row, array &$errors): array
    {
        $sftpUser = $row['sftp_user'] ?? null;
        if (!is_string($sftpUser) || $sftpUser === '') {
            // Site doesn't declare an sftp user yet (e.g. fresh
            // provisioning row). Not a drift case.
            return [null, null];
        }
        $userResult = null;
        $groupResult = null;
        try {
            $userResult = $this->adapters->sftp->userExists($sftpUser);
        } catch (\Throwable $e) {
            $errors[] = 'sftp user probe failed: ' . $e->getMessage();
        }

        // Resolve the user's actual primary group before probing for
        // existence. Saga-created sites have group name === user name
        // by convention, but adopted legacy / service users (e.g.
        // /var/www-style shared hosts) often share a system group like
        // `www-data`. Hard-coding `$group = $sftpUser` mis-flags these
        // as "missing sftp_group" every reconciler tick.
        //
        // When the user exists we ask the adapter for the real group
        // name and probe that. When the user doesn't exist we fall
        // back to the user name - that's still useful information for
        // the drift assessor because it confirms both halves are
        // missing.
        $group = null;
        if ($userResult === true) {
            try {
                $group = $this->adapters->sftp->primaryGroupName($sftpUser);
            } catch (\Throwable $e) {
                $errors[] = 'sftp primary-group lookup failed: ' . $e->getMessage();
            }
        }
        if ($group === null || $group === '') {
            $group = $sftpUser;
        }

        try {
            $groupResult = $this->adapters->sftp->groupExists($group);
        } catch (\Throwable $e) {
            $errors[] = 'sftp group probe failed: ' . $e->getMessage();
        }
        return [$userResult, $groupResult];
    }
}
