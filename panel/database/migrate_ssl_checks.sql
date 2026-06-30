-- SSL Check Results Table
-- Stores comprehensive SSL analysis results per domain

CREATE TABLE IF NOT EXISTS ssl_check_results (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    grade VARCHAR(5) NOT NULL,
    score INT NOT NULL DEFAULT 0,
    protocols JSON,
    ciphers JSON,
    vulnerabilities JSON,
    certificate JSON,
    security_headers JSON,
    deductions JSON,
    scan_duration DECIMAL(8,2),
    scanned_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_domain (domain),
    INDEX idx_grade (grade),
    INDEX idx_scanned_at (scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

