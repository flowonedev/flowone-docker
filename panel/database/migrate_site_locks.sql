-- =====================================================
-- Site Locks - Per-Domain Distributed Mutex
--
-- Only ONE job at a time may operate on a given domain. The legacy
-- system used /tmp/vhost-lock-<md5>.lock files which:
--   - die with the box (broken on reboot)
--   - leak on crash (NB locks linger)
--   - cannot be inspected (no "who holds it / how long")
--
-- This table replaces them with a database-backed lock that:
--   - is inspectable (operator can see who holds it and why)
--   - expires (lease_until prevents stuck locks from a dead worker)
--   - survives DB restarts (rows persist; expired ones get re-claimed)
--
-- The implementation is transactional in SiteLock.php: SELECT ... FOR UPDATE,
-- check lease_until, INSERT or UPDATE atomically.
--
-- Apply on server:
--   mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
--     devc_vps_dash < /var/www/vps-admin/database/migrate_site_locks.sql
-- =====================================================

CREATE TABLE IF NOT EXISTS site_locks (
    domain          VARCHAR(253) PRIMARY KEY,

    holder_id       VARCHAR(64) NOT NULL
                        COMMENT 'Opaque ID of the process/worker holding the lock',
    purpose         VARCHAR(128) NULL
                        COMMENT 'Human-readable reason (e.g. "create job 42")',

    acquired_at     DATETIME NOT NULL,
    lease_until     DATETIME NOT NULL
                        COMMENT 'When the lock auto-expires if no heartbeat',

    request_id      VARCHAR(64) NULL,

    INDEX idx_lease (lease_until),
    INDEX idx_holder (holder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
