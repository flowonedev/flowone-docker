-- Guest call chat attachments
-- Files shared in the in-call chat (attach button, clipboard paste, drag & drop).
-- Stored on the server so they survive the call and can be embedded/attached
-- in the transcript email sent to the host when the call ends.
--
-- Run:
-- mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' devc_vps_dash < /var/www/vps-email/backend/migrations/188_guest_call_attachments.sql

CREATE TABLE IF NOT EXISTS guest_call_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(255) NOT NULL,
    token_id INT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room (room_name),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
