-- =====================================================
-- Security Audit Migration
-- Adds centralized audit logging + dependency scanning
-- Uses IF NOT EXISTS for idempotent re-runs
-- =====================================================

-- 1. Extend audit_logs for multi-app support
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS source_app VARCHAR(50) NOT NULL DEFAULT 'panel' AFTER id;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS severity ENUM('critical', 'high', 'medium', 'low', 'info') NOT NULL DEFAULT 'info' AFTER source_app;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER actor;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS user_email VARCHAR(255) NULL AFTER ip_address;

-- 2. Dependency scan results table
CREATE TABLE IF NOT EXISTS dependency_scans (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_app VARCHAR(50) NOT NULL,
    scan_type VARCHAR(20) NOT NULL COMMENT 'composer or npm',
    vulnerabilities_found INT NOT NULL DEFAULT 0,
    critical_count INT NOT NULL DEFAULT 0,
    high_count INT NOT NULL DEFAULT 0,
    medium_count INT NOT NULL DEFAULT 0,
    low_count INT NOT NULL DEFAULT 0,
    results JSON,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source_app),
    INDEX idx_scanned (scanned_at),
    INDEX idx_type (scan_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
