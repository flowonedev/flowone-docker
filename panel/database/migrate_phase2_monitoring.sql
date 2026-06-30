-- Phase 2: Resource Monitoring
-- Run: mysql -u root -p < database/migrate_phase2_monitoring.sql

USE vpsadmin;

-- Resource metrics (sampled every minute)
CREATE TABLE IF NOT EXISTS resource_metrics (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    metric_type ENUM('cpu', 'memory', 'disk', 'network', 'load') NOT NULL,
    metric_value DECIMAL(10,2) NOT NULL,
    metric_unit VARCHAR(20),
    details JSON,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_time (metric_type, recorded_at),
    INDEX idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregated metrics (hourly, daily)
CREATE TABLE IF NOT EXISTS resource_metrics_aggregated (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    metric_type VARCHAR(50) NOT NULL,
    period ENUM('hourly', 'daily', 'weekly') NOT NULL,
    period_start TIMESTAMP NOT NULL,
    min_value DECIMAL(10,2),
    max_value DECIMAL(10,2),
    avg_value DECIMAL(10,2),
    sample_count INT,
    INDEX idx_type_period (metric_type, period, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert rules
CREATE TABLE IF NOT EXISTS alert_rules (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    metric_type VARCHAR(50) NOT NULL,
    `condition` ENUM('gt', 'lt', 'gte', 'lte', 'eq') NOT NULL,
    threshold DECIMAL(10,2) NOT NULL,
    duration_minutes INT DEFAULT 5,
    action ENUM('email', 'webhook', 'both') DEFAULT 'email',
    recipients TEXT,
    enabled BOOLEAN DEFAULT TRUE,
    last_triggered TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alert history
CREATE TABLE IF NOT EXISTS alert_history (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    rule_id INT UNSIGNED,
    metric_type VARCHAR(50),
    metric_value DECIMAL(10,2),
    threshold DECIMAL(10,2),
    message TEXT,
    acknowledged BOOLEAN DEFAULT FALSE,
    acknowledged_by INT UNSIGNED,
    acknowledged_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE SET NULL,
    INDEX idx_created (created_at),
    INDEX idx_acknowledged (acknowledged)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default alert rules
INSERT INTO alert_rules (name, metric_type, `condition`, threshold, duration_minutes, action) VALUES
('High CPU Usage', 'cpu', 'gt', 90.00, 5, 'email'),
('High Memory Usage', 'memory', 'gt', 90.00, 5, 'email'),
('Disk Almost Full', 'disk', 'gt', 85.00, 1, 'email'),
('High Load Average', 'load', 'gt', 10.00, 5, 'email')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Cleanup procedure for old metrics (keep 7 days raw)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_metrics()
BEGIN
    DELETE FROM resource_metrics WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    DELETE FROM resource_metrics_aggregated WHERE period_start < DATE_SUB(NOW(), INTERVAL 90 DAY);
END //
DELIMITER ;

SELECT 'Phase 2 migration complete: monitoring tables created' AS status;

