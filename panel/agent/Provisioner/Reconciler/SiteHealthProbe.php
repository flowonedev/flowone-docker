<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * Snapshot of what the prober actually observed on disk / in OLS / in
 * MariaDB / in /etc/passwd for one site. The DriftAssessor compares this
 * against the desired-state record in `sites` and decides whether a
 * RECONCILE job should be enqueued.
 *
 * `null` on any field means "we didn't probe it because the site's
 * declared shape doesn't require it" (e.g. a site without a `db_name`
 * column doesn't need a database probe). That's distinct from `false`
 * which means "we probed and the resource is missing".
 *
 * Why the DTO is immutable: drift assessment must be deterministic for
 * a given snapshot. If a single field mutated mid-assessment the
 * recommendation could flip while audit logs already mentioned the
 * older value. Build the DTO once, pass it everywhere.
 */
final class SiteHealthProbe
{
    /**
     * @param array<int, string> $errors  Probe-time errors (e.g. "no
     *                                    SQL admin creds to inspect DB")
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?bool $vhostConfigPresent,
        public readonly ?bool $homeDirPresent,
        public readonly ?bool $documentRootPresent,
        public readonly ?bool $databasePresent,
        public readonly ?bool $databaseUserPresent,
        public readonly ?bool $sftpUserPresent,
        public readonly ?bool $sftpGroupPresent,
        public readonly array $errors = [],
        public readonly float $probedAtUnix = 0.0,
        /**
         * Metrics fields - observed values the reconciler writes back
         * into `sites` so the list view can display fresh data. These
         * are NOT consulted by the DriftAssessor; they exist purely
         * for display + the operator's "is this site healthy" gut
         * check.
         *
         * `null` means "we did not probe this metric" (e.g. the home
         * dir did not exist so du was skipped). The reconciler writes
         * the column back unchanged when a metric is null.
         */
        public readonly ?int $sizeBytes = null,
        public readonly ?bool $sslEnabled = null,
        public readonly ?string $sslExpiresAt = null,
        public readonly ?string $sslIssuer = null
    ) {
    }

    /**
     * Subsystems that this probe was unable to evaluate. Drift assessment
     * treats null-valued subsystems as "unknown" rather than missing, so
     * we don't enqueue remediation jobs on the back of incomplete data.
     *
     * @return list<string>
     */
    public function unevaluated(): array
    {
        $out = [];
        if ($this->vhostConfigPresent === null) {
            $out[] = 'vhost';
        }
        if ($this->homeDirPresent === null) {
            $out[] = 'home';
        }
        if ($this->documentRootPresent === null) {
            $out[] = 'document_root';
        }
        if ($this->databasePresent === null) {
            $out[] = 'database';
        }
        if ($this->databaseUserPresent === null) {
            $out[] = 'database_user';
        }
        if ($this->sftpUserPresent === null) {
            $out[] = 'sftp_user';
        }
        if ($this->sftpGroupPresent === null) {
            $out[] = 'sftp_group';
        }
        return $out;
    }

    /**
     * Subsystems the prober confirmed present.
     *
     * @return list<string>
     */
    public function present(): array
    {
        $out = [];
        if ($this->vhostConfigPresent === true) $out[] = 'vhost';
        if ($this->homeDirPresent === true) $out[] = 'home';
        if ($this->documentRootPresent === true) $out[] = 'document_root';
        if ($this->databasePresent === true) $out[] = 'database';
        if ($this->databaseUserPresent === true) $out[] = 'database_user';
        if ($this->sftpUserPresent === true) $out[] = 'sftp_user';
        if ($this->sftpGroupPresent === true) $out[] = 'sftp_group';
        return $out;
    }

    /**
     * Subsystems the prober confirmed missing.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $out = [];
        if ($this->vhostConfigPresent === false) $out[] = 'vhost';
        if ($this->homeDirPresent === false) $out[] = 'home';
        if ($this->documentRootPresent === false) $out[] = 'document_root';
        if ($this->databasePresent === false) $out[] = 'database';
        if ($this->databaseUserPresent === false) $out[] = 'database_user';
        if ($this->sftpUserPresent === false) $out[] = 'sftp_user';
        if ($this->sftpGroupPresent === false) $out[] = 'sftp_group';
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'vhost_config_present' => $this->vhostConfigPresent,
            'home_dir_present' => $this->homeDirPresent,
            'document_root_present' => $this->documentRootPresent,
            'database_present' => $this->databasePresent,
            'database_user_present' => $this->databaseUserPresent,
            'sftp_user_present' => $this->sftpUserPresent,
            'sftp_group_present' => $this->sftpGroupPresent,
            'errors' => $this->errors,
            'probed_at_unix' => $this->probedAtUnix,
            'present' => $this->present(),
            'missing' => $this->missing(),
            'unevaluated' => $this->unevaluated(),
            'size_bytes' => $this->sizeBytes,
            'ssl_enabled' => $this->sslEnabled,
            'ssl_expires_at' => $this->sslExpiresAt,
            'ssl_issuer' => $this->sslIssuer,
        ];
    }
}
