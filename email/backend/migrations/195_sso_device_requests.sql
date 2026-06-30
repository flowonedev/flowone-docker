-- Migration 195: Device-authorization ("scan to sign in") requests.
--
-- Powers the QR + approval login for desktop apps. The device being signed in
-- (e.g. FlowOne Drive) creates an ANONYMOUS request here and shows a QR / a
-- 2-digit match number. An already-signed-in web session opens the approval
-- page, taps the matching number, and the request is bound to that user. The
-- desktop polls (holding a poll_secret) and, once approved, receives a one-time
-- sso_codes code which it redeems through the existing /sso/exchange flow.
--
-- Security:
--   * request_id is the scannable capability (lets a logged-in user APPROVE).
--   * poll_secret_hmac gates token retrieval (only the desktop can poll), so a
--     bystander who photographs the QR cannot harvest a session.
--   * match_number + two decoys block blind/relay approvals; approve_attempts
--     caps guessing. Rows are short-lived (~2 min) and single-use.

CREATE TABLE IF NOT EXISTS sso_device_requests (
    request_id CHAR(36) NOT NULL PRIMARY KEY,
    poll_secret_hmac VARCHAR(128) NOT NULL,
    match_number TINYINT UNSIGNED NOT NULL,
    decoy_a TINYINT UNSIGNED NOT NULL,
    decoy_b TINYINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','denied','consumed') NOT NULL DEFAULT 'pending',
    user_email VARCHAR(255) DEFAULT NULL,
    code VARCHAR(16) DEFAULT NULL,
    device_label VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    approve_attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    approved_at DATETIME DEFAULT NULL,
    consumed_at DATETIME DEFAULT NULL,
    INDEX idx_status_expires (status, expires_at),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
