<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Saga;

/**
 * Canonical step names used as keys in sites.state JSON and in the
 * site_step_executions journal.
 *
 * Why constants and not raw strings everywhere:
 *   - A typo in a step name is silently treated as a fresh state,
 *     causing the step to re-run with empty StepState (potentially
 *     losing checkpoint progress on resume).
 *   - When we rename a step in the future, grep finds every call site.
 *   - The static analyzer (Step 9: architecture-boundary tests) verifies
 *     that every concrete step's name() matches one of these constants.
 *
 * Naming convention: snake_case, verb-noun-direction. The "create"
 * suffix is explicit so the (future) inverse step is unambiguous:
 *   - sftp_group_create  <-> sftp_group_remove
 *   - database_create    <-> database_drop
 */
final class StepName
{
    // ─── CREATE direction ────────────────────────────────────
    public const SFTP_GROUP_CREATE = 'sftp_group_create';
    public const SFTP_USER_CREATE = 'sftp_user_create';
    public const HOME_DIR_CREATE = 'home_dir_create';
    public const VHOST_CONFIG_WRITE = 'vhost_config_write';
    public const OLS_MAIN_CONFIG_INSERT = 'ols_main_config_insert';
    public const DATABASE_CREATE = 'database_create';
    public const DATABASE_USER_CREATE = 'database_user_create';
    public const DATABASE_GRANT = 'database_grant';
    public const DNS_ZONE_CREATE = 'dns_zone_create';
    public const OLS_RESTART = 'ols_restart';
    public const SSL_ISSUE = 'ssl_issue';
    // Optional final step: installs an app (WordPress, etc.) into the
    // freshly provisioned site. Gated on payload['install_app'] so a
    // bare site creation skips it. DEGRADE_ONLY: a failed install
    // leaves the otherwise-working empty site behind for re-attempt.
    public const INSTALL_APP = 'install_app';

    // ─── DELETE direction (Step 4b) ──────────────────────────
    public const PRE_DELETE_SNAPSHOT = 'pre_delete_snapshot';
    public const DATABASE_DROP = 'database_drop';
    public const DATABASE_USER_DROP = 'database_user_drop';
    public const DNS_ZONE_REMOVE = 'dns_zone_remove';
    public const OLS_MAIN_CONFIG_REMOVE = 'ols_main_config_remove';
    public const VHOST_CONFIG_REMOVE = 'vhost_config_remove';
    public const HOME_DIR_REMOVE = 'home_dir_remove';
    public const SFTP_USER_REMOVE = 'sftp_user_remove';
    public const SFTP_GROUP_REMOVE = 'sftp_group_remove';
    public const SSL_REVOKE = 'ssl_revoke';
    // Removes every mail artifact for the domain: mail_domains /
    // mail_accounts / mail_forwards rows, the vmail maildir tree,
    // opendkim keys + SigningTable/KeyTable entries. Added after the
    // production incident where deleted sites kept appearing on the
    // Mail Security page because mail_domains rows were never reaped.
    public const MAIL_TEARDOWN = 'mail_teardown';

    // ─── SUSPEND / RESUME (Step 4c) ──────────────────────────
    public const VHOST_SUSPEND = 'vhost_suspend';
    public const VHOST_RESUME = 'vhost_resume';

    // ─── ARCHIVE direction (Step 4c) ─────────────────────────
    // Reuses PRE_DELETE_SNAPSHOT then promotes the snapshot to long-
    // term storage before running the destructive teardown. The
    // ARCHIVE_PROMOTE step is the only addition; the rest of the
    // sequence overlaps with DELETE.
    public const ARCHIVE_PROMOTE = 'archive_promote';

    // ─── RESTORE direction (Step 4c) ─────────────────────────
    // RESTORE rebuilds a site from an archived snapshot. The first
    // step verifies the archive payload exists + is readable. The
    // hydrate steps re-populate the home dir / database after the
    // CREATE-style steps have laid down the infrastructure.
    public const ARCHIVE_RESTORE_PREFLIGHT = 'archive_restore_preflight';
    public const HOME_DIR_HYDRATE = 'home_dir_hydrate';
    public const DATABASE_HYDRATE = 'database_hydrate';

