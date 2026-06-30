-- Migration 197: Per-account IP block list for device sign-in approvals.
--
-- When an approval prompt is clearly not the user ("someone is trying to sign in
-- to my account from an IP I don't recognize"), they can Block it. That denies
-- the current request AND stops that IP from creating any further device sign-in
-- requests TARGETED at that account.
--
-- Scope: blocks are per (ip_address, target_email) so one user blocking an
-- attacker can never lock another account out. This is an app-level block for
-- the device-login flow only; it is not a firewall ban.

CREATE TABLE IF NOT EXISTS sso_device_blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    target_email VARCHAR(255) NOT NULL,
    blocked_by VARCHAR(255) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ip_email (ip_address, target_email),
    INDEX idx_target_email (target_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
