-- Phase 4: Staging Environments
-- Run: mysql -u root -p < database/migrate_phase4_staging.sql

USE vpsadmin;

-- Staging environments
CREATE TABLE IF NOT EXISTS staging_environments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    production_domain VARCHAR(255) NOT NULL,
    staging_domain VARCHAR(255) NOT NULL UNIQUE,
    staging_subdomain VARCHAR(100),
    database_name VARCHAR(100),
    htpasswd_user VARCHAR(50),
    htpasswd_hash VARCHAR(255),
    sync_database BOOLEAN DEFAULT TRUE,
    sync_files BOOLEAN DEFAULT TRUE,
    sync_uploads BOOLEAN DEFAULT TRUE,
    last_sync_at TIMESTAMP NULL,
    last_sync_status ENUM('success', 'failed') NULL,
    status ENUM('active', 'syncing', 'error', 'expired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    expires_at TIMESTAMP NULL,
    INDEX idx_production (production_domain),
    INDEX idx_status (status),
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staging sync history
CREATE TABLE IF NOT EXISTS staging_sync_history (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    staging_id INT UNSIGNED NOT NULL,
    sync_type ENUM('full', 'database', 'files', 'push') NOT NULL,
    sync_direction ENUM('prod_to_staging', 'staging_to_prod') DEFAULT 'prod_to_staging',
    files_synced INT,
    database_tables INT,
    urls_replaced INT,
    duration_seconds INT,
    status ENUM('started', 'success', 'failed') NOT NULL,
    log MEDIUMTEXT,
    triggered_by INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staging_id) REFERENCES staging_environments(id) ON DELETE CASCADE,
    INDEX idx_staging (staging_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup event for expired staging environments
DELIMITER //
CREATE EVENT IF NOT EXISTS evt_cleanup_expired_staging
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 2 HOUR
DO
BEGIN
    UPDATE staging_environments SET status = 'expired' 
    WHERE expires_at IS NOT NULL AND expires_at < NOW() AND status = 'active';
END //
DELIMITER ;

SELECT 'Phase 4 migration complete: staging tables created' AS status;

