-- Guest call tokens: one-click magic links for external clients to join video calls
-- No portal session required - the token itself authenticates and grants call access

CREATE TABLE IF NOT EXISTS guest_call_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL UNIQUE,
    portal_call_id INT NULL,
    room_name VARCHAR(255) NOT NULL,
    client_id INT NULL,
    guest_name VARCHAR(255) NULL,
    guest_email VARCHAR(255) NULL,
    created_by VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    use_count INT NOT NULL DEFAULT 0,
    max_uses INT NOT NULL DEFAULT 0,
    status ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_room (room_name),
    INDEX idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