    /**
     * @return list<string>
     */
    public static function allCreateNames(): array
    {
        return [
            self::SFTP_GROUP_CREATE,
            self::SFTP_USER_CREATE,
            self::HOME_DIR_CREATE,
            self::VHOST_CONFIG_WRITE,
            self::OLS_MAIN_CONFIG_INSERT,
            self::DATABASE_CREATE,
            self::DATABASE_USER_CREATE,
            self::DATABASE_GRANT,
            self::DNS_ZONE_CREATE,
            self::OLS_RESTART,
            self::SSL_ISSUE,
            self::INSTALL_APP,
        ];
    }

    /**
     * @return list<string>
     *
     * The DELETE order mirrors CREATE in reverse, with one addition:
     * PRE_DELETE_SNAPSHOT runs FIRST so we always have a recoverable
     * artifact (mysqldump + home tarball) before any destructive
     * action. OLS_RESTART runs LAST so the removed vhost is purged
     * from the running OLS instance atomically.
     */
    public static function allDeleteNames(): array
    {
        return [
            self::PRE_DELETE_SNAPSHOT,
            self::SSL_REVOKE,
            self::DATABASE_DROP,
            self::DATABASE_USER_DROP,
            self::DNS_ZONE_REMOVE,
            self::MAIL_TEARDOWN,
            self::OLS_MAIN_CONFIG_REMOVE,
            self::VHOST_CONFIG_REMOVE,
            self::HOME_DIR_REMOVE,
            self::SFTP_USER_REMOVE,
            self::SFTP_GROUP_REMOVE,
            self::OLS_RESTART,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allSuspendNames(): array
    {
        return [
            self::VHOST_SUSPEND,
            self::OLS_RESTART,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allResumeNames(): array
    {
        return [
            self::VHOST_RESUME,
            self::OLS_RESTART,
        ];
    }

    /**
     * Archive = mandatory snapshot + promote to archive storage + the
     * destructive teardown subset that DELETE uses. The sites row is
     * left in 'archived' state with an archive_path pointer so it can
     * be RESTORE'd later.
     *
     * @return list<string>
     */
    public static function allArchiveNames(): array
    {
        // ARCHIVE deliberately does NOT include SSL_REVOKE: a restored
        // site should keep its existing certificate so we don't burn
        // through Let's Encrypt issuance budget on every archive cycle.
        // The cert files stay in /etc/letsencrypt/live, the renewal
        // config stays parked, and RESTORE rewires the vhost back to
        // it. SSL_REVOKE only fires on hard DELETE.
        // ARCHIVE deliberately does NOT include MAIL_TEARDOWN either:
        // mailboxes under /home/vmail/<domain> are NOT captured by
        // PRE_DELETE_SNAPSHOT (it tars /home/<domain> only), and the
        // RESTORE saga has no mail re-create step. Tearing down mail
        // here would silently destroy mailbox bytes with no recovery
        // artifact. Mail teardown only happens on hard DELETE.
        return [
            self::PRE_DELETE_SNAPSHOT,
            self::ARCHIVE_PROMOTE,
            self::DATABASE_DROP,
            self::DATABASE_USER_DROP,
            self::DNS_ZONE_REMOVE,
            self::OLS_MAIN_CONFIG_REMOVE,
            self::VHOST_CONFIG_REMOVE,
            self::HOME_DIR_REMOVE,
            self::SFTP_USER_REMOVE,
            self::SFTP_GROUP_REMOVE,
            self::OLS_RESTART,
        ];
    }

    /**
     * Restore = preflight check on archive payload + CREATE-style
     * infrastructure laydown + hydrate the home dir / database from
     * the archived snapshot.
     *
     * @return list<string>
     */
    public static function allRestoreNames(): array
    {
        return [
            self::ARCHIVE_RESTORE_PREFLIGHT,
            self::SFTP_GROUP_CREATE,
            self::SFTP_USER_CREATE,
            self::HOME_DIR_CREATE,
            self::HOME_DIR_HYDRATE,
            self::VHOST_CONFIG_WRITE,
            self::OLS_MAIN_CONFIG_INSERT,
            self::DATABASE_CREATE,
            self::DATABASE_USER_CREATE,
            self::DATABASE_GRANT,
            self::DATABASE_HYDRATE,
            self::DNS_ZONE_CREATE,
            self::OLS_RESTART,
            self::SSL_ISSUE,
        ];
    }

    private function __construct()
    {
    }
}
