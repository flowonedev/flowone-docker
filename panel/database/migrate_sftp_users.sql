-- =====================================================
-- Additional Restricted SFTP Users
--
-- Stores the "extra" chroot-jailed SFTP accounts an operator can create
-- per site, on top of the single primary site user in `sites.sftp_user`.
--
-- Each row maps to exactly one Linux user that is:
--   - jailed (OpenSSH ChrootDirectory %h) into a root-owned jail under
--     /srv/sftp-jails/<linux_username>,
--   - given access to one real folder (`target_path`, always under the
--     site's /home/<domain>) via a read-write bind mount + POSIX ACL,
--   - a member of the shared `flowone_sftp` group that a single static
--     sshd Match block keys on.
--
-- The Linux username is GENERATED (sftp_<siteId>_<rand>), never operator
-- supplied, so it can never collide with a reserved/system account. The
-- human-friendly name lives in `display_name`.
--
-- Passwords are NOT stored here - they live in the encrypted secrets
-- vault (scope `site:<domain>`, key `sftp_user:<linux_username>`).
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_sftp_users.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS sftp_users (
    id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Domain-keyed to match user_sites and the whole /api/sites/{domain}
    -- surface. The site whose tree this user is jailed into.
    domain            VARCHAR(253) NOT NULL,

    -- Generated Linux account name (sftp_<siteId>_<rand>, <= 31 chars).
    linux_username    VARCHAR(32) NOT NULL,

    -- Operator-facing label, free text. Shown in the UI; the Linux user
    -- name is never edited.
    display_name      VARCHAR(128) NULL,

    -- Real folder exposed to the user (absolute, canonicalized, always
    -- under /home/<domain>). This is what gets bind-mounted into the jail.
    target_path       VARCHAR(512) NOT NULL,

    -- Root-owned jail directory (ChrootDirectory). Usually
    -- /srv/sftp-jails/<linux_username>.
    jail_root         VARCHAR(512) NOT NULL,

    -- Mount point inside the jail where target_path is bind-mounted,
    -- e.g. /srv/sftp-jails/<user>/<label>. This is what the user sees.
    mount_point       VARCHAR(512) NOT NULL,

    -- Folder label shown inside the jail (last path component of
    -- mount_point), e.g. "uploads".
    label             VARCHAR(64) NOT NULL,

    -- How the account is allowed to authenticate. `key` locks the unix
    -- password (passwd -l); `password`/`both` keep it usable.
    auth_type         ENUM('password','key','both') NOT NULL DEFAULT 'password',

    -- active   = provisioned and usable
    -- disabled = password locked + keys withheld, row + jail kept
    -- error    = last operation failed / drift detected, needs repair
    -- deleting = teardown in progress (set before OS teardown so a busy
    --            unmount leaves a safely retryable row, never a ghost)
    status            ENUM('active','disabled','error','deleting') NOT NULL DEFAULT 'active',

    -- Login telemetry. OpenSSH does not populate these on its own; a
    -- future sftpUser.syncLogins parser of journalctl -u ssh /
    -- /var/log/auth.log backfills them. Present now to avoid a later
    -- migration.
    last_login_at     DATETIME NULL,
    last_login_ip     VARCHAR(45) NULL,
    login_count       INT UNSIGNED NOT NULL DEFAULT 0,

    created_by        VARCHAR(128) NULL
                          COMMENT 'Actor (panel user) who created the account',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_linux_username (linux_username),
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
