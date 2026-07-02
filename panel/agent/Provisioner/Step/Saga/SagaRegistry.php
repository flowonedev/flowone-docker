<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Saga;

use VpsAdmin\Agent\Installers\WordPressInstaller;
use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\Steps\App\InstallAppStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseGrantStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DnsZoneCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\HomeDirCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsRestartStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpGroupCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SslIssueStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DatabaseDropStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DatabaseUserDropStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DnsZoneRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\HomeDirRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\MailTeardownStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\OlsMainConfigRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\PreDeleteSnapshotStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpGroupRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SftpUserRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SslRevokeStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\VhostConfigRemoveStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Archive\ArchivePromoteStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\ArchiveRestorePreflightStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\DatabaseHydrateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Restore\HomeDirHydrateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostResumeStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Suspend\VhostSuspendStep;

/**
 * Builds canonical SagaSequence instances. This is the SINGLE source
 * of truth for "what steps are in the create flow, in what order".
 *
 * Keep additions to the sequences obvious: each entry is a single line
 * `new XxxStep()` so a code reviewer can read the order top-to-bottom
 * without chasing inheritance. If a step needs constructor arguments
 * (like VhostConfigWriteStep needing a VhostConfigTemplate), wire them
 * via SagaRegistry's constructor so all step DI lives in one place.
 *
 * The orchestrator (Step 5) instantiates ONE SagaRegistry per worker
 * boot and asks it for the appropriate sequence per job type.
 *
 * Not final: the JobWorker test suite (Step 5c-2) overrides
 * createSequence() to inject a 1-step fake saga so it can exercise
 * claim/lease/retry semantics without touching SFTP, OLS, or MySQL.
 * Production callers must not subclass this; if you find yourself
 * wanting to, add a SagaProvider interface instead.
 */
class SagaRegistry
{
    /**
     * @param VhostConfigTemplate $vhostTemplate Renders /usr/local/lsws/conf/vhosts/<d>/vhost.conf.
     * @param string $serverIp Public IPv4 of this VPS - injected as the
     *                         A-record content for both <domain> and
     *                         mail.<domain> at zone-creation time. The
     *                         empty default keeps the foundation tests
     *                         (which build a SagaRegistry with no DI
     *                         args) compiling - in that path the DNS
     *                         step is a no-op because tests use single-
     *                         label domains that the step skips.
     * @param string $ns1      Authoritative NS hostname #1. Empty means "no
     *                          NS configured" — DnsZoneCreateStep then skips
     *                          NS records instead of publishing a wrong one.
     *                          Production callers (worker-daemon) pass the
     *                          NsDefaults-resolved value.
     * @param string $ns2      Authoritative NS hostname #2.
     */
    public function __construct(
        private readonly VhostConfigTemplate $vhostTemplate = new VhostConfigTemplate(),
        private readonly string $serverIp = '',
        private readonly string $ns1 = '',
        private readonly string $ns2 = '',
        /**
         * Optional WordPress installer used by the (gated) InstallAppStep
         * at the end of the create / restore sagas. When null, the step
         * lazily builds one from $appInstallerConfig the first time it
         * runs; if the agent config wasn't passed either, the step's
         * fallback path uses the WordPressInstaller's own defaults.
         * Tests pass a stub installer here to skip the real WP-CLI hop.
         */
        private readonly ?WordPressInstaller $wordPressInstaller = null,
        /**
         * Agent config snapshot, passed to WordPressInstaller for paths
         * (ols_bin / backups). Production callers should pass the merged
         * config.php + config.local.php; tests can leave empty.
         *
         * @var array<string,mixed>
         */
        private readonly array $appInstallerConfig = [],
    ) {
    }

