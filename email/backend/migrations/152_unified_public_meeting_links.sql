-- Migration 152: Unified public meeting links (polymorphic tokens, rooms, sessions, admission)

-- Polymorphic ownership on guest_call_tokens
ALTER TABLE guest_call_tokens
    ADD COLUMN owner_type ENUM('standalone','portal_call','calendar_event','chat_conversation')
        NOT NULL DEFAULT 'standalone' AFTER token,
    ADD COLUMN owner_id INT NULL AFTER owner_type,
    ADD COLUMN metadata JSON NULL AFTER status,
    ADD COLUMN last_used_ip VARCHAR(45) NULL AFTER metadata,
    ADD COLUMN last_used_user_agent VARCHAR(500) NULL AFTER last_used_ip,
    ADD INDEX idx_owner (owner_type, owner_id),
    ADD INDEX idx_owner_role_status (owner_type, owner_id, role, status);

UPDATE guest_call_tokens
   SET owner_type = 'portal_call', owner_id = portal_call_id
 WHERE portal_call_id IS NOT NULL;

-- Calendar: LiveKit room name (unique per event)
ALTER TABLE calendar_events
    ADD COLUMN meeting_room_name VARCHAR(100) NULL AFTER meeting_conversation_id;

CREATE UNIQUE INDEX idx_meeting_room_name ON calendar_events (meeting_room_name);

-- Per-join session tracking (kick, audit)
CREATE TABLE IF NOT EXISTS meeting_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    identity VARCHAR(255) NOT NULL,
    display_name VARCHAR(60) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME NULL,
    last_seen DATETIME NULL,
    INDEX idx_token (token),
    INDEX idx_room (room_name),
    INDEX idx_room_active (room_name, left_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Room-level settings (waiting room, workshop mode) — set at create, immutable
CREATE TABLE IF NOT EXISTS meeting_rooms (
    room_name VARCHAR(100) PRIMARY KEY,
    waiting_room_enabled TINYINT(1) NOT NULL DEFAULT 0,
    participants_hidden TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Waiting room admission queue
CREATE TABLE IF NOT EXISTS meeting_admission_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(128) NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    guest_name VARCHAR(60) NOT NULL,
    guest_ip VARCHAR(45) NULL,
    status ENUM('pending','approved','denied','expired') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by VARCHAR(255) NULL,
    livekit_token TEXT NULL,
    livekit_ws_url VARCHAR(255) NULL,
    INDEX idx_token (token),
    INDEX idx_room_status (room_name, status),
    INDEX idx_status_requested (status, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
