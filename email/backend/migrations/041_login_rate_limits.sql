-- Login rate limiting table
-- Tracks failed login attempts by email and IP to prevent brute-force attacks
CREATE TABLE IF NOT EXISTS login_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL COMMENT 'email or IP address',
    identifier_type ENUM('email', 'ip') NOT NULL DEFAULT 'email',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME DEFAULT NULL,
    INDEX idx_identifier (identifier, identifier_type),
    INDEX idx_locked (locked_until),
    INDEX idx_cleanup (first_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