    /**
     * Default "create site" saga. The order is significant:
     *
     *   group        - prerequisite for user.
     *   user         - prerequisite for chown.
     *   home dir     - must exist before vhost.conf references it.
     *   vhost.conf   - must exist before main config references it.
     *   main config  - inserts vhost block + listener maps.
     *   db           - independent of OLS, but kept after OLS so that
     *                  if OLS fails we don't litter the DB server.
     *   db user      - depends on db existing for the GRANT.
     *   db grant     - depends on both.
     *   dns zone     - seed authoritative DNS records (SOA/NS/A/MX/SPF/
     *                  DMARC). Skips silently for single-label hosts and
     *                  when payload['dns_enabled']===false. Position is
     *                  late so a DNS-DB hiccup never blocks vhost laydown.
     *   ols restart  - First reload. The vhost is reachable on :80 here
     *                  so Let's Encrypt's HTTP challenge prober can
     *                  fetch the ACME challenge in the next step.
     *   ssl issue    - certbot --webroot + appendVhssl + 2nd OLS reload.
     *                  Position is after dns and the first OLS reload so
     *                  an SSL hiccup (rate limit, DNS not propagated yet)
     *                  leaves us with a working HTTP site instead of
     *                  nuking the create. DEGRADE_ONLY.
     *   install app  - OPTIONAL final step. Gated on payload['install_app']
     *                  - bare site creation skips it entirely. When
     *                  present, runs WP-CLI to install WordPress into
     *                  public_html. DEGRADE_ONLY: a failed install
     *                  leaves the working empty site behind so the
     *                  operator can retry from the UI without
     *                  re-running the create saga.
     *
     * On failure, compensate runs BACKWARDS. The DEGRADE_ONLY policy
     * on db_create / db_user_create / ssl_issue / install_app stops
     * the compensation chain there and parks the site as degraded.
     */
    public function createSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.create',
            steps: [
                new SftpGroupCreateStep(),
                new SftpUserCreateStep(),
                new HomeDirCreateStep(),
                new VhostConfigWriteStep($this->vhostTemplate),
                new OlsMainConfigInsertStep(),
                new DatabaseCreateStep(),
                new DatabaseUserCreateStep(),
                new DatabaseGrantStep(),
                new DnsZoneCreateStep($this->serverIp, $this->ns1, $this->ns2),
                new OlsRestartStep(),
                new SslIssueStep($this->vhostTemplate),
                new InstallAppStep($this->wordPressInstaller, $this->appInstallerConfig),
            ],
        );
    }

    /**
     * Default "delete site" saga. The order is the reverse of create
     * with one mandatory addition at the front:
     *
     *   PRE-snapshot       - mysqldump + home tar BEFORE anything is
     *                        destroyed. Both DatabaseDropStep and
     *                        HomeDirRemoveStep refuse to run without
     *                        a confirmed snapshot, unless the operator
     *                        sets payload['skip_snapshot']=true.
     *   ssl revoke         - revoke the cert at the ACME endpoint and
     *                        delete it from /etc/letsencrypt so the
     *                        renewal cron stops nagging. Runs EARLY
     *                        (right after the snapshot) so an ACME
     *                        outage is detected before the destructive
     *                        teardown begins. Idempotent and
     *                        DEGRADE_ONLY: a failed revoke does not
     *                        block the rest of the delete.
     *   db drop            - DROP DATABASE (idempotent if absent).
     *   db user drop       - DROP USER (implicitly revokes grants).
     *   dns zone remove    - drop the PowerDNS zone + records.
     *   mail teardown      - remove mail_domains/_accounts/_forwards
     *                        rows, the vmail maildir (tar'd into the
     *                        snapshot dir first), opendkim keys and
     *                        SigningTable/KeyTable lines. Runs after
     *                        dns zone remove so the MX/DKIM records
     *                        are already gone when signing stops.
     *   ols main remove    - prune the vhost block + listener maps
     *                        from httpd_config.conf in one atomic
     *                        write. Runs BEFORE the vhost dir is
     *                        deleted so OLS never points at a missing
     *                        path.
     *   vhost dir remove   - delete /usr/local/lsws/conf/vhosts/<d>/.
     *   home dir remove    - rmtree /home/<d> (allowed-root guarded).
     *   sftp user remove   - userdel (must precede groupdel).
     *   sftp group remove  - groupdel.
     *   ols restart        - LAST. One reload picks up everything.
     *
     * All DELETE steps use CompensationPolicy::DEGRADE_ONLY by design:
     * a partial-delete failure parks the site in 'degraded' and leaves
     * the operator's recovery artifact (the snapshot dir) intact.
     */
    public function deleteSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.delete',
            steps: [
                new PreDeleteSnapshotStep(),
                new SslRevokeStep(),
                new DatabaseDropStep(),
                new DatabaseUserDropStep(),
                new DnsZoneRemoveStep(),
                new MailTeardownStep(),
                new OlsMainConfigRemoveStep(),
                new VhostConfigRemoveStep(),
                new HomeDirRemoveStep(),
                new SftpUserRemoveStep(),
                new SftpGroupRemoveStep(),
                new OlsRestartStep(),
            ],
        );
    }

    /**
     * "Suspend site" saga. Two-step sequence:
     *
     *   vhost suspend   - swap vhost.conf to a 503-only config and
     *                     stash the original as
     *                     vhost.conf.suspended-backup so RESUME can
     *                     restore it byte-for-byte.
     *   ols restart     - graceful reload picks up the change.
     *
     * The DB / SFTP user / home dir are intentionally untouched: an
     * operator may want to SSH/SFTP in to inspect or back up the
     * data while the site is offline. The sites row transitions
     * active -> suspended.
     *
     * Compensation: SAFE_ROLLBACK throughout; VhostSuspendStep
     * compensate() restores the original vhost.conf if the saga is
     * aborted between the swap and the OLS restart.
     */
    public function suspendSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.suspend',
            steps: [
                new VhostSuspendStep(),
                new OlsRestartStep(),
            ],
        );
    }

    /**
     * "Resume site" saga - the inverse of suspendSequence().
     *
     *   vhost resume    - restore vhost.conf from the
     *                     vhost.conf.suspended-backup file written by
     *                     SUSPEND, then delete the backup.
     *   ols restart     - graceful reload.
     *
     * No-ops if the site was never suspended (the resume step's
     * check() returns true on a healthy live config + missing
     * backup). The sites row transitions suspended -> active.
     */
    public function resumeSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.resume',
            steps: [
                new VhostResumeStep(),
                new OlsRestartStep(),
            ],
        );
    }

    /**
     * "Archive site" saga.
     *
     * The shape is "mandatory snapshot + promote to archive store +
     * DELETE-style teardown of live infrastructure". The sites row
     * is intentionally NOT removed; the bridge moves it into
     * 'archived' state with an archive_path so RESTORE can find it
     * later.
     *
     *   pre snapshot         - mysqldump + home tar (skip flags
     *                          IGNORED for archive - archive always
     *                          needs the data).
     *   archive promote      - copy the snapshot to the archive
     *                          store and record archive_path.
     *   db drop              - destructive teardown begins.
     *   db user drop
     *   ols main remove
     *   vhost dir remove
     *   home dir remove
     *   sftp user remove
     *   sftp group remove
     *   ols restart          - one reload picks up everything.
     *
     * Compensation: all destructive steps are DEGRADE_ONLY by
     * policy. ArchivePromoteStep is SAFE_ROLLBACK so a partial
     * archive can be cleaned up.
     */
    public function archiveSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.archive',
            steps: [
                new PreDeleteSnapshotStep(),
                new ArchivePromoteStep(),
                new DatabaseDropStep(),
                new DatabaseUserDropStep(),
                new DnsZoneRemoveStep(),
                new OlsMainConfigRemoveStep(),
                new VhostConfigRemoveStep(),
                new HomeDirRemoveStep(),
                new SftpUserRemoveStep(),
                new SftpGroupRemoveStep(),
                new OlsRestartStep(),
            ],
        );
    }

    /**
     * "Restore site" saga.
     *
     *   restore preflight    - verify archive_path + db dump + home
     *                          tar all exist and are readable.
     *   sftp group create    - re-lay infrastructure (same shape
     *   sftp user create       as CREATE saga, in the same order).
     *   home dir create
     *   home dir hydrate     - untar archived home.tar.gz into the
     *                          fresh home dir.
     *   vhost config write   - re-render vhost.conf from template.
     *   ols main insert      - re-insert vhost block + listener maps.
     *   database create      - create empty DB.
     *   database user create
     *   database grant
     *   database hydrate     - mysql < <db>.sql into the empty DB.
     *   ols restart          - one reload picks up everything.
     *
     * Compensation:
     *   - hydrate steps are SAFE_ROLLBACK and a no-op on compensate
     *     (the destructive teardown chain removes the underlying
     *     storage anyway).
     *   - preflight is SAFE_ROLLBACK no-op (read only).
     */
    public function restoreSequence(): SagaSequence
    {
        return new SagaSequence(
            name: 'site.restore',
            steps: [
                new ArchiveRestorePreflightStep(),
                new SftpGroupCreateStep(),
                new SftpUserCreateStep(),
                new HomeDirCreateStep(),
                new HomeDirHydrateStep(),
                new VhostConfigWriteStep($this->vhostTemplate),
                new OlsMainConfigInsertStep(),
                new DatabaseCreateStep(),
                new DatabaseUserCreateStep(),
                new DatabaseGrantStep(),
                new DatabaseHydrateStep(),
                new DnsZoneCreateStep($this->serverIp, $this->ns1, $this->ns2),
                new OlsRestartStep(),
                // ARCHIVE keeps the cert in /etc/letsencrypt/live so a
                // RESTORE typically just re-appends the existing
                // vhssl block without burning another issuance. The
                // step's own check() short-circuits when the cert
                // already exists + the vhssl block is in vhost.conf.
                new SslIssueStep($this->vhostTemplate),
            ],
        );
    }
}
